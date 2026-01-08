<?php
/**
 * CNY Sync Dashboard
 * Dashboard สำหรับ monitor และจัดการ sync ข้อมูลจาก CNY Pharmacy API
 * ใช้ระบบ sync เดียวกันกับ admin/setup-cny.php
 */

session_start();
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/CnyPharmacyAPI.php';

$db = Database::getInstance()->getConnection();
$cnyApi = new CnyPharmacyAPI($db);

// Check if cny_products table exists
$cnyTableExists = false;
$cnyProductCount = 0;
try {
    $stmt = $db->query("SELECT COUNT(*) FROM cny_products");
    $cnyProductCount = $stmt->fetchColumn();
    $cnyTableExists = true;
} catch (PDOException $e) {
    // Table doesn't exist
}

// Get business_items stats
$businessItemsCount = 0;
$businessItemsWithSku = 0;
try {
    $stmt = $db->query("SELECT COUNT(*) FROM business_items");
    $businessItemsCount = $stmt->fetchColumn();
    $stmt = $db->query("SELECT COUNT(*) FROM business_items WHERE sku IS NOT NULL AND sku != ''");
    $businessItemsWithSku = $stmt->fetchColumn();
} catch (PDOException $e) {
    // Table doesn't exist
}

// Get last sync time
$lastSync = null;
try {
    $stmt = $db->query("SELECT MAX(last_updated) FROM cny_products");
    $lastSync = $stmt->fetchColumn();
} catch (PDOException $e) {}

// Get categories count
$categoriesCount = 0;
try {
    $stmt = $db->query("SELECT COUNT(*) FROM product_categories WHERE cny_code IS NOT NULL");
    $categoriesCount = $stmt->fetchColumn();
} catch (PDOException $e) {}

$pageTitle = 'CNY Sync Dashboard';

