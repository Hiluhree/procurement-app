<?php
/**
 * Database & application configuration.
 * Copy this file's values to match your hosting environment.
 */

// --- Database ---
define('DB_HOST', 'localhost');
define('DB_NAME', 'sunshine_procurement');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// --- Application ---
define('APP_NAME', 'Sunshine Secondary School — Procurement System');
define('SCHOOL_NAME', 'SUNSHINE SECONDARY SCHOOL');
define('SCHOOL_ADDRESS', 'P.O. Box 56890 - 00200, Nairobi, Kenya');
define('SCHOOL_CONTACT', 'Tel: 020-601797 · Fax: 020-604643 · info@sunshineschool.sc.ke');
define('WHT_RATE', 0.02); // 2% withholding tax on VAT-registered supplier invoices

// Base URL path (leave as '' if the app sits at the domain root, e.g. '/procurement' otherwise)
define('BASE_PATH', '/procurement-app');

// Uploads
define('UPLOAD_DIR', __DIR__ . '/uploads/signatures');
define('UPLOAD_URL', BASE_PATH . '/uploads/signatures');
define('MAX_UPLOAD_BYTES', 2 * 1024 * 1024); // 2MB

// Timezone
date_default_timezone_set('Africa/Nairobi');
