<?php
// ============================================================
//  api/customers.php  —  CRUD for customer records
// ============================================================
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../db.php';

$action = $_GET['action'] ?? 'list';

if ($action === 'list') {
    requireAuth();
    $search = $_GET['search'] ?? '';
    $sql    = "SELECT c.*, b.name AS branch_name FROM customers c LEFT JOIN branches b ON c.branch_id = b.id WHERE 1=1";
    $params = [];
    if ($search) {
        $sql .= " AND (c.name LIKE ? OR c.email LIKE ? OR c.phone LIKE ?)";
        $params = ["%$search%", "%$search%", "%$search%"];
    }
    $sql .= " ORDER BY c.name ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    respond(['success' => true, 'data' => $stmt->fetchAll()]);
}

if ($action === 'get') {
    requireAuth();
    $id   = (int)($_GET['id'] ?? 0);
    $stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
    $stmt->execute([$id]);
    $c = $stmt->fetch();
    if (!$c) respond(['success' => false, 'error' => 'Customer not found.'], 404);

    // Last 10 transactions
    $txns = $pdo->prepare(
        "SELECT reference_no, total, order_type, created_at FROM transactions WHERE customer_id = ? ORDER BY created_at DESC LIMIT 10"
    );
    $txns->execute([$id]);
    $c['recent_transactions'] = $txns->fetchAll();

    respond(['success' => true, 'data' => $c]);
}

if ($action === 'create') {
    requireAuth();
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $name      = trim($body['name']      ?? '');
    $email     = trim($body['email']     ?? '');
    $phone     = trim($body['phone']     ?? '');
    $branch_id = $body['branch_id']      ?? null;

    if (!$name) respond(['success' => false, 'error' => 'Name is required.'], 400);

    $stmt = $pdo->prepare("INSERT INTO customers (name, email, phone, branch_id) VALUES (?,?,?,?)");
    $stmt->execute([$name, $email, $phone, $branch_id ?: null]);
    respond(['success' => true, 'id' => $pdo->lastInsertId()]);
}

if ($action === 'update') {
    requireAuth();
    $id   = (int)($_GET['id'] ?? 0);
    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    $allowed = ['name', 'email', 'phone', 'branch_id'];
    $sets = []; $params = [];
    foreach ($allowed as $f) {
        if (array_key_exists($f, $body)) { $sets[] = "$f = ?"; $params[] = $body[$f]; }
    }
    if (empty($sets)) respond(['success' => false, 'error' => 'Nothing to update.'], 400);

    $params[] = $id;
    $pdo->prepare("UPDATE customers SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
    respond(['success' => true]);
}

if ($action === 'delete') {
    requireAuth();
    $id = (int)($_GET['id'] ?? 0);
    $pdo->prepare("DELETE FROM customers WHERE id = ?")->execute([$id]);
    respond(['success' => true]);
}

respond(['success' => false, 'error' => 'Unknown action.'], 400);
