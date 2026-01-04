<?php
/**
 * AgingHelper - Shared aging bracket calculation helper
 * 
 * Provides common aging report functionality for Account Payable and Account Receivable
 * 
 * Requirements: 5.1, 5.2, 5.3
 */

class AgingHelper {
    /**
     * Standard aging brackets configuration
     * - current: not yet due (days overdue <= 0)
     * - 1-30: 1 to 30 days overdue
     * - 31-60: 31 to 60 days overdue
     * - 61-90: 61 to 90 days overdue
     * - 90+: more than 90 days overdue
     */
    public const AGING_BRACKETS = [
        'current' => ['min' => null, 'max' => 0, 'label' => 'Current', 'label_th' => 'ยังไม่ครบกำหนด'],
        '1-30' => ['min' => 1, 'max' => 30, 'label' => '1-30 Days', 'label_th' => '1-30 วัน'],
        '31-60' => ['min' => 31, 'max' => 60, 'label' => '31-60 Days', 'label_th' => '31-60 วัน'],
        '61-90' => ['min' => 61, 'max' => 90, 'label' => '61-90 Days', 'label_th' => '61-90 วัน'],
        '90+' => ['min' => 91, 'max' => null, 'label' => 'Over 90 Days', 'label_th' => 'เกิน 90 วัน']
    ];
    
    /**
     * Get the bracket names in order
     */
    public const BRACKET_ORDER = ['current', '1-30', '31-60', '61-90', '90+'];
    
    /**
     * Calculate days overdue from due date
     * 
     * @param string $dueDate Due date in Y-m-d format
     * @param string|null $referenceDate Reference date (defaults to today)
     * @return int Days overdue (negative means not yet due)
     */
    public static function calculateDaysOverdue(string $dueDate, ?string $referenceDate = null): int {
        $dueDateObj = new DateTime($dueDate);
        $refDateObj = $referenceDate ? new DateTime($referenceDate) : new DateTime();
        
        // Set both to start of day for accurate day calculation
        $dueDateObj->setTime(0, 0, 0);
        $refDateObj->setTime(0, 0, 0);
        
        $diff = $refDateObj->diff($dueDateObj);
        
        // If reference date is after due date, days are positive (overdue)
        // If reference date is before or equal to due date, days are negative or zero (not overdue)
        return $diff->invert ? $diff->days : -$diff->days;
    }
    
    /**
     * Determine aging bracket based on days overdue
     * 
     * @param int $daysOverdue Days overdue (negative means not yet due)
     * @return string Bracket name (current, 1-30, 31-60, 61-90, 90+)
     */
    public static function getAgingBracket(int $daysOverdue): string {
        if ($daysOverdue <= 0) {
            return 'current';
        } elseif ($daysOverdue <= 30) {
            return '1-30';
        } elseif ($daysOverdue <= 60) {
            return '31-60';
        } elseif ($daysOverdue <= 90) {
            return '61-90';
        } else {
            return '90+';
        }
    }
    
    /**
     * Get bracket from due date directly
     * 
     * @param string $dueDate Due date in Y-m-d format
     * @param string|null $referenceDate Reference date (defaults to today)
     * @return string Bracket name
     */
    public static function getBracketFromDueDate(string $dueDate, ?string $referenceDate = null): string {
        $daysOverdue = self::calculateDaysOverdue($dueDate, $referenceDate);
        return self::getAgingBracket($daysOverdue);
    }
    
    /**
     * Initialize empty brackets structure for aging report
     * 
     * @return array Empty brackets structure with records, total, and count
     */
    public static function initializeBrackets(): array {
        $brackets = [];
        foreach (self::BRACKET_ORDER as $bracketName) {
            $brackets[$bracketName] = [
                'records' => [],
                'total' => 0.0,
                'count' => 0
            ];
        }
        return $brackets;
    }
    
