<?php
// Installation-wide interaction state is deliberately outside Playthrough Saves.
function chimInteractionFile()
{
    $dir = dirname(__DIR__) . '/conf/chim_interaction';
    if (!is_dir($dir) && !@mkdir($dir, 02775, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot open CHIM interaction state.');
    }
    @chmod($dir, 02775);
    $handle = @fopen($dir . '/state.json', 'c+e');
    if (!$handle) throw new RuntimeException('Cannot open CHIM interaction state.');
    @chmod($dir . '/state.json', 0664);
    return $handle;
}

function chimInteractionRead($handle): array
{
    rewind($handle);
    $raw = stream_get_contents($handle);
    if ($raw === '') return ['enabled' => true, 'generation' => 0];
    $state = json_decode($raw, true);
    if (!is_array($state) || !is_bool($state['enabled'] ?? null) || !is_int($state['generation'] ?? null)) {
        throw new RuntimeException('Cannot read CHIM interaction state.');
    }
    return $state;
}

function chimInteractionState(): array
{
    $handle = chimInteractionFile();
    try {
        if (!flock($handle, LOCK_SH)) throw new RuntimeException('Cannot read CHIM interaction state.');
        return chimInteractionRead($handle);
    } finally { fclose($handle); }
}

// Capture once per request, so Off/On cannot revive an old generator.
function chimInteractionBegin(): void
{
    if (isset($GLOBALS['chim_interaction_generation'])) return;
    try {
        $state = chimInteractionState();
        $GLOBALS['chim_interaction_generation'] = isset($_SERVER['HTTP_X_CHIM_GENERATION'])
            ? (int)$_SERVER['HTTP_X_CHIM_GENERATION'] : $state['generation'];
    } catch (Throwable $e) {
        $GLOBALS['chim_interaction_generation'] = -1;
        error_log('CHIM interaction state unavailable; event recording remains active.');
    }
}

function chimInteractionAllowed(): bool
{
    chimInteractionBegin();
    try {
        $state = chimInteractionState();
        return ($_SERVER['HTTP_X_CHIM_PASSIVE'] ?? '') !== '1' && $state['enabled']
            && $state['generation'] === $GLOBALS['chim_interaction_generation'];
    } catch (Throwable $e) { return false; }
}

// These are requests for invented speech/actions, not observations of Skyrim.
function chimInteractionIsTrigger(string $type): bool
{
    $type = strtolower($type);
    return str_starts_with($type, 'diary') || str_starts_with($type, 'player_menu_tts_')
        || in_array($type, ['inputtext', 'inputtext_s', 'ginputtext', 'ginputtext_s',
            'narrator_inputtext', 'bored', 'rechat', 'continue', 'continue_group',
            'instruction', 'suggestion', 'narration', 'narrator_welcome', 'combatbark',
            'just_say', 'cheatmode', 'vision', 'force_current_task', 'recover_last_task'], true);
}

function chimInteractionIsGameOutput(string $channel): bool
{
    return in_array(explode('|', $channel, 2)[0],
        ['ScriptQueue', 'command', 'rolecommand', 'approvedcommand', 'confirmcommand'], true);
}

function chimInteractionRequire(): void
{
    if (!chimInteractionAllowed()) exit;
    $GLOBALS['chim_interaction_generated'] = true;
}

chimInteractionBegin();
