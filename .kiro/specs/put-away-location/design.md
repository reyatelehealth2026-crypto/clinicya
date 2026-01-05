# Design Document: Put Away & Location Management

## Overview

ระบบ Put Away & Location Management สำหรับจัดการตำแหน่งจัดเก็บสินค้า/ยาในคลัง รองรับ ABC Analysis, Batch/Lot Tracking และ FIFO/FEFO picking โดยใช้หลักการ "เก็บให้หยิบง่าย ไม่ใช่เก็บให้แน่น"

## Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                    Inventory Module                          │
├─────────────────────────────────────────────────────────────┤
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────────────┐  │
│  │  Location   │  │   Batch     │  │   Put Away          │  │
│  │  Manager    │  │   Tracker   │  │   Suggester         │  │
│  └──────┬──────┘  └──────┬──────┘  └──────────┬──────────┘  │
│         │                │                     │             │
│  ┌──────┴────────────────┴─────────────────────┴──────────┐ │
│  │              Location Service (API)                     │ │
│  └─────────────────────────┬───────────────────────────────┘ │
│                            │                                 │
│  ┌─────────────────────────┴───────────────────────────────┐ │
│  │                    Database Layer                        │ │
│  │  ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌────────────┐  │ │
│  │  │ locations│ │ batches  │ │ inventory│ │ movements  │  │ │
│  │  └──────────┘ └──────────┘ └──────────┘ └────────────┘  │ │
│  └─────────────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────────┘
```

## Components and Interfaces

### 1. LocationService
จัดการ CRUD สำหรับตำแหน่งจัดเก็บ

```php
class LocationService {
    // Location CRUD
    public function createLocation(array $data): int;
    public function updateLocation(int $id, array $data): bool;
    public function deleteLocation(int $id): bool;
    public function getLocation(int $id): ?array;
    public function getLocationByCode(string $code): ?array;
    
    // Location hierarchy
    public function getZones(): array;
    public function getShelvesInZone(string $zone): array;
    public function getBinsInShelf(string $zone, int $shelf): array;
    
    // Validation
    public function validateLocationCode(string $code): bool;
    public function isLocationAvailable(int $locationId): bool;
    public function getLocationCapacity(int $locationId): array;
    
    // Utilization
    public function getZoneUtilization(string $zone): float;
    public function getUnderutilizedLocations(): array;
}
```

### 2. BatchService
จัดการ Batch/Lot tracking

```php
class BatchService {
    // Batch CRUD
    public function createBatch(array $data): int;
    public function updateBatch(int $id, array $data): bool;
    public function getBatch(int $id): ?array;
    public function getBatchByNumber(string $batchNumber): ?array;
    
    // Batch queries
    public function getBatchesForProduct(int $productId): array;
    public function getExpiringBatches(int $daysAhead = 90): array;
    public function getExpiredBatches(): array;
    
    // FIFO/FEFO
    public function getNextBatchForPicking(int $productId, string $method = 'FEFO'): ?array;
    public function getBatchesSortedByExpiry(int $productId): array;
    public function getBatchesSortedByReceiveDate(int $productId): array;
    
    // Expiry management
    public function flagExpiredBatches(): int;
    public function disposeBatch(int $batchId, int $pharmacistId, string $reason): bool;
}
```

### 3. PutAwayService
แนะนำตำแหน่งจัดเก็บที่เหมาะสม

```php
class PutAwayService {
    // Suggestion
    public function suggestLocation(int $productId): array;
    public function suggestLocationForBatch(int $batchId): array;
    
    // Assignment
    public function assignProductToLocation(int $productId, int $locationId): bool;
    public function assignBatchToLocation(int $batchId, int $locationId): bool;
    public function moveProduct(int $productId, int $fromLocationId, int $toLocationId): bool;
    
    // ABC Analysis
    public function runABCAnalysis(): array;
    public function getProductABCClass(int $productId): string;
    public function updateProductABCClass(int $productId, string $class): bool;
    
