# joebz_inventory

## Deploying to Vercel

This project is a **plain PHP + MySQL app** (not a Node/Next.js app), so deployment on Vercel needs a small adaptation.

### Important architecture notes

- The app expects PHP sessions (`session_start()`), a MySQL database connection via `mysqli`, and local filesystem uploads in `uploads/`. 
- Vercel Serverless Functions have **ephemeral file storage**, so uploaded files cannot be safely stored on local disk long-term.
- Because of this, production should use:
  - a managed MySQL database (PlanetScale/Neon MySQL/TiDB Cloud/RDS, etc.)
  - object storage for images (Cloudinary, S3, Supabase Storage, etc.)

---

## Option A (Recommended): Keep app as PHP on Vercel Functions

### 1) Add Vercel config

Create a `vercel.json` in the repo root:

```json
{
  "version": 2,
  "builds": [
    { "src": "*.php", "use": "vercel-php@0.7.3" }
  ],
  "routes": [
    { "src": "/(.*)", "dest": "/index.php" }
  ]
}
```

> If you want file-based routing (e.g. `/items.php`, `/dashboard.php`) without forcing everything through `index.php`, adjust routes accordingly.

### 2) Move DB credentials to environment variables

Update `config/db.php` to read from env vars instead of hardcoded local values:

```php
<?php
$host = getenv('DB_HOST') ?: '127.0.0.1';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: '';
$name = getenv('DB_NAME') ?: 'inventory_joebz';

$conn = new mysqli($host, $user, $pass, $name);
if ($conn->connect_error) {
    die('Connection failed: ' . $conn->connect_error);
}
$conn->set_charset('utf8mb4');
```

In Vercel Project Settings → Environment Variables, set:

- `DB_HOST`
- `DB_USER`
- `DB_PASS`
- `DB_NAME`

### 3) Handle uploads using cloud storage

Your current code writes to `uploads/` on disk. For Vercel, switch image upload handling to cloud storage and save only the resulting URL/path in DB.

### 4) Deploy

```bash
npm i -g vercel
vercel login
vercel
```

Then production deploy:

```bash
vercel --prod
```

---

## Option B: Migrate to Next.js (long-term modernization)

If you plan to keep scaling this app on Vercel, consider moving to Next.js API routes or Server Actions and keeping MySQL + object storage externally managed. This gives better native support on Vercel.

---

## Quick checklist

- [ ] Add `vercel.json`
- [ ] Move DB config to environment variables
- [ ] Provision managed MySQL database
- [ ] Move image uploads to object storage
- [ ] Set env vars in Vercel
- [ ] Run `vercel --prod`
