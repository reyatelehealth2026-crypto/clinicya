'use client'

import liff from '@line/liff'
import { resolveLineAccountId, resolveLiffId } from '@/lib/config'
import type { LineBootstrapState, MiniAppCapabilities } from '@/types/line'

export async function bootstrapLine(): Promise<LineBootstrapState> {
  const baseState: LineBootstrapState = {
    isReady: false,
    isLoggedIn: false,
    isInClient: false,
    isGuest: false,
    profile: null,
    accessToken: null,
    error: null
  }

  // Resolve the correct LIFF id for THIS host (tenant) before init — using the
  // baked id on every host makes login bounce to the wrong tenant's endpoint.
  const liffId = await resolveLiffId()
  if (!liffId) {
    return {
      ...baseState,
      isReady: true,
      error: 'LIFF ID is not configured'
    }
  }

  try {
    await liff.init({ liffId, withLoginOnExternalBrowser: false })

    // Resolve which tenant (line_account) this shared Mini App is serving.
    // Priority: ?la= / localStorage (handled inside) → resolver API via LIFF id.
    // Best-effort: never blocks bootstrap on failure.
    try {
      const ctxLiffId =
        (liff as unknown as { id?: string }).id ||
        liff.getContext?.()?.liffId ||
        liffId
      await resolveLineAccountId(ctxLiffId ?? null)
    } catch {
      /* keep going with the build-time default */
    }

    if (!liff.isLoggedIn()) {
      return {
        ...baseState,
        isReady: true,
        isLoggedIn: false,
        isGuest: true
      }
    }

    const profile = await liff.getProfile()

    return {
      isReady: true,
      isLoggedIn: true,
      isInClient: liff.isInClient(),
      isGuest: false,
      profile: {
        userId: profile.userId,
        displayName: profile.displayName,
        pictureUrl: profile.pictureUrl,
        statusMessage: profile.statusMessage
      },
      accessToken: liff.getAccessToken() || null,
      error: null
    }
  } catch (error) {
    return {
      ...baseState,
      isReady: true,
      error: error instanceof Error ? error.message : 'Failed to initialize LIFF'
    }
  }
}

export function getMiniAppCapabilities(): MiniAppCapabilities {
  const hasCommonProfile = typeof window !== 'undefined' && 'liff' in window

  return {
    canUseQuickFill: Boolean(hasCommonProfile),
    canUseServiceMessages: false,
    canUseIap: false
  }
}

export function getLineSdk() {
  return liff
}

export async function shareTextOnMiniApp(text: string) {
  return shareMessagesOnMiniApp([{ type: 'text', text }], text)
}

export async function shareMessagesOnMiniApp(
  messages: Array<Record<string, unknown>>,
  fallbackText?: string
) {
  const sdk = getLineSdk() as unknown as {
    isApiAvailable?: (apiName: string) => boolean
    shareTargetPicker?: (messages: Array<Record<string, unknown>>) => Promise<unknown>
  }

  if (sdk.isApiAvailable?.('shareTargetPicker') && sdk.shareTargetPicker) {
    await sdk.shareTargetPicker(messages)
    return 'line'
  }

  if (typeof navigator !== 'undefined' && navigator.share && fallbackText) {
    await navigator.share({ text: fallbackText })
    return 'web'
  }

  throw new Error('อุปกรณ์นี้ยังไม่รองรับการแชร์')
}
