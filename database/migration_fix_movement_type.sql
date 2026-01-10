-- Fix movement_type column to support all types
-- Change from ENUM to VARCHAR to support more movement types

-- Check if stock_movements table exists and alter movement_type
ALTER TABLE stock_movements 
MODIFY COLUMN movement_type VARCHAR(50) NOT NULL 
COMMENT 'goods_receive, disposal, adjustment_in, adjustment_out, sale, return_restore, void_restore';

-- Also update location_movements if exists
ALTER TABLE location_movements 
MODIFY COLUMN movement_type VARCHAR(50) NOT NULL 
COMMENT 'put_away, pick, transfer, adjustment, disposal, return';
