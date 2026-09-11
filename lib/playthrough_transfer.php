<?php
require_once __DIR__ . '/playthrough_home.php';

// Transfer files are private, session-owned and short lived; never extract ZIP paths.
function ptx_directory(string $id = ''): string {
    $base = sys_get_temp_dir().'/dwemer-playthrough-'.substr(hash('sha256',dirname(__DIR__)),0,16);
    if (!is_dir($base) && !mkdir($base,0700,true)) throw new RuntimeException('Temporary storage is unavailable.');
    if ($id==='') return $base;
    if (!preg_match('/^[a-f0-9]{32}$/D',$id)) throw new InvalidArgumentException('Invalid transfer.');
    return $base.'/'.$id;
}

function ptx_clean(string $directory): void {
    // Only our flat, random job directories are eligible for removal.
    if (dirname($directory)!==ptx_directory() || !preg_match('/^[a-f0-9]{32}$/D',basename($directory))) return;
    foreach (glob($directory.'/*') ?: [] as $file) if (is_file($file) && !is_link($file)) @unlink($file);
    @rmdir($directory);
}

function ptx_job(string $owner, string $kind): string {
    if (!in_array($kind,['import','export'],true)) throw new InvalidArgumentException('Invalid transfer type.');
    foreach (glob(ptx_directory().'/*',GLOB_ONLYDIR) ?: [] as $directory) {
        if (filemtime($directory)>time()-86400) continue;
        $lock=@fopen($directory.'/lock','c');
        if ($lock && flock($lock,LOCK_EX|LOCK_NB)) ptx_clean($directory);
        if ($lock) fclose($lock);
    }
    $id=bin2hex(random_bytes(16));$directory=ptx_directory($id);
    if (!mkdir($directory,0700)) throw new RuntimeException('Could not prepare the transfer.');
    file_put_contents($directory.'/owner.json',json_encode(['owner'=>$owner,'kind'=>$kind,'created'=>time()],JSON_THROW_ON_ERROR));
    ptx_progress($directory,'Ready');return $id;
}

function ptx_owned(string $id, string $owner): string {
    $directory=ptx_directory($id);$info=json_decode((string)@file_get_contents($directory.'/owner.json'),true);
    if (!$info || !hash_equals($info['owner'],$owner) || $info['created']<time()-86400) throw new RuntimeException('This transfer expired. Start again.');
    return $directory;
}

function ptx_progress(string $directory, string $message): void {
    $tmp=$directory.'/status.tmp';file_put_contents($tmp,json_encode(['message'=>$message],JSON_THROW_ON_ERROR));rename($tmp,$directory.'/status.json');
}

function ptx_columns($conn, string $schema, string $table): array {
    return pg_fetch_all(pth_query($conn,"SELECT attname AS name,format_type(atttypid,atttypmod) AS type,attgenerated AS generated FROM pg_attribute WHERE attrelid=to_regclass($1) AND attnum>0 AND NOT attisdropped ORDER BY attnum",[pg_escape_identifier($conn,$schema).'.'.pg_escape_identifier($conn,$table)])) ?: [];
}

// Reference fingerprints reveal no shared profile contents or credentials.
function ptx_profiles($conn): array {
    return pg_fetch_all(pth_query($conn,"SELECT id,COALESCE(to_jsonb(p)->>'label',to_jsonb(p)->>'name','Profile '||id) AS label,md5((to_jsonb(p)-'id'-'created_at'-'updated_at')::text) AS fingerprint FROM public.core_profiles p ORDER BY id")) ?: [];
}

