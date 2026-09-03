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

/**
 * ส่งข้อความกลับเข้าห้องแชท OA ในนามผู้ใช้ — ขาย้อนกลับของ hybrid gateway
 * (Flex ในแชท -> เปิดหน้าใน Mini App -> ส่งคำสั่งกลับให้บอทตอบต่อในแชท)
 *
 * ต่างจาก shareMessagesOnMiniApp ตรงที่ไม่เปิด target picker: ยิงเข้าห้องที่
 * เปิด Mini App มาเท่านั้น จึงใช้ได้เฉพาะตอนอยู่ใน LINE จริง
 * คืน false เมื่อทำไม่ได้ ให้ผู้เรียกเลือก fallback เอง แทนที่จะโยน error
 */
export async function sendMessagesToChat(
  messages: Array<Record<string, unknown>>
): Promise<boolean> {
  const sdk = getLineSdk() as unknown as {
    isInClient?: () => boolean
    isApiAvailable?: (apiName: string) => boolean
    sendMessages?: (messages: Array<Record<string, unknown>>) => Promise<void>
  }

  if (!sdk.isInClient?.() || !sdk.isApiAvailable?.('sendMessages') || !sdk.sendMessages) {
    return false
  }

  try {
    await sdk.sendMessages(messages)
    return true
  } catch {
    return false
  }
}

export async function sendTextToChat(text: string): Promise<boolean> {
  return sendMessagesToChat([{ type: 'text', text }])
}

/** ปิดหน้าต่าง Mini App เพื่อให้ผู้ใช้เห็นคำตอบของบอทในแชททันที */
export function closeMiniApp(): void {
  const sdk = getLineSdk() as unknown as { closeWindow?: () => void }
  sdk.closeWindow?.()
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
