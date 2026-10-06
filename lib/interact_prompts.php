<?php
// Mechanical action descriptions stay code-owned and are included only when eligible.
function chimInteractActionDescriptions(): array {
    return [
        'frost'=>'Apply frost-resisted health and stamina damage over time at equal value per second, plus the same numeric reduction in movement-speed multiplier points. Confirm initial application of all components only; no ice prison or scenery freezing. Duration 5/10/20/30 seconds, alive=true; refresh the same CHIM status rather than stack.',
        'shock'=>'Apply shock-resisted health and magicka damage over time, value per second. No chain lightning or equipment damage. Duration 5/10/20/30 seconds, alive=true; refresh the same CHIM status rather than stack.',
        'drain_stamina'=>'Reduce target stamina over time, value per second. Do not promise exhaustion, collapse or interrupted actions. Duration 5/10/20/30 seconds, alive=true; refresh the same CHIM status rather than stack.',
        'drain_magicka'=>'Reduce target magicka over time, value per second. Do not promise silence or interrupted casting. Duration 5/10/20/30 seconds, alive=true; refresh the same CHIM status rather than stack.',
        'slow'=>'Reduce current actor movement speed by value percent. Do not claim paralysis or an animation. Duration 5/10/20/30 seconds, alive=true; refresh the same CHIM status rather than stack.',
        'haste'=>'Increase current actor movement speed by value percent. Does not guarantee faster attacks or AI movement. Duration 5/10/20/30 seconds, alive=true; refresh the same CHIM status rather than stack.',
        'weaken_armor'=>'Temporarily reduce armor rating by value points; no equipment removal or destruction. Duration 5/10/20/30 seconds, alive=true; refresh the same CHIM status rather than stack.',
        'fortify_armor'=>'Temporarily increase armor rating by value points; no new equipment or guaranteed immunity. Duration 5/10/20/30 seconds, alive=true; refresh the same CHIM status rather than stack.',
        'weaken_weapon'=>'Temporarily reduce the current weapon-damage multiplier by value percent; no weapon removal or destruction. Duration 5/10/20/30 seconds, alive=true; refresh the same CHIM status rather than stack.',
        'fortify_weapon'=>'Temporarily increase the current weapon-damage multiplier by value percent; no new weapon or enchantment. Duration 5/10/20/30 seconds, alive=true; refresh the same CHIM status rather than stack.',
        'stagger'=>'Request a standalone bounded stagger, value, duration=0, alive=true. Animation or immunity may prevent it; no damage or guaranteed fall.',
        'absorb_health'=>'Transfer health from target to player over time, value per second; Dwarven actors are excluded. Confirm initial application, not total drain or guaranteed player restoration. Duration 5/10/20/30 seconds, alive=true; refresh the same CHIM status rather than stack.',
        'absorb_stamina'=>'Transfer stamina from target to player over time, value per second; Dwarven actors are excluded. No guaranteed exhaustion or full restoration. Duration 5/10/20/30 seconds, alive=true; refresh the same CHIM status rather than stack.',
        'absorb_magicka'=>'Transfer magicka from target to player over time, value per second; Dwarven actors are excluded. No guaranteed silence or full restoration. Duration 5/10/20/30 seconds, alive=true; refresh the same CHIM status rather than stack.',
        'ethereal'=>'Apply temporary ethereal protection, value=1. No teleportation, permanent invulnerability or guarantee of passing through objects. Duration 5/10/20/30 seconds, alive=true; refresh the same CHIM status rather than stack.',
        'soul_trap'=>'Apply a temporary soul-trap effect, value=1. Does not kill the target or confirm a captured soul; later death and an eligible soul gem are separate requirements. Duration 5/10/20/30 seconds, alive=true; refresh the same CHIM status rather than stack.',
        'reanimate'=>'Temporarily reanimate an eligible captured corpse, value is actor level limit, duration 5/10/20/30, alive=false. No permanent resurrection, restored identity or guaranteed subsequent behavior.',
        'banish'=>'Banish an eligible currently summoned living Daedra within value level limit; duration=0, alive=true. Never use on ordinary actors, undead or animal summons, or promise loot or death.',
        'turn_undead'=>'Apply temporary turn-undead influence to an eligible living undead actor; value is level limit. No guarantee of a particular fleeing animation or route. Duration 5/10/20/30 seconds, alive=true; refresh the same CHIM status rather than stack.',
        'extinguish'=>'Remove only the target’s own CHIM burning status or CHIM scenery-fire shader, value=0, duration=0. No removal of other fire effects or reversal of prior damage; actor or scenery target.',
        'neutralize_poison'=>'Remove only the target’s own CHIM poison status, value=0, duration=0, alive=true. Does not cure unrelated poisons or restore lost health.',
        'release_paralysis'=>'Remove only the target’s own CHIM paralysis status, value=0, duration=0, alive=true. Does not remove unrelated paralysis or guarantee an immediate animation.',
        'dispel'=>'Dispel only the exact captured eligible spell at target.dispellable_spells index value, duration=0, alive=true. Never select a hidden spell or remove every effect.',
        'directional_throw'=>'Apply bounded impulse value to the exact eligible movable non-actor reference; direction toward/away/up, duration=0, alive=false. Toward/away are relative to the player. No guaranteed landing point, collision damage or destruction.',
        'rotate'=>'Rotate the exact eligible movable non-actor reference by value degrees around axis x/y/z, duration=0, alive=false. No item creation or guaranteed stable resting position.',
        'move'=>'Move the exact eligible movable non-actor reference a positive value units in direction forward/backward/left/right/up/down relative to the player’s heading (vertical directions use the world axis) within the checked nearby area; duration=0, alive=false. Never specify coordinates, cross cells or promise stable placement.',
        'frost_visual'=>'Apply a timed frost shader to non-actor scenery, value=1, duration 5/10/20/30, alive=false. Appearance only: no damage, ice collision, structural change or guaranteed rendered animation.',
        'shock_visual'=>'Apply a timed shock shader to non-actor scenery, value=1, duration 5/10/20/30, alive=false. Appearance only: no damage, spreading electricity or structural change.',
        'impact_burst'=>'Apply a one-second shock-shader burst at the captured non-actor target, value=1, duration=0, alive=false. Appearance only: no impact damage, explosion force, debris, structural change or guaranteed rendered animation.',
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
        'unequip'=>'Remove the exact captured target armor slot, leaving it in NPC inventory. No player item needed; alive=true.',
        'drop'=>'Drop value copies of the selected inventory instance at the PLAYER.',
        'place'=>'Place value copies directly near the captured target in the same cell. No prerequisite drop; no guarantee of tabletop or stable physics positioning.',
        'injure'=>'Apply value health loss and signal a player assault through Skyrim’s assault response. Also request a stagger on a surviving actor; animation or immunity may block it. This is not a full weapon hit: no weapon enchantment procs. Narrate injury, not guaranteed stagger, crime, bounty or NPC behavior.',
        'kill'=>'Kill the captured target.',
        'push'=>'Push with bounded force value.',
        'lock'=>'Lock using value as the lock level.',
        'unlock'=>'Unlock the target.',
        'activate'=>'Request activation only; never assert pickup or unverified scripted consequences.',
        'open'=>'Open the target.',
        'close'=>'Close the target.',
        'destroy'=>'Use authored destruction when available. Non-actor scenery without destruction data is removed by disabling its exact reference, with no debris or destruction animation. Only for requested destruction, never minor injury.',
        'disable'=>'Remove the captured reference without debris.',
        'resize'=>'Set absolute scale value.',
        'cast_selected_magic'=>'Cast only the exact selected known spell, power or unlocked shout using its captured authored effects and rank. No selected item is consumed. Never invent spells, effects or shout words; eligibility and execution receipts determine success.',
        'magic'=>'Consume the selected supported scroll and apply only its authored effects. Resistance may prevent them; never invent spells.',
        'poison'=>'Apply actual poison-resisted health damage over time: value points per second, duration 5/10/20/30 seconds, alive=true. Refresh this CHIM poison rather than stack.',
        'burning_visual'=>'For non-actor scenery only: apply a timed fire shader, value=1, duration 5/10/20/30 seconds, alive=false. Appearance only: no health damage, spreading fire or destruction. Narrate only the fire effect, not guaranteed rendered animation or structural consequences.',
        'burning'=>'Apply fire-resisted burning damage over time: value points per second, duration 5/10/20/30 seconds, alive=true. No fire spread or object destruction. Refresh rather than stack.',
        'paralysis'=>'Apply actual temporary paralysis, value=1, duration 5/10/20/30 seconds, alive=true. Immunity may prevent it; refresh rather than stack.',
        'calm'=>'Apply temporary calm, value is affected actor level limit, duration 5/10/20/30 seconds, alive=true. Refresh rather than stack.',
        'fear'=>'Apply temporary fear, value is affected actor level limit, duration 5/10/20/30 seconds, alive=true. Refresh rather than stack.',
        'frenzy'=>'Apply temporary frenzy, value is affected actor level limit, duration 5/10/20/30 seconds, alive=true. Refresh rather than stack.',
        'combat'=>'Start combat with the player; alive=true.'
    ];
}

