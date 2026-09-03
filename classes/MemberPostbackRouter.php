<?php

// BusinessBot เรียก router นี้ได้จาก shop/order-detail.php ซึ่งไม่ได้โหลด
// FlexTemplates ไว้ก่อนเหมือน webhook.php — โหลดเองกันพลาด
require_once __DIR__ . '/FlexTemplates.php';

/**
 * MemberPostbackRouter — ตารางส่งต่อ postback ฝั่งสมาชิก
 *
 * ทำไมต้องมี
 * ----------
 * webhook.php ยาว 5,600 บรรทัด และรับปุ่มกดด้วย if/strpos ต่อกันกลางไฟล์
 * ปุ่ม "✅ ทานแล้ว" กับ "⏰ เตือนอีกครั้ง" ถูกส่งออกจาก
 * cron/medication_reminder.php ตั้งแต่แรก แต่ไม่เคยมีใครรับ —
 * ลูกค้ากดแล้วเงียบ และข้อมูล adherence ที่มีค่าที่สุดของร้านยาหายไปทุกวัน
 *
 * คลาสนี้เป็นประตูเดียวของ "เลน reply" (ลูกค้าเป็นคนเริ่ม) ตรงข้ามกับ
 * NotificationGate ที่คุม "เลน push" (ร้านเป็นคนเริ่ม) — เลนนี้ใช้ reply token
 * ซึ่งฟรีและไม่มีโควตา จึงไม่ผ่าน Gate ไม่มีเพดาน ไม่มี quiet hours
 *
 * รูปแบบ postback ที่รับ
 * ---------------------
 *   action=medication_taken&reminder_id=12&time=08:00   (ของเดิมที่ cron ส่งอยู่)
 *   {"action":"member_card"}                            (JSON เผื่อของเดิมบางที่)
 *
 * ไม่รู้จัก action ไหน → คืน false ให้ webhook เดิมจัดการต่อ (send_receipt,
 * broadcast_click) จะได้ไม่ต้องแตะ if-chain เดิม
 */
class MemberPostbackRouter
{
    /** เลื่อนเตือนทานยากี่นาที */
    const SNOOZE_MINUTES = 30;

    /** คอลัมน์ที่ยอมให้สลับผ่านปุ่ม — กัน SQL injection ผ่านชื่อคอลัมน์ */
    const TOGGLEABLE_PREFS = [
        'drug_reminders',
        'appointment_reminders',
        'order_updates',
        'promotions',
        'restock_alerts',
        'price_alerts',
        'health_tips',
    ];

    /**
     * แยก postback.data ออกเป็น action + params
     *
     * @param string $data
     * @return array|null  ['action' => string, 'params' => array] หรือ null ถ้าอ่านไม่ออก
     */
    public static function parse($data)
    {
        $data = trim((string) $data);
        if ($data === '') {
            return null;
        }

        // รูปแบบ JSON — {"action":"...","id":1}
        if ($data[0] === '{') {
            $json = json_decode($data, true);
            if (!is_array($json) || empty($json['action']) || !is_string($json['action'])) {
                return null;
            }
            $action = $json['action'];
            unset($json['action']);
            return ['action' => $action, 'params' => $json];
        }

        // รูปแบบ query-string — action=x&k=v
        parse_str($data, $params);
        if (!is_array($params) || empty($params['action']) || !is_string($params['action'])) {
            return null;
        }
        $action = $params['action'];
        unset($params['action']);
        return ['action' => $action, 'params' => $params];
    }

