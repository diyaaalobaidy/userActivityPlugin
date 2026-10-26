# User Activity & Database Audit System Plugin for OJS 3.5

A system-wide security and database audit plugin for **Open Journal Systems (OJS) 3.5**, accessible strictly by **Site Administrators** (`ROLE_ID_SITE_ADMIN`), that records and displays all user activities from login/logout to every `INSERT`, `UPDATE`, `DELETE`, and `REPLACE` action executed on the database.

---

## 🔒 Key Features

1. **Strictly Site Administrator Only (`ROLE_ID_SITE_ADMIN`)**:
   - Enforces `Validation::isSiteAdmin()` and `RoleBasedHandlerOperationPolicy` (`Role::ROLE_ID_SITE_ADMIN`) across all plugin actions, routes, and exports.
   - Non-admin users (including Journal Managers, Editors, Authors, and Reviewers) cannot view or access the audit dashboard, plugin settings, or exports.
   - Marked as `<sitewide>1</sitewide>` and permanently active so no user action can bypass auditing.

2. **Complete Authentication & Session Lifecycle Tracking**:
   - **User Logins (`LOGIN`)**: Captures successful logins across all journals and site contexts with User ID, `@username`, full name, email, roles, session ID, real client IP (supporting Cloudflare/Nginx/reverse proxies), and User-Agent.
   - **User Logouts (`LOGOUT`)**: Captures explicit user sign-outs and session terminations.
   - **Failed Login Attempts (`LOGIN_FAILED`)**: Detects and records failed sign-in attempts along with the attempted username and client IP address.
   - **Admin Impersonation (`IMPERSONATE_START` / `IMPERSONATE_END`)**: Logs when an administrator uses "Log in As" (`signInAsUser`) to impersonate another user.

3. **Universal Database Mutation Auditing (`DB::listen`)**:
   - Intercepts every `INSERT`, `UPDATE`, `DELETE`, and `REPLACE` SQL statement executed across all OJS tables.
   - Captures in-memory and flushes safely at request shutdown (`register_shutdown_function`) so `PDO::lastInsertId()` and active database transactions are never disrupted.
   - Records the target table, operation type, primary key / record ID, new column values, `WHERE` conditions, interpolated SQL statement, HTTP method, request URL, and sanitized request payload (automatically redacting passwords, tokens, and secrets).

4. **High-Level Domain Lifecycle Diffs (Before vs. After)**:
   - Hooks into OJS 3.5 Repositories and Eloquent Models (`User`, `Submission`, `Publication`, `Author`, `SubmissionFile`, `ReviewAssignment`, `Issue`, `Decision`, `StageAssignment`, `Query`, `Note`) to capture exact **Old Value (Before)** vs. **New Value (After)** field diffs.

5. **Interactive Admin Audit Dashboard**:
   - Accessible from the Backend Sidebar (**User Activity & DB Audit**), from **Settings > Website > Plugins > User Activity & Database Audit System Plugin**, or directly at `/{context}/userActivity/index`.
   - **Live KPI Cards**: Total Recorded Events, Events Today, Logins & Logouts, Failed Logins, Database Mutations, and Distinct Active Users.
   - **Multi-Dimensional Filtering**: Filter by keyword search, category (`auth`, `user`, `submission`, `publication`, `review`, `file`, `email`, `settings`, `database`), SQL operation (`LOGIN`, `LOGOUT`, `LOGIN_FAILED`, `INSERT`, `UPDATE`, `DELETE`, `REPLACE`), database table, user, journal context, IP address, and date range.
   - **Deep Record Inspector Modal**: Click **Inspect** on any row to view side-by-side field diffs, `WHERE` conditions, executed SQL statement, and sanitized HTTP payload.
   - **One-Click Exports & Retention Management**: Export filtered audit logs to **CSV** (Excel-compatible UTF-8 BOM) or **JSON**, and purge older records by retention period.

---

## 📂 Directory Structure

```text
userActivityPlugin/
├── UserActivityPlugin.php                          # Main OJS 3.5 sitewide system plugin class
├── index.php                                       # Plugin bootstrap wrapper
├── version.xml                                     # Plugin metadata (<sitewide>1</sitewide>)
├── classes/
│   ├── UserActivityHelper.php                      # Admin verification, IP resolution, sanitization, helpers
│   ├── UserActivityRecorder.php                    # Universal DB::listen, Auth, and Domain Hook recorder
│   ├── handlers/
│   │   └── UserActivityHandler.php                 # Admin-only dashboard, AJAX filter API, CSV/JSON exporter
│   └── migration/
│       └── UserActivitySchemaMigration.php         # Creates `user_activity_logs` table automatically
├── templates/
│   ├── dashboard.tpl                               # Full-page Admin Audit Dashboard template
│   ├── dashboardModal.tpl                          # Modal dashboard wrapper
│   ├── quickSummaryModal.tpl                       # Quick summary modal in Plugin Gallery
│   └── activityDetailModal.tpl                     # Record inspection template
├── js/
│   └── userActivity.js                             # Interactive filter, pagination, and diff modal script
├── css/
│   └── userActivity.css                            # Modern dashboard & modal styling (with RTL support)
├── locale/
│   ├── en/
│   │   └── locale.po                               # English translations
│   └── ar/
│       └── locale.po                               # Arabic translations
└── README.md
```

---

## 🚀 Installation

1. Copy or symlink this plugin directory into your OJS 3.5 installation at:
   ```bash
   plugins/generic/userActivityPlugin
   ```
2. Register the plugin via CLI or Plugin Gallery:
   ```bash
   php tools/upgrade.php check
   ```
   *(The `user_activity_logs` database table is created automatically on first run or when opening the dashboard).*
3. Log in as a **Site Administrator** and click **User Activity & DB Audit** in the left navigation sidebar (or open **Settings > Website > Plugins > Generic Plugins > User Activity & Database Audit System Plugin > Activity & Database Audit Log**).
