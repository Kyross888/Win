<?php
// ============================================================
//  api/dashboard.php  —  KPI cards + chart data
//
//  GET ?action=kpis          → today's sales summary
//  GET ?action=revenue_trend → last-7-days revenue
//  GET ?action=order_sources → dine-in/takeout/coupon split
//  GET ?action=top_products  → best sellers
// ============================================================
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once '../db.php';

$action   = $_GET['action']    ?? 'kpis';
$branchId = $_GET['branch_id'] ?? '';

$branchFilter = '';
$branchParam  = [];
if ($branchId) {
    $branchFilter = " AND branch_id = ?";
    $branchParam  = [(int)$branchId];
}

// ── KPIS ─────────────────────────────────────────────────────
if ($action === 'kpis') {
    requireAuth();

    // Today's total revenue
    $rev = $pdo->prepare(
        "SELECT COALESCE(SUM(total), 0) AS revenue,
                COUNT(*) AS orders
         FROM transactions
         WHERE DATE(created_at) = CURDATE()
           AND status = 'completed'
           $branchFilter"
    );
    $rev->execute($branchParam);
    $row = $rev->fetch();

    // Low stock (≤ 10) and out of stock (= 0)
    $low  = $pdo->query("SELECT COUNT(*) FROM products WHERE stock > 0 AND stock <= 10 AND is_active = 1")->fetchColumn();
    $out  = $pdo->query("SELECT COUNT(*) FROM products WHERE stock = 0 AND is_active = 1")->fetchColumn();

    respond([
        'success'        => true,
        'revenue_today'  => (float)$row['revenue'],
        'orders_today'   => (int)  $row['orders'],
        'low_stock'      => (int)  $low,
        'out_of_stock'   => (int)  $out,
    ]);
}

// ── REVENUE TREND (last 7 days) ───────────────────────────────
if ($action === 'revenue_trend') {
    requireAuth();

    $stmt = $pdo->prepare(
        "SELECT DATE(created_at) AS day,
                COALESCE(SUM(total), 0) AS revenue
         FROM transactions
         WHERE created_at >= CURDATE() - INTERVAL 6 DAY
           AND status = 'completed'
           $branchFilter
         GROUP BY DATE(created_at)
         ORDER BY day ASC"
    );
    $stmt->execute($branchParam);
    respond(['success' => true, 'data' => $stmt->fetchAll()]);
}

// ── ORDER SOURCES ────────────────────────────────────────────
if ($action === 'order_sources') {
    requireAuth();

    $stmt = $pdo->prepare(
        "SELECT order_type AS label, COUNT(*) AS value
         FROM transactions
         WHERE DATE(created_at) = CURDATE()
           AND status = 'completed'
           $branchFilter
         GROUP BY order_type"
    );
    $stmt->execute($branchParam);
    respond(['success' => true, 'data' => $stmt->fetchAll()]);
}

// ── TOP PRODUCTS ──────────────────────────────────────────────
if ($action === 'top_products') {
    requireAuth();

    $stmt = $pdo->prepare(
        "SELECT ti.product_name AS name,
                SUM(ti.quantity) AS total_qty,
                SUM(ti.line_total) AS total_revenue
         FROM transaction_items ti
         JOIN transactions t ON ti.transaction_id = t.id
         WHERE DATE(t.created_at) = CURDATE()
           AND t.status = 'completed'
           $branchFilter
         GROUP BY ti.product_name
         ORDER BY total_qty DESC
         LIMIT 5"
    );
    $stmt->execute($branchParam);
    respond(['success' => true, 'data' => $stmt->fetchAll()]);
}

respond(['success' => false, 'error' => 'Unknown action.'], 400);
