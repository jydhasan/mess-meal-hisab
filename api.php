<?php
declare(strict_types=1);

require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

/**
 * Everything the app does goes through this one endpoint:
 *   GET  api.php?action=data&month=YYYY-MM
 *   POST api.php?action=save_meal      {date, member_id, morning, noon, night,
 *                                       version, admin_token?}
 *   POST api.php?action=admin_login    {admin_key}
 *   POST api.php?action=admin_logout
 *   GET  api.php?action=admin_config
 *   POST api.php?action=save_bazar     multipart: date, member_id, item, cost, file
 *   POST api.php?action=delete_bazar   {id}
 *   POST api.php?action=save_deposit   multipart: date, member_id, amount, file
 *   POST api.php?action=delete_deposit {id}
 *   POST api.php?action=import         {meals:{...}, bazar:[...], deposit:[...],
 *                                       admin_token}
 *
 * A boarder may save today or a future date. Only an authenticated admin may
 * save a past date. `version` is checked against the row as it is stored right
 * now, and every past-date change lands in meal_changes.
 */
$action = isset($_GET['action']) ? (string) $_GET['action'] : '';

/* If PHP threw the body away because the upload was too big, say so instead
   of complaining about a missing date. */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
    && !$_POST && !$_FILES
    && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0
    && file_get_contents('php://input') === '') {
    fail('The upload was bigger than this server accepts (post_max_size = ' . ini_get('post_max_size') . ').');
}

try {
    switch ($action) {
        case 'data':           $result = action_data();            break;
        case 'save_meal':      $result = action_save_meal();       break;
        case 'admin_login':    $result = action_admin_login();     break;
        case 'admin_logout':   $result = action_admin_logout();    break;
        case 'admin_config':   $result = action_admin_config();    break;
        case 'save_bazar':     $result = action_save_bazar();      break;
        case 'delete_bazar':   $result = action_delete('bazar');   break;
        case 'save_deposit':   $result = action_save_deposit();    break;
        case 'delete_deposit': $result = action_delete('deposits'); break;
        case 'import':         $result = action_import();          break;
        case 'ping':           $result = ['message' => 'ok'];      break;
        default:               fail('Unknown action "' . $action . '".');
    }

    echo json_encode(['ok' => true] + $result, JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'ok'    => false,
        'error' => 'Database error: ' . $e->getMessage()
            . ' — check config.php and make sure schema.sql was imported.',
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

/* ------------------------------------------------------------------ */
/*  helpers                                                            */
/* ------------------------------------------------------------------ */

/** Stop the request with a readable message. */
function fail(string $message, int $status = 400): void
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

/** Decoded JSON request body (falls back to $_POST for form posts). */
function json_body(): array
{
    $raw = file_get_contents('php://input');
    if (is_string($raw) && $raw !== '') {
        $data = json_decode($raw, true);
        if (is_array($data)) {
            return $data;
        }
    }

    return $_POST;
}

/** Validate a YYYY-MM-DD string. */
function req_date(?string $value): string
{
    $value = trim((string) $value);
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$d || $d->format('Y-m-d') !== $value) {
        fail('Please provide a valid date (YYYY-MM-DD).');
    }

    return $value;
}

/** Validate a member id and confirm the member exists. */
function req_member_id(mixed $value): int
{
    $id = (int) $value;
    if ($id <= 0) {
        fail('Please select a member.');
    }

    $stmt = db()->prepare('SELECT COUNT(*) FROM members WHERE id = ?');
    $stmt->execute([$id]);
    if (!(int) $stmt->fetchColumn()) {
        fail('That member does not exist any more.');
    }

    return $id;
}

/** Amount as a positive number (bazar allows 0, deposit needs > 0 by default). */
function req_amount(mixed $value, bool $allowZero = false): float
{
    if (!is_numeric($value)) {
        fail('Please enter a valid number.');
    }
    $amount = round((float) $value, 2);
    if ($amount < 0 || (!$allowZero && $amount <= 0)) {
        fail($allowZero ? 'Amount cannot be negative.' : 'Please enter an amount greater than zero.');
    }

    return $amount;
}

function month_range(string $month): array
{
    if (!preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $month, $m)) {
        $month = date('Y-m');
        [$y, $mo] = array_map('intval', explode('-', $month));
    } else {
        $y  = (int) $m[1];
        $mo = (int) $m[2];
    }

    $start = sprintf('%04d-%02d-01', $y, $mo);

    return [$month, $start, date('Y-m-t', strtotime($start))];
}