// Some old tables use a sequence default without an ownership dependency.
function ptx_sequence($conn, string $schema, string $table, string $column): ?string {
    $relation=pg_escape_identifier($conn,$schema).'.'.pg_escape_identifier($conn,$table);
    $seq=pg_fetch_result(pth_query($conn,'SELECT pg_get_serial_sequence($1,$2)',[$relation,$column]),0,0);
    if ($seq) return $seq;
    $row=pg_fetch_assoc(pth_query($conn,"SELECT s.oid::regclass::text AS name FROM pg_depend d JOIN pg_attrdef def ON d.classid='pg_attrdef'::regclass AND d.objid=def.oid JOIN pg_class s ON s.oid=d.refobjid JOIN pg_attribute a ON a.attrelid=def.adrelid AND a.attnum=def.adnum WHERE d.refclassid='pg_class'::regclass AND s.relkind='S' AND def.adrelid=$1::regclass AND a.attname=$2",[$relation,$column]));
    return $row['name']??null;
}

function ptx_profile_columns($conn, string $schema, array $tables): array {
    $found=[];
    foreach ($tables as $table) foreach (ptx_columns($conn,$schema,$table) as $column) {
        if (in_array($column['name'],['profile_id','profile_id_before_player_faction'],true) && in_array($column['type'],['integer','bigint','smallint'],true)) $found[]=[$table,$column['name']];
    }
    return $found;
}

// These schemas are newly created private copies, never existing user archives.
function ptx_drop_private($conn, string $schema): void {
    $prefix=preg_quote(ptp_product()['prefix'],'/');
    if (!preg_match('/^'.$prefix.'(?:transfer_[a-f0-9]{32}|upgrade_[0-9]+_[0-9]+)$/D',$schema)) throw new RuntimeException('Unexpected transfer storage.');
    pth_query($conn,'DROP SCHEMA IF EXISTS '.pg_escape_identifier($conn,$schema).' CASCADE');
}

