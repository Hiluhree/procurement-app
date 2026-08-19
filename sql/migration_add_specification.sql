-- Add specification column to requisition_items table
-- Run this if upgrading an existing installation

ALTER TABLE requisition_items 
ADD COLUMN specification VARCHAR(500) DEFAULT NULL AFTER description;
