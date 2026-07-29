# Academic Configuration

## Historical boundary

A tenant-owned `school_years` row remains the permanent date, timezone, weekly schedule, target, and enrollment boundary. Its one `academic_year_configurations` row selects the instructional sources for that particular year:

```text
tenant -> school year -> academic configuration
                            |-> education provider
                            |-> calendar profile -> calendar events
                            |-> standards framework
                            `-> curriculum package -> course mappings -> courses -> subjects
```

The configuration progresses from `draft` to `active`, then may become `closed` or `archived`. It is not a mutable tenant-wide settings row. Active and historical configurations cannot silently replace their provider, calendar, framework, or package relationships. Closing and archiving retain every relationship.

The initial implementation allows exactly one current configuration row per tenant/school-year pair. This avoids ambiguous “current draft” selection. A future need for parallel proposals should add explicit configuration versions rather than weakening that invariant.

## Shared and tenant ownership

Education providers, calendar profiles, standards frameworks, subjects, courses, and curriculum packages support:

- platform-shared rows with `tenant_id = null` and `ownership_key = platform`;
- tenant-private rows with `tenant_id = <active tenant>` and `ownership_key = tenant:<id>`.

The non-null `ownership_key` is deliberate. MariaDB and SQLite both allow multiple null values in a composite unique index, so nullable `tenant_id` alone cannot reliably enforce a shared namespace. Unique constraints use the normalized ownership key.

`HasAcademicVisibility` requires an active `TenantContext` and exposes only platform rows plus the active tenant's rows. With no context it returns no records. It also assigns tenant ownership during normal tenant creation. Seeders use explicit query-builder writes for the small allowlisted platform dataset.

Ordinary tenant users may read active platform references but cannot create, edit, retire, or archive global rows. No tenant can view, select, or route-bind another tenant's private record. Form Requests validate every relationship ID with the same shared-or-active-tenant rule; client-supplied `tenant_id` is never fillable.

## Education providers

Provider types are `district`, `state_agency`, `private_school`, `homeschool_program`, `curriculum_publisher`, `learning_coop`, and `custom`. Providers are source identities, not behavior switches. Controllers and calculators do not branch on CFISD.

The platform seed includes Cypress-Fairbanks Independent School District (`CFISD`) as an active district reference in Texas. Its notes explicitly state that no official calendar, pacing, or curriculum is imported. Tenants may create private custom providers.

## Calendar profiles and events

A calendar profile is a reusable dated schedule template. It may be shared or tenant-owned, may reference a provider, and has a source type (`provider`, `tenant_custom`, `imported`, or `manual`) and lifecycle (`draft`, `active`, `retired`, `archived`).

A selected profile must cover the complete school-year date range. This prevents a partial calendar from producing a deceptively complete scheduled-day result. Events outside a school-year range are clipped out by the calculator.

Calendar events support single dates and inclusive ranges. Effects are:

- `non_instructional`: removes a date only when it was a base instructional weekday;
- `instructional`: adds a date only when it was not already a base instructional day;
- `informational`: does not affect the count.

For conflicts, precedence is:

```text
instructional override > non-instructional event > weekly schedule
```

This lets an explicit makeup/override reopen a date even when an overlapping closure range exists. Date sets deduplicate overlapping events, so each calendar date is counted at most once.

All dates are stored as SQL `date`, serialized as `YYYY-MM-DD`, parsed with date-only Carbon construction on the server, and formatted by the frontend's string-based `formatDateOnly` helper. Neither calculation nor display converts through UTC or JavaScript `Date`.

The dynamic summary is:

```text
base instructional days
- unique removed base days
+ unique added non-base overrides
= scheduled instructional days
```

No scheduled total is persisted, so saved event changes cannot leave a stale count. The school-year `instructional_day_target` remains an optional independent planning goal.

Current active calendars may receive real closures and makeup dates. Once their configuration is closed or archived, event changes are rejected. Calendar-profile identity/date changes are protected when referenced by an active or historical configuration.

## Standards frameworks

A framework is a versioned source container with optional provider, jurisdiction, effective dates, and source URL. The shared Texas Essential Knowledge and Skills (`TEKS`) row is an active, unversioned container only. It contains no individual standard codes, descriptions, alignments, or claim of completeness.

Tenants may create private framework containers. Shared frameworks are read-only.

## Subjects and courses

Platform subjects use stable codes and sort order:

`ELAR`, `MATH`, `SCI`, `SS`, `ART`, `MUSIC`, `PE`, `HEALTH`, `TECH`, `LANG`, `ELEC`, and `OTHER`.

Tenant custom subject codes are normalized to uppercase and unique inside the tenant namespace. The same code may be used independently by another tenant. Platform subjects cannot be edited by tenant users.

A course belongs to an authorized subject and may reference an authorized provider/framework. Its minimum and maximum grade levels are foreign keys to `grade_levels`; both are supplied together. Equal endpoints represent a single-grade course. Ordering is validated through the grade-level `sort_order`, so grade identity is never stored as an unchecked string.

Course codes are required and unique within the ownership namespace in this implementation. That deliberate requirement gives stable selection/mapping identifiers and avoids the cross-database nullable-unique ambiguity.

## Curriculum packages

A package is identified by owner, name, and required `version_label`. It can be `draft`, `active`, `retired`, or `archived`.

Draft packages may edit their descriptive fields and normalized course mappings. A mapping references a course and optional grade level, has a sort order and required/optional flag, and uses a non-null `grade_context_key` (`all` or `grade:<id>`) to enforce portable uniqueness. Units and lessons are not part of this schema.

Once a package is active, material edits and mapping removal are rejected. A referenced package cannot return to draft. Retiring or archiving preserves it. Future material changes require a new package version.

## Copy prior year

Copying requires academic-management permission and executes transactionally. It:

- creates a new configuration with `draft` status;
- copies provider, standards framework, and curriculum package references;
- copies the calendar only if it covers the complete target school year;
- adds a review-required note;
- does not copy enrollments, audit records, events, students, or school-year fields;
- never changes the source configuration.

A target that already has a configuration is rejected. Cross-tenant source or target IDs fail scoped validation.

## Permissions, isolation, and audit

Owners and administrators have full academic management. Teachers may view the academic setup and manage tenant courses/curriculum drafts, but not providers, calendars, frameworks, subjects, or the year configuration. Parents and tutors have read-only academic visibility. Student-linked users are rejected by administrative middleware before these routes and have no academic permissions.

Policies protect record mutation, global scopes protect lookup, Form Requests protect submitted relationships, restrictive foreign keys preserve history, and transactions serialize configuration writes. Nullable ownership never means accidentally public; platform visibility is an explicit branch in the academic visibility scope.

Audits use per-model field allowlists and remain tenant-scoped. Provider, calendar/profile event, framework, subject, course, package/mapping, configuration, activation, and copy changes record safe identifiers and lifecycle fields only. Notes, arbitrary payloads, credentials, secrets, and authentication tokens are not audited.

## Database deletion behavior

Historical parents use restrictive foreign keys. The tenant UI provides status transitions, not destructive deletion, for providers, profiles, frameworks, subjects, courses, packages, and configurations. Draft package mappings may be removed through the supported transaction and are audited first. Calendar events are archived rather than deleted.

## Seed limitations and deferred features

Seeders are idempotent and add only stable reference containers and subjects. They do not add tenant rows, demo accounts, official CFISD dates, district pacing, courses, curriculum content, TEKS standards, units, lessons, assignments, submissions, mastery, gradebook, attendance, reports, or AI tutoring.
