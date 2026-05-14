import { apiUrl, appConfig } from '@/lib/config'

const NOTIFICATIONS_URL = apiUrl('/api/member-notifications.php')

/** PHP member-notifications.php sends CORS headers, so we call it directly from the browser. */
export async function saveNotificationPreference(lineUserId: string, enabled: boolean) {
  const response = await fetch(NOTIFICATIONS_URL, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      action: enabled ? 'opt_in' : 'opt_out',
      line_user_id: lineUserId,
      line_account_id: appConfig.lineAccountId,
    }),
  })

  if (!response.ok) {
    throw new Error(`Notification API failed: HTTP ${response.status}`)
  }

  return response.json() as Promise<{ success: boolean; message: string }>
}

export async function openLineOA() {
  const { default: liff } = await import('@line/liff')
  const channelId = appConfig.channelId
  const url = `https://line.me/R/ti/p/${channelId}`
  if (liff.isInClient()) {
    liff.openWindow({ url, external: false })
  } else {
    window.open(url, '_blank')
  }
}
