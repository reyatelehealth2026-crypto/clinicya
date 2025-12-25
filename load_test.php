<?php
/**
 * Load Test - ทดสอบระบบรองรับผู้ใช้พร้อมกัน
 * ทดสอบ: Webhook, Chat API, Database Connections, AI Response
 */

set_time_limit(300);
ini_set('memory_limit', '512M');

require_once 'config/config.php';
require_once 'config/database.php';

$db = Database::getInstance()->getConnection();

// Configuration
$testConfig = [
    'concurrent_users' => [10, 25, 50, 100, 200], // จำนวนผู้ใช้ที่จะทดสอบ
    'requests_per_user' => 5,
    'timeout' => 30,
];

$results = [];
$startTime = microtime(true);

?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🔥 Load Test - ทดสอบระบบรองรับผู้ใช้</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="bg-gray-100 p-4">
<div class="max-w-6xl mx-auto">
    <h1 class="text-2xl font-bold mb-4">🔥 Load Test - ทดสอบระบบรองรับผู้ใช้พร้อมกัน</h1>
    
    <!-- System Info -->
    <div class="bg-white rounded-lg shadow p-4 mb-4">
        <h2 class="font-bold text-lg mb-2">📊 ข้อมูลระบบ</h2>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
            <?php
            // Server Info
            $serverInfo = [
                'PHP Version' => PHP_VERSION,
                'Max Execution Time' => ini_get('max_execution_time') . 's',
                'Memory Limit' => ini_get('memory_limit'),
                'Max Connections' => 'Checking...',
            ];
            
            // Check MySQL max connections
            try {
                $stmt = $db->query("SHOW VARIABLES LIKE 'max_connections'");
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $serverInfo['Max Connections'] = $row['Value'] ?? 'N/A';
                
                $stmt = $db->query("SHOW STATUS LIKE 'Threads_connected'");
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $serverInfo['Current Connections'] = $row['Value'] ?? 'N/A';
            } catch (Exception $e) {}
            
            foreach ($serverInfo as $key => $value):
            ?>
            <div class="bg-gray-50 p-2 rounded">
                <div class="text-gray-500 text-xs"><?= $key ?></div>
                <div class="font-bold"><?= $value ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Test Controls -->
    <div class="bg-white rounded-lg shadow p-4 mb-4">
        <h2 class="font-bold text-lg mb-2">🎮 เริ่มทดสอบ</h2>
        <div class="flex flex-wrap gap-2 mb-4">
            <button onclick="runTest('database')" class="bg-blue-500 text-white px-4 py-2 rounded hover:bg-blue-600">
                💾 Test Database
            </button>
            <button onclick="runTest('api')" class="bg-green-500 text-white px-4 py-2 rounded hover:bg-green-600">
                🔌 Test API
            </button>
            <button onclick="runTest('webhook')" class="bg-purple-500 text-white px-4 py-2 rounded hover:bg-purple-600">
                📨 Test Webhook
            </button>
            <button onclick="runTest('chat')" class="bg-orange-500 text-white px-4 py-2 rounded hover:bg-orange-600">
                💬 Test Chat
            </button>
            <button onclick="runTest('full')" class="bg-red-500 text-white px-4 py-2 rounded hover:bg-red-600">
                🔥 Full Load Test
            </button>
        </div>
        
        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium mb-1">จำนวน Concurrent Users (max 50)</label>
                <input type="number" id="concurrentUsers" value="10" min="1" max="50" 
                       class="w-full border rounded px-3 py-2">
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Requests per User (max 10)</label>
                <input type="number" id="requestsPerUser" value="3" min="1" max="10" 
                       class="w-full border rounded px-3 py-2">
            </div>
        </div>
        
        <div class="mt-3 text-sm text-gray-500">
            ⚠️ ทดสอบแบบ concurrent จริงๆ ด้วย curl_multi - อาจทำให้ server หนักได้
        </div>
    </div>

    <!-- Results -->
    <div class="bg-white rounded-lg shadow p-4 mb-4">
        <h2 class="font-bold text-lg mb-2">📈 ผลการทดสอบ</h2>
        <div id="testProgress" class="mb-4 hidden">
            <div class="flex items-center gap-2">
                <div class="animate-spin h-5 w-5 border-2 border-blue-500 border-t-transparent rounded-full"></div>
                <span id="progressText">กำลังทดสอบ...</span>
            </div>
            <div class="w-full bg-gray-200 rounded-full h-2 mt-2">
                <div id="progressBar" class="bg-blue-500 h-2 rounded-full" style="width: 0%"></div>
            </div>
        </div>
        
        <div id="results" class="space-y-4">
            <p class="text-gray-500">กดปุ่มด้านบนเพื่อเริ่มทดสอบ</p>
        </div>
    </div>

    <!-- Chart -->
    <div class="bg-white rounded-lg shadow p-4 mb-4">
        <h2 class="font-bold text-lg mb-2">📊 กราฟ Response Time</h2>
        <canvas id="responseChart" height="200"></canvas>
    </div>

    <!-- Recommendations -->
    <div class="bg-white rounded-lg shadow p-4" id="recommendations">
        <h2 class="font-bold text-lg mb-2">💡 คำแนะนำ</h2>
        <div id="recommendationContent" class="text-sm text-gray-600">
            รันการทดสอบเพื่อดูคำแนะนำ
        </div>
    </div>