?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .gradient-bg { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .card-hover:hover { transform: translateY(-2px); box-shadow: 0 10px 40px rgba(0,0,0,0.15); }
        .log-container { font-family: 'Courier New', monospace; }
        .log-success { color: #68d391; }
        .log-error { color: #fc8181; }
        .log-warning { color: #f6e05e; }
        .log-info { color: #90cdf4; }
    </style>
</head>
<body class="bg-gray-100 min-h-screen">
    <!-- Header -->
    <div class="gradient-bg text-white py-6 px-4 mb-6">
        <div class="max-w-7xl mx-auto">
            <div class="flex justify-between items-center">
                <div>
                    <h1 class="text-3xl font-bold mb-2">
                        <i class="fas fa-sync-alt mr-3"></i>CNY Sync Dashboard
                    </h1>
                    <p class="text-white/80">
                        จัดการ sync ข้อมูลสินค้าจาก CNY Pharmacy API
                        <?php if ($lastSync): ?>
                        <span class="ml-2 text-sm">(อัพเดทล่าสุด: <?= date('d/m/Y H:i', strtotime($lastSync)) ?>)</span>
                        <?php endif; ?>
                    </p>
                </div>
                <div class="flex gap-3">
                    <a href="shop/products-cny.php" class="px-4 py-2 bg-white/20 hover:bg-white/30 rounded-lg transition">
                        <i class="fas fa-box mr-2"></i>ดูสินค้า CNY
                    </a>
                    <a href="admin/setup-cny.php?key=cny2024" class="px-4 py-2 bg-white/20 hover:bg-white/30 rounded-lg transition">
                        <i class="fas fa-cog mr-2"></i>Setup CNY
                    </a>
                    <button onclick="location.reload()" class="px-4 py-2 bg-white/20 hover:bg-white/30 rounded-lg transition">
                        <i class="fas fa-refresh mr-2"></i>Refresh
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="max-w-7xl mx-auto px-4 pb-8">
        <!-- Stats Cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <!-- CNY Products -->
            <div class="bg-white rounded-xl shadow p-6 card-hover transition">
                <div class="flex items-center justify-between mb-4">
                    <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center">
                        <i class="fas fa-database text-blue-600 text-xl"></i>
                    </div>
                    <?php if ($cnyTableExists): ?>
                    <span class="px-2 py-1 bg-green-100 text-green-700 text-xs rounded-full">Active</span>
                    <?php else: ?>
                    <span class="px-2 py-1 bg-red-100 text-red-700 text-xs rounded-full">Not Setup</span>
                    <?php endif; ?>
                </div>
                <div class="text-3xl font-bold text-gray-800 mb-1"><?= number_format($cnyProductCount) ?></div>
                <div class="text-sm text-gray-500">CNY Products (Cache)</div>
            </div>

            <!-- Business Items -->
            <div class="bg-white rounded-xl shadow p-6 card-hover transition">
                <div class="flex items-center justify-between mb-4">
                    <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center">
                        <i class="fas fa-box text-green-600 text-xl"></i>
                    </div>
                </div>
                <div class="text-3xl font-bold text-gray-800 mb-1"><?= number_format($businessItemsCount) ?></div>
                <div class="text-sm text-gray-500">Business Items (<?= number_format($businessItemsWithSku) ?> with SKU)</div>
            </div>

            <!-- Categories -->
            <div class="bg-white rounded-xl shadow p-6 card-hover transition">
                <div class="flex items-center justify-between mb-4">
                    <div class="w-12 h-12 bg-purple-100 rounded-lg flex items-center justify-center">
                        <i class="fas fa-tags text-purple-600 text-xl"></i>
                    </div>
                </div>
                <div class="text-3xl font-bold text-gray-800 mb-1"><?= number_format($categoriesCount) ?></div>
                <div class="text-sm text-gray-500">CNY Categories</div>
            </div>

            <!-- API Status -->
            <div class="bg-white rounded-xl shadow p-6 card-hover transition">
                <div class="flex items-center justify-between mb-4">
                    <div class="w-12 h-12 bg-yellow-100 rounded-lg flex items-center justify-center">
                        <i class="fas fa-plug text-yellow-600 text-xl"></i>
                    </div>
                    <span id="apiStatus" class="px-2 py-1 bg-gray-100 text-gray-700 text-xs rounded-full">Checking...</span>
                </div>
                <div class="text-lg font-bold text-gray-800 mb-1">CNY API</div>
                <div class="text-sm text-gray-500">manager.cnypharmacy.com</div>
            </div>
        </div>

        <!-- Sync Options -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
            <!-- Method 1: Import from CSV -->
            <div class="bg-white rounded-xl shadow overflow-hidden">
                <div class="bg-purple-600 text-white px-6 py-4">
                    <h2 class="text-lg font-semibold">
                        <i class="fas fa-file-csv mr-2"></i>Method 1: Import from CSV (แนะนำ)
                    </h2>
                    <p class="text-sm text-white/80 mt-1">นำเข้าจากไฟล์ CSV - เร็วและใช้ memory น้อย</p>
                </div>
                <div class="p-6">
                    <p class="text-gray-600 mb-4">
                        Import ข้อมูลจากไฟล์ <code class="bg-gray-100 px-2 py-1 rounded">CNY API for AI - AI.csv</code> 
                        เข้าตาราง <code class="bg-gray-100 px-2 py-1 rounded">cny_products</code>
                    </p>
                    <div class="flex gap-3">
                        <a href="admin/setup-cny.php?key=cny2024&step=import_csv" 
                           class="flex-1 text-center px-4 py-3 bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition font-medium">
                            <i class="fas fa-file-import mr-2"></i>Import CSV
                        </a>
                    </div>
                </div>
            </div>

            <!-- Method 2: Sync from API -->
            <div class="bg-white rounded-xl shadow overflow-hidden">
                <div class="bg-green-600 text-white px-6 py-4">
                    <h2 class="text-lg font-semibold">
                        <i class="fas fa-cloud-download-alt mr-2"></i>Method 2: Sync from API
                    </h2>
                    <p class="text-sm text-white/80 mt-1">ดึงข้อมูลตรงจาก CNY API - ต้องใช้ memory สูง</p>
                </div>
                <div class="p-6">
                    <p class="text-gray-600 mb-4">
                        ดึงข้อมูลจาก CNY Pharmacy API โดยตรง เข้าตาราง <code class="bg-gray-100 px-2 py-1 rounded">cny_products</code>
                    </p>
                    <div class="flex gap-3">
                        <a href="admin/setup-cny.php?key=cny2024&step=sync" 
                           class="flex-1 text-center px-4 py-3 bg-green-600 text-white rounded-lg hover:bg-green-700 transition font-medium">
                            <i class="fas fa-sync mr-2"></i>Sync from API
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Continuous Sync to Business Items -->
        <div class="bg-white rounded-xl shadow overflow-hidden mb-8">
            <div class="bg-blue-600 text-white px-6 py-4">
                <h2 class="text-lg font-semibold">
                    <i class="fas fa-exchange-alt mr-2"></i>Sync to Business Items (ระบบหลัก)
                </h2>
                <p class="text-sm text-white/80 mt-1">
                    Sync ข้อมูลจาก cny_products → business_items พร้อมสร้างหมวดหมู่อัตโนมัติ
                </p>
            </div>
            <div class="p-6">
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Batch Size</label>
                        <select id="batchSize" class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500">
                            <option value="5">5 รายการ</option>
                            <option value="10" selected>10 รายการ</option>
                            <option value="20">20 รายการ</option>
                            <option value="50">50 รายการ</option>
                            <option value="100">100 รายการ</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Delay (ms)</label>
                        <select id="syncDelay" class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500">
                            <option value="500">500ms</option>
                            <option value="1000">1 วินาที</option>
                            <option value="2000" selected>2 วินาที</option>
                            <option value="3000">3 วินาที</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Max Batches (0=ไม่จำกัด)</label>
                        <input type="number" id="maxBatches" value="0" min="0" 
                               class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div class="flex items-end gap-2">
                        <button id="startSyncBtn" onclick="startContinuousSync(false)" 
                                class="flex-1 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition font-medium">
                            <i class="fas fa-play mr-2"></i>เริ่ม Sync
                        </button>
                        <button id="resetSyncBtn" onclick="startContinuousSync(true)" 
                                class="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition">
                            <i class="fas fa-redo"></i>
                        </button>
                        <button id="stopSyncBtn" onclick="stopSync()" disabled
                                class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition disabled:opacity-50">
                            <i class="fas fa-stop"></i>
                        </button>
                    </div>
                </div>

                <!-- Sync Status Panel -->
                <div id="syncStatusPanel" class="hidden bg-gray-50 rounded-lg p-6">
                    <div class="flex justify-between items-center mb-4">
                        <span class="font-semibold text-gray-800">
                            <i class="fas fa-chart-line mr-2"></i>สถานะ Sync
                        </span>
                        <span id="syncStatusBadge" class="px-3 py-1 bg-blue-500 text-white text-sm rounded-full">
                            กำลังทำงาน...
                        </span>
                    </div>
                    
                    <!-- Progress Bar -->
                    <div class="mb-4">
                        <div class="flex justify-between text-sm text-gray-600 mb-1">
                            <span>Progress</span>
                            <span id="progressText">0 / 0</span>
                        </div>
                        <div class="w-full bg-gray-200 rounded-full h-3">
                            <div id="progressBar" class="bg-blue-600 h-3 rounded-full transition-all duration-300" style="width: 0%"></div>
                        </div>
                    </div>
                    
                    <!-- Stats Grid -->
                    <div class="grid grid-cols-5 gap-4 mb-4">
                        <div class="text-center p-3 bg-white rounded-lg">
                            <div class="text-2xl font-bold text-blue-600" id="statBatches">0</div>
                            <div class="text-xs text-gray-500">Batches</div>
                        </div>
                        <div class="text-center p-3 bg-white rounded-lg">
                            <div class="text-2xl font-bold text-green-600" id="statCreated">0</div>
                            <div class="text-xs text-gray-500">Created</div>
                        </div>
                        <div class="text-center p-3 bg-white rounded-lg">
                            <div class="text-2xl font-bold text-blue-600" id="statUpdated">0</div>
                            <div class="text-xs text-gray-500">Updated</div>
                        </div>
                        <div class="text-center p-3 bg-white rounded-lg">
                            <div class="text-2xl font-bold text-yellow-600" id="statSkipped">0</div>
                            <div class="text-xs text-gray-500">Skipped</div>
                        </div>
                        <div class="text-center p-3 bg-white rounded-lg">
                            <div class="text-2xl font-bold text-red-600" id="statFailed">0</div>
                            <div class="text-xs text-gray-500">Failed</div>
                        </div>
                    </div>
                    
                    <!-- Log Output -->
                    <div id="syncLog" class="log-container bg-gray-900 text-gray-300 p-4 rounded-lg text-sm max-h-48 overflow-y-auto"></div>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="bg-white rounded-xl shadow overflow-hidden mb-8">
            <div class="bg-gray-800 text-white px-6 py-4">
                <h2 class="text-lg font-semibold">
                    <i class="fas fa-bolt mr-2"></i>Quick Actions
                </h2>
            </div>
            <div class="p-6">
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <a href="admin/setup-cny.php?key=cny2024&step=migrate" 
                       class="flex flex-col items-center p-4 bg-gray-50 rounded-lg hover:bg-gray-100 transition">
                        <i class="fas fa-database text-blue-600 text-2xl mb-2"></i>
                        <span class="text-sm font-medium text-gray-700">Create Table</span>
                    </a>
                    <a href="shop/products-cny.php" 
                       class="flex flex-col items-center p-4 bg-gray-50 rounded-lg hover:bg-gray-100 transition">
                        <i class="fas fa-box text-green-600 text-2xl mb-2"></i>
                        <span class="text-sm font-medium text-gray-700">View Products</span>
                    </a>
                    <button onclick="updateCategories()" 
                            class="flex flex-col items-center p-4 bg-gray-50 rounded-lg hover:bg-gray-100 transition">
                        <i class="fas fa-tags text-purple-600 text-2xl mb-2"></i>
                        <span class="text-sm font-medium text-gray-700">Update Categories</span>
                    </button>
                    <button onclick="testApiConnection()" 
                            class="flex flex-col items-center p-4 bg-gray-50 rounded-lg hover:bg-gray-100 transition">
                        <i class="fas fa-plug text-yellow-600 text-2xl mb-2"></i>
                        <span class="text-sm font-medium text-gray-700">Test API</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- Links to Product Pages -->
        <div class="bg-white rounded-xl shadow overflow-hidden">
            <div class="bg-indigo-600 text-white px-6 py-4">
                <h2 class="text-lg font-semibold">
                    <i class="fas fa-link mr-2"></i>Product Pages
                </h2>
            </div>
            <div class="p-6">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <a href="shop/products-cny.php" class="block p-4 border-2 border-indigo-200 rounded-lg hover:border-indigo-400 transition">
                        <div class="flex items-center mb-2">
                            <i class="fas fa-pills text-indigo-600 text-xl mr-3"></i>
                            <span class="font-semibold text-gray-800">CNY Products</span>
                        </div>
                        <p class="text-sm text-gray-600">ดูสินค้าจาก CNY Cache (<?= number_format($cnyProductCount) ?> รายการ)</p>
                    </a>
                    <a href="shop/products.php" class="block p-4 border-2 border-green-200 rounded-lg hover:border-green-400 transition">
                        <div class="flex items-center mb-2">
                            <i class="fas fa-store text-green-600 text-xl mr-3"></i>
                            <span class="font-semibold text-gray-800">Shop Products</span>
                        </div>
                        <p class="text-sm text-gray-600">ดูสินค้าในระบบหลัก (<?= number_format($businessItemsCount) ?> รายการ)</p>
                    </a>
                    <a href="inventory/" class="block p-4 border-2 border-yellow-200 rounded-lg hover:border-yellow-400 transition">
                        <div class="flex items-center mb-2">
                            <i class="fas fa-warehouse text-yellow-600 text-xl mr-3"></i>
                            <span class="font-semibold text-gray-800">Inventory</span>
                        </div>
                        <p class="text-sm text-gray-600">จัดการคลังสินค้า</p>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script>
    // Sync state
    let syncRunning = false;
    let syncStats = { batches: 0, created: 0, updated: 0, skipped: 0, failed: 0 };
    
    // Check API status on load
    document.addEventListener('DOMContentLoaded', function() {
        testApiConnection();
    });
    
    // Test API Connection
    async function testApiConnection() {
        const statusEl = document.getElementById('apiStatus');
        statusEl.textContent = 'Checking...';
        statusEl.className = 'px-2 py-1 bg-gray-100 text-gray-700 text-xs rounded-full';
        
        try {
            const response = await fetch('api/cny-sync.php?action=test');
            const data = await response.json();
            
            if (data.success) {
                statusEl.textContent = 'Connected';
                statusEl.className = 'px-2 py-1 bg-green-100 text-green-700 text-xs rounded-full';
            } else {
                statusEl.textContent = 'Error';
                statusEl.className = 'px-2 py-1 bg-red-100 text-red-700 text-xs rounded-full';
            }
        } catch (e) {
            statusEl.textContent = 'Offline';
            statusEl.className = 'px-2 py-1 bg-red-100 text-red-700 text-xs rounded-full';
        }
    }
    
    // Start Continuous Sync
    function startContinuousSync(reset = false) {
        syncRunning = true;
        syncStats = { batches: 0, created: 0, updated: 0, skipped: 0, failed: 0 };
        
        document.getElementById('startSyncBtn').disabled = true;
        document.getElementById('resetSyncBtn').disabled = true;
        document.getElementById('stopSyncBtn').disabled = false;
        document.getElementById('syncStatusPanel').classList.remove('hidden');
        document.getElementById('syncStatusBadge').textContent = 'กำลังทำงาน...';
        document.getElementById('syncStatusBadge').className = 'px-3 py-1 bg-blue-500 text-white text-sm rounded-full';
        document.getElementById('syncLog').innerHTML = '';
        
        addLog(reset ? '🔄 เริ่ม sync ใหม่ตั้งแต่ต้น...' : '▶️ เริ่ม sync ต่อจากที่ค้างไว้...', 'info');
        runSyncBatch(reset);
    }
    
    // Stop Sync
    function stopSync() {
        syncRunning = false;
        document.getElementById('startSyncBtn').disabled = false;
        document.getElementById('resetSyncBtn').disabled = false;
        document.getElementById('stopSyncBtn').disabled = true;
        document.getElementById('syncStatusBadge').textContent = 'หยุดแล้ว';
        document.getElementById('syncStatusBadge').className = 'px-3 py-1 bg-gray-500 text-white text-sm rounded-full';
        addLog('⏹️ หยุด Sync แล้ว', 'warning');
    }
    
    // Run single batch
    async function runSyncBatch(reset = false) {
        if (!syncRunning) return;
        
        const batchSize = document.getElementById('batchSize').value;
        const delay = parseInt(document.getElementById('syncDelay').value);
        const maxBatches = parseInt(document.getElementById('maxBatches').value);
        
        // Check max batches
        if (maxBatches > 0 && syncStats.batches >= maxBatches) {
            addLog(`✅ ครบ ${maxBatches} batches แล้ว`, 'success');
            stopSync();
            return;
        }
        
        try {
            let url = `sync-worker-run.php?api=1&mode=direct&batch_size=${batchSize}`;
            if (reset && syncStats.batches === 0) {
                url += '&reset=1';
            }
            
            const response = await fetch(url);
            const data = await response.json();
            
            if (data.success) {
                syncStats.batches++;
                syncStats.created += data.stats.created || 0;
                syncStats.updated += data.stats.updated || 0;
                syncStats.skipped += data.stats.skipped || 0;
                syncStats.failed += data.stats.failed || 0;
                
                updateStatsDisplay();
                
                // Update progress
                if (data.progress) {
                    const current = data.progress.offset + (data.stats.processed || 0);
                    const total = data.progress.total || 0;
                    const percent = total > 0 ? Math.round((current / total) * 100) : 0;
                    document.getElementById('progressBar').style.width = percent + '%';
                    document.getElementById('progressText').textContent = `${current.toLocaleString()} / ${total.toLocaleString()}`;
                }
                
                const processed = data.stats.processed || 0;
                if (processed > 0) {
                    addLog(`✓ Batch #${syncStats.batches}: ${processed} รายการ (C:${data.stats.created} U:${data.stats.updated} S:${data.stats.skipped} F:${data.stats.failed})`, 'success');
                    
                    // Check if complete
                    if (data.progress && data.progress.complete) {
                        addLog(`🎉 Sync เสร็จสมบูรณ์! ทั้งหมด ${data.progress.total} รายการ`, 'success');
                        document.getElementById('syncStatusBadge').textContent = 'เสร็จสมบูรณ์';
                        document.getElementById('syncStatusBadge').className = 'px-3 py-1 bg-green-500 text-white text-sm rounded-full';
                        stopSync();
                        return;
                    }
                } else {
                    addLog(`⚠️ ไม่มีรายการให้ sync แล้ว`, 'warning');
                    stopSync();
                    return;
                }
                
                // Continue with delay
                if (syncRunning) {
                    setTimeout(() => runSyncBatch(false), delay);
                }
            } else {
                addLog(`❌ Error: ${data.error || 'Unknown error'}`, 'error');
                stopSync();
            }
        } catch (error) {
            addLog(`❌ Network error: ${error.message}`, 'error');
            stopSync();
        }
    }
    
    // Update stats display
    function updateStatsDisplay() {
        document.getElementById('statBatches').textContent = syncStats.batches;
        document.getElementById('statCreated').textContent = syncStats.created;
        document.getElementById('statUpdated').textContent = syncStats.updated;
        document.getElementById('statSkipped').textContent = syncStats.skipped;
        document.getElementById('statFailed').textContent = syncStats.failed;
    }
    
    // Add log entry
    function addLog(message, type = 'info') {
        const logDiv = document.getElementById('syncLog');
        const time = new Date().toLocaleTimeString('th-TH');
        const colorClass = {
            success: 'log-success',
            error: 'log-error',
            warning: 'log-warning',
            info: 'log-info'
        }[type] || 'log-info';
        
        logDiv.innerHTML += `<div class="${colorClass}">[${time}] ${message}</div>`;
        logDiv.scrollTop = logDiv.scrollHeight;
    }
    
    // Update categories
    async function updateCategories() {
        if (!confirm('อัพเดทหมวดหมู่สินค้าทั้งหมดจาก CNY data?')) return;
        
        try {
            const response = await fetch('api/cny-sync.php?action=update_categories');
            const data = await response.json();
            
            if (data.success) {
                alert(`อัพเดทหมวดหมู่เสร็จสิ้น!\nUpdated: ${data.stats.updated}\nSkipped: ${data.stats.skipped}`);
                location.reload();
            } else {
                alert('Error: ' + (data.error || 'Unknown error'));
            }
        } catch (e) {
            alert('Network error: ' + e.message);
        }
    }
    </script>
</body>
</html>
