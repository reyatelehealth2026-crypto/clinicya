<?php
/**
 * TriageEngine - ระบบซักประวัติอัจฉริยะสำหรับเภสัชออนไลน์
 * Version 2.0 - Professional Pharmacy Triage System
 * 
 * Features:
 * - State Machine สำหรับการซักประวัติเป็นขั้นตอน
 * - Red Flag Detection (อาการฉุกเฉิน)
 * - Drug Interaction Check
 * - Smart Recommendation
 */

namespace Modules\AIChat\Services;

use Modules\Core\Database;

class TriageEngine
{
    private $db;
    private ?int $lineAccountId;
    private ?int $userId;
    private array $currentState;
    private RedFlagDetector $redFlagDetector;
    
    // Triage States
    public const STATE_GREETING = 'greeting';
    public const STATE_SYMPTOM = 'symptom';
    public const STATE_DURATION = 'duration';
    public const STATE_SEVERITY = 'severity';
    public const STATE_ASSOCIATED = 'associated';
    public const STATE_ALLERGY = 'allergy';
    public const STATE_MEDICAL_HISTORY = 'medical_history';
    public const STATE_CURRENT_MEDS = 'current_meds';
    public const STATE_RECOMMEND = 'recommend';
    public const STATE_CONFIRM = 'confirm';
    public const STATE_ESCALATE = 'escalate';
    public const STATE_COMPLETE = 'complete';

    // State Flow Configuration
    private const STATE_FLOW = [
        self::STATE_GREETING => self::STATE_SYMPTOM,
        self::STATE_SYMPTOM => self::STATE_DURATION,
        self::STATE_DURATION => self::STATE_SEVERITY,
        self::STATE_SEVERITY => self::STATE_ASSOCIATED,
        self::STATE_ASSOCIATED => self::STATE_ALLERGY,
        self::STATE_ALLERGY => self::STATE_MEDICAL_HISTORY,
        self::STATE_MEDICAL_HISTORY => self::STATE_CURRENT_MEDS,
        self::STATE_CURRENT_MEDS => self::STATE_RECOMMEND,
        self::STATE_RECOMMEND => self::STATE_CONFIRM,
        self::STATE_CONFIRM => self::STATE_COMPLETE,
    ];
    
    // Questions for each state
    private const STATE_QUESTIONS = [
        self::STATE_SYMPTOM => 'มีอาการอะไรคะ? บอกอาการหลักที่รู้สึกได้เลยค่ะ 🩺',
        self::STATE_DURATION => 'เป็นมานานแค่ไหนแล้วคะ? (เช่น 2 วัน, 1 สัปดาห์)',
        self::STATE_SEVERITY => 'อาการรุนแรงแค่ไหนคะ? (1-10 โดย 10 คือรุนแรงมาก)',
        self::STATE_ASSOCIATED => 'มีอาการอื่นร่วมด้วยไหมคะ? เช่น ไข้ คลื่นไส้ อ่อนเพลีย',
        self::STATE_ALLERGY => 'แพ้ยาอะไรไหมคะ? ถ้าไม่แพ้พิมพ์ "ไม่แพ้" ได้เลยค่ะ',
        self::STATE_MEDICAL_HISTORY => 'มีโรคประจำตัวไหมคะ? เช่น เบาหวาน ความดัน หอบหืด (ถ้าไม่มีพิมพ์ "ไม่มี")',
        self::STATE_CURRENT_MEDS => 'ตอนนี้ทานยาอะไรอยู่บ้างคะ? (ถ้าไม่มีพิมพ์ "ไม่มี")',
    ];
    
    // Skip keywords
    private const SKIP_KEYWORDS = ['ไม่', 'ไม่มี', 'ไม่แพ้', 'ไม่ทาน', 'ไม่ได้', 'ไม่เคย', '-', 'no', 'none', 'skip'];
    
    public function __construct(?int $lineAccountId = null, ?int $userId = null, $db = null)
    {
        // Accept PDO connection or use Database singleton
        if ($db instanceof \PDO) {
            $this->db = new class($db) {
                private \PDO $pdo;
                public function __construct(\PDO $pdo) { $this->pdo = $pdo; }
                public function getConnection(): \PDO { return $this->pdo; }
                public function fetchOne(string $sql, array $params = []) {
                    $stmt = $this->pdo->prepare($sql);
                    $stmt->execute($params);
                    return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
                }
                public function fetchAll(string $sql, array $params = []) {
                    $stmt = $this->pdo->prepare($sql);
                    $stmt->execute($params);
                    return $stmt->fetchAll(\PDO::FETCH_ASSOC);
                }
                public function execute(string $sql, array $params = []) {
                    $stmt = $this->pdo->prepare($sql);
                    return $stmt->execute($params);
                }
                public function lastInsertId() { return $this->pdo->lastInsertId(); }
            };
        } else {
            $this->db = Database::getInstance();
        }
        $this->lineAccountId = $lineAccountId;
        $this->userId = $userId;
        $this->redFlagDetector = new RedFlagDetector();
        $this->loadState();
    }

