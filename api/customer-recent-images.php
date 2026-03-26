<?php
/**
 * Recent incoming LINE chat images for a customer (matching dashboard).
 * GET: customer_ref | line_user_id | partner_id
 * Returns up to 10 image messages from the last 10 days.
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';

use Modules\Core\Database;

try {
    $db = Database::getInstance()->getConnection();

    $lineUserId  = trim((string) ($_GET['line_user_id'] ?? ''));
    $customerRef = trim((string) ($_GET['customer_ref'] ?? ''));
    $partnerId   = (int) ($_GET['partner_id'] ?? 0);

    if ($lineUserId === '' && $customerRef !== '') {
        try {
            $stmt = $db->prepare('SELECT line_user_id FROM odoo_line_users WHERE odoo_customer_code = ? AND line_user_id IS NOT NULL LIMIT 1');
            $stmt->execute([$customerRef]);
            $row = $stmt->fetchColumn();
            if ($row) {
                $lineUserId = $row;
            }
        } catch (Exception $e) { /* ignore */
        }
        if ($lineUserId === '') {
            try {
                $stmt = $db->prepare('SELECT line_user_id FROM odoo_bdos WHERE customer_ref = ? AND line_user_id IS NOT NULL ORDER BY updated_at DESC LIMIT 1');
                $stmt->execute([$customerRef]);
                $row = $stmt->fetchColumn();
                if ($row) {
                    $lineUserId = $row;
                }
            } catch (Exception $e) { /* ignore */
            }
        }
    }

    if ($lineUserId === '' && $partnerId > 0) {
        try {
            $stmt = $db->prepare('SELECT line_user_id FROM odoo_line_users WHERE odoo_partner_id = ? AND line_user_id IS NOT NULL LIMIT 1');
            $stmt->execute([$partnerId]);
            $row = $stmt->fetchColumn();
            if ($row) {
                $lineUserId = $row;
            }
        } catch (Exception $e) { /* ignore */
        }
    }

    if ($lineUserId === '') {
        throw new Exception('Could not resolve line_user_id');
    }

    $stmt = $db->prepare('SELECT id FROM users WHERE line_user_id = ? LIMIT 1');
    $stmt->execute([$lineUserId]);
    $userRow = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$userRow) {
        echo json_encode([
            'success' => true,
            'data'    => [
                'images'      => [],
                'line_user_id'=> $lineUserId,
                'user_id'     => null,
            ],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $userId = (int) $userRow['id'];

    $stmt = $db->prepare("
        SELECT id, content, created_at, message_type
        FROM messages
        WHERE user_id = ?
          AND direction = 'incoming'
          AND message_type = 'image'
          AND created_at >= DATE_SUB(NOW(), INTERVAL 10 DAY)
        ORDER BY created_at DESC
        LIMIT 10
    ");
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $baseUrl = rtrim(defined('SITE_URL') ? SITE_URL : '', '/');
    $images  = [];

    foreach ($rows as $row) {
        $content = (string) ($row['content'] ?? '');
        $lineMessageId = null;
        if (preg_match('/\[image\]\s*ID:\s*(\S+)/i', $content, $m)) {
            $lineMessageId = $m[1];
        }

        $imageUrl = null;
        $thumbUrl = null;
        if (preg_match('#^https?://#i', trim($content))) {
            $imageUrl = trim($content);
            $thumbUrl = $imageUrl;
        } elseif ($lineMessageId) {
            $thumbUrl = 'api/line_content.php?id=' . rawurlencode($lineMessageId) . '&thumb=1&w=120&h=120';
            $imageUrl = 'api/line_content.php?id=' . rawurlencode($lineMessageId);
        }

        $images[] = [
            'id'              => (int) $row['id'],
            'created_at'      => $row['created_at'],
            'image_url'       => $imageUrl,
            'thumb_url'       => $thumbUrl,
            'line_message_id' => $lineMessageId,
            'content_raw'     => $content,
        ];
    }

    echo json_encode([
        'success' => true,
        'data'    => [
            'images'       => $images,
            'line_user_id' => $lineUserId,
            'user_id'      => $userId,
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
