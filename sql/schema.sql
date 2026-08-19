-- ============================================================
-- Sunshine Secondary School — Procurement System
-- Database schema (MySQL / MariaDB, InnoDB, utf8mb4)
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- Users (requesters, procurement, finance, principal, admin)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(150) NOT NULL,
    username        VARCHAR(80)  NOT NULL UNIQUE,
    email           VARCHAR(120) DEFAULT NULL,
    password_hash   VARCHAR(255) NOT NULL,
    role            ENUM('requester','procurement','finance','principal','admin') NOT NULL,
    department      VARCHAR(120) DEFAULT NULL,
    title           VARCHAR(120) DEFAULT NULL,       -- printed title, e.g. "Procurement Officer"
    signature_path  VARCHAR(255) DEFAULT NULL,        -- uploaded stamp/signature image
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Items (dropdown catalogue)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS items (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(150) NOT NULL,
    unit            VARCHAR(30)  DEFAULT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Departments (dropdown catalogue)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS departments (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(150) NOT NULL UNIQUE,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Stores (dropdown catalogue for GRN storage locations)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS stores (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(150) NOT NULL UNIQUE,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Suppliers
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS suppliers (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(150) NOT NULL,
    address         VARCHAR(255) DEFAULT NULL,
    phone           VARCHAR(60)  DEFAULT NULL,
    email           VARCHAR(120) DEFAULT NULL,
    vat_registered  TINYINT(1) NOT NULL DEFAULT 0,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Requisitions (Purchase / Service Request)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS requisitions (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    req_no          VARCHAR(30) NOT NULL UNIQUE,
    department      VARCHAR(120) NOT NULL,
    applicant_id    INT UNSIGNED NOT NULL,
    date_raised     DATE NOT NULL,
    date_required   DATE DEFAULT NULL,
    purpose         TEXT,
    status          ENUM('pending_procurement','pending_finance','pending_principal','approved','rejected')
                    NOT NULL DEFAULT 'pending_procurement',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (applicant_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS requisition_items (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    requisition_id  INT UNSIGNED NOT NULL,
    description     VARCHAR(255) NOT NULL,
    specification   VARCHAR(500) DEFAULT NULL,
    unit            VARCHAR(30) DEFAULT NULL,
    qty             DECIMAL(12,2) NOT NULL DEFAULT 0,
    unit_cost       DECIMAL(14,2) NOT NULL DEFAULT 0,
    FOREIGN KEY (requisition_id) REFERENCES requisitions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Local Purchase Orders
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS lpos (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lpo_no             VARCHAR(30) NOT NULL UNIQUE,
    requisition_id     INT UNSIGNED NOT NULL,
    supplier_id        INT UNSIGNED NOT NULL,
    date               DATE NOT NULL,
    status             ENUM('pending_finance','pending_principal','approved','rejected')
                       NOT NULL DEFAULT 'pending_finance',
    sent_to_supplier   TINYINT(1) NOT NULL DEFAULT 0,
    prepared_by        INT UNSIGNED NOT NULL,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (requisition_id) REFERENCES requisitions(id),
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
    FOREIGN KEY (prepared_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS lpo_items (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lpo_id          INT UNSIGNED NOT NULL,
    description     VARCHAR(255) NOT NULL,
    unit            VARCHAR(30) DEFAULT NULL,
    qty             DECIMAL(12,2) NOT NULL DEFAULT 0,
    unit_price      DECIMAL(14,2) NOT NULL DEFAULT 0,
    FOREIGN KEY (lpo_id) REFERENCES lpos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Goods Received Notes
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS grns (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    grn_no          VARCHAR(30) NOT NULL UNIQUE,
    lpo_id          INT UNSIGNED NOT NULL,
    section         VARCHAR(120) DEFAULT NULL,
    received_by     VARCHAR(150) DEFAULT NULL,
    date            DATE NOT NULL,
    created_by      INT UNSIGNED NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (lpo_id) REFERENCES lpos(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS grn_items (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    grn_id          INT UNSIGNED NOT NULL,
    description     VARCHAR(255) NOT NULL,
    unit            VARCHAR(30) DEFAULT NULL,
    qty_ordered     DECIMAL(12,2) NOT NULL DEFAULT 0,
    qty_received    DECIMAL(12,2) NOT NULL DEFAULT 0,
    unit_price      DECIMAL(14,2) NOT NULL DEFAULT 0,
    destination     VARCHAR(150) DEFAULT NULL,
    FOREIGN KEY (grn_id) REFERENCES grns(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Invoices & Payment
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS invoices (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    inv_no          VARCHAR(30) NOT NULL UNIQUE,
    grn_id          INT UNSIGNED NOT NULL,
    lpo_id          INT UNSIGNED NOT NULL,
    supplier_id     INT UNSIGNED NOT NULL,
    supplier_invoice_no VARCHAR(60) DEFAULT NULL,
    amount          DECIMAL(14,2) NOT NULL DEFAULT 0,
    vat_registered  TINYINT(1) NOT NULL DEFAULT 0,
    wht_amount      DECIMAL(14,2) NOT NULL DEFAULT 0,
    net_payable     DECIMAL(14,2) NOT NULL DEFAULT 0,
    status          ENUM('pending_payment','partially_paid','paid') NOT NULL DEFAULT 'pending_payment',
    date            DATE NOT NULL,
    paid_date       DATE DEFAULT NULL,
    payment_method  VARCHAR(50) DEFAULT NULL,
    payment_reference VARCHAR(120) DEFAULT NULL,
    transaction_code VARCHAR(120) DEFAULT NULL,
    payment_notes   TEXT DEFAULT NULL,
    created_by      INT UNSIGNED NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (grn_id) REFERENCES grns(id),
    FOREIGN KEY (lpo_id) REFERENCES lpos(id),
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Approvals — one row per stage acted on, for requisitions & LPOs
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS approvals (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    document_type   ENUM('requisition','lpo') NOT NULL,
    document_id     INT UNSIGNED NOT NULL,
    role            ENUM('procurement','finance','principal') NOT NULL,
    status          ENUM('approved','rejected') NOT NULL,
    acted_by        INT UNSIGNED NOT NULL,
    acted_by_name   VARCHAR(150) NOT NULL,
    signature_path  VARCHAR(255) DEFAULT NULL,
    reason          VARCHAR(255) DEFAULT NULL,
    acted_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (acted_by) REFERENCES users(id),
    UNIQUE KEY uniq_doc_role (document_type, document_id, role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- Seed data — sample users (password for all: "password123")
-- Password hash below is bcrypt for "password123"
-- ============================================================
INSERT IGNORE INTO users (name, username, password_hash, role, department, title) VALUES
('Grace Wanjiru',   'admin',       '$2y$10$q0yQKusii4tnYXhKPJu/MuLpZtnQv.tvlzNovQLeTNVdTpeqatGPm', 'admin',       'Administration', 'System Administrator'),
('Peter Otieno',    'procurement', '$2y$10$q0yQKusii4tnYXhKPJu/MuLpZtnQv.tvlzNovQLeTNVdTpeqatGPm', 'procurement', 'Procurement',     'Procurement Officer'),
('Mary Achieng',    'finance',     '$2y$10$q0yQKusii4tnYXhKPJu/MuLpZtnQv.tvlzNovQLeTNVdTpeqatGPm', 'finance',      'Finance',         'Finance Officer'),
('Dr. James Mwangi','principal',   '$2y$10$q0yQKusii4tnYXhKPJu/MuLpZtnQv.tvlzNovQLeTNVdTpeqatGPm', 'principal',    'Administration',  'Principal'),
('Emily Kimutai',   'ekimutai',    '$2y$10$q0yQKusii4tnYXhKPJu/MuLpZtnQv.tvlzNovQLeTNVdTpeqatGPm', 'requester',    'House Keeping',   'Housekeeping Supervisor');

INSERT IGNORE INTO suppliers (name, address, phone, email, vat_registered) VALUES
('BEVA General Agencies', 'Nairobi, Kenya', '020-555-0101', 'sales@bevageneral.co.ke', 1),
('Pool Shop EA', 'Nairobi, Kenya', '020-555-0102', 'info@poolshopea.co.ke', 1),
('Maweni Stationers', 'Nairobi, Kenya', '020-555-0103', 'orders@maweni.co.ke', 0);

INSERT IGNORE INTO departments (name) VALUES
('Administration'),
('Procurement'),
('Finance'),
('House Keeping'),
('Kitchen'),
('Maintenance'),
('Academic'),
('ICT');

INSERT IGNORE INTO items (name, unit) VALUES
('Bar soap', 'Pcs'),
('Toilet paper', 'Pkt'),
('Office A4 paper', 'Ream'),
('Blue pen', 'Box'),
('Hand sanitizer', 'Ltr'),
('Bin liners', 'Pkt'),
('Dustpan & brush set', 'Set'),
('Broom', 'Pcs');