    /**
     * โหลด state ปัจจุบันของ user
     */
    private function loadState(): void
    {
        $this->currentState = [
            'state' => self::STATE_GREETING,
            'data' => [
                'symptoms' => [],
                'duration' => null,
                'severity' => null,
                'associated_symptoms' => [],
                'allergies' => [],
                'medical_history' => [],
                'current_medications' => [],
                'red_flags' => [],
                'recommendations' => [],
            ],
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        
        if (!$this->userId) return;
        
        try {
            $result = $this->db->fetchOne(
                "SELECT * FROM triage_sessions WHERE user_id = ? AND status = 'active' ORDER BY created_at DESC LIMIT 1",
                [$this->userId]
            );
            
            if ($result) {
                $this->currentState = [
                    'id' => $result['id'],
                    'state' => $result['current_state'],
                    'data' => json_decode($result['triage_data'], true) ?: [],
                    'created_at' => $result['created_at'],
                    'updated_at' => $result['updated_at'],
                ];
            }
        } catch (\Exception $e) {
            // Table might not exist yet
        }
    }
    
    /**
     * บันทึก state
     * Updates status to 'completed' and sets completed_at when session reaches STATE_COMPLETE
     * Updates status to 'escalated' when session reaches STATE_ESCALATE
     */
    private function saveState(): void
    {
        if (!$this->userId) return;
        
        try {
            $data = json_encode($this->currentState['data'], JSON_UNESCAPED_UNICODE);
            $state = $this->currentState['state'];
            $now = date('Y-m-d H:i:s');
            
            if (isset($this->currentState['id'])) {
                // Determine status based on current state
                if ($state === self::STATE_COMPLETE) {
                    // Session completed - update status and set completed_at timestamp
                    $this->db->execute(
                        "UPDATE triage_sessions SET current_state = ?, triage_data = ?, status = 'completed', completed_at = ?, updated_at = ? WHERE id = ?",
                        [$state, $data, $now, $now, $this->currentState['id']]
                    );
                } elseif ($state === self::STATE_ESCALATE) {
                    // Session escalated - update status
                    $this->db->execute(
                        "UPDATE triage_sessions SET current_state = ?, triage_data = ?, status = 'escalated', updated_at = ? WHERE id = ?",
                        [$state, $data, $now, $this->currentState['id']]
                    );
                } else {
                    // Normal state update
                    $this->db->execute(
                        "UPDATE triage_sessions SET current_state = ?, triage_data = ?, updated_at = ? WHERE id = ?",
                        [$state, $data, $now, $this->currentState['id']]
                    );
                }
            } else {
                $this->db->execute(
                    "INSERT INTO triage_sessions (user_id, line_account_id, current_state, triage_data, status, created_at, updated_at) VALUES (?, ?, ?, ?, 'active', ?, ?)",
                    [$this->userId, $this->lineAccountId, $state, $data, $now, $now]
                );
                $this->currentState['id'] = $this->db->lastInsertId();
            }
        } catch (\Exception $e) {
            error_log("TriageEngine saveState error: " . $e->getMessage());
        }
    }

    /**
     * ประมวลผลข้อความจาก user
     */
    public function process(string $message): array
    {
        $message = trim($message);
        $messageLower = mb_strtolower($message);
        
        // ตรวจสอบ Red Flags ก่อนเสมอ
        $redFlags = $this->redFlagDetector->detect($message);
        if (!empty($redFlags)) {
            $this->currentState['data']['red_flags'] = array_merge(
                $this->currentState['data']['red_flags'] ?? [],
                $redFlags
            );
            
            // ถ้าเป็น Critical Red Flag → Escalate ทันที
            if ($this->redFlagDetector->isCritical($redFlags)) {
                return $this->escalate($redFlags);
            }
        }
        
        // ตรวจสอบคำสั่งพิเศษ
        if ($this->isResetCommand($messageLower)) {
            return $this->reset();
        }
        
        if ($this->isSkipCommand($messageLower)) {
            return $this->skipCurrentStep();
        }
        
        // ตรวจสอบว่าเป็น numeric response สำหรับ severity state หรือไม่
        if ($this->currentState['state'] === self::STATE_SEVERITY && $this->isNumericSeverityResponse($message)) {
            return $this->handleSeverity($message);
        }
        
        // ตรวจสอบว่าเป็น duration response สำหรับ duration state หรือไม่
        if ($this->currentState['state'] === self::STATE_DURATION && $this->isDurationResponse($message)) {
            return $this->handleDuration($message);
        }
        
        // ประมวลผลตาม state ปัจจุบัน
        return $this->processState($message);
    }
    
    /**
     * ตรวจสอบว่าข้อความเป็น numeric severity response (1-10) หรือไม่
     */
    public function isNumericSeverityResponse(string $message): bool
    {
        $message = trim($message);
        
        // First, check if this is a duration response - if so, it's NOT a severity response
        if ($this->isDurationResponse($message)) {
            return false;
        }
        
        // ตรวจสอบว่าเป็นตัวเลข 1-10 โดยตรง
        if (preg_match('/^(\d+)$/', $message, $matches)) {
            $num = (int)$matches[1];
            return $num >= 1 && $num <= 10;
        }
        
        // ตรวจสอบว่ามีตัวเลขในข้อความ (but not negative numbers)
        if (preg_match('/(?<![0-9-])(\d+)(?![0-9])/', $message, $matches)) {
            $num = (int)$matches[1];
            return $num >= 1 && $num <= 10;
        }
        
        // ตรวจสอบคำบอกความรุนแรง
        $severityWords = ['เล็กน้อย', 'นิดหน่อย', 'ไม่มาก', 'ปานกลาง', 'พอทน', 'มาก', 'รุนแรง', 'มากๆ', 'ทนไม่ไหว', 'รุนแรงมาก'];
        foreach ($severityWords as $word) {
            if (mb_strpos($message, $word) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * ตรวจสอบว่าข้อความเป็น duration response หรือไม่
     */
    public function isDurationResponse(string $message): bool
    {
        $message = trim($message);
        
        // ตรวจสอบ pattern: X วัน, X สัปดาห์, X ชั่วโมง, X เดือน
        if (preg_match('/(\d+)\s*(วัน|สัปดาห์|อาทิตย์|ชั่วโมง|เดือน|ปี)/u', $message)) {
            return true;
        }
        
        // ตรวจสอบคำบอกเวลา
        $timeWords = ['เมื่อวาน', 'วันนี้', 'เมื่อกี้', 'ตอนเช้า', 'ตอนบ่าย', 'ตอนเย็น', 'ตอนดึก', 'เมื่อคืน', 'สักครู่', 'นานแล้ว'];
        foreach ($timeWords as $word) {
            if (mb_strpos($message, $word) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * ประมวลผลตาม state
     */
    private function processState(string $message): array
    {
        $state = $this->currentState['state'];
        
        // Check if session is active - if so, don't reset to greeting
        if ($this->isActiveSession() && $state === self::STATE_GREETING) {
            // Session is active but state is greeting - this shouldn't happen
            // Continue from the last known active state
            return $this->continueFromCurrentState($message);
        }
        
        switch ($state) {
            case self::STATE_GREETING:
                return $this->handleGreeting($message);
                
            case self::STATE_SYMPTOM:
                return $this->handleSymptom($message);
                
            case self::STATE_DURATION:
                return $this->handleDuration($message);
                
            case self::STATE_SEVERITY:
                return $this->handleSeverity($message);
                
            case self::STATE_ASSOCIATED:
                return $this->handleAssociated($message);
                
            case self::STATE_ALLERGY:
                return $this->handleAllergy($message);
                
            case self::STATE_MEDICAL_HISTORY:
                return $this->handleMedicalHistory($message);
                
            case self::STATE_CURRENT_MEDS:
                return $this->handleCurrentMeds($message);
                
            case self::STATE_RECOMMEND:
                return $this->handleRecommend($message);
                
            case self::STATE_CONFIRM:
                return $this->handleConfirm($message);
                
            case self::STATE_COMPLETE:
            case self::STATE_ESCALATE:
                // Session จบแล้ว - ถ้ามีข้อความใหม่ให้เริ่มใหม่
                return $this->reset();
                
            default:
                return $this->handleGreeting($message);
        }
    }
    
    /**
     * Check if the current session is active (not complete or escalate)
     */
    public function isActiveSession(): bool
    {
        $state = $this->currentState['state'] ?? self::STATE_GREETING;
        
        // Active states are all states except greeting, complete, and escalate
        $activeStates = [
            self::STATE_SYMPTOM,
            self::STATE_DURATION,
            self::STATE_SEVERITY,
            self::STATE_ASSOCIATED,
            self::STATE_ALLERGY,
            self::STATE_MEDICAL_HISTORY,
            self::STATE_CURRENT_MEDS,
            self::STATE_RECOMMEND,
            self::STATE_CONFIRM,
        ];
        
        return in_array($state, $activeStates);
    }
    
    /**
     * Continue from current state without resetting
     */
    private function continueFromCurrentState(string $message): array
    {
        // Get the current state and continue processing
        $state = $this->currentState['state'];
        
        // If we have data, continue from where we left off
        if (!empty($this->currentState['data']['symptoms'])) {
            if (empty($this->currentState['data']['duration'])) {
                $this->currentState['state'] = self::STATE_DURATION;
                return $this->handleDuration($message);
            }
            if (empty($this->currentState['data']['severity'])) {
                $this->currentState['state'] = self::STATE_SEVERITY;
                return $this->handleSeverity($message);
            }
        }
        
        // Default: process as symptom
        return $this->handleSymptom($message);
    }

    /**
     * Handle Greeting State
     * Prevents greeting during active session (Requirements 9.1, 9.5)
     */
    private function handleGreeting(string $message): array
    {
        // Check if there's an active session - if so, don't greet
        if ($this->hasActiveSessionInDb()) {
            // Load the active session and continue from current state
            $this->loadState();
            if ($this->isActiveSession()) {
                return $this->continueFromCurrentState($message);
            }
        }
        
        // ตรวจสอบว่ามีอาการในข้อความแรกหรือไม่
        $symptoms = $this->extractSymptoms($message);
        
        if (!empty($symptoms)) {
            $this->currentState['data']['symptoms'] = $symptoms;
            $this->currentState['state'] = self::STATE_DURATION;
            $this->saveState();
            
            $symptomText = implode(', ', $symptoms);
            return $this->buildResponse(
                "เข้าใจค่ะ คุณมีอาการ {$symptomText} 📝\n\n" . self::STATE_QUESTIONS[self::STATE_DURATION],
                self::STATE_DURATION
            );
        }
        
        $this->currentState['state'] = self::STATE_SYMPTOM;
        $this->saveState();
        
        return $this->buildResponse(
            "สวัสดีค่ะ 👋 ยินดีให้บริการค่ะ\n\nดิฉันเป็นผู้ช่วยเภสัชกร พร้อมช่วยประเมินอาการและแนะนำยาเบื้องต้นค่ะ\n\n" . self::STATE_QUESTIONS[self::STATE_SYMPTOM],
            self::STATE_SYMPTOM,
            $this->getSymptomQuickReplies()
        );
    }
    
    /**
     * Check if there's an active session in database
     */
    private function hasActiveSessionInDb(): bool
    {
        if (!$this->userId) return false;
        
        try {
            $result = $this->db->fetchOne(
                "SELECT id, current_state FROM triage_sessions WHERE user_id = ? AND status = 'active' ORDER BY created_at DESC LIMIT 1",
                [$this->userId]
            );
            
            if ($result) {
                $state = $result['current_state'];
                // Check if state is active (not greeting, complete, or escalate)
                return !in_array($state, [self::STATE_GREETING, self::STATE_COMPLETE, self::STATE_ESCALATE]);
            }
        } catch (\Exception $e) {
            // Table might not exist yet
        }
        
        return false;
    }
    
    /**
     * Handle Symptom State
     */
    private function handleSymptom(string $message): array
    {
        $symptoms = $this->extractSymptoms($message);
        
        if (empty($symptoms)) {
            // ถ้าไม่พบอาการ ให้ใช้ข้อความทั้งหมดเป็นอาการ
            $symptoms = [$message];
        }
        
        $this->currentState['data']['symptoms'] = $symptoms;
        $this->currentState['state'] = self::STATE_DURATION;
        $this->saveState();
        
        $symptomText = implode(', ', $symptoms);
        return $this->buildResponse(
            "รับทราบค่ะ อาการ: {$symptomText} 📝\n\n" . self::STATE_QUESTIONS[self::STATE_DURATION],
            self::STATE_DURATION,
            $this->getDurationQuickReplies()
        );
    }
    
    /**
     * Handle Duration State
     * Interprets duration responses (วัน, สัปดาห์, เดือน patterns)
     */
    private function handleDuration(string $message): array
    {
        $duration = $this->extractDuration($message);
        $this->currentState['data']['duration'] = $duration ?: $message;
        $this->currentState['state'] = self::STATE_SEVERITY;
        $this->saveState();
        
        // Acknowledge the duration response explicitly
        $durationValue = $this->currentState['data']['duration'];
        $acknowledgment = "รับทราบค่ะ เป็นมา {$durationValue} ⏱️\n\n";
        
        return $this->buildResponse(
            $acknowledgment . self::STATE_QUESTIONS[self::STATE_SEVERITY],
            self::STATE_SEVERITY,
            $this->getSeverityQuickReplies()
        );
    }
    
    /**
     * Parse duration value from message and return structured data
     */
    public function parseDurationValue(string $message): ?array
    {
        // Pattern: X วัน, X สัปดาห์, X ชั่วโมง
        if (preg_match('/(\d+)\s*(วัน|สัปดาห์|อาทิตย์|ชั่วโมง|เดือน|ปี)/u', $message, $matches)) {
            $value = (int)$matches[1];
            $unit = $matches[2];
            
            // Normalize unit
            $unitMap = [
                'วัน' => 'day',
                'สัปดาห์' => 'week',
                'อาทิตย์' => 'week',
                'ชั่วโมง' => 'hour',
                'เดือน' => 'month',
                'ปี' => 'year'
            ];
            
            return [
                'value' => $value,
                'unit' => $unitMap[$unit] ?? $unit,
                'original' => $matches[0]
            ];
        }
        
        // Pattern: เมื่อวาน, วันนี้, etc.
        $timeWords = [
            'เมื่อวาน' => ['value' => 1, 'unit' => 'day'],
            'วันนี้' => ['value' => 0, 'unit' => 'day'],
            'เมื่อกี้' => ['value' => 0, 'unit' => 'hour'],
            'เมื่อคืน' => ['value' => 1, 'unit' => 'day'],
            'สักครู่' => ['value' => 0, 'unit' => 'hour'],
            'นานแล้ว' => ['value' => 7, 'unit' => 'day']
        ];
        
        foreach ($timeWords as $word => $data) {
            if (mb_strpos($message, $word) !== false) {
                return array_merge($data, ['original' => $word]);
            }
        }
        
        return null;
    }
    
    /**
     * Handle Severity State
     * Interprets numeric responses (1-10) as severity values
     */
    private function handleSeverity(string $message): array
    {
        $severity = $this->extractSeverity($message);
        $this->currentState['data']['severity'] = $severity;
        $this->currentState['state'] = self::STATE_ASSOCIATED;
        $this->saveState();
        
        $severityText = $this->getSeverityText($severity);
        
        // Acknowledge the numeric response explicitly
        $acknowledgment = "รับทราบค่ะ ";
        if ($severity >= 7) {
            $acknowledgment .= "ความรุนแรงระดับ {$severity} ถือว่าค่อนข้างมากนะคะ 😟\n\n";
        } elseif ($severity >= 4) {
            $acknowledgment .= "ความรุนแรงระดับ {$severity} ค่ะ 📊\n\n";
        } else {
            $acknowledgment .= "ความรุนแรงระดับ {$severity} ถือว่าไม่มากค่ะ 😊\n\n";
        }
        
        return $this->buildResponse(
            $acknowledgment . self::STATE_QUESTIONS[self::STATE_ASSOCIATED],
            self::STATE_ASSOCIATED,
            $this->getAssociatedQuickReplies()
        );
    }

    /**
     * Handle Associated Symptoms State
     */
    private function handleAssociated(string $message): array
    {
        if (!$this->isSkipAnswer($message)) {
            $associated = $this->extractSymptoms($message);
            $this->currentState['data']['associated_symptoms'] = !empty($associated) ? $associated : [$message];
        }
        
        // ตรวจสอบว่ามีข้อมูลแพ้ยาในระบบหรือไม่
        $userAllergies = $this->getUserAllergies();
        if (!empty($userAllergies)) {
            $this->currentState['data']['allergies'] = $userAllergies;
            $this->currentState['state'] = self::STATE_MEDICAL_HISTORY;
            $this->saveState();
            
            return $this->buildResponse(
                "จากข้อมูลในระบบ คุณแพ้ยา: " . implode(', ', $userAllergies) . " ✅\n\n" . self::STATE_QUESTIONS[self::STATE_MEDICAL_HISTORY],
                self::STATE_MEDICAL_HISTORY,
                $this->getMedicalHistoryQuickReplies()
            );
        }
        
        $this->currentState['state'] = self::STATE_ALLERGY;
        $this->saveState();
        
        return $this->buildResponse(
            "รับทราบค่ะ 📝\n\n" . self::STATE_QUESTIONS[self::STATE_ALLERGY],
            self::STATE_ALLERGY,
            $this->getAllergyQuickReplies()
        );
    }
    
    /**
     * Handle Allergy State
     */
    private function handleAllergy(string $message): array
    {
        if (!$this->isSkipAnswer($message)) {
            $this->currentState['data']['allergies'] = $this->extractAllergies($message);
            
            // บันทึกลง user profile ด้วย
            $this->saveUserAllergies($this->currentState['data']['allergies']);
        }
        
        $this->currentState['state'] = self::STATE_MEDICAL_HISTORY;
        $this->saveState();
        
        return $this->buildResponse(
            "บันทึกข้อมูลแพ้ยาแล้วค่ะ 💊\n\n" . self::STATE_QUESTIONS[self::STATE_MEDICAL_HISTORY],
            self::STATE_MEDICAL_HISTORY,
            $this->getMedicalHistoryQuickReplies()
        );
    }
    
    /**
     * Handle Medical History State
     */
    private function handleMedicalHistory(string $message): array
    {
        if (!$this->isSkipAnswer($message)) {
            $medicalHistory = $this->extractMedicalConditions($message);
            $this->currentState['data']['medical_history'] = $medicalHistory;
            
            // Check for high-risk conditions combined with severity
            $severity = $this->currentState['data']['severity'] ?? 0;
            $highRiskConditions = ['โรคหัวใจ', 'หัวใจ', 'heart', 'cardiac', 'หลอดเลือดสมอง', 'stroke', 'เบาหวาน', 'diabetes'];
            $hasHighRiskCondition = false;
            $matchedCondition = '';
            
            $medicalHistoryLower = mb_strtolower(is_array($medicalHistory) ? implode(' ', $medicalHistory) : $medicalHistory);
            
            foreach ($highRiskConditions as $condition) {
                if (mb_strpos($medicalHistoryLower, mb_strtolower($condition)) !== false) {
                    $hasHighRiskCondition = true;
                    $matchedCondition = $condition;
                    break;
                }
            }
            
            // If high-risk condition + moderate-high severity, add red flag
            if ($hasHighRiskCondition && $severity >= 5) {
                $redFlags = $this->currentState['data']['red_flags'] ?? [];
                $redFlags[] = [
                    'message' => "ผู้ป่วยมีโรคประจำตัว ({$matchedCondition}) ร่วมกับอาการรุนแรงระดับ {$severity}/10",
                    'severity' => 'critical',
                    'action' => '🚨 ควรติดต่อผู้ป่วยโดยด่วน หรือแนะนำให้ไปพบแพทย์'
                ];
                $this->currentState['data']['red_flags'] = $redFlags;
                
                // Escalate to pharmacist immediately
                $this->notifyPharmacist(true);
                
                // Return warning message
                $this->currentState['state'] = self::STATE_CURRENT_MEDS;
                $this->saveState();
                
                return $this->buildResponse(
                    "⚠️ พบข้อมูลสำคัญ!\n\n" .
                    "คุณมีโรคประจำตัว ({$matchedCondition}) ร่วมกับอาการที่รุนแรง\n" .
                    "เภสัชกรจะติดต่อกลับโดยเร็วค่ะ\n\n" .
                    "🚨 หากอาการรุนแรงมาก กรุณาโทร 1669 หรือไปโรงพยาบาลทันที\n\n" .
                    self::STATE_QUESTIONS[self::STATE_CURRENT_MEDS],
                    self::STATE_CURRENT_MEDS,
                    $this->getCurrentMedsQuickReplies()
                );
            }
        }
        
        $this->currentState['state'] = self::STATE_CURRENT_MEDS;
        $this->saveState();
        
        return $this->buildResponse(
            "รับทราบค่ะ 📋\n\n" . self::STATE_QUESTIONS[self::STATE_CURRENT_MEDS],
            self::STATE_CURRENT_MEDS,
            $this->getCurrentMedsQuickReplies()
        );
    }
    
    /**
     * Handle Current Medications State
     */
    private function handleCurrentMeds(string $message): array
    {
        if (!$this->isSkipAnswer($message)) {
            $this->currentState['data']['current_medications'] = $this->extractMedications($message);
        }
        
        // ซักประวัติครบแล้ว → สร้างคำแนะนำ
        return $this->generateRecommendation();
    }

    /**
     * สร้างคำแนะนำยา
     */
    private function generateRecommendation(): array
    {
        $data = $this->currentState['data'];
        
        // ค้นหายาที่เหมาะสม
        $recommendations = $this->findSuitableMedications($data);
        
        // ตรวจสอบ Drug Interactions
        $interactions = $this->checkDrugInteractions(
            $recommendations,
            $data['current_medications'] ?? [],
            $data['allergies'] ?? []
        );
        
        // กรองยาที่มีปัญหา
        $safeRecommendations = $this->filterSafeRecommendations($recommendations, $interactions);
        
        $this->currentState['data']['recommendations'] = $safeRecommendations;
        $this->currentState['data']['interactions'] = $interactions;
        $this->currentState['state'] = self::STATE_RECOMMEND;
        $this->saveState();
        
        // สร้างข้อความแนะนำ
        return $this->buildRecommendationResponse($safeRecommendations, $interactions, $data);
    }
    
    /**
     * ค้นหายาที่เหมาะสม
     */
    private function findSuitableMedications(array $data): array
    {
        $symptoms = $data['symptoms'] ?? [];
        $severity = $data['severity'] ?? 5;
        
        try {
            // Map อาการ → หมวดยา
            $categories = $this->mapSymptomsToCategories($symptoms);
            
            if (empty($categories)) {
                return [];
            }
            
            $placeholders = implode(',', array_fill(0, count($categories), '?'));
            
            // ใช้ business_items table - ไม่ filter ตาม line_account_id
            $sql = "SELECT p.*, 
                           COALESCE(p.generic_name, '') as generic_name,
                           COALESCE(p.usage_instructions, '') as usage_instructions
                    FROM business_items p
                    WHERE p.is_active = 1 
                    AND (p.name LIKE CONCAT('%', ?, '%') OR p.description LIKE CONCAT('%', ?, '%'))
                    ORDER BY p.name ASC
                    LIMIT 5";
            
            // ค้นหาตาม keywords แทน category
            $searchTerms = implode(' ', $categories);
            return $this->db->fetchAll($sql, [$searchTerms, $searchTerms]);
        } catch (\Exception $e) {
            error_log("findSuitableMedications error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Map อาการ → หมวดยา
     */
    private function mapSymptomsToCategories(array $symptoms): array
    {
        $categoryMap = [
            'ปวดหัว' => ['แก้ปวด', 'ยาแก้ปวด', 'pain_relief'],
            'ปวดศีรษะ' => ['แก้ปวด', 'ยาแก้ปวด', 'pain_relief'],
            'ไข้' => ['ลดไข้', 'ยาลดไข้', 'fever'],
            'หวัด' => ['แก้หวัด', 'ยาแก้หวัด', 'cold'],
            'ไอ' => ['แก้ไอ', 'ยาแก้ไอ', 'cough'],
            'เจ็บคอ' => ['แก้เจ็บคอ', 'ยาอม', 'sore_throat'],
            'ท้องเสีย' => ['ท้องเสีย', 'ยาท้องเสีย', 'diarrhea'],
            'ท้องผูก' => ['ยาระบาย', 'laxative'],
            'แพ้' => ['แก้แพ้', 'ยาแก้แพ้', 'antihistamine'],
            'คัน' => ['แก้แพ้', 'ยาทาแก้คัน', 'antihistamine'],
            'กรดไหลย้อน' => ['ลดกรด', 'ยาลดกรด', 'antacid'],
            'ปวดท้อง' => ['ยาลดกรด', 'แก้ปวดท้อง', 'stomach'],
            'ปวดกล้ามเนื้อ' => ['คลายกล้ามเนื้อ', 'แก้ปวด', 'muscle'],
            'นอนไม่หลับ' => ['ช่วยนอนหลับ', 'sleep'],
            'เครียด' => ['วิตามิน', 'supplement'],
        ];
        
        $categories = [];
        foreach ($symptoms as $symptom) {
            $symptomLower = mb_strtolower($symptom);
            foreach ($categoryMap as $key => $cats) {
                if (mb_strpos($symptomLower, $key) !== false) {
                    $categories = array_merge($categories, $cats);
                }
            }
        }
        
        return array_unique($categories);
    }

    /**
     * ตรวจสอบ Drug Interactions
     */
    private function checkDrugInteractions(array $recommendations, array $currentMeds, array $allergies): array
    {
        $interactions = [];
        
        foreach ($recommendations as $drug) {
            $genericName = mb_strtolower($drug['generic_name'] ?? '');
            $drugName = mb_strtolower($drug['name'] ?? '');
            
            // ตรวจสอบแพ้ยา
            foreach ($allergies as $allergy) {
                $allergyLower = mb_strtolower($allergy);
                if (mb_strpos($genericName, $allergyLower) !== false || 
                    mb_strpos($drugName, $allergyLower) !== false) {
                    $interactions[] = [
                        'type' => 'allergy',
                        'severity' => 'high',
                        'drug' => $drug['name'],
                        'message' => "⚠️ คุณแพ้ยา {$allergy} - ห้ามใช้ {$drug['name']}"
                    ];
                }
            }
            
            // ตรวจสอบยาตีกัน (Basic)
            foreach ($currentMeds as $currentMed) {
                $interaction = $this->checkInteraction($drug, $currentMed);
                if ($interaction) {
                    $interactions[] = $interaction;
                }
            }
        }
        
        return $interactions;
    }
    
    /**
     * ตรวจสอบยาตีกัน
     */
    private function checkInteraction(array $drug, string $currentMed): ?array
    {
        // Drug Interaction Database (Basic)
        $interactionDb = [
            'warfarin' => ['aspirin', 'ibuprofen', 'naproxen', 'แอสไพริน', 'ไอบูโพรเฟน'],
            'metformin' => ['alcohol', 'แอลกอฮอล์'],
            'aspirin' => ['ibuprofen', 'warfarin', 'ไอบูโพรเฟน', 'วาร์ฟาริน'],
            'paracetamol' => ['alcohol', 'แอลกอฮอล์'],
        ];
        
        $drugGeneric = mb_strtolower($drug['generic_name'] ?? '');
        $currentMedLower = mb_strtolower($currentMed);
        
        foreach ($interactionDb as $med1 => $interactsWith) {
            if (mb_strpos($drugGeneric, $med1) !== false || mb_strpos($currentMedLower, $med1) !== false) {
                foreach ($interactsWith as $med2) {
                    if (mb_strpos($drugGeneric, $med2) !== false || mb_strpos($currentMedLower, $med2) !== false) {
                        return [
                            'type' => 'interaction',
                            'severity' => 'medium',
                            'drug' => $drug['name'],
                            'interacts_with' => $currentMed,
                            'message' => "⚠️ {$drug['name']} อาจตีกับ {$currentMed} - ควรปรึกษาเภสัชกร"
                        ];
                    }
                }
            }
        }
        
        return null;
    }
    
    /**
     * กรองยาที่ปลอดภัย
     */
    private function filterSafeRecommendations(array $recommendations, array $interactions): array
    {
        $unsafeDrugs = [];
        foreach ($interactions as $interaction) {
            if ($interaction['severity'] === 'high') {
                $unsafeDrugs[] = $interaction['drug'];
            }
        }
        
        return array_filter($recommendations, function($drug) use ($unsafeDrugs) {
            return !in_array($drug['name'], $unsafeDrugs);
        });
    }

    /**
     * สร้าง Response แนะนำยา
     */
    private function buildRecommendationResponse(array $recommendations, array $interactions, array $data): array
    {
        $symptoms = implode(', ', $data['symptoms'] ?? []);
        $hasRedFlags = !empty($data['red_flags']);
        
        // Header
        $text = "📋 สรุปการประเมินอาการ\n";
        $text .= "━━━━━━━━━━━━━━━━━━━━\n\n";
        $text .= "🩺 อาการ: {$symptoms}\n";
        $text .= "⏱️ ระยะเวลา: {$data['duration']}\n";
        $text .= "📊 ความรุนแรง: {$this->getSeverityText($data['severity'])}\n\n";
        
        // Red Flags Warning
        if ($hasRedFlags) {
            $text .= "⚠️ พบอาการที่ควรระวัง:\n";
            foreach ($data['red_flags'] as $flag) {
                $text .= "• {$flag['message']}\n";
            }
            $text .= "\n";
        }
        
        // Drug Interactions Warning
        if (!empty($interactions)) {
            $text .= "⚠️ ข้อควรระวัง:\n";
            foreach ($interactions as $interaction) {
                $text .= "• {$interaction['message']}\n";
            }
            $text .= "\n";
        }
        
        // Recommendations
        if (!empty($recommendations)) {
            $text .= "💊 ยาที่แนะนำ:\n";
            $text .= "━━━━━━━━━━━━━━━━━━━━\n";
            
            $i = 1;
            foreach (array_slice($recommendations, 0, 3) as $drug) {
                $text .= "\n{$i}. {$drug['name']}\n";
                $text .= "   💰 ราคา: {$drug['price']} บาท\n";
                if (!empty($drug['usage_instructions'])) {
                    $text .= "   📝 วิธีใช้: {$drug['usage_instructions']}\n";
                }
                $i++;
            }
            
            $text .= "\n━━━━━━━━━━━━━━━━━━━━\n";
            $text .= "⚕️ กรุณารอเภสัชกรยืนยันก่อนใช้ยา\n";
            $text .= "📞 หรือกด 'ปรึกษาเภสัชกร' เพื่อ Video Call";
        } else {
            // วินิจฉัยเบื้องต้นเมื่อไม่มียาในระบบ
            $diagnosis = $this->generatePreliminaryDiagnosis($data);
            $text .= "📝 การวินิจฉัยเบื้องต้น:\n";
            $text .= "━━━━━━━━━━━━━━━━━━━━\n\n";
            $text .= $diagnosis;
            $text .= "\n\n━━━━━━━━━━━━━━━━━━━━\n";
            $text .= "⚕️ นี่เป็นการประเมินเบื้องต้นเท่านั้น\n";
            $text .= "📞 แนะนำปรึกษาเภสัชกรหรือแพทย์เพื่อความแม่นยำ";
        }
        
        return $this->buildResponse(
            $text,
            self::STATE_RECOMMEND,
            $this->getRecommendQuickReplies($recommendations, $hasRedFlags)
        );
    }
    
    /**
     * Handle Recommend State (รอการตอบรับ)
     */
    private function handleRecommend(string $message): array
    {
        $messageLower = mb_strtolower(trim($message));
        
        // ต้องการปรึกษาเภสัชกร
        if (preg_match('/(ปรึกษา|โทร|video|call|เภสัช)/iu', $messageLower)) {
            return $this->escalateToPharmacist();
        }
        
        // ต้องการสั่งซื้อ
        if (preg_match('/(สั่ง|ซื้อ|เอา|order)/iu', $messageLower)) {
            return $this->proceedToOrder();
        }
        
        // ตรวจสอบว่าเป็นอาการใหม่หรือไม่ - ถ้าใช่ให้เริ่มใหม่
        $symptoms = $this->extractSymptoms($message);
        if (!empty($symptoms)) {
            // พบอาการใหม่ → reset และเริ่มซักประวัติใหม่
            return $this->reset();
        }
        
        // ตรวจสอบคำถามทั่วไปเกี่ยวกับยา/สุขภาพ - ขยาย pattern
        $healthKeywords = [
            'ยา', 'แนะนำ', 'รักษา', 'อาการ', 'เจ็บ', 'ปวด', 'ไข้', 'หวัด', 'ไอ', 
            'ท้อง', 'แพ้', 'คัน', 'ข้อมูล', 'ถาม', 'สอบถาม', 'ช่วย', 'mims',
            'วิตามิน', 'อาหารเสริม', 'ครีม', 'โลชั่น', 'สบู่', 'แชมพู'
        ];
        
        foreach ($healthKeywords as $keyword) {
            if (mb_strpos($messageLower, $keyword) !== false) {
                // เป็นคำถามเกี่ยวกับสุขภาพ → เริ่มซักประวัติใหม่
                return $this->reset();
            }
        }
        
        // ถ้าข้อความยาวกว่า 5 ตัวอักษร น่าจะเป็นคำถามใหม่
        if (mb_strlen($message) > 5) {
            return $this->reset();
        }
        
        // ถามเพิ่มเติม
        return $this->buildResponse(
            "ต้องการให้ช่วยอะไรเพิ่มเติมคะ?\n\n• พิมพ์ 'สั่งซื้อ' เพื่อสั่งยา\n• พิมพ์ 'ปรึกษาเภสัชกร' เพื่อ Video Call\n• พิมพ์ 'เริ่มใหม่' เพื่อประเมินอาการใหม่\n• หรือบอกอาการใหม่ได้เลยค่ะ",
            self::STATE_RECOMMEND,
            $this->getRecommendQuickReplies($this->currentState['data']['recommendations'] ?? [], false)
        );
    }
    
    /**
     * Handle Confirm State
     */
    private function handleConfirm(string $message): array
    {
        $messageLower = mb_strtolower(trim($message));
        
        if (preg_match('/(ยืนยัน|ตกลง|ok|yes|ใช่)/iu', $messageLower)) {
            $this->currentState['state'] = self::STATE_COMPLETE;
            $this->saveState();
            
            return $this->buildResponse(
                "✅ บันทึกเรียบร้อยค่ะ\n\nเภสัชกรจะตรวจสอบและติดต่อกลับโดยเร็วค่ะ 🙏",
                self::STATE_COMPLETE
            );
        }
        
        // ตรวจสอบว่าเป็นอาการใหม่หรือไม่
        $symptoms = $this->extractSymptoms($message);
        if (!empty($symptoms)) {
            return $this->reset();
        }
        
        // ตรวจสอบคำถามทั่วไปเกี่ยวกับยา/สุขภาพ - ขยาย pattern
        $healthKeywords = [
            'ยา', 'แนะนำ', 'รักษา', 'อาการ', 'เจ็บ', 'ปวด', 'ไข้', 'หวัด', 'ไอ', 
            'ท้อง', 'แพ้', 'คัน', 'ข้อมูล', 'ถาม', 'สอบถาม', 'ช่วย', 'mims'
        ];
        
        foreach ($healthKeywords as $keyword) {
            if (mb_strpos($messageLower, $keyword) !== false) {
                return $this->reset();
            }
        }
        
        // ถ้าข้อความยาวกว่า 5 ตัวอักษร น่าจะเป็นคำถามใหม่
        if (mb_strlen($message) > 5) {
            return $this->reset();
        }
        
        return $this->buildResponse(
            "ต้องการแก้ไขข้อมูลส่วนไหนคะ? หรือพิมพ์ 'เริ่มใหม่' เพื่อประเมินอาการใหม่\nหรือบอกอาการใหม่ได้เลยค่ะ",
            self::STATE_CONFIRM
        );
    }

    /**
     * Escalate to Pharmacist
     */
    private function escalateToPharmacist(): array
    {
        $this->currentState['state'] = self::STATE_ESCALATE;
        $this->saveState();
        
        // สร้าง notification ให้เภสัชกร
        $this->notifyPharmacist();
        
        return $this->buildResponse(
            "📞 กำลังเชื่อมต่อกับเภสัชกร...\n\nเภสัชกรจะติดต่อกลับภายใน 5-10 นาทีค่ะ\n\nหรือกดปุ่มด้านล่างเพื่อ Video Call ทันที 👇",
            self::STATE_ESCALATE,
            $this->getEscalateQuickReplies()
        );
    }
    
    /**
     * Escalate เมื่อพบ Red Flags
     */
    private function escalate(array $redFlags): array
    {
        $this->currentState['state'] = self::STATE_ESCALATE;
        $this->currentState['data']['red_flags'] = $redFlags;
        $this->saveState();
        
        // แจ้งเตือนเภสัชกรด่วน
        $this->notifyPharmacist(true);
        
        $text = "🚨 พบอาการที่ต้องได้รับการดูแลเป็นพิเศษ\n\n";
        foreach ($redFlags as $flag) {
            $text .= "⚠️ {$flag['message']}\n";
            if (!empty($flag['action'])) {
                $text .= "👉 {$flag['action']}\n";
            }
        }
        $text .= "\n📞 เภสัชกรจะติดต่อกลับโดยด่วนค่ะ";
        
        return $this->buildResponse($text, self::STATE_ESCALATE, $this->getEscalateQuickReplies());
    }
    
    /**
     * Proceed to Order
     */
    private function proceedToOrder(): array
    {
        $recommendations = $this->currentState['data']['recommendations'] ?? [];
        
        if (empty($recommendations)) {
            return $this->buildResponse(
                "❌ ไม่มียาที่แนะนำในระบบ กรุณาปรึกษาเภสัชกรค่ะ",
                self::STATE_RECOMMEND
            );
        }
        
        // สร้าง order draft
        $this->createOrderDraft($recommendations);
        
        $this->currentState['state'] = self::STATE_CONFIRM;
        $this->saveState();
        
        return $this->buildResponse(
            "🛒 เพิ่มยาลงตะกร้าแล้วค่ะ\n\nกดปุ่มด้านล่างเพื่อดำเนินการสั่งซื้อ\n\n⚕️ หมายเหตุ: เภสัชกรจะตรวจสอบก่อนจัดส่ง",
            self::STATE_CONFIRM,
            $this->getOrderQuickReplies()
        );
    }
    
    /**
     * Reset Session
     */
    private function reset(): array
    {
        if (isset($this->currentState['id'])) {
            try {
                $this->db->execute(
                    "UPDATE triage_sessions SET status = 'cancelled' WHERE id = ?",
                    [$this->currentState['id']]
                );
            } catch (\Exception $e) {}
        }
        
        $this->currentState = [
            'state' => self::STATE_GREETING,
            'data' => [],
        ];
        
        return $this->handleGreeting('');
    }
    
    /**
     * Complete the current session programmatically
     * Sets status to 'completed' and completed_at timestamp
     * 
     * @return bool True if session was completed successfully
     */
    public function completeSession(): bool
    {
        if (!isset($this->currentState['id'])) {
            return false;
        }
        
        try {
            $this->currentState['state'] = self::STATE_COMPLETE;
            $this->saveState();
            return true;
        } catch (\Exception $e) {
            error_log("TriageEngine completeSession error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get session completion information
     * 
     * @param int $sessionId The session ID to get info for
     * @return array|null Session info with completion data or null if not found
     */
    public function getSessionCompletionInfo(int $sessionId): ?array
    {
        try {
            $result = $this->db->fetchOne(
                "SELECT id, user_id, current_state, status, created_at, updated_at, completed_at,
                        TIMESTAMPDIFF(MINUTE, created_at, completed_at) as completion_time_minutes
                 FROM triage_sessions WHERE id = ?",
                [$sessionId]
            );
            
            return $result ?: null;
        } catch (\Exception $e) {
            error_log("TriageEngine getSessionCompletionInfo error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get current session ID
     * 
     * @return int|null The current session ID or null if no session
     */
    public function getSessionId(): ?int
    {
        return $this->currentState['id'] ?? null;
    }
    
    /**
     * Get current state
     * 
     * @return string The current triage state
     */
    public function getCurrentState(): string
    {
        return $this->currentState['state'] ?? self::STATE_GREETING;
    }
    
    /**
     * Skip Current Step
     */
    private function skipCurrentStep(): array
    {
        $nextState = self::STATE_FLOW[$this->currentState['state']] ?? self::STATE_RECOMMEND;
        $this->currentState['state'] = $nextState;
        $this->saveState();
        
        if (isset(self::STATE_QUESTIONS[$nextState])) {
            return $this->buildResponse(
                self::STATE_QUESTIONS[$nextState],
                $nextState
            );
        }
        
        return $this->generateRecommendation();
    }

    // ==================== Helper Methods ====================
    
    /**
     * สร้างการวินิจฉัยเบื้องต้นจากอาการ
     */
    private function generatePreliminaryDiagnosis(array $data): string
    {
        $symptoms = $data['symptoms'] ?? [];
        $duration = $data['duration'] ?? '';
        $severity = $data['severity'] ?? 5;
        $associated = $data['associated_symptoms'] ?? [];
        
        $allSymptoms = array_merge($symptoms, $associated);
        $symptomsLower = array_map('mb_strtolower', $allSymptoms);
        $symptomsText = implode(' ', $symptomsLower);
        
        $diagnosis = [];
        $advice = [];
        
        // วินิจฉัยตามกลุ่มอาการ
        
        // ไข้หวัด / ติดเชื้อทางเดินหายใจ
        if ($this->hasAnyKeyword($symptomsText, ['ไข้', 'หวัด', 'ไอ', 'เจ็บคอ', 'น้ำมูก', 'คัดจมูก', 'จาม'])) {
            $diagnosis[] = "🤒 อาการคล้าย: ไข้หวัด / ติดเชื้อทางเดินหายใจส่วนบน";
            $advice[] = "• พักผ่อนให้เพียงพอ นอนหลับอย่างน้อย 8 ชม.";
            $advice[] = "• ดื่มน้ำอุ่นมากๆ วันละ 2-3 ลิตร";
            $advice[] = "• กินอาหารอ่อนๆ ย่อยง่าย";
            $advice[] = "• หลีกเลี่ยงอาหารทอด ของมัน น้ำเย็น";
            if ($severity >= 7) {
                $advice[] = "⚠️ อาการค่อนข้างรุนแรง แนะนำพบแพทย์";
            }
        }
        
        // ปวดหัว / ไมเกรน
        if ($this->hasAnyKeyword($symptomsText, ['ปวดหัว', 'ปวดศีรษะ', 'มึนหัว', 'เวียนหัว', 'ไมเกรน'])) {
            $diagnosis[] = "🤕 อาการคล้าย: ปวดศีรษะจากความเครียด / ไมเกรน";
            $advice[] = "• พักผ่อนในที่เงียบ แสงน้อย";
            $advice[] = "• ประคบเย็นบริเวณหน้าผาก";
            $advice[] = "• หลีกเลี่ยงแสงจ้า เสียงดัง";
            $advice[] = "• นอนหลับให้เพียงพอ";
            if ($severity >= 8) {
                $advice[] = "⚠️ ปวดรุนแรงมาก ควรพบแพทย์";
            }
        }
        
        // ปวดท้อง / ระบบทางเดินอาหาร
        if ($this->hasAnyKeyword($symptomsText, ['ปวดท้อง', 'ท้องเสีย', 'ท้องผูก', 'คลื่นไส้', 'อาเจียน', 'แน่นท้อง', 'จุกเสียด', 'กรดไหลย้อน'])) {
            $diagnosis[] = "🤢 อาการคล้าย: ระบบทางเดินอาหารผิดปกติ";
            $advice[] = "• งดอาหารรสจัด เผ็ด เปรี้ยว";
            $advice[] = "• กินอาหารอ่อนๆ โจ๊ก ข้าวต้ม";
            $advice[] = "• ดื่มน้ำเกลือแร่ทดแทน (ถ้าท้องเสีย)";
            $advice[] = "• หลีกเลี่ยงนม กาแฟ แอลกอฮอล์";
        }
        
        // ปวดกล้ามเนื้อ / ปวดเมื่อย
        if ($this->hasAnyKeyword($symptomsText, ['ปวดกล้ามเนื้อ', 'ปวดเมื่อย', 'ปวดหลัง', 'ปวดคอ', 'ปวดไหล่', 'ปวดข้อ'])) {
            $diagnosis[] = "💪 อาการคล้าย: ปวดกล้ามเนื้อ / กล้ามเนื้ออักเสบ";
            $advice[] = "• ประคบร้อนบริเวณที่ปวด 15-20 นาที";
            $advice[] = "• ยืดเหยียดกล้ามเนื้อเบาๆ";
            $advice[] = "• หลีกเลี่ยงการยกของหนัก";
            $advice[] = "• นอนหมอนที่เหมาะสม";
        }
        
        // แพ้ / ผื่น / คัน
        if ($this->hasAnyKeyword($symptomsText, ['แพ้', 'ผื่น', 'คัน', 'ลมพิษ', 'บวม'])) {
            $diagnosis[] = "🤧 อาการคล้าย: ภูมิแพ้ / ผื่นแพ้";
            $advice[] = "• หลีกเลี่ยงสิ่งที่แพ้ (ถ้าทราบ)";
            $advice[] = "• อาบน้ำเย็น หลีกเลี่ยงน้ำร้อน";
            $advice[] = "• สวมเสื้อผ้าหลวมๆ ผ้าฝ้าย";
            $advice[] = "• อย่าเกา จะทำให้อาการแย่ลง";
        }
        
        // นอนไม่หลับ
        if ($this->hasAnyKeyword($symptomsText, ['นอนไม่หลับ', 'หลับยาก', 'ตื่นกลางดึก'])) {
            $diagnosis[] = "😴 อาการคล้าย: นอนไม่หลับ / Insomnia";
            $advice[] = "• เข้านอน-ตื่นเวลาเดิมทุกวัน";
            $advice[] = "• งดกาแฟ ชา หลังบ่าย 2 โมง";
            $advice[] = "• ไม่ใช้มือถือก่อนนอน 1 ชม.";
            $advice[] = "• ทำห้องนอนให้มืด เงียบ เย็น";
        }
        
        // ถ้าไม่ตรงกับกลุ่มไหนเลย
        if (empty($diagnosis)) {
            $diagnosis[] = "🩺 อาการที่แจ้งมา: " . implode(', ', $symptoms);
            $advice[] = "• พักผ่อนให้เพียงพอ";
            $advice[] = "• ดื่มน้ำมากๆ";
            $advice[] = "• สังเกตอาการต่อเนื่อง";
        }
        
        // เพิ่มคำแนะนำตามระยะเวลา
        if (preg_match('/(\d+)\s*(สัปดาห์|อาทิตย์|เดือน)/u', $duration)) {
            $advice[] = "⏰ อาการเป็นมานาน แนะนำพบแพทย์เพื่อตรวจละเอียด";
        }
        
        // สร้างข้อความ
        $text = implode("\n", $diagnosis);
        $text .= "\n\n💡 คำแนะนำเบื้องต้น:\n";
        $text .= implode("\n", $advice);
        
        return $text;
    }
    
    /**
     * ตรวจสอบว่ามี keyword ใดๆ ในข้อความหรือไม่
     */
    private function hasAnyKeyword(string $text, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if (mb_strpos($text, $keyword) !== false) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Extract symptoms from message
     */
    private function extractSymptoms(string $message): array
    {
        $symptomPatterns = [
            'ปวดหัว', 'ปวดศีรษะ', 'ปวดท้อง', 'ปวดกล้ามเนื้อ', 'ปวดหลัง', 'ปวดคอ',
            'ปวดเมื่อย', 'ปวดข้อ', 'ปวดเข่า', 'ปวดไหล่', 'ปวดแขน', 'ปวดขา',
            'ไข้', 'ไอ', 'เจ็บคอ', 'คัดจมูก', 'น้ำมูก', 'หวัด', 'จาม',
            'ท้องเสีย', 'ท้องผูก', 'คลื่นไส้', 'อาเจียน', 'แน่นท้อง',
            'ผื่น', 'คัน', 'แพ้', 'บวม', 'ลมพิษ',
            'เวียนหัว', 'มึนหัว', 'อ่อนเพลีย', 'เหนื่อย',
            'นอนไม่หลับ', 'หายใจลำบาก', 'แน่นหน้าอก', 'ใจสั่น',
            'กรดไหลย้อน', 'แสบท้อง', 'จุกเสียด', 'เรอเปรี้ยว',
            'ตาแดง', 'ตาอักเสบ', 'คันตา', 'ตาแห้ง',
            'ปวดฟัน', 'เหงือกบวม', 'ร้อนใน', 'แผลในปาก',
        ];
        
        $found = [];
        $messageLower = mb_strtolower($message);
        
        foreach ($symptomPatterns as $symptom) {
            if (mb_strpos($messageLower, $symptom) !== false) {
                $found[] = $symptom;
            }
        }
        
        return $found;
    }
    
    /**
     * Extract duration from message
     */
    private function extractDuration(string $message): ?string
    {
        // Pattern: X วัน, X สัปดาห์, X ชั่วโมง
        if (preg_match('/(\d+)\s*(วัน|สัปดาห์|อาทิตย์|ชั่วโมง|เดือน|ปี)/u', $message, $matches)) {
            return $matches[0];
        }
        
        // Pattern: เมื่อวาน, วันนี้, etc.
        $timeWords = ['เมื่อวาน', 'วันนี้', 'เมื่อกี้', 'ตอนเช้า', 'ตอนบ่าย', 'ตอนเย็น', 'ตอนดึก'];
        foreach ($timeWords as $word) {
            if (mb_strpos($message, $word) !== false) {
                return $word;
            }
        }
        
        return null;
    }
    
    /**
     * Extract severity (1-10)
     */
    private function extractSeverity(string $message): int
    {
        if (preg_match('/(\d+)/', $message, $matches)) {
            $num = (int)$matches[1];
            return min(10, max(1, $num));
        }
        
        // Word-based severity
        $severityWords = [
            'เล็กน้อย' => 2, 'นิดหน่อย' => 2, 'ไม่มาก' => 3,
            'ปานกลาง' => 5, 'พอทน' => 5,
            'มาก' => 7, 'รุนแรง' => 8, 'มากๆ' => 8,
            'ทนไม่ไหว' => 9, 'รุนแรงมาก' => 10,
        ];
        
        foreach ($severityWords as $word => $level) {
            if (mb_strpos($message, $word) !== false) {
                return $level;
            }
        }
        
        return 5; // Default
    }
    
    /**
     * Get severity text
     */
    private function getSeverityText(int $severity): string
    {
        if ($severity <= 3) return "เล็กน้อย ({$severity}/10)";
        if ($severity <= 5) return "ปานกลาง ({$severity}/10)";
        if ($severity <= 7) return "ค่อนข้างมาก ({$severity}/10)";
        return "รุนแรง ({$severity}/10) ⚠️";
    }
    
    /**
     * Extract allergies
     */
    private function extractAllergies(string $message): array
    {
        $commonAllergies = [
            'แอสไพริน', 'aspirin', 'พาราเซตามอล', 'paracetamol',
            'ไอบูโพรเฟน', 'ibuprofen', 'เพนนิซิลิน', 'penicillin',
            'ซัลฟา', 'sulfa', 'อะม็อกซี่', 'amoxicillin',
            'nsaids', 'ยาแก้อักเสบ',
        ];
        
        $found = [];
        $messageLower = mb_strtolower($message);
        
        foreach ($commonAllergies as $allergy) {
            if (mb_strpos($messageLower, $allergy) !== false) {
                $found[] = $allergy;
            }
        }
        
        // ถ้าไม่พบใน list แต่ไม่ใช่คำว่า "ไม่แพ้" ให้เก็บทั้งข้อความ
        if (empty($found) && !$this->isSkipAnswer($message)) {
            $found[] = $message;
        }
        
        return $found;
    }
    
    /**
     * Extract medical conditions
     */
    private function extractMedicalConditions(string $message): array
    {
        $conditions = [
            'เบาหวาน', 'diabetes', 'ความดัน', 'hypertension', 'ความดันสูง',
            'หัวใจ', 'heart', 'หอบหืด', 'asthma', 'ไต', 'kidney',
            'ตับ', 'liver', 'มะเร็ง', 'cancer', 'ไทรอยด์', 'thyroid',
            'โรคกระเพาะ', 'ภูมิแพ้', 'ไมเกรน', 'migraine',
        ];
        
        $found = [];
        $messageLower = mb_strtolower($message);
        
        foreach ($conditions as $condition) {
            if (mb_strpos($messageLower, $condition) !== false) {
                $found[] = $condition;
            }
        }
        
        if (empty($found) && !$this->isSkipAnswer($message)) {
            $found[] = $message;
        }
        
        return $found;
    }
    
    /**
     * Extract medications
     */
    private function extractMedications(string $message): array
    {
        if ($this->isSkipAnswer($message)) {
            return [];
        }
        
        // Split by comma or space
        $meds = preg_split('/[,\s]+/u', $message);
        return array_filter($meds, fn($m) => mb_strlen(trim($m)) > 1);
    }

    // ==================== Quick Reply Builders ====================
    
    private function getSymptomQuickReplies(): array
    {
        return [
            ['label' => '🤕 ปวดหัว', 'text' => 'ปวดหัว'],
            ['label' => '🤒 ไข้/หวัด', 'text' => 'มีไข้ หวัด'],
            ['label' => '😷 ไอ/เจ็บคอ', 'text' => 'ไอ เจ็บคอ'],
            ['label' => '🤢 ท้องเสีย', 'text' => 'ท้องเสีย'],
            ['label' => '💪 ปวดกล้ามเนื้อ', 'text' => 'ปวดกล้ามเนื้อ'],
            ['label' => '🤧 แพ้/คัน', 'text' => 'แพ้ คัน'],
        ];
    }
    
    private function getDurationQuickReplies(): array
    {
        return [
            ['label' => 'วันนี้', 'text' => 'วันนี้'],
            ['label' => 'เมื่อวาน', 'text' => 'เมื่อวาน'],
            ['label' => '2-3 วัน', 'text' => '2-3 วัน'],
            ['label' => '1 สัปดาห์', 'text' => '1 สัปดาห์'],
            ['label' => 'มากกว่า 1 สัปดาห์', 'text' => 'มากกว่า 1 สัปดาห์'],
        ];
    }
    
    private function getSeverityQuickReplies(): array
    {
        return [
            ['label' => '😊 เล็กน้อย (1-3)', 'text' => '2'],
            ['label' => '😐 ปานกลาง (4-6)', 'text' => '5'],
            ['label' => '😣 มาก (7-8)', 'text' => '7'],
            ['label' => '😫 รุนแรงมาก (9-10)', 'text' => '9'],
        ];
    }
    
    private function getAssociatedQuickReplies(): array
    {
        return [
            ['label' => '🤒 มีไข้', 'text' => 'มีไข้ร่วมด้วย'],
            ['label' => '🤢 คลื่นไส้', 'text' => 'คลื่นไส้'],
            ['label' => '😴 อ่อนเพลีย', 'text' => 'อ่อนเพลีย'],
            ['label' => '✅ ไม่มี', 'text' => 'ไม่มี'],
        ];
    }
    
    private function getAllergyQuickReplies(): array
    {
        return [
            ['label' => '✅ ไม่แพ้ยา', 'text' => 'ไม่แพ้'],
            ['label' => '💊 แพ้ Aspirin', 'text' => 'แพ้ aspirin'],
            ['label' => '💊 แพ้ Penicillin', 'text' => 'แพ้ penicillin'],
            ['label' => '💊 แพ้ NSAIDs', 'text' => 'แพ้ NSAIDs'],
        ];
    }
    
    private function getMedicalHistoryQuickReplies(): array
    {
        return [
            ['label' => '✅ ไม่มีโรคประจำตัว', 'text' => 'ไม่มี'],
            ['label' => '🩺 เบาหวาน', 'text' => 'เบาหวาน'],
            ['label' => '❤️ ความดันสูง', 'text' => 'ความดันสูง'],
            ['label' => '🫁 หอบหืด', 'text' => 'หอบหืด'],
        ];
    }
    
    private function getCurrentMedsQuickReplies(): array
    {
        return [
            ['label' => '✅ ไม่ได้ทานยา', 'text' => 'ไม่มี'],
            ['label' => '💊 ยาเบาหวาน', 'text' => 'ยาเบาหวาน'],
            ['label' => '💊 ยาความดัน', 'text' => 'ยาความดัน'],
            ['label' => '💊 ยาละลายลิ่มเลือด', 'text' => 'ยาละลายลิ่มเลือด'],
        ];
    }
    
    private function getRecommendQuickReplies(array $recommendations, bool $hasRedFlags): array
    {
        $replies = [];
        
        if (!$hasRedFlags && !empty($recommendations)) {
            $replies[] = ['label' => '🛒 สั่งซื้อยา', 'text' => 'สั่งซื้อ'];
        }
        
        $replies[] = ['label' => '📞 ปรึกษาเภสัชกร', 'text' => 'ปรึกษาเภสัชกร'];
        $replies[] = ['label' => '📹 Video Call', 'text' => 'video call'];
        $replies[] = ['label' => '🔄 เริ่มใหม่', 'text' => 'เริ่มใหม่'];
        
        return $replies;
    }
    
    private function getEscalateQuickReplies(): array
    {
        return [
            ['label' => '📹 Video Call ทันที', 'text' => 'video call', 'type' => 'uri', 'uri' => $this->getVideoCallUrl()],
            ['label' => '📞 โทรหาเภสัชกร', 'text' => 'โทรหาเภสัชกร'],
            ['label' => '⏰ รอติดต่อกลับ', 'text' => 'รอติดต่อกลับ'],
        ];
    }
    
    private function getOrderQuickReplies(): array
    {
        return [
            ['label' => '🛒 ดำเนินการสั่งซื้อ', 'text' => 'checkout', 'type' => 'uri', 'uri' => $this->getCheckoutUrl()],
            ['label' => '📞 ปรึกษาก่อน', 'text' => 'ปรึกษาเภสัชกร'],
            ['label' => '❌ ยกเลิก', 'text' => 'ยกเลิก'],
        ];
    }

    // ==================== Database & Notification Methods ====================
    
    private function getUserAllergies(): array
    {
        if (!$this->userId) return [];
        
        try {
            $result = $this->db->fetchOne(
                "SELECT drug_allergies FROM users WHERE id = ?",
                [$this->userId]
            );
            
            if ($result && !empty($result['drug_allergies'])) {
                return array_filter(explode(',', $result['drug_allergies']));
            }
        } catch (\Exception $e) {}
        
        return [];
    }
    
    private function saveUserAllergies(array $allergies): void
    {
        if (!$this->userId || empty($allergies)) return;
        
        try {
            $allergyStr = implode(',', $allergies);
            $this->db->execute(
                "UPDATE users SET drug_allergies = ? WHERE id = ?",
                [$allergyStr, $this->userId]
            );
        } catch (\Exception $e) {}
    }
    
    /**
     * แจ้งเตือนเภสัชกร
     * Requirements: 4.1, 4.2 - Create notification when user requests pharmacist or severity is high/critical
     */
    private function notifyPharmacist(bool $urgent = false): void
    {
        try {
            $data = $this->currentState['data'];
            $priority = $urgent ? 'urgent' : 'normal';
            $redFlags = $data['red_flags'] ?? [];
            
            // Get user info for notification
            $userName = $this->getUserName();
            
            // Build notification title and message
            $title = $urgent 
                ? '🚨 ฉุกเฉิน - ลูกค้าต้องการความช่วยเหลือด่วน'
                : '👨‍⚕️ ลูกค้าขอปรึกษาเภสัชกร';
            
            // Update title if red flags exist
            if (!empty($redFlags)) {
                $title = '🚨 พบ Red Flag - ต้องตรวจสอบด่วน!';
            }
            
            $message = "ลูกค้า: {$userName}\n";
            
            if (!empty($data['symptoms'])) {
                $symptoms = is_array($data['symptoms']) ? implode(', ', $data['symptoms']) : $data['symptoms'];
                $message .= "อาการ: {$symptoms}\n";
            }
            if (!empty($data['duration'])) {
                $message .= "ระยะเวลา: {$data['duration']}\n";
            }
            if (!empty($data['severity'])) {
                $message .= "ความรุนแรง: {$data['severity']}/10\n";
            }
            if (!empty($data['medical_history'])) {
                $medHistory = is_array($data['medical_history']) ? implode(', ', $data['medical_history']) : $data['medical_history'];
                $message .= "โรคประจำตัว: {$medHistory}\n";
            }
            if (!empty($redFlags)) {
                $message .= "\n🚨 Red Flags:\n";
                foreach ($redFlags as $flag) {
                    $flagMsg = is_array($flag) ? ($flag['message'] ?? '') : $flag;
                    if ($flagMsg) {
                        $message .= "• {$flagMsg}\n";
                    }
                }
            }
            
            // Include full triage data in notification_data for dashboard display
            $notificationData = json_encode([
                'symptoms' => $data['symptoms'] ?? [],
                'duration' => $data['duration'] ?? '',
                'severity' => $data['severity'] ?? null,
                'associated_symptoms' => $data['associated_symptoms'] ?? [],
                'allergies' => $data['allergies'] ?? [],
                'medical_history' => $data['medical_history'] ?? [],
                'current_medications' => $data['current_medications'] ?? [],
                'red_flags' => $data['red_flags'] ?? [],
                'recommendations' => $data['recommendations'] ?? [],
                'interactions' => $data['interactions'] ?? [],
                'user_name' => $userName
            ], JSON_UNESCAPED_UNICODE);
            
            // บันทึกลง database with all required fields
            $this->db->execute(
                "INSERT INTO pharmacist_notifications 
                 (user_id, line_account_id, triage_session_id, type, title, message, notification_data, priority, status) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')",
                [
                    $this->userId,
                    $this->lineAccountId,
                    $this->currentState['id'] ?? null,
                    $urgent ? 'emergency_alert' : 'escalation',
                    $title,
                    $message,
                    $notificationData,
                    $priority
                ]
            );
            
            $notificationId = $this->db->lastInsertId();
            error_log("notifyPharmacist: Created notification #{$notificationId} for user #{$this->userId}, urgent={$urgent}");
            
            // ส่ง LINE notification ให้เภสัชกร
            try {
                require_once __DIR__ . '/PharmacistNotifier.php';
                $notifier = new PharmacistNotifier($this->lineAccountId);
                
                // เพิ่มชื่อลูกค้า
                $data['user_name'] = $userName;
                
                $notifier->notifyAllPharmacists($data, $urgent);
            } catch (\Exception $e) {
                error_log("PharmacistNotifier error: " . $e->getMessage());
            }
            
        } catch (\Exception $e) {
            error_log("notifyPharmacist error: " . $e->getMessage());
        }
    }
    
    /**
     * ดึงชื่อลูกค้า
     */
    private function getUserName(): string
    {
        if (!$this->userId) return 'ลูกค้า';
        
        try {
            $result = $this->db->fetchOne(
                "SELECT display_name FROM users WHERE id = ?",
                [$this->userId]
            );
            return $result['display_name'] ?? 'ลูกค้า';
        } catch (\Exception $e) {
            return 'ลูกค้า';
        }
    }
    
    private function createOrderDraft(array $recommendations): void
    {
        if (!$this->userId || empty($recommendations)) return;
        
        try {
            foreach ($recommendations as $drug) {
                $this->db->execute(
                    "INSERT INTO cart_items (user_id, product_id, quantity, added_from) VALUES (?, ?, 1, 'triage')
                     ON DUPLICATE KEY UPDATE quantity = quantity + 1",
                    [$this->userId, $drug['id']]
                );
            }
        } catch (\Exception $e) {
            error_log("createOrderDraft error: " . $e->getMessage());
        }
    }
    
    private function getVideoCallUrl(): string
    {
        // Return LIFF URL for video call
        return "https://liff.line.me/{LIFF_ID}/video-call";
    }
    
    private function getCheckoutUrl(): string
    {
        // Use actual LIFF URL
        return "https://clinicya.re-ya.com/liff/";
    }
    
    // ==================== Utility Methods ====================
    
    private function isResetCommand(string $message): bool
    {
        return in_array($message, ['เริ่มใหม่', 'reset', 'ใหม่', 'clear', 'ล้าง']);
    }
    
    private function isSkipCommand(string $message): bool
    {
        return in_array($message, ['ข้าม', 'skip', 'ถัดไป', 'next']);
    }
    
    private function isSkipAnswer(string $message): bool
    {
        $messageLower = mb_strtolower(trim($message));
        foreach (self::SKIP_KEYWORDS as $keyword) {
            if ($messageLower === $keyword || mb_strpos($messageLower, $keyword) === 0) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Build response array
     */
    private function buildResponse(string $text, string $state, array $quickReplies = []): array
    {
        return [
            'success' => true,
            'text' => $text,
            'state' => $state,
            'quick_replies' => $quickReplies,
            'data' => $this->currentState['data'],
        ];
    }
    
    /**
     * Get triage data
     */
    public function getTriageData(): array
    {
        return $this->currentState['data'];
    }
}
