<?php
/**
 * Warehouse Locations Tab - จัดการตำแหน่งจัดเก็บ
 * Tab content for inventory/index.php
 * 
 * Features:
 * - Location management UI with zone/shelf/bin hierarchy
 * - Utilization heat map display
 * 
 * Requirements: 1.3, 5.2
 */

require_once __DIR__ . '/../../classes/LocationService.php';

$locationService = new LocationService($db, $lineAccountId);

// Get filter parameters
$filterZone = $_GET['zone'] ?? '';
$filterZoneType = $_GET['zone_type'] ?? '';
$filterErgonomic = $_GET['ergonomic'] ?? '';

// Get warehouse utilization data
$utilization = $locationService->getWarehouseUtilization();
$zones = $utilization['zones'] ?? [];

// Get all locations with filters
$filters = ['is_active' => 1];
if ($filterZone) $filters['zone'] = $filterZone;
if ($filterZoneType) $filters['zone_type'] = $filterZoneType;
if ($filterErgonomic) $filters['ergonomic_level'] = $filterErgonomic;

$locations = $locationService->getLocations($filters);

// Get unique zones for filter dropdown
$allZones = $locationService->getZones();

// Zone type labels
$zoneTypeLabels = [
    'general' => ['label' => 'ทั่วไป', 'color' => 'blue', 'icon' => 'fa-box'],
    'cold_storage' => ['label' => 'ห้องเย็น', 'color' => 'cyan', 'icon' => 'fa-snowflake'],
    'controlled' => ['label' => 'ยาควบคุม (RX)', 'color' => 'red', 'icon' => 'fa-lock'],
    'hazardous' => ['label' => 'วัตถุอันตราย', 'color' => 'orange', 'icon' => 'fa-exclamation-triangle']
];

// Ergonomic level labels
$ergonomicLabels = [
    'golden' => ['label' => 'Golden Zone', 'color' => 'yellow', 'desc' => 'ระดับอก-เอว'],
    'upper' => ['label' => 'Upper', 'color' => 'gray', 'desc' => 'ชั้นบน'],
    'lower' => ['label' => 'Lower', 'color' => 'gray', 'desc' => 'ชั้นล่าง']
];

/**
 * Get utilization color class based on percentage
 */
function getUtilizationColor($percent) {
    if ($percent >= 85) return 'red';
    if ($percent >= 70) return 'yellow';
    if ($percent >= 40) return 'green';
    return 'blue';
}
?>

