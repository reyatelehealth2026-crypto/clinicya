<?php
/**
 * BDO Slip Contract — Canonical definitions for the BDO/Slip matching flow.
 *
 * SOURCE OF TRUTH RULES:
 *  1. Odoo is the authoritative source for all match state.
 *     This repo only caches read-model data; it NEVER fabricates a "matched" outcome.
 *  2. slip_inbox_id (Odoo Slip Inbox record ID) is the canonical slip identifier
 *     for all match/unmatch operations. Never use local odoo_slip_uploads.id for Odoo calls.
 *  3. bdo_id (Odoo BDO record ID) is the canonical BDO identifier.
 *  4. Match/unmatch mutations MUST succeed at Odoo before any local cache update.
 *     If Odoo is unreachable, return an error — do NOT silently succeed locally.
 *  5. BDO context per customer is keyed by (line_user_id, bdo_id).
 *     A customer can have multiple open BDOs; always resolve to the correct one.
 *  6. Stale context (bdo.done / bdo.cancelled) must be closed immediately on webhook receipt.
 *
 * MATCH CONFIDENCE VALUES (from Odoo):
 *  - exact          : amount matches invoice 100%
 *  - partial        : partial payment against invoice
 *  - multi          : amount matches sum of multiple invoices
 *  - bdo_prepayment : amount matches BDO net-to-pay (private delivery, no invoice yet)
 *  - manual         : requires Sales decision
 *  - unmatched      : no match found
 *
 * SLIP STATUS LIFECYCLE (Odoo):
 *  new → matched → payment_created → posted → done
 *
 * BDO STATE LIFECYCLE (Odoo):
 *  draft → waiting → done | cancel
 *
 * DELIVERY TYPES:
 *  - company : pay-after-delivery (สายส่ง)
 *  - private : prepayment required (ขนส่งเอกชน — Kerry, Flash, DHL)
 *
 * VALIDATION RULES (must be enforced before calling Odoo):
 *  - slip_inbox_id must be a positive integer
 *  - bdo_id must be a positive integer
 *  - matches array must not be empty for match operations
 *  - sum(match amounts) must not exceed slip amount (client-side guard)
 *  - unmatch is only allowed when slip status is NOT 'posted' or 'done'
 *
 * @version 1.0.0 (March 2026 — cny_reya_connector v11.0.1.3.0)
 */

class BdoSlipContract
{
    // ── Match confidence values ──────────────────────────────────────────────
    const CONFIDENCE_EXACT          = 'exact';
    const CONFIDENCE_PARTIAL        = 'partial';
    const CONFIDENCE_MULTI          = 'multi';
    const CONFIDENCE_BDO_PREPAYMENT = 'bdo_prepayment';
    const CONFIDENCE_MANUAL         = 'manual';
    const CONFIDENCE_UNMATCHED      = 'unmatched';

    // ── Slip status values ───────────────────────────────────────────────────
    const SLIP_STATUS_PENDING_LEGACY  = 'pending';
    const SLIP_STATUS_NEW             = 'new';
    const SLIP_STATUS_MATCHED         = 'matched';
    const SLIP_STATUS_PAYMENT_CREATED = 'payment_created';
    const SLIP_STATUS_POSTED          = 'posted';
    const SLIP_STATUS_DONE            = 'done';
    const SLIP_STATUS_FAILED          = 'failed';

    // Statuses where unmatch is blocked
    const SLIP_UNMATCH_BLOCKED = [self::SLIP_STATUS_POSTED, self::SLIP_STATUS_DONE];
    const SLIP_OPEN_STATUSES = [self::SLIP_STATUS_NEW, self::SLIP_STATUS_PENDING_LEGACY];

    // ── BDO state values ─────────────────────────────────────────────────────
    const BDO_STATE_DRAFT   = 'draft';
    const BDO_STATE_WAITING = 'waiting';
    const BDO_STATE_DONE    = 'done';
    const BDO_STATE_CANCEL  = 'cancel';

