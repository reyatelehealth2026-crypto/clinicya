<?php
/**
 * SiteNotifier — lightweight site-level alerts for the REYA platform.
 *
 *   1) New visitor IP on the public SaaS landing (re-ya.com) → Telegram alert
 *      (first time an IP is seen only; repeat IPs are a cheap indexed no-op).
 *   2) New self-serve shop signup → Email (+ Telegram) to the platform owner.
 *
 * Secrets live in config/notify_config.php (gitignored, server-only):
 *   NOTIFY_SIGNUP_EMAIL, NOTIFY_TELEGRAM_BOT_TOKEN, NOTIFY_TELEGRAM_CHAT_ID
 *
 * Everything here is best-effort: a failure must never break the page or the
 * signup flow — all public methods swallow their own errors into error_log.
 */
declare(strict_types=1);

class SiteNotifier
{
    /** Lazy-load the server-only secrets file once. */
    private static function ensureConfig(): void
    {
        if (defined('NOTIFY_SIGNUP_EMAIL')) {
            return;
        }
        $f = __DIR__ . '/../config/notify_config.php';
        if (is_file($f)) {
            require_once $f;
        }
    }

    private static function cfg(string $const, string $default = ''): string
    {
        self::ensureConfig();
        return defined($const) ? (string) constant($const) : $default;
    }

