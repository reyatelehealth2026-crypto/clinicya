<?php
/**
 * Slip Multi-Order Matching API
 *
 * Handles matching a single payment slip to one or more Odoo orders/invoices.
 * Supports the case where a customer transfers a combined amount covering
 * multiple orders (e.g. Order 1 + Order 2 + Order 3 in one transfer).
 *
 * POST actions:
 *   search_orders  – Find open orders/invoices for a LINE user
 *   match          – Match slip to selected order(s) and update status in Odoo
 *   unmatch        – Reset slip back to pending
 */

error_reporting(0);
ob_start();

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/OdooAPIClient.php';

use Modules\Core\Database;

try {
    $db    = Database::getInstance()->getConnection();
    $input = json_decode(file_get_contents('php://input'), true) ?? [];

    $action = trim($input['action'] ?? '');

    switch ($action) {

        // ------------------------------------------------------------------ //
        // 1. Search open orders / invoices for a customer                    //
        // ------------------------------------------------------------------ //
        case 'search_orders':
            $lineUserId     = trim($input['line_user_id'] ?? '');
            $lineAccountId  = (int) ($input['line_account_id'] ?? 0);
            $slipAmount     = isset($input['slip_amount']) ? (float) $input['slip_amount'] : null;

            if (!$lineUserId || !$lineAccountId) {
                throw new Exception('Missing line_user_id or line_account_id');
            }

            // Find Odoo partner for this LINE user (optional — used for display only)
            $partnerStmt = $db->prepare("
                SELECT odoo_partner_id, odoo_partner_name, odoo_customer_code
                FROM odoo_line_users
                WHERE line_user_id = ?
                LIMIT 1
            ");
            $partnerStmt->execute([$lineUserId]);
            $partner = $partnerStmt->fetch(PDO::FETCH_ASSOC) ?: null;

            $orders   = [];
            $invoices = [];
            $bdos     = [];
            $odooError = null;
            $odooRawDebug = [];

            // Resolve partner_id from line_user_id (for sync table queries)
            $partnerId = null;
            if ($partner && !empty($partner['odoo_partner_id'])) {
                $partnerId = (int) $partner['odoo_partner_id'];
            }

            // ------------------------------------------------------------------ //
            // PRIMARY: Query from dedicated sync tables (fastest, most complete) //
            // ------------------------------------------------------------------ //
            $syncFound = false;
            if ($partnerId || $lineUserId) {
                try {
                    $syncWhere  = $partnerId ? 'partner_id = ?' : 'line_user_id = ?';
                    $syncParam  = $partnerId ?: $lineUserId;

                    // Orders from odoo_orders sync table
                    $ordStmt = $db->prepare("
                        SELECT
                            order_id AS id, order_name AS name, partner_id, line_user_id,
                            state, payment_status, delivery_status,
                            amount_total, amount_tax, amount_untaxed, currency,
                            date_order, expected_delivery, is_paid, is_delivered, latest_event
                        FROM odoo_orders
                        WHERE {$syncWhere}
                          AND state NOT IN ('cancel', 'draft')
                        ORDER BY updated_at DESC
                        LIMIT 20
                    ");
                    $ordStmt->execute([$syncParam]);
                    foreach ($ordStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                        $orders[] = [
                            'id'            => (int)$row['id'],
                            'name'          => $row['name'],
                            'state'         => $row['state'],
                            'payment_status'=> $row['payment_status'],
                            'amount_total'  => (float)$row['amount_total'],
                            'amount_untaxed'=> (float)($row['amount_untaxed'] ?? 0),
                            'currency'      => $row['currency'] ?? 'THB',
                            'date_order'    => $row['date_order'],
                            'is_paid'       => (bool)$row['is_paid'],
                            '_source'       => 'sync_table',
                        ];
                    }

                    // Invoices from odoo_invoices sync table (all columns for best matching)
                    $invStmt = $db->prepare("
                        SELECT
                            invoice_id AS id, invoice_number AS name,
                            order_id, order_name, partner_id, line_user_id,
                            state, invoice_state, payment_state,
                            amount_total, amount_tax, amount_untaxed, amount_residual, currency,
                            invoice_date, due_date, payment_term, is_paid, is_overdue, pdf_url
                        FROM odoo_invoices
                        WHERE {$syncWhere}
                          AND state NOT IN ('cancel')
                        ORDER BY updated_at DESC
                        LIMIT 20
                    ");
                    $invStmt->execute([$syncParam]);
                    foreach ($invStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                        $isPaid = (bool)$row['is_paid'];
                        $amt    = (float)$row['amount_total'];
                        $residual = $row['amount_residual'] !== null ? (float)$row['amount_residual'] : ($isPaid ? 0 : $amt);
                        $invoices[] = [
                            'id'              => (int)$row['id'],
                            'invoice_id'      => (int)$row['id'],
                            'name'            => $row['name'],
                            'invoice_number'  => $row['name'],
                            'order_id'        => $row['order_id'] ? (int)$row['order_id'] : null,
                            'order_name'      => $row['order_name'],
                            'state'           => $row['state'] ?? 'posted',
                            'payment_state'   => $row['payment_state'] ?? ($isPaid ? 'paid' : 'not_paid'),
                            'amount_total'    => $amt,
                            'amount_residual' => $residual,
                            'amount_tax'      => (float)($row['amount_tax'] ?? 0),
                            'amount_untaxed'  => (float)($row['amount_untaxed'] ?? 0),
                            'currency'        => $row['currency'] ?? 'THB',
                            'invoice_date'    => $row['invoice_date'],
                            'invoice_date_due'=> $row['due_date'],
                            'due_date'        => $row['due_date'],
                            'payment_term'    => $row['payment_term'],
                            'is_paid'         => $isPaid,
                            'is_overdue'      => (bool)$row['is_overdue'],
                            'pdf_url'         => $row['pdf_url'],
                            '_source'         => 'sync_table',
                        ];
                    }

                    // BDOs from odoo_bdos sync table
                    $bdoStmt = $db->prepare("
                        SELECT
                            bdo_id AS id, bdo_name, order_id, order_name,
                            partner_id, line_user_id, state,
                            amount_total, currency, bdo_date, expected_delivery
                        FROM odoo_bdos
                        WHERE {$syncWhere}
                        ORDER BY updated_at DESC
                        LIMIT 10
                    ");
                    $bdoStmt->execute([$syncParam]);
                    foreach ($bdoStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                        $bdos[] = [
                            'id'            => (int)$row['id'],
                            'bdo_name'      => $row['bdo_name'],
                            'order_id'      => $row['order_id'] ? (int)$row['order_id'] : null,
                            'order_name'    => $row['order_name'],
                            'amount_total'  => (float)$row['amount_total'],
                            'currency'      => $row['currency'] ?? 'THB',
                            'bdo_date'      => $row['bdo_date'],
                            'state'         => $row['state'],
                            '_source'       => 'sync_table',
                        ];
                    }

                    $syncFound = !empty($orders) || !empty($invoices) || !empty($bdos);
                } catch (Exception $e) {
                    error_log('[slip-match-orders] sync table query failed: ' . $e->getMessage());
                }
            }

            // ------------------------------------------------------------------ //
            // SECONDARY: Try Odoo API if sync table had no results               //
            // ------------------------------------------------------------------ //
            if (!$syncFound) {
                try {
                    $odoo = new OdooAPIClient($db, $lineAccountId);

                    try {
                        $invResult = $odoo->getInvoices($lineUserId, ['limit' => 20]);
                        $odooRawDebug['invoices_raw'] = $invResult;
                        $raw = $invResult['invoices'] ?? $invResult['result']['invoices'] ?? $invResult['result'] ?? [];
                        if (is_array($raw)) {
                            foreach ($raw as $inv) {
                                if (!is_array($inv)) continue;
                                if (empty($inv['id']) && empty($inv['invoice_id']) && empty($inv['name'])) continue;
                                if (($inv['state'] ?? '') === 'cancel') continue;
                                $inv['_source'] = 'odoo_api';
                                $invoices[] = $inv;
                            }
                        }
                    } catch (Exception $e) {
                        error_log('[slip-match-orders] getInvoices failed: ' . $e->getMessage());
                        $odooError = $e->getMessage();
                    }

                    try {
                        $orderResult = $odoo->getOrders($lineUserId, ['limit' => 20]);
                        $odooRawDebug['orders_raw'] = $orderResult;
                        $raw = $orderResult['orders'] ?? $orderResult['result']['orders'] ?? $orderResult['result'] ?? [];
                        if (is_array($raw)) {
                            foreach ($raw as $ord) {
                                if (!is_array($ord)) continue;
                                if (empty($ord['id']) && empty($ord['order_id']) && empty($ord['name'])) continue;
                                if (in_array($ord['state'] ?? '', ['cancel', 'draft'])) continue;
                                $ord['_source'] = 'odoo_api';
                                $orders[] = $ord;
                            }
                        }
                    } catch (Exception $e) {
                        error_log('[slip-match-orders] getOrders failed: ' . $e->getMessage());
                        if (!$odooError) $odooError = $e->getMessage();
                    }

                } catch (Exception $e) {
                    $odooError = 'OdooAPIClient init: ' . $e->getMessage();
                    error_log('[slip-match-orders] OdooAPIClient init failed: ' . $e->getMessage());
                }
            }

            // ------------------------------------------------------------------ //
            // TERTIARY: Fallback to webhook_log JSON extraction                  //
            // ------------------------------------------------------------------ //
            if (empty($orders) && empty($invoices)) {
                $orderNameExpr = "COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.order_name')), ''), NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.order_ref')), ''), CONCAT('SO-', order_id))";
                $amountExpr    = "CAST(COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.amount_total')), ''), '0') AS DECIMAL(14,2))";

                $ordStmt = $db->prepare("
                    SELECT order_id, {$orderNameExpr} AS order_name,
                        MAX({$amountExpr}) AS amount_total, MAX(event_type) AS latest_event, MAX(processed_at) AS last_at
                    FROM odoo_webhooks_log
                    WHERE line_user_id = ? AND order_id IS NOT NULL
                    GROUP BY order_id ORDER BY last_at DESC LIMIT 5
                ");
                $ordStmt->execute([$lineUserId]);
                foreach ($ordStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    if (!$row['order_id']) continue;
                    $orders[] = ['id' => (int)$row['order_id'], 'name' => $row['order_name'] ?: ('SO-' . $row['order_id']), 'amount_total' => (float)$row['amount_total'], 'state' => 'sale', '_source' => 'webhook_log'];
                }

                $invStmt = $db->prepare("
                    SELECT id AS wh_id, order_id, event_type, {$orderNameExpr} AS ref_name, {$amountExpr} AS amount_total,
                        NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.invoice_number')), '') AS inv_number,
                        NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.due_date')), '') AS due_date,
                        NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.amount_residual')), '') AS amount_residual,
                        processed_at
                    FROM odoo_webhooks_log
                    WHERE line_user_id = ? AND event_type IN ('invoice.posted','invoice.created','invoice.overdue','invoice.paid')
                    ORDER BY processed_at DESC LIMIT 10
                ");
                $invStmt->execute([$lineUserId]);
                $seenInv = [];
                foreach ($invStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $key = $row['order_id'] ?: ('wh-' . $row['wh_id']);
                    if (isset($seenInv[$key])) continue;
                    $seenInv[$key] = true;
                    $invAmt = (float)$row['amount_total'];
                    $invoices[] = [
                        'id' => (int)($row['order_id'] ?: $row['wh_id']),
                        'name' => $row['inv_number'] ?: $row['ref_name'] ?: ('INV-' . ($row['order_id'] ?: $row['wh_id'])),
                        'amount_total' => $invAmt,
                        'amount_residual' => $row['amount_residual'] !== null ? (float)$row['amount_residual'] : $invAmt,
                        'state' => 'posted',
                        'payment_state' => str_contains($row['event_type'], 'paid') ? 'paid' : 'not_paid',
                        'invoice_date_due' => $row['due_date'],
                        '_source' => 'webhook_log',
                    ];
                    if (count($invoices) >= 5) break;
                }

                if (empty($bdos)) {
                    $bdoStmt = $db->prepare("
                        SELECT id AS wh_id, NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.bdo_id')), '') AS bdo_id,
                            NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.bdo_name')), '') AS bdo_name,
                            {$orderNameExpr} AS order_name, {$amountExpr} AS amount_total,
                            NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.bdo_date')), '') AS bdo_date, processed_at
                        FROM odoo_webhooks_log
                        WHERE line_user_id = ? AND event_type LIKE 'bdo.%'
                        ORDER BY processed_at DESC LIMIT 5
                    ");
                    $bdoStmt->execute([$lineUserId]);
                    foreach ($bdoStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                        if (!$row['bdo_id'] && !$row['bdo_name']) continue;
                        $bdos[] = ['id' => (int)($row['bdo_id'] ?: $row['wh_id']), 'bdo_name' => $row['bdo_name'] ?: ('BDO-' . $row['bdo_id']), 'order_name' => $row['order_name'], 'amount_total' => (float)$row['amount_total'], 'bdo_date' => $row['bdo_date'] ?: $row['processed_at'], '_source' => 'webhook_log'];
                    }
                }
            }

            // Limit display: sync_table already fetches 20, fallback keeps fewer
            $invoices = array_slice($invoices, 0, 20);
            $orders   = array_slice($orders,   0, 20);
            $bdos     = array_slice($bdos,     0, 10);

            // If slip_amount provided, compute how items could sum to it
            // Priority: unpaid invoices (use amount_residual) > unpaid orders > BDOs
            $suggestions = [];
            if ($slipAmount !== null && $slipAmount > 0) {
                $allItems = [];

                // Invoices: use amount_residual (remaining unpaid balance)
                foreach ($invoices as $inv) {
                    $isPaid = (bool)($inv['is_paid'] ?? ($inv['payment_state'] ?? '') === 'paid');
                    if ($isPaid) continue; // Skip already paid
                    $amt = (float)($inv['amount_residual'] ?? $inv['amount_total'] ?? 0);
                    if ($amt > 0.5) {
                        $allItems[] = [
                            'type'   => 'invoice',
                            'id'     => $inv['id'],
                            'name'   => $inv['invoice_number'] ?? $inv['name'] ?? ('INV-' . $inv['id']),
                            'amount' => $amt,
                            'raw'    => $inv,
                        ];
                    }
                }

                // Orders: use amount_total (if not paid)
                foreach ($orders as $ord) {
                    $isPaid = (bool)($ord['is_paid'] ?? false);
                    if ($isPaid) continue;
                    $amt = (float)($ord['amount_total'] ?? 0);
                    if ($amt > 0.5) {
                        $allItems[] = [
                            'type'   => 'order',
                            'id'     => $ord['id'],
                            'name'   => $ord['name'] ?? ('SO-' . $ord['id']),
                            'amount' => $amt,
                            'raw'    => $ord,
                        ];
                    }
                }

                // BDOs: as supplementary matching
                foreach ($bdos as $bdo) {
                    $amt = (float)($bdo['amount_total'] ?? 0);
                    if ($amt > 0.5) {
                        $allItems[] = [
                            'type'   => 'bdo',
                            'id'     => $bdo['id'],
                            'name'   => $bdo['bdo_name'] ?? ('BDO-' . $bdo['id']),
                            'amount' => $amt,
                            'raw'    => $bdo,
                        ];
                    }
                }

                // Find subsets that sum ≈ slipAmount (tolerance 1 THB)
                $suggestions = _findMatchingSets($allItems, $slipAmount, 1.0);
            }

            // Detect data source
            $sources = [];
            foreach (array_merge($orders, $invoices, $bdos) as $item) {
                if (!empty($item['_source'])) $sources[$item['_source']] = true;
            }
            $usingFallback = !isset($sources['sync_table']);

            echo json_encode([
                'success' => true,
                'data'    => [
                    'partner'        => $partner,
                    'orders'         => $orders,
                    'invoices'       => $invoices,
                    'bdos'           => $bdos,
                    'suggestions'    => $suggestions,
                    'slip_amount'    => $slipAmount,
                    'odoo_error'     => $odooError,
                    'using_fallback' => $usingFallback,
                    '_debug'         => $odooRawDebug,
                ],
            ], JSON_UNESCAPED_UNICODE);
            break;

        // ------------------------------------------------------------------ //
        // 2. Match slip to one or more orders/invoices/BDOs                //
        // ------------------------------------------------------------------ //
        case 'match':
            $slipId        = (int) ($input['slip_id'] ?? 0);
            $lineAccountId = (int) ($input['line_account_id'] ?? 0);
            $targets       = $input['targets'] ?? []; // [{'type':'invoice','id':123}, {'type':'bdo','id':437,'amount':15950}, ...]

            if (!$slipId || !$lineAccountId || empty($targets)) {
                throw new Exception('Missing slip_id, line_account_id, or targets');
            }

            // Load slip
            $slipStmt = $db->prepare("
                SELECT s.*, u.display_name AS customer_name
                FROM odoo_slip_uploads s
                LEFT JOIN users u ON u.line_user_id = s.line_user_id
                WHERE s.id = ? AND s.line_account_id = ?
            ");
            $slipStmt->execute([$slipId, $lineAccountId]);
            $slip = $slipStmt->fetch(PDO::FETCH_ASSOC);

            if (!$slip) {
                throw new Exception('Slip not found');
            }

            try {
                $odoo = new OdooAPIClient($db, $lineAccountId);
            } catch (Exception $e) {
                throw new Exception('OdooAPIClient init: ' . $e->getMessage());
            }

            // Separate BDO targets from invoice/order targets
            $bdoTargets   = array_filter($targets, fn($t) => ($t['type'] ?? '') === 'bdo');
            $otherTargets = array_filter($targets, fn($t) => ($t['type'] ?? '') !== 'bdo');

            $results      = [];
            $successCount = 0;
            $failCount    = 0;
            $firstOrderId   = null;
            $firstInvoiceId = null;

            // ---- BDO matching: use /reya/slip/match-bdo (canonical endpoint) ----
            if (!empty($bdoTargets)) {
                $slipInboxId = (int) ($slip['slip_inbox_id'] ?? $slip['odoo_slip_id'] ?? 0);
                if ($slipInboxId <= 0) {
                    // If no slip_inbox_id yet, upload slip first to get one
                    $fullPath = __DIR__ . '/../' . ltrim($slip['image_path'] ?? '', '/');
                    if (file_exists($fullPath)) {
                        $imageData = file_get_contents($fullPath);
                        if ($imageData && strlen($imageData) >= 100) {
                            $base64 = base64_encode($imageData);
                            $uploadOpts = ['amount' => (float)($slip['amount'] ?? 0)];
                            if ($slip['transfer_date']) $uploadOpts['transfer_date'] = $slip['transfer_date'];
                            if ($slip['bdo_id']) $uploadOpts['bdo_id'] = (int)$slip['bdo_id'];
                            try {
                                $uploadResult = $odoo->uploadSlip($slip['line_user_id'], $base64, $uploadOpts);
                                $slipInboxId = (int)($uploadResult['slip_inbox_id'] ?? $uploadResult['data']['slip']['slip_inbox_id'] ?? 0);
                                // Save slip_inbox_id for future use
                                if ($slipInboxId > 0) {
                                    $db->prepare("UPDATE odoo_slip_uploads SET slip_inbox_id = ? WHERE id = ?")->execute([$slipInboxId, $slipId]);
                                }
                            } catch (Exception $e) {
                                error_log("[slip-match-orders] pre-upload for BDO match failed: " . $e->getMessage());
                            }
                        }
                    }
                }

                if ($slipInboxId > 0) {
                    $matches = array_map(fn($t) => [
                        'bdo_id' => (int) $t['id'],
                        'amount' => (float) ($t['amount'] ?? $slip['amount'] ?? 0)
                    ], array_values($bdoTargets));

                    $note = $input['note'] ?? '';
                    try {
                        $odooResult = $odoo->matchSlipBdo($slip['line_user_id'], $slipInboxId, $matches, $note);
                        $matchedBdos = $odooResult['data']['matched_bdos'] ?? $odooResult['matched_bdos'] ?? [];
                        $confidence  = $odooResult['data']['match_confidence'] ?? $odooResult['match_confidence'] ?? 'manual';

                        foreach ($bdoTargets as $bt) {
                            $results[] = ['target' => $bt, 'success' => true, 'odoo' => $odooResult, 'match_confidence' => $confidence];
                            $successCount++;
                        }
                    } catch (Exception $e) {
                        foreach ($bdoTargets as $bt) {
                            $results[] = ['target' => $bt, 'success' => false, 'error' => $e->getMessage()];
                            $failCount++;
                        }
                    }
                } else {
                    foreach ($bdoTargets as $bt) {
                        $results[] = ['target' => $bt, 'success' => false, 'error' => 'Could not resolve slip_inbox_id for BDO matching'];
                        $failCount++;
                    }
                }
            }

            // ---- Invoice/Order matching: use uploadSlip (existing flow) ----
            if (!empty($otherTargets)) {
                $fullPath = __DIR__ . '/../' . ltrim($slip['image_path'] ?? '', '/');
                if (!file_exists($fullPath)) {
                    throw new Exception('Slip image not found on disk: ' . $slip['image_path']);
                }
                $imageData = file_get_contents($fullPath);
                if (!$imageData || strlen($imageData) < 100) {
                    throw new Exception('Slip image is empty or too small');
                }
                $base64 = base64_encode($imageData);

                foreach ($otherTargets as $target) {
                    $targetType = $target['type'] ?? '';
                    $targetId   = (int) ($target['id'] ?? 0);
                    if (!$targetId) continue;

                    $opts = [
                        'amount' => (float) ($slip['amount'] ?? 0),
                    ];
                    if ($slip['transfer_date']) $opts['transfer_date'] = $slip['transfer_date'];
                    if ($targetType === 'invoice') {
                        $opts['invoice_id'] = $targetId;
                        if (!$firstInvoiceId) $firstInvoiceId = $targetId;
                    } elseif ($targetType === 'order') {
                        $opts['order_id'] = $targetId;
                        if (!$firstOrderId) $firstOrderId = $targetId;
                    }

                    try {
                        $odooResult = $odoo->uploadSlip($slip['line_user_id'], $base64, $opts);
                        $odooSlipId = $odooResult['id'] ?? $odooResult['slip_id'] ?? $odooResult['result']['id'] ?? null;
                        $results[]  = ['target' => $target, 'success' => true, 'odoo' => $odooResult, 'odoo_slip_id' => $odooSlipId];
                        $successCount++;
                    } catch (Exception $e) {
                        $results[]  = ['target' => $target, 'success' => false, 'error' => $e->getMessage()];
                        $failCount++;
                    }
                }
            }

            // Update slip status
            $newStatus  = $failCount === 0 ? 'matched' : ($successCount > 0 ? 'matched' : 'failed');
            $matchNote  = 'Match: ' . count($targets) . ' targets (bdo=' . count($bdoTargets) . ', other=' . count($otherTargets) . '), success=' . $successCount . ', fail=' . $failCount;
            $odooSlipIdSave = null;
            foreach ($results as $r) {
                if (!empty($r['odoo_slip_id'])) { $odooSlipIdSave = (int) $r['odoo_slip_id']; break; }
            }

            // Extract match_confidence and bdo_name from results
            $matchConfidence = null;
            $bdoName = null;
            foreach ($results as $r) {
                if (!empty($r['match_confidence'])) { $matchConfidence = $r['match_confidence']; }
                if (!empty($r['odoo']['data']['matched_bdos'][0]['bdo_name'])) { $bdoName = $r['odoo']['data']['matched_bdos'][0]['bdo_name']; }
            }

            $db->prepare("
                UPDATE odoo_slip_uploads
                SET status = ?, match_reason = ?, matched_at = NOW(),
                    odoo_slip_id    = COALESCE(?, odoo_slip_id),
                    order_id        = COALESCE(?, order_id),
                    invoice_id      = COALESCE(?, invoice_id),
                    match_confidence = COALESCE(?, match_confidence),
                    bdo_name         = COALESCE(?, bdo_name)
                WHERE id = ?
            ")->execute([$newStatus, mb_substr($matchNote, 0, 500), $odooSlipIdSave, $firstOrderId, $firstInvoiceId, $matchConfidence, $bdoName, $slipId]);

            echo json_encode([
                'success' => true,
                'message' => "จับคู่สำเร็จ {$successCount}/" . count($targets) . " รายการ" . ($failCount > 0 ? " (ล้มเหลว {$failCount})" : ''),
                'data'    => [
                    'slip_id'      => $slipId,
                    'new_status'   => $newStatus,
                    'success_count'=> $successCount,
                    'fail_count'   => $failCount,
                    'results'      => $results,
                ],
            ], JSON_UNESCAPED_UNICODE);
            break;

        // ------------------------------------------------------------------ //
        // 3. Unmatch: call Odoo /reya/slip/unmatch + reset local DB          //
        // ------------------------------------------------------------------ //
        case 'unmatch':
            $slipId        = (int) ($input['slip_id'] ?? 0);
            $lineAccountId = (int) ($input['line_account_id'] ?? 0);
            $reason        = trim((string) ($input['reason'] ?? 'รีเซ็ตโดยแอดมิน'));
            if (!$slipId) throw new Exception('Missing slip_id');

            // Try to call Odoo unmatch first (if slip has slip_inbox_id)
            $odooUnmatchResult = null;
            $odooUnmatchError  = null;

            $slipStmt = $db->prepare("SELECT slip_inbox_id, odoo_slip_id, line_user_id FROM odoo_slip_uploads WHERE id = ?");
            $slipStmt->execute([$slipId]);
            $slipRow = $slipStmt->fetch(PDO::FETCH_ASSOC);

            if ($slipRow) {
                $slipInboxId = (int) ($slipRow['slip_inbox_id'] ?? $slipRow['odoo_slip_id'] ?? 0);
                $lineUserId  = $slipRow['line_user_id'] ?? '';

                if ($slipInboxId > 0 && $lineUserId !== '' && $lineAccountId > 0) {
                    try {
                        $odoo = new OdooAPIClient($db, $lineAccountId);
                        $odooUnmatchResult = $odoo->unmatchSlip($lineUserId, $slipInboxId, $reason);
                    } catch (Exception $e) {
                        $odooUnmatchError = $e->getMessage();
                        error_log("[slip-match-orders] Odoo unmatch failed: " . $odooUnmatchError);
                    }
                }
            }

            // Always reset local DB regardless of Odoo result
            $db->prepare("
                UPDATE odoo_slip_uploads
                SET status = 'pending', match_reason = ?, matched_at = NULL,
                    odoo_slip_id = NULL, order_id = NULL, invoice_id = NULL,
                    match_confidence = NULL, bdo_name = NULL, slip_inbox_id = NULL
                WHERE id = ?
            ")->execute([$reason, $slipId]);

            // Reset odoo_bdo_orders.payment_status if bdo_id provided or linked
            $unmatchBdoId = (int) ($input['bdo_id'] ?? 0);
            if ($unmatchBdoId > 0) {
                try {
                    $tblChk = $db->query("SHOW TABLES LIKE 'odoo_bdo_orders'");
                    if ($tblChk->rowCount() > 0) {
                        $db->prepare("UPDATE odoo_bdo_orders SET payment_status = 'pending', slip_upload_id = NULL WHERE bdo_id = ? AND slip_upload_id = ?")->execute([$unmatchBdoId, $slipId]);
                    }
                } catch (Exception $e2) {
                    error_log("[slip-match-orders] bdo_orders reset failed: " . $e2->getMessage());
                }
            }

            echo json_encode([
                'success' => true,
                'message' => 'รีเซ็ตสถานะสลิปเป็น pending แล้ว',
                'odoo_unmatch' => $odooUnmatchResult,
                'odoo_error'   => $odooUnmatchError,
            ], JSON_UNESCAPED_UNICODE);
            break;

        // ------------------------------------------------------------------ //
        // 4. Match BDO: attach existing slip to a BDO                        //
        // ------------------------------------------------------------------ //
        case 'match_bdo':
            $slipId = (int) ($input['slip_id'] ?? 0);
            $bdoId  = (int) ($input['bdo_id'] ?? 0);
            $amount = isset($input['amount']) ? (float) $input['amount'] : null;
            if (!$slipId || !$bdoId) throw new Exception('Missing slip_id or bdo_id');

            // Update slip with bdo_id
            $db->prepare("
                UPDATE odoo_slip_uploads
                SET bdo_id = ?, status = 'matched', match_reason = 'Manual BDO match from dashboard',
                    matched_at = NOW()
                WHERE id = ? AND status IN ('new', 'pending')
            ")->execute([$bdoId, $slipId]);

            // Update odoo_bdo_orders.payment_status
            try {
                $tblChk = $db->query("SHOW TABLES LIKE 'odoo_bdo_orders'");
                if ($tblChk->rowCount() > 0) {
                    $db->prepare("
                        UPDATE odoo_bdo_orders
                        SET payment_status = 'slip_uploaded', slip_upload_id = ?
                        WHERE bdo_id = ? AND payment_status = 'pending'
                    ")->execute([$slipId, $bdoId]);
                }
            } catch (Exception $e2) {
                error_log("[slip-match-orders] bdo_orders match update failed: " . $e2->getMessage());
            }

            echo json_encode([
                'success' => true,
                'message' => 'จับคู่สลิปกับ BDO เรียบร้อยแล้ว',
                'slip_id' => $slipId,
                'bdo_id'  => $bdoId,
            ], JSON_UNESCAPED_UNICODE);
            break;

        default:
            throw new Exception('Unknown action: ' . $action);
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

/**
 * Find subsets of $items whose amounts sum to $target (±$tolerance).
 * Returns up to 5 suggestions (arrays of matching item indices).
 * Uses recursive search limited to depth 6 to avoid combinatorial explosion.
 */
function _findMatchingSets(array $items, float $target, float $tolerance = 1.0): array
{
    $results = [];
    _subsetSearch($items, $target, $tolerance, 0, [], 0.0, $results, 5, 6);
    return $results;
}

function _subsetSearch(array $items, float $target, float $tol, int $start, array $current, float $sum, array &$results, int $maxResults, int $maxDepth): void
{
    if (count($results) >= $maxResults) return;
    if (abs($sum - $target) <= $tol && !empty($current)) {
        $results[] = $current;
        return;
    }
    if ($sum > $target + $tol) return;
    if (count($current) >= $maxDepth) return;
    for ($i = $start; $i < count($items); $i++) {
        _subsetSearch($items, $target, $tol, $i + 1, array_merge($current, [$items[$i]]), $sum + $items[$i]['amount'], $results, $maxResults, $maxDepth);
    }
}
