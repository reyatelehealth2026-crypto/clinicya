<?php
/**
 * CNY Sync Dashboard
 * Dashboard สำหรับ monitor และจัดการ sync queue
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/SyncQueue.php';

$db = Database::getInstance()->getConnection();
$queue = new SyncQueue($db);

// Get stats
$stats = $queue->getStats();
$logs = $queue->getRecentLogs(30);

// Calculate progress
$total = $stats['total'] ?: 1;
$done = $stats['completed'] + $stats['failed'] + $stats['skipped'];
$progress = round(($done / $total) * 100);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🔄 CNY Sync Dashboard</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        .container { max-width: 1200px; margin: 0 auto; }
        .card {
            background: white;
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 20px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }
        h1 { color: white; font-size: 28px; }
        h2 { color: #2d3748; font-size: 18px; margin-bottom: 16px; }
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .stat-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
        }
        .stat-card.success { background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); }
        .stat-card.warning { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); }
        .stat-card.info { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); }
        .stat-number { font-size: 32px; font-weight: bold; margin-bottom: 8px; }
        .stat-label { font-size: 13px; opacity: 0.9; }
        .btn {
            display: inline-block;
            padding: 10px 20px;
            background: #667eea;
            color: white;
            text-decoration: none;
            border-radius: 6px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            margin: 4px;
        }
        .btn:hover { opacity: 0.9; }
        .btn-success { background: #10B981; }
        .btn-warning { background: #F59E0B; }
        .btn-danger { background: #EF4444; }
        .btn-secondary { background: #718096; }
        .progress-container {
            background: #e2e8f0;
            border-radius: 10px;
            height: 24px;
            overflow: hidden;
            margin: 16px 0;
        }
        .progress-bar {
            height: 100%;
            background: linear-gradient(90deg, #667eea, #764ba2);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 12px;
            transition: width 0.5s;
        }
        .log-container {
            background: #1a202c;
            color: #e2e8f0;
            padding: 16px;
            border-radius: 6px;
            font-family: 'Courier New', monospace;
            font-size: 12px;
            max-height: 300px;
            overflow-y: auto;
        }
        .log-line { margin-bottom: 4px; line-height: 1.5; }
        .log-success { color: #68d391; }
        .log-error { color: #fc8181; }
        .action-buttons { display: flex; flex-wrap: wrap; gap: 8px; }
        .form-group { margin-bottom: 16px; }
        label { display: block; margin-bottom: 8px; color: #4a5568; font-weight: 600; }
        input, select { width: 100%; padding: 10px; border: 2px solid #e2e8f0; border-radius: 6px; }
        .hidden { display: none; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🔄 CNY Sync Dashboard</h1>
            <button class="btn btn-secondary" onclick="location.reload()">🔄 Refresh</button>
        </div>
        
        <!-- Stats -->
        <div class="stats">
            <div class="stat-card">
                <div class="stat-number"><?= number_format($stats['total']) ?></div>
                <div class="stat-label">Total Jobs</div>
            </div>
            <div class="stat-card info">
                <div class="stat-number"><?= number_format($stats['pending']) ?></div>
                <div class="stat-label">Pending</div>
            </div>
            <div class="stat-card warning">
                <div class="stat-number"><?= number_format($stats['processing']) ?></div>
                <div class="stat-label">Processing</div>
            </div>
            <div class="stat-card success">
                <div class="stat-number"><?= number_format($stats['completed']) ?></div>
                <div class="stat-label">Completed</div>
            </div>
            <div class="stat-card" style="background: linear-gradient(135deg, #f5576c 0%, #f093fb 100%);">
                <div class="stat-number"><?= number_format($stats['failed']) ?></div>
                <div class="stat-label">Failed</div>
            </div>
        </div>
        
        <!-- Progress -->
        <div class="card">
            <h2>📊 Overall Progress</h2>
            <div class="progress-container">
                <div class="progress-bar" style="width: <?= $progress ?>%"><?= $progress ?>%</div>
            </div>
            <p style="color: #718096; font-size: 14px;">
                <?= number_format($done) ?> of <?= number_format($stats['total']) ?> jobs processed
                (<?= number_format($stats['failed']) ?> failed, <?= number_format($stats['skipped']) ?> skipped)
            </p>
        </div>
        
        <!-- Continuous Sync -->
        <div class="card">
            <h2>🔄 Continuous Sync (ซิงค์ต่อเนื่องจาก CNY API)</h2>
            <div style="display: flex; gap: 16px; align-items: flex-end; flex-wrap: wrap; margin-bottom: 16px;">
                <div class="form-group" style="margin-bottom: 0; flex: 1; min-width: 120px;">
                    <label>Batch Size (ทีละกี่รายการ)</label>
                    <select id="continuousBatchSize">
                        <option value="5">5 รายการ</option>
                        <option value="10" selected>10 รายการ</option>
                        <option value="20">20 รายการ</option>
                        <option value="50">50 รายการ</option>
                        <option value="100">100 รายการ</option>
                    </select>
                </div>
                <div class="form-group" style="margin-bottom: 0; flex: 1; min-width: 120px;">
                    <label>Delay (หน่วงระหว่าง batch)</label>
                    <select id="continuousDelay">
                        <option value="1000">1 วินาที</option>
                        <option value="2000" selected>2 วินาที</option>
                        <option value="3000">3 วินาที</option>
                        <option value="5000">5 วินาที</option>
                    </select>
                </div>
                <div class="form-group" style="margin-bottom: 0; flex: 1; min-width: 120px;">
                    <label>Max Batches (0 = ไม่จำกัด)</label>
                    <input type="number" id="maxBatches" value="0" min="0" style="width: 100%;">
                </div>
                <div style="display: flex; gap: 8px;">
                    <button class="btn btn-success" id="startContinuousBtn" onclick="startContinuousSync(false)">▶️ เริ่ม Sync</button>
                    <button class="btn" id="resetSyncBtn" onclick="startContinuousSync(true)" style="background:#8b5cf6;">🔄 เริ่มใหม่</button>
                    <button class="btn btn-danger" id="stopContinuousBtn" onclick="stopContinuousSync()" disabled>⏹️ หยุด</button>
                </div>
            </div>
            
            <!-- Continuous Sync Status -->
            <div id="continuousSyncStatus" class="hidden" style="background: #f7fafc; border-radius: 8px; padding: 16px; margin-top: 16px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                    <span style="font-weight: 600; color: #2d3748;">📊 สถานะ Sync</span>
                    <span id="syncStatusBadge" style="background: #48bb78; color: white; padding: 4px 12px; border-radius: 20px; font-size: 12px;">กำลังทำงาน...</span>
                </div>
                <!-- Progress Bar -->
                <div id="syncProgressContainer" class="hidden" style="margin-bottom: 12px;">
                    <div style="display: flex; justify-content: space-between; font-size: 12px; color: #718096; margin-bottom: 4px;">
                        <span>Progress</span>
                        <span id="syncProgressText">0 / 0</span>
                    </div>
                    <div style="background: #e2e8f0; border-radius: 10px; height: 12px; overflow: hidden;">
                        <div id="syncProgressBar" style="height: 100%; background: linear-gradient(90deg, #667eea, #764ba2); width: 0%; transition: width 0.3s;"></div>
                    </div>
                </div>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(100px, 1fr)); gap: 12px; text-align: center;">
                    <div><div style="font-size: 24px; font-weight: bold; color: #667eea;" id="syncBatchCount">0</div><div style="font-size: 11px; color: #718096;">Batches</div></div>
                    <div><div style="font-size: 24px; font-weight: bold; color: #48bb78;" id="syncCreated">0</div><div style="font-size: 11px; color: #718096;">Created</div></div>
                    <div><div style="font-size: 24px; font-weight: bold; color: #4299e1;" id="syncUpdated">0</div><div style="font-size: 11px; color: #718096;">Updated</div></div>
                    <div><div style="font-size: 24px; font-weight: bold; color: #ed8936;" id="syncSkipped">0</div><div style="font-size: 11px; color: #718096;">Skipped</div></div>
                    <div><div style="font-size: 24px; font-weight: bold; color: #f56565;" id="syncFailed">0</div><div style="font-size: 11px; color: #718096;">Failed</div></div>
                </div>
                <div id="syncLog" style="margin-top: 12px; background: #1a202c; color: #e2e8f0; padding: 12px; border-radius: 6px; font-family: monospace; font-size: 11px; max-height: 150px; overflow-y: auto;"></div>
            </div>
        </div>
        
        <!-- Actions -->
        <div class="card">
            <h2>⚡ Quick Actions</h2>
            <div class="action-buttons">
                <button class="btn btn-success" onclick="createBatch()">➕ Create Batch from API</button>
                <a href="sync-worker-run.php?batch_size=5" class="btn" target="_blank">▶️ Run Worker (5)</a>
                <a href="sync-worker-run.php?batch_size=10" class="btn" target="_blank">▶️ Run Worker (10)</a>
                <a href="sync_categories_from_manufacturer.php" class="btn" style="background:#8b5cf6;color:white;">🏷️ สร้างหมวดหมู่จากผู้ผลิต</a>
                <button class="btn btn-warning" onclick="clearFailed()">🗑️ Clear Failed</button>
                <button class="btn btn-danger" onclick="clearAll()">🗑️ Clear All</button>
            </div>
            
            <h2 style="margin-top:20px;">📦 Direct Sync (ไม่ผ่าน Queue)</h2>
            <div class="action-buttons">
                <a href="sync_products_with_sku_id.php" class="btn" style="background:#059669;color:white;" target="_blank">🔄 Sync with SKU</a>
                <a href="sync_cny_with_id.php" class="btn" style="background:#0891b2;color:white;" target="_blank">🔄 Sync with CNY ID</a>
                <a href="sync_update_categories.php" class="btn" style="background:#7c3aed;color:white;" target="_blank">📁 Sync Categories</a>
            </div>
        </div>
        
        <!-- Create Batch Form -->
        <div class="card hidden" id="batchForm">
            <h2>➕ Create New Batch</h2>
            <form action="api/sync_queue.php" method="POST">
                <input type="hidden" name="action" value="create_batch">
                <div class="form-group">
                    <label>Batch Name</label>
                    <input type="text" name="batch_name" value="Sync <?= date('Y-m-d H:i') ?>" required>
                </div>
                <div class="form-group">
                    <label>Priority (1=highest, 10=lowest)</label>
                    <input type="number" name="priority" value="5" min="1" max="10">
                </div>
                <button type="submit" class="btn btn-success">Create Batch</button>
                <button type="button" class="btn btn-secondary" onclick="hideBatchForm()">Cancel</button>
            </form>
        </div>
        
        <!-- Recent Logs -->
        <div class="card">
            <h2>📝 Recent Activity</h2>
            <div class="log-container">
                <?php if (empty($logs)): ?>
                    <div class="log-line">No logs yet</div>
                <?php else: ?>
                    <?php foreach ($logs as $log): ?>
                        <div class="log-line <?= $log['action'] === 'failed' ? 'log-error' : ($log['action'] === 'created' ? 'log-success' : '') ?>">
                            [<?= $log['created_at'] ?>] 
                            <?= $log['action'] === 'created' ? '✓' : ($log['action'] === 'failed' ? '✗' : '○') ?>
                            <?= htmlspecialchars($log['sku']) ?> - <?= $log['action'] ?> 
                            (<?= $log['duration_ms'] ?>ms)
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script>
        // Continuous Sync Variables
        let continuousSyncRunning = false;
        let continuousSyncStats = { batches: 0, created: 0, updated: 0, skipped: 0, failed: 0 };
        let autoRefreshTimer = null;
        let resetOnStart = false;
        
        function createBatch() {
            document.getElementById('batchForm').classList.remove('hidden');
        }
        
        function hideBatchForm() {
            document.getElementById('batchForm').classList.add('hidden');
        }
        
        function clearFailed() {
            if (confirm('Clear all failed jobs?')) {
                fetch('api/sync_queue.php?action=clear_failed', { method: 'POST' })
                    .then(r => r.json())
                    .then(d => { alert(d.message || 'Done'); location.reload(); });
            }
        }
        
        function clearAll() {
            if (confirm('Clear ALL jobs from queue? This cannot be undone!')) {
                fetch('api/sync_queue.php?action=clear_all', { method: 'POST' })
                    .then(r => r.json())
                    .then(d => { alert(d.message || 'Done'); location.reload(); });
            }
        }
        
        // Continuous Sync Functions
        function startContinuousSync(reset = false) {
            continuousSyncRunning = true;
            resetOnStart = reset;
            continuousSyncStats = { batches: 0, created: 0, updated: 0, skipped: 0, failed: 0 };
            
            document.getElementById('startContinuousBtn').disabled = true;
            document.getElementById('resetSyncBtn').disabled = true;
            document.getElementById('stopContinuousBtn').disabled = false;
            document.getElementById('continuousSyncStatus').classList.remove('hidden');
            document.getElementById('syncProgressContainer').classList.remove('hidden');
            document.getElementById('syncStatusBadge').textContent = 'กำลังทำงาน...';
            document.getElementById('syncStatusBadge').style.background = '#48bb78';
            document.getElementById('syncLog').innerHTML = '';
            
            // Stop auto refresh while syncing
            if (autoRefreshTimer) clearTimeout(autoRefreshTimer);
            
            addSyncLog(reset ? '🔄 เริ่ม sync ใหม่ตั้งแต่ต้น...' : '▶️ เริ่ม sync ต่อจากที่ค้างไว้...', 'info');
            runContinuousBatch();
        }
        
        function stopContinuousSync() {
            continuousSyncRunning = false;
            document.getElementById('startContinuousBtn').disabled = false;
            document.getElementById('resetSyncBtn').disabled = false;
            document.getElementById('stopContinuousBtn').disabled = true;
            document.getElementById('syncStatusBadge').textContent = 'หยุดแล้ว';
            document.getElementById('syncStatusBadge').style.background = '#718096';
            addSyncLog('⏹️ หยุด Sync แล้ว', 'warning');
        }
        
        async function runContinuousBatch() {
            if (!continuousSyncRunning) return;
            
            const batchSize = document.getElementById('continuousBatchSize').value;
            const delay = parseInt(document.getElementById('continuousDelay').value);
            const maxBatches = parseInt(document.getElementById('maxBatches').value);
            
            // Check max batches
            if (maxBatches > 0 && continuousSyncStats.batches >= maxBatches) {
                addSyncLog(`✅ ครบ ${maxBatches} batches แล้ว`, 'success');
                stopContinuousSync();
                return;
            }
            
            try {
                // Build URL - use sync-worker-run.php with api=1&mode=direct
                let url = `sync-worker-run.php?api=1&mode=direct&batch_size=${batchSize}`;
                if (resetOnStart && continuousSyncStats.batches === 0) {
                    url += '&reset=1';
                }
                
                const response = await fetch(url);
                const data = await response.json();
                
                if (data.success) {
                    continuousSyncStats.batches++;
                    continuousSyncStats.created += data.stats.created || 0;
                    continuousSyncStats.updated += data.stats.updated || 0;
                    continuousSyncStats.skipped += data.stats.skipped || 0;
                    continuousSyncStats.failed += data.stats.failed || 0;
                    
                    updateSyncDisplay();
                    
                    // Update progress bar
                    if (data.progress) {
                        const current = data.progress.offset + (data.stats.processed || 0);
                        const total = data.progress.total || 0;
                        const percent = total > 0 ? Math.round((current / total) * 100) : 0;
                        document.getElementById('syncProgressBar').style.width = percent + '%';
                        document.getElementById('syncProgressText').textContent = `${current.toLocaleString()} / ${total.toLocaleString()}`;
                    }
                    
                    const processed = data.stats.processed || 0;
                    if (processed > 0) {
                        addSyncLog(`✓ Batch #${continuousSyncStats.batches}: ${processed} รายการ (C:${data.stats.created} U:${data.stats.updated} S:${data.stats.skipped} F:${data.stats.failed})`, 'success');
                        
                        // Check if complete
                        if (data.progress && data.progress.complete) {
                            addSyncLog(`🎉 Sync เสร็จสมบูรณ์! ทั้งหมด ${data.progress.total} รายการ`, 'success');
                            document.getElementById('syncStatusBadge').textContent = 'เสร็จสมบูรณ์';
                            document.getElementById('syncStatusBadge').style.background = '#48bb78';
                            stopContinuousSync();
                            return;
                        }
                    } else {
                        addSyncLog(`⚠️ ไม่มีรายการให้ sync แล้ว`, 'warning');
                        stopContinuousSync();
                        return;
                    }
                    
                    // Continue with delay
                    if (continuousSyncRunning) {
                        setTimeout(runContinuousBatch, delay);
                    }
                } else {
                    addSyncLog(`❌ Error: ${data.error || 'Unknown error'}`, 'error');
                    stopContinuousSync();
                }
            } catch (error) {
                addSyncLog(`❌ Network error: ${error.message}`, 'error');
                stopContinuousSync();
            }
        }
        
        function updateSyncDisplay() {
            document.getElementById('syncBatchCount').textContent = continuousSyncStats.batches;
            document.getElementById('syncCreated').textContent = continuousSyncStats.created;
            document.getElementById('syncUpdated').textContent = continuousSyncStats.updated;
            document.getElementById('syncSkipped').textContent = continuousSyncStats.skipped;
            document.getElementById('syncFailed').textContent = continuousSyncStats.failed;
        }
        
        function addSyncLog(message, type = 'info') {
            const logDiv = document.getElementById('syncLog');
            const time = new Date().toLocaleTimeString('th-TH');
            const colors = { success: '#68d391', error: '#fc8181', warning: '#f6e05e', info: '#90cdf4' };
            logDiv.innerHTML += `<div style="color: ${colors[type] || '#e2e8f0'}">[${time}] ${message}</div>`;
            logDiv.scrollTop = logDiv.scrollHeight;
        }
        
        // Auto refresh every 10 seconds (only when not syncing)
        function startAutoRefresh() {
            if (!continuousSyncRunning) {
                autoRefreshTimer = setTimeout(() => location.reload(), 10000);
            }
        }
        startAutoRefresh();
    </script>
</body>
</html>
