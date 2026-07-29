# Learning App

Learning App is a multi-tenant online learning and homeschool platform. Milestone 1 establishes secure tenant membership, students, data-driven grade levels, permanent school-year history, enrollments, member authorization, audit records, and a setup dashboard. Curriculum, standards, lessons, gradebook, attendance, and AI tutoring are intentionally deferred.

## Technology

- Laravel 12 / PHP 8.2+
- Laravel session authentication and Inertia
- Vue 3, TypeScript, Pinia, and Vite
- Bootstrap 5
- MySQL/MariaDB locally; SQLite in memory for tests
- PHPUnit 11 and Vitest

## Local prerequisites

Install PHP 8.2 or later, Composer 2, Node.js with npm, Git, and MySQL/MariaDB. The verified Windows environment uses XAMPP PHP 8.2.12 and MariaDB 10.4.32.

## Installation

```powershell
cd C:\xampp\htdocs\Learniing-App
composer install
npm install
Copy-Item .env.example .env
php artisan key:generate
```

Create a MariaDB database that contains no production data:

```powershell
C:\xampp\mysql\bin\mysql.exe -u root -e "CREATE DATABASE IF NOT EXISTS learning_app CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

Review `.env` and set the local database credentials. Never commit `.env`.

```powershell
php artisan migrate
php artisan db:seed
npm run build
```

For frontend development, run `npm run dev`. For a standalone PHP development server, run `php artisan serve` and use the URL it prints.

## Friendly local URL with XAMPP

Point an Apache virtual host for `learning-app.test` to:

```text
C:\xampp\htdocs\Learniing-App\public
```

Add `127.0.0.1 learning-app.test` to the Windows hosts file, enable Apache virtual hosts, restart Apache, and set `APP_URL=http://learning-app.test`. The document root must be `public`, never the repository root.

## Testing

```powershell
php artisan test
npm test
npm run build
```

`phpunit.xml` forces SQLite `:memory:`. Before Laravel's testing traits can run migrations, refreshes, or truncation, `tests/TestCase.php` checks the booted database configuration and accepts only the SQLite driver with the exact database name `:memory:`. Missing or malformed values fail closed. This protects `learning_app` and every other persistent database from destructive test setup.

## Database and seed data

`php artisan migrate` creates the platform tables. `php artisan db:seed` installs stable platform grade levels from Pre-K through Grade 12 plus Ungraded. The seeder creates no demo users, families, or students.

## Windows/XAMPP notes

- XAMPP's MariaDB client may not be on `PATH`; use `C:\xampp\mysql\bin\mysql.exe`.
- Keep Apache's document root on `public`.
- Ensure PHP extensions required by Laravel are enabled in XAMPP.
- The current workspace name contains a double "i" (`Learniing-App`); the friendly hostname is unaffected.

## Architecture summary

The active tenant is a server-side session selection validated against an active membership on each tenant request. Tenant middleware runs before route model binding. Tenant-owned models receive a centralized, fail-closed global scope and automatically receive the active `tenant_id` when created. Code that genuinely needs an unscoped query must opt into Laravel's explicit `withoutGlobalScopes()` API. Policies and centralized permission mappings provide a second authorization boundary. Students are academic profiles with an optional login relationship; grade is stored on a school-year enrollment, not the student.

See [Milestone 1 architecture](docs/architecture.md) for rules and decisions.

## Shared hosting and cPanel

Deploy source and Composer production dependencies, configure the web root to `public`, create a dedicated database/user, supply production environment variables outside Git, and run `php artisan migrate --force`. Use `APP_ENV=production`, `APP_DEBUG=false`, HTTPS, secure cookies, and writable `storage`/`bootstrap/cache`. The generated `public/build` directory is intentionally ignored by Git, so run `npm ci && npm run build` during deployment or include the resulting build directory in the deployment artifact. Laravel scheduler/queue configuration is only needed when later features introduce background work.

## Foundation behavior

