<?php
require_once __DIR__ . '/playthrough_home.php';

// Both directions accept the same JSON nesting depth without logging saved row contents.
function ptx_decode_row(string $line, string $table, int $row): array {
    try {
        $value=json_decode($line,true,512,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);
    } catch (JsonException $error) {
        error_log('Playthrough transfer: Invalid JSON in table '.$table.', row '.$row.': '.$error->getMessage());
        throw new RuntimeException('A saved row contains invalid or overly nested JSON. Check the server log for details.',0,$error);
    }
    if (!is_array($value)) throw new RuntimeException('A saved row must contain named columns.');
    return $value;
}

// Transfer files are private, session-owned and short lived; never extract ZIP paths.
function ptx_directory(string $id = ''): string {
    $base = sys_get_temp_dir().'/dwemer-playthrough-'.substr(hash('sha256',dirname(__DIR__)),0,16);
    if (!is_dir($base) && !mkdir($base,0700,true)) throw new RuntimeException('Temporary storage is unavailable.');
    if ($id==='') return $base;
    if (!preg_match('/^[a-f0-9]{32}$/D',$id)) throw new InvalidArgumentException('Invalid transfer.');
    return $base.'/'.$id;
}

// Returns whether the job directory is gone, so callers can report a failed cleanup.
function ptx_clean(string $directory): bool {
    // Only our flat, random job directories are eligible for removal.
    if (dirname($directory)!==ptx_directory() || !preg_match('/^[a-f0-9]{32}$/D',basename($directory))) return false;
    foreach (glob($directory.'/*') ?: [] as $file) if (is_file($file) && !is_link($file)) @unlink($file);
    @rmdir($directory);
    clearstatcache();
    if (is_dir($directory)) { error_log('Playthrough transfer: could not remove temporary job '.basename($directory)); return false; }
    return true;
}

function ptx_job(string $owner, string $kind): string {
    if (!in_array($kind,['import','export'],true)) throw new InvalidArgumentException('Invalid transfer type.');
    foreach (glob(ptx_directory().'/*',GLOB_ONLYDIR) ?: [] as $directory) {
        // Downloads released by a closed dialog wait briefly in case the browser request is still queued.
        $released=@filemtime($directory.'/discard');
        if (filemtime($directory)>time()-86400 && ($released===false || $released>time()-600)) continue;
        $lock=@fopen($directory.'/lock','c');
        if ($lock && flock($lock,LOCK_EX|LOCK_NB)) ptx_clean($directory);
        if ($lock) fclose($lock);
    }
    $id=bin2hex(random_bytes(16));$directory=ptx_directory($id);
    if (!mkdir($directory,0700)) throw new RuntimeException('Could not prepare the transfer.');
    file_put_contents($directory.'/owner.json',json_encode(['owner'=>$owner,'kind'=>$kind,'created'=>time()],JSON_THROW_ON_ERROR));
    touch($directory.'/heartbeat');
    ptx_progress($directory,'Ready');return $id;
}

function ptx_owned(string $id, string $owner): string {
    $directory=ptx_directory($id);$info=json_decode((string)@file_get_contents($directory.'/owner.json'),true);
    if (!$info || !hash_equals($info['owner'],$owner) || $info['created']<time()-86400) throw new RuntimeException('This transfer expired. Start again.');
    return $directory;
}

function ptx_progress(string $directory, string $message, array $extra = []): void {
    $tmp=$directory.'/status.'.bin2hex(random_bytes(6)).'.tmp';
    file_put_contents($tmp,json_encode(['message'=>$message]+$extra,JSON_THROW_ON_ERROR));rename($tmp,$directory.'/status.json');
}

final class PtxCancelled extends RuntimeException {}

// Export stops at safe checkpoints: an explicit cancel, or status polling that stopped with the page.
function ptx_check_cancel(string $directory): void {
    clearstatcache();
    if (is_file($directory.'/cancel')) throw new PtxCancelled('Download cancelled. Temporary files were removed.');
    $seen=@filemtime($directory.'/heartbeat');
    if ($seen!==false && $seen<time()-180) throw new PtxCancelled('Download stopped because its page was closed. Temporary files were removed.');
}

