<?php
/**
 * Admin Testimonials Management Tab
 * จัดการรีวิวจากลูกค้าสำหรับ Landing Page
 * 
 * Requirements: 10.4
 */

// Get filter status
$filterStatus = $_GET['status'] ?? 'all';

// Get testimonials based on filter
if ($filterStatus === 'all') {
    $testimonials = $testimonialService->getAllForAdmin();
} else {
    $testimonials = $testimonialService->getAllForAdmin($filterStatus);
}

// Get counts for badges
$pendingCount = $testimonialService->getPendingCount();
$approvedCount = $testimonialService->getTotalCount();
$avgRating = $testimonialService->getAverageRating();
$ratingDistribution = $testimonialService->getRatingDistribution();
?>

<div class="space-y-6">
    <!-- Stats Overview -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div class="bg-white rounded-xl p-4 shadow-sm">
            <div class="text-3xl font-bold text-yellow-500"><?= number_format($avgRating, 1) ?></div>
            <div class="text-sm text-gray-500">คะแนนเฉลี่ย</div>
            <div class="rating-stars text-sm mt-1">
                <?php for ($i = 1; $i <= 5; $i++): ?>
                    <i class="fas fa-star<?= $i <= round($avgRating) ? '' : '-o' ?>"></i>
                <?php endfor; ?>
            </div>
        </div>
        <div class="bg-white rounded-xl p-4 shadow-sm">
            <div class="text-3xl font-bold text-green-600"><?= $approvedCount ?></div>
            <div class="text-sm text-gray-500">รีวิวที่อนุมัติ</div>
        </div>
        <div class="bg-white rounded-xl p-4 shadow-sm">
            <div class="text-3xl font-bold text-orange-500"><?= $pendingCount ?></div>
            <div class="text-sm text-gray-500">รอตรวจสอบ</div>
        </div>
        <div class="bg-white rounded-xl p-4 shadow-sm">
            <div class="text-3xl font-bold text-gray-600"><?= count($testimonials) ?></div>
            <div class="text-sm text-gray-500">รีวิวทั้งหมด</div>
        </div>
    </div>

    <!-- Header with Filter & Add Button -->
    <div class="flex flex-col md:flex-row items-start md:items-center justify-between gap-4">
        <div class="flex items-center gap-2">
            <a href="?tab=testimonials&status=all" 
                class="px-3 py-1.5 rounded-lg text-sm <?= $filterStatus === 'all' ? 'bg-gray-800 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' ?>">
                ทั้งหมด
            </a>
            <a href="?tab=testimonials&status=pending" 
                class="px-3 py-1.5 rounded-lg text-sm <?= $filterStatus === 'pending' ? 'bg-orange-500 text-white' : 'bg-orange-50 text-orange-600 hover:bg-orange-100' ?>">
                รอตรวจสอบ <?php if ($pendingCount > 0): ?><span class="ml-1 px-1.5 py-0.5 bg-white/30 rounded"><?= $pendingCount ?></span><?php endif; ?>
            </a>
            <a href="?tab=testimonials&status=approved" 
                class="px-3 py-1.5 rounded-lg text-sm <?= $filterStatus === 'approved' ? 'bg-green-500 text-white' : 'bg-green-50 text-green-600 hover:bg-green-100' ?>">
                อนุมัติแล้ว
            </a>
            <a href="?tab=testimonials&status=rejected" 
                class="px-3 py-1.5 rounded-lg text-sm <?= $filterStatus === 'rejected' ? 'bg-red-500 text-white' : 'bg-red-50 text-red-600 hover:bg-red-100' ?>">
                ปฏิเสธ
            </a>
        </div>
        <button onclick="openTestimonialModal()" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 flex items-center gap-2">
            <i class="fas fa-plus"></i>
            เพิ่มรีวิว
        </button>
    </div>

    <!-- Testimonials List -->
    <div class="space-y-4">
        <?php if (empty($testimonials)): ?>
        <div class="bg-white rounded-xl p-12 text-center text-gray-400 shadow-sm">
            <i class="fas fa-star text-4xl mb-4"></i>
            <p>ยังไม่มีรีวิว<?= $filterStatus !== 'all' ? 'ในสถานะนี้' : '' ?></p>
            <button onclick="openTestimonialModal()" class="mt-4 text-blue-600 hover:underline">+ เพิ่มรีวิวแรก</button>
        </div>
        <?php else: ?>
        <?php foreach ($testimonials as $testimonial): ?>
        <div class="testimonial-card bg-white rounded-xl p-5 shadow-sm">
            <div class="flex items-start gap-4">
                <!-- Avatar -->
                <div class="w-12 h-12 rounded-full bg-gradient-to-br from-blue-400 to-purple-500 flex items-center justify-center text-white font-bold text-lg flex-shrink-0">
                    <?php if (!empty($testimonial['customer_avatar'])): ?>
                        <img src="<?= htmlspecialchars($testimonial['customer_avatar']) ?>" class="w-full h-full rounded-full object-cover">
                    <?php else: ?>
                        <?= mb_substr($testimonial['customer_name'], 0, 1) ?>
                    <?php endif; ?>
                </div>
                
                <!-- Content -->
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2 mb-1">
                        <h3 class="font-medium text-gray-800"><?= htmlspecialchars($testimonial['customer_name']) ?></h3>
                        <span class="badge-status badge-<?= $testimonial['status'] ?>">
                            <?php
                            $statusLabels = ['pending' => 'รอตรวจสอบ', 'approved' => 'อนุมัติ', 'rejected' => 'ปฏิเสธ'];
                            echo $statusLabels[$testimonial['status']] ?? $testimonial['status'];
                            ?>
                        </span>
                        <?php if ($testimonial['source']): ?>
                        <span class="text-xs text-gray-400"><?= htmlspecialchars($testimonial['source']) ?></span>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Rating -->
                    <div class="rating-stars text-sm mb-2">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <i class="fas fa-star<?= $i <= $testimonial['rating'] ? '' : '-o' ?>"></i>
                        <?php endfor; ?>
                    </div>
                    
                    <!-- Review Text -->
                    <p class="text-gray-600"><?= nl2br(htmlspecialchars($testimonial['review_text'])) ?></p>
                    
                    <!-- Meta -->
                    <div class="text-xs text-gray-400 mt-2">
                        สร้างเมื่อ <?= date('d/m/Y H:i', strtotime($testimonial['created_at'])) ?>
                        <?php if ($testimonial['approved_at']): ?>
                        • อนุมัติเมื่อ <?= date('d/m/Y H:i', strtotime($testimonial['approved_at'])) ?>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Actions -->
                <div class="flex items-center gap-1">
                    <?php if ($testimonial['status'] === 'pending'): ?>
                    <form method="POST" class="inline">
                        <input type="hidden" name="action" value="approve_testimonial">
                        <input type="hidden" name="id" value="<?= $testimonial['id'] ?>">
                        <button type="submit" class="p-2 text-green-600 hover:bg-green-50 rounded-lg" title="อนุมัติ">
                            <i class="fas fa-check"></i>
                        </button>
                    </form>
                    <form method="POST" class="inline">
                        <input type="hidden" name="action" value="reject_testimonial">
                        <input type="hidden" name="id" value="<?= $testimonial['id'] ?>">
                        <button type="submit" class="p-2 text-red-600 hover:bg-red-50 rounded-lg" title="ปฏิเสธ">
                            <i class="fas fa-times"></i>
                        </button>
                    </form>
                    <?php endif; ?>
                    
                    <button onclick="editTestimonial(<?= htmlspecialchars(json_encode($testimonial)) ?>)" 
                        class="p-2 text-gray-400 hover:text-blue-600 hover:bg-blue-50 rounded-lg" title="แก้ไข">
                        <i class="fas fa-edit"></i>
                    </button>
                    <button onclick="deleteTestimonial(<?= $testimonial['id'] ?>, '<?= htmlspecialchars(addslashes($testimonial['customer_name'])) ?>')" 
                        class="p-2 text-gray-400 hover:text-red-600 hover:bg-red-50 rounded-lg" title="ลบ">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Testimonial Modal -->