</div>

<script>
let chart = null;
const testResults = [];

async function runTest(type) {
    const concurrentUsers = parseInt(document.getElementById('concurrentUsers').value);
    const requestsPerUser = parseInt(document.getElementById('requestsPerUser').value);
    
    document.getElementById('testProgress').classList.remove('hidden');
    document.getElementById('progressText').textContent = `กำลังทดสอบ ${type}...`;
    document.getElementById('progressBar').style.width = '0%';
    
    try {
        const response = await fetch(`load_test_runner.php?type=${type}&users=${concurrentUsers}&requests=${requestsPerUser}`);
        const data = await response.json();
        
        document.getElementById('progressBar').style.width = '100%';
        displayResults(data);
        updateChart(data);
        showRecommendations(data);
        
    } catch (error) {
        document.getElementById('results').innerHTML = `
            <div class="bg-red-100 text-red-700 p-4 rounded">
                ❌ Error: ${error.message}
            </div>
        `;
    }
    
    setTimeout(() => {
        document.getElementById('testProgress').classList.add('hidden');
    }, 1000);
}

function displayResults(data) {
    const errorsHtml = data.errors && data.errors.length > 0 
        ? `<div class="mt-4 p-3 bg-red-50 rounded text-sm">
            <strong>Errors:</strong> ${data.errors.join(', ')}
           </div>` 
        : '';
    
    const html = `
        <div class="bg-blue-50 p-2 rounded mb-4 text-sm">
            <strong>Mode:</strong> ${data.test_mode || 'sequential'} | 
            <strong>Total Time:</strong> ${data.total_time}s
        </div>
        
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <div class="bg-blue-50 p-3 rounded">
                <div class="text-xs text-blue-600">Total Requests</div>
                <div class="text-2xl font-bold text-blue-700">${data.total_requests}</div>
            </div>
            <div class="bg-green-50 p-3 rounded">
                <div class="text-xs text-green-600">Successful</div>
                <div class="text-2xl font-bold text-green-700">${data.successful}</div>
            </div>
            <div class="bg-red-50 p-3 rounded">
                <div class="text-xs text-red-600">Failed</div>
                <div class="text-2xl font-bold text-red-700">${data.failed}</div>
            </div>
            <div class="bg-purple-50 p-3 rounded">
                <div class="text-xs text-purple-600">Success Rate</div>
                <div class="text-2xl font-bold text-purple-700">${data.success_rate}%</div>
            </div>
        </div>
        
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mt-4">
            <div class="bg-gray-50 p-3 rounded">
                <div class="text-xs text-gray-600">Avg Response</div>
                <div class="text-xl font-bold">${data.avg_response_time}ms</div>
            </div>
            <div class="bg-gray-50 p-3 rounded">
                <div class="text-xs text-gray-600">P50 (Median)</div>
                <div class="text-xl font-bold">${data.p50_response_time || '-'}ms</div>
            </div>
            <div class="bg-gray-50 p-3 rounded">
                <div class="text-xs text-gray-600">P95</div>
                <div class="text-xl font-bold">${data.p95_response_time || '-'}ms</div>
            </div>
            <div class="bg-gray-50 p-3 rounded">
                <div class="text-xs text-gray-600">P99</div>
                <div class="text-xl font-bold">${data.p99_response_time || '-'}ms</div>
            </div>
        </div>
        
        <div class="grid grid-cols-3 gap-4 mt-4">
            <div class="bg-gray-50 p-3 rounded">
                <div class="text-xs text-gray-600">Min / Max</div>
                <div class="text-lg font-bold">${data.min_response_time}ms / ${data.max_response_time}ms</div>
            </div>
            <div class="bg-gray-50 p-3 rounded">
                <div class="text-xs text-gray-600">Requests/sec</div>
                <div class="text-xl font-bold">${data.requests_per_second}</div>
            </div>
            <div class="bg-gray-50 p-3 rounded">
                <div class="text-xs text-gray-600">Est. Users Supported</div>
                <div class="text-xl font-bold">${data.estimated_capacity?.concurrent_users_supported || '-'}</div>
            </div>
        </div>
        
        <div class="mt-4 p-3 rounded ${data.success_rate >= 95 ? 'bg-green-100 text-green-700' : data.success_rate >= 80 ? 'bg-yellow-100 text-yellow-700' : 'bg-red-100 text-red-700'}">
            <strong>สรุป:</strong> ทดสอบ <strong>${data.concurrent_users}</strong> concurrent users, 
            ${data.requests_per_user} requests/user
            ${data.success_rate >= 95 ? '✅ ผ่าน' : data.success_rate >= 80 ? '⚠️ พอใช้' : '❌ ต้องปรับปรุง'}
        </div>
        ${errorsHtml}
    `;
    
    document.getElementById('results').innerHTML = html;
}

