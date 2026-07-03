<?php
/**
 * TriageRouter — orchestrator ของ AI Chat triage flow
 *
 * รวม: SymptomMapper, TriageQuestionEngine, TriageSessionManager,
 *      ProductRecommender, RedFlagDetector (มีอยู่), PharmacistNotifier (มีอยู่)
 *
 * Public method หลัก: handleTurn(message, userId)
 *   คืน array<type:..., ...payload> ให้ ai-chat.php emit เป็น SSE structured.
 */

namespace Modules\AIChat\Services;

class TriageRouter
{
    private \PDO $pdo;

    /** @var list<string> */
    private array $geminiKeys;
    private ?int $lineAccountId;

    private SymptomMapper $mapper;
    private TriageQuestionEngine $questions;
    private TriageSessionManager $sessions;
    private ProductRecommender $recommender;
    private ?RedFlagDetector $redFlag;
    private ?PharmacistNotifier $notifier;
    private ?ConsultationAudit $audit;

    /** user_id ของ turn ปัจจุบัน — ตั้งใน handleTurn ให้ build* helper บันทึก audit ได้ */
    private ?int $currentUserId = null;

    /**
     * @param list<string> $geminiKeys
     */
    public function __construct(\PDO $pdo, array $geminiKeys, ?int $lineAccountId)
    {
        $this->pdo = $pdo;
        $this->geminiKeys = $geminiKeys;
        $this->lineAccountId = $lineAccountId;

        $this->mapper      = new SymptomMapper();
        $this->questions   = new TriageQuestionEngine($pdo);
        $this->sessions    = new TriageSessionManager($pdo);
        $this->recommender = new ProductRecommender($pdo, $geminiKeys);

        $this->redFlag  = class_exists(RedFlagDetector::class) ? new RedFlagDetector() : null;
        $this->notifier = class_exists(PharmacistNotifier::class) ? new PharmacistNotifier($lineAccountId) : null;
        $this->audit    = class_exists(ConsultationAudit::class) ? new ConsultationAudit($pdo, $lineAccountId) : null;
    }

    /**
     * บันทึก audit trail แบบไม่ throw (การ log ห้ามทำให้การสนทนาพัง).
     *
     * @param array<string,mixed> $payload
     */
    private function audit(string $eventType, string $actorType, ?int $sessionId, array $payload = [], ?int $actorId = null): void
    {
        if ($this->audit === null) {
            return;
        }
        try {
            $this->audit->log($eventType, $actorType, $sessionId, $this->currentUserId, $payload, $actorId);
        } catch (\Throwable $e) {
            // ConsultationAudit::log กลืน error อยู่แล้ว — กันไว้อีกชั้น
        }
    }

