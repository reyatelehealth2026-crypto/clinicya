<?php
/**
 * Planogram View - Visual Shelf Display
 * แสดงผังชั้นวางสินค้าแบบ Visual
 * 
 * Features:
 * - Visual shelf grid display
 * - Click to view/assign products
 * - Color-coded status (empty/occupied/full/expiring)
 */

require_once __DIR__ . '/../../classes/LocationService.php';

$locationService = new LocationService($db, $lineAccountId);

// Get all zones
$zones = $locationService->getZones();

// Get selected zone (default to first zone)
$selectedZone = $_GET['zone'] ?? ($zones[0]['zone'] ?? '');

// Get shelf data for selected zone
$shelfData = [];
$maxShelf = 0;
$maxBin = 0;

if ($selectedZone) {
    // Get all locations in this zone
    $locations = $locationService->getLocations(['zone' => $selectedZone, 'is_active' => 1]);
    
    foreach ($locations as $loc) {
        $shelf = (int)$loc['shelf'];
        $bin = (int)$loc['bin'];
        $shelfData[$shelf][$bin] = $loc;
        
        if ($shelf > $maxShelf) $maxShelf = $shelf;
        if ($bin > $maxBin) $maxBin = $bin;
    }
}

// Get products in locations for this zone (with actual stock from business_items)
$productsInLocations = [];
try {
    $stmt = $db->prepare("
        SELECT ib.*, wl.location_code, wl.shelf, wl.bin, 
               bi.name as product_name, bi.sku, bi.stock as actual_stock,
               DATEDIFF(ib.expiry_date, CURDATE()) as days_until_expiry
        FROM inventory_batches ib
        JOIN warehouse_locations wl ON ib.location_id = wl.id
        JOIN business_items bi ON ib.product_id = bi.id
        WHERE wl.zone = ? AND ib.status = 'active' AND ib.quantity_available > 0
    ");
    $stmt->execute([$selectedZone]);
    $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($batches as $batch) {
        $key = $batch['shelf'] . '-' . $batch['bin'];
        if (!isset($productsInLocations[$key])) {
            $productsInLocations[$key] = [];
        }
        $productsInLocations[$key][] = $batch;
    }
} catch (Exception $e) {
    // Table might not exist
}

/**
 * Get cell status and color
 */
function getCellStatus($location, $products) {
    if (!$location) return ['status' => 'none', 'color' => 'gray-200', 'text' => 'ไม่มี'];
    
    $utilization = $location['capacity'] > 0 ? ($location['current_qty'] / $location['capacity']) * 100 : 0;
    
    if (empty($products)) {
        return ['status' => 'empty', 'color' => 'green-100', 'text' => 'ว่าง', 'border' => 'green-300'];
    }
    
    // Check for expiring products
    $hasExpiring = false;
    foreach ($products as $p) {
        if (isset($p['days_until_expiry']) && $p['days_until_expiry'] <= 30) {
            $hasExpiring = true;
            break;
        }
    }
    
    if ($hasExpiring) {
        return ['status' => 'expiring', 'color' => 'red-100', 'text' => 'ใกล้หมดอายุ', 'border' => 'red-400'];
    }
    
    if ($utilization >= 90) {
        return ['status' => 'full', 'color' => 'orange-100', 'text' => 'เกือบเต็ม', 'border' => 'orange-400'];
    }
    
    return ['status' => 'occupied', 'color' => 'blue-100', 'text' => 'มีสินค้า', 'border' => 'blue-400'];
}
?>

<div class="space-y-6">
    <!-- Zone Selector -->
    <div class="bg-white rounded-xl shadow p-4">
        <div class="flex flex-wrap items-center gap-4">
            <div class="flex items-center gap-2">
                <i class="fas fa-warehouse text-blue-500"></i>
                <span class="font-medium">เลือกโซน:</span>
            </div>
            <div class="flex flex-wrap gap-2">
                <?php foreach ($zones as $zone): ?>
                <a href="?tab=planogram&zone=<?= urlencode($zone['zone']) ?>" 
                   class="px-4 py-2 rounded-lg <?= $selectedZone === $zone['zone'] ? 'bg-blue-600 text-white' : 'bg-gray-100 hover:bg-gray-200' ?>">
                    <?= htmlspecialchars($zone['zone']) ?>
                    <span class="text-xs opacity-75">(<?= $zone['location_count'] ?>)</span>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <?php if ($selectedZone && $maxShelf > 0): ?>
    <!-- Legend -->
    <div class="bg-white rounded-xl shadow p-4">
        <div class="flex flex-wrap items-center gap-4 text-sm">
            <span class="font-medium">สถานะ:</span>
            <div class="flex items-center gap-1">
                <div class="w-4 h-4 bg-green-100 border-2 border-green-300 rounded"></div>
                <span>ว่าง</span>
            </div>
            <div class="flex items-center gap-1">
                <div class="w-4 h-4 bg-blue-100 border-2 border-blue-400 rounded"></div>
                <span>มีสินค้า</span>
            </div>
            <div class="flex items-center gap-1">
                <div class="w-4 h-4 bg-orange-100 border-2 border-orange-400 rounded"></div>
                <span>เกือบเต็ม</span>
            </div>
            <div class="flex items-center gap-1">
                <div class="w-4 h-4 bg-red-100 border-2 border-red-400 rounded"></div>
                <span>ใกล้หมดอายุ</span>
            </div>
            <div class="flex items-center gap-1">
                <div class="w-4 h-4 bg-gray-200 border-2 border-gray-300 rounded"></div>
                <span>ไม่มีตำแหน่ง</span>
            </div>
        </div>
    </div>

    <!-- Planogram Grid -->
    <div class="bg-white rounded-xl shadow p-4">
        <div class="flex items-center justify-between mb-4">
            <h2 class="font-semibold text-lg">
                <i class="fas fa-th mr-2 text-blue-500"></i>
                ผังชั้นวาง: โซน <?= htmlspecialchars($selectedZone) ?>
            </h2>
            <div class="text-sm text-gray-500">
                <?= $maxShelf ?> ชั้น × <?= $maxBin ?> ช่อง
            </div>
        </div>
        
        <!-- Shelf Visual - Display from top shelf to bottom -->
        <div class="overflow-x-auto">
            <div class="min-w-max">
                <!-- Column Headers (Bin numbers) -->
                <div class="flex items-center mb-2">
                    <div class="w-20 text-center text-xs text-gray-500 font-medium">ชั้น/ช่อง</div>
                    <?php for ($bin = 1; $bin <= $maxBin; $bin++): ?>
                    <div class="w-36 text-center text-xs text-gray-500 font-medium"><?= $bin ?></div>
                    <?php endfor; ?>
                </div>
                
                <!-- Shelves (from top to bottom) -->
                <?php for ($shelf = $maxShelf; $shelf >= 1; $shelf--): ?>
                <div class="flex items-stretch mb-1">
                    <!-- Shelf Label -->
                    <div class="w-20 flex items-center justify-center bg-gray-100 rounded-l-lg text-sm font-medium">
                        ชั้น <?= $shelf ?>
                        <?php 
                        // Ergonomic indicator
                        $ergonomic = '';
                        if ($shelf == ceil($maxShelf / 2) || $shelf == ceil($maxShelf / 2) + 1) {
                            $ergonomic = '⭐';
                        }
                        echo $ergonomic;
                        ?>
                    </div>
                    
                    <!-- Bins -->
                    <?php for ($bin = 1; $bin <= $maxBin; $bin++): 
                        $location = $shelfData[$shelf][$bin] ?? null;
                        $key = $shelf . '-' . $bin;
                        $products = $productsInLocations[$key] ?? [];
                        $cellStatus = getCellStatus($location, $products);
                    ?>
                    <div class="w-36 min-h-28 p-1 border-r border-gray-200 last:border-r-0">
                        <?php if ($location): ?>
                        <div onclick="showLocationDetail(<?= $location['id'] ?>)" 
                             class="w-full h-full bg-<?= $cellStatus['color'] ?> border-2 border-<?= $cellStatus['border'] ?? 'gray-300' ?> 
                                    rounded-lg cursor-pointer hover:shadow-md transition-all flex flex-col p-2">
                            <div class="text-xs font-mono font-bold text-gray-700 text-center"><?= $location['location_code'] ?></div>
                            <?php if (!empty($products)): 
                                $product = $products[0];
                                $productName = $product['product_name'];
                            ?>
                            <div class="flex-1 mt-1">
                                <div class="text-sm font-medium text-gray-800 leading-tight line-clamp-2" title="<?= htmlspecialchars($productName) ?>">
                                    <?= htmlspecialchars($productName) ?>
                                </div>
                                <?php if (!empty($product['sku'])): ?>
                                <div class="text-xs text-gray-500 mt-0.5">SKU: <?= htmlspecialchars($product['sku']) ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="mt-1 pt-1 border-t border-gray-200">
                                <div class="flex justify-between items-center text-xs">
                                    <span class="font-bold text-blue-600"><?= $product['quantity_available'] ?> ชิ้น</span>
                                    <span class="text-gray-500">(คงเหลือ <?= number_format($product['actual_stock'] ?? 0) ?>)</span>
                                </div>
                                <?php if (count($products) > 1): ?>
                                <div class="text-xs text-purple-600 font-medium mt-0.5">+<?= count($products) - 1 ?> รายการอื่น</div>
                                <?php endif; ?>
                            </div>
                            <?php else: ?>
                            <div class="flex-1 flex flex-col items-center justify-center">
                                <div class="text-sm text-green-600 font-medium">ว่าง</div>
                                <div class="text-xs text-gray-400">ความจุ <?= $location['capacity'] ?> ชิ้น</div>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php else: ?>
                        <div class="w-full h-full min-h-24 bg-gray-100 border-2 border-dashed border-gray-300 rounded-lg 
                                    flex items-center justify-center text-gray-400 text-xs">
                            -
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endfor; ?>
                </div>
                <?php endfor; ?>
                
                <!-- Floor indicator -->
                <div class="flex items-center mt-2 pt-2 border-t-4 border-gray-400">
                    <div class="w-16"></div>
                    <div class="flex-1 text-center text-gray-500 text-sm">
                        <i class="fas fa-arrow-down mr-1"></i> พื้น
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <?php else: ?>
    <div class="bg-white rounded-xl shadow p-8 text-center text-gray-500">
        <i class="fas fa-warehouse text-4xl mb-3"></i>
        <p>ไม่พบข้อมูลตำแหน่งจัดเก็บ</p>
        <p class="text-sm mt-2">กรุณาสร้างตำแหน่งใน tab "ตำแหน่งจัดเก็บ" ก่อน</p>
    </div>
    <?php endif; ?>
</div>

<!-- Location Detail Modal -->
<div id="locationDetailModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 flex items-center justify-center">
    <div class="bg-white rounded-xl shadow-xl max-w-lg w-full mx-4 max-h-[90vh] overflow-y-auto">
        <div class="p-4 border-b flex justify-between items-center">
            <h3 class="font-semibold text-lg"><i class="fas fa-map-marker-alt mr-2 text-blue-500"></i>รายละเอียดตำแหน่ง</h3>
            <button onclick="closeLocationDetailModal()" class="text-gray-500 hover:text-gray-700">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div id="locationDetailContent" class="p-4">
            <div class="text-center py-8">
                <i class="fas fa-spinner fa-spin text-3xl text-gray-400"></i>
            </div>
        </div>
    </div>
</div>

<!-- Assign Product Modal -->
<div id="assignProductModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 flex items-center justify-center">
    <div class="bg-white rounded-xl shadow-xl max-w-lg w-full mx-4">
        <div class="p-4 border-b flex justify-between items-center">
            <h3 class="font-semibold text-lg"><i class="fas fa-plus-circle mr-2 text-green-500"></i>จัดเก็บสินค้า</h3>
            <button onclick="closeAssignProductModal()" class="text-gray-500 hover:text-gray-700">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form id="assignProductForm" class="p-4 space-y-4">
            <input type="hidden" name="location_id" id="assignLocationId">
            
            <div class="bg-blue-50 rounded-lg p-3 text-center">
                <div class="text-sm text-blue-600">ตำแหน่งจัดเก็บ</div>
                <div id="assignLocationCode" class="text-xl font-mono font-bold text-blue-700">-</div>
            </div>
            
            <div>
                <label class="block text-sm font-medium mb-1">ค้นหาสินค้า <span class="text-red-500">*</span></label>
                <input type="hidden" name="product_id" id="assignProductId" required>
                <div class="relative">
                    <input type="text" id="assignProductSearch" placeholder="พิมพ์ชื่อสินค้าหรือ SKU..."
                           class="w-full px-3 py-2 border rounded-lg" autocomplete="off">
                    <div id="assignProductResults" class="absolute z-10 w-full bg-white border rounded-lg shadow-lg mt-1 max-h-48 overflow-y-auto hidden"></div>
                </div>
                <div id="assignSelectedProduct" class="mt-2 p-2 bg-green-50 rounded-lg hidden">
                    <span class="text-sm text-green-700"></span>
                    <button type="button" onclick="clearAssignProduct()" class="ml-2 text-red-500 hover:text-red-700">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
            
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-1">จำนวน <span class="text-red-500">*</span></label>
                    <input type="number" name="quantity" id="assignQuantity" required min="1" placeholder="จำนวน"
                           class="w-full px-3 py-2 border rounded-lg">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Batch/Lot</label>
                    <input type="text" name="batch_number" placeholder="เช่น B2024001"
                           class="w-full px-3 py-2 border rounded-lg">
                </div>
            </div>
            
            <div>
                <label class="block text-sm font-medium mb-1">หมายเหตุ</label>
                <input type="text" name="notes" placeholder="หมายเหตุ (ถ้ามี)"
                       class="w-full px-3 py-2 border rounded-lg">
            </div>
            
            <div class="flex gap-2 pt-2">
                <button type="submit" class="flex-1 px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
                    <i class="fas fa-save mr-1"></i>บันทึก
                </button>
                <button type="button" onclick="closeAssignProductModal()" class="px-4 py-2 bg-gray-200 rounded-lg hover:bg-gray-300">
                    ยกเลิก
                </button>
            </div>
        </form>
    </div>
</div>

<script>
let currentLocationId = null;

function showLocationDetail(locationId) {
    currentLocationId = locationId;
    document.getElementById('locationDetailModal').classList.remove('hidden');
    document.getElementById('locationDetailContent').innerHTML = `
        <div class="text-center py-8">
            <i class="fas fa-spinner fa-spin text-3xl text-gray-400"></i>
            <p class="text-gray-500 mt-2">กำลังโหลด...</p>
        </div>
    `;
    
    fetch(`../api/locations.php?action=get&id=${locationId}`)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const loc = data.location;
                const utilization = loc.capacity > 0 ? ((loc.current_qty / loc.capacity) * 100).toFixed(1) : 0;
                
                document.getElementById('locationDetailContent').innerHTML = `
                    <div class="space-y-4">
                        <div class="text-center">
                            <div class="text-3xl font-mono font-bold text-blue-600">${loc.location_code}</div>
                            <div class="text-gray-500">โซน ${loc.zone} | ชั้น ${loc.shelf} | ช่อง ${loc.bin}</div>
                        </div>
                        
                        <div class="grid grid-cols-2 gap-4 text-center">
                            <div class="bg-blue-50 rounded-lg p-3">
                                <div class="text-2xl font-bold text-blue-600">${loc.current_qty}</div>
                                <div class="text-sm text-blue-500">จำนวนปัจจุบัน</div>
                            </div>
                            <div class="bg-green-50 rounded-lg p-3">
                                <div class="text-2xl font-bold text-green-600">${loc.capacity}</div>
                                <div class="text-sm text-green-500">ความจุสูงสุด</div>
                            </div>
                        </div>
                        
                        <div class="bg-gray-50 rounded-lg p-3">
                            <div class="flex justify-between text-sm mb-1">
                                <span>การใช้งาน</span>
                                <span>${utilization}%</span>
                            </div>
                            <div class="w-full bg-gray-200 rounded-full h-2">
                                <div class="bg-blue-500 h-2 rounded-full" style="width: ${Math.min(100, utilization)}%"></div>
                            </div>
                        </div>
                        
                        <div class="flex gap-2">
                            <button onclick="openAssignProductModal(${loc.id}, '${loc.location_code}')" class="flex-1 px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
                                <i class="fas fa-plus mr-1"></i>จัดเก็บสินค้า
                            </button>
                            <button onclick="viewLocationProducts(${loc.id})" class="px-4 py-2 bg-blue-100 text-blue-700 rounded-lg hover:bg-blue-200">
                                <i class="fas fa-boxes"></i>
                            </button>
                        </div>
                    </div>
                `;
            } else {
                document.getElementById('locationDetailContent').innerHTML = `
                    <div class="text-center py-8 text-red-500">
                        <i class="fas fa-exclamation-circle text-3xl mb-2"></i>
                        <p>${data.error || 'ไม่สามารถโหลดข้อมูลได้'}</p>
                    </div>
                `;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            document.getElementById('locationDetailContent').innerHTML = `
                <div class="text-center py-8 text-red-500">
                    <i class="fas fa-exclamation-circle text-3xl mb-2"></i>
                    <p>เกิดข้อผิดพลาด</p>
                </div>
            `;
        });
}