- Owners and administrators manage existing memberships. Adding or inviting members, acceptance tokens, and invitation email are deferred.
- Students have legal first/last names and an optional preferred name. The preferred name is used for ordinary display when present; the legal names remain stored.
- School-year transitions are `draft -> active|archived`, `active -> closed|archived`, and `closed -> archived`; archived is terminal. Activating a year closes the tenant's prior active year transactionally.
- School years store their actual instructional weekdays as ascending ISO numbers (`1` Monday through `7` Sunday), rather than a restrictive four-day/five-day boolean. Five-day and four-day presets select Monday-Friday and Monday-Thursday; custom schedules can select any weekday pattern.
- Base instructional days are calculated dynamically and inclusively from the date-only start/end values and stored weekdays. The optional instructional-day target remains a separate planning goal and is never replaced by the calculation.
- School years store their actual instructional weekdays as ascending ISO numbers (`1` Monday through `7` Sunday), rather than a restrictive four-day/five-day boolean. Five-day and four-day presets select Monday-Friday and Monday-Thursday; custom schedules can select any weekday pattern.
- Base instructional days are calculated dynamically and inclusively from the date-only start/end values and stored weekdays. The optional instructional-day target remains a separate planning goal and is never replaced by the calculation.
- School years store their actual instructional weekdays as ascending ISO numbers (`1` Monday through `7` Sunday), rather than a restrictive four-day/five-day boolean. Five-day and four-day presets select Monday-Friday and Monday-Thursday; custom schedules can select any weekday pattern.
- Base instructional days are calculated dynamically and inclusively from the date-only start/end values and stored weekdays. The optional instructional-day target remains a separate planning goal and is never replaced by the calculation.
- One planned or active enrollment is allowed per student and school year. Completion and withdrawal dates are required for those terminal statuses and must fall within the school-year dates.
- Dashboard active students exclude inactive/archived students; current enrollments include planned/active only; the active year has status `active`; setup indicators reflect whether those records exist. Recent audit activity is visible only to users with tenant-management permission.
- Calendar values are stored as date-only fields. They represent the tenant's stated calendar dates and do not undergo server-timezone conversion.
- Final-owner checks run in transactions and at model boundaries. A tenant with memberships cannot be deleted directly.

## Deferred work

Invitations and member onboarding, custom tenant grade levels, district/calendar providers, TEKS and other standards, curriculum versioning, lessons and practice, submissions, mastery, gradebook, attendance, reports, portfolios, files, platform-admin UI, and AI tutoring are later milestones.

The current school-year calculation covers base instructional weekdays only. Future calendar profiles may subtract holidays, breaks, teacher workdays, staff-development days, weather closures, tenant days off, and district closures, then add instructional overrides or makeup days. No exception tables or district records are implemented yet.

Schema changes for this feature are additive. Existing school-year rows retain their identifiers, tenant relationships, dates, status, timezone, and optional target; only previously absent schedule fields are backfilled to the safe five-day Monday-Friday default. Persistent development data must never be refreshed, reset, wiped, truncated, rolled back, or reseeded during ordinary verification. Automated tests are guarded to use SQLite `:memory:` only.

The current school-year calculation covers base instructional weekdays only. Future calendar profiles may subtract holidays, breaks, teacher workdays, staff-development days, weather closures, tenant days off, and district closures, then add instructional overrides or makeup days. No exception tables or district records are implemented yet.

Schema changes for this feature are additive. Existing school-year rows retain their identifiers, tenant relationships, dates, status, timezone, and optional target; only previously absent schedule fields are backfilled to the safe five-day Monday-Friday default. Persistent development data must never be refreshed, reset, wiped, truncated, rolled back, or reseeded during ordinary verification. Automated tests are guarded to use SQLite `:memory:` only.

The current school-year calculation covers base instructional weekdays only. Future calendar profiles may subtract holidays, breaks, teacher workdays, staff-development days, weather closures, tenant days off, and district closures, then add instructional overrides or makeup days. No exception tables or district records are implemented yet.

Schema changes for this feature are additive. Existing school-year rows retain their identifiers, tenant relationships, dates, status, timezone, and optional target; only previously absent schedule fields are backfilled to the safe five-day Monday-Friday default. Persistent development data must never be refreshed, reset, wiped, truncated, rolled back, or reseeded during ordinary verification. Automated tests are guarded to use SQLite `:memory:` only.
