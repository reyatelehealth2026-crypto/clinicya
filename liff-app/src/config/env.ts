export const env = {
  LIFF_ID: import.meta.env.VITE_LIFF_ID as string || '',
  API_BASE_URL: (import.meta.env.VITE_API_BASE_URL as string || '').replace(/\/$/, ''),
  DEFAULT_ACCOUNT_ID: Number(import.meta.env.VITE_DEFAULT_ACCOUNT_ID) || 1,
}
