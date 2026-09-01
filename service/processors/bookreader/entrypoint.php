<?php



$GLOBALS["TASKS"]["bookreader"] = [];
$GLOBALS["TASKS"]["bookreader"]["fn"] = function () {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);

    $enginePath = $GLOBALS['ENGINE_ROOT'];
    $GLOBALS['ENGINE_PATH'] = $enginePath;

    $GLOBALS["TTS_FFMPEG_FILTERS"]=[];
    
    // ─── Includes ─────────────────────────────────────────────────────────────────

    if (!isset($GLOBALS['db'])) {
        $GLOBALS['db'] = new sql();
    }


    require_once $enginePath . 'lib/model_dynmodel.php';
    require_once $enginePath . 'lib/chat_helper_functions.php';
    require_once $enginePath . 'lib/data_functions.php';
    require_once $enginePath . 'lib/logger.php';
    require_once $enginePath . 'lib/utils_game_timestamp.php';
    require_once $enginePath . 'lib/rolemaster_helpers.php';
    require_once $enginePath . 'lib/scriptproxy_papyrus.php';
    require_once $enginePath . 'lib/core/player.class.php';
    require_once $enginePath . 'lib/core/npc_master.class.php';
    require_once $enginePath . 'lib/core/api_badge.class.php';
    require_once $enginePath . 'lib/core/core_profiles.class.php';
    require_once $enginePath . 'lib/core/llm_connector.class.php';
    require_once $enginePath . 'lib/core/tts_connector.class.php';
    require_once $enginePath . 'lib/lazy_xml.php';
    require_once $enginePath . 'debug/background_action_handler.php';

    require_once $enginePath . "lib/scriptproxy_papyrus.php";
    require_once $enginePath . "lib/core/activity_status.php";
    require_once $enginePath . "processor/narrator_init.php";

    // ─── Database ─────────────────────────────────────────────────────────────────

    $db = $GLOBALS["db"];

    // ─── Config ───────────────────────────────────────────────────────────────────

    // Don't log on event log table we will do it manually in this script, as this is not generated from a request.
    $GLOBALS["PATCH_DONT_STORE_SPEECH_ON_DB"] = true;
    $GLOBALS["SCRIPTLINE_ANIMATION"] = "";

    // In-game last timestamps.

    $lastGameTsRow = $db->fetchAll('SELECT max(gamets) AS last_gamets FROM eventlog');
    $lastTsRow = $db->fetchAll("SELECT max(ts) AS ts FROM eventlog WHERE gamets='{$lastGameTsRow[0]['last_gamets']}'");

    $last_gamets = $lastGameTsRow[0]['last_gamets'] ?? 0;
    $last_ts = $lastTsRow[0]['ts'] ?? 0;


    $defaultNarratorName = function_exists('chimGetNarratorRoleplayName')
        ? chimGetNarratorRoleplayName()
        : ($GLOBALS['NARRATOR_ROLEPLAY_NAME'] ?? Narrator::DEFAULT_ROLEPLAY_NAME);
    $defaultPlayerName = $GLOBALS['PLAYER_NAME'] ?? 'Varek';
    $commenter = $defaultNarratorName; // NPC that comments on the narrator's reading
    $animationsEnabled = true;
    $linesPerBatch = max(1, intval($GLOBALS['BOOK_READ_LINES_PER_BATCH'] ?? 8));

    require_once $enginePath . 'lib/core/book_read.class.php';

    // ─── Main entry point ─────────────────────────────────────────────────────────

    $reader = new BookReader($db, $last_ts, $last_gamets, $defaultNarratorName, $defaultPlayerName, $commenter, $animationsEnabled, $linesPerBatch);
    $reader->run([]);
}
// ─── Example external caller usage (commented out) ────────────────────────────
/*
require_once 'debug/book_read.php'; // or include the relevant helpers

if ($bookCandidate) {
    $result = bookReadStateHandleBookAction($bookCandidate, $GLOBALS["HERIKA_NAME"], $playerName);
    // $result is one of: 'resumed', 'replaced', 'started', 'ignored'
    unset($actionsCopy[$n]);
}
*/
?>
