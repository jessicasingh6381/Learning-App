# Learning App

Learning App is a multi-tenant online learning and homeschool platform. The current foundation includes secure tenant membership, students, historical enrollments and school years, student access, and a historical Academic Configuration layer for providers, calendars, standards-framework containers, subjects, courses, and versioned curriculum packages. Lesson content, assignments, gradebook, attendance, reporting, and AI tutoring are intentionally deferred.

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
cd C:\xampp\htdocs\Learning-App
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
C:\xampp\htdocs\Learning-App\public
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

`php artisan migrate` creates the platform tables. `php artisan db:seed` idempotently installs stable grade levels, platform subjects, a CFISD provider reference, and a TEKS framework container. The CFISD and TEKS records contain no official calendar, pacing, curriculum, standards codes, or standards descriptions. Seeders create no demo users, families, students, courses, or tenant configurations.

## Windows/XAMPP notes

- XAMPP's MariaDB client may not be on `PATH`; use `C:\xampp\mysql\bin\mysql.exe`.
- Keep Apache's document root on `public`.
- Ensure PHP extensions required by Laravel are enabled in XAMPP.
- The verified project path is `C:\xampp\htdocs\Learning-App`.

## Architecture summary

The active tenant is a server-side session selection validated against an active membership on each tenant request. Tenant middleware runs before route model binding. Tenant-owned models receive a centralized, fail-closed global scope and automatically receive the active `tenant_id` when created. Code that genuinely needs an unscoped query must opt into Laravel's explicit `withoutGlobalScopes()` API. Policies and centralized permission mappings provide a second authorization boundary. Students are academic profiles with an optional login relationship; grade is stored on a school-year enrollment, not the student.

See [Architecture](docs/architecture.md), [Parent/Teacher workspace](docs/parent-teacher-workspace.md), [Curriculum intake](docs/curriculum-intake.md), [Academic configuration](docs/academic-configuration.md), and [Academic sources](docs/academic-sources.md) for rules and decisions.

## Shared hosting and cPanel

Deploy source and Composer production dependencies, configure the web root to `public`, create a dedicated database/user, supply production environment variables outside Git, and run `php artisan migrate --force`. Use `APP_ENV=production`, `APP_DEBUG=false`, HTTPS, secure cookies, and writable `storage`/`bootstrap/cache`. The generated `public/build` directory is intentionally ignored by Git, so run `npm ci && npm run build` during deployment or include the resulting build directory in the deployment artifact. Laravel scheduler/queue configuration is only needed when later features introduce background work.

## Foundation behavior

- Owners and administrators manage existing memberships. Adding or inviting members, acceptance tokens, and invitation email are deferred.
- Students have legal first/last names and an optional preferred name. The preferred name is used for ordinary display when present; the legal names remain stored.
- School-year transitions are `draft -> active|archived`, `active -> closed|archived`, and `closed -> archived`; archived is terminal. Activating a year closes the tenant's prior active year transactionally.
- School years store their actual instructional weekdays as ascending ISO numbers (`1` Monday through `7` Sunday), rather than a restrictive four-day/five-day boolean. Five-day and four-day presets select Monday-Friday and Monday-Thursday; custom schedules can select any weekday pattern.
- Base instructional days are calculated dynamically and inclusively from the date-only start/end values and stored weekdays. The optional instructional-day target remains a separate planning goal and is never replaced by the calculation.
- One planned or active enrollment is allowed per student and school year. Completion and withdrawal dates are required for those terminal statuses and must fall within the school-year dates.
- Adult users land in a friendly Parent/Teacher Workspace. Home, Students, Learning Plan, and Calendar summarize saved tenant data; technical resource management remains under Advanced Academic Setup.
- Assignments, Gradebook, Attendance, and Reports are navigation placeholders only. They create no feature records and perform no calculations.
- Curriculum Intake under Learning Plan lets authorized adults add grade- and subject-specific PDF, URL, or manual sources. Grade is derived from enrollment, files remain private, and source review is kept separate from draft or ready curriculum status.
- Calendar values are stored as date-only fields. They represent the tenant's stated calendar dates and do not undergo server-timezone conversion.
- Final-owner checks run in transactions and at model boundaries. A tenant with memberships cannot be deleted directly.

## Student access and portal

Student academic profiles remain independent of authentication accounts. Creating a student or enrollment never creates a login. An authorized owner, administrator, or teacher may explicitly enable access from the student page, which transactionally creates one username-only user, links it to the profile, creates an active Student membership in the same tenant, and writes credential-free audit records. The same link survives later school-year enrollments.

Students sign in through the shared **Email or username** field. Adult email authentication remains supported. Student usernames are trimmed and normalized to lowercase at request and model boundaries, restricted to 3–40 safe characters, checked against reserved names, and stored under a unique index. Student email is genuinely nullable; fake email addresses are not generated. MariaDB's case-insensitive application collation and normalized lowercase storage enforce the same uniqueness rule, while SQLite tests exercise the normalized unique value.

Temporary passwords are hashed with Laravel and normally require a first-login change. Until that change succeeds, the student can reach only the password-change route or log out. Adult-managed password resets set the requirement again and invalidate the student's other database sessions and remember token. Username-only students recover access through an authorized adult; the email reset flow remains for adults.

