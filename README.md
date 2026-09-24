# TaskFlow

TaskFlow is a modern, responsive, and secure project and task management platform built with PHP and MySQL. Designed with an Arabic-first RTL interface and full BiDi isolation, it streamlines team workflows through an intuitive interactive Kanban board, real-time task discussions, enterprise user administration, automated database migrations, and daily maintenance cron jobs.

---

## Key Features

### 📋 Interactive Kanban Board
- **Three Workflow Columns**: *To Do (للتنفيذ)*, *In Progress (قيد التنفيذ)*, and *Done (مكتملة)*.
- **Drag-and-Drop & Fast Status Switching**: HTML5 drag-and-drop support with native select fallbacks.
- **Due Dates & Overdue Badges**: Automatic overdue indicators for pending tasks past their due date.
- **Deep Linking**: Share direct links to tasks (`?task=ID`) that automatically open the task details drawer.

### 💬 Threaded Task Comments & Notifications
- **Slide-over Drawer**: Inspect task details, metadata, and conversation history without leaving the Kanban board.
- **Real-Time Collaboration**: Markdown-safe text with multi-line support, timestamps, and edit/delete permissions.
- **Permission Matrix**: Admins and project owners can manage all comments; team members can edit their own.
- **Instant In-App Notifications**: Automatic notifications with direct links to task threads for assignees and participants.
- **Security & Anti-Abuse**: Rate-limiting (10 comments/min per user) and XSS sanitization.

### 👥 Enterprise User & Access Management
- **Closed Registration**: Public self-registration is disabled for maximum organizational control. Accounts are created and invited solely by administrators.
- **Role-Based Access Control (RBAC)**: Distinct permissions for `admin` and `member` roles.
- **One-Time Temporary Passwords**: Automatically generated 14-character secure passwords; forced password change upon first login.
- **Safe Member Deletion**: Full ownership transfer wizard for projects and unassignment of active tasks before account removal.
- **Session & Brute-Force Protection**: IP/email throttle tables, session regeneration, and automatic timeout handling.

### 🎨 Custom Branding & Multi-Install Support
- **Custom Visual Identity**: Set custom application name, dark-mode & light-mode logos, and dual-variant browser tab favicons.
- **Dual-Mode Mock Previews**: Live preview cards for both dark and light browser tabs at 16px and 32px.
- **Multi-Tenant / Subdomain Isolation**: Automatic environment resolution supporting `../.env.<folder>`, `../.env`, and `./.env`, enabling multiple company installations to run isolated databases on the same server without configuration collisions.

### 🔄 Database Migrations Engine
- **Dual Runner**: Run migrations either via Web UI (`admin/migrations.php`) or Terminal CLI (`bin/migrate.php`).
- **Automated Baseline Detection**: Seamlessly tracks schema history via `schema_migrations`, automatically baselining existing tables.
- **Idempotent SQL Scripts**: All migrations in `db/migrations/` are written idempotently to guarantee safe execution.

### ⏰ Daily Maintenance & Automated Backups
- **Automated Daily Cron (`bin/cron-daily.php`)**:
  - **Job 1 (Backups)**: Creates gzip-compressed SQL dumps and automatically enforces retention (`BACKUP_KEEP_DAYS`).
  - **Job 2 (Cleanup)**: Purges expired login throttles and old notifications.
  - **Job 3 (Reminders)**: Idempotently notifies assignees and owners of tasks due tomorrow.
- **Dashboard Alerts**: Warns administrators if scheduled backups have stalled or cron hasn't executed recently.

### 🌍 Arabic-First RTL & BiDi Isolation
- Fully designed in Arabic RTL with custom typography.
- Strict BiDi isolation (`<bdi dir="ltr">`, `.ltr`, `tabular-nums`) ensuring emails, dates, timestamps, filenames, numbers, and code snippets never flip or corrupt Arabic text flow.

---

## Technical Stack & Requirements

- **PHP**: `8.0` or higher (`8.1` / `8.2` recommended).
- **Database**: MySQL `5.7+` or MariaDB `10.3+`.
- **Required PHP Extensions**: `pdo_mysql`, `mbstring`, `openssl`, `json`.
- **Optional PHP Extensions**: `gd` (recommended for favicon luminescence checks in branding).
- **Web Server**: Apache or LiteSpeed with `mod_rewrite` and `mod_headers` enabled.

