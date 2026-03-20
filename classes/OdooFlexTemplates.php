<?php
/**
 * Odoo Flex Templates Stub
 * Simplified version for testing
 */

class OdooFlexTemplates
{
    public static function bdoPaymentRequest($data, $qrCodeUrl)
    {
        $amount = number_format($data['amount_total'], 2);
        $bdoRef = $data['bdo_ref'] ?? 'BDO-XXX';
        $orderRef = $data['order_ref'] ?? 'SO-XXX';
        $dueDate = $data['due_date'] ?? date('Y-m-d');

        $bankName = $data['bank_account']['bank_name'] ?? 'ธนาคารกสิกรไทย';
        $accountNumber = $data['bank_account']['account_number'] ?? '123-4-56789-0';
        $accountName = $data['bank_account']['account_name'] ?? 'บริษัท ซีเอ็นวาย จำกัด';

        $invoiceUrl = $data['invoice']['pdf_url'] ?? '#';
        $liffUrl = $data['liff_url'] ?? '';

        return [
            'type' => 'bubble',
            'header' => [
                'type' => 'box',
                'layout' => 'vertical',
                'contents' => [
                    [
                        'type' => 'text',
                        'text' => '💰 แจ้งชำระเงิน',
                        'weight' => 'bold',
                        'size' => 'xl',
                        'color' => '#ffffff'
                    ]
                ],
                'backgroundColor' => '#06C755'
            ],
            'body' => [
                'type' => 'box',
                'layout' => 'vertical',
                'contents' => [
                    [
                        'type' => 'text',
                        'text' => "BDO: {$bdoRef}",
                        'size' => 'sm',
                        'color' => '#aaaaaa'
                    ],
                    [
                        'type' => 'text',
                        'text' => "ออเดอร์: {$orderRef}",
                        'size' => 'sm',
                        'color' => '#aaaaaa'
                    ],
                    [
                        'type' => 'separator',
                        'margin' => 'lg'
                    ],
                    [
                        'type' => 'box',
                        'layout' => 'vertical',
                        'contents' => [
                            [
                                'type' => 'text',
                                'text' => 'ยอดชำระ',
                                'size' => 'sm',
                                'color' => '#555555'
                            ],
                            [
                                'type' => 'text',
                                'text' => "฿{$amount}",
                                'size' => 'xxl',
                                'weight' => 'bold',
                                'color' => '#06C755'
                            ],
                            [
                                'type' => 'text',
                                'text' => "ครบกำหนด: {$dueDate}",
                                'size' => 'xs',
                                'color' => '#ff4444'
                            ]
                        ],
                        'margin' => 'lg'
                    ],
                    [
                        'type' => 'separator',
                        'margin' => 'lg'
                    ],
                    [
                        'type' => 'text',
                        'text' => 'สแกน QR Code',
                        'size' => 'sm',
                        'margin' => 'lg',
                        'align' => 'center'
                    ],
                    [
                        'type' => 'image',
                        'url' => $qrCodeUrl,
                        'size' => 'full',
                        'aspectMode' => 'cover',
                        'aspectRatio' => '1:1',
                        'margin' => 'md'
                    ],
                    [
                        'type' => 'separator',
                        'margin' => 'lg'
                    ],
                    [
                        'type' => 'text',
                        'text' => 'หรือโอนเงินเข้าบัญชี',
                        'size' => 'sm',
                        'margin' => 'lg'
                    ],
                    [
                        'type' => 'box',
                        'layout' => 'vertical',
                        'contents' => [
                            [
                                'type' => 'text',
                                'text' => $bankName,
                                'size' => 'sm',
                                'color' => '#555555'
                            ],
                            [
                                'type' => 'text',
                                'text' => $accountNumber,
                                'size' => 'sm',
                                'weight' => 'bold'
                            ],
                            [
                                'type' => 'text',
                                'text' => $accountName,
                                'size' => 'xs',
                                'color' => '#aaaaaa'
                            ]
                        ],
                        'margin' => 'md'
                    ]
                ]
            ],
            'footer' => self::buildFooterButtons($invoiceUrl, $liffUrl)
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
