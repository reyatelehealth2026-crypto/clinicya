<?php
/**
 * api/inventory-csv.php — CSV import/export/template for storefront products
 *
 * Actions (all scoped by $_SESSION['current_bot_id']):
 *   GET  ?action=template  → downloads sample CSV using the master_สินค้า.xlsx schema
 *                            (12 Thai-headered columns matching the user's working template)
 *   GET  ?action=export    → downloads all current business_items in the same 12-col schema
 *   POST ?action=import    → multipart upload (file=<csv>); parses + bulk-inserts/updates
 *                            returns JSON { ok, inserted, updated, skipped, errors }
 *
 * Accepted CSV column headers (case-insensitive, alias-friendly, mix Thai + English):
 *   - sku                       :  รหัสสินค้า (SKU), รหัสสินค้า, รหัส
 *   - name                      :  ชื่อสินค้า (ไทย), ชื่อสินค้า, ชื่อ
 *   - name_en                   :  ชื่อสินค้า (อังกฤษ), english name
 *   - manufacturer              :  ผู้ผลิต, brand
 *   - variant                   :  ตัวแปร, รุ่น                      → business_items.dosage_form
 *   - generic_name              :  ตัวยาสำคัญ (Generic), ตัวยาสำคัญ  → generic_name + active_ingredient
 *   - unit                      :  หน่วย, หน่วยนับ
 *   - pack_size                 :  ขนาดบรรจุ, ขนาด                  → business_items.strength
 *   - usage                     :  วิธีใช้, usage_instructions      → usage_instructions + default_usage_text
 *   - description               :  สรรพคุณ / คุณสมบัติ, รายละเอียด
 *   - image_url                 :  รูปภาพ (URL), รูปภาพ, photo       → image_url + photo_path
 *   - category                  :  หมวด, หมวดหมู่                   (mapped via business_categories.name when present)
 *   - list_price                :  ราคา, ราคาขาย, price
 *   - online_price              :  ราคา online, sale_price
 *   - stock                     :  สต็อก, คงเหลือ
 *   - is_active                 :  active, เปิดขาย, เปิดใช้งาน
 *   - "name_en เดิม (อ้างอิง)"   :  ignored (reference column for migrators)
 *
 * Required headers: sku, name.  Everything else optional.
 *
 * Excel (.xlsx) is NOT supported directly — instruct user to "Save As .csv"
 * (no PhpSpreadsheet dependency on shared cPanel hosting).
 *
 * @package Inventory
 * @version 2.0.0
 */
declare(strict_types=1);

// Big imports may take a while — disable PHP timeout and don't die when user closes tab.
@set_time_limit(0);
@ignore_user_abort(true);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';

