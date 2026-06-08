<?php
/**
 * Shop - รายละเอียดคำสั่งซื้อ
 * V2.5 - รองรับทั้ง orders และ transactions + Multi-bot
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/LineAPI.php';
require_once __DIR__ . '/../classes/LineAccountManager.php';

$db = Database::getInstance()->getConnection();
$pageTitle = 'รายละเอียดคำสั่งซื้อ';

// Get current bot ID from session
$currentBotId = $_SESSION['current_bot_id'] ?? 1;

// Get LineAPI for current bot
$lineManager = new LineAccountManager($db);
$line = $lineManager->getLineAPI($currentBotId);

$orderId = (int)($_GET['id'] ?? 0);

// Redirect if no order ID
if (!$orderId) {
    header('Location: orders.php');
    exit;
}

// Use transactions table (unified with LIFF checkout)
$useTransactions = true;
$ordersTable = 'transactions';
$itemsTable = 'transaction_items';
$itemsFk = 'transaction_id';

/**
 * Build Flex Order Status Message
 */
function buildOrderStatusFlex($order, $items, $newStatus, $tracking = null) {
    $statusConfig = [
        'pending'   => ['icon' => '⏳', 'label' => 'รอยืนยัน',     'color' => '#F59E0B', 'msg' => 'รอการยืนยันจากร้านค้า'],
        'confirmed' => ['icon' => '✅', 'label' => 'ยืนยันแล้ว',   'color' => '#3B82F6', 'msg' => 'ออเดอร์ได้รับการยืนยันแล้ว'],
        'paid'      => ['icon' => '💰', 'label' => 'ชำระเงินแล้ว', 'color' => '#10B981', 'msg' => 'ยืนยันการชำระเงินเรียบร้อย'],
        'shipping'  => ['icon' => '🚚', 'label' => 'กำลังจัดส่ง', 'color' => '#8B5CF6', 'msg' => 'สินค้าถูกจัดส่งแล้ว'],
        'delivered' => ['icon' => '📦', 'label' => 'จัดส่งแล้ว',  'color' => '#059669', 'msg' => 'สินค้าถึงปลายทางแล้ว'],
        'cancelled' => ['icon' => '❌', 'label' => 'ยกเลิก',       'color' => '#EF4444', 'msg' => 'ออเดอร์ถูกยกเลิก']
    ];

    $status = $statusConfig[$newStatus] ?? $statusConfig['pending'];

    // Build item list
    $itemList = [];
    foreach ($items as $item) {
        $itemList[] = [
            'type'     => 'box',
            'layout'   => 'horizontal',
            'contents' => [
                ['type' => 'text', 'text' => $item['product_name'], 'size' => 'sm', 'color' => '#555555', 'flex' => 4, 'wrap' => true],
                ['type' => 'text', 'text' => 'x' . $item['quantity'], 'size' => 'sm', 'color' => '#111111', 'align' => 'end', 'flex' => 1],
                ['type' => 'text', 'text' => '฿' . number_format($item['subtotal'], 0), 'size' => 'sm', 'color' => '#111111', 'align' => 'end', 'flex' => 2]
            ]
        ];
    }

    // Get delivery info
    $deliveryInfo = json_decode($order['delivery_info'] ?? '{}', true);
    $addrParts = [];
    if (!empty($deliveryInfo['name']))    $addrParts[] = $deliveryInfo['name'];
    if (!empty($deliveryInfo['phone']))   $addrParts[] = $deliveryInfo['phone'];
    if (!empty($deliveryInfo['address'])) $addrParts[] = $deliveryInfo['address'];
    $addr = implode("\n", $addrParts) ?: 'ไม่ระบุที่อยู่';

    // Body contents
    $bodyContents = [
        [
            'type'     => 'box',
            'layout'   => 'horizontal',
            'contents' => [
                ['type' => 'text', 'text' => $status['icon'] . ' ' . $status['label'], 'weight' => 'bold', 'size' => 'xl', 'color' => $status['color']],
            ]
        ],
        ['type' => 'text', 'text' => $status['msg'], 'size' => 'sm', 'color' => '#888888', 'margin' => 'sm'],
        ['type' => 'separator', 'margin' => 'lg'],
        ['type' => 'text', 'text' => '📋 Order #' . $order['order_number'], 'weight' => 'bold', 'size' => 'sm', 'margin' => 'lg'],
        ['type' => 'text', 'text' => '📅 ' . date('d/m/Y H:i'), 'size' => 'xs', 'color' => '#aaaaaa', 'margin' => 'sm'],
    ];

    // Add tracking number if shipping
    if ($newStatus === 'shipping' && $tracking) {
        $bodyContents[] = [
            'type'            => 'box',
            'layout'          => 'vertical',
            'margin'          => 'lg',
            'paddingAll'      => 'md',
            'backgroundColor' => '#F3E8FF',
            'cornerRadius'    => 'md',
            'contents'        => [
                ['type' => 'text', 'text' => '🚚 เลขพัสดุ', 'weight' => 'bold', 'size' => 'sm', 'color' => '#7C3AED'],
                ['type' => 'text', 'text' => $tracking, 'size' => 'lg', 'weight' => 'bold', 'color' => '#5B21B6', 'margin' => 'sm']
            ]
        ];
    }

    // Add items section
    $bodyContents[] = ['type' => 'separator', 'margin' => 'lg'];
    $bodyContents[] = ['type' => 'text', 'text' => '🛒 รายการสินค้า', 'weight' => 'bold', 'size' => 'sm', 'margin' => 'lg'];
    $bodyContents[] = ['type' => 'box', 'layout' => 'vertical', 'margin' => 'md', 'spacing' => 'sm', 'contents' => $itemList];

    // Add totals
    $bodyContents[] = ['type' => 'separator', 'margin' => 'lg'];
    $bodyContents[] = [
        'type' => 'box', 'layout' => 'horizontal', 'margin' => 'md',
        'contents' => [
            ['type' => 'text', 'text' => 'ยอดสินค้า', 'size' => 'sm', 'color' => '#555555'],
            ['type' => 'text', 'text' => '฿' . number_format($order['total_amount'], 0), 'size' => 'sm', 'color' => '#111111', 'align' => 'end']
        ]
    ];
    $bodyContents[] = [
        'type' => 'box', 'layout' => 'horizontal', 'margin' => 'sm',
        'contents' => [
            ['type' => 'text', 'text' => 'ค่าจัดส่ง', 'size' => 'sm', 'color' => '#555555'],
            ['type' => 'text', 'text' => $order['shipping_fee'] > 0 ? '฿' . number_format($order['shipping_fee'], 0) : 'ฟรี!', 'size' => 'sm', 'color' => $order['shipping_fee'] > 0 ? '#111111' : '#10B981', 'align' => 'end']
        ]
    ];
    $bodyContents[] = ['type' => 'separator', 'margin' => 'md'];
    $bodyContents[] = [
        'type' => 'box', 'layout' => 'horizontal', 'margin' => 'md',
        'contents' => [
            ['type' => 'text', 'text' => 'ยอดสุทธิ', 'weight' => 'bold', 'size' => 'md'],
            ['type' => 'text', 'text' => '฿' . number_format($order['grand_total'], 0), 'weight' => 'bold', 'size' => 'xl', 'align' => 'end', 'color' => $status['color']]
        ]
    ];

    // Add address section
    $bodyContents[] = ['type' => 'separator', 'margin' => 'lg'];
    $bodyContents[] = ['type' => 'text', 'text' => '📦 ที่อยู่จัดส่ง', 'weight' => 'bold', 'size' => 'sm', 'margin' => 'lg'];
    $bodyContents[] = ['type' => 'text', 'text' => $addr, 'size' => 'xs', 'color' => '#666666', 'wrap' => true, 'margin' => 'sm'];

    $bubble = [
        'type' => 'bubble',
        'body' => ['type' => 'box', 'layout' => 'vertical', 'contents' => $bodyContents],
        'footer' => [
            'type'     => 'box',
            'layout'   => 'vertical',
            'contents' => [
                ['type' => 'text', 'text' => '🙏 ขอบคุณที่ใช้บริการ', 'align' => 'center', 'color' => '#aaaaaa', 'size' => 'xs']
            ]
        ]
    ];

    return [
        'type'     => 'flex',
        'altText'  => $status['icon'] . ' อัพเดทสถานะ #' . $order['order_number'] . ' - ' . $status['label'],
        'contents' => $bubble
    ];
}

/**
 * Send Flex Order Status to customer
 * ใช้ sendMessage เพื่อเช็ค replyToken ก่อน (ฟรี!) หรือ fallback ไป pushMessage
 */