    /**
     * ตรวจว่า triage feature เปิดสำหรับ tenant นี้ไหม.
     * อ่านจาก ai_pharmacy_settings.triage_enabled (default 1).
     */
    public function isTriageEnabled(): bool
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT triage_enabled FROM ai_pharmacy_settings
                 WHERE (line_account_id <=> :acc)
                 ORDER BY (line_account_id IS NOT NULL) DESC
                 LIMIT 1"
            );
            $stmt->execute([':acc' => $this->lineAccountId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$row) {
                return true;
            }
            return !empty($row['triage_enabled']);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Entry point — เรียกทุกครั้งจาก ai-chat.php ก่อนเข้า LLM.
     *
     * @return array<string, mixed>
     */
    public function handleTurn(string $userMessage, int $userId): array
    {
        if (!$this->isTriageEnabled()) {
            return ['type' => 'continue'];
        }

        $this->currentUserId = $userId;

        // 1. Red flag screen — ดูข้อความผู้ใช้ก่อน triage
        if ($this->redFlag !== null) {
            try {
                $flags = $this->redFlag->detect($userMessage);
                if (!empty($flags) && method_exists($this->redFlag, 'isCritical') && $this->redFlag->isCritical($flags)) {
                    return $this->buildEscalateResult(null, $this->summarizeRedFlags($flags));
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }

        // 2. resume active session
        $session = $this->getActiveSession($userId);
        $askedIds = [];
        $sessionId = null;

        if ($session !== null) {
            $sessionId = (int) $session['id'];
            $askedIds = $this->sessions->getAskedQuestionIds($sessionId);

            $lastQid = $this->getLastQuestionId($session);
            if ($lastQid !== null) {
                $answer = $this->normalizeAnswer($userMessage);
                $lastQ = $this->questions->getQuestionById($lastQid);
                if ($lastQ !== null && $answer !== null) {
                    $newSymptoms = ($answer === 'yes') ? $lastQ['recommend_symptom_codes'] : [];
                    $this->sessions->recordResponse($sessionId, $lastQid, $answer, $userMessage, $newSymptoms);
                    $askedIds[] = $lastQid;

                    if ($answer === 'yes' && !empty($lastQ['red_flag_if_yes'])) {
                        return $this->buildEscalateResult($sessionId, 'พบสัญญาณที่ต้องพบเภสัชกร/แพทย์ทันที');
                    }

                    $branchId = $answer === 'yes' ? ($lastQ['next_if_yes'] ?? null) : ($lastQ['next_if_no'] ?? null);
                    if ($branchId !== null && !in_array($branchId, $askedIds, true)) {
                        $next = $this->questions->getQuestionById($branchId);
                        if ($next !== null) {
                            return $this->buildQuestionResult($sessionId, $next);
                        }
                    }
                }
            }
        }

        // 3. ถ้ายังไม่มี session — ตรวจจับ symptom signal เพื่อเริ่ม session
        if ($session === null) {
            if (!$this->mapper->hasSymptomSignal($userMessage)) {
                return ['type' => 'continue'];
            }
            $conditionCode = $this->mapper->mapMessageToCondition($userMessage) ?? 'pain_general';
            $session = $this->sessions->resumeOrStart($this->lineAccountId, $userId, $conditionCode, $userMessage);
            $sessionId = (int) $session['id'];
            $askedIds = [];
        }

        // 4. enforce max_questions_per_session
        $maxQ = $this->getMaxQuestionsPerSession();
        if ($sessionId !== null && $this->sessions->countQuestionsAsked($sessionId) >= $maxQ) {
            return $this->finishWithProducts($sessionId);
        }

        // 5. ดึงคำถามถัดไปจาก condition tree
        $conditionCode = (string) ($session['current_state'] ?? 'pain_general');
        $next = $this->questions->getNextQuestion($this->lineAccountId, $conditionCode, $askedIds);
        if ($next === null) {
            return $sessionId !== null ? $this->finishWithProducts($sessionId) : ['type' => 'continue'];
        }
        return $this->buildQuestionResult($sessionId, $next);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getActiveSession(int $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM triage_sessions
             WHERE user_id = :uid
               AND (line_account_id <=> :acc)
               AND status = 'active'
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([':uid' => $userId, ':acc' => $this->lineAccountId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * @param array<string, mixed> $session
     */
    private function getLastQuestionId(array $session): ?int
    {
        $raw = $session['triage_data'] ?? null;
        if (!is_string($raw)) {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }
        $qid = $data['last_question_id'] ?? null;
        return is_numeric($qid) ? (int) $qid : null;
    }

    private function normalizeAnswer(string $msg): ?string
    {
        $m = mb_strtolower(trim($msg));
        if ($m === '') {
            return null;
        }
        $yes = ['yes', 'ใช่', 'มี', 'ค่ะ', 'ครับ', 'มีค่ะ', 'มีครับ', 'y'];
        foreach ($yes as $w) {
            if ($m === $w || mb_stripos($m, $w) === 0) {
                return 'yes';
            }
        }
        $no = ['no', 'ไม่', 'ไม่ใช่', 'ไม่มี', 'ไม่ค่ะ', 'ไม่ครับ', 'n'];
        foreach ($no as $w) {
            if ($m === $w || mb_stripos($m, $w) === 0) {
                return 'no';
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed> $question
     * @return array<string, mixed>
     */
    private function buildQuestionResult(?int $sessionId, array $question): array
    {
        $qid = (int) $question['id'];
        // persist last_question_id ทันที — กัน turn ถัดไปไม่เจอ context และลูปคำถามเดิม
        if ($sessionId !== null && $qid > 0) {
            try {
                $this->sessions->setLastQuestion($sessionId, $qid);
            } catch (\Throwable $e) {
                error_log('setLastQuestion failed: ' . $e->getMessage());
            }
        }
        $this->audit('triage_question', 'ai', $sessionId, [
            'question_id' => $qid,
            'question_th' => (string) $question['question_th'],
        ]);
        return [
            'type'        => 'question',
            'session_id'  => $sessionId,
            'question_id' => $qid,
            'question_th' => (string) $question['question_th'],
            'options'     => $question['options'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildEscalateResult(?int $sessionId, string $message): array
    {
        $this->audit('escalation', 'system', $sessionId, [
            'message'  => $message,
            'red_flag' => true,
        ]);
        if ($sessionId !== null) {
            $this->sessions->escalate($sessionId, $message);
        }
        if ($this->notifier !== null && $sessionId !== null) {
            try {
                $this->notifier->notifyAllPharmacists([
                    'session_id' => $sessionId,
                    'message'    => $message,
                    'red_flag'   => true,
                ], true);
            } catch (\Throwable $e) {
                // ignore
            }
        }
        $this->fireAndForgetSummary($sessionId);
        return [
            'type'       => 'escalate',
            'session_id' => $sessionId,
            'message'    => $message !== '' ? $message : 'พบสัญญาณที่ต้องพบเภสัชกร/แพทย์',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function finishWithProducts(int $sessionId): array
    {
        $symptoms = $this->sessions->getCollectedSymptoms($sessionId);
        $products = $this->canRecommendProducts() && !empty($symptoms)
            ? $this->recommender->recommend($this->lineAccountId, $symptoms, [], 5)
            : [];

        // 🆕 Empty product list → don't dead-end the chat. Fall through to
        // Gemini AI so it can suggest OTC drugs by name (Paracetamol etc.)
        // and explain dose/usage. Mark session complete so the same Y/N flow
        // doesn't re-trigger, but return `continue` to let the LLM answer.
        if (empty($products)) {
            $this->sessions->complete($sessionId, 'self_care', 'no_match_fallback_to_ai');
            $this->fireAndForgetSummary($sessionId);
            return ['type' => 'continue'];
        }

        $summary = 'ตามอาการที่คุณระบุ แนะนำสินค้าต่อไปนี้ค่ะ';
        $this->sessions->complete($sessionId, 'otc_recommended', $summary);

        $this->audit('ai_recommendation', 'ai', $sessionId, [
            'symptoms'     => array_values($symptoms),
            'product_ids'  => array_values(array_filter(array_map(
                static fn ($p) => is_array($p) ? ($p['id'] ?? $p['product_id'] ?? null) : null,
                $products
            ))),
            'product_count' => count($products),
        ]);
        $this->fireAndForgetSummary($sessionId);

        return [
            'type'       => 'products',
            'session_id' => $sessionId,
            'products'   => $products,
            'message'    => $summary,
        ];
    }

    /**
     * Trigger the auto-summary helper after the response has been flushed.
     * Replaces the previous self-HTTP curl indirection — that path had two
     * security issues:
     *   1. The endpoint accepted any session_id without auth.
     *   2. URL was built from $_SERVER['HTTP_HOST'] (attacker-controlled).
     *
     * New flow: flush the response via fastcgi_finish_request (FPM only),
     * then call the helper in-process. The cron sweeper
     * (cron/ai_session_summarizer.php) still catches anything this drops.
     */
    private function fireAndForgetSummary(?int $sessionId): void
    {
        if ($sessionId === null || $sessionId <= 0) {
            return;
        }
        $sid = $sessionId;
        try {
            register_shutdown_function(static function () use ($sid): void {
                try {
                    if (function_exists('fastcgi_finish_request')) {
                        @fastcgi_finish_request(); // response already sent
                    }
                    require_once __DIR__ . '/../../../includes/ai-chat-summary-helper.php';
                    $db = \Database::getInstance()->getConnection();
                    summary_run_for_session($db, $sid);
                } catch (\Throwable $e) {
                    error_log('[TriageRouter] summary helper failed: ' . $e->getMessage());
                }
            });
        } catch (\Throwable $e) {
            error_log('[TriageRouter] register_shutdown_function failed: ' . $e->getMessage());
        }
    }

    private function canRecommendProducts(): bool
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT recommend_products FROM ai_pharmacy_settings
                 WHERE (line_account_id <=> :acc)
                 ORDER BY (line_account_id IS NOT NULL) DESC
                 LIMIT 1"
            );
            $stmt->execute([':acc' => $this->lineAccountId]);
            $val = $stmt->fetchColumn();
            return $val === false ? true : !empty($val);
        } catch (\Throwable $e) {
            return true;
        }
    }

    private function getMaxQuestionsPerSession(): int
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT max_questions_per_session FROM ai_pharmacy_settings
                 WHERE (line_account_id <=> :acc)
                 ORDER BY (line_account_id IS NOT NULL) DESC
                 LIMIT 1"
            );
            $stmt->execute([':acc' => $this->lineAccountId]);
            $val = $stmt->fetchColumn();
            $n = (int) $val;
            return $n > 0 ? $n : 7;
        } catch (\Throwable $e) {
            return 7;
        }
    }

    /**
     * @param list<array<string, mixed>> $flags
     */
    private function summarizeRedFlags(array $flags): string
    {
        $msgs = [];
        foreach ($flags as $f) {
            $msg = (string) ($f['message'] ?? '');
            $action = (string) ($f['action'] ?? '');
            if ($msg !== '' || $action !== '') {
                $msgs[] = trim($msg . ' ' . $action);
            }
        }
        return empty($msgs) ? 'พบสัญญาณที่ต้องพบเภสัชกร/แพทย์ทันที' : implode("\n", $msgs);
    }
}
