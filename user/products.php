<?php
/**
 * User Products - จัดการสินค้า (AJAX Version)
 */
$pageTitle = 'สินค้า';
require_once '../includes/user_header.php';

// Get categories for dropdown - แสดงทั้งหมด ไม่ filter ตาม line_account_id
$stmt = $db->query("SELECT id, name FROM product_categories WHERE is_active = 1 ORDER BY sort_order ASC");
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get products with category - แสดงทั้งหมด ไม่ filter ตาม line_account_id
$stmt = $db->query("SELECT p.*, c.name as category_name FROM business_items p LEFT JOIN product_categories c ON p.category_id = c.id ORDER BY p.id DESC");
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!-- Toast Notification -->
<div id="toast" class="fixed top-4 right-4 z-50 hidden">
    <div class="bg-green-500 text-white px-6 py-3 rounded-lg shadow-lg flex items-center">
        <i class="fas fa-check-circle mr-2"></i>
        <span id="toast-message">สำเร็จ</span>
    </div>
</div>

<div class="mb-4 p-4 bg-blue-50 border border-blue-200 rounded-lg">
    <div class="flex items-center">
        <i class="fas fa-info-circle text-blue-500 mr-2"></i>
        <span class="text-sm text-blue-700">
            สินค้าด้านล่างเป็นของ <strong><?= htmlspecialchars($lineAccount['name']) ?></strong> เท่านั้น
        </span>
    </div>
</div>

<div class="mb-4 flex justify-between items-center">
    <div>
        <span class="text-gray-600">สินค้าทั้งหมด <span id="product-count"><?= count($products) ?></span> รายการ</span>
    </div>
    <button onclick="openModal()" class="px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600">
        <i class="fas fa-plus mr-2"></i>เพิ่มสินค้า
    </button>
</div>

<div id="products-grid" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
    <?php if (empty($products)): ?>
    <div id="empty-state" class="col-span-full bg-white rounded-xl p-8 text-center text-gray-400">
        <i class="fas fa-box text-5xl mb-4"></i>
        <p>ยังไม่มีสินค้า</p>
        <button onclick="openModal()" class="mt-4 px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600">
            เพิ่มสินค้าแรก
        </button>
    </div>
    <?php else: ?>
    <?php foreach ($products as $product): ?>
    <div class="product-card bg-white rounded-xl shadow overflow-hidden" data-id="<?= $product['id'] ?>">
        <div class="h-40 bg-gray-100 flex items-center justify-center relative">
            <?php if ($product['image_url']): ?>
            <img src="<?= htmlspecialchars($product['image_url']) ?>" class="w-full h-full object-cover product-image">
            <?php else: ?>
            <i class="fas fa-image text-4xl text-gray-300 product-image-placeholder"></i>
            <?php endif; ?>
            <?php if ($product['sale_price']): ?>
            <span class="sale-badge absolute top-2 left-2 px-2 py-1 bg-red-500 text-white text-xs rounded">SALE</span>
            <?php endif; ?>
        </div>
        <div class="p-4">
            <div class="flex items-start justify-between mb-1">
                <h3 class="font-semibold truncate flex-1 product-name"><?= htmlspecialchars($product['name']) ?></h3>
                <span class="status-badge px-2 py-0.5 text-xs rounded ml-2 <?= $product['is_active'] ? 'bg-green-100 text-green-600' : 'bg-gray-100 text-gray-600' ?>">
                    <?= $product['is_active'] ? 'เปิด' : 'ปิด' ?>
                </span>
            </div>
            <div class="text-xs text-gray-400 mb-2 product-category"><?= htmlspecialchars($product['category_name'] ?? '') ?></div>
            <div class="mb-2 product-price">
                <?php if ($product['sale_price']): ?>
                <span class="text-lg font-bold text-red-500">฿<?= number_format($product['sale_price']) ?></span>
                <span class="text-sm text-gray-400 line-through ml-1">฿<?= number_format($product['price']) ?></span>
                <?php else: ?>
                <span class="text-lg font-bold text-green-600">฿<?= number_format($product['price']) ?></span>
                <?php endif; ?>
            </div>
            <div class="text-sm text-gray-500 mb-3">
                คงเหลือ: <span class="product-stock <?= $product['stock'] <= 5 ? 'text-red-500 font-medium' : '' ?>"><?= number_format($product['stock']) ?></span> ชิ้น
            </div>
            <div class="flex gap-2">
                <button onclick='editProduct(<?= json_encode($product) ?>)' class="flex-1 px-3 py-2 text-sm bg-blue-50 text-blue-600 rounded hover:bg-blue-100">
                    <i class="fas fa-edit mr-1"></i>แก้ไข
                </button>
                <button onclick="deleteProduct(<?= $product['id'] ?>)" class="px-3 py-2 text-sm bg-red-50 text-red-600 rounded hover:bg-red-100">
                    <i class="fas fa-trash"></i>
                </button>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>


