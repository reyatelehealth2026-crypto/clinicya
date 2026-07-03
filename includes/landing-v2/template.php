<?php
/**
 * Landing V2 template — tenant storefront โฉมใหม่
 *
 * ถูก include จาก index.php เมื่อร้านเปิดใช้ v2 (มี landing_v2_published)
 * หรือแอดมินเปิด ?v2=draft (preview ร่าง)
 *
 * ตัวแปรที่ index.php เตรียมไว้แล้ว:
 *   $db, $lineAccountId, $lineAccount, $shopSettings, $shopName, $shopLogo,
 *   $shopDescription, $contactPhone, $shopAddress, $shopEmail, $lineId,
 *   $liffUrl, $seoService, $faqService, $featuredProductService, $bannerService
 * ตัวแปรเพิ่มจาก entry point:
 *   $lv2Config (array), $lv2IsDraftPreview (bool)
 */

if (!function_exists('lv2_h')) {
    function lv2_h($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
}

$lv2Config = isset($lv2Config) && is_array($lv2Config) ? $lv2Config : LandingV2Config::defaults();
$lv2IsDraftPreview = !empty($lv2IsDraftPreview);

$lv2Theme = $lv2Config['theme'] ?? 'mint';
$lv2Hero  = $lv2Config['hero'] ?? 'shop';
$lv2Show  = $lv2Config['show'] ?? [];

// ── landing settings (เวลาทำการ / แผนที่ / custom html) ──────────────
$lv2Settings = [];
try {
    // ORDER BY: แถว NULL มาก่อน แถวผูก OA มาทีหลัง — FETCH_KEY_PAIR ตัวหลังทับตัวแรก
    // ทำให้ค่าเฉพาะ OA ชนะค่ากลาง (พฤติกรรมเดียวกับหน้า v1)
    $stmt = $db->prepare(
        "SELECT setting_key, setting_value FROM landing_settings
         WHERE line_account_id = ? OR line_account_id IS NULL
         ORDER BY line_account_id IS NULL DESC"
    );
    $stmt->execute([$lineAccountId]);
    $lv2Settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Exception $e) {
    // ตารางอาจยังไม่มีในบาง tenant — render ต่อโดยซ่อน section ที่พึ่งข้อมูลนี้
}

// เวลาทำการ: {mon: "08:30-20:30"|"closed", ...}
$lv2Hours = null;
if (!empty($lv2Settings['operating_hours'])) {
    $decoded = json_decode($lv2Settings['operating_hours'], true);
    if (is_array($decoded)) { $lv2Hours = $decoded; }
}
$lv2DayNames = [
    'mon' => 'จันทร์', 'tue' => 'อังคาร', 'wed' => 'พุธ', 'thu' => 'พฤหัสบดี',
    'fri' => 'ศุกร์', 'sat' => 'เสาร์', 'sun' => 'อาทิตย์',
];
$lv2TodayKey = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'][(int) date('N') - 1];

// สถานะเปิด/ปิดตอนนี้ (คำนวณจากช่วงเวลาของวันนี้ ถ้า parse ไม่ได้ = ไม่โชว์ป้าย)
$lv2OpenNow = null;      // true|false|null(ไม่ทราบ)
$lv2TodayLabel = '';
if ($lv2Hours !== null && isset($lv2Hours[$lv2TodayKey])) {
    $todayRange = $lv2Hours[$lv2TodayKey];
    if ($todayRange === 'closed' || $todayRange === '' || $todayRange === null) {
        $lv2OpenNow = false;
        $lv2TodayLabel = 'วันนี้ปิดทำการ';
    } elseif (preg_match('/\A(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})\z/', trim((string) $todayRange), $m)) {
        $nowMin   = (int) date('G') * 60 + (int) date('i');
        $openMin  = (int) $m[1] * 60 + (int) $m[2];
        $closeMin = (int) $m[3] * 60 + (int) $m[4];
        $lv2OpenNow = ($nowMin >= $openMin && $nowMin < $closeMin);
        $lv2TodayLabel = $lv2OpenNow
            ? 'เปิดอยู่ตอนนี้ วันนี้ปิด ' . sprintf('%02d:%02d น.', (int) $m[3], (int) $m[4])
            : 'ตอนนี้ปิดอยู่ เปิด ' . sprintf('%02d:%02d น.', (int) $m[1], (int) $m[2]);
    }
}

// แผนที่: embed ที่ตั้งเองมาก่อน ตามด้วย lat/long
$lv2MapEmbed = trim((string) ($lv2Settings['google_map_embed'] ?? ''));
$lv2Lat = trim((string) ($lv2Settings['latitude'] ?? ''));
$lv2Lng = trim((string) ($lv2Settings['longitude'] ?? ''));
$lv2MapSrc = '';
if ($lv2MapEmbed !== '' && preg_match('#\Ahttps://((www|maps)\.)?google\.(com|co\.th)/maps#i', $lv2MapEmbed)) {
    $lv2MapSrc = $lv2MapEmbed;
} elseif ($lv2Lat !== '' && $lv2Lng !== '' && is_numeric($lv2Lat) && is_numeric($lv2Lng)) {
    $lv2MapSrc = 'https://maps.google.com/maps?q=' . rawurlencode($lv2Lat . ',' . $lv2Lng) . '&z=16&output=embed';
}
$lv2MapLink = ($lv2Lat !== '' && $lv2Lng !== '')
    ? 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($lv2Lat . ',' . $lv2Lng)
    : ($shopAddress ? 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($shopAddress) : '');

// ── รูปหน้าร้าน (bucket shop_photos ต่อ tenant) ───────────────────────
// TenantFileStorage ไม่ได้อยู่ใน composer autoload (คลาส legacy ไม่มี namespace)
// ต้อง require เองไม่งั้นรูปไม่โชว์บนหน้า public
require_once __DIR__ . '/../../classes/TenantFileStorage.php';
$lv2Photos = [];
if (class_exists('TenantContext') && class_exists('TenantFileStorage')) {
    try {
        $lv2TenantId = TenantContext::getCurrentTenantId();
        if ($lv2TenantId) {
            foreach (LandingV2Config::PHOTO_SLOTS as $slot => $slotLabel) {
                $filename = $lv2Config['photos'][$slot] ?? '';
                if ($filename !== '') {
                    $lv2Photos[] = [
                        'url'   => TenantFileStorage::url((int) $lv2TenantId, 'shop_photos', $filename),
                        'label' => $slotLabel,
                    ];
                }
            }
        }
    } catch (Exception $e) {
        $lv2Photos = [];
    }
}

// ── LINE CTA ──────────────────────────────────────────────────────────
require_once __DIR__ . '/../liff-helper.php';
$lv2LineUrl = '';
try {
    $lv2LineUrl = (string) reya_liff_url_or_oa($db, $lineAccountId);
} catch (Exception $e) {
    $lv2LineUrl = '';
}
if ($lv2LineUrl === '#' ) { $lv2LineUrl = ''; }
$lv2BasicId = trim((string) ($lineAccount['basic_id'] ?? ''));
$lv2QrUrl = $lv2BasicId !== ''
    ? 'https://qr-official.line.me/gs/M_' . rawurlencode(ltrim($lv2BasicId, '@')) . '_GW.png'
    : '';
$lv2Tel = preg_replace('/[^0-9+]/', '', (string) $contactPhone);

// ── เนื้อหา section (ซ่อนเมื่อว่างตามนโยบาย) ─────────────────────────
$lv2Banners = [];
if (!isset($lv2Show['banners']) || $lv2Show['banners']) {
    // $bannerService ถูกสร้างไว้แล้วใน index.php ก่อน include ไฟล์นี้
    try { $lv2Banners = $bannerService->getActiveBanners(8); } catch (Exception $e) {}
}
$lv2Products = [];
if (!isset($lv2Show['products']) || $lv2Show['products']) {
    try { $lv2Products = $featuredProductService->getFeaturedProducts(4); } catch (Exception $e) {}
}
$lv2Faqs = [];
if (!isset($lv2Show['faq']) || $lv2Show['faq']) {
    try { $lv2Faqs = $faqService->getActiveFAQs(6); } catch (Exception $e) {}
}
$lv2Articles = [];
if (!isset($lv2Show['articles']) || $lv2Show['articles']) {
    $articleServicePath = __DIR__ . '/../../classes/HealthArticleService.php';
    if (file_exists($articleServicePath)) {
        require_once $articleServicePath;
        try {
            $lv2ArticleService = new HealthArticleService($db, $lineAccountId);
            $lv2Articles = $lv2ArticleService->getPublishedArticles(3);
        } catch (Exception $e) {}
    }
}
$lv2CustomHtml = '';
if (!isset($lv2Show['custom_html']) || $lv2Show['custom_html']) {
    $lv2CustomHtml = (string) ($lv2Settings['custom_html'] ?? '');
}
$lv2ShowServices = !isset($lv2Show['services']) || $lv2Show['services'];

// hero เน้นสินค้า ต้องมีสินค้าอย่างน้อย 1 ชิ้น ไม่งั้น fallback เป็นเน้นหน้าร้าน
if ($lv2Hero === 'product' && empty($lv2Products)) {
    $lv2Hero = 'shop';
}

$lv2Headline = trim((string) ($lv2Config['headline'] ?? ''));
if ($lv2Headline === '') {
    $lv2Headline = 'ร้านยาใกล้บ้านคุณ ปรึกษาเภสัชกรได้ทุกวัน';
}
$lv2Tagline = trim((string) ($lv2Config['tagline'] ?? ''));
if ($lv2Tagline === '') {
    $lv2Tagline = (string) $shopDescription;
}

// บริการมาตรฐาน v1 (แก้ไขรายร้านเป็นงานเฟส hub)
$lv2Services = [
    ['t' => 'ปรึกษาเภสัชกรฟรี', 'd' => 'ทักถามอาการผ่าน LINE เภสัชกรประจำร้านตอบเองทุกข้อความ'],
    ['t' => 'สั่งซื้อผ่าน LINE', 'd' => 'เลือกสินค้า แจ้งสลิป และติดตามออเดอร์ได้ในแชทเดียว'],
    ['t' => 'เช็คยาก่อนมาร้าน', 'd' => 'ส่งรูปยาหรือชื่อยาเข้ามา เช็คให้ก่อนว่ามีของ ไม่ต้องมาเก้อ'],
    ['t' => 'สะสมแต้มรับส่วนลด', 'd' => 'ซื้อครบรับแต้มอัตโนมัติใน LINE แลกส่วนลดได้ทันที'],
];

$lv2CssVersion = @filemtime(__DIR__ . '/../../assets/css/landing-v2.css') ?: 1;
$lv2LineIconPath = 'M12 2C6.48 2 2 5.64 2 10.13c0 4.03 3.58 7.4 8.42 8.04.33.07.77.22.89.5.1.26.07.66.03.92l-.14.86c-.04.26-.2 1.01.89.55 1.09-.46 5.87-3.46 8.01-5.92C21.68 13.32 22 11.78 22 10.13 22 5.64 17.52 2 12 2z';

$lv2HasTrust = !empty($lv2Photos) || !empty($lv2Hours) || $lv2MapSrc !== '' || $lv2MapLink !== '';
?>
<!DOCTYPE html>
<html lang="th" data-theme="<?= lv2_h($lv2Theme) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <?php if ($lv2IsDraftPreview): ?><meta name="robots" content="noindex"><?php endif; ?>

    <?php include __DIR__ . '/../landing/seo-meta.php'; ?>
    <title><?= lv2_h($seoService->getPageTitle()) ?></title>
    <?php include __DIR__ . '/../landing/structured-data.php'; ?>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/landing-v2.css?v=<?= $lv2CssVersion ?>">
</head>
<body class="lv2">

<?php if ($lv2IsDraftPreview): ?>
<div class="v2-preview-banner">
    โหมดตัวอย่างร่าง (เห็นเฉพาะแอดมิน) ลูกค้ายังเห็นหน้าเดิมจนกว่าจะกดเผยแพร่
</div>
<?php endif; ?>

<header class="site-header">
    <div class="wrap header-in">
        <div class="logo-mark" aria-hidden="true">
            <?php if (!empty($shopLogo)): ?>
                <img src="<?= lv2_h($shopLogo) ?>" alt="">
            <?php else: ?>
                <?= lv2_h(mb_substr($shopName, 0, 1)) ?>
            <?php endif; ?>
        </div>
        <div class="shop-ident">
            <b><?= lv2_h($shopName) ?></b>
            <small>ร้านยามีเภสัชกรประจำ</small>
        </div>
        <nav class="header-nav" aria-label="เมนูหลัก">
            <?php if ($lv2HasTrust): ?><a href="#trust">ข้อมูลร้าน</a><?php endif; ?>
            <?php if ($lv2ShowServices): ?><a href="#services">บริการ</a><?php endif; ?>
            <?php if (!empty($lv2Products)): ?><a href="#products">สินค้าแนะนำ</a><?php endif; ?>
            <?php if (!empty($lv2Faqs)): ?><a href="#faq">คำถามที่พบบ่อย</a><?php endif; ?>
        </nav>
        <?php if ($lv2LineUrl !== ''): ?>
        <span class="header-cta">
            <a class="btn btn-line btn-sm" href="<?= lv2_h($lv2LineUrl) ?>">
                <svg class="line-icon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="<?= $lv2LineIconPath ?>"/></svg>
                เพิ่มเพื่อน LINE
            </a>
        </span>
        <?php endif; ?>
    </div>
</header>

<section class="hero">
    <div class="wrap hero-in<?= ($lv2Hero === 'shop' && empty($lv2Photos)) ? ' single' : '' ?>">
        <div>
            <?php if ($lv2TodayLabel !== ''): ?>
            <span class="status-pill">
                <span class="status-dot<?= $lv2OpenNow ? '' : ' closed' ?>"></span>
                <?= lv2_h($lv2TodayLabel) ?>
            </span>
            <?php endif; ?>
            <h1><?= lv2_h($lv2Headline) ?></h1>
            <?php if ($lv2Tagline !== ''): ?>
            <p class="lede"><?= lv2_h($lv2Tagline) ?></p>
            <?php endif; ?>
            <div class="hero-ctas">
                <?php if ($lv2LineUrl !== ''): ?>
                <a class="btn btn-line" href="<?= lv2_h($lv2LineUrl) ?>">
                    <svg class="line-icon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="<?= $lv2LineIconPath ?>"/></svg>
                    <?= $lv2Hero === 'product' ? 'สั่งซื้อผ่าน LINE' : 'เพิ่มเพื่อน LINE ร้าน' ?>
                </a>
                <?php endif; ?>
                <?php if ($lv2Tel !== ''): ?>
                <a class="btn btn-ghost" href="tel:<?= lv2_h($lv2Tel) ?>">โทร <?= lv2_h($contactPhone) ?></a>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($lv2Hero === 'product'): ?>
        <div class="hero-products">
            <?php foreach (array_slice($lv2Products, 0, 4) as $p): ?>
            <a class="hero-prod-card" href="<?= lv2_h($lv2LineUrl !== '' ? $lv2LineUrl : '#products') ?>">
                <?php if (!empty($p['image_url'])): ?><img src="<?= lv2_h($p['image_url']) ?>" alt="<?= lv2_h($p['name']) ?>" loading="lazy"><?php endif; ?>
                <b><?= lv2_h($p['name']) ?></b>
                <span class="price"><?= number_format((float) $p['price']) ?> บาท</span>
            </a>
            <?php endforeach; ?>
        </div>
        <?php elseif (!empty($lv2Photos)): ?>
        <figure class="hero-art">
            <img src="<?= lv2_h($lv2Photos[0]['url']) ?>" alt="<?= lv2_h($lv2Photos[0]['label'] . ' ' . $shopName) ?>">
        </figure>
        <?php endif; ?>
    </div>
</section>

<?php if (!empty($lv2Banners)): ?>
<div class="wrap banner-wrap">
    <div class="banner-slider" id="lv2Banner" data-autoplay="5000">
        <div class="banner-track">
            <?php foreach ($lv2Banners as $i => $banner): ?>
            <div class="banner-slide">
                <?php if (!empty($banner['link_url'])): ?>
                <a href="<?= lv2_h($banner['link_url']) ?>"
                   <?= ($banner['link_type'] ?? '') === 'external' ? 'target="_blank" rel="noopener"' : '' ?>>
                <?php endif; ?>
                    <img src="<?= lv2_h($banner['image_url']) ?>"
                         alt="<?= lv2_h($banner['title'] ?: ('โปรโมชัน ' . $shopName)) ?>"
                         loading="<?= $i === 0 ? 'eager' : 'lazy' ?>">
                <?php if (!empty($banner['link_url'])): ?>
                </a>
                <?php endif; ?>
                <?php if (!empty($banner['title'])): ?>
                <span class="banner-caption"><?= lv2_h($banner['title']) ?></span>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php if (count($lv2Banners) > 1): ?>
        <button type="button" class="banner-nav prev" aria-label="แบนเนอร์ก่อนหน้า">‹</button>
        <button type="button" class="banner-nav next" aria-label="แบนเนอร์ถัดไป">›</button>
        <div class="banner-dots" role="tablist" aria-label="เลือกแบนเนอร์">
            <?php foreach ($lv2Banners as $i => $banner): ?>
            <button type="button" class="banner-dot<?= $i === 0 ? ' active' : '' ?>"
                    aria-label="แบนเนอร์ที่ <?= $i + 1 ?>"></button>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($lv2HasTrust): ?>
<section class="section" id="trust">
    <div class="wrap">
        <div class="section-head">
            <span class="eyebrow">ร้านจริง เชื่อถือได้</span>
            <h2>มีหน้าร้านจริง แวะมาได้ทุกวัน</h2>
        </div>
        <div class="trust-grid">
            <?php if (!empty($lv2Photos)): ?>
            <div class="card card-pad">
                <h3>บรรยากาศร้าน</h3>
                <p class="sub">รูปถ่ายจริงจากหน้าร้าน</p>
                <div class="photo-strip <?= count($lv2Photos) === 1 ? 'one' : (count($lv2Photos) === 2 ? 'two' : '') ?>">
                    <?php foreach ($lv2Photos as $photo): ?>
                    <div class="photo">
                        <img src="<?= lv2_h($photo['url']) ?>" alt="<?= lv2_h($photo['label'] . ' ' . $shopName) ?>" loading="lazy">
                        <span><?= lv2_h($photo['label']) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            <div class="trust-col">
                <?php if (!empty($lv2Hours)): ?>
                <div class="card card-pad">
                    <h3>เวลาทำการ</h3>
                    <div>
                        <?php foreach ($lv2DayNames as $dayKey => $dayName):
                            if (!isset($lv2Hours[$dayKey])) { continue; }
                            $range = $lv2Hours[$dayKey];
                            $isClosed = ($range === 'closed' || $range === '' || $range === null);
                        ?>
                        <div class="hours-row<?= $dayKey === $lv2TodayKey ? ' today' : '' ?><?= $isClosed ? ' closed-day' : '' ?>">
                            <span><?= lv2_h($dayName) ?><?= $dayKey === $lv2TodayKey ? ' (วันนี้)' : '' ?></span>
                            <span class="t"><?= $isClosed ? 'ปิดทำการ' : lv2_h($range) ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                <?php if ($lv2MapSrc !== '' || $lv2MapLink !== '' || !empty($shopAddress)): ?>
                <div class="card card-pad">
                    <h3>ที่ตั้งร้าน</h3>
                    <?php if (!empty($shopAddress)): ?><p class="sub"><?= lv2_h($shopAddress) ?></p><?php endif; ?>
                    <?php if ($lv2MapSrc !== ''): ?>
                    <iframe class="map-frame" src="<?= lv2_h($lv2MapSrc) ?>" loading="lazy"
                            referrerpolicy="no-referrer-when-downgrade" title="แผนที่ <?= lv2_h($shopName) ?>"></iframe>
                    <?php endif; ?>
                    <div class="map-actions">
                        <?php if ($lv2MapLink !== ''): ?>
                        <a class="chip-link" href="<?= lv2_h($lv2MapLink) ?>" target="_blank" rel="noopener">เปิดเส้นทางใน Google Maps</a>
                        <?php endif; ?>
                        <?php if ($lv2Tel !== ''): ?>
                        <a class="chip-link" href="tel:<?= lv2_h($lv2Tel) ?>">โทรหาร้าน</a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>
<?php endif; ?>

<?php if ($lv2ShowServices): ?>
<section class="section" id="services" <?= $lv2HasTrust ? 'style="padding-top:0"' : '' ?>>
    <div class="wrap">
        <div class="section-head">
            <h2>มากกว่าร้านขายยา</h2>
        </div>
        <div class="svc-grid">
            <?php foreach ($lv2Services as $svc): ?>
            <div class="svc">
                <b><?= lv2_h($svc['t']) ?></b>
                <p><?= lv2_h($svc['d']) ?></p>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<?php if (!empty($lv2Products)): ?>
<section class="section" id="products" style="padding-top:0">
    <div class="wrap">
        <div class="section-head">
            <h2>สินค้าแนะนำ คัดโดยเภสัชกร</h2>
            <p class="section-sub">ราคาเดียวกับหน้าร้าน กดสั่งแล้วคุยกับร้านใน LINE ได้เลย</p>
        </div>
        <div class="prod-grid">
            <?php foreach ($lv2Products as $p): ?>
            <div class="prod">
                <?php if (!empty($p['image_url'])): ?>
                <img class="prod-img" src="<?= lv2_h($p['image_url']) ?>" alt="<?= lv2_h($p['name']) ?>" loading="lazy">
                <?php endif; ?>
                <div class="prod-body">
                    <b><?= lv2_h($p['name']) ?></b>
                    <div class="prod-foot">
                        <span class="price"><?= number_format((float) $p['price']) ?> บาท</span>
                        <?php if ($lv2LineUrl !== ''): ?>
                        <a class="btn btn-line btn-sm" href="<?= lv2_h($lv2LineUrl) ?>">สั่งผ่าน LINE</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<?php if (!empty($lv2Articles)): ?>
<section class="section" id="articles" style="padding-top:0">
    <div class="wrap">
        <div class="section-head">
            <h2>บทความสุขภาพ</h2>
        </div>
        <div class="art-grid">
            <?php foreach ($lv2Articles as $article): ?>
            <a class="art-card" href="article.php?slug=<?= lv2_h($article['slug']) ?>">
                <?php if (!empty($article['featured_image'])): ?>
                <img src="<?= lv2_h($article['featured_image']) ?>" alt="" loading="lazy">
                <?php endif; ?>
                <div class="art-body">
                    <b><?= lv2_h($article['title']) ?></b>
                    <small><?= lv2_h(mb_substr(strip_tags((string) ($article['excerpt'] ?? '')), 0, 90)) ?></small>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<?php if (!empty($lv2Faqs)): ?>
<section class="section" id="faq" style="padding-top:0">
    <div class="wrap">
        <div class="section-head">
            <h2>คำถามที่พบบ่อย</h2>
        </div>
        <div class="faq-list">
            <?php foreach ($lv2Faqs as $i => $faq): ?>
            <details<?= $i === 0 ? ' open' : '' ?>>
                <summary><?= lv2_h($faq['question']) ?></summary>
                <div class="a"><?= nl2br(lv2_h($faq['answer'])) ?></div>
            </details>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<?php if ($lv2CustomHtml !== ''): ?>
<section class="section landing-custom-html" style="padding-top:0">
    <div class="wrap"><?= $lv2CustomHtml /* admin-entered HTML — พฤติกรรมเดียวกับหน้า v1 */ ?></div>
</section>
<?php endif; ?>

<?php if ($lv2LineUrl !== ''): ?>
<section class="section" id="cta" style="padding-top:0">
    <div class="wrap">
        <div class="cta-final<?= $lv2QrUrl === '' ? ' no-qr' : '' ?>">
            <div>
                <h2>ทักหาเราได้เลยใน LINE</h2>
                <p>สอบถามอาการ เช็คสต๊อกยา สั่งซื้อ และสะสมแต้ม จบทุกอย่างใน LINE เดียว</p>
                <a class="btn btn-line" href="<?= lv2_h($lv2LineUrl) ?>">
                    <svg class="line-icon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="<?= $lv2LineIconPath ?>"/></svg>
                    เพิ่มเพื่อน<?= $lv2BasicId !== '' ? ' ' . lv2_h($lv2BasicId) : ' LINE' ?>
                </a>
            </div>
            <?php if ($lv2QrUrl !== ''): ?>
            <div class="qr-wrap">
                <img src="<?= lv2_h($lv2QrUrl) ?>" alt="QR เพิ่มเพื่อน LINE <?= lv2_h($shopName) ?>"
                     loading="lazy" onerror="this.closest('.qr-wrap').style.display='none'">
                <small>สแกนเพื่อเพิ่มเพื่อน</small>
            </div>
            <?php endif; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<footer>
    <div class="wrap">
        <div class="foot-grid">
            <div>
                <h4><?= lv2_h($shopName) ?></h4>
                <?php if (!empty($shopAddress)): ?><p><?= lv2_h($shopAddress) ?></p><?php endif; ?>
                <p>
                    <?php if (!empty($contactPhone)): ?>โทร <?= lv2_h($contactPhone) ?><?php endif; ?>
                    <?php if ($lv2BasicId !== ''): ?> LINE <?= lv2_h($lv2BasicId) ?><?php endif; ?>
                </p>
            </div>
            <?php if (!empty($lv2Hours)): ?>
            <div>
                <h4>เวลาทำการ</h4>
                <ul>
                    <?php foreach ($lv2DayNames as $dayKey => $dayName):
                        if (!isset($lv2Hours[$dayKey])) { continue; }
                        $range = $lv2Hours[$dayKey];
                        $isClosed = ($range === 'closed' || $range === '' || $range === null);
                    ?>
                    <li><?= lv2_h($dayName) ?> <?= $isClosed ? 'ปิดทำการ' : lv2_h($range) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
            <div>
                <h4>เมนูลัด</h4>
                <ul>
                    <?php if ($lv2ShowServices): ?><li><a href="#services">บริการ</a></li><?php endif; ?>
                    <?php if (!empty($lv2Products)): ?><li><a href="#products">สินค้าแนะนำ</a></li><?php endif; ?>
                    <?php if (!empty($lv2Faqs)): ?><li><a href="#faq">คำถามที่พบบ่อย</a></li><?php endif; ?>
                </ul>
            </div>
        </div>
        <div class="foot-credit">
            <span>© <?= (int) date('Y') + 543 ?> <?= lv2_h($shopName) ?></span>
            <span>ขับเคลื่อนโดย Re-ya ระบบร้านยาออนไลน์</span>
        </div>
    </div>
</footer>

<?php if ($lv2LineUrl !== ''): ?>
<a class="float-line" href="<?= lv2_h($lv2LineUrl) ?>" aria-label="เพิ่มเพื่อน LINE">
    <svg width="28" height="28" viewBox="0 0 24 24" fill="#fff" aria-hidden="true"><path d="<?= $lv2LineIconPath ?>"/></svg>
</a>
<div class="mobile-cta">
    <a class="btn btn-line" href="<?= lv2_h($lv2LineUrl) ?>">
        <svg class="line-icon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="<?= $lv2LineIconPath ?>"/></svg>
        เพิ่มเพื่อน LINE
    </a>
    <?php if ($lv2Tel !== ''): ?>
    <a class="btn btn-call" href="tel:<?= lv2_h($lv2Tel) ?>">โทร</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if (count($lv2Banners) > 1): ?>
<script>
(function () {
    var root = document.getElementById('lv2Banner');
    if (!root) { return; }
    var track = root.querySelector('.banner-track');
    var slides = root.querySelectorAll('.banner-slide');
    var dots = root.querySelectorAll('.banner-dot');
    var current = 0;
    var timer = null;

    function show(i) {
        current = (i + slides.length) % slides.length;
        track.style.transform = 'translateX(-' + (current * 100) + '%)';
        dots.forEach(function (d, di) { d.classList.toggle('active', di === current); });
    }
    function restart() {
        clearInterval(timer);
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) { return; }
        timer = setInterval(function () { show(current + 1); }, parseInt(root.dataset.autoplay, 10) || 5000);
    }

    root.querySelector('.banner-nav.prev').addEventListener('click', function () { show(current - 1); restart(); });
    root.querySelector('.banner-nav.next').addEventListener('click', function () { show(current + 1); restart(); });
    dots.forEach(function (d, i) { d.addEventListener('click', function () { show(i); restart(); }); });

    show(0);
    restart();
})();
</script>
<?php endif; ?>

</body>
</html>