<div id="testimonialModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-xl w-full max-w-lg max-h-[90vh] overflow-y-auto">
        <form method="POST" id="testimonialForm">
            <input type="hidden" name="action" id="testimonialAction" value="create_testimonial">
            <input type="hidden" name="id" id="testimonialId" value="">
            
            <div class="p-6 border-b">
                <h3 class="text-lg font-bold" id="testimonialModalTitle">เพิ่มรีวิว</h3>
            </div>
            
            <div class="p-6 space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">ชื่อลูกค้า <span class="text-red-500">*</span></label>
                    <input type="text" name="customer_name" id="testimonialName" required
                        class="w-full px-4 py-3 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                        placeholder="เช่น คุณสมชาย">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">คะแนน <span class="text-red-500">*</span></label>
                    <div class="flex items-center gap-2" id="ratingSelector">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                        <button type="button" onclick="setRating(<?= $i ?>)" 
                            class="rating-btn text-3xl text-gray-300 hover:text-yellow-400 transition-colors" data-rating="<?= $i ?>">
                            <i class="fas fa-star"></i>
                        </button>
                        <?php endfor; ?>
                    </div>
                    <input type="hidden" name="rating" id="testimonialRating" value="5">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">ข้อความรีวิว <span class="text-red-500">*</span></label>
                    <textarea name="review_text" id="testimonialText" rows="4" required
                        class="w-full px-4 py-3 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                        placeholder="ความคิดเห็นของลูกค้า..."></textarea>
                </div>
            </div>
            
            <div class="p-6 border-t bg-gray-50 flex justify-end gap-3">
                <button type="button" onclick="closeTestimonialModal()" class="px-4 py-2 text-gray-600 hover:bg-gray-100 rounded-lg">
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
<form id="deleteTestimonialForm" method="POST" class="hidden">
    <input type="hidden" name="action" value="delete_testimonial">
    <input type="hidden" name="id" id="deleteTestimonialId">
