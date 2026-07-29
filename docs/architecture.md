# Learning App Architecture

## Tenant resolution and switching

`ResolveActiveTenant` reads the tenant identifier only from the authenticated server session. On every tenant request it verifies an active membership and active tenant, falls back to the user's first authorized active membership, and stores the validated selection. If no active membership remains, it clears the selection and sends the user to tenant onboarding. Switching accepts a route identifier only as a candidate: the server rechecks the authenticated user's active membership before changing the session and regenerates the session identifier.

Middleware priority places tenant resolution before `SubstituteBindings`. Consequently, route model binding runs after the tenant context exists and tenant-owned model scopes can return a 404 for foreign records.

## Memberships, roles, and permissions

A user has many `tenant_memberships`, and each unique tenant/user pair has its own role and status. Roles map centrally to named permissions in `PermissionService`. Laravel policies cover students, school years, enrollments, and memberships. Form Requests recheck management permissions before validation.

Supported roles are owner, administrator, teacher, parent, tutor, and student. The ordinary tenant interface has no platform-administrator field or control. Invitations are deferred; a linked user must already exist and be an active tenant member.

Only an owner may create, modify, or remove another owner membership. Administrators cannot promote themselves or alter owners. The final active owner cannot be demoted, deactivated, or deleted. Membership mutations and account deletion lock the tenant and relevant records inside transactions, and model events enforce the same invariant for ordinary Eloquent writes. A tenant with memberships cannot be deleted.

## Tenant-owned data and isolation

Students, school years, enrollments, tenant memberships, and audit logs are tenant-owned. The academic and audit models use `BelongsToTenant`; membership route binding is also scoped to the active context. The global scope fails closed with an empty result when no tenant context exists and fills `tenant_id` from that context during creation. Controllers do not accept a client-provided tenant ID. Foreign keys, request validation, policies, and service checks form additional boundaries. Console jobs and system operations must establish `TenantContext` or explicitly use `withoutGlobalScopes()` for a deliberate unscoped query; there is no ambient fallback tenant.

Tenant middleware is required for every foundation route that reads or mutates tenant data. Cross-tenant route identifiers fail scoped binding. Enrollment validation resolves both student and school year through active-tenant scoped models.

## Students and user accounts

`students` are academic profiles, not authentication identities. A student requires only basic name fields and a status; email and password are not collected. Creating an academic profile or enrollment never creates a user. Grade, school year, and academic history remain on enrollments, not users.

`students.user_id` is an optional one-to-one link to a dedicated student-authentication user. A unique index prevents one user from linking to multiple profiles, the scalar field prevents a profile from holding multiple users, and a composite foreign key requires the linked user to have a membership in the same tenant. The access workflow creates the user, Student membership, link, enable timestamp, and audits in one transaction under row locks. An audit or constraint failure rolls back every step. Broad existing-user search and arbitrary linking are deferred.

The same linked account remains across all future school-year enrollments. Student access enablement is an explicit capability and is never inferred from enrollment. Active, inactive, and archived academic states remain distinct. Ordinary editing cannot manipulate `user_id`; all account lifecycle changes go through the dedicated access service. Preferred name is used for ordinary display when present, while legal first and last names remain stored.

### Username authentication

The shared login accepts either an adult email address or a student username and always returns the same generic failure for unknown, invalid, or disabled accounts. The login value participates in the existing five-attempt rate limiter. Successful authentication regenerates the session and records `last_login_at`.

Usernames are trimmed and normalized to lowercase both before validation and at the User model boundary. They are 3–40 characters and may contain only lowercase letters, numbers, periods, hyphens, and underscores. Reserved administrative or routing names are rejected. A unique database index is applied to the normalized stored value. MariaDB uses the application's case-insensitive `utf8mb4_unicode_ci` collation; SQLite test compatibility comes from storing only normalized lowercase values before the portable unique constraint is evaluated.

Adult email remains required in registration and profile editing. The database column is nullable only so dedicated student accounts can use username-only authentication. Fake student email addresses are prohibited. Laravel's ordinary email reset remains available to adults; adult-managed reset is the recovery path for a username-only student.

### Password and access lifecycle

