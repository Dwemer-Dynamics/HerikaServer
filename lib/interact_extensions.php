<?php
// Interact plugin actions. Game descriptors arrive from the client and are untrusted data; server
// compositions come from trusted installed packages and expand only into built-in Interact steps.
require_once __DIR__.'/item_interaction.php';

const CHIM_INTERACT_EXTENSION_VERSION = 1;
const CHIM_INTERACT_EXTENSION_FILE = 'interact_actions.php';

// Namespaced lowercase "plugin:action" IDs cannot collide with built-in effect names.
function chimInteractExtensionId(mixed $id): ?string {
    if (!is_string($id) || !preg_match('/^([a-z][a-z0-9_]{0,30}):([a-z][a-z0-9_]{0,30})$/D', $id, $parts)) return null;
    return $parts[1]==='chim' ? null : $id;
}

// Shared descriptor fields for game and server actions; returns null for any invalid field.
function chimInteractExtensionDescriptor(array $raw, string $id): ?array {
    $description = $raw['description'] ?? null;
    if (!is_string($description)) return null;
    $description = trim($description);
    if ($description==='' || strlen($description)>240 || !mb_check_encoding($description,'UTF-8')
        || preg_match('/[\x00-\x1F\x7F]/', $description)) return null;
    $targets = $raw['targets'] ?? null;
    if (!is_array($targets) || !$targets || !array_is_list($targets) || count($targets)>3) return null;
    // Check every element strictly before any operation that would coerce it to a string.
    foreach ($targets as $target) if (!in_array($target, ['living_actor','dead_actor','object'], true)) return null;
    if (count(array_unique($targets))!==count($targets)) return null;
    $item = $raw['item'] ?? null;
    if (!in_array($item, ['optional','required','forbidden'], true)) return null;
    [$min, $max, $whole] = [$raw['min'] ?? null, $raw['max'] ?? null, $raw['whole'] ?? null];
    if ((!is_int($min) && !is_float($min)) || (!is_int($max) && !is_float($max)) || !is_bool($whole)
        || !is_finite((float)$min) || !is_finite((float)$max) || $min>$max || abs($min)>1000000 || abs($max)>1000000
        || ($whole && ceil($min)>floor($max))) return null;
    return ['id'=>$id, 'description'=>$description, 'targets'=>$targets, 'item'=>$item,
        'min'=>$min + 0, 'max'=>$max + 0, 'whole'=>$whole];
}

function chimInteractExtensionEligible(array $descriptor, array $snapshot): bool {
    $target = $snapshot['target'] ?? [];
    $kind = ($target['actor'] ?? false)!==true ? 'object' : (($target['dead'] ?? false)===true ? 'dead_actor' : 'living_actor');
    $hasItem = ($snapshot['item'] ?? null)!==null;
    if ($descriptor['item']==='required' && !$hasItem) return false;
    if ($descriptor['item']==='forbidden' && $hasItem) return false;
    return in_array($kind, $descriptor['targets'], true);
}

// Accept only well-formed, advertised, snapshot-eligible game descriptors; drop the rest individually.
function chimInteractGameExtensions(mixed $payload, array $capabilities, array $snapshot): array {
    if ($payload===null) return [];
    if (!is_array($payload) || !array_is_list($payload) || count($payload)>16) {
        error_log('[INTERACT] plugin descriptors rejected reason=invalid_payload');
        return [];
    }
    $advertised = array_flip(array_filter($capabilities, 'is_string'));
    $accepted = [];
    $rejected = 0;
    foreach ($payload as $raw) {
        $id = is_array($raw) ? chimInteractExtensionId($raw['id'] ?? null) : null;
        $keys = is_array($raw) ? array_keys($raw) : [];
        sort($keys);
        $descriptor = $id!==null && $keys===['description','id','item','max','min','targets','version','whole']
            && ($raw['version'] ?? null)===CHIM_INTERACT_EXTENSION_VERSION && isset($advertised[$id]) && !isset($accepted[$id])
            ? chimInteractExtensionDescriptor($raw, $id) : null;
        if ($descriptor===null || !chimInteractExtensionEligible($descriptor, $snapshot)) { $rejected++; continue; }
        $accepted[$id] = $descriptor + ['kind'=>'game'];
    }
    if ($rejected) error_log('[INTERACT] plugin descriptors rejected count='.$rejected.' accepted='.count($accepted));
    return $accepted;
}

