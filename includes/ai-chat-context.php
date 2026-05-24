<?php
/**
 * AI Chat — user context, persistence, and notification helpers
 * Phase 1 of AI Chat Option D (2026-05-24)
 *
 * These helpers are PORTED — not duplicated — from api/pharmacy-ai.php so
 * the Mini-App SSE endpoint (api/ai-chat.php) and the history endpoint
 * (api/ai-chat-history.php) share one safety/persistence layer.
 *
 * Design notes:
 *  - All functions return ARRAYS, never throw; on error they log + degrade to
 *    safe defaults so the SSE stream is never killed.
 *  - All SQL uses prepared statements + Database::getInstance() PDO singleton.
 *  - PHP 8.0 compatible (no enums, no readonly).
 */

if (!function_exists('aiChatEnsureConversationHistorySchema')) {
    /**
     * Ensure ai_conversation_history has the columns we depend on.
     * Safety net for environments where the migration has not yet been run.
     */
    function aiChatEnsureConversationHistorySchema($db): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;

        try {
            $db->query('SELECT session_id FROM ai_conversation_history LIMIT 1');
        } catch (\Throwable $e) {
            try {
                $db->exec('ALTER TABLE ai_conversation_history ADD COLUMN session_id VARCHAR(64) NULL');
            } catch (\Throwable $alterError) {
                error_log('aiChatEnsureConversationHistorySchema(session_id): ' . $alterError->getMessage());
            }
        }

        try {
            $db->query('SELECT line_account_id FROM ai_conversation_history LIMIT 1');
        } catch (\Throwable $e) {
            try {
                $db->exec('ALTER TABLE ai_conversation_history ADD COLUMN line_account_id INT NULL');
            } catch (\Throwable $alterError) {
                error_log('aiChatEnsureConversationHistorySchema(line_account_id): ' . $alterError->getMessage());
            }
        }
    }
}