    /**
     * จัดการ postback ถ้าเป็นของฝั่งสมาชิก
     *
     * @param PDO     $db
     * @param LineAPI $line
     * @param array   $ctx  ต้องมี: data, reply_token, user_id (users.id),
     *                      line_user_id, line_account_id
     * @return bool true = จัดการแล้ว (webhook หยุดได้), false = ไม่ใช่ของเรา
     */
    public static function handle($db, $line, array $ctx)
    {
        $parsed = self::parse($ctx['data'] ?? '');
        if ($parsed === null) {
            return false;
        }

        $userId = (int) ($ctx['user_id'] ?? 0);
        $replyToken = (string) ($ctx['reply_token'] ?? '');
        if ($userId <= 0 || $replyToken === '') {
            return false;
        }

        $accountId = isset($ctx['line_account_id']) ? (int) $ctx['line_account_id'] : null;
        $params = $parsed['params'];

        // วงหมุน "กำลังพิมพ์" ไม่ได้อยู่ตรงนี้ — webhook.php ขึ้นให้ตั้งแต่รับ event
        // แล้ว ครอบทุกเส้นทางตอบกลับ ไม่ใช่แค่ปุ่มของ router นี้

        try {
            switch ($parsed['action']) {
                case 'member_menu':
                    return self::showMemberMenu($db, $line, $replyToken, $userId, $accountId);

                case 'medication_taken':
                    return self::medicationTaken($db, $line, $replyToken, $userId, $accountId, $params);

                case 'medication_snooze':
                    return self::medicationSnooze($db, $line, $replyToken, $userId, $accountId, $params);

                case 'member_medications':
                    return self::showMedications($db, $line, $replyToken, $userId, $accountId);

                case 'member_med_stop':
                    return self::stopMedication($db, $line, $replyToken, $userId, $params);

                case 'member_med_refill':
                    return self::refillMedication($db, $line, $replyToken, $userId, $params);

                case 'member_points_history':
                    return self::showPointsHistory($db, $line, $replyToken, $userId, $accountId);

                case 'member_appointments':
                    return self::showAppointments($db, $line, $replyToken, $userId, $accountId);

                case 'member_appt_confirm':
                    return self::setAppointmentStatus($db, $line, $replyToken, $userId, $params, 'confirmed');

                case 'member_appt_cancel':
                    return self::setAppointmentStatus($db, $line, $replyToken, $userId, $params, 'cancelled');

                case 'member_notif_prefs':
                    return self::showNotificationPrefs($db, $line, $replyToken, $userId);

                case 'member_notif_toggle':
                    return self::toggleNotificationPref($db, $line, $replyToken, $userId, $accountId, $params);

                case 'member_card':
                case 'member_points':
                case 'member_rewards':
                    return self::delegateToBot($db, $line, $replyToken, $userId, $accountId, $ctx, $parsed['action']);
            }
        } catch (Throwable $e) {
            error_log('MemberPostbackRouter[' . $parsed['action'] . ']: ' . $e->getMessage());
            self::reply($line, $replyToken, FlexTemplates::textMessage(
                'ขออภัยค่ะ ระบบขัดข้องชั่วคราว ลองกดใหม่อีกครั้งนะคะ'
            ));
            return true;
        }

        return false;
    }

    // ------------------------------------------------------------------
    // เมนูรวมฝั่งสมาชิก — เห็นทั้งระบบได้ในแชท ไม่ต้องเข้า mini app
    // ------------------------------------------------------------------

    /**
     * "เมนูสมาชิก" → การ์ดเดียวที่สรุปสถานะจริงของลูกค้า + ทางเข้าทุกหน้า
     *
     * ตัวเลขบนการ์ดอ่านจากฐานจริงทั้งหมด ไม่ใช่ป้ายนิ่ง ๆ ลูกค้าจึงเห็นว่า
     * ระบบรู้จักเขาอยู่ก่อนจะกดเข้าไปหน้าไหน
     */
    private static function showMemberMenu($db, $line, $replyToken, $userId, $accountId)
    {
        $line->replyMessage($replyToken, [
            FlexTemplates::memberMenu(self::memberMenuStats($db, $userId)),
        ]);
        return true;
    }

    /**
     * ตัวเลขสรุปสำหรับเมนูรวม — ทุกช่องล้มแยกกันได้ ตารางไหนไม่มีก็ให้เป็น 0
     *
     * @return array{meds:int,doses_today:int,appointments:int,points:int}
     */
    private static function memberMenuStats($db, $userId)
    {
        $stats = ['meds' => 0, 'doses_today' => 0, 'appointments' => 0, 'points' => 0];

        $counts = [
            'meds' => ['SELECT COUNT(*) FROM medication_reminders WHERE user_id = ? AND is_active = 1', [$userId]],
            'doses_today' => [
                "SELECT COUNT(*) FROM medication_taken_history
                 WHERE user_id = ? AND status = 'taken' AND DATE(taken_at) = CURDATE()",
                [$userId],
            ],
            'appointments' => [
                "SELECT COUNT(*) FROM appointments
                 WHERE user_id = ? AND appointment_date >= CURDATE()
                   AND status IN ('pending', 'confirmed', 'in_progress')",
                [$userId],
            ],
            'points' => ['SELECT COALESCE(points, 0) FROM users WHERE id = ?', [$userId]],
        ];

        foreach ($counts as $key => [$sql, $args]) {
            try {
                $stmt = $db->prepare($sql);
                $stmt->execute($args);
                $stats[$key] = (int) $stmt->fetchColumn();
            } catch (Throwable $e) {
                // tenant นี้ยังไม่มีตารางนั้น — แสดง 0 ดีกว่าไม่แสดงเมนูเลย
                $stats[$key] = 0;
            }
        }

        return $stats;
    }

