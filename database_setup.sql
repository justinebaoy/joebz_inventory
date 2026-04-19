-- Database setup for Inventory_Joebz
-- Run this SQL script to create the missing sale_items table

USE inventory_joebz;

-- Create sale_items table to track individual items in each sale
CREATE TABLE IF NOT EXISTS sale_items (
    sale_item_id INT PRIMARY KEY AUTO_INCREMENT,
    sale_id INT NOT NULL,
    item_id INT NOT NULL,
    quantity INT NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sale_id) REFERENCES sales(sale_id) ON DELETE CASCADE,
    FOREIGN KEY (item_id) REFERENCES items(item_id) ON DELETE CASCADE
);

-- Add index for better performance (optional)
-- CREATE INDEX idx_sale_items_sale_id ON sale_items(sale_id);
-- CREATE INDEX idx_sale_items_item_id ON sale_items(item_id);

-- Add new columns to sales table if they don't exist
ALTER TABLE sales ADD COLUMN IF NOT EXISTS cash_received DECIMAL(10,2) NOT NULL DEFAULT 0.00;
ALTER TABLE sales DROP COLUMN IF EXISTS change_amount;
ALTER TABLE sales ADD COLUMN change_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00;

-- Add support for item images
ALTER TABLE items ADD COLUMN IF NOT EXISTS image_path VARCHAR(255) DEFAULT NULL;

-- Soft-delete support for items with sales history
ALTER TABLE items ADD COLUMN IF NOT EXISTS is_active TINYINT(1) NOT NULL DEFAULT 1;

-- Verify the tables
DESCRIBE sale_items;
DESCRIBE sales;
