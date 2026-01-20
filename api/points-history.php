<?php
/**
 * Points History API
 * API สำหรับดูประวัติคะแนนสะสม
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
require_once __DIR__ . '/../classes/LoyaltyPoints.php';

$db = Database::getInstance()->getConnection();

$action = $_GET['action'] ?? $_POST['action'] ?? 'history';
$lineUserId = $_GET['line_user_id'] ?? $_POST['line_user_id'] ?? null;

if (!$lineUserId) {
    echo json_encode(['success' => false, 'error' => 'Missing line_user_id']);
    exit;
}

try {
    // Get user info
    $stmt = $db->prepare("SELECT id, line_account_id, total_points, available_points, used_points, points, display_name FROM users WHERE line_user_id = ?");
    $stmt->execute([$lineUserId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        echo json_encode(['success' => false, 'error' => 'User not found']);
        exit;
    }

    // Fallback: use 'points' column if available_points is 0 but points has value
    if (empty($user['available_points']) && !empty($user['points'])) {
        $user['available_points'] = $user['points'];
        $user['total_points'] = $user['points'];
    }

    $loyalty = new LoyaltyPoints($db, $user['line_account_id']);

    switch ($action) {
        case 'dashboard':
            // Get points dashboard data (Requirements: 21.1-21.8)
            $limit = 5; // Recent 5 transactions for dashboard
            $history = $loyalty->getPointsHistory($user['id'], $limit);

            // Format history
            $formatted = array_map(function ($item) {
                return [
                    'id' => $item['id'],
                    'type' => $item['type'],
                    'points' => $item['points'],
                    'balance_after' => $item['balance_after'],
                    'description' => $item['description'],
                    'reference_type' => $item['reference_type'],
                    'created_at' => $item['created_at'],
                    'formatted_date' => date('d/m/Y H:i', strtotime($item['created_at']))
                ];
            }, $history);

            // Calculate totals
            $totalEarned = 0;
            $totalUsed = 0;
            $totalExpired = 0;

            try {
                $stmt = $db->prepare("
                    SELECT 
                        COALESCE(SUM(CASE WHEN points > 0 THEN points ELSE 0 END), 0) as total_earned,
                        COALESCE(ABS(SUM(CASE WHEN type = 'redeem' AND points < 0 THEN points ELSE 0 END)), 0) as total_used,
                        COALESCE(ABS(SUM(CASE WHEN type = 'expire' AND points < 0 THEN points ELSE 0 END)), 0) as total_expired
                    FROM points_transactions 
                    WHERE user_id = ?
                ");
                $stmt->execute([$user['id']]);
                $totals = $stmt->fetch(PDO::FETCH_ASSOC);
                $totalEarned = (int) ($totals['total_earned'] ?? 0);
                $totalUsed = (int) ($totals['total_used'] ?? 0);
                $totalExpired = (int) ($totals['total_expired'] ?? 0);
            } catch (Exception $e) {
                // Fallback calculation from user table
                $totalEarned = (int) $user['total_points'];
                $totalUsed = (int) $user['used_points'];
            }

            // Get tier info
            $tier = $loyalty->getUserTier($user['id']);
            $tierName = $tier['name'] ?? 'Silver';
            $tierPoints = (int) ($tier['current_points'] ?? $user['available_points']);
            $nextTierPoints = (int) ($tier['next_tier_points'] ?? 2000);
            $nextTierName = $tier['next_tier_name'] ?? 'Gold';
            $pointsToNext = max(0, $nextTierPoints - $tierPoints);

            // Get expiring points (within 30 days)
            $expiringPoints = 0;
            $nearestExpiryDate = null;
            try {
                $stmt = $db->prepare("
                    SELECT SUM(points) as expiring, MIN(expires_at) as nearest_expiry
                    FROM points_transactions 
                    WHERE user_id = ? 
                    AND type = 'earn' 
                    AND expires_at IS NOT NULL 
                    AND expires_at <= DATE_ADD(NOW(), INTERVAL 30 DAY)
                    AND expires_at > NOW()
                ");
                $stmt->execute([$user['id']]);
                $expiring = $stmt->fetch(PDO::FETCH_ASSOC);
                $expiringPoints = (int) ($expiring['expiring'] ?? 0);
                $nearestExpiryDate = $expiring['nearest_expiry'] ?? null;
            } catch (Exception $e) {
            }

            // Get pending points
            $pendingPoints = 0;
            $pendingConfirmDate = null;
            try {
                $stmt = $db->prepare("
                    SELECT SUM(points) as pending, MIN(created_at) as confirm_date
                    FROM points_transactions 
                    WHERE user_id = ? 
                    AND status = 'pending'
                ");
                $stmt->execute([$user['id']]);
                $pending = $stmt->fetch(PDO::FETCH_ASSOC);
                $pendingPoints = (int) ($pending['pending'] ?? 0);
                $pendingConfirmDate = $pending['confirm_date'] ?? null;
            } catch (Exception $e) {
            }

            echo json_encode([
                'success' => true,
                'user' => [
                    'name' => $user['display_name'],
                    'total_points' => (int) $user['total_points'],
                    'available_points' => (int) $user['available_points'],
                    'used_points' => (int) $user['used_points']
                ],
                'total_earned' => $totalEarned,
                'total_used' => $totalUsed,
                'total_expired' => $totalExpired,
                'tier' => [
                    'name' => $tierName,
                    'current_points' => $tierPoints,
                    'next_tier_points' => $nextTierPoints,
                    'color' => $tier['color'] ?? '#9CA3AF',
                    'icon' => $tier['icon'] ?? '⭐️',
                    'code' => $tier['tier_code'] ?? 'member'
                ],
                'tier_points' => $tierPoints,
                'points_to_next_tier' => $pointsToNext,
                'next_tier_name' => $nextTierName,
                'expiring_points' => $expiringPoints,
                'nearest_expiry_date' => $nearestExpiryDate,
                'pending_points' => $pendingPoints,
                'pending_confirmation_date' => $pendingConfirmDate,
                'history' => $formatted
            ]);
            break;

        case 'history':
            $limit = (int) ($_GET['limit'] ?? 20);
            $history = $loyalty->getPointsHistory($user['id'], $limit);

            // Format history
            $formatted = array_map(function ($item) {
                return [
                    'id' => $item['id'],
                    'type' => $item['type'],
                    'points' => $item['points'],
                    'balance_after' => $item['balance_after'],
                    'description' => $item['description'],
                    'reference_type' => $item['reference_type'],
                    'reference_id' => $item['reference_id'] ?? null,
                    'created_at' => $item['created_at'],
                    'formatted_date' => date('d/m/Y H:i', strtotime($item['created_at']))
                ];
            }, $history);

            echo json_encode([
                'success' => true,
                'user' => [
                    'name' => $user['display_name'],
                    'total_points' => (int) $user['total_points'],
                    'available_points' => (int) $user['available_points'],
                    'used_points' => (int) $user['used_points']
                ],
                'history' => $formatted
            ]);
            break;

        case 'full_history':
            // Full history with pagination and filtering (Requirements: 22.1, 22.6, 22.7, 22.10, 22.11, 22.12)
            $page = max(1, (int) ($_GET['page'] ?? 1));
            $limit = min(50, max(1, (int) ($_GET['limit'] ?? 20)));
            $offset = ($page - 1) * $limit;
            $type = $_GET['type'] ?? 'all';

            // Build query with optional type filter
            $sql = "SELECT * FROM points_transactions WHERE user_id = ?";
            $params = [$user['id']];

            // Apply type filter (Requirement 22.6)
            if ($type !== 'all') {
                $typeMap = [
                    'earned' => 'earn',
                    'redeemed' => 'redeem',
                    'expired' => 'expire'
                ];
                $dbType = $typeMap[$type] ?? $type;
                $sql .= " AND type = ?";
                $params[] = $dbType;
            }

            // Order by date descending (Requirement 22.1 - chronological order, newest first)
            $sql .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;

            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $history = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Format history with all required fields (Requirement 22.2)
            $formatted = array_map(function ($item) {
                return [
                    'id' => (int) $item['id'],
                    'type' => $item['type'],
                    'points' => (int) $item['points'],
                    'balance_after' => (int) $item['balance_after'],
                    'description' => $item['description'],
                    'reference_type' => $item['reference_type'] ?? null,
                    'reference_id' => $item['reference_id'] ?? null,
                    'reference_code' => $item['reference_id'] ?? null,
                    'created_at' => $item['created_at'],
                    'formatted_date' => date('d/m/Y H:i', strtotime($item['created_at']))
                ];
            }, $history);

            // Calculate totals for summary (Requirement 22.10)
            $totalEarned = 0;
            $totalUsed = 0;
            $totalExpired = 0;

            try {
                $stmt = $db->prepare("
                    SELECT 
                        COALESCE(SUM(CASE WHEN type = 'earn' THEN points ELSE 0 END), 0) as total_earned,
                        COALESCE(ABS(SUM(CASE WHEN type = 'redeem' THEN points ELSE 0 END)), 0) as total_used,
                        COALESCE(ABS(SUM(CASE WHEN type = 'expire' THEN points ELSE 0 END)), 0) as total_expired
                    FROM points_transactions 
                    WHERE user_id = ?
                ");
                $stmt->execute([$user['id']]);
                $totals = $stmt->fetch(PDO::FETCH_ASSOC);
                $totalEarned = (int) ($totals['total_earned'] ?? 0);
                $totalUsed = (int) ($totals['total_used'] ?? 0);
                $totalExpired = (int) ($totals['total_expired'] ?? 0);
            } catch (Exception $e) {
                $totalEarned = (int) $user['total_points'];
                $totalUsed = (int) $user['used_points'];
            }

            // Get total count for pagination
            $countSql = "SELECT COUNT(*) FROM points_transactions WHERE user_id = ?";
            $countParams = [$user['id']];
            if ($type !== 'all') {
                $countSql .= " AND type = ?";
                $countParams[] = $typeMap[$type] ?? $type;
            }
            $stmt = $db->prepare($countSql);
            $stmt->execute($countParams);
            $totalCount = (int) $stmt->fetchColumn();

            // JSON encode response (Requirement 22.11)
            echo json_encode([
                'success' => true,
                'user' => [
                    'name' => $user['display_name'],
                    'total_points' => (int) $user['total_points'],
                    'available_points' => (int) $user['available_points'],
                    'used_points' => (int) $user['used_points']
                ],
                'total_earned' => $totalEarned,
                'total_used' => $totalUsed,
                'total_expired' => $totalExpired,
                'history' => $formatted,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $totalCount,
                    'has_more' => ($offset + count($history)) < $totalCount
                ]
            ]);
            break;

        case 'rewards':
            $rewards = $loyalty->getRewards(true);
            $userRedemptions = $loyalty->getUserRedemptions($user['id']);

            // Format redemptions with expiry info (Requirement 23.11)
            $formattedRedemptions = array_map(function ($item) {
                $daysUntilExpiry = null;
                if (!empty($item['expires_at'])) {
                    $expiryDate = new DateTime($item['expires_at']);
                    $now = new DateTime();
                    $daysUntilExpiry = (int) $now->diff($expiryDate)->format('%r%a');
                }

                return [
                    'id' => $item['id'],
                    'reward_name' => $item['reward_name'],
                    'image_url' => $item['reward_image'] ?? null,
                    'points_used' => $item['points_used'],
                    'redemption_code' => $item['redemption_code'],
                    'status' => $item['status'],
                    'expires_at' => $item['expires_at'] ?? null,
                    'days_until_expiry' => $daysUntilExpiry,
                    'created_at' => $item['created_at'],
                    'formatted_date' => date('d/m/Y H:i', strtotime($item['created_at']))
                ];
            }, $userRedemptions);

            echo json_encode([
                'success' => true,
                'available_points' => (int) $user['available_points'],
                'rewards' => $rewards,
                'my_redemptions' => $formattedRedemptions
            ]);
            break;

        case 'redeem':
            $rewardId = (int) ($_POST['reward_id'] ?? 0);
            if (!$rewardId) {
                echo json_encode(['success' => false, 'error' => 'Missing reward_id']);
                exit;
            }

            $result = $loyalty->redeemReward($user['id'], $rewardId);

            // Format response
            if ($result['success']) {
                echo json_encode([
                    'success' => true,
                    'message' => 'แลกรางวัลสำเร็จ!',
                    'redemption_code' => $result['redemption_code'],
                    'reward_name' => $result['reward']['name'] ?? ''
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'error' => $result['message'] ?? 'ไม่สามารถแลกรางวัลได้'
                ]);
            }
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }

} catch (Exception $e) {
    error_log("Points History API error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