    /** Real visitor IP, Cloudflare-aware (the origin sees CF edge IPs in REMOTE_ADDR). */
    private static function clientIp(): string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $k) {
            if (!empty($_SERVER[$k])) {
                $ip = trim(explode(',', (string) $_SERVER[$k])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return '';
    }

    // ---------------------------------------------------------------------
    // Telegram
    // ---------------------------------------------------------------------

    public static function sendTelegram(string $text): bool
    {
        $token = self::cfg('NOTIFY_TELEGRAM_BOT_TOKEN');
        $chat  = self::cfg('NOTIFY_TELEGRAM_CHAT_ID');
        if ($token === '' || $chat === '') {
            return false;
        }

        $url = "https://api.telegram.org/bot{$token}/sendMessage";
        $payload = http_build_query([
            'chat_id'                  => $chat,
            'text'                     => $text,
            'parse_mode'               => 'HTML',
            'disable_web_page_preview' => true,
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => 5,
        ]);
        $res  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($res === false || $code !== 200) {
            error_log("[SiteNotifier] Telegram send failed (http {$code})");
            return false;
        }
        return true;
    }

    // ---------------------------------------------------------------------
    // 1) New-IP alert on the landing page
    // ---------------------------------------------------------------------

    /**
     * Record the visit; if the IP has never been seen, fire a Telegram alert.
     * Designed to be called AFTER the page is flushed to the client
     * (fastcgi_finish_request) so it never delays TTFB.
     */
    public static function trackLandingVisit(): void
    {
        $ip = self::clientIp();
        if ($ip === '') {
            return;
        }

        try {
            $db = Database::platform()->getConnection();
            self::ensureTable($db);

            $stmt = $db->prepare('SELECT id FROM site_visitor_ips WHERE ip = ? LIMIT 1');
            $stmt->execute([$ip]);
            if ($stmt->fetchColumn()) {
                // Seen before — bump last_seen + hit count, no alert.
                $db->prepare('UPDATE site_visitor_ips SET last_seen_at = NOW(), hits = hits + 1 WHERE ip = ?')
                   ->execute([$ip]);
                return;
            }

            $ua  = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
            $ref = substr((string) ($_SERVER['HTTP_REFERER'] ?? ''), 0, 255);
            $cc  = substr((string) ($_SERVER['HTTP_CF_IPCOUNTRY'] ?? ''), 0, 8);

            $p   = self::parseUa($ua);            // device / os / browser / bot
            $geo = self::geoLookup($ip);          // city / lat / lon / isp / flags

            $country = (string) ($geo['country'] ?? '');
            $ccode   = (string) ($geo['countryCode'] ?? $cc);
            $city    = (string) ($geo['city'] ?? '');
            $region  = (string) ($geo['regionName'] ?? '');
            $place   = trim($city . ($region ? ($city ? ', ' : '') . $region : ''));
            $lat = isset($geo['lat']) ? (float) $geo['lat'] : null;
            $lon = isset($geo['lon']) ? (float) $geo['lon'] : null;
            $isp = (string) ($geo['isp'] ?? '');
            $asn = (string) ($geo['as'] ?? '');
            $isProxy   = !empty($geo['proxy']);
            $isHosting = !empty($geo['hosting']);
            $isMobile  = !empty($geo['mobile']);

            // base row (always saved)
            $db->prepare(
                'INSERT INTO site_visitor_ips (ip, user_agent, referer, country, hits, created_at, last_seen_at)
                 VALUES (?, ?, ?, ?, 1, NOW(), NOW())'
            )->execute([$ip, $ua, $ref, $ccode]);
            // enrich (best-effort — columns come from the *_geo migration)
            try {
                $db->prepare(
                    'UPDATE site_visitor_ips SET city=?, region=?, lat=?, lon=?, isp=?, asn=?,
                        is_bot=?, bot_name=?, device=?, os=?, browser=?, is_proxy=?, is_hosting=?, is_mobile=?
                     WHERE ip=?'
                )->execute([
                    $city ?: null, $region ?: null, $lat, $lon, $isp ?: null, $asn ?: null,
                    $p['is_bot'] ? 1 : 0, $p['bot_name'] ?: null, $p['device'], $p['os'], $p['browser'],
                    $isProxy ? 1 : 0, $isHosting ? 1 : 0, $isMobile ? 1 : 0, $ip,
                ]);
            } catch (\Throwable $e) { /* geo columns not migrated yet */ }

            $e = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

            // --- classify the visit into ONE category (priority order) ------
            // Social/SEO crawlers (Facebook link-preview, Ahrefs, etc.) often
            // aren't flagged is_bot by UA nor is_hosting by IP — catch them by ISP.
            $isTH       = strtoupper($ccode) === 'TH';
            $crawlerIsp = $isp !== '' && preg_match('/facebook|bytedance|tiktok|bytespider|ahrefs|semrush|censys|shodan|datacamp|telegram messenger/i', $isp);
            if ($p['is_bot'] || $crawlerIsp) {
                $cat  = 'bot';
                $bn   = $p['bot_name'] ?: ($crawlerIsp ? trim((string) $isp) : '');
                $banner = '🤖 บอท / Crawler' . ($bn ? ' · ' . $e($bn) : '');
            } elseif ($isHosting) {
                $cat = 'host';    $banner = '☁️ Datacenter / เซิร์ฟเวอร์';
            } elseif ($isProxy) {
                $cat = 'vpn';     $banner = '🛡️ ผู้เข้าชมผ่าน VPN / Proxy';
            } elseif ($isTH) {
                $cat = 'th';      $banner = '🇹🇭 ลูกค้าไทย · ผู้เข้าชมใหม่';
            } else {
                $cat = 'foreign'; $banner = '🌍 ผู้เข้าชมต่างชาติ';
            }

            // --- noise filter: skip Telegram for machine traffic ------------
            // (bots + datacenters are still logged to the DB, just not pinged)
            $skipBots = self::cfg('NOTIFY_VISITOR_SKIP_BOTS', '1') !== '0';
            $skipHost = self::cfg('NOTIFY_VISITOR_SKIP_HOSTING', '1') !== '0';
            if (($cat === 'bot' && $skipBots) || ($cat === 'host' && $skipHost)) {
                return;
            }

            $msg = "<b>{$banner}</b>\n"
                 . "──────────\n"
                 . "🏳️ " . $e($country ?: '-') . ($ccode ? " ({$e($ccode)})" : '') . ($place ? " · {$e($place)}" : '') . "\n"
                 . "📱 {$e($p['device'])} · {$e($p['os'])} · {$e($p['browser'])}\n"
                 . ($isp ? "🛰 {$e($isp)}" . ($asn ? " · {$e($asn)}" : '') . "\n" : '')
                 . "🌐 <code>{$e($ip)}</code>\n"
                 . ($ref ? "↩️ จาก: {$e($ref)}\n" : '')
                 . (($lat !== null && $lon !== null) ? "🗺 https://maps.google.com/?q={$e($lat)},{$e($lon)}\n" : '')
                 . "🕐 " . date('H:i') . " น.";
            self::sendTelegram($msg);
        } catch (\Throwable $ex) {
            error_log('[SiteNotifier] trackLandingVisit: ' . $ex->getMessage());
        }
    }

    /**
     * Classify a User-Agent into device / os / browser and detect bots/crawlers.
     * @return array{device:string,os:string,browser:string,is_bot:bool,bot_name:string}
     */
    private static function parseUa(string $ua): array
    {
        $u = strtolower($ua);

        $bots = [
            '360Spider' => '360spider', 'Googlebot' => 'googlebot', 'Bingbot' => 'bingbot',
            'YandexBot' => 'yandex', 'Baidu' => 'baiduspider', 'AhrefsBot' => 'ahrefsbot',
            'SemrushBot' => 'semrushbot', 'MJ12bot' => 'mj12bot', 'DotBot' => 'dotbot',
            'PetalBot' => 'petalbot', 'ByteSpider' => 'bytespider', 'GPTBot' => 'gptbot',
            'ClaudeBot' => 'claudebot', 'Facebook' => 'facebookexternalhit', 'Twitterbot' => 'twitterbot',
            'Yahoo Slurp' => 'slurp', 'DuckDuckGo' => 'duckduckbot', 'Applebot' => 'applebot',
            'Headless' => 'headlesschrome', 'curl' => 'curl', 'wget' => 'wget', 'Python' => 'python-requests',
        ];
        $isBot = false; $botName = '';
        foreach ($bots as $name => $sig) {
            if (strpos($u, $sig) !== false) { $isBot = true; $botName = $name; break; }
        }
        if (!$isBot && (strpos($u, 'bot') !== false || strpos($u, 'spider') !== false || strpos($u, 'crawl') !== false)) {
            $isBot = true; $botName = 'Unknown bot';
        }
        if ($ua === '') { $isBot = true; $botName = 'No User-Agent'; }

        $os = 'ไม่ทราบ';
        if (preg_match('/android[ \/]?([\d.]+)?/i', $ua, $m))      { $os = 'Android ' . ($m[1] ?? ''); }
        elseif (preg_match('/iphone os ([\d_]+)/i', $ua, $m))      { $os = 'iOS ' . str_replace('_', '.', $m[1]); }
        elseif (preg_match('/ipad.*os ([\d_]+)/i', $ua, $m))       { $os = 'iPadOS ' . str_replace('_', '.', $m[1]); }
        elseif (stripos($ua, 'windows nt 10') !== false)           { $os = 'Windows 10/11'; }
        elseif (stripos($ua, 'windows') !== false)                 { $os = 'Windows'; }
        elseif (stripos($ua, 'mac os x') !== false)                { $os = 'macOS'; }
        elseif (stripos($ua, 'cros') !== false)                    { $os = 'ChromeOS'; }
        elseif (stripos($ua, 'linux') !== false)                   { $os = 'Linux'; }

        $br = 'ไม่ทราบ';
        if (preg_match('/line\//i', $ua))                           { $br = 'LINE App'; }
        elseif (preg_match('/edg\/([\d.]+)/i', $ua, $m))            { $br = 'Edge ' . explode('.', $m[1])[0]; }
        elseif (preg_match('/(chrome|crios)\/([\d.]+)/i', $ua, $m)) { $br = 'Chrome ' . explode('.', $m[2])[0]; }
        elseif (preg_match('/firefox\/([\d.]+)/i', $ua, $m))        { $br = 'Firefox ' . explode('.', $m[1])[0]; }
        elseif (preg_match('/version\/([\d.]+).*safari/i', $ua, $m)){ $br = 'Safari ' . explode('.', $m[1])[0]; }

        $device = (stripos($ua, 'mobile') !== false || stripos($ua, 'iphone') !== false || stripos($ua, 'android') !== false)
            ? 'มือถือ'
            : ((stripos($ua, 'ipad') !== false || stripos($ua, 'tablet') !== false) ? 'แท็บเล็ต' : 'คอมพิวเตอร์');

        return ['device' => $device, 'os' => trim($os), 'browser' => $br, 'is_bot' => $isBot, 'bot_name' => $botName];
    }

    /**
     * Free IP geolocation via ip-api.com (no key, ~45 req/min). Runs only for
     * brand-new IPs and after the page is flushed, so latency is irrelevant.
     * @return array<string,mixed> empty on failure.
     */
    private static function geoLookup(string $ip): array
    {
        try {
            $url = 'http://ip-api.com/json/' . urlencode($ip)
                 . '?fields=status,country,countryCode,regionName,city,lat,lon,isp,as,mobile,proxy,hosting,query';
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT        => 4,
            ]);
            $res = curl_exec($ch);
            curl_close($ch);
            $j = json_decode((string) $res, true);
            if (is_array($j) && ($j['status'] ?? '') === 'success') {
                return $j;
            }
        } catch (\Throwable $e) {
            error_log('[SiteNotifier] geoLookup: ' . $e->getMessage());
        }
        return [];
    }

    /** Defensive auto-create (platform DB). Migration is the canonical source. */
    private static function ensureTable(\PDO $db): void
    {
        $db->exec(
            'CREATE TABLE IF NOT EXISTS site_visitor_ips (
                id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                ip           VARCHAR(45)  NOT NULL,
                user_agent   VARCHAR(255) NULL,
                referer      VARCHAR(255) NULL,
                country      VARCHAR(8)   NULL,
                hits         INT UNSIGNED NOT NULL DEFAULT 1,
                created_at   DATETIME     NOT NULL,
                last_seen_at DATETIME     NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_ip (ip),
                KEY idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT="Unique public-landing visitor IPs for new-visitor alerts"'
        );
    }

    // ---------------------------------------------------------------------
    // 2) New-signup alert
    // ---------------------------------------------------------------------

    /**
     * Notify the platform owner that a new shop just self-registered.
     * $d keys: shop_name, subdomain, tenant_id, email, name, phone
     */
    public static function notifySignup(array $d): void
    {
        $base   = defined('REYA_BASE_DOMAIN') ? REYA_BASE_DOMAIN : 're-ya.com';
        $shop   = (string) ($d['shop_name'] ?? '');
        $slug   = (string) ($d['subdomain'] ?? '');
        $tid    = (string) ($d['tenant_id'] ?? '');
        $email  = (string) ($d['email'] ?? '');
        $name   = (string) ($d['name'] ?? '');
        $phone  = (string) ($d['phone'] ?? '');
        $shopUrl = $slug !== '' ? "https://{$slug}.{$base}/" : '';

        // --- Email (primary) -------------------------------------------------
        $to = self::cfg('NOTIFY_SIGNUP_EMAIL');
        if ($to !== '') {
            try {
                require_once __DIR__ . '/EmailService.php';
                $db = null;
                try {
                    $db = Database::platform()->getConnection();
                } catch (\Throwable $e) {
                    // EmailService works without a DB (PHP mail() fallback)
                }
                $mailer  = new EmailService($db);
                // Deliverability: send from a real hosted-domain address so the
                // cPanel MTA can DKIM/SPF-sign it (avoids Gmail spam/drop).
                $fromEmail = self::cfg('NOTIFY_FROM_EMAIL', 'noreply@re-ya.com');
                $fromName  = self::cfg('NOTIFY_FROM_NAME', 'REYA Platform');
                $mailer->setFrom($fromEmail, $fromName);
                $subject = 'REYA แจ้งเตือน · ร้านสมัครใหม่: ' . ($shop !== '' ? $shop : $slug);
                $htmlBody = self::signupEmailBody([
                    'shop' => $shop, 'slug' => $slug, 'tid' => $tid, 'email' => $email,
                    'name' => $name, 'phone' => $phone, 'url' => $shopUrl,
                ]);
                // NOTIFY_SIGNUP_EMAIL may be a comma-separated list — send to each
                // individually so it works on both SMTP and PHP mail().
                foreach (array_filter(array_map('trim', explode(',', $to))) as $rcpt) {
                    $mailer->send($rcpt, $subject, $htmlBody, true);
                }
            } catch (\Throwable $e) {
                error_log('[SiteNotifier] notifySignup email: ' . $e->getMessage());
            }
        }

        // --- Telegram (bonus, instant) --------------------------------------
        try {
            $e   = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
            $msg = "🎉 <b>มีร้านสมัครใหม่!</b>\n"
                 . "🏪 {$e($shop)}\n"
                 . "🔗 <code>{$e($slug)}.{$e($base)}</code>\n"
                 . ($name  ? "👤 {$e($name)}\n"  : '')
                 . ($email ? "📧 {$e($email)}\n" : '')
                 . ($phone ? "📞 {$e($phone)}\n" : '')
                 . "🆔 tenant #{$e($tid)} · รออนุมัติ\n"
                 . "✅ อนุมัติ: https://{$base}/admin/tenant-approvals.php";
            self::sendTelegram($msg);
        } catch (\Throwable $e) {
            error_log('[SiteNotifier] notifySignup telegram: ' . $e->getMessage());
        }
    }

    private static function signupEmailBody(array $d): string
    {
        $e   = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $row = static function (string $label, string $val) use ($e): string {
            if ($val === '') {
                return '';
            }
            return "<tr><td style='padding:6px 12px;color:#6b7280;font-size:13px;'>{$e($label)}</td>"
                 . "<td style='padding:6px 12px;color:#111827;font-size:13px;font-weight:600;'>{$e($val)}</td></tr>";
        };

        $approveUrl = 'https://' . (defined('REYA_BASE_DOMAIN') ? REYA_BASE_DOMAIN : 're-ya.com')
                    . '/admin/tenant-approvals.php';

        return "<!DOCTYPE html><html><head><meta charset='UTF-8'></head>
<body style='font-family:Arial,Helvetica,sans-serif;background:#f3f4f6;padding:24px;'>
  <div style='max-width:520px;margin:0 auto;background:#fff;border-radius:14px;overflow:hidden;box-shadow:0 6px 18px rgba(0,0,0,.08);'>
    <div style='background:#059669;padding:22px 24px;'>
      <div style='color:rgba(255,255,255,.85);font-size:12px;letter-spacing:1px;font-weight:600;'>REYA PLATFORM</div>
      <h2 style='color:#fff;margin:4px 0 0;font-size:18px;'>มีร้านสมัครใหม่</h2>
    </div>
    <table style='width:100%;border-collapse:collapse;margin:16px 0;'>
      " . $row('ชื่อร้าน', (string) $d['shop'])
        . $row('เว็บ', ((string) $d['slug'] !== '' ? $d['slug'] . '.' . (defined('REYA_BASE_DOMAIN') ? REYA_BASE_DOMAIN : 're-ya.com') : ''))
        . $row('เจ้าของ', (string) $d['name'])
        . $row('อีเมล', (string) $d['email'])
        . $row('เบอร์โทร', (string) $d['phone'])
        . $row('Tenant ID', (string) $d['tid']) . "
    </table>
    <div style='padding:0 24px 24px;text-align:center;'>
      <a href='{$e($approveUrl)}' style='display:inline-block;background:#059669;color:#fff;text-decoration:none;padding:11px 22px;border-radius:10px;font-weight:600;font-size:14px;'>ตรวจสอบ &amp; อนุมัติร้าน</a>
      <p style='color:#9ca3af;font-size:12px;margin-top:14px;'>📅 " . date('Y-m-d H:i:s') . " · ร้านอยู่ในสถานะรออนุมัติ (pending_setup)</p>
    </div>
  </div>
</body></html>";
    }

    // ---------------------------------------------------------------------
    // 3) Approve / reject decision — notify the applicant AND the platform owners
    // ---------------------------------------------------------------------

    /**
     * @param string $decision 'approved' | 'rejected'
     * @param array  $t        tenant row: id, slug, display_name, owner_name, owner_email
     * @param string $reason   optional reason / note (shown to applicant + admins)
     * @param string $decidedBy platform user name who made the decision
     */
    public static function notifyTenantDecision(string $decision, array $t, string $reason, string $decidedBy): void
    {
        $base       = defined('REYA_BASE_DOMAIN') ? REYA_BASE_DOMAIN : 're-ya.com';
        $approved   = $decision === 'approved';
        $shop       = (string) ($t['display_name'] ?? $t['slug'] ?? '');
        $slug       = (string) ($t['slug'] ?? '');
        $ownerEmail = (string) ($t['owner_email'] ?? '');
        $ownerName  = (string) ($t['owner_name'] ?? '');
        $tid        = (string) ($t['id'] ?? '');
        $shopUrl    = $slug !== '' ? "https://{$slug}.{$base}/" : '';
        $when       = date('Y-m-d H:i:s');

        $db = null;
        try {
            $db = Database::platform()->getConnection();
        } catch (\Throwable $e) {
            // EmailService works without a DB (uses email_settings via DB, else mail())
        }
        $fromEmail = self::cfg('NOTIFY_FROM_EMAIL', 'noreply@re-ya.com');
        $fromName  = self::cfg('NOTIFY_FROM_NAME', 'REYA Platform');

        // (a) Email the applicant / shop owner ------------------------------
        if ($ownerEmail !== '' && filter_var($ownerEmail, FILTER_VALIDATE_EMAIL)) {
            try {
                require_once __DIR__ . '/EmailService.php';
                $mailer = new EmailService($db);
                $mailer->setFrom($fromEmail, $fromName);
                $subject = $approved
                    ? ('ร้านของคุณได้รับการอนุมัติแล้ว · ' . $shop)
                    : ('อัปเดตการสมัครเปิดร้าน · ' . $shop);
                $mailer->send($ownerEmail, $subject, self::decisionOwnerBody($approved, $shop, $shopUrl, $reason, $ownerName), true);
            } catch (\Throwable $e) {
                error_log('[SiteNotifier] decision owner mail: ' . $e->getMessage());
            }
        }

        // (b) Email the platform owners (audit notice — who/when/why) --------
        $admins = self::cfg('NOTIFY_SIGNUP_EMAIL');
        if ($admins !== '') {
            try {
                require_once __DIR__ . '/EmailService.php';
                $mailer = new EmailService($db);
                $mailer->setFrom($fromEmail, $fromName);
                $subject = 'ร้าน ' . $shop . ' ถูก' . ($approved ? 'อนุมัติ' : 'ปฏิเสธ') . ' โดย ' . $decidedBy;
                $body    = self::decisionAdminBody($approved, $shop, $slug, $base, $ownerName, $ownerEmail, $tid, $reason, $decidedBy, $when);
                foreach (array_filter(array_map('trim', explode(',', $admins))) as $rcpt) {
                    $mailer->send($rcpt, $subject, $body, true);
                }
            } catch (\Throwable $e) {
                error_log('[SiteNotifier] decision admin mail: ' . $e->getMessage());
            }
        }

        // (c) Telegram to the owners (instant) ------------------------------
        try {
            $e    = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
            $icon = $approved ? '✅' : '🚫';
            $verb = $approved ? 'อนุมัติเปิดร้าน' : 'ปฏิเสธคำขอเปิดร้าน';
            $msg  = "{$icon} <b>{$verb}</b>\n"
                  . "🏪 {$e($shop)} (<code>{$e($slug)}.{$e($base)}</code>)\n"
                  . "👤 โดย {$e($decidedBy)} · {$when}\n"
                  . ($ownerEmail ? "📧 เจ้าของ: {$e($ownerName)} · {$e($ownerEmail)}\n" : '')
                  . ($reason !== '' ? "📝 เหตุผล: {$e($reason)}" : '');
            self::sendTelegram($msg);
        } catch (\Throwable $e) {
            error_log('[SiteNotifier] decision telegram: ' . $e->getMessage());
        }
    }

    /** Formal email body shown to the applicant when their shop is approved / rejected. */
    private static function decisionOwnerBody(bool $approved, string $shop, string $shopUrl, string $reason, string $ownerName = ''): string
    {
        $e        = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $lineUrl  = self::cfg('REYA_SUPPORT_LINE_URL', 'https://line.me/R/ti/p/%40reya');
        $lineId   = self::cfg('REYA_SUPPORT_LINE_ID', '@Reya');
        $phone    = self::cfg('REYA_SUPPORT_PHONE', '0989919556');
        $guideUrl = $shopUrl !== '' ? ($shopUrl . 'onboarding-assistant.php') : '';
        $lineGuideUrl = self::cfg('REYA_GUIDE_LINE_URL', 'https://re-ya.com/help/line-setup.html');
        $headerBg = $approved ? '#059669' : '#b45309';
        $title    = $approved ? 'ร้านของคุณได้รับการอนุมัติแล้ว' : 'แจ้งผลการพิจารณาคำขอเปิดร้าน';
        $greeting = $ownerName !== '' ? ('เรียน คุณ' . $e($ownerName)) : 'เรียน ท่านเจ้าของร้าน';

        // --- main body paragraph -------------------------------------------
        if ($approved) {
            $intro = "ทีมงาน REYA ขอแจ้งว่า ร้าน <b>{$e($shop)}</b> ของท่านได้รับการอนุมัติและเปิดใช้งานระบบเรียบร้อยแล้ว "
                   . "ท่านสามารถเริ่มเข้าใช้งานระบบได้ทันทีตามลิงก์ด้านล่าง";
        } else {
            $intro = "ทีมงาน REYA ขอขอบคุณสำหรับความสนใจสมัครเปิดร้าน <b>{$e($shop)}</b> "
                   . "อย่างไรก็ตาม คำขอของท่านยังไม่ผ่านการพิจารณาในขณะนี้ รายละเอียดเพิ่มเติมตามด้านล่าง";
        }

        $reasonBlock = $reason !== ''
            ? "<div style='margin:18px 0;padding:13px 15px;background:#f8fafc;border-left:3px solid {$headerBg};border-radius:6px;color:#334155;font-size:14px;'>"
              . "<b style='color:#0f172a;'>หมายเหตุจากทีมงาน</b><br>{$e($reason)}</div>"
            : '';

        // --- access + guide (approved only) --------------------------------
        $accessBlock = '';
        if ($approved && $shopUrl !== '') {
            $accessBlock = "
    <div style='padding:4px 24px 0;'>
      <p style='color:#64748b;font-size:13px;margin:0 0 6px;'>เริ่มเข้าใช้งานระบบได้ที่</p>
      <a href='{$e($shopUrl)}' style='display:inline-block;background:#059669;color:#fff;text-decoration:none;padding:12px 26px;border-radius:10px;font-weight:700;font-size:15px;'>เข้าใช้งานระบบ &rarr;</a>
      <p style='margin:10px 0 0;'><a href='{$e($shopUrl)}' style='color:#059669;font-size:13px;word-break:break-all;'>{$e($shopUrl)}</a></p>
    </div>";
            if ($guideUrl !== '') {
                $accessBlock .= "
    <div style='padding:16px 24px 4px;'>
      <p style='margin:0;color:#334155;font-size:14px;'>🤖 <b>ผู้ช่วยตั้งค่าระบบอัตโนมัติ (AI)</b></p>
      <p style='margin:4px 0 0;color:#64748b;font-size:13px;line-height:1.6;'>มีผู้ช่วย AI แนะนำการตั้งค่าและใช้งานระบบทีละขั้นตอน พร้อมเช็กลิสต์ความคืบหน้า</p>
      <p style='margin:6px 0 0;'><a href='{$e($guideUrl)}' style='color:#2563eb;font-size:14px;'>เริ่มตั้งค่ากับผู้ช่วย AI &rarr;</a></p>
    </div>";
            }
            if ($lineGuideUrl !== '') {
                $accessBlock .= "
    <div style='padding:10px 24px 4px;'>
      <p style='margin:0;color:#334155;font-size:14px;'>📱 <b>คู่มือเชื่อมต่อ LINE OA</b></p>
      <p style='margin:4px 0 0;'><a href='{$e($lineGuideUrl)}' style='color:#06c755;font-size:14px;'>เปิดคู่มือเชื่อมต่อ LINE (เปิดดูได้ทันที) &rarr;</a></p>
    </div>";
            }
        }

        // --- support block (always) ----------------------------------------
        $support = "
    <div style='margin:20px 24px 0;padding:16px 18px;background:#ecfdf5;border:1px solid #d1fae5;border-radius:12px;'>
      <p style='margin:0 0 8px;color:#065f46;font-size:14px;font-weight:700;'>ต้องการให้ทีมงานช่วยตั้งค่าระบบเบื้องต้น?</p>
      <p style='margin:0 0 12px;color:#047857;font-size:13px;line-height:1.6;'>ทีมซัพพอต REYA ยินดีช่วยเหลือท่านในการเริ่มต้นใช้งานระบบ ติดต่อได้ที่</p>
      <p style='margin:0;font-size:14px;'>
        <a href='{$e($lineUrl)}' style='display:inline-block;background:#06c755;color:#fff;text-decoration:none;padding:8px 16px;border-radius:8px;font-weight:600;'>LINE: {$e($lineId)}</a>
        <span style='display:inline-block;margin-left:8px;color:#065f46;font-weight:600;'>หรือโทร {$e($phone)}</span>
      </p>
    </div>";

        return "<!DOCTYPE html><html><head><meta charset='UTF-8'></head>
<body style='font-family:Arial,Helvetica,sans-serif;background:#f3f4f6;padding:24px;'>
  <div style='max-width:540px;margin:0 auto;background:#fff;border-radius:14px;overflow:hidden;box-shadow:0 6px 18px rgba(0,0,0,.08);'>
    <div style='background:{$headerBg};padding:22px 24px;'>
      <div style='color:rgba(255,255,255,.85);font-size:12px;letter-spacing:1px;font-weight:600;'>REYA PHARMACY CRM</div>
      <h2 style='color:#fff;margin:4px 0 0;font-size:19px;'>{$e($title)}</h2>
    </div>
    <div style='padding:22px 24px 4px;color:#334155;font-size:14px;line-height:1.75;'>
      <p style='margin:0 0 12px;font-weight:600;color:#0f172a;'>{$greeting}</p>
      <p style='margin:0;'>{$intro}</p>
      {$reasonBlock}
    </div>
    {$accessBlock}
    {$support}
    <div style='padding:18px 24px 22px;'>
      <p style='margin:0;color:#94a3b8;font-size:12px;line-height:1.6;'>ขอแสดงความนับถือ<br>ทีมงาน REYA Pharmacy CRM · re-ya.com</p>
    </div>
  </div>
</body></html>";
    }

    /** Email body for the platform owners — audit record of who decided what, when, why. */
    private static function decisionAdminBody(
        bool $approved, string $shop, string $slug, string $base,
        string $ownerName, string $ownerEmail, string $tid, string $reason,
        string $decidedBy, string $when
    ): string {
        $e    = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $hbg  = $approved ? '#059669' : '#dc2626';
        $verb = $approved ? 'อนุมัติ' : 'ปฏิเสธ';
        $row  = static function (string $label, string $val) use ($e): string {
            if ($val === '') {
                return '';
            }
            return "<tr><td style='padding:6px 12px;color:#6b7280;font-size:13px;'>{$e($label)}</td>"
                 . "<td style='padding:6px 12px;color:#111827;font-size:13px;font-weight:600;'>{$e($val)}</td></tr>";
        };

        return "<!DOCTYPE html><html><head><meta charset='UTF-8'></head>
<body style='font-family:Arial,Helvetica,sans-serif;background:#f3f4f6;padding:24px;'>
  <div style='max-width:520px;margin:0 auto;background:#fff;border-radius:14px;overflow:hidden;box-shadow:0 6px 18px rgba(0,0,0,.08);'>
    <div style='background:{$hbg};padding:22px 24px;'>
      <div style='color:rgba(255,255,255,.85);font-size:12px;letter-spacing:1px;font-weight:600;'>REYA PLATFORM · บันทึกการตัดสินใจ</div>
      <h2 style='color:#fff;margin:4px 0 0;font-size:18px;'>ร้านถูก{$e($verb)}</h2>
    </div>
    <table style='width:100%;border-collapse:collapse;margin:16px 0;'>
      " . $row('ร้าน', $shop)
        . $row('เว็บ', $slug !== '' ? $slug . '.' . $base : '')
        . $row('Tenant ID', $tid)
        . $row('เจ้าของ', $ownerName)
        . $row('อีเมลเจ้าของ', $ownerEmail)
        . $row('ดำเนินการโดย', $decidedBy)
        . $row('เวลา', $when)
        . $row('เหตุผล', $reason) . "
    </table>
  </div>
</body></html>";
    }
}
