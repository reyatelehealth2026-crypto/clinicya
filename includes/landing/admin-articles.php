<?php
/**
 * Admin Health Articles Management
 * จัดการบทความสุขภาพ
 */

require_once __DIR__ . '/../../classes/HealthArticleService.php';
$articleService = new HealthArticleService($db, $lineAccountId);

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'create':
            case 'update':
                $data = [
                    'category_id' => $_POST['category_id'] ?: null,
                    'title' => trim($_POST['title']),
                    'excerpt' => trim($_POST['excerpt'] ?? ''),
                    'content' => $_POST['content'],
                    'featured_image' => trim($_POST['featured_image'] ?? ''),
                    'author_name' => trim($_POST['author_name'] ?? ''),
                    'author_title' => trim($_POST['author_title'] ?? ''),
                    'meta_title' => trim($_POST['meta_title'] ?? ''),
                    'meta_description' => trim($_POST['meta_description'] ?? ''),
                    'is_featured' => isset($_POST['is_featured']) ? 1 : 0,
                    'is_published' => isset($_POST['is_published']) ? 1 : 0,
                    'tags' => array_filter(array_map('trim', explode(',', $_POST['tags'] ?? '')))
                ];
                
                if ($action === 'create') {
                    $articleService->create($data);
                    $successMessage = 'สร้างบทความสำเร็จ';
                } else {
                    $articleService->update((int)$_POST['id'], $data);
                    $successMessage = 'อัปเดตบทความสำเร็จ';
                }
                break;
                
            case 'delete':
                $articleService->delete((int)$_POST['id']);
                $successMessage = 'ลบบทความสำเร็จ';
                break;
                
            case 'toggle_publish':
                $articleService->togglePublish((int)$_POST['id']);
                $successMessage = 'เปลี่ยนสถานะสำเร็จ';
                break;
                
            case 'toggle_featured':
                $articleService->toggleFeatured((int)$_POST['id']);
                $successMessage = 'เปลี่ยนสถานะสำเร็จ';
                break;
        }
    } catch (Exception $e) {
        $errorMessage = $e->getMessage();
    }
}

$articles = $articleService->getAllForAdmin();
$categories = $articleService->getCategories();
$editArticle = null;

if (isset($_GET['edit'])) {
    $editArticle = $articleService->getById((int)$_GET['edit']);
}
?>