/** "Now" in the mess timezone — the server itself may be on UTC. */
function now_local(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone(APP_TIMEZONE));
}

/* ------------------------------------------------------------------ */
/*  admin authentication                                               */
/*  A signed, expiring bearer token avoids relying on PHP session      */
/*  storage, which is not always available on shared hosting.          */
/* ------------------------------------------------------------------ */

function base64url_encode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function base64url_decode(string $value): ?string
{
    $padding = strlen($value) % 4;
    if ($padding) {
        $value .= str_repeat('=', 4 - $padding);
    }
    $decoded = base64_decode(strtr($value, '-_', '+/'), true);

    return $decoded === false ? null : $decoded;
}

/** Issue an 8-hour admin token signed with the configured ADMIN_KEY. */
function issue_admin_token(): string
{
    $payload = base64url_encode(json_encode([
        'exp'   => time() + (8 * 60 * 60),
        'nonce' => bin2hex(random_bytes(16)),
    ], JSON_THROW_ON_ERROR));
    $signature = hash_hmac('sha256', $payload, ADMIN_KEY, true);

    return $payload . '.' . base64url_encode($signature);
}

/** Validate the token signature and expiry. No server-side session needed. */
function verify_admin_token(?string $token): bool
{
    if (!is_string($token) || ADMIN_KEY === '') {
        return false;
    }
    $parts = explode('.', $token, 2);
    if (count($parts) !== 2) {
        return false;
    }

    [$payload, $providedSignature] = $parts;
    $signature = base64url_decode($providedSignature);
    $expected  = hash_hmac('sha256', $payload, ADMIN_KEY, true);
    if ($signature === null || !hash_equals($expected, $signature)) {
        return false;
    }

    $decoded = base64url_decode($payload);
    $data    = is_string($decoded) ? json_decode($decoded, true) : null;
    if (!is_array($data) || !isset($data['exp']) || !is_int($data['exp'])) {
        return false;
    }

    return $data['exp'] >= time();
}

/** Admin token may arrive in a header (reads) or JSON body (writes). */
function request_admin_token(): string
{
    $token = $_SERVER['HTTP_X_ADMIN_TOKEN'] ?? '';

    return is_string($token) ? trim($token) : '';
}

function admin_state(): array
{
    $token = request_admin_token();

    return ['is_admin' => verify_admin_token($token), 'token' => $token];
}

/** Require a valid admin token to change a past meal or import data. */
function require_admin(?string $token): void
{
    if (!verify_admin_token($token)) {
        fail('Admin sign-in is required, or the admin token has expired.', 401);
    }
}

function action_admin_login(): array
{
    if (ADMIN_KEY === '') {
        fail('Admin access is not configured. Set ADMIN_KEY in config.php.', 503);
    }

    $key = trim((string) (json_body()['admin_key'] ?? ''));
    if ($key === '' || !hash_equals(ADMIN_KEY, $key)) {
        usleep(250000);
        fail('Incorrect admin passcode. Use the exact ADMIN_KEY value from config.php.', 403);
    }

    return [
        'is_admin' => true,
        'token'    => issue_admin_token(),
        'expires_in' => 8 * 60 * 60,
    ];
}

function action_admin_logout(): array
{
    // Tokens are stateless. The browser deletes its copy on sign-out.
    return ['is_admin' => false];
}

function action_admin_config(): array
{
    return [
        'admin_configured'     => ADMIN_KEY !== '',
        'admin_key_is_default' => ADMIN_KEY === 'change-me',
        'auth_mode'            => 'signed-token',
    ];
}

