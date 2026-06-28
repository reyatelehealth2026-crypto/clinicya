<?php
/**
 * Data Deletion Instructions — คำแนะนำการลบข้อมูลผู้ใช้
 *
 * Public page satisfying Meta's "User Data Deletion" requirement (instructions URL)
 * for Facebook/Messenger app review. Standalone, no auth. Bilingual Thai/English.
 */
require_once 'config/config.php';
require_once 'config/database.php';

$db = Database::getInstance()->getConnection();

// ---------------------------------------------------------------------------
// Facebook "Data Deletion Callback" — Meta POSTs a signed_request and expects
// a JSON reply {url, confirmation_code}. Serving this makes the URL valid for
// BOTH the Callback-URL and Instructions-URL settings in the Meta App Dashboard.
// (Plain GET requests fall through to the human-readable instructions page.)
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['signed_request'])) {
    header('Content-Type: application/json');

    $appSecret = '';
    try {
        $r = $db->query("SELECT app_secret FROM facebook_accounts WHERE is_active = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $appSecret = $r['app_secret'] ?? '';
    } catch (Exception $e) {
    }

    $data  = [];
    $parts = explode('.', (string) $_POST['signed_request'], 2);
    if (count($parts) === 2 && $appSecret !== '') {
        [$encSig, $payload] = $parts;
        $sig      = base64_decode(strtr($encSig, '-_', '+/'));
        $expected = hash_hmac('sha256', $payload, $appSecret, true);
        if (hash_equals($expected, $sig)) {
            $data = json_decode(base64_decode(strtr($payload, '-_', '+/')), true) ?: [];
        }
    }

    $fbUserId = $data['user_id'] ?? 'unknown';
    $code     = 'REYA-DEL-' . substr(hash('sha256', $fbUserId . '|' . microtime(true)), 0, 16);

    // Best-effort: flag the matching Facebook contact for deletion (non-fatal).
    try {
        $db->prepare("UPDATE users SET chat_status = 'delete_requested' WHERE platform = 'facebook' AND platform_user_id = ?")
           ->execute([$fbUserId]);
    } catch (Exception $e) {
    }

    echo json_encode([
        'url'               => 'https://re-ya.com/data-deletion.php?confirm=' . urlencode($code),
        'confirmation_code' => $code,
    ]);
    exit;
}

// Pull company/contact info from DB (same source as privacy-policy.php)
$companyName   = 'ร้านยา';
$companyPhone  = '';
$companyEmail  = '';
$companyLineId = '';

try {
    $stmt = $db->query("SELECT name FROM line_accounts WHERE is_default = 1 OR is_active = 1 ORDER BY is_default DESC LIMIT 1");
    $lineAccount = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($lineAccount && $lineAccount['name']) {
        $companyName = $lineAccount['name'];
    }

    $stmt = $db->query("SELECT * FROM shop_settings LIMIT 1");
    $shopSettings = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $companyPhone  = $shopSettings['contact_phone'] ?? $shopSettings['shop_phone'] ?? '';
    $companyEmail  = $shopSettings['shop_email'] ?? '';
    $companyLineId = $shopSettings['line_id'] ?? '';
} catch (Exception $e) {
}

$contactEmail = $companyEmail !== '' ? $companyEmail : 'support@re-ya.com';
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>คำแนะนำการลบข้อมูลผู้ใช้ (User Data Deletion) - <?= htmlspecialchars($companyName) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: 'Sarabun', sans-serif; }
        .content h2 { font-size: 1.25rem; font-weight: bold; margin-top: 1.5rem; margin-bottom: 0.75rem; color: #1e40af; }
        .content p { margin-bottom: 0.75rem; line-height: 1.8; }
        .content ul { list-style-type: disc; margin-left: 1.5rem; margin-bottom: 0.75rem; }
        .content li { margin-bottom: 0.35rem; }
    </style>
</head>
<body class="bg-gray-50">
    <div class="bg-blue-600 text-white py-6">
        <div class="max-w-4xl mx-auto px-4">
            <h1 class="text-2xl font-bold">🗑️ คำแนะนำการลบข้อมูลผู้ใช้</h1>
            <p class="text-blue-100 mt-1">User Data Deletion Instructions</p>
        </div>
    </div>

    <div class="max-w-4xl mx-auto px-4 py-8">
        <div class="bg-white rounded-xl shadow-lg p-6 md:p-8 content">

            <div class="bg-blue-50 border-l-4 border-blue-500 p-4 mb-6">
                <p class="text-sm text-blue-800">
                    <strong><?= htmlspecialchars($companyName) ?></strong> ("เรา") เคารพสิทธิ์ของท่านในการขอลบข้อมูลส่วนบุคคล
                    ตามพระราชบัญญัติคุ้มครองข้อมูลส่วนบุคคล พ.ศ. 2562 (PDPA) หน้านี้อธิบายวิธีการขอลบข้อมูลที่ท่านให้ไว้กับเรา
                    ผ่านบริการ LINE Official Account, Facebook Messenger และ TikTok Shop
                </p>
            </div>

            <p><strong>ปรับปรุงล่าสุด:</strong> <?= date('d/m/Y') ?></p>

            <h2>ข้อมูลที่เราจัดเก็บ / Data we store</h2>
            <p>เมื่อท่านติดต่อหรือใช้บริการของเราผ่าน LINE, Facebook Messenger หรือ TikTok Shop เราอาจจัดเก็บ:</p>
            <ul>
                <li>ชื่อที่แสดง (display name) และรูปโปรไฟล์สาธารณะของแพลตฟอร์ม</li>
                <li>รหัสผู้ใช้ของแพลตฟอร์ม (LINE User ID / Facebook PSID / TikTok Buyer ID)</li>
                <li>ประวัติการสนทนา ข้อความ และไฟล์ที่ท่านส่งมาให้ร้าน</li>
                <li>ข้อมูลคำสั่งซื้อ การนัดหมาย และการจ่ายยา (ถ้ามี)</li>
            </ul>

            <h2>วิธีขอลบข้อมูล / How to request deletion</h2>
            <p>ท่านสามารถขอให้เราลบข้อมูลส่วนบุคคลทั้งหมดของท่านได้ฟรี โดยติดต่อเราพร้อมแจ้ง
                "<strong>ขอลบข้อมูลส่วนบุคคล</strong>" ผ่านช่องทางใดช่องทางหนึ่งต่อไปนี้:</p>
            <ul>
                <li><strong>อีเมล:</strong> <a class="text-blue-600 underline" href="mailto:<?= htmlspecialchars($contactEmail) ?>?subject=ขอลบข้อมูลส่วนบุคคล%20(Data%20Deletion%20Request)"><?= htmlspecialchars($contactEmail) ?></a></li>
                <?php if ($companyPhone !== ''): ?>
                <li><strong>โทรศัพท์:</strong> <?= htmlspecialchars($companyPhone) ?></li>
                <?php endif; ?>
                <?php if ($companyLineId !== ''): ?>
                <li><strong>LINE:</strong> <?= htmlspecialchars($companyLineId) ?></li>
                <?php endif; ?>
                <li><strong>ทักแชทมาที่เพจ/บัญชีทางการของเรา</strong> (LINE OA, Facebook Messenger หรือ TikTok Shop) แล้วพิมพ์ว่า "ขอลบข้อมูล"</li>
            </ul>
            <p>โปรดระบุชื่อที่ใช้ติดต่อ และช่องทาง (LINE/Facebook/TikTok) ที่ท่านเคยใช้ เพื่อให้เราระบุและลบข้อมูลได้ถูกต้อง</p>

            <h2>ระยะเวลาดำเนินการ / Processing time</h2>
            <p>เราจะดำเนินการลบข้อมูลส่วนบุคคลของท่านออกจากระบบภายใน <strong>30 วัน</strong> นับจากวันที่ได้รับคำขอและยืนยันตัวตน
                ยกเว้นข้อมูลที่กฎหมายกำหนดให้ต้องเก็บรักษาไว้ (เช่น เอกสารภาษี/ใบกำกับภาษี) ซึ่งจะถูกลบเมื่อพ้นระยะเวลาที่กฎหมายกำหนด</p>

            <h2>การเพิกถอนการเชื่อมต่อแอป / Revoking app access</h2>
            <p>ท่านยังสามารถยกเลิกการอนุญาตให้แอปเข้าถึงข้อมูลของท่านได้เองที่การตั้งค่าของแต่ละแพลตฟอร์ม
                (เช่น Facebook → การตั้งค่า → แอปและเว็บไซต์)</p>

            <div class="mt-8 pt-4 border-t text-sm text-gray-500">
                <p>หากมีข้อสงสัยเกี่ยวกับการคุ้มครองข้อมูลส่วนบุคคล โปรดดู
                    <a class="text-blue-600 underline" href="privacy-policy.php">นโยบายคุ้มครองข้อมูลส่วนบุคคล (Privacy Policy)</a>
                </p>
                <p class="mt-2">&copy; <?= date('Y') ?> <?= htmlspecialchars($companyName) ?></p>
            </div>
        </div>
    </div>
</body>
</html>
