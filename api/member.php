<?php
/**
 * Member API
 * จัดการข้อมูลสมาชิก, สมัครสมาชิก, บัตรสมาชิก
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
 exit(0);
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
// 2026-05-27 — Route root-domain Mini App calls to correct tenant DB.
require_once __DIR__ . '/../bootstrap/route_by_account.php';

$db = Database::getInstance()->getConnection();

// Get action (read php://input once for JSON POST)
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$input = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    if ($raw !== '' && $raw !== false) {
        $decoded = json_decode($raw, true);
        $input = is_array($decoded) ? $decoded : [];
        if ($action === '' && !empty($input['action'])) {
            $action = $input['action'];
        }
    }
}

try {
 switch ($action) {
  case 'register':
   handleRegister($db, $input ?? $_POST);
   break;
  case 'check':
   handleCheck($db);
   break;
  case 'get_card':
   handleGetCard($db);
   break;
  case 'get_tiers':
   handleGetTiers($db);
   break;
  case 'update_profile':
   handleUpdateProfile($db, $input ?? $_POST);
   break;
  default:
   jsonResponse(false, 'Invalid action');
 }
} catch (Exception $e) {
 jsonResponse(false, $e->getMessage());
}

/**
 * สมัครสมาชิก
 */
function handleRegister($db, $data)
{
 $lineUserId = $data['line_user_id'] ?? '';
 $lineAccountId = $data['line_account_id'] ?? 1;

 if (empty($lineUserId)) {
  jsonResponse(false, 'กรุณาเข้าสู่ระบบผ่าน LINE');
 }

 // Validate required fields
 $firstName = trim($data['first_name'] ?? '');
 $lastName = trim($data['last_name'] ?? '');
 $birthday = $data['birthday'] ?? null;
 $gender = $data['gender'] ?? null;

 if (empty($firstName)) {
  jsonResponse(false, 'กรุณากรอกชื่อ');
 }
 if (empty($birthday)) {
  jsonResponse(false, 'กรุณากรอกวันเกิด');
 }
 if (empty($gender)) {
  jsonResponse(false, 'กรุณาเลือกเพศ');
 }

 // Optional fields
 $phone = trim($data['phone'] ?? '');
 $email = trim($data['email'] ?? '');
 $weight = !empty($data['weight']) ? floatval($data['weight']) : null;
 $height = !empty($data['height']) ? floatval($data['height']) : null;
 $medicalConditions = trim($data['medical_conditions'] ?? '');
 $drugAllergies = trim($data['drug_allergies'] ?? '');
 $address = trim($data['address'] ?? '');
 $district = trim($data['district'] ?? '');
 $province = trim($data['province'] ?? '');
 $postalCode = trim($data['postal_code'] ?? '');

 // Check which columns exist in users table
 $existingColumns = [];
 try {
  $cols = $db->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
  $existingColumns = array_flip($cols);
 } catch (Exception $e) {
 }

 // Check if user exists - first try exact match, then try without account filter
 $stmt = $db->prepare("SELECT id, member_id, is_registered, line_account_id FROM users WHERE line_user_id = ? AND line_account_id = ?");
 $stmt->execute([$lineUserId, $lineAccountId]);
 $user = $stmt->fetch(PDO::FETCH_ASSOC);

 // If not found, try without account filter (user might exist with NULL or different account)
 if (!$user) {
  $stmt = $db->prepare("SELECT id, member_id, is_registered, line_account_id FROM users WHERE line_user_id = ?");
  $stmt->execute([$lineUserId]);
  $user = $stmt->fetch(PDO::FETCH_ASSOC);
 }

 if ($user && $user['is_registered']) {
  jsonResponse(false, 'คุณเป็นสมาชิกอยู่แล้ว', ['member_id' => $user['member_id']]);
 }

 // Generate member ID
 $memberId = generateMemberId($db, $lineAccountId);

 // Prepare real_name
 $realName = $firstName . ($lastName ? ' ' . $lastName : '');

 // Build dynamic UPDATE/INSERT based on existing columns
 $phoneValue = !empty($phone) ? $phone : null;
 $emailValue = !empty($email) ? $email : null;

 if ($user) {
  // Update existing user - build dynamic SQL
  error_log("Register: Updating existing user ID={$user['id']}, line_user_id=$lineUserId");

  $updates = [
   'first_name = ?',
   'last_name = ?',
   'real_name = ?',
   'birthday = ?',
   'gender = ?',
   'phone = IFNULL(?, phone)',
   'weight = ?',
   'height = ?',
   'medical_conditions = ?',
   'drug_allergies = ?',
   'member_id = ?',
   'is_registered = 1',
   'registered_at = NOW()',
   'updated_at = NOW()'
  ];
  $params = [$firstName, $lastName, $realName, $birthday, $gender, $phoneValue, $weight, $height, $medicalConditions, $drugAllergies, $memberId];

  // Add member_tier if column exists
  if (isset($existingColumns['member_tier'])) {
   $updates[] = "member_tier = 'bronze'";
  }

  // NOTE(Batch 2): `points = 0` used to be set here, which WIPED any balance an
  // existing customer had accumulated via the Odoo webhook or a shop order
  // before they completed registration. The ledger owns the balance now, so
  // registration must not touch the legacy column at all.

  // Add optional columns if they exist
  if (isset($existingColumns['email'])) {
   $updates[] = 'email = IFNULL(?, email)';
   $params[] = $emailValue;
  }
  if (isset($existingColumns['address'])) {
   $updates[] = 'address = ?';
   $params[] = $address ?: null;
  }
  if (isset($existingColumns['district'])) {
   $updates[] = 'district = ?';
   $params[] = $district ?: null;
  }
  if (isset($existingColumns['province'])) {
   $updates[] = 'province = ?';
   $params[] = $province ?: null;
  }
  if (isset($existingColumns['postal_code'])) {
   $updates[] = 'postal_code = ?';
   $params[] = $postalCode ?: null;
  }

  $params[] = $user['id'];

  $sql = "UPDATE users SET " . implode(', ', $updates) . " WHERE id = ?";
  $stmt = $db->prepare($sql);
  $result = $stmt->execute($params);

  $userId = $user['id'];
 } else {
  // Create new user - build dynamic SQL
  $columns = ['line_account_id', 'line_user_id', 'first_name', 'last_name', 'real_name', 'birthday', 'gender', 'phone', 'weight', 'height', 'medical_conditions', 'drug_allergies', 'member_id', 'is_registered'];
  $values = [$lineAccountId, $lineUserId, $firstName, $lastName, $realName, $birthday, $gender, $phone ?: null, $weight, $height, $medicalConditions ?: null, $drugAllergies ?: null, $memberId, 1];

  // Add member_tier if column exists
  if (isset($existingColumns['member_tier'])) {
   $columns[] = 'member_tier';
   $values[] = 'bronze';
  }

  // Add points if column exists
  if (isset($existingColumns['points'])) {
   $columns[] = 'points';
   $values[] = 0;
  }

  // Add registered_at and created_at
  $columns[] = 'registered_at';
  $columns[] = 'created_at';

  $placeholders = array_fill(0, count($values), '?');
  $placeholders[] = 'NOW()';
  $placeholders[] = 'NOW()';

  // Add optional columns if they exist
  if (isset($existingColumns['email']) && $email) {
   $columns[] = 'email';
   $values[] = $email;
   $placeholders[] = '?';
  }
  if (isset($existingColumns['address']) && $address) {
   $columns[] = 'address';
   $values[] = $address;
   $placeholders[] = '?';
  }
  if (isset($existingColumns['district']) && $district) {
   $columns[] = 'district';
   $values[] = $district;
   $placeholders[] = '?';
  }
  if (isset($existingColumns['province']) && $province) {
   $columns[] = 'province';
   $values[] = $province;
   $placeholders[] = '?';
  }
  if (isset($existingColumns['postal_code']) && $postalCode) {
   $columns[] = 'postal_code';
   $values[] = $postalCode;
   $placeholders[] = '?';
  }

  // Build and execute dynamic INSERT
  $sql = "INSERT INTO users (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";
  $stmt = $db->prepare($sql);
  $stmt->execute($values);
  $userId = $db->lastInsertId();
 }

 // 2026-06-20: if this LINE member's phone matches a points-holding phone-only
 // counter record, flag a merge for pharmacist confirmation (never auto-merge).
 if (!empty($phone)) {
  flagPointsMergeOnLink($db, $lineAccountId, $userId, $phone);
 }

 // Welcome bonus — through the canonical ledger, exactly once per member.
 $welcomeBonus = memberAwardWelcomeBonus($db, (int) $lineAccountId, (int) $userId, 'โบนัสต้อนรับสมาชิกใหม่');

 jsonResponse(true, 'สมัครสมาชิกสำเร็จ!', [
  'member_id' => $memberId,
  'welcome_bonus' => $welcomeBonus,
  'tier' => 'bronze'
 ]);
}

