<?php
/**
 * FAQ Section Component for Landing Page
 * 
 * Displays expandable accordion FAQ items with FAQPage schema.
 * Hides section if no FAQs are configured.
 * 
 * Requirements: 4.1, 4.3, 4.4, 4.5
 * 
 * Usage:
 *   require_once 'classes/FAQService.php';
 *   $faqService = new FAQService($db, $lineAccountId);
 *   include 'includes/landing/faq-section.php';
 * 
 * Expected variables:
 *   $faqService - Instance of FAQService
 */

// Ensure $faqService is available
if (!isset($faqService) || !($faqService instanceof FAQService)) {
    return;
}

// Get active FAQs (Requirements: 4.5 - min 3, max 10)
$faqs = $faqService->getActiveFAQs(10);

// Hide section if no FAQs (Requirements: 4.4)
if (empty($faqs)) {
    return;
}
?>

<!-- FAQ Section (Requirements: 4.1, 4.3, 4.4, 4.5) -->
<section class="faq-section" id="faq">
    <div class="container">
        <div class="section-title">
            <h2>คำถามที่พบบ่อย</h2>
            <p>คำตอบสำหรับคำถามที่ลูกค้าถามบ่อย</p>
        </div>
        
        <div class="faq-accordion">
            <?php foreach ($faqs as $index => $faq): ?>
            <div class="faq-item" data-faq-id="<?= (int)$faq['id'] ?>">
                <button class="faq-question" 
                        aria-expanded="false" 
                        aria-controls="faq-answer-<?= (int)$faq['id'] ?>"
                        onclick="toggleFaq(this)">
                    <span class="faq-question-text"><?= htmlspecialchars($faq['question']) ?></span>
                    <span class="faq-icon">
                        <i class="fas fa-chevron-down"></i>
                    </span>
                </button>
                <div class="faq-answer" 
                     id="faq-answer-<?= (int)$faq['id'] ?>" 
                     role="region"
                     aria-hidden="true">
                    <div class="faq-answer-content">
                        <?= nl2br(htmlspecialchars($faq['answer'])) ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<style>
/* FAQ Section Styles */
.faq-section {
    padding: 48px 0;
    background: #F8FAFC;
}

.faq-accordion {
    max-width: 800px;
    margin: 0 auto;
}

.faq-item {
    background: white;
    border-radius: 12px;
    margin-bottom: 12px;
    overflow: hidden;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    border: 1px solid #E5E7EB;
    transition: all 0.2s ease;
}

.faq-item:hover {
    border-color: var(--primary, #06C755);
}

.faq-item.active {
    border-color: var(--primary, #06C755);
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);
}

.faq-question {
    width: 100%;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    padding: 20px 24px;
    background: none;
    border: none;
    cursor: pointer;
    text-align: left;
    font-family: inherit;
    font-size: 1rem;
    font-weight: 600;
    color: #1F2937;
    transition: all 0.2s ease;
}

.faq-question:hover {
    background: #F8FAFC;
}

.faq-question:focus {
    outline: none;
    background: #F8FAFC;
}

.faq-question:focus-visible {
    outline: 2px solid var(--primary, #06C755);
    outline-offset: -2px;
}

.faq-question-text {
    flex: 1;
    line-height: 1.4;
}

.faq-icon {
    width: 24px;
    height: 24px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #9CA3AF;
    transition: transform 0.3s ease;
    flex-shrink: 0;
}

.faq-item.active .faq-icon {
    transform: rotate(180deg);
    color: var(--primary, #06C755);
}

.faq-answer {
    max-height: 0;
    overflow: hidden;
    transition: max-height 0.3s ease-out;
}

.faq-item.active .faq-answer {
    max-height: 500px;
    transition: max-height 0.5s ease-in;
}

.faq-answer-content {
    padding: 0 24px 20px;
    color: #4B5563;
    font-size: 0.95rem;
    line-height: 1.7;
}

/* Responsive Design */
@media (max-width: 767px) {
    .faq-section {
        padding: 32px 0;
    }
    
    .faq-question {
        padding: 16px 18px;
        font-size: 0.95rem;
    }
    
    .faq-answer-content {
        padding: 0 18px 16px;
        font-size: 0.9rem;
    }
}

@media (min-width: 768px) {
    .faq-section {
        padding: 64px 0;
    }
    
    .faq-item {
        margin-bottom: 16px;
    }
}

@media (min-width: 1024px) {
    .faq-section {
        padding: 80px 0;
    }
}

/* Reduced Motion */
@media (prefers-reduced-motion: reduce) {
    .faq-answer,
    .faq-icon {
        transition: none;
    }
}
</style>

<script>
/**
 * Toggle FAQ accordion item
 * Requirements: 4.2 - Expand to show answer with smooth animation
 */
function toggleFaq(button) {
    const faqItem = button.closest('.faq-item');
    const isActive = faqItem.classList.contains('active');
    const answer = faqItem.querySelector('.faq-answer');
    
    // Close all other FAQ items (optional: remove for multi-open)
    document.querySelectorAll('.faq-item.active').forEach(item => {
        if (item !== faqItem) {
            item.classList.remove('active');
            item.querySelector('.faq-question').setAttribute('aria-expanded', 'false');
            item.querySelector('.faq-answer').setAttribute('aria-hidden', 'true');
        }
    });
    
    // Toggle current item
    if (isActive) {
        faqItem.classList.remove('active');
        button.setAttribute('aria-expanded', 'false');
        answer.setAttribute('aria-hidden', 'true');
    } else {
        faqItem.classList.add('active');
        button.setAttribute('aria-expanded', 'true');
        answer.setAttribute('aria-hidden', 'false');
    }
}

// Initialize FAQ - open first item by default (optional)
document.addEventListener('DOMContentLoaded', function() {
    // Uncomment to auto-open first FAQ
    // const firstFaq = document.querySelector('.faq-item');
    // if (firstFaq) {
    //     firstFaq.classList.add('active');
    //     firstFaq.querySelector('.faq-question').setAttribute('aria-expanded', 'true');
    //     firstFaq.querySelector('.faq-answer').setAttribute('aria-hidden', 'false');
    // }
});
</script>