$db = Database::getInstance()->getConnection();
$lineAccountId = (int) ($_SESSION['current_bot_id'] ?? 0);
if ($lineAccountId <= 0 && !empty($_SESSION['user_id'])) {
    try {
        $s = $db->prepare('SELECT line_account_id FROM admin_users WHERE id = ? LIMIT 1');
        $s->execute([(int) $_SESSION['user_id']]);
        $lineAccountId = (int) ($s->fetchColumn() ?: 0);
    } catch (\Throwable $e) {}
}
if ($lineAccountId <= 0) {
    try {
        $r = $db->query('SELECT id FROM line_accounts WHERE is_active=1 ORDER BY id ASC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        $lineAccountId = (int) ($r['id'] ?? 0);
    } catch (\Throwable $e) {}
}
if ($lineAccountId <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'no_line_account']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ─── canonical → Thai display headers (Excel template order) ──────────────────
// These are what we write to template/export. The importer also accepts the
// English column names (kept for legacy CSVs uploaded before May 2026).
const TEMPLATE_HEADERS = [
    'sku'          => 'รหัสสินค้า (SKU)',
    'name'         => 'ชื่อสินค้า (ไทย)',
    'name_en'      => 'ชื่อสินค้า (อังกฤษ)',
    'manufacturer' => 'ผู้ผลิต',
    'variant'      => 'ตัวแปร',
    'generic_name' => 'ตัวยาสำคัญ (Generic)',
    'unit'         => 'หน่วย',
    'pack_size'    => 'ขนาดบรรจุ',
    'usage'        => 'วิธีใช้',
    'description'  => 'สรรพคุณ / คุณสมบัติ',
    'image_url'    => 'รูปภาพ (URL)',
];

// ─── header alias map: any of these (lowercased, trimmed) → canonical key ─────
const HEADER_ALIASES = [
    'sku' => [
        'sku', 'รหัสสินค้า (sku)', 'รหัสสินค้า', 'รหัส', 'product_code',
    ],
    'name' => [
        'name', 'ชื่อสินค้า (ไทย)', 'ชื่อสินค้า', 'ชื่อ', 'ชื่อสินค้าไทย', 'thai_name',
    ],
    'name_en' => [
        'name_en', 'ชื่อสินค้า (อังกฤษ)', 'name en', 'english name', 'english_name',
        'ชื่อสินค้าอังกฤษ', 'ชื่ออังกฤษ',
    ],
    'manufacturer' => [
        'manufacturer', 'ผู้ผลิต', 'maker', 'brand', 'ยี่ห้อ',
    ],
    'variant' => [
        'variant', 'ตัวแปร', 'รุ่น', 'รุ่น/ตัวแปร',
    ],
    'generic_name' => [
        'generic_name', 'ตัวยาสำคัญ (generic)', 'ตัวยาสำคัญ', 'generic',
        'active_ingredient', 'ตัวยา',
    ],
    'unit' => [
        'unit', 'หน่วย', 'หน่วยนับ',
    ],
    'pack_size' => [
        'pack_size', 'ขนาดบรรจุ', 'size', 'ขนาด', 'pack', 'package_size',
    ],
    'usage' => [
        'usage', 'usage_instructions', 'วิธีใช้', 'การใช้', 'วิธีรับประทาน',
    ],
    'description' => [
        'description', 'สรรพคุณ / คุณสมบัติ', 'สรรพคุณ/คุณสมบัติ',
        'สรรพคุณ', 'คุณสมบัติ', 'รายละเอียด', 'desc',
    ],
    'image_url' => [
        'image_url', 'image', 'รูปภาพ (url)', 'รูปภาพ', 'รูป', 'photo', 'photo_url',
    ],
    // Legacy English columns we still honour if present
    'category' => [
        'category', 'หมวด', 'หมวดหมู่', 'category_name',
    ],
    'list_price' => [
        'list_price', 'price', 'ราคา', 'ราคาขาย', 'ราคาเต็ม',
    ],
    'online_price' => [
        'online_price', 'sale_price', 'ราคา online', 'ราคาออนไลน์', 'ราคา_online',
    ],
    'stock' => [
        'stock', 'สต็อก', 'คงเหลือ', 'qty', 'quantity',
    ],
    'is_active' => [
        'is_active', 'active', 'เปิดขาย', 'เปิดใช้งาน', 'enabled',
    ],
];

/**
 * Normalize a raw CSV header cell to a canonical key, or null if unknown.
 */
function canonicalHeader(string $raw): ?string
{
    // Strip BOM, NBSP, trim, lowercase
    $h = preg_replace('/^\xEF\xBB\xBF/u', '', $raw);
    $h = str_replace("\xC2\xA0", ' ', (string) $h);
    $h = strtolower(trim((string) $h));
    if ($h === '') return null;

    foreach (HEADER_ALIASES as $canon => $aliases) {
        foreach ($aliases as $a) {
            if ($h === strtolower($a)) {
                return $canon;
            }
        }
    }
    return null;
}

// ─── action=template ──────────────────────────────────────────────────────────
if ($action === 'template') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="reya-products-template.csv"');
    echo "\xEF\xBB\xBF"; // BOM — Excel opens Thai correctly
    $out = fopen('php://output', 'w');

    fputcsv($out, array_values(TEMPLATE_HEADERS));

    // Two example rows in the exact xlsx template shape
    fputcsv($out, [
        'SKU001',
        'พาราเซตามอล 500 มก. ชนิดเม็ด',
        'PARACETAMOL 500MG TAB',
        'GPO',
        'แผง 10 เม็ด',
        'PARACETAMOL 500 MG',
        'แผง',
        '10 เม็ด',
        'ผู้ใหญ่ ครั้งละ 1-2 เม็ด ทุก 4-6 ชั่วโมง ไม่เกิน 8 เม็ด/วัน',
        'แก้ปวด ลดไข้',
        'https://example.com/uploads/paracetamol.jpg',
    ]);
    fputcsv($out, [
        'SKU002',
        'ซาร่า โคลด์ พีแอล ไซรัป รสองุ่น 60 มล.',
        'SARACOLD SYRUP GRAPE',
        'TNP',
        'รสองุ่น',
        'PARACETAMOL + CHLORPHENIRAMINE + PHENYLEPHRINE',
        'ขวด',
        '60 ML',
        'เด็ก 2-6 ปี: 1 ช้อนชา ทุก 4 ชม. / เด็ก 7-12 ปี: 1-2 ช้อนชา ทุก 4 ชม.',
        'บรรเทาอาการไข้หวัด คัดจมูก จาม',
        'https://example.com/uploads/saracold.jpg',
    ]);
    fclose($out);
    exit;
}

