<?php
/**
 * Admin FAQ Management Tab
 * จัดการคำถามที่พบบ่อยสำหรับ Landing Page
 * 
 * Requirements: 10.3
 */

// Get all FAQs for admin
$faqs = $faqService->getAllForAdmin();
$editFaq = null;

// Check if editing
if (isset($_GET['edit_faq'])) {
    $editFaq = $faqService->getById((int)$_GET['edit_faq']);
}
?>

<div class="space-y-6">
    <!-- Header with Add Button -->
    <div class="flex items-center justify-between">
        <div>
            <h2 class="text-xl font-bold text-gray-800">จัดการคำถามที่พบบ่อย (FAQ)</h2>
            <p class="text-gray-500 text-sm mt-1">เพิ่ม แก้ไข หรือลบคำถามที่พบบ่อยสำหรับหน้า Landing Page</p>
        </div>
        <button onclick="openFaqModal()" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 flex items-center gap-2">
            <i class="fas fa-plus"></i>
            เพิ่มคำถาม
        </button>
    </div>

    <!-- FAQ List with Drag & Drop -->
    <div class="bg-white rounded-xl shadow-sm overflow-hidden">
        <div class="p-4 bg-gray-50 border-b flex items-center gap-2 text-sm text-gray-600">
            <i class="fas fa-grip-vertical"></i>
            ลากเพื่อจัดเรียงลำดับ
        </div>
        
        <?php if (empty($faqs)): ?>
        <div class="p-12 text-center text-gray-400">
            <i class="fas fa-question-circle text-4xl mb-4"></i>
            <p>ยังไม่มีคำถามที่พบบ่อย</p>
            <button onclick="openFaqModal()" class="mt-4 text-blue-600 hover:underline">+ เพิ่มคำถามแรก</button>
        </div>
        <?php else: ?>
        <div id="faqList" class="divide-y">
            <?php foreach ($faqs as $faq): ?>
            <div class="faq-item p-4 flex items-start gap-4 hover:bg-gray-50" data-id="<?= $faq['id'] ?>">
                <div class="drag-handle text-gray-400 cursor-grab pt-1">
                    <i class="fas fa-grip-vertical"></i>
                </div>
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2 mb-1">
                        <h3 class="font-medium text-gray-800 truncate"><?= htmlspecialchars($faq['question']) ?></h3>
                        <?php if (!$faq['is_active']): ?>
                        <span class="px-2 py-0.5 bg-gray-200 text-gray-600 text-xs rounded">ซ่อน</span>
                        <?php endif; ?>
                    </div>
                    <p class="text-sm text-gray-500 line-clamp-2"><?= htmlspecialchars($faq['answer']) ?></p>
                </div>
                <div class="flex items-center gap-2">
                    <button onclick="editFaq(<?= htmlspecialchars(json_encode($faq)) ?>)" 
                        class="p-2 text-gray-400 hover:text-blue-600 hover:bg-blue-50 rounded-lg" title="แก้ไข">
                        <i class="fas fa-edit"></i>
                    </button>
                    <button onclick="deleteFaq(<?= $faq['id'] ?>, '<?= htmlspecialchars(addslashes($faq['question'])) ?>')" 
                        class="p-2 text-gray-400 hover:text-red-600 hover:bg-red-50 rounded-lg" title="ลบ">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- FAQ Modal -->