/* ------------------------------------------------------------------ */
/*  meal edit policy                                                   */
/*  Today and future dates are open to boarders. Past dates are admin. */
/* ------------------------------------------------------------------ */

/** Is this date today or in the future, in the mess timezone? */
function meal_is_open(string $date): bool
{
    return $date >= now_local()->format('Y-m-d');
}

/**
 * The rule, in a form the browser can use to grey out the form.
 * The server date is authoritative even if the visitor's device clock is off.
 */
function meal_policy(): array
{
    $now   = now_local();
    $admin = admin_state();

    return [
        'today'                => $now->format('Y-m-d'),
        'server_time'          => $now->format('Y-m-d H:i:s'),
        'timezone'             => APP_TIMEZONE,
        'open_from'            => $now->format('Y-m-d'),
        'backdate_admin_only'  => true,
        'admin_configured'     => ADMIN_KEY !== '',
        'admin_key_is_default' => ADMIN_KEY === 'change-me',
        'is_admin'              => $admin['is_admin'],
        'admin_token'           => $admin['token'],
    ];
}

/** Best-effort client address for the change log. */
function client_ip(): ?string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';

    return is_string($ip) && $ip !== '' ? substr($ip, 0, 45) : null;
}

/* ------------------------------------------------------------------ */
/*  reading                                                            */
/* ------------------------------------------------------------------ */

function action_data(): array
{
    [$month, $start, $end] = month_range(isset($_GET['month']) ? (string) $_GET['month'] : '');

    $members = members_list();

    $stmt = db()->prepare(
        'SELECT member_id, meal_date, morning, noon, night, updated_at
           FROM meals
          WHERE meal_date BETWEEN ? AND ?'
    );
    $stmt->execute([$start, $end]);

    $meals   = [];
    $updates = [];
    foreach ($stmt as $row) {
        $memberId = (string) (int) $row['member_id'];
        $meals[$row['meal_date']][$memberId] = [
            (int) $row['morning'],
            (int) $row['noon'],
            (int) $row['night'],
        ];
        // Sent back by the browser on save: if it no longer matches, somebody
        // else changed this day in the meantime and we refuse to overwrite it.
        $updates[$row['meal_date']][$memberId] = (string) $row['updated_at'];
    }

    return [
        'month'        => $month,
        'members'      => $members,
        'meals'        => $meals,
        'meal_updates' => $updates,
        'policy'       => meal_policy(),
        'bazar'        => list_entries('bazar', $start, $end),
        'deposits'     => list_entries('deposits', $start, $end),
    ] + meal_changes_for_month($start, $end);
}

/**
 * Change log for one month. `late_edits` marks the (day, member) cells that
 * were changed after that day had already closed, `change_log` is the full
 * list for the "who changed what" panel.
 */
function meal_changes_for_month(string $start, string $end): array
{
    try {
        $stmt = db()->prepare(
            'SELECT c.id, c.member_id, c.meal_date, c.changed_at, c.source,
                    c.locked_override, m.name,
                    c.old_morning, c.old_noon, c.old_night,
                    c.new_morning, c.new_noon, c.new_night
               FROM meal_changes c
               LEFT JOIN members m ON m.id = c.member_id
              WHERE c.meal_date BETWEEN ? AND ?
              ORDER BY c.changed_at DESC, c.id DESC
              LIMIT 400'
        );
        $stmt->execute([$start, $end]);
    } catch (PDOException $e) {
        // migrate_meal_lock.sql has not been imported yet — the sheet still
        // works, only the log stays empty.
        return ['late_edits' => [], 'change_log' => [], 'log_available' => false];
    }

    $late = [];
    $log  = [];
    foreach ($stmt as $row) {
        $key = (string) (int) $row['member_id'];
        if ((int) $row['locked_override'] === 1) {
            $late[$row['meal_date']][$key] = ($late[$row['meal_date']][$key] ?? 0) + 1;
        }
        $log[] = [
            'id'        => (int) $row['id'],
            'date'      => $row['meal_date'],
            'member_id' => (int) $row['member_id'],
            'name'      => $row['name'] ?? '—',
            'at'        => (string) $row['changed_at'],
            'source'    => (string) $row['source'],
            'late'      => (int) $row['locked_override'] === 1,
            'old'       => [(int) $row['old_morning'], (int) $row['old_noon'], (int) $row['old_night']],
            'new'       => [(int) $row['new_morning'], (int) $row['new_noon'], (int) $row['new_night']],
        ];
    }

    return ['late_edits' => $late, 'change_log' => $log, 'log_available' => true];
}

