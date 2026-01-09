<?php
/**
 * Admin Banners Management - Landing Page Settings
 * จัดการแบนเนอร์/โปสเตอร์สไลด์
 */

$banners = $bannerService->getAllForAdmin();
?>

<div class="card">
    <div class="card-header flex items-center justify-between">
        <div>
            <h3 class="text-lg font-semibold">🖼️ แบนเนอร์/โปสเตอร์</h3>
            <p class="text-sm text-gray-500">จัดการแบนเนอร์สไลด์บนหน้า Landing Page</p>
        </div>
        <button type="button" onclick="openBannerModal()" class="btn btn-primary">
            <i class="fas fa-plus mr-2"></i>เพิ่มแบนเนอร์
        </button>
    </div>
    
    <div class="card-body">
        <?php if (empty($banners)): ?>
        <div class="text-center py-12 text-gray-500">
            <i class="fas fa-image text-4xl mb-4 opacity-50"></i>
            <p>ยังไม่มีแบนเนอร์</p>
            <p class="text-sm">คลิก "เพิ่มแบนเนอร์" เพื่อเริ่มต้น</p>
        </div>
        <?php else: ?>
        <div class="banner-list" id="bannerList">
            <?php foreach ($banners as $banner): ?>
            <div class="banner-item" data-id="<?= $banner['id'] ?>">
                <div class="banner-drag-handle">
                    <i class="fas fa-grip-vertical"></i>
                </div>
                
                <div class="banner-preview">
                    <?php if (!empty($banner['image_url'])): ?>
                    <img src="<?= htmlspecialchars($banner['image_url']) ?>" alt="">
                    <?php else: ?>
                    <div class="banner-placeholder">
                        <i class="fas fa-image"></i>
                    </div>
                    <?php endif; ?>
                </div>
                
                <div class="banner-info">
                    <div class="banner-title"><?= htmlspecialchars($banner['title'] ?: 'ไม่มีชื่อ') ?></div>
                    <div class="banner-meta">
                        <?php if (!empty($banner['link_url'])): ?>
                        <span class="text-xs text-blue-600">
                            <i class="fas fa-link mr-1"></i>
                            <?= $banner['link_type'] === 'external' ? 'ลิงก์ภายนอก' : 'ลิงก์ภายใน' ?>
                        </span>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="banner-status">
                    <?php if ($banner['is_active']): ?>
                    <span class="badge badge-success">แสดง</span>
                    <?php else: ?>
                    <span class="badge badge-secondary">ซ่อน</span>
                    <?php endif; ?>
                </div>
                
                <div class="banner-actions">
                    <button type="button" onclick="editBanner(<?= htmlspecialchars(json_encode($banner)) ?>)" 
                            class="btn btn-sm btn-outline">
                        <i class="fas fa-edit"></i>
                    </button>
                    <form method="POST" class="inline" onsubmit="return confirm('ต้องการลบแบนเนอร์นี้?')">
                        <input type="hidden" name="action" value="delete_banner">
                        <input type="hidden" name="id" value="<?= $banner['id'] ?>">
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

<!-- Banner Modal -->
<div id="bannerModal" class="modal hidden">
    <div class="modal-backdrop" onclick="closeBannerModal()"></div>
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="bannerModalTitle">เพิ่มแบนเนอร์</h3>
            <button type="button" onclick="closeBannerModal()" class="modal-close">&times;</button>
        </div>
        <form method="POST" id="bannerForm">
            <input type="hidden" name="action" id="bannerAction" value="create_banner">
            <input type="hidden" name="id" id="bannerId" value="">
            
            <div class="modal-body">
                <div class="form-group">
                    <label>ชื่อแบนเนอร์</label>
                    <input type="text" name="title" id="bannerTitle" class="form-control" placeholder="เช่น โปรโมชั่นเดือนนี้">
                </div>
                
                <div class="form-group">
                    <label>URL รูปภาพ <span class="text-red-500">*</span></label>
                    <input type="url" name="image_url" id="bannerImageUrl" class="form-control" required 
                           placeholder="https://example.com/banner.jpg">
                    <p class="text-xs text-gray-500 mt-1">แนะนำขนาด 1200x525 px (อัตราส่วน 16:7)</p>
                </div>
                
                <div class="form-group">
                    <label>ลิงก์เมื่อคลิก</label>
                    <input type="url" name="link_url" id="bannerLinkUrl" class="form-control" 
                           placeholder="https://example.com หรือ /shop">
                </div>
                
                <div class="form-group">
                    <label>ประเภทลิงก์</label>
                    <select name="link_type" id="bannerLinkType" class="form-control">
                        <option value="none">ไม่มีลิงก์</option>
                        <option value="internal">ลิงก์ภายใน (เปิดในหน้าเดิม)</option>
                        <option value="external">ลิงก์ภายนอก (เปิดแท็บใหม่)</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="flex items-center gap-2">
                        <input type="checkbox" name="is_active" id="bannerIsActive" value="1" checked>
                        <span>แสดงแบนเนอร์</span>
                    </label>
                </div>
            </div>
            
            <div class="modal-footer">
                <button type="button" onclick="closeBannerModal()" class="btn btn-secondary">ยกเลิก</button>
                <button type="submit" class="btn btn-primary">บันทึก</button>
            </div>
        </form>
    </div>
