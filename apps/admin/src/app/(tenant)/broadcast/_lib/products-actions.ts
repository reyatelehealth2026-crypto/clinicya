'use server';

import { createHash } from 'node:crypto';
import { sql } from 'kysely';
import { redirect } from 'next/navigation';
import type { LineMessage } from '@reya/line';
import { requireTenantPageContext } from '../../users/_lib/session';
import { resolveCurrentBotId } from './send-queries';
import { getCatalogProductById } from './products-queries';
import { executeProductBroadcastSend } from './broadcastFanout';
import { CREATE_BROADCAST_ERRORS } from './products-errors';

/**
 * products-actions.ts — Server Actions for the three POST handlers inside
 * `renderBroadcastProducts($db, $currentBotId)` in includes/broadcast/products.php:
 * `action==='create_broadcast'` (lines 79-133), `action==='send_broadcast'` (lines 141-197),
 * `action==='delete_campaign'` (lines 199-209). Read the full 522-line source before touching
 * this file.
 */

function first(formData: FormData, key: string): string {
  const v = formData.get(key);
  return typeof v === 'string' ? v : '';
}

// ---------------------------------------------------------------------------
// action=create_broadcast — products.php lines 79-133
// ---------------------------------------------------------------------------

// CREATE_BROADCAST_ERRORS lives in ./products-errors.ts, not here: this file
// is "use server", and Next.js only permits async-function exports from such a
// module (a re-export of the object would fail `next build` the same way the
// original declaration did). Imported below for use by the action.

/**
 * Port of products.php lines 79-133 (`action === 'create_broadcast'`):
 *
 *   $name = trim($_POST['name'] ?? ''); $tagPrefix = trim($_POST['tag_prefix'] ?? 'สนใจ_');
 *   $autoTagEnabled = isset($_POST['auto_tag_enabled']) ? 1 : 0;
 *   $selectedProducts = $_POST['products'] ?? [];
 *   if (empty($name)) { $error = '...'; }
 *   elseif (empty($selectedProducts)) { $error = '...'; }
 *   elseif (count($selectedProducts) > 10) { $error = '...'; }
 *   else {
 *     BEGIN; INSERT INTO broadcast_campaigns (...); $campaignId = lastInsertId();
 *     foreach ($selectedProducts as $productId) {
 *       $product = $shop->getItem($productId);
 *       if ($product) {
 *         if ($autoTagEnabled) {
 *           $tagName = $tagPrefix . $product['name']; $tagColor = '#'.substr(md5($product['name']),0,6);
 *           SELECT id FROM user_tags WHERE name=? AND (line_account_id=? OR NULL) LIMIT 1;
 *           if not found: INSERT INTO user_tags (line_account_id,name,color,description) VALUES (...);
 *         }
 *         $postbackData = "broadcast_click_{$campaignId}_{$productId}";
 *         INSERT INTO broadcast_items (broadcast_id, product_id, item_name, item_image, item_price,
 *           postback_data, tag_id, sort_order) VALUES (...);
 *       }
 *     }
 *     COMMIT; header('Location: broadcast.php?tab=products&success=created&id={$campaignId}'); exit;
 *   }
 *
 * Validation order is EXACT (name -> products empty -> products > 10), and — per the brief —
 * each error return path issues ZERO database writes: the three `if`/`elseif`/`elseif` guards
 * below `return` before touching `db` at all, matching PHP's `elseif` chain never reaching the
 * transaction block on any of the three branches.
 *
 * FLAGGED, NOT FIXED (per the brief — a genuine pre-existing PHP bug, reproduced verbatim):
 * the `broadcast_items` INSERT below has the EXACT SAME 8-column list as products.php:121
 * (`broadcast_id, product_id, item_name, item_image, item_price, postback_data, tag_id,
 * sort_order`) — deliberately WITHOUT a `line_account_id` column. The tenant template defines
 * `broadcast_items.line_account_id INT NOT NULL DEFAULT 1` (packages/db's generated
 * `tenant-db.d.ts`: `Generated<number>`, non-nullable), and PHP's own INSERT never sets it —
 * every campaign's items are therefore ALWAYS silently tagged to `line_account_id = 1`
 * regardless of which bot is actually active. This is NOT "fixed" here by adding the column;
 * doing so would diverge from what real PHP actually persists today.
 *
 * Success redirect carries `&id={campaignId}` (products.php:127) — mirrored below.
 *
 * ERROR DELIVERY: unlike `sendProductBroadcastAction` below (invoked from the CLIENT
 * `ProductsSendModal.tsx`, which can capture a returned `{error}` value directly),
 * `ProductsTab.tsx` renders this action's `<form>` as a plain Server Component
 * `action={createBroadcastAction}` binding — there is no client boundary in that form to
 * capture a returned value the way `useActionState` would. Real PHP re-renders the SAME
 * request's response with `$error` inline, a "re-render the same POST response" primitive
 * Next has no equivalent for from a Server Component form; this port instead `redirect()`s
 * back to `/broadcast?tab=products&error=...` with the exact Thai string URL-encoded — the
 * SAME documented divergence (tenant)/settings/_lib/welcome-actions.ts's and
 * (tenant)/user-detail/actions.ts's own module docs establish for an identical PHP shape
 * ("no re-render the same POST response primitive... redirect with a ?error= search param").
 * `ProductsTab.tsx` reads `searchParams.error` and renders the exact same red banner PHP's
 * `<?php if ($error): ?>` block does.
 */