/** Rows for bazar / deposits, newest last, with the attachment URL prepared. */
function list_entries(string $table, string $start, string $end): array
{
    $table = $table === 'bazar' ? 'bazar' : 'deposits';
    $value = $table === 'bazar' ? 'cost' : 'amount';
    $extra = $table === 'bazar' ? ', t.item' : '';

    $sql = "SELECT t.id, t.entry_date, t.member_id, t.$value AS value $extra,
                   t.file_path, t.file_kind, t.file_name, m.name
              FROM $table t
              LEFT JOIN members m ON m.id = t.member_id
             WHERE t.entry_date BETWEEN ? AND ?
             ORDER BY t.entry_date, t.id";
    $stmt = db()->prepare($sql);
    $stmt->execute([$start, $end]);

    $out = [];
    foreach ($stmt as $row) {
        $entry = [
            'id'        => (int) $row['id'],
            'date'      => $row['entry_date'],
            'member_id' => $row['member_id'] === null ? null : (int) $row['member_id'],
            'name'      => $row['name'] ?? '—',
            'value'     => (float) $row['value'],
            'file'      => $row['file_path']
                ? [
                    'url'  => $row['file_path'],
                    'kind' => $row['file_kind'] ?: 'image',
                    'name' => $row['file_name'] ?: 'attachment',
                ]
                : null,
        ];
        if ($table === 'bazar') {
            $entry['item'] = (string) ($row['item'] ?? '');
        }
        $out[] = $entry;
    }

    return $out;
}

/* ------------------------------------------------------------------ */
/*  meals                                                              */
/* ------------------------------------------------------------------ */

function action_save_meal(): array
{
    $body     = json_body();
    $date     = req_date($body['date'] ?? '');
    $morning  = !empty($body['morning']) ? 1 : 0;
    $noon     = !empty($body['noon'])    ? 1 : 0;
    $night    = !empty($body['night'])   ? 1 : 0;

    /* --- 1. only an authenticated admin may change a past day -------- */
    $isOpen    = meal_is_open($date);
    $byAdmin   = false;
    $sentToken = isset($body['admin_token']) ? (string) $body['admin_token'] : '';

    if (!$isOpen) {
        require_admin($sentToken);
        $byAdmin = true;
    } elseif ($sentToken !== '') {
        // Admin-panel edits to today/future are still attributed to the admin.
        require_admin($sentToken);
        $byAdmin = true;
    }
    $source = $byAdmin ? 'admin' : 'member';

    $memberId = req_member_id($body['member_id'] ?? 0);
    $pdo = db();

    $stmt = $pdo->prepare(
        'SELECT morning, noon, night, updated_at
           FROM meals
          WHERE member_id = ? AND meal_date = ?'
    );
    $stmt->execute([$memberId, $date]);
    $before = $stmt->fetch() ?: null;

    /* --- 2. did somebody else edit it while this form was open? ----- */
    if (array_key_exists('version', $body)) {
        $seen    = (string) $body['version'];
        $current = $before ? (string) $before['updated_at'] : '';

        if ($seen !== $current) {
            fail(
                'Somebody else changed this day while you were editing it. '
                . 'The table has been reloaded — please check it and save again.',
                409
            );
        }
    }

    $old = $before
        ? [(int) $before['morning'], (int) $before['noon'], (int) $before['night']]
        : [0, 0, 0];
    $new = [$morning, $noon, $night];

    /* Nothing to do: either the day was never saved and stays empty, or the
       status is exactly what it already was. */
    if ($old === $new) {
        return ['date' => $date, 'member_id' => $memberId, 'unchanged' => true];
    }

    /* --- 3. write it, then record who changed what ------------------ */
    $sql = 'INSERT INTO meals (member_id, meal_date, morning, noon, night)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE morning = VALUES(morning),
                                    noon    = VALUES(noon),
                                    night   = VALUES(night)';
    $pdo->prepare($sql)->execute([$memberId, $date, $morning, $noon, $night]);

    try {
        $pdo->prepare(
            'INSERT INTO meal_changes
               (member_id, meal_date, old_morning, old_noon, old_night,
                new_morning, new_noon, new_night, source, locked_override,
                ip, user_agent)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $memberId, $date,
            $old[0], $old[1], $old[2],
            $new[0], $new[1], $new[2],
            $source, $byAdmin && !$isOpen ? 1 : 0,
            client_ip(),
            mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 190) ?: null,
        ]);
    } catch (PDOException $e) {
        // The migration was not run yet — the meal itself is saved, we just
        // cannot log it. Do not fail the user's save because of that.
    }

    return ['date' => $date, 'member_id' => $memberId];
}

