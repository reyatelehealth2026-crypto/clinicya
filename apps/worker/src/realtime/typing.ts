/**
 * realtime/typing.ts — ports websocket-server.js's in-memory typing-indicator
 * bookkeeping (that file's `typingIndicators` Map, `getConversationKey()`,
 * `cleanupTypingIndicators()`, and the disconnect-time cleanup loop inside
 * its `socket.on('disconnect', ...)` handler) into apps/worker. DB-free,
 * duck-typed, no compile-time dependency on `socket.io` — same one-file-
 * per-concern posture as realtime/inboxRelay.ts.
 *
 * DELIBERATE ADAPTATION FROM LEGACY (not an oversight — see this batch's
 * agent brief):
 *
 * Legacy's inner map is keyed by `socket.userId`, the AUTHENTICATED admin's
 * user ID (`authenticateToken()` sets `socket.userId` once at connection
 * time, and a legacy socket only ever belongs to ONE `account_<id>` room —
 * `socket.lineAccountId` is fixed for that socket's whole lifetime). This
 * batch's socketServer.ts has no auth (explicitly out of scope this round)
 * and a socket MAY join multiple `account_<lineAccountId>` rooms, so there
 * is no stable per-connection "admin identity" to key on, and the
 * conversation a `typing` event belongs to must be identified by an
 * EXPLICIT `lineAccountId` in the event payload rather than implicit
 * `socket.lineAccountId` connection state.
 *
 * This module's inner map is therefore keyed by `socket.id` (Socket.io's
 * own always-present per-connection identity — needs no auth) instead of
 * an admin user ID, and `conversationKey()` is built from the explicit
 * `lineAccountId` carried on every `typing` payload. The OUTER shape —
 * `Map<conversationKey, Map<innerKey, timestamp>>`, a 5s TTL
 * (`TYPING_TTL_MS`), and a 2s cleanup cadence (`TYPING_CLEANUP_INTERVAL_MS`)
 * — is otherwise a straight port of legacy's `typingIndicators` /
 * `cleanupTypingIndicators()` semantics, INCLUDING that TTL expiry is
 * silent (no broadcast) — legacy's `cleanupTypingIndicators()` only ever
 * deletes stale entries from its Map, it never emits a "stopped typing"
 * notice on expiry (clients are expected to treat "no typing:true refresh
 * within 5s" as implicit stop). Only two call sites broadcast a `typing`
 * event: an explicit `is_typing:false` from the client, and this module's
 * `clearSocket()` on disconnect (ported from legacy's disconnect handler,
 * which DOES notify the room for each conversation the disconnecting user
 * was typing in).
 */

export const TYPING_TTL_MS = 5000;
export const TYPING_CLEANUP_INTERVAL_MS = 2000;

export interface TypingPayload {
  lineAccountId: number;
  user_id: string | number;
  is_typing: boolean;
}

/** Runtime guard for the `typing` socket event's payload — mirrors socketServer.ts's existing `isJoinAccountPayload` posture (never throw on an untrusted client payload, just decline to act on it). */
export function isTypingPayload(payload: unknown): payload is TypingPayload {
  if (typeof payload !== 'object' || payload === null) {
    return false;
  }
  const p = payload as Record<string, unknown>;
  return (
    typeof p.lineAccountId === 'number' &&
    (typeof p.user_id === 'string' || typeof p.user_id === 'number') &&
    typeof p.is_typing === 'boolean'
  );
}

export interface TypingIndicatorEntry {
  lineAccountId: number;
  userId: string;
}

function conversationKey(lineAccountId: number, userId: string | number): string {
  return `${lineAccountId}:${userId}`;
}

/** Inverse of conversationKey() — conversationKey() never emits more than one ':' since lineAccountId is always numeric, so splitting on the FIRST ':' recovers both parts even if userId itself happens to contain one. */
function splitConversationKey(key: string): TypingIndicatorEntry {
  const separatorIndex = key.indexOf(':');
  return {
    lineAccountId: Number(key.slice(0, separatorIndex)),
    userId: key.slice(separatorIndex + 1),
  };
}

/**
 * Owns the typing-indicator Map + TTL cleanup interval. One instance is
 * created per realtime server (realtime/socketServer.ts) and wired into its
 * 'typing' handler, its 'disconnect' handler, and its /health + /status
 * responses (`typingIndicators` field — count of distinct conversations
 * with at least one active typer, matching legacy's `typingIndicators.size`
 * in its /status handler).
 */
export class TypingIndicatorTracker {
  // conversationKey (`${lineAccountId}:${user_id}`) -> (socket.id -> last-seen timestamp)
  private readonly indicators = new Map<string, Map<string, number>>();
  private cleanupTimer: NodeJS.Timeout | undefined;

  /** Records/refreshes (`is_typing: true`) or clears (`is_typing: false`) one socket's typing state for a conversation. Mirrors legacy's `socket.on('typing', ...)` Map-mutation branch (before that handler's broadcast, which stays in socketServer.ts). */
  set(socketId: string, payload: TypingPayload): void {
    const key = conversationKey(payload.lineAccountId, payload.user_id);
    if (payload.is_typing) {
      let bySocket = this.indicators.get(key);
      if (!bySocket) {
        bySocket = new Map();
        this.indicators.set(key, bySocket);
      }
      bySocket.set(socketId, Date.now());
    } else {
      const bySocket = this.indicators.get(key);
      if (bySocket) {
        bySocket.delete(socketId);
        if (bySocket.size === 0) {
          this.indicators.delete(key);
        }
      }
    }
  }

  /** Removes every typing entry belonging to `socketId` (any conversation/account room), returning one entry per conversation it was typing in so the caller (socketServer.ts's 'disconnect' handler) can notify each of those rooms — mirrors legacy's disconnect-handler loop over `typingIndicators.entries()`. */
  clearSocket(socketId: string): TypingIndicatorEntry[] {
    const removed: TypingIndicatorEntry[] = [];
    for (const [key, bySocket] of this.indicators.entries()) {
      if (bySocket.delete(socketId)) {
        removed.push(splitConversationKey(key));
        if (bySocket.size === 0) {
          this.indicators.delete(key);
        }
      }
    }
    return removed;
  }

  /** Number of distinct conversations with at least one active typer — same metric legacy's /status endpoint exposes as `typingIndicators.size`. */
  get size(): number {
    return this.indicators.size;
  }

  /** Silently removes entries older than TYPING_TTL_MS — a direct port of legacy's `cleanupTypingIndicators()`, including that it never broadcasts on expiry (see this file's module doc comment). Exposed (not private) so tests can drive it deterministically without needing a live interval/timer. */
  cleanupExpired(now: number = Date.now()): void {
    for (const [key, bySocket] of this.indicators.entries()) {
      for (const [socketId, timestamp] of bySocket.entries()) {
        if (now - timestamp > TYPING_TTL_MS) {
          bySocket.delete(socketId);
        }
      }
      if (bySocket.size === 0) {
        this.indicators.delete(key);
      }
    }
  }

  /** Starts the 2s cleanup interval — mirrors legacy's `setInterval(cleanupTypingIndicators, 2000)`. `.unref()`s the timer so it never keeps the process (or a test runner) alive on its own. */
  startCleanupInterval(): void {
    if (this.cleanupTimer) {
      return;
    }
    this.cleanupTimer = setInterval(() => this.cleanupExpired(), TYPING_CLEANUP_INTERVAL_MS);
    this.cleanupTimer.unref?.();
  }

  stopCleanupInterval(): void {
    if (this.cleanupTimer) {
      clearInterval(this.cleanupTimer);
      this.cleanupTimer = undefined;
    }
  }
}