    /**
     * Categorize records into aging brackets
     * 
     * @param array $records Array of records with 'due_date' and 'balance' fields
     * @param string|null $referenceDate Reference date for calculation (defaults to today)
     * @param string $dueDateField Field name for due date (default: 'due_date')
     * @param string $balanceField Field name for balance (default: 'balance')
     * @return array Aging report with brackets, grand_total, and total_records
     */
    public static function categorizeRecords(
        array $records, 
        ?string $referenceDate = null,
        string $dueDateField = 'due_date',
        string $balanceField = 'balance'
    ): array {
        $brackets = self::initializeBrackets();
        $grandTotal = 0.0;
        
        foreach ($records as $record) {
            if (!isset($record[$dueDateField])) {
                continue;
            }
            
            $daysOverdue = self::calculateDaysOverdue($record[$dueDateField], $referenceDate);
            $bracket = self::getAgingBracket($daysOverdue);
            $balance = isset($record[$balanceField]) ? (float)$record[$balanceField] : 0.0;
            
            // Add days_overdue to record
            $record['days_overdue'] = $daysOverdue;
            
            $brackets[$bracket]['records'][] = $record;
            $brackets[$bracket]['total'] += $balance;
            $brackets[$bracket]['count']++;
            $grandTotal += $balance;
        }
        
        return [
            'brackets' => $brackets,
            'grand_total' => $grandTotal,
            'total_records' => count($records),
            'generated_at' => date('Y-m-d H:i:s')
        ];
    }
    
    /**
     * Get bracket configuration
     * 
     * @return array Aging brackets configuration
     */
    public static function getBracketsConfig(): array {
        return self::AGING_BRACKETS;
    }
    
    /**
     * Get bracket label
     * 
     * @param string $bracketName Bracket name
     * @param string $language Language code ('en' or 'th')
     * @return string Bracket label
     */
    public static function getBracketLabel(string $bracketName, string $language = 'en'): string {
        if (!isset(self::AGING_BRACKETS[$bracketName])) {
            return $bracketName;
        }
        
        $labelKey = $language === 'th' ? 'label_th' : 'label';
        return self::AGING_BRACKETS[$bracketName][$labelKey];
    }
    
    /**
     * Get summary totals from brackets (without individual records)
     * 
     * @param array $agingReport Full aging report from categorizeRecords
     * @return array Summary with bracket totals only
     */
    public static function getSummaryTotals(array $agingReport): array {
        $summary = [
            'brackets' => [],
            'grand_total' => $agingReport['grand_total'],
            'total_records' => $agingReport['total_records']
        ];
        
        foreach ($agingReport['brackets'] as $bracketName => $bracketData) {
            $summary['brackets'][$bracketName] = [
                'total' => $bracketData['total'],
                'count' => $bracketData['count'],
                'label' => self::getBracketLabel($bracketName),
                'label_th' => self::getBracketLabel($bracketName, 'th')
            ];
        }
        
        return $summary;
    }
    
    /**
     * Validate that aging report totals are consistent
     * (Sum of bracket totals should equal grand total)
     * 
     * @param array $agingReport Aging report to validate
     * @return bool True if totals are consistent
     */
    public static function validateTotals(array $agingReport): bool {
        $sumOfBrackets = 0.0;
        foreach ($agingReport['brackets'] as $bracketData) {
            $sumOfBrackets += $bracketData['total'];
        }
        
        // Use small epsilon for floating point comparison
        return abs($sumOfBrackets - $agingReport['grand_total']) < 0.01;
    }
    
    /**
     * Check if a record appears in exactly one bracket
     * 
     * @param array $agingReport Aging report to check
     * @return bool True if each record appears in exactly one bracket
     */
    public static function validateRecordUniqueness(array $agingReport): bool {
        $recordCount = 0;
        foreach ($agingReport['brackets'] as $bracketData) {
            $recordCount += $bracketData['count'];
        }
        
        return $recordCount === $agingReport['total_records'];
    }
}
