<?php
/**
 * PharmacistNotifier - ระบบแจ้งเตือนเภสัชกรผ่าน LINE
 * Version 1.0
 * 
 * Features:
 * - แจ้งเตือนเมื่อมีคำขอปรึกษาใหม่
 * - แจ้งเตือนเร่งด่วนเมื่อพบ Red Flags
 * - ส่ง Flex Message สรุปอาการ
 */

namespace Modules\AIChat\Services;

// Auto-load Core Database
require_once __DIR__ . '/../../Core/Database.php';

use Modules\Core\Database;

class PharmacistNotifier
{
    private Database $db;
    private ?int $lineAccountId;
    
    public function __construct(?int $lineAccountId = null)
    {
        $this->db = Database::getInstance();
        $this->lineAccountId = $lineAccountId;
    }
    
    /**
     * แจ้งเตือนเภสัชกรทุกคน
     */
    public function notifyAllPharmacists(array $data, bool $urgent = false): bool
    {
        try {
            // ดึงรายชื่อเภสัชกร
            $pharmacists = $this->getPharmacists();
            
            if (empty($pharmacists)) {
                error_log("PharmacistNotifier: No pharmacists found");
                return false;
            }
            
            // สร้าง Flex Message
            $flexMessage = $this->buildNotificationFlex($data, $urgent);
            
            // ส่งแจ้งเตือนทุกคน
            foreach ($pharmacists as $pharmacist) {
                // ส่ง LINE
                $this->sendLINEPush($pharmacist['line_user_id'], $flexMessage);
                
                // ส่ง Email ถ้าเป็น urgent และมี email
                if ($urgent && !empty($pharmacist['email'])) {
                    $this->sendUrgentEmail($pharmacist['email'], $data);
                }
            }
            
            return true;
        } catch (\Exception $e) {
            error_log("PharmacistNotifier error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * แจ้งเตือนเภสัชกรที่ assign
     */
    public function notifyAssignedPharmacist(int $pharmacistId, array $data): bool
    {
        try {
            $pharmacist = $this->db->fetchOne(
                "SELECT line_user_id FROM admin_users WHERE id = ? AND role IN ('pharmacist', 'admin')",
                [$pharmacistId]
            );
            
            if (!$pharmacist || empty($pharmacist['line_user_id'])) {
                return false;
            }
            
            $flexMessage = $this->buildNotificationFlex($data, false);
            return $this->sendLINEPush($pharmacist['line_user_id'], $flexMessage);
        } catch (\Exception $e) {
            error_log("notifyAssignedPharmacist error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * ส่งข้อความกลับลูกค้า
     */
    public function sendToCustomer(int $userId, string $message, ?array $flexMessage = null): bool
    {
        try {
            // Get user with their line_account_id
            $user = $this->db->fetchOne(
                "SELECT line_user_id, line_account_id FROM users WHERE id = ?",
                [$userId]
            );
            
            if (!$user || empty($user['line_user_id'])) {
                error_log("sendToCustomer: User {$userId} has no line_user_id");
                return false;
            }
            
            // Get token from user's LINE account (not the default one)
            $token = $this->getChannelAccessTokenForUser($user['line_account_id']);
            if (!$token) {
                error_log("sendToCustomer: No token for user's LINE account");
                return false;
            }
            
            if ($flexMessage) {
                return $this->sendLINEPushWithToken($user['line_user_id'], $flexMessage, $token);
            }
            
            return $this->sendLINEPushWithToken($user['line_user_id'], [
                'type' => 'text',
                'text' => $message,
                'sender' => [
                    'name' => '💊 เภสัชกร',
                    'iconUrl' => 'https://cdn-icons-png.flaticon.com/512/3774/3774299.png'
                ]
            ], $token);
        } catch (\Exception $e) {
            error_log("sendToCustomer error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * ส่ง Flex สรุปยาที่อนุมัติ
     */
    public function sendApprovalToCustomer(int $userId, array $triageData, array $approvedDrugs, ?string $pharmacistName = null, ?string $pharmacistLicense = null, ?string $note = null): bool
    {
        $flex = $this->buildApprovalFlex($triageData, $approvedDrugs, $pharmacistName, $pharmacistLicense, $note);
        return $this->sendToCustomer($userId, '', $flex);
    }
    
    /**
     * Get token for specific LINE account
     */
    private function getChannelAccessTokenForUser(?int $lineAccountId): ?string
    {
        try {
            if ($lineAccountId) {
                $result = $this->db->fetchOne(
                    "SELECT channel_access_token FROM line_accounts WHERE id = ?",
                    [$lineAccountId]
                );
                if ($result && !empty($result['channel_access_token'])) {
                    return $result['channel_access_token'];
                }
            }
            
            // Fallback to default
            return $this->getChannelAccessToken();
        } catch (\Exception $e) {
            error_log("getChannelAccessTokenForUser error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Send LINE push with specific token
     */
    private function sendLINEPushWithToken(string $lineUserId, array $message, string $token): bool
    {
        try {
            $data = [
                'to' => $lineUserId,
                'messages' => [$message]
            ];
            
            $ch = curl_init('https://api.line.me/v2/bot/message/push');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $token
                ],
                CURLOPT_POSTFIELDS => json_encode($data)
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode !== 200) {
                error_log("LINE Push failed (HTTP {$httpCode}): " . $response);
                return false;
            }
            
            return true;
        } catch (\Exception $e) {
            error_log("sendLINEPushWithToken error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * ดึงรายชื่อเภสัชกร - ใช้ notify_admin_users จาก notification_settings ถ้ามี
     */
    private function getPharmacists(): array
    {
        try {
            // ลองดึงจาก notification_settings ก่อน
            $accountId = (int)($this->lineAccountId ?: 0);
            $settings = $this->db->fetchOne(
                "SELECT notify_admin_users FROM notification_settings WHERE line_account_id = ?",
                [$accountId]
            );
            
            $adminIds = [];
            if ($settings && !empty($settings['notify_admin_users'])) {
                $adminIds = array_filter(array_map('intval', explode(',', $settings['notify_admin_users'])));
            }
            
            // ถ้ามี notify_admin_users ให้ใช้เฉพาะคนที่ตั้งค่าไว้
            if (!empty($adminIds)) {
                $placeholders = implode(',', array_fill(0, count($adminIds), '?'));
                return $this->db->fetchAll(
                    "SELECT id, username, line_user_id, email, role FROM admin_users 
                     WHERE id IN ({$placeholders}) AND is_active = 1 
                     AND (line_user_id IS NOT NULL AND line_user_id != '')",
                    $adminIds
                );
            }
            
            // Fallback: ดึงเภสัชกรและ admin ทั้งหมด
            $sql = "SELECT au.id, au.username, au.line_user_id, au.email, au.role
                    FROM admin_users au
                    WHERE au.role IN ('pharmacist', 'admin')
                    AND au.is_active = 1
                    AND (au.line_user_id IS NOT NULL AND au.line_user_id != '')";
            
            if ($this->lineAccountId) {
                $sql .= " AND (au.line_account_id = ? OR au.line_account_id IS NULL)";
                return $this->db->fetchAll($sql, [$this->lineAccountId]);
            }
            
            return $this->db->fetchAll($sql);
        } catch (\Exception $e) {
            error_log("getPharmacists error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * ส่ง Email แจ้งเตือนฉุกเฉิน
     */
    private function sendUrgentEmail(string $email, array $data): bool
    {
        try {
            $symptoms = implode(', ', $data['symptoms'] ?? ['ไม่ระบุ']);
            $severity = $data['severity'] ?? '-';
            $userName = $data['user_name'] ?? 'ลูกค้า';
            $redFlags = $data['red_flags'] ?? [];
            
            $subject = "🚨 [ด่วน] แจ้งเตือนผู้ป่วยฉุกเฉิน - {$userName}";
            
            $body = "
<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <style>
        body { font-family: 'Sarabun', Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #DC2626; color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
        .content { background: #fff; padding: 20px; border: 1px solid #ddd; }
        .alert-box { background: #FEF2F2; border-left: 4px solid #DC2626; padding: 15px; margin: 15px 0; }
        .info-row { display: flex; padding: 8px 0; border-bottom: 1px solid #eee; }
        .label { font-weight: bold; width: 120px; color: #666; }
        .value { flex: 1; }
        .footer { background: #f5f5f5; padding: 15px; text-align: center; font-size: 12px; color: #666; }
        .btn { display: inline-block; background: #DC2626; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; margin-top: 15px; }
    </style>
</head>
<body>
    <div class='container'>
        <div class='header'>
            <h1>🚨 แจ้งเตือนฉุกเฉิน</h1>
        </div>
        <div class='content'>
            <h2>ข้อมูลผู้ป่วย</h2>
            <div class='info-row'><span class='label'>ชื่อ:</span><span class='value'>{$userName}</span></div>
            <div class='info-row'><span class='label'>อาการ:</span><span class='value'>{$symptoms}</span></div>
            <div class='info-row'><span class='label'>ความรุนแรง:</span><span class='value'>{$severity}/10</span></div>
            ";
            
            if (!empty($redFlags)) {
                $body .= "<div class='alert-box'><strong>⚠️ Red Flags ที่พบ:</strong><ul>";
                foreach ($redFlags as $flag) {
                    $flagMsg = is_array($flag) ? ($flag['message'] ?? '') : $flag;
                    $body .= "<li>{$flagMsg}</li>";
                }
                $body .= "</ul></div>";
            }
            
            $dashboardUrl = $this->getDashboardUrl();
            $body .= "
            <p style='text-align: center;'>
                <a href='{$dashboardUrl}' class='btn'>📋 ดูรายละเอียดใน Dashboard</a>
            </p>
        </div>
        <div class='footer'>
            <p>ข้อความนี้ส่งอัตโนมัติจากระบบ Pharmacy AI</p>
            <p>กรุณาตรวจสอบและดำเนินการโดยเร็ว</p>
        </div>
    </div>
</body>
</html>";
            
            // ส่ง Email
            $headers = [
                'MIME-Version: 1.0',
                'Content-type: text/html; charset=UTF-8',
                'From: Pharmacy Alert <noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '>',
                'X-Priority: 1 (Highest)',
                'X-MSMail-Priority: High',
                'Importance: High'
            ];
            
            $result = mail($email, $subject, $body, implode("\r\n", $headers));
            
            if ($result) {
                error_log("Urgent email sent to: {$email}");
            } else {
                error_log("Failed to send urgent email to: {$email}");
            }
            
            return $result;
        } catch (\Exception $e) {
            error_log("sendUrgentEmail error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * สร้าง Flex Message แจ้งเตือน
     */
    private function buildNotificationFlex(array $data, bool $urgent): array
    {
        $symptoms = implode(', ', $data['symptoms'] ?? ['ไม่ระบุ']);
        $severity = $data['severity'] ?? '-';
        $duration = $data['duration'] ?? '-';
        $userName = $data['user_name'] ?? 'ลูกค้า';
        
        $headerColor = $urgent ? '#DC2626' : '#059669';
        $headerText = $urgent ? '🚨 แจ้งเตือนด่วน!' : '📋 คำขอปรึกษาใหม่';
        
        $contents = [
            'type' => 'bubble',
            'size' => 'mega',
            'header' => [
                'type' => 'box',
                'layout' => 'vertical',
                'contents' => [
                    [
                        'type' => 'text',
                        'text' => $headerText,
                        'color' => '#FFFFFF',
                        'weight' => 'bold',
                        'size' => 'lg'
                    ]
                ],
                'backgroundColor' => $headerColor,
                'paddingAll' => '15px'
            ],
            'body' => [
                'type' => 'box',
                'layout' => 'vertical',
                'contents' => [
                    [
                        'type' => 'text',
                        'text' => $userName,
                        'weight' => 'bold',
                        'size' => 'xl',
                        'margin' => 'none'
                    ],
                    [
                        'type' => 'separator',
                        'margin' => 'lg'
                    ],
                    [
                        'type' => 'box',
                        'layout' => 'vertical',
                        'margin' => 'lg',
                        'contents' => [
                            $this->createInfoRow('🩺 อาการ', $symptoms),
                            $this->createInfoRow('⏱️ ระยะเวลา', $duration),
                            $this->createInfoRow('📊 ความรุนแรง', "{$severity}/10"),
                        ]
                    ]
                ],
                'paddingAll' => '20px'
            ],
            'footer' => [
                'type' => 'box',
                'layout' => 'horizontal',
                'contents' => [
                    [
                        'type' => 'button',
                        'action' => [
                            'type' => 'uri',
                            'label' => '📋 ดูรายละเอียด',
                            'uri' => $this->getDashboardUrl()
                        ],
                        'style' => 'primary',
                        'color' => '#059669'
                    ]
                ],
                'paddingAll' => '15px'
            ]
        ];
        
        // เพิ่ม Red Flags ถ้ามี
        if ($urgent && !empty($data['red_flags'])) {
            $redFlagText = '';
            foreach ($data['red_flags'] as $flag) {
                $redFlagText .= "⚠️ " . ($flag['message'] ?? $flag) . "\n";
            }
            
            $contents['body']['contents'][] = [
                'type' => 'box',
                'layout' => 'vertical',
                'margin' => 'lg',
                'backgroundColor' => '#FEF2F2',
                'cornerRadius' => '8px',
                'paddingAll' => '12px',
                'contents' => [
                    [
                        'type' => 'text',
                        'text' => trim($redFlagText),
                        'color' => '#DC2626',
                        'size' => 'sm',
                        'wrap' => true
                    ]
                ]
            ];
        }
        
        return [
            'type' => 'flex',
            'altText' => $headerText,
            'contents' => $contents
        ];
    }
    
    /**
     * สร้าง Flex Message อนุมัติยา - รูปแบบทางการเหมือนซองยา
     */
    private function buildApprovalFlex(array $triageData, array $approvedDrugs, ?string $pharmacistName = null, ?string $pharmacistLicense = null, ?string $note = null): array
    {
        $drugContents = [];
        $nonDrugContents = [];
        $total = 0;
        
        foreach ($approvedDrugs as $drug) {
            $price = (float)($drug['price'] ?? 0);
            $quantity = (int)($drug['quantity'] ?? 1);
            $itemTotal = $price * $quantity;
            $total += $itemTotal;
            $isNonDrug = !empty($drug['isNonDrug']);
            
            if ($isNonDrug) {
                // Non-drug item
                $nonDrugContents[] = [
                    'type' => 'box',
                    'layout' => 'horizontal',
                    'margin' => 'md',
                    'contents' => [
                        [
                            'type' => 'text',
                            'text' => ($drug['name'] ?? 'สินค้า') . ($quantity > 1 ? " x{$quantity}" : ''),
                            'size' => 'sm',
                            'flex' => 3,
                            'wrap' => true
                        ],
                        [
                            'type' => 'text',
                            'text' => number_format($itemTotal, 2),
                            'size' => 'sm',
                            'align' => 'end',
                            'flex' => 1
                        ]
                    ]
                ];
                
                // Non-drug details
                $details = [];
                if (!empty($drug['indication'])) {
                    $details[] = $drug['indication'];
                }
                if (!empty($drug['warning'])) {
                    $details[] = "หมายเหตุ: {$drug['warning']}";
                }
                
                if (!empty($details)) {
                    $nonDrugContents[] = [
                        'type' => 'box',
                        'layout' => 'vertical',
                        'margin' => 'sm',
                        'paddingStart' => '12px',
                        'contents' => array_map(function($detail) {
                            return [
                                'type' => 'text',
                                'text' => $detail,
                                'size' => 'xs',
                                'color' => '#666666',
                                'wrap' => true
                            ];
                        }, $details)
                    ];
                }
            } else {
                // Drug item - formal style like medicine label
                $drugName = $drug['name'] ?? 'ยา';
                $genericName = $drug['genericName'] ?? '';
                
                $drugContents[] = [
                    'type' => 'box',
                    'layout' => 'horizontal',
                    'margin' => 'md',
                    'contents' => [
                        [
                            'type' => 'box',
                            'layout' => 'vertical',
                            'flex' => 3,
                            'contents' => [
                                [
                                    'type' => 'text',
                                    'text' => $drugName . ($quantity > 1 ? " x{$quantity}" : ''),
                                    'size' => 'sm',
                                    'weight' => 'bold',
                                    'wrap' => true
                                ],
                                $genericName ? [
                                    'type' => 'text',
                                    'text' => $genericName,
                                    'size' => 'xs',
                                    'color' => '#0891b2',
                                    'wrap' => true
                                ] : ['type' => 'filler']
                            ]
                        ],
                        [
                            'type' => 'text',
                            'text' => number_format($itemTotal, 2),
                            'size' => 'sm',
                            'align' => 'end',
                            'flex' => 1
                        ]
                    ]
                ];
                
                // Drug details - formal style
                $details = [];
                
                if (!empty($drug['indication'])) {
                    $details[] = ['label' => 'ข้อบ่งใช้', 'value' => $drug['indication']];
                }
                
                $dosageInfo = '';
                if (!empty($drug['dosage'])) {
                    $unit = $drug['unit'] ?? 'เม็ด';
                    $dosageInfo = "รับประทานครั้งละ {$drug['dosage']} {$unit}";
                }
                if (!empty($drug['timing'])) {
                    $dosageInfo .= " ({$drug['timing']})";
                }
                if ($dosageInfo) {
                    $details[] = ['label' => 'วิธีใช้', 'value' => $dosageInfo];
                }
                
                if (!empty($drug['instructions'])) {
                    $details[] = ['label' => 'คำแนะนำ', 'value' => $drug['instructions']];
                }
                
                if (!empty($drug['warning'])) {
                    $details[] = ['label' => 'คำเตือน', 'value' => $drug['warning']];
                }
                
                if (!empty($details)) {
                    $drugContents[] = [
                        'type' => 'box',
                        'layout' => 'vertical',
                        'margin' => 'sm',
                        'paddingStart' => '12px',
                        'backgroundColor' => '#f8fafc',
                        'cornerRadius' => '4px',
                        'paddingAll' => '8px',
                        'contents' => array_map(function($detail) {
                            return [
                                'type' => 'text',
                                'text' => $detail['label'] . ': ' . $detail['value'],
                                'size' => 'xs',
                                'color' => '#374151',
                                'wrap' => true
                            ];
                        }, $details)
                    ];
                }
            }
        }
        
        // Build body contents
        $bodyContents = [];
        
        // Drug section
        if (!empty($drugContents)) {
            $bodyContents[] = [
                'type' => 'text',
                'text' => 'รายการยา',
                'weight' => 'bold',
                'size' => 'md',
                'color' => '#059669'
            ];
            $bodyContents[] = [
                'type' => 'separator',
                'margin' => 'sm'
            ];
            $bodyContents = array_merge($bodyContents, $drugContents);
        }
        
        // Non-drug section
        if (!empty($nonDrugContents)) {
            if (!empty($drugContents)) {
                $bodyContents[] = [
                    'type' => 'separator',
                    'margin' => 'lg'
                ];
            }
            $bodyContents[] = [
                'type' => 'text',
                'text' => 'สินค้าอื่นๆ (ไม่ใช่ยา)',
                'weight' => 'bold',
                'size' => 'md',
                'color' => '#d97706',
                'margin' => 'md'
            ];
            $bodyContents[] = [
                'type' => 'separator',
                'margin' => 'sm'
            ];
            $bodyContents = array_merge($bodyContents, $nonDrugContents);
        }
        
        // Total section
        $bodyContents[] = [
            'type' => 'separator',
            'margin' => 'lg'
        ];
        $bodyContents[] = [
            'type' => 'box',
            'layout' => 'horizontal',
            'margin' => 'md',
            'contents' => [
                [
                    'type' => 'text',
                    'text' => 'รวม',
                    'weight' => 'bold',
                    'flex' => 3
                ],
                [
                    'type' => 'text',
                    'text' => number_format($total, 2),
                    'weight' => 'bold',
                    'color' => '#059669',
                    'align' => 'end',
                    'flex' => 1
                ]
            ]
        ];
        
        // Pharmacist info section
        if ($pharmacistName || $pharmacistLicense) {
            $bodyContents[] = [
                'type' => 'separator',
                'margin' => 'lg'
            ];
            $bodyContents[] = [
                'type' => 'box',
                'layout' => 'vertical',
                'margin' => 'md',
                'contents' => [
                    [
                        'type' => 'text',
                        'text' => 'ออกโดย: ' . ($pharmacistName ?: '-'),
                        'size' => 'xs',
                        'color' => '#666666'
                    ],
                    [
                        'type' => 'text',
                        'text' => 'ใบอนุญาตเลขที่: ' . ($pharmacistLicense ?: '-'),
                        'size' => 'xs',
                        'color' => '#666666'
                    ]
                ]
            ];
        }
        
        // Note section
        if ($note) {
            $bodyContents[] = [
                'type' => 'box',
                'layout' => 'vertical',
                'margin' => 'md',
                'backgroundColor' => '#FEF3C7',
                'cornerRadius' => '4px',
                'paddingAll' => '10px',
                'contents' => [
                    [
                        'type' => 'text',
                        'text' => 'หมายเหตุ: ' . $note,
                        'size' => 'xs',
                        'color' => '#92400E',
                        'wrap' => true
                    ]
                ]
            ];
        }
        
        return [
            'type' => 'flex',
            'altText' => 'เภสัชกรอนุมัติยาแล้ว',
            'contents' => [
                'type' => 'bubble',
                'size' => 'mega',
                'header' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'contents' => [
                        [
                            'type' => 'text',
                            'text' => 'เภสัชกรอนุมัติยาแล้ว',
                            'color' => '#FFFFFF',
                            'weight' => 'bold',
                            'size' => 'lg'
                        ]
                    ],
                    'backgroundColor' => '#059669',
                    'paddingAll' => '15px'
                ],
                'body' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'contents' => $bodyContents,
                    'paddingAll' => '20px'
                ],
                'footer' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'contents' => [
                        [
                            'type' => 'button',
                            'action' => [
                                'type' => 'uri',
                                'label' => 'ดูรายการและชำระเงิน',
                                'uri' => $this->getCheckoutUrl() . '#/cart'
                            ],
                            'style' => 'primary',
                            'color' => '#059669'
                        ],
                        [
                            'type' => 'button',
                            'action' => [
                                'type' => 'message',
                                'label' => 'สอบถามเพิ่มเติม',
                                'text' => 'สอบถามเพิ่มเติม'
                            ],
                            'style' => 'secondary',
                            'margin' => 'sm'
                        ]
                    ],
                    'paddingAll' => '15px'
                ]
            ]
        ];
    }
    
    /**
     * Helper: สร้าง info row
     */
    private function createInfoRow(string $label, string $value): array
    {
        return [
            'type' => 'box',
            'layout' => 'horizontal',
            'margin' => 'md',
            'contents' => [
                [
                    'type' => 'text',
                    'text' => $label,
                    'size' => 'sm',
                    'color' => '#6B7280',
                    'flex' => 2
                ],
                [
                    'type' => 'text',
                    'text' => $value,
                    'size' => 'sm',
                    'color' => '#111827',
                    'flex' => 3,
                    'wrap' => true
                ]
            ]
        ];
    }
    
    /**
     * ส่ง LINE Push Message
     */
    private function sendLINEPush(string $lineUserId, array $message): bool
    {
        try {
            // ดึง Channel Access Token
            $token = $this->getChannelAccessToken();
            if (!$token) {
                error_log("PharmacistNotifier: No channel access token");
                return false;
            }
            
            $data = [
                'to' => $lineUserId,
                'messages' => [$message]
            ];
            
            $ch = curl_init('https://api.line.me/v2/bot/message/push');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $token
                ],
                CURLOPT_POSTFIELDS => json_encode($data)
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode !== 200) {
                error_log("LINE Push failed: " . $response);
                return false;
            }
            
            return true;
        } catch (\Exception $e) {
            error_log("sendLINEPush error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * ดึง Channel Access Token
     */
    private function getChannelAccessToken(): ?string
    {
        try {
            if ($this->lineAccountId) {
                $result = $this->db->fetchOne(
                    "SELECT channel_access_token FROM line_accounts WHERE id = ?",
                    [$this->lineAccountId]
                );
            } else {
                $result = $this->db->fetchOne(
                    "SELECT channel_access_token FROM line_accounts WHERE is_active = 1 LIMIT 1"
                );
            }
            
            return $result['channel_access_token'] ?? null;
        } catch (\Exception $e) {
            return null;
        }
    }
    
    private function getDashboardUrl(): string
    {
        return (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . 
               ($_SERVER['HTTP_HOST'] ?? 'localhost') . 
               '/pharmacist-dashboard.php';
    }
    
    private function getCheckoutUrl(): string
    {
        // Get LIFF ID from LINE account
        try {
            $liffId = null;
            if ($this->lineAccountId) {
                $result = $this->db->fetchOne(
                    "SELECT liff_id FROM line_accounts WHERE id = ?",
                    [$this->lineAccountId]
                );
                $liffId = $result['liff_id'] ?? null;
            }
            
            if (!$liffId) {
                // Fallback to first active account
                $result = $this->db->fetchOne(
                    "SELECT liff_id FROM line_accounts WHERE is_active = 1 AND liff_id IS NOT NULL LIMIT 1"
                );
                $liffId = $result['liff_id'] ?? null;
            }
            
            if ($liffId) {
                return "https://liff.line.me/{$liffId}";
            }
        } catch (\Exception $e) {
            error_log("getCheckoutUrl error: " . $e->getMessage());
        }
        
        // Fallback to direct URL
        return "https://clinicya.re-ya.com/liff/";
    }
}
