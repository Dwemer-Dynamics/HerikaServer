<?php 


 
class ApiBadge {
    private $table = "core_api_badge";
    private $db;

    public function __construct() {
        $this->db = $GLOBALS["db"];
    }

    // Create (Insert)
    public function create($data) {
		$fields = [
			"id",
			"label",
			"api_key"
		];

		$filtered = array_intersect_key($data, array_flip($fields));
		if (isset($filtered['id'])) {
			$filtered['id'] = (int)$filtered['id'];
		}
        
        return $this->db->insertReturningId($this->table, $filtered);
    }

    // Read by ID
    public function getById($id) {
        $id = (int)$id;
        $query = "SELECT * FROM {$this->table} WHERE id = $id LIMIT 1";
        return $this->withoutCopiedSecret($this->db->fetchOne($query));
    }

    // Read by Label (case-insensitive)
    public function getByLabel($label) {
        $escaped = $this->escape($label);
        $query = "SELECT * FROM {$this->table} WHERE LOWER(label) = LOWER('{$escaped}') ORDER BY id ASC LIMIT 1";
        return $this->withoutCopiedSecret($this->db->fetchOne($query));
    }

    // Read all (optional WHERE clause)
    public function getAll($where = "TRUE") {
        $query = "SELECT * FROM {$this->table} WHERE $where";
        return array_map([$this, "withoutCopiedSecret"], $this->db->fetchAll($query));
    }

    // Shared references are metadata here. Only an actual provider dispatch may request the decrypted value.
    private function withoutCopiedSecret($row) {
        if (is_array($row) && !empty($row['shared_key_id'])) {
            $row['api_key'] = '';
        }
        return $row;
    }

    // Preserve legacy badge IDs while resolving an explicitly bound shared key for the actual outbound endpoint.
    public function getSecretForEndpoint($id, string $kind, string $endpoint): string {
        $id = (int)$id;
        $row = $this->db->fetchOne("SELECT * FROM {$this->table} WHERE id = $id LIMIT 1");
        if (!is_array($row)) {
            return '';
        }
        if (empty($row['shared_key_id'])) {
            return (string)($row['api_key'] ?? '');
        }
        return self::sharedSecret((string)$row['shared_key_id'], $kind, $endpoint);
    }

    // Connector overrides and badge aliases share the same endpoint-checked dispatch boundary.
    public static function sharedSecret(string $keyId, string $kind, string $endpoint): string {
        $bridge = '/usr/local/lib/dwemerdistro/shared_keys.php';
        if (!is_file($bridge)) {
            throw new RuntimeException('Shared API key service is unavailable');
        }
        require_once $bridge;
        return DistroSharedKeys::resolve($keyId, $kind, $endpoint);
    }

    // Resolve only at dispatch; direct connector overrides win over inherited badge references.
    public static function applyShared(array $connector, string $kind, string $endpoint, array &$settings): bool {
        $keyId = $connector['shared_key_id'] ?? null;
        if (!$keyId && intval($connector['api_badge_id'] ?? 0) > 0) {
            $badge = (new self())->getById(intval($connector['api_badge_id']));
            $keyId = is_array($badge) ? ($badge['shared_key_id'] ?? null) : null;
        }
        if ($keyId) {
            $settings['API_KEY'] = '';
            $settings['API_KEY'] = self::sharedSecret((string)$keyId, $kind, $endpoint);
            return true;
        }
        return false;
    }

    // Legacy HTTP adapters must not overwrite a selected shared credential with a named badge copy.
    public static function dispatchSecret(array $connector, string $legacyKey, string $kind, string $endpoint, string $fallbackLabel = ''): string {
        $settings = ['API_KEY' => $legacyKey];
        if (self::applyShared($connector, $kind, $endpoint, $settings)) {
            return $settings['API_KEY'];
        }
        if ($fallbackLabel !== '') {
            $matches = array_values(array_filter((new self())->getAll(), static fn($row) => strcasecmp((string)($row['label'] ?? ''), $fallbackLabel) === 0));
            foreach ($matches as $badge) {
                if (!empty($badge['shared_key_id'])) {
                    if (count($matches) !== 1) {
                        throw new RuntimeException('Ambiguous shared credential; select a saved badge explicitly.');
                    }
                    return self::sharedSecret((string)$badge['shared_key_id'], $kind, $endpoint);
                }
            }
        }
        return $legacyKey;
    }

    // Update by ID
    public function update($id, $data) {
        $id = (int)$id;
        $fields = [
            "label",
            "api_key"
        ];

        $filtered = array_intersect_key($data, array_flip($fields));
        // Blank edits preserve a bound badge's retained rollback credential as well as its shared reference.
        if (array_key_exists('api_key', $filtered)) {
            $current = $this->getById($id);
            if (is_array($current) && !empty($current['shared_key_id'])) {
                if ((string)$filtered['api_key'] === '') {
                    unset($filtered['api_key']);
                } else {
                    $filtered['shared_key_id'] = null;
                }
            }
        }
        $where = "id = $id";
        return $this->db->updateRow($this->table, $filtered, $where);
    }

    // Delete by ID
    public function delete($id) {
        $id = (int)$id;
        $where = "id = $id";
        return $this->db->delete($this->table, $where);
    }

    // Truncate the table
    public function truncate($restart = false, $cascade = false) {
        return $this->db->truncate($this->table, $restart, $cascade);
    }

    public function getLastError() {
        return $this->db->GetLastError();
    }

    // Upsert using ON CONFLICT (requires UNIQUE or PRIMARY constraint)
    public function upsert($data, $conflictTarget) {
        return $this->db->upsertRowOnConflict($this->table, $data, $conflictTarget);
    }

    // Escape string
    public function escape($str) {
        return $this->db->escape($str);
    }
}

?>