    // ------------------------------------------------------------------
    // เตือนทานยา — เส้นย้อนกลับที่ทำให้ได้ข้อมูลจริงแทนการเดา
    // ------------------------------------------------------------------

    /**
     * "✅ ทานแล้ว" → บันทึกลง medication_taken_history
     */
    private static function medicationTaken($db, $line, $replyToken, $userId, $accountId, array $params)
    {
        $reminderId = (int) ($params['reminder_id'] ?? 0);
        $slot = self::normalizeTime($params['time'] ?? null);

        $reminder = self::loadReminder($db, $reminderId, $userId);
        if (!$reminder) {
            self::reply($line, $replyToken, FlexTemplates::textMessage('ไม่พบรายการยานี้แล้วค่ะ'));
            return true;
        }

        if (self::alreadyLogged($db, $reminderId, $slot)) {
            self::reply($line, $replyToken, FlexTemplates::textMessage('บันทึกไว้แล้วค่ะ ✅'));
            return true;
        }

        self::logAdherence($db, $accountId, $reminder, $userId, $slot, 'taken', null);

        self::reply($line, $replyToken, FlexTemplates::textMessage(
            '✅ บันทึกแล้วค่ะ — ' . $reminder['medication_name'] . "\nดูแลตัวเองดีมากเลย 💚",
            null,
            [
                ['label' => '💊 ยาของฉัน', 'text' => 'ยาของฉัน'],
                ['label' => '🩺 เมนูสมาชิก', 'text' => 'เมนูสมาชิก'],
                ['label' => '💳 บัตรสมาชิก', 'text' => 'สมาชิก'],
            ]
        ));
        return true;
    }

    /**
     * "⏰ เตือนอีกครั้ง" → ตั้ง snooze_until ให้ cron รอบถัดไปมาเตือนซ้ำ
     */
    private static function medicationSnooze($db, $line, $replyToken, $userId, $accountId, array $params)
    {
        $reminderId = (int) ($params['reminder_id'] ?? 0);
        $slot = self::normalizeTime($params['time'] ?? null);

        $reminder = self::loadReminder($db, $reminderId, $userId);
        if (!$reminder) {
            self::reply($line, $replyToken, FlexTemplates::textMessage('ไม่พบรายการยานี้แล้วค่ะ'));
            return true;
        }

        $until = date('Y-m-d H:i:s', strtotime('+' . self::SNOOZE_MINUTES . ' minutes'));
        self::logAdherence($db, $accountId, $reminder, $userId, $slot, 'snoozed', $until);

        self::reply($line, $replyToken, FlexTemplates::textMessage(
            '⏰ ได้ค่ะ จะเตือนอีกครั้งตอน ' . date('H:i', strtotime($until)) . ' น.'
        ));
        return true;
    }

