<?php
ob_start();
if (session_status()===PHP_SESSION_NONE) session_start();
header('Content-Type: application/json');header('Cache-Control: no-store');
require_once dirname(__DIR__,2).'/lib/playthrough_transfer.php';
$conn=null;$lock=null;$directory=null;$held=false;
try {
    $method=$_SERVER['REQUEST_METHOD']??'GET';$input=$method==='POST'?$_POST:$_GET;
    $action=$input['action']??'';
    if (!is_string($action) || !in_array($method,['GET','POST'],true) || !in_array($action,$method==='GET'?['status','download']:['allocate','export','inspect','import','cancel'],true)) throw new InvalidArgumentException('Invalid transfer request.');
    if ($method==='POST' && (!is_string($_POST['csrf_token']??null) || empty($_SESSION['ptm_csrf']) || !hash_equals($_SESSION['ptm_csrf'],$_POST['csrf_token']))) {
        http_response_code(403);throw new RuntimeException('Security check failed. Reload this page.');
    }
    // Release the session so progress polling can run during uploads and database work.
    $owner=hash('sha256',session_id());session_write_close();
    if ($action==='allocate') {
        if (!is_string($input['kind']??null)) throw new InvalidArgumentException('Choose an import or download.');
        echo json_encode(['ok'=>true,'job'=>ptx_job($owner,$input['kind'])]);exit;
    }
    if (!is_string($input['job']??null)) throw new InvalidArgumentException('Missing transfer.');
    // Cancelling is idempotent: a job that already removed itself needs no further cleanup.
    if ($action==='cancel' && !is_dir(ptx_directory($input['job']))) { echo '{"ok":true,"removed":true}';exit; }
    $directory=ptx_owned($input['job'],$owner);
    if ($action==='status') { @touch($directory.'/heartbeat');echo json_encode(['ok'=>true]+(json_decode(file_get_contents($directory.'/status.json'),true)?:[]));exit; }
    $lock=fopen($directory.'/lock','c');
    $held=$lock && flock($lock,LOCK_EX|LOCK_NB);
    $info=json_decode(file_get_contents($directory.'/owner.json'),true);
    if ($action==='cancel') {
        $release=($input['after_download']??'')==='1';
        if ($held) {
            // A released download may still be queued in the browser; the sweep removes it later.
            if ($release) { if (!touch($directory.'/discard')) throw new RuntimeException('Could not release the download.');echo '{"ok":true,"removed":false}';exit; }
            if (!ptx_clean($directory)) throw new RuntimeException('Temporary files could not be removed. Check the server log.');
            echo '{"ok":true,"removed":true}';exit;
        }
        if ($info['kind']!=='export') throw new RuntimeException('This transfer is still running.');
        // The running export or download removes its own files when it sees this marker.
        if (!touch($directory.'/'.($release?'discard':'cancel'))) throw new RuntimeException('Could not request cancellation.');
        // A failed interrupt is logged; the marker still stops the export at its next checkpoint.
        if (!$release) try { ptx_interrupt(basename($directory)); } catch (Throwable $e) { error_log('Playthrough transfer: could not interrupt export SQL: '.$e->getMessage()); }
        echo '{"ok":true,"pending":true}';exit;
    }
    if (!$held) throw new RuntimeException('This transfer is still running.');
    $kind=in_array($action,['export','download'],true)?'export':'import';
    if ($info['kind']!==$kind) throw new InvalidArgumentException('Wrong transfer type.');
    if ($action==='download') {
        $result=json_decode((string)@file_get_contents($directory.'/result.json'),true);
        if (!$result || !is_file($directory.'/save.zip')) throw new RuntimeException('The download is not ready.');
        ob_end_clean();ini_set('zlib.output_compression','0');set_time_limit(0);ignore_user_abort(true);
        $size=filesize($directory.'/save.zip');$file=fopen($directory.'/save.zip','rb');$sent=0;
        header('Content-Type: application/zip');header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: attachment; filename="'.$result['filename'].'"');
        header('Content-Length: '.$size);
        while (!feof($file) && !connection_aborted()) { $chunk=fread($file,1048576);if ($chunk===false) break;echo $chunk;flush();$sent+=strlen($chunk); }
        fclose($file);
        // A finished stream or closed dialog releases the server copy; an interrupted one can be retried.
        clearstatcache();
        if (($sent===$size && !connection_aborted()) || is_file($directory.'/discard') || is_file($directory.'/cancel')) ptx_clean($directory);
        exit;
    }
    set_time_limit(0);ignore_user_abort(true);
    $conn=ptp_connect();if (!$conn) throw new RuntimeException('Database unavailable.');
    pth_query($conn,"SET lock_timeout='2s'");
    if (in_array($action,['export','import'],true) && ptp_product()['meta']==='stobe_meta') {
        require_once dirname(__DIR__,2).'/lib/postgresql.class.php';require_once dirname(__DIR__,2).'/lib/settings.php';
        require_once dirname(__DIR__,2).'/lib/data_functions.php';$GLOBALS['db']=new sql();
    }
    if ($action==='export') {
        $id=filter_var($input['profile_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if (!$id || !is_string($input['expected_token']??null) || !preg_match('/^[a-f0-9]{64}$/D',$input['expected_token'])) throw new InvalidArgumentException('Reload the save list before downloading.');
        $result=ptx_export($conn,$id,$input['expected_token'],$directory);
        ptx_check_cancel($directory);
        if (file_put_contents($directory.'/result.json',json_encode($result,JSON_THROW_ON_ERROR))===false) throw new RuntimeException('Could not finish download.');
        ptx_check_cancel($directory);
    } elseif ($action==='inspect') {
        $file=$_FILES['save']??null;
        if (!$file || !is_int($file['error']??null) || $file['error']!==UPLOAD_ERR_OK || !is_int($file['size']??null) || $file['size']>21474836480) throw new RuntimeException('The upload failed or exceeded the server upload limit. Choose the file again.');
        if (!move_uploaded_file($file['tmp_name'],$directory.'/save.zip')) throw new RuntimeException('Could not store the upload.');
        $result=ptx_inspect($conn,$directory);
    } else {
        if (!is_string($input['name']??null) || !is_string($input['profile_map']??null) || !is_string($input['profiles_version']??null)) throw new InvalidArgumentException('Check the file before importing.');
        $map=json_decode($input['profile_map'],true,32,JSON_THROW_ON_ERROR);if (!is_array($map)) throw new InvalidArgumentException('Invalid profile choices.');
        $result=ptx_import($conn,$directory,$input['name'],$map,$input['profiles_version']);
    }
    echo json_encode(['ok'=>true]+$result,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $error) {
    if ($conn && pg_transaction_status($conn)!==PGSQL_TRANSACTION_IDLE) @pg_query($conn,'ROLLBACK');
    error_log('Playthrough transfer: '.$error->getMessage());
    $cancelled=$error instanceof PtxCancelled;
    if ($directory && $held && $action==='export') {
        foreach (['save.zip.part','save.zip','result.json'] as $file) if (is_file($directory.'/'.$file)) @unlink($directory.'/'.$file);
    }
    if ($directory && $held && is_dir($directory)) ptx_progress($directory,$cancelled?'Download cancelled':'Transfer stopped',['phase'=>$cancelled?'cancelled':'failed']);
    if (http_response_code()<400) http_response_code(409);
    echo json_encode(['ok'=>false,'cancelled'=>$cancelled,'message'=>$error->getMessage()],JSON_INVALID_UTF8_SUBSTITUTE);
} finally {
    if ($lock) fclose($lock);if ($conn) pg_close($conn);
}
