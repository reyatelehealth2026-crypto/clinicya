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

  // Add points if column exists
  if (isset($existingColumns['points'])) {
   $updates[] = 'points = 0';
  }

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

 // Add welcome bonus points if points column exists
 $welcomeBonus = 50;
 try {
  $stmt = $db->prepare("UPDATE users SET points = ? WHERE id = ?");
  $stmt->execute([$welcomeBonus, $userId]);
 } catch (Exception $e) {
  // points column might not exist
  error_log("Update points error: " . $e->getMessage());
 }

 // Log points
 try {
  $stmt = $db->prepare("
            INSERT INTO points_history (line_account_id, user_id, points, type, description, balance_after)
            VALUES (?, ?, ?, 'bonus', 'โบนัสต้อนรับสมาชิกใหม่', ?)
        ");
  $stmt->execute([$lineAccountId, $userId, $welcomeBonus, $welcomeBonus]);
 } catch (Exception $e) {
  // points_history table might not exist
  error_log("points_history insert error: " . $e->getMessage());
 }

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

 // Calculate actual tier using TierService
 require_once __DIR__ . '/../classes/TierService.php';
 $tierService = new TierService($db, $lineAccountId);
 $tierInfo = $tierService->calculateTier((int) ($user['points'] ?? 0));

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
  'points' => (int) ($user['points'] ?? 0),
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

 if (isset($existingColumns['points'])) {
  $columns[] = 'points';
  $placeholders[] = '?';
  $values[] = 50; // Welcome bonus
 }

 $sql = "INSERT INTO users (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";
 $stmt = $db->prepare($sql);
 $stmt->execute($values);
 $userId = $db->lastInsertId();

 // Log welcome bonus points
 try {
  $stmt = $db->prepare("
   INSERT INTO points_history (line_account_id, user_id, points, type, description, balance_after)
   VALUES (?, ?, ?, 'bonus', 'โบนัสต้อนรับสมาชิกใหม่ (Auto-Register)', ?)
  ");
  $stmt->execute([$lineAccountId, $userId, 50, 50]);
 } catch (Exception $e) {
  error_log("points_history insert error: " . $e->getMessage());
 }

 error_log("autoRegisterMember: Created new member id=$userId, member_id=$memberId");

 return [
  'id' => $userId,
  'member_id' => $memberId,
  'is_registered' => 1,
  'first_name' => null,
  'last_name' => null,
  'display_name' => $displayName,
  'points' => 50
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

 if (isset($existingColumns['points'])) {
  $updates[] = 'points = COALESCE(points, 0) + 50';
 }

 $params[] = $userId;
 $sql = "UPDATE users SET " . implode(', ', $updates) . " WHERE id = ?";
 $stmt = $db->prepare($sql);
 $stmt->execute($params);

 // Log welcome bonus
 try {
  $stmt = $db->prepare("
   INSERT INTO points_history (line_account_id, user_id, points, type, description, balance_after)
   VALUES (?, ?, ?, 'bonus', 'โบนัสต้อนรับสมาชิก (Auto-Upgrade)', (SELECT COALESCE(points, 50) FROM users WHERE id = ?))
  ");
  $stmt->execute([$lineAccountId, $userId, 50, $userId]);
 } catch (Exception $e) {
  error_log("points_history insert error: " . $e->getMessage());
 }

 error_log("autoUpgradeMember: Upgraded user id=$userId to member_id=$memberId");

 // Fetch updated user
 $stmt = $db->prepare("SELECT id, member_id, is_registered, first_name, last_name, display_name, points FROM users WHERE id = ?");
 $stmt->execute([$userId]);
 return $stmt->fetch(PDO::FETCH_ASSOC);
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

 jsonResponse(true, 'อัพเดทข้อมูลสำเร็จ');
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
