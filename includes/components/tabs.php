<?php
/**
 * Tab Component - Reusable Tab-based UI
 * สำหรับหน้าที่รวมหลายฟีเจอร์เป็นหน้าเดียว
 * 
 * @package FileConsolidation
 * @version 1.0.0
 */

/**
 * Render tab navigation
 * 
 * @param array $tabs Array of tabs with keys: label, icon (optional), badge (optional)
 * @param string $activeTab Current active tab key
 * @param array $options Optional settings: style (pills|underline), size (sm|md|lg)
 * @return string HTML output
 * 
 * Example usage:
 * $tabs = [
 *     'overview' => ['label' => 'ภาพรวม', 'icon' => 'fas fa-chart-line'],
 *     'advanced' => ['label' => 'วิเคราะห์ขั้นสูง', 'icon' => 'fas fa-chart-bar'],
 *     'crm' => ['label' => 'CRM', 'icon' => 'fas fa-users', 'badge' => 5],
 * ];
 * echo renderTabs($tabs, 'overview');
 */
function renderTabs($tabs, $activeTab, $options = []) {
    $style = $options['style'] ?? 'pills'; // pills, underline
    $size = $options['size'] ?? 'md'; // sm, md, lg
    $preserveParams = $options['preserveParams'] ?? []; // Query params to preserve
    
    // Build base URL with preserved params
    $baseUrl = strtok($_SERVER['REQUEST_URI'], '?');
    $preservedQuery = '';
    if (!empty($preserveParams)) {
        $params = [];
        foreach ($preserveParams as $param) {
            if (isset($_GET[$param])) {
                $params[$param] = $_GET[$param];
            }
        }
        if (!empty($params)) {
            $preservedQuery = '&' . http_build_query($params);
        }
    }
    
    $html = '<div class="tabs-component">';
    $html .= '<div class="tabs-nav tabs-' . htmlspecialchars($style) . ' tabs-' . htmlspecialchars($size) . '">';
    
    foreach ($tabs as $key => $tab) {
        $isActive = ($key === $activeTab);
        $activeClass = $isActive ? 'active' : '';
        $label = htmlspecialchars($tab['label'] ?? $key);
        $icon = $tab['icon'] ?? '';
        $badge = $tab['badge'] ?? null;
        $disabled = $tab['disabled'] ?? false;
        
        $href = $disabled ? '#' : $baseUrl . '?tab=' . urlencode($key) . $preservedQuery;
        $disabledClass = $disabled ? 'disabled' : '';
        
        $html .= '<a href="' . $href . '" class="tab-item ' . $activeClass . ' ' . $disabledClass . '"';
        if ($disabled) {
            $html .= ' onclick="return false;"';
        }
        $html .= '>';
        
        if ($icon) {
            $html .= '<i class="' . htmlspecialchars($icon) . ' tab-icon"></i>';
        }
        
        $html .= '<span class="tab-label">' . $label . '</span>';
        
        if ($badge !== null && $badge > 0) {
            $badgeColor = $tab['badgeColor'] ?? 'red';
            $html .= '<span class="tab-badge badge-' . htmlspecialchars($badgeColor) . '">' . intval($badge) . '</span>';
        }
        
        $html .= '</a>';
    }
    
    $html .= '</div>';
    $html .= '</div>';
    
    return $html;
}

/**
 * Get CSS styles for tabs component
 * Include this once in the page head or before using tabs
 * 
 * @return string CSS styles
 */
function getTabsStyles() {
    return <<<CSS
<style>
/* Tab Component Styles */
.tabs-component {
    margin-bottom: 24px;
}

.tabs-nav {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    padding: 4px;
    background: #f1f5f9;
    border-radius: 12px;
}

/* Pills Style (Default) */
.tabs-pills .tab-item {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 16px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 500;
    color: #64748b;
    text-decoration: none;
    transition: all 0.2s ease;
    white-space: nowrap;
}

.tabs-pills .tab-item:hover {
    color: #1e293b;
    background: rgba(255, 255, 255, 0.5);
}

.tabs-pills .tab-item.active {
    background: white;
    color: #7c3aed;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}

.tabs-pills .tab-item.disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

/* Underline Style */
.tabs-underline {
    background: transparent;
    border-bottom: 2px solid #e2e8f0;
    border-radius: 0;
    padding: 0;
    gap: 0;
}

.tabs-underline .tab-item {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 12px 20px;
    font-size: 14px;
    font-weight: 500;
    color: #64748b;
    text-decoration: none;
    border-bottom: 2px solid transparent;
    margin-bottom: -2px;
    transition: all 0.2s ease;
}

.tabs-underline .tab-item:hover {
    color: #7c3aed;
}

.tabs-underline .tab-item.active {
    color: #7c3aed;
    border-bottom-color: #7c3aed;
}

/* Size Variants */
.tabs-sm .tab-item {
    padding: 8px 12px;
    font-size: 13px;
}

.tabs-lg .tab-item {
    padding: 12px 20px;
    font-size: 15px;
}

/* Tab Icon */
.tab-icon {
    font-size: 14px;
    opacity: 0.8;
}

.tab-item.active .tab-icon {
    opacity: 1;
}

/* Tab Badge */
.tab-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 20px;
    height: 20px;
    padding: 0 6px;
    font-size: 11px;
    font-weight: 600;
    border-radius: 10px;
    color: white;
}

.badge-red { background: #ef4444; }
.badge-yellow { background: #f59e0b; }
.badge-green { background: #22c55e; }
.badge-blue { background: #3b82f6; }
.badge-purple { background: #7c3aed; }
.badge-gray { background: #64748b; }

/* Responsive */
@media (max-width: 640px) {
    .tabs-nav {
        overflow-x: auto;
        flex-wrap: nowrap;
        -webkit-overflow-scrolling: touch;
        scrollbar-width: none;
        -ms-overflow-style: none;
    }
    
    .tabs-nav::-webkit-scrollbar {
        display: none;
    }
    
    .tab-item {
        flex-shrink: 0;
    }
    
    .tab-label {
        display: none;
    }
    
    .tab-item .tab-icon {
        margin-right: 0;
    }
    
    /* Show label on active tab */
    .tab-item.active .tab-label {
        display: inline;
    }
}

/* Tab Content Container */
.tab-content {
    background: white;
    border-radius: 12px;
    padding: 24px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
}

/* Tab Panel Animation */
.tab-panel {
    animation: tabFadeIn 0.3s ease;
}

@keyframes tabFadeIn {
    from {
        opacity: 0;
        transform: translateY(10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}
</style>
CSS;
}

/**
 * Render tab content wrapper
 * 
 * @param string $content The content to wrap
 * @return string HTML output
 */
function renderTabContent($content) {
    return '<div class="tab-content"><div class="tab-panel">' . $content . '</div></div>';
}

/**
 * Get active tab from URL or default
 * 
 * @param array $tabs Available tabs
 * @param string $default Default tab key
 * @return string Active tab key
 */
function getActiveTab($tabs, $default = null) {
    $tab = $_GET['tab'] ?? null;
    
    // Validate tab exists
    if ($tab && isset($tabs[$tab])) {
        return $tab;
    }
    
    // Return default or first tab
    if ($default && isset($tabs[$default])) {
        return $default;
    }
    
    // Return first tab key
    return array_key_first($tabs);
}
