<?php
/** Read-only discovery for the DwemerDistro engine in the same WSL instance. */
final class DwemerDistroLlm
{
    public const ENDPOINT = 'http://127.0.0.1:1234/v1/chat/completions';

    /** Normalize the explicit service and recognize older Quickstart-owned connectors. */
    public static function connector(array $data, bool $forWrite = false): array
    {
        $metadata = json_decode((string)($data['metadata'] ?? '{}'), true) ?: [];
        $service = $data['service'] ?? '';
        if (!$forWrite && ($metadata['quickstart_server_type'] ?? '') === 'dwemerdistro'
            && in_array($service, ['', 'custom'], true) && ($data['url'] ?? '') === self::ENDPOINT) {
            $service = 'dwemerdistro';
        }
        if ($service === 'dwemerdistro') {
            $data['service'] = 'dwemerdistro';
            $data['url'] = self::ENDPOINT;
            $data['driver'] = 'openaijson';
            $data['provider'] = 'local';
            $data['api_badge_id'] = null;
        } elseif ($forWrite && isset($data['service']) && ($metadata['quickstart_server_type'] ?? '') === 'dwemerdistro') {
            $metadata['quickstart_server_type'] = 'other';
            $data['metadata'] = json_encode($metadata);
        }
        return $data;
    }

    public static function status(): array
    {
        $result = ['state' => 'not_installed', 'models' => [], 'message' => 'Install LLM Studio from the DwemerDistro launcher Components page.'];
        if (!is_file('/usr/local/bin/ddistro_lmstudio')) return $result;
        $result['state'] = 'stopped';
        $result['message'] = 'Engine unavailable. Open LLM Studio and start the engine, then refresh models.';
        if (!function_exists('curl_init')) { $result['message'] = 'PHP cURL is required for model discovery.'; return $result; }
        $body = '';
        $handle = curl_init('http://127.0.0.1:1234/api/v1/models');
        curl_setopt_array($handle, [CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 4,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROXY => '', CURLOPT_PROTOCOLS => CURLPROTO_HTTP,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > 262144) return 0;
                $body .= $chunk;
                return strlen($chunk);
            }]);
        $ok = curl_exec($handle);
        $code = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);
        if ($ok === false || $code !== 200) return $result;
        $data = json_decode($body, true);
        if (!is_array($data['models'] ?? null)) { $result['message'] = 'The service on port 1234 did not return an LM Studio model list.'; return $result; }
        foreach ($data['models'] as $model) {
            if (($model['type'] ?? '') !== 'llm') continue;
            foreach ($model['loaded_instances'] ?? [] as $instance) {
                $id = $instance['id'] ?? null;
                if (is_string($id) && $id !== '' && strlen($id) <= 255 && !preg_match('/[\x00-\x1f\x7f]/', $id)) $result['models'][] = $id;
                if (count($result['models']) >= 100) break 2;
            }
        }
        $result['models'] = array_values(array_unique($result['models']));
        $result['state'] = $result['models'] ? 'ready' : 'no_model';
        $result['message'] = $result['models'] ? 'Engine running. Choose a loaded model and test it before applying.' : 'Engine running, but no language model is loaded. Load one in LLM Studio, then refresh.';
        return $result;
    }

    /** Reject unloaded or stale model choices before generation or routing changes. */
    public static function requireModel(string $model): void
    {
        $state = self::status();
        if (!in_array($model, $state['models'], true)) throw new InvalidArgumentException($state['message'] . ' Refresh the model selection.');
    }
}
