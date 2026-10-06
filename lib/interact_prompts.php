<?php
// Mechanical action descriptions stay code-owned and are included only when eligible.
function chimInteractActionDescriptions(): array {
    return [
        'observe'=>'Only for an intent to look, examine or show something. Never substitute observe for a failed physical action, transformation or unsupported effect; return empty steps with failure narration instead.',
        'pickup'=>'Take one loose world reference for keeping. Never repeat pickup or combine it with consume_world. Later target actions may be skipped once the reference leaves the world.',
        'consume_world'=>'The PLAYER eats/drinks the world food/potion with item=null. This atomic action transfers and consumes it: use directly, never require or add pickup.',
        'give'=>'Transfer value copies of the exact selected inventory item to the target.',
        'store'=>'Transfer value copies of the exact selected inventory item into the container.',
        'consume'=>'Transfer and administer the selected consumable’s real effects to the NPC.',
        'equip'=>'Transfer and equip the exact selected inventory item on the NPC.',
        'heal'=>'Restore value points of the living target actor’s health (1..100), capped by actual deficit. No item is consumed; alive=true.',
        'restore_stamina'=>'Restore value points of the living target actor’s stamina (1..100), capped by actual deficit. No item is consumed; alive=true.',
        'restore_magicka'=>'Restore value points of the living target actor’s magicka (1..100), capped by actual deficit. No item is consumed; alive=true.',
        'disarm'=>'Unequip and drop the exact captured target weapon: 0=right hand, 1=left hand. No player item needed; alive=true. Use only captured equipment.',
        'unequip'=>'Remove the exact captured target armor slot (30..61), leaving it in NPC inventory. No player item needed; alive=true.',
        'drop'=>'Drop value copies of the selected inventory instance at the PLAYER.',
        'place'=>'Place value copies directly near the captured target in the same cell. No prerequisite drop; no guarantee of tabletop or stable physics positioning.',
        'injure'=>'Apply value health loss, not a simulated weapon hit.',
        'kill'=>'Kill the captured target.',
        'push'=>'Push with bounded force value.',
        'lock'=>'Lock using value as the lock level.',
        'unlock'=>'Unlock the target.',
        'activate'=>'Request activation only; never assert pickup or unverified scripted consequences.',
        'open'=>'Open the target.',
        'close'=>'Close the target.',
        'destroy'=>'Use the target’s authored destruction; no invented destruction behavior.',
        'disable'=>'Remove the captured reference without debris.',
        'resize'=>'Set absolute scale value; requires plausible magic, not invented powers for ordinary objects.',
        'magic'=>'Consume the selected supported scroll and apply only its authored effects. Resistance may prevent them; never invent spells.',
        'poison'=>'Apply actual poison-resisted health damage over time: value 1..10 points per second, duration 5/10/20/30 seconds, alive=true. Refresh this CHIM poison rather than stack.',
        'burning'=>'Apply fire-resisted burning damage over time: value 1..10 points per second, duration 5/10/20/30 seconds, alive=true. No fire spread or object destruction. Refresh rather than stack.',
        'paralysis'=>'Apply actual temporary paralysis, value=1, duration 5/10/20/30 seconds, alive=true. Immunity may prevent it; refresh rather than stack.',
        'calm'=>'Apply temporary calm, value 1..100 is affected actor level limit, duration 5/10/20/30 seconds, alive=true. Refresh rather than stack.',
        'fear'=>'Apply temporary fear, value 1..100 is affected actor level limit, duration 5/10/20/30 seconds, alive=true. Refresh rather than stack.',
        'frenzy'=>'Apply temporary frenzy, value 1..100 is affected actor level limit, duration 5/10/20/30 seconds, alive=true. Refresh rather than stack.',
        'combat'=>'Start combat with the player; alive=true.'
    ];
}

// Only these three guidance prompts are editable in Prompt Manager.
function chimInteractPromptDefaults(): array {
    $prompts = [
        'interact_rules_normal'=>'Fulfill the player’s intent with the closest eligible mechanics and default to success. An exact animation match is unnecessary. Use scene plausibility to scale magnitude or choose meaningful partial success, not to invent a refusal based on armor, alertness, level, skill, hostility or morality. The selected item is a narrative prop for synthetic effects. Use only effects motivated by the intent; do not turn a simple stab into a kill or invent additional magic. Failure is reserved for concrete engine obstacles or an intent with no meaningful eligible equivalent.',
        'interact_rules_cheat'=>'Cheat Mode is on. Grant the requested intent using the eligible implemented actions even when it is unrealistic, socially inappropriate or lacks an in-world justification. Do not refuse on plausibility, morality, consequences or skill grounds. This policy overrides in-world justification requirements in action guidance, including the magical rationale for resize. Choose the closest faithful supported mechanics; never substitute observe for a requested physical effect that an eligible action supports. Engine eligibility and the response contract still apply; if no eligible mechanic can achieve the intent, explain that limitation without inventing success.',
        'interact_narration'=>'Write flowing, descriptive third-person narration using supplied names. Usually use two sentences per effect, up to three when useful, within 500 characters. Describe the intended successful effect and concrete physical details supported by the captured scene and that effect; vary the phrasing instead of repeating the action. Do not pad a simple effect with invented detail. Describe each effect separately; a timed status receipt confirms initial application, not guaranteed full duration or total damage. Choose the mechanical outcome first; never select failure to set up a joke. Leave top-level failure_narration empty on successful plans. Only for a genuine failure, describe the attempt with a small touch of dry humor about ambition outpacing results. Do not invent the cause, an animation, an NPC reaction, injury or item consumption. Keep uncertainty and any confirmed partial effects intact. No debug/status language, dialogue, unperformed animations, imagined sensations, NPC reactions, new events or later consequences.'
    ];
    return $prompts;
}

// Follow Prompt Manager custom/default/fallback precedence, loading this small category in one query.
function chimInteractManagedPrompts(): array {
    $prompts = chimInteractPromptDefaults();
    if (!isset($GLOBALS['db'])) return $prompts;
    try {
        $rows = $GLOBALS['db']->fetchAll("SELECT prompt_key, custom_prompt, default_prompt FROM prompts WHERE prompt_key IN ('interact_rules_normal','interact_rules_cheat','interact_narration')");
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
