import { createHmac } from 'node:crypto';
import { loadEnv } from '@reya/config';
import type { AuthResult, BridgeSyncPayload } from './types';

/**
 * bridgeClient.ts — POSTs to internal/session-bridge.php with an
 * `X-Reya-Signature: HMAC-SHA256(body, SESSION_BRIDGE_HMAC_SECRET)` header,
 * where `body` is the exact raw JSON string sent (the whole BridgeSyncPayload,
 * `issuedAt` included — signing the whole body covers the replay-window
 * timestamp too, so there's no separate "timestamp.payload" concatenation
 * step like api/odoo-webhook.php's pattern uses).
 *
 * NEVER throws — any network/DNS/timeout/non-2xx/malformed-response failure
 * resolves {ok:false, error:{code:'bridge_unreachable'}} so login()/
 * switchBot()/switchTenant()/logout() can all complete their Node-side state
 * change regardless of PHP-side bridge health (Node is the source of truth
 * in Phase 1, plan §1.4).
 */
export async function syncToPhpBridge(payload: BridgeSyncPayload): Promise<AuthResult<{ acknowledged: true }>> {
  const env = loadEnv();
  const body = JSON.stringify(payload);
  const signature = createHmac('sha256', env.SESSION_BRIDGE_HMAC_SECRET).update(body).digest('hex');

  try {
    const response = await fetch(env.SESSION_BRIDGE_URL, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Reya-Signature': signature,
      },
      body,
    });

    if (!response.ok) {
      return { ok: false, error: { code: 'bridge_unreachable' } };
    }

    const json = (await response.json().catch(() => null)) as { acknowledged?: boolean } | null;
    if (!json || json.acknowledged !== true) {
      return { ok: false, error: { code: 'bridge_unreachable' } };
    }

    return { ok: true, value: { acknowledged: true } };
  } catch {
    // Network error, DNS failure, connection refused/timeout, etc.
    return { ok: false, error: { code: 'bridge_unreachable' } };
  }
}