function ptx_export($conn, int $id, string $expected, string $directory): array {
    $product=ptp_product();$meta=$product['meta'];$runtime=null;$locked=false;$stage='';$raw='';$zip=null;
    try {
        ptx_progress($directory,'Capturing a consistent copy…');
        $runtime=ptr_runtime_begin_switch(30,$conn);
        if (!ptr_lock($conn)) throw new RuntimeException('Another Playthrough Save operation is running.');
        $locked=true;pth_query($conn,'BEGIN');
        $state=pth_state($conn);
        if (!hash_equals($state['token'],$expected)) throw new RuntimeException('The active playthrough changed. Reload and try again.');
        $row=pg_fetch_assoc(pth_query($conn,"SELECT * FROM {$meta}.playthrough_profiles WHERE id=$1 FOR UPDATE",[$id]));
        if (!$row || $row['storage_type']!=='schema') throw new RuntimeException('That saved copy is unavailable.');
        $source=$row['schema_name'];
        if ($row['is_active']==='t') {
            $raw=$product['prefix'].'transfer_'.bin2hex(random_bytes(16));
            if (!pts_transfer_playthrough($conn,$raw)['success']) throw new RuntimeException('Could not capture current progress.');
            $source=$raw;
        }
        $original=json_decode((string)pg_fetch_result(pth_query($conn,"SELECT obj_description(oid,'pg_namespace') FROM pg_namespace WHERE nspname=$1",[$source]),0,0),true) ?: [];
        $stage=pts_prepare_playthrough($conn,$source);
        $snapshot=json_decode(pg_fetch_result(pth_query($conn,"SELECT obj_description(oid,'pg_namespace') FROM pg_namespace WHERE nspname=$1",[$stage]),0,0),true);
        $snapshot['player_identity']=$original['player_identity'] ?? ['version'=>1,'player_name'=>$row['player_name']??'','player_faction_members'=>json_decode($row['player_faction_members']??'[]',true)];
        unset($snapshot['source_schema']);
        $snapshot['migrations']=json_decode(pg_fetch_result(pth_query($conn,"SELECT COALESCE(jsonb_object_agg(tablename,version),'{}') FROM public.database_versioning"),0,0),true);
        pth_query($conn,'LOCK TABLE public.core_profiles IN SHARE MODE');
        $profiles=ptx_profiles($conn);
        $row['last_gamets']=(int)pg_fetch_result(pth_query($conn,'SELECT COALESCE(max(gamets),0) FROM '.pg_escape_identifier($conn,$stage).'.eventlog'),0,0);
        if ($raw!=='') { ptx_drop_private($conn,$raw);$raw=''; }
        pth_query($conn,'COMMIT');ptr_unlock($conn);$locked=false;
        $ready=ptr_runtime_finish_switch($runtime);
        ptx_progress($directory,'Preparing download…');
        $tables=[];$expanded=0;$zip=new ZipArchive();
        if ($zip->open($directory.'/save.zip',ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true) throw new RuntimeException('Could not create download.');
        $references=[];
        foreach (ptx_profile_columns($conn,$stage,$snapshot['tables']) as [$table,$column]) {
            $rows=pg_fetch_all(pth_query($conn,'SELECT DISTINCT '.pg_escape_identifier($conn,$column).' AS id FROM '.pg_escape_identifier($conn,$stage).'.'.pg_escape_identifier($conn,$table).' WHERE '.pg_escape_identifier($conn,$column).'>0')) ?: [];
            foreach ($rows as $ref) {
                $match=array_values(array_filter($profiles,fn($p)=>(string)$p['id']===(string)$ref['id']));
                if (!$match) throw new RuntimeException('A referenced global profile is missing. Repair it before downloading.');
                $references[$ref['id']]=$match[0];
            }
        }
        pth_query($conn,'BEGIN READ ONLY');
        foreach ($snapshot['tables'] as $index=>$table) {
            if (!in_array($table,pts_playthrough_tables(),true)) throw new RuntimeException('Unexpected snapshot table.');
            $path=$directory.'/table-'.$index.'.jsonl';$output=fopen($path,'wb');$rows=0;
            $columns=ptx_columns($conn,$stage,$table);
            $names=implode(',',array_map(fn($c)=>pg_escape_identifier($conn,$c['name']),$columns));
            $filter=in_array($table,['conf_opts','general_settings'],true)?' WHERE NOT '.$meta.'.is_global_setting('.pg_escape_literal($conn,$table).',id)':'';
            pth_query($conn,'DECLARE ptx_rows NO SCROLL CURSOR FOR SELECT row_to_json(t)::text AS data FROM (SELECT '.$names.' FROM '.pg_escape_identifier($conn,$stage).'.'.pg_escape_identifier($conn,$table).$filter.') t');
            try {
                do {
                    $batch=pg_fetch_all(pth_query($conn,'FETCH 250 FROM ptx_rows')) ?: [];
                    foreach ($batch as $record) { $line=$record['data']."\n";$expanded+=strlen($line);if (strlen($line)>33554432 || $expanded>21474836480) throw new RuntimeException('This save exceeds the transfer size limit (20 GB total or 32 MB per row).');if (fwrite($output,$line)!==strlen($line)) throw new RuntimeException('Temporary storage is full.');$rows++; }
                } while ($batch);
            } finally { fclose($output);pth_query($conn,'CLOSE ptx_rows'); }
            $sequences=[];
            foreach ($columns as $column) {
                $seq=ptx_sequence($conn,$stage,$table,$column['name']);
                if ($seq) $sequences[$column['name']]=pg_fetch_assoc(pth_query($conn,'SELECT last_value::text,is_called FROM '.$seq));
            }
            $tables[$table]=['sequences'=>(object)$sequences,'columns'=>$columns,'rows'=>$rows,'bytes'=>filesize($path),'sha256'=>hash_file('sha256',$path)];
            if (!$zip->addFile($path,'tables/'.$table.'.jsonl')) throw new RuntimeException('Could not package saved data.');
            ptx_progress($directory,'Preparing download: '.($index+1).' of '.count($snapshot['tables']).' tables');
        }
        pth_query($conn,'COMMIT');
        $manifest=['format'=>'dwemer-playthrough','version'=>1,'product'=>$product['label'],'created_at'=>gmdate('c'),
            'save'=>['name'=>$row['name'],'notes'=>$row['notes']??'','created_at'=>$row['created_at'],'last_gamets'=>$row['last_gamets']],
            'snapshot'=>$snapshot,'profiles'=>array_values($references),'tables'=>$tables];
        $zip->addFromString('manifest.json',json_encode($manifest,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
        if (!$zip->close()) throw new RuntimeException('Could not finish download.');$zip=null;
        foreach (glob($directory.'/table-*.jsonl') ?: [] as $file) unlink($file);
        ptx_progress($directory,'Download ready');
        return ['filename'=>$product['label'].'-'.gmdate('Y-m-d').'-Save-'.$id.'.playthrough.zip','runtime_ready'=>$ready];
    } finally {
        if (pg_transaction_status($conn)!==PGSQL_TRANSACTION_IDLE) @pg_query($conn,'ROLLBACK');
        if ($zip!==null) $zip->close();
        try { if ($stage!=='') ptx_drop_private($conn,$stage);if ($raw!=='') ptx_drop_private($conn,$raw); }
        finally { if ($locked) ptr_unlock($conn);if ($runtime!==null) ptr_runtime_finish_switch($runtime); }
    }
}

// Uploaded SQL types are never trusted; permit only catalog types and reviewed old forms.
function ptx_column_type($column, array $current, string $table, array $names): string {
    if (!is_array($column) || !is_string($column['name']??null) || !is_string($column['type']??null) || !isset($current[$column['name']]) || isset($names[$column['name']])) throw new RuntimeException('This save has unsupported table columns.');
    $target=$current[$column['name']]['type'];$type=$column['type'];
    if ($type===$target) return $target;
    $supported=($table==='memory' && $column['name']==='localts' && in_array($type,['smallint','integer'],true) && $target==='bigint')
        || ($table==='eventlog' && $column['name']==='sess' && in_array($type,['smallint','integer','bigint','character varying'],true) && $target==='text')
        || ($table==='responselog' && in_array($column['name'],['actor','action','text'],true) && preg_match('/^character varying(?:\([1-9][0-9]{0,5}\))?$/D',$type) && $target==='text');
    if (!$supported) throw new RuntimeException('This save needs an unsupported column migration.');
    return $type;
}

// Parse bounded metadata, verify every entry and checksum, and reject extra files.
function ptx_inspect($conn, string $directory): array {
    $zip=new ZipArchive();if ($zip->open($directory.'/save.zip')!==true) throw new RuntimeException('Choose a valid .playthrough.zip file.');
    try {
        $stat=$zip->statName('manifest.json');
        if (!$stat || $stat['size']>2097152) throw new RuntimeException('The package manifest is missing or too large.');
        $manifest=json_decode($zip->getFromName('manifest.json'),true,64,JSON_THROW_ON_ERROR);
        if (($manifest['format']??'')!=='dwemer-playthrough' || ($manifest['version']??0)!==1) throw new RuntimeException('This package format is unsupported.');
        if (($manifest['product']??'')!==ptp_product()['label']) throw new RuntimeException('This save belongs to a different mod.');
        if (!is_array($manifest['tables']??null) || !$manifest['tables'] || count($manifest['tables'])>500 || !isset($manifest['tables']['eventlog'])) throw new RuntimeException('The package table list is invalid.');
        if (!is_array($manifest['snapshot']??null) || !is_array($manifest['save']??null) || !is_array($manifest['profiles']??null) || count($manifest['profiles'])>10000) throw new RuntimeException('The package metadata is invalid.');
        $identity=$manifest['snapshot']['player_identity']??[];
        if (!is_array($identity) || (isset($identity['player_name']) && !is_string($identity['player_name'])) || (isset($identity['player_level']) && (!is_int($identity['player_level']) || $identity['player_level']<0))) throw new RuntimeException('Invalid character metadata.');
        if (isset($identity['player_faction_members'])) {
            if (!is_array($identity['player_faction_members']) || count($identity['player_faction_members'])>10000) throw new RuntimeException('Invalid party metadata.');
            foreach ($identity['player_faction_members'] as $member) if (!is_string($member)) throw new RuntimeException('Invalid party member.');
        }
        $keys=array_keys($manifest['tables']);$declared=$manifest['snapshot']['tables']??null;
        if (!is_array($declared)) throw new RuntimeException('Missing snapshot table policy.');
        sort($keys);sort($declared);if ($keys!==$declared) throw new RuntimeException('Snapshot table lists disagree.');
        foreach (['name','notes','created_at'] as $field) if (!is_string($manifest['save'][$field]??null)) throw new RuntimeException('Invalid save details.');
        $allowed=['manifest.json'];$total=0;
        foreach ($manifest['tables'] as $table=>$info) {
            if (!in_array($table,pts_playthrough_tables(),true) || !preg_match('/^[a-z_][a-z0-9_]*$/D',$table)) throw new RuntimeException('The package contains an excluded or unknown table.');
            if (!is_array($info['columns']??null) || !$info['columns'] || count($info['columns'])>500 || !is_int($info['rows']??null) || $info['rows']<0 || !is_int($info['bytes']??null) || $info['bytes']<0 || !preg_match('/^[a-f0-9]{64}$/D',$info['sha256']??'')) throw new RuntimeException('Invalid table metadata.');
            $file='tables/'.$table.'.jsonl';$allowed[]=$file;$stat=$zip->statName($file);
            if (!$stat || $stat['size']!==$info['bytes']) throw new RuntimeException('Saved table data is missing or truncated.');
            $total+=$stat['size'];if ($total>21474836480) throw new RuntimeException('Expanded save exceeds the 20 GB transfer limit.');
            $current=array_column(ptx_columns($conn,'public',$table),null,'name');$names=[];
            foreach ($info['columns'] as $column) { ptx_column_type($column,$current,$table,$names);$names[$column['name']]=true; }
        }
        foreach ($manifest['tables'] as $table=>$info) {
            $file='tables/'.$table.'.jsonl';$stat=$zip->statName($file);
            ptx_progress($directory,'Checking '.$table.'…');
            $stream=$zip->getStream($file);if (!$stream) throw new RuntimeException('Could not read saved table.');
            $hash=hash_init('sha256');$bytes=hash_update_stream($hash,$stream);fclose($stream);
            if ($bytes!==$stat['size'] || !hash_equals($info['sha256'],hash_final($hash))) throw new RuntimeException('The save failed its integrity check.');
        }
        $seen=[];for ($i=0;$i<$zip->numFiles;$i++) { $name=$zip->getNameIndex($i);if (isset($seen[$name]) || !in_array($name,$allowed,true)) throw new RuntimeException('The package contains unexpected files.');$seen[$name]=true; }
        if (count($seen)!==count($allowed)) throw new RuntimeException('The package is incomplete.');
        if (disk_free_space($directory)<$total*2+16777216) throw new RuntimeException('There is not enough free space to import this save.');
        $profiles=ptx_profiles($conn);$mapping=[];$profileIds=[];
        foreach ($manifest['profiles'] as $profile) {
            if (!is_array($profile) || !ctype_digit((string)($profile['id']??'')) || (int)$profile['id']<1 || !is_string($profile['label']??null) || !is_string($profile['fingerprint']??null) || !preg_match('/^[a-f0-9]{32}$/D',$profile['fingerprint']) || isset($profileIds[(string)$profile['id']])) throw new RuntimeException('Invalid shared profile references.');
            $profileIds[(string)$profile['id']]=true;
            $matches=array_values(array_filter($profiles,fn($p)=>hash_equals($p['fingerprint'],$profile['fingerprint'])));
            if (count($matches)===1) $mapping[(string)$profile['id']]=(int)$matches[0]['id'];
        }
        file_put_contents($directory.'/manifest.json',json_encode($manifest,JSON_THROW_ON_ERROR));
        ptx_progress($directory,'Ready to import');
        $gamets=max(0,(int)($manifest['save']['last_gamets']??0));$gameDate='';
        if ($gamets>0) $gameDate=ptp_product()['meta']==='stobe_meta'?'Day '.stobeGametsToDateParts(stobeGametsNormalize($gamets))['day_number']:(ptp_product()['meta']==='chim_meta'?convert_gamets2skyrim_long_date_no_time($gamets):convert_gamets2fallout_long_date_no_time($gamets));
        return ['game_date'=>$gameDate,'save'=>$manifest['save'],'product'=>$manifest['product'],'identity'=>$manifest['snapshot']['player_identity']??[],
            'profiles'=>$manifest['profiles'],'available_profiles'=>$profiles,'profile_map'=>$mapping,'profiles_version'=>hash('sha256',json_encode($profiles))];
    } finally { $zip->close(); }
}

function ptx_import($conn, string $directory, string $name, array $mapping, string $profilesVersion): array {
    $name=trim($name);if ($name==='' || strlen($name)>160) throw new InvalidArgumentException('Enter a save name up to 160 bytes.');
    $job=basename($directory);$key='PLAYTHROUGH_IMPORT_'.$job;$done=ptr_read($conn,$key,null);if (is_array($done)) return $done;
    $preview=ptx_inspect($conn,$directory);$manifest=json_decode(file_get_contents($directory.'/manifest.json'),true);
    if (!hash_equals($preview['profiles_version'],$profilesVersion)) throw new RuntimeException('Global profiles changed. Check the file again before importing.');
    foreach ($preview['profiles'] as $profile) {
        $selected=$mapping[(string)$profile['id']]??null;
        if (!is_int($selected) || !in_array((string)$selected,array_column($preview['available_profiles'],'id'),true)) throw new InvalidArgumentException('Choose an existing profile for every referenced profile.');
    }
    if (array_diff(array_keys($mapping),array_column($preview['profiles'],'id'))) throw new InvalidArgumentException('Unexpected profile mapping.');
    $product=ptp_product();$meta=$product['meta'];$raw=$product['prefix'].'transfer_'.bin2hex(random_bytes(16));$stage='';$runtime=null;$locked=false;
    $zip=new ZipArchive();if ($zip->open($directory.'/save.zip')!==true) throw new RuntimeException('The uploaded file is unavailable.');
    try {
        pth_query($conn,'BEGIN');pth_query($conn,'CREATE SCHEMA '.pg_escape_identifier($conn,$raw));
        foreach ($manifest['tables'] as $table=>$info) {
            ptx_progress($directory,'Importing '.$table.'…');$current=array_column(ptx_columns($conn,'public',$table),null,'name');$definitions=[];$names=[];
            foreach ($info['columns'] as $column) {
                $type=ptx_column_type($column,$current,$table,$names);$target=$type;
                // Only a catalog type or one of the literal reviewed legacy types reaches SQL.
                $definitions[]=pg_escape_identifier($conn,$column['name']).' '.($type===$target?$target:$type);$names[$column['name']]=true;
            }
            $relation=pg_escape_identifier($conn,$raw).'.'.pg_escape_identifier($conn,$table);
            pth_query($conn,'CREATE TABLE '.$relation.' ('.implode(',',$definitions).')');
            $stream=$zip->getStream('tables/'.$table.'.jsonl');$rows=0;$batch=[];$bytes=0;
            try {
                while (!feof($stream)) {
                    $line=fgets($stream,33554434);if ($line===false) break;
                    if (strlen($line)>33554432 || !str_ends_with($line,"\n")) throw new RuntimeException('A saved row is too large or incomplete.');
                    $value=json_decode($line,true,128,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);
                    if (!is_array($value) || count($value)!==count($names) || array_diff_key($value,$names)) throw new RuntimeException('Saved row columns do not match the manifest.');
                    $batch[]=trim($line);$bytes+=strlen($line);$rows++;
                    if (count($batch)>=250 || $bytes>=1048576) { pth_query($conn,'INSERT INTO '.$relation.' SELECT * FROM json_populate_recordset(NULL::'.$relation.',$1::json)',['['.implode(',',$batch).']']);$batch=[];$bytes=0; }
                }
                if ($batch) pth_query($conn,'INSERT INTO '.$relation.' SELECT * FROM json_populate_recordset(NULL::'.$relation.',$1::json)',['['.implode(',',$batch).']']);
            } finally { fclose($stream); }
            // Recreate owned sequences privately; their definitions come from this server.
            foreach ($names as $column=>$_) {
                $seqName=ptx_sequence($conn,'public',$table,$column);
                $seq=$seqName?pg_fetch_assoc(pth_query($conn,'SELECT seqincrement,seqmin,seqmax,seqstart,seqcycle FROM pg_sequence WHERE seqrelid=$1::regclass',[$seqName])):false;
                if (!$seq) continue;
                $saved=$info['sequences'][$column]??null;
                if (!is_array($saved) || !is_string($saved['last_value']??null) || !preg_match('/^-?[0-9]{1,19}$/D',$saved['last_value']) || !in_array($saved['is_called']??null,['t','f'],true)) throw new RuntimeException('Saved sequence state is missing or invalid.');
                $private=pg_escape_identifier($conn,$raw).'.'.pg_escape_identifier($conn,'transfer_seq_'.substr(hash('sha256',$table.'.'.$column),0,20));
                pth_query($conn,'CREATE SEQUENCE '.$private.' INCREMENT BY '.$seq['seqincrement'].' MINVALUE '.$seq['seqmin'].' MAXVALUE '.$seq['seqmax'].' START WITH '.$seq['seqstart'].($seq['seqcycle']==='t'?' CYCLE':' NO CYCLE').' OWNED BY '.$relation.'.'.pg_escape_identifier($conn,$column));
                pth_query($conn,'ALTER TABLE '.$relation.' ALTER COLUMN '.pg_escape_identifier($conn,$column).' SET DEFAULT nextval('.pg_escape_literal($conn,$private).'::regclass)');
                pth_query($conn,'SELECT setval($1::regclass,$2::bigint,$3::boolean)',[$private,$saved['last_value'],$saved['is_called']]);
            }
            if ($rows!==$info['rows']) throw new RuntimeException('Saved row count does not match the manifest.');
            if (in_array($table,['conf_opts','general_settings'],true)) pth_query($conn,'DELETE FROM '.$relation.' WHERE '.$meta.'.is_global_setting($1,id)',[$table]);
        }
        // Map every positive profile reference simultaneously, without ID collision chains.
        foreach (ptx_profile_columns($conn,$raw,array_keys($manifest['tables'])) as [$table,$column]) {
            $rel=pg_escape_identifier($conn,$raw).'.'.pg_escape_identifier($conn,$table);$col=pg_escape_identifier($conn,$column);
            $missing=pg_fetch_result(pth_query($conn,'SELECT count(*) FROM '.$rel.' WHERE '.$col.'>0 AND NOT ($1::jsonb ? '.$col.'::text)',[json_encode((object)$mapping)]),0,0);
            if ((int)$missing>0) throw new RuntimeException('The package is missing a referenced shared profile.');
            pth_query($conn,'UPDATE '.$rel.' SET '.$col.'=($1::jsonb->>'.$col.'::text)::integer WHERE '.$col.'>0',[json_encode((object)$mapping)]);
        }
        pth_query($conn,'COMMENT ON SCHEMA '.pg_escape_identifier($conn,$raw).' IS '.pg_escape_literal($conn,json_encode($manifest['snapshot'],JSON_THROW_ON_ERROR)));
        ptx_progress($directory,'Checking compatibility…');$runtime=ptr_runtime_begin_switch(30,$conn);
        if (!ptr_lock($conn)) throw new RuntimeException('Another Playthrough Save operation is running.');$locked=true;
        pth_query($conn,'LOCK TABLE public.core_profiles IN SHARE MODE');
        if (!hash_equals($profilesVersion,hash('sha256',json_encode(ptx_profiles($conn))))) throw new RuntimeException('Global profiles changed. Check the file again.');
        $stage=pts_prepare_playthrough($conn,$raw);
        $snapshot=json_decode(pg_fetch_result(pth_query($conn,"SELECT obj_description(oid,'pg_namespace') FROM pg_namespace WHERE nspname=$1",[$stage]),0,0),true);
        $snapshot['imported_from']=['save'=>$manifest['save'],'created_at'=>$manifest['created_at']??null];
        $snapshot['player_identity']=$manifest['snapshot']['player_identity']??['version'=>1];$snapshot['migrations']=$manifest['snapshot']['migrations']??[];
        pth_query($conn,'COMMENT ON SCHEMA '.pg_escape_identifier($conn,$stage).' IS '.pg_escape_literal($conn,json_encode($snapshot,JSON_THROW_ON_ERROR)));
        $final=$product['prefix'].'save_'.bin2hex(random_bytes(16));pth_query($conn,'ALTER SCHEMA '.pg_escape_identifier($conn,$stage).' RENAME TO '.pg_escape_identifier($conn,$final));$stage='';
        $base=$name;$suffix=2;while (pg_num_rows(pth_query($conn,"SELECT id FROM {$meta}.playthrough_profiles WHERE lower(name)=lower($1)",[$name]))) $name=substr($base,0,145).' ('.$suffix++.')';
        $identity=$snapshot['player_identity'];$knowledge=$meta==='chim_meta'?'oghma':($meta==='stobe_meta'?'world_knowledge':'worldknowledge');
        $fields=['name'=>$name,'notes'=>(string)($manifest['save']['notes']??''),'schema_name'=>$final,'storage_type'=>'schema','size_bytes'=>pts_get_schema_size($conn,$final),'retention_kind'=>'manual','is_active'=>'false',
            'player_name'=>(string)($identity['player_name']??''),'game'=>['CHIM'=>'Skyrim','STOBE'=>'Kenshi','DIALECTIC'=>'Fallout'][$product['label']],
            'eventlog_count'=>(int)pg_fetch_result(pth_query($conn,'SELECT count(*) FROM '.pg_escape_identifier($conn,$final).'.eventlog'),0,0),
            'last_gamets'=>(int)pg_fetch_result(pth_query($conn,'SELECT COALESCE(max(gamets),0) FROM '.pg_escape_identifier($conn,$final).'.eventlog'),0,0)];
        if ($meta==='stobe_meta') $fields['player_faction_members']=json_encode($identity['player_faction_members']??[]);
        if (in_array($knowledge,$snapshot['tables'],true)) $fields[$meta==='dialectic_meta'?'worldknowledge_count':'oghma_count']=(int)pg_fetch_result(pth_query($conn,'SELECT count(*) FROM '.pg_escape_identifier($conn,$final).'.'.pg_escape_identifier($conn,$knowledge)),0,0);
        $slots=array_map(fn($i)=>'$'.$i,range(1,count($fields)));
        $id=(int)pg_fetch_result(pth_query($conn,"INSERT INTO {$meta}.playthrough_profiles(".implode(',',array_keys($fields)).') VALUES('.implode(',',$slots).') RETURNING id',array_values($fields)),0,0);
        $result=['id'=>$id,'name'=>$name,'message'=>'Imported '.$name.'. Your current playthrough is unchanged.'];
        ptr_write($conn,$key,$result);ptx_drop_private($conn,$raw);$raw='';pth_query($conn,'COMMIT');
        ptr_unlock($conn);$locked=false;$result['runtime_ready']=ptr_runtime_finish_switch($runtime);
        ptx_progress($directory,'Import complete');return $result;
    } catch (Throwable $e) {
        if (pg_transaction_status($conn)!==PGSQL_TRANSACTION_IDLE) @pg_query($conn,'ROLLBACK');throw $e;
    } finally {
        $zip->close();if ($locked) ptr_unlock($conn);if ($runtime!==null) ptr_runtime_finish_switch($runtime);
    }
}
