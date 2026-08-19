# Sunshine Secondary School — Procurement System

A PHP + MySQL web app implementing Sunshine Secondary School's seven-stage
procurement workflow:

Requisition → Approve (Procurement → Finance → Principal) → RFQ → LPO
(prepared, authorised, approved) → Sent to Supplier → Goods Received →
Invoice (2% withholding tax on VAT-registered suppliers) → Paid.

Plain PHP (no framework), PDO/MySQL, session-based auth. Built to run on
standard shared/LAMP hosting.


## Requirements

- PHP 8.1+ with `pdo_mysql` and `gd` (or any image extension for `mime_content_type`)
- MySQL 5.7+ or MariaDB 10.3+
- Apache or Nginx with PHP-FPM (an `.htaccess` is included for Apache)


## Setup

1. **Create the database and load the schema**

   ```bash
   mysql -u root -p -e "CREATE DATABASE sunshine_procurement CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
   mysql -u root -p sunshine_procurement < sql/schema.sql
   ```

   This creates all tables and seeds five demo users and three demo suppliers.

2. **Configure the database connection**

   Edit `config.php` and set `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` to
   match your environment. Also review `SCHOOL_NAME`, `SCHOOL_ADDRESS`,
   `SCHOOL_CONTACT`, and `BASE_PATH` (set this if the app isn't served from
   your domain root, e.g. `BASE_PATH = '/procurement'`).

3. **Make the uploads folder writable**

   ```bash
   chmod -R 775 uploads/signatures
   ```

   Signature/stamp images uploaded by approvers are stored here.

4. **Point your web server's document root at this folder** and visit
   `login.php`.


## Demo logins

All seeded accounts use the password `password123`.

| Username     | Role         | Notes                                   |
|--------------|--------------|------------------------------------------|
| `admin`      | Administrator| Manage users, view everything            |
| `procurement`| Procurement  | Raises LPOs, records GRNs & invoices     |
| `finance`    | Finance      | Authorises requisitions/LPOs, pays invoices |
| `principal`  | Principal    | Final approval on requisitions & LPOs    |
| `ekimutai`   | Requester    | Raises requisitions only (Emily Kimutai, Housekeeping) |

**Change these passwords (or delete/replace the demo accounts) before using
this in production.**


## How the workflow maps to the code

| Stage | Page(s) | Who |
|---|---|---|
| Raise requisition | `requisition_new.php` | Any logged-in user |
| Approve requisition | `requisition_view.php` (Approve/Reject buttons) | Procurement → Finance → Principal, in order |
| Create LPO | `lpo_new.php` (from an approved requisition) | Procurement |
| Approve LPO | `lpo_view.php` | Finance → Principal (Procurement is auto-recorded as preparer) |
| Send to supplier | `lpo_view.php` (button, once approved) | Procurement |
| Record goods receipt | `grn_new.php` (from a sent LPO) | Procurement |
| Record invoice | `invoice_new.php` (from a GRN) | Procurement — 2% withholding tax auto-calculated if the supplier is VAT-registered |
| Mark paid | `invoice_view.php` (button) | Finance |

Every requisition/LPO approval or rejection is written to the `approvals`
table with the acting user, their stored signature/stamp image, and a
timestamp — this is the audit trail.


## Signatures & stamps

Each approver uploads their signature/stamp once, under **My Signature**
(`signatories.php`). From then on, whenever they click **Approve**, their
stored image, name, and the current timestamp are attached to that approval
automatically — no re-upload needed each time. Administrators can manage any
approver's signature from the same page.

Approving is blocked with a clear message until the logged-in approver has a
signature on file.


## Printing forms

Every document view (Requisition, LPO, GRN, Invoice) has a **Print** button.
The print stylesheet hides the sidebar and buttons, leaving a clean form
that mirrors the school's existing paper layouts.


## Roles & permissions summary

- **requester** — raise requisitions, view only their own
- **procurement** — approve requisitions (stage 1), prepare/approve LPOs,
  manage suppliers, record GRNs and invoices
- **finance** — approve requisitions (stage 2), authorise LPOs, mark
  invoices as paid
- **principal** — final approval on requisitions and LPOs (stage 3)
- **admin** — manage users and any approver's signature; full visibility

Direct URL access is enforced server-side (`require_role()` in
`includes/auth.php`), not just hidden in the menu.


## Extending this

A few things worth doing before a full production rollout — see the
"Open Items" section of the accompanying Functional Specification document
for the bigger-picture list (budget checks, delegated approvals, etc). In
the code specifically:

- Add email notifications when a document reaches a user's approval queue.
- Add partial-delivery handling on the GRN (currently one GRN per LPO).
- Add pagination once list pages grow beyond a page or two of records.
- Consider moving uploaded signature images outside the web root and
  serving them through a small script if stricter access control is needed.


## File structure

```
procurement-app/
├── config.php              # DB + app settings — edit this first
├── login.php / logout.php
├── index.php                # Dashboard
├── requisitions.php / requisition_new.php / requisition_view.php
├── lpos.php / lpo_new.php / lpo_view.php
├── grns.php / grn_new.php / grn_view.php
├── invoices.php / invoice_new.php / invoice_view.php
├── suppliers.php
├── users.php                 # Admin only
├── signatories.php           # Signature/stamp upload
├── includes/
│   ├── db.php                 # PDO connection
│   ├── auth.php                # Login/session/role checks, CSRF
│   ├── functions.php           # Helpers: money, status badges, approval logic
│   ├── header.php              # Sidebar layout + nav (shared)
│   └── footer.php
├── assets/
│   ├── css/style.css
│   └── js/items.js             # Dynamic item-row add/remove + live totals
├── uploads/signatures/         # Uploaded signature/stamp images (writable)
└── sql/schema.sql              # Full schema + seed data
```
