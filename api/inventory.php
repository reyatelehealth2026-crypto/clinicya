<?php
/**
 * Inventory API - Stock Management
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/InventoryService.php';
require_once __DIR__ . '/../classes/SupplierService.php';
require_once __DIR__ . '/../classes/PurchaseOrderService.php';

session_start();

$db = Database::getInstance()->getConnection();
$lineAccountId = $_SESSION['current_bot_id'] ?? null;
$adminId = $_SESSION['admin_user']['id'] ?? null;

$inventoryService = new InventoryService($db, $lineAccountId);
$supplierService = new SupplierService($db, $lineAccountId);
$poService = new PurchaseOrderService($db, $lineAccountId);

$action = $_REQUEST['action'] ?? '';

try {
    switch ($action) {
        // ==================== Stock ====================
        case 'get_stock':
            $productId = (int)$_GET['product_id'];
            $stock = $inventoryService->getProductStock($productId);
            echo json_encode(['success' => true, 'stock' => $stock]);
            break;
            
        case 'low_stock':
            $products = $inventoryService->getLowStockProducts();
            echo json_encode(['success' => true, 'products' => $products]);
            break;
            
        case 'stock_valuation':
            $data = $inventoryService->getStockValuation();
            echo json_encode(['success' => true, 'data' => $data]);
            break;
            
        case 'stock_movements':
            $productId = isset($_GET['product_id']) ? (int)$_GET['product_id'] : null;
            $filters = [
                'movement_type' => $_GET['movement_type'] ?? null,
                'date_from' => $_GET['date_from'] ?? null,
                'date_to' => $_GET['date_to'] ?? null,
                'limit' => $_GET['limit'] ?? 100
            ];
            $movements = $inventoryService->getStockMovements($productId, $filters);
            echo json_encode(['success' => true, 'movements' => $movements]);
            break;
            
        // ==================== Adjustment ====================
        case 'create_adjustment':
            $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            $data['created_by'] = $adminId;
            $result = $inventoryService->createAdjustment($data);
            echo json_encode(['success' => true, 'data' => $result]);
            break;
            
        case 'confirm_adjustment':
            $id = (int)($_POST['id'] ?? $_GET['id']);
            $inventoryService->confirmAdjustment($id);
            echo json_encode(['success' => true, 'message' => 'Adjustment confirmed']);
            break;
            
        case 'get_adjustments':
            $filters = [
                'status' => $_GET['status'] ?? null,
                'limit' => $_GET['limit'] ?? 50
            ];
            $adjustments = $inventoryService->getAdjustments($filters);
            echo json_encode(['success' => true, 'adjustments' => $adjustments]);
            break;

        // ==================== Suppliers ====================
        case 'get_suppliers':
            $filters = [
                'is_active' => isset($_GET['active']) ? (int)$_GET['active'] : null,
                'search' => $_GET['search'] ?? null
            ];
            $suppliers = $supplierService->getAll($filters);
            echo json_encode(['success' => true, 'suppliers' => $suppliers]);
            break;
            
        case 'get_supplier':
            $id = (int)$_GET['id'];
            $supplier = $supplierService->getById($id);
            echo json_encode(['success' => true, 'supplier' => $supplier]);
            break;
            
        case 'create_supplier':
            $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            $id = $supplierService->create($data);
            echo json_encode(['success' => true, 'id' => $id]);
            break;
            
        case 'update_supplier':
            $id = (int)($_POST['id'] ?? $_GET['id']);
            $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            $supplierService->update($id, $data);
            echo json_encode(['success' => true, 'message' => 'Supplier updated']);
            break;
            
        case 'toggle_supplier':
            $id = (int)($_POST['id'] ?? $_GET['id']);
            $active = (int)($_POST['active'] ?? $_GET['active']);
            if ($active) {
                $supplierService->activate($id);
            } else {
                $supplierService->deactivate($id);
            }
            echo json_encode(['success' => true]);
            break;
            
        // ==================== Purchase Orders ====================
        case 'get_pos':
            $filters = [
                'status' => $_GET['status'] ?? null,
                'supplier_id' => $_GET['supplier_id'] ?? null,
                'date_from' => $_GET['date_from'] ?? null,
                'date_to' => $_GET['date_to'] ?? null,
                'limit' => $_GET['limit'] ?? 50
            ];
            $pos = $poService->getAllPOs($filters);
            echo json_encode(['success' => true, 'purchase_orders' => $pos]);
            break;
            
        case 'get_po':
            $id = (int)$_GET['id'];
            $po = $poService->getPO($id);
            $items = $poService->getPOItems($id);
            echo json_encode(['success' => true, 'po' => $po, 'items' => $items]);
            break;
            
        case 'create_po':
            $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            $data['created_by'] = $adminId;
            $result = $poService->createPO($data);
            echo json_encode(['success' => true, 'data' => $result]);
            break;
            
        case 'add_po_item':
            $poId = (int)($_POST['po_id'] ?? $_GET['po_id']);
            $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            $itemId = $poService->addPOItem($poId, $data);
            echo json_encode(['success' => true, 'item_id' => $itemId]);
            break;
            
        case 'update_po_item':
            $itemId = (int)($_POST['item_id'] ?? $_GET['item_id']);
            $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            $poService->updatePOItem($itemId, $data);
            echo json_encode(['success' => true]);
            break;
            
        case 'remove_po_item':
            $itemId = (int)($_POST['item_id'] ?? $_GET['item_id']);
            $poService->removePOItem($itemId);
            echo json_encode(['success' => true]);
            break;
            
        case 'submit_po':
            $id = (int)($_POST['id'] ?? $_GET['id']);
            $poService->submitPO($id);
            echo json_encode(['success' => true, 'message' => 'PO submitted']);
            break;
            
        case 'cancel_po':
            $id = (int)($_POST['id'] ?? $_GET['id']);
            $reason = $_POST['reason'] ?? 'Cancelled by admin';
            $poService->cancelPO($id, $reason);
            echo json_encode(['success' => true, 'message' => 'PO cancelled']);
            break;
            
        // ==================== Goods Receive ====================
        case 'get_grs':
            $filters = [
                'status' => $_GET['status'] ?? null,
                'po_id' => $_GET['po_id'] ?? null,
                'limit' => $_GET['limit'] ?? 50
            ];
            $grs = $poService->getAllGRs($filters);
            echo json_encode(['success' => true, 'goods_receives' => $grs]);
            break;
            
        case 'get_gr':
            $id = (int)$_GET['id'];
            $gr = $poService->getGR($id);
            $items = $poService->getGRItems($id);
            echo json_encode(['success' => true, 'gr' => $gr, 'items' => $items]);
            break;
            
        case 'create_gr':
            $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            $data['received_by'] = $adminId;
            
            // Create GR
            $result = $poService->createGR($data['po_id'], $data);
            $grId = $result['id'];
            
            // Add items if provided
            if (!empty($data['items'])) {
                // Get PO items to map
                $poItems = $poService->getPOItems($data['po_id']);
                $poItemMap = [];
                foreach ($poItems as $poi) {
                    $poItemMap[$poi['id']] = $poi;
                }
                
                foreach ($data['items'] as $poItemId => $qty) {
                    if ($qty > 0 && isset($poItemMap[$poItemId])) {
                        $poService->addGRItem($grId, [
                            'po_item_id' => $poItemId,
                            'product_id' => $poItemMap[$poItemId]['product_id'],
                            'quantity' => $qty
                        ]);
                    }
                }
            }
            
            // Confirm if requested
            if (!empty($data['confirm'])) {
                $poService->confirmGR($grId, $adminId);
            }
            
            echo json_encode(['success' => true, 'data' => $result]);
            break;
            
        case 'add_gr_item':
            $grId = (int)($_POST['gr_id'] ?? $_GET['gr_id']);
            $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            $itemId = $poService->addGRItem($grId, $data);
            echo json_encode(['success' => true, 'item_id' => $itemId]);
            break;
            
        case 'confirm_gr':
            $id = (int)($_POST['id'] ?? $_GET['id']);
            $poService->confirmGR($id, $adminId);
            echo json_encode(['success' => true, 'message' => 'GR confirmed, stock updated']);
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