export async function createBroadcastAction(formData: FormData): Promise<void> {
  const name = first(formData, 'name').trim();
  const tagPrefixRaw = first(formData, 'tag_prefix').trim();
  const tagPrefix = tagPrefixRaw !== '' ? tagPrefixRaw : 'สนใจ_';
  const autoTagEnabled = formData.get('auto_tag_enabled') !== null;
  const selectedProducts = formData
    .getAll('products[]')
    .map((v) => Number.parseInt(String(v), 10))
    .filter((n) => Number.isFinite(n) && n > 0);

  if (name === '') {
    redirect(`/broadcast?tab=products&error=${encodeURIComponent(CREATE_BROADCAST_ERRORS.emptyName)}`);
  }
  if (selectedProducts.length === 0) {
    redirect(`/broadcast?tab=products&error=${encodeURIComponent(CREATE_BROADCAST_ERRORS.noProducts)}`);
  }
  if (selectedProducts.length > 10) {
    redirect(`/broadcast?tab=products&error=${encodeURIComponent(CREATE_BROADCAST_ERRORS.tooManyProducts)}`);
  }

  const { db, session } = await requireTenantPageContext();
  const currentBotId = await resolveCurrentBotId(db, session.currentBotId);

  let campaignId = 0;
  try {
    await db.transaction().execute(async (trx) => {
      const campaignInsert = await sql`
        INSERT INTO broadcast_campaigns (line_account_id, name, message_type, auto_tag_enabled, tag_prefix)
        VALUES (${currentBotId}, ${name}, 'product_carousel', ${autoTagEnabled ? 1 : 0}, ${tagPrefix})
      `.execute(trx);
      campaignId = Number(campaignInsert.insertId ?? 0);

      let sortOrder = 0;
      for (const productId of selectedProducts) {
        const product = await getCatalogProductById(trx, currentBotId, productId);
        if (!product) {
          continue;
        }

        let tagId: number | null = null;
        if (autoTagEnabled) {
          const tagName = tagPrefix + product.name;
          const tagColor = '#' + md5Hex(product.name).slice(0, 6);

          const existing = await sql<{ id: number }>`
            SELECT id FROM user_tags WHERE name = ${tagName} AND (line_account_id = ${currentBotId} OR line_account_id IS NULL) LIMIT 1
          `.execute(trx);
          const existingRow = existing.rows[0];
          if (existingRow) {
            tagId = Number(existingRow.id);
          } else {
            const tagInsert = await sql`
              INSERT INTO user_tags (line_account_id, name, color, description)
              VALUES (${currentBotId}, ${tagName}, ${tagColor}, ${'สนใจสินค้า: ' + product.name})
            `.execute(trx);
            tagId = Number(tagInsert.insertId ?? 0);
          }
        }

        const postbackData = `broadcast_click_${campaignId}_${productId}`;

        // Deliberately NO line_account_id column — see doc comment above.
        await sql`
          INSERT INTO broadcast_items (broadcast_id, product_id, item_name, item_image, item_price, postback_data, tag_id, sort_order)
          VALUES (${campaignId}, ${productId}, ${product.name}, ${product.imageUrl}, ${product.salePrice ?? product.price}, ${postbackData}, ${tagId}, ${sortOrder})
        `.execute(trx);
        sortOrder += 1;
      }
    });
  } catch (err) {
    // products.php:130-132: `$db->rollBack(); $error = 'เกิดข้อผิดพลาด: ' . $e->getMessage();`
    const message = err instanceof Error ? err.message : String(err);
    redirect(`/broadcast?tab=products&error=${encodeURIComponent(`เกิดข้อผิดพลาด: ${message}`)}`);
  }

  redirect(`/broadcast?tab=products&success=created&id=${campaignId}`);
}

