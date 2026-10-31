<?php
/**
 * Moneybag SANDBOX demo - single file
 * Flow: form -> create checkout (server) -> redirect to Moneybag -> return -> verify (server)
 *
 * Run locally:  php -S localhost:8000   then open http://localhost:8000/moneybag.php
 * On your site: upload this file and open https://jydhasan2026.xyz/moneybag.php
 */
session_start();

// ====== CONFIG ======
// API key কোডে লিখবেন না, সম্ভব হলে server environment variable ব্যবহার করুন।
// দ্রুত টেস্টের জন্য নিচের 'PASTE_YOUR_SANDBOX_KEY_HERE' জায়গায় বসাতে পারেন।
$API_KEY  = 'e100e5e0.l1zDKGBJ5sJAv31gIbxkR_NLPjTVzNcWdVAYBlln9S4';  // SANDBOX key (শুধু টেস্টের জন্য)
$API_BASE = 'https://sandbox.api.moneybag.com.bd';
$SITE     = 'https://jydhasan2026.xyz';          // locally: 'http://localhost:8000'
$SELF     = $SITE . '/moneybag.php';
// =====================

function moneybag(string $method, string $path, ?array $body = null): array
{
    global $API_KEY, $API_BASE;
    $ch = curl_init($API_BASE . $path);
    $headers = ['X-Merchant-API-Key: ' . $API_KEY, 'Accept: application/json'];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CUSTOMREQUEST  => $method,
    ]);
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return ['http' => $code, 'json' => json_decode((string)$raw, true), 'raw' => $raw, 'error' => $err];
}

function page(string $title, string $html): void
{
    echo "<!doctype html><meta charset='utf-8'><meta name='viewport' content='width=device-width,initial-scale=1'>"
       . "<title>$title</title><body style='font-family:sans-serif;max-width:560px;margin:40px auto;padding:0 16px'>"
       . "<h2>$title</h2>$html</body>";
    exit;
}

$action = $_GET['action'] ?? 'form';

// ---------- 1) Checkout তৈরি ----------
if ($action === 'pay' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $amount  = number_format((float)($_POST['amount'] ?? 0), 2, '.', '');
    $name    = trim($_POST['name'] ?? '');
    $email   = trim($_POST['email'] ?? '');
    $phone   = trim($_POST['phone'] ?? '');

    if ((float)$amount < 10 || !$name || !filter_var($email, FILTER_VALIDATE_EMAIL) || !$phone) {
        page('ভুল তথ্য', "<p>Amount কমপক্ষে 10 BDT, এবং নাম/ইমেইল/ফোন দিন।</p><a href='$SELF'>ফিরে যান</a>");
    }

    $orderId = 'ORD-' . date('YmdHis') . '-' . random_int(100, 999);   // 10-36 অক্ষর হতে হবে

    // ভেরিফাইয়ের সময় মিলিয়ে দেখার জন্য সেশনে রাখা হলো (প্রোডাকশনে ডাটাবেসে রাখবেন)
    $_SESSION['order'] = ['order_id' => $orderId, 'amount' => $amount];

    $res = moneybag('POST', '/api/v2/payments/checkout', [
        'order_id'          => $orderId,
        'currency'          => 'BDT',
        'order_amount'      => $amount,
        'order_description' => 'Website order ' . $orderId,
        'checkout_source'   => 'WEBSITE_CHECKOUT',
        'success_url'       => $SELF . '?action=success',
        'cancel_url'        => $SELF . '?action=cancel',
        'fail_url'          => $SELF . '?action=fail',
        'customer'          => ['name' => $name, 'email' => $email, 'phone' => $phone],
    ]);

    $url = $res['json']['data']['checkout_url'] ?? null;
    if ($res['http'] === 200 && $url) {
        header('Location: ' . $url);   // Moneybag hosted checkout এ পাঠানো
        exit;
    }
    page('Checkout তৈরি হয়নি',
        '<pre>' . htmlspecialchars("HTTP {$res['http']}\n{$res['error']}\n{$res['raw']}") . '</pre>');
}

// ---------- 2) পেমেন্ট শেষে ফেরা: সার্ভার থেকে verify ----------
if ($action === 'success') {
    $txn = $_GET['transaction_id'] ?? '';
    if (!preg_match('/^[A-Za-z0-9_\-]+$/', $txn)) {
        page('ভুল transaction', '<p>transaction_id পাওয়া যায়নি।</p>');
    }

    // URL এর status=SUCCESS বিশ্বাস করবেন না; API থেকে নিশ্চিত হোন
    $res = moneybag('GET', '/api/v2/payments/verify/' . rawurlencode($txn));
    $d   = $res['json']['data'] ?? [];
    $order = $_SESSION['order'] ?? null;

    $verified = $res['http'] === 200 && ($d['verified'] ?? false) === true;
    $matches  = $order
        && ($d['order_id'] ?? '') === $order['order_id']
        && abs((float)($d['amount'] ?? 0) - (float)$order['amount']) < 0.01;

    if ($verified && $matches) {
        // এখানে অর্ডার "paid" করুন (একবারই!). ডাটাবেসে transaction_id সংরক্ষণ করুন।
        unset($_SESSION['order']);
        page('✅ পেমেন্ট সফল',
            '<p>Transaction: <b>' . htmlspecialchars($txn) . '</b><br>Status: '
            . htmlspecialchars($d['status'] ?? '-') . '<br>Amount: ' . htmlspecialchars($d['amount'] ?? '-') . ' '
            . htmlspecialchars($d['currency'] ?? '') . '</p>');
    }
    page('⚠️ Verify হয়নি',
        '<p>অর্ডার paid করা হয়নি।</p><pre>' . htmlspecialchars(print_r($res['json'] ?? $res['raw'], true)) . '</pre>');
}

if ($action === 'cancel') page('পেমেন্ট বাতিল', "<a href='$SELF'>আবার চেষ্টা করুন</a>");
if ($action === 'fail')   page('পেমেন্ট ব্যর্থ', "<a href='$SELF'>আবার চেষ্টা করুন</a>");

// ---------- ফর্ম ----------
page('Moneybag Sandbox Test', "
<form method='post' action='$SELF?action=pay'>
  <p><input name='name'  placeholder='নাম'  value='Test Customer' required style='width:100%;padding:8px'></p>
  <p><input name='email' placeholder='ইমেইল' value='test@example.com' required style='width:100%;padding:8px'></p>
  <p><input name='phone' placeholder='ফোন'  value='+8801700000000' required style='width:100%;padding:8px'></p>
  <p><input name='amount' type='number' step='0.01' min='10' value='100' required style='width:100%;padding:8px'></p>
  <button style='padding:10px 18px'>Pay with Moneybag (Sandbox)</button>
</form>");
