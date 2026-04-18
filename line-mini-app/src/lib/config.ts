/** In-app Live Chat widget (SaaS script URL). Separate from LINE OA chat URL. */
function liveChatScriptSrc(): string {
  return (process.env.NEXT_PUBLIC_LIVECHAT_SCRIPT_SRC || '').trim()
}

/** LINE Official Account chat URL (e.g. https://line.me/R/oaMessage/@handle). Not used for /livechat. */
function lineOaChatUrl(): string {
  return (process.env.NEXT_PUBLIC_LINE_OA_CHAT_URL || '').trim()
}

export const appConfig = {
  miniAppName: process.env.NEXT_PUBLIC_MINIAPP_NAME || 'Re-Ya LINE Mini App',
  liffId: process.env.NEXT_PUBLIC_LINE_LIFF_ID || '',
  channelId: process.env.NEXT_PUBLIC_LINE_CHANNEL_ID || '',
  apiBaseUrl: (process.env.NEXT_PUBLIC_PHP_API_BASE_URL || 'https://cny.re-ya.com').replace(/\/$/, ''),
  lineAccountId: Number(process.env.NEXT_PUBLIC_LINE_ACCOUNT_ID || '1'),
  /** Human agent chat inside Mini App WebView — load external widget script when set */
  liveChatScriptSrc: liveChatScriptSrc(),
  /** True when Live Chat route should be reachable and entry points may be shown */
  isLiveChatConfigured: liveChatScriptSrc().length > 0,
  /** OA deep link for “แชทผ่านไลน์” — opens outside in-app chat room */
  lineOaChatUrl: lineOaChatUrl(),
  isLineOaChatConfigured: lineOaChatUrl().length > 0,
  shopCatalog: {
    hideZeroPriceProducts: process.env.NEXT_PUBLIC_SHOP_HIDE_ZERO_PRICE === '1',
    hideInactiveProducts: process.env.NEXT_PUBLIC_SHOP_HIDE_INACTIVE === '1',
    mode: process.env.NEXT_PUBLIC_SHOP_CATALOG_MODE || 'all',
    defaultBucket: process.env.NEXT_PUBLIC_SHOP_CATALOG_BUCKET || ''
  }
}

export function apiUrl(path: string) {
  return `${appConfig.apiBaseUrl}${path.startsWith('/') ? path : `/${path}`}`
}
