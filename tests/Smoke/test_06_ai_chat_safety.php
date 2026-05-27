<?php
/**
 * Smoke test 06 — AI Chat allergy safety + persistence helpers
 *
 * Verifies Phase 1 (Option D) safety net WITHOUT requiring a live DB:
 *  (1) getUserFullContextForChat returns the empty shape when given a
 *      fake LINE id (DB lookup misses → empty arrays).
 *  (2) aiChatBuildUserProfileXml renders <allergies>Ibuprofen ... </allergies>
 *      when an allergy is present and '' when it is not.
 *  (3) aiChatBuildUserContextEvent shapes the SSE payload with
 *      has_allergies/has_medications flags.
 *  (4) aiChatCheckDrugInteractionsSimple FLAGS a product named Ibuprofen
 *      as an `allergy` warning of `high` severity for a user allergic to
 *      "Ibuprofen" — i.e. the AI must not silently recommend it.
 *  (5) aiChatCheckDrugInteractionsSimple FLAGS aspirin+warfarin as an
 *      `interaction` warning of `medium` severity.
 *
 * Usage:
 *   php tests/Smoke/test_06_ai_chat_safety.php
 *
 * Exit code 0 = all passed, 1 = any failure.
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

// Stub config + Database so includes/ai-chat-context.php loads cleanly
// without touching a real MySQL instance.
if (!defined('DB_HOST')) define('DB_HOST', '127.0.0.1');
if (!defined('DB_NAME')) define('DB_NAME', 'smoke_test_unused');
if (!defined('DB_USER')) define('DB_USER', 'smoke_test_unused');
if (!defined('DB_PASS')) define('DB_PASS', '');

if (!class_exists('Database')) {
    // Provide a no-op singleton so other helpers don't blow up if they
    // reach for Database::getInstance(); we never call it directly here.
    class Database
    {
        private static $instance = null;
        public static function getInstance(): self
        {
            if (self::$instance === null) {
                self::$instance = new self();
            }
            return self::$instance;
        }
        public function getConnection() { return null; }
    }
}

require_once __DIR__ . '/../../includes/ai-chat-context.php';

/**
 * Mini-PDO double that returns scripted result sets per SQL fragment so
 * we can exercise getUserFullContextForChat without a real connection.
 */
class FakePDOStmt
{
    /** @var array<int,array<string,mixed>> */
    public array $rows;
    public int $cursor = 0;
    public function __construct(array $rows) { $this->rows = $rows; }
    public function execute($args = null) { return true; }
    public function fetch($mode = null)
    {
        if ($this->cursor >= count($this->rows)) {
            return false;
        }
        return $this->rows[$this->cursor++];
    }
    public function fetchAll($mode = null) { return $this->rows; }
    public function fetchColumn($col = 0)
    {
        if (empty($this->rows)) return false;
        $first = $this->rows[0];
        if (is_array($first)) {
            return array_values($first)[$col] ?? false;
        }
        return $first;
    }
    public function rowCount(): int { return count($this->rows); }
}

class FakePDO
{
    /** @var array<string,array<int,array<string,mixed>>> */
    public array $scripts = [];

    /** @var array<int,array{sql:string,args:?array}> */
    public array $executed = [];

    public function prepare(string $sql): FakePDOStmt
    {
        foreach ($this->scripts as $needle => $rows) {
            if (stripos($sql, $needle) !== false) {
                $stmt = new FakePDOStmt($rows);
                $this->executed[] = ['sql' => $sql, 'args' => null];
                return $stmt;
            }
        }
        $this->executed[] = ['sql' => $sql, 'args' => null];
        return new FakePDOStmt([]);
    }

    public function query(string $sql): FakePDOStmt
    {
        // Used by aiChatEnsureConversationHistorySchema feature-detect
        // probes — pretend the column already exists.
        return new FakePDOStmt([]);
    }

    public function exec(string $sql): int { return 0; }
}

// -----------------------------------------------------------------------
// Tiny assertion helpers
// -----------------------------------------------------------------------
$failures = [];
$passes   = 0;

