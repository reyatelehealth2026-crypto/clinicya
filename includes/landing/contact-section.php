<?php
/**
 * Contact Section Component for Landing Page
 * 
 * Displays contact information with:
 * - Operating hours (Requirements: 7.1)
 * - Clickable phone and LINE links (Requirements: 7.2, 7.3)
 * - Google Map embed (Requirements: 7.4)
 * - Full address display (Requirements: 7.5)
 * 
 * Required variables:
 * - $db: PDO database connection
 * - $lineAccountId: LINE account ID
 * - $contactPhone: Phone number
 * - $lineId: LINE ID
 * - $shopEmail: Email address
 * - $shopAddress: Shop address
 */

// Get landing settings for operating hours and map
$operatingHours = null;
$googleMapEmbed = null;
$latitude = null;
$longitude = null;

try {
    $stmt = $db->prepare("SELECT setting_key, setting_value FROM landing_settings WHERE line_account_id = ? OR line_account_id IS NULL");
    $stmt->execute([$lineAccountId]);
    $landingSettings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    $operatingHours = isset($landingSettings['operating_hours']) ? json_decode($landingSettings['operating_hours'], true) : null;
    $googleMapEmbed = $landingSettings['google_map_embed'] ?? null;
    $latitude = $landingSettings['latitude'] ?? null;
    $longitude = $landingSettings['longitude'] ?? null;
} catch (Exception $e) {
    // Settings table might not exist
}

// Day names in Thai
$dayNames = [
    'mon' => 'จันทร์',
    'tue' => 'อังคาร',
    'wed' => 'พุธ',
    'thu' => 'พฤหัสบดี',
    'fri' => 'ศุกร์',
    'sat' => 'เสาร์',
    'sun' => 'อาทิตย์'
];

/**
 * Format operating hours for display
 * @param array|null $hours Operating hours array
 * @param array $dayNames Day name translations
 * @return array Formatted hours array
 */
function formatOperatingHours(?array $hours, array $dayNames): array {
    if (empty($hours) || !is_array($hours)) {
        return [];
    }
    
    $formatted = [];
    foreach ($dayNames as $key => $name) {
        if (isset($hours[$key])) {
            $time = $hours[$key];
            if ($time === 'closed' || empty($time)) {
                $formatted[] = ['day' => $name, 'time' => 'ปิดทำการ', 'closed' => true];
            } else {
                $formatted[] = ['day' => $name, 'time' => $time, 'closed' => false];
            }
        }
    }
    return $formatted;
}

$formattedHours = formatOperatingHours($operatingHours, $dayNames);

// Check if we have any contact info to display
$hasContactInfo = !empty($contactPhone) || !empty($lineId) || !empty($shopEmail) || !empty($shopAddress);
$hasOperatingHours = !empty($formattedHours);
$hasMap = !empty($googleMapEmbed) || (!empty($latitude) && !empty($longitude));

// Only render if there's something to show
if (!$hasContactInfo && !$hasOperatingHours && !$hasMap) {
    return;
}
?>

<!-- Contact Section (Requirements: 7.1, 7.2, 7.3, 7.4, 7.5) -->
<section class="contact-section" id="contact">
    <div class="container">
        <div class="section-title">
            <h2>ติดต่อเรา</h2>
            <p>พร้อมให้บริการทุกวัน</p>
        </div>
        
        <div class="contact-content">
            <!-- Contact Info Grid -->
            <div class="contact-grid">
                <?php if ($contactPhone): ?>
                <!-- Phone (Requirements: 7.2) -->
                <div class="contact-item">
                    <div class="contact-icon">
                        <i class="fas fa-phone"></i>
                    </div>
                    <div class="contact-info">
                        <h4>โทรศัพท์</h4>
                        <p><a href="tel:<?= htmlspecialchars(preg_replace('/[^0-9+]/', '', $contactPhone)) ?>" class="contact-link"><?= htmlspecialchars($contactPhone) ?></a></p>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if ($lineId): ?>
                <!-- LINE (Requirements: 7.3) -->
                <div class="contact-item">
                    <div class="contact-icon contact-icon-line">
                        <i class="fab fa-line"></i>
                    </div>
                    <div class="contact-info">
                        <h4>LINE</h4>
                        <p><a href="https://line.me/R/ti/p/<?= htmlspecialchars(ltrim($lineId, '@')) ?>" target="_blank" rel="noopener" class="contact-link contact-link-line"><?= htmlspecialchars($lineId) ?></a></p>
                        <a href="https://line.me/R/ti/p/<?= htmlspecialchars(ltrim($lineId, '@')) ?>" target="_blank" rel="noopener" class="btn-add-line">
                            <i class="fab fa-line"></i> เพิ่มเพื่อน
                        </a>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if ($shopEmail): ?>
                <!-- Email -->
                <div class="contact-item">
                    <div class="contact-icon">
                        <i class="fas fa-envelope"></i>
                    </div>
                    <div class="contact-info">
                        <h4>อีเมล</h4>
                        <p><a href="mailto:<?= htmlspecialchars($shopEmail) ?>" class="contact-link"><?= htmlspecialchars($shopEmail) ?></a></p>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if ($shopAddress): ?>
                <!-- Address (Requirements: 7.5) -->
                <div class="contact-item">
                    <div class="contact-icon">
                        <i class="fas fa-map-marker-alt"></i>
                    </div>
                    <div class="contact-info">
                        <h4>ที่อยู่</h4>
                        <p><?= nl2br(htmlspecialchars($shopAddress)) ?></p>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <?php if ($hasOperatingHours): ?>
            <!-- Operating Hours (Requirements: 7.1) -->
            <div class="operating-hours-section">
                <div class="operating-hours-card">
                    <div class="operating-hours-header">
                        <i class="fas fa-clock"></i>
                        <h4>เวลาทำการ</h4>
                    </div>
                    <div class="operating-hours-list">
                        <?php foreach ($formattedHours as $hour): ?>
                        <div class="operating-hours-item <?= $hour['closed'] ? 'closed' : '' ?>">
                            <span class="day-name"><?= htmlspecialchars($hour['day']) ?></span>
                            <span class="day-time"><?= htmlspecialchars($hour['time']) ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if ($hasMap): ?>
            <!-- Google Map (Requirements: 7.4) -->
            <div class="map-section">
                <?php if (!empty($googleMapEmbed)): ?>
                <!-- Embedded Map URL -->
                <div class="map-container">
                    <iframe 
                        src="<?= htmlspecialchars($googleMapEmbed) ?>" 
                        width="100%" 
                        height="300" 
                        style="border:0;" 
                        allowfullscreen="" 
                        loading="lazy" 
                        referrerpolicy="no-referrer-when-downgrade"
                        title="แผนที่ร้าน">
                    </iframe>
                </div>
                <?php elseif (!empty($latitude) && !empty($longitude)): ?>
                <!-- Map from coordinates -->
                <div class="map-container">
                    <iframe 
                        src="https://maps.google.com/maps?q=<?= htmlspecialchars($latitude) ?>,<?= htmlspecialchars($longitude) ?>&z=15&output=embed" 
                        width="100%" 
                        height="300" 
                        style="border:0;" 
                        allowfullscreen="" 
                        loading="lazy" 
                        referrerpolicy="no-referrer-when-downgrade"
                        title="แผนที่ร้าน">
                    </iframe>
                </div>
                <div class="map-actions">
                    <a href="https://www.google.com/maps/search/?api=1&query=<?= htmlspecialchars($latitude) ?>,<?= htmlspecialchars($longitude) ?>" 
                       target="_blank" 
                       rel="noopener" 
                       class="btn-directions">
                        <i class="fas fa-directions"></i> เปิดใน Google Maps
                    </a>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<style>
