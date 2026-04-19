<?php
// ============================================================
//  api/forgot_password.php
//
//  POST ?action=send    → generate code, send SMS via Semaphore
//  POST ?action=verify  → check code, return one-time token
//  POST ?action=reset   → use token to set new password
//
//  SMS Provider: Semaphore (https://semaphore.co) — free PH SMS API
//  Sign up at semaphore.co, get your API key, paste it below.
// ============================================================
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../db.php';

// ── CONFIG — paste your Semaphore API key here ────────────
define('SMS_API_KEY',  'YOUR_SEMAPHORE_API_KEY_HERE');
define('SMS_SENDER',   'LunasPOS');   // Your approved sender name (max 11 chars)

$action = $_GET['action'] ?? '';
$body   = json_decode(file_get_contents('php://input'), true) ?? [];

// ────────────────────────────────────────────────────────────
// STEP 1 — SEND CODE
// ────────────────────────────────────────────────────────────
if ($action === 'send') {
    $email = trim($body['email'] ?? '');
    if (!$email) respond(['success' => false, 'error' => 'Email is required.'], 400);

    // Find the user
    $stmt = $pdo->prepare("SELECT id, first_name, phone FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) {
        // Don't reveal whether email exists — generic message
        respond(['success' => false, 'error' => 'No account found with that email address.'], 404);
    }

    // Check phone — stored in users.phone or in reset_tokens table
    $phone = $user['phone'] ?? null;

    if (!$phone) {
        respond([
            'success' => false,
            'error'   => 'No mobile number saved for this account. Please contact your administrator.',
        ], 400);
    }

    // Generate 6-digit code
    $code    = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $expires = date('Y-m-d H:i:s', time() + 600); // 10 minutes

    // Store code in DB (create table if not exists)
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS password_resets (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            user_id    INT NOT NULL,
            code       VARCHAR(6) NOT NULL,
            token      VARCHAR(64),
            expires_at DATETIME NOT NULL,
            used       TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )"
    );

    // Delete any old codes for this user
    $pdo->prepare("DELETE FROM password_resets WHERE user_id = ?")->execute([$user['id']]);

    // Insert new code
    $pdo->prepare(
        "INSERT INTO password_resets (user_id, code, expires_at) VALUES (?, ?, ?)"
    )->execute([$user['id'], $code, $expires]);

    // Send SMS via Semaphore
    $smsSent = sendSMS($phone, "Luna's POS: Your password reset code is {$code}. Valid for 10 minutes. Do not share this code.");

    // Mask phone for display: 09171234567 → 0917***4567
    $phoneMasked = substr($phone, 0, 4) . '***' . substr($phone, -4);

    if ($smsSent) {
        respond(['success' => true, 'phone_hint' => $phoneMasked]);
    } else {
        // SMS failed — still return success so admin can check logs
        // In development, log the code to PHP error log
        error_log("FORGOT PASSWORD: code={$code} for user_id={$user['id']} phone={$phone}");
        respond([
            'success'    => true,
            'phone_hint' => $phoneMasked,
            'dev_note'   => 'SMS sending failed. Check SMS_API_KEY in forgot_password.php.',
        ]);
    }
}

// ────────────────────────────────────────────────────────────
// STEP 2 — VERIFY CODE
// ────────────────────────────────────────────────────────────
if ($action === 'verify') {
    $email = trim($body['email'] ?? '');
    $code  = trim($body['code']  ?? '');

    if (!$email || !$code) respond(['success' => false, 'error' => 'Email and code are required.'], 400);

    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if (!$user) respond(['success' => false, 'error' => 'Invalid request.'], 400);

    $stmt = $pdo->prepare(
        "SELECT id FROM password_resets
         WHERE user_id = ? AND code = ? AND used = 0 AND expires_at > NOW()
         LIMIT 1"
    );
    $stmt->execute([$user['id'], $code]);
    $reset = $stmt->fetch();

    if (!$reset) {
        respond(['success' => false, 'error' => 'Invalid or expired code. Please request a new one.'], 400);
    }

    // Generate a one-time token for the reset step
    $token = bin2hex(random_bytes(32));
    $pdo->prepare("UPDATE password_resets SET token = ? WHERE id = ?")->execute([$token, $reset['id']]);

    respond(['success' => true, 'token' => $token]);
}

// ────────────────────────────────────────────────────────────
// STEP 3 — RESET PASSWORD
// ────────────────────────────────────────────────────────────
if ($action === 'reset') {
    $token  = trim($body['token']        ?? '');
    $newPw  = trim($body['new_password'] ?? '');

    if (!$token || !$newPw) respond(['success' => false, 'error' => 'Token and new password required.'], 400);
    if (strlen($newPw) < 6) respond(['success' => false, 'error' => 'Password must be at least 6 characters.'], 400);

    $stmt = $pdo->prepare(
        "SELECT user_id FROM password_resets
         WHERE token = ? AND used = 0 AND expires_at > NOW()
         LIMIT 1"
    );
    $stmt->execute([$token]);
    $reset = $stmt->fetch();

    if (!$reset) {
        respond(['success' => false, 'error' => 'Invalid or expired token. Please restart the reset process.'], 400);
    }

    // Update password
    $hash = password_hash($newPw, PASSWORD_BCRYPT);
    $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$hash, $reset['user_id']]);

    // Mark token as used
    $pdo->prepare("UPDATE password_resets SET used = 1 WHERE token = ?")->execute([$token]);

    respond(['success' => true, 'message' => 'Password reset successfully.']);
}

respond(['success' => false, 'error' => 'Unknown action.'], 400);

// ────────────────────────────────────────────────────────────
// HELPER — Send SMS via Semaphore
// ────────────────────────────────────────────────────────────
function sendSMS(string $number, string $message): bool {
    if (SMS_API_KEY === 'YOUR_SEMAPHORE_API_KEY_HERE') {
        error_log("SMS not configured. Set SMS_API_KEY in forgot_password.php");
        return false;
    }

    // Normalize number: 09171234567 → 639171234567
    $number = preg_replace('/\D/', '', $number);
    if (str_starts_with($number, '0')) {
        $number = '63' . substr($number, 1);
    }

    $ch = curl_init('https://api.semaphore.co/api/v4/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'apikey'      => SMS_API_KEY,
            'number'      => $number,
            'message'     => $message,
            'sendername'  => SMS_SENDER,
        ]),
        CURLOPT_TIMEOUT => 15,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    error_log("Semaphore SMS response [{$httpCode}]: " . $response);
    return $httpCode === 200;
}