function s_assert(string $name, bool $ok, string $detail = '')
{
    global $failures, $passes;
    if ($ok) {
        echo "  PASS  $name\n";
        $passes++;
        return;
    }
    echo "  FAIL  $name" . ($detail !== '' ? "\n        $detail" : '') . "\n";
    $failures[] = $name;
}

// =======================================================================
// 1. getUserFullContextForChat returns empty shape on miss
// =======================================================================
echo "[1] getUserFullContextForChat empty\n";
$db = new FakePDO();
$db->scripts['FROM users u'] = []; // user lookup misses
$ctxEmpty = getUserFullContextForChat($db, 'Unonexistent');
s_assert('empty.id is 0', ($ctxEmpty['id'] ?? null) === 0);
s_assert('empty.drug_allergies is []', $ctxEmpty['drug_allergies'] === []);
s_assert('empty.current_medications is []', $ctxEmpty['current_medications'] === []);

// =======================================================================
// 2. With allergies + meds + chronic, helper returns hydrated context
// =======================================================================
echo "[2] getUserFullContextForChat hydrated\n";
$db2 = new FakePDO();
$db2->scripts['FROM users u'] = [[
    'id' => 42,
    'display_name' => 'สมชาย',
    'medical_conditions' => 'เบาหวาน, ความดันโลหิตสูง',
]];
$db2->scripts['FROM user_drug_allergies'] = [[
    'drug_name' => 'Ibuprofen',
    'reaction_type' => 'rash',
    'severity' => 'high',
]];
$db2->scripts['FROM user_current_medications'] = [[
    'medication_name' => 'Warfarin',
    'dosage' => '5mg/day',
]];
$db2->scripts['FROM transactions t'] = [];
$db2->scripts['FROM transaction_items ti'] = [];

$ctx = getUserFullContextForChat($db2, 'Utestuser');
s_assert('id == 42', ($ctx['id'] ?? null) === 42);
s_assert('display_name set', ($ctx['display_name'] ?? '') === 'สมชาย');
s_assert('chronic_diseases set', ($ctx['chronic_diseases'] ?? '') === 'เบาหวาน, ความดันโลหิตสูง');
s_assert('allergies hydrated', count($ctx['drug_allergies']) === 1 && $ctx['drug_allergies'][0]['drug_name'] === 'Ibuprofen');
s_assert('current_meds hydrated', count($ctx['current_medications']) === 1 && $ctx['current_medications'][0]['medication_name'] === 'Warfarin');

// =======================================================================
// 3. aiChatBuildUserProfileXml renders allergy block
// =======================================================================
echo "[3] aiChatBuildUserProfileXml\n";
$xml = aiChatBuildUserProfileXml($ctx);
s_assert('XML contains <allergies>', strpos($xml, '<allergies>') !== false, $xml);
s_assert('XML contains Ibuprofen', strpos($xml, 'Ibuprofen') !== false, $xml);
s_assert('XML contains chronic block', strpos($xml, 'เบาหวาน') !== false, $xml);
s_assert('XML contains current_medications block', strpos($xml, '<current_medications>') !== false, $xml);
s_assert('XML contains Warfarin', strpos($xml, 'Warfarin') !== false, $xml);

$xmlEmpty = aiChatBuildUserProfileXml([
    'drug_allergies' => [],
    'current_medications' => [],
    'chronic_diseases' => null,
]);
s_assert('Empty profile XML is empty string', $xmlEmpty === '');

// =======================================================================
// 4. aiChatBuildUserContextEvent shape
// =======================================================================
echo "[4] aiChatBuildUserContextEvent\n";
$event = aiChatBuildUserContextEvent($ctx);
s_assert('type=user_context', ($event['type'] ?? '') === 'user_context');
s_assert('has_allergies true', ($event['has_allergies'] ?? null) === true);
s_assert('has_medications true', ($event['has_medications'] ?? null) === true);
s_assert('allergies array shape', isset($event['allergies'][0]['drug_name']) && $event['allergies'][0]['drug_name'] === 'Ibuprofen');
s_assert('allergies severity preserved', ($event['allergies'][0]['severity'] ?? '') === 'high');

