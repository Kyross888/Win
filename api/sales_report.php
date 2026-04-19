<?php
// ============================================================
//  api/sales_report.php  —  Daily / ranged sales summary
// ============================================================
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once '../db.php';

requireAuth();

$date_from = $_GET['date_from'] ?? date('Y-m-d');
$date_to   = $_GET['date_to']   ?? $date_from;
$branch_id = $_GET['branch_id'] ?? '';

$branchFilter = '';
$params       = [$date_from, $date_to];
if ($branch_id) { $branchFilter = " AND t.branch_id = ?"; $params[] = (int)$branch_id; }

// Summary KPIs
$kpi = $pdo->prepare(
    "SELECT
        COALESCE(SUM(t.total), 0)    AS total_revenue,
        COUNT(t.id)                   AS total_orders,
        COALESCE(SUM(t.discount + t.coupon_discount), 0) AS total_discounts
     FROM transactions t
     WHERE DATE(t.created_at) BETWEEN ? AND ?
       AND t.status = 'completed'
       $branchFilter"
);
$kpi->execute($params);
$summary = $kpi->fetch();

// Transaction rows
$rows = $pdo->prepare(
    "SELECT t.id, t.reference_no, t.order_type, t.payment_method,
            t.subtotal, t.discount, t.coupon_discount, t.total,
            t.created_at,
            u.first_name, u.last_name,
            (SELECT COUNT(*) FROM transaction_items WHERE transaction_id = t.id) AS item_count
     FROM transactions t
     LEFT JOIN users u ON t.user_id = u.id
     WHERE DATE(t.created_at) BETWEEN ? AND ?
       AND t.status = 'completed'
       $branchFilter
     ORDER BY t.created_at DESC"
);
$rows->execute($params);

// Best seller today
$best = $pdo->prepare(
    "SELECT ti.product_name, SUM(ti.quantity) AS qty
     FROM transaction_items ti
     JOIN transactions t ON ti.transaction_id = t.id
     WHERE DATE(t.created_at) BETWEEN ? AND ?
       AND t.status = 'completed'
       $branchFilter
     GROUP BY ti.product_name
     ORDER BY qty DESC LIMIT 1"
);
$best->execute($params);
$bestSeller = $best->fetch();

respond([
    'success'     => true,
    'summary'     => $summary,
    'best_seller' => $bestSeller,
    'transactions'=> $rows->fetchAll(),
]);
