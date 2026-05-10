-- Procurement module migration
START TRANSACTION;

CREATE TABLE IF NOT EXISTS suppliers (
    supplier_id INT AUTO_INCREMENT PRIMARY KEY,
    supplier_name VARCHAR(150) NOT NULL,
    contact_person VARCHAR(120) DEFAULT NULL,
    email VARCHAR(150) DEFAULT NULL,
    phone VARCHAR(50) DEFAULT NULL,
    address TEXT DEFAULT NULL,
    lead_time_days INT NOT NULL DEFAULT 0,
    status ENUM('active','inactive','blocked') NOT NULL DEFAULT 'active',
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS purchase_orders (
    po_id INT AUTO_INCREMENT PRIMARY KEY,
    po_number VARCHAR(40) NOT NULL UNIQUE,
    supplier_id INT NOT NULL,
    status ENUM('draft','submitted','partial_received','received','closed','cancelled') NOT NULL DEFAULT 'draft',
    ordered_at DATETIME DEFAULT NULL,
    expected_at DATETIME DEFAULT NULL,
    currency VARCHAR(10) NOT NULL DEFAULT 'PHP',
    notes TEXT DEFAULT NULL,
    created_by INT NOT NULL,
    submitted_by INT DEFAULT NULL,
    submitted_at DATETIME DEFAULT NULL,
    closed_by INT DEFAULT NULL,
    closed_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_po_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(supplier_id),
    CONSTRAINT fk_po_creator FOREIGN KEY (created_by) REFERENCES users(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS purchase_order_items (
    po_item_id INT AUTO_INCREMENT PRIMARY KEY,
    po_id INT NOT NULL,
    item_id INT NOT NULL,
    ordered_qty DECIMAL(12,2) NOT NULL,
    unit_cost DECIMAL(12,4) NOT NULL,
    tax DECIMAL(12,4) NOT NULL DEFAULT 0,
    discount DECIMAL(12,4) NOT NULL DEFAULT 0,
    line_total DECIMAL(14,4) NOT NULL,
    received_qty DECIMAL(12,2) NOT NULL DEFAULT 0,
    last_adjusted_unit_cost DECIMAL(12,4) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_poi_po FOREIGN KEY (po_id) REFERENCES purchase_orders(po_id) ON DELETE CASCADE,
    CONSTRAINT fk_poi_item FOREIGN KEY (item_id) REFERENCES items(item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS goods_receipts (
    receipt_id INT AUTO_INCREMENT PRIMARY KEY,
    po_id INT NOT NULL,
    received_at DATETIME NOT NULL,
    received_by INT NOT NULL,
    reference_no VARCHAR(60) DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    allocation_method ENUM('value','qty') NOT NULL DEFAULT 'value',
    landed_cost_total DECIMAL(12,4) NOT NULL DEFAULT 0,
    is_backdated TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_gr_po FOREIGN KEY (po_id) REFERENCES purchase_orders(po_id),
    CONSTRAINT fk_gr_user FOREIGN KEY (received_by) REFERENCES users(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS goods_receipt_items (
    receipt_item_id INT AUTO_INCREMENT PRIMARY KEY,
    receipt_id INT NOT NULL,
    po_item_id INT NOT NULL,
    received_qty DECIMAL(12,2) NOT NULL,
    accepted_qty DECIMAL(12,2) NOT NULL,
    rejected_qty DECIMAL(12,2) NOT NULL DEFAULT 0,
    landed_cost_alloc DECIMAL(12,4) NOT NULL DEFAULT 0,
    adjusted_unit_cost DECIMAL(12,4) NOT NULL DEFAULT 0,
    line_valuation_total DECIMAL(14,4) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_gri_receipt FOREIGN KEY (receipt_id) REFERENCES goods_receipts(receipt_id) ON DELETE CASCADE,
    CONSTRAINT fk_gri_po_item FOREIGN KEY (po_item_id) REFERENCES purchase_order_items(po_item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS supplier_item_cost_history (
    history_id INT AUTO_INCREMENT PRIMARY KEY,
    supplier_id INT NOT NULL,
    item_id INT NOT NULL,
    cost DECIMAL(12,4) NOT NULL,
    effective_at DATETIME NOT NULL,
    source_receipt_item_id INT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_sich_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(supplier_id),
    CONSTRAINT fk_sich_item FOREIGN KEY (item_id) REFERENCES items(item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE inventory_logs MODIFY action ENUM('add','remove','sale','adjust','inbound_receipt') NOT NULL;
ALTER TABLE users MODIFY role ENUM('admin','manager','cashier') DEFAULT 'cashier';

COMMIT;