/**
 * Minimal MD5 hex digest — `substr(md5($product['name']),0,6)` needs the FULL MD5 hex string
 * before truncating (a truncated-input hash would produce different bytes), so this must be a
 * real MD5, not a stand-in. Zero-dependency (no @reya/* or npm crypto MD5 helper exists in this
 * workspace) — Node's built-in `crypto` module (already used by packages/line/src/api.ts for
 * HMAC) provides `createHash('md5')` directly; kept local since only this one call site needs it.
 */
function md5Hex(input: string): string {
  return createHash('md5').update(input, 'utf8').digest('hex');
}

// ---------------------------------------------------------------------------
// action=send_broadcast — products.php lines 141-197
// ---------------------------------------------------------------------------

export interface SendProductBroadcastResult {
  error: string | null;
}

const SEND_BROADCAST_ERRORS = {
  campaignNotFound: 'ไม่พบ Campaign',
  noItems: 'ไม่มีสินค้าใน Campaign',
} as const;

interface CampaignRow {
  id: number;
  name: string;
}
interface CampaignItemRow {
  item_name: string;
  item_image: string | null;
  item_price: string | number | null;
  postback_data: string;
}

/** products.php lines 154-171: one Flex "kilo" bubble per campaign item — hero image (when
 * present) + name/price body + a postback "สนใจสินค้านี้" button, wrapped in a carousel. */
function buildProductCarousel(campaignName: string, items: CampaignItemRow[]): LineMessage {
  const bubbles = items.map((item) => {
    const price = item.item_price !== null && item.item_price !== '' ? Number(item.item_price) : 0;
    const bubble: Record<string, unknown> = { type: 'bubble', size: 'kilo' };
    if (item.item_image) {
      bubble.hero = {
        type: 'image',
        url: item.item_image,
        size: 'full',
        aspectRatio: '1:1',
        aspectMode: 'cover',
      };
    }
    bubble.body = {
      type: 'box',
      layout: 'vertical',
      contents: [
        { type: 'text', text: item.item_name, weight: 'bold', size: 'md', wrap: true, maxLines: 2 },
        {
          type: 'text',
          text: `฿${price.toLocaleString('en-US')}`,
          size: 'xl',
          color: '#06C755',
          weight: 'bold',
          margin: 'md',
        },
      ],
      paddingAll: 'lg',
    };
    bubble.footer = {
      type: 'box',
      layout: 'vertical',
      contents: [
        {
          type: 'button',
          style: 'primary',
          color: '#06C755',
          action: { type: 'postback', label: '❤️ สนใจสินค้านี้', data: item.postback_data },
        },
      ],
      paddingAll: 'lg',
    };
    return bubble;
  });

  return { type: 'flex', altText: `📦 ${campaignName}`, contents: { type: 'carousel', contents: bubbles } };
}

/**
 * Port of products.php lines 141-197 (`action === 'send_broadcast'`). Loads the campaign +
 * items, throws the two exact Thai errors PHP throws (`ไม่พบ Campaign` / `ไม่มีสินค้าใน
 * Campaign`) when missing, builds the Flex carousel, delegates to
 * `executeProductBroadcastSend()` (`./broadcastFanout.ts`) for the actual send, then UPDATEs
 * `broadcast_campaigns SET status='sent', sent_at=NOW()` and redirects with
 * `?success=sent&count={sentCount}` — `sentCount` is `-1` when `targetType==='all'` and LINE
 * returned 200 (see broadcastFanout.ts's module doc for that sentinel's origin), ported as-is
 * even though it renders as "(-1 คน)" in the success banner.
 */
