<?php
declare(strict_types=1);

require_once __DIR__ . '/TenantContext.php';
require_once __DIR__ . '/Database.php';

/**
 * TenantProvisioning — end-to-end lifecycle for tenant databases on cPanel shared hosting.
 *
 * On cPanel shared hosting the MySQL user CANNOT run `CREATE DATABASE` directly
 * (Access denied). The supported path is `uapi Mysql create_database name=...`,
 * which we shell out to. After creation we grant the app DB user `ALL PRIVILEGES`
 * on the new database via `uapi Mysql set_privileges_on_database`, then apply
 * the tenant template schema via the regular `mysql` client.
 *
 * Naming convention:
 *   db_name format: zrismpsz_reya_t_NNNN (4-digit zero-padded tenant id).
 *   The cPanel-account prefix (`zrismpsz_`) is mandatory — cPanel rejects
 *   create_database calls whose name doesn't start with the account username.
 *
 * Every mutating operation:
 *   1. Records `status=started` in tenant_provisioning_log (master DB)
 *   2. Executes the uapi / mysql shell call
 *   3. Records `status=succeeded` OR `status=failed` (+ error_message)
 *   4. Throws RuntimeException on failure so callers can run compensating actions
 *
 * Pre-migration safety:
 *   When the master DB doesn't exist yet, log writes degrade to error_log()
 *   silently — `tenant_provisioning_log` requires `tenants.id` FK so the very
 *   first provision of tenant_0001 has no place to write log rows. This is
 *   acceptable for bootstrap; subsequent provisions land in the master log.
 */
class TenantProvisioning
{
    /** Mandatory cPanel-account prefix for ALL DB names on this host. */
    private const CPANEL_ACCOUNT = 'zrismpsz';

    /** Tenant DB name prefix: zrismpsz_reya_t_<NNNN>. */
    private const DB_PREFIX = 'zrismpsz_reya_t_';

    /** Tenant template — full DDL applied to every fresh tenant DB. */
    private const SCHEMA_FILE = __DIR__ . '/../database/migration_2026-05-25_tenant_template.sql';

    /** Path to uapi binary on cPanel hosts. */
    private const UAPI_BIN = '/usr/bin/uapi';

    /** Path to mysql client (used by applySchema). */
    private const MYSQL_BIN = '/usr/bin/mysql';

    // ---------------------------------------------------------------------
    // Naming helpers (pure — no I/O)
    // ---------------------------------------------------------------------