// Validate one composition template against catalog bounds by checking a synthetic plan at its value extremes.
function chimInteractCompositionSteps(mixed $steps, array $descriptor): ?array {
    if (!is_array($steps) || !$steps || !array_is_list($steps) || count($steps)>5) return null;
    $catalog = chimInteractCatalog();
    $templates = [];
    foreach ($steps as $index=>$step) {
        if (!is_array($step) || array_diff(array_keys($step), ['effect','value','alive','requires','duration','direction','axis'])) return null;
        $effect = $step['effect'] ?? null;
        $value = $step['value'] ?? null;
        $requires = $step['requires'] ?? [];
        if (!is_string($effect) || !isset($catalog[$effect]) || !is_bool($step['alive'] ?? null)
            || (!is_int($value) && !is_float($value) && $value!=='input') || !is_array($requires) || !array_is_list($requires)) return null;
        foreach ($requires as $dependency) if (!is_int($dependency) || $dependency<0 || $dependency>=$index) return null;
        $template = ['effect'=>$effect, 'value'=>$value, 'alive'=>$step['alive'], 'requires'=>array_values(array_unique($requires)),
            'direction'=>$step['direction'] ?? '', 'axis'=>$step['axis'] ?? ''];
        if (array_key_exists('duration', $step)) $template['duration'] = $step['duration'];
        $templates[] = $template;
    }
    $probes = $descriptor['whole'] ? [ceil($descriptor['min']), floor($descriptor['max'])] : [$descriptor['min'], $descriptor['max']];
    if (!$descriptor['whole'] && $descriptor['min']<$descriptor['max']) $probes[] = $descriptor['min'] + ($descriptor['max'] - $descriptor['min']) / 3;
    try {
        foreach ($probes as $probe) chimInteractValidate(['steps'=>chimInteractTemplateSteps($templates, $probe, 'x', []), 'failure_narration'=>''], $catalog);
    } catch (InvalidArgumentException $error) {
        return null;
    }
    return $templates;
}

function chimInteractTemplateSteps(array $templates, int|float $value, string $narration, array $outer, int $offset = 0): array {
    $steps = [];
    foreach ($templates as $template) {
        $step = ['effect'=>$template['effect'], 'value'=>$template['value']==='input' ? $value : $template['value'],
            'requires'=>array_values(array_unique(array_merge($outer, array_map(static fn(int $d): int => $offset + $d, $template['requires'])))),
            'alive'=>$template['alive'], 'narration'=>$narration, 'failure_narration'=>'',
            'direction'=>$template['direction'], 'axis'=>$template['axis']];
        if (array_key_exists('duration', $template)) $step['duration'] = $template['duration'];
        $steps[] = $step;
    }
    return $steps;
}