function closeLocationDetailModal() {
    document.getElementById('locationDetailModal').classList.add('hidden');
}

function openAssignProductModal(locationId, locationCode) {
    closeLocationDetailModal();
    document.getElementById('assignLocationId').value = locationId;
    document.getElementById('assignLocationCode').textContent = locationCode;
    document.getElementById('assignProductForm').reset();
    document.getElementById('assignSelectedProduct').classList.add('hidden');
    document.getElementById('assignProductModal').classList.remove('hidden');
}

function closeAssignProductModal() {
    document.getElementById('assignProductModal').classList.add('hidden');
}

// Product search for assignment
let assignSearchTimeout = null;
document.getElementById('assignProductSearch').addEventListener('input', function() {
    const query = this.value.trim();
    const resultsDiv = document.getElementById('assignProductResults');
    
    if (assignSearchTimeout) clearTimeout(assignSearchTimeout);
    
    if (query.length < 2) {
        resultsDiv.classList.add('hidden');
        return;
    }
    
    assignSearchTimeout = setTimeout(async () => {
        try {
            const response = await fetch(`../api/batches.php?action=search_products&q=${encodeURIComponent(query)}`);
            const data = await response.json();
            
            if (data.success && data.products.length > 0) {
                resultsDiv.innerHTML = data.products.map(p => `
                    <div class="px-3 py-2 hover:bg-gray-100 cursor-pointer border-b last:border-b-0" 
                         onclick="selectAssignProduct(${p.id}, '${escapeHtml(p.name)}', '${escapeHtml(p.sku || '')}')">
                        <div class="font-medium">${escapeHtml(p.name)}</div>
                        <div class="text-sm text-gray-500">SKU: ${escapeHtml(p.sku || '-')}</div>
                    </div>
                `).join('');
                resultsDiv.classList.remove('hidden');
            } else {
                resultsDiv.innerHTML = '<div class="px-3 py-2 text-gray-500">ไม่พบสินค้า</div>';
                resultsDiv.classList.remove('hidden');
            }
        } catch (error) {
            console.error('Error:', error);
        }
    }, 300);
});

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function selectAssignProduct(id, name, sku) {
    document.getElementById('assignProductId').value = id;
    document.getElementById('assignProductSearch').value = '';
    document.getElementById('assignProductResults').classList.add('hidden');
    
    const selectedDiv = document.getElementById('assignSelectedProduct');
    selectedDiv.querySelector('span').textContent = `${name} (SKU: ${sku || '-'})`;
    selectedDiv.classList.remove('hidden');
}

