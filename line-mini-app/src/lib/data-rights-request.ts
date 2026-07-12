// ─────────────────────────────────────────────────────────────────────────────
// Pure request-body builders for the PDPA data-rights endpoint. Kept free of the
// `@/` alias imports (config/php-bridge) so the endpoint contract is unit-testable
// under the project's node --test runner. data-rights-api.ts wraps these with the
// runtime line_account_id + phpPost transport.
// ─────────────────────────────────────────────────────────────────────────────

export type DataRightsAction = 'withdraw_consent' | 'request_deletion' | 'export_data'

// Index signatures keep these structurally compatible with the phpPost()
// transport's `Record<string, unknown>` body param without casts.
export interface WithdrawConsentRequest {
  action: 'withdraw_consent'
  line_user_id: string
  line_account_id: number
  consent_type: string
  [key: string]: unknown
}

export interface RequestDeletionRequest {
  action: 'request_deletion'
  line_user_id: string
  line_account_id: number
  reason?: string
  [key: string]: unknown
}

export interface ExportDataRequest {
  action: 'export_data'
  line_user_id: string
  line_account_id: number
  [key: string]: unknown
}

export function buildWithdrawConsentRequest(
  lineUserId: string,
  lineAccountId: number,
  consentType: string = 'health_data'
): WithdrawConsentRequest {
  return {
    action: 'withdraw_consent',
    line_user_id: lineUserId,
    line_account_id: lineAccountId,
    consent_type: consentType
  }
}

export function buildRequestDeletionRequest(
  lineUserId: string,
  lineAccountId: number,
  reason?: string
): RequestDeletionRequest {
  const body: RequestDeletionRequest = {
    action: 'request_deletion',
    line_user_id: lineUserId,
    line_account_id: lineAccountId
  }
  if (reason !== undefined && reason !== '') {
    body.reason = reason
  }
  return body
}

export function buildExportDataRequest(
  lineUserId: string,
  lineAccountId: number
): ExportDataRequest {
  return {
    action: 'export_data',
    line_user_id: lineUserId,
    line_account_id: lineAccountId
  }
}