<div class="card">
    <div class="card-header flex items-center justify-between">
        <div>
            <h3 class="text-lg font-semibold">📚 บทความสุขภาพ</h3>
            <p class="text-sm text-gray-500">จัดการบทความเพื่อ SEO และให้ความรู้ลูกค้า</p>
        </div>
        <button type="button" onclick="showArticleForm()" class="btn btn-primary">
            <i class="fas fa-plus mr-2"></i>เขียนบทความ
        </button>
    </div>
    
    <div class="card-body">
        <?php if (empty($articles)): ?>
        <div class="text-center py-12 text-gray-500">
            <i class="fas fa-newspaper text-4xl mb-4 opacity-50"></i>
            <p>ยังไม่มีบทความ</p>
            <p class="text-sm">เริ่มเขียนบทความเพื่อเพิ่ม SEO และให้ความรู้ลูกค้า</p>
        </div>
        <?php else: ?>
        <div class="articles-admin-list">
            <?php foreach ($articles as $article): ?>
            <div class="article-admin-item">
                <div class="article-admin-image">
                    <?php if (!empty($article['featured_image'])): ?>
                    <img src="<?= htmlspecialchars($article['featured_image']) ?>" alt="">
                    <?php else: ?>
                    <div class="article-admin-placeholder">
                        <i class="fas fa-newspaper"></i>
                    </div>
                    <?php endif; ?>
                </div>
                
                <div class="article-admin-info">
                    <div class="article-admin-title">
                        <?= htmlspecialchars($article['title']) ?>
                        <?php if ($article['is_featured']): ?>
                        <span class="badge badge-warning ml-2">แนะนำ</span>
                        <?php endif; ?>
                    </div>
                    <div class="article-admin-meta">
                        <?php if (!empty($article['category_name'])): ?>
                        <span class="text-primary"><?= htmlspecialchars($article['category_name']) ?></span> •
                        <?php endif; ?>
                        <?php if (!empty($article['author_name'])): ?>
                        <?= htmlspecialchars($article['author_name']) ?> •
                        <?php endif; ?>
                        <i class="fas fa-eye"></i> <?= number_format($article['view_count']) ?>
                        <?php if (!empty($article['published_at'])): ?>
                        • <?= date('d M Y', strtotime($article['published_at'])) ?>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="article-admin-status">
                    <?php if ($article['is_published']): ?>
                    <span class="badge badge-success">เผยแพร่</span>
                    <?php else: ?>
                    <span class="badge badge-secondary">ฉบับร่าง</span>
                    <?php endif; ?>
                </div>
                
                <div class="article-admin-actions">
                    <a href="?tab=articles&edit=<?= $article['id'] ?>" class="btn btn-sm btn-outline" title="แก้ไข">
                        <i class="fas fa-edit"></i>
                    </a>
                    <form method="POST" class="inline">
                        <input type="hidden" name="action" value="toggle_publish">
                        <input type="hidden" name="id" value="<?= $article['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline" title="<?= $article['is_published'] ? 'ซ่อน' : 'เผยแพร่' ?>">
                            <i class="fas fa-<?= $article['is_published'] ? 'eye-slash' : 'eye' ?>"></i>
                        </button>
                    </form>
                    <form method="POST" class="inline">
                        <input type="hidden" name="action" value="toggle_featured">
                        <input type="hidden" name="id" value="<?= $article['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline" title="<?= $article['is_featured'] ? 'ยกเลิกแนะนำ' : 'แนะนำ' ?>">
                            <i class="fas fa-star<?= $article['is_featured'] ? '' : '-o' ?>"></i>
                        </button>
                    </form>
                    <a href="<?= BASE_URL ?>article.php?slug=<?= htmlspecialchars($article['slug']) ?>" 
                       target="_blank" class="btn btn-sm btn-outline" title="ดูบทความ">
                        <i class="fas fa-external-link-alt"></i>
                    </a>
                    <form method="POST" class="inline" onsubmit="return confirm('ต้องการลบบทความนี้?')">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= $article['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline text-red-600">
                            <i class="fas fa-trash"></i>
                        </button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Article Form Modal -->
<div id="articleFormModal" class="modal <?= $editArticle ? '' : 'hidden' ?>">
    <div class="modal-backdrop" onclick="hideArticleForm()"></div>
    <div class="modal-content modal-lg">
        <div class="modal-header">
            <h3><?= $editArticle ? 'แก้ไขบทความ' : 'เขียนบทความใหม่' ?></h3>
            <button type="button" onclick="hideArticleForm()" class="modal-close">&times;</button>
        </div>
        
        <form method="POST" class="modal-body">
            <input type="hidden" name="action" value="<?= $editArticle ? 'update' : 'create' ?>">
            <?php if ($editArticle): ?>
            <input type="hidden" name="id" value="<?= $editArticle['id'] ?>">
            <?php endif; ?>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="form-group md:col-span-2">
                    <label class="form-label">หัวข้อบทความ *</label>
                    <input type="text" name="title" class="form-control" required
                           value="<?= htmlspecialchars($editArticle['title'] ?? '') ?>"
                           placeholder="เช่น วิธีดูแลสุขภาพในหน้าหนาว">
                </div>
                
                <div class="form-group">
                    <label class="form-label">หมวดหมู่</label>
                    <select name="category_id" class="form-control">
                        <option value="">-- เลือกหมวดหมู่ --</option>
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat['id'] ?>" <?= ($editArticle['category_id'] ?? '') == $cat['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cat['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">รูปภาพหลัก (URL)</label>
                    <input type="url" name="featured_image" class="form-control"
                           value="<?= htmlspecialchars($editArticle['featured_image'] ?? '') ?>"
                           placeholder="https://...">
                </div>
                
                <div class="form-group md:col-span-2">
                    <label class="form-label">คำอธิบายสั้น</label>
                    <textarea name="excerpt" class="form-control" rows="2"
                              placeholder="สรุปเนื้อหาบทความสั้นๆ สำหรับแสดงในหน้ารวมบทความ"><?= htmlspecialchars($editArticle['excerpt'] ?? '') ?></textarea>
                </div>
                
                <div class="form-group md:col-span-2">
                    <label class="form-label">เนื้อหาบทความ * (รองรับ HTML)</label>
                    <textarea name="content" class="form-control" rows="12" required
                              placeholder="<h2>หัวข้อ</h2><p>เนื้อหา...</p>"><?= htmlspecialchars($editArticle['content'] ?? '') ?></textarea>
                    <p class="text-xs text-gray-500 mt-1">ใช้ HTML tags: &lt;h2&gt;, &lt;h3&gt;, &lt;p&gt;, &lt;ul&gt;, &lt;li&gt;, &lt;strong&gt;, &lt;img&gt;</p>
                </div>
                
                <div class="form-group">
                    <label class="form-label">ชื่อผู้เขียน</label>
                    <input type="text" name="author_name" class="form-control"
                           value="<?= htmlspecialchars($editArticle['author_name'] ?? '') ?>"
                           placeholder="เช่น ภก.สมชาย ใจดี">
                </div>
                
                <div class="form-group">
                    <label class="form-label">ตำแหน่งผู้เขียน</label>
                    <input type="text" name="author_title" class="form-control"
                           value="<?= htmlspecialchars($editArticle['author_title'] ?? '') ?>"
                           placeholder="เช่น เภสัชกร">
                </div>
                
                <div class="form-group md:col-span-2">
                    <label class="form-label">Tags (คั่นด้วย ,)</label>
                    <input type="text" name="tags" class="form-control"
                           value="<?= htmlspecialchars(implode(', ', json_decode($editArticle['tags'] ?? '[]', true) ?? [])) ?>"
                           placeholder="สุขภาพ, วิตามิน, ภูมิคุ้มกัน">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Meta Title (SEO)</label>
                    <input type="text" name="meta_title" class="form-control"
                           value="<?= htmlspecialchars($editArticle['meta_title'] ?? '') ?>"
                           placeholder="หัวข้อสำหรับ Google">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Meta Description (SEO)</label>
                    <input type="text" name="meta_description" class="form-control"
                           value="<?= htmlspecialchars($editArticle['meta_description'] ?? '') ?>"
                           placeholder="คำอธิบายสำหรับ Google">
                </div>
                
                <div class="form-group md:col-span-2">
                    <label class="flex items-center gap-2">
                        <input type="checkbox" name="is_featured" value="1" 
                               <?= ($editArticle['is_featured'] ?? 0) ? 'checked' : '' ?>>
                        <span>บทความแนะนำ</span>
                    </label>
                    <label class="flex items-center gap-2 mt-2">
                        <input type="checkbox" name="is_published" value="1"
                               <?= ($editArticle['is_published'] ?? 0) ? 'checked' : '' ?>>
                        <span>เผยแพร่บทความ</span>
                    </label>
                </div>
            </div>
            
            <div class="flex justify-end gap-3 mt-6">
                <button type="button" onclick="hideArticleForm()" class="btn btn-secondary">ยกเลิก</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save mr-2"></i>บันทึก
                </button>
            </div>
        </form>
    </div>
</div>

<style>
/* Modal Styles */
.modal {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    z-index: 1000;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
}

.modal.hidden {
    display: none;
}

.modal-backdrop {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.5);
}