// Interrupt SQL only for this session-owned export; its connection is tagged with the random job ID.
function ptx_interrupt(string $job): void {
    if (!preg_match('/^[a-f0-9]{32}$/D',$job)) throw new InvalidArgumentException('Invalid transfer.');
    $conn=ptp_connect();
    if (!$conn) { error_log('Playthrough transfer: cancellation will wait for the next export checkpoint; database unavailable.'); return; }
    try { pth_query($conn,'SELECT pg_cancel_backend(pid) FROM pg_stat_activity WHERE datname=current_database() AND application_name=$1 AND pid<>pg_backend_pid()',['chim_ptx_'.$job]); }
    finally { pg_close($conn); }
}

function ptx_size(float $bytes): string {
    foreach (['bytes','KB','MB','GB','TB'] as $unit) { if ($bytes<1024 || $unit==='TB') break; $bytes/=1024; }
    return ($unit==='bytes'?(string)(int)$bytes:number_format($bytes,1)).' '.$unit;
}

// Transfer files use PHP's temporary filesystem, which may differ from PostgreSQL storage.
function ptx_space(string $directory): void {
    $free=@disk_free_space($directory);
    if ($free!==false && $free<268435456) throw new RuntimeException('Temporary storage is nearly full: '.ptx_size($free).' free in '.dirname($directory).'. Free space there, then try again.');
}

// Streams deflated ZIP entries straight to disk, so no expanded copy of the save is kept.
// Local headers reserve ZIP64 sizes; central-directory fields expand when sizes or offsets need them.
class PtxZipWriter {
    protected const LIMIT = 0xFFFFFFFF;
    private $file; private $deflate; private $crc; private int $offset=0; private array $central=[];
    private string $name=''; private int $start=0; private int $size=0; private int $packed=0; private int $time; private int $date;

