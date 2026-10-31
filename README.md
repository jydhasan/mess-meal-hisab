# Mess Meal Sheet — PHP + MySQL version

This is the same mess meal sheet, but the data no longer lives inside the browser.
Meals, bazar costs and deposits are stored in MySQL and read/written through PHP.

Boarders can update meal status for today and future dates. Past-date meal
corrections and imports require the passcode behind the **Admin Login** button.
Every past-date meal change is recorded in the change log.

`mess_meal_sheet_v3.html` is untouched and still works on its own (localStorage).

## Files

| File | What it does |
| --- | --- |
| `index.php` | The page. Same layout as before. |
| `assets/style.css` | All styling (copied from the original file). |
| `assets/app.js` | Front-end logic; talks to `api.php` with `fetch`. |
| `api.php` | The only backend entry point: read/save/delete + import. |
| `config.php` | Database login + upload settings. **Edit this one.** |
| `schema.sql` | Creates the database, all tables and the 7 members. |
| `migrate_meal_lock.sql` | Adds the meal change log to an existing installation. |
| `uploads/` | Uploaded vouchers/attachments land here. |

## Setup (XAMPP)

1. Install XAMPP and start **Apache** and **MySQL** from the control panel.
2. Copy the `mess` folder into `C:\xampp\htdocs\` → so you have `C:\xampp\htdocs\mess\`.
3. Create the database: open <http://localhost/phpmyadmin> → **Import** → choose `schema.sql` → **Go**.
   (Command line alternative: `mysql -u root -p < schema.sql`)
4. If your MySQL user/password is not `root` with an empty password, edit `config.php`.
5. In `config.php`, change `ADMIN_KEY` from `change-me` to a strong private passcode.
6. Open <http://localhost/mess/>.

For an installation that already has `mess_db`, import `migrate_meal_lock.sql`
once. Fresh installations get the log table from `schema.sql`.

### Deploying to an existing server

Upload these files together:

- `index.php`
- `api.php`
- `config.php` with the `ADMIN_KEY` you want to use
- `assets/app.js`
- `assets/style.css`

For an existing database, import `migrate_meal_lock.sql`. The SQL file is for
the audit table; it is not required to sign in. Only a database without the
existing mess tables needs `schema.sql`.

After uploading, open the website and click the single **Admin Login** button
at the top-right. The default passcode in this project is `change-me`; if you
changed `ADMIN_KEY`, use that exact value.

No XAMPP? Any Apache + PHP 8 + MySQL/MariaDB stack works. For a quick local test you can also run
`php -S localhost:8000` inside this folder — but MySQL still has to be running.

## Database

Database `mess_db`, five tables:

- `members` — id, name, sort_order (the 7 members are pre-filled).
- `meals` — one row per member per day, with `morning` / `noon` / `night` flags.
  A unique key on `(member_id, meal_date)` means saving the same day twice updates the row.
- `bazar` and `deposits` — one row per entry, with `entry_date`, member, amount and an optional
  attachment (`file_path`, `file_kind`, `file_name`). Attachments are real files inside `uploads/`.
- `meal_changes` — audit history for meal edits, including the before/after status,
  who made the change, and whether it was a backdate.

Deleting an entry also deletes its uploaded file.

## Bringing your old data across

The old page kept everything in `localStorage`, so it can be exported:

1. Open `mess_meal_sheet_v3.html` in the browser you used (Chrome/Edge is easiest).
2. Press `F12` → **Console** tab, paste this and hit Enter — it copies the JSON to your clipboard:

```js
copy(JSON.stringify({
  meals: Object.fromEntries(Object.keys(localStorage)
    .filter(k => /^mess_\d{4}-\d{2}-\d{2}_/.test(k))
    .map(k => [k, JSON.parse(localStorage.getItem(k))])),
  bazar: JSON.parse(localStorage.getItem('mess_bazar_list') || '[]'),
  deposit: JSON.parse(localStorage.getItem('mess_deposit_list') || '[]')
}))
```

3. Open the new page → **Admin Panel** → sign in → **⇪ Import old data** → paste → **Import**.

Screenshots and PDFs are converted back into files in `uploads/`, so nothing is lost.
Importing the same data twice creates duplicate bazar/deposit rows, so run it once.

## Notes / limits

- Needs PHP 8.0+ (uses `match`, `str_starts_with`) and the `pdo_mysql` + `fileinfo` extensions,
  which XAMPP enables by default.
- Attachments: images or PDF only. The browser shrinks images to max 1100px / JPEG before upload
  (same as the old page), and the server refuses anything over 5 MB.
- The month picker is shared by all data tabs, because a month is fetched in one request.
- Today is determined by the server in the `Asia/Dhaka` timezone, not by the visitor's device.
- Admin authentication uses a signed, expiring token stored only in the current
  browser tab. Backdated writes require that token on the server.
- All SQL uses prepared statements; all output is HTML-escaped before rendering.
- The page, admin login, token authorization, and PHP/JavaScript syntax can be checked without
  the database. After a database import, verify the full data flow with
  `api.php?action=data&month=YYYY-MM` and one test meal entry.
