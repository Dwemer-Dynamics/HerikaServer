<?php

// Player letters to Background Life NPCs, used by the in-game Prisma panel.
//   operation=list  npc_name|refid             -> thread in both directions
//   operation=send  npc_name|refid, body, [reply_to] -> queue a letter for the courier
// Always answers HTTP 200 with {success, ...}; the in-game proxy drops non-2xx bodies.

error_reporting(E_ERROR);
session_start();

define('BASE_PATH', dirname(dirname(__DIR__)));
define('CONFIG_PATH', BASE_PATH . DIRECTORY_SEPARATOR . 'conf');
define('LIB_PATH', BASE_PATH . DIRECTORY_SEPARATOR . 'lib');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

function chimLetterApiReply(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    chimLetterApiReply(['success' => false, 'error' => 'POST required']);
}
if (!file_exists(CONFIG_PATH . DIRECTORY_SEPARATOR . 'conf.php')) {
    chimLetterApiReply(['success' => false, 'error' => 'Configuration file not found']);
}

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'profile_loader.php';
require_once LIB_PATH . DIRECTORY_SEPARATOR . 'logger.php';
require_once LIB_PATH . DIRECTORY_SEPARATOR . "{$GLOBALS['DBDRIVER']}.class.php";
require_once LIB_PATH . DIRECTORY_SEPARATOR . 'data_functions.php';
require_once LIB_PATH . DIRECTORY_SEPARATOR . 'rolemaster_helpers.php';
require_once LIB_PATH . DIRECTORY_SEPARATOR . 'bgl_letters.php';

$GLOBALS['db'] = new sql();
$npcMaster = new NpcMaster();

try {
    $operation = trim((string)($_POST['operation'] ?? ''));
    $refid = trim((string)($_POST['refid'] ?? ''));
    $npcName = trim((string)($_POST['npc_name'] ?? ''));
    if ($refid === '' && $npcName === '') {
        throw new InvalidArgumentException('NPC RefID or name is required');
    }

    if ($operation === 'list') {
        $npc = chimBglResolveNpc($npcMaster, $refid, $npcName);
        if (!$npc) {
            throw new DomainException('NPC has not been discovered by CHIM');
        }
        $status = chimBglNpcStatus($npcMaster, $npc, $refid, $npcName);
        chimLetterApiReply([
            'success' => true,
            'npc' => $status['name'],
            'fee' => chimLetterFee(),
            'delay_hours' => chimLetterDelayHours(),
            'max_length' => CHIM_LETTER_MAX_BODY,
            'letters' => chimLetterThread($status['name']),
        ]);
    }

    if ($operation === 'send') {
        $letter = chimLetterSendFromPlayer(
            $npcMaster,
            $refid,
            $npcName,
            (string)($_POST['body'] ?? ''),
            (int)($_POST['reply_to'] ?? 0)
        );
        chimLetterApiReply([
            'success' => true,
            'message' => "Letter to {$letter['npc_name']} handed to the courier service",
            'letter_id' => (int)$letter['id'],
        ]);
    }

    throw new InvalidArgumentException('Unsupported letter operation');
} catch (InvalidArgumentException | DomainException $error) {
    chimLetterApiReply(['success' => false, 'error' => $error->getMessage()]);
} catch (Throwable $error) {
    Logger::error('Background Life letter API failed: ' . $error->getMessage());
    chimLetterApiReply(['success' => false, 'error' => $error->getMessage()]);
}
