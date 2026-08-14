import type { Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';
import type { ReactNode } from 'react';
import {
  getCatalogCategories,
  getCatalogProducts,
  getCatalogSegmentsForParity,
  getCatalogUserTagsForParity,
  toCatalogBuilderCategories,
  toCatalogBuilderProducts,
} from '../_lib/catalog-queries';
import { CatalogBuilderClient } from './CatalogBuilderClient';

/**
 * CatalogTab — Server Component port of includes/broadcast/catalog.php (662
 * LOC): the drag-and-drop product-to-Flex-carousel bubble builder tab.
 * `await`-invoked directly from page.tsx (`await CatalogTab({db,
 * lineAccountId})`), same direct-invocation convention as
 * ../../settings/_components/ConsentTab.tsx/GeneralTab.tsx.
 *
 * Fetches products (`UnifiedShop::getItems(['in_stock' => true], 200)`) and
 * categories (`UnifiedShop::getCategories(50)`) server-side via
 * ../_lib/catalog-queries.ts, trims them down to exactly the `{id, name,
 * price, image, cat}` shape catalog.php's own `$productsJson` (lines 17-23)
 * sends into its client `<script>`, and hands that to the 'use client'
 * bubble-builder island — which owns everything interactive: drag & drop
 * (SortableJS, loaded from the same CDN URL catalog.php uses today), the
 * live Flex preview (assets/js/flex-preview.js, same global-script
 * dependency), and the draft save/load/delete + "send Flex" `fetch()` calls
 * straight to the pre-existing `api/broadcast_drafts.php` /
 * `api/broadcast.php` PHP endpoints (out of this batch's scope, ported
 * verbatim — see ../_components/CatalogBuilderClient.tsx's module doc).
 *
 * Segments (`customer_segments`) and user tags (`user_tags`) are ALSO
 * fetched here (catalog.php lines 25-38) for read-parity, but — confirmed by
 * reading the full 662-line source — `$segments`/`$userTags` are NEVER
 * referenced anywhere else in the file: no `<?= $segments ?>`, no
 * `data-segment-*`, no `allSegments`/`allUserTags` JS array anywhere in the
 * page's `<script>`. They are dead-but-fetched in the PHP source itself.
 * Ported here for the same reason (byte-for-byte read parity, not silently
 * dropped — see ../_lib/catalog-queries.ts's module docs on both functions)
 * but deliberately NOT wired into any UI below: inventing a segment/tag
 * picker that PHP itself never built would be new behavior, not a port.
 */
export interface CatalogTabProps {
  db: Kysely<TenantDB>;
  lineAccountId: number;
}

export async function CatalogTab({ db, lineAccountId }: CatalogTabProps): Promise<ReactNode> {
  const [products, categories] = await Promise.all([
    getCatalogProducts(db, lineAccountId),
    getCatalogCategories(db, lineAccountId),
  ]);

  // Dead-but-fetched parity reads — see module doc above. Results intentionally discarded.
  await Promise.all([getCatalogSegmentsForParity(db), getCatalogUserTagsForParity(db, lineAccountId)]);

  return (
    <CatalogBuilderClient products={toCatalogBuilderProducts(products)} categories={toCatalogBuilderCategories(categories)} />
  );
}
