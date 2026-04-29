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

        // ---------------------- Seed default data ----------------------
        case 'seed_default_data': {
            $rfInsert = $db->prepare(
                "INSERT IGNORE INTO red_flag_symptoms
                 (symptom_code, symptom_name_th, symptom_name_en, description, severity, action_required, is_active)
                 VALUES (:c, :tn, :en, :d, :s, :a, 1)"
            );
            $redFlags = [
                // Existing 9 + 19 new (28 total)
                ['chest_pain_severe', 'เจ็บหน้าอกรุนแรง', 'Severe chest pain', 'อาจเป็นโรคหัวใจขาดเลือด', 'critical', 'โทร 1669 ทันที'],
                ['breathing_difficulty', 'หายใจไม่ออก/หายใจลำบาก', 'Difficulty breathing', 'ปัญหาทางเดินหายใจรุนแรง', 'critical', 'พบแพทย์ฉุกเฉินทันที'],
                ['stroke_signs', 'อาการสโตรก (FAST)', 'Stroke warning signs', 'ปากเบี้ยว/แขนอ่อนแรง/พูดไม่ชัด', 'critical', 'โทร 1669 ทันที — ทุกนาทีมีค่า'],
                ['severe_bleeding', 'เลือดออกไม่หยุด', 'Severe bleeding', 'เลือดออกผิดปกติไม่หยุด', 'critical', 'พบแพทย์ฉุกเฉินทันที'],
                ['high_fever_persistent', 'ไข้สูงเกิน 3 วัน', 'Persistent high fever', 'ไข้ > 39°C นานกว่า 3 วัน', 'urgent', 'ปรึกษาแพทย์ภายในวันนี้'],
                ['severe_dehydration', 'ขาดน้ำรุนแรง', 'Severe dehydration', 'ปัสสาวะน้อย ปากแห้งมาก', 'urgent', 'พบแพทย์ภายในวันนี้'],
                ['allergic_reaction_anaphylaxis', 'แพ้รุนแรง (anaphylaxis)', 'Anaphylactic reaction', 'ผื่นลาม + หายใจลำบาก', 'critical', 'โทร 1669 ทันที'],
                ['severe_abdominal_pain', 'ปวดท้องรุนแรง', 'Severe abdominal pain', 'ปวดบีบไม่หาย > 6 ชั่วโมง', 'urgent', 'พบแพทย์ภายในวันนี้'],
                ['blood_in_stool_vomit', 'ถ่าย/อาเจียนเป็นเลือด', 'Blood in stool or vomit', 'อุจจาระดำหรือมีเลือด', 'urgent', 'พบแพทย์ภายในวันนี้'],
                ['thunderclap_headache', 'ปวดหัวรุนแรงเฉียบพลัน', 'Thunderclap headache', 'ปวดหัวที่สุดในชีวิต รุนแรงเฉียบพลัน', 'critical', 'โทร 1669 ทันที — สงสัยเส้นเลือดในสมองแตก'],
                ['vision_loss_sudden', 'ตามองไม่เห็นเฉียบพลัน', 'Sudden vision loss', 'มองไม่เห็นทันทีหรือเห็นภาพซ้อนเฉียบพลัน', 'critical', 'พบจักษุแพทย์ฉุกเฉินทันที'],
                ['paralysis_sudden', 'อ่อนแรงครึ่งซีกเฉียบพลัน', 'Sudden hemiparesis', 'แขนหรือขาอ่อนแรงข้างเดียวเฉียบพลัน', 'critical', 'โทร 1669 ทันที (สโตรก)'],
                ['confusion_altered', 'สับสน/ซึมลง', 'Altered mental status', 'สับสนเฉียบพลันหรือซึมลงผิดปกติ', 'critical', 'พบแพทย์ฉุกเฉิน'],
                ['suicide_ideation', 'คิดทำร้ายตัวเอง', 'Suicidal ideation', 'มีความคิดฆ่าตัวตายหรือทำร้ายตัวเอง', 'critical', 'โทรสายด่วนสุขภาพจิต 1323 ทันที'],
                ['severe_burn', 'ไฟไหม้/น้ำร้อนลวกรุนแรง', 'Severe burn', 'แผลไหม้กว้างหรือลึกถึงชั้นไขมัน', 'urgent', 'พบแพทย์ฉุกเฉิน'],
                ['eye_injury', 'อุบัติเหตุที่ดวงตา', 'Eye trauma', 'มีของแข็ง/สารเคมีเข้าตา', 'urgent', 'ล้างตา + พบจักษุแพทย์ทันที'],
                ['neck_stiffness_fever', 'ไข้ + คอแข็ง', 'Fever with neck stiffness', 'ไข้สูงร่วมกับคอแข็ง อาจเป็นเยื่อหุ้มสมองอักเสบ', 'critical', 'พบแพทย์ฉุกเฉินทันที'],
                ['infant_high_fever', 'ทารก < 3 เดือน มีไข้', 'Fever in infant <3mo', 'ทารกอายุน้อยกว่า 3 เดือนมีไข้', 'urgent', 'พบแพทย์ทันที — ห้ามให้ยาลดไข้เอง'],
                ['pregnancy_bleeding', 'ตั้งครรภ์ + เลือดออก', 'Bleeding in pregnancy', 'มีเลือดออกผิดปกติระหว่างตั้งครรภ์', 'urgent', 'พบสูตินรีแพทย์ทันที'],
                ['severe_diarrhea_blood', 'ท้องเสียมีเลือด', 'Bloody diarrhea', 'ถ่ายเหลวมีเลือดปนมากกว่า 1 ครั้ง', 'urgent', 'พบแพทย์ภายในวันนี้'],
                ['persistent_vomiting', 'อาเจียนไม่หยุด', 'Persistent vomiting', 'อาเจียนเกิน 6 ครั้ง/วัน หรือไม่หยุด', 'urgent', 'พบแพทย์ภายในวันนี้'],
                ['throat_swelling_breathing', 'คอบวม + หายใจลำบาก', 'Throat swelling + dyspnea', 'อาการแพ้รุนแรงหรือติดเชื้อทางเดินหายใจ', 'critical', 'โทร 1669 ทันที'],
                ['asthma_severe_attack', 'หอบหืดกำเริบรุนแรง', 'Severe asthma attack', 'พ่นยาแล้วยังหอบ พูดไม่จบประโยค', 'critical', 'พบแพทย์ฉุกเฉินทันที'],
                ['testicular_torsion', 'ปวดอัณฑะเฉียบพลัน', 'Testicular pain acute', 'ปวดอัณฑะรุนแรงเฉียบพลัน อาจเป็น torsion', 'urgent', 'พบแพทย์ภายใน 6 ชั่วโมง'],
                ['back_pain_neuro', 'ปวดหลัง + ขาชา/อ่อนแรง', 'Back pain with neuro deficit', 'ปวดหลังร่วมกับขาอ่อนแรงหรือควบคุมปัสสาวะไม่ได้', 'urgent', 'พบแพทย์ทันที (cauda equina)'],
                ['jaundice_progressive', 'ตา/ตัวเหลือง', 'Progressive jaundice', 'ผิวหนังหรือตาขาวเหลืองชัดและเพิ่มขึ้น', 'urgent', 'พบแพทย์ภายใน 1-2 วัน'],
                ['dehydration_infant', 'ทารกขาดน้ำ', 'Infant dehydration', 'ทารกซึม ปัสสาวะน้อย กระหม่อมยุบ', 'urgent', 'พบแพทย์ทันที'],
                ['mouth_swelling_anaphylaxis', 'ปาก/ลิ้นบวม', 'Tongue/lip swelling', 'ปากหรือลิ้นบวมเฉียบพลัน อาจเป็น anaphylaxis', 'critical', 'โทร 1669 ทันที'],
            ];
            $rfCount = 0;
            foreach ($redFlags as $rf) {
                $rfInsert->execute([
                    ':c' => $rf[0], ':tn' => $rf[1], ':en' => $rf[2],
                    ':d' => $rf[3], ':s' => $rf[4], ':a' => $rf[5],
                ]);
                $rfCount += $rfInsert->rowCount();
            }

            // Triage questions: 22 conditions x 4-7 questions
            $tqInsert = $db->prepare(
                "INSERT IGNORE INTO triage_questions
                 (line_account_id, condition_code, question_th, question_en, answer_type, red_flag_if_yes, recommend_symptom_codes, sort_order, is_active)
                 VALUES (NULL, :cc, :qth, :qen, 'yes_no', :rf, :sc, :so, 1)"
            );
            $triageData = [
                // [condition_code, [[question_th, question_en, red_flag, symptom_codes_csv], ...]]
                ['common_cold', [
                    ['มีไข้สูงเกิน 38.5°C ไหม?', 'Fever above 38.5C?', 0, 'fever'],
                    ['หายใจลำบากหรือเหนื่อยผิดปกติไหม?', 'Difficulty breathing?', 1, ''],
                    ['มีน้ำมูกใส/คัดจมูกไหม?', 'Runny/stuffy nose?', 0, 'runny_nose'],
                    ['ไอแห้งหรือไอมีเสมหะไหม?', 'Cough?', 0, 'cough'],
                    ['เจ็บคอหรือกลืนเจ็บไหม?', 'Sore throat?', 0, 'sore_throat'],
                    ['ปวดเมื่อยตัวไหม?', 'Body aches?', 0, 'body_ache'],
                ]],
                ['allergy', [
                    ['ผื่นลามทั่วตัว + หายใจลำบาก/ปากบวม?', 'Hives + dyspnea?', 1, ''],
                    ['จาม/คัดจมูก/น้ำมูกไหลซ้ำๆ?', 'Sneezing/rhinitis?', 0, 'allergy_rhinitis'],
                    ['คันตา/ตาแดง/น้ำตาไหล?', 'Itchy eyes?', 0, 'eye_allergy'],
                    ['ผื่นคัน/ลมพิษ?', 'Hives?', 0, 'urticaria'],
                    ['อาการเกิดหลังสัมผัสอาหาร/ฝุ่น/สัตว์เลี้ยง?', 'Food/dust/pet trigger?', 0, ''],
                ]],
                ['headache', [
                    ['ปวดหัวรุนแรงที่สุดในชีวิตหรือเฉียบพลันมาก?', 'Worst-ever headache?', 1, ''],
                    ['มีไข้ + คอแข็ง + อาเจียน?', 'Fever + neck stiffness?', 1, ''],
                    ['ปวดข้างเดียว + เห็นแสงเป็นเส้น?', 'One-sided + aura?', 0, 'migraine'],
                    ['ปวดตื้อๆ ทั่วศีรษะ/บีบรัด?', 'Tension headache?', 0, 'headache'],
                    ['นอนน้อย/เครียด/พักผ่อนไม่พอ?', 'Sleep deprived?', 0, ''],
                    ['ปวดหลังตา?', 'Pain behind eyes?', 0, 'sinus_headache'],
                ]],
                ['gi', [
                    ['ถ่ายเป็นเลือด/อาเจียนเป็นเลือด?', 'Blood in stool/vomit?', 1, ''],
                    ['ปวดท้องรุนแรงไม่หายเกิน 6 ชั่วโมง?', 'Severe pain >6h?', 1, ''],
                    ['ท้องเสียถ่ายเหลวเกิน 3 ครั้ง/วัน?', 'Diarrhea >3x/day?', 0, 'diarrhea'],
                    ['คลื่นไส้/อาเจียน?', 'Nausea/vomiting?', 0, 'nausea'],
                    ['ท้องอืด/แน่นท้อง?', 'Bloating?', 0, 'bloating'],
                    ['มีไข้ร่วมไหม?', 'Fever?', 0, 'fever'],
                    ['ปวดท้องน้อยข้างขวา?', 'RLQ pain?', 1, ''],
                ]],
                ['skin_rash', [
                    ['ผื่นลามรวดเร็ว + ปากบวม/หายใจลำบาก?', 'Spreading + dyspnea?', 1, ''],
                    ['ผื่นคัน + ตุ่มน้ำใส?', 'Itchy + vesicles?', 0, 'eczema'],
                    ['ผิวหนังแดง/ลอก/แห้ง?', 'Red/scaly?', 0, 'dermatitis'],
                    ['มีหนองหรือบาดแผลติดเชื้อ?', 'Pus/infected?', 0, 'skin_infection'],
                    ['ผื่นแพ้สัมผัสจากเครื่องสำอาง/โลหะ?', 'Contact allergy?', 0, 'contact_dermatitis'],
                ]],
                ['insomnia', [
                    ['มีความคิดทำร้ายตัวเอง?', 'Self-harm thoughts?', 1, ''],
                    ['นอนไม่หลับติดต่อกันเกิน 2 สัปดาห์?', '>2 weeks insomnia?', 0, 'chronic_insomnia'],
                    ['หลับยาก/ตื่นกลางดึกบ่อย?', 'Sleep problem?', 0, 'insomnia'],
                    ['มีความเครียด/วิตกกังวลร่วม?', 'Anxiety?', 0, 'anxiety'],
                    ['ดื่มกาแฟ/เครื่องดื่มชูกำลังบ่อย?', 'Caffeine?', 0, ''],
                ]],
                ['cough', [
                    ['ไอเป็นเลือด/เหนื่อยมาก?', 'Hemoptysis/SOB?', 1, ''],
                    ['ไอแห้งไม่มีเสมหะ?', 'Dry cough?', 0, 'cough_dry'],
                    ['ไอมีเสมหะเหลือง/เขียว?', 'Productive yellow/green?', 0, 'cough_productive'],
                    ['ไอมานานกว่า 2 สัปดาห์?', '>2 weeks?', 0, 'cough_chronic'],
                    ['ไอตอนกลางคืน?', 'Night cough?', 0, 'asthma'],
                ]],
                ['fever', [
                    ['ไข้สูงเกิน 39.5°C หรือชัก?', 'Fever >39.5C or seizure?', 1, ''],
                    ['ไข้ติดต่อกันเกิน 3 วัน?', '>3 days?', 1, ''],
                    ['ปวดหัว/ปวดเมื่อยตัว?', 'Headache/myalgia?', 0, 'fever'],
                    ['มีผื่น/จุดแดง (สงสัยไข้เลือดออก)?', 'Petechiae?', 1, ''],
                    ['มีอาการเฉพาะที่ใดเป็นพิเศษ?', 'Localized?', 0, ''],
                ]],
                ['pain_general', [
                    ['ปวดรุนแรงระดับ 8-10/10?', 'Severe 8-10/10?', 1, ''],
                    ['ปวดข้อ/กล้ามเนื้อจากการเคลื่อนไหว?', 'Joint/muscle?', 0, 'musculoskeletal_pain'],
                    ['ปวดเรื้อรังเกิน 1 เดือน?', '>1 month?', 0, 'chronic_pain'],
                ]],
                ['sore_throat', [
                    ['คอบวมจนหายใจลำบาก?', 'Throat swelling + dyspnea?', 1, ''],
                    ['เจ็บคอ + ไข้สูง > 38.5?', 'Sore throat + high fever?', 0, 'streptococcal_pharyngitis'],
                    ['คอแดง มีจุดขาว?', 'White patches?', 0, 'pharyngitis'],
                    ['เสียงแหบมานานเกิน 2 สัปดาห์?', 'Hoarseness >2w?', 1, ''],
                    ['ต่อมน้ำเหลืองที่คอบวม?', 'Cervical lymphadenopathy?', 0, 'pharyngitis'],
                ]],
                ['eye_irritation', [
                    ['การมองเห็นลดลงเฉียบพลัน?', 'Sudden vision loss?', 1, ''],
                    ['มีของเข้าตาหรือกระแทก?', 'Foreign body/trauma?', 1, ''],
                    ['คันตา/ตาแดง/น้ำตาไหล?', 'Itchy/red/tearing?', 0, 'eye_allergy'],
                    ['ตาแห้ง/แสบตา?', 'Dry eye?', 0, 'dry_eye'],
                    ['มีขี้ตาเขียว/เหลือง?', 'Purulent discharge?', 0, 'conjunctivitis'],
                ]],
                ['ear_pain', [
                    ['ปวดหูรุนแรง + ไข้สูง + คอแข็ง?', 'Ear pain + fever + neck stiffness?', 1, ''],
                    ['การได้ยินลดลง?', 'Hearing loss?', 0, 'otitis_media'],
                    ['มีน้ำหนองออกจากหู?', 'Ear discharge?', 0, 'ear_infection'],
                    ['คันในรูหู?', 'Itchy ear?', 0, 'otitis_externa'],
                ]],
                ['menstrual_pain', [
                    ['เลือดออกมากผิดปกติจนหน้ามืด?', 'Heavy bleeding + faint?', 1, ''],
                    ['ปวดท้องน้อยเกี่ยวกับรอบเดือน?', 'Cyclic pelvic pain?', 0, 'dysmenorrhea'],
                    ['ปวดร้าวลงต้นขา?', 'Radiating pain?', 0, 'dysmenorrhea'],
                ]],
                ['constipation', [
                    ['ถ่ายเป็นเลือดสด?', 'Fresh blood?', 1, ''],
                    ['ไม่ถ่ายเกิน 3 วัน + ปวดท้อง?', '>3d + abdo pain?', 0, 'constipation'],
                    ['อุจจาระแข็ง/ก้อนเล็ก?', 'Hard stool?', 0, 'constipation'],
                    ['น้ำหนักลดผิดปกติ?', 'Weight loss?', 1, ''],
                ]],
                ['hemorrhoids', [
                    ['เลือดออกทางทวารมาก?', 'Heavy rectal bleeding?', 1, ''],
                    ['ก้อนยื่นออกมาทางทวาร?', 'Prolapse?', 0, 'hemorrhoids'],
                    ['คันรอบทวาร?', 'Anal itching?', 0, 'hemorrhoids'],
                ]],
                ['muscle_pain', [
                    ['ปวดร่วมกับชา/อ่อนแรง?', 'Pain + numbness?', 1, ''],
                    ['ปวดหลังจากออกกำลังกาย?', 'Post-exercise?', 0, 'musculoskeletal_pain'],
                    ['ปวดตอนกลางคืน?', 'Night pain?', 0, 'musculoskeletal_pain'],
                ]],
                ['burn_minor', [
                    ['แผลไหม้ใหญ่กว่าฝ่ามือ?', 'Larger than palm?', 1, ''],
                    ['ผิวพอง + แดง?', 'Blister + redness?', 0, 'burn_minor'],
                    ['ไหม้ที่ใบหน้า/ข้อต่อ?', 'Face/joint burn?', 1, ''],
                ]],
                ['motion_sickness', [
                    ['อาเจียนไม่หยุด > 6 ครั้ง?', 'Persistent vomiting?', 1, ''],
                    ['คลื่นไส้เวลาเดินทาง?', 'Travel-induced nausea?', 0, 'motion_sickness'],
                    ['เวียนศีรษะเป็นพัก ๆ?', 'Vertigo?', 0, 'vertigo'],
                ]],
                ['indigestion', [
                    ['ปวดอกร้าวไปแขนซ้าย/กราม?', 'Radiating chest pain?', 1, ''],
                    ['แสบยอดอก/เรอเปรี้ยว?', 'Heartburn?', 0, 'indigestion'],
                    ['ท้องอืด/แน่นท้องหลังกินอาหาร?', 'Postprandial bloating?', 0, 'indigestion'],
                ]],
                ['athlete_foot', [
                    ['ผิวเปื่อย + เลือดออก/ติดเชื้อ?', 'Macerated + infected?', 1, ''],
                    ['คันระหว่างนิ้วเท้า/ผิวลอก?', 'Itchy between toes?', 0, 'athlete_foot'],
                ]],
                ['mouth_ulcer', [
                    ['แผลไม่หาย > 3 สัปดาห์?', 'Non-healing >3w?', 1, ''],
                    ['แผลในปากเจ็บ?', 'Painful ulcer?', 0, 'mouth_ulcer'],
                    ['น้ำหนักลด/กลืนลำบาก?', 'Weight loss/dysphagia?', 1, ''],
                ]],
                ['acne', [
                    ['ผื่นแดง + ไข้?', 'Rash + fever?', 1, ''],
                    ['สิวเล็กหัวขาว/หัวดำ?', 'Comedones?', 0, 'acne'],
                    ['สิวอักเสบหนอง?', 'Pustular acne?', 0, 'acne_inflammatory'],
                ]],
            ];
            $tqCount = 0;
            foreach ($triageData as $cond) {
                [$cc, $questions] = $cond;
                $so = 1;
                foreach ($questions as $q) {
                    $tqInsert->execute([
                        ':cc' => $cc,
                        ':qth' => $q[0],
                        ':qen' => $q[1] ?: null,
                        ':rf' => $q[2],
                        ':sc' => $q[3] ?: null,
                        ':so' => $so++,
                    ]);
                    $tqCount += $tqInsert->rowCount();
                }
            }

            // Auto-classify business_items.ai_recommendable ตามกฎหมายไทย
            // ใช้ category name + cny_code + product name (ไม่พึ่ง drug_type ที่อาจไม่ได้ตั้งค่า)
            //
            // ALLOW (ยาสามัญประจำบ้าน + อาหารเสริม + ทาภายนอก):
            //   - วิตามิน/อาหารเสริม (VIT, supplement)
            //   - ทาฆ่าเชื้อแผล/topical antiseptic (SKI ทา)
            //   - ลดไข้/แก้ปวด พาราเซตามอล (ที่ไม่ใช่ NSAIDs ขนาดสูง)
            //   - ยาแก้แพ้ ลมพิษ (chlorpheniramine, loratadine, cetirizine)
            //   - ยาแก้หวัด/ไอ (OTC)
            //   - ผงเกลือแร่/ORS
            //   - ยาอม/อมเจ็บคอ
            //   - ยาขับลม/ลดกรด/ระบาย
            //   - ยาดม/ยาหม่อง
            //   - เครื่องมือแพทย์ทั่วไป (ผ้าพันแผล, แอลกอฮอล์, betadine)
            //
            // BLOCK (ต้องเภสัชกรจ่าย/ใบสั่งแพทย์):
            //   - ยาปฏิชีวนะกิน (antibiotic oral)
            //   - ยาหัวใจ/ความดัน/เบาหวาน
            //   - ยานอนหลับ/ระงับประสาท
            //   - สเตียรอยด์ที่ใช้ภายใน
            //   - ยาคุม
            //   - ยาควบคุมพิเศษ (controlled)
            $aiRecommendUpdated = 0;
            try {
                $bcols = [];
                $stmt = $db->query("SHOW COLUMNS FROM business_items");
                foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $c) { $bcols[$c] = true; }
                if (!isset($bcols['ai_recommendable'])) {
                    $db->exec("ALTER TABLE business_items ADD COLUMN ai_recommendable TINYINT(1) NOT NULL DEFAULT 1");
                }

                // ตรวจว่ามีตาราง item_categories ไหม
                $hasItemCat = false;
                try {
                    $db->query("SELECT 1 FROM item_categories LIMIT 1");
                    $hasItemCat = true;
                } catch (\Throwable $e) {}

                $rxCol = isset($bcols['requires_prescription']) ? 'COALESCE(bi.requires_prescription,0)' : '0';
                $isRxCol = isset($bcols['is_prescription']) ? 'COALESCE(bi.is_prescription,0)' : '0';

                // keyword sets
                $blockKws = [
                    'ปฏิชีวนะ', 'antibiotic', 'amoxicillin', 'cephalexin', 'azithromycin',
                    'ยาฆ่าเชื้อ', 'ฆ่าเชื้อในลำคอ', 'ยากิน',
                    'หัวใจ', 'cardiac', 'aspirin 81', 'amlodipine', 'losartan', 'enalapril',
                    'ความดัน', 'hypertension', 'เบาหวาน', 'metformin', 'glipizide', 'insulin',
                    'นอนหลับ', 'diazepam', 'alprazolam', 'lorazepam', 'sedat',
                    'สเตียรอยด์', 'prednisolone', 'dexamethasone',
                    'ยาคุม', 'contracept', 'ethinyl',
                    'ควบคุมพิเศษ', 'controlled', 'morphine', 'tramadol',
                    'warfarin', 'heparin',
                    'ฉีด', 'injection',
                ];
                $allowKws = [
                    'วิตามิน', 'vitamin', 'อาหารเสริม', 'supplement', 'collagen', 'calcium', 'omega',
                    'พาราเซตา', 'paracetamol', 'tylenol', 'ลดไข้', 'แก้ปวด',
                    'ทา', 'ครีม', 'cream', 'ointment', 'gel', 'ยาทา', 'topical',
                    'ฆ่าเชื้อแผล', 'antiseptic', 'betadine', 'แอลกอฮอล์', 'alcohol', 'iodine',
                    'แผล', 'ผ้าพันแผล', 'plaster', 'gauze',
                    'ยาดม', 'ยาหม่อง', 'balm', 'inhaler nasal', 'menthol',
                    'แก้หวัด', 'แก้ไอ', 'cough', 'dextromethorphan', 'guaifenesin',
                    'แก้แพ้', 'antihistamine', 'chlorpheniramine', 'loratadine', 'cetirizine',
                    'ลมพิษ', 'ผื่น',
                    'ผงเกลือแร่', 'ors', 'electrolyte',
                    'ระบาย', 'laxative', 'lactulose', 'fiber',
                    'ขับลม', 'antacid', 'ลดกรด', 'simethicone', 'eno', 'gas',
                    'อมเจ็บคอ', 'lozenge', 'strepsils', 'ยาอม',
                    'น้ำเกลือ', 'saline',
                    'ยาสีฟัน', 'ยาสระผม', 'shampoo', 'soap', 'สบู่',
                    'ตา', 'eye drop', 'น้ำตาเทียม',
                    'ทาแก้คัน', 'แก้คัน',
                    'ทาแก้สิว', 'สิว', 'acne', 'benzoyl',
                    'น้ำมัน', 'fish oil',
                ];

                $blockOr = [];
                $allowOr = [];
                $bind = [];
                foreach ($blockKws as $i => $k) {
                    $bind[":bk$i"] = '%' . $k . '%';
                    $blockOr[] = "LOWER(name_combo) LIKE LOWER(:bk$i)";
                }
                foreach ($allowKws as $i => $k) {
                    $bind[":ak$i"] = '%' . $k . '%';
                    $allowOr[] = "LOWER(name_combo) LIKE LOWER(:ak$i)";
                }

                $catJoin = $hasItemCat
                    ? "LEFT JOIN item_categories ic ON bi.category_id = ic.id"
                    : "";
                $catNameExpr = $hasItemCat
                    ? "CONCAT_WS(' ', COALESCE(ic.name,''), COALESCE(ic.cny_code,''), COALESCE(ic.name_en,''))"
                    : "''";

                // build name_combo = category text + product name + active_ingredient (ถ้ามี)
                $aiCol = isset($bcols['active_ingredient']) ? 'COALESCE(bi.active_ingredient,\'\')' : "''";
                $nameComboExpr = "CONCAT_WS(' ', $catNameExpr, COALESCE(bi.name,''), $aiCol)";

                // Strategy: UPDATE ในรอบเดียวด้วย CASE
                $sql = "UPDATE business_items bi
                        $catJoin
                        SET bi.ai_recommendable = CASE
                            WHEN $rxCol = 1 THEN 0
                            WHEN $isRxCol = 1 THEN 0
                            WHEN ("
                                . implode(' OR ', array_map(static fn($e) => str_replace('name_combo', $nameComboExpr, $e), $blockOr))
                                . ") THEN 0
                            WHEN ("
                                . implode(' OR ', array_map(static fn($e) => str_replace('name_combo', $nameComboExpr, $e), $allowOr))
                                . ") THEN 1
                            ELSE 0
                        END
                        WHERE bi.is_active = 1";

                $stmt = $db->prepare($sql);
                foreach ($bind as $k => $v) {
                    $stmt->bindValue($k, $v, \PDO::PARAM_STR);
                }
                $stmt->execute();
                $aiRecommendUpdated = $stmt->rowCount();
            } catch (\Throwable $e) {
                error_log('seed ai_recommendable update error: ' . $e->getMessage());
            }

            // Sample symptom map: เพิ่ม mapping ตัวอย่างถ้ายังไม่มี
            $smCount = 0;
            try {
                // Symptom map: รัน INSERT IGNORE ทุกครั้ง (idempotent ผ่าน UNIQUE KEY)
                // ค้นจาก category name + product name + active_ingredient
                $hasItemCat = false;
                try { $db->query("SELECT 1 FROM item_categories LIMIT 1"); $hasItemCat = true; } catch (\Throwable $e) {}

                $matches = [
                    'fever'           => ['paracetamol', 'พาราเซตามอล', 'ลดไข้', 'tylenol', 'sara'],
                    'cough'           => ['dextromethorphan', 'ระงับไอ', 'แก้ไอ', 'guaifenesin'],
                    'runny_nose'      => ['chlorpheniramine', 'แก้แพ้', 'น้ำมูก', 'cpm'],
                    'sore_throat'     => ['อม', 'เจ็บคอ', 'strepsils', 'lozenge', 'difflam'],
                    'allergy_rhinitis'=> ['loratadine', 'cetirizine', 'แก้แพ้', 'แอนตี้ฮีสต'],
                    'diarrhea'        => ['loperamide', 'ท้องเสีย', 'ผงเกลือแร่', 'ors', 'อิเล็กโทรไลต์'],
                    'indigestion'     => ['antacid', 'ลดกรด', 'ขับลม', 'simethicone', 'eno'],
                    'headache'        => ['paracetamol', 'พาราเซตามอล', 'ปวดหัว'],
                    'musculoskeletal_pain' => ['ยาทาแก้ปวด', 'counterpain', 'salonpas', 'capsaicin', 'น้ำมันมวย'],
                    'mouth_ulcer'     => ['kenalog', 'แผลในปาก', 'sm-33', 'borax'],
                    'constipation'    => ['ยาระบาย', 'fiber', 'lactulose', 'senna', 'bisacodyl'],
                    'motion_sickness' => ['dimenhydrinate', 'แก้เมา', 'ดรามามีน'],
                    'eye_allergy'     => ['น้ำตาเทียม', 'eye drop', 'ตา'],
                    'skin_infection'  => ['ฆ่าเชื้อแผล', 'betadine', 'iodine', 'antiseptic', 'แอลกอฮอล์'],
                    'urticaria'       => ['ทาแก้คัน', 'calamine'],
                    'dermatitis'      => ['ครีม', 'cream', 'lotion', 'โลชั่น'],
                    'acne'            => ['สิว', 'acne', 'benzoyl'],
                    'athlete_foot'    => ['เชื้อรา', 'antifungal', 'clotrimazole', 'miconazole'],
                    'burn_minor'      => ['silver sulfadiazine', 'แผลไหม้', 'burn'],
                    'eczema'          => ['eczema', 'ผื่น'],
                    'insomnia'        => ['valerian', 'melatonin'],
                    'body_ache'       => ['paracetamol', 'แก้ปวด', 'ปวดเมื่อย'],
                    'nausea'          => ['domperidone', 'แก้คลื่นไส้'],
                ];

                $smInsert = $db->prepare(
                    "INSERT IGNORE INTO product_symptom_map
                     (line_account_id, product_id, symptom_code, symptom_label_th, weight, is_first_line)
                     VALUES (NULL, :pid, :sc, :lb, :w, :fl)"
                );

                $catJoin = $hasItemCat ? "LEFT JOIN item_categories ic ON bi.category_id = ic.id" : "";
                $catFields = $hasItemCat ? "CONCAT_WS(' ', COALESCE(ic.name,''), COALESCE(ic.cny_code,''))" : "''";
                $aiCol = "COALESCE(bi.active_ingredient,'')";

                foreach ($matches as $code => $kws) {
                    $orParts = [];
                    $bind = [];
                    foreach ($kws as $i => $kw) {
                        $bind[":k$i"] = "%$kw%";
                        $orParts[] = "(LOWER(bi.name) LIKE LOWER(:k$i)"
                            . " OR LOWER($aiCol) LIKE LOWER(:k$i)"
                            . " OR LOWER($catFields) LIKE LOWER(:k$i))";
                    }
                    $sql2 = "SELECT bi.id FROM business_items bi $catJoin
                             WHERE COALESCE(bi.ai_recommendable,1)=1
                               AND bi.is_active=1
                               AND (" . implode(' OR ', $orParts) . ")
                             LIMIT 5";
                    try {
                        $stmt2 = $db->prepare($sql2);
                        foreach ($bind as $k => $v) { $stmt2->bindValue($k, $v, \PDO::PARAM_STR); }
                        $stmt2->execute();
                        $ids = $stmt2->fetchAll(\PDO::FETCH_COLUMN);
                        $idx = 0;
                        foreach ($ids as $pid) {
                            $smInsert->execute([
                                ':pid' => (int) $pid,
                                ':sc'  => $code,
                                ':lb'  => null,
                                ':w'   => 100 - ($idx * 10),
                                ':fl'  => $idx === 0 ? 1 : 0,
                            ]);
                            $smCount += $smInsert->rowCount();
                            $idx++;
                        }
                    } catch (\Throwable $e) {
                        error_log("seed map for $code error: " . $e->getMessage());
                    }
                }
            } catch (\Throwable $e) {
                error_log('seed symptom map error: ' . $e->getMessage());
            }

            $respond(true, [
                'red_flags_inserted'         => $rfCount,
                'triage_questions_inserted'  => $tqCount,
                'symptom_map_inserted'       => $smCount,
                'business_items_classified'  => $aiRecommendUpdated,
                'note' => 'AI แนะนำได้ = เฉพาะ household/otc/traditional ที่ไม่ต้องใบสั่งแพทย์ — ตามกฎหมายไทย',
            ]);
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
            // diagnostic: count rows in KB to detect import failures
            $kbCount = 0;
            $kbCountByAcc = 0;
            try {
                $stmt = $db->query("SELECT COUNT(*) FROM ai_knowledge_base WHERE is_active=1");
                $kbCount = (int) $stmt->fetchColumn();
                if ($lineAccountId !== null) {
                    $stmt2 = $db->prepare("SELECT COUNT(*) FROM ai_knowledge_base WHERE is_active=1 AND (line_account_id=:a OR line_account_id IS NULL)");
                    $stmt2->execute([':a' => $lineAccountId]);
                    $kbCountByAcc = (int) $stmt2->fetchColumn();
                } else {
                    $stmt2 = $db->query("SELECT COUNT(*) FROM ai_knowledge_base WHERE is_active=1 AND line_account_id IS NULL");
                    $kbCountByAcc = (int) $stmt2->fetchColumn();
                }
            } catch (\Throwable $e) {}
            $mapper = new \Modules\AIChat\Services\SymptomMapper();
            $codes = $mapper->mapAllConditions($q);
            $retriever = new \Modules\AIChat\Services\KnowledgeRetriever($db);
            $chunks = $retriever->retrieve($lineAccountId, $q, $codes, 5);
            $respond(true, [
                'data'                  => $chunks,
                'matched_conditions'    => $codes,
                'total_chunks_in_kb'    => $kbCount,
                'chunks_for_account'    => $kbCountByAcc,
                'account_id_used'       => $lineAccountId,
            ]);
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
