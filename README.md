# AuBase

**AuBase** is a full-stack auction marketplace: browse live-style listings, place bids with concurrency-safe updates, list your own items, and manage everything from a personal dashboard. The UI is a custom PHP front end (no framework) with a polished, editorial look—navy and gold typography, responsive grids, and clear flows for sign-up, selling, and account settings.

---

## What you can do on the site

| Area | Highlights |
|------|------------|
| **Browse** | Home catalog with search, category filter, tabs (**All**, **Open**, **Closed**, **Buy It Now**), pagination, and stats (items, bids, live auctions). |
| **Item detail** | Full description, seller rating, categories, bid history, minimum next bid, optional **Buy It Now**. Closed vs open state is driven by a configurable demo “current time.” |
| **Bidding** | Logged-in users bid above the current price; sellers cannot bid on their own listings. Bidding uses a short transaction and row lock to reduce race conditions. |
| **Selling** | **List an item** with title, description, location, country, category, starting price, optional Buy It Now, and auction length (1–14 days). |
| **Dashboard** | Overview stats, **My Bids** (withdraw on open auctions with confirmation), **My Listings** (remove listing with confirmation), quick links. Success toasts auto-dismiss after a few seconds. |
| **Account** | Profile (name, address, phone), change username, change email, change password, activity-style stats, optional **soft delete** of the account (when DB migration applied). |
| **Auth & security** | Register / login / logout, CSRF on sensitive forms, `password_hash` / `password_verify`, safe post-login redirect. Optional **email verification** and **password reset** when mail and DB columns are configured. |

---

## Tech stack

- **PHP 8+** (`declare(strict_types=1);` on entry scripts)
- **MySQL** (mysqli, prepared statements where it matters)
- **Sessions** for authentication
- **No npm / no front-end build**—plain HTML, CSS, and small inline scripts

---

## Screenshots (add your own)

Drop images under `docs/screenshots/` (create the folder if needed) and link them here so README doubles as a portfolio page:

```markdown
![AuBase home](docs/screenshots/home.png)
![Item page](docs/screenshots/item.png)
![Dashboard](docs/screenshots/dashboard.png)
```

Until then, run the app locally and capture the home page, an item with bids, the dashboard, and account settings.

---

## Repository layout (important files)

```
AuBase/
├── index.php              # Catalog: filters, tabs, pagination
├── item.php               # Single listing, place bid / buy now
├── sell.php               # Create listing + auction
├── dashboard.php          # Bids, listings, withdraw / remove + CSRF
├── account.php            # Profile, credentials, optional delete
├── login.php / register.php / logout.php
├── forgot_password.php / reset_password.php
├── verify.php / resend_verification.php
├── import.php             # Loads bundled JSON into MySQL (CLI or keyed URL)
├── db.sql                 # Base schema
├── migrate_*.php          # Small one-off DB upgrades (see below)
├── backend/
│   ├── config.php         # .env loading, AUBASE_* constants
│   ├── db.php             # mysqli connection
│   ├── csrf.php           # Token helpers
│   ├── auction_list.php   # Shared filter SQL for index
│   └── mail_verify.php    # Optional verification / reset mail
├── ebay_data/             # items-0.json … (demo import source)
└── .env.example           # Copy to .env
```

---

## Prerequisites

- **PHP 8.0+** with mysqli enabled  
- **MySQL 5.7+** (or MariaDB equivalent)  
- A MySQL user that can create/use the `aubase` database (or whatever name you set)

---

## Local setup

1. **Clone or copy** this repository.

2. **Create the database** and load the schema:

   ```bash
   mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS aubase CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
   mysql -u root -p aubase < db.sql
   ```

3. **Environment file**

   ```bash
   cp .env.example .env
   ```

   Edit `.env` with your DB host, port, name, user, and password. See **Environment variables** below.

4. **Optional: demo dataset**  
   If `ebay_data/` JSON files are present, import them (large; can take a minute):

   ```bash
   php import.php
   ```

   Or set `AUBASE_IMPORT_KEY` in `.env` and open `import.php?key=YOUR_KEY` in the browser.

5. **Run PHP’s built-in server** from the project root:

   ```bash
   php -S localhost:8080
   ```

6. Open **http://localhost:8080** — start at `index.php` (default document may depend on your server).

---

## Environment variables

| Variable | Purpose |
|----------|---------|
| `AUBASE_DB_HOST` | MySQL host (default `127.0.0.1`) |
| `AUBASE_DB_PORT` | Port (default `3306`) |
| `AUBASE_DB_NAME` | Database name (default `aubase`) |
| `AUBASE_DB_USER` / `AUBASE_DB_PASS` | Credentials |
| `AUBASE_DEMO_NOW` | `YYYY-MM-DD` “today” for open/closed auctions when using the historical demo dataset (default `2001-12-14`) |
| `AUBASE_IMPORT_KEY` | If non-empty, allows browser `import.php?key=…`; empty = CLI only |
| `AUBASE_BASE_URL` | Used when sending mail links (verification / reset) |
| `AUBASE_MAIL_FROM` | If set with working mail, enables **email verification** on register and supports **forgot password** |

If `AUBASE_MAIL_FROM` is **empty**, new accounts can typically use the site without waiting on a verification email (good for local dev).

---

## Database migrations (optional features)

Run from project root **after** `db.sql`, only when you need the feature:

| Script | What it adds |
|--------|----------------|
| `php migrate_password_hash.php` | Safer password storage if you started from an older schema |
| `php migrate_email_verify.php` | Columns + flow for email verification |
| `php migrate_password_reset.php` | Reset token columns for forgot password |
| `php migrate_account_settings.php` | `User.created_at`, `User.deleted_at` for account page + soft delete |

---

## Design notes for reviewers

- **Demo time**: Auctions open/closed vs `AUBASE_DEMO_NOW`, not necessarily the machine clock—useful for a fixed academic or portfolio dataset.
- **Images**: Listing thumbnails use [Picsum](https://picsum.photos) seeds from `item_id` for a consistent placeholder look without hosting uploads.
- **Security**: Withdraw listing/bid and account POSTs use CSRF checks; passwords use PHP’s native hashing API.

---

## Contributing / fork

This is a compact educational or portfolio codebase: small surface area, readable SQL, and minimal dependencies. Issues and PRs are welcome if you open the project publicly.

---

## License

Specify your license here if you add one (e.g. MIT). Until then, all rights reserved unless you state otherwise.
