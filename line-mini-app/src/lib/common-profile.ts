'use client'

import { getLineSdk } from '@/lib/line-miniapp'

const QUICK_FILL_ERROR = 'Common Profile Quick-fill ต้องใช้ verified LINE Mini App และเปิด plugin ให้พร้อมก่อน'

export async function getCommonProfile(scopes: string[]) {
  const liff = getLineSdk() as unknown as {
    $commonProfile?: {
      get: (requestedScopes: string[]) => Promise<unknown>
    }
  }

  if (!liff.$commonProfile?.get) {
    throw new Error(QUICK_FILL_ERROR)
  }

  return liff.$commonProfile.get(scopes)
}

export function getQuickFillUnavailableReason() {
  return QUICK_FILL_ERROR
}
