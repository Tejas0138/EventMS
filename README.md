# EventMS - Visitor Gatepass & Entry Validation System

A streamlined, production-grade **Visitor Gatepass & Entry Validation System** built with **Pure PHP 8.x**, **MySQL (PDO)**, and **Bootstrap 5.3**.

The entire system is simplified into **EXACTLY two standalone, authentication-free pages** without any admin logins, sessions, or extra modules.

---

## 🚀 Architectural Overview

### 1. Database Purge & Pre-Seeded Dataset
- **Single Database Table**: `allowed_visitors`
  - `id` (INT UNSIGNED AUTO_INCREMENT PRIMARY KEY)
  - `name` (VARCHAR(100) NOT NULL)
  - `mobile` (VARCHAR(15) NOT NULL UNIQUE)
  - `is_checked_in` (TINYINT(1) NOT NULL DEFAULT 0)
  - `checked_in_at` (DATETIME NULL DEFAULT NULL)
  - `created_at` (TIMESTAMP DEFAULT CURRENT_TIMESTAMP)
- **Zero Admin Tables**: The `admins` table has been completely removed (`DROP TABLE IF EXISTS admins;`).
- **10 Pre-populated Whitelisted Records**:
  1. `Gopal Sir` &bull; `7720877204`
  2. `Tejas K` &bull; `9022492233`
  3. `Kaushal K` &bull; `9359536055`
  4. `Nikhil B` &bull; `3333333333`
  5. `Sanket P` &bull; `4444444444`
  6. `Aditya K` &bull; `5555555555`
  7. `Sumit K` &bull; `6666666666`
  8. `Pratik R` &bull; `7777777777`
  9. `Akshay` &bull; `8888888888`
  10. `Anuragh` &bull; `9999999999`

---

## 📄 Exactly Two Standalone Pages

### Page 1: Check-in Terminal (`index.php` at `/eventms/` or `/eventms/index.php`)
- **Direct Public Access**: Zero login, credentials, or session restrictions.
- **Mobile-First Layout**: Signature Dark Navy Header (`#0f1c30`) + Pure White Card Body (`#ffffff`) with 22px rounded radius and responsive dynamic viewport height (`100dvh`).
- **Input Field**: Clean single mobile number input (`type="tel"`, `inputmode="numeric"`, `maxlength="10"`) with `+91` prefix divider and live digit counter.
- **Core Validation Logic**:
  - **Not Found**: If number is not in `allowed_visitors`, rejects with `"Access Denied: Number not registered"`.
  - **Duplicate Check**: If `is_checked_in == 1`, blocks entry with previous check-in timestamp and message `"Duplicate Entry: Already checked in"`.
  - **Approval**: If `is_checked_in == 0`, atomically updates `is_checked_in = 1` and `checked_in_at = NOW()`, approving entry with `"Welcome, [Name]!"`.
- **Embedded Dynamic QR Code Modal**: Top-right "QR Code" pill opens high-resolution QuickChart SVG/PNG QR code pointing directly to `index.php` (auto-detecting protocol, host, and subpath).
- **Direct Navigation**: Includes direct link to `dashboard.php`.

### Page 2: Live Tracking & Reset Dashboard (`dashboard.php` at `/eventms/dashboard.php`)
- **Direct Public Access**: Completely authentication-free and session-free.
- **Real-Time Metrics Cards**:
  - **Total Invited**: 10 Whitelisted Guests
  - **Inside Gate**: Real-time counter of checked-in visitors with animated status indicator
  - **Pending Entry**: Awaiting gate arrival
  - **Gate Progress**: Percentage progress bar
- **Tabular Visitor Log**: Displays ID, Full Name, Mobile (+91), Current Status Badge (Inside Gate / Not Checked-in), and Entry Timestamp.
- **Bulk CSV Import & Export**:
  - **Import CSV**: Interactive modal accepting `.csv` spreadsheets (`Name, Mobile Number`) with automated phone sanitization, header skipping, and non-destructive `ON DUPLICATE KEY UPDATE` ingestion.
  - **Export CSV**: One-click download of attendance reports.
- **Reset Actions**:
  - **Individual Reset**: Direct `dashboard.php?action=reset&id=...` action resets a visitor to `is_checked_in = 0, checked_in_at = NULL`.
  - **Global Reset All**: Direct `dashboard.php?action=reset_all` button clears all visitors back to pending status.
- **Direct Navigation**: Includes direct link back to `index.php`.

---

## 📂 Project Structure

```text
c:\xampp\htdocs\EventMS\
├── index.php                # Page 1: Check-in Terminal with embedded dynamic QR modal
├── dashboard.php            # Page 2: Live Tracking & Reset Dashboard (Zero Auth)
├── config/
│   └── db.php               # Dynamic Localhost/Hostinger PDO singleton & auto-seeder
├── assets/
│   ├── css/
│   │   └── style.css        # Unified Design System (Navy Header + White Card Body)
│   └── js/
│       └── main.js          # Client controller (digit sanitizer, audio feedback, AJAX)
├── database.sql             # Pure schema (DROP admins; CREATE allowed_visitors + 10 seeds)
├── .htaccess                # Apache routing and security directives
└── README.md                # System documentation
```

---

## 🛠️ Getting Started

### Localhost (XAMPP):
1. Ensure Apache and MySQL are running in XAMPP.
2. Open either:
   - Check-in Terminal: `http://localhost/EventMS/index.php` (or `http://localhost:8080/EventMS/index.php`)
   - Tracking Dashboard: `http://localhost/EventMS/dashboard.php` (or `http://localhost:8080/EventMS/dashboard.php`)
3. On first load, `config/db.php` automatically ensures the database exists, drops any legacy `admins` table, creates `allowed_visitors`, and seeds the 10 visitors.

### Hostinger Deployment:
1. Upload the files to `public_html/eventms/`.
2. Configure database credentials in `config/db.php` under `HOSTINGER_DB_*`.
3. Import `database.sql` in phpMyAdmin or let `db.php` initialize on first hit.