// ─── action=export ────────────────────────────────────────────────────────────
if ($action === 'export') {
    $stmt = $db->prepare(
        'SELECT bi.sku,
                bi.name,
                bi.name_en,
                bi.manufacturer,
                bi.dosage_form    AS variant,
                bi.generic_name,
                bi.unit,
                bi.strength       AS pack_size,
                bi.usage_instructions AS `usage`,
                bi.description,
                bi.image_url
         FROM business_items bi
         WHERE bi.line_account_id = ?
         ORDER BY bi.id ASC'
    );
    $stmt->execute([$lineAccountId]);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="reya-products-export-' . date('Y-m-d-Hi') . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, array_values(TEMPLATE_HEADERS));
    $keys = array_keys(TEMPLATE_HEADERS);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($out, array_map(static fn ($k) => (string) ($row[$k] ?? ''), $keys));
    }
    fclose($out);
    exit;
}

// ─── action=import ────────────────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');

if ($action !== 'import') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'unknown_action']);
    exit;
}

if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? 1) !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'no_file', 'message' => 'กรุณาเลือกไฟล์ CSV']);
    exit;
}
$tmp  = $_FILES['file']['tmp_name'];
$name = (string) $_FILES['file']['name'];
$ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
if (!in_array($ext, ['csv', 'txt'], true)) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => 'bad_ext',
        'message' => 'รับเฉพาะไฟล์ .csv — ถ้าเป็น Excel (.xlsx) กรุณา Save As → CSV (Comma delimited) ก่อน',
    ]);
    exit;
}

$mode = $_POST['mode'] ?? 'upsert'; // upsert | insert_only

// Open + strip BOM from first 3 bytes
if (($fh = fopen($tmp, 'r')) === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'read_fail']);
    exit;
}
$peek = fread($fh, 3);
if ($peek !== "\xEF\xBB\xBF") {
    rewind($fh);
}

$rawHeader = fgetcsv($fh);
if (!$rawHeader) {
    fclose($fh);
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'empty_header']);
    exit;
}

// Map each column position → canonical key (or null if we don't know that column)
$colMap = [];
foreach ($rawHeader as $i => $h) {
    $colMap[$i] = canonicalHeader((string) $h);
}
$canonicals = array_filter(array_values($colMap));

