# Curriculum Intake

## Purpose

Curriculum Intake is the friendly Parent/Teacher workflow under Learning Plan for organizing grade-level, subject-specific curriculum evidence. It supports any tenant, enrollment grade, school year, provider, district, publisher, custom homeschool curriculum, or other adult-identified source. It does not import official curriculum content.

## Five-step workflow

1. Choose an active student and a draft or active school year with a planned or active enrollment. When only one valid context exists, it is selected automatically. Grade is displayed from that enrollment and is never accepted from the browser.
2. Choose an existing visible district/provider/publisher or identify the source as custom homeschool curriculum or another source. Shared reference providers such as CFISD remain data, not hardcoded behavior.
3. Choose an active tenant-visible or shared subject. Subject identifiers are resolved through the existing academic-visibility scope.
4. Upload a PDF, store a safe source URL, or add an identifying manual reference. Uploads remain on private storage with generated names and original-name metadata. URLs are stored but never fetched, parsed, proxied, or previewed.
5. Review the student, school year, grade, provider/source type, subject, and source format before saving.

Saving creates one tenant-owned Academic Source with `curriculum` category, unreviewed status, and provider/year/grade/subject links. It never creates a student link because student context is derived from the enrollment rather than duplicated onto reusable academic evidence.

## Subject status rules

- **Not started:** no matching active curriculum source exists.
- **Source added:** at least one source exists but has not entered review.
- **Needs review:** at least one source is in review.
- **Reviewed:** at least one source has been marked reviewed by an authorized adult.
- **Draft curriculum started:** a matching source links to a Curriculum Package.
- **Ready for lesson planning:** a linked active Curriculum Package contains an approved course mapping for the subject and selected grade.

The highest applicable state is shown. A PDF upload alone is never Reviewed, Draft curriculum started, or Ready for lesson planning.

## Review and draft outline

Review transitions reuse the Academic Source lifecycle and permission policy. Authorized adults may view/download private PDFs, start review, mark reviewed, edit metadata, or archive through existing routes.

A reviewed curriculum source may initialize one draft Curriculum Package. Repeated requests open the existing linked draft instead of creating a duplicate. The draft copies only adult-confirmed name, version label, provider, safe URL when applicable, and the source link. School year, grade, and subject remain available through the linked source. Description, framework, courses, units, lessons, standards codes, pacing dates, and assignments remain empty until an adult supplies them in an appropriate later workflow.

## Permissions and isolation

Owners, administrators, teachers, and parents currently have the existing `academic-sources.create` permission and may enter sources. Review and metadata-management actions continue to require their existing granular permissions; draft initialization requires `curriculum.manage`. Tutors without create permission cannot open intake. Students are blocked by administrative middleware and have no source, file, review, curriculum-management, or Advanced Setup permissions.

All model queries use the active `TenantContext` and existing academic-visibility rules. Form validation rejects foreign students and school years, prohibits client tenant and grade ownership fields, and resolves provider/subject links through tenant-aware services. Private file routes authorize the source, verify file ownership, and fail closed for foreign identifiers.

## Deferred processing

No PDF parsing, OCR, scraping, AI extraction, TEKS import, standards mapping, outline generation, unit creation, lesson generation, pacing generation, assignment generation, or publication workflow is implemented. The word “outline” refers only to the empty linked Curriculum Package shell.