/* Contact Section Enhanced Styles */
.contact-content {
    display: flex;
    flex-direction: column;
    gap: 32px;
}

.contact-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 24px;
}

.contact-item {
    display: flex;
    align-items: flex-start;
    gap: 16px;
}

.contact-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    background: var(--primary-light);
    color: var(--primary);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    flex-shrink: 0;
}

.contact-icon-line {
    background: rgba(6, 199, 85, 0.1);
    color: #06C755;
}

.contact-info h4 {
    font-size: 1rem;
    margin-bottom: 4px;
    color: #1F2937;
}

.contact-info p {
    color: #6B7280;
    font-size: 0.9rem;
    margin: 0;
}

.contact-link {
    color: var(--primary);
    transition: color 0.2s;
}

.contact-link:hover {
    color: var(--primary-dark);
    text-decoration: underline;
}

.contact-link-line {
    color: #06C755;
}

.contact-link-line:hover {
    color: #05B04C;
}

.btn-add-line {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    margin-top: 8px;
    padding: 6px 12px;
    background: #06C755;
    color: white;
    border-radius: 6px;
    font-size: 0.85rem;
    font-weight: 500;
    transition: all 0.2s;
}

.btn-add-line:hover {
    background: #05B04C;
    transform: translateY(-1px);
}

/* Operating Hours Styles (Requirements: 7.1) */
.operating-hours-section {
    margin-top: 8px;
}

.operating-hours-card {
    background: #F8FAFC;
    border-radius: 16px;
    padding: 24px;
    border: 1px solid #E5E7EB;
}

.operating-hours-header {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 16px;
    padding-bottom: 12px;
    border-bottom: 1px solid #E5E7EB;
}

.operating-hours-header i {
    font-size: 20px;
    color: var(--primary);
}

.operating-hours-header h4 {
    font-size: 1.1rem;
    margin: 0;
    color: #1F2937;
}

.operating-hours-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.operating-hours-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 0;
    border-bottom: 1px dashed #E5E7EB;
}

.operating-hours-item:last-child {
    border-bottom: none;
}

.operating-hours-item .day-name {
    font-weight: 500;
    color: #374151;
}

.operating-hours-item .day-time {
    color: #059669;
    font-weight: 500;
}

.operating-hours-item.closed .day-time {
    color: #DC2626;
}

/* Map Styles (Requirements: 7.4) */
.map-section {
    margin-top: 8px;
}

.map-container {
    border-radius: 16px;
    overflow: hidden;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

.map-container iframe {
    display: block;
}

.map-actions {
    margin-top: 12px;
    text-align: center;
}

.btn-directions {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 20px;
    background: var(--primary);
    color: white;
    border-radius: 8px;
    font-size: 0.9rem;
    font-weight: 500;
    transition: all 0.2s;
}

.btn-directions:hover {
    background: var(--primary-dark);
    transform: translateY(-1px);
}

/* Responsive Styles */
@media (min-width: 768px) {
    .contact-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 32px;
    }
    
    .contact-content {
        gap: 40px;
    }
    
    .operating-hours-card {
        max-width: 500px;
    }
    
    .map-container iframe {
        height: 350px;
    }
}

@media (min-width: 1024px) {
    .contact-grid {
        grid-template-columns: repeat(4, 1fr);
    }
    
    .contact-content {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 40px;
    }
    
    .contact-grid {
        grid-column: 1 / -1;
    }
    
    .operating-hours-section {
        grid-column: 1;
    }
    
    .map-section {
        grid-column: 2;
    }
    
    .map-container iframe {
        height: 300px;
    }
}
</style>
