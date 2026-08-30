<?php
/**
 * Learning Hub — Admin manager (platform-global tutorial videos + comments).
 * /admin/learning-hub.php — super-admin only.
 *
 * Upload/manage the video library that every SaaS tenant sees at /learning-hub.php,
 * and moderate customer comments (hide / delete / reply).
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../classes/LearningHubService.php';

if (!isSuperAdmin()) {
    http_response_code(403);
    echo 'เฉพาะผู้ดูแลระบบ (super admin) เท่านั้น';
    exit;
}

$pdo = LearningHubService::platformPdo();
if ($pdo === null) {
    echo 'ไม่สามารถเชื่อมต่อฐานข้อมูล platform ได้';
    exit;
}
$svc = new LearningHubService($pdo);

$uploadDir = __DIR__ . '/../uploads/learning';
$uploadUrlBase = '/uploads/learning';

// ----------------------------------------------------------- POST (PRG)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';
    $msg = '';
    try {
        if ($act === 'save_content') {
            $sourceType = $_POST['source_type'] ?? 'youtube';
            $videoUrl = trim((string) ($_POST['video_url'] ?? ''));

            // Handle mp4 upload when source is "file"
            if ($sourceType === 'file' && !empty($_FILES['video_file']['name'])) {
                if (!is_dir($uploadDir)) { @mkdir($uploadDir, 0755, true); }
                $f = $_FILES['video_file'];
                if ($f['error'] !== UPLOAD_ERR_OK) { throw new Exception('อัปโหลดไฟล์ไม่สำเร็จ'); }
                if ($f['size'] > 200 * 1024 * 1024) { throw new Exception('ไฟล์ใหญ่เกิน 200MB'); }
                $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, ['mp4', 'webm', 'mov'], true)) { throw new Exception('รองรับเฉพาะ mp4/webm/mov'); }
                $fname = 'lh_' . date('Ymd_His') . '_' . substr(md5((string) mt_rand()), 0, 6) . '.' . $ext;
                if (!move_uploaded_file($f['tmp_name'], $uploadDir . '/' . $fname)) { throw new Exception('บันทึกไฟล์ไม่สำเร็จ'); }
                $videoUrl = $uploadUrlBase . '/' . $fname;
            }

            $data = [
                'title' => $_POST['title'] ?? '',
                'description' => $_POST['description'] ?? '',
                'category' => trim((string) ($_POST['category'] ?? '')) ?: null,
                'source_type' => $sourceType,
                'video_url' => $videoUrl ?: null,
                'thumbnail_url' => trim((string) ($_POST['thumbnail_url'] ?? '')) ?: null,
                'duration' => trim((string) ($_POST['duration'] ?? '')) ?: null,
                'sort_order' => (int) ($_POST['sort_order'] ?? 0),
                'is_published' => isset($_POST['is_published']) ? 1 : 0,
            ];
            if ($data['title'] === '') { throw new Exception('กรุณากรอกชื่อวิดีโอ'); }
            if (!$data['video_url']) { throw new Exception('กรุณาใส่ลิงก์วิดีโอ หรืออัปโหลดไฟล์'); }

            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) { $svc->update($id, $data); $msg = 'แก้ไขวิดีโอแล้ว'; }
            else { $svc->create($data); $msg = 'เพิ่มวิดีโอแล้ว'; }
        } elseif ($act === 'delete_content') {
            $svc->delete((int) ($_POST['id'] ?? 0));
            $msg = 'ลบวิดีโอแล้ว';
        } elseif ($act === 'comment_hide') {
            $svc->setCommentStatus((int) ($_POST['id'] ?? 0), 'hidden');
            $msg = 'ซ่อนความคิดเห็นแล้ว';
        } elseif ($act === 'comment_show') {
            $svc->setCommentStatus((int) ($_POST['id'] ?? 0), 'visible');
            $msg = 'แสดงความคิดเห็นแล้ว';
        } elseif ($act === 'comment_delete') {
            $svc->deleteComment((int) ($_POST['id'] ?? 0));
            $msg = 'ลบความคิดเห็นแล้ว';
        } elseif ($act === 'comment_reply') {
            $cid = (int) ($_POST['content_id'] ?? 0);
            $pid = (int) ($_POST['parent_id'] ?? 0);
            $body = trim((string) ($_POST['body'] ?? ''));
            if ($cid && $pid && $body !== '') {
                $svc->addAdminReply($cid, $pid, 'ทีมงาน REYA', $body);
                $msg = 'ตอบกลับแล้ว';
            }
        }
        header('Location: /admin/learning-hub.php?msg=' . urlencode($msg));
        exit;
    } catch (Exception $e) {
        header('Location: /admin/learning-hub.php?err=' . urlencode($e->getMessage()));
        exit;
    }
}

$editing = null;
if (!empty($_GET['edit'])) { $editing = $svc->getById((int) $_GET['edit']); }

$pageTitle = 'จัดการวิดีโอสอนใช้งาน';
require_once __DIR__ . '/../includes/header.php';

$videos = $svc->listAll();
$comments = $svc->recentComments(80);
$flashMsg = $_GET['msg'] ?? '';
$flashErr = $_GET['err'] ?? '';
?>
<div class="max-w-6xl mx-auto px-4 py-6">
    <h1 class="text-2xl font-bold text-gray-800 mb-1">🎬 จัดการวิดีโอสอนใช้งาน</h1>
    <p class="text-gray-500 mb-5">คอนเทนต์กลาง — อัปทีเดียว ลูกค้าทุกร้านเห็นที่หน้า <code>/learning-hub</code></p>

    <?php if ($flashMsg): ?><div class="mb-4 bg-emerald-50 text-emerald-700 px-4 py-2 rounded-lg"><?= htmlspecialchars($flashMsg) ?></div><?php endif; ?>
    <?php if ($flashErr): ?><div class="mb-4 bg-red-50 text-red-700 px-4 py-2 rounded-lg"><?= htmlspecialchars($flashErr) ?></div><?php endif; ?>

    <!-- Add / Edit form -->
    <div class="bg-white rounded-2xl border border-gray-100 p-5 mb-8">
        <h2 class="font-semibold text-gray-800 mb-4"><?= $editing ? 'แก้ไขวิดีโอ' : 'เพิ่มวิดีโอใหม่' ?></h2>
        <form method="post" enctype="multipart/form-data" class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <input type="hidden" name="action" value="save_content">
            <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>

            <div class="md:col-span-2">
                <label class="block text-sm text-gray-600 mb-1">ชื่อวิดีโอ *</label>
                <input name="title" required value="<?= htmlspecialchars($editing['title'] ?? '') ?>" class="w-full border border-gray-200 rounded-lg px-3 py-2">
            </div>
            <div class="md:col-span-2">
                <label class="block text-sm text-gray-600 mb-1">คำอธิบาย</label>
                <textarea name="description" rows="3" class="w-full border border-gray-200 rounded-lg px-3 py-2"><?= htmlspecialchars($editing['description'] ?? '') ?></textarea>
            </div>
            <div>
                <label class="block text-sm text-gray-600 mb-1">หมวดหมู่</label>
                <input name="category" value="<?= htmlspecialchars($editing['category'] ?? '') ?>" placeholder="เช่น เริ่มต้น / การตลาด" class="w-full border border-gray-200 rounded-lg px-3 py-2">
            </div>
            <div>
                <label class="block text-sm text-gray-600 mb-1">ความยาว (แสดงผล)</label>
                <input name="duration" value="<?= htmlspecialchars($editing['duration'] ?? '') ?>" placeholder="เช่น 1 นาที" class="w-full border border-gray-200 rounded-lg px-3 py-2">
            </div>
            <div>
                <label class="block text-sm text-gray-600 mb-1">แหล่งวิดีโอ</label>
                <select name="source_type" id="src-type" class="w-full border border-gray-200 rounded-lg px-3 py-2">
                    <?php $st = $editing['source_type'] ?? 'youtube'; ?>
                    <option value="youtube" <?= $st === 'youtube' ? 'selected' : '' ?>>YouTube</option>
                    <option value="url" <?= $st === 'url' ? 'selected' : '' ?>>ลิงก์ภายนอก (mp4/url)</option>
                    <option value="file" <?= $st === 'file' ? 'selected' : '' ?>>อัปโหลดไฟล์</option>
                </select>
            </div>
            <div>
                <label class="block text-sm text-gray-600 mb-1">ลำดับ (sort)</label>
                <input type="number" name="sort_order" value="<?= (int) ($editing['sort_order'] ?? 0) ?>" class="w-full border border-gray-200 rounded-lg px-3 py-2">
            </div>
            <div class="md:col-span-2" id="url-row">
                <label class="block text-sm text-gray-600 mb-1">ลิงก์วิดีโอ (YouTube/URL)</label>
                <input name="video_url" value="<?= htmlspecialchars($editing['video_url'] ?? '') ?>" placeholder="https://youtu.be/... หรือ https://.../clip.mp4" class="w-full border border-gray-200 rounded-lg px-3 py-2">
            </div>
            <div class="md:col-span-2 hidden" id="file-row">
                <label class="block text-sm text-gray-600 mb-1">อัปโหลดไฟล์ (mp4/webm/mov ≤200MB)</label>
                <input type="file" name="video_file" accept="video/mp4,video/webm,video/quicktime" class="w-full">
            </div>
            <div class="md:col-span-2">
                <label class="block text-sm text-gray-600 mb-1">รูปปก (thumbnail URL — ไม่ใส่ก็ได้)</label>
                <input name="thumbnail_url" value="<?= htmlspecialchars($editing['thumbnail_url'] ?? '') ?>" class="w-full border border-gray-200 rounded-lg px-3 py-2">
            </div>
            <div class="md:col-span-2 flex items-center justify-between">
                <label class="inline-flex items-center gap-2 text-sm text-gray-600">
                    <input type="checkbox" name="is_published" <?= (!$editing || (int) $editing['is_published'] === 1) ? 'checked' : '' ?>> เผยแพร่
                </label>
                <div class="flex gap-2">
                    <?php if ($editing): ?><a href="/admin/learning-hub.php" class="px-4 py-2 rounded-lg bg-gray-100 text-gray-600">ยกเลิก</a><?php endif; ?>
                    <button class="px-5 py-2 rounded-lg bg-emerald-600 text-white hover:bg-emerald-700"><?= $editing ? 'บันทึกการแก้ไข' : 'เพิ่มวิดีโอ' ?></button>
                </div>
            </div>
        </form>
    </div>

    <!-- Video list -->
    <h2 class="font-semibold text-gray-800 mb-3">วิดีโอทั้งหมด (<?= count($videos) ?>)</h2>
    <div class="bg-white rounded-2xl border border-gray-100 overflow-hidden mb-10">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-gray-500">
                <tr><th class="text-left px-4 py-2">ลำดับ</th><th class="text-left px-4 py-2">ชื่อ</th><th class="text-left px-4 py-2">หมวด</th><th class="text-left px-4 py-2">แหล่ง</th><th class="text-left px-4 py-2">ดู</th><th class="text-left px-4 py-2">สถานะ</th><th class="px-4 py-2"></th></tr>
            </thead>
            <tbody>
                <?php foreach ($videos as $v): ?>
                    <tr class="border-t border-gray-100">
                        <td class="px-4 py-2"><?= (int) $v['sort_order'] ?></td>
                        <td class="px-4 py-2 font-medium text-gray-800"><?= htmlspecialchars($v['title']) ?></td>
                        <td class="px-4 py-2 text-gray-500"><?= htmlspecialchars((string) $v['category']) ?></td>
                        <td class="px-4 py-2 text-gray-500"><?= htmlspecialchars($v['source_type']) ?></td>
                        <td class="px-4 py-2 text-gray-500"><?= (int) $v['view_count'] ?></td>
                        <td class="px-4 py-2"><?= (int) $v['is_published'] === 1 ? '<span class="text-emerald-600">เผยแพร่</span>' : '<span class="text-gray-400">ซ่อน</span>' ?></td>
                        <td class="px-4 py-2 text-right whitespace-nowrap">
                            <a href="/admin/learning-hub.php?edit=<?= (int) $v['id'] ?>" class="text-emerald-600 hover:underline">แก้ไข</a>
                            <form method="post" class="inline" onsubmit="return confirm('ลบวิดีโอนี้?')">
                                <input type="hidden" name="action" value="delete_content"><input type="hidden" name="id" value="<?= (int) $v['id'] ?>">
                                <button class="text-red-500 hover:underline ml-2">ลบ</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($videos)): ?><tr><td colspan="7" class="px-4 py-6 text-center text-gray-400">ยังไม่มีวิดีโอ</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Comment moderation -->
    <h2 class="font-semibold text-gray-800 mb-3">ความคิดเห็นล่าสุด</h2>
    <div class="space-y-3">
        <?php foreach ($comments as $c): ?>
            <div class="bg-white rounded-xl border border-gray-100 p-4 <?= $c['status'] === 'hidden' ? 'opacity-50' : '' ?>">
                <div class="flex justify-between items-start gap-3">
                    <div class="min-w-0">
                        <div class="text-xs text-gray-400"><?= htmlspecialchars($c['content_title']) ?></div>
                        <div class="font-medium text-gray-700"><?= htmlspecialchars($c['author_name']) ?> <span class="text-xs text-gray-400">· tenant <?= (int) $c['tenant_id'] ?></span></div>
                        <div class="text-gray-600"><?= htmlspecialchars($c['body']) ?></div>
                    </div>
                    <div class="flex gap-2 shrink-0">
                        <?php if ($c['status'] === 'visible'): ?>
                            <form method="post"><input type="hidden" name="action" value="comment_hide"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><button class="text-xs text-amber-600">ซ่อน</button></form>
                        <?php else: ?>
                            <form method="post"><input type="hidden" name="action" value="comment_show"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><button class="text-xs text-emerald-600">แสดง</button></form>
                        <?php endif; ?>
                        <form method="post" onsubmit="return confirm('ลบความคิดเห็นนี้?')"><input type="hidden" name="action" value="comment_delete"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><button class="text-xs text-red-500">ลบ</button></form>
                    </div>
                </div>
                <form method="post" class="mt-2 flex gap-2">
                    <input type="hidden" name="action" value="comment_reply">
                    <input type="hidden" name="content_id" value="<?= (int) $c['content_id'] ?>">
                    <input type="hidden" name="parent_id" value="<?= (int) $c['id'] ?>">
                    <input name="body" placeholder="ตอบกลับในนามทีมงาน REYA..." class="flex-1 border border-gray-200 rounded-lg px-3 py-1.5 text-sm">
                    <button class="text-sm bg-gray-800 text-white px-3 py-1.5 rounded-lg">ตอบ</button>
                </form>
            </div>
        <?php endforeach; ?>
        <?php if (empty($comments)): ?><div class="text-gray-400 text-sm">ยังไม่มีความคิดเห็น</div><?php endif; ?>
    </div>
</div>

<script>
(function () {
    const sel = document.getElementById('src-type');
    const urlRow = document.getElementById('url-row');
    const fileRow = document.getElementById('file-row');
    function sync() {
        const isFile = sel.value === 'file';
        fileRow.classList.toggle('hidden', !isFile);
        urlRow.classList.toggle('hidden', isFile);
    }
    sel.addEventListener('change', sync); sync();
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
