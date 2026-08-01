# Parent/Teacher Workspace

## Experience boundaries

The application deliberately separates three experiences:

1. The Student Portal at `/student` is identity-derived, enrollment-scoped, and uses its own layout. It is unchanged by the adult workspace.
2. The Parent/Teacher Workspace is the normal adult experience. It uses friendly language and summarizes saved academy, student, enrollment, calendar, and learning-plan data.
3. Advanced Academic Setup at `/academic-setup` owns technical source, provider, Calendar Profile, standards, subject, course, curriculum, and configuration workflows.

Adult login continues to target `/dashboard`, which now renders workspace Home. Adults without an active tenant membership are sent to tenant onboarding by the existing tenant middleware. Linked student users are rejected by administrative middleware and continue to route to Student Portal (or their required password-change page).

## Summary boundary

`WorkspaceSummaryService` builds a tenant-scoped read model shared by Home, Learning Plan, and Calendar. It does not persist derived status. The read model contains:

- active academy and active school-year context;
- active students, current-year enrollment and grade, and student-access state;
- a five-step setup checklist;
- prioritized, deduplicated attention items;
- tenant-local Today state;
- compatible selected Calendar Profile events that are upcoming;
- provider, standards, curriculum, and mapped courses from the active configuration;
- calendar base, removed, added, and scheduled totals from the existing calculator;
- the saved instructional-day target as an independent planning value.

Date-only values remain `YYYY-MM-DD` through PHP serialization. Vue uses the shared date-only formatter, which parses string fields rather than constructing a timezone-sensitive JavaScript `Date`.

## Friendly workspace areas

Home presents academy context, active year, students, setup, Needs Attention, Today, upcoming saved events, and quick actions. Its seven checklist steps are school year, enrollment, student login, calendar, courses, curriculum, and Ready for learning. Ready for learning requires an active year, current enrollment, compatible selected calendar, selected curriculum, and at least one mapped course. Student login is reported separately and does not permanently block readiness because a tenant may intentionally keep portal access disabled.

Students presents current enrollment and access details, with archived filtering and retained enrollment history. Learning Plan groups mapped courses by subject and uses honest guidance when provider, calendar, standards, curriculum, or courses are missing. Calendar explains calculated schedule totals and links authorized owners/administrators to technical calendar setup.

Learning Plan also presents Curriculum by subject for the selected student's enrollment grade and school year. Authorized owners, administrators, teachers, and parents can open the friendly Curriculum Intake workflow without starting in Advanced Academic Setup. Intake status comes from saved sources, review state, linked drafts, and approved course structure; an upload alone never makes a subject ready.

Assignments, Gradebook, Attendance, and Reports are explicit placeholders. No tables, models, records, calculations, sample activity, or fake progress were added for them. Student details likewise describe lesson activity, mastery, and progress as future work.

## Permissions and tenancy

`workspace.view` is granted to owner, administrator, teacher, parent, and tutor memberships. Student memberships receive no adult permissions and are also blocked by administrative-user middleware. `advanced-academic.view` controls visibility of the Advanced Academic Setup navigation and is granted to owners and administrators. Existing granular permissions remain authoritative for every technical route and action.

All records are resolved under `TenantContext`; switching an academy changes the server-side active membership before the summary is rebuilt. Cross-tenant identifiers and records cannot enter a workspace summary. The workspace performs read-only presentation and does not modify existing academic records.