    /** เวลาในสล็อต HH:MM:SS — ถ้าไม่ส่งมาใช้เวลาปัจจุบัน */
    private static function normalizeTime($time)
    {
        $time = trim((string) $time);
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time)) {
            return $time . ':00';
        }
        return date('H:i:00');
    }

    /** อ่าน reminder โดยบังคับ scope ที่ user เจ้าของ */
    private static function loadReminder($db, $reminderId, $userId)
    {
        if ($reminderId <= 0) {
            return null;
        }
        $stmt = $db->prepare(
            'SELECT id, user_id, line_account_id, medication_name, product_id
             FROM medication_reminders WHERE id = ? AND user_id = ? LIMIT 1'
        );
        $stmt->execute([$reminderId, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** กันกดซ้ำในสล็อตเดียวกันของวันเดียวกัน (double-tap / LINE retry) */
    private static function alreadyLogged($db, $reminderId, $slot)
    {
        $stmt = $db->prepare(
            "SELECT 1 FROM medication_taken_history
             WHERE reminder_id = ? AND scheduled_time = ? AND DATE(taken_at) = CURDATE()
               AND status = 'taken' LIMIT 1"
        );
        $stmt->execute([$reminderId, $slot]);
        return (bool) $stmt->fetchColumn();
    }

    private static function logAdherence($db, $accountId, array $reminder, $userId, $slot, $status, $snoozeUntil)
    {
        $stmt = $db->prepare(
            'INSERT INTO medication_taken_history
                (line_account_id, reminder_id, user_id, scheduled_time, status, snooze_until)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $accountId ?: ($reminder['line_account_id'] ?: 1),
            (int) $reminder['id'],
            $userId,
            $slot,
            $status,
            $snoozeUntil,
        ]);
    }

    // ------------------------------------------------------------------
    // ยาของฉัน
    // ------------------------------------------------------------------

    private static function showMedications($db, $line, $replyToken, $userId, $accountId)
    {
        $stmt = $db->prepare(
            'SELECT id, medication_name, dosage, frequency, reminder_times, product_id
             FROM medication_reminders
             WHERE user_id = ? AND is_active = 1
               AND (? IS NULL OR line_account_id = ?)
               AND (end_date IS NULL OR end_date >= CURDATE())
             ORDER BY id DESC LIMIT 50'
        );
        $stmt->execute([$userId, $accountId, $accountId]);
        $meds = $stmt->fetchAll(PDO::FETCH_ASSOC);

        self::reply($line, $replyToken, FlexTemplates::toMessage(
            FlexTemplates::medicationList($meds),
            'ยาของฉัน'
        ));
        return true;
    }

    private static function stopMedication($db, $line, $replyToken, $userId, array $params)
    {
        $id = (int) ($params['id'] ?? 0);
        $stmt = $db->prepare('UPDATE medication_reminders SET is_active = 0 WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $userId]);

        if ($stmt->rowCount() === 0) {
            self::reply($line, $replyToken, FlexTemplates::textMessage('ไม่พบรายการยานี้ค่ะ'));
            return true;
        }

        self::reply($line, $replyToken, FlexTemplates::textMessage(
            '🔕 หยุดเตือนยาตัวนี้แล้วค่ะ — อยากให้เตือนอีกเมื่อไหร่ พิมพ์ "ยาของฉัน" ได้เลย'
        ));
        return true;
    }

    private static function refillMedication($db, $line, $replyToken, $userId, array $params)
    {
        $reminder = self::loadReminder($db, (int) ($params['id'] ?? 0), $userId);
        if (!$reminder) {
            self::reply($line, $replyToken, FlexTemplates::textMessage('ไม่พบรายการยานี้ค่ะ'));
            return true;
        }

        if (!empty($reminder['product_id'])) {
            $stmt = $db->prepare('SELECT * FROM business_items WHERE id = ? LIMIT 1');
            $stmt->execute([(int) $reminder['product_id']]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($product) {
                self::reply($line, $replyToken, FlexTemplates::toMessage(
                    FlexTemplates::productCard($product),
                    'สั่งซ้ำ ' . $reminder['medication_name']
                ));
                return true;
            }
        }

        self::reply($line, $replyToken, FlexTemplates::textMessage(
            '🔁 ต้องการสั่ง "' . $reminder['medication_name'] . '" ใช่ไหมคะ' .
            "\nพิมพ์จำนวนที่ต้องการมาได้เลย เภสัชกรจะจัดให้ค่ะ"
        ));
        return true;
    }

    // ------------------------------------------------------------------
    // แต้ม
    // ------------------------------------------------------------------

    private static function showPointsHistory($db, $line, $replyToken, $userId, $accountId)
    {
        $stmt = $db->prepare(
            'SELECT created_at, description, points, balance_after
             FROM points_transactions
             WHERE user_id = ? AND (? IS NULL OR line_account_id = ?)
             ORDER BY created_at DESC LIMIT 10'
        );
        $stmt->execute([$userId, $accountId, $accountId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $balance = $rows ? (int) $rows[0]['balance_after'] : 0;

        self::reply($line, $replyToken, FlexTemplates::toMessage(
            FlexTemplates::pointsHistory($rows, $balance),
            'ประวัติแต้ม'
        ));
        return true;
    }

    // ------------------------------------------------------------------
    // นัดหมาย
    // ------------------------------------------------------------------

    private static function showAppointments($db, $line, $replyToken, $userId, $accountId)
    {
        $stmt = $db->prepare(
            "SELECT id, appointment_type, appointment_date, appointment_time, status, notes
             FROM appointments
             WHERE user_id = ? AND (? IS NULL OR line_account_id = ?)
               AND appointment_date >= CURDATE()
               AND status NOT IN ('cancelled', 'completed', 'no_show')
             ORDER BY appointment_date ASC, appointment_time ASC LIMIT 20"
        );
        $stmt->execute([$userId, $accountId, $accountId]);
        $appts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        self::reply($line, $replyToken, FlexTemplates::toMessage(
            FlexTemplates::appointmentList($appts),
            'นัดหมายของฉัน'
        ));
        return true;
    }

    private static function setAppointmentStatus($db, $line, $replyToken, $userId, array $params, $status)
    {
        $id = (int) ($params['id'] ?? 0);
        $stmt = $db->prepare(
            "UPDATE appointments SET status = ?
             WHERE id = ? AND user_id = ? AND status IN ('pending', 'confirmed')"
        );
        $stmt->execute([$status, $id, $userId]);

        if ($stmt->rowCount() === 0) {
            self::reply($line, $replyToken, FlexTemplates::textMessage('นัดนี้เปลี่ยนสถานะไม่ได้แล้วค่ะ'));
            return true;
        }

        $text = $status === 'confirmed'
            ? '✅ ยืนยันนัดเรียบร้อยค่ะ แล้วเจอกันนะคะ'
            : '✖️ ยกเลิกนัดแล้วค่ะ ต้องการนัดใหม่พิมพ์ "จองนัด" ได้เลย';

        self::reply($line, $replyToken, FlexTemplates::textMessage($text));
        return true;
    }

    // ------------------------------------------------------------------
    // ตั้งค่าแจ้งเตือน — ปิดได้จากในแชท ไม่ต้องเข้า mini App (PDPA)
    // ------------------------------------------------------------------

    private static function showNotificationPrefs($db, $line, $replyToken, $userId)
    {
        self::reply($line, $replyToken, FlexTemplates::toMessage(
            FlexTemplates::notificationPrefs(self::loadPrefs($db, $userId)),
            'ตั้งค่าแจ้งเตือน'
        ));
        return true;
    }

    private static function toggleNotificationPref($db, $line, $replyToken, $userId, $accountId, array $params)
    {
        $key = (string) ($params['key'] ?? '');
        if (!in_array($key, self::TOGGLEABLE_PREFS, true)) {
            return false;
        }

        $prefs = self::loadPrefs($db, $userId);
        $current = !isset($prefs[$key]) || (int) $prefs[$key] === 1;
        $next = $current ? 0 : 1;

        // หนึ่งแถวต่อลูกค้า (user_notification_preferences มี UNIQUE KEY user_id)
        if ($prefs) {
            $stmt = $db->prepare("UPDATE user_notification_preferences SET `{$key}` = ? WHERE user_id = ?");
            $stmt->execute([$next, $userId]);
        } else {
            $stmt = $db->prepare(
                "INSERT INTO user_notification_preferences (user_id, line_account_id, `{$key}`) VALUES (?, ?, ?)"
            );
            $stmt->execute([$userId, $accountId, $next]);
        }

        $prefs[$key] = $next;

        self::reply($line, $replyToken, FlexTemplates::toMessage(
            FlexTemplates::notificationPrefs($prefs),
            'ตั้งค่าแจ้งเตือน'
        ));
        return true;
    }

    private static function loadPrefs($db, $userId)
    {
        $stmt = $db->prepare('SELECT * FROM user_notification_preferences WHERE user_id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : [];
    }

    // ------------------------------------------------------------------
    // หน้าจอที่ BusinessBot วาดไว้อยู่แล้ว — ไม่วาดซ้ำ
    // ------------------------------------------------------------------

    private static function delegateToBot($db, $line, $replyToken, $userId, $accountId, array $ctx, $action)
    {
        if (!class_exists('BusinessBot')) {
            $path = __DIR__ . '/BusinessBot.php';
            if (!file_exists($path)) {
                return false;
            }
            require_once $path;
        }

        $bot = new BusinessBot($db, $line, $accountId);
        $lineUserId = (string) ($ctx['line_user_id'] ?? '');

        if ($action === 'member_points') {
            $bot->showPoints($lineUserId, $userId, $replyToken);
        } elseif ($action === 'member_rewards') {
            $bot->showRewards($lineUserId, $userId, $replyToken);
        } else {
            $bot->showMemberCard($lineUserId, $userId, $replyToken);
        }

        return true;
    }

    // ------------------------------------------------------------------

    /** ตอบด้วย reply token — ฟรี ไม่กินโควตา push จึงไม่ผ่าน NotificationGate */
    private static function reply($line, $replyToken, $message)
    {
        $line->replyMessage($replyToken, [$message]);
    }
}