export async function sendProductBroadcastAction(formData: FormData): Promise<SendProductBroadcastResult> {
  const { db, session } = await requireTenantPageContext();
  const currentBotId = await resolveCurrentBotId(db, session.currentBotId);

  const campaignId = Number.parseInt(first(formData, 'campaign_id'), 10) || 0;
  const targetTypeRaw = first(formData, 'target_type');
  const targetType: 'all' | 'tags' = targetTypeRaw === 'tags' ? 'tags' : 'all';
  const targetTagIds = formData
    .getAll('target_tags[]')
    .map((v) => Number.parseInt(String(v), 10))
    .filter((n) => Number.isFinite(n) && n > 0);

  // `redirect()` is deliberately called OUTSIDE this try/catch (same established convention as
  // (tenant)/line-groups/actions.ts and (tenant)/settings/_lib/welcome-actions.ts): it works by
  // throwing a special Next-internal error to abort rendering, so catching broadly here would
  // require detecting-and-rethrowing that internal error to avoid mis-reporting a successful
  // send as "ส่ง Broadcast ไม่สำเร็จ" — computing `sentCount` inside the try and redirecting
  // after it returns sidesteps that entirely.
  let sentCount: number;
  try {
    const campaignResult = await sql<CampaignRow>`
      SELECT id, name FROM broadcast_campaigns WHERE id = ${campaignId}
    `.execute(db);
    const campaign = campaignResult.rows[0];
    if (!campaign) {
      throw new Error(SEND_BROADCAST_ERRORS.campaignNotFound);
    }

    const itemsResult = await sql<CampaignItemRow>`
      SELECT item_name, item_image, item_price, postback_data FROM broadcast_items
      WHERE broadcast_id = ${campaignId} ORDER BY sort_order
    `.execute(db);
    if (itemsResult.rows.length === 0) {
      throw new Error(SEND_BROADCAST_ERRORS.noItems);
    }

    const flexMessage = buildProductCarousel(campaign.name, itemsResult.rows);

    const tokenResult = await sql<{ channel_access_token: string }>`
      SELECT channel_access_token FROM line_accounts WHERE id = ${currentBotId} LIMIT 1
    `.execute(db);
    const token = tokenResult.rows[0]?.channel_access_token;
    if (!token) {
      throw new Error('ไม่พบการเชื่อมต่อ LINE OA (line_account_id ไม่ถูกต้อง)');
    }

    const result = await executeProductBroadcastSend({
      db,
      currentBotId,
      lineOptions: { channelAccessToken: token },
      targetType,
      messages: [flexMessage],
      targetTagIds,
    });
    sentCount = result.sentCount;

    await sql`
      UPDATE broadcast_campaigns SET status = 'sent', sent_at = NOW() WHERE id = ${campaignId}
    `.execute(db);
  } catch (err) {
    // products.php:194-196: `$error = 'ส่ง Broadcast ไม่สำเร็จ: ' . $e->getMessage();`
    const message = err instanceof Error ? err.message : String(err);
    return { error: `ส่ง Broadcast ไม่สำเร็จ: ${message}` };
  }

  redirect(`/broadcast?tab=products&success=sent&count=${sentCount}`);
}

// ---------------------------------------------------------------------------
// action=delete_campaign — products.php lines 199-209
// ---------------------------------------------------------------------------

/**
 * Port of products.php lines 199-209 (`action === 'delete_campaign'`): a transactional
 * two-table delete (`broadcast_items` first, then `broadcast_campaigns` — FK-order-safe even
 * though the tenant template defines no FK constraint between the two), then redirects with
 * `?success=deleted`. Same "no re-render, redirect with `?error=`" divergence as
 * `createBroadcastAction` above (this action's `<form>` is also a plain Server Component
 * `action={deleteCampaignAction}` binding in `ProductsTab.tsx` — no client boundary to catch a
 * returned value). PHP's own `onsubmit="return confirm('ลบ Broadcast นี้?')"` JS confirm gate
 * is a client-only browser API and is NOT reproduced here (a documented, minor UX
 * simplification — the delete still requires an explicit button click, just without the extra
 * native confirm dialog; not a "fix", just an omission this port flags rather than silently
 * dropping).
 */
export async function deleteCampaignAction(formData: FormData): Promise<void> {
  const { db } = await requireTenantPageContext();
  const campaignId = Number.parseInt(first(formData, 'campaign_id'), 10) || 0;

  try {
    await db.transaction().execute(async (trx) => {
      await sql`DELETE FROM broadcast_items WHERE broadcast_id = ${campaignId}`.execute(trx);
      await sql`DELETE FROM broadcast_campaigns WHERE id = ${campaignId}`.execute(trx);
    });
  } catch (err) {
    const message = err instanceof Error ? err.message : String(err);
    redirect(`/broadcast?tab=products&error=${encodeURIComponent(`ลบไม่สำเร็จ: ${message}`)}`);
  }

  redirect('/broadcast?tab=products&success=deleted');
}
