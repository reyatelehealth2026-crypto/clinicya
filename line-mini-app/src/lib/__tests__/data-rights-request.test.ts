import { test } from 'node:test'
import assert from 'node:assert/strict'
import {
  buildExportDataRequest,
  buildRequestDeletionRequest,
  buildWithdrawConsentRequest,
} from '../data-rights-request.ts'

// The PHP endpoint (api/data-rights.php) resolves the user server-side from
// (line_user_id, line_account_id) and NEVER trusts a client user_id. These
// tests lock the request contract: the client must always send the LINE
// identity + the tenant's line_account_id, and must NEVER send a user_id.

test('withdraw_consent request carries identity + default health_data consent, no user_id', () => {
  const body = buildWithdrawConsentRequest('Uabc', 7)
  assert.deepEqual(body, {
    action: 'withdraw_consent',
    line_user_id: 'Uabc',
    line_account_id: 7,
    consent_type: 'health_data',
  })
  assert.ok(!('user_id' in body), 'must never send a client user_id')
})

test('withdraw_consent honours an explicit consent_type', () => {
  const body = buildWithdrawConsentRequest('Uabc', 7, 'marketing')
  assert.equal(body.consent_type, 'marketing')
})

test('request_deletion omits reason when not provided', () => {
  const body = buildRequestDeletionRequest('Uabc', 7)
  assert.deepEqual(body, {
    action: 'request_deletion',
    line_user_id: 'Uabc',
    line_account_id: 7,
  })
  assert.ok(!('reason' in body), 'reason should be absent when empty')
})

test('request_deletion includes a non-empty reason', () => {
  const body = buildRequestDeletionRequest('Uabc', 7, 'ไม่อยากใช้แล้ว')
  assert.equal(body.reason, 'ไม่อยากใช้แล้ว')
})

test('request_deletion treats empty-string reason as absent', () => {
  const body = buildRequestDeletionRequest('Uabc', 7, '')
  assert.ok(!('reason' in body))
})

test('export_data request carries only identity, no user_id', () => {
  const body = buildExportDataRequest('Uabc', 7)
  assert.deepEqual(body, {
    action: 'export_data',
    line_user_id: 'Uabc',
    line_account_id: 7,
  })
  assert.ok(!('user_id' in body), 'must never send a client user_id')
})
