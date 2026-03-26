<?php
/**
 * Odoo Flex Templates Stub
 * Simplified version for testing
 */

class OdooFlexTemplates
{
    public static function bdoPaymentRequest($data, $qrCodeUrl = '')
    {
        $bdoRef   = $data['bdo_ref']   ?? 'BDO-XXX';
        $orderRef = $data['order_ref'] ?? '-';
        $dueDate  = $data['due_date']  ?? date('Y-m-d');
        $isTest   = strpos($bdoRef, '[TEST]') !== false;

        $invoiceUrl = $data['invoice']['pdf_url'] ?? '#';

        // Financial summary values
        $fs         = $data['financial_summary'] ?? [];
        $soAmt      = isset($fs['so_amount'])          ? (float)$fs['so_amount']          : null;
        $invAmt     = isset($fs['outstanding_amount'])  ? (float)$fs['outstanding_amount'] : null;
        $cnAmt      = isset($fs['credit_note_amount'])  ? (float)$fs['credit_note_amount'] : null;
        $depAmt     = isset($fs['deposit_amount'])      ? (float)$fs['deposit_amount']     : null;
        $netAmt     = isset($fs['net_to_pay'])          ? (float)$fs['net_to_pay']         : (float)($data['amount_total'] ?? 0);

        $fmt = function($v) { return '฿' . number_format($v, 2); };

        // Invoice rows (max 8)
        $invoices = array_slice($data['invoices'] ?? [], 0, 8);

        // ── helper: one summary row ──────────────────────────────────────
        $summaryRow = function(string $label, string $value, string $valueColor = '#111827', bool $separator = false) {
            $row = [
                'type'   => 'box',
                'layout' => 'horizontal',
                'margin' => 'xs',
                'contents' => [
                    [
                        'type'  => 'text',
                        'text'  => $label,
                        'size'  => 'sm',
                        'color' => '#6b7280',
                        'flex'  => 1,
                    ],
                    [
                        'type'   => 'text',
                        'text'   => $value,
                        'size'   => 'sm',
                        'weight' => 'bold',
                        'color'  => $valueColor,
                        'align'  => 'end',
                        'flex'   => 1,
                    ],
                ],
            ];
            return $row;
        };

        // ── Body ─────────────────────────────────────────────────────────
        $bodyContents = [];

        // Title + BDO ref
        $bodyContents[] = [
            'type'   => 'box',
            'layout' => 'horizontal',
            'contents' => [
                [
                    'type'   => 'box',
                    'layout' => 'vertical',
                    'flex'   => 1,
                    'contents' => [
                        [
                            'type'   => 'text',
                            'text'   => 'ใบแจ้งชำระค่าสินค้า',
                            'weight' => 'bold',
                            'size'   => 'md',
                            'color'  => '#1d4ed8',
                        ],
                        [
                            'type'  => 'text',
                            'text'  => ($isTest ? '[TEST] ' : '') . $bdoRef,
                            'size'  => 'xs',
                            'color' => '#6b7280',
                            'margin' => 'xs',
                        ],
                    ],
                ],
                [
                    'type'  => 'text',
                    'text'  => $dueDate,
                    'size'  => 'xxs',
                    'color' => '#9ca3af',
                    'align' => 'end',
                    'flex'  => 0,
                ],
            ],
        ];

        // Financial summary section
        $bodyContents[] = ['type' => 'separator', 'margin' => 'lg'];
        $bodyContents[] = [
            'type'   => 'text',
            'text'   => 'สรุปยอด',
            'size'   => 'xs',
            'weight' => 'bold',
            'color'  => '#374151',
            'margin' => 'lg',
        ];

        if ($soAmt !== null)  $bodyContents[] = $summaryRow('SO รอบนี้',        $fmt($soAmt),  '#374151');
        if ($invAmt !== null) $bodyContents[] = $summaryRow('Invoice ค้างชำระ', $fmt($invAmt), '#d97706');
        if ($cnAmt !== null)  $bodyContents[] = $summaryRow('Credit Note',       $fmt(-abs($cnAmt)), '#dc2626');
        if ($depAmt !== null) $bodyContents[] = $summaryRow('เงินมัดจำ',         $fmt(-abs($depAmt)), '#dc2626');

        // Net to pay — highlighted row
        $bodyContents[] = ['type' => 'separator', 'margin' => 'sm'];
        $bodyContents[] = [
            'type'            => 'box',
            'layout'          => 'horizontal',
            'margin'          => 'sm',
            'backgroundColor' => '#eff6ff',
            'cornerRadius'    => '6px',
            'paddingAll'      => '10px',
            'contents' => [
                [
                    'type'   => 'text',
                    'text'   => 'Net To Pay',
                    'size'   => 'sm',
                    'weight' => 'bold',
                    'color'  => '#1d4ed8',
                    'flex'   => 1,
                ],
                [
                    'type'   => 'text',
                    'text'   => $fmt($netAmt),
                    'size'   => 'lg',
                    'weight' => 'bold',
                    'color'  => '#059669',
                    'align'  => 'end',
                    'flex'   => 1,
                ],
            ],
        ];

        // Invoice list
        if (!empty($invoices)) {
            $bodyContents[] = ['type' => 'separator', 'margin' => 'lg'];
            $bodyContents[] = [
                'type'   => 'text',
                'text'   => 'ใบแจ้งหนี้ค้างชำระ',
                'size'   => 'xs',
                'weight' => 'bold',
                'color'  => '#6b7280',
                'margin' => 'lg',
            ];
            foreach ($invoices as $inv) {
                $invNum  = $inv['number'] ?? $inv['name'] ?? '-';
                $invVal  = isset($inv['residual']) ? $fmt((float)$inv['residual']) : '-';
                $invDate = $inv['date'] ?? '';
                $origin  = $inv['origin'] ?? '';
                $sub     = trim(implode(' ', array_filter([$invDate, $origin])));
                $bodyContents[] = [
                    'type'   => 'box',
                    'layout' => 'horizontal',
                    'margin' => 'sm',
                    'contents' => [
                        [
                            'type'   => 'box',
                            'layout' => 'vertical',
                            'flex'   => 3,
                            'contents' => array_values(array_filter([
                                ['type' => 'text', 'text' => $invNum, 'size' => 'xs', 'color' => '#374151', 'weight' => 'bold'],
                                $sub ? ['type' => 'text', 'text' => $sub, 'size' => 'xxs', 'color' => '#9ca3af', 'wrap' => true] : null,
                            ])),
                        ],
                        [
                            'type'   => 'text',
                            'text'   => $invVal,
                            'size'   => 'xs',
                            'color'  => '#d97706',
                            'align'  => 'end',
                            'flex'   => 2,
                        ],
                    ],
                ];
            }
            $total = count($data['invoices'] ?? []);
            if ($total > 8) {
                $bodyContents[] = [
                    'type'   => 'text',
                    'text'   => '... และอีก ' . ($total - 8) . ' รายการ',
                    'size'   => 'xxs',
                    'color'  => '#9ca3af',
                    'margin' => 'xs',
                ];
            }
        }

        // ── Bank details section ────────────────────────────────────────
        $bank = $data['bank_account'] ?? [];
        $bankName    = $bank['bank_name']      ?? 'ธนาคารกสิกรไทย';
        $bankAccNo   = $bank['account_number'] ?? '';
        $bankAccName = $bank['account_name']   ?? '';

        // clipboard action — LINE spec: type=clipboard, clipboardText=<text>
        // action อยู่ได้เฉพาะที่ box/button ระดับเดียว ห้าม nested
        $copyAction = [
            'type'          => 'clipboard',
            'label'         => 'คัดลอกเลขบัญชี',
            'clipboardText' => $bankAccNo,
        ];

        $bankSection = [];
        $bankSection[] = ['type' => 'separator', 'margin' => 'lg'];
        $bankSection[] = [
            'type'            => 'box',
            'layout'          => 'vertical',
            'margin'          => 'lg',
            'backgroundColor' => '#f0fdf4',
            'cornerRadius'    => '12px',
            'paddingAll'      => '14px',
            'borderWidth'     => '1px',
            'borderColor'     => '#86efac',
            'contents' => [
                // ── Header: KBank logo + label ──
                [
                    'type'       => 'box',
                    'layout'     => 'horizontal',
                    'spacing'    => 'sm',
                    'alignItems' => 'center',
                    'contents' => [
                        [
                            'type'       => 'image',
                            'url'        => 'https://www.kasikornbank.com/SiteCollectionDocuments/about/img/logo/logo.png',
                            'size'       => '28px',
                            'aspectMode' => 'fit',
                            'flex'       => 0,
                        ],
                        [
                            'type'   => 'box',
                            'layout' => 'vertical',
                            'flex'   => 1,
                            'contents' => [
                                [
                                    'type'   => 'text',
                                    'text'   => $bankName,
                                    'size'   => 'xs',
                                    'weight' => 'bold',
                                    'color'  => '#15803d',
                                ],
                                [
                                    'type'  => 'text',
                                    'text'  => 'กรุณาโอนเงินมายังบัญชีนี้',
                                    'size'  => 'xxs',
                                    'color' => '#6b7280',
                                ],
                            ],
                        ],
                    ],
                ],
                ['type' => 'separator', 'margin' => 'sm', 'color' => '#bbf7d0'],
                // ── Account number row — action อยู่ที่ outer box เดียว ──
                [
                    'type'            => 'box',
                    'layout'          => 'horizontal',
                    'margin'          => 'sm',
                    'backgroundColor' => '#ffffff',
                    'cornerRadius'    => '8px',
                    'paddingAll'      => '10px',
                    'alignItems'      => 'center',
                    'action'          => $copyAction,
                    'contents' => [
                        // Account details (no action — inherits from parent box)
                        [
                            'type'   => 'box',
                            'layout' => 'vertical',
                            'flex'   => 1,
                            'contents' => [
                                [
                                    'type'  => 'text',
                                    'text'  => 'เลขที่บัญชี',
                                    'size'  => 'xxs',
                                    'color' => '#9ca3af',
                                ],
                                [
                                    'type'   => 'text',
                                    'text'   => $bankAccNo,
                                    'size'   => 'xl',
                                    'weight' => 'bold',
                                    'color'  => '#111827',
                                    'margin' => 'xs',
                                ],
                                [
                                    'type'  => 'text',
                                    'text'  => $bankAccName,
                                    'size'  => 'xs',
                                    'color' => '#6b7280',
                                    'wrap'  => true,
                                ],
                            ],
                        ],
                        // Copy badge — visual only, no action (parent handles it)
                        [
                            'type'            => 'box',
                            'layout'          => 'vertical',
                            'flex'            => 0,
                            'backgroundColor' => '#16a34a',
                            'cornerRadius'    => '20px',
                            'paddingStart'    => '10px',
                            'paddingEnd'      => '10px',
                            'paddingTop'      => '6px',
                            'paddingBottom'   => '6px',
                            'alignItems'      => 'center',
                            'justifyContent'  => 'center',
                            'contents' => [
                                [
                                    'type'   => 'text',
                                    'text'   => '⎘ คัดลอก',
                                    'size'   => 'xs',
                                    'color'  => '#ffffff',
                                    'weight' => 'bold',
                                    'align'  => 'center',
                                ],
                            ],
                        ],
                    ],
                ],
                // ── Footer note ──
                [
                    'type'   => 'text',
                    'text'   => 'แตะที่ตัวเลขเพื่อคัดลอก  •  หลังโอนกรุณาส่งสลิปใน LINE',
                    'size'   => 'xxs',
                    'color'  => '#9ca3af',
                    'align'  => 'center',
                    'wrap'   => true,
                    'margin' => 'sm',
                ],
            ],
        ];

        $bodyContents = array_merge($bodyContents, $bankSection);

        return [
            'type' => 'bubble',
            'size' => 'mega',
            'body' => [
                'type'       => 'box',
                'layout'     => 'vertical',
                'paddingAll' => '20px',
                'contents'   => $bodyContents,
            ],
            'footer' => [
                'type'       => 'box',
                'layout'     => 'vertical',
                'paddingAll' => '12px',
                'contents'   => [
                    [
                        'type'   => 'button',
                        'action' => [
                            'type'  => 'uri',
                            'label' => 'ชำระเงิน',
                            'uri'   => $invoiceUrl ?: 'https://cny.re-ya.com',
                        ],
                        'style'  => 'primary',
                        'color'  => '#1d4ed8',
                        'height' => 'sm',
                    ],
                ],
            ],
        ];
    }

