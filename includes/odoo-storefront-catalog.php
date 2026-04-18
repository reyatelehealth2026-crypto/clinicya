<?php
/**
 * Shared Odoo storefront catalog helpers (odoo_products_cache + shop_settings.order_data_source).
 * Used by api/shop-products.php and api/checkout.php.
 */

require_once __DIR__ . '/shop-data-source.php';
require_once __DIR__ . '/manager-product-photo.php';

if (!function_exists('schema_table_has_column')) {
    /**
     * @return bool
     */
    function schema_table_has_column(PDO $db, string $table, string $column)
    {
        try {
            $st = $db->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
            );
            $st->execute([$table, $column]);

            return ((int) $st->fetchColumn()) > 0;
        } catch (Exception $e) {
            return false;
        }
    }
}

if (!function_exists('useOdooStorefrontCatalog')) {
    /**
     * Odoo storefront mode: cache table + storefront column + shop_settings
     *
     * @return bool
     */
    function useOdooStorefrontCatalog(PDO $db, int $lineAccountId)
    {
        if ($lineAccountId <= 0) {
            return false;
        }
        try {
            $db->query('SELECT 1 FROM odoo_products_cache LIMIT 1');
        } catch (Exception $e) {
            return false;
        }
        if (!schema_table_has_column($db, 'odoo_products_cache', 'storefront_enabled')) {
            return false;
        }

        return getShopOrderDataSource($db, $lineAccountId) === 'odoo';
    }
}

if (!function_exists('odooEffectiveFields')) {
    /**
     * Merge admin_overrides JSON (same fields as inventory storefront)
     *
     * @return array{name: string, generic_name: string, category: string, list_price: float, online_price: float}
     */
    function odooEffectiveFields(array $row)
    {
        $overrides = [];
        if (!empty($row['admin_overrides'])) {
            $d = is_string($row['admin_overrides'])
                ? json_decode($row['admin_overrides'], true)
                : $row['admin_overrides'];
            if (is_array($d)) {
                $overrides = $d;
            }
        }
        $name = array_key_exists('name', $overrides) && $overrides['name'] !== null && $overrides['name'] !== ''
            ? (string) $overrides['name'] : (string) ($row['name'] ?? '');
        $generic = array_key_exists('generic_name', $overrides) && $overrides['generic_name'] !== null && $overrides['generic_name'] !== ''
            ? (string) $overrides['generic_name'] : (string) ($row['generic_name'] ?? '');
        $cat = array_key_exists('category', $overrides) && $overrides['category'] !== null && $overrides['category'] !== ''
            ? (string) $overrides['category'] : (string) ($row['category'] ?? '');
        $list = array_key_exists('list_price', $overrides) && $overrides['list_price'] !== null
            ? (float) $overrides['list_price'] : (float) ($row['list_price'] ?? 0);
        $online = array_key_exists('online_price', $overrides) && $overrides['online_price'] !== null
            ? (float) $overrides['online_price'] : (float) ($row['online_price'] ?? 0);

        return [
            'name' => $name,
            'generic_name' => $generic,
            'category' => $cat,
            'list_price' => $list,
            'online_price' => $online,
        ];
    }
}

if (!function_exists('formatOdooProductForLiff')) {
    /**
     * @return array<string, mixed>
     */
    function formatOdooProductForLiff(array $row)
    {
        $eff = odooEffectiveFields($row);
        $list = (float) $eff['list_price'];
        $online = (float) $eff['online_price'];
        if ($list <= 0 && $online > 0) {
            $displayPrice = $online;
            $displaySale = null;
        } elseif ($online > 0 && $online < $list) {
            $displayPrice = $list;
            $displaySale = $online;
        } else {
            $displayPrice = $list > 0 ? $list : ($online > 0 ? $online : 0);
            $displaySale = null;
        }
        $img = buildManagerProductPhotoUrl($row['product_code'] ?? null, $row['sku'] ?? null);

        return [
            'id' => (int) $row['id'],
            'sku' => (string) ($row['sku'] ?? ''),
            'name' => $eff['name'],
            'name_en' => '',
            'price' => $displayPrice,
            'sale_price' => $displaySale,
            'stock' => (int) round((float) ($row['saleable_qty'] ?? 0)),
            'image_url' => $img,
            'unit' => 'ชิ้น',
            'manufacturer' => null,
            'generic_name' => $eff['generic_name'] ?: null,
            'description' => null,
            'usage_instructions' => null,
            'category_id' => $eff['category'] ?: null,
            'category_name' => $eff['category'] ?: null,
            'barcode' => (string) ($row['barcode'] ?? ''),
            'is_featured' => !empty($row['featured_order']),
            'is_bestseller' => 0,
            'is_flash_sale' => 0,
            'is_choice' => 0,
            'flash_sale_end' => null,
            'product_source' => 'odoo_products_cache',
            'product_code' => (string) ($row['product_code'] ?? ''),
            'drug_type' => $row['drug_type'] ?? null,
        ];
    }
}
