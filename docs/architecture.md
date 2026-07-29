# Milestone 1 Architecture

## Tenant resolution and switching

`ResolveActiveTenant` reads the tenant identifier only from the authenticated server session. It verifies an active membership and active tenant, falls back to the user’s first authorized active membership, and stores the validated selection. Switching accepts a route identifier only as a candidate: the server rechecks the authenticated user’s active membership before changing the session and regenerates the session identifier.

Middleware priority places tenant resolution before `SubstituteBindings`. Consequently, route model binding runs after the tenant context exists and tenant-owned model scopes can return a 404 for foreign records.

## Memberships, roles, and permissions

A user has many `tenant_memberships`, and each unique tenant/user pair has its own role and status. Roles map centrally to named permissions in `PermissionService`. Laravel policies cover students, school years, enrollments, and memberships. Form Requests recheck management permissions before validation.

Supported roles are owner, administrator, teacher, parent, tutor, and student. The ordinary tenant interface has no platform-administrator field or control. Invitations are deferred; a linked user must already exist and be an active tenant member.

The final active owner cannot be demoted or deactivated. The check locks relevant membership rows inside a transaction. Account deletion is also rejected when it would remove a tenant’s final active owner.

## Tenant-owned data and isolation

Students, school years, enrollments, and audit logs use the `BelongsToTenant` concern. It applies a central global query scope once the tenant context is resolved and fills `tenant_id` from that context during creation. Controllers do not accept a client-provided tenant ID. Foreign keys, request validation, policies, and service checks form additional boundaries.

Tenant middleware is required for every foundation route that reads or mutates tenant data. Cross-tenant route identifiers fail scoped binding. Enrollment validation resolves both student and school year through active-tenant scoped models.

## Students and user accounts

`students` are academic profiles, not authentication identities. A student requires only basic name fields and a status; email and password are not collected. `user_id` is optional and may reference only an active member of the same tenant. Active, inactive, and archived states are explicit. Archival records `archived_at` and never deletes enrollment history.

## Grade levels

`grade_levels` are platform defaults with stable codes, names, ordering, and active state. Defaults include Pre-K, Kindergarten, Grades 1–12, and Ungraded. Enrollment rows reference the grade-level record, so a future tenant customization/mapping layer can be added without rewriting historical enrollments.

## School years

School years belong to tenants and are permanent records. Status is draft, active, closed, or archived. End date must follow start date. Names are unique within a tenant.

Exactly one school year may be active per tenant. Activating one locks and closes the previous active year in the same transaction, then activates the selected record. Closing or archiving never deletes enrollments or related history.

## Enrollments and history

An enrollment connects one tenant, student, school year, and platform grade level, with dates and planned, active, completed, withdrawn, or cancelled status. Grade progression is represented by new enrollments in later school years; no “current grade” is overwritten on the student.

The service transaction rejects a second planned or active enrollment for the same student and school year. Completed, withdrawn, or cancelled rows may coexist to preserve corrections, transfers, and reenrollment history without creating ambiguous current enrollment. Supporting indexes make the transactional check efficient. Database foreign keys ensure every referenced record exists; scoped validation ensures tenant consistency.

## Audit logging

Administrative student, school-year, enrollment, and membership changes create `audit_logs` with tenant, actor, action, model type/identifier, before/after values, and timestamps. A denylist removes passwords, session/remember tokens, secrets, and API keys. Authentication secrets are never audited.

## Historical-data rules

Students, school years, and enrollments have no ordinary destructive route. Student archival is status-based. School years are closed or archived. Foreign keys restrict deletion of academic parents. Historical enrollment rows retain the grade level that applied in that school year.

## Test database safety

PHPUnit forces SQLite in memory. The base feature test class stops immediately unless the active driver is SQLite and the database name is exactly `:memory:`. Tenant-isolation, role denial, switching, last-owner protection, school-year activation, enrollment uniqueness, and archival-history tests run through HTTP routes.