/* ------------------------------------------------------------------ */
/*  bazar + deposits                                                   */
/* ------------------------------------------------------------------ */

function action_save_bazar(): array
{
    $date     = req_date($_POST['date'] ?? '');
    $memberId = req_member_id($_POST['member_id'] ?? 0);
    $item     = trim((string) ($_POST['item'] ?? ''));
    $cost     = req_amount($_POST['cost'] ?? null, true);

    if ($item === '') {
        fail('Please write the meal item.');
    }
    if (mb_strlen($item) > 190) {
        fail('The item name is too long (max 190 characters).');
    }

    $file = handle_upload('file');

    $stmt = db()->prepare(
        'INSERT INTO bazar (entry_date, member_id, item, cost, file_path, file_kind, file_name)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $date, $memberId, $item, $cost,
        $file['path'] ?? null, $file['kind'] ?? null, $file['name'] ?? null,
    ]);

    return ['id' => (int) db()->lastInsertId()];
}

function action_save_deposit(): array
{
    $date     = req_date($_POST['date'] ?? '');
    $memberId = req_member_id($_POST['member_id'] ?? 0);
    $amount   = req_amount($_POST['amount'] ?? null);

    $file = handle_upload('file');

    $stmt = db()->prepare(
        'INSERT INTO deposits (entry_date, member_id, amount, file_path, file_kind, file_name)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $date, $memberId, $amount,
        $file['path'] ?? null, $file['kind'] ?? null, $file['name'] ?? null,
    ]);

    return ['id' => (int) db()->lastInsertId()];
}

/** Shared delete for bazar and deposits (also removes the uploaded file). */
function action_delete(string $table): array
{
    $body = json_body();
    $id   = (int) ($body['id'] ?? 0);
    if ($id <= 0) {
        fail('Missing entry id.');
    }

    $table = $table === 'bazar' ? 'bazar' : 'deposits';

    $stmt = db()->prepare("SELECT file_path FROM $table WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        fail('That entry is already gone.', 404);
    }

    db()->prepare("DELETE FROM $table WHERE id = ?")->execute([$id]);
    delete_upload($row['file_path'] ?? null);

    return ['id' => $id];
}

/**
 * Move an uploaded image/PDF into uploads/ and return its public path.
 * Returns null when no file was sent.
 */
