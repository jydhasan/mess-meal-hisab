<?php
/**
 * Moneybag SANDBOX webhook receiver
 * Upload as: https://jydhasan2026.xyz/api/moneybag-webhook.php
 *
 * ধাপ ১ (এই ফাইল): আসা রিকোয়েস্ট (header + raw body) লগে রাখে, এবং payment.success/failed এলে
 * Moneybag verify API দিয়ে আসল অবস্থা নিশ্চিত করে। অর্থাৎ webhook এর কথা সরাসরি বিশ্বাস করা হয় না।
 *
 * TODO: Moneybag এর signature header/পদ্ধতি নিশ্চিত হলে এখানে whsec_ secret দিয়ে signature যাচাই যোগ করুন।
 */

$API_KEY  = 'e100e5e0.l1zDKGBJ5sJAv31gIbxkR_NLPjTVzNcWdVAYBlln9S4';  // SANDBOX key (শুধু টেস্টের জন্য)
$API_BASE = 'https://sandbox.api.moneybag.com.bd';
// লগ web root (public_html) এর বাইরে: /home/cpanelUser/private/logs/
$LOG_DIR  = dirname(__DIR__, 2) . '/private/logs';
if (!is_dir($LOG_DIR)) {
    @mkdir($LOG_DIR, 0750, true);
}
$LOG_FILE = $LOG_DIR . '/moneybag-webhook.log';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

$raw = file_get_contents('php://input');          // raw body, signature যাচাইয়ের জন্য এটাই লাগবে
$headers = function_exists('getallheaders') ? getallheaders() : [];

function logline(string $msg): void
{
    global $LOG_FILE;
    file_put_contents($LOG_FILE, '[' . date('c') . '] ' . $msg . PHP_EOL, FILE_APPEND | LOCK_EX);
}

// কোন header গুলো আসছে সেটা দেখার জন্য (signature header এর নাম এখান থেকেই জানা যাবে)
logline('HEADERS ' . json_encode($headers));
logline('BODY ' . $raw);

$event = json_decode($raw, true) ?: [];

// event type ও transaction id বের করার চেষ্টা (field নাম payload দেখে ঠিক করে নেবেন)
$type = $event['event'] ?? $event['event_type'] ?? $event['type'] ?? '';
$txn  = $event['data']['transaction_id'] ?? $event['transaction_id'] ?? '';

// Moneybag দ্রুত 200 চায়, তাই আগে উত্তর দিয়ে দিই
http_response_code(200);
header('Content-Type: application/json');
echo json_encode(['received' => true]);
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

// ---- এরপর ব্যাকগ্রাউন্ডে কাজ ----
if (!$txn || !preg_match('/^[A-Za-z0-9_\-]+$/', $txn)) {
    logline('No usable transaction_id in payload, skipping');
    exit;
}

$ch = curl_init($API_BASE . '/api/v2/payments/verify/' . rawurlencode($txn));
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_HTTPHEADER     => ['X-Merchant-API-Key: ' . $API_KEY, 'Accept: application/json'],
]);
$resp = curl_exec($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$d = json_decode((string)$resp, true)['data'] ?? [];
logline("VERIFY type=$type txn=$txn http=$http verified=" . var_export($d['verified'] ?? null, true)
      . ' status=' . ($d['status'] ?? '-') . ' order=' . ($d['order_id'] ?? '-'));

if ($http === 200 && ($d['verified'] ?? false) === true) {
    // এখানে ডাটাবেসে order_id অনুযায়ী অর্ডার "paid" করুন।
    // একই event দুইবার আসতে পারে, তাই আগে দেখুন অর্ডার আগেই paid কিনা (idempotent রাখুন)।
}