foreach (['sku', 'name'] as $req) {
    if (!in_array($req, $canonicals, true)) {
        fclose($fh);
        http_response_code(400);
        echo json_encode([
            'ok' => false,
            'error' => 'missing_column',
            'message' => "ไม่พบคอลัมน์ '{$req}' ในไฟล์ CSV — กรุณาดาวน์โหลด template ล่าสุดและใช้คอลัมน์ตามนั้น",
            'header_received' => $rawHeader,
            'header_recognized' => $canonicals,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// ─── Optional: resolve "category" → category_id via business_categories ──────
$categoryCache = []; // name (lower) => id
$resolveCategoryId = function (string $rawName) use ($db, $lineAccountId, &$categoryCache): ?int {
    $key = mb_strtolower(trim($rawName));
    if ($key === '') return null;
    if (array_key_exists($key, $categoryCache)) return $categoryCache[$key];

    try {
        $s = $db->prepare('SELECT id FROM business_categories WHERE line_account_id = ? AND LOWER(name) = ? LIMIT 1');
        $s->execute([$lineAccountId, $key]);
        $id = (int) ($s->fetchColumn() ?: 0);
        if ($id === 0) {
            $ins = $db->prepare('INSERT INTO business_categories (line_account_id, name, is_active, created_at) VALUES (?, ?, 1, NOW())');
            $ins->execute([$lineAccountId, trim($rawName)]);
            $id = (int) $db->lastInsertId();
        }
    } catch (\Throwable $e) {
        $id = 0;
    }
    $categoryCache[$key] = $id ?: null;
    return $categoryCache[$key];
};

// ─── Import loop ──────────────────────────────────────────────────────────────
$inserted = 0;
$updated  = 0;
$skipped  = 0;
$errors   = [];
$lineNo   = 1; // header was line 1

$BATCH_SIZE = 250;

$checkStmt = $db->prepare('SELECT id FROM business_items WHERE line_account_id = ? AND sku = ? LIMIT 1');

// Build dynamic INSERT/UPDATE per-row because we only want to touch columns
// the CSV actually provided (avoid clobbering existing data with empty strings).
$baseCols = [
    'sku', 'name', 'name_en', 'manufacturer', 'dosage_form', 'generic_name',
    'active_ingredient', 'unit', 'base_unit', 'strength', 'usage_instructions',
    'default_usage_text', 'description', 'image_url', 'photo_path',
    'category_id', 'price', 'sale_price', 'stock', 'is_active',
];

try {
    $db->beginTransaction();
    $batchRow = 0;

    while (($r = fgetcsv($fh)) !== false) {
        $lineNo++;

        // skip totally blank rows
        $nonBlank = false;
        foreach ($r as $cell) {
            if (trim((string) $cell) !== '') { $nonBlank = true; break; }
        }
        if (!$nonBlank) continue;

        // Map raw row → canonical-keyed values
        $rowVals = [];
        foreach ($colMap as $i => $canon) {
            if ($canon === null) continue;
            $rowVals[$canon] = trim((string) ($r[$i] ?? ''));
        }

        $sku = $rowVals['sku']  ?? '';
        $nm  = $rowVals['name'] ?? '';
        if ($sku === '' || $nm === '') {
            $skipped++;
            if (count($errors) < 50) {
                $errors[] = "บรรทัด {$lineNo}: ข้าม (sku หรือ name ว่าง)";
            }
            continue;
        }

        // Build set-fields list — only include keys the CSV actually provided
        $setData = ['name' => $nm];

        if (array_key_exists('name_en', $rowVals)) {
            $setData['name_en'] = $rowVals['name_en'] !== '' ? $rowVals['name_en'] : null;
        }
        if (array_key_exists('manufacturer', $rowVals)) {
            $setData['manufacturer'] = $rowVals['manufacturer'] !== '' ? $rowVals['manufacturer'] : null;
        }
        if (array_key_exists('variant', $rowVals)) {
            $setData['dosage_form'] = $rowVals['variant'] !== '' ? $rowVals['variant'] : null;
        }
        if (array_key_exists('generic_name', $rowVals)) {
            $g = $rowVals['generic_name'] !== '' ? $rowVals['generic_name'] : null;
            $setData['generic_name']      = $g;
            $setData['active_ingredient'] = $g; // mirror — they semantically overlap
        }
        if (array_key_exists('unit', $rowVals)) {
            $u = $rowVals['unit'] !== '' ? $rowVals['unit'] : null;
            $setData['unit']      = $u;
            $setData['base_unit'] = $u;
        }
        if (array_key_exists('pack_size', $rowVals)) {
            $setData['strength'] = $rowVals['pack_size'] !== '' ? $rowVals['pack_size'] : null;
        }
        if (array_key_exists('usage', $rowVals)) {
            $usg = $rowVals['usage'] !== '' ? $rowVals['usage'] : null;
            $setData['usage_instructions'] = $usg;
            $setData['default_usage_text'] = $usg;
        }
        if (array_key_exists('description', $rowVals)) {
            $setData['description'] = $rowVals['description'] !== '' ? $rowVals['description'] : null;
        }
        if (array_key_exists('image_url', $rowVals)) {
            $img = $rowVals['image_url'] !== '' ? $rowVals['image_url'] : null;
            $setData['image_url']  = $img;
            $setData['photo_path'] = $img;
        }
        if (array_key_exists('category', $rowVals) && $rowVals['category'] !== '') {
            $setData['category_id'] = $resolveCategoryId($rowVals['category']);
        }
        if (array_key_exists('list_price', $rowVals)) {
            $setData['price'] = $rowVals['list_price'] !== '' ? (float) $rowVals['list_price'] : 0.0;
        }
        if (array_key_exists('online_price', $rowVals)) {
            $setData['sale_price'] = $rowVals['online_price'] !== '' ? (float) $rowVals['online_price'] : null;
        }
        if (array_key_exists('stock', $rowVals)) {
            $setData['stock'] = $rowVals['stock'] !== '' ? (int) $rowVals['stock'] : 0;
        }
        if (array_key_exists('is_active', $rowVals)) {
            $setData['is_active'] = $rowVals['is_active'] === '' ? 1 : ((int) $rowVals['is_active'] ? 1 : 0);
        }

        // Lookup existing
        $checkStmt->execute([$lineAccountId, $sku]);
        $existingId = (int) ($checkStmt->fetchColumn() ?: 0);

        if ($existingId > 0) {
            if ($mode === 'insert_only') {
                $skipped++;
                continue;
            }
            $assignments = [];
            $params = [];
            foreach ($setData as $col => $val) {
                $assignments[] = "`{$col}` = ?";
                $params[] = $val;
            }
            $assignments[] = '`updated_at` = NOW()';
            $params[] = $existingId;

            $sql = 'UPDATE business_items SET ' . implode(', ', $assignments) . ' WHERE id = ?';
            $db->prepare($sql)->execute($params);
            $updated++;
        } else {
            // Insert: add sku/line_account_id, defaults for any required NOT NULL
            $setData['sku']             = $sku;
            $setData['line_account_id'] = $lineAccountId;
            if (!array_key_exists('price', $setData))     $setData['price']     = 0.0;
            if (!array_key_exists('stock', $setData))     $setData['stock']     = 0;
            if (!array_key_exists('is_active', $setData)) $setData['is_active'] = 1;

            $cols = array_keys($setData);
            $place = implode(', ', array_fill(0, count($cols), '?'));
            $sql = 'INSERT INTO business_items (`' . implode('`, `', $cols) . '`, created_at) VALUES (' . $place . ', NOW())';
            $db->prepare($sql)->execute(array_values($setData));
            $inserted++;
        }

        $batchRow++;
        if ($batchRow >= $BATCH_SIZE) {
            $db->commit();
            $db->beginTransaction();
            $batchRow = 0;
        }
    }

    if ($db->inTransaction()) {
        $db->commit();
    }
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('[inventory-csv] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'import_failed',
        'message' => $e->getMessage(),
        'inserted' => $inserted,
        'updated' => $updated,
        'skipped' => $skipped,
        'failed_at_line' => $lineNo,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
fclose($fh);

echo json_encode([
    'ok' => true,
    'inserted' => $inserted,
    'updated' => $updated,
    'skipped' => $skipped,
    'errors' => array_slice($errors, 0, 20),
    'total_processed' => $inserted + $updated + $skipped,
], JSON_UNESCAPED_UNICODE);
