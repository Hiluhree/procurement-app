-- ============================================================
-- Migration: Add RFQ workflow, quotations, and workflow config
-- Adds support for Request for Quotation (RFQ) process
-- and LPO workflow assignment
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
-- 1. Add phone_number column to users table
-- ============================================================
ALTER TABLE users ADD COLUMN phone_number VARCHAR(20) DEFAULT NULL AFTER title;

-- ============================================================
-- 2. Create RFQ (Request for Quotation) table
-- ============================================================
CREATE TABLE IF NOT EXISTS rfqs (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rfq_no          VARCHAR(30) NOT NULL UNIQUE,
    requisition_id  INT UNSIGNED DEFAULT NULL,
    created_by      INT UNSIGNED NOT NULL,
    date_created    DATE NOT NULL,
    date_required   DATE DEFAULT NULL,
    status          ENUM('draft','issued','evaluating','awarded','cancelled') 
                    NOT NULL DEFAULT 'draft',
    notes           TEXT DEFAULT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (requisition_id) REFERENCES requisitions(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 3. Create RFQ Items table (line items requested in RFQ)
-- ============================================================
CREATE TABLE IF NOT EXISTS rfq_items (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rfq_id          INT UNSIGNED NOT NULL,
    description     VARCHAR(255) NOT NULL,
    specification   VARCHAR(500) DEFAULT NULL,
    unit            VARCHAR(30) DEFAULT NULL,
    qty             DECIMAL(12,2) NOT NULL DEFAULT 0,
    FOREIGN KEY (rfq_id) REFERENCES rfqs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 4. Create RFQ Suppliers table (suppliers invited to quote)
-- ============================================================
CREATE TABLE IF NOT EXISTS rfq_suppliers (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rfq_id          INT UNSIGNED NOT NULL,
    supplier_id     INT UNSIGNED NOT NULL,
    invitation_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    invitation_sent TINYINT(1) NOT NULL DEFAULT 0,
    FOREIGN KEY (rfq_id) REFERENCES rfqs(id) ON DELETE CASCADE,
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_rfq_supplier (rfq_id, supplier_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 5. Create Quotations table (supplier responses to RFQ)
-- ============================================================
CREATE TABLE IF NOT EXISTS quotations (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    quotation_no    VARCHAR(30) NOT NULL UNIQUE,
    rfq_id          INT UNSIGNED NOT NULL,
    supplier_id     INT UNSIGNED NOT NULL,
    quotation_date  DATE NOT NULL,
    supplier_reference VARCHAR(120) DEFAULT NULL,
    delivery_days   INT UNSIGNED DEFAULT NULL,
    notes           TEXT DEFAULT NULL,
    status          ENUM('submitted','evaluated','awarded','rejected','cancelled')
                    NOT NULL DEFAULT 'submitted',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (rfq_id) REFERENCES rfqs(id) ON DELETE CASCADE,
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_rfq_supplier_quote (rfq_id, supplier_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 6. Create Quotation Items table (line items in quotation)
-- ============================================================
CREATE TABLE IF NOT EXISTS quotation_items (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    quotation_id    INT UNSIGNED NOT NULL,
    rfq_item_id     INT UNSIGNED NOT NULL,
    description     VARCHAR(255) NOT NULL,
    unit            VARCHAR(30) DEFAULT NULL,
    qty_offered     DECIMAL(12,2) NOT NULL DEFAULT 0,
    unit_price      DECIMAL(14,2) NOT NULL DEFAULT 0,
    currency        VARCHAR(3) DEFAULT 'KES',
    total_price     DECIMAL(14,2) GENERATED ALWAYS AS (qty_offered * unit_price) STORED,
    notes           VARCHAR(500) DEFAULT NULL,
    FOREIGN KEY (quotation_id) REFERENCES quotations(id) ON DELETE CASCADE,
    FOREIGN KEY (rfq_item_id) REFERENCES rfq_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 7. Create Workflow Config table (LPO workflow stage assignment)
-- Allows configuration of who approves at each LPO stage
-- ============================================================
CREATE TABLE IF NOT EXISTS workflow_config (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    stage           ENUM('prepared_by_procurement','authorized_by_finance','approved_by_principal','issued_to_supplier','closed')
                    NOT NULL,
    assigned_user_id INT UNSIGNED DEFAULT NULL,
    assigned_role   ENUM('procurement','finance','principal') DEFAULT NULL,
    description     VARCHAR(255) DEFAULT NULL,
    sequence_order  INT UNSIGNED NOT NULL,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    updated_by      INT UNSIGNED DEFAULT NULL,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_stage (stage),
    FOREIGN KEY (assigned_user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 8. Create Email Notifications table (audit trail of notifications)
-- ============================================================
CREATE TABLE IF NOT EXISTS email_notifications (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    recipient_email VARCHAR(120) NOT NULL,
    recipient_user_id INT UNSIGNED DEFAULT NULL,
    event_type      VARCHAR(50) NOT NULL,  -- e.g., 'requisition_approval', 'lpo_status_change'
    document_type   VARCHAR(50) NOT NULL,  -- e.g., 'requisition', 'lpo', 'grn'
    document_id     INT UNSIGNED NOT NULL,
    document_number VARCHAR(30) DEFAULT NULL,
    subject         VARCHAR(255) NOT NULL,
    sent_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    status          ENUM('sent','failed','pending') NOT NULL DEFAULT 'sent',
    error_message   VARCHAR(500) DEFAULT NULL,
    FOREIGN KEY (recipient_user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_event (event_type),
    INDEX idx_document (document_type, document_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 9. Create Three-Way Matching table (validation of PO vs GRN vs Invoice)
-- ============================================================
CREATE TABLE IF NOT EXISTS three_way_matches (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lpo_id          INT UNSIGNED NOT NULL,
    grn_id          INT UNSIGNED DEFAULT NULL,
    invoice_id      INT UNSIGNED DEFAULT NULL,
    match_status    ENUM('matched','variance','unmatched') NOT NULL DEFAULT 'unmatched',
    qty_variance    DECIMAL(12,2) DEFAULT NULL,
    price_variance  DECIMAL(14,2) DEFAULT NULL,
    notes           TEXT DEFAULT NULL,
    checked_by      INT UNSIGNED DEFAULT NULL,
    checked_at      DATETIME DEFAULT NULL,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (lpo_id) REFERENCES lpos(id) ON DELETE CASCADE,
    FOREIGN KEY (grn_id) REFERENCES grns(id) ON DELETE SET NULL,
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL,
    FOREIGN KEY (checked_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 10. Default Workflow Configuration (can be edited by admin)
-- ============================================================
INSERT IGNORE INTO workflow_config (stage, assigned_role, description, sequence_order) VALUES
('prepared_by_procurement',  'procurement', 'Prepared by Procurement Officer', 1),
('authorized_by_finance',    'finance',     'Authorized by Finance Officer', 2),
('approved_by_principal',    'principal',   'Approved by Principal', 3),
('issued_to_supplier',       'procurement', 'Issued to Supplier', 4),
('closed',                   NULL,          'Purchase Order Closed', 5);

SET FOREIGN_KEY_CHECKS = 1;
