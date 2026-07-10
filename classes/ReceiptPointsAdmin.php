<?php
/**
 * Admin-triggered award action for receipt-point claims stuck in
 * pending_review. See docs/adr/0007-receipt-points-review.md.
 */

require_once __DIR__ . '/LoyaltyPoints.php';
require_once __DIR__ . '/LineAccountManager.php';

class ReceiptPointsAdmin
{
    public static function awardPendingReceiptClaim(PDO $db, int $claimId, int $lineAccountId, int $points, string $description, int $adminUserId): array
    {
        if ($points <= 0) {
            return ['success' => false, 'error' => 'จำนวนแต้มต้องมากกว่า 0'];
        }

        $stmt = $db->prepare("SELECT * FROM receipt_point_claims WHERE id = ? AND line_account_id = ? LIMIT 1");
        $stmt->execute([$claimId, $lineAccountId]);
        $claim = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$claim) {
            return ['success' => false, 'error' => 'ไม่พบรายการนี้'];
        }
        if ($claim['status'] !== 'pending_review') {
            return ['success' => false, 'error' => 'รายการนี้ถูกดำเนินการไปแล้ว'];
        }

        $lp = new LoyaltyPoints($db, $lineAccountId);
        $lp->addPoints((int) $claim['user_id'], $points, 'receipt', $claimId, $description);

        $upd = $db->prepare("UPDATE receipt_point_claims SET status = 'approved', points_awarded = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?");
        $upd->execute([$points, $adminUserId, $claimId]);

        // Best-effort LINE push — never let a messaging failure roll back the award.
        try {
            $u = $db->prepare("SELECT line_user_id FROM users WHERE id = ? LIMIT 1");
            $u->execute([(int) $claim['user_id']]);
            $lineUserId = $u->fetchColumn();
            if ($lineUserId) {
                $newBalance = (int) $lp->getUserPoints((int) $claim['user_id'])['available_points'];
                $manager = new LineAccountManager($db);
                $line = $manager->getLineAPI($lineAccountId);
                if ($line) {
                    $line->pushMessage($lineUserId, [[
                        'type' => 'text',
                        'text' => "✅ ใบเสร็จของคุณได้รับการตรวจสอบแล้ว ได้รับ +{$points} แต้ม (แต้มสะสมรวม {$newBalance})",
                    ]]);
                }
            }
        } catch (\Throwable $e) {
            error_log('ReceiptPointsAdmin push notify failed: ' . $e->getMessage());
        }

        return ['success' => true, 'points_awarded' => $points];
    }
}
