# Maulana Azad College Portal

A small college management system for the browser. PHP 8.1+, MySQL/MariaDB, plain custom CSS (no frameworks, works offline).

## Features

| Role | What they can do |
|---|---|
| **Student** | Dashboard with attendance % and marks %, attendance by subject (with "can miss N more" / "attend next N" hints), marks by exam, assignments (submit and resubmit until graded), notices, printable result card |
| **Teacher** | Dashboard of their subjects, take attendance (P / L / A per day), enter marks per exam, post and grade assignments, post notices, class reports |
| **Admin** | Everything above for all subjects, plus users, courses, subjects and teacher assignment, and attendance and marks reports |

- **Automatic calculations:** attendance % (late counts as present), per-exam %, per-subject and overall %, letter grade, pass/fail (pass mark and attendance minimum are in `includes/config.php`).
- **Printable output:** result card and all reports use a print stylesheet; reports also export to CSV.
- **Security basics:** PDO prepared statements, `password_hash`, session ID regeneration on login, CSRF tokens on every POST, output escaping, role checks on every page, teachers limited to their own subjects, login lockout after 5 failures (30 s).

## Setup

1. Create a MySQL/MariaDB user that can create a database (or create an empty `campusos` database yourself).
2. Set credentials via environment variables or edit `includes/config.php`:
   `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`, `COLLEGE_NAME`.
3. Install the tables and demo data:
   ```
   php install.php
   ```
   (or open `/install.php` in the browser once).
4. Run it:
   ```
   php -S localhost:8000
   ```
   or point Apache/Nginx at this folder.
5. **Delete `install.php`** and set `DEMO_MODE=0` to hide the demo logins on the sign-in page.

## Demo logins

| Role | Email | Password |
|---|---|---|
| Admin | admin@campus.test | admin123 |
| Teacher | teacher1@campus.test | teacher123 |
| Student | student1@campus.test | student123 |

There are 3 teachers (`teacher1..3`) and 12 students (`student1..12`). **Change or delete these before real use** (Users page).

## Layout

```
index.php  logout.php  dashboard.php     sign in, sign out, role dashboards
attendance.php  marks.php                role-aware: staff enter data, students view it
assignments.php  notices.php             post / submit / grade, announcements
result.php  reports.php                  printable result card, reports + CSV
users.php  courses.php                   admin only
includes/   config, bootstrap (DB, auth, CSRF, helpers), layout
assets/     style.css, app.js
schema.sql  install.php                  database
```

## Notes and limits

- Apache users: `includes/.htaccess` blocks direct access. On Nginx, deny `/includes/` and `/schema.sql` in your server config.
- Assignments are text answers (no file uploads).
- Marks from all recorded exams are summed per subject for the result card.
- Serve over HTTPS in production and set the session cookie `secure` flag.