function updateChart(data) {
    const ctx = document.getElementById('responseChart').getContext('2d');
    
    if (chart) chart.destroy();
    
    chart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: data.response_times_distribution?.labels || ['0-100ms', '100-500ms', '500-1000ms', '1000ms+'],
            datasets: [{
                label: 'จำนวน Requests',
                data: data.response_times_distribution?.data || [0, 0, 0, 0],
                backgroundColor: ['#10B981', '#3B82F6', '#F59E0B', '#EF4444']
            }]
        },
        options: {
            responsive: true,
            plugins: {
                title: {
                    display: true,
                    text: 'Response Time Distribution'
                }
            }
        }
    });
}

function showRecommendations(data) {
    let recommendations = [];
    
    if (data.success_rate < 95) {
        recommendations.push('⚠️ Success rate ต่ำกว่า 95% - ควรตรวจสอบ server resources');
    }
    if (data.avg_response_time > 1000) {
        recommendations.push('🐌 Response time สูง - ควรเพิ่ม caching หรือ optimize queries');
    }
    if (data.max_response_time > 5000) {
        recommendations.push('⏱️ Max response time สูงมาก - อาจมี bottleneck ในระบบ');
    }
    if (data.concurrent_users >= 100 && data.success_rate >= 95) {
        recommendations.push('✅ ระบบรองรับ 100+ users ได้ดี');
    }
    if (data.requests_per_second < 10) {
        recommendations.push('📈 Throughput ต่ำ - ควรพิจารณา horizontal scaling');
    }
    
    // Server recommendations
    recommendations.push('💡 เพิ่ม MySQL max_connections ถ้าต้องการรองรับผู้ใช้มากขึ้น');
    recommendations.push('💡 ใช้ Redis/Memcached สำหรับ session และ caching');
    recommendations.push('💡 ใช้ CDN สำหรับ static files');
    
    document.getElementById('recommendationContent').innerHTML = recommendations.map(r => `<p class="mb-1">${r}</p>`).join('');
}
</script>
</body>
</html>
