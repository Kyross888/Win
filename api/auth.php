<?php
// ============================================================
//  api/auth.php  —  Login · Register · Logout · Me
//  All routes:  POST /api/auth.php?action=login   etc.
// ============================================================
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../db.php';

$action = $_GET['action'] ?? '';

switch ($action) {

    // ── LOGIN ────────────────────────────────────────────────
    case 'login':
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $email    = trim($body['email']    ?? '');
        $password = trim($body['password'] ?? '');
        $role     = trim($body['role']     ?? 'staff');

        if (!$email || !$password) {
            respond(['success' => false, 'error' => 'Email and password are required.'], 400);
        }

        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND role = ? LIMIT 1");
        $stmt->execute([$email, $role]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password'])) {
            respond(['success' => false, 'error' => 'Invalid credentials.'], 401);
        }

        // Store safe subset in session
        $_SESSION['user'] = [
            'id'        => $user['id'],
            'name'      => $user['first_name'] . ' ' . $user['last_name'],
            'email'     => $user['email'],
            'role'      => $user['role'],
            'branch_id' => $user['branch_id'],
        ];

        respond(['success' => true, 'user' => $_SESSION['user']]);
        break;

    // ── REGISTER ─────────────────────────────────────────────
    case 'register':
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $first_name  = trim($body['first_name']  ?? '');
        $last_name   = trim($body['last_name']   ?? '');
        $email       = trim($body['email']       ?? '');
        $password    = trim($body['password']    ?? '');
        $role        = trim($body['role']        ?? 'staff');
        $branch      = trim($body['branch']      ?? '');
        $employee_id = trim($body['employee_id'] ?? '');

        if (!$first_name || !$last_name || !$email || !$password) {
            respond(['success' => false, 'error' => 'All fields are required.'], 400);
        }

        // Check duplicate email
        $check = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $check->execute([$email]);
        if ($check->fetch()) {
            respond(['success' => false, 'error' => 'Email already registered.'], 409);
        }

        // Resolve branch key → ID
        // The register form sends keys like 'gen_luna', 'sm_central' etc.
        // Map them directly to the branch IDs from the database
        $branchKeyMap = [
            'festive'    => 1,
            'sm_central' => 2,
            'gen_luna'   => 3,
            'jaro'       => 4,
            'molo'       => 5,
            'la_paz'     => 6,
            'calumpang'  => 7,
            'tagbak'     => 8,
        ];

        $branch_id = null;
        if ($branch) {
            if (isset($branchKeyMap[$branch])) {
                // Direct key match (e.g. 'gen_luna' → 3)
                $branch_id = $branchKeyMap[$branch];
            } else {
                // Fallback: try matching by branch name in DB
                $br = $pdo->prepare("SELECT id FROM branches WHERE name = ? LIMIT 1");
                $br->execute([$branch]);
                $brRow = $br->fetch();
                $branch_id = $brRow ? $brRow['id'] : null;
            }
        }

        $hash = password_hash($password, PASSWORD_BCRYPT);
        $ins  = $pdo->prepare(
            "INSERT INTO users (first_name, last_name, email, password, role, employee_id, branch_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $ins->execute([$first_name, $last_name, $email, $hash, $role, $employee_id, $branch_id]);

        respond(['success' => true, 'message' => 'Account created successfully.']);
        break;

    // ── LOGOUT ───────────────────────────────────────────────
    case 'logout':
        $_SESSION = [];
        session_destroy();
        respond(['success' => true]);
        break;

    // ── ME (session check) ───────────────────────────────────
    case 'me':
        if (empty($_SESSION['user'])) {
            respond(['success' => false, 'error' => 'Not logged in.'], 401);
        }
        $u = $_SESSION['user'];
        // Fetch branch name live from DB so it's always accurate
        if (!empty($u['branch_id'])) {
            $bStmt = $pdo->prepare("SELECT name FROM branches WHERE id = ? LIMIT 1");
            $bStmt->execute([$u['branch_id']]);
            $bRow = $bStmt->fetch();
            $u['branch_name'] = $bRow ? $bRow['name'] : null;
        } else {
            $u['branch_name'] = null;
        }
        respond(['success' => true, 'user' => $u]);
        break;


    // ── CHANGE PASSWORD ──────────────────────────────────────
    case 'change_password':
        $user = requireAuth();
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $currentPw = trim($body['current_password'] ?? '');
        $newPw     = trim($body['new_password']     ?? '');

        if (!$currentPw || !$newPw) {
            respond(['success' => false, 'error' => 'Both fields are required.'], 400);
        }
        if (strlen($newPw) < 6) {
            respond(['success' => false, 'error' => 'Password must be at least 6 characters.'], 400);
        }

        $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$user['id']]);
        $row = $stmt->fetch();

        if (!$row || !password_verify($currentPw, $row['password'])) {
            respond(['success' => false, 'error' => 'Current password is incorrect.'], 401);
        }

        $hash = password_hash($newPw, PASSWORD_BCRYPT);
        $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$hash, $user['id']]);
        respond(['success' => true, 'message' => 'Password updated successfully.']);
        break;

    default:
        respond(['success' => false, 'error' => 'Unknown action.'], 400);
}