    // BDO states where matching is allowed
    const BDO_MATCHABLE_STATES = [self::BDO_STATE_WAITING];

    // BDO states that should close the context
    const BDO_CONTEXT_CLOSE_STATES = [self::BDO_STATE_DONE, self::BDO_STATE_CANCEL];

    // ── Delivery types ───────────────────────────────────────────────────────
    const DELIVERY_COMPANY = 'company';
    const DELIVERY_PRIVATE = 'private';

    // ── Odoo API endpoints ───────────────────────────────────────────────────
    const ENDPOINT_SLIP_UPLOAD   = '/reya/slip/upload';
    const ENDPOINT_SLIP_MATCH    = '/reya/slip/match-bdo';
    const ENDPOINT_SLIP_UNMATCH  = '/reya/slip/unmatch';
    const ENDPOINT_BDO_LIST      = '/reya/bdo/list';
    const ENDPOINT_BDO_DETAIL    = '/reya/bdo/detail';
    const ENDPOINT_BDO_PDF       = '/reya/bdo/statement-pdf/';
    const ENDPOINT_PAYMENT_STATUS = '/reya/payment/status';

    // ── Webhook events ───────────────────────────────────────────────────────
    const EVENT_BDO_CONFIRMED = 'bdo.confirmed';
    const EVENT_BDO_DONE      = 'bdo.done';
    const EVENT_BDO_CANCELLED = 'bdo.cancelled';

    // ── Amount tolerance for auto-accept (configurable) ──────────────────────
    // 0 = strict (no tolerance). Set via ODOO_BDO_AMOUNT_TOLERANCE_THB in config.
    const DEFAULT_AMOUNT_TOLERANCE_THB = 0;

    // ── Local cache table names ──────────────────────────────────────────────
    const TABLE_BDO_CONTEXT  = 'odoo_bdo_context';
    const TABLE_SLIP_UPLOADS = 'odoo_slip_uploads';
    const TABLE_BDOS         = 'odoo_bdos';

    /**
     * Validate a match request before sending to Odoo.
     *
     * @param int   $slipInboxId  Odoo Slip Inbox record ID
     * @param array $matches      [{bdo_id: int, amount: float}, ...]
     * @param float $slipAmount   Total slip amount (for over-allocation guard)
     * @return array ['valid' => bool, 'error' => string|null]
     */
    public static function validateMatchRequest(int $slipInboxId, array $matches, float $slipAmount = 0): array
    {
        if ($slipInboxId <= 0) {
            return ['valid' => false, 'error' => 'slip_inbox_id ต้องเป็นตัวเลขที่มากกว่า 0'];
        }

        if (empty($matches)) {
            return ['valid' => false, 'error' => 'ต้องระบุ matches อย่างน้อย 1 รายการ'];
        }

        $totalMatchAmount = 0.0;
        foreach ($matches as $i => $m) {
            $bdoId = (int) ($m['bdo_id'] ?? 0);
            $amount = (float) ($m['amount'] ?? 0);

            if ($bdoId <= 0) {
                return ['valid' => false, 'error' => "matches[$i].bdo_id ต้องเป็นตัวเลขที่มากกว่า 0"];
            }
            if ($amount <= 0) {
                return ['valid' => false, 'error' => "matches[$i].amount ต้องมากกว่า 0"];
            }

            $totalMatchAmount += $amount;
        }

        // Guard: total match amount must not exceed slip amount (when known)
        if ($slipAmount > 0 && $totalMatchAmount > $slipAmount + 0.01) {
            return [
                'valid' => false,
                'error' => sprintf(
                    'ยอดจับคู่รวม ฿%s เกินยอดสลิป ฿%s',
                    number_format($totalMatchAmount, 2),
                    number_format($slipAmount, 2)
                )
            ];
        }

        return ['valid' => true, 'error' => null];
    }