<!-- Modal -->
<div id="modal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
    <div class="bg-white rounded-xl w-full max-w-lg mx-4 max-h-[90vh] overflow-y-auto">
        <div class="p-6 border-b sticky top-0 bg-white">
            <h3 class="text-lg font-semibold" id="modalTitle">เพิ่มสินค้า</h3>
        </div>
        <div class="p-6 space-y-4">
            <input type="hidden" id="formAction" value="create">
            <input type="hidden" id="formId">
            <input type="hidden" id="existing_image">
            
            <div>
                <label class="block text-sm font-medium mb-1">รูปสินค้า</label>
                <div class="flex items-center gap-4">
                    <div id="imagePreview" class="w-24 h-24 bg-gray-100 rounded-lg flex items-center justify-center overflow-hidden">
                        <i class="fas fa-image text-gray-300 text-2xl"></i>
                    </div>
                    <input type="file" id="image" accept="image/*" class="text-sm" onchange="previewImage(this)">
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">ชื่อสินค้า <span class="text-red-500">*</span></label>
                <input type="text" id="name" required class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500">
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">หมวดหมู่</label>
                <select id="category_id" class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500">
                    <option value="">-- ไม่ระบุ --</option>
                    <?php foreach ($categories as $cat): ?>
                    <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-1">ราคาปกติ <span class="text-red-500">*</span></label>
                    <input type="number" id="price" required min="0" step="0.01" class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">ราคาลด</label>
                    <input type="number" id="sale_price" min="0" step="0.01" class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500">
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">จำนวนคงเหลือ</label>
                <input type="number" id="stock" min="0" value="0" class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500">
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">รายละเอียด</label>
                <textarea id="description" rows="3" class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500"></textarea>
            </div>
            <div>
                <label class="flex items-center">
                    <input type="checkbox" id="is_active" checked class="mr-2">
                    <span class="text-sm">เปิดขาย</span>
                </label>
            </div>
        </div>
        <div class="p-6 border-t flex justify-end space-x-2 sticky bottom-0 bg-white">
            <button type="button" onclick="closeModal()" class="px-4 py-2 border rounded-lg hover:bg-gray-50">ยกเลิก</button>
            <button type="button" onclick="saveProduct()" id="btn-save" class="px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600 disabled:opacity-50">บันทึก</button>
        </div>
    </div>
</div>

<script>
const categoriesData = <?= json_encode($categories) ?>;
let uploadedImageUrl = '';

function showToast(message, isError = false) {
    const toast = document.getElementById('toast');
    const toastMsg = document.getElementById('toast-message');
    toastMsg.textContent = message;
    
    const toastDiv = toast.querySelector('div');
    toastDiv.className = isError 
        ? 'bg-red-500 text-white px-6 py-3 rounded-lg shadow-lg flex items-center'
        : 'bg-green-500 text-white px-6 py-3 rounded-lg shadow-lg flex items-center';
    
    toast.classList.remove('hidden');
    setTimeout(() => toast.classList.add('hidden'), 3000);
}

function openModal() {
    document.getElementById('modal').classList.remove('hidden');
    document.getElementById('modal').classList.add('flex');
    document.getElementById('formAction').value = 'create';
    document.getElementById('modalTitle').textContent = 'เพิ่มสินค้า';
    resetForm();
}

function closeModal() {
    document.getElementById('modal').classList.add('hidden');
    document.getElementById('modal').classList.remove('flex');
}

function resetForm() {
    document.getElementById('formId').value = '';
    document.getElementById('name').value = '';
    document.getElementById('category_id').value = '';
    document.getElementById('price').value = '';
    document.getElementById('sale_price').value = '';
    document.getElementById('stock').value = '0';
    document.getElementById('description').value = '';
    document.getElementById('is_active').checked = true;
    document.getElementById('existing_image').value = '';
    document.getElementById('image').value = '';
    document.getElementById('imagePreview').innerHTML = '<i class="fas fa-image text-gray-300 text-2xl"></i>';
    uploadedImageUrl = '';
}

