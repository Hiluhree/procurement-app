<?php
/**
 * RFQ & Workflow Migration - Direct database execution
 * This script creates all necessary tables for RFQ and workflow features
 */

require_once 'config.php';
require_once 'includes/db.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

try {
    // 1. Add phone_number column to users if not exists
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN phone_number VARCHAR(20) DEFAULT NULL AFTER title");
        echo "✓ Added phone_number column to users table\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column') === false) {
            throw $e;
        }
        echo "✓ phone_number column already exists\n";
    }

    // 2. Create rfqs table
    $pdo->exec("CREATE TABLE IF NOT EXISTS rfqs (
        id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        rfq_no          VARCHAR(30) NOT NULL UNIQUE,
        requisition_id  INT UNSIGNED DEFAULT NULL,
        created_by      INT UNSIGNED NOT NULL,
        date_created    DATE NOT NULL,
        date_required   DATE DEFAULT NULL,
        status          ENUM('draft','issued','evaluating','awarded','cancelled') NOT NULL DEFAULT 'draft',
        notes           TEXT DEFAULT NULL,
        created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (requisition_id) REFERENCES requisitions(id) ON DELETE SET NULL,
        FOREIGN KEY (created_by) REFERENCES users(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "✓ Created rfqs table\n";

    // 3. Create rfq_items table
    $pdo->exec("CREATE TABLE IF NOT EXISTS rfq_items (
        id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        rfq_id          INT UNSIGNED NOT NULL,
        description     VARCHAR(255) NOT NULL,
        specification   VARCHAR(500) DEFAULT NULL,
        unit            VARCHAR(30) DEFAULT NULL,
        qty             DECIMAL(12,2) NOT NULL DEFAULT 0,
        FOREIGN KEY (rfq_id) REFERENCES rfqs(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "✓ Created rfq_items table\n";

    // 4. Create rfq_suppliers table
    $pdo->exec("CREATE TABLE IF NOT EXISTS rfq_suppliers (
        id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        rfq_id          INT UNSIGNED NOT NULL,
        supplier_id     INT UNSIGNED NOT NULL,
        invitation_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        invitation_sent TINYINT(1) NOT NULL DEFAULT 0,
        token           VARCHAR(64) DEFAULT NULL UNIQUE,
        FOREIGN KEY (rfq_id) REFERENCES rfqs(id) ON DELETE CASCADE,
        FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE,
        UNIQUE KEY uniq_rfq_supplier (rfq_id, supplier_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "✓ Created rfq_suppliers table\n";

    // 5. Create quotations table
    $pdo->exec("CREATE TABLE IF NOT EXISTS quotations (
        id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        quotation_no    VARCHAR(30) NOT NULL UNIQUE,
        rfq_id          INT UNSIGNED NOT NULL,
        supplier_id     INT UNSIGNED NOT NULL,
        quotation_date  DATE NOT NULL,
        supplier_reference VARCHAR(120) DEFAULT NULL,
        delivery_days   INT UNSIGNED DEFAULT NULL,
        notes           TEXT DEFAULT NULL,
        status          ENUM('submitted','evaluated','awarded','rejected','cancelled') NOT NULL DEFAULT 'submitted',
        created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (rfq_id) REFERENCES rfqs(id) ON DELETE CASCADE,
        FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE,
        UNIQUE KEY uniq_rfq_supplier_quote (rfq_id, supplier_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "✓ Created quotations table\n";

    // 6. Create quotation_items table
    $pdo->exec("CREATE TABLE IF NOT EXISTS quotation_items (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "✓ Created quotation_items table\n";

    // 7. Create workflow_config table
    $pdo->exec("CREATE TABLE IF NOT EXISTS workflow_config (
        id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        stage           ENUM('prepared_by_procurement','authorized_by_finance','approved_by_principal','issued_to_supplier','closed') NOT NULL,
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "✓ Created workflow_config table\n";

    // 8. Create email_notifications table
    $pdo->exec("CREATE TABLE IF NOT EXISTS email_notifications (
        id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        recipient_email VARCHAR(120) NOT NULL,
        recipient_user_id INT UNSIGNED DEFAULT NULL,
        event_type      VARCHAR(50) NOT NULL,
        document_type   VARCHAR(50) NOT NULL,
        document_id     INT UNSIGNED NOT NULL,
        document_number VARCHAR(30) DEFAULT NULL,
        subject         VARCHAR(255) NOT NULL,
        sent_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        status          ENUM('sent','failed','pending') NOT NULL DEFAULT 'sent',
        error_message   VARCHAR(500) DEFAULT NULL,
        FOREIGN KEY (recipient_user_id) REFERENCES users(id) ON DELETE SET NULL,
        INDEX idx_event (event_type),
        INDEX idx_document (document_type, document_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "✓ Created email_notifications table\n";

    // 9. Create three_way_matches table
    $pdo->exec("CREATE TABLE IF NOT EXISTS three_way_matches (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "✓ Created three_way_matches table\n";

    // 10. Create rfq_awards table for per-item award decisions
    $pdo->exec("CREATE TABLE IF NOT EXISTS rfq_awards (
        id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        rfq_id          INT UNSIGNED NOT NULL,
        rfq_item_id     INT UNSIGNED NOT NULL,
        quotation_id    INT UNSIGNED NOT NULL,
        supplier_id     INT UNSIGNED NOT NULL,
        qty_awarded     DECIMAL(12,2) NOT NULL,
        unit_price      DECIMAL(14,2) NOT NULL,
        created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (rfq_id) REFERENCES rfqs(id) ON DELETE CASCADE,
        FOREIGN KEY (rfq_item_id) REFERENCES rfq_items(id) ON DELETE CASCADE,
        FOREIGN KEY (quotation_id) REFERENCES quotations(id) ON DELETE CASCADE,
        FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE,
        UNIQUE KEY uniq_award_item (rfq_id, rfq_item_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "✓ Created rfq_awards table\n";

    // 11. Insert default workflow configuration
    $check_workflow = $pdo->query("SELECT COUNT(*) FROM workflow_config")->fetchColumn();
    if ($check_workflow == 0) {
        $pdo->exec("INSERT INTO workflow_config (stage, assigned_role, description, sequence_order) VALUES
            ('prepared_by_procurement',  'procurement', 'Prepared by Procurement Officer', 1),
            ('authorized_by_finance',    'finance',     'Authorized by Finance Officer', 2),
            ('approved_by_principal',    'principal',   'Approved by Principal', 3),
            ('issued_to_supplier',       'procurement', 'Issued to Supplier', 4),
            ('closed',                   NULL,          'Purchase Order Closed', 5)");
        echo "✓ Inserted default workflow configuration\n";
    } else {
        echo "✓ Workflow configuration already exists\n";
    }

    echo "\n✅ All tables created successfully!\n";
    echo "Migration completed at " . date('Y-m-d H:i:s') . "\n";

} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
?>