Enable access accepts a confirmed temporary password, hashes it with Laravel, and defaults `must_change_password` to true. A student with that flag may reach only `/student/password/change` and logout. Successful change replaces the hash, clears the flag, rotates the remember token, writes a safe audit, and regenerates the current session.

Authorized adults can change the normalized username, set a new confirmed temporary password, disable access, or re-enable access. Reset sets `must_change_password` again and invalidates other database sessions. Disable makes the Student membership inactive, rotates the remember token, and removes database sessions while preserving the user, username, profile link, enrollment history, and enable timestamp. Re-enable restores that same membership as Student and never creates a second user.

The active/inactive Student membership is the authoritative access status. `student_access_enabled_at` records when the capability was first established and is not a competing status boolean. Generic membership editing rejects linked student accounts so roles cannot drift away from Student or bypass lifecycle audits. A directly linked student user is intentionally a dedicated identity; mixed student/administrative memberships are unsupported in this milestone, and administrative middleware blocks that user regardless of client-supplied tenant state.

### Student portal isolation

Student routes are `/student`, `/student/learning`, `/student/profile`, and `/student/password/change`. Their middleware resolves:

```text
authenticated user
-> globally unique linked student profile
-> matching active Student membership
-> matching active tenant
-> active academic profile and enabled timestamp
-> active enrollment loaded through that profile
```

No student, tenant, enrollment, or school-year identifier is accepted from the browser for portal identity. Portal pages expose only name/preferred name, the student's own username, academy name, and their active enrollment's school year, grade, and status. With no active enrollment, the portal shows a setup message.

All tenant administration, member management, student management, school-year management, enrollment management, profile administration, tenant onboarding, and tenant switching routes require the administrative-user middleware before tenant resolution. A linked student user receives 403 even if a membership is manipulated. Disabled or otherwise stale student sessions fail the portal middleware, are logged out, and receive the generic login failure.

### Student access auditing

Credential lifecycle events are tenant-scoped and use strict allowlists:

- account created and access enabled
- username updated
- password reset
- password changed
- access disabled
- access re-enabled

Only username, password-change requirement, enable timestamp, and membership role/status metadata may enter these payloads. Plaintext passwords, confirmations, hashes, remember tokens, session identifiers, and reset credentials are never supplied to the audit service.

## Grade levels

`grade_levels` are platform defaults with stable codes, names, ordering, and active state. Defaults include Pre-K, Kindergarten, Grades 1-12, and Ungraded. Enrollment rows reference the grade-level record, so a future tenant customization/mapping layer can be added without rewriting historical enrollments.

## School years

School years belong to tenants and are permanent records. Status is draft, active, closed, or archived. End date must follow start date. Names are unique within a tenant. Allowed transitions are draft to active or archived, active to closed or archived, and closed to archived; archived is terminal.

Exactly one school year may be active per tenant. Activating one locks the tenant row, closes and audits the previous active year, and activates the selected record in one transaction. The tenant lock serializes competing activations. Closing or archiving never deletes enrollments or related history. Start and end values are date-only calendar fields and are not converted through the server timezone.

### Weekly instructional schedules

The authoritative weekly pattern is stored on each school year as a JSON array of ascending, unique ISO weekday numbers: `1` is Monday through `7` for Sunday. Storing the actual weekdays instead of an `is_four_day_week` boolean supports Monday-Friday, Monday-Thursday, Tuesday-Friday, non-contiguous schedules, weekend instruction, and other tenant-defined patterns without a schema change.

`instructional_week_type` is an interface preset label with the allowlisted values `five_day`, `four_day`, and `custom`. It is not used as the calculation source. The `five_day` preset must match `[1,2,3,4,5]`; `four_day` must match `[1,2,3,4]`; custom accepts any valid non-empty pattern. The form applies preset weekdays immediately and switches to custom when a user changes a preset selection. Server validation independently rejects empty, malformed, nested, duplicate, non-integer, out-of-range, or preset-inconsistent data, and normalizes valid weekdays into ascending order before persistence.

The additive schedule migration gives the type column a `five_day` default, adds the JSON column, and controlled-backfills only missing schedule fields to Monday-Friday. It does not replace school-year rows or alter identifiers, ownership, dates, timezone, status, or `instructional_day_target`. The application requires the JSON field even though it remains nullable at the database layer for portable MariaDB/SQLite migration behavior; all existing rows are backfilled and all application writes validate it.

