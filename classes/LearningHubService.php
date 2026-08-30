<?php
declare(strict_types=1);

/**
 * LearningHubService — platform-global tutorial videos + customer comments.
 *
 * Content lives in the platform (master) DB so REYA uploads once and every
 * tenant sees the same library. Comments are tagged with the commenter's
 * tenant_id for context and moderated by platform admins.
 *
 * Pass a PDO to the platform DB (TenantContext::getPlatformConnection()).
 */
class LearningHubService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->ensureSchema();
    }

    /**
     * Build a PDO to the platform (master) DB without depending on a specific
     * TenantContext version. Prefers TenantContext::getPlatformConnection() when
     * present, otherwise connects directly using the shared DB credentials.
     * Returns null if the platform DB is unreachable.
     */
    public static function platformPdo(): ?PDO
    {
        if (class_exists('TenantContext') && method_exists('TenantContext', 'getPlatformConnection')) {
            $pdo = TenantContext::getPlatformConnection();
            if ($pdo instanceof PDO) {
                return $pdo;
            }
        }
        if (!defined('DB_HOST') || !defined('DB_USER') || !defined('DB_PASS')) {
            return null;
        }
        $dbName = (class_exists('TenantContext') && defined('TenantContext::PLATFORM_DB_NAME'))
            ? TenantContext::PLATFORM_DB_NAME
            : 'zrismpsz_reya_platform';
        try {
            $pdo = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . $dbName . ';charset=utf8mb4',
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]
            );
            $pdo->exec("SET time_zone = '+07:00'");
            return $pdo;
        } catch (PDOException $e) {
            return null;
        }
    }

    /** Defensive: create tables if the migration hasn't been run yet. */
    private function ensureSchema(): void
    {
        try {
            $this->db->exec(
                "CREATE TABLE IF NOT EXISTS learning_content (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    title VARCHAR(255) NOT NULL,
                    slug VARCHAR(255) NOT NULL,
                    description TEXT NULL,
                    category VARCHAR(100) NULL,
                    source_type ENUM('youtube','url','file') NOT NULL DEFAULT 'youtube',
                    video_url VARCHAR(1000) NULL,
                    thumbnail_url VARCHAR(1000) NULL,
                    duration VARCHAR(50) NULL,
                    sort_order INT NOT NULL DEFAULT 0,
                    view_count INT NOT NULL DEFAULT 0,
                    is_published TINYINT(1) NOT NULL DEFAULT 1,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_learning_slug (slug),
                    KEY idx_learning_pub (is_published, sort_order)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            $this->db->exec(
                "CREATE TABLE IF NOT EXISTS learning_comments (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    content_id INT NOT NULL,
                    tenant_id INT NULL,
                    user_id INT NULL,
                    author_name VARCHAR(150) NOT NULL,
                    body TEXT NOT NULL,
                    is_admin_reply TINYINT(1) NOT NULL DEFAULT 0,
                    parent_id INT NULL,
                    status ENUM('visible','hidden') NOT NULL DEFAULT 'visible',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    KEY idx_lc_content (content_id, status, created_at),
                    KEY idx_lc_parent (parent_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        } catch (PDOException $e) {
            // Tables may already exist with different privileges — ignore.
        }
    }

    // ---------------------------------------------------------------- content

    /** Published videos for the customer-facing hub. */
    public function listPublished(?string $category = null): array
    {
        $sql = "SELECT * FROM learning_content WHERE is_published = 1";
        $params = [];
        if ($category !== null && $category !== '') {
            $sql .= " AND category = ?";
            $params[] = $category;
        }
        $sql .= " ORDER BY sort_order ASC, created_at DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** All videos (admin view, incl. unpublished). */
    public function listAll(): array
    {
        return $this->db->query(
            "SELECT * FROM learning_content ORDER BY sort_order ASC, created_at DESC"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getById(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM learning_content WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getBySlug(string $slug): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM learning_content WHERE slug = ? LIMIT 1");
        $stmt->execute([$slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** Distinct categories present in the library. */
    public function listCategories(): array
    {
        $rows = $this->db->query(
            "SELECT DISTINCT category FROM learning_content
              WHERE is_published = 1 AND category IS NOT NULL AND category <> ''
              ORDER BY category ASC"
        )->fetchAll(PDO::FETCH_COLUMN);
        return $rows ?: [];
    }

    public function create(array $data): int
    {
        $slug = $this->uniqueSlug($data['title'] ?? 'video');
        $stmt = $this->db->prepare(
            "INSERT INTO learning_content
                (title, slug, description, category, source_type, video_url, thumbnail_url, duration, sort_order, is_published)
             VALUES (?,?,?,?,?,?,?,?,?,?)"
        );
        $stmt->execute([
            trim((string)($data['title'] ?? '')),
            $slug,
            $data['description'] ?? null,
            $data['category'] ?? null,
            $this->normalizeSource($data['source_type'] ?? 'youtube'),
            $data['video_url'] ?? null,
            $data['thumbnail_url'] ?? null,
            $data['duration'] ?? null,
            (int)($data['sort_order'] ?? 0),
            isset($data['is_published']) ? (int)$data['is_published'] : 1,
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $stmt = $this->db->prepare(
            "UPDATE learning_content SET
                title = ?, description = ?, category = ?, source_type = ?,
                video_url = ?, thumbnail_url = ?, duration = ?, sort_order = ?, is_published = ?
             WHERE id = ?"
        );
        $stmt->execute([
            trim((string)($data['title'] ?? '')),
            $data['description'] ?? null,
            $data['category'] ?? null,
            $this->normalizeSource($data['source_type'] ?? 'youtube'),
            $data['video_url'] ?? null,
            $data['thumbnail_url'] ?? null,
            $data['duration'] ?? null,
            (int)($data['sort_order'] ?? 0),
            isset($data['is_published']) ? (int)$data['is_published'] : 1,
            $id,
        ]);
    }

    public function delete(int $id): void
    {
        $this->db->prepare("DELETE FROM learning_comments WHERE content_id = ?")->execute([$id]);
        $this->db->prepare("DELETE FROM learning_content WHERE id = ?")->execute([$id]);
    }

    public function incrementView(int $id): void
    {
        try {
            $this->db->prepare("UPDATE learning_content SET view_count = view_count + 1 WHERE id = ?")
                ->execute([$id]);
        } catch (PDOException $e) {
            // non-critical
        }
    }

    // --------------------------------------------------------------- comments

    /** Visible comments for a video (with admin replies nested by parent_id). */
    public function listComments(int $contentId, bool $includeHidden = false): array
    {
        $sql = "SELECT * FROM learning_comments WHERE content_id = ?";
        if (!$includeHidden) {
            $sql .= " AND status = 'visible'";
        }
        $sql .= " ORDER BY created_at ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$contentId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function countComments(int $contentId): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM learning_comments WHERE content_id = ? AND status = 'visible'"
        );
        $stmt->execute([$contentId]);
        return (int)$stmt->fetchColumn();
    }

    public function addComment(int $contentId, string $authorName, string $body, ?int $tenantId, ?int $userId): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO learning_comments (content_id, tenant_id, user_id, author_name, body)
             VALUES (?,?,?,?,?)"
        );
        $stmt->execute([
            $contentId,
            $tenantId,
            $userId,
            mb_substr(trim($authorName) ?: 'ผู้ใช้', 0, 150),
            mb_substr(trim($body), 0, 4000),
        ]);
        return (int)$this->db->lastInsertId();
    }

    /** Admin reply to a comment (shown nested under the original). */
    public function addAdminReply(int $contentId, int $parentId, string $authorName, string $body): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO learning_comments (content_id, author_name, body, is_admin_reply, parent_id)
             VALUES (?,?,?,1,?)"
        );
        $stmt->execute([
            $contentId,
            mb_substr(trim($authorName) ?: 'ทีมงาน REYA', 0, 150),
            mb_substr(trim($body), 0, 4000),
            $parentId,
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function setCommentStatus(int $id, string $status): void
    {
        $status = $status === 'hidden' ? 'hidden' : 'visible';
        $this->db->prepare("UPDATE learning_comments SET status = ? WHERE id = ?")->execute([$status, $id]);
    }

    public function deleteComment(int $id): void
    {
        // Delete the comment and any admin replies attached to it.
        $this->db->prepare("DELETE FROM learning_comments WHERE id = ? OR parent_id = ?")->execute([$id, $id]);
    }

    /** Recent comments across all videos — admin moderation queue. */
    public function recentComments(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $stmt = $this->db->prepare(
            "SELECT c.*, v.title AS content_title
               FROM learning_comments c
               JOIN learning_content v ON v.id = c.content_id
              WHERE c.is_admin_reply = 0
              ORDER BY c.created_at DESC
              LIMIT $limit"
        );
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ----------------------------------------------------------------- helpers

    private function normalizeSource(string $s): string
    {
        return in_array($s, ['youtube', 'url', 'file'], true) ? $s : 'youtube';
    }

    private function uniqueSlug(string $title): string
    {
        $base = strtolower(trim($title));
        $base = preg_replace('/[^a-z0-9]+/u', '-', $base) ?? '';
        $base = trim($base, '-');
        // Thai/non-latin titles collapse to empty — fall back to a stable token.
        if ($base === '' || strlen($base) < 3) {
            $base = 'video-' . substr(md5($title . microtime()), 0, 8);
        }
        $slug = $base;
        $i = 2;
        while ($this->slugExists($slug)) {
            $slug = $base . '-' . $i;
            $i++;
        }
        return $slug;
    }

    private function slugExists(string $slug): bool
    {
        $stmt = $this->db->prepare("SELECT 1 FROM learning_content WHERE slug = ? LIMIT 1");
        $stmt->execute([$slug]);
        return (bool)$stmt->fetchColumn();
    }

    /** Extract a YouTube video id from common URL forms (for embedding). */
    public static function youtubeId(string $url): ?string
    {
        if (preg_match('~(?:youtu\.be/|youtube\.com/(?:watch\?v=|embed/|shorts/))([A-Za-z0-9_-]{6,})~', $url, $m)) {
            return $m[1];
        }
        return null;
    }
}
