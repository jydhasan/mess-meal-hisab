<?php
declare(strict_types=1);

/* ------------------------------------------------------------------
 * Database settings — edit these to match your MySQL server.
 * XAMPP / WAMP defaults are usually: host 127.0.0.1, user root, no password.
 * ------------------------------------------------------------------ */
const DB_HOST = 'sql306.infinityfree.com';
const DB_PORT = 3306;
const DB_NAME = 'if0_41895540_mess_db';
const DB_USER = 'if0_41895540';
const DB_PASS = '9NJngzxrrPrC5L';

/* Uploaded vouchers / attachments */
const UPLOAD_DIR       = __DIR__ . '/uploads';
const UPLOAD_URL       = 'uploads';          // relative URL used by the browser
const MAX_UPLOAD_BYTES = 5 * 1024 * 1024;    // 5 MB

/* Default members used to seed an empty members table */
const DEFAULT_MEMBERS = ['Zahid', 'Sojib', 'Ajharul', 'Rakib', 'Siyam', 'Akib', 'Saif'];

/* The timezone used to decide which day "today" is. */
const APP_TIMEZONE = 'Asia/Dhaka';

/* Admin panel passcode. It is required for backdated meal changes and data
   imports. Change 'change-me' before putting the site online. Leave '' only
   to disable admin access completely. */
const ADMIN_KEY = 'change-me';

/**
 * Shared PDO connection (created once per request).
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    return $pdo;
}

/**
 * Amount of members, sorted the way the sheet shows them.
 */
function members_list(): array
{
    $sql = 'SELECT id, name FROM members ORDER BY sort_order, id';
    $rows = db()->query($sql)->fetchAll();

    if (!$rows) {
        seed_members(db());
        $rows = db()->query($sql)->fetchAll();
    }

    return array_map(
        static fn(array $r): array => ['id' => (int) $r['id'], 'name' => $r['name']],
        $rows
    );
}

/**
 * Insert the default 7 members if the table is empty.
 */
function seed_members(PDO $pdo): void
{
    $stmt = $pdo->prepare('INSERT IGNORE INTO members (name, sort_order) VALUES (?, ?)');
    foreach (DEFAULT_MEMBERS as $i => $name) {
        $stmt->execute([$name, $i + 1]);
    }
}
