# Town Council Revenue, Finance & Administration Management System (TCMS)

A professional, integrated web-based management platform for Town Councils and Local Government authorities in Uganda.

## Overview

TCMS provides a complete digital trail connecting:

> Revenue Payers → Assessments → Payments → Receipts → Council Revenue → Budget → Department → Voucher → Approval → Payment → Accountability → Reporting → Audit

## Modules

| Module | Description |
|--------|-------------|
| **Revenue Management** | Payer registry, assessments, payments, official receipts |
| **Government Funds** | Track central government grants and utilization |
| **Budget Management** | Departmental budgets, budget vs actual analysis |
| **Payment Vouchers** | 6-stage approval workflow with electronic sign-off |
| **Department Management** | Budget and expenditure per department |
| **Document Library** | Upload, version control, access control |
| **Projects** | Government-funded project tracking |
| **Procurement** | Procurement request management |
| **Assets Register** | Council asset register |
| **Reports** | Revenue, finance, and audit reports |
| **Audit Trail** | Complete system activity log |
| **User Management** | Role-based access control (7 roles) |

## Technology Stack

- **Backend:** PHP 8.1+ with PDO
- **Database:** MySQL 8.0+
- **Frontend:** Vanilla HTML/CSS/JS (no framework dependencies)
- **Charts:** Chart.js 4
- **Fonts:** Inter (Google Fonts)

## Roles

| Role | Access Level |
|------|-------------|
| System Administrator | Full access + user management |
| Town Clerk | Council-wide view + senior approvals |
| Finance Officer | Financial management + payment processing |
| Revenue Officer | Payer registration + revenue collection |
| Head of Department | Department budget + expenditure requests |
| Auditor | Read-only access to all financial records |
| Council Management | Dashboard + reports |

## Quick Start (Local)

### Requirements
- PHP 8.1+
- MySQL 8.0+
- Apache with `mod_rewrite` enabled (WAMP / XAMPP / Laragon)

### Installation

1. Clone the repository:
   ```bash
   git clone https://github.com/mugabecharles/town-council-syst.git
   cd town-council-syst
   ```

2. Copy environment file:
   ```bash
   cp .env.example .env
   ```

3. Edit `.env` with your database credentials:
   ```env
   DB_HOST=localhost
   DB_NAME=tcms_db
   DB_USER=root
   DB_PASS=
   APP_URL=http://localhost/town-council-syst
   ```

4. Run the database setup in your browser:
   ```
   http://localhost/town-council-syst/setup.php?key=TCMS_SETUP_2026
   ```

5. **Delete `setup.php`** immediately after setup.

6. Login at:
   ```
   http://localhost/town-council-syst/
   ```
   - Username: `admin`
   - Password: `Admin@2026`
   - ⚠️ Change the password immediately after first login.

## Production Deployment

### Render (Recommended — PHP + MySQL)

1. Connect this repository to [Render](https://render.com)
2. Create a **Web Service** → select this repo
3. Set environment variables in the Render dashboard:
   ```
   DB_HOST        = <your MySQL host>
   DB_NAME        = tcms_db
   DB_USER        = <db user>
   DB_PASS        = <db password>
   APP_URL        = https://your-app.onrender.com
   APP_ENV        = production
   ```
4. Render will use `render.yaml` for configuration automatically.

### Vercel (PHP via Vercel Runtime)

1. Connect this repository to [Vercel](https://vercel.com)
2. Add environment variables in the Vercel dashboard (same as above)
3. Vercel uses `vercel.json` for routing configuration.

## Security Notes

- All forms use CSRF token protection
- Passwords hashed with bcrypt (cost 12)
- PDO prepared statements prevent SQL injection
- Session timeout: 30 minutes (configurable)
- Login lockout after 5 failed attempts
- `.htaccess` blocks direct access to uploads, SQL files, and dotfiles
- `config/database.php` is in `.gitignore` — credentials never committed

## Database Schema

The full schema is in `config/tcms_schema.sql`. Key table groups:

- **Administrative:** `users`, `roles`, `permissions`, `departments`
- **Geography:** `wards`, `parishes`, `villages`
- **Revenue:** `payers`, `revenue_assessments`, `revenue_payments`, `receipts`
- **Finance:** `budgets`, `payment_vouchers`, `voucher_approvals`, `government_funds`
- **Documents:** `documents`, `document_versions`
- **Projects:** `projects`, `procurement_requests`, `assets`
- **Audit:** `audit_logs`, `login_logs`

## Currency

All monetary values are in **Uganda Shillings (UGX)**.

## License

Developed for Town Council / Local Government use.  
&copy; 2026 Kijura Town Council. All rights reserved.