if (!function_exists('aiChatResolveInternalUserId')) {
    /**
     * Resolve users.id from LINE user_id. Returns 0 if no row exists.
     * Does NOT create a row — we only persist conversation for known users.
     */
    function aiChatResolveInternalUserId($db, string $lineUserId): int
    {
        $lineUserId = trim($lineUserId);
        if ($lineUserId === '') {
            return 0;
        }
        try {
            $stmt = $db->prepare('SELECT id FROM users WHERE line_user_id = ? LIMIT 1');
            $stmt->execute([$lineUserId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row && !empty($row['id']) ? (int) $row['id'] : 0;
        } catch (\Throwable $e) {
            error_log('aiChatResolveInternalUserId: ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('getUserFullContextForChat')) {
    /**
     * Load full safety + behavioural context for a LINE user.
     *
     * @return array{
     *   id:int,
     *   display_name:?string,
     *   drug_allergies:array<int,array{drug_name:string,reaction_type:?string,severity:?string}>,
     *   chronic_diseases:?string,
     *   current_medications:array<int,array{medication_name:string,dosage:?string}>,
     *   recent_orders:array<int,array<string,mixed>>,
     *   frequent_products:array<int,array<string,mixed>>
     * }
     */
    function getUserFullContextForChat($db, ?string $lineUserId): array
    {
        $empty = [
            'id'                  => 0,
            'display_name'        => null,
            'drug_allergies'      => [],
            'chronic_diseases'    => null,
            'current_medications' => [],
            'recent_orders'       => [],
            'frequent_products'   => [],
        ];

        if ($lineUserId === null || trim($lineUserId) === '') {
            return $empty;
        }
        $lineUserId = trim($lineUserId);

        try {
            $stmt = $db->prepare(
                "SELECT u.id, u.display_name, uhp.medical_conditions
                   FROM users u
              LEFT JOIN user_health_profiles uhp ON uhp.line_user_id = u.line_user_id
                  WHERE u.line_user_id = ?
                  LIMIT 1"
            );
            $stmt->execute([$lineUserId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$user) {
                return $empty;
            }
            $userId = (int) $user['id'];

            $ctx = $empty;
            $ctx['id']               = $userId;
            $ctx['display_name']     = isset($user['display_name']) ? (string) $user['display_name'] : null;
            $ctx['chronic_diseases'] = isset($user['medical_conditions']) && $user['medical_conditions'] !== ''
                ? (string) $user['medical_conditions'] : null;

            // Allergies
            try {
                $stmt = $db->prepare(
                    'SELECT drug_name, reaction_type, severity
                       FROM user_drug_allergies
                      WHERE line_user_id = ?
                      ORDER BY created_at DESC'
                );
                $stmt->execute([$lineUserId]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                $ctx['drug_allergies'] = array_map(static function ($r) {
                    return [
                        'drug_name'     => (string) ($r['drug_name'] ?? ''),
                        'reaction_type' => isset($r['reaction_type']) ? (string) $r['reaction_type'] : null,
                        'severity'      => isset($r['severity']) ? (string) $r['severity'] : null,
                    ];
                }, $rows);
            } catch (\Throwable $e) {
                error_log('getUserFullContextForChat(allergies): ' . $e->getMessage());
            }

            // Current medications
            try {
                $stmt = $db->prepare(
                    'SELECT medication_name, dosage
                       FROM user_current_medications
                      WHERE line_user_id = ? AND is_active = 1
                      ORDER BY created_at DESC'
                );
                $stmt->execute([$lineUserId]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                $ctx['current_medications'] = array_map(static function ($r) {
                    return [
                        'medication_name' => (string) ($r['medication_name'] ?? ''),
                        'dosage'          => isset($r['dosage']) ? (string) $r['dosage'] : null,
                    ];
                }, $rows);
            } catch (\Throwable $e) {
                error_log('getUserFullContextForChat(meds): ' . $e->getMessage());
            }

            // Recent orders (top 3)
            try {
                $stmt = $db->prepare(
                    "SELECT t.id, t.order_number, t.total_amount, t.status, t.created_at,
                            GROUP_CONCAT(ti.product_name SEPARATOR ', ') AS products
                       FROM transactions t
                  LEFT JOIN transaction_items ti ON ti.transaction_id = t.id
                      WHERE t.user_id = ?
                   GROUP BY t.id
                   ORDER BY t.created_at DESC
                      LIMIT 3"
                );
                $stmt->execute([$userId]);
                $ctx['recent_orders'] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (\Throwable $e) {
                error_log('getUserFullContextForChat(orders): ' . $e->getMessage());
            }

            // Frequent products (top 5)
            try {
                $stmt = $db->prepare(
                    'SELECT ti.product_name, COUNT(*) AS purchase_count
                       FROM transaction_items ti
                       JOIN transactions t ON ti.transaction_id = t.id
                      WHERE t.user_id = ?
                   GROUP BY ti.product_name
                   ORDER BY purchase_count DESC
                      LIMIT 5'
                );
                $stmt->execute([$userId]);
                $ctx['frequent_products'] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (\Throwable $e) {
                error_log('getUserFullContextForChat(frequent): ' . $e->getMessage());
            }

            return $ctx;
        } catch (\Throwable $e) {
            error_log('getUserFullContextForChat: ' . $e->getMessage());
            return $empty;
        }
    }
}

if (!function_exists('aiChatBuildUserProfileXml')) {
    /**
     * Build a deterministic <user_profile> XML block to inject into the
     * Gemini system_instruction. Returns '' if context is empty.
     */
    function aiChatBuildUserProfileXml(array $ctx): string
    {
        $allergies = $ctx['drug_allergies'] ?? [];
        $chronic   = $ctx['chronic_diseases'] ?? null;
        $meds      = $ctx['current_medications'] ?? [];

        if (empty($allergies) && empty($meds) && ($chronic === null || $chronic === '')) {
            return '';
        }

        $esc = static function ($v): string {
            return htmlspecialchars((string) $v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        };

        $xml = "<user_profile>\n";

        $xml .= "  <allergies>";
        if (!empty($allergies)) {
            $parts = [];
            foreach ($allergies as $a) {
                $line = $esc($a['drug_name'] ?? '');
                if (!empty($a['reaction_type'])) {
                    $line .= ' (' . $esc($a['reaction_type']) . ')';
                }
                if (!empty($a['severity'])) {
                    $line .= ' [severity=' . $esc($a['severity']) . ']';
                }
                $parts[] = $line;
            }
            $xml .= implode('; ', $parts);
        } else {
            $xml .= 'none';
        }
        $xml .= "</allergies>\n";

        $xml .= "  <chronic_diseases>" . ($chronic !== null && $chronic !== '' ? $esc($chronic) : 'none') . "</chronic_diseases>\n";

        $xml .= "  <current_medications>";
        if (!empty($meds)) {
            $parts = [];
            foreach ($meds as $m) {
                $line = $esc($m['medication_name'] ?? '');
                if (!empty($m['dosage'])) {
                    $line .= ' — ' . $esc($m['dosage']);
                }
                $parts[] = $line;
            }
            $xml .= implode('; ', $parts);
        } else {
            $xml .= 'none';
        }
        $xml .= "</current_medications>\n";

        $xml .= "</user_profile>";
        return $xml;
    }
}

if (!function_exists('aiChatBuildUserContextEvent')) {
    /**
     * Shape the user_context SSE payload per the canonical schema in
     * docs/plans/2026-05-24-ai-chat-option-d-spec.md.
     */
    function aiChatBuildUserContextEvent(array $ctx): array
    {
        $allergies = array_values(array_filter(
            $ctx['drug_allergies'] ?? [],
            static function ($a) { return !empty($a['drug_name']); }
        ));
        $meds = array_values(array_filter(
            $ctx['current_medications'] ?? [],
            static function ($m) { return !empty($m['medication_name']); }
        ));

        return [
            'type'                => 'user_context',
            'name'                => $ctx['display_name'] ?? null,
            'allergies'           => array_map(static function ($a) {
                return [
                    'drug_name'     => (string) ($a['drug_name'] ?? ''),
                    'reaction_type' => (string) ($a['reaction_type'] ?? ''),
                    'severity'      => (string) ($a['severity'] ?? 'medium'),
                ];
            }, $allergies),
            'chronic_diseases'    => $ctx['chronic_diseases'] ?? null,
            'current_medications' => array_map(static function ($m) {
                return [
                    'medication_name' => (string) ($m['medication_name'] ?? ''),
                    'dosage'          => (string) ($m['dosage'] ?? ''),
                ];
            }, $meds),
            'has_allergies'       => !empty($allergies),
            'has_medications'     => !empty($meds),
        ];
    }
}

if (!function_exists('aiChatSaveConversationMessage')) {
    /**
     * Persist one role/content row to ai_conversation_history.
     * Returns true on success, false on any error.
     */
    function aiChatSaveConversationMessage(
        $db,
        int $userId,
        ?int $lineAccountId,
        ?string $sessionId,
        string $role,
        string $content
    ): bool {
        if ($userId <= 0 || $content === '') {
            return false;
        }
        if ($role !== 'user' && $role !== 'assistant') {
            $role = 'user';
        }

        try {
            aiChatEnsureConversationHistorySchema($db);
            $stmt = $db->prepare(
                'INSERT INTO ai_conversation_history
                    (user_id, line_account_id, session_id, role, content, created_at)
                 VALUES (?, ?, ?, ?, ?, NOW())'
            );
            $stmt->execute([
                $userId,
                $lineAccountId,
                $sessionId,
                $role,
                $content,
            ]);
            return true;
        } catch (\Throwable $e) {
            error_log('aiChatSaveConversationMessage: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('aiChatGetConversationHistory')) {
    /**
     * Fetch the last N conversation rows for a LINE user, chronological order.
     *
     * @return array<int,array{role:string,content:string,session_id:?string,created_at:string}>
     */
    function aiChatGetConversationHistory($db, string $lineUserId, int $limit = 20): array
    {
        $lineUserId = trim($lineUserId);
        if ($lineUserId === '') {
            return [];
        }
        $limit = max(1, min(100, $limit));

        try {
            $userId = aiChatResolveInternalUserId($db, $lineUserId);
            if ($userId <= 0) {
                return [];
            }
            aiChatEnsureConversationHistorySchema($db);
            $sql = 'SELECT role, content, session_id, created_at
                      FROM ai_conversation_history
                     WHERE user_id = ?
                  ORDER BY created_at DESC, id DESC
                     LIMIT ' . (int) $limit;
            $stmt = $db->prepare($sql);
            $stmt->execute([$userId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            return array_reverse(array_map(static function ($r) {
                return [
                    'role'       => (string) ($r['role'] ?? 'user'),
                    'content'    => (string) ($r['content'] ?? ''),
                    'session_id' => isset($r['session_id']) ? (string) $r['session_id'] : null,
                    'created_at' => (string) ($r['created_at'] ?? ''),
                ];
            }, $rows));
        } catch (\Throwable $e) {
            error_log('aiChatGetConversationHistory: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('aiChatCheckDrugInteractionsSimple')) {
    /**
     * Lightweight allergy + interaction check used to emit a
     * drug_interactions SSE event AFTER products are mentioned.
     * Ported from api/pharmacy-ai.php :: checkDrugInteractionsSimple.
     *
     * @param array<int,array<string,mixed>> $products
     * @param array $ctx output of getUserFullContextForChat
     * @return array<int,array<string,mixed>>
     */
    function aiChatCheckDrugInteractionsSimple(array $products, array $ctx): array
    {
        $allergiesList = $ctx['drug_allergies'] ?? [];
        $medsList      = $ctx['current_medications'] ?? [];
        if (empty($products) || (empty($allergiesList) && empty($medsList))) {
            return [];
        }

        // Built-in interaction map (Thai + English drug names)
        $interactionDb = [
            'warfarin'    => ['aspirin', 'ibuprofen', 'naproxen', 'แอสไพริน', 'ไอบูโพรเฟน'],
            'metformin'   => ['alcohol', 'แอลกอฮอล์'],
            'aspirin'     => ['ibuprofen', 'ไอบูโพรเฟน', 'naproxen'],
            'lisinopril'  => ['potassium', 'โพแทสเซียม'],
            'simvastatin' => ['grapefruit', 'เกรปฟรุต'],
            'methotrexate'=> ['nsaid', 'ibuprofen', 'aspirin'],
            'digoxin'     => ['amiodarone', 'verapamil'],
            'clopidogrel' => ['omeprazole', 'โอเมพราโซล'],
        ];

        $warnings = [];
        foreach ($products as $product) {
            if (!is_array($product)) {
                continue;
            }
            $productName = mb_strtolower((string) ($product['name'] ?? ''), 'UTF-8');
            $genericName = mb_strtolower((string) ($product['generic_name'] ?? ''), 'UTF-8');
            if ($productName === '' && $genericName === '') {
                continue;
            }

            $allergyHit = false;
            foreach ($allergiesList as $allergy) {
                $allergyName = mb_strtolower((string) ($allergy['drug_name'] ?? ''), 'UTF-8');
                if ($allergyName === '') {
                    continue;
                }
                if (
                    ($productName !== '' && (mb_strpos($productName, $allergyName) !== false || mb_strpos($allergyName, $productName) !== false))
                    || ($genericName !== '' && (mb_strpos($genericName, $allergyName) !== false || mb_strpos($allergyName, $genericName) !== false))
                ) {
                    $warnings[] = [
                        'type'           => 'allergy',
                        'severity'       => 'high',
                        'product'        => (string) ($product['name'] ?? ''),
                        'message'        => '⛔ ห้ามใช้! คุณแพ้ยา ' . ($allergy['drug_name'] ?? ''),
                        'reaction_type'  => (string) ($allergy['reaction_type'] ?? 'other'),
                        'interacts_with' => null,
                    ];
                    $allergyHit = true;
                    break;
                }
            }
            if ($allergyHit) {
                // Don't double-warn on interactions for an allergic product.
                continue;
            }

            foreach ($medsList as $med) {
                $medName = mb_strtolower((string) ($med['medication_name'] ?? ''), 'UTF-8');
                if ($medName === '') {
                    continue;
                }
                foreach ($interactionDb as $drug => $interactsWith) {
                    if (mb_strpos($medName, $drug) === false) {
                        continue;
                    }
                    foreach ($interactsWith as $interactDrug) {
                        $needle = mb_strtolower($interactDrug, 'UTF-8');
                        if (
                            ($productName !== '' && mb_strpos($productName, $needle) !== false)
                            || ($genericName !== '' && mb_strpos($genericName, $needle) !== false)
                        ) {
                            $warnings[] = [
                                'type'           => 'interaction',
                                'severity'       => 'medium',
                                'product'        => (string) ($product['name'] ?? ''),
                                'message'        => '⚠️ ' . ($product['name'] ?? '') . ' อาจตีกับยา ' . ($med['medication_name'] ?? '') . ' ที่คุณทานอยู่',
                                'reaction_type'  => 'other',
                                'interacts_with' => (string) ($med['medication_name'] ?? ''),
                            ];
                            continue 3;
                        }
                    }
                }
            }
        }
        return $warnings;
    }
}

if (!function_exists('aiChatExtractProductMentions')) {
    /**
     * Crude product-mention extractor — scans the AI response for catalog
     * product names so we can drive the drug-interaction warning event.
     * Returns rows shaped like [{id, name, generic_name}] suitable for
     * aiChatCheckDrugInteractionsSimple().
     *
     * @return array<int,array{id:?int,name:string,generic_name:string}>
     */
    function aiChatExtractProductMentions($db, ?int $lineAccountId, string $aiResponse): array
    {
        $aiResponse = trim($aiResponse);
        if ($aiResponse === '') {
            return [];
        }

        $haystack = mb_strtolower($aiResponse, 'UTF-8');
        $found = [];

        try {
            // Pull a bounded list of active product names + generics. Keep
            // the limit low — this runs on every consult turn.
            $sql = 'SELECT id, name, generic_name
                      FROM business_items
                     WHERE is_active = 1';
            $args = [];
            if ($lineAccountId !== null) {
                $sql .= ' AND (line_account_id = ? OR line_account_id IS NULL)';
                $args[] = $lineAccountId;
            }
            $sql .= ' LIMIT 500';
            $stmt = $db->prepare($sql);
            $stmt->execute($args);

            while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
                $name    = trim((string) ($row['name'] ?? ''));
                $generic = trim((string) ($row['generic_name'] ?? ''));
                if ($name === '' && $generic === '') {
                    continue;
                }
                $hit = false;
                if ($name !== '' && mb_strlen($name) >= 3) {
                    if (mb_strpos($haystack, mb_strtolower($name, 'UTF-8')) !== false) {
                        $hit = true;
                    }
                }
                if (!$hit && $generic !== '' && mb_strlen($generic) >= 3) {
                    if (mb_strpos($haystack, mb_strtolower($generic, 'UTF-8')) !== false) {
                        $hit = true;
                    }
                }
                if ($hit) {
                    $found[] = [
                        'id'           => isset($row['id']) ? (int) $row['id'] : null,
                        'name'         => $name,
                        'generic_name' => $generic,
                    ];
                    if (count($found) >= 10) {
                        break;
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log('aiChatExtractProductMentions: ' . $e->getMessage());
        }
        return $found;
    }
}

if (!function_exists('aiChatGetActiveTriageSession')) {
    /**
     * Look up the currently-active triage session for an internal user id.
     * Returns null if none exists. Read-only — never creates.
     *
     * @return array<string,mixed>|null
     */
    function aiChatGetActiveTriageSession($db, int $userId, ?int $lineAccountId): ?array
    {
        if ($userId <= 0) {
            return null;
        }
        try {
            $stmt = $db->prepare(
                "SELECT * FROM triage_sessions
                  WHERE user_id = :uid
                    AND (line_account_id <=> :acc)
                    AND status = 'active'
                  ORDER BY id DESC LIMIT 1"
            );
            $stmt->execute([':uid' => $userId, ':acc' => $lineAccountId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (\Throwable $e) {
            error_log('aiChatGetActiveTriageSession: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('aiChatEnsureTriageNotification')) {
    /**
     * Make sure the pharmacist dashboard sees a row for this triage session.
     * Ported from api/pharmacy-ai.php :: ensureTriageNotification, trimmed
     * down to what the Mini-App consult flow actually needs.
     *
     * Safe to call repeatedly — updates the existing pending row.
     */
    function aiChatEnsureTriageNotification(
        $db,
        int $sessionId,
        int $userId,
        ?int $lineAccountId,
        array $userContext,
        array $triageData = [],
        string $state = 'active'
    ): bool {
        if ($sessionId <= 0 || $userId <= 0) {
            return false;
        }

        try {
            // Make sure the table exists (matches pharmacy-ai.php DDL).
            $db->exec(
                'CREATE TABLE IF NOT EXISTS pharmacist_notifications (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    line_account_id INT NULL,
                    type VARCHAR(50) DEFAULT "triage_alert",
                    title VARCHAR(255),
                    message TEXT,
                    notification_data JSON,
                    reference_id INT,
                    reference_type VARCHAR(50),
                    user_id INT,
                    triage_session_id INT NULL,
                    priority ENUM("normal","urgent") DEFAULT "normal",
                    status ENUM("pending","handled","dismissed") DEFAULT "pending",
                    is_read TINYINT(1) DEFAULT 0,
                    handled_by INT NULL,
                    handled_at TIMESTAMP NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_line_account (line_account_id),
                    INDEX idx_status (status),
                    INDEX idx_priority (priority),
                    INDEX idx_triage_session (triage_session_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );

            // Existing pending notification for this session?
            $stmt = $db->prepare(
                "SELECT id FROM pharmacist_notifications
                  WHERE triage_session_id = ? AND status = 'pending'
                  LIMIT 1"
            );
            $stmt->execute([$sessionId]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            $symptoms = $triageData['symptoms'] ?? '';
            if (is_array($symptoms)) {
                $symptoms = implode(', ', $symptoms);
            }
            $severity = $triageData['severity'] ?? null;
            $priority = 'normal';
            if ($severity !== null && (int) $severity >= 6) {
                $priority = 'urgent';
            }

            $userName = (string) ($userContext['display_name'] ?? 'ไม่ระบุชื่อ');

            $notificationData = json_encode([
                'symptoms'         => $symptoms,
                'duration'         => $triageData['duration'] ?? '',
                'severity'         => $severity,
                'allergies'        => array_map(static function ($a) {
                    return $a['drug_name'] ?? '';
                }, $userContext['drug_allergies'] ?? []),
                'chronic_diseases' => $userContext['chronic_diseases'] ?? '',
                'current_meds'     => array_map(static function ($m) {
                    return $m['medication_name'] ?? '';
                }, $userContext['current_medications'] ?? []),
                'current_state'    => $state,
                'user_name'        => $userName,
                'source'           => 'ai-chat-mini-app',
            ], JSON_UNESCAPED_UNICODE);

            if ($existing) {
                $stmt = $db->prepare(
                    'UPDATE pharmacist_notifications
                        SET notification_data = ?, priority = ?, updated_at = NOW()
                      WHERE id = ?'
                );
                $stmt->execute([$notificationData, $priority, (int) $existing['id']]);
                return true;
            }

            $title   = $priority === 'urgent' ? '⚠️ การซักประวัติ — ต้องตรวจสอบ' : '🩺 การซักประวัติใหม่';
            $message = "ลูกค้า: {$userName}\n";
            if ($symptoms !== '') {
                $message .= "อาการ: {$symptoms}\n";
            }
            if (!empty($triageData['duration'])) {
                $message .= "ระยะเวลา: " . $triageData['duration'] . "\n";
            }
            if ($severity !== null) {
                $message .= "ความรุนแรง: {$severity}/10\n";
            }
            $message .= "สถานะ: {$state}";

            $stmt = $db->prepare(
                "INSERT INTO pharmacist_notifications
                    (line_account_id, type, title, message, notification_data,
                     user_id, triage_session_id, priority, status)
                 VALUES (?, 'triage_session', ?, ?, ?, ?, ?, ?, 'pending')"
            );
            $stmt->execute([
                $lineAccountId,
                $title,
                $message,
                $notificationData,
                $userId,
                $sessionId,
                $priority,
            ]);
            return true;
        } catch (\Throwable $e) {
            error_log('aiChatEnsureTriageNotification: ' . $e->getMessage());
            return false;
        }
    }
}