function clearAssignProduct() {
    document.getElementById('assignProductId').value = '';
    document.getElementById('assignSelectedProduct').classList.add('hidden');
}

// Submit assignment form
document.getElementById('assignProductForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const productId = document.getElementById('assignProductId').value;
    if (!productId) {
        alert('กรุณาเลือกสินค้า');
        return;
    }
    
    const formData = new FormData(this);
    const data = {
        action: 'assign_to_location',
        location_id: parseInt(formData.get('location_id')),
        product_id: parseInt(productId),
        quantity: parseInt(formData.get('quantity')),
        batch_number: formData.get('batch_number') || null,
        notes: formData.get('notes') || null
    };
    
    try {
        const response = await fetch('../api/put-away.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });
        const result = await response.json();
        
        if (result.success) {
            closeAssignProductModal();
            alert('จัดเก็บสินค้าสำเร็จ');
            location.reload();
        } else {
            alert(result.error || 'ไม่สามารถจัดเก็บสินค้าได้');
        }
    } catch (error) {
        console.error('Error:', error);
        alert('เกิดข้อผิดพลาด');
    }
});

function viewLocationProducts(locationId) {
    document.getElementById('locationDetailContent').innerHTML = `
        <div class="text-center py-8">
            <i class="fas fa-spinner fa-spin text-3xl text-gray-400"></i>
            <p class="text-gray-500 mt-2">กำลังโหลดสินค้า...</p>
        </div>
    `;
    
    fetch(`../api/put-away.php?action=get_location_products&location_id=${locationId}`)
        .then(r => r.json())
        .then(data => {
            if (data.success && data.products.length > 0) {
                let html = `
                    <div class="space-y-3">
                        <div class="flex justify-between items-center">
                            <h4 class="font-medium">สินค้าในตำแหน่งนี้</h4>
                            <button onclick="showLocationDetail(${locationId})" class="text-blue-600 text-sm hover:underline">
                                <i class="fas fa-arrow-left mr-1"></i>กลับ
                            </button>
                        </div>
                `;
                
                data.products.forEach(p => {
                    const expiryClass = p.days_until_expiry <= 30 ? 'text-red-600' : (p.days_until_expiry <= 90 ? 'text-orange-600' : 'text-gray-500');
                    html += `
                        <div class="border rounded-lg p-3">
                            <div class="font-medium">${escapeHtml(p.product_name)}</div>
                            <div class="text-sm text-gray-500">SKU: ${escapeHtml(p.sku || '-')}</div>
                            <div class="grid grid-cols-2 gap-2 mt-2 text-sm">
                                <div class="bg-blue-50 rounded p-2 text-center">
                                    <div class="text-blue-600 font-bold">${p.quantity_available}</div>
                                    <div class="text-xs text-blue-500">ในตำแหน่งนี้</div>
                                </div>
                                <div class="bg-green-50 rounded p-2 text-center">
                                    <div class="text-green-600 font-bold">${p.actual_stock}</div>
                                    <div class="text-xs text-green-500">คงเหลือทั้งหมด</div>
                                </div>
                            </div>
                            ${p.batch_number ? `<div class="text-xs text-gray-500 mt-2">Batch: ${escapeHtml(p.batch_number)}</div>` : ''}
                            ${p.expiry_date ? `<div class="text-xs ${expiryClass} mt-1">หมดอายุ: ${p.expiry_date} (${p.days_until_expiry} วัน)</div>` : ''}
                        </div>
                    `;
                });
                
                html += '</div>';
                document.getElementById('locationDetailContent').innerHTML = html;
            } else {
                document.getElementById('locationDetailContent').innerHTML = `
                    <div class="text-center py-8 text-gray-500">
                        <i class="fas fa-box-open text-3xl mb-2"></i>
                        <p>ไม่มีสินค้าในตำแหน่งนี้</p>
                        <button onclick="showLocationDetail(${locationId})" class="mt-3 text-blue-600 hover:underline">
                            <i class="fas fa-arrow-left mr-1"></i>กลับ
                        </button>
                    </div>
                `;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            document.getElementById('locationDetailContent').innerHTML = `
                <div class="text-center py-8 text-red-500">
                    <i class="fas fa-exclamation-circle text-3xl mb-2"></i>
                    <p>เกิดข้อผิดพลาด</p>
                </div>
            `;
        });
}

// Close results when clicking outside
document.addEventListener('click', function(e) {
    const search = document.getElementById('assignProductSearch');
    const results = document.getElementById('assignProductResults');
    if (search && results && !search.contains(e.target) && !results.contains(e.target)) {
        results.classList.add('hidden');
    }
});
</script>