<div id="faqModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-xl w-full max-w-lg max-h-[90vh] overflow-y-auto">
        <form method="POST" id="faqForm">
            <input type="hidden" name="action" id="faqAction" value="create_faq">
            <input type="hidden" name="id" id="faqId" value="">
            
            <div class="p-6 border-b">
                <h3 class="text-lg font-bold" id="faqModalTitle">เพิ่มคำถามที่พบบ่อย</h3>
            </div>
            
            <div class="p-6 space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">คำถาม <span class="text-red-500">*</span></label>
                    <input type="text" name="question" id="faqQuestion" required
                        class="w-full px-4 py-3 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                        placeholder="เช่น ร้านยาเปิดให้บริการเวลาใด?">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">คำตอบ <span class="text-red-500">*</span></label>
                    <textarea name="answer" id="faqAnswer" rows="4" required
                        class="w-full px-4 py-3 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                        placeholder="คำตอบสำหรับคำถามนี้..."></textarea>
                </div>
                
                <div class="flex items-center gap-2">
                    <input type="checkbox" name="is_active" id="faqActive" value="1" checked
                        class="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500">
                    <label for="faqActive" class="text-sm text-gray-700">แสดงบนหน้าเว็บ</label>
                </div>
            </div>
            
            <div class="p-6 border-t bg-gray-50 flex justify-end gap-3">
                <button type="button" onclick="closeFaqModal()" class="px-4 py-2 text-gray-600 hover:bg-gray-100 rounded-lg">
                    ยกเลิก
                </button>
                <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                    <i class="fas fa-save mr-1"></i>
                    บันทึก
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Confirmation Form -->
<form id="deleteFaqForm" method="POST" class="hidden">
    <input type="hidden" name="action" value="delete_faq">
    <input type="hidden" name="id" id="deleteFaqId">
</form>

<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
<script>
// Initialize Sortable for drag & drop
document.addEventListener('DOMContentLoaded', function() {
    const faqList = document.getElementById('faqList');
    if (faqList) {
        new Sortable(faqList, {
            handle: '.drag-handle',
            animation: 150,
            ghostClass: 'dragging',
            onEnd: function() {
                const ids = Array.from(faqList.querySelectorAll('.faq-item')).map(el => el.dataset.id);
                
                // Save new order via AJAX
                fetch(window.location.href, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=reorder_faq&ids=' + encodeURIComponent(JSON.stringify(ids))
                }).then(res => res.json()).then(data => {
                    if (data.success) {
                        // Show brief success indicator
                        const toast = document.createElement('div');
                        toast.className = 'fixed bottom-4 right-4 bg-green-500 text-white px-4 py-2 rounded-lg shadow-lg z-50';
                        toast.innerHTML = '<i class="fas fa-check mr-2"></i>บันทึกลำดับแล้ว';
                        document.body.appendChild(toast);
                        setTimeout(() => toast.remove(), 2000);
                    }
                });
            }
        });
    }
});

function openFaqModal() {
    document.getElementById('faqAction').value = 'create_faq';
    document.getElementById('faqId').value = '';
    document.getElementById('faqQuestion').value = '';
    document.getElementById('faqAnswer').value = '';
    document.getElementById('faqActive').checked = true;
    document.getElementById('faqModalTitle').textContent = 'เพิ่มคำถามที่พบบ่อย';
    document.getElementById('faqModal').classList.remove('hidden');
    document.getElementById('faqModal').classList.add('flex');
}

function editFaq(faq) {
    document.getElementById('faqAction').value = 'update_faq';
    document.getElementById('faqId').value = faq.id;
    document.getElementById('faqQuestion').value = faq.question;
    document.getElementById('faqAnswer').value = faq.answer;
    document.getElementById('faqActive').checked = faq.is_active == 1;
    document.getElementById('faqModalTitle').textContent = 'แก้ไขคำถามที่พบบ่อย';
    document.getElementById('faqModal').classList.remove('hidden');
    document.getElementById('faqModal').classList.add('flex');
}

function closeFaqModal() {
    document.getElementById('faqModal').classList.add('hidden');
    document.getElementById('faqModal').classList.remove('flex');
}

function deleteFaq(id, question) {
    if (typeof Swal !== 'undefined') {
        Swal.fire({
            title: 'ยืนยันการลบ?',
            text: 'คำถาม: ' + question,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc2626',
            cancelButtonColor: '#6b7280',
            confirmButtonText: 'ลบ',
            cancelButtonText: 'ยกเลิก'
        }).then((result) => {
            if (result.isConfirmed) {
                document.getElementById('deleteFaqId').value = id;
                document.getElementById('deleteFaqForm').submit();
            }
        });
    } else if (confirm('ต้องการลบคำถาม "' + question + '" หรือไม่?')) {
        document.getElementById('deleteFaqId').value = id;
        document.getElementById('deleteFaqForm').submit();
    }
}

// Close modal on backdrop click
document.getElementById('faqModal').addEventListener('click', function(e) {
    if (e.target === this) closeFaqModal();
});
</script>