    /**
     * Helper: build a summary column box (used in financial summary row).
     */
    private static function summaryCol(string $label, string $value, string $color): array
    {
        return [
            'type'   => 'box',
            'layout' => 'vertical',
            'flex'   => 1,
            'contents' => [
                [
                    'type'  => 'text',
                    'text'  => $label,
                    'size'  => 'xxs',
                    'color' => '#9ca3af',
                    'align' => 'center',
                ],
                [
                    'type'   => 'text',
                    'text'   => $value,
                    'size'   => 'xxs',
                    'weight' => 'bold',
                    'color'  => $color,
                    'align'  => 'center',
                    'wrap'   => true,
                ],
            ],
        ];
    }

    /**
     * Build footer buttons for Flex message.
     * If liffUrl is provided, show both "ดูใบแจ้งหนี้" and "อัพโหลดสลิป" buttons.
     * Otherwise show only the invoice button.
     */
    private static function buildFooterButtons($invoiceUrl, $liffUrl)
    {
        $buttons = [
            [
                'type' => 'button',
                'action' => [
                    'type' => 'uri',
                    'label' => 'ดูใบแจ้งหนี้',
                    'uri' => $invoiceUrl ?: 'https://cny.re-ya.com',
                ],
                'style' => 'primary',
            ],
        ];

        if (!empty($liffUrl)) {
            $buttons[] = [
                'type' => 'button',
                'action' => [
                    'type' => 'uri',
                    'label' => 'อัพโหลดสลิป',
                    'uri' => $liffUrl,
                ],
                'style' => 'secondary',
                'margin' => 'sm',
            ];
        }

        return [
            'type' => 'box',
            'layout' => 'vertical',
            'contents' => $buttons,
        ];
    }