    /**
     * Validate an unmatch request.
     *
     * @param int    $slipInboxId  Odoo Slip Inbox record ID
     * @param string $slipStatus   Current slip status (from local cache)
     * @return array ['valid' => bool, 'error' => string|null]
     */
    public static function validateUnmatchRequest(int $slipInboxId, string $slipStatus = ''): array
    {
        if ($slipInboxId <= 0) {
            return ['valid' => false, 'error' => 'slip_inbox_id ต้องเป็นตัวเลขที่มากกว่า 0'];
        }

        if ($slipStatus !== '' && in_array($slipStatus, self::SLIP_UNMATCH_BLOCKED, true)) {
            return [
                'valid' => false,
                'error' => "ไม่สามารถยกเลิกการจับคู่ได้ เนื่องจากสลิปอยู่ในสถานะ '{$slipStatus}' แล้ว"
            ];
        }

        return ['valid' => true, 'error' => null];
    }

    public static function normalizeSlipStatus(?string $status): string
    {
        $status = trim((string) ($status ?? ''));
        if ($status === '' || $status === self::SLIP_STATUS_PENDING_LEGACY) {
            return self::SLIP_STATUS_NEW;
        }

        return $status;
    }

    public static function isOpenSlipStatus(?string $status): bool
    {
        return in_array(self::normalizeSlipStatus($status), [self::SLIP_STATUS_NEW], true);
    }

    public static function getOpenSlipStatuses(): array
    {
        return self::SLIP_OPEN_STATUSES;
    }

    /**
     * Normalize a slip record from Odoo API response into a consistent shape
     * for use by inboxreya and dashboard.
     *
     * @param array $raw  Raw slip object from Odoo result.data.slip
     * @return array Normalized slip
     */
    public static function normalizeSlip(array $raw): array
    {
        return [
            'slip_inbox_id'    => isset($raw['slip_inbox_id']) ? (int) $raw['slip_inbox_id'] : null,
            'slip_inbox_name'  => $raw['slip_inbox_name'] ?? null,
            'odoo_slip_id'     => isset($raw['id']) ? (int) $raw['id'] : null,
            'partner_id'       => isset($raw['partner_id']) ? (int) $raw['partner_id'] : null,
            'partner_name'     => $raw['partner_name'] ?? null,
            'amount'           => isset($raw['amount']) ? (float) $raw['amount'] : null,
            'transfer_date'    => $raw['transfer_date'] ?? null,
            'status'           => self::normalizeSlipStatus($raw['status'] ?? self::SLIP_STATUS_NEW),
            'match_confidence' => $raw['match_confidence'] ?? self::CONFIDENCE_UNMATCHED,
            'bdo_id'           => isset($raw['bdo_id']) ? (int) $raw['bdo_id'] : null,
            'bdo_name'         => $raw['bdo_name'] ?? null,
            'bdo_amount'       => isset($raw['bdo_amount']) ? (float) $raw['bdo_amount'] : null,
            'delivery_type'    => $raw['delivery_type'] ?? null,
            'created_at'       => $raw['created_at'] ?? null,
        ];
    }

