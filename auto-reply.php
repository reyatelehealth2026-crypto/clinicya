<?php
/**
 * Auto-Reply - ตอบข้อความอัตโนมัติตามคำสำคัญ (Upgraded)
 * รองรับ: Sender, Quick Reply, Alt Text, Tags และอื่นๆ
 */
require_once 'config/config.php';
require_once 'config/database.php';

$db = Database::getInstance()->getConnection();
$pageTitle = 'Auto-Reply';

// Get current bot ID from session or default to null
$currentBotId = $_SESSION['current_bot_id'] ?? $_SESSION['line_account_id'] ?? null;

// Check if new columns exist
$hasNewColumns = false;
$hasShareColumn = false;
try {
    $stmt = $db->query("SHOW COLUMNS FROM auto_replies LIKE 'sender_name'");
    $hasNewColumns = $stmt->rowCount() > 0;
    
    $stmt = $db->query("SHOW COLUMNS FROM auto_replies LIKE 'enable_share'");
    $hasShareColumn = $stmt->rowCount() > 0;
    
    // Also check quick_reply column
    $stmt = $db->query("SHOW COLUMNS FROM auto_replies LIKE 'quick_reply'");
    $hasQuickReply = $stmt->rowCount() > 0;
    
    // Force enable if quick_reply exists
    if ($hasQuickReply && !$hasNewColumns) {
        $hasNewColumns = true;
    }
} catch (Exception $e) {
    // Table might not exist yet
}