    /**
     * Build order status notification in timeline-like Flex card style.
     */
    public static function odooStatusUpdate($eventCode, array $data = [], $message = '', $forSalesperson = false, array $timelineEvents = [])
    {
        $eventLabels = [
            'order.validated' => ['icon' => '✅', 'label' => 'ยืนยันออเดอร์'],
            'order.picker_assigned' => ['icon' => '👤', 'label' => 'เตรียมจัดสินค้า'],
            'order.picking' => ['icon' => '📦', 'label' => 'กำลังจัดสินค้า'],
            'order.picked' => ['icon' => '✅', 'label' => 'จัดเสร็จแล้ว'],
            'order.packing' => ['icon' => '📦', 'label' => 'กำลังแพ็ค'],
            'order.packed' => ['icon' => '✅', 'label' => 'แพ็คเสร็จ'],
            'order.awaiting_payment' => ['icon' => '💰', 'label' => 'รอชำระเงิน'],
            'order.paid' => ['icon' => '💳', 'label' => 'ชำระเงินแล้ว'],
            'order.to_delivery' => ['icon' => '🚚', 'label' => 'เตรียมส่ง'],
            'order.in_delivery' => ['icon' => '🚚', 'label' => 'กำลังจัดส่ง'],
            'order.delivered' => ['icon' => '✅', 'label' => 'จัดส่งสำเร็จ'],
            'invoice.created' => ['icon' => '📄', 'label' => 'ออกใบแจ้งหนี้'],
            'invoice.overdue' => ['icon' => '⚠️', 'label' => 'ใบแจ้งหนี้เกินกำหนด'],
            'invoice.paid'    => ['icon' => '📌', 'label' => 'รับชำระเงินแล้ว'],
        ];

        $eventMeta = $eventLabels[$eventCode] ?? ['icon' => '📌', 'label' => $eventCode];
        $orderRef = $data['order_ref'] ?? $data['order_name'] ?? 'SO-UNKNOWN';
        $customerName = $data['customer']['name'] ?? ($data['customer_name'] ?? '-');
        $amount = isset($data['amount_total']) ? number_format((float) $data['amount_total'], 2) : null;
        $timestamp = $data['event_time'] ?? date('d/m/Y H:i:s');

        $headerTitle = $forSalesperson ? 'Timeline (Sales)' : 'Timeline';

        if (empty($timelineEvents)) {
            $timelineEvents = [[
                'event_code' => $eventCode,
                'status' => 'success',
                'timestamp' => $timestamp,
            ]];
        }

        $timelineMap = [
            'order.validated' => ['icon' => '✅', 'label' => 'ยืนยันออเดอร์'],
            'order.picker_assigned' => ['icon' => '👤', 'label' => 'เตรียมจัดสินค้า'],
            'order.picking' => ['icon' => '📦', 'label' => 'กำลังจัดสินค้า'],
            'order.picked' => ['icon' => '✅', 'label' => 'จัดเสร็จแล้ว'],
            'order.packing' => ['icon' => '📦', 'label' => 'กำลังแพ็ค'],
            'order.packed' => ['icon' => '✅', 'label' => 'แพ็คเสร็จ'],
            'order.awaiting_payment' => ['icon' => '💰', 'label' => 'รอชำระเงิน'],
            'order.paid' => ['icon' => '💳', 'label' => 'ชำระเงินแล้ว'],
            'order.to_delivery' => ['icon' => '🚚', 'label' => 'เตรียมส่ง'],
            'order.in_delivery' => ['icon' => '🚚', 'label' => 'กำลังจัดส่ง'],
            'order.delivered' => ['icon' => '✅', 'label' => 'จัดส่งสำเร็จ'],
            'invoice.created' => ['icon' => '📄', 'label' => 'ออกใบแจ้งหนี้'],
            'invoice.overdue' => ['icon' => '⚠️', 'label' => 'เกินกำหนด'],
            'invoice.paid'    => ['icon' => '📌', 'label' => 'รับชำระเงินแล้ว'],
        ];

        $timelineItems = [];
        foreach (array_slice($timelineEvents, -8) as $item) {
            $code = (string) ($item['event_code'] ?? 'unknown');
            $mapMeta = $timelineMap[$code] ?? ['icon' => '📌', 'label' => $code];
            $meta = [
                'icon'  => $item['icon']  ?? $mapMeta['icon'],
                'label' => $item['label'] ?? $mapMeta['label'],
            ];
            $timeText = (string) ($item['timestamp'] ?? '-');
            $statusText = (string) ($item['status'] ?? 'success');

            $timelineItems[] = [
                'type' => 'box',
                'layout' => 'vertical',
                'spacing' => 'xs',
                'contents' => [
                    [
                        'type' => 'text',
                        'text' => $meta['icon'] . ' ' . $meta['label'],
                        'size' => 'sm',
                        'weight' => 'bold',
                        'color' => '#0F172A',
                        'wrap' => true,
                    ],
                    [
                        'type' => 'text',
                        'text' => $timeText,
                        'size' => 'xs',
                        'color' => '#64748B',
                    ],
                    [
                        'type' => 'text',
                        'text' => $code . ' · ' . $statusText,
                        'size' => 'xs',
                        'color' => '#94A3B8',
                        'wrap' => true,
                    ]
                ]
            ];
        }

        $timelineBody = [];
        foreach ($timelineItems as $idx => $node) {
            $timelineBody[] = $node;
            if ($idx < count($timelineItems) - 1) {
                $timelineBody[] = [
                    'type' => 'separator',
                    'margin' => 'sm'
                ];
            }
        }

        return [
            'type' => 'bubble',
            'size' => 'mega',
            'header' => [
                'type' => 'box',
                'layout' => 'vertical',
                'contents' => [
                    [
                        'type' => 'text',
                        'text' => "{$headerTitle}: {$orderRef}",
                        'weight' => 'bold',
                        'size' => 'lg',
                        'color' => '#0F172A'
                    ]
                ]
            ],
            'body' => [
                'type' => 'box',
                'layout' => 'vertical',
                'spacing' => 'md',
                'contents' => [
                    [
                        'type' => 'box',
                        'layout' => 'baseline',
                        'contents' => [
                            ['type' => 'text', 'text' => $eventMeta['icon'], 'size' => 'sm', 'flex' => 0],
                            ['type' => 'text', 'text' => $eventMeta['label'], 'size' => 'md', 'weight' => 'bold', 'color' => '#111827', 'margin' => 'sm']
                        ]
                    ],
                    [
                        'type' => 'text',
                        'text' => $timestamp,
                        'size' => 'xs',
                        'color' => '#64748B'
                    ],
                    [
                        'type' => 'text',
                        'text' => $eventCode,
                        'size' => 'xs',
                        'color' => '#94A3B8'
                    ],
                    [
                        'type' => 'separator',
                        'margin' => 'sm'
                    ],
                    [
                        'type' => 'box',
                        'layout' => 'vertical',
                        'spacing' => 'sm',
                        'contents' => array_values(array_filter([
                            [
                                'type' => 'box',
                                'layout' => 'baseline',
                                'contents' => [
                                    ['type' => 'text', 'text' => 'ลูกค้า', 'size' => 'sm', 'color' => '#64748B', 'flex' => 2],
                                    ['type' => 'text', 'text' => $customerName, 'size' => 'sm', 'color' => '#0F172A', 'align' => 'end', 'flex' => 3]
                                ]
                            ],
                            $amount !== null ? [
                                'type' => 'box',
                                'layout' => 'baseline',
                                'contents' => [
                                    ['type' => 'text', 'text' => 'ยอดเงิน', 'size' => 'sm', 'color' => '#64748B', 'flex' => 2],
                                    ['type' => 'text', 'text' => '฿' . $amount, 'size' => 'sm', 'color' => '#0EA5E9', 'weight' => 'bold', 'align' => 'end', 'flex' => 3]
                                ]
                            ] : null,
                        ]))
                    ],
                    [
                        'type' => 'box',
                        'layout' => 'vertical',
                        'margin' => 'sm',
                        'paddingAll' => '10px',
                        'backgroundColor' => '#F8FAFC',
                        'cornerRadius' => '8px',
                        'contents' => [
                            [
                                'type' => 'text',
                                'text' => $message !== '' ? $message : ($eventMeta['label'] . ' (' . $eventCode . ')'),
                                'size' => 'sm',
                                'color' => '#334155',
                                'wrap' => true
                            ]
                        ]
                    ],
                    [
                        'type' => 'separator',
                        'margin' => 'md'
                    ],
                    [
                        'type' => 'box',
                        'layout' => 'vertical',
                        'spacing' => 'sm',
                        'contents' => array_merge(
                            [[
                                'type' => 'text',
                                'text' => 'ประวัติสถานะล่าสุด',
                                'size' => 'sm',
                                'weight' => 'bold',
                                'color' => '#334155'
                            ]],
                            $timelineBody
                        )
                    ]
                ]
            ]
        ];
    }

