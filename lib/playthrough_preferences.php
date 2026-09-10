<?php

// Server-owned configuration; metadata is outside saved gameplay tables.
function ptp_product(): array {
    return ['meta'=>'chim_meta', 'database'=>'dwemer', 'prefix'=>'chim_profile_', 'label'=>'CHIM', 'legacy'=>'DRAGON_BREAK', 'days'=>3, 'env'=>''];
}

// Read configuration without running migrations, profile loading or automatic backups.
function ptp_config(): array {
    static $config;
    return $config ??= (static function (): array {
        foreach (['conf.sample.php','conf.php'] as $name) {
            $path = dirname(__DIR__) . '/conf/' . $name;
            if (is_file($path)) include $path;
        }
        return get_defined_vars();
    })();
}

function ptp_connect() {
    $product = ptp_product(); $config = ptp_config();
    $settings = ['host'=>'localhost', 'port'=>'5432', 'dbname'=>$product['database'], 'user'=>'dwemer', 'password'=>'dwemer'];
    $parts = ['connect_timeout=3'];
    foreach ($settings as $key => $value) {
        if ($product['env'] !== '') {
            $name = $product['env'] . '_DB_' . ($key === 'dbname' ? 'NAME' : strtoupper($key));
            $env = getenv($name);
            $value = $env !== false && $env !== '' ? $env : ($GLOBALS[$name] ?? $config[$name] ?? $value);
        }
        $parts[] = $key . "='" . str_replace(['\\', "'"], ['\\\\', "\\'"], (string)$value) . "'";
    }
    return @pg_connect(implode(' ', $parts), PGSQL_CONNECT_FORCE_NEW);
}

function ptp_validate_backup(array $input): array {
    $days = filter_var($input['min_days'] ?? null, FILTER_VALIDATE_INT);
    if ($days === false || $days < 1 || $days > 3650) {
        throw new InvalidArgumentException('Choose a rollback threshold from 1 to 3650 in-game days.');
    }
    // Automatic rollback saves are mandatory; ignore retired off values from older clients and settings.
    return ['enabled'=>true, 'min_days'=>$days];
}

function ptp_backup_settings($conn): array {
    $product = ptp_product(); $legacy = $product['legacy']; $config = ptp_config();
    $days = $GLOBALS[$legacy . '_MIN_DAYS'] ?? $config[$legacy . '_MIN_DAYS'] ?? $product['days'];
    $fallback = ['enabled'=>true, 'min_days'=>max(1,min(3650,(int)$days))];
    return ptp_validate_backup(ptr_read($conn, 'PLAYTHROUGH_SAVE_POLICY', $fallback));
}

// Resolve once per request, avoiding repeated reads during the same rollback.
function ptp_runtime_backup_settings(): array {
    static $settings;
    if ($settings === null) {
        $conn = ptp_connect();
        if (!$conn) throw new RuntimeException('Cannot read automatic Playthrough Save settings.');
        try { $settings = ptp_backup_settings($conn); } finally { pg_close($conn); }
    }
    return $settings;
}

// Status writes must not turn a completed save into a reported capture failure.
function ptp_record_backup($conn, int $id, string $message): void {
    try {
        ptr_ensure_schema($conn);
        ptr_write($conn, 'PLAYTHROUGH_SAVE_LAST_ATTEMPT', ['at'=>gmdate('c'), 'status'=>$id>0?'succeeded':'failed', 'profile_id'=>$id, 'message'=>$message]);
    } catch (Throwable $e) { error_log('Playthrough Save status could not be recorded: ' . $e->getMessage()); }
}
