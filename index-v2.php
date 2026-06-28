<?php
/**
 * Public Landing Page V2 Prototype
 *
 * Public landing v2. This file intentionally does not replace index.php.
 */

if (!file_exists('config/installed.lock') && file_exists('install/index.php')) {
    header('Location: install/');
    exit;
}

require_once 'config/config.php';
require_once 'config/database.php';

$db = null;
try {
    $db = Database::getInstance()->getConnection();
} catch (Throwable $e) {
    $db = null;
}

function reya_v2_setting(PDO $db = null, int $lineAccountId = 1, string $key = '', ?string $default = null): ?string
{
    if (!$db || $key === '') {
        return $default;
    }

    try {
        $stmt = $db->prepare('SELECT setting_value FROM promotion_settings WHERE line_account_id = ? AND setting_key = ?');
        $stmt->execute([$lineAccountId, $key]);
        $value = $stmt->fetchColumn();
        return $value !== false ? (string) $value : $default;
    } catch (Throwable $e) {
        return $default;
    }
}

$lineAccount = null;
if ($db) {
    try {
        $stmt = $db->query('SELECT * FROM line_accounts WHERE is_default = 1 LIMIT 1');
        $lineAccount = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$lineAccount) {
            $stmt = $db->query('SELECT * FROM line_accounts ORDER BY id ASC LIMIT 1');
            $lineAccount = $stmt->fetch(PDO::FETCH_ASSOC);
        }
    } catch (Throwable $e) {
        $lineAccount = null;
    }
}

$lineAccountId = (int) ($lineAccount['id'] ?? 1);
$liffId = $lineAccount['liff_id'] ?? null;

$shopSettings = [];
if ($db) {
    try {
        $stmt = $db->prepare('SELECT * FROM shop_settings WHERE line_account_id = ? LIMIT 1');
        $stmt->execute([$lineAccountId]);
        $shopSettings = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        if (!$shopSettings) {
            $stmt = $db->query('SELECT * FROM shop_settings WHERE id = 1 OR line_account_id IS NULL LIMIT 1');
            $shopSettings = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        }
    } catch (Throwable $e) {
        $shopSettings = [];
    }
}

$shopName = $shopSettings['shop_name'] ?? 'REYA Pharmacy';
$shopLogo = $shopSettings['shop_logo'] ?? '';
$contactPhone = $shopSettings['contact_phone'] ?? '';
$lineId = $shopSettings['line_id'] ?? '';

require_once __DIR__ . '/includes/liff-helper.php';
$liffUrl = ($liffId && reya_is_real_liff_id($liffId)) ? "https://liff.line.me/{$liffId}" : null;

require_once 'classes/LandingPageRenderer.php';
$primaryColor = reya_v2_setting($db, $lineAccountId, 'primary_color', LandingPageRenderer::DEFAULT_PRIMARY_COLOR);
$secondaryColor = reya_v2_setting($db, $lineAccountId, 'secondary_color', LandingPageRenderer::DEFAULT_SECONDARY_COLOR);
$primaryColor = LandingPageRenderer::normalizeHexColor($primaryColor, LandingPageRenderer::DEFAULT_PRIMARY_COLOR);
$secondaryColor = LandingPageRenderer::normalizeHexColor($secondaryColor, LandingPageRenderer::DEFAULT_SECONDARY_COLOR);

$primaryRgb = implode(', ', [
    hexdec(substr($primaryColor, 1, 2)),
    hexdec(substr($primaryColor, 3, 2)),
    hexdec(substr($primaryColor, 5, 2)),
]);

$ctaHref = $liffUrl ?: '#waitlist';
$ctaLabel = $liffUrl ? 'เริ่มใช้งานผ่าน LINE' : 'เร็วๆ นี้';
$shopHref = $liffUrl ? htmlspecialchars($liffUrl . '#/shop') : '#waitlist';
$chatHref = $liffUrl ? htmlspecialchars($liffUrl . '#/ai-chat') : '#waitlist';