</div>

<style>
.banner-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.banner-item {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 12px;
    background: #f8fafc;
    border-radius: 12px;
    border: 1px solid #e5e7eb;
    transition: all 0.2s;
}

.banner-item:hover {
    border-color: #06C755;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}

.banner-drag-handle {
    cursor: grab;
    color: #9ca3af;
    padding: 8px;
}

.banner-drag-handle:active { cursor: grabbing; }

.banner-preview {
    width: 120px;
    height: 52px;
    border-radius: 8px;
    overflow: hidden;
    background: #e5e7eb;
    flex-shrink: 0;
}

.banner-preview img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.banner-placeholder {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #9ca3af;
}

.banner-info {
    flex: 1;
    min-width: 0;
}

.banner-title {
    font-weight: 600;
    color: #1f2937;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.banner-meta {
    margin-top: 4px;
}

.banner-status {
    flex-shrink: 0;
}

.banner-actions {
    display: flex;
    gap: 8px;
    flex-shrink: 0;
}

/* Modal Styles */
.modal {
    position: fixed;
    inset: 0;
    z-index: 1000;
    display: flex;
    align-items: center;
    justify-content: center;
}

.modal.hidden { display: none; }

.modal-backdrop {
    position: absolute;
    inset: 0;
    background: rgba(0,0,0,0.5);
}

.modal-content {
    position: relative;
    background: white;
    border-radius: 16px;
    width: 100%;
    max-width: 500px;
    max-height: 90vh;
    overflow-y: auto;
    margin: 16px;
}

.modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px 20px;
    border-bottom: 1px solid #e5e7eb;
}

.modal-header h3 {
    font-size: 1.1rem;
    font-weight: 600;
}

.modal-close {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    border: none;
    background: #f3f4f6;
    cursor: pointer;
    font-size: 20px;
    line-height: 1;
}

.modal-body {
    padding: 20px;
}

.modal-footer {
    display: flex;
    justify-content: flex-end;
    gap: 12px;
    padding: 16px 20px;
    border-top: 1px solid #e5e7eb;
}

.form-group {
    margin-bottom: 16px;
}

.form-group label {
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
    transition: border-color 0.2s;
}

.form-control:focus {
    outline: none;
    border-color: #06C755;
    box-shadow: 0 0 0 3px rgba(6,199,85,0.1);
}

.badge {
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 500;
}

.badge-success { background: #dcfce7; color: #16a34a; }
.badge-secondary { background: #f3f4f6; color: #6b7280; }

.btn {
    padding: 10px 20px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s;
    border: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}

.btn-primary { background: #06C755; color: white; }
.btn-primary:hover { background: #05a648; }
.btn-secondary { background: #f3f4f6; color: #374151; }
.btn-outline { background: transparent; border: 1px solid #d1d5db; }
.btn-sm { padding: 6px 12px; font-size: 13px; }
</style>

<script>
function openBannerModal() {
    document.getElementById('bannerModalTitle').textContent = 'เพิ่มแบนเนอร์';
    document.getElementById('bannerAction').value = 'create_banner';
    document.getElementById('bannerId').value = '';
    document.getElementById('bannerForm').reset();
    document.getElementById('bannerIsActive').checked = true;
    document.getElementById('bannerModal').classList.remove('hidden');
}

function closeBannerModal() {
    document.getElementById('bannerModal').classList.add('hidden');
}

function editBanner(banner) {
    document.getElementById('bannerModalTitle').textContent = 'แก้ไขแบนเนอร์';
    document.getElementById('bannerAction').value = 'update_banner';
    document.getElementById('bannerId').value = banner.id;
    document.getElementById('bannerTitle').value = banner.title || '';
    document.getElementById('bannerImageUrl').value = banner.image_url || '';
    document.getElementById('bannerLinkUrl').value = banner.link_url || '';
    document.getElementById('bannerLinkType').value = banner.link_type || 'none';
    document.getElementById('bannerIsActive').checked = banner.is_active == 1;
    document.getElementById('bannerModal').classList.remove('hidden');
}
</script>