.modal-content {
    position: relative;
    background: white;
    border-radius: 16px;
    width: 100%;
    max-width: 600px;
    max-height: 90vh;
    overflow: hidden;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
}

.modal-content.modal-lg {
    max-width: 900px;
}

.modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 20px 24px;
    border-bottom: 1px solid #e5e7eb;
}

.modal-header h3 {
    font-size: 1.25rem;
    font-weight: 600;
    color: #1f2937;
    margin: 0;
}

.modal-close {
    width: 36px;
    height: 36px;
    border: none;
    background: #f3f4f6;
    border-radius: 8px;
    font-size: 24px;
    color: #6b7280;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    line-height: 1;
}

.modal-close:hover {
    background: #e5e7eb;
    color: #1f2937;
}

.modal-body {
    padding: 24px;
    overflow-y: auto;
    max-height: calc(90vh - 80px);
}

/* Form Styles */
.form-group {
    margin-bottom: 16px;
}

.form-label {
    display: block;
    font-size: 14px;
    font-weight: 500;
    color: #374151;
    margin-bottom: 6px;
}

.form-control {
    width: 100%;
    padding: 10px 14px;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    font-size: 14px;
    font-family: inherit;
    transition: border-color 0.2s, box-shadow 0.2s;
}

.form-control:focus {
    outline: none;
    border-color: #06C755;
    box-shadow: 0 0 0 3px rgba(6, 199, 85, 0.1);
}

textarea.form-control {
    resize: vertical;
    min-height: 80px;
}

select.form-control {
    cursor: pointer;
}

/* Grid */
.grid {
    display: grid;
}

.grid-cols-1 {
    grid-template-columns: repeat(1, 1fr);
}

.gap-4 {
    gap: 16px;
}

@media (min-width: 768px) {
    .md\:grid-cols-2 {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .md\:col-span-2 {
        grid-column: span 2;
    }
}

/* Flex utilities */
.flex {
    display: flex;
}

.items-center {
    align-items: center;
}

.justify-end {
    justify-content: flex-end;
}

.gap-2 {
    gap: 8px;
}

.gap-3 {
    gap: 12px;
}

.mt-2 {
    margin-top: 8px;
}

.mt-6 {
    margin-top: 24px;
}

/* Articles List */
.articles-admin-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.article-admin-item {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 16px;
    background: #f8fafc;
    border-radius: 12px;
    border: 1px solid #e5e7eb;
}

.article-admin-image {
    width: 80px;
    height: 50px;
    border-radius: 8px;
    overflow: hidden;
    background: #e5e7eb;
    flex-shrink: 0;
}

.article-admin-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.article-admin-placeholder {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #9ca3af;
}

.article-admin-info {
    flex: 1;
    min-width: 0;
}

.article-admin-title {
    font-weight: 600;
    color: #1f2937;
    margin-bottom: 4px;
}

.article-admin-meta {
    font-size: 13px;
    color: #6b7280;
}

.article-admin-status {
    flex-shrink: 0;
}

.article-admin-actions {
    display: flex;
    gap: 8px;
    flex-shrink: 0;
}

.badge-warning { background: #fef3c7; color: #d97706; }
</style>

<script>
function showArticleForm() {
    document.getElementById('articleFormModal').classList.remove('hidden');
}

function hideArticleForm() {
    document.getElementById('articleFormModal').classList.add('hidden');
    // Reset URL if editing
    if (window.location.search.includes('edit=')) {
        window.history.pushState({}, '', '?tab=articles');
    }
}
</script>
