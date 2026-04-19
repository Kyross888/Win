<?php
// ============================================================
//  api/admin_stats.php  —  Real data for the Admin dashboard
//
//  GET ?action=all_branches  → sales KPIs per branch (today)
//  GET ?action=branch&id=3   → KPIs + recent transactions for one branch
//  GET ?action=totals        → system-wide totals today
// ============================================================
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once '../db.php';

$user = requireAuth();
if ($user['role'] !== 'admin') {
    respond(['success' => false, 'error' => 'Admin access required.'], 403);
}

$action = $_GET['action'] ?? 'all_branches';

// ── ALL BRANCHES — one row per branch with today's totals ──
if ($action === 'all_branches') {
    $stmt = $pdo->query(
        "SELECT
            b.id,
            b.name,
            b.address,
            COALESCE(SUM(t.total), 0)  AS sales_today,
            COUNT(t.id)                 AS orders_today
         FROM branches b
         LEFT JOIN transactions t
               ON  t.branch_id   = b.id
               AND DATE(t.created_at) = CURDATE()
               AND t.status      = 'completed'
         GROUP BY b.id, b.name, b.address
         ORDER BY b.id ASC"
    );
    respond(['success' => true, 'data' => $stmt->fetchAll()]);
}

// ── SINGLE BRANCH — KPIs + last 50 transactions with item summary ──
if ($action === 'branch') {
    $branchId = (int)($_GET['id'] ?? 0);
    if (!$branchId) respond(['success' => false, 'error' => 'Branch ID required.'], 400);

    $kpi = $pdo->prepare(
        "SELECT
            COALESCE(SUM(total), 0) AS sales_today,
            COUNT(*)                 AS orders_today
         FROM transactions
         WHERE branch_id        = ?
           AND DATE(created_at) = CURDATE()
           AND status           = 'completed'"
    );
    $kpi->execute([$branchId]);
    $kpiRow = $kpi->fetch();

    $txns = $pdo->prepare(
        "SELECT
            t.id,
            t.reference_no,
            t.order_type,
            t.payment_method,
            t.total,
            t.created_at,
            GROUP_CONCAT(
                CONCAT(ti.quantity, 'x ', ti.product_name)
                ORDER BY ti.id SEPARATOR ', '
            ) AS items_summary
         FROM transactions t
         LEFT JOIN transaction_items ti ON ti.transaction_id = t.id
         WHERE t.branch_id        = ?
           AND t.status           = 'completed'
         GROUP BY t.id
         ORDER BY t.created_at DESC
         LIMIT 50"
    );
    $txns->execute([$branchId]);

    respond([
        'success'      => true,
        'kpi'          => $kpiRow,
        'transactions' => $txns->fetchAll(),
    ]);
}

// ── SYSTEM-WIDE TOTALS ─────────────────────────────────────
if ($action === 'totals') {
    $stmt = $pdo->query(
        "SELECT
            COALESCE(SUM(total), 0) AS total_revenue,
            COUNT(*)                 AS total_orders
         FROM transactions
         WHERE DATE(created_at) = CURDATE()
           AND status           = 'completed'"
    );
    respond(['success' => true, 'data' => $stmt->fetch()]);
}

respond(['success' => false, 'error' => 'Unknown action.'], 400);