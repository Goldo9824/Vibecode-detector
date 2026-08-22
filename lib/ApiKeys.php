<?php
declare(strict_types=1);

require_once __DIR__ . '/Db.php';

/**
 * API keys managed through admin/index.php, backed by the optional database.
 *
 * Only a SHA-256 hash of each key is ever stored, the same reasoning as a
 * password table, except these keys are 192 bits of random_bytes() rather
 * than something a person picked — a fast hash is enough; there is no
 * dictionary to guard against, so bcrypt's deliberate slowness buys nothing.
 *
 * A key is never hard-deleted. "Remove" in the admin UI sets revoked_at,
 * which is enough to make it stop working immediately (vcd_api_key_lookup()
 * only matches rows where revoked_at IS NULL) while keeping its name and its
 * usage history intact — a revoked key's past usage is still worth seeing.
 */
final class ApiKeys
{
    public static function generate(): string
    {
        return 'vcd_' . bin2hex(random_bytes(24));
    }

    /** Creates a new key and returns the plaintext — shown to the operator exactly once. */
    public static function create(PDO $pdo, string $name): string
    {
        $key = self::generate();
        $stmt = $pdo->prepare('INSERT INTO api_keys (name, key_hash, created_at) VALUES (?, ?, UTC_TIMESTAMP())');
        $stmt->execute(array($name, hash('sha256', $key)));
        return $key;
    }

    public static function revoke(PDO $pdo, int $id): void
    {
        $stmt = $pdo->prepare('UPDATE api_keys SET revoked_at = UTC_TIMESTAMP() WHERE id = ? AND revoked_at IS NULL');
        $stmt->execute(array($id));
    }

    /** @return array<int,array<string,mixed>> every key, active first then newest first, with a usage count each */
    public static function all(PDO $pdo): array
    {
        return $pdo->query(
            'SELECT k.id, k.name, k.created_at, k.revoked_at,
                    COUNT(u.id) AS uses,
                    MAX(u.created_at) AS last_used
             FROM api_keys k
             LEFT JOIN usage_log u ON u.api_key_id = k.id
             GROUP BY k.id, k.name, k.created_at, k.revoked_at
             ORDER BY (k.revoked_at IS NULL) DESC, k.created_at DESC'
        )->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public static function find(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare('SELECT id, name, created_at, revoked_at FROM api_keys WHERE id = ?');
        $stmt->execute(array($id));
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @return array{id:int,name:string}|null the active key matching this plaintext, or null */
    public static function lookup(PDO $pdo, string $key): ?array
    {
        $stmt = $pdo->prepare('SELECT id, name FROM api_keys WHERE key_hash = ? AND revoked_at IS NULL LIMIT 1');
        $stmt->execute(array(hash('sha256', $key)));
        $row = $stmt->fetch();
        return $row ? array('id' => (int) $row['id'], 'name' => (string) $row['name']) : null;
    }
}