<div class="space-y-6">
    <!-- Summary Cards -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div class="bg-gradient-to-r from-blue-500 to-blue-600 rounded-xl p-6 text-white">
            <p class="text-blue-100 text-sm">ตำแหน่งทั้งหมด</p>
            <p class="text-3xl font-bold"><?= number_format($utilization['location_count'] ?? 0) ?></p>
        </div>
        <div class="bg-gradient-to-r from-green-500 to-green-600 rounded-xl p-6 text-white">
            <p class="text-green-100 text-sm">ความจุรวม</p>
            <p class="text-3xl font-bold"><?= number_format($utilization['total_capacity'] ?? 0) ?></p>
        </div>
        <div class="bg-gradient-to-r from-purple-500 to-purple-600 rounded-xl p-6 text-white">
            <p class="text-purple-100 text-sm">ใช้งานแล้ว</p>
            <p class="text-3xl font-bold"><?= number_format($utilization['total_qty'] ?? 0) ?></p>
        </div>
        <div class="bg-gradient-to-r from-orange-500 to-orange-600 rounded-xl p-6 text-white">
            <p class="text-orange-100 text-sm">อัตราการใช้งาน</p>
            <p class="text-3xl font-bold"><?= number_format($utilization['overall_utilization'] ?? 0, 1) ?>%</p>
        </div>
    </div>

    <!-- Zone Utilization Heat Map -->
    <div class="bg-white rounded-xl shadow">
        <div class="p-4 border-b flex justify-between items-center">
            <h2 class="font-semibold"><i class="fas fa-th-large mr-2 text-blue-500"></i>แผนผังการใช้งานโซน (Heat Map)</h2>
            <button onclick="openCreateZoneModal()" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 text-sm">
                <i class="fas fa-plus mr-1"></i>สร้างโซนใหม่
            </button>
        </div>
        <div class="p-4">
            <?php if (empty($zones)): ?>
            <div class="text-center py-8 text-gray-500">
                <i class="fas fa-warehouse text-4xl mb-3"></i>
                <p>ยังไม่มีข้อมูลโซน</p>
                <p class="text-sm">กรุณาสร้างตำแหน่งจัดเก็บใหม่</p>
            </div>
            <?php else: ?>
            <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4">
                <?php foreach ($zones as $zone): 
                    $utilPercent = $zone['utilization_percent'] ?? 0;
                    $color = getUtilizationColor($utilPercent);
                    $zoneInfo = $zoneTypeLabels[$zone['zone_type']] ?? $zoneTypeLabels['general'];
                ?>
                <div class="relative group cursor-pointer" onclick="filterByZone('<?= htmlspecialchars($zone['zone']) ?>')">
                    <div class="bg-<?= $color ?>-100 border-2 border-<?= $color ?>-300 rounded-lg p-4 hover:shadow-lg transition-all">
                        <div class="flex items-center justify-between mb-2">
                            <span class="font-bold text-lg"><?= htmlspecialchars($zone['zone']) ?></span>
                            <i class="fas <?= $zoneInfo['icon'] ?> text-<?= $zoneInfo['color'] ?>-500"></i>
                        </div>
                        <div class="text-sm text-gray-600 mb-2"><?= $zoneInfo['label'] ?></div>
                        <div class="w-full bg-gray-200 rounded-full h-2 mb-2">
                            <div class="bg-<?= $color ?>-500 h-2 rounded-full" style="width: <?= min(100, $utilPercent) ?>%"></div>
                        </div>
                        <div class="flex justify-between text-xs text-gray-500">
                            <span><?= number_format($zone['total_qty']) ?>/<?= number_format($zone['total_capacity']) ?></span>
                            <span class="font-medium text-<?= $color ?>-600"><?= number_format($utilPercent, 1) ?>%</span>
                        </div>
                        <div class="text-xs text-gray-400 mt-1"><?= $zone['location_count'] ?> ตำแหน่ง</div>
                    </div>
                    <!-- Warning badge for high utilization -->
                    <?php if ($utilPercent >= 85): ?>
                    <span class="absolute -top-2 -right-2 bg-red-500 text-white text-xs px-2 py-1 rounded-full">
                        <i class="fas fa-exclamation"></i>
                    </span>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>


    <!-- Filters -->
    <div class="bg-white rounded-xl shadow p-4">
        <form method="GET" class="grid grid-cols-1 md:grid-cols-5 gap-4">
            <input type="hidden" name="tab" value="locations">
            <div>
                <label class="block text-sm font-medium mb-1">โซน</label>
                <select name="zone" class="w-full px-3 py-2 border rounded-lg">
                    <option value="">-- ทั้งหมด --</option>
                    <?php foreach ($allZones as $z): ?>
                    <option value="<?= htmlspecialchars($z['zone']) ?>" <?= $filterZone === $z['zone'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($z['zone']) ?> (<?= $z['location_count'] ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">ประเภทโซน</label>
                <select name="zone_type" class="w-full px-3 py-2 border rounded-lg">
                    <option value="">-- ทั้งหมด --</option>
                    <?php foreach ($zoneTypeLabels as $type => $info): ?>
                    <option value="<?= $type ?>" <?= $filterZoneType === $type ? 'selected' : '' ?>>
                        <?= $info['label'] ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">ระดับ Ergonomic</label>
                <select name="ergonomic" class="w-full px-3 py-2 border rounded-lg">
                    <option value="">-- ทั้งหมด --</option>
                    <?php foreach ($ergonomicLabels as $level => $info): ?>
                    <option value="<?= $level ?>" <?= $filterErgonomic === $level ? 'selected' : '' ?>>
                        <?= $info['label'] ?> (<?= $info['desc'] ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="flex items-end gap-2">
                <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                    <i class="fas fa-search mr-1"></i>ค้นหา
                </button>
                <?php if ($filterZone || $filterZoneType || $filterErgonomic): ?>
                <a href="?tab=locations" class="px-4 py-2 bg-gray-200 rounded-lg hover:bg-gray-300">ล้าง</a>
                <?php endif; ?>
            </div>
            <div class="flex items-end">
                <button type="button" onclick="openCreateLocationModal()" class="w-full px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
                    <i class="fas fa-plus mr-1"></i>เพิ่มตำแหน่ง
                </button>
            </div>
        </form>
    </div>

    <!-- Locations Table -->
    <div class="bg-white rounded-xl shadow">
        <div class="p-4 border-b flex justify-between items-center">
            <h2 class="font-semibold"><i class="fas fa-map-marker-alt mr-2 text-blue-500"></i>รายการตำแหน่งจัดเก็บ</h2>
            <div class="flex items-center gap-2">
                <span class="text-sm text-gray-500"><?= count($locations) ?> ตำแหน่ง</span>
                <?php if (!empty($locations)): ?>
                <div class="relative" x-data="{ open: false }">
                    <button onclick="togglePrintMenu()" class="px-3 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 text-sm">
                        <i class="fas fa-print mr-1"></i>พิมพ์ป้าย
                    </button>
                    <div id="printMenu" class="hidden absolute right-0 mt-2 w-48 bg-white rounded-lg shadow-lg border z-10">
                        <button onclick="printAllLabels(false)" class="w-full px-4 py-2 text-left text-sm hover:bg-gray-50 rounded-t-lg">
                            <i class="fas fa-barcode mr-2"></i>พิมพ์ทั้งหมด (Barcode)
                        </button>
                        <button onclick="printAllLabels(true)" class="w-full px-4 py-2 text-left text-sm hover:bg-gray-50">
                            <i class="fas fa-qrcode mr-2"></i>พิมพ์ทั้งหมด (QR)
                        </button>
                        <hr class="my-1">
                        <button onclick="printSelectedLabels(false)" class="w-full px-4 py-2 text-left text-sm hover:bg-gray-50">
                            <i class="fas fa-check-square mr-2"></i>พิมพ์ที่เลือก (Barcode)
                        </button>
                        <button onclick="printSelectedLabels(true)" class="w-full px-4 py-2 text-left text-sm hover:bg-gray-50 rounded-b-lg">
                            <i class="fas fa-check-square mr-2"></i>พิมพ์ที่เลือก (QR)
                        </button>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 w-10">
                            <input type="checkbox" id="selectAllLocations" onchange="toggleSelectAll(this)" class="rounded">
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500">รหัสตำแหน่ง</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">โซน</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">ชั้น</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">ช่อง</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">ประเภท</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">Ergonomic</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">ความจุ</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">ใช้งาน</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">การใช้งาน</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">จัดการ</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <?php if (empty($locations)): ?>
                    <tr><td colspan="11" class="px-4 py-8 text-center text-gray-500">ไม่พบข้อมูลตำแหน่ง</td></tr>
                    <?php else: ?>
                    <?php foreach ($locations as $loc): 
                        $utilPercent = $loc['capacity'] > 0 ? ($loc['current_qty'] / $loc['capacity']) * 100 : 0;
                        $color = getUtilizationColor($utilPercent);
                        $zoneInfo = $zoneTypeLabels[$loc['zone_type']] ?? $zoneTypeLabels['general'];
                        $ergoInfo = $ergonomicLabels[$loc['ergonomic_level']] ?? $ergonomicLabels['golden'];
                    ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 text-center">
                            <input type="checkbox" class="location-checkbox rounded" value="<?= $loc['id'] ?>">
                        </td>
                        <td class="px-4 py-3">
                            <span class="font-mono font-bold text-blue-600"><?= htmlspecialchars($loc['location_code']) ?></span>
                        </td>
                        <td class="px-4 py-3 text-center font-medium"><?= htmlspecialchars($loc['zone']) ?></td>
                        <td class="px-4 py-3 text-center"><?= $loc['shelf'] ?></td>
                        <td class="px-4 py-3 text-center"><?= $loc['bin'] ?></td>
                        <td class="px-4 py-3 text-center">
                            <span class="px-2 py-1 bg-<?= $zoneInfo['color'] ?>-100 text-<?= $zoneInfo['color'] ?>-700 rounded text-xs">
                                <i class="fas <?= $zoneInfo['icon'] ?> mr-1"></i><?= $zoneInfo['label'] ?>
                            </span>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <?php if ($loc['ergonomic_level'] === 'golden'): ?>
                            <span class="px-2 py-1 bg-yellow-100 text-yellow-700 rounded text-xs">
                                <i class="fas fa-star mr-1"></i>Golden
                            </span>
                            <?php else: ?>
                            <span class="text-gray-500 text-xs"><?= $ergoInfo['label'] ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-center"><?= number_format($loc['capacity']) ?></td>
                        <td class="px-4 py-3 text-center font-medium"><?= number_format($loc['current_qty']) ?></td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2">
                                <div class="flex-1 bg-gray-200 rounded-full h-2">
                                    <div class="bg-<?= $color ?>-500 h-2 rounded-full" style="width: <?= min(100, $utilPercent) ?>%"></div>
                                </div>
                                <span class="text-xs font-medium text-<?= $color ?>-600 w-12 text-right"><?= number_format($utilPercent, 0) ?>%</span>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <div class="flex justify-center gap-1">
                                <button onclick="editLocation(<?= $loc['id'] ?>)" class="p-2 text-blue-600 hover:bg-blue-50 rounded" title="แก้ไข">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button onclick="printLocationLabel(<?= $loc['id'] ?>, false)" class="p-2 text-gray-600 hover:bg-gray-50 rounded" title="พิมพ์ป้าย (Barcode)">
                                    <i class="fas fa-barcode"></i>
                                </button>
                                <button onclick="printLocationLabel(<?= $loc['id'] ?>, true)" class="p-2 text-purple-600 hover:bg-purple-50 rounded" title="พิมพ์ป้าย (QR)">
                                    <i class="fas fa-qrcode"></i>
                                </button>
                                <?php if ($loc['current_qty'] == 0): ?>
                                <button onclick="deleteLocation(<?= $loc['id'] ?>)" class="p-2 text-red-600 hover:bg-red-50 rounded" title="ลบ">
                                    <i class="fas fa-trash"></i>
                                </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>


<!-- Create Location Modal -->
<div id="createLocationModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 flex items-center justify-center">
    <div class="bg-white rounded-xl shadow-xl max-w-lg w-full mx-4 max-h-[90vh] overflow-y-auto">
        <div class="p-4 border-b flex justify-between items-center">
            <h3 class="font-semibold text-lg"><i class="fas fa-plus-circle mr-2 text-green-500"></i>เพิ่มตำแหน่งจัดเก็บ</h3>
            <button onclick="closeCreateLocationModal()" class="text-gray-500 hover:text-gray-700">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form id="createLocationForm" class="p-4 space-y-4">
            <div class="grid grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-1">โซน <span class="text-red-500">*</span></label>
                    <input type="text" name="zone" required placeholder="A1, B, RX" 
                           class="w-full px-3 py-2 border rounded-lg uppercase" maxlength="10">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">ชั้น <span class="text-red-500">*</span></label>
                    <input type="number" name="shelf" required min="1" max="99" placeholder="1-99"
                           class="w-full px-3 py-2 border rounded-lg">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">ช่อง <span class="text-red-500">*</span></label>
                    <input type="number" name="bin" required min="1" max="99" placeholder="1-99"
                           class="w-full px-3 py-2 border rounded-lg">
                </div>
            </div>
            <div class="bg-gray-50 rounded-lg p-3">
                <label class="block text-sm font-medium mb-1">รหัสตำแหน่ง (สร้างอัตโนมัติ)</label>
                <div id="previewLocationCode" class="font-mono text-lg font-bold text-blue-600">-</div>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-1">ประเภทโซน</label>
                    <select name="zone_type" class="w-full px-3 py-2 border rounded-lg">
                        <?php foreach ($zoneTypeLabels as $type => $info): ?>
                        <option value="<?= $type ?>"><?= $info['label'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">ระดับ Ergonomic</label>
                    <select name="ergonomic_level" class="w-full px-3 py-2 border rounded-lg">
                        <?php foreach ($ergonomicLabels as $level => $info): ?>
                        <option value="<?= $level ?>"><?= $info['label'] ?> (<?= $info['desc'] ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">ความจุ (หน่วย)</label>
                <input type="number" name="capacity" value="100" min="1" 
                       class="w-full px-3 py-2 border rounded-lg">
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">หมายเหตุ</label>
                <textarea name="description" rows="2" class="w-full px-3 py-2 border rounded-lg" 
                          placeholder="รายละเอียดเพิ่มเติม..."></textarea>
            </div>
            <div class="flex gap-2 pt-4">
                <button type="submit" class="flex-1 px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
                    <i class="fas fa-save mr-1"></i>บันทึก
                </button>
                <button type="button" onclick="closeCreateLocationModal()" class="px-4 py-2 bg-gray-200 rounded-lg hover:bg-gray-300">
                    ยกเลิก
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Create Zone (Bulk) Modal -->
<div id="createZoneModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 flex items-center justify-center">
    <div class="bg-white rounded-xl shadow-xl max-w-lg w-full mx-4">
        <div class="p-4 border-b flex justify-between items-center">
            <h3 class="font-semibold text-lg"><i class="fas fa-th mr-2 text-blue-500"></i>สร้างโซนใหม่ (Bulk)</h3>
            <button onclick="closeCreateZoneModal()" class="text-gray-500 hover:text-gray-700">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form id="createZoneForm" class="p-4 space-y-4">
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-1">ชื่อโซน <span class="text-red-500">*</span></label>
                    <input type="text" name="zone" required placeholder="A1, B, RX, COLD" 
                           class="w-full px-3 py-2 border rounded-lg uppercase" maxlength="10">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">ประเภทโซน</label>
                    <select name="zone_type" class="w-full px-3 py-2 border rounded-lg">
                        <?php foreach ($zoneTypeLabels as $type => $info): ?>
                        <option value="<?= $type ?>"><?= $info['label'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-1">จำนวนชั้น <span class="text-red-500">*</span></label>
                    <input type="number" name="shelves" required min="1" max="20" value="5"
                           class="w-full px-3 py-2 border rounded-lg">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">จำนวนช่องต่อชั้น <span class="text-red-500">*</span></label>
                    <input type="number" name="bins_per_shelf" required min="1" max="50" value="10"
                           class="w-full px-3 py-2 border rounded-lg">
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">ความจุต่อช่อง</label>
                <input type="number" name="capacity" value="100" min="1" 
                       class="w-full px-3 py-2 border rounded-lg">
            </div>
            <div class="bg-blue-50 rounded-lg p-3 text-sm text-blue-700">
                <i class="fas fa-info-circle mr-1"></i>
                ระบบจะสร้างตำแหน่งทั้งหมด <span id="totalLocationsPreview" class="font-bold">50</span> ตำแหน่ง
                <br>และกำหนด Ergonomic Level อัตโนมัติตามชั้น (Golden Zone = ชั้นกลาง)
            </div>
            <div class="flex gap-2 pt-4">
                <button type="submit" class="flex-1 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                    <i class="fas fa-magic mr-1"></i>สร้างโซน
                </button>
                <button type="button" onclick="closeCreateZoneModal()" class="px-4 py-2 bg-gray-200 rounded-lg hover:bg-gray-300">
                    ยกเลิก
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Location Modal -->
<div id="editLocationModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 flex items-center justify-center">
    <div class="bg-white rounded-xl shadow-xl max-w-lg w-full mx-4 max-h-[90vh] overflow-y-auto">
        <div class="p-4 border-b flex justify-between items-center">
            <h3 class="font-semibold text-lg"><i class="fas fa-edit mr-2 text-blue-500"></i>แก้ไขตำแหน่งจัดเก็บ</h3>
            <button onclick="closeEditLocationModal()" class="text-gray-500 hover:text-gray-700">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form id="editLocationForm" class="p-4 space-y-4">
            <input type="hidden" name="id" id="editLocationId">
            <div class="bg-gray-50 rounded-lg p-3">
                <label class="block text-sm font-medium mb-1">รหัสตำแหน่ง</label>
                <div id="editLocationCode" class="font-mono text-lg font-bold text-blue-600">-</div>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-1">ประเภทโซน</label>
                    <select name="zone_type" id="editZoneType" class="w-full px-3 py-2 border rounded-lg">
                        <?php foreach ($zoneTypeLabels as $type => $info): ?>
                        <option value="<?= $type ?>"><?= $info['label'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">ระดับ Ergonomic</label>
                    <select name="ergonomic_level" id="editErgonomicLevel" class="w-full px-3 py-2 border rounded-lg">
                        <?php foreach ($ergonomicLabels as $level => $info): ?>
                        <option value="<?= $level ?>"><?= $info['label'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">ความจุ (หน่วย)</label>
                <input type="number" name="capacity" id="editCapacity" min="1" 
                       class="w-full px-3 py-2 border rounded-lg">
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">หมายเหตุ</label>
                <textarea name="description" id="editDescription" rows="2" class="w-full px-3 py-2 border rounded-lg"></textarea>
            </div>
            <div class="flex gap-2 pt-4">
                <button type="submit" class="flex-1 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                    <i class="fas fa-save mr-1"></i>บันทึก
                </button>
                <button type="button" onclick="closeEditLocationModal()" class="px-4 py-2 bg-gray-200 rounded-lg hover:bg-gray-300">
                    ยกเลิก
                </button>
            </div>
        </form>
    </div>
</div>


<script>
// Modal functions
function openCreateLocationModal() {
    document.getElementById('createLocationModal').classList.remove('hidden');
    document.getElementById('createLocationForm').reset();
    updateLocationCodePreview();
}

function closeCreateLocationModal() {
    document.getElementById('createLocationModal').classList.add('hidden');
}

function openCreateZoneModal() {
    document.getElementById('createZoneModal').classList.remove('hidden');
    document.getElementById('createZoneForm').reset();
    updateTotalLocationsPreview();
}

function closeCreateZoneModal() {
    document.getElementById('createZoneModal').classList.add('hidden');
}

function closeEditLocationModal() {
    document.getElementById('editLocationModal').classList.add('hidden');
}

// Preview location code
function updateLocationCodePreview() {
    const zone = document.querySelector('#createLocationForm input[name="zone"]').value.toUpperCase();
    const shelf = document.querySelector('#createLocationForm input[name="shelf"]').value.padStart(2, '0');
    const bin = document.querySelector('#createLocationForm input[name="bin"]').value.padStart(2, '0');
    
    if (zone && shelf && bin) {
        document.getElementById('previewLocationCode').textContent = `${zone}-${shelf}-${bin}`;
    } else {
        document.getElementById('previewLocationCode').textContent = '-';
    }
}

// Preview total locations for bulk create
function updateTotalLocationsPreview() {
    const shelves = parseInt(document.querySelector('#createZoneForm input[name="shelves"]').value) || 0;
    const bins = parseInt(document.querySelector('#createZoneForm input[name="bins_per_shelf"]').value) || 0;
    document.getElementById('totalLocationsPreview').textContent = shelves * bins;
}

// Filter by zone
function filterByZone(zone) {
    window.location.href = `?tab=locations&zone=${encodeURIComponent(zone)}`;
}

// Edit location
async function editLocation(id) {
    try {
        const response = await fetch(`api/locations.php?action=get&id=${id}`);
        const data = await response.json();
        
        if (data.success) {
            const loc = data.location;
            document.getElementById('editLocationId').value = loc.id;
            document.getElementById('editLocationCode').textContent = loc.location_code;
            document.getElementById('editZoneType').value = loc.zone_type;
            document.getElementById('editErgonomicLevel').value = loc.ergonomic_level;
            document.getElementById('editCapacity').value = loc.capacity;
            document.getElementById('editDescription').value = loc.description || '';
            document.getElementById('editLocationModal').classList.remove('hidden');
        } else {
            alert('ไม่พบข้อมูลตำแหน่ง');
        }
    } catch (error) {
        console.error('Error:', error);
        alert('เกิดข้อผิดพลาดในการโหลดข้อมูล');
    }
}

// Delete location
async function deleteLocation(id) {
    if (!confirm('ยืนยันการลบตำแหน่งนี้?')) return;
    
    try {
        const response = await fetch('api/locations.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'delete', id: id })
        });
        const data = await response.json();
        
        if (data.success) {
            location.reload();
        } else {
            alert(data.error || 'ไม่สามารถลบตำแหน่งได้');
        }
    } catch (error) {
        console.error('Error:', error);
        alert('เกิดข้อผิดพลาด');
    }
}

// Print location label
function printLocationLabel(id, withQr = false) {
    const qrParam = withQr ? '&with_qr=1' : '';
    window.open(`api/locations.php?action=print_label&id=${id}${qrParam}`, '_blank');
}

// Toggle print menu
function togglePrintMenu() {
    const menu = document.getElementById('printMenu');
    menu.classList.toggle('hidden');
}

// Close print menu when clicking outside
document.addEventListener('click', function(e) {
    const menu = document.getElementById('printMenu');
    const button = e.target.closest('button');
    if (menu && !menu.contains(e.target) && (!button || !button.textContent.includes('พิมพ์ป้าย'))) {
        menu.classList.add('hidden');
    }
});

// Toggle select all checkboxes
function toggleSelectAll(checkbox) {
    const checkboxes = document.querySelectorAll('.location-checkbox');
    checkboxes.forEach(cb => cb.checked = checkbox.checked);
}

// Get selected location IDs
function getSelectedLocationIds() {
    const checkboxes = document.querySelectorAll('.location-checkbox:checked');
    return Array.from(checkboxes).map(cb => cb.value);
}

// Print all labels (with current filters)
function printAllLabels(withQr = false) {
    const params = new URLSearchParams(window.location.search);
    let url = 'api/locations.php?action=print_batch_labels';
    
    if (params.get('zone')) url += `&zone=${encodeURIComponent(params.get('zone'))}`;
    if (params.get('zone_type')) url += `&zone_type=${encodeURIComponent(params.get('zone_type'))}`;
    if (params.get('ergonomic')) url += `&ergonomic_level=${encodeURIComponent(params.get('ergonomic'))}`;
    if (withQr) url += '&with_qr=1';
    
    window.open(url, '_blank');
    document.getElementById('printMenu').classList.add('hidden');
}

// Print selected labels
function printSelectedLabels(withQr = false) {
    const ids = getSelectedLocationIds();
    if (ids.length === 0) {
        alert('กรุณาเลือกตำแหน่งที่ต้องการพิมพ์');
        return;
    }
    
    let url = `api/locations.php?action=print_batch_labels&ids=${ids.join(',')}`;
    if (withQr) url += '&with_qr=1';
    
    window.open(url, '_blank');
    document.getElementById('printMenu').classList.add('hidden');
}

// Event listeners
document.addEventListener('DOMContentLoaded', function() {
    // Create location form
    const createForm = document.getElementById('createLocationForm');
    createForm.querySelectorAll('input[name="zone"], input[name="shelf"], input[name="bin"]').forEach(input => {
        input.addEventListener('input', updateLocationCodePreview);
    });
    
    createForm.addEventListener('submit', async function(e) {
        e.preventDefault();
        const formData = new FormData(this);
        const data = Object.fromEntries(formData);
        data.action = 'create';
        
        try {
            const response = await fetch('api/locations.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            });
            const result = await response.json();
            
            if (result.success) {
                closeCreateLocationModal();
                location.reload();
            } else {
                alert(result.error || 'ไม่สามารถสร้างตำแหน่งได้');
            }
        } catch (error) {
            console.error('Error:', error);
            alert('เกิดข้อผิดพลาด');
        }
    });
    
    // Create zone form (bulk)
    const zoneForm = document.getElementById('createZoneForm');
    zoneForm.querySelectorAll('input[name="shelves"], input[name="bins_per_shelf"]').forEach(input => {
        input.addEventListener('input', updateTotalLocationsPreview);
    });
    
    zoneForm.addEventListener('submit', async function(e) {
        e.preventDefault();
        const formData = new FormData(this);
        const data = Object.fromEntries(formData);
        data.action = 'bulk_create';
        
        try {
            const response = await fetch('api/locations.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            });
            const result = await response.json();
            
            if (result.success) {
                closeCreateZoneModal();
                alert(`สร้างตำแหน่งสำเร็จ ${result.created_count} ตำแหน่ง`);
                location.reload();
            } else {
                alert(result.error || 'ไม่สามารถสร้างโซนได้');
            }
        } catch (error) {
            console.error('Error:', error);
            alert('เกิดข้อผิดพลาด');
        }
    });
    
    // Edit location form
    document.getElementById('editLocationForm').addEventListener('submit', async function(e) {
        e.preventDefault();
        const formData = new FormData(this);
        const data = Object.fromEntries(formData);
        data.action = 'update';
        
        try {
            const response = await fetch('api/locations.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            });
            const result = await response.json();
            
            if (result.success) {
                closeEditLocationModal();
                location.reload();
            } else {
                alert(result.error || 'ไม่สามารถแก้ไขตำแหน่งได้');
            }
        } catch (error) {
            console.error('Error:', error);
            alert('เกิดข้อผิดพลาด');
        }
    });
});
</script>