    // Zone restrictions
    public function validateZoneForProduct(int $productId, int $locationId): bool;
}
```

## Data Models

### warehouse_locations
```sql
CREATE TABLE warehouse_locations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    line_account_id INT DEFAULT 1,
    location_code VARCHAR(20) NOT NULL UNIQUE,  -- A1-03-02
    zone VARCHAR(10) NOT NULL,                   -- A, B, C, RX, COLD
    shelf INT NOT NULL,                          -- 1-10
    bin INT NOT NULL,                            -- 1-20
    zone_type ENUM('general', 'cold_storage', 'controlled', 'hazardous') DEFAULT 'general',
    ergonomic_level ENUM('golden', 'upper', 'lower') DEFAULT 'golden',
    capacity INT DEFAULT 100,                    -- max items
    current_qty INT DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_zone (zone),
    INDEX idx_zone_type (zone_type),
    INDEX idx_location_code (location_code)
);
```

### inventory_batches
```sql
CREATE TABLE inventory_batches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    line_account_id INT DEFAULT 1,
    product_id INT NOT NULL,
    batch_number VARCHAR(50) NOT NULL,
    lot_number VARCHAR(50),
    supplier_id INT,
    quantity INT NOT NULL DEFAULT 0,
    quantity_available INT NOT NULL DEFAULT 0,
    cost_price DECIMAL(10,2),
    manufacture_date DATE,
    expiry_date DATE,
    received_at DATETIME NOT NULL,
    received_by INT,
    location_id INT,
    status ENUM('active', 'quarantine', 'expired', 'disposed') DEFAULT 'active',
    disposal_date DATETIME,
    disposal_by INT,
    disposal_reason TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_product (product_id),
    INDEX idx_batch_number (batch_number),
    INDEX idx_expiry (expiry_date),
    INDEX idx_status (status),
    INDEX idx_location (location_id),
    FOREIGN KEY (location_id) REFERENCES warehouse_locations(id)
);
```

### location_movements
```sql
CREATE TABLE location_movements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    line_account_id INT DEFAULT 1,
    product_id INT NOT NULL,
    batch_id INT,
    from_location_id INT,
    to_location_id INT,
    quantity INT NOT NULL,
    movement_type ENUM('put_away', 'pick', 'transfer', 'adjustment', 'disposal') NOT NULL,
    reference_type VARCHAR(50),  -- order, gr, adjustment
    reference_id INT,
    staff_id INT,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_product (product_id),
    INDEX idx_batch (batch_id),
    INDEX idx_from_location (from_location_id),
    INDEX idx_to_location (to_location_id),
    INDEX idx_created (created_at)
);
```

### Add columns to business_items
```sql
ALTER TABLE business_items ADD COLUMN movement_class ENUM('A', 'B', 'C') DEFAULT 'C';
ALTER TABLE business_items ADD COLUMN storage_zone_type ENUM('general', 'cold_storage', 'controlled', 'hazardous') DEFAULT 'general';
ALTER TABLE business_items ADD COLUMN default_location_id INT;
ALTER TABLE business_items ADD COLUMN requires_batch_tracking TINYINT(1) DEFAULT 0;
ALTER TABLE business_items ADD COLUMN requires_expiry_tracking TINYINT(1) DEFAULT 0;
```

## Correctness Properties

*A property is a characteristic or behavior that should hold true across all valid executions of a system-essentially, a formal statement about what the system should do. Properties serve as the bridge between human-readable specifications and machine-verifiable correctness guarantees.*

### Property 1: Location code format validation
*For any* location code string, the validation function should return true only if it matches the pattern Zone-Shelf-Bin (e.g., A1-03-02)
**Validates: Requirements 1.1**

### Property 2: Zone type assignment
*For any* created location, it must have a valid zone_type from the allowed enum values
**Validates: Requirements 1.2**

### Property 3: Location uniqueness
*For any* two locations in the system, their location_code values must be different
**Validates: Requirements 1.5**

### Property 4: ABC classification consistency
*For any* product with sales data, the ABC classification should be deterministic based on sales velocity percentile
**Validates: Requirements 2.1, 2.4**

### Property 5: Golden Zone recommendation for A-class
*For any* A-class product, the suggested locations should prioritize ergonomic_level = 'golden' (Shelf 2-3)
**Validates: Requirements 2.2, 4.4**

### Property 6: Controlled drug zone restriction
*For any* controlled drug (drug_category = 'controlled'), assignment to non-RX zones should be rejected
**Validates: Requirements 3.3, 7.1**

### Property 7: Cold-chain zone restriction
*For any* cold-chain product (storage_zone_type = 'cold_storage'), assignment to non-cold zones should be rejected
**Validates: Requirements 3.4**

### Property 8: Capacity validation
*For any* location, the current_qty should never exceed capacity after any assignment
**Validates: Requirements 3.1**

### Property 9: FEFO ordering
*For any* product with multiple batches having expiry dates, getNextBatchForPicking should return the batch with the earliest expiry date
**Validates: Requirements 9.1, 9.3**

### Property 10: FIFO ordering
*For any* product with multiple batches without expiry dates, getNextBatchForPicking should return the batch with the earliest received_at date
**Validates: Requirements 9.2**

### Property 11: Expired batch blocking
*For any* batch with status = 'expired', it should not be returned by getNextBatchForPicking
**Validates: Requirements 10.3**

### Property 12: Batch traceability
*For any* batch, the location_movements table should contain a complete history from receipt to current state
**Validates: Requirements 8.6**

### Property 13: Expiry alert threshold
*For any* batch with expiry_date within 30 days of current date, it should appear in getExpiringBatches(30)
**Validates: Requirements 10.1, 8.4**

### Property 14: Movement logging
*For any* product movement (put_away, pick, transfer), a corresponding record should be created in location_movements
**Validates: Requirements 6.5**

### Property 15: Zone utilization calculation
*For any* zone, the utilization percentage should equal (sum of current_qty / sum of capacity) * 100 for all locations in that zone
**Validates: Requirements 5.1**

## Error Handling

| Error | Code | Handling |
|-------|------|----------|
| Invalid location code format | LOC_001 | Return validation error with format hint |
| Duplicate location code | LOC_002 | Return error, suggest alternative code |
| Location capacity exceeded | LOC_003 | Reject assignment, suggest alternative location |
| Invalid zone for product type | LOC_004 | Return error with allowed zones |
| Batch not found | BAT_001 | Return 404 error |
| Expired batch pick attempt | BAT_002 | Block and return warning |
| Controlled item without approval | SEC_001 | Require pharmacist approval |

## Testing Strategy

### Unit Tests
- Location code validation (valid/invalid formats)
- ABC classification calculation
- Zone restriction validation
- Capacity calculation
- FIFO/FEFO sorting

### Property-Based Tests
- Use PHPUnit with data providers for property testing
- Generate random locations and validate constraints
- Generate random batches and test FIFO/FEFO ordering
- Test zone restrictions with various product types

### Integration Tests
- Full put-away workflow
- Batch receiving and tracking
- Pick with FEFO enforcement
- Expiry alert generation
