<?php
declare(strict_types=1);

/**
 * TenantFileStorage — per-tenant filesystem paths + URL builders.
 *
 * Implements the file-storage portion of ADR-001 (Database-per-Tenant Isolation).
 * Every uploaded file MUST land under a per-tenant directory so that URL-guessing
 * one tenant's filename cannot fetch another tenant's slip / product image /
 * profile picture.
 *
 * Layout (on disk):
 *   <storage_root>/
 *   ├── platform/                  ← system assets, default avatars, brand logos
 *   └── tenant_0001/
 *       ├── slips/                 ← payment slips from customers
 *       ├── products/              ← product images managed by tenant
 *       ├── logos/                 ← tenant brand / shop logo
 *       ├── exports/               ← CSV / Excel exports generated for tenant
 *       ├── rx_uploads/            ← prescription images uploaded by customers
 *       └── profile_pics/          ← user avatars within the tenant
 *
 * Storage root resolution order:
 *   1. Env var  REYA_STORAGE_ROOT      (e.g. /var/reya/storage in production)
 *   2. Constant REYA_STORAGE_ROOT      (defined in config/config.php)
 *   3. Default: <project>/uploads      (same place as today — works on shared hosting)
 *
 * Tenant dir naming: sprintf('tenant_%04d', $tenantId)
 *   Zero-padded to 4 digits for filesystem sort + clean directory listings.
 *
 * Security notes:
 *   - Filenames are validated against ^[A-Za-z0-9._-]+$ — anything with `/`, `..`,
 *     `\`, NUL bytes, or other path-traversal characters is rejected.
 *   - saveUpload() GENERATES the destination filename from random bytes plus the
 *     uploaded extension — the caller cannot inject a filename, and $_FILES['name']
 *     is never trusted for the on-disk name.
 *   - The bucket name must be in BUCKETS (constant whitelist below). Free-form
 *     bucket strings would re-introduce the path-injection class of bug.
 *   - All directories created with 0750 (owner: web user, group: web group, no
 *     world access). Files written 0640.
 *
 * NOT responsible for:
 *   - URL signing / expiring tokens (a future signed-URL service will wrap this).
 *   - Virus scanning / MIME validation of upload contents (caller's job — pass
 *     a pre-validated $_FILES entry).
 *   - Updating database rows that point at the file (caller's job).
 */
class TenantFileStorage
{
    /**
     * Allowed buckets. Adding a new bucket = explicit change here + an entry in
     * docs/file-storage-migration-plan.md. Do not pass free-form strings.
     */
    public const BUCKETS = [
        'slips',
        'products',
        'logos',
        'exports',
        'rx_uploads',
        'profile_pics',
        'shop_photos',
    ];

    /** Filename validation regex — basenames only, no path separators. */
    private const FILENAME_RE = '/\A[A-Za-z0-9._-]+\z/';

    /** Directory permission for tenant + bucket dirs. */
    private const DIR_PERM = 0750;

    /** File permission for stored uploads. */
    private const FILE_PERM = 0640;

    /** Max filename length we will accept (DB column is VARCHAR(500)). */
    private const MAX_FILENAME_LEN = 200;

    /** Returns absolute filesystem path for a tenant's root directory. */
    public static function tenantRoot(int $tenantId): string
    {
        self::assertTenantId($tenantId);
        return self::storageRoot() . DIRECTORY_SEPARATOR . self::tenantDirName($tenantId);
    }

    /**
     * Returns absolute path for a sub-bucket, with trailing separator.
     * Example: TenantFileStorage::path(42, 'slips') -> ".../tenant_0042/slips/"
     */
    public static function path(int $tenantId, string $bucket): string
    {
        self::assertTenantId($tenantId);
        self::assertBucket($bucket);
        return self::tenantRoot($tenantId) . DIRECTORY_SEPARATOR . $bucket . DIRECTORY_SEPARATOR;
    }

    /**
     * Builds a public URL for a stored file.
     * Path layout under web root: /uploads/tenant_NNNN/<bucket>/<filename>
     *
     * The web-root prefix is taken from BASE_URL constant if defined; otherwise
     * the URL is returned as a root-relative path ("/uploads/...") so it still
     * works behind any host.
     */
    public static function url(int $tenantId, string $bucket, string $filename): string
    {
        self::assertTenantId($tenantId);
        self::assertBucket($bucket);
        self::assertFilename($filename);

        $rel = '/uploads/' . self::tenantDirName($tenantId) . '/' . $bucket . '/' . $filename;

        if (defined('BASE_URL')) {
            return rtrim((string) constant('BASE_URL'), '/') . $rel;
        }
        return $rel;
    }

