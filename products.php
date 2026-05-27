<?php
/**
 * /products.php — DEPRECATED redirect to /inventory/
 *
 * The standalone Products page was merged into /inventory/ to avoid duplicating
 * what storefront/units/locations already handle. Pharmacy-specific tabs
 * (drug-groups, generic-names, label-templates, drug-interactions) are now
 * tabs inside /inventory/.
 *
 * Old query map:
 *   ?tab=list              → /inventory/?tab=storefront
 *   ?tab=units             → /inventory/?tab=locations (units live in product-units.php)
 *   ?tab=storage-locations → /inventory/?tab=locations
 *   ?tab=drug-groups       → /inventory/?tab=drug-groups
 *   ?tab=generic-names     → /inventory/?tab=generic-names
 *   ?tab=label-templates   → /inventory/?tab=label-templates
 *   ?tab=drug-interactions → /inventory/?tab=drug-interactions
 *   ?tab=categories        → /inventory/?tab=storefront (categories edited in product form)
 */
$tabMap = [
    'list'              => 'storefront',
    'categories'        => 'storefront',
    'units'             => 'locations',
    'storage-locations' => 'locations',
    'drug-groups'       => 'drug-groups',
    'generic-names'     => 'generic-names',
    'label-templates'   => 'label-templates',
    'drug-interactions' => 'drug-interactions',
];
$src = (string)($_GET['tab'] ?? 'list');
$dst = $tabMap[$src] ?? 'storefront';
header('Location: /inventory/?tab=' . urlencode($dst), true, 301);
exit;
