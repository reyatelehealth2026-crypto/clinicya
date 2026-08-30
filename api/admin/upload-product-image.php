<?php
/**
 * Product image upload endpoint.
 *
 * Saves uploaded files to public_html/uploads/products/{product_id}/{hash}.{ext}
 * and returns the public URL for storing in business_items.image_url
 * or appending to image_gallery JSON.
 *
 * POST multipart/form-data:
 *   image      (file)  — image/jpeg|png|webp|gif, max 5MB
 *   product_id (int)   — must belong to current tenant (super_admin/admin bypass)
 *
 * Response: { success: bool, url?: string, filename?: string, error?: string }
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
@ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_WARNING);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth_check.php';

$db            = Database::getInstance()->getConnection();
$lineAccountId = (int) ($_SESSION['current_bot_id'] ?? $currentUser['line_account_id'] ?? 0);
$userRole      = (string) ($_SESSION['admin_user']['role'] ?? '');
$unrestricted  = in_array($userRole, ['super_admin', 'admin'], true);

function up_fail(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}
function up_ok(array $extra = []): void {
    echo json_encode(['success' => true] + $extra, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        up_fail('POST only', 405);
    }
    $productId = (int) ($_POST['product_id'] ?? 0);
    if ($productId <= 0) {
        up_fail('product_id required');
    }
    if (empty($_FILES['image']) || ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $err = $_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE;
        $msg = [
            UPLOAD_ERR_INI_SIZE   => 'ไฟล์ใหญ่เกินกว่า php.ini upload_max_filesize',
            UPLOAD_ERR_FORM_SIZE  => 'ไฟล์ใหญ่เกินขีดจำกัด',
            UPLOAD_ERR_PARTIAL    => 'อัพโหลดไม่สมบูรณ์',
            UPLOAD_ERR_NO_FILE    => 'ไม่ได้แนบไฟล์',
            UPLOAD_ERR_NO_TMP_DIR => 'Server: missing tmp dir',
            UPLOAD_ERR_CANT_WRITE => 'Server: write failed',
        ][$err] ?? ('upload error ' . $err);
        up_fail($msg);
    }

    // Tenancy check: product must belong to current line_account_id (admins bypass)
    $own = $db->prepare('SELECT line_account_id FROM business_items WHERE id = ?');
    $own->execute([$productId]);
    $rowLa = $own->fetchColumn();
    if ($rowLa === false) {
        up_fail('product not found', 404);
    }
    if (!$unrestricted && (int) $rowLa !== $lineAccountId) {
        up_fail('forbidden', 403);
    }

    // Validate size + mime
    $file = $_FILES['image'];
    $sizeMax = 5 * 1024 * 1024; // 5 MB
    if ((int) $file['size'] > $sizeMax) {
        up_fail('ไฟล์ใหญ่เกิน 5MB');
    }

    $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : null;
    $mime  = $finfo ? finfo_file($finfo, $file['tmp_name']) : ($file['type'] ?? '');
    if ($finfo) finfo_close($finfo);

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/pjpeg'=> 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];
    if (!isset($allowed[$mime])) {
        up_fail('รองรับเฉพาะ JPG / PNG / WebP / GIF (ตรวจจาก mime: ' . htmlspecialchars((string) $mime) . ')');
    }
    $ext = $allowed[$mime];

    // Destination: public_html/uploads/products/{id}/{hash}.{ext}
    // __DIR__ = public_html/api/admin → ../../uploads/products/{id}
    $baseDir = realpath(__DIR__ . '/../..');
    if ($baseDir === false) {
        up_fail('Server: base path error', 500);
    }
    $relDir = '/uploads/products/' . $productId;
    $absDir = $baseDir . $relDir;
    if (!is_dir($absDir)) {
        if (!@mkdir($absDir, 0755, true) && !is_dir($absDir)) {
            up_fail('Server: cannot create upload dir (' . $absDir . ')', 500);
        }
    }
    if (!is_writable($absDir)) {
        up_fail('Server: upload dir not writable (' . $absDir . ')', 500);
    }

    // Unique filename — hash + timestamp suffix (avoid collisions + browser cache)
    $name = bin2hex(random_bytes(6)) . '_' . substr((string) time(), -6) . '.' . $ext;
    $absPath = $absDir . '/' . $name;
    if (!move_uploaded_file($file['tmp_name'], $absPath)) {
        up_fail('Server: move_uploaded_file failed', 500);
    }
    @chmod($absPath, 0644);

    $publicUrl = $relDir . '/' . $name; // e.g. /uploads/products/23/abc123_456789.jpg

    up_ok([
        'url'        => $publicUrl,
        'filename'   => $name,
        'product_id' => $productId,
        'size'       => (int) $file['size'],
        'mime'       => $mime,
    ]);
} catch (Throwable $e) {
    error_log('[upload-product-image] ' . $e->getMessage());
    up_fail('Server error: ' . $e->getMessage(), 500);
}