// Handle actions
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create' || $action === 'update') {
        $keyword = trim($_POST['keyword'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $matchType = $_POST['match_type'] ?? 'contains';
        $replyType = $_POST['reply_type'] ?? 'text';
        $replyContent = trim($_POST['reply_content'] ?? '');
        $altText = trim($_POST['alt_text'] ?? '');
        $senderName = trim($_POST['sender_name'] ?? '');
        $senderIcon = trim($_POST['sender_icon'] ?? '');
        $quickReply = $_POST['quick_reply'] ?? '';
        $tags = trim($_POST['tags'] ?? '');
        $priority = (int)($_POST['priority'] ?? 0);
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $enableShare = isset($_POST['enable_share']) ? 1 : 0;
        $shareButtonLabel = trim($_POST['share_button_label'] ?? '📤 แชร์ให้เพื่อน');
        
        // Validate JSON for flex and quick_reply
        if ($replyType === 'flex' && !empty($replyContent)) {
            $decoded = json_decode($replyContent);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $error = 'Flex Message JSON ไม่ถูกต้อง: ' . json_last_error_msg();
            }
        }
        
        if (!empty($quickReply)) {
            $decoded = json_decode($quickReply);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $error = 'Quick Reply JSON ไม่ถูกต้อง: ' . json_last_error_msg();
            }
        }

        if (!$error) {
            try {
                if ($hasNewColumns) {
                    if ($action === 'create') {
                        if ($hasShareColumn) {
                            $stmt = $db->prepare("INSERT INTO auto_replies 
                                (keyword, description, match_type, reply_type, reply_content, alt_text, sender_name, sender_icon, quick_reply, enable_share, share_button_label, tags, priority, is_active, line_account_id) 
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                            $stmt->execute([
                                $keyword, $description, $matchType, $replyType, $replyContent,
                                $altText ?: null, $senderName ?: null, $senderIcon ?: null, 
                                $quickReply ?: null, $enableShare, $shareButtonLabel,
                                $tags ?: null, $priority, $isActive, $currentBotId
                            ]);
                        } else {
                            $stmt = $db->prepare("INSERT INTO auto_replies 
                                (keyword, description, match_type, reply_type, reply_content, alt_text, sender_name, sender_icon, quick_reply, tags, priority, is_active, line_account_id) 
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                            $stmt->execute([
                                $keyword, $description, $matchType, $replyType, $replyContent,
                                $altText ?: null, $senderName ?: null, $senderIcon ?: null, 
                                $quickReply ?: null, $tags ?: null, $priority, $isActive, $currentBotId
                            ]);
                        }
                        $message = 'เพิ่มกฎใหม่เรียบร้อย';
                    } else {
                        if ($hasShareColumn) {
                            $stmt = $db->prepare("UPDATE auto_replies SET 
                                keyword=?, description=?, match_type=?, reply_type=?, reply_content=?, 
                                alt_text=?, sender_name=?, sender_icon=?, quick_reply=?, enable_share=?, share_button_label=?, tags=?, priority=?, is_active=?
                                WHERE id=?");
                            $stmt->execute([
                                $keyword, $description, $matchType, $replyType, $replyContent,
                                $altText ?: null, $senderName ?: null, $senderIcon ?: null,
                                $quickReply ?: null, $enableShare, $shareButtonLabel,
                                $tags ?: null, $priority, $isActive,
                                $_POST['id']
                            ]);
                        } else {
                            $stmt = $db->prepare("UPDATE auto_replies SET 
                                keyword=?, description=?, match_type=?, reply_type=?, reply_content=?, 
                                alt_text=?, sender_name=?, sender_icon=?, quick_reply=?, tags=?, priority=?, is_active=?
                                WHERE id=?");
                            $stmt->execute([
                                $keyword, $description, $matchType, $replyType, $replyContent,
                                $altText ?: null, $senderName ?: null, $senderIcon ?: null,
                                $quickReply ?: null, $tags ?: null, $priority, $isActive,
                                $_POST['id']
                            ]);
                        }
                        $message = 'อัพเดทกฎเรียบร้อย';
                    }
                } else {
                    // Fallback for old schema
                    if ($action === 'create') {
                        $stmt = $db->prepare("INSERT INTO auto_replies (keyword, match_type, reply_type, reply_content, is_active, priority, line_account_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$keyword, $matchType, $replyType, $replyContent, $isActive, $priority, $currentBotId]);
                    } else {
                        $stmt = $db->prepare("UPDATE auto_replies SET keyword=?, match_type=?, reply_type=?, reply_content=?, is_active=?, priority=? WHERE id=?");
                        $stmt->execute([$keyword, $matchType, $replyType, $replyContent, $isActive, $priority, $_POST['id']]);
                    }
                    $message = $action === 'create' ? 'เพิ่มกฎใหม่เรียบร้อย' : 'อัพเดทกฎเรียบร้อย';
                }
            } catch (Exception $e) {
                $error = 'เกิดข้อผิดพลาด: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'delete') {
        $stmt = $db->prepare("DELETE FROM auto_replies WHERE id = ?");
        $stmt->execute([$_POST['id']]);
        $message = 'ลบกฎเรียบร้อย';
    } elseif ($action === 'toggle') {
        $stmt = $db->prepare("UPDATE auto_replies SET is_active = NOT is_active WHERE id = ?");
        $stmt->execute([$_POST['id']]);
    } elseif ($action === 'duplicate') {
        $stmt = $db->prepare("SELECT * FROM auto_replies WHERE id = ?");
        $stmt->execute([$_POST['id']]);
        $rule = $stmt->fetch();
        if ($rule) {
            if ($hasNewColumns) {
                $stmt = $db->prepare("INSERT INTO auto_replies (keyword, description, match_type, reply_type, reply_content, alt_text, sender_name, sender_icon, quick_reply, tags, priority, is_active, line_account_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)");
                $stmt->execute([
                    $rule['keyword'] . ' (copy)', $rule['description'] ?? '', $rule['match_type'], $rule['reply_type'], $rule['reply_content'],
                    $rule['alt_text'] ?? null, $rule['sender_name'] ?? null, $rule['sender_icon'] ?? null,
                    $rule['quick_reply'] ?? null, $rule['tags'] ?? null, $rule['priority'], $currentBotId
                ]);
            } else {
                $stmt = $db->prepare("INSERT INTO auto_replies (keyword, match_type, reply_type, reply_content, is_active, priority, line_account_id) VALUES (?, ?, ?, ?, 0, ?, ?)");
                $stmt->execute([$rule['keyword'] . ' (copy)', $rule['match_type'], $rule['reply_type'], $rule['reply_content'], $rule['priority'], $currentBotId]);
            }
            $message = 'คัดลอกกฎเรียบร้อย';
        }
    }
    
    if (!$error && $action !== 'toggle') {
        header('Location: auto-reply.php' . ($message ? '?msg=' . urlencode($message) : ''));
        exit;
    }
}

// Include header AFTER all redirects
require_once 'includes/header.php';

// Get message from redirect
if (isset($_GET['msg'])) {
    $message = $_GET['msg'];
}

// Get filter
$filterTag = $_GET['tag'] ?? '';
$filterStatus = $_GET['status'] ?? '';
$search = $_GET['search'] ?? '';

// Build query
$whereConditions = ["(line_account_id = ? OR line_account_id IS NULL)"];
$params = [$currentBotId ?? null];

if ($search) {
    $whereConditions[] = "(keyword LIKE ? OR reply_content LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}
if ($filterStatus === 'active') {
    $whereConditions[] = "is_active = 1";
} elseif ($filterStatus === 'inactive') {
    $whereConditions[] = "is_active = 0";
}
if ($filterTag && $hasNewColumns) {
    $whereConditions[] = "tags LIKE ?";
    $params[] = "%{$filterTag}%";
}

$whereClause = implode(' AND ', $whereConditions);
$stmt = $db->prepare("SELECT * FROM auto_replies WHERE {$whereClause} ORDER BY priority DESC, created_at DESC");
$stmt->execute($params);
$rules = $stmt->fetchAll();

// Get all tags for filter
$allTags = [];
if ($hasNewColumns) {
    $stmt = $db->prepare("SELECT DISTINCT tags FROM auto_replies WHERE (line_account_id = ? OR line_account_id IS NULL) AND tags IS NOT NULL AND tags != ''");
    $stmt->execute([$currentBotId]);
    while ($row = $stmt->fetch()) {
        foreach (explode(',', $row['tags']) as $tag) {
            $tag = trim($tag);
            if ($tag) $allTags[$tag] = true;
        }
    }
    $allTags = array_keys($allTags);
    sort($allTags);
}

// Stats
$totalRules = count($rules);
$activeRules = count(array_filter($rules, fn($r) => $r['is_active']));
?>

<?php if ($message): ?>
<div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4 flex justify-between items-center">
    <span><?= htmlspecialchars($message) ?></span>
    <button onclick="this.parentElement.remove()" class="text-green-700 hover:text-green-900">&times;</button>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4 flex justify-between items-center">
    <span><?= htmlspecialchars($error) ?></span>
    <button onclick="this.parentElement.remove()" class="text-red-700 hover:text-red-900">&times;</button>
</div>
<?php endif; ?>

<!-- Stats & Actions -->
<div class="flex flex-wrap gap-4 mb-6">
    <div class="flex-1 min-w-[200px] bg-white rounded-lg shadow p-4">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm text-gray-500">กฎทั้งหมด</p>
                <p class="text-2xl font-bold text-gray-800"><?= $totalRules ?></p>
            </div>
            <div class="p-3 bg-blue-100 rounded-full">
                <i class="fas fa-reply-all text-blue-600"></i>
            </div>
        </div>
    </div>
    <div class="flex-1 min-w-[200px] bg-white rounded-lg shadow p-4">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm text-gray-500">กฎที่ใช้งาน</p>
                <p class="text-2xl font-bold text-green-600"><?= $activeRules ?></p>
            </div>
            <div class="p-3 bg-green-100 rounded-full">
                <i class="fas fa-check-circle text-green-600"></i>
            </div>
        </div>
    </div>
    <div class="flex items-center gap-2">
        <button onclick="openModal()" class="px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600 h-fit">
            <i class="fas fa-plus mr-2"></i>เพิ่มกฎใหม่
        </button>
        <button onclick="openTemplateModal()" class="px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 h-fit">
            <i class="fas fa-magic mr-2"></i>เทมเพลต
        </button>
    </div>
</div>

<!-- Filters -->
<div class="bg-white rounded-lg shadow p-4 mb-6">
    <form method="GET" class="flex flex-wrap gap-4 items-end">
        <div class="flex-1 min-w-[200px]">
            <label class="block text-sm font-medium text-gray-700 mb-1">ค้นหา</label>
            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="ค้นหา keyword หรือ reply..." 
                   class="w-full border rounded-lg px-3 py-2 focus:ring-2 focus:ring-green-500">
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">สถานะ</label>
            <select name="status" class="border rounded-lg px-3 py-2 focus:ring-2 focus:ring-green-500">
                <option value="">ทั้งหมด</option>
                <option value="active" <?= $filterStatus === 'active' ? 'selected' : '' ?>>Active</option>
                <option value="inactive" <?= $filterStatus === 'inactive' ? 'selected' : '' ?>>Inactive</option>
            </select>
        </div>
        <?php if (!empty($allTags)): ?>
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">แท็ก</label>
            <select name="tag" class="border rounded-lg px-3 py-2 focus:ring-2 focus:ring-green-500">
                <option value="">ทั้งหมด</option>
                <?php foreach ($allTags as $tag): ?>
                <option value="<?= htmlspecialchars($tag) ?>" <?= $filterTag === $tag ? 'selected' : '' ?>><?= htmlspecialchars($tag) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <button type="submit" class="px-4 py-2 bg-gray-500 text-white rounded-lg hover:bg-gray-600">
            <i class="fas fa-search mr-1"></i> ค้นหา
        </button>
        <?php if ($search || $filterStatus || $filterTag): ?>
        <a href="auto-reply.php" class="px-4 py-2 border rounded-lg hover:bg-gray-50">ล้างตัวกรอง</a>
        <?php endif; ?>
    </form>
</div>

<!-- Rules Table -->
<div class="bg-white rounded-xl shadow overflow-hidden">
    <table class="w-full">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Keyword</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Match</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Reply</th>
                <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Features</th>
                <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Priority</th>
                <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Status</th>
                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
            </tr>
        </thead>
        <tbody class="divide-y">
            <?php foreach ($rules as $rule): ?>
            <tr class="hover:bg-gray-50">
                <td class="px-4 py-3">
                    <div class="font-medium text-gray-900"><?= htmlspecialchars($rule['keyword']) ?></div>
                    <?php if ($hasNewColumns && !empty($rule['description'])): ?>
                    <div class="text-xs text-gray-500"><?= htmlspecialchars($rule['description']) ?></div>
                    <?php endif; ?>
                    <?php if ($hasNewColumns && !empty($rule['tags'])): ?>
                    <div class="flex flex-wrap gap-1 mt-1">
                        <?php foreach (explode(',', $rule['tags']) as $tag): ?>
                        <span class="px-1.5 py-0.5 text-xs bg-gray-100 text-gray-600 rounded"><?= htmlspecialchars(trim($tag)) ?></span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </td>
                <td class="px-4 py-3">
                    <span class="px-2 py-1 text-xs rounded 
                        <?php
                        echo match($rule['match_type']) {
                            'exact' => 'bg-purple-100 text-purple-600',
                            'starts_with' => 'bg-blue-100 text-blue-600',
                            'regex' => 'bg-orange-100 text-orange-600',
                            'all' => 'bg-red-100 text-red-600',
                            default => 'bg-green-100 text-green-600'
                        };
                        ?>">
                        <?= $rule['match_type'] ?>
                    </span>
                </td>
                <td class="px-4 py-3 max-w-xs">
                    <div class="flex items-center gap-2">
                        <span class="px-2 py-0.5 text-xs rounded <?= $rule['reply_type'] === 'flex' ? 'bg-indigo-100 text-indigo-600' : 'bg-gray-100 text-gray-600' ?>">
                            <?= $rule['reply_type'] ?>
                        </span>
                        <span class="truncate text-sm text-gray-600" title="<?= htmlspecialchars($rule['reply_content']) ?>">
                            <?= htmlspecialchars(mb_substr($rule['reply_content'], 0, 40)) ?><?= mb_strlen($rule['reply_content']) > 40 ? '...' : '' ?>
                        </span>
                    </div>
                </td>
                <td class="px-4 py-3 text-center">
                    <div class="flex justify-center gap-1">
                        <?php if ($hasNewColumns): ?>
                            <?php if (!empty($rule['sender_name'])): ?>
                            <span class="px-1.5 py-0.5 text-xs bg-yellow-100 text-yellow-700 rounded" title="Sender: <?= htmlspecialchars($rule['sender_name']) ?>">👤</span>
                            <?php endif; ?>
                            <?php if (!empty($rule['quick_reply'])): ?>
                            <span class="px-1.5 py-0.5 text-xs bg-blue-100 text-blue-700 rounded" title="Quick Reply">⚡</span>
                            <?php endif; ?>
                            <?php if (!empty($rule['alt_text'])): ?>
                            <span class="px-1.5 py-0.5 text-xs bg-gray-100 text-gray-700 rounded" title="Alt Text">📝</span>
                            <?php endif; ?>
                            <?php if (empty($rule['sender_name']) && empty($rule['quick_reply']) && empty($rule['alt_text'])): ?>
                            <span class="text-gray-400">-</span>
                            <?php endif; ?>
                        <?php else: ?>
                        <span class="text-gray-400">-</span>
                        <?php endif; ?>
                    </div>
                </td>
                <td class="px-4 py-3 text-center">
                    <span class="font-medium"><?= $rule['priority'] ?></span>
                </td>
                <td class="px-4 py-3 text-center">
                    <form method="POST" class="inline">
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="id" value="<?= $rule['id'] ?>">
                        <button type="submit" class="px-2 py-1 text-xs rounded transition <?= $rule['is_active'] ? 'bg-green-100 text-green-600 hover:bg-green-200' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' ?>">
                            <?= $rule['is_active'] ? '✓ Active' : 'Inactive' ?>
                        </button>
                    </form>
                </td>
                <td class="px-4 py-3 text-right">
                    <div class="flex justify-end gap-1">
                        <button onclick="previewRule(<?= $rule['id'] ?>)" class="p-1.5 text-gray-500 hover:text-gray-700 hover:bg-gray-100 rounded" title="Preview">
                            <i class="fas fa-eye"></i>
                        </button>
                        <button onclick='editRule(<?= json_encode($rule, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)' class="p-1.5 text-blue-500 hover:text-blue-700 hover:bg-blue-50 rounded" title="Edit">
                            <i class="fas fa-edit"></i>
                        </button>
                        <form method="POST" class="inline">
                            <input type="hidden" name="action" value="duplicate">
                            <input type="hidden" name="id" value="<?= $rule['id'] ?>">
                            <button type="submit" class="p-1.5 text-purple-500 hover:text-purple-700 hover:bg-purple-50 rounded" title="Duplicate">
                                <i class="fas fa-copy"></i>
                            </button>
                        </form>
                        <form method="POST" class="inline" onsubmit="return confirm('ต้องการลบกฎนี้?')">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $rule['id'] ?>">
                            <button type="submit" class="p-1.5 text-red-500 hover:text-red-700 hover:bg-red-50 rounded" title="Delete">
                                <i class="fas fa-trash"></i>
                            </button>
                        </form>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($rules)): ?>
            <tr>
                <td colspan="7" class="px-6 py-12 text-center">
                    <div class="text-gray-400 text-4xl mb-2">📭</div>
                    <p class="text-gray-500">ยังไม่มีกฎการตอบกลับ</p>
                    <button onclick="openModal()" class="mt-4 px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600">
                        <i class="fas fa-plus mr-2"></i>เพิ่มกฎแรก
                    </button>
                </td>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Main Modal -->
<div id="modal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50 overflow-y-auto">
    <div class="bg-white rounded-xl w-full max-w-3xl mx-4 my-8">
        <form method="POST" id="ruleForm">
            <input type="hidden" name="action" id="formAction" value="create">
            <input type="hidden" name="id" id="formId">
            
            <div class="p-4 border-b flex justify-between items-center bg-gray-50 rounded-t-xl">
                <h3 class="text-lg font-semibold" id="modalTitle">เพิ่มกฎใหม่</h3>
                <button type="button" onclick="closeModal()" class="text-gray-500 hover:text-gray-700">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <div class="p-6 space-y-6 max-h-[70vh] overflow-y-auto">
                <!-- Basic Info -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium mb-1">Keyword <span class="text-red-500">*</span></label>
                        <input type="text" name="keyword" id="keyword" required 
                               class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-green-500"
                               placeholder="คำที่ต้องการให้ตอบกลับ">
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">Match Type</label>
                        <select name="match_type" id="match_type" class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-green-500">
                            <option value="contains">Contains (มีคำนี้)</option>
                            <option value="exact">Exact (ตรงทั้งหมด)</option>
                            <option value="starts_with">Starts With (ขึ้นต้นด้วย)</option>
                            <option value="regex">Regex (Regular Expression)</option>
                            <option value="all">All (ตอบทุกข้อความ)</option>
                        </select>
                    </div>
                </div>
                
                <?php if ($hasNewColumns): ?>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium mb-1">คำอธิบาย</label>
                        <input type="text" name="description" id="description" 
                               class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-green-500"
                               placeholder="อธิบายกฎนี้ (สำหรับจดจำ)">
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">แท็ก</label>
                        <input type="text" name="tags" id="tags" 
                               class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-green-500"
                               placeholder="tag1, tag2, tag3">
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Reply Settings -->
                <div class="border-t pt-4">
                    <h4 class="font-medium mb-3">📝 การตอบกลับ</h4>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                        <div>
                            <label class="block text-sm font-medium mb-1">Reply Type</label>
                            <select name="reply_type" id="reply_type" onchange="toggleReplyType()" class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-green-500">
                                <option value="text">Text (ข้อความธรรมดา)</option>
                                <option value="flex">Flex Message (JSON)</option>
                            </select>
                        </div>
                        <?php if ($hasNewColumns): ?>
                        <div id="altTextWrapper">
                            <label class="block text-sm font-medium mb-1">Alt Text <span class="text-xs text-gray-500">(สำหรับ Flex)</span></label>
                            <input type="text" name="alt_text" id="alt_text" 
                                   class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-green-500"
                                   placeholder="ข้อความแจ้งเตือน (แสดงใน notification)">
                        </div>
                        <?php endif; ?>
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">Reply Content <span class="text-red-500">*</span></label>
                        <textarea name="reply_content" id="reply_content" rows="5" required 
                                  class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-green-500 font-mono text-sm"
                                  placeholder="ข้อความตอบกลับ หรือ Flex JSON"></textarea>
                        <div id="flexHelp" class="hidden mt-2 text-xs text-gray-500">
                            💡 ใส่ JSON ของ Flex Message (bubble หรือ carousel) - <a href="flex-builder.php" target="_blank" class="text-blue-500 hover:underline">ใช้ Flex Builder</a>
                        </div>
                    </div>
                </div>

                <?php if ($hasNewColumns): ?>
                <!-- Sender Settings -->
                <div class="border-t pt-4">
                    <h4 class="font-medium mb-3">👤 Sender (ผู้ส่ง)</h4>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium mb-1">ชื่อผู้ส่ง</label>
                            <input type="text" name="sender_name" id="sender_name" 
                                   class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-green-500"
                                   placeholder="เช่น Shop Bot, Support">
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1">Icon URL</label>
                            <input type="url" name="sender_icon" id="sender_icon" 
                                   class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-green-500"
                                   placeholder="https://example.com/icon.png">
                        </div>
                    </div>
                    <p class="text-xs text-gray-500 mt-2">💡 Sender จะแสดงเป็นชื่อและรูปของผู้ส่งข้อความ (ไม่ใช่ชื่อบอท)</p>
                </div>
                
                <!-- Quick Reply Settings -->
                <div class="border-t pt-4">
                    <h4 class="font-medium mb-3">⚡ Quick Reply <span class="text-xs text-gray-500 font-normal">(สูงสุด 13 ปุ่ม)</span></h4>
                    <div id="quickReplyBuilder">
                        <div id="quickReplyItems" class="space-y-3 mb-3"></div>
                        <div class="flex flex-wrap gap-2">
                            <button type="button" onclick="addQuickReplyItem('message')" class="px-3 py-1.5 text-sm bg-green-50 text-green-600 rounded-lg hover:bg-green-100">
                                <i class="fas fa-comment mr-1"></i> Message
                            </button>
                            <button type="button" onclick="addQuickReplyItem('uri')" class="px-3 py-1.5 text-sm bg-blue-50 text-blue-600 rounded-lg hover:bg-blue-100">
                                <i class="fas fa-link mr-1"></i> URL
                            </button>
                            <button type="button" onclick="addQuickReplyItem('postback')" class="px-3 py-1.5 text-sm bg-purple-50 text-purple-600 rounded-lg hover:bg-purple-100">
                                <i class="fas fa-reply mr-1"></i> Postback
                            </button>
                            <button type="button" onclick="addQuickReplyItem('datetimepicker')" class="px-3 py-1.5 text-sm bg-orange-50 text-orange-600 rounded-lg hover:bg-orange-100">
                                <i class="fas fa-calendar mr-1"></i> DateTime
                            </button>
                            <button type="button" onclick="addQuickReplyItem('camera')" class="px-3 py-1.5 text-sm bg-pink-50 text-pink-600 rounded-lg hover:bg-pink-100">
                                <i class="fas fa-camera mr-1"></i> Camera
                            </button>
                            <button type="button" onclick="addQuickReplyItem('cameraRoll')" class="px-3 py-1.5 text-sm bg-indigo-50 text-indigo-600 rounded-lg hover:bg-indigo-100">
                                <i class="fas fa-images mr-1"></i> Gallery
                            </button>
                            <button type="button" onclick="addQuickReplyItem('location')" class="px-3 py-1.5 text-sm bg-red-50 text-red-600 rounded-lg hover:bg-red-100">
                                <i class="fas fa-map-marker-alt mr-1"></i> Location
                            </button>
                            <button type="button" onclick="addQuickReplyItem('share')" class="px-3 py-1.5 text-sm bg-cyan-50 text-cyan-600 rounded-lg hover:bg-cyan-100">
                                <i class="fas fa-share-alt mr-1"></i> Share
                            </button>
                        </div>
                    </div>
                    <input type="hidden" name="quick_reply" id="quick_reply">
                    <div class="mt-3 p-3 bg-gray-50 rounded-lg text-xs text-gray-600">
                        <p class="font-medium mb-1">💡 ประเภท Action:</p>
                        <ul class="space-y-1 ml-4">
                            <li><b>Message</b> - ส่งข้อความเมื่อกด</li>
                            <li><b>URL</b> - เปิดลิงก์เมื่อกด</li>
                            <li><b>Postback</b> - ส่ง data กลับมา (สำหรับ developer)</li>
                            <li><b>DateTime</b> - เลือกวันที่/เวลา</li>
                            <li><b>Camera/Gallery/Location</b> - เปิดกล้อง/แกลเลอรี่/แผนที่</li>
                            <li><b>Share</b> - แชร์ข้อความให้เพื่อน</li>
                        </ul>
                    </div>
                </div>
                
                <!-- Share Flex Settings -->
                <div class="border-t pt-4" id="shareFlexSection">
                    <h4 class="font-medium mb-3">📤 แชร์ Flex Message <span class="text-xs text-gray-500 font-normal">(ให้ผู้ใช้แชร์ Flex ที่บอทตอบกลับ)</span></h4>
                    <div class="flex flex-wrap gap-4 items-center">
                        <label class="flex items-center cursor-pointer">
                            <input type="checkbox" name="enable_share" id="enable_share" class="mr-2 w-4 h-4 text-green-500">
                            <span class="text-sm">เพิ่มปุ่มแชร์ใน Flex Message</span>
                        </label>
                        <div class="flex-1 min-w-[200px]">
                            <input type="text" name="share_button_label" id="share_button_label" 
                                   value="📤 แชร์ให้เพื่อน"
                                   class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-green-500 text-sm"
                                   placeholder="ข้อความบนปุ่มแชร์">
                        </div>
                    </div>
                    <p class="text-xs text-gray-500 mt-2">💡 ปุ่มแชร์จะถูกเพิ่มใน footer ของ Flex Message (ต้องตั้งค่า LIFF_SHARE_ID ใน config.php)</p>
                </div>
                <?php endif; ?>
                
                <!-- Priority & Status -->
                <div class="border-t pt-4">
                    <div class="flex flex-wrap gap-4 items-center">
                        <div class="w-32">
                            <label class="block text-sm font-medium mb-1">Priority</label>
                            <input type="number" name="priority" id="priority" value="0" 
                                   class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-green-500">
                        </div>
                        <div class="flex items-center pt-5">
                            <label class="flex items-center cursor-pointer">
                                <input type="checkbox" name="is_active" id="is_active" checked class="mr-2 w-4 h-4 text-green-500">
                                <span class="text-sm">เปิดใช้งาน</span>
                            </label>
                        </div>
                        <p class="text-xs text-gray-500 pt-5">💡 Priority สูงกว่าจะถูกตรวจสอบก่อน</p>
                    </div>
                </div>
            </div>
            
            <div class="p-4 border-t flex justify-between items-center bg-gray-50 rounded-b-xl">
                <button type="button" onclick="previewMessage()" class="px-4 py-2 text-blue-600 hover:bg-blue-50 rounded-lg">
                    <i class="fas fa-eye mr-1"></i> Preview
                </button>
                <div class="flex gap-2">
                    <button type="button" onclick="closeModal()" class="px-4 py-2 border rounded-lg hover:bg-gray-100">ยกเลิก</button>
                    <button type="submit" class="px-6 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600">
                        <i class="fas fa-save mr-1"></i> บันทึก
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Template Modal -->
<div id="templateModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
    <div class="bg-white rounded-xl w-full max-w-2xl mx-4">
        <div class="p-4 border-b flex justify-between items-center">
            <h3 class="text-lg font-semibold">🎨 เทมเพลตสำเร็จรูป</h3>
            <button onclick="closeTemplateModal()" class="text-gray-500 hover:text-gray-700"><i class="fas fa-times"></i></button>
        </div>
        <div class="p-6 max-h-[60vh] overflow-y-auto">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div onclick="useTemplate('greeting')" class="p-4 border rounded-lg cursor-pointer hover:border-green-500 hover:bg-green-50 transition">
                    <div class="text-2xl mb-2">👋</div>
                    <h4 class="font-medium">ทักทาย</h4>
                    <p class="text-sm text-gray-500">ตอบกลับเมื่อมีคนทักทาย</p>
                </div>
                <div onclick="useTemplate('thanks')" class="p-4 border rounded-lg cursor-pointer hover:border-green-500 hover:bg-green-50 transition">
                    <div class="text-2xl mb-2">🙏</div>
                    <h4 class="font-medium">ขอบคุณ</h4>
                    <p class="text-sm text-gray-500">ตอบกลับเมื่อมีคนขอบคุณ</p>
                </div>
                <div onclick="useTemplate('price')" class="p-4 border rounded-lg cursor-pointer hover:border-green-500 hover:bg-green-50 transition">
                    <div class="text-2xl mb-2">💰</div>
                    <h4 class="font-medium">ถามราคา</h4>
                    <p class="text-sm text-gray-500">ตอบกลับเมื่อถามราคา</p>
                </div>
                <div onclick="useTemplate('shipping')" class="p-4 border rounded-lg cursor-pointer hover:border-green-500 hover:bg-green-50 transition">
                    <div class="text-2xl mb-2">🚚</div>
                    <h4 class="font-medium">การจัดส่ง</h4>
                    <p class="text-sm text-gray-500">ตอบกลับเมื่อถามเรื่องจัดส่ง</p>
                </div>
                <div onclick="useTemplate('payment')" class="p-4 border rounded-lg cursor-pointer hover:border-green-500 hover:bg-green-50 transition">
                    <div class="text-2xl mb-2">💳</div>
                    <h4 class="font-medium">การชำระเงิน</h4>
                    <p class="text-sm text-gray-500">ตอบกลับเมื่อถามเรื่องชำระเงิน</p>
                </div>
                <div onclick="useTemplate('contact')" class="p-4 border rounded-lg cursor-pointer hover:border-green-500 hover:bg-green-50 transition">
                    <div class="text-2xl mb-2">📞</div>
                    <h4 class="font-medium">ติดต่อเรา</h4>
                    <p class="text-sm text-gray-500">ตอบกลับเมื่อต้องการติดต่อ</p>
                </div>
                <div onclick="useTemplate('hours')" class="p-4 border rounded-lg cursor-pointer hover:border-green-500 hover:bg-green-50 transition">
                    <div class="text-2xl mb-2">🕐</div>
                    <h4 class="font-medium">เวลาทำการ</h4>
                    <p class="text-sm text-gray-500">ตอบกลับเมื่อถามเวลาทำการ</p>
                </div>
                <div onclick="useTemplate('promo')" class="p-4 border rounded-lg cursor-pointer hover:border-green-500 hover:bg-green-50 transition">
                    <div class="text-2xl mb-2">🎁</div>
                    <h4 class="font-medium">โปรโมชั่น</h4>
                    <p class="text-sm text-gray-500">ตอบกลับเมื่อถามโปรโมชั่น</p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Preview Modal -->
<div id="previewModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
    <div class="bg-white rounded-xl w-full max-w-md mx-4">
        <div class="p-4 border-b flex justify-between items-center">
            <h3 class="text-lg font-semibold">👁️ Preview</h3>
            <button onclick="closePreviewModal()" class="text-gray-500 hover:text-gray-700"><i class="fas fa-times"></i></button>
        </div>
        <div class="p-6">
            <div id="previewContent" class="bg-gray-100 rounded-lg p-4 min-h-[200px]">
                <!-- Preview will be rendered here -->
            </div>
        </div>
    </div>
</div>

<script>
// Quick Reply Items
let quickReplyItems = [];

function openModal() {
    document.getElementById('modal').classList.remove('hidden');
    document.getElementById('modal').classList.add('flex');
    document.getElementById('formAction').value = 'create';
    document.getElementById('modalTitle').textContent = 'เพิ่มกฎใหม่';
    document.getElementById('ruleForm').reset();
    document.getElementById('is_active').checked = true;
    quickReplyItems = [];
    renderQuickReplyItems();
    toggleReplyType();
}

function closeModal() {
    document.getElementById('modal').classList.add('hidden');
    document.getElementById('modal').classList.remove('flex');
}

function editRule(rule) {
    openModal();
    document.getElementById('formAction').value = 'update';
    document.getElementById('formId').value = rule.id;
    document.getElementById('modalTitle').textContent = 'แก้ไขกฎ';
    document.getElementById('keyword').value = rule.keyword || '';
    document.getElementById('match_type').value = rule.match_type || 'contains';
    document.getElementById('reply_type').value = rule.reply_type || 'text';
    document.getElementById('reply_content').value = rule.reply_content || '';
    document.getElementById('priority').value = rule.priority || 0;
    document.getElementById('is_active').checked = rule.is_active == 1;
    
    // New fields
    if (document.getElementById('description')) {
        document.getElementById('description').value = rule.description || '';
    }
    if (document.getElementById('tags')) {
        document.getElementById('tags').value = rule.tags || '';
    }
    if (document.getElementById('alt_text')) {
        document.getElementById('alt_text').value = rule.alt_text || '';
    }
    if (document.getElementById('sender_name')) {
        document.getElementById('sender_name').value = rule.sender_name || '';
    }
    if (document.getElementById('sender_icon')) {
        document.getElementById('sender_icon').value = rule.sender_icon || '';
    }
    
    // Quick Reply
    if (rule.quick_reply) {
        try {
            quickReplyItems = JSON.parse(rule.quick_reply);
        } catch (e) {
            quickReplyItems = [];
        }
    } else {
        quickReplyItems = [];
    }
    renderQuickReplyItems();
    
    // Share Flex settings
    if (document.getElementById('enable_share')) {
        document.getElementById('enable_share').checked = rule.enable_share == 1;
    }
    if (document.getElementById('share_button_label')) {
        document.getElementById('share_button_label').value = rule.share_button_label || '📤 แชร์ให้เพื่อน';
    }
    
    toggleReplyType();
}

function toggleReplyType() {
    const replyType = document.getElementById('reply_type').value;
    const flexHelp = document.getElementById('flexHelp');
    const altTextWrapper = document.getElementById('altTextWrapper');
    
    if (flexHelp) {
        flexHelp.classList.toggle('hidden', replyType !== 'flex');
    }
    if (altTextWrapper) {
        altTextWrapper.style.display = replyType === 'flex' ? 'block' : 'none';
    }
}

// Quick Reply Builder - Full Featured
function addQuickReplyItem(type = 'message') {
    if (quickReplyItems.length >= 13) {
        alert('Quick Reply สูงสุด 13 ปุ่ม');
        return;
    }
    
    const defaultItem = {
        type: type,
        label: '',
        imageUrl: '',
        // For message
        text: '',
        // For uri
        uri: '',
        // For postback
        data: '',
        displayText: '',
        // For datetimepicker
        mode: 'datetime',
        initial: '',
        max: '',
        min: '',
        // For share
        shareText: ''
    };
    
    quickReplyItems.push(defaultItem);
    renderQuickReplyItems();
}

function removeQuickReplyItem(index) {
    quickReplyItems.splice(index, 1);
    renderQuickReplyItems();
}

function updateQuickReplyItem(index, field, value) {
    quickReplyItems[index][field] = value;
    
    // Re-render if type changed
    if (field === 'type') {
        renderQuickReplyItems();
    } else {
        updateQuickReplyHidden();
    }
}

function moveQuickReplyItem(index, direction) {
    const newIndex = index + direction;
    if (newIndex < 0 || newIndex >= quickReplyItems.length) return;
    
    const temp = quickReplyItems[index];
    quickReplyItems[index] = quickReplyItems[newIndex];
    quickReplyItems[newIndex] = temp;
    renderQuickReplyItems();
}

function renderQuickReplyItems() {
    const container = document.getElementById('quickReplyItems');
    if (!container) return;
    
    const typeConfig = {
        message: { icon: '💬', color: 'green', label: 'Message' },
        uri: { icon: '🔗', color: 'blue', label: 'URL' },
        postback: { icon: '📤', color: 'purple', label: 'Postback' },
        datetimepicker: { icon: '📅', color: 'orange', label: 'DateTime' },
        camera: { icon: '📷', color: 'pink', label: 'Camera' },
        cameraRoll: { icon: '🖼️', color: 'indigo', label: 'Gallery' },
        location: { icon: '📍', color: 'red', label: 'Location' },
        share: { icon: '📤', color: 'cyan', label: 'Share' }
    };
    
    container.innerHTML = quickReplyItems.map((item, index) => {
        const config = typeConfig[item.type] || typeConfig.message;
        
        let actionFields = '';
        
        switch (item.type) {
            case 'message':
                actionFields = `
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="text-xs text-gray-500">Text (ข้อความที่ส่ง)</label>
                            <input type="text" value="${escapeHtml(item.text || '')}" 
                                   onchange="updateQuickReplyItem(${index}, 'text', this.value)"
                                   placeholder="ข้อความที่จะส่งเมื่อกด" class="w-full px-2 py-1 border rounded text-sm">
                        </div>
                    </div>`;
                break;
                
            case 'uri':
                actionFields = `
                    <div>
                        <label class="text-xs text-gray-500">URL</label>
                        <input type="url" value="${escapeHtml(item.uri || '')}" 
                               onchange="updateQuickReplyItem(${index}, 'uri', this.value)"
                               placeholder="https://example.com" class="w-full px-2 py-1 border rounded text-sm">
                    </div>`;
                break;
                
            case 'postback':
                actionFields = `
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="text-xs text-gray-500">Data (ส่งกลับ server)</label>
                            <input type="text" value="${escapeHtml(item.data || '')}" 
                                   onchange="updateQuickReplyItem(${index}, 'data', this.value)"
                                   placeholder="action=buy&id=123" class="w-full px-2 py-1 border rounded text-sm">
                        </div>
                        <div>
                            <label class="text-xs text-gray-500">Display Text (แสดงในแชท)</label>
                            <input type="text" value="${escapeHtml(item.displayText || '')}" 
                                   onchange="updateQuickReplyItem(${index}, 'displayText', this.value)"
                                   placeholder="ข้อความที่แสดง" class="w-full px-2 py-1 border rounded text-sm">
                        </div>
                    </div>`;
                break;
                
            case 'datetimepicker':
                actionFields = `
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="text-xs text-gray-500">Data</label>
                            <input type="text" value="${escapeHtml(item.data || '')}" 
                                   onchange="updateQuickReplyItem(${index}, 'data', this.value)"
                                   placeholder="action=setdate" class="w-full px-2 py-1 border rounded text-sm">
                        </div>
                        <div>
                            <label class="text-xs text-gray-500">Mode</label>
                            <select onchange="updateQuickReplyItem(${index}, 'mode', this.value)" class="w-full px-2 py-1 border rounded text-sm">
                                <option value="datetime" ${item.mode === 'datetime' ? 'selected' : ''}>Date & Time</option>
                                <option value="date" ${item.mode === 'date' ? 'selected' : ''}>Date Only</option>
                                <option value="time" ${item.mode === 'time' ? 'selected' : ''}>Time Only</option>
                            </select>
                        </div>
                    </div>
                    <div class="grid grid-cols-3 gap-2 mt-2">
                        <div>
                            <label class="text-xs text-gray-500">Initial</label>
                            <input type="text" value="${escapeHtml(item.initial || '')}" 
                                   onchange="updateQuickReplyItem(${index}, 'initial', this.value)"
                                   placeholder="2024-01-01T10:00" class="w-full px-2 py-1 border rounded text-sm">
                        </div>
                        <div>
                            <label class="text-xs text-gray-500">Min</label>
                            <input type="text" value="${escapeHtml(item.min || '')}" 
                                   onchange="updateQuickReplyItem(${index}, 'min', this.value)"
                                   placeholder="2024-01-01" class="w-full px-2 py-1 border rounded text-sm">
                        </div>
                        <div>
                            <label class="text-xs text-gray-500">Max</label>
                            <input type="text" value="${escapeHtml(item.max || '')}" 
                                   onchange="updateQuickReplyItem(${index}, 'max', this.value)"
                                   placeholder="2024-12-31" class="w-full px-2 py-1 border rounded text-sm">
                        </div>
                    </div>`;
                break;
                
            case 'share':
                actionFields = `
                    <div>
                        <label class="text-xs text-gray-500">ข้อความที่จะแชร์</label>
                        <input type="text" value="${escapeHtml(item.shareText || '')}" 
                               onchange="updateQuickReplyItem(${index}, 'shareText', this.value)"
                               placeholder="มาช้อปที่ร้านเราสิ! 🛒" class="w-full px-2 py-1 border rounded text-sm">
                        <p class="text-xs text-gray-400 mt-1">💡 ผู้ใช้จะสามารถแชร์ข้อความนี้ให้เพื่อนได้</p>
                    </div>`;
                break;
                
            default:
                // camera, cameraRoll, location - no extra fields
                actionFields = `<p class="text-xs text-gray-500 italic">ไม่มีการตั้งค่าเพิ่มเติม</p>`;
        }
        
        return `
            <div class="bg-${config.color}-50 border border-${config.color}-200 rounded-lg p-3">
                <div class="flex items-center gap-2 mb-2">
                    <span class="text-lg">${config.icon}</span>
                    <span class="text-sm font-medium text-${config.color}-700">${config.label}</span>
                    <span class="text-xs text-gray-400">#${index + 1}</span>
                    <div class="flex-1"></div>
                    <button type="button" onclick="moveQuickReplyItem(${index}, -1)" class="p-1 text-gray-400 hover:text-gray-600 ${index === 0 ? 'opacity-30' : ''}" ${index === 0 ? 'disabled' : ''}>
                        <i class="fas fa-arrow-up"></i>
                    </button>
                    <button type="button" onclick="moveQuickReplyItem(${index}, 1)" class="p-1 text-gray-400 hover:text-gray-600 ${index === quickReplyItems.length - 1 ? 'opacity-30' : ''}" ${index === quickReplyItems.length - 1 ? 'disabled' : ''}>
                        <i class="fas fa-arrow-down"></i>
                    </button>
                    <button type="button" onclick="removeQuickReplyItem(${index})" class="p-1 text-red-500 hover:bg-red-100 rounded">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-2 mb-2">
                    <div>
                        <label class="text-xs text-gray-500">Label (ข้อความบนปุ่ม) *</label>
                        <input type="text" value="${escapeHtml(item.label || '')}" 
                               onchange="updateQuickReplyItem(${index}, 'label', this.value)"
                               placeholder="🛒 ดูสินค้า" class="w-full px-2 py-1 border rounded text-sm" maxlength="20">
                    </div>
                    <div>
                        <label class="text-xs text-gray-500">Icon URL (รูปไอคอน)</label>
                        <input type="url" value="${escapeHtml(item.imageUrl || '')}" 
                               onchange="updateQuickReplyItem(${index}, 'imageUrl', this.value)"
                               placeholder="https://example.com/icon.png" class="w-full px-2 py-1 border rounded text-sm">
                    </div>
                </div>
                
                ${actionFields}
            </div>
        `;
    }).join('');
    
    updateQuickReplyHidden();
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function updateQuickReplyHidden() {
    const hidden = document.getElementById('quick_reply');
    if (!hidden) return;
    
    const items = quickReplyItems.filter(item => item.label).map(item => {
        const result = {
            type: item.type,
            label: item.label
        };
        
        // Add icon if exists
        if (item.imageUrl) {
            result.imageUrl = item.imageUrl;
        }
        
        // Add action-specific fields
        switch (item.type) {
            case 'message':
                result.text = item.text || item.label;
                break;
            case 'uri':
                result.uri = item.uri;
                break;
            case 'postback':
                result.data = item.data;
                if (item.displayText) result.displayText = item.displayText;
                break;
            case 'datetimepicker':
                result.data = item.data;
                result.mode = item.mode || 'datetime';
                if (item.initial) result.initial = item.initial;
                if (item.min) result.min = item.min;
                if (item.max) result.max = item.max;
                break;
            // camera, cameraRoll, location - no extra fields needed
        }
        
        return result;
    });
    
    hidden.value = items.length > 0 ? JSON.stringify(items) : '';
}

// Templates
const templates = {
    greeting: {
        keyword: 'สวัสดี',
        match_type: 'contains',
        reply_type: 'text',
        reply_content: 'สวัสดีค่ะ! 👋\n\nยินดีต้อนรับสู่ร้านของเรา\nมีอะไรให้ช่วยเหลือไหมคะ?\n\n💡 พิมพ์ "menu" เพื่อดูเมนู',
        description: 'ตอบกลับเมื่อมีคนทักทาย',
        tags: 'greeting,welcome',
        sender_name: '🛒 Shop',
        quick_reply: JSON.stringify([
            {label: '🛒 ดูสินค้า', text: 'shop'},
            {label: '📋 เมนู', text: 'menu'}
        ])
    },
    thanks: {
        keyword: 'ขอบคุณ',
        match_type: 'contains',
        reply_type: 'text',
        reply_content: 'ขอบคุณเช่นกันค่ะ! 🙏✨\n\nหากมีข้อสงสัยเพิ่มเติม สามารถสอบถามได้ตลอดเวลานะคะ',
        description: 'ตอบกลับเมื่อมีคนขอบคุณ',
        tags: 'thanks,polite',
        sender_name: '💬 Support'
    },
    price: {
        keyword: 'ราคา',
        match_type: 'contains',
        reply_type: 'text',
        reply_content: '💰 สอบถามราคาสินค้า\n\nสามารถดูราคาสินค้าทั้งหมดได้ที่เมนู "ดูสินค้า" ค่ะ\n\nหรือบอกชื่อสินค้าที่สนใจมาได้เลยนะคะ',
        description: 'ตอบกลับเมื่อถามราคา',
        tags: 'price,product',
        quick_reply: JSON.stringify([
            {label: '🛒 ดูสินค้า', text: 'shop'},
            {label: '🏷️ โปรโมชั่น', text: 'promo'}
        ])
    },
    shipping: {
        keyword: 'จัดส่ง',
        match_type: 'contains',
        reply_type: 'text',
        reply_content: '🚚 ข้อมูลการจัดส่ง\n\n• จัดส่งทุกวัน จันทร์-เสาร์\n• ใช้เวลา 1-3 วันทำการ\n• ค่าส่งเริ่มต้น 50 บาท\n• สั่งครบ 500 บาท ส่งฟรี!\n\n📦 ติดตามพัสดุได้ที่ "เช็คสถานะออเดอร์"',
        description: 'ตอบกลับเมื่อถามเรื่องจัดส่ง',
        tags: 'shipping,delivery',
        sender_name: '📦 Shipping'
    },
    payment: {
        keyword: 'ชำระเงิน',
        match_type: 'contains',
        reply_type: 'text',
        reply_content: '💳 ช่องทางชำระเงิน\n\n• โอนผ่านธนาคาร\n• พร้อมเพย์\n• เก็บเงินปลายทาง (COD)\n\n📸 หลังโอนเงิน กรุณาส่งสลิปมาที่แชทนี้\nหรือพิมพ์ "สลิป" เพื่อส่งหลักฐาน',
        description: 'ตอบกลับเมื่อถามเรื่องชำระเงิน',
        tags: 'payment,transfer',
        sender_name: '💳 Payment',
        quick_reply: JSON.stringify([
            {label: '💳 ส่งสลิป', text: 'สลิป'},
            {label: '📦 เช็คออเดอร์', text: 'orders'}
        ])
    },
    contact: {
        keyword: 'ติดต่อ',
        match_type: 'contains',
        reply_type: 'text',
        reply_content: '📞 ช่องทางติดต่อ\n\n• LINE: @yourshop\n• โทร: 02-xxx-xxxx\n• Email: contact@shop.com\n• เวลาทำการ: 9:00-18:00 น.\n\nหรือพิมพ์ข้อความทิ้งไว้ได้เลยค่ะ ทีมงานจะติดต่อกลับโดยเร็ว',
        description: 'ตอบกลับเมื่อต้องการติดต่อ',
        tags: 'contact,support',
        sender_name: '📞 Contact'
    },
    hours: {
        keyword: 'เวลา',
        match_type: 'contains',
        reply_type: 'text',
        reply_content: '🕐 เวลาทำการ\n\n• จันทร์-ศุกร์: 9:00-18:00 น.\n• เสาร์: 10:00-16:00 น.\n• อาทิตย์: หยุด\n\n💬 สามารถสั่งซื้อผ่าน LINE ได้ตลอด 24 ชม. ค่ะ',
        description: 'ตอบกลับเมื่อถามเวลาทำการ',
        tags: 'hours,time'
    },
    promo: {
        keyword: 'โปรโมชั่น',
        match_type: 'contains',
        reply_type: 'text',
        reply_content: '🎁 โปรโมชั่นพิเศษ!\n\n🔥 สั่งครบ 500 บาท ส่งฟรี!\n🔥 สมาชิกใหม่ลด 10%\n🔥 ซื้อ 2 ชิ้น ลด 50 บาท\n\n📅 โปรโมชั่นมีจำกัด รีบสั่งเลย!',
        description: 'ตอบกลับเมื่อถามโปรโมชั่น',
        tags: 'promo,discount,sale',
        sender_name: '🎁 Promo',
        quick_reply: JSON.stringify([
            {label: '🛒 ช้อปเลย', text: 'shop'},
            {label: '🏷️ ดูทั้งหมด', text: 'promotions'}
        ])
    }
};

function openTemplateModal() {
    document.getElementById('templateModal').classList.remove('hidden');
    document.getElementById('templateModal').classList.add('flex');
}

function closeTemplateModal() {
    document.getElementById('templateModal').classList.add('hidden');
    document.getElementById('templateModal').classList.remove('flex');
}

function useTemplate(key) {
    const template = templates[key];
    if (!template) return;
    
    closeTemplateModal();
    openModal();
    
    document.getElementById('keyword').value = template.keyword || '';
    document.getElementById('match_type').value = template.match_type || 'contains';
    document.getElementById('reply_type').value = template.reply_type || 'text';
    document.getElementById('reply_content').value = template.reply_content || '';
    
    if (document.getElementById('description')) {
        document.getElementById('description').value = template.description || '';
    }
    if (document.getElementById('tags')) {
        document.getElementById('tags').value = template.tags || '';
    }
    if (document.getElementById('sender_name')) {
        document.getElementById('sender_name').value = template.sender_name || '';
    }
    
    if (template.quick_reply) {
        try {
            quickReplyItems = JSON.parse(template.quick_reply);
        } catch (e) {
            quickReplyItems = [];
        }
    } else {
        quickReplyItems = [];
    }
    renderQuickReplyItems();
    toggleReplyType();
}

// Preview
function previewMessage() {
    const replyType = document.getElementById('reply_type').value;
    const content = document.getElementById('reply_content').value;
    const senderName = document.getElementById('sender_name')?.value || '';
    
    let preview = '';
    
    if (senderName) {
        preview += `<div class="text-xs text-gray-500 mb-1">👤 ${senderName}</div>`;
    }
    
    if (replyType === 'flex') {
        try {
            const json = JSON.parse(content);
            preview += `<div class="bg-white rounded-lg p-3 shadow-sm">
                <div class="text-xs text-indigo-500 mb-2">📦 Flex Message</div>
                <pre class="text-xs overflow-auto max-h-40">${JSON.stringify(json, null, 2)}</pre>
            </div>`;
        } catch (e) {
            preview += `<div class="text-red-500">❌ Invalid JSON: ${e.message}</div>`;
        }
    } else {
        preview += `<div class="bg-white rounded-lg p-3 shadow-sm whitespace-pre-wrap">${content.replace(/</g, '&lt;').replace(/>/g, '&gt;')}</div>`;
    }
    
    // Quick Reply preview
    if (quickReplyItems.length > 0) {
        preview += `<div class="flex flex-wrap gap-1 mt-2">`;
        quickReplyItems.forEach(item => {
            if (item.label) {
                preview += `<span class="px-2 py-1 bg-white border rounded-full text-xs">${item.label}</span>`;
            }
        });
        preview += `</div>`;
    }
    
    document.getElementById('previewContent').innerHTML = preview;
    document.getElementById('previewModal').classList.remove('hidden');
    document.getElementById('previewModal').classList.add('flex');
}

function previewRule(id) {
    // Find rule and preview
    const rules = <?= json_encode($rules) ?>;
    const rule = rules.find(r => r.id == id);
    if (!rule) return;
    
    let preview = '';
    
    if (rule.sender_name) {
        preview += `<div class="text-xs text-gray-500 mb-1">👤 ${rule.sender_name}</div>`;
    }
    
    if (rule.reply_type === 'flex') {
        try {
            const json = JSON.parse(rule.reply_content);
            preview += `<div class="bg-white rounded-lg p-3 shadow-sm">
                <div class="text-xs text-indigo-500 mb-2">📦 Flex Message</div>
                <pre class="text-xs overflow-auto max-h-40">${JSON.stringify(json, null, 2)}</pre>
            </div>`;
        } catch (e) {
            preview += `<div class="bg-white rounded-lg p-3 shadow-sm whitespace-pre-wrap">${rule.reply_content}</div>`;
        }
    } else {
        preview += `<div class="bg-white rounded-lg p-3 shadow-sm whitespace-pre-wrap">${rule.reply_content.replace(/</g, '&lt;').replace(/>/g, '&gt;')}</div>`;
    }
    
    if (rule.quick_reply) {
        try {
            const qr = JSON.parse(rule.quick_reply);
            preview += `<div class="flex flex-wrap gap-1 mt-2">`;
            qr.forEach(item => {
                preview += `<span class="px-2 py-1 bg-white border rounded-full text-xs">${item.label}</span>`;
            });
            preview += `</div>`;
        } catch (e) {}
    }
    
    document.getElementById('previewContent').innerHTML = preview;
    document.getElementById('previewModal').classList.remove('hidden');
    document.getElementById('previewModal').classList.add('flex');
}

function closePreviewModal() {
    document.getElementById('previewModal').classList.add('hidden');
    document.getElementById('previewModal').classList.remove('flex');
}

// Initialize
document.addEventListener('DOMContentLoaded', function() {
    toggleReplyType();
});
</script>

<?php require_once 'includes/footer.php'; ?>
