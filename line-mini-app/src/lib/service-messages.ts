import { phpPost } from '@/lib/php-bridge'
import { appConfig } from '@/lib/config'

export async function saveNotificationPreference(lineUserId: string, enabled: boolean) {
  return phpPost<{ success: boolean; message: string }>('/api/member-notifications.php', {
    action: enabled ? 'opt_in' : 'opt_out',
    line_user_id: lineUserId,
    line_account_id: appConfig.lineAccountId,
  })
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