// Only these guidance prompts are editable in Prompt Manager.
function chimInteractPromptDefaults(): array {
    $prompts = [
        'interact_rules'=>'Favor the player’s intended outcome whenever eligible mechanics support it. Realism, social norms, morality and skill are not reasons to refuse; only actual engine constraints limit execution.',
        'interact_narration'=>'Write concise, flowing third-person prose using supplied names, usually two sentences per effect and at most 500 characters. Describe only that effect and supported scene details. Success prose is spoken only after a successful receipt; timed effects confirm initial application, not future totals or duration. Keep failed or uncertain attempts and confirmed partial changes distinct. For genuine failure, a little dry humor is welcome after the outcome is chosen. Do not invent causes, animations, reactions, sensations, dialogue or later consequences. Use story language, not statistics, timers or debug terms.'
    ];
    return $prompts;
}

// Follow Prompt Manager custom/default/fallback precedence, loading this small category in one query.
function chimInteractManagedPrompts(): array {
    $prompts = chimInteractPromptDefaults();
    if (!isset($GLOBALS['db'])) return $prompts;
    try {
        $rows = $GLOBALS['db']->fetchAll("SELECT prompt_key, custom_prompt, default_prompt FROM prompts WHERE prompt_key IN ('interact_rules','interact_narration')");
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
