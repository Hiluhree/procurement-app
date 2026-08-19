<?php
// Run migration for RFQ workflow tables
// This script executes the migration SQL file against the database

require_once 'config.php';
require_once 'includes/db.php';

$migration_file = __DIR__ . '/sql/migration_rfq_and_workflow.sql';

if (!file_exists($migration_file)) {
    die("Migration file not found: $migration_file\n");
}

$sql = file_get_contents($migration_file);

// Split by semicolon to execute multiple statements
$statements = array_filter(array_map('trim', explode(';', $sql)));

$pdo = db();
$count = 0;

try {
    foreach ($statements as $statement) {
        if (empty($statement) || substr(trim($statement), 0, 2) === '--') {
            continue;
        }
        $pdo->exec($statement);
        $count++;
    }
    echo "✓ Migration completed successfully! Executed $count statements.\n";
    echo "New tables created:\n";
    echo "  - rfqs\n";
    echo "  - rfq_items\n";
    echo "  - rfq_suppliers\n";
    echo "  - quotations\n";
    echo "  - quotation_items\n";
    echo "  - workflow_config\n";
    echo "  - email_notifications\n";
    echo "  - three_way_matches\n";
    echo "Users table updated with phone_number column.\n";
} catch (PDOException $e) {
    echo "✗ Migration failed:\n";
    echo $e->getMessage() . "\n";
    exit(1);
}
?>
