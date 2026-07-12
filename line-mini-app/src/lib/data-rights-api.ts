// ─────────────────────────────────────────────────────────────────────────────
// PDPA data-subject rights API client (พ.ร.บ. คุ้มครองข้อมูลส่วนบุคคล).
//
// Calls api/data-rights.php on the PHP monolith. The endpoint resolves the user
// server-side from (line_user_id, line_account_id) and NEVER trusts a raw
// user_id — this client therefore only ever sends the LINE identity, not an id.
// ─────────────────────────────────────────────────────────────────────────────

import { appConfig } from '@/lib/config'
import { phpPost } from '@/lib/php-bridge'
import {
  buildExportDataRequest,
  buildRequestDeletionRequest,
  buildWithdrawConsentRequest
} from '@/lib/data-rights-request'

export type { DataRightsAction } from '@/lib/data-rights-request'

interface BaseResponse {
  success: boolean
  message: string
}

export interface WithdrawConsentResponse extends BaseResponse {
  consent_type?: string
}

export interface RequestDeletionResponse extends BaseResponse {
  confirmation_code?: string
  status?: string
}

/** Shape returned by export_data. Fields are best-effort — treat as opaque JSON. */
export interface ExportData {
  export_meta: {
    generated_at: string
    standard: string
    user_id: number | null
  }
  profile: Record<string, unknown>
  consents: Array<Record<string, unknown>>
  consent_history: Array<Record<string, unknown>>
  chat_history: Array<Record<string, unknown>>
  orders: Array<Record<string, unknown>>
}

export interface ExportDataResponse extends BaseResponse {
  data?: ExportData
}

/** Withdraw a consent (defaults to health_data — the PDPA-sensitive one). */
export function withdrawConsent(
  lineUserId: string,
  consentType: string = 'health_data'
): Promise<WithdrawConsentResponse> {
  return phpPost<WithdrawConsentResponse>(
    '/api/data-rights.php',
    buildWithdrawConsentRequest(lineUserId, appConfig.lineAccountId, consentType)
  )
}

/** Request account/data deletion (SOFT flag — returns a confirmation code). */
export function requestDeletion(
  lineUserId: string,
  reason?: string
): Promise<RequestDeletionResponse> {
  return phpPost<RequestDeletionResponse>(
    '/api/data-rights.php',
    buildRequestDeletionRequest(lineUserId, appConfig.lineAccountId, reason)
  )
}

/** Export the caller's own data as JSON. */
export function exportData(lineUserId: string): Promise<ExportDataResponse> {
  return phpPost<ExportDataResponse>(
    '/api/data-rights.php',
    buildExportDataRequest(lineUserId, appConfig.lineAccountId)
  )
}

/**
 * Trigger a client-side download of an arbitrary JSON payload. Kept here so the
 * privacy page stays declarative. No-op outside the browser.
 */
export function downloadJson(filename: string, payload: unknown): void {
  if (typeof window === 'undefined' || typeof document === 'undefined') return
  const blob = new Blob([JSON.stringify(payload, null, 2)], {
    type: 'application/json;charset=utf-8'
  })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = filename
  document.body.appendChild(a)
  a.click()
  document.body.removeChild(a)
  URL.revokeObjectURL(url)
}
