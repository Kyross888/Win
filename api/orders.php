<?php
// ============================================================
//  api/orders.php  — Place, list, get, void orders
// ============================================================
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../db.php';

$action = $_GET['action'] ?? '';

// ── PLACE ORDER ──────────────────────────────────────────────
if ($action === 'place') {
    $user = requireAuth();
    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    $items          = $body['items']           ?? [];
    $order_type     = $body['order_type']      ?? 'Dine-in';
    $payment_method = $body['payment_method']  ?? 'Cash';
    $subtotal       = (float)($body['subtotal']       ?? 0);
    $discount       = (float)($body['discount']       ?? 0);
    $coupon_discount= (float)($body['coupon_discount'] ?? 0);
    $total          = (float)($body['total']          ?? 0);
    $customer_id    = $body['customer_id']     ?? null;

    if (empty($items)) respond(['success' => false, 'error' => 'No items in order.'], 400);

    // Generate reference number
    $ref = 'REF-' . strtoupper(substr(uniqid(), -6));

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO transactions
             (reference_no, branch_id, user_id, order_type, payment_method,
              subtotal, discount, coupon_discount, total, customer_id, status)
             VALUES (?,?,?,?,?,?,?,?,?,?,'completed')"
        );
        $stmt->execute([
            $ref,
            $user['branch_id'] ?? null,
            $user['id'],
            $order_type,
            $payment_method,
            $subtotal,
            $discount,
            $coupon_discount,
            $total,
            $customer_id ?: null,
        ]);
        $txnId = $pdo->lastInsertId();

        foreach ($items as $item) {
            $qty       = (int)($item['quantity'] ?? 1);
            $unitPrice = (float)($item['price'] ?? 0);
            $lineTotal = $qty * $unitPrice;

            $pdo->prepare(
                "INSERT INTO transaction_items
                 (transaction_id, product_id, product_name, unit_price, quantity, line_total)
                 VALUES (?,?,?,?,?,?)"
            )->execute([
                $txnId,
                $item['product_id'] ?? null,
                $item['name']       ?? '',
                $unitPrice,
                $qty,
                $lineTotal,
            ]);

            // Deduct stock
            if (!empty($item['product_id'])) {
                $pdo->prepare(
                    "UPDATE products SET stock = GREATEST(0, stock - ?) WHERE id = ?"
                )->execute([$qty, $item['product_id']]);
            }
        }

        $pdo->commit();
        respond(['success' => true, 'reference_no' => $ref, 'transaction_id' => $txnId]);
    } catch (Exception $e) {
        $pdo->rollBack();
        respond(['success' => false, 'error' => 'Order failed: ' . $e->getMessage()], 500);
    }
}

// ── LIST ORDERS ──────────────────────────────────────────────
if ($action === 'list') {
    requireAuth();
    $page  = max(1, (int)($_GET['page'] ?? 1));
    $limit = 50;
    $offset= ($page - 1) * $limit;

    $stmt = $pdo->prepare(
        "SELECT * FROM transactions
         WHERE status = 'completed'
         ORDER BY created_at DESC
         LIMIT ? OFFSET ?"
    );
    $stmt->execute([$limit, $offset]);
    respond(['success' => true, 'data' => $stmt->fetchAll()]);
}

// ── GET SINGLE ORDER (with items) ────────────────────────────
if ($action === 'get') {
    requireAuth();
    $id   = (int)($_GET['id'] ?? 0);
    $stmt = $pdo->prepare("SELECT * FROM transactions WHERE id = ?");
    $stmt->execute([$id]);
    $txn  = $stmt->fetch();
    if (!$txn) respond(['success' => false, 'error' => 'Order not found.'], 404);

    $items = $pdo->prepare(
        "SELECT * FROM transaction_items WHERE transaction_id = ?"
    );
    $items->execute([$id]);
    $txn['items'] = $items->fetchAll();

    respond(['success' => true, 'data' => $txn]);
}

// ── VOID ORDER ───────────────────────────────────────────────
if ($action === 'void') {
    requireAuth();
    $id = (int)($_GET['id'] ?? 0);
    $pdo->prepare("UPDATE transactions SET status = 'voided' WHERE id = ?")->execute([$id]);
    respond(['success' => true]);
}

respond(['success' => false, 'error' => 'Unknown action.'], 400);