### Base instructional days

`BaseInstructionalDayCalculator` dynamically counts calendar dates from `start_date` through `end_date`, inclusive, whose ISO weekday is present in `instructional_weekdays`. It operates on exact `YYYY-MM-DD` strings without UTC or browser timezone conversion. The inclusive iteration naturally handles partial weeks, month/year boundaries, and leap days. The result is exposed as `base_instructional_days` and is not persisted, avoiding stale derived data when dates or weekdays change.

`instructional_day_target` remains a nullable planning goal from 1 through 366. It is displayed separately and is never inferred, overwritten, or synchronized with base days.

Calendar profiles are implemented as a separate academic-configuration layer. Exception concepts include:

- `holiday`
- `break`
- `teacher_workday`
- `staff_development`
- `weather_closure`
- `instructional_makeup_day`
- `tenant_day_off`
- `district_closure`

The calculation boundary is:

```text
Base instructional weekdays
minus non-instructional calendar exceptions
plus instructional override or makeup days
equals scheduled instructional days
```

Overlapping dates are deduplicated and instructional overrides take precedence over non-instructional events, which take precedence over the weekly schedule. The result is dynamic rather than persisted. See [Academic Configuration](academic-configuration.md) for ownership, calendar, framework, course, curriculum, configuration, copy, permission, audit, and historical-integrity rules. No official district calendar or pacing data is imported.

## Enrollments and history

An enrollment connects one tenant, student, school year, and platform grade level, with dates and planned, active, completed, withdrawn, or cancelled status. Grade progression is represented by new enrollments in later school years; no “current grade” is overwritten on the student.

Validation permits new enrollments only for active students and draft/active school years. Completed and withdrawn statuses require a completion/withdrawal date; other statuses reject that date. All enrollment dates must fall within the school year. The transaction locks the student before rejecting a second planned or active enrollment for the same student and school year, which serializes repeated submissions. Completed, withdrawn, or cancelled rows may coexist to preserve corrections, transfers, and reenrollment history without creating an ambiguous current enrollment. Composite tenant/record foreign keys ensure the student and school year belong to the same tenant at the database boundary; scoped validation enforces the same rule in the application.

## Audit logging

Administrative student, school-year, enrollment, membership, and academic-configuration changes create immutable-through-the-tenant-UI `audit_logs` with tenant, actor, action, model type/identifier, before/after values, and timestamps. Strict per-model allowlists select the small set of auditable fields; authentication secrets, arbitrary metadata, and notes are never included. Audit creation occurs in the same transaction as the primary change, so an audit failure rolls back the operation. Auditing requires an explicit active tenant context rather than attributing an event to a fallback tenant.

## Historical-data rules

Students, school years, enrollments, and academic configurations have no ordinary destructive route. Student archival is status-based. School years and configurations are closed or archived. Shared/tenant academic resources use lifecycle statuses, package versions, restrictive foreign keys, and draft-only mapping removal. Historical enrollment rows retain the grade level that applied in that school year.

## Test database safety

PHPUnit forces SQLite in memory. After the application boots but before Laravel invokes `RefreshDatabase`, migration, or truncation traits, the base test class stops immediately unless the active driver is SQLite and the database name is exactly `:memory:`. Missing and malformed configuration also fails closed. Tenant-isolation, role denial, switching, last-owner protection, school-year activation, enrollment uniqueness, and archival-history tests run through HTTP routes and database assertions.

## Dashboard rules

Dashboard queries always use the active tenant context. Active-student count includes only `active` students. Current-enrollment count includes only `planned` and `active` enrollments. Active school year means status `active`. Setup indicators report the existence of a school year, student, and current enrollment under those same definitions. Recent audit activity is tenant-scoped and is returned only to owners and administrators with `tenant.manage`.

## Membership onboarding

Milestone 1 member management edits existing memberships. Adding or inviting members, acceptance tokens, and invitation email are deliberately deferred and the interface says so. Duplicate tenant/user memberships are prevented by a unique database constraint.

## Scope limitations

Eloquent model operations receive the tenant safeguards described above. Raw SQL and bulk query-builder updates do not invoke Eloquent model events; any future bulk administrative operation must explicitly use the ownership service and establish tenant context. This is an implementation boundary, not an invitation to bypass the invariant.
