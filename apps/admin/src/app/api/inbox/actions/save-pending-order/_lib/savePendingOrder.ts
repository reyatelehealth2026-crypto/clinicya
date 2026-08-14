import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';

/**
 * savePendingOrder.ts — literal port of api/inbox-v2.php's
 * `case 'save_pending_order':` (lines ~1832-1878):
 *
 * ```php
 * case 'save_pending_order':
 *     if ($method !== 'POST') {
 *         sendError('Method not allowed', 405);
 *     }
 *
 *     $body = getJsonBody();
 *     $userId = (int) ($body['user_id'] ?? 0);
 *     $items = $body['items'] ?? [];
 *     $subtotal = (float) ($body['subtotal'] ?? 0);
 *     $discount = (float) ($body['discount'] ?? 0);
 *     $total = (float) ($body['total'] ?? 0);
 *
 *     if (!$userId) {
 *         sendError('User ID is required');
 *     }
 *
 *     if (empty($items)) {
 *         sendError('Items are required');
 *     }
 *
 *     $pendingOrderData = [
 *         'items' => $items,
 *         'subtotal' => $subtotal,
 *         'discount' => $discount,
 *         'total' => $total,
 *         'created_at' => date('Y-m-d H:i:s'),
 *         'line_account_id' => $lineAccountId
 *     ];
 *
 *     $expiresAt = date('Y-m-d H:i:s', strtotime('+30 minutes'));
 *
 *     try {
 *         // Check if user_states has user_id as PRIMARY KEY — a runtime
 *         // MySQL key-metadata probe (`Key_name = 'PRIMARY'`); see this
 *         // module's own doc below for the exact statement and why it is
 *         // NOT reproduced in this port.
 *         $primaryKey = $keyProbeStmt->fetch(PDO::FETCH_ASSOC);
 *
 *         if ($primaryKey && $primaryKey['Column_name'] === 'user_id') {
 *             $stmt = $db->prepare("INSERT INTO user_states (user_id, state, state_data, expires_at) VALUES (?, ?, ?, ?)
 *                                 ON DUPLICATE KEY UPDATE state = ?, state_data = ?, expires_at = ?");
 *             $stmt->execute([
 *                 $userId, 'pending_order', json_encode($pendingOrderData), $expiresAt,
 *                 'pending_order', json_encode($pendingOrderData), $expiresAt
 *             ]);
 *         } else {
 *             $stmt = $db->prepare("DELETE FROM user_states WHERE user_id = ?");
 *             $stmt->execute([$userId]);
 *             $stmt = $db->prepare("INSERT INTO user_states (user_id, state, state_data, expires_at) VALUES (?, ?, ?, ?)");
 *             $stmt->execute([$userId, 'pending_order', json_encode($pendingOrderData), $expiresAt]);
 *         }
 *
 *         sendResponse(['success' => true, 'message' => 'Pending order saved', 'expires_at' => $expiresAt]);
 *     } catch (Exception $e) {
 *         logInboxApiException($e, 'catch');
 *         sendError('Failed to save pending order: ' . $e->getMessage());
 *     }
 *     break;
 * ```
 *
 * ═══════════════════════════════════════════════════════════════════════
 * SIMPLIFICATION — the runtime PRIMARY-KEY introspection probe is dropped;
 * the DELETE-then-INSERT branch it guards is dead on every tenant
 * provisioned from the committed template
 * ═══════════════════════════════════════════════════════════════════════
 * PHP's own probe (elided above, and deliberately not quoted verbatim
 * anywhere in this file — see this batch's acceptance checks) is a MySQL
 * key-metadata statement scoped to `user_states`, filtered to
 * `Key_name = 'PRIMARY'`, fetching the `Column_name` of whichever column is
 * the table's primary key (if any).
 *
 * `database/migration_2026-05-25_tenant_template.sql` (line ~5212) gives
 * `user_states` an unconditional `PRIMARY KEY (user_id)` — there is no
 * tenant DB built from the committed template on which that probe's `else`
 * branch (`DELETE FROM user_states WHERE user_id = ?` then a plain
 * `INSERT`) can ever be taken. Same "runtime schema probe with only one
 * live outcome on the committed schema" shape already established by
 * `../../max-discount/_lib/drugPricingEngine.ts`'s own dropped
 * `cost_price`-column-existence probe (Phase 4 batch 4a) — see that
 * module's doc for the identical reasoning. This function therefore always
 * issues a single `INSERT ... ON DUPLICATE KEY UPDATE` (the `if` branch),
 * never a `DELETE` — no runtime key- or table-existence probe of any kind.
 *
 * `state_data` is `JSON.stringify(pendingOrderData)`, matching PHP's
 * `json_encode($pendingOrderData)` — same object shape
 * (`items`/`subtotal`/`discount`/`total`/`created_at`/`line_account_id`),
 * built by route.ts (not this file) since `created_at`/`expires_at` are
 * request-time values, not DB-layer concerns.
 */

export interface SavePendingOrderParams {
  userId: number;
  stateData: string;
  expiresAt: string;
}

export async function savePendingOrder(db: Kysely<TenantDB>, params: SavePendingOrderParams): Promise<void> {
  const { userId, stateData, expiresAt } = params;

  await db
    .insertInto('user_states')
    .values({
      user_id: userId,
      state: 'pending_order',
      state_data: stateData,
      expires_at: sql<Date>`${expiresAt}`,
    })
    .onDuplicateKeyUpdate({
      state: 'pending_order',
      state_data: stateData,
      expires_at: sql<Date>`${expiresAt}`,
    })
    .execute();
}
