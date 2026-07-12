/**
 * PDPA health-data consent for the Mini App (issue #15).
 *
 * Wraps the existing `api/consent.php` endpoint (action=save). Recording a
 * `health_data` consent is required before the AI consultation stores the
 * user's symptom/health history (PDPA มาตรา 26).
 *
 * Response contract (api/consent.php):
 * ```
 * { success: true, message: 'Consent saved', user_id: number }
 * ```
 */

import { apiUrl, appConfig } from '@/lib/config'

interface SaveConsentResponse {
  success?: boolean
  user_id?: number
}

/**
 * Record the user's decision on the `health_data` consent.
 * Returns true on success, false on network/server failure.
 */
export async function saveHealthDataConsent(
  lineUserId: string | null | undefined,
  accepted: boolean,
  accessToken?: string | null
): Promise<boolean> {
  if (!lineUserId) return false
  const url = apiUrl('/api/consent.php')
  try {
    const headers: Record<string, string> = { 'Content-Type': 'application/json' }
    if (accessToken) headers.Authorization = `Bearer ${accessToken}`
    const response = await fetch(url, {
      method: 'POST',
      headers,
      body: JSON.stringify({
        action: 'save',
        line_user_id: lineUserId,
        line_account_id: appConfig.lineAccountId,
        consents: { health_data: accepted }
      })
    })
    if (!response.ok) return false
    const data = (await response.json()) as SaveConsentResponse
    return data.success === true
  } catch {
    return false
  }
}