function editProduct(product) {
    openModal();
    document.getElementById('formAction').value = 'update';
    document.getElementById('formId').value = product.id;
    document.getElementById('modalTitle').textContent = 'แก้ไขสินค้า';
    document.getElementById('name').value = product.name;
    document.getElementById('category_id').value = product.category_id || '';
    document.getElementById('price').value = product.price;
    document.getElementById('sale_price').value = product.sale_price || '';
    document.getElementById('stock').value = product.stock;
    document.getElementById('description').value = product.description || '';
    document.getElementById('is_active').checked = product.is_active == 1;
    document.getElementById('existing_image').value = product.image_url || '';
    uploadedImageUrl = product.image_url || '';
    
    if (product.image_url) {
        document.getElementById('imagePreview').innerHTML = '<img src="' + product.image_url + '" class="w-full h-full object-cover">';
    }
}

function previewImage(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            document.getElementById('imagePreview').innerHTML = '<img src="' + e.target.result + '" class="w-full h-full object-cover">';
        };
        reader.readAsDataURL(input.files[0]);
    }
}

async function uploadImage() {
    const imageInput = document.getElementById('image');
    if (!imageInput.files || !imageInput.files[0]) {
        return document.getElementById('existing_image').value || '';
    }
    
    const formData = new FormData();
    formData.append('action', 'upload_product_image');
    formData.append('image', imageInput.files[0]);
    
    const res = await fetch('../api/ajax_handler.php', {
        method: 'POST',
        body: formData
    });
    const data = await res.json();
    
    if (data.success) {
        return data.image_url;
    }
    throw new Error(data.error || 'อัพโหลดรูปภาพไม่สำเร็จ');
}

async function saveProduct() {
    const btn = document.getElementById('btn-save');
    const action = document.getElementById('formAction').value;
    const name = document.getElementById('name').value.trim();
    const price = document.getElementById('price').value;
    
    if (!name || !price) {
        showToast('กรุณากรอกชื่อสินค้าและราคา', true);
        return;
    }
    
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>กำลังบันทึก...';
    
    try {
        // Upload image first if selected
        const imageUrl = await uploadImage();
        
        const formData = new FormData();
        formData.append('action', action === 'create' ? 'create_product' : 'update_product');
        formData.append('name', name);
        formData.append('price', price);
        formData.append('sale_price', document.getElementById('sale_price').value);
        formData.append('stock', document.getElementById('stock').value);
        formData.append('category_id', document.getElementById('category_id').value);
        formData.append('description', document.getElementById('description').value);
        formData.append('image_url', imageUrl);
        if (document.getElementById('is_active').checked) {
            formData.append('is_active', '1');
        }
        if (action === 'update') {
            formData.append('id', document.getElementById('formId').value);
        }
        
        const res = await fetch('../api/ajax_handler.php', {
            method: 'POST',
            body: formData
        });
        const data = await res.json();
        
        if (data.success) {
            showToast(data.message);
            closeModal();
            // Reload page to show updated data
            setTimeout(() => location.reload(), 500);
        } else {
            showToast(data.error || 'เกิดข้อผิดพลาด', true);
        }
    } catch (err) {
        showToast(err.message || 'เกิดข้อผิดพลาด', true);
    } finally {
        btn.disabled = false;
        btn.innerHTML = 'บันทึก';
    }
}

function deleteProduct(id) {
    if (!confirm('ยืนยันลบสินค้านี้?')) return;
    
    const formData = new FormData();
    formData.append('action', 'delete_product');
    formData.append('id', id);
    
    fetch('../api/ajax_handler.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            showToast(data.message);
            // Remove card from DOM
            const card = document.querySelector(`.product-card[data-id="${id}"]`);
            if (card) {
                card.remove();
                // Update count
                const countEl = document.getElementById('product-count');
                countEl.textContent = parseInt(countEl.textContent) - 1;
            }
        } else {
            showToast(data.error || 'เกิดข้อผิดพลาด', true);
        }
    })
    .catch(err => {
        showToast('เกิดข้อผิดพลาด: ' + err.message, true);
    });
}
</script>

<?php require_once '../includes/user_footer.php'; ?>