The student portal lives under `/student` with Home, My Learning, Profile, and password-change routes. Middleware derives the student exclusively from the authenticated user, verifies the linked profile, active Student membership, active tenant, enabled timestamp, and active student state, then establishes the tenant context. Student-linked users are rejected before all administrative and tenant-switching routes. The portal exposes only the student's own safe profile fields and active enrollment. My Learning is an honest placeholder; no curriculum, lesson, assignment, submission, mastery, gradebook, attendance, standards, district-calendar, or AI feature is implemented.

Disabling access deactivates the Student membership, invalidates sessions, and preserves the user, username, student profile, enrollments, and history. Re-enabling restores the same Student membership and account. Generic member management cannot change a linked student's role; student lifecycle changes use the dedicated access screen. Linking arbitrary existing users is intentionally deferred.

## Academic configuration

Each tenant school year may have one `academic_year_configurations` record linking the historical year to an education provider, calendar profile, standards framework, and versioned curriculum package. Platform records have `tenant_id = null`; an explicit `ownership_key` makes shared and tenant-private uniqueness portable between MariaDB and SQLite. Scoped queries expose only active-tenant records plus deliberate platform references and fail closed without tenant context. Platform references are read-only in the tenant UI.

Calendar profiles contain date-only events. Scheduled instructional days are calculated dynamically as base weekly days minus unique non-instructional dates plus unique instructional overrides. An instructional override wins a same-date conflict, then a non-instructional event, then the weekly pattern. Informational events never affect totals. Closed or archived configurations protect their calendar and curriculum history.

Calendar provenance has two independent forms. A profile's optional direct source URL/version identifies an official webpage or publication reference; linked Academic Sources are tenant-managed documents, URL records, or manual references with review and version history. The detail page shows both, opens only validated HTTP(S) references in a protected new tab, and keeps uploads behind authenticated file routes. Linking or unlinking a managed source never overwrites the direct URL, and editing or removing the direct URL never changes managed links. Neither action parses documents, creates events, or changes scheduled-day totals.

Calendar Profile lifecycle changes use dedicated audited actions. Draft and active profiles appear in the default list; archived profiles are available through Archived or All filters, while retired profiles remain available through All. Archive preserves the profile, events, direct source metadata, managed source links, audits, and historical configuration references, but is blocked while a draft or active Academic Setup configuration selects the profile. Restore always returns an archived profile to Draft and never creates or changes a configuration selection. Permanent deletion requires an explicit `DELETE` confirmation and is available only for tenant-owned profiles with no events, source links, or current/historical Academic Setup references. Dependencies are rechecked transactionally, shared profiles are protected, and deletion removes no provider, school year, source, event, or configuration.

Calendar Profile lifecycle changes use dedicated audited actions. Draft and active profiles appear in the default list; archived profiles are available through Archived or All filters, while retired profiles remain available through All. Archive preserves the profile, events, direct source metadata, managed source links, audits, and historical configuration references, but is blocked while a draft or active Academic Setup configuration selects the profile. Restore always returns an archived profile to Draft and never creates or changes a configuration selection. Permanent deletion requires an explicit `DELETE` confirmation and is available only for tenant-owned profiles with no events, source links, or current/historical Academic Setup references. Dependencies are rechecked transactionally, shared profiles are protected, and deletion removes no provider, school year, source, event, or configuration.

Curriculum packages are versioned. Drafts may change course mappings; active, retired, archived, or historically referenced versions retain their meaning. Copy-prior-year creates a review-required draft, never copies enrollments or audits, never mutates the source, and omits a year-specific calendar unless it covers the entire target school year.

## Deferred work

Invitations and member onboarding, custom tenant grade levels, individual TEKS or other standards, official district calendars and pacing, curriculum units, lessons and practice, assignments, submissions, mastery, gradebook, attendance, reports, portfolios, files, platform-admin UI, and AI tutoring are later milestones.

Calendar profiles now calculate scheduled days from saved non-instructional events and instructional overrides. No official CFISD calendar dates are seeded or imported.

## Academic source library

Authorized adults can preserve uploaded documents, external URL references, and manual source notes in a tenant-private academic source library. Uploads use generated server filenames on Laravel's private local disk, retain every replacement version, and store original filename, detected MIME type, size, and SHA-256 checksum. Downloads pass through tenant-scoped authorization; no public storage link is used.

URL records are store-only. The application validates an external HTTP(S) address and does not fetch, scrape, parse, or follow it. Review states and explicit links to controlled academic record types establish provenance. A reviewed source may create an empty calendar, curriculum-package, or course draft for adult completion, but no content is extracted or published automatically. Source counts on Academic Setup are evidence indicators and never make a configuration step complete.

For calendar setup, the overview distinguishes a source document from a structured Calendar Profile. It reports when a source, an unselected compatible profile, or a source-linked draft is available and links directly to the next review, creation, or selection action. Authorized PDF uploads can be viewed inline through an authenticated private route; other formats remain download-only. Calendar becomes complete only after a compatible draft or active profile is selected. Creating a draft never creates calendar events, so scheduled-day totals change only when adults add actual structured events.

Schema changes for this feature are additive. Existing school-year rows retain their identifiers, tenant relationships, dates, status, timezone, and optional target; only previously absent schedule fields are backfilled to the safe five-day Monday-Friday default. Persistent development data must never be refreshed, reset, wiped, truncated, rolled back, or reseeded during ordinary verification. Automated tests are guarded to use SQLite `:memory:` only.
