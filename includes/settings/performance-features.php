<?php
/**
 * Performance Features Settings Tab
 * 
 * Manage inbox v2 performance upgrade feature flags and rollout
 * 
 * Requirements: Task 25.1 - Feature flag for gradual rollout
 */

if (!defined('INCLUDED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/../../classes/VibeSellingHelper.php';

$db = Database::getInstance()->getConnection();
$helper = VibeSellingHelper::getInstance($db);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_performance_settings') {
    try {
        $settings = [
            'performance_upgrade_enabled' => isset($_POST['performance_upgrade_enabled']) ? '1' : '0',
            'websocket_enabled' => isset($_POST['websocket_enabled']) ? '1' : '0',
            'performance_rollout_percentage' => intval($_POST['performance_rollout_percentage'] ?? 10),
            'performance_internal_users' => trim($_POST['performance_internal_users'] ?? '')
        ];
        
        // Validate rollout percentage
        if ($settings['performance_rollout_percentage'] < 0 || $settings['performance_rollout_percentage'] > 100) {
            throw new Exception('Rollout percentage must be between 0 and 100');
        }
        
        // Update settings
        $stmt = $db->prepare("
            INSERT INTO vibe_selling_settings (line_account_id, setting_key, setting_value, created_at, updated_at)
            VALUES (NULL, ?, ?, NOW(), NOW())
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
        ");
        
        foreach ($settings as $key => $value) {
            $stmt->execute([$key, $value]);
        }
        
        $successMessage = 'Performance settings updated successfully!';
    } catch (Exception $e) {
        $errorMessage = 'Error updating settings: ' . $e->getMessage();
    }
}

// Load current settings
$currentSettings = [];
$stmt = $db->query("
    SELECT setting_key, setting_value 
    FROM vibe_selling_settings 
    WHERE setting_key IN ('performance_upgrade_enabled', 'websocket_enabled', 'performance_rollout_percentage', 'performance_internal_users')
");

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $currentSettings[$row['setting_key']] = $row['setting_value'];
}

// Set defaults if not found
$performanceEnabled = ($currentSettings['performance_upgrade_enabled'] ?? '0') === '1';
$websocketEnabled = ($currentSettings['websocket_enabled'] ?? '0') === '1';
$rolloutPercentage = intval($currentSettings['performance_rollout_percentage'] ?? 10);
$internalUsers = $currentSettings['performance_internal_users'] ?? '';

// Get statistics
$totalUsers = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
$estimatedTestUsers = ceil($totalUsers * ($rolloutPercentage / 100));

?>

<div class="performance-features-settings">
    <?php if (isset($successMessage)): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle"></i> <?= htmlspecialchars($successMessage) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <?php if (isset($errorMessage)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($errorMessage) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <div class="card">
        <div class="card-header">
            <h5 class="mb-0">
                <i class="fas fa-rocket"></i> Performance Upgrade Features
            </h5>
        </div>
        <div class="card-body">
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i>
                <strong>About Performance Upgrade:</strong> The inbox v2 performance upgrade includes AJAX conversation switching, 
                automatic conversation bumping, WebSocket real-time updates, virtual scrolling, and intelligent caching for a faster, 
                smoother user experience.
            </div>
            
            <form method="POST" action="">
                <input type="hidden" name="action" value="update_performance_settings">
                
                <!-- Master Switch -->
                <div class="mb-4">
                    <h6 class="fw-bold">Master Switch</h6>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="performance_upgrade_enabled" 
                               name="performance_upgrade_enabled" <?= $performanceEnabled ? 'checked' : '' ?>>
                        <label class="form-check-label" for="performance_upgrade_enabled">
                            <strong>Enable Performance Upgrade Features</strong>
                        </label>
                    </div>
                    <small class="text-muted">
                        When enabled, performance features will be rolled out according to the settings below.
                    </small>
                </div>
                
                <hr>
                
                <!-- WebSocket -->
                <div class="mb-4">
                    <h6 class="fw-bold">Real-time Updates</h6>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="websocket_enabled" 
                               name="websocket_enabled" <?= $websocketEnabled ? 'checked' : '' ?>>
                        <label class="form-check-label" for="websocket_enabled">
                            <strong>Enable WebSocket Real-time Updates</strong>
                        </label>
                    </div>
                    <small class="text-muted">
                        Requires WebSocket server to be running. Falls back to polling if unavailable.
                    </small>
                    
                    <?php if ($websocketEnabled): ?>
                        <div class="mt-2">
                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="testWebSocketConnection()">
                                <i class="fas fa-plug"></i> Test WebSocket Connection
                            </button>
                            <span id="websocket-status" class="ms-2"></span>
                        </div>
                    <?php endif; ?>
                </div>
                
                <hr>
                
                <!-- Rollout Strategy -->
                <div class="mb-4">
                    <h6 class="fw-bold">Rollout Strategy</h6>
                    
                    <!-- Internal Team -->
                    <div class="mb-3">
                        <label for="performance_internal_users" class="form-label">
                            <strong>Internal Team User IDs</strong>
                        </label>
                        <input type="text" class="form-control" id="performance_internal_users" 
                               name="performance_internal_users" value="<?= htmlspecialchars($internalUsers) ?>"
                               placeholder="1,2,3,4,5">
                        <small class="text-muted">
                            Comma-separated user IDs. These users will always have performance features enabled (for testing).
                        </small>
                    </div>
                    
                    <!-- A/B Test Percentage -->
                    <div class="mb-3">
                        <label for="performance_rollout_percentage" class="form-label">
                            <strong>Rollout Percentage (A/B Test)</strong>
                        </label>
                        <div class="row align-items-center">
                            <div class="col-md-6">
                                <input type="range" class="form-range" id="performance_rollout_percentage" 
                                       name="performance_rollout_percentage" min="0" max="100" step="5" 
                                       value="<?= $rolloutPercentage ?>" 
                                       oninput="updateRolloutDisplay(this.value)">
                            </div>
                            <div class="col-md-6">
                                <div class="input-group">
                                    <input type="number" class="form-control" id="rollout_percentage_input" 
                                           min="0" max="100" value="<?= $rolloutPercentage ?>"
                                           onchange="document.getElementById('performance_rollout_percentage').value = this.value; updateRolloutDisplay(this.value)">
                                    <span class="input-group-text">%</span>
                                </div>
                            </div>
                        </div>
                        <div id="rollout-display" class="mt-2">
                            <span class="badge bg-primary" id="rollout-badge"><?= $rolloutPercentage ?>%</span>
                            <span class="text-muted ms-2">
                                Approximately <strong id="estimated-users"><?= number_format($estimatedTestUsers) ?></strong> 
                                out of <strong><?= number_format($totalUsers) ?></strong> users
                            </span>
                        </div>
                        <small class="text-muted">
                            Percentage of users (excluding internal team) who will receive performance features. 
                            Uses consistent hashing to ensure same users always get same experience.
                        </small>
                    </div>
                </div>
                
                <hr>
                
                <!-- Rollout Phases -->
                <div class="mb-4">
                    <h6 class="fw-bold">Recommended Rollout Phases</h6>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered">
                            <thead>
                                <tr>
                                    <th>Phase</th>
                                    <th>Duration</th>
                                    <th>Percentage</th>
                                    <th>Description</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>1</td>
                                    <td>1 week</td>
                                    <td>Internal Only</td>
                                    <td>Test with internal team</td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-outline-primary" 
                                                onclick="setRolloutPhase(0, '1,2,3')">
                                            Apply
                                        </button>
                                    </td>
                                </tr>
                                <tr>
                                    <td>2</td>
                                    <td>1 week</td>
                                    <td>10%</td>
                                    <td>A/B test with small group</td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-outline-primary" 
                                                onclick="setRolloutPhase(10, '')">
                                            Apply
                                        </button>
                                    </td>
                                </tr>
                                <tr>
                                    <td>3</td>
                                    <td>1 week</td>
                                    <td>25%</td>
                                    <td>Expand to quarter of users</td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-outline-primary" 
                                                onclick="setRolloutPhase(25, '')">
                                            Apply
                                        </button>
                                    </td>
                                </tr>
                                <tr>
                                    <td>4</td>
                                    <td>1 week</td>
                                    <td>50%</td>
                                    <td>Expand to half of users</td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-outline-primary" 
                                                onclick="setRolloutPhase(50, '')">
                                            Apply
                                        </button>
                                    </td>
                                </tr>
                                <tr>
                                    <td>5</td>
                                    <td>Ongoing</td>
                                    <td>100%</td>
                                    <td>Full rollout to all users</td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-outline-success" 
                                                onclick="setRolloutPhase(100, '')">
                                            Apply
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <!-- Submit Button -->
                <div class="d-grid gap-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Save Settings
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Monitoring Card -->
    <div class="card mt-4">
        <div class="card-header">
            <h5 class="mb-0">
                <i class="fas fa-chart-line"></i> Monitoring & Metrics
            </h5>
        </div>
        <div class="card-body">
            <p class="text-muted">
                Monitor performance metrics and user feedback during rollout:
            </p>
            <ul>
                <li>Page load times (target: < 2s)</li>
                <li>Conversation switch times (target: < 500ms)</li>
                <li>Message render times (target: < 200ms)</li>
                <li>API response times (target: < 300ms)</li>
                <li>Error rates and user complaints</li>
            </ul>
            <a href="analytics.php?tab=performance" class="btn btn-outline-primary">
                <i class="fas fa-chart-bar"></i> View Performance Dashboard
            </a>
        </div>
    </div>
</div>

<script>
function updateRolloutDisplay(percentage) {
    const totalUsers = <?= $totalUsers ?>;
    const estimatedUsers = Math.ceil(totalUsers * (percentage / 100));
    
    document.getElementById('rollout_percentage_input').value = percentage;
    document.getElementById('rollout-badge').textContent = percentage + '%';
    document.getElementById('estimated-users').textContent = estimatedUsers.toLocaleString();
}

function setRolloutPhase(percentage, internalUsers) {
    document.getElementById('performance_rollout_percentage').value = percentage;
    document.getElementById('performance_internal_users').value = internalUsers;
    updateRolloutDisplay(percentage);
}

function testWebSocketConnection() {
    const statusEl = document.getElementById('websocket-status');
    statusEl.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Testing...';
    
    try {
        const socket = io(window.location.protocol + '//' + window.location.hostname + ':3000', {
            transports: ['websocket', 'polling'],
            timeout: 5000
        });
        
        socket.on('connect', () => {
            statusEl.innerHTML = '<span class="badge bg-success"><i class="fas fa-check"></i> Connected</span>';
            socket.disconnect();
        });
        
        socket.on('connect_error', (error) => {
            statusEl.innerHTML = '<span class="badge bg-danger"><i class="fas fa-times"></i> Connection Failed</span>';
            console.error('WebSocket connection error:', error);
        });
        
        setTimeout(() => {
            if (socket.connected) {
                socket.disconnect();
            } else {
                statusEl.innerHTML = '<span class="badge bg-warning"><i class="fas fa-exclamation-triangle"></i> Timeout</span>';
            }
        }, 5000);
        
    } catch (error) {
        statusEl.innerHTML = '<span class="badge bg-danger"><i class="fas fa-times"></i> Error</span>';
        console.error('WebSocket test error:', error);
    }
}
</script>

<style>
.performance-features-settings .card {
    border: none;
    box-shadow: 0 0 10px rgba(0,0,0,0.1);
}

.performance-features-settings .card-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
}

.performance-features-settings .form-check-input:checked {
    background-color: #667eea;
    border-color: #667eea;
}

.performance-features-settings .btn-primary {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border: none;
}

.performance-features-settings .btn-primary:hover {
    background: linear-gradient(135deg, #764ba2 0%, #667eea 100%);
}

#rollout-display {
    padding: 10px;
    background: #f8f9fa;
    border-radius: 5px;
}
</style>