// =======================================================================
// 5. CORE SAFETY — drug interaction checker FLAGS an Ibuprofen product
//    for an Ibuprofen-allergic user. This is the property the spec
//    requires: "AI doesn't recommend a drug the user is allergic to".
// =======================================================================
echo "[5] aiChatCheckDrugInteractionsSimple — allergy match\n";
$products = [
    ['id' => 101, 'name' => 'Ibuprofen 400mg', 'generic_name' => 'ibuprofen'],
    ['id' => 102, 'name' => 'Paracetamol 500mg', 'generic_name' => 'paracetamol'],
];
$warnings = aiChatCheckDrugInteractionsSimple($products, $ctx);
$ibuprofenAllergy = array_values(array_filter($warnings, static function ($w) {
    return ($w['type'] ?? '') === 'allergy' && stripos($w['product'] ?? '', 'Ibuprofen') !== false;
}));
s_assert('Ibuprofen flagged as allergy', count($ibuprofenAllergy) === 1, json_encode($warnings, JSON_UNESCAPED_UNICODE));
s_assert('Ibuprofen warning severity=high', ($ibuprofenAllergy[0]['severity'] ?? '') === 'high');
$paracetamolWarnings = array_values(array_filter($warnings, static function ($w) {
    return stripos($w['product'] ?? '', 'Paracetamol') !== false;
}));
s_assert('Paracetamol NOT flagged', count($paracetamolWarnings) === 0, json_encode($paracetamolWarnings, JSON_UNESCAPED_UNICODE));

// =======================================================================
// 6. Interaction map: warfarin (user med) + aspirin (suggested product)
//    must emit a medium-severity interaction warning.
// =======================================================================
echo "[6] aiChatCheckDrugInteractionsSimple — interaction match\n";
$ctxInt = [
    'drug_allergies' => [],
    'current_medications' => [['medication_name' => 'Warfarin 5mg', 'dosage' => null]],
];
$prodsInt = [['id' => 201, 'name' => 'Aspirin 81mg', 'generic_name' => 'aspirin']];
$warnsInt = aiChatCheckDrugInteractionsSimple($prodsInt, $ctxInt);
s_assert('Aspirin+Warfarin yields >=1 warning', count($warnsInt) >= 1, json_encode($warnsInt, JSON_UNESCAPED_UNICODE));
s_assert('Interaction type set', ($warnsInt[0]['type'] ?? '') === 'interaction');
s_assert('Interaction severity=medium', ($warnsInt[0]['severity'] ?? '') === 'medium');
s_assert('Interaction interacts_with names Warfarin', stripos($warnsInt[0]['interacts_with'] ?? '', 'Warfarin') !== false);

// =======================================================================
// 7. Empty product list / empty context → no warnings, no crash.
// =======================================================================
echo "[7] aiChatCheckDrugInteractionsSimple — degenerate cases\n";
s_assert('empty products → []', aiChatCheckDrugInteractionsSimple([], $ctx) === []);
s_assert('empty context → []', aiChatCheckDrugInteractionsSimple($products, [
    'drug_allergies' => [], 'current_medications' => [],
]) === []);

// =======================================================================
// 8. aiChatSaveConversationMessage rejects invalid input cleanly
// =======================================================================
echo "[8] aiChatSaveConversationMessage guards\n";
$savePdo = new FakePDO();
s_assert('user_id=0 returns false', aiChatSaveConversationMessage($savePdo, 0, null, null, 'user', 'hi') === false);
s_assert('empty content returns false', aiChatSaveConversationMessage($savePdo, 5, null, null, 'user', '') === false);

// -----------------------------------------------------------------------
echo "\n";
echo str_repeat('=', 60) . "\n";
echo "Smoke 06 — AI chat safety\n";
echo "  Passed: $passes\n";
echo "  Failed: " . count($failures) . "\n";
if (!empty($failures)) {
    echo "  First failure: " . $failures[0] . "\n";
    echo str_repeat('=', 60) . "\n";
    exit(1);
}
echo "  Result: OK\n";
echo str_repeat('=', 60) . "\n";
exit(0);