    /**
     * Build Daily Summary Flex Message
     */
    public static function dailySummary(array $data)
    {
        $displayName = $data['display_name'] ?? 'คุณลูกค้า';
        $orders = $data['orders'] ?? [];
        $orderCount = count($orders);
        
        $orderBoxes = [];
        
        // Define timeline icons matching the user's request format
        $timelineIcons = [
            'order.validated' => '📌',
            'order.picker_assigned' => '📌',
            'order.picking' => '📌',
            'order.picked' => '📌',
            'order.packing' => '📌',
            'order.packed' => '📌',
            'order.awaiting_payment' => '📌',
            'order.paid' => '📌',
            'order.to_delivery' => '📌',
            'order.in_delivery' => '📌',
            'order.delivered' => '📌',
        ];
        
        foreach ($orders as $order) {
            $badgeColor = '#4b5563';
            if ($order['status'] === 'success') {
                $badgeColor = '#16a34a';
            } elseif ($order['status'] === 'failed') {
                $badgeColor = '#dc2626';
            }
            
            // Build timeline items for this order
            $timelineItems = [];
            if (!empty($order['timeline'])) {
                foreach ($order['timeline'] as $event) {
                    $icon = $timelineIcons[$event['type']] ?? '📌';
                    $label = $event['label'] ?? $event['type'];
                    $timeStr = $event['time'] ? date('d/m/Y H:i:s', strtotime($event['time'])) : '-';
                    // Convert year to Buddhist Era (BE)
                    if ($event['time']) {
                        $timeStr = date('d/m/', strtotime($event['time'])) . (date('Y', strtotime($event['time'])) + 543) . ' ' . date('H:i:s', strtotime($event['time']));
                    }
                    
                    $timelineItems[] = [
                        'type' => 'box',
                        'layout' => 'vertical',
                        'spacing' => 'none',
                        'margin' => 'md',
                        'contents' => [
                            [
                                'type' => 'text',
                                'text' => $icon . ' ' . $label,
                                'size' => 'sm',
                                'weight' => 'bold',
                                'color' => '#111827'
                            ],
                            [
                                'type' => 'text',
                                'text' => $timeStr,
                                'size' => 'xs',
                                'color' => '#64748B'
                            ]
                        ]
                    ];
                }
            }
            
            $orderContents = [
                [
                    'type' => 'text',
                    'text' => 'Timeline: ' . ($order['order_ref'] ?? 'ออเดอร์'),
                    'weight' => 'bold',
                    'size' => 'sm',
                    'color' => '#0F172A',
                    'decoration' => 'underline'
                ]
            ];
            
            if (!empty($timelineItems)) {
                $orderContents = array_merge($orderContents, $timelineItems);
            } else {
                // Fallback if no timeline data
                $orderContents[] = [
                    'type' => 'box',
                    'layout' => 'baseline',
                    'spacing' => 'sm',
                    'margin' => 'sm',
                    'contents' => [
                        [
                            'type' => 'text',
                            'text' => '📌',
                            'flex' => 0,
                            'size' => 'sm'
                        ],
                        [
                            'type' => 'text',
                            'text' => $order['event_label'] ?? $order['event_type'],
                            'weight' => 'bold',
                            'size' => 'sm',
                            'color' => $badgeColor
                        ]
                    ]
                ];
                $timeStr = $order['last_update'] ? date('d/m/Y H:i:s', strtotime($order['last_update'])) : '-';
                if ($order['last_update']) {
                    $timeStr = date('d/m/', strtotime($order['last_update'])) . (date('Y', strtotime($order['last_update'])) + 543) . ' ' . date('H:i:s', strtotime($order['last_update']));
                }
                $orderContents[] = [
                    'type' => 'text',
                    'text' => $timeStr,
                    'size' => 'xs',
                    'color' => '#64748B',
                    'margin' => 'xs'
                ];
            }
            
            $orderBoxes[] = [
                'type' => 'box',
                'layout' => 'vertical',
                'margin' => 'lg',
                'spacing' => 'sm',
                'contents' => $orderContents
            ];
            
            $orderBoxes[] = [
                'type' => 'separator',
                'margin' => 'lg'
            ];
        }
        
        // Remove the last separator
        if (!empty($orderBoxes)) {
            array_pop($orderBoxes);
        }

        return [
            'type' => 'bubble',
            'size' => 'mega',
            'header' => [
                'type' => 'box',
                'layout' => 'vertical',
                'backgroundColor' => '#f0f9ff',
                'contents' => [
                    [
                        'type' => 'text',
                        'text' => '📊 สรุปออเดอร์ประจำวัน',
                        'weight' => 'bold',
                        'size' => 'lg',
                        'color' => '#0369a1'
                    ],
                    [
                        'type' => 'text',
                        'text' => $displayName,
                        'size' => 'sm',
                        'color' => '#0ea5e9',
                        'margin' => 'sm'
                    ]
                ]
            ],
            'body' => [
                'type' => 'box',
                'layout' => 'vertical',
                'contents' => [
                    [
                        'type' => 'text',
                        'text' => "รายการออเดอร์ที่ดำเนินการวันนี้ ({$orderCount} รายการ)",
                        'size' => 'sm',
                        'color' => '#475569',
                        'weight' => 'bold',
                        'margin' => 'sm'
                    ],
                    [
                        'type' => 'separator',
                        'margin' => 'md'
                    ],
                    [
                        'type' => 'box',
                        'layout' => 'vertical',
                        'contents' => $orderBoxes,
                        'margin' => 'sm'
                    ]
                ]
            ],
            'footer' => [
                'type' => 'box',
                'layout' => 'vertical',
                'contents' => [
                    [
                        'type' => 'text',
                        'text' => 'หากมีข้อสงสัยสามารถติดต่อแอดมินได้เลยค่ะ',
                        'size' => 'xs',
                        'color' => '#94A3B8',
                        'align' => 'center',
                        'wrap' => true
                    ]
                ]
            ]
        ];
    }
}
