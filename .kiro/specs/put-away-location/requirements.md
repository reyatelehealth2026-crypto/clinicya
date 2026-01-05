# Requirements Document

## Introduction

ระบบ Put Away (จัดเก็บเข้าที่) สำหรับคลังยา/สินค้า ใช้หลักการ "เก็บให้หยิบง่าย ไม่ใช่เก็บให้แน่น" โดยจัดแยกตาม ABC Analysis, Zone/Shelf และ Fast/Slow moving พร้อมระบบ Location Code ที่ชัดเจน รองรับ Batch/Lot Tracking และ FIFO/FEFO สำหรับยาที่มีวันหมดอายุ

## Glossary

- **Put Away**: กระบวนการจัดเก็บสินค้าเข้าตำแหน่งในคลัง
- **Location Code**: รหัสตำแหน่งจัดเก็บ รูปแบบ Zone-Shelf-Bin (เช่น A1-03-02)
- **ABC Analysis**: การจัดกลุ่มสินค้าตามความถี่การเคลื่อนไหว (A=Fast, B=Medium, C=Slow)
- **Zone**: พื้นที่/โซนในคลัง (A, B, C, D, RX)
- **Shelf**: ชั้นวางในแต่ละโซน
- **Bin**: ช่องเก็บในแต่ละชั้น
- **Ergonomic Level**: ระดับความสูงที่เหมาะสมกับการหยิบ (Golden Zone = ระดับอก-เอว)
- **Batch/Lot**: รุ่นการผลิตของสินค้า ใช้ติดตามแหล่งที่มาและวันหมดอายุ
- **FIFO**: First In First Out - หลักการจ่ายสินค้าที่รับเข้ามาก่อนออกก่อน
- **FEFO**: First Expired First Out - หลักการจ่ายสินค้าที่หมดอายุก่อนออกก่อน (สำหรับยา)
- **Expiry Date**: วันหมดอายุของสินค้า/ยา

## Requirements

### Requirement 1

**User Story:** As a warehouse staff, I want to manage storage locations, so that I can organize products efficiently.

#### Acceptance Criteria

1. WHEN a staff creates a new location THEN the system SHALL validate the location code format (Zone-Shelf-Bin)
2. WHEN a location is created THEN the system SHALL assign zone type (General, Cold Storage, Controlled, Hazardous)
3. WHEN viewing locations THEN the system SHALL display location hierarchy (Zone → Shelf → Bin)
4. WHEN a location has products THEN the system SHALL show current occupancy and capacity
5. IF a location code already exists THEN the system SHALL prevent duplicate creation

### Requirement 2

**User Story:** As a warehouse manager, I want to classify products by ABC analysis, so that I can optimize storage placement.

#### Acceptance Criteria

1. WHEN analyzing products THEN the system SHALL calculate ABC classification based on sales velocity
2. WHEN a product is classified as A (Fast-moving) THEN the system SHALL recommend Golden Zone placement (eye-to-waist level)
3. WHEN a product is classified as C (Slow-moving) THEN the system SHALL recommend upper/lower shelf placement
4. WHEN ABC analysis runs THEN the system SHALL update product movement_class field
5. WHEN viewing products THEN the system SHALL display ABC classification badge

### Requirement 3

**User Story:** As a warehouse staff, I want to assign products to locations, so that I can track where items are stored.

#### Acceptance Criteria

1. WHEN assigning a product to location THEN the system SHALL validate location capacity
2. WHEN a product is assigned THEN the system SHALL update product's storage_location field
3. WHEN a controlled drug is assigned THEN the system SHALL only allow RX/Controlled zone
4. WHEN a cold-chain product is assigned THEN the system SHALL only allow Cold Storage zone
5. WHEN viewing a product THEN the system SHALL display its current location with visual map

### Requirement 4

**User Story:** As a warehouse staff, I want the system to suggest optimal locations, so that I can put away items efficiently.

#### Acceptance Criteria

