<?php
/**
 * Broadcast Drafts API
 *
 * JSON endpoint backing the Catalog Builder's Save/Load/List/Delete flow.
 * Stores the full builder state (bubbles, layout, theme) so an operator can
 * resume a half-built carousel later.
 *
 * Methods (selected via ?action=…):
 *   GET    list        → {success, drafts:[{id,name,updated_at}]}
 *   GET    load&id=    → {success, draft:{id,name,payload}}
 *   POST   save        → body: {name, payload, id?}     → {success, id}
 *   POST   delete      → body: {id}                     → {success}
 *
 * Auth: relies on session $_SESSION['current_bot_id'] being set (same pattern
 * as broadcast.php). Drafts are scoped to that line_account_id.
 *
 * Schema: see database/migration_2026-05-04_unified_broadcast.sql
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');

$db = Database::getInstance()->getConnection();
$botId = $_SESSION['current_bot_id'] ?? null;
$userId = $_SESSION['user_id'] ?? null;

if (!$botId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Missing bot context']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$body = [];
if ($method === 'POST') {
    $raw = file_get_contents('php://input') ?: '';
    $body = $raw ? (json_decode($raw, true) ?: []) : ($_POST ?: []);
    if (!$action) {
        $action = $body['action'] ?? '';
    }
}

try {
    switch ($action) {
        case 'list':
            $stmt = $db->prepare(
                "SELECT id, name, source, updated_at, created_at
                   FROM broadcast_drafts
                  WHERE line_account_id = ? OR line_account_id IS NULL
                  ORDER BY updated_at DESC LIMIT 50"
            );
            $stmt->execute([$botId]);
            echo json_encode([
                'success' => true,
                'drafts'  => $stmt->fetchAll(PDO::FETCH_ASSOC),
            ]);
            break;

        case 'load':
            $id = (int)($_GET['id'] ?? 0);
            if ($id <= 0) {
                throw new InvalidArgumentException('Missing id');
            }
            $stmt = $db->prepare(
                "SELECT id, name, source, payload, updated_at
                   FROM broadcast_drafts
                  WHERE id = ? AND (line_account_id = ? OR line_account_id IS NULL)"
            );
            $stmt->execute([$id, $botId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Draft not found']);
                break;
            }
            // Decode payload so JS gets an object, not a JSON string blob.
            $row['payload'] = json_decode($row['payload'], true);
            echo json_encode(['success' => true, 'draft' => $row]);
            break;

        case 'save':
            $name = trim((string)($body['name'] ?? ''));
            $payload = $body['payload'] ?? null;
            $source = trim((string)($body['source'] ?? 'catalog')) ?: 'catalog';
            $id = isset($body['id']) ? (int)$body['id'] : 0;

            if ($name === '') {
                throw new InvalidArgumentException('Missing name');
            }
            if (!is_array($payload)) {
                throw new InvalidArgumentException('Payload must be an object');
            }
            $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);
            if ($payloadJson === false) {
                throw new RuntimeException('Failed to encode payload');
            }

            if ($id > 0) {
                // Update — verify ownership
                $stmt = $db->prepare(
                    "UPDATE broadcast_drafts
                        SET name = ?, source = ?, payload = ?
                      WHERE id = ? AND (line_account_id = ? OR line_account_id IS NULL)"
                );
                $stmt->execute([$name, $source, $payloadJson, $id, $botId]);
                echo json_encode(['success' => true, 'id' => $id]);
            } else {
                $stmt = $db->prepare(
                    "INSERT INTO broadcast_drafts (line_account_id, created_by, name, source, payload)
                     VALUES (?, ?, ?, ?, ?)"
                );
                $stmt->execute([$botId, $userId, $name, $source, $payloadJson]);
                echo json_encode(['success' => true, 'id' => (int)$db->lastInsertId()]);
            }
            break;

        case 'delete':
            $id = (int)($body['id'] ?? 0);
            if ($id <= 0) {
                throw new InvalidArgumentException('Missing id');
            }
            $stmt = $db->prepare(
                "DELETE FROM broadcast_drafts
                  WHERE id = ? AND (line_account_id = ? OR line_account_id IS NULL)"
            );
            $stmt->execute([$id, $botId]);
            echo json_encode(['success' => true, 'deleted' => $stmt->rowCount()]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Unknown action']);
            break;
    }
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Exception $e) {
    error_log('broadcast_drafts: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error']);
}
