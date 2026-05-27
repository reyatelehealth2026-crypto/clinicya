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

export const NOTIFICATION_CATEGORIES = [
  'order_updates',
  'promotions',
  'appointment_reminders',
  'med_reminders',
  'health_tips',
  'price_alerts',
  'restock_alerts',
] as const

export type NotificationCategory = (typeof NOTIFICATION_CATEGORIES)[number]
export type NotificationPreferences = Record<NotificationCategory, boolean>

export interface NotificationPreferencesResponse {
  success: boolean
  message: string
  enabled?: boolean
  preferences?: NotificationPreferences
}

async function postNotifications(payload: Record<string, unknown>): Promise<NotificationPreferencesResponse> {
  const response = await fetch(NOTIFICATIONS_URL, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      line_account_id: appConfig.lineAccountId,
      ...payload,
    }),
  })

  if (!response.ok) {
    throw new Error(`Notification API failed: HTTP ${response.status}`)
  }

  return response.json() as Promise<NotificationPreferencesResponse>
}

export async function getNotificationPreferences(lineUserId: string): Promise<NotificationPreferencesResponse> {
  return postNotifications({
    action: 'get_preferences',
    line_user_id: lineUserId,
  })
}

export async function setNotificationPreference(
  lineUserId: string,
  category: NotificationCategory,
  enabled: boolean,
): Promise<NotificationPreferencesResponse> {
  return postNotifications({
    action: 'set_preference',
    line_user_id: lineUserId,
    category,
    enabled,
  })
}

export async function setNotificationPreferences(
  lineUserId: string,
  prefs: Partial<NotificationPreferences>,
): Promise<NotificationPreferencesResponse> {
  return postNotifications({
    action: 'set_preferences',
    line_user_id: lineUserId,
    preferences: prefs,
  })
}