    public function __construct(private string $path) {
        $this->file=@fopen($path,'xb');
        if (!$this->file) throw new RuntimeException('Could not create download.');
        $now=getdate();
        $this->time=($now['hours']<<11)|($now['minutes']<<5)|intdiv($now['seconds'],2);
        $this->date=(max(0,$now['year']-1980)<<9)|($now['mon']<<5)|$now['mday'];
    }
    private function put(string $bytes): void {
        if ($bytes!=='' && fwrite($this->file,$bytes)!==strlen($bytes)) throw new RuntimeException('Temporary storage is full.');
        $this->offset+=strlen($bytes);
    }
    public function begin(string $name): void {
        $this->name=$name;$this->start=$this->offset;$this->size=$this->packed=0;
        $this->crc=hash_init('crc32b');$this->deflate=deflate_init(ZLIB_ENCODING_RAW,['level'=>6]);
        // Flag 8: CRC and sizes follow the data; flag 0x800: UTF-8 name.
        // Entry sizes are unknown until streaming ends. Reserve ZIP64 locally even for small entries.
        $extra=pack('vvPP',1,16,0,0);
        $this->put(pack('VvvvvvVVVvv',0x04034b50,45,0x0808,8,$this->time,$this->date,0,0xFFFFFFFF,0xFFFFFFFF,strlen($name),strlen($extra)).$name.$extra);
    }
    public function write(string $data): void {
        hash_update($this->crc,$data);$this->size+=strlen($data);
        $out=deflate_add($this->deflate,$data,ZLIB_NO_FLUSH);$this->packed+=strlen($out);$this->put($out);
    }
    public function end(): void {
        $out=deflate_add($this->deflate,'',ZLIB_FINISH);$this->packed+=strlen($out);$this->put($out);$this->deflate=null;
        $crc=unpack('N',hash_final($this->crc,true))[1];
        $this->put(pack('VVPP',0x08074b50,$crc,$this->packed,$this->size));
        $extra='';$size=$this->size;$packed=$this->packed;$start=$this->start;
        if ($size>=static::LIMIT) { $extra.=pack('P',$size);$size=0xFFFFFFFF; }
        if ($packed>=static::LIMIT) { $extra.=pack('P',$packed);$packed=0xFFFFFFFF; }
        if ($start>=static::LIMIT) { $extra.=pack('P',$start);$start=0xFFFFFFFF; }
        if ($extra!=='') $extra=pack('vv',1,strlen($extra)).$extra;
        $this->central[]=pack('VvvvvvvVVVvvvvvVV',0x02014b50,45,45,0x0808,8,$this->time,$this->date,$crc,$packed,$size,strlen($this->name),strlen($extra),0,0,0,0,$start).$this->name.$extra;
    }
    public function bytes(): int { return $this->offset; }
    public function finish(): void {
        $start=$this->offset;$list=implode('',$this->central);$this->put($list);$count=count($this->central);
        if ($count>=0xFFFF || strlen($list)>=static::LIMIT || $start>=static::LIMIT) {
            $record=$this->offset;
            $this->put(pack('VPvvVVPPPP',0x06064b50,44,45,45,0,0,$count,$count,strlen($list),$start).pack('VVPV',0x07064b50,0,$record,1));
            $this->put(pack('VvvvvVVv',0x06054b50,0,0,0xFFFF,0xFFFF,0xFFFFFFFF,0xFFFFFFFF,0));
        } else $this->put(pack('VvvvvVVv',0x06054b50,0,0,$count,$count,strlen($list),$start,0));
        $file=$this->file;$this->file=null;
        $ok=fflush($file);$ok=fclose($file) && $ok;
        if (!$ok) throw new RuntimeException('Could not finish download.');
    }
    public function abort(): void {
        if ($this->file) fclose($this->file);
        $this->file=null;
        if (is_file($this->path) && !@unlink($this->path)) error_log('Playthrough transfer: could not remove a partial download.');
    }
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

// Collect saved profile references before any copy; missing global profiles stop with bounded detail.
function ptx_profile_references($conn, string $schema, array $tables, array $profiles, bool $active): array {
    $known=array_column($profiles,null,'id');$references=[];$missing=[];$records=0;$names=[];
    foreach (ptx_profile_columns($conn,$schema,$tables) as [$table,$column]) {
        $relation=pg_escape_identifier($conn,$schema).'.'.pg_escape_identifier($conn,$table);$col=pg_escape_identifier($conn,$column);
        $lost=[];
        foreach (pg_fetch_all(pth_query($conn,'SELECT '.$col.'::text AS id,count(*) AS n FROM '.$relation.' WHERE '.$col.'>0 GROUP BY 1')) ?: [] as $ref) {
            if (isset($known[$ref['id']])) { $references[$ref['id']]=$known[$ref['id']];continue; }
            $missing[$ref['id']]=true;$lost[]=(int)$ref['id'];$records+=(int)$ref['n'];
        }
        if ($lost && $table==='core_npc_master' && count($names)<5) foreach (pg_fetch_all(pth_query($conn,'SELECT DISTINCT npc_name FROM '.$relation.' WHERE '.$col.'=ANY($1::bigint[]) ORDER BY npc_name LIMIT 5',['{'.implode(',',$lost).'}'])) ?: [] as $npc) $names[]=$npc['npc_name'];
    }
    if (!$missing) return $references;
    $ids=array_keys($missing);sort($ids,SORT_NUMERIC);
    $list=implode(', ',array_slice($ids,0,10)).(count($ids)>10?' and '.(count($ids)-10).' more':'');
    $npcs=$names?' (for example '.implode(', ',array_slice(array_unique($names),0,5)).')':'';
    throw new RuntimeException($records.' saved record'.($records===1?'':'s').$npcs.' use global profile ID'.(count($ids)===1?' ':'s ').$list.', which no longer exist'.(count($ids)===1?'s':'').' in Profiles. '
        .($active?'Choose an existing profile for those NPCs, then download again.':'Restore those shared profile IDs from a database backup, or ask for help repairing this saved copy. Creating a new profile with a different ID will not repair it.').' Nothing was changed.');
}

// Map serial/identity columns to sequences in one catalog read; owned sequences win over defaults.
function ptx_sequence_map($conn, string $schema): array {
    $rows=pg_fetch_all(pth_query($conn,"SELECT t.relname AS tbl,a.attname AS col,s.oid::regclass::text AS seq,0 AS rank FROM pg_depend d JOIN pg_class s ON s.oid=d.objid AND s.relkind='S' JOIN pg_class t ON t.oid=d.refobjid JOIN pg_namespace n ON n.oid=t.relnamespace JOIN pg_attribute a ON a.attrelid=t.oid AND a.attnum=d.refobjsubid WHERE d.classid='pg_class'::regclass AND d.refclassid='pg_class'::regclass AND d.deptype IN ('a','i') AND n.nspname=$1
        UNION ALL SELECT t.relname,a.attname,s.oid::regclass::text,1 FROM pg_depend d JOIN pg_attrdef def ON d.classid='pg_attrdef'::regclass AND d.objid=def.oid JOIN pg_class s ON s.oid=d.refobjid AND s.relkind='S' JOIN pg_class t ON t.oid=def.adrelid JOIN pg_namespace n ON n.oid=t.relnamespace JOIN pg_attribute a ON a.attrelid=t.oid AND a.attnum=def.adnum WHERE d.refclassid='pg_class'::regclass AND n.nspname=$1 ORDER BY rank",[$schema])) ?: [];
    $map=[];foreach ($rows as $row) $map[$row['tbl']][$row['col']]??=$row['seq'];
    return $map;
}

// The capture manifest version is owned by SQL; read it instead of keeping a second constant.
function ptx_policy_version($conn): int {
    $source=pg_fetch_result(pth_query($conn,"SELECT prosrc FROM pg_proc WHERE oid=to_regprocedure('chim_meta.capture_playthrough(text,text[])')"),0,0);
    if (!preg_match("/'table_policy_version',\\s*([0-9]+)/",(string)$source,$match)) throw new RuntimeException('Playthrough database functions are unavailable.');
    return (int)$match[1];
}

// A violated rule aborts the export transaction; the rolled-back stage is never kept.
function ptx_private_rule($conn, string $table, string $sql): void {
    if (@pg_query($conn,$sql)) return;
    error_log('Playthrough transfer: '.$table.' failed a current database rule: '.pg_last_error($conn));
    throw new RuntimeException('This save\'s '.$table.' data does not meet the current database rules, so it cannot be downloaded. Details are in the server log.');
}

// Apply the live table rules to the private stage only, so an older save that could not be
// imported fails here. Import and activation still run the full restore validation.
function ptx_check_private($conn, string $stage, array $tables): void {
    $tail="regexp_replace(pg_get_indexdef(indexrelid),'^CREATE UNIQUE INDEX \\S+ ON (ONLY )?\\S+ ','')";
    foreach ($tables as $table) {
        if ($table==='database_versioning') continue;
        $private=pg_escape_identifier($conn,$stage).'.'.pg_escape_identifier($conn,$table);$args=['public.'.pg_escape_identifier($conn,$table),$private];
        foreach (pg_fetch_all(pth_query($conn,'SELECT a.attname FROM pg_attribute a JOIN pg_attribute s ON s.attrelid=$2::regclass AND s.attname=a.attname AND NOT s.attisdropped WHERE a.attrelid=$1::regclass AND a.attnum>0 AND NOT a.attisdropped AND a.attnotnull AND NOT s.attnotnull',$args)) ?: [] as $rule)
            ptx_private_rule($conn,$table,'ALTER TABLE '.$private.' ALTER COLUMN '.pg_escape_identifier($conn,$rule['attname']).' SET NOT NULL');
        foreach (pg_fetch_all(pth_query($conn,"SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conrelid=$1::regclass AND contype='c' AND convalidated EXCEPT SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conrelid=$2::regclass AND contype='c'",$args)) ?: [] as $rule)
            ptx_private_rule($conn,$table,'ALTER TABLE '.$private.' ADD '.$rule['def']);
        foreach (pg_fetch_all(pth_query($conn,"SELECT $tail AS def FROM pg_index WHERE indrelid=$1::regclass AND indisunique AND indisvalid EXCEPT SELECT $tail FROM pg_index WHERE indrelid=$2::regclass AND indisunique",$args)) ?: [] as $rule)
            ptx_private_rule($conn,$table,'CREATE UNIQUE INDEX ON '.$private.' '.$rule['def']);
        $keys=pg_fetch_all(pth_query($conn,"SELECT rn.nspname AS schema,rc.relname AS ref,json_agg(a.attname ORDER BY k.i) AS cols,json_agg(b.attname ORDER BY k.i) AS refs FROM pg_constraint c JOIN pg_class rc ON rc.oid=c.confrelid JOIN pg_namespace rn ON rn.oid=rc.relnamespace CROSS JOIN LATERAL unnest(c.conkey,c.confkey) WITH ORDINALITY k(ck,fk,i) JOIN pg_attribute a ON a.attrelid=c.conrelid AND a.attnum=k.ck JOIN pg_attribute b ON b.attrelid=c.confrelid AND b.attnum=k.fk WHERE c.conrelid=$1::regclass AND c.contype='f' AND c.convalidated GROUP BY c.oid,rn.nspname,rc.relname",[$args[0]])) ?: [];
        foreach ($keys as $key) {
            // References between saved tables are checked inside the stage; others against the live global table.
            $target=($key['schema']==='public' && in_array($key['ref'],$tables,true)?pg_escape_identifier($conn,$stage):pg_escape_identifier($conn,$key['schema'])).'.'.pg_escape_identifier($conn,$key['ref']);
            $cols=json_decode($key['cols'],true);$refs=json_decode($key['refs'],true);$present=[];$match=[];
            foreach ($cols as $i=>$col) { $present[]='c.'.pg_escape_identifier($conn,$col).' IS NOT NULL';$match[]='r.'.pg_escape_identifier($conn,$refs[$i]).'=c.'.pg_escape_identifier($conn,$col); }
            $orphans=(int)pg_fetch_result(pth_query($conn,'SELECT count(*) FROM '.$private.' c WHERE '.implode(' AND ',$present).' AND NOT EXISTS (SELECT 1 FROM '.$target.' r WHERE '.implode(' AND ',$match).')'),0,0);
            if ($orphans>0) throw new RuntimeException('This save has '.$orphans.' '.$table.' row'.($orphans===1?'':'s').' that refer to missing '.$key['ref'].' records, so it cannot be downloaded.');
        }
    }
}

// Active progress streams from one read-only snapshot taken while game requests are paused.
// Inactive saves are normalized in a private stage inside the same transaction, which is
// rolled back afterwards, so cancellation or failure never leaves a database copy behind.
function ptx_export($conn, int $id, string $expected, string $directory): array {
    $product=ptp_product();$meta=$product['meta'];$runtime=null;$locked=false;$zip=null;$ready=true;$part=$directory.'/save.zip.part';
    try {
        ptx_progress($directory,'Checking the save…',['phase'=>'check']);
        ptx_space($directory);
        pth_query($conn,'SELECT set_config($1,$2,false)',['application_name','chim_ptx_'.basename($directory)]);
        if (!pts_ensure_functions($conn)) throw new RuntimeException('Playthrough database functions are unavailable.');
        $row=pg_fetch_assoc(pth_query($conn,"SELECT is_active,schema_name FROM {$meta}.playthrough_profiles WHERE id=$1 AND storage_type='schema'",[$id]));
        if (!$row) throw new RuntimeException('That saved copy is unavailable.');
        $active=$row['is_active']==='t';$selected=pts_playthrough_tables();
        // Fast rejection before pausing the game or copying anything; repeated below inside the snapshot.
        ptx_profile_references($conn,$active?'public':$row['schema_name'],$selected,ptx_profiles($conn),$active);
        $tables=json_decode(pg_fetch_result(pth_query($conn,"SELECT COALESCE(json_agg(tablename ORDER BY tablename),'[]') FROM pg_tables WHERE schemaname='public' AND tablename IN (SELECT jsonb_array_elements_text($1::jsonb))",[json_encode($selected)]),0,0),true);
        if (!in_array('eventlog',$tables,true)) throw new RuntimeException('Playthrough source tables are unavailable.');
        ptx_check_cancel($directory);
        if ($active) { ptx_progress($directory,'Pausing game requests for a consistent copy…',['phase'=>'snapshot']);$runtime=ptr_runtime_begin_switch(30,$conn); }
        if (!ptr_lock($conn)) throw new RuntimeException('Another Playthrough Save operation is running.');
        $locked=true;
        pth_query($conn,'BEGIN ISOLATION LEVEL REPEATABLE READ'.($active?' READ ONLY':''));
        // Like pg_dump: ACCESS SHARE allows gameplay writes but blocks table rewrites until packaging ends.
        if ($active) pth_query($conn,'LOCK TABLE '.implode(',',array_map(fn($t)=>'public.'.pg_escape_identifier($conn,$t),$tables)).' IN ACCESS SHARE MODE');
        $state=pth_state($conn);
        if (!hash_equals($state['token'],$expected)) throw new RuntimeException('The active playthrough changed. Reload and try again.');
        $row=pg_fetch_assoc(pth_query($conn,"SELECT * FROM {$meta}.playthrough_profiles WHERE id=$1",[$id]));
        if (!$row || $row['storage_type']!=='schema' || ($row['is_active']==='t')!==$active) throw new RuntimeException('That saved copy changed. Reload and try again.');
        $profiles=ptx_profiles($conn);
        if ($active) {
            $source='public';
            $references=ptx_profile_references($conn,$source,$tables,$profiles,true);
            $identity=json_decode(pg_fetch_result(pth_query($conn,"SELECT chim_meta.playthrough_identity('public')"),0,0),true);
            $snapshot=['format'=>'chim_selected_tables_v2','table_policy_version'=>ptx_policy_version($conn),'tables'=>$tables,'missing_tables'=>[],'empty_tables'=>[],'upgrade_version'=>2,'player_identity'=>$identity];
        } else {
            $size=pts_get_schema_size($conn,$row['schema_name']);
            ptx_progress($directory,'Preparing a private copy for older-save checks'.($size>0?' (uses about '.ptx_size($size).' of database storage until the download is built)':'').'…',['phase'=>'prepare']);
            $original=json_decode((string)pg_fetch_result(pth_query($conn,"SELECT obj_description(oid,'pg_namespace') FROM pg_namespace WHERE nspname=$1",[$row['schema_name']]),0,0),true) ?: [];
            $source=pts_prepare_playthrough($conn,$row['schema_name'],false);
            ptr_unlock($conn);$locked=false;
            ptx_check_cancel($directory);
            ptx_progress($directory,'Checking the private copy…',['phase'=>'prepare']);
            $snapshot=json_decode(pg_fetch_result(pth_query($conn,"SELECT obj_description(oid,'pg_namespace') FROM pg_namespace WHERE nspname=$1",[$source]),0,0),true);
            $snapshot['player_identity']=$original['player_identity'] ?? ['version'=>1,'player_name'=>$row['player_name']??'','player_faction_members'=>json_decode($row['player_faction_members']??'[]',true)];
            unset($snapshot['source_schema']);
            if ($snapshot['tables']!==$tables) throw new RuntimeException('Snapshot tables do not match this server schema.');
            $references=ptx_profile_references($conn,$source,$tables,$profiles,false);
            ptx_check_private($conn,$source,$tables);
        }
        $snapshot['migrations']=json_decode(pg_fetch_result(pth_query($conn,"SELECT COALESCE(jsonb_object_agg(tablename,version),'{}') FROM public.database_versioning"),0,0),true);
        // Sequence values are not transactional, so read them before game requests resume.
        $map=ptx_sequence_map($conn,$source);$live=$active?$map:ptx_sequence_map($conn,'public');$sequences=[];
        foreach ($tables as $table) foreach ($live[$table]??[] as $column=>$_) {
            if (!isset($map[$table][$column])) throw new RuntimeException('This save is missing ID counter state for '.$table.'.'.$column.', so it cannot be downloaded.');
            $sequences[$table][$column]=pg_fetch_assoc(pth_query($conn,'SELECT last_value::text,is_called FROM '.$map[$table][$column]));
        }
        $row['last_gamets']=(int)pg_fetch_result(pth_query($conn,'SELECT COALESCE(max(gamets),0) FROM '.pg_escape_identifier($conn,$source).'.eventlog'),0,0);
        if ($locked) { ptr_unlock($conn);$locked=false; }
        if ($runtime!==null) $ready=ptr_runtime_finish_switch($runtime);
        $zip=new PtxZipWriter($part);$info=[];$expanded=0;$checked=0;$count=count($tables);$tick=0.0;
        foreach ($tables as $index=>$table) {
            ptx_check_cancel($directory);
            $columns=ptx_columns($conn,$source,$table);
            $names=implode(',',array_map(fn($c)=>pg_escape_identifier($conn,$c['name']),$columns));
            $filter=in_array($table,['conf_opts','general_settings'],true)?' WHERE NOT '.$meta.'.is_global_setting('.pg_escape_literal($conn,$table).',id)':'';
            $zip->begin('tables/'.$table.'.jsonl');$hash=hash_init('sha256');$rows=0;$bytes=0;
            pth_query($conn,'DECLARE ptx_rows NO SCROLL CURSOR FOR SELECT row_to_json(t)::text AS data FROM (SELECT '.$names.' FROM '.pg_escape_identifier($conn,$source).'.'.pg_escape_identifier($conn,$table).$filter.') t');
            do {
                $batch=pg_fetch_all(pth_query($conn,'FETCH 250 FROM ptx_rows')) ?: [];
                foreach ($batch as $record) {
                    $line=$record['data']."\n";$expanded+=strlen($line);
                    if (strlen($line)>33554432 || $expanded>21474836480) throw new RuntimeException('This save exceeds the transfer size limit (20 GB total or 32 MB per row).');
                    ptx_decode_row($line,$table,++$rows);
                    hash_update($hash,$line);$bytes+=strlen($line);$zip->write($line);
                }
                if ($zip->bytes()-$checked>67108864) { ptx_space($directory);$checked=$zip->bytes(); }
                if (microtime(true)-$tick>2) {
                    ptx_check_cancel($directory);$tick=microtime(true);
                    ptx_progress($directory,'Building download: table '.($index+1).' of '.$count.' ('.$table.'), '.ptx_size($zip->bytes()).' written…',['phase'=>'write','step'=>$index,'total'=>$count]);
                }
            } while ($batch);
            pth_query($conn,'CLOSE ptx_rows');$zip->end();
            $info[$table]=['sequences'=>(object)($sequences[$table]??[]),'columns'=>$columns,'rows'=>$rows,'bytes'=>$bytes,'sha256'=>hash_final($hash)];
            ptx_progress($directory,'Building download: '.($index+1).' of '.$count.' tables, '.ptx_size($zip->bytes()).' written…',['phase'=>'write','step'=>$index+1,'total'=>$count]);
        }
        $manifest=['format'=>'dwemer-playthrough','version'=>1,'product'=>$product['label'],'created_at'=>gmdate('c'),
            'save'=>['name'=>$row['name'],'notes'=>$row['notes']??'','created_at'=>$row['created_at'],'last_gamets'=>$row['last_gamets']],
            'snapshot'=>$snapshot,'profiles'=>array_values($references),'tables'=>$info];
        // Rolling back discards the private stage; the snapshot is no longer needed.
        pth_query($conn,'ROLLBACK');
        ptx_check_cancel($directory);
        $zip->begin('manifest.json');$zip->write(json_encode($manifest,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));$zip->end();$zip->finish();$zip=null;
        if (!rename($part,$directory.'/save.zip')) throw new RuntimeException('Could not finish download.');
        ptx_check_cancel($directory);
        ptx_progress($directory,'Download ready',['phase'=>'ready']);
        return ['filename'=>$product['label'].'-'.gmdate('Y-m-d').'-Save-'.$id.'.playthrough.zip','runtime_ready'=>$ready];
    } catch (Throwable $error) {
        if (is_file($directory.'/save.zip') && !@unlink($directory.'/save.zip')) error_log('Playthrough transfer: could not remove a failed download.');
        clearstatcache();
        if (!$error instanceof PtxCancelled && is_file($directory.'/cancel')) throw new PtxCancelled('Download cancelled. Temporary files were removed.',0,$error);
        throw $error;
    } finally {
        if (pg_transaction_status($conn)!==PGSQL_TRANSACTION_IDLE && !@pg_query($conn,'ROLLBACK')) error_log('Playthrough transfer: export rollback failed: '.pg_last_error($conn));
        if ($zip!==null) $zip->abort();
        if (is_file($part) && !@unlink($part)) error_log('Playthrough transfer: could not remove a partial download.');
        if ($locked) ptr_unlock($conn);
        if ($runtime!==null) ptr_runtime_finish_switch($runtime);
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
        if (isset($identity['character_id']) && (!is_string($identity['character_id']) || ($identity['character_id'] !== '' && !preg_match('/^[a-f0-9]{32}$/D', $identity['character_id'])))) throw new RuntimeException('Invalid character identity.');
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
                    $value=ptx_decode_row($line,$table,$rows+1);
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
        ptr_ensure_schema($conn);
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
