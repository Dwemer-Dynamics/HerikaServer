<?php
// Defaults are shared by Prompt Manager registration and runtime fallback without loading dialogue prompts.
function chimInteractPromptDefaults(): array {
    $actions = [
        'observe'=>'Observe or show; no physical change.',
        'pickup'=>'Take one loose world reference for keeping. Never repeat pickup or combine it with consume_world. Later target actions may be skipped once the reference leaves the world.',
        'consume_world'=>'The PLAYER eats/drinks the world food/potion with item=null. This atomic action transfers and consumes it: use directly, never require or add pickup.',
        'give'=>'Transfer value copies of the exact selected inventory item to the target.',
        'store'=>'Transfer value copies of the exact selected inventory item into the container.',
        'consume'=>'Transfer and administer the selected consumable’s real effects to the NPC.',
        'equip'=>'Transfer and equip the exact selected inventory item on the NPC.',
        'heal'=>'Administer one selected real healing consumable to the NPC: value=1, alive=true. Alternative to consume, not an extra bonus.',
        'restore_stamina'=>'Administer one selected real stamina consumable to the NPC: value=1, alive=true. Alternative to consume, not an extra bonus.',
        'restore_magicka'=>'Administer one selected real magicka consumable to the NPC: value=1, alive=true. Alternative to consume, not an extra bonus.',
        'disarm'=>'Unequip and drop the exact captured target weapon: 0=right hand, 1=left hand. No player item needed; alive=true. Use only captured equipment.',
        'unequip'=>'Remove the exact captured target armor slot (30..61), leaving it in NPC inventory. No player item needed; alive=true.',
        'drop'=>'Drop value copies of the selected inventory instance at the PLAYER.',
        'place'=>'Place value copies directly near the captured target in the same cell. No prerequisite drop; no guarantee of tabletop or stable physics positioning.',
        'injure'=>'Apply value health loss, not a simulated weapon hit.',
        'kill'=>'Kill the target; requires explicit confirmation.',
        'push'=>'Push with bounded force value.',
        'lock'=>'Lock using value as the lock level.',
        'unlock'=>'Unlock the target.',
        'activate'=>'Request activation only; never assert pickup or unverified scripted consequences.',
        'open'=>'Open the target.',
        'close'=>'Close the target.',
        'destroy'=>'Use the target’s authored destruction; no invented destruction behavior.',
        'disable'=>'Remove the reference without debris; requires explicit confirmation.',
        'resize'=>'Set absolute scale value; requires plausible magic, not invented powers for ordinary objects.',
        'magic'=>'Consume the selected supported scroll and apply only its authored effects. Resistance may prevent them; never invent spells.',
        'combat'=>'Start combat with the player; alive=true.'
    ];
    $prompts = [
        'interact_rules_normal'=>'Judge the requested attempt by scene plausibility and the actual selected item. Reject implausible outcomes rather than inventing powers. These CHIM operations are not limited by vanilla interaction menus or distance/reach.',
        'interact_rules_cheat'=>'Cheat Mode is on. Grant the requested intent using the eligible implemented actions even when it is unrealistic, socially inappropriate or lacks an in-world justification. Do not refuse on plausibility, morality, consequences or skill grounds. This policy overrides in-world justification requirements in action guidance, including the magical rationale for resize. Choose the closest faithful supported mechanics; never substitute observe for a requested physical effect that an eligible action supports. Engine eligibility and the response contract still apply; if no eligible mechanic can achieve the intent, explain that limitation without inventing success.',
        'interact_narration'=>'Brief natural third-person prose using supplied names, describing only that step’s intended successful effect. No debug/status language, dialogue, invented animations, sensations, reactions or later consequences.'
    ];
    foreach ($actions as $effect => $description) $prompts['interact_action_'.$effect] = $description;
    return $prompts;
}

// Follow Prompt Manager custom/default/fallback precedence, loading this small category in one query.
function chimInteractManagedPrompts(): array {
    $prompts = chimInteractPromptDefaults();
    if (!isset($GLOBALS['db'])) return $prompts;
    try {
        $rows = $GLOBALS['db']->fetchAll("SELECT prompt_key, custom_prompt, default_prompt FROM prompts WHERE prompt_key LIKE 'interact_%'");
        foreach ($rows ?: [] as $row) {
            $key = $row['prompt_key'];
            if (!array_key_exists($key, $prompts)) continue;
            $text = !empty($row['custom_prompt']) ? $row['custom_prompt'] : ($row['default_prompt'] ?? '');
            if (is_string($text) && trim($text) !== '') $prompts[$key] = $text;
        }
    } catch (Throwable $error) {
        error_log('[INTERACT] Managed prompts unavailable; using defaults');
    }
    return $prompts;
}
