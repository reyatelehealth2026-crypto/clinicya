-- Update transactions table status column to VARCHAR(50) to support WMS statuses
ALTER TABLE `transactions` MODIFY COLUMN `status` VARCHAR(50) DEFAULT 'pending';
