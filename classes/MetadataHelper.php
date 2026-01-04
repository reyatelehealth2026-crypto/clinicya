<?php
/**
 * MetadataHelper - JSON serialization/deserialization helper for payment metadata
 * 
 * Provides common metadata handling functionality for accounting records
 * including payment vouchers, receipt vouchers, and other financial documents.
 * 
 * Requirements: 7.1, 7.2
 */

class MetadataHelper {
    /**
     * Serialize metadata to JSON format for database storage
     * 
     * @param array|null $metadata Metadata array to serialize
     * @return string|null JSON string or null if input is null/empty
     */
    public static function serialize($metadata): ?string {
        if ($metadata === null || (is_array($metadata) && empty($metadata))) {
            return null;
        }
        
        if (!is_array($metadata)) {
            return null;
        }
        
        $json = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        
        if ($json === false) {
            return null;
        }
        
        return $json;
    }
    
    /**
     * Deserialize JSON string back to metadata array
     * 
     * @param string|null $json JSON string to deserialize
     * @return array|null Metadata array or null if input is null/invalid
     */
    public static function deserialize(?string $json): ?array {
        if ($json === null || $json === '') {
            return null;
        }
        
        $metadata = json_decode($json, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }
        
        return $metadata;
    }
    
    /**
     * Round-trip test: serialize then deserialize should return equivalent data
     * 
     * @param array $metadata Original metadata
     * @return bool True if round-trip produces equivalent result
     */
    public static function verifyRoundTrip(array $metadata): bool {
        $serialized = self::serialize($metadata);
        if ($serialized === null && !empty($metadata)) {
            return false;
        }
        
        if ($serialized === null && empty($metadata)) {
            return true;
        }
        
        $deserialized = self::deserialize($serialized);
        
        return $deserialized === $metadata;
    }

    
    /**
     * Create payment metadata structure for AP payments
     * 
     * @param array $apRecord Account Payable record
     * @param array $paymentData Payment details
     * @return array Structured payment metadata
     */
    public static function createApPaymentMetadata(array $apRecord, array $paymentData): array {
        return [
            'ap_id' => $apRecord['id'] ?? null,
            'ap_number' => $apRecord['ap_number'] ?? null,
            'supplier_name' => $apRecord['supplier_name'] ?? null,
            'supplier_id' => $apRecord['supplier_id'] ?? null,
            'payment_amount' => $paymentData['amount'] ?? 0,
            'payment_method' => $paymentData['payment_method'] ?? null,
            'payment_date' => $paymentData['payment_date'] ?? date('Y-m-d'),
            'reference_number' => $paymentData['reference_number'] ?? null,
            'bank_account' => $paymentData['bank_account'] ?? null,
            'cheque_number' => $paymentData['cheque_number'] ?? null,
            'cheque_date' => $paymentData['cheque_date'] ?? null,
            'recorded_at' => date('Y-m-d H:i:s')
        ];
    }
    
    /**
     * Create receipt metadata structure for AR receipts
     * 
     * @param array $arRecord Account Receivable record
     * @param array $receiptData Receipt details
     * @return array Structured receipt metadata
     */
    public static function createArReceiptMetadata(array $arRecord, array $receiptData): array {
        return [
            'ar_id' => $arRecord['id'] ?? null,
            'ar_number' => $arRecord['ar_number'] ?? null,
            'customer_name' => $arRecord['customer_name'] ?? null,
            'user_id' => $arRecord['user_id'] ?? null,
            'receipt_amount' => $receiptData['amount'] ?? 0,
            'payment_method' => $receiptData['payment_method'] ?? null,
            'receipt_date' => $receiptData['receipt_date'] ?? date('Y-m-d'),
            'reference_number' => $receiptData['reference_number'] ?? null,
            'bank_account' => $receiptData['bank_account'] ?? null,
            'slip_id' => $receiptData['slip_id'] ?? null,
            'recorded_at' => date('Y-m-d H:i:s')
        ];
    }
    
    /**
     * Create expense metadata structure
     * 
     * @param array $expenseData Expense details
     * @return array Structured expense metadata
     */
    public static function createExpenseMetadata(array $expenseData): array {
        return [
            'category_id' => $expenseData['category_id'] ?? null,
            'category_name' => $expenseData['category_name'] ?? null,
            'vendor_name' => $expenseData['vendor_name'] ?? null,
            'expense_date' => $expenseData['expense_date'] ?? date('Y-m-d'),
            'reference_number' => $expenseData['reference_number'] ?? null,
            'recorded_at' => date('Y-m-d H:i:s')
        ];
    }
    
    /**
     * Merge additional data into existing metadata
     * 
     * @param array|null $existingMetadata Existing metadata (can be null)
     * @param array $additionalData Additional data to merge
     * @return array Merged metadata
     */
    public static function merge(?array $existingMetadata, array $additionalData): array {
        if ($existingMetadata === null) {
            return $additionalData;
        }
        
        return array_merge($existingMetadata, $additionalData);
    }
    
    /**
     * Add timestamp to metadata
     * 
     * @param array $metadata Metadata to update
     * @param string $key Timestamp key name
     * @return array Updated metadata with timestamp
     */
    public static function addTimestamp(array $metadata, string $key = 'updated_at'): array {
        $metadata[$key] = date('Y-m-d H:i:s');
        return $metadata;
    }
    
    /**
     * Get a specific value from metadata with default
     * 
     * @param array|null $metadata Metadata array
     * @param string $key Key to retrieve
     * @param mixed $default Default value if key not found
     * @return mixed Value or default
     */
    public static function get(?array $metadata, string $key, $default = null) {
        if ($metadata === null || !isset($metadata[$key])) {
            return $default;
        }
        
        return $metadata[$key];
    }
    
    /**
     * Check if metadata has a specific key
     * 
     * @param array|null $metadata Metadata array
     * @param string $key Key to check
     * @return bool True if key exists
     */
    public static function has(?array $metadata, string $key): bool {
        return $metadata !== null && array_key_exists($key, $metadata);
    }
    
    /**
     * Remove sensitive data from metadata for display
     * 
     * @param array|null $metadata Metadata array
     * @param array $sensitiveKeys Keys to remove
     * @return array|null Sanitized metadata
     */
    public static function sanitize(?array $metadata, array $sensitiveKeys = []): ?array {
        if ($metadata === null) {
            return null;
        }
        
        $defaultSensitiveKeys = ['bank_account', 'cheque_number'];
        $keysToRemove = array_merge($defaultSensitiveKeys, $sensitiveKeys);
        
        return array_diff_key($metadata, array_flip($keysToRemove));
    }
    
    /**
     * Validate metadata structure has required keys
     * 
     * @param array|null $metadata Metadata to validate
     * @param array $requiredKeys Required keys
     * @return bool True if all required keys exist
     */
    public static function validate(?array $metadata, array $requiredKeys): bool {
        if ($metadata === null) {
            return empty($requiredKeys);
        }
        
        foreach ($requiredKeys as $key) {
            if (!array_key_exists($key, $metadata)) {
                return false;
            }
        }
        
        return true;
    }
}
