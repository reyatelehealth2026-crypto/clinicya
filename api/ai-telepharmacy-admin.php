<?php
/**
 * AI Telepharmacy Admin API — JSON CRUD endpoint for admin settings page
 *
 * Tabs supported:
 *   - Tab 1: products (list, toggle ai_recommendable)
 *   - Tab 2: product_symptom_map (list/save/delete)
 *   - Tab 3: triage_questions + red_flag_symptoms (list/save/delete)
 *   - Tab 4: sandbox preview (read-only triage simulation)
 *
 * Single endpoint pattern: POST { action, ...params } → { success, data?, error? }
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$db = Database::getInstance()->getConnection();
$db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

// Auto-bootstrap tables ถ้ายังไม่มี (กันลืมรัน migration manually)
try {
    $db->exec(
        "CREATE TABLE IF NOT EXISTS `ai_knowledge_base` (
          `id` INT(11) NOT NULL AUTO_INCREMENT,
          `line_account_id` INT(11) DEFAULT NULL,
          `source` VARCHAR(150) NOT NULL,
          `title` VARCHAR(255) DEFAULT NULL,
          `heading_path` VARCHAR(500) DEFAULT NULL,
          `content` MEDIUMTEXT NOT NULL,
          `keywords` VARCHAR(1000) DEFAULT NULL,
          `condition_codes` VARCHAR(500) DEFAULT NULL,
          `priority` TINYINT(3) UNSIGNED NOT NULL DEFAULT 50,
          `is_active` TINYINT(1) NOT NULL DEFAULT 1,
          `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `idx_kb_account` (`line_account_id`),
          KEY `idx_kb_source` (`source`),
          KEY `idx_kb_active_priority` (`is_active`, `priority`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $db->exec(
        "CREATE TABLE IF NOT EXISTS `product_symptom_map` (
          `id` INT(11) NOT NULL AUTO_INCREMENT,
          `line_account_id` INT(11) DEFAULT NULL,
          `product_id` INT(11) NOT NULL,
          `symptom_code` VARCHAR(64) NOT NULL,
          `symptom_label_th` VARCHAR(255) DEFAULT NULL,
          `weight` TINYINT(3) UNSIGNED NOT NULL DEFAULT 50,
          `is_first_line` TINYINT(1) NOT NULL DEFAULT 0,
          `notes` TEXT DEFAULT NULL,
          `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uniq_account_product_symptom` (`line_account_id`, `product_id`, `symptom_code`),
          KEY `idx_psm_symptom` (`symptom_code`),
          KEY `idx_psm_product` (`product_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $db->exec(
        "CREATE TABLE IF NOT EXISTS `triage_questions` (
          `id` INT(11) NOT NULL AUTO_INCREMENT,
          `line_account_id` INT(11) DEFAULT NULL,
          `condition_code` VARCHAR(64) NOT NULL,
          `parent_question_id` INT(11) DEFAULT NULL,
          `question_th` TEXT NOT NULL,
          `question_en` TEXT DEFAULT NULL,
          `answer_type` ENUM('yes_no','scale_1_10','multi_choice') NOT NULL DEFAULT 'yes_no',
          `options_json` LONGTEXT DEFAULT NULL,
          `next_if_yes` INT(11) DEFAULT NULL,
          `next_if_no` INT(11) DEFAULT NULL,
          `red_flag_if_yes` TINYINT(1) NOT NULL DEFAULT 0,
          `recommend_symptom_codes` VARCHAR(500) DEFAULT NULL,
          `sort_order` INT(11) NOT NULL DEFAULT 0,
          `is_active` TINYINT(1) NOT NULL DEFAULT 1,
          `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `idx_tq_condition` (`condition_code`),
          KEY `idx_tq_active_order` (`is_active`, `sort_order`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $db->exec(
        "CREATE TABLE IF NOT EXISTS `triage_question_responses` (
          `id` INT(11) NOT NULL AUTO_INCREMENT,
          `triage_session_id` INT(11) NOT NULL,
          `question_id` INT(11) NOT NULL,
          `answer_value` VARCHAR(255) NOT NULL,
          `answer_text` TEXT DEFAULT NULL,
          `answered_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `idx_tqr_session` (`triage_session_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $db->exec(
        "CREATE TABLE IF NOT EXISTS `red_flag_symptoms` (
          `id` INT(11) NOT NULL AUTO_INCREMENT,
          `symptom_code` VARCHAR(50) NOT NULL,
          `symptom_name_th` VARCHAR(255) NOT NULL,
          `symptom_name_en` VARCHAR(255) DEFAULT NULL,
          `description` TEXT DEFAULT NULL,
          `severity` ENUM('critical','urgent','warning') DEFAULT 'warning',
          `action_required` TEXT DEFAULT NULL,
          `is_active` TINYINT(1) DEFAULT 1,
          `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `symptom_code` (`symptom_code`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $db->exec(
        "CREATE TABLE IF NOT EXISTS `triage_sessions` (
          `id` INT(11) NOT NULL AUTO_INCREMENT,
          `line_account_id` INT(11) DEFAULT NULL,
          `user_id` INT(11) NOT NULL,
          `current_state` VARCHAR(50) DEFAULT 'greeting',
          `triage_data` LONGTEXT DEFAULT NULL,
          `status` ENUM('active','completed','escalated','expired') DEFAULT 'active',
          `triage_level` ENUM('green','yellow','orange','red') NOT NULL DEFAULT 'green',
          `chief_complaint` TEXT DEFAULT NULL,
          `red_flags_detected` LONGTEXT DEFAULT NULL,
          `ai_recommendation` TEXT DEFAULT NULL,
          `outcome` ENUM('self_care','otc_recommended','refer_doctor','emergency') DEFAULT 'self_care',
          `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          `completed_at` TIMESTAMP NULL DEFAULT NULL,
          PRIMARY KEY (`id`),
          KEY `idx_triage_user` (`user_id`),
          KEY `idx_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    // ALTER business_items แบบ silent — ถ้าคอลัมน์มีอยู่แล้วจะ throw แล้ว catch ทิ้ง
    try { $db->exec("ALTER TABLE business_items ADD COLUMN ai_recommendable TINYINT(1) NOT NULL DEFAULT 1"); } catch (\Throwable $e) {}
} catch (\Throwable $e) {
    // bootstrap failed (e.g. permission) — actions ถัดไปจะ throw error ที่ชัดเจน
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$action = (string) ($input['action'] ?? '');
$lineAccountId = isset($input['line_account_id']) && is_numeric($input['line_account_id'])
    ? (int) $input['line_account_id'] : null;

$respond = static function (bool $success, array $extra = []): void {
    echo json_encode(array_merge(['success' => $success], $extra), JSON_UNESCAPED_UNICODE);
    exit;
};

try {
    switch ($action) {

        // ---------------------- Tab 1: Products ----------------------
        case 'list_products': {
            $search = trim((string) ($input['search'] ?? ''));
            $drugType = trim((string) ($input['drug_type'] ?? ''));
            $rxOnly = !empty($input['rx_only']) ? 1 : 0;
            $page = max(1, (int) ($input['page'] ?? 1));
            $perPage = min(200, max(10, (int) ($input['per_page'] ?? 50)));
            $offset = ($page - 1) * $perPage;

            $where = ['p.is_active = 1'];
            $params = [];
            if ($lineAccountId !== null) {
                $where[] = '(p.line_account_id = :acc OR p.line_account_id IS NULL)';
                $params[':acc'] = $lineAccountId;
            }
            if ($search !== '') {
                $where[] = '(p.name LIKE :q OR p.sku LIKE :q OR p.active_ingredient LIKE :q)';
                $params[':q'] = "%$search%";
            }
            if ($drugType !== '') {
                // tolerant — ใช้ drug_category ถ้ามี, ไม่งั้นข้าม filter
                try {
                    $check = $db->query("SHOW COLUMNS FROM business_items LIKE 'drug_category'");
                    if ($check && $check->rowCount() > 0) {
                        $where[] = 'p.drug_category = :dt';
                        $params[':dt'] = $drugType;
                    }
                } catch (\Throwable $e) {}
            }
            if ($rxOnly === 1) {
                $where[] = 'p.requires_prescription = 1';
            }

            $sqlBase = 'FROM business_items p WHERE ' . implode(' AND ', $where);
            $countStmt = $db->prepare("SELECT COUNT(*) $sqlBase");
            $countStmt->execute($params);
            $total = (int) $countStmt->fetchColumn();

            // ตรวจคอลัมน์ที่มีจริงเพื่อรองรับ schema เก่า/ใหม่
            $cols = [];
            try {
                $stmt = $db->query("SHOW COLUMNS FROM business_items");
                foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $c) { $cols[$c] = true; }
            } catch (\Throwable $e) { $cols = []; }
            $colExpr = static function (string $c, string $alias = '', string $default = "''") use ($cols): string {
                $a = $alias !== '' ? " AS `$alias`" : "";
                return isset($cols[$c]) ? "p.`$c`" . ($alias ? " AS `$alias`" : "") : "$default" . ($alias ? " AS `$alias`" : " AS `$c`");
            };

            $selectCols = "p.id, p.name, p.sku, p.price, p.sale_price, p.image_url, p.stock, "
                . $colExpr('drug_category', 'drug_type', "''") . ', '
                . $colExpr('requires_prescription', '', '0') . ', '
                . (isset($cols['is_prescription']) ? "p.`is_prescription`" : "0") . ' AS requires_pharmacist, '
                . $colExpr('active_ingredient', '', "''") . ', '
                . $colExpr('strength', '', "''") . ', '
                . $colExpr('dosage_form', '', "''") . ', '
                . (isset($cols['ai_recommendable']) ? "COALESCE(p.`ai_recommendable`, 1)" : "1") . ' AS ai_recommendable';

            $orderBy = isset($cols['is_featured']) ? 'COALESCE(p.is_featured,0) DESC, p.id DESC' : 'p.id DESC';

            $listStmt = $db->prepare(
                "SELECT $selectCols
                 $sqlBase
                 ORDER BY $orderBy
                 LIMIT $perPage OFFSET $offset"
            );
            $listStmt->execute($params);
            $rows = $listStmt->fetchAll(\PDO::FETCH_ASSOC);
            $respond(true, ['data' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage]);
        }

        case 'toggle_product_recommendable': {
            $productId = (int) ($input['product_id'] ?? 0);
            $recommendable = !empty($input['ai_recommendable']) ? 1 : 0;
            if ($productId <= 0) {
                $respond(false, ['error' => 'invalid product_id']);
            }
            // ถ้าคอลัมน์ ai_recommendable ยังไม่มี (migration ยังไม่รัน) → สร้างให้อัตโนมัติ
            try {
                $check = $db->query("SHOW COLUMNS FROM business_items LIKE 'ai_recommendable'");
                if (!$check || $check->rowCount() === 0) {
                    $db->exec("ALTER TABLE business_items ADD COLUMN ai_recommendable TINYINT(1) NOT NULL DEFAULT 1");
                }
            } catch (\Throwable $e) {}
            $stmt = $db->prepare("UPDATE business_items SET ai_recommendable = :r WHERE id = :id");
            $stmt->execute([':r' => $recommendable, ':id' => $productId]);
            $respond(true);
        }

        // ---------------------- Tab 2: Symptom Map ----------------------
        case 'list_symptom_codes': {
            $stmt = $db->query(
                "SELECT DISTINCT symptom_code, MAX(symptom_label_th) AS label
                 FROM product_symptom_map
                 GROUP BY symptom_code
                 ORDER BY symptom_code"
            );
            $codes = $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
            $respond(true, ['data' => $codes]);
        }

        case 'list_symptom_map': {
            $symptomCode = trim((string) ($input['symptom_code'] ?? ''));
            if ($symptomCode === '') {
                $respond(false, ['error' => 'symptom_code required']);
            }
            // tolerant: SELECT เฉพาะคอลัมน์ที่มีจริง
            $bcols = [];
            try {
                $stmt = $db->query("SHOW COLUMNS FROM business_items");
                foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $c) { $bcols[$c] = true; }
            } catch (\Throwable $e) { $bcols = []; }
            $extra = [];
            $extra[] = isset($bcols['drug_category']) ? 'p.drug_category AS drug_type' : "'' AS drug_type";
            $extra[] = isset($bcols['active_ingredient']) ? 'p.active_ingredient' : "'' AS active_ingredient";
            $extra[] = isset($bcols['strength']) ? 'p.strength' : "'' AS strength";
            $sql = "SELECT psm.id, psm.product_id, psm.symptom_code, psm.symptom_label_th,
                           psm.weight, psm.is_first_line, psm.notes,
                           p.name AS product_name, p.image_url, p.price, "
                    . implode(', ', $extra) . "
                    FROM product_symptom_map psm
                    INNER JOIN business_items p ON p.id = psm.product_id
                    WHERE psm.symptom_code = :sc
                      AND (psm.line_account_id <=> :acc OR psm.line_account_id IS NULL)
                    ORDER BY psm.is_first_line DESC, psm.weight DESC";
            $stmt = $db->prepare($sql);
            $stmt->execute([':sc' => $symptomCode, ':acc' => $lineAccountId]);
            $respond(true, ['data' => $stmt->fetchAll(\PDO::FETCH_ASSOC)]);
        }

        case 'save_symptom_map': {
            $productId = (int) ($input['product_id'] ?? 0);
            $symptomCode = trim((string) ($input['symptom_code'] ?? ''));
            $label = trim((string) ($input['symptom_label_th'] ?? ''));
            $weight = max(1, min(100, (int) ($input['weight'] ?? 50)));
            $firstLine = !empty($input['is_first_line']) ? 1 : 0;
            $notes = trim((string) ($input['notes'] ?? ''));

            if ($productId <= 0 || $symptomCode === '') {
                $respond(false, ['error' => 'product_id and symptom_code required']);
            }

            $stmt = $db->prepare(
                "INSERT INTO product_symptom_map
                   (line_account_id, product_id, symptom_code, symptom_label_th, weight, is_first_line, notes)
                 VALUES (:acc, :pid, :sc, :lbl, :w, :fl, :n)
                 ON DUPLICATE KEY UPDATE
                   symptom_label_th = VALUES(symptom_label_th),
                   weight = VALUES(weight),
                   is_first_line = VALUES(is_first_line),
                   notes = VALUES(notes),
                   updated_at = NOW()"
            );
            $stmt->execute([
                ':acc' => $lineAccountId,
                ':pid' => $productId,
                ':sc'  => $symptomCode,
                ':lbl' => $label !== '' ? $label : null,
                ':w'   => $weight,
                ':fl'  => $firstLine,
                ':n'   => $notes !== '' ? $notes : null,
            ]);
            $respond(true);
        }

        case 'delete_symptom_map': {
            $id = (int) ($input['id'] ?? 0);
            if ($id <= 0) {
                $respond(false, ['error' => 'invalid id']);
            }
            $stmt = $db->prepare("DELETE FROM product_symptom_map WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $respond(true);
        }

        // ---------------------- Tab 3a: Triage Questions ----------------------
        case 'list_triage_questions': {
            $conditionCode = trim((string) ($input['condition_code'] ?? ''));
            if ($conditionCode === '') {
                $stmt = $db->prepare(
                    "SELECT DISTINCT condition_code FROM triage_questions
                     WHERE (line_account_id <=> :acc OR line_account_id IS NULL)
                     ORDER BY condition_code"
                );
                $stmt->execute([':acc' => $lineAccountId]);
                $respond(true, ['conditions' => $stmt->fetchAll(\PDO::FETCH_COLUMN)]);
            }
            $stmt = $db->prepare(
                "SELECT * FROM triage_questions
                 WHERE condition_code = :cc
                   AND (line_account_id <=> :acc OR line_account_id IS NULL)
                 ORDER BY sort_order ASC, id ASC"
            );
            $stmt->execute([':cc' => $conditionCode, ':acc' => $lineAccountId]);
            $respond(true, ['data' => $stmt->fetchAll(\PDO::FETCH_ASSOC)]);
        }

        case 'save_triage_question': {
            $id = (int) ($input['id'] ?? 0);
            $cc = trim((string) ($input['condition_code'] ?? ''));
            $qth = trim((string) ($input['question_th'] ?? ''));
            $qen = trim((string) ($input['question_en'] ?? ''));
            $type = trim((string) ($input['answer_type'] ?? 'yes_no'));
            $nextYes = isset($input['next_if_yes']) && $input['next_if_yes'] !== '' ? (int) $input['next_if_yes'] : null;
            $nextNo = isset($input['next_if_no']) && $input['next_if_no'] !== '' ? (int) $input['next_if_no'] : null;
            $rfYes = !empty($input['red_flag_if_yes']) ? 1 : 0;
            $sympCodes = trim((string) ($input['recommend_symptom_codes'] ?? ''));
            $order = (int) ($input['sort_order'] ?? 0);
            $active = isset($input['is_active']) ? (int) !empty($input['is_active']) : 1;

            if ($cc === '' || $qth === '' || !in_array($type, ['yes_no', 'scale_1_10', 'multi_choice'], true)) {
                $respond(false, ['error' => 'invalid input']);
            }

            if ($id > 0) {
                $stmt = $db->prepare(
                    "UPDATE triage_questions SET
                        condition_code=:cc, question_th=:qth, question_en=:qen,
                        answer_type=:t, next_if_yes=:ny, next_if_no=:nn,
                        red_flag_if_yes=:rf, recommend_symptom_codes=:sc,
                        sort_order=:o, is_active=:a
                     WHERE id=:id"
                );
                $stmt->execute([
                    ':cc' => $cc, ':qth' => $qth, ':qen' => $qen ?: null,
                    ':t' => $type, ':ny' => $nextYes, ':nn' => $nextNo,
                    ':rf' => $rfYes, ':sc' => $sympCodes ?: null,
                    ':o' => $order, ':a' => $active, ':id' => $id,
                ]);
            } else {
                $stmt = $db->prepare(
                    "INSERT INTO triage_questions
                     (line_account_id, condition_code, question_th, question_en, answer_type,
                      next_if_yes, next_if_no, red_flag_if_yes, recommend_symptom_codes,
                      sort_order, is_active)
                     VALUES (:acc, :cc, :qth, :qen, :t, :ny, :nn, :rf, :sc, :o, :a)"
                );
                $stmt->execute([
                    ':acc' => $lineAccountId, ':cc' => $cc, ':qth' => $qth,
                    ':qen' => $qen ?: null, ':t' => $type,
                    ':ny' => $nextYes, ':nn' => $nextNo, ':rf' => $rfYes,
                    ':sc' => $sympCodes ?: null, ':o' => $order, ':a' => $active,
                ]);
                $id = (int) $db->lastInsertId();
            }
            $respond(true, ['id' => $id]);
        }

        case 'delete_triage_question': {
            $id = (int) ($input['id'] ?? 0);
            if ($id <= 0) {
                $respond(false, ['error' => 'invalid id']);
            }
            $stmt = $db->prepare("DELETE FROM triage_questions WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $respond(true);
        }

        // ---------------------- Tab 3b: Red Flag Symptoms ----------------------
        case 'list_red_flags': {
            $stmt = $db->query("SELECT * FROM red_flag_symptoms ORDER BY severity, id");
            $respond(true, ['data' => $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : []]);
        }

        case 'save_red_flag': {
            $id = (int) ($input['id'] ?? 0);
            $code = trim((string) ($input['symptom_code'] ?? ''));
            $thaiName = trim((string) ($input['symptom_name_th'] ?? ''));
            $enName = trim((string) ($input['symptom_name_en'] ?? ''));
            $desc = trim((string) ($input['description'] ?? ''));
            $sev = trim((string) ($input['severity'] ?? 'warning'));
            $action_required = trim((string) ($input['action_required'] ?? ''));
            $active = isset($input['is_active']) ? (int) !empty($input['is_active']) : 1;

            if ($code === '' || $thaiName === '' || !in_array($sev, ['critical', 'urgent', 'warning'], true)) {
                $respond(false, ['error' => 'invalid input']);
            }

            if ($id > 0) {
                $stmt = $db->prepare(
                    "UPDATE red_flag_symptoms SET
                        symptom_code=:c, symptom_name_th=:tn, symptom_name_en=:en,
                        description=:d, severity=:s, action_required=:a, is_active=:act
                     WHERE id=:id"
                );
                $stmt->execute([
                    ':c' => $code, ':tn' => $thaiName, ':en' => $enName ?: null,
                    ':d' => $desc ?: null, ':s' => $sev, ':a' => $action_required ?: null,
                    ':act' => $active, ':id' => $id,
                ]);
            } else {
                $stmt = $db->prepare(
                    "INSERT INTO red_flag_symptoms
                     (symptom_code, symptom_name_th, symptom_name_en, description,
                      severity, action_required, is_active)
                     VALUES (:c, :tn, :en, :d, :s, :a, :act)
                     ON DUPLICATE KEY UPDATE
                       symptom_name_th=VALUES(symptom_name_th),
                       severity=VALUES(severity),
                       is_active=VALUES(is_active)"
                );
                $stmt->execute([
                    ':c' => $code, ':tn' => $thaiName, ':en' => $enName ?: null,
                    ':d' => $desc ?: null, ':s' => $sev, ':a' => $action_required ?: null,
                    ':act' => $active,
                ]);
                $id = (int) $db->lastInsertId();
            }
            $respond(true, ['id' => $id]);
        }

        case 'delete_red_flag': {
            $id = (int) ($input['id'] ?? 0);
            if ($id <= 0) {
                $respond(false, ['error' => 'invalid id']);
            }
            $stmt = $db->prepare("DELETE FROM red_flag_symptoms WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $respond(true);
        }

        // ---------------------- Tab 4: Sandbox Preview ----------------------
        case 'sandbox_preview_recommendation': {
            $codesRaw = $input['symptom_codes'] ?? [];
            $codes = is_array($codesRaw) ? array_values(array_filter(array_map('strval', $codesRaw))) : [];
            if (empty($codes)) {
                $respond(false, ['error' => 'symptom_codes required']);
            }
            require_once __DIR__ . '/../modules/AIChat/Autoloader.php';
            if (function_exists('loadAIChatModule')) {
                loadAIChatModule();
            }
            $rec = new \Modules\AIChat\Services\ProductRecommender($db, []);
            $candidates = $rec->lookupCandidates($lineAccountId, $codes, 20);
            $respond(true, ['data' => array_slice($candidates, 0, 10)]);
        }

        // ---------------------- Tab 5: Knowledge Base (RAG) ----------------------
        case 'list_knowledge_sources': {
            $stmt = $db->prepare(
                "SELECT source, COUNT(*) AS chunks, MAX(updated_at) AS last_update
                 FROM ai_knowledge_base
                 WHERE (line_account_id <=> :acc OR line_account_id IS NULL)
                 GROUP BY source
                 ORDER BY source"
            );
            $stmt->execute([':acc' => $lineAccountId]);
            $respond(true, ['data' => $stmt->fetchAll(\PDO::FETCH_ASSOC)]);
        }

        case 'list_knowledge_chunks': {
            $source = trim((string) ($input['source'] ?? ''));
            $sql = "SELECT id, source, title, heading_path, LEFT(content, 200) AS preview,
                           keywords, condition_codes, priority, is_active, updated_at
                    FROM ai_knowledge_base
                    WHERE (line_account_id <=> :acc OR line_account_id IS NULL)";
            $params = [':acc' => $lineAccountId];
            if ($source !== '') {
                $sql .= ' AND source = :s';
                $params[':s'] = $source;
            }
            $sql .= ' ORDER BY source, id LIMIT 200';
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $respond(true, ['data' => $stmt->fetchAll(\PDO::FETCH_ASSOC)]);
        }

        case 'import_knowledge_md': {
            $docsDir = realpath(__DIR__ . '/../docs');
            $fxDir = realpath(__DIR__ . '/../data/aichat-knowledge');
            $allowed = [
                // [primary md filename, fallback .md.txt filename, source label]
                ['ระบบประเมินอาการเบื้องต้น.md', 'symptom_assessment.md.txt',  'ระบบประเมินอาการเบื้องต้น'],
                ['ข้อมูลโรค.md',                 'disease_info.md.txt',          'ข้อมูลโรค'],
                ['Thailand MIMS Clinical Guidelines.md', 'mims_guidelines.md.txt', 'Thailand MIMS Clinical Guidelines'],
            ];
            $resolveFile = static function (array $row) use ($docsDir, $fxDir): array {
                $primary = $docsDir ? ($docsDir . DIRECTORY_SEPARATOR . $row[0]) : '';
                if ($primary !== '' && is_file($primary)) {
                    return ['path' => $primary, 'source' => $row[2], 'label' => $row[0]];
                }
                $fb = $fxDir ? ($fxDir . DIRECTORY_SEPARATOR . $row[1]) : '';
                if ($fb !== '' && is_file($fb)) {
                    return ['path' => $fb, 'source' => $row[2], 'label' => $row[0] . ' (fixture)'];
                }
                return ['path' => '', 'source' => $row[2], 'label' => $row[0]];
            };
            require_once __DIR__ . '/../modules/AIChat/Autoloader.php';
            if (function_exists('loadAIChatModule')) {
                loadAIChatModule();
            }
            $retriever = new \Modules\AIChat\Services\KnowledgeRetriever($db);

            $relName = trim((string) ($input['filename'] ?? ''));
            if ($relName === '') {
                $totalChunks = 0;
                $report = [];
                foreach ($allowed as $row) {
                    $resolved = $resolveFile($row);
                    $path = $resolved['path'];
                    $exists = $path !== '' && is_file($path);
                    $size = $exists ? filesize($path) : 0;
                    $n = $exists ? $retriever->importMarkdownFile($lineAccountId, $path, $resolved['source']) : 0;
                    $totalChunks += $n;
                    $report[] = [
                        'file'       => $resolved['label'],
                        'source'     => $resolved['source'],
                        'chunks'     => $n,
                        'path'       => $path,
                        'exists'     => $exists,
                        'size_bytes' => $size,
                    ];
                }
                $respond(true, [
                    'data'          => $report,
                    'total_chunks'  => $totalChunks,
                    'docs_dir'      => $docsDir ?: '(not found)',
                    'fixture_dir'   => $fxDir ?: '(not found)',
                ]);
            }

            $matched = null;
            foreach ($allowed as $row) {
                if ($row[0] === $relName) { $matched = $row; break; }
            }
            if ($matched === null) {
                $respond(false, ['error' => 'filename not in allowlist']);
            }
            $resolved = $resolveFile($matched);
            $path = $resolved['path'];
            $exists = $path !== '' && is_file($path);
            $size = $exists ? filesize($path) : 0;
            $n = $exists ? $retriever->importMarkdownFile($lineAccountId, $path, $resolved['source']) : 0;
            $respond(true, [
                'chunks_imported' => $n,
                'path'            => $path,
                'exists'          => $exists,
                'size_bytes'      => $size,
                'source'          => $resolved['source'],
            ]);
        }

        case 'import_knowledge_paste': {
            $sourceLabel = trim((string) ($input['source'] ?? 'custom_paste'));
            $markdown = (string) ($input['markdown'] ?? '');
            if (mb_strlen(trim($markdown)) < 50) {
                $respond(false, ['error' => 'markdown too short (need >= 50 chars)']);
            }
            require_once __DIR__ . '/../modules/AIChat/Autoloader.php';
            if (function_exists('loadAIChatModule')) {
                loadAIChatModule();
            }
            $retriever = new \Modules\AIChat\Services\KnowledgeRetriever($db);
            $n = $retriever->importMarkdownText($lineAccountId, $markdown, $sourceLabel);
            $respond(true, ['chunks_imported' => $n, 'source' => $sourceLabel]);
        }

        case 'save_knowledge_chunk': {
            $id = (int) ($input['id'] ?? 0);
            $source = trim((string) ($input['source'] ?? 'custom'));
            $title = trim((string) ($input['title'] ?? ''));
            $headingPath = trim((string) ($input['heading_path'] ?? ''));
            $content = trim((string) ($input['content'] ?? ''));
            $keywords = trim((string) ($input['keywords'] ?? ''));
            $conditionCodes = trim((string) ($input['condition_codes'] ?? ''));
            $priority = max(1, min(100, (int) ($input['priority'] ?? 50)));
            $active = isset($input['is_active']) ? (int) !empty($input['is_active']) : 1;

            if ($content === '' || mb_strlen($content) < 20) {
                $respond(false, ['error' => 'content too short']);
            }

            if ($id > 0) {
                $stmt = $db->prepare(
                    "UPDATE ai_knowledge_base SET
                        source=:s, title=:t, heading_path=:hp, content=:c,
                        keywords=:k, condition_codes=:cc, priority=:p, is_active=:a
                     WHERE id=:id"
                );
                $stmt->execute([
                    ':s' => $source, ':t' => $title ?: null, ':hp' => $headingPath ?: null,
                    ':c' => $content, ':k' => $keywords ?: null,
                    ':cc' => $conditionCodes ?: null, ':p' => $priority,
                    ':a' => $active, ':id' => $id,
                ]);
            } else {
                $stmt = $db->prepare(
                    "INSERT INTO ai_knowledge_base
                     (line_account_id, source, title, heading_path, content, keywords, condition_codes, priority, is_active)
                     VALUES (:acc, :s, :t, :hp, :c, :k, :cc, :p, :a)"
                );
                $stmt->execute([
                    ':acc' => $lineAccountId, ':s' => $source,
                    ':t' => $title ?: null, ':hp' => $headingPath ?: null,
                    ':c' => $content, ':k' => $keywords ?: null,
                    ':cc' => $conditionCodes ?: null, ':p' => $priority, ':a' => $active,
                ]);
                $id = (int) $db->lastInsertId();
            }
            $respond(true, ['id' => $id]);
        }

        case 'delete_knowledge_chunk': {
            $id = (int) ($input['id'] ?? 0);
            if ($id <= 0) {
                $respond(false, ['error' => 'invalid id']);
            }
            $stmt = $db->prepare("DELETE FROM ai_knowledge_base WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $respond(true);
        }

        case 'sandbox_test_retrieve': {
            $q = trim((string) ($input['query'] ?? ''));
            if ($q === '') {
                $respond(false, ['error' => 'query required']);
            }
            require_once __DIR__ . '/../modules/AIChat/Autoloader.php';
            if (function_exists('loadAIChatModule')) {
                loadAIChatModule();
            }
            $mapper = new \Modules\AIChat\Services\SymptomMapper();
            $codes = $mapper->mapAllConditions($q);
            $retriever = new \Modules\AIChat\Services\KnowledgeRetriever($db);
            $chunks = $retriever->retrieve($lineAccountId, $q, $codes, 5);
            $respond(true, ['data' => $chunks, 'matched_conditions' => $codes]);
        }

        case 'sandbox_recent_sessions': {
            $stmt = $db->prepare(
                "SELECT id, user_id, current_state, status, triage_level, outcome,
                        chief_complaint, created_at, updated_at
                 FROM triage_sessions
                 WHERE (line_account_id <=> :acc)
                 ORDER BY id DESC LIMIT 20"
            );
            $stmt->execute([':acc' => $lineAccountId]);
            $respond(true, ['data' => $stmt->fetchAll(\PDO::FETCH_ASSOC)]);
        }

        default:
            $respond(false, ['error' => 'unknown action: ' . $action]);
    }
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'server_error',
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