1. WHEN putting away a new product THEN the system SHALL suggest locations based on ABC class
2. WHEN suggesting locations THEN the system SHALL prioritize empty bins in appropriate zones
3. WHEN a product has similar items THEN the system SHALL suggest nearby locations for grouping
4. WHEN suggesting for A-class items THEN the system SHALL prioritize ergonomic Golden Zone (Shelf 2-3)
5. WHEN no suitable location exists THEN the system SHALL alert staff to create new location

### Requirement 5

**User Story:** As a warehouse manager, I want to view location utilization, so that I can optimize warehouse space.

#### Acceptance Criteria

1. WHEN viewing dashboard THEN the system SHALL display zone utilization percentages
2. WHEN viewing locations THEN the system SHALL show heat map of occupancy
3. WHEN a zone exceeds 85% capacity THEN the system SHALL display warning alert
4. WHEN viewing reports THEN the system SHALL show location efficiency metrics
5. WHEN analyzing space THEN the system SHALL identify underutilized locations

### Requirement 6

**User Story:** As a picker, I want clear location labels, so that I can find products quickly during picking.

#### Acceptance Criteria

1. WHEN printing location labels THEN the system SHALL generate barcode/QR with location code
2. WHEN displaying location THEN the system SHALL show human-readable format (Zone A, Shelf 1, Bin 3)
3. WHEN picking THEN the system SHALL display location with visual direction hints
4. WHEN scanning location barcode THEN the system SHALL validate correct location
5. WHEN a product moves THEN the system SHALL update location history log

### Requirement 7

**User Story:** As a pharmacist, I want controlled substances in secure locations, so that I can maintain regulatory compliance.

#### Acceptance Criteria

1. WHEN a controlled drug is received THEN the system SHALL require RX zone assignment
2. WHEN accessing RX zone products THEN the system SHALL log access with staff ID
3. WHEN viewing controlled items THEN the system SHALL display security zone indicator
4. WHEN moving controlled items THEN the system SHALL require pharmacist approval
5. WHEN auditing THEN the system SHALL provide controlled substance location report

### Requirement 8

**User Story:** As a warehouse staff, I want to track product batches/lots, so that I can trace product origin and manage expiry dates.

#### Acceptance Criteria

1. WHEN receiving products THEN the system SHALL capture batch/lot number and expiry date
2. WHEN a batch is created THEN the system SHALL link it to supplier and receiving date
3. WHEN viewing inventory THEN the system SHALL display batch information with expiry countdown
4. WHEN a batch expires within 90 days THEN the system SHALL display warning alert
5. WHEN a batch expires THEN the system SHALL flag it for quarantine or disposal
6. WHEN tracing products THEN the system SHALL show complete batch history from receipt to sale

### Requirement 9

**User Story:** As a warehouse manager, I want FIFO/FEFO picking enforcement, so that older stock or soon-to-expire items are sold first.

#### Acceptance Criteria

1. WHEN picking products THEN the system SHALL prioritize FEFO (First Expired First Out) for items with expiry dates
2. WHEN picking products without expiry THEN the system SHALL use FIFO (First In First Out) based on receiving date
3. WHEN suggesting pick locations THEN the system SHALL order by expiry date ascending (soonest first)
4. WHEN multiple batches exist THEN the system SHALL display all batches sorted by expiry/receive date
5. IF staff picks wrong batch THEN the system SHALL warn and require override confirmation
6. WHEN viewing pick list THEN the system SHALL show recommended batch with expiry date clearly

### Requirement 10

**User Story:** As a pharmacist, I want expiry date alerts, so that I can prevent dispensing expired medications.

#### Acceptance Criteria

1. WHEN a product expires within 30 days THEN the system SHALL send alert notification
2. WHEN viewing near-expiry report THEN the system SHALL list products by days until expiry
3. WHEN a product is expired THEN the system SHALL block it from being picked/sold
4. WHEN disposing expired products THEN the system SHALL require pharmacist approval and log disposal
5. WHEN running expiry check THEN the system SHALL generate report of all expiring items by date range
