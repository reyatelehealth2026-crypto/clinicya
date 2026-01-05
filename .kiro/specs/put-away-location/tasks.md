# Implementation Plan

## Put Away & Location Management System

- [x] 1. Database Migration
  - [x] 1.1 Create warehouse_locations table
    - Create table with zone, shelf, bin, zone_type, ergonomic_level, capacity fields
    - Add indexes for zone, zone_type, location_code
    - _Requirements: 1.1, 1.2, 1.3_
  - [x] 1.2 Create inventory_batches table
    - Create table with batch_number, lot_number, expiry_date, received_at fields
    - Add indexes for product_id, batch_number, expiry_date, status
    - _Requirements: 8.1, 8.2_
  - [x] 1.3 Create location_movements table
    - Create table for tracking all product movements
    - Add indexes for product_id, batch_id, locations
    - _Requirements: 6.5, 8.6_
  - [x] 1.4 Add columns to business_items table

    - Add movement_class, storage_zone_type, default_location_id, requires_batch_tracking
    - _Requirements: 2.4, 3.2_


- [x] 2. LocationService Implementation







  - [x] 2.1 Create LocationService class with CRUD methods






    - Implement createLocation, updateLocation, deleteLocation, getLocation
    - _Requirements: 1.1, 1.2_
  - [ ]* 2.2 Write property test for location code validation
    - **Property 1: Location code format validation**
    - **Validates: Requirements 1.1**

  - [x] 2.3 Implement location code validation
    - Validate Zone-Shelf-Bin format (e.g., A1-03-02)
    - _Requirements: 1.1_
  - [ ]* 2.4 Write property test for location uniqueness
    - **Property 3: Location uniqueness**

    - **Validates: Requirements 1.5**
  - [x] 2.5 Implement zone hierarchy methods

    - getZones, getShelvesInZone, getBinsInShelf
    - _Requirements: 1.3_
  - [x] 2.6 Implement capacity and utilization methods
    - getLocationCapacity, getZoneUtilization, getUnderutilizedLocations
    - _Requirements: 1.4, 5.1, 5.5_
  - [ ]* 2.7 Write property test for zone utilization calculation
    - **Property 15: Zone utilization calculation**
    - **Validates: Requirements 5.1**

- [ ] 3. Checkpoint - Ensure all tests pass
  - Ensure all tests pass, ask the user if questions arise.

- [x] 4. BatchService Implementation






  - [x] 4.1 Create BatchService class with CRUD methods

    - Implement createBatch, updateBatch, getBatch, getBatchByNumber
    - _Requirements: 8.1, 8.2_

  - [ ] 4.2 Implement batch query methods
    - getBatchesForProduct, getExpiringBatches, getExpiredBatches
    - _Requirements: 8.3, 8.4, 8.5_
  - [ ]* 4.3 Write property test for expiry alert threshold
    - **Property 13: Expiry alert threshold**

    - **Validates: Requirements 10.1, 8.4**
  - [ ] 4.4 Implement FIFO/FEFO methods
    - getNextBatchForPicking, getBatchesSortedByExpiry, getBatchesSortedByReceiveDate
    - _Requirements: 9.1, 9.2, 9.3_
  - [ ]* 4.5 Write property test for FEFO ordering
    - **Property 9: FEFO ordering**
    - **Validates: Requirements 9.1, 9.3**
  - [ ]* 4.6 Write property test for FIFO ordering
    - **Property 10: FIFO ordering**
    - **Validates: Requirements 9.2**
  - [x]* 4.7 Write property test for expired batch blocking

    - **Property 11: Expired batch blocking**
    - **Validates: Requirements 10.3**
  - [ ] 4.8 Implement expiry management methods
    - flagExpiredBatches, disposeBatch
    - _Requirements: 8.5, 10.3, 10.4_

- [ ] 5. Checkpoint - Ensure all tests pass
  - Ensure all tests pass, ask the user if questions arise.


- [x] 6. PutAwayService Implementation





  - [x] 6.1 Create PutAwayService class

    - Implement suggestLocation, suggestLocationForBatch
    - _Requirements: 4.1, 4.2_

  - [x] 6.2 Implement ABC Analysis

    - runABCAnalysis, getProductABCClass, updateProductABCClass
    - _Requirements: 2.1, 2.2, 2.3, 2.4_
  - [ ]* 6.3 Write property test for ABC classification
    - **Property 4: ABC classification consistency**
    - **Validates: Requirements 2.1, 2.4**
  - [ ]* 6.4 Write property test for Golden Zone recommendation
    - **Property 5: Golden Zone recommendation for A-class**
    - **Validates: Requirements 2.2, 4.4**

  - [x] 6.3 Implement zone restriction validation






    - validateZoneForProduct for controlled and cold-chain products
    - _Requirements: 3.3, 3.4, 7.1_
  - [ ]* 6.5 Write property test for controlled drug zone restriction
    - **Property 6: Controlled drug zone restriction**
    - **Validates: Requirements 3.3, 7.1**
  - [ ]* 6.6 Write property test for cold-chain zone restriction
    - **Property 7: Cold-chain zone restriction**
    - **Validates: Requirements 3.4**

  - [x] 6.7 Implement assignment methods

    - assignProductToLocation, assignBatchToLocation, moveProduct
    - _Requirements: 3.1, 3.2_
  - [ ]* 6.8 Write property test for capacity validation
    - **Property 8: Capacity validation**
    - **Validates: Requirements 3.1**
  - [ ]* 6.9 Write property test for movement logging
    - **Property 14: Movement logging**
    - **Validates: Requirements 6.5**

- [ ] 7. Checkpoint - Ensure all tests pass
  - Ensure all tests pass, ask the user if questions arise.


- [x] 8. API Endpoints




  - [x] 8.1 Create api/locations.php


    - CRUD endpoints for locations
    - Zone hierarchy endpoints
    - Utilization endpoints
    - _Requirements: 1.1, 1.2, 1.3, 1.4, 5.1_
  - [x] 8.2 Create api/batches.php


    - CRUD endpoints for batches
    - Expiry query endpoints
    - FIFO/FEFO endpoints
    - _Requirements: 8.1, 8.2, 8.3, 9.1, 9.2_
  - [x] 8.3 Create api/put-away.php


    - Location suggestion endpoint
    - Assignment endpoints
    - ABC analysis endpoint
    - _Requirements: 4.1, 4.2, 2.1_



- [x] 9. UI Components



  - [x] 9.1 Create includes/inventory/locations.php


    - Location management UI with zone/shelf/bin hierarchy
    - Utilization heat map display
    - _Requirements: 1.3, 5.2_
  - [x] 9.2 Create includes/inventory/batches.php


    - Batch list with expiry countdown
    - Near-expiry alerts
    - FIFO/FEFO display
    - _Requirements: 8.3, 8.4, 9.4, 9.6_
  - [x] 9.3 Create includes/inventory/put-away.php


    - Put away workflow UI
    - Location suggestion display
    - ABC classification badges
    - _Requirements: 4.1, 4.2, 2.5_
  - [x] 9.4 Add location tab to inventory/index.php


    - Add tabs for locations, batches, put-away
    - _Requirements: 1.3_


- [x] 10. Label Printing





  - [x] 10.1 Add location label generation to WMSPrintService

    - Generate barcode/QR with location code
    - Human-readable format display
    - _Requirements: 6.1, 6.2_


- [x] 11. Final Checkpoint - Ensure all tests pass









  - Ensure all tests pass, ask the user if questions arise.
