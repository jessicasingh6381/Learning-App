# Learning App

Learning App is a multi-tenant online learning and homeschool platform. Milestone 1 establishes secure tenant membership, students, data-driven grade levels, permanent school-year history, enrollments, member authorization, audit records, and a setup dashboard. Curriculum, standards, lessons, gradebook, attendance, and AI tutoring are intentionally deferred.

## Technology

- Laravel 12 / PHP 8.2+
- Laravel Breeze session authentication and Inertia
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

`phpunit.xml` forces SQLite `:memory:` and `tests/TestCase.php` refuses to run feature tests against anything else. This protects `learning_app` and every other persistent database from `RefreshDatabase`.

## Database and seed data

`php artisan migrate` creates the platform tables. `php artisan db:seed` installs stable platform grade levels from Pre-K through Grade 12 plus Ungraded. The seeder creates no demo users, families, or students.

## Windows/XAMPP notes

- XAMPP’s MariaDB client may not be on `PATH`; use `C:\xampp\mysql\bin\mysql.exe`.
- Keep Apache’s document root on `public`.
- Ensure PHP extensions required by Laravel are enabled in XAMPP.
- The current workspace name contains a double “i” (`Learniing-App`); the friendly hostname is unaffected.

## Architecture summary

The active tenant is a server-side session selection validated against an active membership. Tenant middleware runs before route model binding. Tenant-owned models receive a centralized global scope and automatically receive the active `tenant_id` when created. Policies and permission mappings provide a second authorization boundary. Students are academic profiles with an optional login relationship; grade is stored on a school-year enrollment, not the student.

See [Milestone 1 architecture](docs/architecture.md) for rules and decisions.

## Shared hosting and cPanel

Deploy source and Composer production dependencies, configure the web root to `public`, create a dedicated database/user, supply production environment variables outside Git, and run `php artisan migrate --force`. Use `APP_ENV=production`, `APP_DEBUG=false`, HTTPS, secure cookies, writable `storage`/`bootstrap/cache`, and Laravel scheduler/queue configuration only when later features need them. Build frontend assets before deployment when Node is unavailable on the host.

## Deferred work

Invitations and member onboarding, custom tenant grade levels, district/calendar providers, TEKS and other standards, curriculum versioning, lessons and practice, submissions, mastery, gradebook, attendance, reports, portfolios, files, platform-admin UI, and AI tutoring are later milestones.