---

## Local Development Setup

### 1. Clone Repository
```bash
git clone https://github.com/yosrekh/taskflow.git
cd taskflow
```

### 2. Configure Environment File
Copy `.env.example` to `.env`:
```bash
cp .env.example .env
```
Edit `.env` to configure your local database credentials:
```ini
DB_HOST=localhost
DB_NAME=taskflow_db
DB_USER=root
DB_PASS=

APP_ENV=development
APP_TIMEZONE=Africa/Cairo
SESSION_NAME=TASKFLOW_SESSID
```

### 3. Initialize Database Schema
Create the database and import the baseline schema:
```bash
mysql -u root -e "CREATE DATABASE IF NOT EXISTS taskflow_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root taskflow_db < db/taskflow_db.sql
```

### 4. Run Pending Migrations
Apply any incremental schema migrations:
```bash
php bin/migrate.php --apply
```

### 5. Create the Initial Administrator Account
Since public registration is disabled, create your first admin using the CLI utility:
```bash
php bin/create-admin.php "System Admin" admin@example.com
```
*Note the generated temporary password displayed on the screen.*

### 6. Serve the Application
Place the directory inside your web server root (e.g. `C:/xampp/htdocs/taskflow` or Apache `vhost`) and navigate to:
```
http://localhost/taskflow
```
Log in using your admin credentials. You will be prompted to set a new password on your first login.

---

## CLI Utilities Reference

TaskFlow includes a set of CLI tools in the `bin/` directory for system management:

| Script | Description |
| :--- | :--- |
| `php bin/create-admin.php "Name" email@example.com` | Creates a new administrator account directly from the command line. |
| `php bin/make-admin-sql.php "Name" email@example.com` | Generates a hashed SQL INSERT statement to manually insert an admin via phpMyAdmin (useful when SSH is unavailable). |
| `php bin/migrate.php --status` | Checks database migration status and lists applied/pending migrations. |
| `php bin/migrate.php --apply` | Applies all pending migrations in `db/migrations/` sequentially. |
| `php bin/cron-daily.php` | Executes daily maintenance tasks (backup, cleanup, due-date reminders). |
| `php bin/cron-daily.php --dry-run` | Simulates cron execution without writing backups or database records. |

---

## Scheduled Tasks (Cron Setup)

To enable automated backups and due-date notifications, schedule `bin/cron-daily.php` to run once every night:

```bash
# Example crontab entry (runs at 02:00 AM daily)
0 2 * * * /usr/bin/php /path/to/taskflow/bin/cron-daily.php >> /path/to/taskflow/bin/cron-daily.log 2>&1
```

For cPanel shared hosting environments without root cron access, refer to the step-by-step instructions in [DEPLOY.md](DEPLOY.md).

---

## Multi-Company Installation & Hosting

TaskFlow supports hosting multiple company instances on subdomains using a single parent hosting account:

1. Create a separate database and subdomain folder for each company.
2. Place a company-specific environment file above the web root named `.env.<subdomain_folder>` (e.g. `/home/user/.env.company-a.example.com`).
3. TaskFlow detects and loads this environment file before falling back to default paths, maintaining complete database and asset isolation.

Detailed instructions for setting up new company instances and configuring AutoSSL, file permissions, and backups can be found in [DEPLOY.md](DEPLOY.md).

---

## Directory Structure

```text
taskflow/
├── admin/                 # Administration pages (branding, users, migrations)
├── assets/                # Static assets (default logos, icons)
├── bin/                   # Command-line tools and daily cron runners
├── css/                   # Stylesheets (responsive RTL design system)
├── db/                    # Baseline schema and incremental migrations
│   ├── migrations/        # Sequential idempotent SQL migrations
│   └── taskflow_db.sql    # Core database baseline schema
├── includes/              # Core PHP modules (auth, db, branding, migrations, nav)
├── projects/              # Project management endpoints and views
├── tasks/                 # Task Kanban and discussion drawer endpoints
├── uploads/branding/      # Company-uploaded logos and favicons (git-ignored)
├── .env.example           # Example environment configuration
├── .htaccess              # Security headers, HTTPS enforcement, URL rewrites
├── DEPLOY.md              # Production deployment & multi-install guide
└── README.md              # Project documentation
```

---

## License

This project is open-source software licensed under the [MIT License](LICENSE).