</form>

<script>
function setRating(rating) {
    document.getElementById('testimonialRating').value = rating;
    document.querySelectorAll('.rating-btn').forEach((btn, index) => {
        if (index < rating) {
            btn.classList.remove('text-gray-300');
            btn.classList.add('text-yellow-400');
        } else {
            btn.classList.add('text-gray-300');
            btn.classList.remove('text-yellow-400');
        }
    });
}

function openTestimonialModal() {
    document.getElementById('testimonialAction').value = 'create_testimonial';
    document.getElementById('testimonialId').value = '';
    document.getElementById('testimonialName').value = '';
    document.getElementById('testimonialText').value = '';
    setRating(5);
    document.getElementById('testimonialModalTitle').textContent = 'เพิ่มรีวิว';
    document.getElementById('testimonialModal').classList.remove('hidden');
    document.getElementById('testimonialModal').classList.add('flex');
}

function editTestimonial(testimonial) {
    document.getElementById('testimonialAction').value = 'update_testimonial';
    document.getElementById('testimonialId').value = testimonial.id;
    document.getElementById('testimonialName').value = testimonial.customer_name;
    document.getElementById('testimonialText').value = testimonial.review_text;
    setRating(testimonial.rating);
    document.getElementById('testimonialModalTitle').textContent = 'แก้ไขรีวิว';
    document.getElementById('testimonialModal').classList.remove('hidden');
    document.getElementById('testimonialModal').classList.add('flex');
}

function closeTestimonialModal() {
    document.getElementById('testimonialModal').classList.add('hidden');
    document.getElementById('testimonialModal').classList.remove('flex');
}

function deleteTestimonial(id, name) {
    if (typeof Swal !== 'undefined') {
        Swal.fire({
            title: 'ยืนยันการลบ?',
            text: 'รีวิวจาก: ' + name,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc2626',
            cancelButtonColor: '#6b7280',
            confirmButtonText: 'ลบ',
            cancelButtonText: 'ยกเลิก'
        }).then((result) => {
            if (result.isConfirmed) {
                document.getElementById('deleteTestimonialId').value = id;
                document.getElementById('deleteTestimonialForm').submit();
            }
        });
    } else if (confirm('ต้องการลบรีวิวจาก "' + name + '" หรือไม่?')) {
        document.getElementById('deleteTestimonialId').value = id;
        document.getElementById('deleteTestimonialForm').submit();
    }
}

// Close modal on backdrop click
document.getElementById('testimonialModal').addEventListener('click', function(e) {
    if (e.target === this) closeTestimonialModal();
});

// Initialize rating on page load
setRating(5);
</script>