function handle_upload(string $field): ?array
{
    if (empty($_FILES[$field]) || !is_array($_FILES[$field])) {
        return null;
    }

    $file = $_FILES[$field];
    $code = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($code === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($code !== UPLOAD_ERR_OK) {
        fail('Upload failed (PHP error code ' . $code . ').');
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        fail('Upload failed: the file did not arrive correctly.');
    }
    if ((int) $file['size'] > MAX_UPLOAD_BYTES) {
        fail('File is too big (max ' . round(MAX_UPLOAD_BYTES / 1048576) . ' MB). Please upload a smaller image.');
    }

    $mime = detect_mime($file['tmp_name']);
    $ext  = mime_extension($mime);
    if ($ext === null) {
        fail('Only images (jpg, png, webp, gif) or PDF files are allowed.');
    }

    if (!is_dir(UPLOAD_DIR) && !mkdir(UPLOAD_DIR, 0775, true) && !is_dir(UPLOAD_DIR)) {
        fail('Could not create the uploads folder. Check folder permissions.');
    }

    $stored = date('Ymd') . '-' . bin2hex(random_bytes(8)) . '.' . $ext;
    $target = UPLOAD_DIR . '/' . $stored;
    if (!move_uploaded_file($file['tmp_name'], $target)) {
        fail('Could not save the uploaded file.');
    }
    @chmod($target, 0644);

    $original = trim((string) ($file['name'] ?? ''));

    return [
        'path' => UPLOAD_URL . '/' . $stored,
        'kind' => str_starts_with($mime, 'image/') ? 'image' : 'pdf',
        'name' => $original !== '' ? mb_substr(basename($original), 0, 190) : $stored,
    ];
}

function detect_mime(string $path): string
{
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($path);
        if (is_string($mime) && $mime !== '') {
            return strtolower($mime);
        }
    }

    $info = @getimagesize($path);

    return is_array($info) && isset($info['mime']) ? strtolower($info['mime']) : 'application/octet-stream';
}

function mime_extension(string $mime): ?string
{
    return match ($mime) {
        'image/jpeg', 'image/pjpeg'      => 'jpg',
        'image/png'                      => 'png',
        'image/webp'                     => 'webp',
        'image/gif'                      => 'gif',
        'image/bmp'                      => 'bmp',
        'application/pdf'                => 'pdf',
        default                          => null,
    };
}

/** Delete a previously stored upload (only inside our uploads folder). */
function delete_upload(?string $relativePath): void
{
    if (!$relativePath) {
        return;
    }

    $name   = basename($relativePath);
    $target = UPLOAD_DIR . '/' . $name;
    $real   = realpath($target);
    $base   = realpath(UPLOAD_DIR);

    if ($real && $base && str_starts_with($real, $base) && is_file($real)) {
        @unlink($real);
    }
}

/* ------------------------------------------------------------------ */
/*  one-time import from the old localStorage version                  */
/* ------------------------------------------------------------------ */

