<?php
/**
 * Trust Badges Component for Landing Page
 * 
 * Displays license, customer count, rating, and years of operation.
 * Handles missing data gracefully by hiding badges without data.
 * 
 * Requirements: 3.1, 3.2, 3.3, 3.4, 3.5
 * 
 * Usage:
 *   require_once 'classes/TrustBadgeService.php';
 *   $trustBadgeService = new TrustBadgeService($db, $lineAccountId);
 *   include 'includes/landing/trust-badges.php';
 * 
 * Expected variables:
 *   $trustBadgeService - Instance of TrustBadgeService
 *   $primaryColor - Primary theme color (optional)
 */

// Ensure $trustBadgeService is available
if (!isset($trustBadgeService) || !($trustBadgeService instanceof TrustBadgeService)) {
    return;
}

// Get badges - gracefully handles missing data (Requirements: 3.5)
$badges = $trustBadgeService->getBadges();

// Don't render section if no badges available
if (empty($badges)) {
    return;
}

// Icon mapping for Font Awesome
$iconMap = [
    'shield-check' => 'fa-shield-alt',
    'users' => 'fa-users',
    'shopping-bag' => 'fa-shopping-bag',
    'star' => 'fa-star',
    'award' => 'fa-award'
];
?>

<!-- Trust Badges Section (Requirements: 3.1, 3.2, 3.3, 3.4, 3.5) -->
<section class="trust-badges-section">
    <div class="container">
        <div class="trust-badges-grid">
            <?php foreach ($badges as $badge): ?>
            <div class="trust-badge" data-type="<?= htmlspecialchars($badge['type']) ?>">
                <div class="trust-badge-icon">
                    <i class="fas <?= htmlspecialchars($iconMap[$badge['icon']] ?? 'fa-check-circle') ?>"></i>
                </div>
                <div class="trust-badge-content">
                    <div class="trust-badge-value"><?= htmlspecialchars($badge['value']) ?></div>
                    <div class="trust-badge-label"><?= htmlspecialchars($badge['label']) ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<style>
/* Trust Badges Section Styles */
.trust-badges-section {
    padding: 24px 0;
    background: white;
    border-bottom: 1px solid #E5E7EB;
}

.trust-badges-grid {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: 16px;
}

.trust-badge {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 20px;
    background: #F8FAFC;
    border-radius: 12px;
    border: 1px solid #E5E7EB;
    transition: all 0.2s ease;
}

.trust-badge:hover {
    border-color: var(--primary, #06C755);
    box-shadow: 0 4px 12px rgba(0,0,0,0.05);
}

.trust-badge-icon {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    background: var(--primary-light, #E8F5E9);
    color: var(--primary, #06C755);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    flex-shrink: 0;
}

.trust-badge-content {
    text-align: left;
}

.trust-badge-value {
    font-size: 1.25rem;
    font-weight: 700;
    color: #1F2937;
    line-height: 1.2;
}

.trust-badge-label {
    font-size: 0.8rem;
    color: #6B7280;
    line-height: 1.3;
}

/* Badge type specific colors */
.trust-badge[data-type="license"] .trust-badge-icon {
    background: #DBEAFE;
    color: #2563EB;
}

.trust-badge[data-type="rating"] .trust-badge-icon {
    background: #FEF3C7;
    color: #D97706;
}

.trust-badge[data-type="experience"] .trust-badge-icon {
    background: #F3E8FF;
    color: #7C3AED;
}

/* Responsive Design */
@media (max-width: 767px) {
    .trust-badges-section {
        padding: 16px 0;
    }
    
    .trust-badges-grid {
        gap: 12px;
    }
    
    .trust-badge {
        padding: 10px 14px;
        flex: 1 1 calc(50% - 6px);
        min-width: 140px;
        max-width: calc(50% - 6px);
    }
    
    .trust-badge-icon {
        width: 36px;
        height: 36px;
        font-size: 16px;
    }
    
    .trust-badge-value {
        font-size: 1.1rem;
    }
    
    .trust-badge-label {
        font-size: 0.75rem;
    }
}

@media (min-width: 768px) {
    .trust-badges-section {
        padding: 32px 0;
    }
    
    .trust-badges-grid {
        gap: 24px;
    }
    
    .trust-badge {
        padding: 16px 24px;
    }
}

@media (min-width: 1024px) {
    .trust-badges-grid {
        gap: 32px;
    }
}
</style>
