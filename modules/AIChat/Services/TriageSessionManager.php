<?php
/**
 * TriageSessionManager — จัดการ row ใน triage_sessions + triage_question_responses
 *
 * ขอบเขต: persist state ของ Yes/No flow, log คำตอบของผู้ใช้,
 * mark session ว่า completed / escalated.
 *
 * ใช้ PDO โดยตรง (constructor inject) เพื่อให้ test กับ in-memory PDO ได้
 */

namespace Modules\AIChat\Services;

class TriageSessionManager
{
    private \PDO $pdo;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * คืน session ที่ active สำหรับ (line_account_id, user_id) — ถ้าไม่มี ให้สร้างใหม่.
     *
     * @return array<string, mixed> row ของ triage_sessions
     */
    public function resumeOrStart(?int $lineAccountId, int $userId, string $conditionCode, string $chiefComplaint = ''): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM triage_sessions
             WHERE user_id = :uid
               AND (line_account_id <=> :acc)
               AND status = 'active'
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([':uid' => $userId, ':acc' => $lineAccountId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row) {
            return $row;
        }

        $triageData = json_encode([
            'condition_code'     => $conditionCode,
            'symptoms'           => [],
            'last_question_id'   => null,
            'asked_question_ids' => [],
        ], JSON_UNESCAPED_UNICODE);

        $insert = $this->pdo->prepare(
            "INSERT INTO triage_sessions
             (line_account_id, user_id, current_state, triage_data, status, triage_level, chief_complaint, created_at, updated_at)
             VALUES (:acc, :uid, :cond, :data, 'active', 'green', :complaint, NOW(), NOW())"
        );
        $insert->execute([
            ':acc'       => $lineAccountId,
            ':uid'       => $userId,
            ':cond'      => $conditionCode,
            ':data'      => $triageData,
            ':complaint' => mb_substr($chiefComplaint, 0, 1000),
        ]);
        $newId = (int) $this->pdo->lastInsertId();

        return [
            'id'              => $newId,
            'line_account_id' => $lineAccountId,
            'user_id'         => $userId,
            'current_state'   => $conditionCode,
            'triage_data'     => $triageData,
            'status'          => 'active',
            'triage_level'    => 'green',
            'chief_complaint' => $chiefComplaint,
        ];
    }

    /**
     * บันทึกคำตอบของผู้ใช้ + อัพเดท triage_data ให้สะสม symptom codes ที่ได้.
     *
     * @param list<string> $newSymptomCodes symptom codes ที่ recommend_symptom_codes ของ question บวกเข้า
     */
    public function recordResponse(int $sessionId, int $questionId, string $answerValue, ?string $answerText, array $newSymptomCodes = []): void
    {
        $log = $this->pdo->prepare(
            "INSERT INTO triage_question_responses
             (triage_session_id, question_id, answer_value, answer_text, answered_at)
             VALUES (:sid, :qid, :val, :txt, NOW())"
        );
        $log->execute([
            ':sid' => $sessionId,
            ':qid' => $questionId,
            ':val' => mb_substr($answerValue, 0, 255),
            ':txt' => $answerText !== null ? mb_substr($answerText, 0, 2000) : null,
        ]);

        $sel = $this->pdo->prepare("SELECT triage_data FROM triage_sessions WHERE id = :id");
        $sel->execute([':id' => $sessionId]);
        $raw = $sel->fetchColumn();
        $data = is_string($raw) ? (json_decode($raw, true) ?: []) : [];

        if (!isset($data['symptoms']) || !is_array($data['symptoms'])) {
            $data['symptoms'] = [];
        }
        if (!isset($data['asked_question_ids']) || !is_array($data['asked_question_ids'])) {
            $data['asked_question_ids'] = [];
        }
        foreach ($newSymptomCodes as $code) {
            if ($code !== '' && !in_array($code, $data['symptoms'], true)) {
                $data['symptoms'][] = $code;
            }
        }
        if (!in_array($questionId, $data['asked_question_ids'], true)) {
            $data['asked_question_ids'][] = $questionId;
        }
        $data['last_question_id'] = $questionId;
        $data['last_answer'] = $answerValue;

        $upd = $this->pdo->prepare(
            "UPDATE triage_sessions SET triage_data = :d, updated_at = NOW() WHERE id = :id"
        );
        $upd->execute([
            ':d'  => json_encode($data, JSON_UNESCAPED_UNICODE),
            ':id' => $sessionId,
        ]);
    }

    /**
     * ดึง symptom codes ที่สะสมไว้ใน session.
     *
     * @return list<string>
     */
    public function getCollectedSymptoms(int $sessionId): array
    {
        $stmt = $this->pdo->prepare("SELECT triage_data FROM triage_sessions WHERE id = :id");
        $stmt->execute([':id' => $sessionId]);
        $raw = $stmt->fetchColumn();
        if (!is_string($raw)) {
            return [];
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['symptoms']) || !is_array($data['symptoms'])) {
            return [];
        }
        return array_values(array_filter(array_map('strval', $data['symptoms']), static fn($v) => $v !== ''));
    }

    /**
     * ดึงรายการ question_id ที่ถามไปแล้ว (ป้องกันถามซ้ำ)
     *
     * @return list<int>
     */
    public function getAskedQuestionIds(int $sessionId): array
    {
        $stmt = $this->pdo->prepare("SELECT triage_data FROM triage_sessions WHERE id = :id");
        $stmt->execute([':id' => $sessionId]);
        $raw = $stmt->fetchColumn();
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data) || empty($data['asked_question_ids']) || !is_array($data['asked_question_ids'])) {
            return [];
        }
        return array_values(array_map('intval', $data['asked_question_ids']));
    }

    /**
     * ปิด session แบบ escalate (red flag).
     */
    public function escalate(int $sessionId, string $redFlagMessage): void
    {
        $upd = $this->pdo->prepare(
            "UPDATE triage_sessions
             SET status = 'escalated',
                 triage_level = 'red',
                 outcome = 'refer_doctor',
                 red_flags_detected = :flags,
                 ai_recommendation = :msg,
                 completed_at = NOW(),
                 updated_at = NOW()
             WHERE id = :id"
        );
        $upd->execute([
            ':id'    => $sessionId,
            ':flags' => json_encode([['message' => $redFlagMessage]], JSON_UNESCAPED_UNICODE),
            ':msg'   => mb_substr($redFlagMessage, 0, 2000),
        ]);
    }

    /**
     * ปิด session แบบ completed พร้อม outcome.
     */
    public function complete(int $sessionId, string $outcome, string $aiRecommendation = ''): void
    {
        $allowed = ['self_care', 'otc_recommended', 'refer_doctor', 'emergency'];
        if (!in_array($outcome, $allowed, true)) {
            $outcome = 'self_care';
        }
        $upd = $this->pdo->prepare(
            "UPDATE triage_sessions
             SET status = 'completed',
                 outcome = :out,
                 ai_recommendation = :rec,
                 completed_at = NOW(),
                 updated_at = NOW()
             WHERE id = :id"
        );
        $upd->execute([
            ':id'  => $sessionId,
            ':out' => $outcome,
            ':rec' => mb_substr($aiRecommendation, 0, 2000),
        ]);
    }

    /**
     * นับจำนวนคำถามที่ถามไปแล้วใน session — ใช้ enforce max_questions_per_session.
     */
    public function countQuestionsAsked(int $sessionId): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM triage_question_responses WHERE triage_session_id = :id"
        );
        $stmt->execute([':id' => $sessionId]);
        return (int) $stmt->fetchColumn();
    }
}
