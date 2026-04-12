'use client'

import liff from '@line/liff'
import { appConfig } from '@/lib/config'
import type { LineBootstrapState, MiniAppCapabilities } from '@/types/line'

export async function bootstrapLine(): Promise<LineBootstrapState> {
  const baseState: LineBootstrapState = {
    isReady: false,
    isLoggedIn: false,
    isInClient: false,
    profile: null,
    accessToken: null,
    error: null
  }

  if (!appConfig.liffId) {
    return {
      ...baseState,
      isReady: true,
      error: 'LIFF ID is not configured'
    }
  }

  try {
    await liff.init({ liffId: appConfig.liffId })

    if (!liff.isLoggedIn()) {
      return {
        ...baseState,
        isReady: true,
        isLoggedIn: false,
        needsLogin: true
      }
    }

    const profile = await liff.getProfile()

    return {
      isReady: true,
      isLoggedIn: true,
      isInClient: liff.isInClient(),
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