    /**
     * Returns the canonical DB name for a tenant id.
     *   42 → "zrismpsz_reya_t_0042"
     */
    public static function tenantIdToDbName(int $tenantId): string
    {
        if ($tenantId <= 0 || $tenantId > 9999) {
            throw new \InvalidArgumentException(
                "Tenant id out of range (1..9999): {$tenantId}"
            );
        }
        return self::DB_PREFIX . str_pad((string) $tenantId, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Inverse — extract tenant id from a db_name.
     * Returns null if the name doesn't match the expected pattern.
     */
    public static function dbNameToTenantId(string $dbName): ?int
    {
        if (!preg_match('/^' . preg_quote(self::DB_PREFIX, '/') . '(\d{4})$/', $dbName, $m)) {
            return null;
        }
        $tid = (int) $m[1];
        return $tid > 0 ? $tid : null;
    }

    // ---------------------------------------------------------------------
    // Existence / discovery
    // ---------------------------------------------------------------------

    /**
     * Returns true if the given DB exists according to information_schema.
     * Uses the platform PDO connection (master user already has SELECT on
     * information_schema for any schema it can see).
     */
    public static function exists(string $dbName): bool
    {
        try {
            $pdo = self::platformPdo();
            $stmt = $pdo->prepare(
                'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ? LIMIT 1'
            );
            $stmt->execute([$dbName]);
            return (bool) $stmt->fetchColumn();
        } catch (\Throwable $e) {
            // Master DB may not exist yet (bootstrap). Fall back to a direct probe.
            try {
                $pdo = new \PDO(
                    'mysql:host=' . DB_HOST . ';charset=utf8mb4',
                    DB_USER,
                    DB_PASS,
                    [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
                );
                $stmt = $pdo->prepare(
                    'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ? LIMIT 1'
                );
                $stmt->execute([$dbName]);
                return (bool) $stmt->fetchColumn();
            } catch (\Throwable $e2) {
                return false;
            }
        }
    }

    /**
     * List every database matching the tenant prefix.
     * Returns sorted list of db_name strings.
     */
    public static function listAll(): array
    {
        $like = self::DB_PREFIX . '%';
        try {
            $pdo  = self::platformPdo();
            $stmt = $pdo->prepare(
                'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME LIKE ? ORDER BY SCHEMA_NAME'
            );
            $stmt->execute([$like]);
            return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
        } catch (\Throwable $e) {
            return [];
        }
    }

    // ---------------------------------------------------------------------
    // Low-level shell wrappers
    // ---------------------------------------------------------------------

    /**
     * Create a database via cPanel uapi.
     * Returns the db_name on success; throws RuntimeException on failure.
     *
     * Validates that the name starts with the mandatory cPanel-account prefix
     * BEFORE shelling out — cPanel rejects other prefixes anyway, but failing
     * fast is friendlier than a YAML-parse error after the fact.
     */
    public static function create(int $tenantId): string
    {
        $dbName = self::tenantIdToDbName($tenantId);
        self::assertDbNameAllowed($dbName);

        if (self::exists($dbName)) {
            throw new \RuntimeException(
                "Refusing to create '{$dbName}': database already exists. "
                . "Use exists() or terminate() first."
            );
        }

        $logId = self::logStart($tenantId, 'create');
        try {
            $result = self::uapi('Mysql', 'create_database', ['name' => $dbName]);
            if (!self::isUapiSuccess($result)) {
                throw new \RuntimeException(
                    "uapi Mysql create_database failed for '{$dbName}': "
                    . self::extractUapiError($result)
                );
            }
            self::logComplete($logId, 'succeeded');
            return $dbName;
        } catch (\Throwable $e) {
            self::logComplete($logId, 'failed', $e->getMessage());
            throw $e;
        }
    }

    /**
     * Grant ALL PRIVILEGES on $dbName to the application MySQL user.
     * Idempotent — re-running on an already-granted DB is a no-op as far as
     * the application is concerned.
     */
    public static function grant(string $dbName, string $mysqlUser): void
    {
        self::assertDbNameAllowed($dbName);
        $tenantId = self::dbNameToTenantId($dbName) ?? 0;
        $logId    = self::logStart($tenantId, 'seed');

        try {
            $result = self::uapi('Mysql', 'set_privileges_on_database', [
                'user'       => $mysqlUser,
                'database'   => $dbName,
                'privileges' => 'ALL PRIVILEGES',
            ]);
            if (!self::isUapiSuccess($result)) {
                throw new \RuntimeException(
                    "uapi Mysql set_privileges_on_database failed for '{$dbName}': "
                    . self::extractUapiError($result)
                );
            }
            self::logComplete($logId, 'succeeded');
        } catch (\Throwable $e) {
            self::logComplete($logId, 'failed', $e->getMessage());
            throw $e;
        }
    }

    /**
     * Apply a SQL file to the given DB using the mysql client.
     *
     * Password handling: rather than passing -p<pass> on the command line
     * (visible via `ps`), we write a temporary --defaults-extra-file with
     * the credentials and remove it in a finally block.
     */
    public static function applySchema(string $dbName, string $schemaFile = self::SCHEMA_FILE): void
    {
        self::assertDbNameAllowed($dbName);

        if (!is_file($schemaFile) || !is_readable($schemaFile)) {
            throw new \RuntimeException("Schema file not readable: {$schemaFile}");
        }

        $tenantId = self::dbNameToTenantId($dbName) ?? 0;
        $logId    = self::logStart($tenantId, 'schema_apply', basename($schemaFile));

        // Write a one-shot defaults file so the password is never on argv.
        $defaultsFile = tempnam(sys_get_temp_dir(), 'reya_mysql_');
        if ($defaultsFile === false) {
            self::logComplete($logId, 'failed', 'tempnam() failed');
            throw new \RuntimeException('tempnam() failed creating mysql defaults file');
        }

        try {
            // Restrict perms before writing the password.
            @chmod($defaultsFile, 0600);
            $iniContent = "[client]\n"
                . 'host=' . DB_HOST . "\n"
                . 'user=' . DB_USER . "\n"
                . 'password="' . str_replace('"', '\\"', DB_PASS) . "\"\n";
            if (file_put_contents($defaultsFile, $iniContent) === false) {
                throw new \RuntimeException('Failed to write mysql defaults file');
            }

            $cmd = self::MYSQL_BIN
                . ' --defaults-extra-file=' . escapeshellarg($defaultsFile)
                . ' ' . escapeshellarg($dbName)
                . ' < ' . escapeshellarg($schemaFile);

            $start = microtime(true);
            $exec  = self::runShell($cmd);
            $ms    = (int) round((microtime(true) - $start) * 1000);

            if ($exec['exit_code'] !== 0) {
                throw new \RuntimeException(
                    "mysql client failed (exit {$exec['exit_code']}) applying "
                    . basename($schemaFile) . " to {$dbName}: "
                    . trim($exec['stderr'])
                );
            }

            // The tenant template intentionally omits platform-level tables, but
            // every tenant DB still needs admin_users for owner/staff login —
            // its absence made provisionFromOwner fatal with 1146 after the DB
            // was already created (orphaned half-provisioned tenants 0113/0115).
            self::ensureAdminUsersTable($dbName);

            self::logComplete($logId, 'succeeded');
            self::recordMigrationApplied($tenantId, basename($schemaFile), $schemaFile, $ms);
        } catch (\Throwable $e) {
            self::logComplete($logId, 'failed', $e->getMessage());
            throw $e;
        } finally {
            @unlink($defaultsFile);
        }
    }

    /**
     * Create the tenant-side admin_users table when the template didn't
     * (canonical DDL from install_complete_latest.sql). Idempotent.
     */
    public static function ensureAdminUsersTable(string $dbName): void
    {
        self::assertDbNameAllowed($dbName);
        $pdo = new \PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . $dbName . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `admin_users` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `username` varchar(100) NOT NULL,
              `email` varchar(255) NOT NULL,
              `phone` varchar(20) DEFAULT NULL,
              `password` varchar(255) NOT NULL,
              `display_name` varchar(255) DEFAULT NULL,
              `avatar_url` varchar(500) DEFAULT NULL,
              `role` varchar(20) DEFAULT 'admin',
              `line_account_id` int(11) DEFAULT NULL,
              `is_active` tinyint(1) DEFAULT 1,
              `last_login` timestamp NULL DEFAULT NULL,
              `login_count` int(11) DEFAULT 0,
              `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
              `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
              `line_user_id` varchar(50) DEFAULT NULL,
              `notification_enabled` tinyint(1) DEFAULT 1,
              PRIMARY KEY (`id`),
              UNIQUE KEY `username` (`username`),
              UNIQUE KEY `email` (`email`),
              KEY `idx_admin_users_role` (`role`),
              KEY `idx_admin_users_line_account` (`line_account_id`),
              KEY `idx_line_user` (`line_user_id`),
              KEY `idx_role_active` (`role`,`is_active`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    /**
     * Drop the database. Refuses any name that doesn't match the tenant prefix.
     * Caller MUST have backed up first — there is no undo.
     */
    public static function delete(string $dbName): void
    {
        self::assertDbNameAllowed($dbName);
        $tenantId = self::dbNameToTenantId($dbName) ?? 0;
        $logId    = self::logStart($tenantId, 'terminate', null);

        try {
            $result = self::uapi('Mysql', 'delete_database', ['name' => $dbName]);
            if (!self::isUapiSuccess($result)) {
                throw new \RuntimeException(
                    "uapi Mysql delete_database failed for '{$dbName}': "
                    . self::extractUapiError($result)
                );
            }
            self::logComplete($logId, 'succeeded');
        } catch (\Throwable $e) {
            self::logComplete($logId, 'failed', $e->getMessage());
            throw $e;
        }
    }

    // ---------------------------------------------------------------------
    // Lifecycle status mutations (no shell — pure DB updates)
    // ---------------------------------------------------------------------

    /** Set status='suspended'. Data preserved. Idempotent. */
    public static function suspend(int $tenantId): void
    {
        $pdo = self::platformPdo();
        $stmt = $pdo->prepare(
            'UPDATE tenants SET status = "suspended", suspended_at = NOW() WHERE id = ?'
        );
        $stmt->execute([$tenantId]);
        $logId = self::logStart($tenantId, 'suspend');
        self::logComplete($logId, 'succeeded');
    }

    /** Set status='active' (clears suspended_at). Idempotent. */
    public static function resume(int $tenantId): void
    {
        $pdo = self::platformPdo();
        $stmt = $pdo->prepare(
            'UPDATE tenants SET status = "active", suspended_at = NULL WHERE id = ?'
        );
        $stmt->execute([$tenantId]);
        $logId = self::logStart($tenantId, 'resume');
        self::logComplete($logId, 'succeeded');
    }

    /**
     * Mark a tenant for termination. By default does NOT drop the DB — that
     * happens after the grace period via cron. Pass $dropDb=true to drop
     * immediately (dangerous; used only after manual backup).
     */
    public static function terminate(int $tenantId, bool $dropDb = false, int $graceDays = 30): void
    {
        $pdo = self::platformPdo();
        $stmt = $pdo->prepare(
            'UPDATE tenants SET status = "terminated", terminated_at = NOW() WHERE id = ?'
        );
        $stmt->execute([$tenantId]);

        $logId = self::logStart($tenantId, 'terminate');
        try {
            if ($dropDb) {
                $dbName = self::tenantIdToDbName($tenantId);
                if (self::exists($dbName)) {
                    self::delete($dbName);
                }
            }
            self::logComplete($logId, 'succeeded');
        } catch (\Throwable $e) {
            self::logComplete($logId, 'failed', $e->getMessage());
            throw $e;
        }
    }

    // ---------------------------------------------------------------------
    // Composite operation
    // ---------------------------------------------------------------------

    /**
     * Full provision pipeline — runs every step in ADR-002 §"Provisioning Pipeline".
     *
     * $tenantData expected keys:
     *   slug, display_name, plan_id (int), owner_name, owner_email, owner_phone,
     *   created_by (platform_user_id, nullable)
     *
     * $defaultEntitlements — list of arrays:
     *   ['entitlement_key' => 'max_branches', 'value_int' => 1, 'note' => 'plan default']
     *
     * Returns ['db_name' => ..., 'master_tenant_id' => ...]
     *
     * NOTE: this method does NOT begin a master-DB transaction wrapping the
     * uapi shell-out — uapi side effects are not transactional. Instead it
     * runs compensating actions on failure (DROP DATABASE + DELETE tenants row).
     */
    public static function fullProvision(
        int $tenantId,
        array $tenantData,
        array $defaultEntitlements
    ): array {
        $pdo = self::platformPdo();

        // ---- Step 1: insert tenants row (status=pending_setup) ----
        // Caller may have already pre-allocated the tenant_id externally; if so
        // we INSERT with explicit id. Otherwise we INSERT and use lastInsertId.
        $dbName = self::tenantIdToDbName($tenantId);

        $insert = $pdo->prepare(
            'INSERT INTO tenants
              (id, slug, display_name, db_name, db_host, plan_id, status,
               owner_name, owner_email, owner_phone, created_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, "pending_setup", ?, ?, ?, ?, NOW())'
        );
        $insert->execute([
            $tenantId,
            $tenantData['slug'] ?? ('tenant-' . $tenantId),
            $tenantData['display_name'] ?? ('Tenant ' . $tenantId),
            $dbName,
            $tenantData['db_host'] ?? 'localhost',
            (int) ($tenantData['plan_id'] ?? 1),
            $tenantData['owner_name'] ?? null,
            $tenantData['owner_email'] ?? null,
            $tenantData['owner_phone'] ?? null,
            isset($tenantData['created_by']) ? (int) $tenantData['created_by'] : null,
        ]);

        $masterTenantId = $tenantId;

        try {
            // ---- Step 2: create the physical DB via uapi ----
            self::create($tenantId);

            // ---- Step 3: grant the app user on it ----
            self::grant($dbName, DB_USER);

            // ---- Step 4: apply the tenant template schema ----
            self::applySchema($dbName);

            // ---- Step 5: seed default entitlements ----
            $ent = $pdo->prepare(
                'INSERT INTO entitlements
                    (tenant_id, entitlement_key, value_int, value_text, value_bool, note)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            foreach ($defaultEntitlements as $row) {
                $ent->execute([
                    $masterTenantId,
                    (string) $row['entitlement_key'],
                    $row['value_int']  ?? null,
                    $row['value_text'] ?? null,
                    $row['value_bool'] ?? null,
                    $row['note']       ?? null,
                ]);
            }

            // ---- Step 6: flip status to active ----
            $pdo->prepare('UPDATE tenants SET status = "active" WHERE id = ?')
                ->execute([$masterTenantId]);

            return ['db_name' => $dbName, 'master_tenant_id' => $masterTenantId];

        } catch (\Throwable $e) {
            // Compensating rollback — best effort, log failures but rethrow original.
            try {
                if (self::exists($dbName)) {
                    self::delete($dbName);
                }
            } catch (\Throwable $rollbackEx) {
                error_log('[TenantProvisioning] rollback delete failed: ' . $rollbackEx->getMessage());
            }
            try {
                $pdo->prepare('DELETE FROM tenants WHERE id = ?')->execute([$masterTenantId]);
            } catch (\Throwable $rollbackEx) {
                error_log('[TenantProvisioning] rollback row delete failed: ' . $rollbackEx->getMessage());
            }
            throw $e;
        }
    }

    // ---------------------------------------------------------------------
    // Internal — shell + uapi plumbing
    // ---------------------------------------------------------------------

    /**
     * Run `uapi --output=json <Module> <Function> key1=val1 key2=val2 ...`.
     * Returns ['stdout' => string, 'stderr' => string, 'exit_code' => int, 'json' => array|null].
     */
    private static function uapi(string $module, string $function, array $args): array
    {
        $cmd = self::UAPI_BIN . ' --output=json'
            . ' ' . escapeshellarg($module)
            . ' ' . escapeshellarg($function);
        foreach ($args as $k => $v) {
            // uapi expects key=value pairs as separate argv elements
            $cmd .= ' ' . escapeshellarg($k . '=' . $v);
        }

        $result = self::runShell($cmd);
        $result['json'] = null;
        if ($result['stdout'] !== '') {
            $decoded = json_decode($result['stdout'], true);
            if (is_array($decoded)) {
                $result['json'] = $decoded;
            }
        }
        return $result;
    }

    /**
     * proc_open wrapper so we can capture stderr separately from stdout.
     * uapi writes its YAML/JSON on stdout and error chatter on stderr.
     */
    private static function runShell(string $cmd): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($proc)) {
            return ['stdout' => '', 'stderr' => 'proc_open() failed', 'exit_code' => 127];
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);
        return [
            'stdout'    => $stdout,
            'stderr'    => $stderr,
            'exit_code' => is_int($exitCode) ? $exitCode : -1,
        ];
    }

    /**
     * Did the uapi response indicate success?
     * uapi JSON shape: { "result": { "status": 1, "errors": null, "data": {...} } }
     */
    private static function isUapiSuccess(array $result): bool
    {
        if ($result['exit_code'] !== 0) {
            return false;
        }
        $json = $result['json'] ?? null;
        if (!is_array($json) || !isset($json['result'])) {
            return false;
        }
        return (int) ($json['result']['status'] ?? 0) === 1;
    }

    /**
     * Extract a human-readable error from a uapi response.
     */
    private static function extractUapiError(array $result): string
    {
        $json = $result['json'] ?? null;
        if (is_array($json) && isset($json['result']['errors']) && is_array($json['result']['errors'])) {
            return implode('; ', array_map('strval', $json['result']['errors']));
        }
        $stderr = trim($result['stderr'] ?? '');
        if ($stderr !== '') {
            return $stderr;
        }
        $stdout = trim($result['stdout'] ?? '');
        return $stdout !== '' ? $stdout : 'unknown uapi failure';
    }

    /**
     * Guard against accidentally targeting a non-tenant DB.
     * Refuses anything not starting with `zrismpsz_reya_t_`.
     */
    private static function assertDbNameAllowed(string $dbName): void
    {
        if (strpos($dbName, self::DB_PREFIX) !== 0) {
            throw new \InvalidArgumentException(
                "Refusing to operate on '{$dbName}': name does not start with '"
                . self::DB_PREFIX . "'."
            );
        }
        // Also reject anything that doesn't match the strict pattern — defense in depth.
        if (!preg_match('/^' . preg_quote(self::DB_PREFIX, '/') . '\d{4}$/', $dbName)) {
            throw new \InvalidArgumentException(
                "Refusing to operate on '{$dbName}': does not match strict tenant pattern."
            );
        }
    }

    // ---------------------------------------------------------------------
    // Internal — provisioning log writes
    // ---------------------------------------------------------------------

    /**
     * INSERT a `started` row into tenant_provisioning_log; returns the new id.
     * Returns 0 if the master DB isn't reachable yet (bootstrap).
     */
    private static function logStart(int $tenantId, string $event, ?string $migrationFile = null): int
    {
        try {
            $pdo = self::platformPdo();
            $stmt = $pdo->prepare(
                'INSERT INTO tenant_provisioning_log
                    (tenant_id, event, migration_file, status, started_at)
                 VALUES (?, ?, ?, "started", NOW())'
            );
            // tenant_id is FK-constrained — only insert if tenants row exists.
            if (!self::tenantRowExists($pdo, $tenantId)) {
                error_log("[TenantProvisioning] skip log: tenant id {$tenantId} has no master row yet");
                return 0;
            }
            $stmt->execute([$tenantId, $event, $migrationFile]);
            return (int) $pdo->lastInsertId();
        } catch (\Throwable $e) {
            error_log('[TenantProvisioning] logStart failed: ' . $e->getMessage());
            return 0;
        }
    }

    private static function logComplete(int $logId, string $status, ?string $error = null): void
    {
        if ($logId <= 0) {
            return;
        }
        try {
            $pdo  = self::platformPdo();
            $stmt = $pdo->prepare(
                'UPDATE tenant_provisioning_log
                    SET status = ?, error_message = ?, completed_at = NOW()
                  WHERE id = ?'
            );
            $stmt->execute([$status, $error, $logId]);
        } catch (\Throwable $e) {
            error_log('[TenantProvisioning] logComplete failed: ' . $e->getMessage());
        }
    }

    private static function recordMigrationApplied(
        int $tenantId,
        string $fileName,
        string $absPath,
        int $execMs
    ): void {
        try {
            $pdo = self::platformPdo();
            if (!self::tenantRowExists($pdo, $tenantId)) {
                return;
            }
            $checksum = hash_file('sha256', $absPath) ?: null;
            $stmt = $pdo->prepare(
                'INSERT INTO tenant_migrations
                    (tenant_id, migration_file, applied_at, checksum, execution_ms, status)
                 VALUES (?, ?, NOW(), ?, ?, "applied")
                 ON DUPLICATE KEY UPDATE
                    applied_at   = NOW(),
                    checksum     = VALUES(checksum),
                    execution_ms = VALUES(execution_ms),
                    status       = "applied",
                    error_message = NULL'
            );
            $stmt->execute([$tenantId, $fileName, $checksum, $execMs]);
        } catch (\Throwable $e) {
            error_log('[TenantProvisioning] recordMigrationApplied failed: ' . $e->getMessage());
        }
    }

    private static function tenantRowExists(\PDO $pdo, int $tenantId): bool
    {
        if ($tenantId <= 0) {
            return false;
        }
        try {
            $stmt = $pdo->prepare('SELECT 1 FROM tenants WHERE id = ? LIMIT 1');
            $stmt->execute([$tenantId]);
            return (bool) $stmt->fetchColumn();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Get a PDO connection to the master (platform) DB.
     * Wraps Database::platform()->getConnection().
     */
    private static function platformPdo(): \PDO
    {
        return \Database::platform()->getConnection();
    }
}
