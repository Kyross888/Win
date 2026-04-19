<?php
// ============================================================
//  db.php  —  Single source of truth for DB connection
//  Used by every API file via:  require_once '../db.php';
// ============================================================

define('DB_HOST',    'sql12.freesqldatabase.com');
define('DB_NAME',    'sql12823569');
define('DB_USER',    'sql12823569');
define('DB_PASS',    'ht66dlsm4h');
define('DB_PORT',    3306);
define('DB_CHARSET', 'utf8mb4');

$dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

// ── Session helpers ──────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Helper: send JSON and exit
function respond(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// Helper: require login; returns session user array
function requireAuth(): array {
    if (empty($_SESSION['user'])) {
        respond(['success' => false, 'error' => 'Not authenticated'], 401);
    }
    return $_SESSION['user'];
}
