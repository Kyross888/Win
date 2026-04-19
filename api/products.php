<?php
// ============================================================
//  api/products.php  —  Full CRUD for inventory
// ============================================================
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../db.php';

$action = $_GET['action'] ?? 'list';

// ── LIST ─────────────────────────────────────────────────────
if ($action === 'list') {
    $cat      = $_GET['category'] ?? '';
    $search   = $_GET['search']   ?? '';
    $branchId = $_GET['branch_id'] ?? '';

    $sql    = "SELECT * FROM products WHERE is_active = 1";
    $params = [];

    if ($cat) {
        $sql .= " AND category = ?";
        $params[] = $cat;
    }
    if ($search) {
        $sql .= " AND name LIKE ?";
        $params[] = "%{$search}%";
    }
    if ($branchId) {
        $sql .= " AND (branch_id = ? OR branch_id IS NULL)";
        $params[] = (int)$branchId;
    }

    $sql .= " ORDER BY FIELD(category,
        'Breakfast','Merienda','Burgers And Sandwiches',
        'Rice Meal','Native','Dessert','Drinks'
    ), name";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    respond(['success' => true, 'data' => $stmt->fetchAll()]);
}

// ── GET SINGLE ───────────────────────────────────────────────
if ($action === 'get') {
    $id   = (int)($_GET['id'] ?? 0);
    $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ? AND is_active = 1");
    $stmt->execute([$id]);
    $product = $stmt->fetch();
    if (!$product) respond(['success' => false, 'error' => 'Product not found.'], 404);
    respond(['success' => true, 'data' => $product]);
}

// ── CREATE ───────────────────────────────────────────────────
if ($action === 'create') {
    requireAuth();

    $isMultipart = !empty($_POST);

    if ($isMultipart) {
        $name      = trim($_POST['name']      ?? '');
        $category  = trim($_POST['category']  ?? 'Breakfast');
        $price     = (float)($_POST['price']  ?? 0);
        $stock     = (int)  ($_POST['stock']  ?? 0);
        $branch_id = $_POST['branch_id']      ?? null;
        $icon      = $_POST['icon']           ?? null;
    } else {
        $body      = json_decode(file_get_contents('php://input'), true) ?? [];
        $name      = trim($body['name']      ?? '');
        $category  = trim($body['category']  ?? 'Breakfast');
        $price     = (float)($body['price']  ?? 0);
        $stock     = (int)  ($body['stock']  ?? 0);
        $branch_id = $body['branch_id']      ?? null;
        $icon      = $body['icon']           ?? null;
    }

    if (!$name || $price < 0) {
        respond(['success' => false, 'error' => 'Name and a valid price are required.'], 400);
    }

    // Handle optional image upload
    $image_path = null;
    if (!empty($_FILES['image']['tmp_name'])) {
        $ext     = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg','jpeg','png','webp','gif'];
        if (!in_array($ext, $allowed)) {
            respond(['success' => false, 'error' => 'Invalid image type.'], 400);
        }
        $uploadDir = dirname(__DIR__) . '/img/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        $filename   = uniqid('prod_', true) . '.' . $ext;
        move_uploaded_file($_FILES['image']['tmp_name'], $uploadDir . $filename);
        $image_path = 'img/' . $filename;
    }

    $stmt = $pdo->prepare(
        "INSERT INTO products (name, category, price, stock, image_path, icon, branch_id)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([$name, $category, $price, $stock, $image_path, $icon, $branch_id ?: null]);

    respond(['success' => true, 'id' => $pdo->lastInsertId(), 'message' => 'Product added.']);
}

// ── UPDATE ───────────────────────────────────────────────────
if ($action === 'update') {
    requireAuth();
    $id = (int)($_GET['id'] ?? 0);

    // Support FormData (multipart) for image uploads during update
    $isMultipart = !empty($_POST);

    if ($isMultipart) {
        $fields = ['name', 'category', 'price', 'stock', 'icon', 'branch_id', 'is_active'];
        $sets   = [];
        $params = [];
        foreach ($fields as $field) {
            if (isset($_POST[$field]) && $_POST[$field] !== '') {
                $sets[]   = "$field = ?";
                $params[] = $_POST[$field];
            }
        }
    } else {
        $body    = json_decode(file_get_contents('php://input'), true) ?? [];
        $allowed = ['name', 'category', 'price', 'stock', 'image_path', 'icon', 'branch_id', 'is_active'];
        $sets    = [];
        $params  = [];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $body)) {
                $sets[]   = "$field = ?";
                $params[] = $body[$field];
            }
        }
    }

    // Handle image upload during update
    if (!empty($_FILES['image']['tmp_name'])) {
        $ext     = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg','jpeg','png','webp','gif'];
        if (in_array($ext, $allowed)) {
            $uploadDir = dirname(__DIR__) . '/img/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            $filename   = uniqid('prod_', true) . '.' . $ext;
            move_uploaded_file($_FILES['image']['tmp_name'], $uploadDir . $filename);
            $sets[]   = "image_path = ?";
            $params[] = 'img/' . $filename;
        }
    }

    if (empty($sets)) respond(['success' => false, 'error' => 'Nothing to update.'], 400);

    $params[] = $id;
    $pdo->prepare("UPDATE products SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);

    respond(['success' => true, 'message' => 'Product updated.']);
}

// ── ADJUST STOCK ─────────────────────────────────────────────
if ($action === 'adjust_stock') {
    requireAuth();
    $id    = (int)($_GET['id'] ?? 0);
    $body  = json_decode(file_get_contents('php://input'), true) ?? [];
    $delta = (int)($body['delta'] ?? 0);
    $pdo->prepare("UPDATE products SET stock = GREATEST(0, stock + ?) WHERE id = ?")->execute([$delta, $id]);
    respond(['success' => true, 'message' => 'Stock adjusted.']);
}

// ── DELETE (soft) ────────────────────────────────────────────
if ($action === 'delete') {
    requireAuth();
    $id = (int)($_GET['id'] ?? 0);
    $pdo->prepare("UPDATE products SET is_active = 0 WHERE id = ?")->execute([$id]);
    respond(['success' => true, 'message' => 'Product removed.']);
}

respond(['success' => false, 'error' => 'Unknown action.'], 400);