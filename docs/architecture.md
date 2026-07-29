# Milestone 1 Architecture

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

`students` are academic profiles, not authentication identities. A student requires only basic name fields and a status; email and password are not collected. `user_id` is optional and may reference only an active member of the same tenant. Active, inactive, and archived states are explicit. Ordinary editing can select active or inactive; archival is a distinct confirmed action that records `archived_at` and never deletes enrollment history. Preferred name is used for ordinary display when present, while legal first and last names remain stored.

## Grade levels

`grade_levels` are platform defaults with stable codes, names, ordering, and active state. Defaults include Pre-K, Kindergarten, Grades 1-12, and Ungraded. Enrollment rows reference the grade-level record, so a future tenant customization/mapping layer can be added without rewriting historical enrollments.

## School years

School years belong to tenants and are permanent records. Status is draft, active, closed, or archived. End date must follow start date. Names are unique within a tenant. Allowed transitions are draft to active or archived, active to closed or archived, and closed to archived; archived is terminal.

Exactly one school year may be active per tenant. Activating one locks the tenant row, closes and audits the previous active year, and activates the selected record in one transaction. The tenant lock serializes competing activations. Closing or archiving never deletes enrollments or related history. Start and end values are date-only calendar fields and are not converted through the server timezone.

## Enrollments and history

An enrollment connects one tenant, student, school year, and platform grade level, with dates and planned, active, completed, withdrawn, or cancelled status. Grade progression is represented by new enrollments in later school years; no “current grade” is overwritten on the student.

Validation permits new enrollments only for active students and draft/active school years. Completed and withdrawn statuses require a completion/withdrawal date; other statuses reject that date. All enrollment dates must fall within the school year. The transaction locks the student before rejecting a second planned or active enrollment for the same student and school year, which serializes repeated submissions. Completed, withdrawn, or cancelled rows may coexist to preserve corrections, transfers, and reenrollment history without creating an ambiguous current enrollment. Composite tenant/record foreign keys ensure the student and school year belong to the same tenant at the database boundary; scoped validation enforces the same rule in the application.

## Audit logging

Administrative student, school-year, enrollment, and membership changes create immutable-through-the-tenant-UI `audit_logs` with tenant, actor, action, model type/identifier, before/after values, and timestamps. Strict per-model allowlists select the small set of auditable fields; authentication secrets are never included. Audit creation occurs in the same transaction as the primary change, so an audit failure rolls back the operation. Auditing requires an explicit active tenant context rather than attributing an event to a fallback tenant.

## Historical-data rules

Students, school years, and enrollments have no ordinary destructive route. Student archival is status-based. School years are closed or archived. Foreign keys restrict deletion of academic parents. Historical enrollment rows retain the grade level that applied in that school year.

## Test database safety

PHPUnit forces SQLite in memory. After the application boots but before Laravel invokes `RefreshDatabase`, migration, or truncation traits, the base test class stops immediately unless the active driver is SQLite and the database name is exactly `:memory:`. Missing and malformed configuration also fails closed. Tenant-isolation, role denial, switching, last-owner protection, school-year activation, enrollment uniqueness, and archival-history tests run through HTTP routes and database assertions.

## Dashboard rules

Dashboard queries always use the active tenant context. Active-student count includes only `active` students. Current-enrollment count includes only `planned` and `active` enrollments. Active school year means status `active`. Setup indicators report the existence of a school year, student, and current enrollment under those same definitions. Recent audit activity is tenant-scoped and is returned only to owners and administrators with `tenant.manage`.

## Membership onboarding

Milestone 1 member management edits existing memberships. Adding or inviting members, acceptance tokens, and invitation email are deliberately deferred and the interface says so. Duplicate tenant/user memberships are prevented by a unique database constraint.

## Scope limitations

Eloquent model operations receive the tenant safeguards described above. Raw SQL and bulk query-builder updates do not invoke Eloquent model events; any future bulk administrative operation must explicitly use the ownership service and establish tenant context. This is an implementation boundary, not an invitation to bypass the invariant.
