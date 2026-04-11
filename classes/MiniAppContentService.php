<?php
/**
 * MiniAppContentService — จัดการเนื้อหาหน้า Home ของ LINE Mini App
 * 
 * รองรับ: Banners, Home Sections, Home Products
 * ออกแบบให้ยืดหยุ่นสูงสุด — Universal Link, Rich Promotion Metadata, Dynamic Sections
 */

class MiniAppContentService
{
    private PDO $db;
    private ?int $lineAccountId;

    public function __construct(PDO $db, ?int $lineAccountId = null)
    {
        $this->db = $db;
        $this->lineAccountId = $lineAccountId;
    }

    // =========================================================================
    // BANNERS
    // =========================================================================

    /**
     * Get active banners for display (filtered by date range + line_account_id)
     */
    public function getActiveBanners(string $position = 'home_top', int $limit = 10): array
    {
        $sql = "SELECT * FROM miniapp_banners 
                WHERE is_active = 1 
                  AND position = :position
                  AND (start_date IS NULL OR start_date <= NOW())
                  AND (end_date IS NULL OR end_date >= NOW())";
        $params = [':position' => $position];

        $sql .= $this->lineAccountFilter('miniapp_banners');
        $sql .= " ORDER BY display_order ASC, id DESC LIMIT :limit";

        $stmt = $this->db->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $this->bindLineAccount($stmt);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return array_map([$this, 'formatBanner'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function getAllBannersForAdmin(): array
    {
        $sql = "SELECT * FROM miniapp_banners WHERE 1=1";
        $params = [];

        if ($this->lineAccountId !== null) {
            $sql .= " AND (line_account_id = :laid OR line_account_id IS NULL)";
            $params[':laid'] = $this->lineAccountId;
        }

        $sql .= " ORDER BY position ASC, display_order ASC, id DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getBannerById(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM miniapp_banners WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function createBanner(array $data): int
    {
        $sql = "INSERT INTO miniapp_banners 
                (title, subtitle, description, image_url, image_mobile_url, 
                 link_type, link_value, link_label, position, display_order, 
                 is_active, bg_color, start_date, end_date, line_account_id)
                VALUES 
                (:title, :subtitle, :description, :image_url, :image_mobile_url,
                 :link_type, :link_value, :link_label, :position, :display_order,
                 :is_active, :bg_color, :start_date, :end_date, :line_account_id)";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($this->bannerParams($data));
        return (int) $this->db->lastInsertId();
    }

    public function updateBanner(int $id, array $data): bool
    {
        $sql = "UPDATE miniapp_banners SET
                title = :title, subtitle = :subtitle, description = :description,
                image_url = :image_url, image_mobile_url = :image_mobile_url,
                link_type = :link_type, link_value = :link_value, link_label = :link_label,
                position = :position, display_order = :display_order,
                is_active = :is_active, bg_color = :bg_color,
                start_date = :start_date, end_date = :end_date, line_account_id = :line_account_id
                WHERE id = :id";

        $params = $this->bannerParams($data);
        $params[':id'] = $id;

        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    public function deleteBanner(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM miniapp_banners WHERE id = ?");
        return $stmt->execute([$id]);
    }

    public function toggleBanner(int $id): bool
    {
        $stmt = $this->db->prepare("UPDATE miniapp_banners SET is_active = NOT is_active WHERE id = ?");
        return $stmt->execute([$id]);
    }

    public function getBannerCount(): int
    {
        $sql = "SELECT COUNT(*) FROM miniapp_banners WHERE 1=1";
        $params = [];
        if ($this->lineAccountId !== null) {
            $sql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
            $params[] = $this->lineAccountId;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    // =========================================================================
    // SECTIONS
    // =========================================================================

    /**
     * Get active sections with their products (for display)
     */
    public function getActiveSections(int $limit = 10): array
    {
        $sql = "SELECT * FROM miniapp_home_sections 
                WHERE is_active = 1
                  AND (start_date IS NULL OR start_date <= NOW())
                  AND (end_date IS NULL OR end_date >= NOW())";

        $sql .= $this->lineAccountFilter('miniapp_home_sections');
        $sql .= " ORDER BY display_order ASC, id ASC LIMIT :limit";

        $stmt = $this->db->prepare($sql);
        $this->bindLineAccount($stmt);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $sections = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($sections as &$section) {
            $section = $this->formatSection($section);
            $section['products'] = $this->getProductsBySection((int) $section['id']);
        }

        return $sections;
    }

    public function getAllSectionsForAdmin(): array
    {
        $sql = "SELECT * FROM miniapp_home_sections WHERE 1=1";
        $params = [];
        if ($this->lineAccountId !== null) {
            $sql .= " AND (line_account_id = :laid OR line_account_id IS NULL)";
            $params[':laid'] = $this->lineAccountId;
        }
        $sql .= " ORDER BY display_order ASC, id DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getSectionById(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM miniapp_home_sections WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function createSection(array $data): int
    {
        $sql = "INSERT INTO miniapp_home_sections 
                (section_key, title, subtitle, section_style, bg_color, text_color,
                 icon_url, countdown_ends_at, display_order, is_active,
                 start_date, end_date, line_account_id)
                VALUES
                (:section_key, :title, :subtitle, :section_style, :bg_color, :text_color,
                 :icon_url, :countdown_ends_at, :display_order, :is_active,
                 :start_date, :end_date, :line_account_id)";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($this->sectionParams($data));
        return (int) $this->db->lastInsertId();
    }

    public function updateSection(int $id, array $data): bool
    {
        $sql = "UPDATE miniapp_home_sections SET
                section_key = :section_key, title = :title, subtitle = :subtitle,
                section_style = :section_style, bg_color = :bg_color, text_color = :text_color,
                icon_url = :icon_url, countdown_ends_at = :countdown_ends_at,
                display_order = :display_order, is_active = :is_active,
                start_date = :start_date, end_date = :end_date, line_account_id = :line_account_id
                WHERE id = :id";

        $params = $this->sectionParams($data);
        $params[':id'] = $id;

        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    public function deleteSection(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM miniapp_home_sections WHERE id = ?");
        return $stmt->execute([$id]);
    }

    public function toggleSection(int $id): bool
    {
        $stmt = $this->db->prepare("UPDATE miniapp_home_sections SET is_active = NOT is_active WHERE id = ?");
        return $stmt->execute([$id]);
    }

    public function getSectionCount(): int
    {
        $sql = "SELECT COUNT(*) FROM miniapp_home_sections WHERE 1=1";
        $params = [];
        if ($this->lineAccountId !== null) {
            $sql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
            $params[] = $this->lineAccountId;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    // =========================================================================
    // PRODUCTS
    // =========================================================================

    /**
     * Get products for a section (for display)
     */
    public function getProductsBySection(int $sectionId, int $limit = 20): array
    {
        $sql = "SELECT * FROM miniapp_home_products 
                WHERE section_id = :section_id AND is_active = 1
                  AND (start_date IS NULL OR start_date <= NOW())
                  AND (end_date IS NULL OR end_date >= NOW())";

        $sql .= $this->lineAccountFilter('miniapp_home_products');
        $sql .= " ORDER BY display_order ASC, id ASC LIMIT :limit";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':section_id', $sectionId, PDO::PARAM_INT);
        $this->bindLineAccount($stmt);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return array_map([$this, 'formatProduct'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function getAllProductsForAdmin(?int $sectionId = null): array
    {
        $sql = "SELECT p.*, s.title as section_title, s.section_key 
                FROM miniapp_home_products p
                LEFT JOIN miniapp_home_sections s ON p.section_id = s.id
                WHERE 1=1";
        $params = [];

        if ($sectionId !== null) {
            $sql .= " AND p.section_id = :section_id";
            $params[':section_id'] = $sectionId;
        }

        if ($this->lineAccountId !== null) {
            $sql .= " AND (p.line_account_id = :laid OR p.line_account_id IS NULL)";
            $params[':laid'] = $this->lineAccountId;
        }

        $sql .= " ORDER BY p.section_id ASC, p.display_order ASC, p.id DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getProductById(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM miniapp_home_products WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function createProduct(array $data): int
    {
        $sql = "INSERT INTO miniapp_home_products 
                (section_id, title, short_description, image_url, image_gallery,
                 original_price, sale_price, discount_percent, price_unit,
                 promotion_tags, promotion_label, badges, custom_label,
                 stock_qty, limit_qty, show_stock_badge, delivery_options,
                 link_type, link_value, display_order, is_active,
                 start_date, end_date, line_account_id)
                VALUES
                (:section_id, :title, :short_description, :image_url, :image_gallery,
                 :original_price, :sale_price, :discount_percent, :price_unit,
                 :promotion_tags, :promotion_label, :badges, :custom_label,
                 :stock_qty, :limit_qty, :show_stock_badge, :delivery_options,
                 :link_type, :link_value, :display_order, :is_active,
                 :start_date, :end_date, :line_account_id)";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($this->productParams($data));
        return (int) $this->db->lastInsertId();
    }

    public function updateProduct(int $id, array $data): bool
    {
        $sql = "UPDATE miniapp_home_products SET
                section_id = :section_id, title = :title, short_description = :short_description,
                image_url = :image_url, image_gallery = :image_gallery,
                original_price = :original_price, sale_price = :sale_price,
                discount_percent = :discount_percent, price_unit = :price_unit,
                promotion_tags = :promotion_tags, promotion_label = :promotion_label,
                badges = :badges, custom_label = :custom_label,
                stock_qty = :stock_qty, limit_qty = :limit_qty,
                show_stock_badge = :show_stock_badge, delivery_options = :delivery_options,
                link_type = :link_type, link_value = :link_value,
                display_order = :display_order, is_active = :is_active,
                start_date = :start_date, end_date = :end_date, line_account_id = :line_account_id
                WHERE id = :id";

        $params = $this->productParams($data);
        $params[':id'] = $id;

        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    public function deleteProduct(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM miniapp_home_products WHERE id = ?");
        return $stmt->execute([$id]);
    }

    public function toggleProduct(int $id): bool
    {
        $stmt = $this->db->prepare("UPDATE miniapp_home_products SET is_active = NOT is_active WHERE id = ?");
        return $stmt->execute([$id]);
    }

    public function getProductCount(?int $sectionId = null): int
    {
        $sql = "SELECT COUNT(*) FROM miniapp_home_products WHERE 1=1";
        $params = [];
        if ($sectionId !== null) {
            $sql .= " AND section_id = ?";
            $params[] = $sectionId;
        }
        if ($this->lineAccountId !== null) {
            $sql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
            $params[] = $this->lineAccountId;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    // =========================================================================
    // HOME ALL — single call to get everything
    // =========================================================================

    public function getHomeAll(): array
    {
        return [
            'banners' => $this->getActiveBanners('home_top'),
            'sections' => $this->getActiveSections()
        ];
    }

    // =========================================================================
    // FORMATTERS — transform DB rows to API-friendly format
    // =========================================================================

    private function formatBanner(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'title' => $row['title'],
            'subtitle' => $row['subtitle'],
            'description' => $row['description'],
            'imageUrl' => $row['image_url'],
            'imageMobileUrl' => $row['image_mobile_url'],
            'link' => [
                'type' => $row['link_type'] ?? 'none',
                'value' => $row['link_value'] ?? '',
                'label' => $row['link_label'] ?? '',
            ],
            'bgColor' => $row['bg_color'],
            'position' => $row['position'],
            'displayOrder' => (int) $row['display_order'],
        ];
    }

    private function formatSection(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'sectionKey' => $row['section_key'],
            'title' => $row['title'],
            'subtitle' => $row['subtitle'],
            'style' => $row['section_style'],
            'bgColor' => $row['bg_color'],
            'textColor' => $row['text_color'],
            'iconUrl' => $row['icon_url'],
            'countdownEndsAt' => $row['countdown_ends_at'],
            'displayOrder' => (int) $row['display_order'],
            'products' => [],
        ];
    }

    private function formatProduct(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'title' => $row['title'],
            'shortDescription' => $row['short_description'],
            'imageUrl' => $row['image_url'],
            'imageGallery' => json_decode($row['image_gallery'] ?? '[]', true) ?: [],
            'originalPrice' => $row['original_price'] !== null ? (float) $row['original_price'] : null,
            'salePrice' => $row['sale_price'] !== null ? (float) $row['sale_price'] : null,
            'discountPercent' => $row['discount_percent'] !== null ? (float) $row['discount_percent'] : null,
            'priceUnit' => $row['price_unit'],
            'promotionTags' => json_decode($row['promotion_tags'] ?? '[]', true) ?: [],
            'promotionLabel' => $row['promotion_label'],
            'badges' => json_decode($row['badges'] ?? '[]', true) ?: [],
            'customLabel' => $row['custom_label'],
            'stockQty' => $row['stock_qty'] !== null ? (int) $row['stock_qty'] : null,
            'limitQty' => $row['limit_qty'] !== null ? (int) $row['limit_qty'] : null,
            'showStockBadge' => (bool) $row['show_stock_badge'],
            'deliveryOptions' => json_decode($row['delivery_options'] ?? '[]', true) ?: [],
            'link' => [
                'type' => $row['link_type'] ?? 'none',
                'value' => $row['link_value'] ?? '',
            ],
            'displayOrder' => (int) $row['display_order'],
        ];
    }

    // =========================================================================
    // PARAM BUILDERS
    // =========================================================================

    private function bannerParams(array $d): array
    {
        return [
            ':title' => $d['title'] ?? '',
            ':subtitle' => $d['subtitle'] ?? null,
            ':description' => $d['description'] ?? null,
            ':image_url' => $d['image_url'] ?? '',
            ':image_mobile_url' => $d['image_mobile_url'] ?? null,
            ':link_type' => $d['link_type'] ?? 'none',
            ':link_value' => $d['link_value'] ?? null,
            ':link_label' => $d['link_label'] ?? null,
            ':position' => $d['position'] ?? 'home_top',
            ':display_order' => (int) ($d['display_order'] ?? 0),
            ':is_active' => (int) ($d['is_active'] ?? 1),
            ':bg_color' => $d['bg_color'] ?? null,
            ':start_date' => !empty($d['start_date']) ? $d['start_date'] : null,
            ':end_date' => !empty($d['end_date']) ? $d['end_date'] : null,
            ':line_account_id' => $this->lineAccountId,
        ];
    }

    private function sectionParams(array $d): array
    {
        return [
            ':section_key' => $d['section_key'] ?? '',
            ':title' => $d['title'] ?? '',
            ':subtitle' => $d['subtitle'] ?? null,
            ':section_style' => $d['section_style'] ?? 'horizontal_scroll',
            ':bg_color' => $d['bg_color'] ?? null,
            ':text_color' => $d['text_color'] ?? null,
            ':icon_url' => $d['icon_url'] ?? null,
            ':countdown_ends_at' => !empty($d['countdown_ends_at']) ? $d['countdown_ends_at'] : null,
            ':display_order' => (int) ($d['display_order'] ?? 0),
            ':is_active' => (int) ($d['is_active'] ?? 1),
            ':start_date' => !empty($d['start_date']) ? $d['start_date'] : null,
            ':end_date' => !empty($d['end_date']) ? $d['end_date'] : null,
            ':line_account_id' => $this->lineAccountId,
        ];
    }

    private function productParams(array $d): array
    {
        return [
            ':section_id' => (int) ($d['section_id'] ?? 0),
            ':title' => $d['title'] ?? '',
            ':short_description' => $d['short_description'] ?? null,
            ':image_url' => $d['image_url'] ?? '',
            ':image_gallery' => is_array($d['image_gallery'] ?? null) ? json_encode($d['image_gallery']) : ($d['image_gallery'] ?? null),
            ':original_price' => !empty($d['original_price']) ? (float) $d['original_price'] : null,
            ':sale_price' => !empty($d['sale_price']) ? (float) $d['sale_price'] : null,
            ':discount_percent' => !empty($d['discount_percent']) ? (float) $d['discount_percent'] : null,
            ':price_unit' => $d['price_unit'] ?? null,
            ':promotion_tags' => is_array($d['promotion_tags'] ?? null) ? json_encode($d['promotion_tags'], JSON_UNESCAPED_UNICODE) : ($d['promotion_tags'] ?? null),
            ':promotion_label' => $d['promotion_label'] ?? null,
            ':badges' => is_array($d['badges'] ?? null) ? json_encode($d['badges'], JSON_UNESCAPED_UNICODE) : ($d['badges'] ?? null),
            ':custom_label' => $d['custom_label'] ?? null,
            ':stock_qty' => isset($d['stock_qty']) && $d['stock_qty'] !== '' ? (int) $d['stock_qty'] : null,
            ':limit_qty' => isset($d['limit_qty']) && $d['limit_qty'] !== '' ? (int) $d['limit_qty'] : null,
            ':show_stock_badge' => (int) ($d['show_stock_badge'] ?? 0),
            ':delivery_options' => is_array($d['delivery_options'] ?? null) ? json_encode($d['delivery_options'], JSON_UNESCAPED_UNICODE) : ($d['delivery_options'] ?? null),
            ':link_type' => $d['link_type'] ?? 'none',
            ':link_value' => $d['link_value'] ?? null,
            ':display_order' => (int) ($d['display_order'] ?? 0),
            ':is_active' => (int) ($d['is_active'] ?? 1),
            ':start_date' => !empty($d['start_date']) ? $d['start_date'] : null,
            ':end_date' => !empty($d['end_date']) ? $d['end_date'] : null,
            ':line_account_id' => $this->lineAccountId,
        ];
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private function lineAccountFilter(string $table): string
    {
        if ($this->lineAccountId !== null) {
            return " AND ({$table}.line_account_id = :laid OR {$table}.line_account_id IS NULL)";
        }
        return '';
    }

    private function bindLineAccount(\PDOStatement $stmt): void
    {
        if ($this->lineAccountId !== null) {
            $stmt->bindValue(':laid', $this->lineAccountId, PDO::PARAM_INT);
        }
    }
}