    /**
     * Normalize a BDO record from Odoo API response.
     *
     * @param array $raw  Raw BDO object from Odoo result.data.bdo
     * @return array Normalized BDO
     */
    public static function normalizeBdo(array $raw): array
    {
        $fin = $raw['financial_summary'] ?? [];

        return [
            'bdo_id'                       => isset($raw['bdo_id']) ? (int) $raw['bdo_id'] : null,
            'bdo_name'                     => $raw['bdo_name'] ?? null,
            'state'                        => $raw['state'] ?? self::BDO_STATE_WAITING,
            'delivery_type'                => $raw['delivery_type'] ?? null,
            'amount_total'                 => isset($raw['amount_total']) ? (float) $raw['amount_total'] : null,
            'amount_net_to_pay'            => isset($raw['amount_net_to_pay']) ? (float) $raw['amount_net_to_pay'] : null,
            'currency'                     => $raw['currency'] ?? 'THB',
            'partner_id'                   => isset($raw['partner_id']) ? (int) $raw['partner_id'] : null,
            'partner_name'                 => $raw['partner_name'] ?? null,
            'line_user_id'                 => $raw['line_user_id'] ?? null,
            'salesperson_name'             => $raw['salesperson_name'] ?? null,
            'payment_slip_confirmed'       => (bool) ($raw['payment_slip_confirmed'] ?? false),
            'statement_pdf_url'            => $raw['statement_pdf_url'] ?? null,
            'odoo_url'                     => $raw['odoo_url'] ?? null,
            // Financial breakdown
            'outstanding_invoice_count'    => (int) ($fin['outstanding_invoice_count'] ?? 0),
            'amount_outstanding_invoice'   => (float) ($fin['amount_outstanding_invoice'] ?? 0),
            'credit_note_count'            => (int) ($fin['credit_note_count'] ?? 0),
            'amount_credit_note'           => (float) ($fin['amount_credit_note'] ?? 0),
            'amount_deposit'               => (float) ($fin['amount_deposit'] ?? 0),
            'amount_so_this_round'         => (float) ($fin['amount_so_this_round'] ?? 0),
            'selected_invoices'            => $fin['selected_invoices'] ?? [],
            'selected_credit_notes'        => $fin['selected_credit_notes'] ?? [],
            // Matched slips
            'matched_slips'                => $raw['matched_slips'] ?? [],
        ];
    }

    /**
     * Build the slip upload params for /reya/slip/upload.
     * Ensures bdo_id is included when available and base64 image is clean.
     *
     * @param string      $lineUserId
     * @param string      $slipImageBase64  Pure base64 (no data:image prefix)
     * @param float|null  $amount
     * @param string|null $transferDate     YYYY-MM-DD
     * @param int|null    $bdoId
     * @return array
     */
    public static function buildUploadParams(
        string $lineUserId,
        string $slipImageBase64,
        ?float $amount = null,
        ?string $transferDate = null,
        ?int $bdoId = null
    ): array {
        // Strip data URI prefix if accidentally included
        if (str_contains($slipImageBase64, ',')) {
            $slipImageBase64 = substr($slipImageBase64, strpos($slipImageBase64, ',') + 1);
        }

        $params = [
            'line_user_id' => $lineUserId,
            'slip_image'   => $slipImageBase64,
        ];

        if ($amount !== null)       $params['amount']        = $amount;
        if ($transferDate !== null) $params['transfer_date'] = $transferDate;
        if ($bdoId !== null)        $params['bdo_id']        = $bdoId;

        return $params;
    }

    /**
     * Extract the canonical error code from an Odoo JSON-RPC response.
     *
     * @param array|null $odooResponse  Full decoded JSON-RPC response
     * @return string|null Error code or null if success
     */
    public static function extractOdooError(?array $odooResponse): ?string
    {
        if ($odooResponse === null) {
            return 'NETWORK_ERROR';
        }

        // JSON-RPC level error
        if (isset($odooResponse['error'])) {
            return $odooResponse['error']['message'] ?? 'JSONRPC_ERROR';
        }

        $result = $odooResponse['result'] ?? null;
        if ($result === null) {
            return 'EMPTY_RESULT';
        }

        if (!empty($result['success'])) {
            return null; // success
        }

        return $result['error']['code'] ?? $result['error']['message'] ?? 'UNKNOWN_ERROR';
    }

    /**
     * Extract result data from an Odoo JSON-RPC response.
     *
     * @param array|null $odooResponse
     * @return array|null
     */
    public static function extractOdooData(?array $odooResponse): ?array
    {
        return $odooResponse['result']['data'] ?? null;
    }
}
