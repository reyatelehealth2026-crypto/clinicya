<?php
/**
 * Shared shop storefront catalog helpers (shop_products + shop_settings.order_data_source).
 * Used by api/shop-products.php and api/checkout.php.
 *
 * NOTE: historical name was `odoo_products_cache` because the table was first
 * populated by an Odoo sync job. The table now powers any admin-managed shop
 * catalog, whether or not Odoo is the upstream source.
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

if (!function_exists('useShopProductCatalog')) {
    /**
     * Storefront mode: shop_products table + storefront column + shop_settings.
     * Returns true when the mini-app shop should query the managed catalog
     * (shop_products) instead of falling back to legacy business_items.
     *
     * `getShopOrderDataSource() === 'odoo'` is the historical enum value for
     * "use the managed cache table" and is preserved for backward compat with
     * existing shop_settings rows.
     *
     * @return bool
     */
    function useShopProductCatalog(PDO $db, int $lineAccountId)
    {
        if ($lineAccountId <= 0) {
            return false;
        }
        try {
            $db->query('SELECT 1 FROM shop_products LIMIT 1');
        } catch (Exception $e) {
            return false;
        }
        if (!schema_table_has_column($db, 'shop_products', 'storefront_enabled')) {
            return false;
        }

        return getShopOrderDataSource($db, $lineAccountId) === 'odoo';
    }
}

if (!function_exists('shopEffectiveFields')) {
    /**
     * Merge admin_overrides JSON (same fields as inventory storefront).
     * Admin-supplied non-empty values win over the synced cache row.
     *
     * @return array{
     *   name: string,
     *   generic_name: string,
     *   category: string,
     *   list_price: float,
     *   online_price: float,
     *   description: string,
     *   usage_instructions: string,
     *   manufacturer: string,
     *   unit: string,
     *   image_url: string,
     *   image_gallery: string
     * }
     */
    function shopEffectiveFields(array $row)
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
        $pick = function (string $key) use ($overrides, $row): string {
            if (array_key_exists($key, $overrides) && $overrides[$key] !== null && $overrides[$key] !== '') {
                return (string) $overrides[$key];
            }
            return (string) ($row[$key] ?? '');
        };
        $list = array_key_exists('list_price', $overrides) && $overrides['list_price'] !== null
            ? (float) $overrides['list_price'] : (float) ($row['list_price'] ?? 0);
        $online = array_key_exists('online_price', $overrides) && $overrides['online_price'] !== null
            ? (float) $overrides['online_price'] : (float) ($row['online_price'] ?? 0);

        return [
            'name'               => $pick('name'),
            'generic_name'       => $pick('generic_name'),
            'category'           => $pick('category'),
            'list_price'         => $list,
            'online_price'       => $online,
            'description'        => $pick('description'),
            'usage_instructions' => $pick('usage_instructions'),
            'manufacturer'       => $pick('manufacturer'),
            'unit'               => $pick('unit'),
            'image_url'          => $pick('image_url'),
            'image_gallery'      => $pick('image_gallery'),
        ];
    }
}

if (!function_exists('formatShopProductForLiff')) {
    /**
     * @return array<string, mixed>
     */
    function formatShopProductForLiff(array $row)
    {
        $eff = shopEffectiveFields($row);
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

        // image_url: prefer the admin-supplied URL; fall back to the legacy
        // manager.cnypharmacy.com photo pattern by product_code/sku.
        $imageUrl = $eff['image_url'] !== '' ? $eff['image_url'] : '';
        if ($imageUrl === '') {
            $imageUrl = buildManagerProductPhotoUrl($row['product_code'] ?? null, $row['sku'] ?? null);
        }

        // image_gallery: stored as a JSON array of URLs (one per image).
        // Falls back to [image_url] so callers always get at least one entry
        // when an admin only filled in the single-image field.
        $gallery = [];
        $rawGallery = $eff['image_gallery'];
        if ($rawGallery !== '') {
            $decoded = json_decode($rawGallery, true);
            if (is_array($decoded)) {
                foreach ($decoded as $url) {
                    if (is_string($url) && trim($url) !== '') {
                        $gallery[] = trim($url);
                    }
                }
            } else {
                // Backwards compat: comma- or newline-separated list.
                foreach (preg_split('/[\r\n,]+/', $rawGallery) as $url) {
                    $url = trim((string) $url);
                    if ($url !== '') {
                        $gallery[] = $url;
                    }
                }
            }
        }
        if ($imageUrl !== '' && !in_array($imageUrl, $gallery, true)) {
            array_unshift($gallery, $imageUrl);
        }

        return [
            'id' => (int) $row['id'],
            'sku' => (string) ($row['sku'] ?? ''),
            'name' => $eff['name'],
            'name_en' => (string) ($row['name_en'] ?? ''),
            'price' => $displayPrice,
            'sale_price' => $displaySale,
            'stock' => (int) round((float) ($row['saleable_qty'] ?? 0)),
            'image_url' => $imageUrl,
            'image_gallery' => $gallery,
            'unit' => $eff['unit'] !== '' ? $eff['unit'] : 'ชิ้น',
            'manufacturer' => $eff['manufacturer'] !== '' ? $eff['manufacturer'] : null,
            'generic_name' => $eff['generic_name'] !== '' ? $eff['generic_name'] : null,
            'description' => $eff['description'] !== '' ? $eff['description'] : null,
            'usage_instructions' => $eff['usage_instructions'] !== '' ? $eff['usage_instructions'] : null,
            'category_id' => $eff['category'] !== '' ? $eff['category'] : null,
            'category_name' => $eff['category'] !== '' ? $eff['category'] : null,
            'barcode' => (string) ($row['barcode'] ?? ''),
            'is_featured' => !empty($row['featured_order']),
            'is_bestseller' => 0,
            'is_flash_sale' => 0,
            'is_choice' => 0,
            'flash_sale_end' => null,
            'product_source' => 'shop_products',
            'product_code' => (string) ($row['product_code'] ?? ''),
            'drug_type' => $row['drug_type'] ?? null,
        ];
    }
}
