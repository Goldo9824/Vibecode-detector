<?php
declare(strict_types=1);

/**
 * Optional MySQL layer for the admin panel and usage analytics.
 *
 * Everything else in this project works with no database at all, by design
 * (see CONTRIBUTING.md and docs/DEPLOY-LWS.md): a fresh checkout with no
 * data/db-config.php keeps every "nothing is stored" claim on the site
 * completely true. This is additive, for an operator who has deliberately
 * set up a database to manage API keys by name and see usage through
 * admin/, instead of by hand in a text file with no visibility at all.
 *
 * Every caller must treat a null connection, or an exception from a query,
 * as "the feature is unavailable right now" — never as a fatal error. The
 * site and the key-authenticated API must keep working exactly as they do
 * with no database configured if this one is unreachable, misconfigured, or
 * simply absent.
 */
final class Db
{
    /** @var PDO|null|false false means "not attempted yet" */
    private static $pdo = false;
    /** @var array<string,mixed>|null the parsed config, once it has been read */
    private static $config = null;

    public static function connect(): ?PDO
    {
        if (self::$pdo !== false) {
            return self::$pdo;
        }

        $file = VCD_DATA . '/db-config.php';
        if (!is_readable($file)) {
            return self::$pdo = null;
        }

        $config = require $file;
        if (!is_array($config) || empty($config['host']) || empty($config['database']) || empty($config['user'])) {
            return self::$pdo = null;
        }
        self::$config = $config;

        try {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                (string) $config['host'],
                (int) ($config['port'] ?? 3306),
                (string) $config['database']
            );
            self::$pdo = new PDO($dsn, (string) $config['user'], (string) ($config['password'] ?? ''), array(
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_TIMEOUT            => 3,
            ));
        } catch (Throwable $e) {
            self::$pdo = null;
        }

        return self::$pdo;
    }

    public static function available(): bool
    {
        return self::connect() !== null;
    }

    /**
     * An optional setting from data/db-config.php.
     *
     * The file is a connection config that has grown two switches, rather than
     * a settings system: everything here has a default that is right for an
     * operator who pasted in the four connection lines and stopped reading.
     *
     * @param  mixed $default
     * @return mixed
     */
    public static function option(string $key, $default = null)
    {
        if (self::$config === null) {
            self::connect();
        }
        if (!is_array(self::$config) || !array_key_exists($key, self::$config)) {
            return $default;
        }
        return self::$config[$key];
    }

    /**
     * Creates the five tables this feature needs if they are not already
     * there. Idempotent, so it is safe to call on every admin page load —
     * called there rather than from the high-traffic logging path, so a
     * database that has not been initialised yet fails a login attempt with
     * a clear reason rather than failing analysis requests silently.
     */
    public static function ensureSchema(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS api_keys (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(190) NOT NULL,
                key_hash CHAR(64) NOT NULL,
                created_at DATETIME NOT NULL,
                revoked_at DATETIME NULL,
                PRIMARY KEY (id),
                UNIQUE KEY key_hash (key_hash)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS usage_log (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                source ENUM('ui','api') NOT NULL,
                api_key_id INT UNSIGNED NULL,
                mode VARCHAR(16) NOT NULL,
                target VARCHAR(2048) NULL,
                target_host VARCHAR(255) NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY api_key_id (api_key_id),
                KEY target_host (target_host),
                KEY created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        // One row per page view. `visitor` is an HMAC of address and user
        // agent salted with the day, so it counts people within a day and is
        // useless for recognising anyone across two — see lib/VisitLog.php.
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS visit_log (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                path VARCHAR(255) NOT NULL,
                visitor CHAR(16) NOT NULL,
                referer_host VARCHAR(255) NULL,
                device ENUM('desktop','mobile','tablet','bot','other') NOT NULL DEFAULT 'other',
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY created_at (created_at),
                KEY path (path),
                KEY device (device),
                KEY referer_host (referer_host)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        // One row per request this site makes to the GitHub API — what it
        // asked for, what came back, and what GitHub said was left of the
        // hourly allowance. Nothing about who asked for it. This is the only
        // way to see the ceiling being hit, because a scan that dies on a
        // refusal never reaches usage_log at all. See lib/GitHubLog.php.
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS github_log (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                repo VARCHAR(255) NOT NULL DEFAULT '',
                endpoint VARCHAR(32) NOT NULL,
                status SMALLINT UNSIGNED NOT NULL,
                outcome ENUM('ok','blocked','missing','error') NOT NULL,
                remaining INT NULL,
                allowance INT NULL,
                reset_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY created_at (created_at),
                KEY outcome (outcome),
                KEY repo (repo)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        // One row per reading somebody disagreed with. Keyed on the
        // certificate id, which is unique per reading, so a second thought
        // replaces the first report rather than counting twice. See
        // lib/Feedback.php for what a report does and does not carry.
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS result_feedback (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                cert_id CHAR(12) NOT NULL,
                mode VARCHAR(16) NOT NULL,
                target VARCHAR(2048) NULL,
                target_host VARCHAR(255) NULL,
                score TINYINT UNSIGNED NOT NULL,
                verdict VARCHAR(32) NOT NULL,
                direction ENUM('too_high','too_low','about_right') NOT NULL,
                truth ENUM('human','ai','mixed','unsure') NOT NULL DEFAULT 'unsure',
                comment VARCHAR(500) NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY cert_id (cert_id),
                KEY created_at (created_at),
                KEY direction (direction),
                KEY target_host (target_host)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }
}