$heroTitle = 'ปรึกษาเภสัชกรและสั่งยากับ ' . $shopName . ' ได้ในไม่กี่ขั้นตอน';
$heroSubtitle = 'คุยกับทีมร้านยา ตรวจสอบสินค้า และติดตามออเดอร์ผ่าน LINE/LIFF ในประสบการณ์เดียวที่ออกแบบมาสำหรับคนไข้ไทย';
$aboutTitle = 'แนะนำบริการของ ' . $shopName;
$aboutText = $shopName . ' คือแพลตฟอร์มเครือข่ายร้านขายยาออนไลน์ ซึ่งเป็นทางเลือกในการดูแลสุขภาพแบบเข้าถึงง่ายและรวดเร็ว เพราะคุณสามารถปรึกษาเภสัชกรออนไลน์ได้ทันทีผ่านแชต โทร หรือวิดีโอคอล ไม่ว่าจะเป็นอาการเจ็บป่วยเล็กน้อย คำถามเกี่ยวกับการใช้ยา หรือข้อสงสัยด้านสุขภาพอื่นๆ ทีมเภสัชกรร้านยาของเราพร้อมให้คำแนะนำที่เหมาะสมเฉพาะบุคคล';
$aboutText2 = 'เราให้บริการครอบคลุมทั้งยาสามัญประจำบ้าน ยาที่จำหน่ายในร้านยาโดยเภสัชกร ยาตามใบสั่งแพทย์ และผลิตภัณฑ์เสริมอาหาร โดยทุกรายการผ่านการดูแลจากทีมเภสัชกร เพื่อประสิทธิภาพในการรักษาอาการเจ็บป่วยของแต่ละบุคคล สามารถสั่งยาออนไลน์ได้เลย พร้อมมีบริการส่ง Delivery ให้ถึงหน้าบ้านของคุณ';
$aboutText3 = 'นอกจากนี้ ยังมีบริการทางการแพทย์ออนไลน์อีกมากมาย ไม่ว่าจะเป็นการปรึกษาแพทย์ ปรึกษาจิตแพทย์ รวมถึงค้นหาร้านขายยาใกล้ฉัน สนใจใช้บริการรูปแบบใด อ่านรายละเอียดเพิ่มเติมจากทางด้านล่างนี้ได้เลย';
$featuresIntro = $shopName . ' เป็นแพลตฟอร์มร้านยาออนไลน์ที่มีความโดดเด่นด้านการให้บริการ ช่วยยกระดับคุณภาพชีวิตในหลากหลายประการ';
$serviceIntro = 'ครบครันทุกบริการด้านสุขภาพ';
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="<?= htmlspecialchars($primaryColor) ?>">
    <title><?= htmlspecialchars($heroTitle) ?></title>
    <meta name="description" content="<?= htmlspecialchars($heroSubtitle) ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@500;600;700;800&family=Sarabun:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: <?= htmlspecialchars($primaryColor) ?>;
            --primary-rgb: <?= htmlspecialchars($primaryRgb) ?>;
            --secondary: <?= htmlspecialchars($secondaryColor) ?>;
            --line: #06c755;
            --ink: #10201c;
            --muted: #5f716c;
            --soft: #eef8f4;
            --surface: #ffffff;
            --warm: #fff6e8;
            --border: rgba(16, 32, 28, 0.1);
            --shadow: 0 24px 80px rgba(16, 32, 28, 0.12);
        }

        * {
            box-sizing: border-box;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            margin: 0;
            font-family: 'Sarabun', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            color: var(--ink);
            background:
                radial-gradient(circle at 12% 0%, rgba(var(--primary-rgb), 0.15), transparent 32rem),
                linear-gradient(180deg, #fbfffd 0%, #f4fbf8 46%, #ffffff 100%);
            line-height: 1.6;
            -webkit-font-smoothing: antialiased;
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        img {
            display: block;
            max-width: 100%;
        }

        .v2-shell {
            overflow: hidden;
        }

        .v2-container {
            width: min(1120px, calc(100% - 32px));
            margin: 0 auto;
        }

        .v2-header {
            position: sticky;
            top: 0;
            z-index: 20;
            background: rgba(251, 255, 253, 0.82);
            border-bottom: 1px solid rgba(16, 32, 28, 0.08);
            backdrop-filter: blur(18px);
        }

        .v2-nav {
            min-height: 68px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
        }

        .v2-brand {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            font-weight: 800;
            letter-spacing: 0;
        }

        .v2-brand-mark {
            width: 42px;
            height: 42px;
            border-radius: 14px;
            display: grid;
            place-items: center;
            color: white;
            background: linear-gradient(145deg, var(--primary), #0fb981);
            box-shadow: 0 12px 28px rgba(var(--primary-rgb), 0.24);
            overflow: hidden;
        }

        .v2-brand-mark img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .v2-nav-links {
            display: flex;
            align-items: center;
            gap: 22px;
            color: var(--muted);
            font-size: 0.95rem;
            font-weight: 700;
        }

        .v2-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 9px;
            min-height: 46px;
            border-radius: 999px;
            padding: 0 20px;
            font-weight: 800;
            border: 1px solid transparent;
            transition: transform 0.18s ease, box-shadow 0.18s ease, background 0.18s ease;
            white-space: nowrap;
        }

        .v2-btn:hover {
            transform: translateY(-2px);
        }

        .v2-btn-primary {
            background: var(--line);
            color: white;
            box-shadow: 0 16px 34px rgba(6, 199, 85, 0.26);
        }

        .v2-btn-ghost {
            background: rgba(255, 255, 255, 0.72);
            border-color: var(--border);
            color: var(--ink);
        }

        .v2-hero {
            padding: 76px 0 48px;
        }

        .v2-hero-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.06fr) minmax(320px, 0.94fr);
            align-items: center;
            gap: 52px;
        }

        .v2-kicker {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 13px;
            border-radius: 999px;
            background: rgba(var(--primary-rgb), 0.1);
            color: var(--primary);
            font-weight: 800;
            font-size: 0.92rem;
        }

        .v2-hero h1 {
            margin: 22px 0 18px;
            max-width: 720px;
            font-size: clamp(2.5rem, 5.5vw, 5.8rem);
            line-height: 0.98;
            letter-spacing: 0;
            font-weight: 800;
        }

        .v2-hero h1 span {
            color: var(--primary);
        }

        .v2-lead {
            max-width: 640px;
            margin: 0;
            color: var(--muted);
            font-size: clamp(1.06rem, 1.7vw, 1.26rem);
            line-height: 1.75;
        }

        .v2-hero-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 28px;
        }

        .v2-proof {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 24px;
            color: var(--muted);
            font-weight: 700;
        }

        .v2-proof span {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.78);
            border: 1px solid var(--border);
        }

        .v2-phone-stage {
            position: relative;
            min-height: 620px;
            display: grid;
            place-items: center;
        }

        .v2-phone-stage::before {
            content: "";
            position: absolute;
            width: 430px;
            height: 430px;
            border-radius: 999px;
            background: linear-gradient(145deg, rgba(var(--primary-rgb), 0.18), rgba(6, 199, 85, 0.1));
            filter: blur(8px);
        }

        .v2-phone {
            position: relative;
            width: min(360px, 86vw);
            border: 10px solid #10201c;
            border-radius: 42px;
            background: #f8fffb;
            box-shadow: var(--shadow);
            padding: 18px;
        }

        .v2-phone::before {
            content: "";
            position: absolute;
            top: 12px;
            left: 50%;
            width: 86px;
            height: 7px;
            border-radius: 999px;
            background: #10201c;
            transform: translateX(-50%);
        }

        .v2-phone-screen {
            padding-top: 22px;
        }

        .v2-profile-card {
            border-radius: 28px;
            padding: 24px;
            color: white;
            background:
                linear-gradient(145deg, rgba(0, 0, 0, 0.12), transparent),
                linear-gradient(145deg, var(--primary), #11b57d);
        }

        .v2-profile-top {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .v2-avatar {
            width: 50px;
            height: 50px;
            border-radius: 18px;
            display: grid;
            place-items: center;
            background: rgba(255, 255, 255, 0.2);
            font-size: 1.35rem;
        }

        .v2-profile-card h2 {
            margin: 0;
            font-size: 1.2rem;
            line-height: 1.25;
        }

        .v2-profile-card p {
            margin: 3px 0 0;
            opacity: 0.88;
            font-size: 0.92rem;
        }

        .v2-link-list {
            display: grid;
            gap: 12px;
            margin-top: 16px;
        }

        .v2-link-pill {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 14px;
            border-radius: 18px;
            background: white;
            color: var(--ink);
            border: 1px solid var(--border);
            box-shadow: 0 12px 24px rgba(16, 32, 28, 0.07);
            font-weight: 800;
        }

        .v2-link-pill i:first-child {
            width: 34px;
            height: 34px;
            border-radius: 12px;
            display: grid;
            place-items: center;
            color: var(--primary);
            background: var(--soft);
        }

        .v2-floating {
            position: absolute;
            display: grid;
            gap: 4px;
            min-width: 168px;
            border-radius: 22px;
            padding: 14px 16px;
            background: white;
            border: 1px solid var(--border);
            box-shadow: 0 18px 50px rgba(16, 32, 28, 0.12);
            font-weight: 800;
        }

        .v2-floating small {
            color: var(--muted);
            font-weight: 700;
        }

        .v2-float-a {
            top: 88px;
            right: -2px;
        }

        .v2-float-b {
            left: 0;
            bottom: 86px;
        }

        .v2-showcase {
            position: relative;
            min-height: 640px;
            display: grid;
            place-items: center;
            isolation: isolate;
        }

        .v2-showcase::before {
            content: "";
            position: absolute;
            inset: 4% 0 0;
            border-radius: 999px;
            background:
                radial-gradient(circle at 50% 45%, rgba(var(--primary-rgb), 0.28), transparent 36%),
                radial-gradient(circle at 80% 22%, rgba(6, 199, 85, 0.16), transparent 30%);
            filter: blur(10px);
            z-index: -1;
        }

        .v2-shot {
            position: absolute;
            overflow: hidden;
            background: #ffffff;
            border: 1px solid rgba(255, 255, 255, 0.64);
            box-shadow: 0 30px 90px rgba(16, 32, 28, 0.22);
        }

        .v2-shot img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .v2-shot-main {
            width: min(330px, 72vw);
            height: 560px;
            z-index: 3;
            border-radius: 42px;
            border: 10px solid #0b2f28;
            background: #f7fbfb;
        }

        .v2-shot-side {
            width: 300px;
            height: 420px;
            z-index: 1;
            border-radius: 34px;
            opacity: 0.96;
        }

        .v2-shot-left {
            left: 0;
            top: 154px;
            transform: rotate(-4deg);
        }

        .v2-shot-right {
            right: 0;
            top: 154px;
            transform: rotate(4deg);
        }

        .v2-shot-left img,
        .v2-shot-right img {
            object-position: left top;
        }

        .v2-showcase-badge {
            position: absolute;
            z-index: 4;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border-radius: 999px;
            padding: 10px 14px;
            background: #ffffff;
            color: var(--primary);
            border: 1px solid var(--border);
            box-shadow: 0 18px 46px rgba(16, 32, 28, 0.14);
            font-weight: 800;
        }

        .v2-showcase-badge.top {
            top: 86px;
            right: 22px;
        }

        .v2-showcase-badge.bottom {
            left: 20px;
            bottom: 78px;
        }

        .v2-section {
            padding: 72px 0;
        }

        .v2-section-head {
            max-width: 720px;
            margin-bottom: 28px;
        }

        .v2-section-head.center {
            margin-left: auto;
            margin-right: auto;
            text-align: center;
        }

        .v2-section-head h2 {
            margin: 0 0 10px;
            font-size: clamp(2rem, 3.5vw, 3.5rem);
            line-height: 1.08;
            letter-spacing: 0;
        }

        .v2-section-head p {
            margin: 0;
            color: var(--muted);
            font-size: 1.08rem;
        }

        .v2-use-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 16px;
        }

        .v2-use-card {
            min-height: 230px;
            border-radius: 28px;
            padding: 22px;
            background: var(--surface);
            border: 1px solid var(--border);
            box-shadow: 0 16px 46px rgba(16, 32, 28, 0.07);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .v2-use-card:nth-child(2) {
            background: var(--warm);
        }

        .v2-use-card:nth-child(3) {
            background: #f1f6ff;
        }

        .v2-use-card:nth-child(4) {
            background: #f7f1ff;
        }

        .v2-card-icon {
            width: 48px;
            height: 48px;
            border-radius: 17px;
            display: grid;
            place-items: center;
            color: white;
            background: var(--primary);
            font-size: 1.15rem;
        }

        .v2-use-card h3 {
            margin: 22px 0 8px;
            font-size: 1.25rem;
            line-height: 1.25;
        }

        .v2-use-card p {
            margin: 0;
            color: var(--muted);
        }

        .v2-split {
            display: grid;
            grid-template-columns: minmax(0, 0.92fr) minmax(0, 1.08fr);
            align-items: center;
            gap: 44px;
        }

        .v2-dashboard {
            border-radius: 32px;
            padding: 18px;
            background: #10201c;
            box-shadow: var(--shadow);
        }

        .v2-dashboard-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: #dff8ef;
            margin-bottom: 14px;
            font-weight: 800;
        }

        .v2-dot-row {
            display: flex;
            gap: 6px;
        }

        .v2-dot-row span {
            width: 10px;
            height: 10px;
            border-radius: 99px;
            background: rgba(255, 255, 255, 0.25);
        }

        .v2-crm-grid {
            display: grid;
            grid-template-columns: 0.9fr 1.1fr;
            gap: 12px;
        }

        .v2-crm-panel {
            border-radius: 22px;
            padding: 16px;
            background: #f8fffb;
        }

        .v2-crm-panel.dark {
            background: #17352e;
            color: white;
        }

        .v2-crm-panel h3 {
            margin: 0 0 12px;
            font-size: 1rem;
        }

        .v2-mini-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 10px 0;
            border-top: 1px solid rgba(16, 32, 28, 0.08);
            color: var(--muted);
            font-weight: 700;
        }

        .v2-crm-panel.dark .v2-mini-row {
            border-color: rgba(255, 255, 255, 0.12);
            color: #cde9df;
        }

        .v2-badge {
            border-radius: 99px;
            padding: 4px 9px;
            color: var(--primary);
            background: rgba(var(--primary-rgb), 0.1);
            font-size: 0.82rem;
            font-weight: 800;
        }

        .v2-steps {
            display: grid;
            gap: 13px;
        }

        .v2-step {
            display: grid;
            grid-template-columns: 42px 1fr;
            gap: 13px;
            align-items: start;
            padding: 15px;
            border-radius: 22px;
            background: white;
            border: 1px solid var(--border);
        }

        .v2-step-num {
            width: 42px;
            height: 42px;
            border-radius: 15px;
            display: grid;
            place-items: center;
            color: white;
            background: var(--primary);
            font-family: 'Lexend', sans-serif;
            font-weight: 800;
        }

        .v2-step h3 {
            margin: 0 0 4px;
            font-size: 1.04rem;
        }

        .v2-step p {
            margin: 0;
            color: var(--muted);
        }

        .v2-feature-band {
            border-radius: 36px;
            padding: 42px;
            color: white;
            background:
                radial-gradient(circle at 90% 0%, rgba(255, 255, 255, 0.22), transparent 22rem),
                linear-gradient(145deg, #10201c, #173d34);
            box-shadow: var(--shadow);
        }

        .v2-feature-band h2,
        .v2-feature-band p {
            color: white;
        }

        .v2-feature-list {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 14px;
            margin-top: 24px;
        }

        .v2-feature-item {
            min-height: 150px;
            border-radius: 24px;
            padding: 20px;
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.12);
        }

        .v2-feature-item i {
            color: #67f3aa;
            font-size: 1.35rem;
        }

        .v2-feature-item h3 {
            margin: 14px 0 6px;
            font-size: 1.08rem;
        }

        .v2-feature-item p {
            margin: 0;
            color: #cde9df;
        }

        .v2-cta {
            padding: 86px 0;
            text-align: center;
        }

        .v2-cta-card {
            border-radius: 38px;
            padding: 52px 24px;
            background:
                linear-gradient(145deg, rgba(var(--primary-rgb), 0.12), rgba(6, 199, 85, 0.08)),
                white;
            border: 1px solid var(--border);
            box-shadow: 0 18px 60px rgba(16, 32, 28, 0.08);
        }

        .v2-cta h2 {
            max-width: 760px;
            margin: 0 auto 12px;
            font-size: clamp(2rem, 4vw, 4.2rem);
            line-height: 1.05;
        }

        .v2-cta p {
            max-width: 620px;
            margin: 0 auto 26px;
            color: var(--muted);
            font-size: 1.08rem;
        }

        .v2-footer {
            padding: 30px 0 40px;
            color: var(--muted);
            border-top: 1px solid var(--border);
        }

        .v2-footer-inner {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
        }

        @media (max-width: 980px) {
            .v2-nav-links {
                display: none;
            }

            .v2-hero-grid,
            .v2-split {
                grid-template-columns: 1fr;
            }

            .v2-phone-stage {
                min-height: 560px;
            }

            .v2-use-grid,
            .v2-feature-list {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 640px) {
            .v2-container {
                width: min(100% - 24px, 1120px);
            }

            .v2-header .v2-btn {
                min-height: 40px;
                padding: 0 13px;
                font-size: 0.9rem;
            }

            .v2-hero {
                padding: 48px 0 28px;
            }

            .v2-hero h1 {
                font-size: clamp(2.38rem, 16vw, 4.2rem);
            }

            .v2-hero-actions {
                display: grid;
            }

            .v2-phone-stage {
                min-height: 500px;
            }

            .v2-showcase {
                min-height: 620px;
            }

            .v2-shot-side {
                width: 210px;
                height: 300px;
                opacity: 0.78;
            }

            .v2-shot-main {
                width: min(300px, 78vw);
                height: 520px;
            }

            .v2-shot-left {
                left: -34px;
                top: 166px;
            }

            .v2-shot-right {
                right: -34px;
                top: 166px;
            }

            .v2-showcase-badge {
                font-size: 0.86rem;
                padding: 8px 11px;
            }

            .v2-showcase-badge.top {
                top: 58px;
                right: 6px;
            }

            .v2-showcase-badge.bottom {
                left: 6px;
                bottom: 48px;
            }

            .v2-floating {
                position: relative;
                inset: auto;
                min-width: 0;
                width: min(270px, 90%);
                margin: -8px auto 0;
            }

            .v2-float-a {
                order: -1;
            }

            .v2-use-grid,
            .v2-feature-list,
            .v2-crm-grid {
                grid-template-columns: 1fr;
            }

            .v2-section {
                padding: 52px 0;
            }

            .v2-feature-band {
                border-radius: 28px;
                padding: 28px 18px;
            }

            .v2-cta-card {
                border-radius: 28px;
                padding: 38px 18px;
            }
        }
    </style>
</head>
<body>
    <div class="v2-shell">
        <header class="v2-header">
            <div class="v2-container v2-nav">
                <a class="v2-brand" href="#top" aria-label="<?= htmlspecialchars($shopName) ?>">
                    <span class="v2-brand-mark">
                        <?php if ($shopLogo): ?>
                            <img src="<?= htmlspecialchars($shopLogo) ?>" alt="<?= htmlspecialchars($shopName) ?>">
                        <?php else: ?>
                            <i class="fas fa-prescription-bottle-medical" aria-hidden="true"></i>
                        <?php endif; ?>
                    </span>
                    <span><?= htmlspecialchars($shopName) ?></span>
                </a>
                <nav class="v2-nav-links" aria-label="เมนูหลัก">
                    <a href="#journey">บริการ</a>
                    <a href="#crm">สินค้าแนะนำ</a>
                    <a href="#line">ติดต่อ</a>
                </nav>
                <a class="v2-btn v2-btn-primary" href="<?= htmlspecialchars($ctaHref) ?>">
                    <i class="fab fa-line" aria-hidden="true"></i>
                    <?= htmlspecialchars($ctaLabel) ?>
                </a>
            </div>
        </header>

        <main id="top">
            <section class="v2-hero" aria-labelledby="v2-hero-title">
                <div class="v2-container v2-hero-grid">
                    <div>
                        <span class="v2-kicker"><i class="fas fa-user-md" aria-hidden="true"></i> เภสัชกรดูแล</span>
                        <h1 id="v2-hero-title"><?= htmlspecialchars($heroTitle) ?></h1>
                        <p class="v2-lead">
                            <?= htmlspecialchars($heroSubtitle) ?>
                        </p>
                        <div class="v2-hero-actions">
                            <a class="v2-btn v2-btn-primary" href="<?= htmlspecialchars($ctaHref) ?>">
                                <i class="fab fa-line" aria-hidden="true"></i>
                                <?= htmlspecialchars($ctaLabel) ?>
                            </a>
                            <a class="v2-btn v2-btn-ghost" href="#journey">
                                <i class="fas fa-arrow-down" aria-hidden="true"></i>
                                ดูบริการของเรา
                            </a>
                        </div>
                        <div class="v2-proof" aria-label="จุดเด่น">
                            <span><i class="fas fa-user-md" aria-hidden="true"></i> เภสัชกรดูแล</span>
                            <span><i class="fas fa-truck-fast" aria-hidden="true"></i> จัดส่งถึงบ้าน</span>
                            <span><i class="fas fa-receipt" aria-hidden="true"></i> ติดตามออเดอร์ได้</span>
                        </div>
                    </div>

                    <div class="v2-showcase" aria-label="ภาพตัวอย่างระบบ <?= htmlspecialchars($shopName) ?>">
                        <div class="v2-showcase-badge top">
                            <i class="fas fa-capsules" aria-hidden="true"></i>
                            สินค้าหลากหลาย
                        </div>
                        <figure class="v2-shot v2-shot-side v2-shot-left" aria-label="Medical Copilot AI">
                            <img src="docs/screenshots/04-ai/ai-studio-viewport.png" alt="Medical Copilot AI">
                        </figure>
                        <figure class="v2-shot v2-shot-main" aria-label="สินค้า">
                            <img src="docs/screenshots/01-miniapp/miniapp-shop-fullpage.png" alt="สินค้า">
                        </figure>
                        <figure class="v2-shot v2-shot-side v2-shot-right" aria-label="Pharmacy Management">
                            <img src="docs/screenshots/08-telepharmacy/pharmacy-management-viewport.png" alt="Pharmacy Management">
                        </figure>
                        <div class="v2-showcase-badge bottom">
                            <i class="fas fa-truck-fast" aria-hidden="true"></i>
                            บริการจัดส่งทั่วประเทศ
                        </div>
                    </div>
                </div>
            </section>

            <section class="v2-section" id="journey">
                <div class="v2-container">
                    <div class="v2-section-head center">
                        <h2><?= htmlspecialchars($aboutTitle) ?></h2>
                        <p><?= htmlspecialchars($featuresIntro) ?></p>
                    </div>
                    <div class="v2-use-grid">
                        <a class="v2-use-card" href="<?= $chatHref ?>">
                            <div>
                                <div class="v2-card-icon"><i class="fas fa-message" aria-hidden="true"></i></div>
                                <h3>ปรึกษาเภสัชกร</h3>
                                <p>พูดคุยกับเภสัชกรผู้เชี่ยวชาญ ได้คำแนะนำที่ถูกต้อง</p>
                            </div>
                            <strong>เริ่มใช้งานผ่าน LINE</strong>
                        </a>
                        <a class="v2-use-card" href="<?= $shopHref ?>">
                            <div>
                                <div class="v2-card-icon"><i class="fas fa-capsules" aria-hidden="true"></i></div>
                                <h3>เลือกซื้อสินค้า</h3>
                                <p>เลือกซื้อยาและผลิตภัณฑ์สุขภาพได้ง่ายๆ พร้อมจัดส่งถึงบ้าน</p>
                            </div>
                            <strong>ดูสินค้าแนะนำ</strong>
                        </a>
                        <a class="v2-use-card" href="<?= htmlspecialchars($ctaHref) ?>">
                            <div>
                                <div class="v2-card-icon"><i class="fas fa-calendar-check" aria-hidden="true"></i></div>
                                <h3>นัดหมายออนไลน์</h3>
                                <p>ครบครันทุกบริการด้านสุขภาพ</p>
                            </div>
                            <strong>ดูบริการของเรา</strong>
                        </a>
                        <a class="v2-use-card" href="<?= htmlspecialchars($ctaHref) ?>">
                            <div>
                                <div class="v2-card-icon"><i class="fas fa-box-open" aria-hidden="true"></i></div>
                                <h3>บริการครบวงจร</h3>
                                <p>ทั้งการปรึกษา การสั่งซื้อ และการจัดส่ง ในแพลตฟอร์มเดียว</p>
                            </div>
                            <strong>เปิดแอปเลย</strong>
                        </a>
                    </div>
                </div>
            </section>

            <section class="v2-section" id="crm">
                <div class="v2-container v2-split">
                    <div class="v2-dashboard" aria-label="Pharmacy Management">
                        <img src="docs/screenshots/08-telepharmacy/pharmacy-management-viewport.png" alt="Pharmacy Management" style="width:100%;border-radius:24px;">
                    </div>
                    <div>
                        <div class="v2-section-head">
                            <h2>บริการของเรา</h2>
                            <p><?= htmlspecialchars($serviceIntro) ?></p>
                        </div>
                        <div class="v2-steps">
                            <div class="v2-step">
                                <div class="v2-step-num">1</div>
                                <div>
                                    <h3>บริการจัดส่งทั่วประเทศ</h3>
                                    <p>เลือกซื้อยาและผลิตภัณฑ์สุขภาพได้ง่ายๆ พร้อมจัดส่งถึงบ้าน</p>
                                </div>
                            </div>
                            <div class="v2-step">
                                <div class="v2-step-num">2</div>
                                <div>
                                    <h3>เภสัชกรผู้ชำนาญการ</h3>
                                    <p>พูดคุยกับเภสัชกรผู้เชี่ยวชาญ ได้คำแนะนำที่ถูกต้อง</p>
                                </div>
                            </div>
                            <div class="v2-step">
                                <div class="v2-step-num">3</div>
                                <div>
                                    <h3>สินค้าหลากหลาย</h3>
                                    <p><?= htmlspecialchars($aboutText2) ?></p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="v2-section" id="line">
                <div class="v2-container">
                    <div class="v2-feature-band">
                        <div class="v2-section-head">
                            <span class="v2-kicker"><i class="fab fa-line" aria-hidden="true"></i> LINE/LIFF</span>
                            <h2><?= htmlspecialchars($heroTitle) ?></h2>
                            <p><?= htmlspecialchars($aboutText3) ?></p>
                        </div>
                        <div class="v2-feature-list">
                            <div class="v2-feature-item">
                                <i class="fas fa-mobile-screen-button" aria-hidden="true"></i>
                                <h3>เปิดแอป</h3>
                                <p><?= htmlspecialchars($heroSubtitle) ?></p>
                            </div>
                            <div class="v2-feature-item">
                                <i class="fas fa-qrcode" aria-hidden="true"></i>
                                <h3>ดูสินค้าแนะนำ</h3>
                                <p>เลือกซื้อยาและผลิตภัณฑ์สุขภาพได้ง่ายๆ พร้อมจัดส่งถึงบ้าน</p>
                            </div>
                            <div class="v2-feature-item">
                                <i class="fas fa-chart-line" aria-hidden="true"></i>
                                <h3>ดูบริการของเรา</h3>
                                <p>ครบครันทุกบริการด้านสุขภาพ</p>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="v2-cta" id="waitlist">
                <div class="v2-container">
                    <div class="v2-cta-card">
                        <h2><?= htmlspecialchars($aboutTitle) ?></h2>
                        <p><?= htmlspecialchars($aboutText) ?></p>
                        <a class="v2-btn v2-btn-primary" href="<?= htmlspecialchars($ctaHref) ?>">
                            <i class="fab fa-line" aria-hidden="true"></i>
                            <?= htmlspecialchars($ctaLabel) ?>
                        </a>
                    </div>
                </div>
            </section>
        </main>

        <footer class="v2-footer">
            <div class="v2-container v2-footer-inner">
                <span>&copy; <?= date('Y') ?> <?= htmlspecialchars($shopName) ?></span>
                <span>
                    <?php if ($lineId): ?>LINE: <?= htmlspecialchars($lineId) ?><?php endif; ?>
                    <?php if ($lineId && $contactPhone): ?> · <?php endif; ?>
                    <?php if ($contactPhone): ?>โทร <?= htmlspecialchars($contactPhone) ?><?php endif; ?>
                </span>
            </div>
        </footer>
    </div>
</body>
</html>