// Load opt-in ext/<package>/interact_actions.php declarations once per request. Discovery is a bounded
// directory-name scan; general plugin hooks are not loaded. The first package (by name) owns a namespace.
function chimInteractServerDeclarations(?string $root = null): array {
    static $cache = [];
    $root ??= dirname(__DIR__).'/ext';
    if (isset($cache[$root])) return $cache[$root];
    $actions = [];
    $owners = [];
    $entries = is_dir($root) ? @scandir($root) : false;
    $loaded = 0;
    foreach (array_slice(is_array($entries) ? $entries : [], 0, 256) as $package) {
        if ($loaded>=16 || count($actions)>=32) break;
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D', $package)) continue;
        $file = $root.'/'.$package.'/'.CHIM_INTERACT_EXTENSION_FILE;
        if (!is_file($file)) continue;
        $loaded++;
        ob_start();
        try {
            $declaration = (static function (string $path) { return require $path; })($file);
        } catch (Throwable $error) {
            $declaration = null;
            error_log('[INTERACT] server plugin actions failed package='.$package.' error='.get_class($error));
        } finally {
            ob_end_clean();
        }
        $namespace = is_array($declaration) ? ($declaration['namespace'] ?? null) : null;
        if (!is_array($declaration) || array_diff(array_keys($declaration), ['version','namespace','actions'])
            || ($declaration['version'] ?? null)!==CHIM_INTERACT_EXTENSION_VERSION || !is_string($namespace)
            || chimInteractExtensionId($namespace.':a')===null || !is_array($declaration['actions'] ?? null)
            || !array_is_list($declaration['actions']) || count($declaration['actions'])>16) {
            error_log('[INTERACT] server plugin actions rejected package='.$package.' reason=invalid_declaration');
            continue;
        }
        if (isset($owners[$namespace])) {
            error_log('[INTERACT] server plugin actions rejected package='.$package.' reason=namespace_owned_by_'.$owners[$namespace]);
            continue;
        }
        $owners[$namespace] = $package;
        foreach ($declaration['actions'] as $raw) {
            $id = is_array($raw) && is_string($raw['name'] ?? null) ? chimInteractExtensionId($namespace.':'.$raw['name']) : null;
            $descriptor = $id!==null && !isset($actions[$id]) && !array_diff(array_keys($raw), ['name','description','targets','item','min','max','whole','steps'])
                ? chimInteractExtensionDescriptor($raw, $id) : null;
            $steps = $descriptor!==null ? chimInteractCompositionSteps($raw['steps'] ?? null, $descriptor) : null;
            if ($steps===null) {
                error_log('[INTERACT] server plugin action rejected package='.$package.' id='.($id ?? 'invalid'));
                continue;
            }
            $actions[$id] = $descriptor + ['kind'=>'server', 'package'=>$package, 'steps'=>$steps];
            if (count($actions)>=32) break;
        }
    }
    return $cache[$root] = $actions;
}

// Advertise a composition only when the request already allows every built-in step at its value range.
function chimInteractServerExtensions(array $allowed, array $snapshot, array $taken, ?string $root = null): array {
    $eligible = [];
    foreach (chimInteractServerDeclarations($root) as $id=>$action) {
        if (isset($taken[$id])) {
            error_log('[INTERACT] server plugin action skipped id='.$id.' reason=owned_by_game_plugin');
            continue;
        }
        if (!chimInteractExtensionEligible($action, $snapshot)) continue;
        foreach ($action['steps'] as $step) {
            $bounds = $allowed[$step['effect']] ?? null;
            $range = $step['value']==='input' ? [$action['min'], $action['max']] : [$step['value'], $step['value']];
            if ($bounds===null || $range[0]<$bounds[0] || $range[1]>$bounds[1]) continue 2;
        }
        $eligible[$id] = $action;
    }
    return $eligible;
}

// Replace each server composition step with its built-in steps before the plan is stored or executed.
// Outer dependencies map to every expanded step; the expanded plan is revalidated with the normal limits.
function chimInteractExpandPlan(array $plan, array $allowed, array $extensions): array {
    $server = array_filter($extensions, static fn(array $extension): bool => $extension['kind']==='server');
    if (!$server) return ['plan'=>$plan, 'compositions'=>[]];
    $steps = [];
    $map = [];
    $compositions = [];
    foreach ($plan['steps'] as $index=>$step) {
        $outer = [];
        foreach ($step['requires'] as $dependency) $outer = array_merge($outer, $map[$dependency]);
        $action = $server[$step['effect']] ?? null;
        if ($action===null) {
            $step['requires'] = array_values(array_unique($outer));
            $map[$index] = [count($steps)];
            $steps[] = $step;
            continue;
        }
        $start = count($steps);
        array_push($steps, ...chimInteractTemplateSteps($action['steps'], $step['value'], $step['narration'], $outer, $start));
        if (count($steps)>5) throw new InvalidArgumentException('Expanded interaction sequence is too long');
        $map[$index] = range($start, count($steps) - 1);
        $compositions[] = ['id'=>$step['effect'], 'package'=>$action['package'], 'start'=>$start, 'count'=>count($action['steps']),
            'narration'=>$step['narration'], 'failure_narration'=>$step['failure_narration']];
    }
    $executable = array_diff_key($allowed, $server);
    $game = array_diff_key($extensions, $server);
    $expanded = chimInteractValidate(['steps'=>$steps, 'failure_narration'=>$plan['failure_narration']], $executable, $game);
    error_log('[INTERACT] expanded server plugin actions count='.count($compositions).' steps='.count($expanded['steps']));
    return ['plan'=>$expanded, 'compositions'=>$compositions];
}