/**
 * ตรวจสอบสถานะสมาชิก - Auto-register if not member
 */
function handleCheck($db)
{
 $lineUserId = $_GET['line_user_id'] ?? '';
 $lineAccountId = $_GET['line_account_id'] ?? 1;
 $displayName = $_GET['display_name'] ?? '';
 $pictureUrl = $_GET['picture_url'] ?? '';

 if (empty($lineUserId)) {
  jsonResponse(false, 'Missing line_user_id');
 }

 // Try exact match first - use only columns that definitely exist
 $stmt = $db->prepare("
        SELECT id, member_id, is_registered, first_name, last_name, points, display_name
        FROM users
        WHERE line_user_id = ? AND line_account_id = ?
    ");
 $stmt->execute([$lineUserId, $lineAccountId]);
 $user = $stmt->fetch(PDO::FETCH_ASSOC);

 // If not found, try without account filter
 if (!$user) {
  $stmt = $db->prepare("
            SELECT id, member_id, is_registered, first_name, last_name, points, display_name
            FROM users
            WHERE line_user_id = ?
        ");
  $stmt->execute([$lineUserId]);
  $user = $stmt->fetch(PDO::FETCH_ASSOC);
 }

 // AUTO-REGISTER: If user not found, create new member automatically
 if (!$user) {
  error_log("check: User not found, auto-registering for line_user_id=$lineUserId");
  $user = autoRegisterMember($db, $lineUserId, $lineAccountId, $displayName, $pictureUrl);
 }

 // AUTO-UPGRADE: If user exists but not registered, upgrade to member
 if ($user && !$user['is_registered']) {
  error_log("check: User exists but not registered, auto-upgrading id={$user['id']}");
  $user = autoUpgradeMember($db, $user['id'], $lineAccountId);
 }

 error_log("check: Found user id={$user['id']}, is_registered={$user['is_registered']}, member_id={$user['member_id']}");

 // has_profile = true ถ้ามี first_name (กรอกข้อมูลแล้วจริงๆ)
 $hasProfile = !empty($user['first_name']);

 // BATCH 2: read the balance the one canonical way. This action used to read
 // `users.points` while `get_card` read the ledger — the mini app calls both in
 // one session and was shown two different numbers for the same member.
 $availablePoints = memberAvailablePoints($db, (int) $lineAccountId, (int) $user['id']);

 // Calculate actual tier using TierService
 require_once __DIR__ . '/../classes/TierService.php';
 $tierService = new TierService($db, $lineAccountId);
 $tierInfo = $tierService->getUserTier((int) $user['id']);

 jsonResponse(true, 'OK', [
  'exists' => true,
  'is_registered' => (bool) $user['is_registered'],
  'has_profile' => $hasProfile,
  'member_id' => $user['member_id'] ?? null,
  'first_name' => $user['first_name'] ?? null,
  'last_name' => $user['last_name'] ?? null,
  'display_name' => $user['display_name'] ?? null,
  'tier' => $tierInfo['tier_code'],
  'tier_name' => $tierInfo['tier_name'],
  'points' => $availablePoints,
  'auto_registered' => true
 ]);
}

/**
 * Auto-register new member from LINE login
 */
function autoRegisterMember($db, $lineUserId, $lineAccountId, $displayName = '', $pictureUrl = '')
{
 // Generate member ID
 $memberId = generateMemberId($db, $lineAccountId);

 // Check which columns exist
 $existingColumns = [];
 try {
  $cols = $db->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
  $existingColumns = array_flip($cols);
 } catch (Exception $e) {}

 // Build insert query
 $columns = ['line_account_id', 'line_user_id', 'display_name', 'picture_url', 'member_id', 'is_registered', 'registered_at', 'created_at'];
 $placeholders = ['?', '?', '?', '?', '?', '1', 'NOW()', 'NOW()'];
 $values = [$lineAccountId, $lineUserId, $displayName ?: null, $pictureUrl ?: null, $memberId];

 if (isset($existingColumns['member_tier'])) {
  $columns[] = 'member_tier';
  $placeholders[] = '?';
  $values[] = 'bronze';
 }

 // NOTE(Batch 2): the row is created with no balance; the ledger awards the
 // welcome bonus below. Seeding `points = 50` here made the bonus invisible to
 // every modern reader and re-awarded it on each auto-register retry.

 $sql = "INSERT INTO users (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";
 $stmt = $db->prepare($sql);
 $stmt->execute($values);
 $userId = $db->lastInsertId();

 $awarded = memberAwardWelcomeBonus($db, (int) $lineAccountId, (int) $userId, 'โบนัสต้อนรับสมาชิกใหม่ (Auto-Register)');

 error_log("autoRegisterMember: Created new member id=$userId, member_id=$memberId");

 return [
  'id' => $userId,
  'member_id' => $memberId,
  'is_registered' => 1,
  'first_name' => null,
  'last_name' => null,
  'display_name' => $displayName,
  'points' => $awarded
 ];
}

/**
 * Auto-upgrade existing user to member
 */
function autoUpgradeMember($db, $userId, $lineAccountId)
{
 $memberId = generateMemberId($db, $lineAccountId);

 // Check which columns exist
 $existingColumns = [];
 try {
  $cols = $db->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
  $existingColumns = array_flip($cols);
 } catch (Exception $e) {}

 $updates = ['member_id = ?', 'is_registered = 1', 'registered_at = NOW()'];
 $params = [$memberId];

 if (isset($existingColumns['member_tier'])) {
  $updates[] = "member_tier = 'bronze'";
 }

 // NOTE(Batch 2): `points = COALESCE(points,0) + 50` lived here, and because the
 // only guard was is_registered, any row left unregistered collected another 50
 // on every `action=check`. The ledger's idempotency key now caps it at one.

 $params[] = $userId;
 $sql = "UPDATE users SET " . implode(', ', $updates) . " WHERE id = ?";
 $stmt = $db->prepare($sql);
 $stmt->execute($params);

 memberAwardWelcomeBonus($db, (int) $lineAccountId, (int) $userId, 'โบนัสต้อนรับสมาชิก (Auto-Upgrade)');

 error_log("autoUpgradeMember: Upgraded user id=$userId to member_id=$memberId");

 // Fetch updated user
 $stmt = $db->prepare("SELECT id, member_id, is_registered, first_name, last_name, display_name, points FROM users WHERE id = ?");
 $stmt->execute([$userId]);
 return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Credit the 50-point welcome bonus through the canonical ledger.
 *
 * BATCH 2. All three registration paths (register, auto-register, auto-upgrade)
 * used to write `users.points` + `points_history` directly. That balance was
 * invisible to every modern reader, never updated the tier, and — because the
 * only guard was `is_registered` — was re-awarded on every retry for any row
 * left unregistered.
 *
 * The idempotency key is keyed on `users.id` alone, deliberately: under
 * database-per-tenant a user id is already tenant-unique and implies its OA,
 * whereas `line_account_id` arrives from the (still unauthenticated, see Phase 6)
 * request body and could vary between calls for the same member — which would
 * mint a second key and award the bonus twice.
 *
 * @return int points actually credited (0 when it had already been awarded)
 */
function memberAwardWelcomeBonus(PDO $db, int $lineAccountId, int $userId, string $description): int
{
 $welcomeBonus = 50;

 if ($userId <= 0) {
  return 0;
 }

 try {
  require_once __DIR__ . '/../classes/LoyaltyPoints.php';
  $loyalty = new LoyaltyPoints($db, $lineAccountId);
  $result = $loyalty->ledger()->credit($userId, $welcomeBonus, [
   'type' => LoyaltyLedgerService::TYPE_BONUS,
   'reference_type' => 'welcome',
   'description' => $description,
   'idempotency_key' => 'member:' . $userId . ':welcome-bonus',
   'created_by' => 'system:member-register',
  ]);

  if (!$result['success']) {
   error_log('[member] welcome bonus declined for user ' . $userId . ': ' . ($result['reason'] ?? '?'));
   return 0;
  }

  return $result['duplicate'] ? 0 : $welcomeBonus;
 } catch (Throwable $e) {
  // Never let the bonus break registration itself.
  error_log('[member] welcome bonus failed for user ' . $userId . ': ' . $e->getMessage());
  return 0;
 }
}

/**
 * The member's spendable balance, read the one canonical way.
 *
 * `action=check` used to read `users.points` while `action=get_card` read
 * LoyaltyPoints — two different numbers for the same member, from two actions in
 * this same file, both called by the mini app during one session.
 */
function memberAvailablePoints(PDO $db, int $lineAccountId, int $userId): int
{
 try {
  require_once __DIR__ . '/../classes/LoyaltyPoints.php';
  $loyalty = new LoyaltyPoints($db, $lineAccountId);

  return (int) ($loyalty->getUserPoints($userId)['available_points'] ?? 0);
 } catch (Throwable $e) {
  error_log('[member] balance read failed for user ' . $userId . ': ' . $e->getMessage());
  return 0;
 }
}

/**
 * ดึงข้อมูลบัตรสมาชิก
 */
function handleGetCard($db)
{
 $lineUserId = $_GET['line_user_id'] ?? '';
 $lineAccountId = $_GET['line_account_id'] ?? 1;

 if (empty($lineUserId)) {
  jsonResponse(false, 'Missing line_user_id');
 }

 // Get user data - try exact match first
 $stmt = $db->prepare("
        SELECT u.*,
               COALESCE(u.first_name, u.display_name) as display_first_name
        FROM users u
        WHERE u.line_user_id = ? AND u.line_account_id = ?
    ");
 $stmt->execute([$lineUserId, $lineAccountId]);
 $user = $stmt->fetch(PDO::FETCH_ASSOC);

 // If not found, try without account filter
 if (!$user) {
  $stmt = $db->prepare("
            SELECT u.*,
                   COALESCE(u.first_name, u.display_name) as display_first_name
            FROM users u
            WHERE u.line_user_id = ?
        ");
  $stmt->execute([$lineUserId]);
  $user = $stmt->fetch(PDO::FETCH_ASSOC);
 }

 // Debug log
 error_log("get_card: line_user_id=$lineUserId, line_account_id=$lineAccountId, user_found=" . ($user ? 'yes (id=' . $user['id'] . ')' : 'no') . ", is_registered=" . ($user['is_registered'] ?? 'null'));

 if (!$user) {
  jsonResponse(false, 'ไม่พบข้อมูลผู้ใช้', ['is_registered' => false, 'user_exists' => false]);
 }

 if (!$user['is_registered']) {
  error_log("get_card: User exists but not registered. user_id={$user['id']}, first_name=" . ($user['first_name'] ?? 'null') . ", member_id=" . ($user['member_id'] ?? 'null'));
  jsonResponse(false, 'ยังไม่ได้ลงทะเบียนสมาชิก', ['is_registered' => false, 'user_exists' => true, 'user_id' => $user['id']]);
 }

 // Calculate tier from points using TierService (not from stored member_tier)
 require_once __DIR__ . '/../classes/TierService.php';
 require_once __DIR__ . '/../classes/LoyaltyPoints.php';

 // Use LoyaltyPoints::getUserPoints for consistent points (same as points-history.php)
 $loyalty = new LoyaltyPoints($db, $lineAccountId);
 $pointsData = $loyalty->getUserPoints($user['id']);
 $userPoints = (int) ($pointsData['available_points'] ?? $pointsData['total_points'] ?? 0);

 $tierService = new TierService($db, $lineAccountId);
 $tierInfo = $tierService->calculateTier($userPoints);

 // Format tier data for response
 $tier = [
  'tier_code' => $tierInfo['tier_code'],
  'tier_name' => $tierInfo['tier_name'],
  'name' => $tierInfo['tier_name'],
  'color' => $tierInfo['color'],
  'icon' => $tierInfo['icon'],
  'discount_percent' => $tierInfo['discount_percent'],
  'min_points' => $tierInfo['min_points'],
  'current_tier_points' => $tierInfo['min_points'],
  'next_tier_points' => $tierInfo['next_tier_points'],
  'next_tier_name' => $tierInfo['next_tier_name'],
  'points_to_next' => $tierInfo['points_to_next'],
  'progress_percent' => $tierInfo['progress_percent']
 ];

 // Next tier is already calculated in tierInfo
 $nextTier = $tierInfo['next_tier_code'] ? [
  'tier_code' => $tierInfo['next_tier_code'],
  'tier_name' => $tierInfo['next_tier_name'],
  'min_points' => $tierInfo['next_tier_points']
 ] : null;

 // Get shop info - handle missing logo_url column
 $shop = null;
 try {
  // First check if logo_url column exists
  $checkCol = $db->query("SHOW COLUMNS FROM shop_settings LIKE 'logo_url'");
  if ($checkCol->rowCount() > 0) {
   $stmt = $db->prepare("SELECT shop_name, logo_url FROM shop_settings WHERE line_account_id = ? LIMIT 1");
  } else {
   $stmt = $db->prepare("SELECT shop_name, '' as logo_url FROM shop_settings WHERE line_account_id = ? LIMIT 1");
  }
  $stmt->execute([$lineAccountId]);
  $shop = $stmt->fetch(PDO::FETCH_ASSOC);
 } catch (Exception $e) {
  // If shop_settings table doesn't exist or other error
  $shop = null;
 }

 // Get LINE account name
 $stmt = $db->prepare("SELECT name FROM line_accounts WHERE id = ? LIMIT 1");
 $stmt->execute([$lineAccountId]);
 $lineAccount = $stmt->fetch(PDO::FETCH_ASSOC);

 $shopName = $shop['shop_name'] ?? $lineAccount['name'] ?? 'ร้านค้า';

 jsonResponse(true, 'OK', [
  'member' => [
   'id' => $user['id'],
   'member_id' => $user['member_id'],
   'is_registered' => (bool) $user['is_registered'],
   'first_name' => $user['first_name'],
   'last_name' => $user['last_name'],
   'display_name' => $user['display_name'],
   'picture_url' => $user['picture_url'],
   'phone' => $user['phone'],
   'email' => $user['email'] ?? null,
   'birthday' => $user['birthday'],
   'gender' => $user['gender'],
   'address' => $user['address'] ?? null,
   'district' => $user['district'] ?? null,
   'province' => $user['province'] ?? null,
   'postal_code' => $user['postal_code'] ?? null,
   'weight' => $user['weight'] ?? null,
   'height' => $user['height'] ?? null,
   'medical_conditions' => $user['medical_conditions'] ?? null,
   'drug_allergies' => $user['drug_allergies'] ?? null,
   'points' => $userPoints,
   'total_spent' => (float) ($user['total_spent'] ?? 0),
   'total_orders' => (int) ($user['total_orders'] ?? 0),
   'registered_at' => $user['registered_at']
  ],
  'tier' => $tier ?: [
   'tier_code' => 'bronze',
   'tier_name' => 'Bronze',
   'color' => '#CD7F32',
   'icon' => '🥉',
   'discount_percent' => 0,
   'benefits' => 'สะสมแต้มทุกการซื้อ'
  ],
  'next_tier' => $nextTier,
  'shop' => [
   'name' => $shopName,
   'logo' => $shop['logo_url'] ?? ''
  ]
 ]);
}

/**
 * ดึงข้อมูลระดับสมาชิกทั้งหมด
 */
function handleGetTiers($db)
{
 $lineAccountId = $_GET['line_account_id'] ?? 1;

 $stmt = $db->prepare("
        SELECT * FROM member_tiers
        WHERE (line_account_id = ? OR line_account_id IS NULL) AND is_active = 1
        ORDER BY sort_order ASC
    ");
 $stmt->execute([$lineAccountId]);
 $tiers = $stmt->fetchAll(PDO::FETCH_ASSOC);

 jsonResponse(true, 'OK', ['tiers' => $tiers]);
}

/**
 * อัพเดทข้อมูลสมาชิก
 */
function handleUpdateProfile($db, $data)
{
 $lineUserId = $data['line_user_id'] ?? '';

 if (empty($lineUserId)) {
  jsonResponse(false, 'กรุณาเข้าสู่ระบบ');
 }

 $updates = [];
 $params = [];

 $allowedFields = [
  'first_name',
  'last_name',
  'phone',
  'email',
  'weight',
  'height',
  'medical_conditions',
  'drug_allergies',
  'address',
  'district',
  'province',
  'postal_code',
  'birthday',
  'gender'
 ];

 foreach ($allowedFields as $field) {
  if (isset($data[$field])) {
   $updates[] = "$field = ?";
   $params[] = $data[$field];
  }
 }

 if (empty($updates)) {
  jsonResponse(false, 'ไม่มีข้อมูลที่ต้องอัพเดท');
 }

 // Update real_name if first_name or last_name changed
 if (isset($data['first_name']) || isset($data['last_name'])) {
  $firstName = $data['first_name'] ?? '';
  $lastName = $data['last_name'] ?? '';
  $realName = trim($firstName . ' ' . $lastName);
  $updates[] = "real_name = ?";
  $params[] = $realName;
 }

 $params[] = $lineUserId;

 $sql = "UPDATE users SET " . implode(', ', $updates) . ", updated_at = NOW() WHERE line_user_id = ?";
 $stmt = $db->prepare($sql);
 $stmt->execute($params);

 // 2026-06-20: phone just set/changed → flag a phone-only counter record with
 // points for merge confirmation.
 if (!empty($data['phone'])) {
  try {
   $u = $db->prepare("SELECT id, line_account_id FROM users WHERE line_user_id = ? LIMIT 1");
   $u->execute([$lineUserId]);
   $row = $u->fetch(PDO::FETCH_ASSOC);
   if ($row) {
    flagPointsMergeOnLink($db, (int) $row['line_account_id'], (int) $row['id'], (string) $data['phone']);
   }
  } catch (Throwable $e) {
   error_log('[member] update_profile merge flag: ' . $e->getMessage());
  }
 }

 jsonResponse(true, 'อัพเดทข้อมูลสำเร็จ');
}

/**
 * Flag (don't perform) a phone->LINE loyalty merge: when a LINE member's phone
 * matches a points-holding phone-only counter record ('offline:<digits>'), add
 * a pending row to points_merge_candidates for a pharmacist to confirm later.
 * Best-effort — never throws into the caller. Mirrors pcFlagMergeForPhone in
 * api/points-claim.php and migration_2026-06-20_points_phone_members.sql.
 */
function flagPointsMergeOnLink($db, $lineAccountId, $lineUserDbId, $phone): void
{
 try {
  $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';
  if (strlen($digits) === 11 && strpos($digits, '66') === 0) {
   $digits = '0' . substr($digits, 2);
  }
  $lineAccountId = (int) $lineAccountId;
  $lineUserDbId = (int) $lineUserDbId;
  if (strlen($digits) < 8 || $lineAccountId <= 0 || $lineUserDbId <= 0) {
   return;
  }

  $st = $db->prepare(
   "SELECT id, available_points FROM users
    WHERE line_account_id = ? AND line_user_id = ? AND available_points > 0 LIMIT 1"
  );
  $st->execute([$lineAccountId, 'offline:' . $digits]);
  $ghost = $st->fetch(PDO::FETCH_ASSOC);
  if (!$ghost) {
   return;
  }
  $offlineId = (int) $ghost['id'];
  if ($offlineId === $lineUserDbId) {
   return;
  }

  $db->exec(
   "CREATE TABLE IF NOT EXISTS `points_merge_candidates` (
       `id` INT NOT NULL AUTO_INCREMENT,
       `line_account_id` INT NOT NULL,
       `phone` VARCHAR(20) NOT NULL,
       `offline_user_id` INT NOT NULL,
       `line_user_id` INT NOT NULL,
       `offline_points` INT NOT NULL DEFAULT 0,
       `status` ENUM('pending','merged','dismissed') NOT NULL DEFAULT 'pending',
       `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
       `resolved_at` TIMESTAMP NULL DEFAULT NULL,
       `resolved_by` INT NULL,
       PRIMARY KEY (`id`),
       UNIQUE KEY `uniq_pair` (`line_account_id`, `offline_user_id`, `line_user_id`),
       KEY `idx_account_status` (`line_account_id`, `status`),
       KEY `idx_phone` (`line_account_id`, `phone`)
   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
  );

  $db->prepare(
   "INSERT INTO points_merge_candidates
       (line_account_id, phone, offline_user_id, line_user_id, offline_points, status)
    VALUES (?, ?, ?, ?, ?, 'pending')
    ON DUPLICATE KEY UPDATE offline_points = VALUES(offline_points),
       status = IF(status = 'merged', 'merged', 'pending'), resolved_at = NULL, resolved_by = NULL"
  )->execute([$lineAccountId, $digits, $offlineId, $lineUserDbId, (int) $ghost['available_points']]);
 } catch (Throwable $e) {
  error_log('[member] flag merge: ' . $e->getMessage());
 }
}

/**
 * สร้างรหัสสมาชิก
 */
function generateMemberId($db, $lineAccountId)
{
 $prefix = 'M';
 $year = date('y');

 // Get last member ID
 $stmt = $db->prepare("
        SELECT member_id FROM users
        WHERE member_id LIKE ? AND (line_account_id = ? OR line_account_id IS NULL)
        ORDER BY member_id DESC LIMIT 1
    ");
 $stmt->execute([$prefix . $year . '%', $lineAccountId]);
 $last = $stmt->fetch(PDO::FETCH_ASSOC);

 if ($last && preg_match('/^M\d{2}(\d{5})$/', $last['member_id'], $matches)) {
  $nextNum = intval($matches[1]) + 1;
 } else {
  $nextNum = 1;
 }

 return $prefix . $year . str_pad($nextNum, 5, '0', STR_PAD_LEFT);
}

/**
 * JSON Response
 */
function jsonResponse($success, $message, $data = [])
{
 echo json_encode([
  'success' => $success,
  'message' => $message,
  ...$data
 ], JSON_UNESCAPED_UNICODE);
 exit;
}