function action_import(): array
{
    $body = json_body();
    require_admin(isset($body['admin_token']) ? (string) $body['admin_token'] : '');

    $pdo     = db();
    $counts  = ['meals' => 0, 'bazar' => 0, 'deposits' => 0];
    $byName  = [];
    foreach (members_list() as $m) {
        $byName[mb_strtolower($m['name'])] = $m['id'];
    }

    $findMember = static function (string $name) use ($pdo, &$byName): int {
        $key = mb_strtolower(trim($name));
        if ($key === '') {
            $key = 'unknown';
        }
        if (isset($byName[$key])) {
            return $byName[$key];
        }
        $stmt = $pdo->prepare('INSERT INTO members (name, sort_order) VALUES (?, 99)');
        $stmt->execute([trim($name) !== '' ? mb_substr(trim($name), 0, 60) : 'Unknown']);
        $byName[$key] = (int) $pdo->lastInsertId();

        return $byName[$key];
    };

    /* meals: { "mess_2026-10-01_Zahid": {morning:true,noon:false,night:true}, ... }

       An old export must never undo a correction that was made here later, so
       by default a day that already exists is left alone. Pass
       {"overwrite": true} in the JSON only if you really want the export to
       win — that is the one thing that can change past days in bulk. */
    $overwrite = !empty($body['overwrite']);
    $mealStmt  = $pdo->prepare($overwrite
        ? 'INSERT INTO meals (member_id, meal_date, morning, noon, night)
           VALUES (?, ?, ?, ?, ?)
           ON DUPLICATE KEY UPDATE morning = VALUES(morning),
                                   noon    = VALUES(noon),
                                   night   = VALUES(night)'
        : 'INSERT IGNORE INTO meals (member_id, meal_date, morning, noon, night)
           VALUES (?, ?, ?, ?, ?)');
    $counts['meals_skipped'] = 0;

    foreach ((array) ($body['meals'] ?? []) as $key => $value) {
        if (!preg_match('/^mess_(\d{4}-\d{2}-\d{2})_(.+)$/', (string) $key, $m)) {
            continue;
        }
        $value = is_array($value) ? $value : [];
        $mealStmt->execute([
            $findMember($m[2]),
            $m[1],
            !empty($value['morning']) ? 1 : 0,
            !empty($value['noon']) ? 1 : 0,
            !empty($value['night']) ? 1 : 0,
        ]);
        if ($mealStmt->rowCount() > 0) {
            $counts['meals']++;
        } else {
            $counts['meals_skipped']++;
        }
    }

    /* bazar list */
    $bazarStmt = $pdo->prepare(
        'INSERT INTO bazar (entry_date, member_id, item, cost, file_path, file_kind, file_name)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ((array) ($body['bazar'] ?? []) as $row) {
        if (!is_array($row) || empty($row['date']) || empty($row['item'])) {
            continue;
        }
        $attachment = is_array($row['file'] ?? null) ? $row['file'] : [];
        $file = store_data_url($attachment['data'] ?? null, $attachment['kind'] ?? null, $attachment['name'] ?? null);
        $bazarStmt->execute([
            (string) $row['date'],
            $findMember((string) ($row['name'] ?? '')),
            mb_substr((string) $row['item'], 0, 190),
            (float) ($row['cost'] ?? 0),
            $file['path'] ?? null,
            $file['kind'] ?? null,
            $file['name'] ?? null,
        ]);
        $counts['bazar']++;
    }

    /* deposit list */
    $depStmt = $pdo->prepare(
        'INSERT INTO deposits (entry_date, member_id, amount, file_path, file_kind, file_name)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    foreach ((array) ($body['deposit'] ?? []) as $row) {
        if (!is_array($row) || empty($row['date'])) {
            continue;
        }
        $attachment = is_array($row['file'] ?? null) ? $row['file'] : [];
        $file = store_data_url($attachment['data'] ?? null, $attachment['kind'] ?? null, $attachment['name'] ?? null);
        $depStmt->execute([
            (string) $row['date'],
            $findMember((string) ($row['name'] ?? '')),
            (float) ($row['amount'] ?? 0),
            $file['path'] ?? null,
            $file['kind'] ?? null,
            $file['name'] ?? null,
        ]);
        $counts['deposits']++;
    }

    return ['imported' => $counts];
}

/** Turn a data URL (from the old localStorage app) into a real file. */
function store_data_url(?string $dataUrl, ?string $kind, ?string $name): ?array
{
    if (!is_string($dataUrl) || $dataUrl === '') {
        return null;
    }
    if (!preg_match('#^data:([a-z0-9.+/-]+);base64,(.+)$#is', $dataUrl, $m)) {
        return null;
    }

    $mime = strtolower($m[1]);
    $ext  = mime_extension($mime) ?? ($kind === 'pdf' ? 'pdf' : null);
    if ($ext === null) {
        return null;
    }

    $binary = base64_decode(preg_replace('/\s+/', '', $m[2]) ?? '', true);
    if ($binary === false || $binary === '' || strlen($binary) > MAX_UPLOAD_BYTES) {
        return null;
    }

    if (!is_dir(UPLOAD_DIR) && !mkdir(UPLOAD_DIR, 0775, true) && !is_dir(UPLOAD_DIR)) {
        return null;
    }

    $stored = date('Ymd') . '-' . bin2hex(random_bytes(8)) . '.' . $ext;
    if (file_put_contents(UPLOAD_DIR . '/' . $stored, $binary) === false) {
        return null;
    }
    @chmod(UPLOAD_DIR . '/' . $stored, 0644);

    return [
        'path' => UPLOAD_URL . '/' . $stored,
        'kind' => str_starts_with($mime, 'image/') ? 'image' : 'pdf',
        'name' => $name !== null && $name !== '' ? mb_substr(basename($name), 0, 190) : $stored,
    ];
}
