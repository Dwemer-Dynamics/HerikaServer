<?php

// Courier pipeline for player letters to Background Life NPCs (lib/bgl_letters.php).
// Each service tick advances the single active courier by one step and delivers letters
// whose travel time has passed. No step blocks; progress is read from eventlog.

$GLOBALS["TASKS"]["letters"] = [];
$GLOBALS["TASKS"]["letters"]["fn"] = function () {

    $enginePath = $GLOBALS["ENGINE_ROOT"];
    $GLOBALS["ENGINE_PATH"] = $enginePath;

    if (!isset($GLOBALS["db"])) {
        $GLOBALS["db"] = new sql();
    }

    // Couriers and deliveries only make sense while the game is running.
    require_once($enginePath . "lib/game_activity.php");
    if (!chimHasRecentGameActivity()) {
        return;
    }

    require_once($enginePath . "lib/chat_helper_functions.php");
    require_once($enginePath . "lib/data_functions.php");
    require_once($enginePath . "lib/rolemaster_helpers.php");
    require_once($enginePath . "lib/utils_game_timestamp.php");
    require_once($enginePath . "lib/core/npc_master.class.php");
    require_once($enginePath . "lib/scriptproxy_papyrus.php");
    require_once($enginePath . "lib/bgl_letters.php");

    try {
        chimLetterCourierTick(new NpcMaster());
    } catch (Throwable $e) {
        Logger::error("[BGL_LETTERS] Courier tick failed: " . $e->getMessage());
    }
};
