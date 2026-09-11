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
    $directory=ptx_owned($input['job'],$owner);
    if ($action==='status') { echo json_encode(['ok'=>true]+(json_decode(file_get_contents($directory.'/status.json'),true)?:[]));exit; }
    $lock=fopen($directory.'/lock','c');
    if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) throw new RuntimeException('This transfer is still running.');
    $held=true;
    $info=json_decode(file_get_contents($directory.'/owner.json'),true);
    if ($action==='cancel') { ptx_clean($directory);echo '{"ok":true}';exit; }
    $kind=in_array($action,['export','download'],true)?'export':'import';
    if ($info['kind']!==$kind) throw new InvalidArgumentException('Wrong transfer type.');
    if ($action==='download') {
        $result=json_decode((string)@file_get_contents($directory.'/result.json'),true);
        if (!$result || !is_file($directory.'/save.zip')) throw new RuntimeException('The download is not ready.');
        ob_end_clean();ini_set('zlib.output_compression','0');
        header('Content-Type: application/zip');header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: attachment; filename="'.$result['filename'].'"');
        header('Content-Length: '.filesize($directory.'/save.zip'));readfile($directory.'/save.zip');exit;
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
        file_put_contents($directory.'/result.json',json_encode($result,JSON_THROW_ON_ERROR));
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
    if ($directory && $held) ptx_progress($directory,'Transfer stopped');
    if (http_response_code()<400) http_response_code(409);
    echo json_encode(['ok'=>false,'message'=>$error->getMessage()],JSON_INVALID_UTF8_SUBSTITUTE);
} finally {
    if ($lock) fclose($lock);if ($conn) pg_close($conn);
}
