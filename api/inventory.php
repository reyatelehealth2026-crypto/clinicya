<?php
/**
 * Inventory API - Stock Management
 */
header('Content-Type: application/json; charset=utf-8');

session_start();

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/InventoryService.php';
require_once __DIR__ . '/../classes/SupplierService.php';
require_once __DIR__ . '/../classes/PurchaseOrderService.php';
require_once __DIR__ . '/../classes/AccountPayableService.php';

$db = Database::getInstance()->getConnection();
$lineAccountId = $_SESSION['current_bot_id'] ?? null;
$adminId = $_SESSION['admin_user']['id'] ?? null;

// Load ProductUnitService if exists (after $db is initialized)
$productUnitService = null;
if (file_exists(__DIR__ . '/../classes/ProductUnitService.php')) {
    require_once __DIR__ . '/../classes/ProductUnitService.php';
    $productUnitService = new ProductUnitService($db, $lineAccountId);
}

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
            $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            $poId = (int)($data['po_id'] ?? $_POST['po_id'] ?? $_GET['po_id'] ?? 0);
            
            if (!$poId) {
                throw new Exception('PO ID is required');
            }
            
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
            $apId = null;
            if (!empty($data['confirm'])) {
                $poService->confirmGR($grId, $adminId);
                
                // Hook: Auto-create Account Payable from confirmed GR
                // Requirement 8.1: WHEN a GR is completed THEN the Accounting System SHALL automatically create corresponding AP record
                try {
                    $apService = new AccountPayableService($db, $lineAccountId);
                    $apId = $apService->createFromGR($grId);
                } catch (Exception $apError) {
                    // Log AP creation error but don't fail the GR creation
                    error_log("AP creation from GR failed: " . $apError->getMessage());
                }
            }
            
            $response = ['success' => true, 'data' => $result];
            if ($apId) {
                $response['ap_id'] = $apId;
            }
            echo json_encode($response);
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
            
            // Hook: Auto-create Account Payable from confirmed GR
            // Requirement 8.1: WHEN a GR is completed THEN the Accounting System SHALL automatically create corresponding AP record
            try {
                $apService = new AccountPayableService($db, $lineAccountId);
                $apId = $apService->createFromGR($id);
                echo json_encode([
                    'success' => true, 
                    'message' => 'GR confirmed, stock updated, AP created',
                    'ap_id' => $apId
                ]);
            } catch (Exception $apError) {
                // Log AP creation error but don't fail the GR confirmation
                error_log("AP creation from GR failed: " . $apError->getMessage());
                echo json_encode([
                    'success' => true, 
                    'message' => 'GR confirmed, stock updated (AP creation skipped: ' . $apError->getMessage() . ')'
                ]);
            }
            break;
            
        // ==================== Bulk Order & Auto Reorder ====================
        case 'bulk_create_po':
            $data = json_decode(file_get_contents('php://input'), true);
            $supplierId = $data['supplier_id'] ?? null;
            $groupBySupplier = $data['group_by_supplier'] ?? false;
            $items = $data['items'] ?? [];
            
            if (empty($items)) {
                throw new Exception('No items provided');
            }
            
            $poIds = [];
            
            if ($groupBySupplier) {
                // Group items by supplier
                $itemsBySupplier = [];
                foreach ($items as $item) {
                    $sid = $item['supplier_id'] ?: 'default';
                    if (!isset($itemsBySupplier[$sid])) {
                        $itemsBySupplier[$sid] = [];
                    }
                    $itemsBySupplier[$sid][] = $item;
                }
                
                // Get default supplier
                $defaultSupplier = null;
                try {
                    $stmt = $db->prepare("SELECT id FROM suppliers WHERE code = 'SUP-DEFAULT' LIMIT 1");
                    $stmt->execute();
                    $defaultSupplier = $stmt->fetchColumn();
                } catch (Exception $e) {}
                
                // Create PO for each supplier
                foreach ($itemsBySupplier as $sid => $supplierItems) {
                    $actualSupplierId = $sid === 'default' ? ($defaultSupplier ?: $supplierId) : $sid;
                    if (!$actualSupplierId) continue;
                    
                    $poResult = $poService->createPO([
                        'supplier_id' => $actualSupplierId,
                        'order_date' => date('Y-m-d'),
                        'notes' => 'Bulk Order - Auto Reorder',
                        'created_by' => $adminId
                    ]);
                    
                    foreach ($supplierItems as $item) {
                        $poService->addPOItem($poResult['id'], [
                            'product_id' => $item['id'],
                            'quantity' => $item['quantity'],
                            'unit_cost' => $item['cost'] ?? 0,
                            'unit_id' => $item['unit_id'] ?? null,
                            'unit_name' => $item['unit_name'] ?? null,
                            'unit_factor' => $item['unit_factor'] ?? 1
                        ]);
                    }
                    
                    $poIds[] = $poResult['id'];
                }
            } else {
                // Single PO for all items
                if (!$supplierId) {
                    throw new Exception('Supplier is required');
                }
                
                $poResult = $poService->createPO([
                    'supplier_id' => $supplierId,
                    'order_date' => date('Y-m-d'),
                    'notes' => 'Bulk Order',
                    'created_by' => $adminId
                ]);
                
                foreach ($items as $item) {
                    $poService->addPOItem($poResult['id'], [
                        'product_id' => $item['id'],
                        'quantity' => $item['quantity'],
                        'unit_cost' => $item['cost'] ?? 0,
                        'unit_id' => $item['unit_id'] ?? null,
                        'unit_name' => $item['unit_name'] ?? null,
                        'unit_factor' => $item['unit_factor'] ?? 1
                    ]);
                }
                
                $poIds[] = $poResult['id'];
            }
            
            echo json_encode([
                'success' => true, 
                'data' => [
                    'po_count' => count($poIds),
                    'po_ids' => $poIds
                ]
            ]);
            break;
            
        // ==================== Product Units ====================
        case 'get_product_units':
            $productId = (int)$_GET['product_id'];
            if (!$productUnitService) {
                echo json_encode(['success' => true, 'units' => []]);
                break;
            }
            $units = $productUnitService->getProductUnits($productId);
            echo json_encode(['success' => true, 'units' => $units]);
            break;
            
        case 'create_product_unit':
            if (!$productUnitService) {
                throw new Exception('Product Unit Service not available');
            }
            $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            $unitId = $productUnitService->createUnit($data);
            echo json_encode(['success' => true, 'unit_id' => $unitId]);
            break;
            
        case 'update_product_unit':
            if (!$productUnitService) {
                throw new Exception('Product Unit Service not available');
            }
            $unitId = (int)($_POST['unit_id'] ?? $_GET['unit_id']);
            $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            $productUnitService->updateUnit($unitId, $data);
            echo json_encode(['success' => true]);
            break;
            
        case 'delete_product_unit':
            if (!$productUnitService) {
                throw new Exception('Product Unit Service not available');
            }
            $unitId = (int)($_POST['unit_id'] ?? $_GET['unit_id']);
            $productUnitService->deleteUnit($unitId);
            echo json_encode(['success' => true]);
            break;
            
        case 'auto_reorder':
            // Get all products at reorder point
            $products = $inventoryService->getProductsAtReorderPoint();
            
            if (empty($products)) {
                echo json_encode(['success' => true, 'data' => ['po_count' => 0, 'item_count' => 0]]);
                break;
            }
            
            // Group by supplier
            $itemsBySupplier = [];
            foreach ($products as $p) {
                $sid = $p['supplier_id'] ?: 'default';
                if (!isset($itemsBySupplier[$sid])) {
                    $itemsBySupplier[$sid] = [];
                }
                $rop = $p['reorder_point'] ?? 5;
                $orderQty = max($rop * 2 - $p['stock'], $rop);
                $itemsBySupplier[$sid][] = [
                    'id' => $p['id'],
                    'quantity' => $orderQty,
                    'cost' => $p['cost_price'] ?? 0
                ];
            }
            
            // Get default supplier
            $defaultSupplier = null;
            try {
                $stmt = $db->prepare("SELECT id FROM suppliers WHERE code = 'SUP-DEFAULT' LIMIT 1");
                $stmt->execute();
                $defaultSupplier = $stmt->fetchColumn();
            } catch (Exception $e) {}
            
            $poIds = [];
            $itemCount = 0;
            
            foreach ($itemsBySupplier as $sid => $supplierItems) {
                $actualSupplierId = $sid === 'default' ? $defaultSupplier : $sid;
                if (!$actualSupplierId) continue;
                
                $poResult = $poService->createPO([
                    'supplier_id' => $actualSupplierId,
                    'order_date' => date('Y-m-d'),
                    'notes' => 'Auto Reorder - ROP',
                    'created_by' => $adminId
                ]);
                
                foreach ($supplierItems as $item) {
                    $poService->addPOItem($poResult['id'], [
                        'product_id' => $item['id'],
                        'quantity' => $item['quantity'],
                        'unit_cost' => $item['cost']
                    ]);
                    $itemCount++;
                }
                
                $poIds[] = $poResult['id'];
            }
            
            echo json_encode([
                'success' => true,
                'data' => [
                    'po_count' => count($poIds),
                    'item_count' => $itemCount,
                    'po_ids' => $poIds
                ]
            ]);
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
