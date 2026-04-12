export const appConfig = {
  miniAppName: process.env.NEXT_PUBLIC_MINIAPP_NAME || 'Re-Ya LINE Mini App',
  liffId: process.env.NEXT_PUBLIC_LINE_LIFF_ID || '',
  channelId: process.env.NEXT_PUBLIC_LINE_CHANNEL_ID || '',
  apiBaseUrl: (process.env.NEXT_PUBLIC_PHP_API_BASE_URL || 'https://cny.re-ya.com').replace(/\/$/, ''),
  lineAccountId: Number(process.env.NEXT_PUBLIC_LINE_ACCOUNT_ID || '1')
}

export function apiUrl(path: string) {
  return `${appConfig.apiBaseUrl}${path.startsWith('/') ? path : `/${path}`}`
}
