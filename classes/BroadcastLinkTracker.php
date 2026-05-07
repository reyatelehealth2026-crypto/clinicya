<?php
/**
 * BroadcastLinkTracker
 *
 * Rewrites HTTP(S) URLs inside outgoing broadcast messages so each link
 * becomes a tracked redirect. Original URL → short token → INSERT click row
 * at hit time → 302 to the original URL.
 *
 * Companion files:
 *  - api/broadcast_redirect.php   (resolves token + records click)
 *  - database/migration_2026-05-04_unified_broadcast.sql (creates tables)
 *
 * Distinct from classes/LinkTrackingService.php, which is a generic
 * admin-curated short-link feature (tracked_links / link_clicks).
 */
class BroadcastLinkTracker
{
    /** @var PDO */
    private $db;

    /** @var string Public endpoint that will resolve tokens */
    private $redirectBase;

    public function __construct(PDO $db, string $redirectBase = '')
    {
        $this->db = $db;
        $this->redirectBase = $redirectBase ?: self::defaultRedirectBase();
    }

    /**
     * Build the public redirect base from APP_URL or current host.
     */
    public static function defaultRedirectBase(): string
    {
        if (defined('APP_URL') && APP_URL) {
            return rtrim(APP_URL, '/') . '/api/broadcast_redirect.php';
        }
        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return $proto . '://' . $host . '/api/broadcast_redirect.php';
    }

    /**
     * Generate a 16-char hex token, ensuring uniqueness against broadcast_links.
     */
    public function mintToken(int $campaignId, string $originalUrl): string
    {
        for ($i = 0; $i < 5; $i++) {
            $token = bin2hex(random_bytes(8));
            try {
                $stmt = $this->db->prepare(
                    "INSERT INTO broadcast_links (token, campaign_id, original_url) VALUES (?, ?, ?)"
                );
                $stmt->execute([$token, $campaignId, $originalUrl]);
                return $token;
            } catch (PDOException $e) {
                // 23000 = duplicate key, retry. Other errors bubble up.
                if ($e->getCode() !== '23000') {
                    throw $e;
                }
            }
        }
        throw new RuntimeException('Failed to mint unique broadcast link token after 5 attempts');
    }

    /**
     * Replace every http(s):// URL in $text with a tracked redirect URL for $campaignId.
     * Skips the redirect base itself to avoid double-wrapping.
     */
    public function rewriteText(string $text, int $campaignId): string
    {
        $base = $this->redirectBase;
        return preg_replace_callback(
            '~https?://[^\s<>"\']+~u',
            function ($m) use ($campaignId, $base) {
                $url = $m[0];
                if (stripos($url, $base) === 0) {
                    return $url;
                }
                $token = $this->mintToken($campaignId, $url);
                return $base . '?t=' . $token;
            },
            $text
        );
    }

    /**
     * Recursively rewrite "uri" actions and text fields inside a Flex message tree.
     */
    public function rewriteFlex(array $flex, int $campaignId): array
    {
        $base = $this->redirectBase;
        $walker = function (&$node) use (&$walker, $campaignId, $base) {
            if (!is_array($node)) {
                return;
            }
            // {type:"uri", uri:"https://..."}
            if (isset($node['type'], $node['uri']) && $node['type'] === 'uri'
                && is_string($node['uri']) && preg_match('~^https?://~i', $node['uri'])
                && stripos($node['uri'], $base) !== 0) {
                $node['uri'] = $base . '?t=' . $this->mintToken($campaignId, $node['uri']);
            }
            // text nodes can contain raw URLs
            if (isset($node['type'], $node['text']) && $node['type'] === 'text' && is_string($node['text'])) {
                $node['text'] = $this->rewriteText($node['text'], $campaignId);
            }
            foreach ($node as &$child) {
                if (is_array($child)) {
                    $walker($child);
                }
            }
        };
        $walker($flex);
        return $flex;
    }

    /**
     * Resolve a token → original URL row, or null if unknown.
     * @return array{token:string,campaign_id:int,original_url:string}|null
     */
    public function resolve(string $token): ?array
    {
        $stmt = $this->db->prepare("SELECT token, campaign_id, original_url FROM broadcast_links WHERE token = ?");
        $stmt->execute([$token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Record a click. All identity fields are best-effort; we never fail the
     * redirect because of logging issues.
     */
    public function recordClick(array $link, ?int $userId, ?string $lineUserId, ?int $lineAccountId, ?string $ua, ?string $ip): void
    {
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO broadcast_link_clicks
                   (campaign_id, line_account_id, user_id, line_user_id, link_token, original_url, user_agent, ip)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                (int)$link['campaign_id'],
                $lineAccountId,
                $userId,
                $lineUserId,
                $link['token'],
                $link['original_url'],
                $ua ? mb_substr($ua, 0, 255) : null,
                $ip ? mb_substr($ip, 0, 64) : null,
            ]);

            // Bump campaign click_count so existing analytics see link clicks too.
            $this->db->prepare(
                "UPDATE broadcast_campaigns SET click_count = click_count + 1 WHERE id = ?"
            )->execute([(int)$link['campaign_id']]);
        } catch (Exception $e) {
            error_log('BroadcastLinkTracker::recordClick failed: ' . $e->getMessage());
        }
    }
}