function sendOrderStatusFlex($line, $db, $orderId, $newStatus, $tracking = null) {
    // Get order with items and reply token
    $stmt = $db->prepare("SELECT o.*, u.line_user_id, u.reply_token, u.reply_token_expires FROM transactions o JOIN users u ON o.user_id = u.id WHERE o.id = ?");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order || !$order['line_user_id']) return false;

    // Get items
    $stmt = $db->prepare("SELECT * FROM transaction_items WHERE transaction_id = ?");
    $stmt->execute([$orderId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Build flex message
    $flexMessage = buildOrderStatusFlex($order, $items, $newStatus, $tracking);

    // ใช้ sendMessage ถ้ามี หรือ fallback ไป pushMessage
    if (method_exists($line, 'sendMessage')) {
        return $line->sendMessage($order['line_user_id'], [$flexMessage], $order['reply_token'] ?? null, $order['reply_token_expires'] ?? null, $db);
    } else {
        return $line->pushMessage($order['line_user_id'], [$flexMessage]);
    }
}

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Manual GhostX verification of a stored slip QR (decoded by the customer's
    // app at upload time and saved in payment_slips.qr_payload).
    if ($action === 'verify_slip') {
        $slipId = (int) ($_POST['slip_id'] ?? 0);
        $postedQr = trim((string) ($_POST['qr_data'] ?? ''));
        $reason = 'no_qr';
        try {
            $st = $db->prepare("SELECT * FROM payment_slips WHERE id = ? AND transaction_id = ? LIMIT 1");
            $st->execute([$slipId, $orderId]);
            $slip = $st->fetch(PDO::FETCH_ASSOC);
            // Prefer a QR the admin's browser just decoded from the image; fall
            // back to the one stored at upload. Persist a freshly decoded one.
            $qr = $postedQr !== '' ? $postedQr : ($slip['qr_payload'] ?? '');
            if ($slip && $postedQr !== '' && empty($slip['qr_payload'])) {
                try {
                    $db->prepare("UPDATE payment_slips SET qr_payload = ? WHERE id = ?")->execute([$postedQr, $slipId]);
                } catch (\Throwable $e) { /* qr_payload column may be missing */ }
            }

            if ($slip && $qr !== '') {
                require_once __DIR__ . '/../classes/SlipVerifier.php';

                // Expected amount = order grand_total.
                $oq = $db->prepare("SELECT grand_total, total_amount FROM {$ordersTable} WHERE id = ? LIMIT 1");
                $oq->execute([$orderId]);
                $ord = $oq->fetch(PDO::FETCH_ASSOC) ?: [];
                $expectedAmount = (float) ($ord['grand_total'] ?? $ord['total_amount'] ?? 0);

                // Shop destination accounts (PromptPay + bank accounts).
                $shopAccounts = [];
                try {
                    $ss = $db->prepare("SELECT promptpay_number, bank_accounts FROM shop_settings WHERE line_account_id = ? LIMIT 1");
                    $ss->execute([$currentBotId]);
                    $cfg = $ss->fetch(PDO::FETCH_ASSOC) ?: [];
                    if (!empty($cfg['promptpay_number'])) {
                        $shopAccounts[] = (string) $cfg['promptpay_number'];
                    }
                    if (!empty($cfg['bank_accounts'])) {
                        $dec = json_decode($cfg['bank_accounts'], true);
                        if (is_array($dec)) {
                            foreach ($dec as $b) {
                                if (!empty($b['account_number'])) {
                                    $shopAccounts[] = (string) $b['account_number'];
                                }
                            }
                        }
                    }
                } catch (\Throwable $e) { /* no shop_settings */ }

                // GhostX rejects re-scans of the same QR with HTTP 409. If we
                // already captured the GhostX response at upload, re-evaluate it
                // instead of re-scanning; only call GhostX fresh if we have none.
                $verifier = new SlipVerifier();
                $prior = !empty($slip['verify_data']) ? json_decode($slip['verify_data'], true) : null;
                // Amount-only: approve on a valid slip whose amount matches the
                // order; account is shown for a visual check but does not block.
                if (is_array($prior) && !empty($prior['slipVerification']['transfer'])) {
                    $vr = $verifier->verifyStored($prior, $expectedAmount, $shopAccounts, false);
                } else {
                    $vr = $verifier->verify($qr, $expectedAmount, $shopAccounts, false);
                }
                $reason = $vr['reason'];
                $vd = json_encode($vr['data'], JSON_UNESCAPED_UNICODE);

                // Guard against the same slip ref being reused on another order.
                $dup = false;
                if ($vr['ref']) {
                    $dq = $db->prepare("SELECT id FROM payment_slips WHERE verify_ref = ? AND id <> ? LIMIT 1");
                    $dq->execute([$vr['ref'], $slipId]);
                    $dup = (bool) $dq->fetch();
                }

                if ($vr['verified'] && !$dup) {
                    $db->prepare("UPDATE payment_slips SET status='approved', verify_ref=?, verify_amount=?, verify_data=?, verified_at=NOW() WHERE id=?")
                       ->execute([$vr['ref'], $vr['amount'], $vd, $slipId]);
                    $db->prepare("UPDATE {$ordersTable} SET payment_status='paid', status='paid' WHERE id=? AND (line_account_id=? OR line_account_id IS NULL)")
                       ->execute([$orderId, $currentBotId]);
                    try {
                        if ($line) { sendOrderStatusFlex($line, $db, $orderId, 'paid'); }
                    } catch (Exception $e) { error_log('verify_slip flex error: ' . $e->getMessage()); }
                    $reason = 'ok';
                } else {
                    if ($dup) { $reason = 'duplicate_ref'; }
                    // Never clobber a real stored response with an empty one (a
                    // re-scan that hit GhostX 409 returns no data) so the saved
                    // upload-time response stays available for re-evaluation.
                    if (!empty($vr['data'])) {
                        $db->prepare("UPDATE payment_slips SET verify_amount=?, verify_data=? WHERE id=?")
                           ->execute([$vr['amount'], $vd, $slipId]);
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log('verify_slip error: ' . $e->getMessage());
            $reason = 'error';
        }
        header("Location: order-detail.php?id={$orderId}&verify=" . urlencode($reason));
        exit;
    }

    if ($action === 'update_status') {
        try {
            $newStatus = $_POST['status'];
            $stmt = $db->prepare("UPDATE {$ordersTable} SET status = ? WHERE id = ? AND (line_account_id = ? OR line_account_id IS NULL)");
            $stmt->execute([$newStatus, $orderId, $currentBotId]);

            // WMS Integration: Set wms_status to pending_pick when order is confirmed or paid
            if (in_array($newStatus, ['confirmed', 'paid'])) {
                try {
                    $stmt = $db->prepare("UPDATE {$ordersTable} SET wms_status = 'pending_pick' WHERE id = ? AND (wms_status IS NULL OR wms_status = '')");
                    $stmt->execute([$orderId]);
                } catch (Exception $e) {
                    // wms_status column may not exist, ignore
                }
            }

            // Update tracking if provided
            $tracking = null;
            if (!empty($_POST['tracking'])) {
                $tracking = $_POST['tracking'];
                $stmt = $db->prepare("UPDATE {$ordersTable} SET shipping_tracking = ? WHERE id = ? AND (line_account_id = ? OR line_account_id IS NULL)");
                $stmt->execute([$tracking, $orderId, $currentBotId]);
            }

            // Send Flex notification to customer (with error handling)
            try {
                if ($line) {
                    sendOrderStatusFlex($line, $db, $orderId, $newStatus, $tracking);
                }
            } catch (Exception $e) {
                error_log('sendOrderStatusFlex error: ' . $e->getMessage());
            }

            // V2.5: Auto-fulfill digital items when paid (optional)
            try {
                if ($newStatus === 'paid' && $useTransactions && file_exists(__DIR__ . '/../classes/BusinessBot.php')) {
                    require_once __DIR__ . '/../classes/BusinessBot.php';
                    if (class_exists('BusinessBot') && method_exists('BusinessBot', 'autoFulfillDigitalItems')) {
                        $businessBot = new BusinessBot($db, $line, $currentBotId);
                        $businessBot->autoFulfillDigitalItems($orderId);
                    }
                }
            } catch (Exception $e) {
                error_log('BusinessBot error: ' . $e->getMessage());
            }
        } catch (Exception $e) {
            error_log('update_status error: ' . $e->getMessage());
        }

        header("Location: order-detail.php?id={$orderId}&updated=1");
        exit;
    }

    if ($action === 'approve_payment') {
        try {
            $stmt = $db->prepare("UPDATE {$ordersTable} SET payment_status = 'paid', status = 'paid' WHERE id = ? AND (line_account_id = ? OR line_account_id IS NULL)");
            $stmt->execute([$orderId, $currentBotId]);

            // WMS Integration: Set wms_status to pending_pick when payment approved
            try {
                $stmt = $db->prepare("UPDATE {$ordersTable} SET wms_status = 'pending_pick' WHERE id = ? AND (wms_status IS NULL OR wms_status = '')");
                $stmt->execute([$orderId]);
            } catch (Exception $e) {
                // wms_status column may not exist, ignore
            }

            $stmt = $db->prepare("UPDATE payment_slips SET status = 'approved' WHERE transaction_id = ? AND status = 'pending'");
            $stmt->execute([$orderId]);

            // Send Flex notification (with error handling)
            try {
                if ($line) {
                    sendOrderStatusFlex($line, $db, $orderId, 'paid');
                }
            } catch (Exception $e) {
                error_log('sendOrderStatusFlex error: ' . $e->getMessage());
            }

            // V2.5: Auto-fulfill digital items (optional)
            try {
                if ($useTransactions && file_exists(__DIR__ . '/../classes/BusinessBot.php')) {
                    require_once __DIR__ . '/../classes/BusinessBot.php';
                    if (class_exists('BusinessBot') && method_exists('BusinessBot', 'autoFulfillDigitalItems')) {
                        $businessBot = new BusinessBot($db, $line, $currentBotId);
                        $businessBot->autoFulfillDigitalItems($orderId);
                    }
                }
            } catch (Exception $e) {
                error_log('BusinessBot error: ' . $e->getMessage());
            }

            // Award loyalty points (unified system)
            try {
                $stmt = $db->prepare("SELECT o.*, u.line_user_id, u.points as current_points FROM {$ordersTable} o JOIN users u ON o.user_id = u.id WHERE o.id = ?");
                $stmt->execute([$orderId]);
                $order = $stmt->fetch();

                if ($order && $order['user_id']) {
                    // Get points settings
                    $pointsPerBaht = 1; // Default: 1 แต้มต่อ 1 บาท
                    try {
                        $stmt = $db->prepare("SELECT points_per_baht FROM points_settings WHERE line_account_id = ? OR line_account_id IS NULL ORDER BY line_account_id DESC LIMIT 1");
                        $stmt->execute([$currentBotId]);
                        $settings = $stmt->fetch();
                        if ($settings) $pointsPerBaht = (float)$settings['points_per_baht'];
                    } catch (Exception $e) {}

                    // Calculate points
                    $earnedPoints = (int)floor($order['grand_total'] * $pointsPerBaht);

                    if ($earnedPoints > 0) {
                        $newBalance = ($order['current_points'] ?? 0) + $earnedPoints;

                        // Update users.points (for LIFF system)
                        $stmt = $db->prepare("UPDATE users SET points = points + ? WHERE id = ?");
                        $stmt->execute([$earnedPoints, $order['user_id']]);

                        // Log to points_history (for LIFF system)
                        try {
                            $stmt = $db->prepare("INSERT INTO points_history (line_account_id, user_id, points, type, description, reference_type, reference_id, balance_after) VALUES (?, ?, ?, 'earn', ?, 'order', ?, ?)");
                            $stmt->execute([$currentBotId, $order['user_id'], $earnedPoints, "แต้มจากออเดอร์ #{$order['order_number']}", $orderId, $newBalance]);
                        } catch (Exception $e) {
                            error_log('points_history insert error: ' . $e->getMessage());
                        }

                        // Also log to points_transactions (for legacy LoyaltyPoints system)
                        try {
                            $stmt = $db->prepare("INSERT INTO points_transactions (user_id, line_account_id, type, points, balance_after, reference_type, reference_id, description) VALUES (?, ?, 'earn', ?, ?, 'order', ?, ?)");
                            $stmt->execute([$order['user_id'], $currentBotId, $earnedPoints, $newBalance, $orderId, "Points from order #{$order['order_number']}"]);
                        } catch (Exception $e) {}

                        // ⚠️ ไม่ส่งแจ้งเตือนแต้มแยก - จะรวมในข้อความสถานะออเดอร์ด้านบน
                    }
                }
            } catch (Exception $e) {
                error_log('Award points error: ' . $e->getMessage());
            }
        } catch (Exception $e) {
            error_log('approve_payment error: ' . $e->getMessage());
        }

        header("Location: order-detail.php?id={$orderId}&updated=1");
        exit;
    }

    if ($action === 'update_shipping') {
        try {
            $stmt = $db->prepare("UPDATE {$ordersTable} SET shipping_name=?, shipping_phone=?, shipping_address=? WHERE id=?");
            $stmt->execute([$_POST['shipping_name'] ?? '', $_POST['shipping_phone'] ?? '', $_POST['shipping_address'] ?? '', $orderId]);
        } catch (Exception $e) {
            error_log('update_shipping error: ' . $e->getMessage());
        }

        header("Location: order-detail.php?id={$orderId}&updated=1");
        exit;
    }

    if ($action === 'reject_payment') {
        $stmt = $db->prepare("UPDATE payment_slips SET status = 'rejected' WHERE transaction_id = ? AND status = 'pending'");
        $stmt->execute([$orderId]);

        // Send rejection Flex message - ดึง reply_token ด้วย
        $stmt = $db->prepare("SELECT o.*, u.line_user_id, u.reply_token, u.reply_token_expires FROM {$ordersTable} o JOIN users u ON o.user_id = u.id WHERE o.id = ?");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();

        if ($order && $order['line_user_id']) {
            // Build rejection Flex
            $rejectFlex = [
                'type'     => 'flex',
                'altText'  => '❌ หลักฐานการชำระเงินไม่ถูกต้อง #' . $order['order_number'],
                'contents' => [
                    'type' => 'bubble',
                    'body' => [
                        'type'     => 'box',
                        'layout'   => 'vertical',
                        'contents' => [
                            ['type' => 'text', 'text' => '❌ สลิปไม่ถูกต้อง', 'weight' => 'bold', 'size' => 'xl', 'color' => '#EF4444'],
                            ['type' => 'text', 'text' => 'หลักฐานการชำระเงินไม่ถูกต้อง', 'size' => 'sm', 'color' => '#888888', 'margin' => 'sm'],
                            ['type' => 'separator', 'margin' => 'lg'],
                            ['type' => 'text', 'text' => '📋 Order #' . $order['order_number'], 'weight' => 'bold', 'size' => 'sm', 'margin' => 'lg'],
                            ['type' => 'text', 'text' => '💰 ยอดที่ต้องชำระ: ฿' . number_format($order['grand_total'], 0), 'size' => 'sm', 'color' => '#555555', 'margin' => 'md'],
                            [
                                'type'            => 'box',
                                'layout'          => 'vertical',
                                'margin'          => 'lg',
                                'paddingAll'      => 'md',
                                'backgroundColor' => '#FEF2F2',
                                'cornerRadius'    => 'md',
                                'contents'        => [
                                    ['type' => 'text', 'text' => '⚠️ กรุณาตรวจสอบและส่งหลักฐานใหม่', 'size' => 'sm', 'color' => '#DC2626', 'wrap' => true]
                                ]
                            ]
                        ]
                    ],
                    'footer' => [
                        'type'     => 'box',
                        'layout'   => 'vertical',
                        'contents' => [
                            ['type' => 'text', 'text' => 'หากมีข้อสงสัย กรุณาติดต่อร้านค้า', 'align' => 'center', 'color' => '#aaaaaa', 'size' => 'xs']
                        ]
                    ]
                ]
            ];
            // ใช้ sendMessage ถ้ามี หรือ fallback ไป pushMessage
            if (method_exists($line, 'sendMessage')) {
                $line->sendMessage($order['line_user_id'], [$rejectFlex], $order['reply_token'] ?? null, $order['reply_token_expires'] ?? null, $db);
            } else {
                $line->pushMessage($order['line_user_id'], [$rejectFlex]);
            }
        }

        header("Location: order-detail.php?id={$orderId}&rejected=1");
        exit;
    }

    if ($action === 'add_tracking') {
        try {
            $tracking = trim($_POST['tracking'] ?? '');
            if ($tracking) {
                // Update without line_account_id filter to ensure it works
                $stmt = $db->prepare("UPDATE {$ordersTable} SET shipping_tracking = ?, status = 'shipping' WHERE id = ?");
                $stmt->execute([$tracking, $orderId]);
                $affected = $stmt->rowCount();
                error_log("add_tracking: orderId={$orderId}, tracking={$tracking}, affected={$affected}");

                // Send Flex notification with tracking
                try {
                    if ($line) {
                        sendOrderStatusFlex($line, $db, $orderId, 'shipping', $tracking);
                    }
                } catch (Exception $e) {
                    error_log('sendOrderStatusFlex error: ' . $e->getMessage());
                }
            }
        } catch (Exception $e) {
            error_log('add_tracking error: ' . $e->getMessage());
        }

        header("Location: order-detail.php?id={$orderId}&tracking_added=1");
        exit;
    }
}

// Get order (filtered by current bot)
$stmt = $db->prepare("SELECT o.*, u.display_name, u.picture_url, u.line_user_id
                      FROM {$ordersTable} o
                      JOIN users u ON o.user_id = u.id
                      WHERE o.id = ? AND (o.line_account_id = ? OR o.line_account_id IS NULL)");
$stmt->execute([$orderId, $currentBotId]);
$order = $stmt->fetch();

if (!$order) {
    header('Location: orders.php');
    exit;
}

// Get order items
$stmt = $db->prepare("SELECT * FROM {$itemsTable} WHERE {$itemsFk} = ?");
$stmt->execute([$orderId]);
$items = $stmt->fetchAll();

// Get payment slips by transaction_id
$stmt = $db->prepare("SELECT * FROM payment_slips WHERE transaction_id = ? ORDER BY created_at DESC");
$stmt->execute([$orderId]);
$slips = $stmt->fetchAll();
// Repair malformed slip URL scheme ("https:/host" → "https://host") from a
// typo'd BASE_URL so existing slips still render in the admin <img>.
foreach ($slips as &$_slip) {
    if (!empty($_slip['image_url'])) {
        $_slip['image_url'] = preg_replace('#^(https?):/([^/])#i', '$1://$2', $_slip['image_url']);
    }
}
unset($_slip);

// Helpers + shop accounts for rendering the GhostX result on each slip card.
require_once __DIR__ . '/../classes/SlipVerifier.php';
$shopAccts = [];
try {
    $ssStmt = $db->prepare("SELECT promptpay_number, bank_accounts FROM shop_settings WHERE line_account_id = ? LIMIT 1");
    $ssStmt->execute([$currentBotId]);
    $ssRow = $ssStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    if (!empty($ssRow['promptpay_number'])) {
        $shopAccts[] = (string) $ssRow['promptpay_number'];
    }
    if (!empty($ssRow['bank_accounts'])) {
        $ssDec = json_decode($ssRow['bank_accounts'], true);
        if (is_array($ssDec)) {
            foreach ($ssDec as $b) {
                if (!empty($b['account_number'])) {
                    $shopAccts[] = (string) $b['account_number'];
                }
            }
        }
    }
} catch (\Throwable $e) { /* no shop_settings */ }
$orderGrandTotal = (float) ($order['grand_total'] ?? $order['total_amount'] ?? 0);

// Transaction type info for V2.5
$transactionTypes = [
    'purchase'     => ['icon' => '🛒', 'label' => 'ซื้อสินค้า'],
    'booking'      => ['icon' => '📅', 'label' => 'จองคิว'],
    'subscription' => ['icon' => '🔄', 'label' => 'สมัครสมาชิก'],
    'redemption'   => ['icon' => '🎁', 'label' => 'แลกของรางวัล']
];
$transType = $order['transaction_type'] ?? 'purchase';
$typeInfo  = $transactionTypes[$transType] ?? $transactionTypes['purchase'];

$pageTitle = "รายการ #{$order['order_number']}";

require_once __DIR__ . '/../includes/components/page-header.php';
require_once __DIR__ . '/../includes/components/toast.php';
require_once __DIR__ . '/../includes/header.php';
?>

<?= getPageHeaderStyles() ?>
<?= getToastStyles() ?>

<style>
.detail-grid {
    display: grid;
    grid-template-columns: 1fr 340px;
    gap: var(--space-6);
    align-items: start;
}
.detail-section {
    background: #ffffff;
    border: 1px solid var(--color-slate-200);
    border-radius: var(--radius-lg);
    box-shadow: 0 1px 3px rgba(15,23,42,0.04);
    margin-bottom: var(--space-6);
    overflow: hidden;
}
.detail-section-hdr {
    padding: var(--space-4) var(--space-5);
    border-bottom: 1px solid var(--color-slate-200);
}
.detail-section-hdr h4 {
    margin: 0; font-size: var(--text-base); font-weight: 600; color: var(--color-dark-800);
}
.detail-section-body { padding: var(--space-5); }
.order-status-pill {
    padding: 6px 16px; border-radius: var(--radius-full);
    font-size: var(--text-sm); font-weight: 500;
}
.customer-link {
    display: flex; align-items: center; padding: var(--space-4);
    background: var(--color-slate-50); border-radius: var(--radius-md);
    text-decoration: none; transition: background var(--transition-fast);
}
.customer-link:hover { background: var(--color-slate-100); }
.order-item-row {
    display: flex; justify-content: space-between; align-items: center;
    padding: var(--space-3); background: var(--color-slate-50);
    border-radius: var(--radius-md); margin-bottom: var(--space-2);
}
.totals-row { display: flex; justify-content: space-between; }
.form-lbl { display:block; font-size:var(--text-sm); font-weight:500; color:var(--color-dark-700); margin-bottom:var(--space-1); }
.form-ctrl {
    width:100%; height:40px; padding:0 12px;
    border:1px solid var(--color-slate-200); border-radius:var(--radius-md);
    background:var(--color-slate-50); font-size:var(--text-sm); color:var(--color-dark-800);
    box-sizing:border-box;
}
.form-ctrl:focus { outline:none; border-color:var(--color-primary-400); box-shadow:0 0 0 3px rgba(99,102,241,0.12); }
.form-area {
    width:100%; padding:10px 12px;
    border:1px solid var(--color-slate-200); border-radius:var(--radius-md);
    background:var(--color-slate-50); font-size:var(--text-sm); color:var(--color-dark-800);
    box-sizing:border-box; resize:vertical;
}
.form-area:focus { outline:none; border-color:var(--color-primary-400); box-shadow:0 0 0 3px rgba(99,102,241,0.12); }
.form-sel {
    width:100%; height:40px; padding:0 12px;
    border:1px solid var(--color-slate-200); border-radius:var(--radius-md);
    background:var(--color-slate-50); font-size:var(--text-sm); color:var(--color-dark-800);
    box-sizing:border-box;
}
.btn-act {
    width:100%; padding:12px; border:none; border-radius:var(--radius-md);
    font-size:var(--text-sm); font-weight:600; cursor:pointer;
    display:flex; align-items:center; justify-content:center; gap:var(--space-2);
    transition:all var(--transition-fast); margin-bottom:var(--space-2);
}
.btn-confirm { background:var(--color-primary-600); color:#fff; }
.btn-confirm:hover { background:var(--color-primary-700); }
.btn-track   { background:var(--color-violet-600); color:#fff; }
.btn-track:hover { background:#6d28d9; }
.btn-done    { background:var(--color-emerald-500); color:#fff; }
.btn-done:hover { background:var(--color-emerald-600); }
.btn-cancel-order {
    width:100%; padding:10px; background:transparent;
    border:1px solid var(--color-rose-300); color:var(--color-rose-600);
    border-radius:var(--radius-md); font-size:var(--text-sm); font-weight:500; cursor:pointer;
    transition:all var(--transition-fast);
}
.btn-cancel-order:hover { background:var(--color-rose-50); }
.btn-save {
    padding:10px var(--space-4); border:none; border-radius:var(--radius-md);
    background:var(--color-slate-500); color:#fff; font-size:var(--text-sm); font-weight:600;
    cursor:pointer; display:inline-flex; align-items:center; gap:var(--space-2);
}
.btn-save:hover { background:var(--color-dark-700); }
.btn-approve {
    flex:1; padding:12px; border:none; border-radius:var(--radius-md);
    background:var(--color-emerald-500); color:#fff; font-size:var(--text-sm); font-weight:600;
    cursor:pointer; display:flex; align-items:center; justify-content:center; gap:var(--space-2);
}
.btn-approve:hover { background:var(--color-emerald-600); }
.btn-reject {
    flex:1; padding:12px; border:none; border-radius:var(--radius-md);
    background:var(--color-rose-500); color:#fff; font-size:var(--text-sm); font-weight:600;
    cursor:pointer; display:flex; align-items:center; justify-content:center; gap:var(--space-2);
}
.btn-reject:hover { background:var(--color-rose-600); }
.slip-card { border:2px solid var(--color-slate-200); border-radius:var(--radius-lg); overflow:hidden; margin-bottom:var(--space-4); }
.slip-card-approved { border-color:var(--color-emerald-300); }
.slip-card-rejected  { border-color:var(--color-rose-300); }
.slip-card-pending   { border-color:var(--color-amber-300); }
.liff-info-box {
    margin-bottom:var(--space-4); padding:var(--space-4);
    background:var(--color-primary-50); border:1px solid var(--color-primary-100);
    border-radius:var(--radius-md);
}
@media (max-width: 1024px) { .detail-grid { grid-template-columns: 1fr; } }
.dark .detail-section { background:var(--color-dark-800); border-color:var(--color-dark-700); }
.dark .detail-section-hdr { border-color:var(--color-dark-700); }
.dark .detail-section-hdr h4 { color:var(--color-slate-100); }
.dark .customer-link { background:var(--color-dark-700); }
.dark .customer-link:hover { background:var(--color-dark-600); }
.dark .order-item-row { background:var(--color-dark-700); }
.dark .form-ctrl, .dark .form-area, .dark .form-sel {
    background:var(--color-dark-900); border-color:var(--color-dark-700); color:var(--color-slate-100);
}
.dark .liff-info-box { background:rgba(99,102,241,0.1); border-color:rgba(99,102,241,0.2); }
</style>

<?php
$statusColors = [
    'pending'   => 'var(--color-amber-100)',   'pending_c'   => 'var(--color-amber-700)',
    'confirmed' => 'var(--color-primary-100)', 'confirmed_c' => 'var(--color-primary-700)',
    'paid'      => 'var(--color-emerald-100)', 'paid_c'      => 'var(--color-emerald-700)',
    'shipping'  => 'var(--color-violet-600)',  'shipping_c'  => '#ffffff',
    'delivered' => 'var(--color-slate-100)',   'delivered_c' => 'var(--color-dark-700)',
    'cancelled' => 'var(--color-rose-50)',     'cancelled_c' => 'var(--color-rose-700)',
];
$statusLabels = [
    'pending'   => 'รอยืนยัน',  'confirmed' => 'ยืนยันแล้ว', 'paid'      => 'ชำระแล้ว',
    'shipping'  => 'กำลังส่ง',  'delivered' => 'ส่งแล้ว',    'cancelled' => 'ยกเลิก'
];

// สำหรับ COD ที่ status = confirmed แสดงว่ารอจัดส่ง
$isCOD         = ($order['payment_method'] ?? '') === 'cod';
$currentStatus = $order['status'] ?? 'pending';
$statusLabel   = ($isCOD && $currentStatus === 'confirmed')
    ? 'รอจัดส่ง (COD)'
    : ($statusLabels[$currentStatus] ?? 'รอดำเนินการ');
$badgeBg    = $statusColors[$currentStatus]         ?? 'var(--color-slate-100)';
$badgeColor = $statusColors[$currentStatus . '_c']  ?? 'var(--color-dark-700)';

// Parse delivery_info from LIFF
$deliveryInfo    = json_decode($order['delivery_info'] ?? '{}', true);
$shippingName    = $order['shipping_name']  ?? $deliveryInfo['name']  ?? '';
$shippingPhone   = $order['shipping_phone'] ?? $deliveryInfo['phone'] ?? '';
// Use full_address if available, otherwise combine parts or use address field
$liffAddress = $deliveryInfo['full_address'] ?? '';
if (empty($liffAddress)) {
    $liffAddress = trim(implode(' ', array_filter([
        $deliveryInfo['address']     ?? '',
        $deliveryInfo['subdistrict'] ?? '',
        $deliveryInfo['district']    ?? '',
        $deliveryInfo['province']    ?? '',
        $deliveryInfo['postcode']    ?? ''
    ])));
}
$shippingAddress = $order['shipping_address'] ?? $liffAddress;

echo renderPageHeader(
    "รายการ #{$order['order_number']}",
    date('d/m/Y H:i', strtotime($order['created_at'])),
    ['label' => 'กลับรายการคำสั่งซื้อ', 'icon' => 'fas fa-arrow-left', 'href' => 'orders.php', 'type' => 'link', 'variant' => 'primary'],
    [
        ['label' => 'ร้านค้า',        'href' => null],
        ['label' => 'คำสั่งซื้อ',      'href' => 'orders.php'],
        ['label' => '#' . $order['order_number'], 'href' => null],
    ]
);
?>

<?php if (isset($_GET['updated'])): ?>
<div style="margin-bottom:var(--space-4);padding:var(--space-4);background:var(--color-emerald-50);color:var(--color-emerald-700);border-radius:var(--radius-lg);font-size:var(--text-sm);">
    <i class="fas fa-check-circle" style="margin-right:var(--space-2);"></i>อัพเดทสำเร็จ!
</div>
<?php endif; ?>

<div class="detail-grid">
    <!-- Left column -->
    <div>
        <!-- Order Header Card -->
        <div class="detail-section">
            <div class="detail-section-body">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:var(--space-4);margin-bottom:var(--space-4);">
                    <div>
                        <div style="display:flex;align-items:center;gap:var(--space-2);margin-bottom:4px;">
                            <h3 style="margin:0;font-size:var(--text-xl);font-weight:700;color:var(--color-dark-800);">#<?= htmlspecialchars($order['order_number']) ?></h3>
                            <?php if ($useTransactions && $transType !== 'purchase'): ?>
                            <span style="padding:2px 8px;background:rgba(124,58,237,0.1);color:var(--color-violet-600);border-radius:var(--radius-full);font-size:var(--text-xs);"><?= $typeInfo['icon'] ?> <?= $typeInfo['label'] ?></span>
                            <?php endif; ?>
                        </div>
                        <p style="font-size:var(--text-sm);color:var(--color-dark-500);margin:0;"><?= date('d/m/Y H:i', strtotime($order['created_at'])) ?></p>
                    </div>
                    <span class="order-status-pill" style="background:<?= $badgeBg ?>;color:<?= $badgeColor ?>;"><?= $statusLabel ?></span>
                </div>

                <!-- Customer -->
                <a href="../user-detail.php?id=<?= $order['user_id'] ?>" class="customer-link">
                    <img src="<?= $order['picture_url'] ?: 'https://via.placeholder.com/48' ?>"
                         style="width:48px;height:48px;border-radius:var(--radius-full);object-fit:cover;margin-right:var(--space-4);" alt="">
                    <div style="flex:1;">
                        <p style="font-weight:500;color:var(--color-dark-800);margin:0;"><?= htmlspecialchars($order['display_name']) ?></p>
                        <p style="font-size:var(--text-sm);color:var(--color-dark-500);margin:0;">LINE User</p>
                    </div>
                    <i class="fas fa-chevron-right" style="color:var(--color-slate-400);"></i>
                </a>
            </div>
        </div>

        <!-- Items -->
        <div class="detail-section">
            <div class="detail-section-hdr"><h4>รายการสินค้า</h4></div>
            <div class="detail-section-body">
                <?php foreach ($items as $item): ?>
                <div class="order-item-row">
                    <div>
                        <p style="font-weight:500;color:var(--color-dark-800);margin:0;"><?= htmlspecialchars($item['product_name']) ?></p>
                        <p style="font-size:var(--text-sm);color:var(--color-dark-500);margin:0;">฿<?= number_format($item['product_price'], 2) ?> × <?= $item['quantity'] ?></p>
                    </div>
                    <p style="font-weight:500;color:var(--color-dark-800);margin:0;">฿<?= number_format($item['subtotal'], 2) ?></p>
                </div>
                <?php endforeach; ?>

                <div style="border-top:1px solid var(--color-slate-200);margin-top:var(--space-4);padding-top:var(--space-4);">
                    <div class="totals-row" style="font-size:var(--text-sm);color:var(--color-dark-500);margin-bottom:var(--space-2);">
                        <span>ยอดสินค้า</span><span>฿<?= number_format($order['total_amount'], 2) ?></span>
                    </div>
                    <div class="totals-row" style="font-size:var(--text-sm);color:var(--color-dark-500);margin-bottom:var(--space-2);">
                        <span>ค่าจัดส่ง</span>
                        <span><?= $order['shipping_fee'] > 0 ? '฿' . number_format($order['shipping_fee'], 2) : 'ฟรี' ?></span>
                    </div>
                    <?php if ($order['discount_amount'] > 0): ?>
                    <div class="totals-row" style="font-size:var(--text-sm);color:var(--color-emerald-600);margin-bottom:var(--space-2);">
                        <span>ส่วนลด</span><span>-฿<?= number_format($order['discount_amount'], 2) ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="totals-row" style="border-top:1px solid var(--color-slate-200);padding-top:var(--space-2);margin-top:var(--space-2);font-size:var(--text-lg);font-weight:700;">
                        <span style="color:var(--color-dark-800);">รวมทั้งหมด</span>
                        <span style="color:var(--color-emerald-600);">฿<?= number_format($order['grand_total'], 2) ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Shipping Info -->
        <div class="detail-section">
            <div class="detail-section-hdr">
                <h4><i class="fas fa-truck" style="color:var(--color-primary-500);margin-right:var(--space-2);"></i>ข้อมูลจัดส่ง</h4>
            </div>
            <div class="detail-section-body">
                <?php if (!empty($deliveryInfo['name']) || !empty($deliveryInfo['phone']) || !empty($liffAddress)): ?>
                <!-- LIFF Delivery Info (Read-only) -->
                <div class="liff-info-box">
                    <div style="display:flex;align-items:center;margin-bottom:var(--space-2);">
                        <span style="padding:2px 8px;background:var(--color-primary-600);color:#fff;font-size:var(--text-xs);border-radius:var(--radius-sm);margin-right:var(--space-2);">จาก LIFF</span>
                        <span style="font-size:var(--text-sm);color:var(--color-primary-600);">ข้อมูลที่ลูกค้ากรอกตอนสั่งซื้อ</span>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-3);font-size:var(--text-sm);">
                        <?php if (!empty($deliveryInfo['name'])): ?>
                        <div><span style="color:var(--color-dark-500);">ผู้รับ:</span> <span style="font-weight:500;"><?= htmlspecialchars($deliveryInfo['name']) ?></span></div>
                        <?php endif; ?>
                        <?php if (!empty($deliveryInfo['phone'])): ?>
                        <div><span style="color:var(--color-dark-500);">โทร:</span> <span style="font-weight:500;"><?= htmlspecialchars($deliveryInfo['phone']) ?></span></div>
                        <?php endif; ?>
                        <?php if (!empty($liffAddress)): ?>
                        <div style="grid-column:1/-1;"><span style="color:var(--color-dark-500);">ที่อยู่:</span> <span style="font-weight:500;"><?= htmlspecialchars($liffAddress) ?></span></div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Editable Shipping Form -->
                <form method="POST">
                    <input type="hidden" name="action" value="update_shipping">
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-4);margin-bottom:var(--space-4);">
                        <div>
                            <label class="form-lbl">ชื่อผู้รับ</label>
                            <input type="text" name="shipping_name" value="<?= htmlspecialchars($shippingName) ?>" class="form-ctrl">
                        </div>
                        <div>
                            <label class="form-lbl">เบอร์โทร</label>
                            <input type="text" name="shipping_phone" value="<?= htmlspecialchars($shippingPhone) ?>" class="form-ctrl">
                        </div>
                    </div>
                    <div style="margin-bottom:var(--space-4);">
                        <label class="form-lbl">ที่อยู่จัดส่ง</label>
                        <textarea name="shipping_address" rows="3" class="form-area"><?= htmlspecialchars($shippingAddress) ?></textarea>
                    </div>
                    <button type="submit" class="btn-save">
                        <i class="fas fa-save"></i>บันทึกที่อยู่
                    </button>
                </form>

                <?php if ($order['shipping_tracking']): ?>
                <div style="margin-top:var(--space-4);padding:var(--space-3) var(--space-4);background:rgba(124,58,237,0.08);border-radius:var(--radius-md);">
                    <p style="font-size:var(--text-sm);color:var(--color-violet-600);margin:0;">
                        <i class="fas fa-truck" style="margin-right:var(--space-2);"></i>เลขพัสดุ: <strong style="font-family:var(--font-mono);"><?= htmlspecialchars($order['shipping_tracking']) ?></strong>
                    </p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Right sidebar -->
    <div>
        <!-- Quick Actions -->
        <div class="detail-section">
            <div class="detail-section-hdr"><h4>⚡ Quick Actions</h4></div>
            <div class="detail-section-body">
                <!-- Status Flow -->
                <div style="margin-bottom:var(--space-5);">
                    <?php if ($order['status'] === 'pending'): ?>
                    <form method="POST">
                        <input type="hidden" name="action" value="update_status">
                        <input type="hidden" name="status" value="confirmed">
                        <button type="submit" class="btn-act btn-confirm">
                            <i class="fas fa-check"></i>ยืนยันออเดอร์
                        </button>
                    </form>
                    <?php endif; ?>

                    <?php if (in_array($order['status'], ['confirmed', 'pending']) && $order['payment_status'] !== 'paid'): ?>
                    <div style="padding:var(--space-3);background:var(--color-amber-50);border:1px solid var(--color-amber-200);border-radius:var(--radius-md);text-align:center;margin-bottom:var(--space-2);">
                        <i class="fas fa-clock" style="color:var(--color-amber-500);margin-right:4px;"></i>
                        <span style="font-size:var(--text-sm);color:var(--color-amber-700);">รอลูกค้าชำระเงิน</span>
                    </div>
                    <?php endif; ?>

                    <?php if ($order['payment_status'] === 'paid' && $order['status'] !== 'shipping' && $order['status'] !== 'delivered'): ?>
                    <form method="POST" id="trackingForm">
                        <input type="hidden" name="action" value="add_tracking">
                        <div style="margin-bottom:var(--space-2);">
                            <input type="text" name="tracking" required placeholder="กรอกเลขพัสดุ เช่น TH123456789" class="form-ctrl">
                        </div>
                        <button type="submit" class="btn-act btn-track">
                            <i class="fas fa-truck"></i>ส่งเลขพัสดุ
                        </button>
                    </form>
                    <?php endif; ?>

                    <?php if ($order['status'] === 'shipping'): ?>
                    <form method="POST">
                        <input type="hidden" name="action" value="update_status">
                        <input type="hidden" name="status" value="delivered">
                        <button type="submit" class="btn-act btn-done">
                            <i class="fas fa-box-open"></i>ยืนยันส่งถึงแล้ว
                        </button>
                    </form>
                    <?php endif; ?>

                    <?php if ($order['status'] === 'delivered'): ?>
                    <div style="padding:var(--space-4);background:var(--color-emerald-50);border:1px solid var(--color-emerald-200);border-radius:var(--radius-md);text-align:center;">
                        <i class="fas fa-check-circle" style="color:var(--color-emerald-500);font-size:24px;display:block;margin-bottom:var(--space-2);"></i>
                        <p style="font-weight:500;color:var(--color-emerald-700);margin:0;">ออเดอร์เสร็จสมบูรณ์</p>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if ($order['shipping_tracking']): ?>
                <div style="padding:var(--space-3);background:rgba(124,58,237,0.08);border-radius:var(--radius-md);margin-bottom:var(--space-4);">
                    <p style="font-size:var(--text-sm);color:var(--color-violet-700);margin:0;">
                        <i class="fas fa-truck" style="margin-right:4px;"></i>เลขพัสดุ:
                        <strong style="font-family:var(--font-mono);"><?= htmlspecialchars($order['shipping_tracking']) ?></strong>
                    </p>
                </div>
                <?php endif; ?>

                <!-- Cancel Order -->
                <?php if (!in_array($order['status'], ['delivered', 'cancelled'])): ?>
                <div style="border-top:1px solid var(--color-slate-200);padding-top:var(--space-4);">
                    <form method="POST">
                        <input type="hidden" name="action" value="update_status">
                        <input type="hidden" name="status" value="cancelled">
                        <button type="submit" onclick="return confirm('ยกเลิกออเดอร์นี้?')" class="btn-cancel-order">
                            <i class="fas fa-times" style="margin-right:var(--space-2);"></i>ยกเลิกออเดอร์
                        </button>
                    </form>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Manual Status Change -->
        <div class="detail-section">
            <div class="detail-section-hdr"><h4>🔧 เปลี่ยนสถานะ (Manual)</h4></div>
            <div class="detail-section-body">
                <form method="POST" style="display:flex;flex-direction:column;gap:var(--space-3);">
                    <input type="hidden" name="action" value="update_status">
                    <select name="status" class="form-sel">
                        <option value="pending"   <?= $order['status'] === 'pending'   ? 'selected' : '' ?>>⏳ รอยืนยัน</option>
                        <option value="confirmed" <?= $order['status'] === 'confirmed' ? 'selected' : '' ?>>✅ ยืนยันแล้ว</option>
                        <option value="paid"      <?= $order['status'] === 'paid'      ? 'selected' : '' ?>>💰 ชำระแล้ว</option>
                        <option value="shipping"  <?= $order['status'] === 'shipping'  ? 'selected' : '' ?>>🚚 กำลังจัดส่ง</option>
                        <option value="delivered" <?= $order['status'] === 'delivered' ? 'selected' : '' ?>>📦 จัดส่งแล้ว</option>
                        <option value="cancelled" <?= $order['status'] === 'cancelled' ? 'selected' : '' ?>>❌ ยกเลิก</option>
                    </select>
                    <button type="submit" class="btn-save" style="width:100%;justify-content:center;">
                        <i class="fas fa-save"></i>อัพเดท
                    </button>
                </form>
            </div>
        </div>

        <!-- Payment Slips -->
        <div class="detail-section">
            <div class="detail-section-hdr"><h4>💳 หลักฐานการชำระเงิน</h4></div>
            <div class="detail-section-body">
                <!-- Payment Status -->
                <div style="margin-bottom:var(--space-4);padding:var(--space-3);border-radius:var(--radius-md);background:<?= $order['payment_status'] === 'paid' ? 'var(--color-emerald-50)' : 'var(--color-amber-50)' ?>;border:1px solid <?= $order['payment_status'] === 'paid' ? 'var(--color-emerald-200)' : 'var(--color-amber-200)' ?>;">
                    <div style="display:flex;align-items:center;justify-content:space-between;">
                        <span style="font-size:var(--text-sm);font-weight:500;color:var(--color-dark-700);">สถานะการชำระ:</span>
                        <span style="padding:4px 12px;border-radius:var(--radius-full);font-size:var(--text-xs);font-weight:500;background:<?= $order['payment_status'] === 'paid' ? 'var(--color-emerald-500)' : 'var(--color-amber-500)' ?>;color:#fff;">
                            <?= $order['payment_status'] === 'paid' ? '✅ ชำระแล้ว' : '⏳ รอชำระ' ?>
                        </span>
                    </div>
                </div>

                <?php if (isset($_GET['verify'])):
                    $vk = $_GET['verify'];
                    $vMsg = [
                        'ok' => ['✅ ตรวจสอบสำเร็จ — GhostX ยืนยันสลิปและอนุมัติการชำระแล้ว', 'emerald'],
                        'amount_mismatch' => ['❌ ยอดเงินในสลิปไม่ตรงกับยอดออเดอร์', 'rose'],
                        'account_mismatch' => ['❌ บัญชีปลายทางในสลิปไม่ตรงกับบัญชีร้าน', 'rose'],
                        'not_a_slip' => ['❌ QR ไม่ใช่สลิปโอนเงินที่ตรวจสอบได้', 'rose'],
                        'duplicate_ref' => ['⚠️ สลิปนี้ถูกใช้กับออเดอร์อื่นแล้ว (กันสลิปซ้ำ)', 'amber'],
                        'no_qr' => ['⚠️ สลิปนี้ไม่มีข้อมูล QR ให้ตรวจสอบ', 'amber'],
                        'error' => ['⚠️ ตรวจสอบไม่สำเร็จ (เชื่อมต่อ GhostX ไม่ได้) ลองใหม่อีกครั้ง', 'amber'],
                    ];
                    if (isset($vMsg[$vk])) {
                        $vRow = $vMsg[$vk];
                    } elseif (strpos($vk, 'scan_error') === 0) {
                        // Surface GhostX's own message (e.g. "ไม่มีรหัสอ้างอิงรายการ").
                        $detail = trim(substr($vk, strlen('scan_error:')));
                        $vRow = ['⚠️ GhostX ตรวจสลิปไม่ผ่าน: ' . htmlspecialchars($detail !== '' ? $detail : 'เชื่อมต่อไม่ได้'), 'amber'];
                    } else {
                        $vRow = ['ผลการตรวจสอบ: ' . htmlspecialchars($vk), 'slate'];
                    }
                ?>
                <div style="margin-bottom:var(--space-4);padding:var(--space-3);border-radius:var(--radius-md);background:var(--color-<?= $vRow[1] ?>-50);border:1px solid var(--color-<?= $vRow[1] ?>-200);color:var(--color-<?= $vRow[1] ?>-700);font-size:var(--text-sm);font-weight:500;">
                    <?= $vRow[0] ?>
                </div>
                <?php endif; ?>

                <?php if (empty($slips)): ?>
                <div style="text-align:center;padding:var(--space-6);background:var(--color-slate-50);border-radius:var(--radius-md);">
                    <i class="fas fa-receipt" style="font-size:36px;color:var(--color-slate-300);display:block;margin-bottom:var(--space-2);"></i>
                    <p style="color:var(--color-dark-500);font-size:var(--text-sm);margin:0;">ยังไม่มีหลักฐานการชำระเงิน</p>
                </div>
                <?php else: ?>
                <div>
                    <?php foreach ($slips as $slip):
                        $slipClass = $slip['status'] === 'approved' ? 'slip-card-approved' : ($slip['status'] === 'rejected' ? 'slip-card-rejected' : 'slip-card-pending');
                        $slipBadge = $slip['status'] === 'approved' ? '✅ อนุมัติแล้ว' : ($slip['status'] === 'rejected' ? '❌ ปฏิเสธ' : '⏳ รอตรวจสอบ');
                        $slipBadgeBg = $slip['status'] === 'approved' ? 'var(--color-emerald-500)' : ($slip['status'] === 'rejected' ? 'var(--color-rose-500)' : 'var(--color-amber-500)');
                        // Render via a same-origin relative path so the image always
                        // loads on the current host over https, regardless of how the
                        // stored image_url was built (wrong host / http mixed-content /
                        // malformed scheme). Files live in the shared /uploads/slips/.
                        $slipFile = basename((string) (parse_url((string) $slip['image_url'], PHP_URL_PATH) ?: $slip['image_url']));
                        $slipSrc = $slipFile !== '' ? '/uploads/slips/' . rawurlencode($slipFile) : (string) $slip['image_url'];
                    ?>
                    <div class="slip-card <?= $slipClass ?>">
                        <div style="position:relative;background:var(--color-slate-100);">
                            <img src="<?= htmlspecialchars($slipSrc) ?>"
                                 style="width:100%;max-height:256px;object-fit:contain;cursor:pointer;display:block;"
                                 onclick="openSlipModal('<?= htmlspecialchars($slipSrc) ?>')"
                                 alt="payment slip">
                            <div style="position:absolute;top:8px;right:8px;">
                                <span style="padding:4px 10px;border-radius:var(--radius-full);font-size:var(--text-xs);font-weight:500;background:<?= $slipBadgeBg ?>;color:#fff;box-shadow:var(--shadow-glass);"><?= $slipBadge ?></span>
                            </div>
                            <button onclick="openSlipModal('<?= htmlspecialchars($slipSrc) ?>')"
                                    style="position:absolute;bottom:8px;right:8px;padding:4px 10px;background:rgba(0,0,0,0.5);color:#fff;border:none;border-radius:var(--radius-sm);font-size:var(--text-xs);cursor:pointer;">
                                <i class="fas fa-expand" style="margin-right:4px;"></i>ขยาย
                            </button>
                        </div>
                        <div style="padding:var(--space-3);background:#fff;">
                            <div style="display:flex;justify-content:space-between;align-items:center;font-size:var(--text-sm);">
                                <span style="color:var(--color-dark-500);"><i class="fas fa-clock" style="margin-right:4px;"></i><?= date('d/m/Y H:i', strtotime($slip['created_at'])) ?></span>
                                <?php if ($slip['amount']): ?>
                                <span style="font-weight:500;color:var(--color-emerald-600);">฿<?= number_format($slip['amount'], 2) ?></span>
                                <?php endif; ?>
                            </div>
                            <?php if ($slip['admin_note']): ?>
                            <p style="font-size:var(--text-xs);color:var(--color-dark-500);margin:4px 0 0;"><i class="fas fa-sticky-note" style="margin-right:4px;"></i><?= htmlspecialchars($slip['admin_note']) ?></p>
                            <?php endif; ?>
                            <?php
                            $vRef = $slip['verify_ref'] ?? null;
                            $qrPayload = $slip['qr_payload'] ?? null;
                            $vData = !empty($slip['verify_data']) ? json_decode($slip['verify_data'], true) : null;
                            $tr = $vData['slipVerification']['transfer'] ?? null;
                            $slipAmt = isset($tr['amount']['amount']) ? (float) $tr['amount']['amount'] : null;
                            $toAcc = $tr['toAccountNo'] ?? null;
                            $fromName = $tr['fromAccountName'] ?? ($tr['fromBankName'] ?? null);
                            $txRef = $tr['transactionRef'] ?? $vRef;
                            $txTime = $tr['transactionDateTime'] ?? null;
                            $amtOk = $slipAmt !== null && SlipVerifier::amountMatches($orderGrandTotal, $slipAmt);
                            $acctOk = false;
                            if ($toAcc) { foreach ($shopAccts as $a) { if (SlipVerifier::accountMatches((string) $a, (string) $toAcc)) { $acctOk = true; break; } } }
                            $vErr = (!$tr && is_array($vData) && !empty($vData['error'])) ? (string) $vData['error'] : null;
                            ?>
                            <div style="margin-top:8px;padding-top:8px;border-top:1px dashed var(--color-slate-200);font-size:var(--text-xs);">
                                <?php if ($tr): ?>
                                    <div style="font-weight:600;color:var(--color-dark-700);margin-bottom:4px;"><i class="fas fa-shield-alt" style="margin-right:4px;color:#6366f1;"></i>ผลตรวจสลิป (GhostX)</div>
                                    <div style="color:var(--color-dark-600);line-height:1.8;">
                                        <div>ยอดในสลิป: <b>฿<?= number_format((float) $slipAmt, 2) ?></b>
                                            <?php if ($amtOk): ?><span style="color:var(--color-emerald-600);">✓ ตรง</span><?php else: ?><span style="color:var(--color-rose-500);">✗ ออเดอร์ ฿<?= number_format($orderGrandTotal, 2) ?></span><?php endif; ?>
                                        </div>
                                        <?php if ($toAcc): ?>
                                        <div>เข้าบัญชี: <b><?= htmlspecialchars($toAcc) ?></b>
                                            <?php if ($acctOk): ?><span style="color:var(--color-emerald-600);">✓ ตรงบัญชีร้าน</span><?php else: ?><span style="color:var(--color-rose-500);">✗ ไม่ตรงบัญชีร้าน</span><?php endif; ?>
                                        </div>
                                        <?php endif; ?>
                                        <?php if ($fromName): ?><div>จาก: <?= htmlspecialchars($fromName) ?></div><?php endif; ?>
                                        <?php if ($txRef): ?><div style="color:var(--color-dark-400);">Ref: <?= htmlspecialchars($txRef) ?><?php if ($txTime): ?> · <?= htmlspecialchars(date('d/m/Y H:i', strtotime($txTime))) ?><?php endif; ?></div><?php endif; ?>
                                    </div>
                                    <?php if ($vRef): ?>
                                        <div style="color:var(--color-emerald-600);font-weight:600;margin-top:4px;">✅ ยืนยันแล้ว — อนุมัติการชำระแล้ว</div>
                                    <?php elseif ($amtOk): ?>
                                        <?php /* amount-only mode: amount match is enough; account is informational */ ?>
                                        <div style="color:var(--color-emerald-600);font-weight:600;margin-top:4px;">✓ GhostX ยืนยันสลิปจริง + ยอดตรง — พร้อมอนุมัติ<?= ($toAcc && !$acctOk) ? ' (บัญชีปลายทางต่างจากที่ตั้งค่า โปรดตรวจดู)' : '' ?></div>
                                        <?php if ($slip['status'] !== 'approved'): ?>
                                        <form method="POST" style="margin:6px 0 0;">
                                            <input type="hidden" name="action" value="verify_slip">
                                            <input type="hidden" name="slip_id" value="<?= (int) $slip['id'] ?>">
                                            <button type="submit" style="width:100%;padding:6px 10px;background:#059669;color:#fff;border:none;border-radius:var(--radius-sm);font-size:var(--text-xs);font-weight:600;cursor:pointer;"><i class="fas fa-check-circle" style="margin-right:4px;"></i>อนุมัติ (GhostX + ยอดตรง)</button>
                                        </form>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <div style="color:var(--color-rose-500);margin-top:4px;">⚠️ ยอดในสลิปไม่ตรงกับออเดอร์ — ตรวจสอบก่อนอนุมัติ</div>
                                        <?php if ($slip['status'] !== 'approved'): ?>
                                        <form method="POST" style="margin:6px 0 0;">
                                            <input type="hidden" name="action" value="verify_slip">
                                            <input type="hidden" name="slip_id" value="<?= (int) $slip['id'] ?>">
                                            <button type="submit" style="width:100%;padding:6px 10px;background:#6366f1;color:#fff;border:none;border-radius:var(--radius-sm);font-size:var(--text-xs);font-weight:500;cursor:pointer;"><i class="fas fa-rotate-right" style="margin-right:4px;"></i>ประเมินซ้ำ</button>
                                        </form>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                <?php elseif ($vErr): ?>
                                    <div style="font-weight:600;color:var(--color-rose-600);margin-bottom:2px;"><i class="fas fa-triangle-exclamation" style="margin-right:4px;"></i>GhostX ตรวจสลิปไม่ผ่าน</div>
                                    <div style="color:var(--color-dark-600);">ข้อความจาก GhostX: <b><?= htmlspecialchars($vErr) ?></b></div>
                                    <div style="color:var(--color-dark-400);margin-top:2px;">มักเกิดจากรูปไม่ใช่สลิปโอนสำเร็จ / QR ไม่มีรหัสอ้างอิงรายการ — ตรวจรูปกับลูกค้า หรือกดอนุมัติเพื่อยืนยันเอง</div>
                                    <?php if (!empty($qrPayload) && $slip['status'] !== 'approved'): ?>
                                    <form method="POST" style="margin:6px 0 0;">
                                        <input type="hidden" name="action" value="verify_slip">
                                        <input type="hidden" name="slip_id" value="<?= (int) $slip['id'] ?>">
                                        <button type="submit" style="width:100%;padding:6px 10px;background:#6366f1;color:#fff;border:none;border-radius:var(--radius-sm);font-size:var(--text-xs);font-weight:500;cursor:pointer;"><i class="fas fa-rotate-right" style="margin-right:4px;"></i>ลองตรวจกับ GhostX อีกครั้ง</button>
                                    </form>
                                    <?php endif; ?>
                                <?php elseif (!empty($qrPayload) && $slip['status'] !== 'approved'): ?>
                                    <form method="POST" style="margin:0;">
                                        <input type="hidden" name="action" value="verify_slip">
                                        <input type="hidden" name="slip_id" value="<?= (int) $slip['id'] ?>">
                                        <button type="submit" style="width:100%;padding:6px 10px;background:#6366f1;color:#fff;border:none;border-radius:var(--radius-sm);font-size:var(--text-xs);font-weight:500;cursor:pointer;"><i class="fas fa-qrcode" style="margin-right:4px;"></i>ตรวจสอบกับ GhostX</button>
                                    </form>
                                <?php elseif (empty($qrPayload)): ?>
                                    <div style="color:var(--color-dark-400);margin-bottom:6px;"><i class="fas fa-info-circle" style="margin-right:4px;"></i>ยังไม่มีข้อมูล QR (ลูกค้าอัปผ่านช่องที่ไม่ถอด QR หรือถอดไม่ติด) — ถอดจากรูปได้</div>
                                    <?php if ($slip['status'] !== 'approved'): ?>
                                    <form method="POST" id="qrform-<?= (int) $slip['id'] ?>" style="margin:0;">
                                        <input type="hidden" name="action" value="verify_slip">
                                        <input type="hidden" name="slip_id" value="<?= (int) $slip['id'] ?>">
                                        <input type="hidden" name="qr_data" id="qrdata-<?= (int) $slip['id'] ?>">
                                        <button type="button" onclick="decodeSlipAndVerify(this, <?= (int) $slip['id'] ?>, '<?= htmlspecialchars($slipSrc, ENT_QUOTES) ?>')" style="width:100%;padding:6px 10px;background:#6366f1;color:#fff;border:none;border-radius:var(--radius-sm);font-size:var(--text-xs);font-weight:500;cursor:pointer;"><i class="fas fa-qrcode" style="margin-right:4px;"></i>ถอด QR จากรูป &amp; ตรวจสอบ</button>
                                    </form>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Action Buttons -->
                <?php if ($order['payment_status'] !== 'paid'): ?>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-2);margin-top:var(--space-4);">
                    <form method="POST">
                        <input type="hidden" name="action" value="approve_payment">
                        <button type="submit" onclick="return confirm('ยืนยันการชำระเงิน?')" class="btn-approve" style="width:100%;">
                            <i class="fas fa-check-circle"></i>อนุมัติ
                        </button>
                    </form>
                    <form method="POST">
                        <input type="hidden" name="action" value="reject_payment">
                        <button type="submit" onclick="return confirm('ปฏิเสธหลักฐานนี้?')" class="btn-reject" style="width:100%;">
                            <i class="fas fa-times-circle"></i>ปฏิเสธ
                        </button>
                    </form>
                </div>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Note -->
        <?php if ($order['note']): ?>
        <div class="detail-section">
            <div class="detail-section-hdr"><h4>หมายเหตุ</h4></div>
            <div class="detail-section-body">
                <p style="color:var(--color-dark-600);font-size:var(--text-sm);margin:0;"><?= nl2br(htmlspecialchars($order['note'])) ?></p>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Slip Modal -->
<div id="slipModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.9);z-index:2000;align-items:center;justify-content:center;padding:var(--space-4);">
    <button onclick="closeSlipModal()" style="position:absolute;top:var(--space-4);right:var(--space-4);color:#fff;font-size:28px;background:transparent;border:none;cursor:pointer;line-height:1;">
        <i class="fas fa-times"></i>
    </button>
    <img id="slipModalImage" src="" style="max-width:100%;max-height:100%;object-fit:contain;border-radius:var(--radius-lg);box-shadow:var(--shadow-glass-xl);" alt="slip">
</div>

<?= renderToastContainer() ?>

<script>
function openSlipModal(imageUrl) {
    document.getElementById('slipModalImage').src = imageUrl;
    document.getElementById('slipModal').style.display = 'flex';
}

function closeSlipModal() {
    document.getElementById('slipModal').style.display = 'none';
}

// Close modal on click outside
document.getElementById('slipModal')?.addEventListener('click', function(e) {
    if (e.target === this) closeSlipModal();
});

// Close modal on ESC key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeSlipModal();
});
</script>

<!-- Admin-side slip QR decoding: native BarcodeDetector (most reliable) + jsQR fallback -->
<script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js"></script>
<script>
function loadImage(src) {
    return new Promise(function (resolve, reject) {
        var img = new Image();
        img.crossOrigin = 'anonymous';
        img.onload = function () { resolve(img); };
        img.onerror = function () { reject(new Error('image load failed')); };
        img.src = src;
    });
}

function jsqrAtScale(img, scale) {
    if (typeof jsQR !== 'function' || !scale || scale <= 0) return null;
    var w = Math.max(1, Math.round(img.naturalWidth * scale));
    var h = Math.max(1, Math.round(img.naturalHeight * scale));
    var canvas = document.createElement('canvas');
    canvas.width = w; canvas.height = h;
    var ctx = canvas.getContext('2d');
    ctx.drawImage(img, 0, 0, w, h);
    var d = ctx.getImageData(0, 0, w, h);
    var code = jsQR(d.data, w, h, { inversionAttempts: 'attemptBoth' });
    return (code && code.data) ? code.data : null;
}

async function detectSlipQR(img) {
    // 1) Native BarcodeDetector — uses the OS decoder, far more robust than jsQR.
    try {
        if ('BarcodeDetector' in window) {
            var det = new BarcodeDetector({ formats: ['qr_code'] });
            var codes = await det.detect(img);
            if (codes && codes.length && codes[0].rawValue) return codes[0].rawValue;
        }
    } catch (e) { /* fall through to jsQR */ }

    // 2) jsQR at several scales (small QR in a tall slip often needs downscaling).
    var maxDim = Math.max(img.naturalWidth, img.naturalHeight);
    var scales = [1, maxDim > 1200 ? 1200 / maxDim : 0.8, 0.5, 1.5];
    for (var i = 0; i < scales.length; i++) {
        var r = jsqrAtScale(img, scales[i]);
        if (r) return r;
    }
    return null;
}

async function decodeSlipAndVerify(btn, slipId, imgSrc) {
    var original = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin" style="margin-right:4px;"></i>กำลังถอด QR...';
    try {
        var img = await loadImage(imgSrc);
        var qr = await detectSlipQR(img);
        if (qr) {
            document.getElementById('qrdata-' + slipId).value = qr;
            document.getElementById('qrform-' + slipId).submit();
            return;
        }
        alert('ถอด QR จากรูปไม่สำเร็จ — ลองเปิดรูปเต็ม (ขยาย) แล้วลองใหม่ หรือกดอนุมัติเพื่อยืนยันเอง');
    } catch (e) {
        alert('เกิดข้อผิดพลาดในการถอด QR: ' + e.message);
    }
    btn.disabled = false;
    btn.innerHTML = original;
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