    /**
     * Saves an uploaded file from a $_FILES entry into tenant scope.
     * Returns the stored basename (not the full path) so the caller can persist
     * it in a database column. Throws RuntimeException on any failure.
     *
     * @param array{tmp_name:string,name?:string,error?:int,size?:int} $fileFromFiles
     */
    public static function saveUpload(int $tenantId, string $bucket, array $fileFromFiles): string
    {
        self::assertTenantId($tenantId);
        self::assertBucket($bucket);

        if (!isset($fileFromFiles['tmp_name']) || !is_string($fileFromFiles['tmp_name'])) {
            throw new RuntimeException('saveUpload: missing tmp_name');
        }
        if (isset($fileFromFiles['error']) && $fileFromFiles['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('saveUpload: upload error code ' . (int) $fileFromFiles['error']);
        }
        if (!is_uploaded_file($fileFromFiles['tmp_name'])) {
            throw new RuntimeException('saveUpload: not an uploaded file');
        }

        $ext = self::safeExtension($fileFromFiles['name'] ?? '');
        $filename = self::generateFilename($bucket, $ext);
        self::ensureDir($tenantId, $bucket);
        $dest = self::path($tenantId, $bucket) . $filename;

        if (!move_uploaded_file($fileFromFiles['tmp_name'], $dest)) {
            throw new RuntimeException('saveUpload: move_uploaded_file failed');
        }
        @chmod($dest, self::FILE_PERM);

        return $filename;
    }

    /** Deletes a single file. Returns true if the file no longer exists after the call. */
    public static function delete(int $tenantId, string $bucket, string $filename): bool
    {
        self::assertTenantId($tenantId);
        self::assertBucket($bucket);
        self::assertFilename($filename);

        $full = self::path($tenantId, $bucket) . $filename;
        if (!file_exists($full)) {
            return true; // already gone — idempotent
        }
        return @unlink($full);
    }

    /** Ensures the bucket dir (and the tenant dir) exist with the right perms. */
    public static function ensureDir(int $tenantId, string $bucket): void
    {
        self::assertTenantId($tenantId);
        self::assertBucket($bucket);

        $dir = self::path($tenantId, $bucket);
        if (is_dir($dir)) {
            return;
        }
        if (!@mkdir($dir, self::DIR_PERM, true) && !is_dir($dir)) {
            throw new RuntimeException('ensureDir: cannot create ' . $dir);
        }
        @chmod($dir, self::DIR_PERM);
        @chmod(dirname($dir), self::DIR_PERM); // also tighten the tenant root we just created
    }

    /**
     * Lists files in a bucket. Returns an array of basenames (no paths).
     * Returns [] if the bucket directory does not exist yet.
     */
    public static function list(int $tenantId, string $bucket): array
    {
        self::assertTenantId($tenantId);
        self::assertBucket($bucket);

        $dir = self::path($tenantId, $bucket);
        if (!is_dir($dir)) {
            return [];
        }
        $entries = @scandir($dir);
        if ($entries === false) {
            return [];
        }
        $out = [];
        foreach ($entries as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            if (is_file($dir . $name) && preg_match(self::FILENAME_RE, $name) === 1) {
                $out[] = $name;
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------ helpers

    /** Resolves the storage root (see file docblock for priority). */
    public static function storageRoot(): string
    {
        $env = getenv('REYA_STORAGE_ROOT');
        if (is_string($env) && $env !== '') {
            return rtrim($env, "/\\");
        }
        if (defined('REYA_STORAGE_ROOT')) {
            return rtrim((string) constant('REYA_STORAGE_ROOT'), "/\\");
        }
        return rtrim(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'uploads', "/\\");
    }

    /** "tenant_0042" — zero-padded for filesystem sortability. */
    public static function tenantDirName(int $tenantId): string
    {
        return sprintf('tenant_%04d', $tenantId);
    }

    private static function assertTenantId(int $tenantId): void
    {
        if ($tenantId < 1) {
            throw new InvalidArgumentException('tenant id must be a positive int');
        }
    }

    private static function assertBucket(string $bucket): void
    {
        if (!in_array($bucket, self::BUCKETS, true)) {
            throw new InvalidArgumentException('unknown bucket: ' . $bucket);
        }
    }

    private static function assertFilename(string $filename): void
    {
        if ($filename === '' || strlen($filename) > self::MAX_FILENAME_LEN) {
            throw new InvalidArgumentException('filename length invalid');
        }
        if (preg_match(self::FILENAME_RE, $filename) !== 1) {
            throw new InvalidArgumentException('filename contains illegal characters: ' . $filename);
        }
        // Belt-and-braces: even though the regex blocks ../, we double-check that
        // basename() agrees so we cannot be tricked by NUL-stripping bugs.
        if (basename($filename) !== $filename) {
            throw new InvalidArgumentException('filename must be a bare basename');
        }
    }

    /**
     * Returns a safe extension (lowercase, max 8 chars, alphanum only).
     * If the original name has no extension, returns 'bin'.
     */
    private static function safeExtension(string $originalName): string
    {
        $ext = pathinfo($originalName, PATHINFO_EXTENSION);
        $ext = is_string($ext) ? strtolower($ext) : '';
        if ($ext === '' || preg_match('/\A[a-z0-9]{1,8}\z/', $ext) !== 1) {
            return 'bin';
        }
        return $ext;
    }

    /**
     * Generates a deterministic-but-unguessable filename.
     * Pattern: <bucket>_<unixtime>_<8bytes-hex>.<ext>
     * 16 hex chars of entropy = 64 bits, sufficient against guessing within a bucket.
     */
    private static function generateFilename(string $bucket, string $ext): string
    {
        $rand = bin2hex(random_bytes(8));
        return sprintf('%s_%d_%s.%s', $bucket, time(), $rand, $ext);
    }
}
