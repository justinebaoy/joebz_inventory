# Deploying Your PHP + MySQL POS System to InfinityFree
> A complete guide for students — from account setup to going live, with a single codebase for both local and online environments.

---

## Table of Contents
1. [Prerequisites](#prerequisites)
2. [Step 1 — Create an InfinityFree Account](#step-1--create-an-infinityfree-account)
3. [Step 2 — Create Your Hosting Account & Database](#step-2--create-your-hosting-account--database)
4. [Step 3 — The Single Codebase Strategy](#step-3--the-single-codebase-strategy)
5. [Step 4 — Update Your DB Connection Code](#step-4--update-your-db-connection-code)
6. [Step 5 — Export Your Local Database](#step-5--export-your-local-database)
7. [Step 6 — Import the Database to InfinityFree](#step-6--import-the-database-to-infinityfree)
8. [Step 7 — Upload Your Files via FTP](#step-7--upload-your-files-via-ftp)
9. [Step 8 — Test Your Live Site](#step-8--test-your-live-site)
10. [Keeping Both Environments in Sync](#keeping-both-environments-in-sync)
11. [Quick Reference Cheat Sheet](#quick-reference-cheat-sheet)

---

## Prerequisites

Before you begin, make sure you have:

- [ ] Your POS project files (PHP + MySQL via XAMPP)
- [ ] XAMPP running locally with your database set up
- [ ] A free email address to register on InfinityFree
- [ ] A browser (Chrome or Firefox recommended) — no extra software needed!

---

## Step 1 — Create an InfinityFree Account

1. Go to **https://infinityfree.com**
2. Click **Sign Up** and register with your email
3. Verify your email address
4. Log in to your InfinityFree dashboard

---

## Step 2 — Create Your Hosting Account & Database

### 2a. Create a Hosting Account
1. In your dashboard, click **New Account**
2. Choose a **free subdomain** (e.g., `mypos.infinityfreeapp.com`) — this will be your live URL
3. Set a password and click **Create Account**
4. Wait ~5 minutes for the account to activate

### 2b. Create a MySQL Database
1. From your hosting account panel, go to **MySQL Databases**
2. Click **Add Database**
3. Note down these four values — you'll need them later:
   - **Database Host** (e.g., `sql304.infinityfree.com`)
   - **Database Name** (e.g., `if0_38291234_pos`)
   - **Username** (e.g., `if0_38291234`)
   - **Password** (what you set)

---

## Step 3 — The Single Codebase Strategy

Yes — you can absolutely use **one codebase** for both local (XAMPP) and live (InfinityFree) using a simple environment detection conditional.

The idea is:

```
If running on localhost → use local DB credentials
If running on the live server → use InfinityFree DB credentials
```

PHP makes this easy with `$_SERVER['HTTP_HOST']`.

---

## Step 4 — Update Your DB Connection Code

Find your database connection file. It's usually named something like:
- `config.php`
- `db.php`
- `connection.php`
- `includes/db_connect.php`

Replace its contents with this pattern:

```php
<?php
// ============================================================
//  ENVIRONMENT DETECTION — single codebase for local + live
// ============================================================

$host = $_SERVER['HTTP_HOST'] ?? '';

if ($host === 'localhost' || $host === '127.0.0.1') {
    // ── LOCAL (XAMPP) ──────────────────────────────────────
    define('DB_HOST',   'localhost');
    define('DB_USER',   'root');          // XAMPP default
    define('DB_PASS',   '');              // XAMPP default (blank)
    define('DB_NAME',   'your_local_db_name');  // ← change this
    define('ENV',       'local');

} else {
    // ── LIVE (InfinityFree) ────────────────────────────────
    define('DB_HOST',   'sql304.infinityfree.com');  // ← your host
    define('DB_USER',   'if0_38291234');             // ← your username
    define('DB_PASS',   'your_db_password');         // ← your password
    define('DB_NAME',   'if0_38291234_pos');         // ← your db name
    define('ENV',       'production');
}

// ── Connect ───────────────────────────────────────────────
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$conn->set_charset("utf8");
?>
```

> **Note:** Replace all the placeholder values with your actual credentials from Step 2b.

### If you use PDO instead of mysqli:

```php
<?php
$host = $_SERVER['HTTP_HOST'] ?? '';

if ($host === 'localhost' || $host === '127.0.0.1') {
    $db_host = 'localhost';
    $db_user = 'root';
    $db_pass = '';
    $db_name = 'your_local_db_name';
} else {
    $db_host = 'sql304.infinityfree.com';
    $db_user = 'if0_38291234';
    $db_pass = 'your_db_password';
    $db_name = 'if0_38291234_pos';
}

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}
?>
```

---

## Step 5 — Export Your Local Database

1. Open **phpMyAdmin** at `http://localhost/phpmyadmin`
2. Click on your POS database in the left sidebar
3. Click the **Export** tab at the top
4. Choose **Quick** export method, format: **SQL**
5. Click **Go** — this downloads a `.sql` file (e.g., `pos_database.sql`)

> Keep this file — you'll import it in the next step.

---

## Step 6 — Import the Database to InfinityFree

1. From your InfinityFree hosting panel, go to **phpMyAdmin**
2. Click on your InfinityFree database name in the left sidebar
3. Click the **Import** tab
4. Click **Choose File** and select your exported `.sql` file
5. Click **Go**

You should see a success message. All your tables and data are now on the live server.

> ⚠️ **File size limit:** InfinityFree's phpMyAdmin has a ~2MB import limit. If your `.sql` file is larger, split it or use a tool like **BigDump** (free PHP script for large imports).

---

## Step 7 — Upload Your Files via File Manager (No Software Needed)

InfinityFree includes a **free built-in File Manager** in your hosting panel — no FTP client or extra software required. Everything is done right in your browser.

### 7a. Open the File Manager
1. Log in to your InfinityFree hosting panel
2. Click on your hosting account to open its control panel
3. Look for **Online File Manager** (sometimes listed under "Files") and click it
4. A new tab will open with a file browser interface

### 7b. Navigate to the Right Folder
1. In the file tree on the left, open the `htdocs` folder — this is your website's root directory
2. Everything you upload here will be publicly accessible on your live URL

### 7c. Upload Your Project Files

**For small projects (a few files):**
1. Click the **Upload** button in the toolbar
2. Select your PHP files one by one or in small batches
3. Wait for each upload to complete

**For larger projects (many files/folders — recommended):**
1. On your computer, select all your project files
2. Right-click → **Send to** → **Compressed (zipped) folder** to create a `.zip` file
3. In the File Manager, click **Upload** and upload the `.zip` file
4. Once uploaded, right-click the `.zip` file → **Extract** to unzip everything in place
5. Make sure the extracted files land directly inside `htdocs/`, not in a subfolder

> ✅ **Zip upload is the fastest method** when you have many files and folders.

### 7d. Verify the Structure
After uploading, your `htdocs/` folder should look something like this:
```
htdocs/
├── index.php
├── config.php
├── login.php
├── dashboard.php
├── includes/
├── assets/
└── ... (rest of your project)
```

> ⚠️ **Do NOT upload** your `.sql` database file here — that was already imported via phpMyAdmin in Step 6.

---

## Step 8 — Test Your Live Site

1. Open your browser and go to your subdomain (e.g., `https://mypos.infinityfreeapp.com`)
2. Test all major features: login, adding products, creating transactions, etc.
3. If something breaks, check:
   - DB credentials in your `config.php`
   - That all files uploaded correctly via the File Manager (check `htdocs/` structure)
   - File/folder names are case-sensitive on Linux servers (e.g., `Login.php` ≠ `login.php`)

---

## Keeping Both Environments in Sync

Since you're using **one codebase**, code changes automatically work on both environments. The only thing you need to sync manually is the **database** when you make schema changes.

| Scenario | What to do |
|---|---|
| You change PHP code | Re-upload only the changed files via FileZilla |
| You add a new table locally | Export SQL → import to InfinityFree phpMyAdmin |
| You add test data locally | No need to sync — keep data separate |
| Internet goes down during presentation | Switch to `localhost` — your XAMPP copy works independently |

### Pro Tip: Use a `.env`-style approach for sensitive credentials

Instead of hardcoding credentials in your PHP file, you can create a `config.local.php` (for local) and `config.live.php` (for live), and include the right one:

```php
<?php
if (file_exists(__DIR__ . '/config.local.php')) {
    require_once 'config.local.php';  // exists only on your laptop
} else {
    require_once 'config.live.php';   // only on the live server
}
?>
```

This way, you never accidentally overwrite your live credentials when uploading files.

---

## Quick Reference Cheat Sheet

```
LOCAL (XAMPP)
─────────────────────────────────────
URL:      http://localhost/your-folder
DB Host:  localhost
DB User:  root
DB Pass:  (blank)
phpMyAdmin: http://localhost/phpmyadmin

LIVE (InfinityFree)
─────────────────────────────────────
URL:      https://yourname.infinityfreeapp.com
DB Host:  sql***.infinityfree.com   ← from your panel
DB User:  if0_XXXXXXXX              ← from your panel
DB Pass:  your password
phpMyAdmin: via InfinityFree panel → phpMyAdmin
File Upload: via InfinityFree panel → Online File Manager → htdocs/
Tip: Zip your project folder before uploading for speed
```

---

> 💡 **Presentation tip:** Run XAMPP locally as your primary demo. Have the live InfinityFree URL as a backup on your phone or another device. If the internet cuts out, you're fully covered.

Good luck with your final project! 🎓