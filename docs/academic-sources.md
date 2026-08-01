# Academic Sources

## Purpose and scope

The academic source library preserves evidence that adults may later use to configure calendars, curriculum, courses, standards, and other academic records. A source is intake material, not trusted structured content. This milestone does not fetch websites, parse PDFs or Office documents, perform OCR, run AI extraction, import official district or TEKS data, or create lessons and assignments.

## Records and provenance

`academic_sources` stores tenant ownership, a generated UUID, creator, source kind, category, authority level, review state, processing state, optional academic context, descriptive metadata, and archival state. Source kinds are upload, URL, and manual. Category, authority, review, processing, and link-type values are centralized allowlists rather than arbitrary polymorphic input.

`academic_source_links` connects a source to an allowlisted record type and identifier. Supported targets are education providers, school years, calendar profiles, standards frameworks, grade levels, subjects, courses, curriculum packages, and academic-year configurations. The service resolves every target through its tenant-aware or shared-reference model scope before storing a unique link. This supports multiple relationships without accepting arbitrary class names.

Sources use lifecycle states: unreviewed, in review, reviewed, rejected, and archived. Transitions are server validated and archived is terminal. Archival preserves metadata, links, files, and audits.

## Private uploads and versions

Uploads are stored on the configurable `academic_sources.disk`, which defaults to Laravel's private `local` disk under `storage/app/private`. No source file is written under `public`, and `storage:link` is neither required nor appropriate. A generated UUID filename prevents path-controlled storage. The original name is metadata only.

Allowed extensions are PDF, PNG, JPG/JPEG, WEBP, DOCX, XLSX, CSV, and TXT. Validation checks the extension, declared MIME type, detected MIME type, upload validity, and configurable size limit (25 MB by default). DOCX and XLSX permit their official MIME types and ZIP container detection. The server records detected MIME, size, and SHA-256 checksum.

Each replacement increments a per-source version number inside a transaction and marks exactly one version current. Earlier versions and their bytes remain available for authorized historical download. Download routes resolve the active tenant, authorize the source, verify file/source ownership and storage existence, force an attachment response with `application/octet-stream`, and set `X-Content-Type-Options: nosniff`. Models hide disk and stored-path fields from Inertia serialization.

Matching checksums are retained as separate source versions; the application neither deduplicates nor silently reuses a tenant's file, and checksum comparison never crosses a tenant boundary. No malware scanner is integrated in this environment, so accepted files are not claimed to be malware-free. MIME checks, private storage, forced downloads, and authorization reduce exposure but do not replace future antivirus scanning.

## URL safety boundary

URL sources accept only syntactically valid HTTP or HTTPS addresses without embedded credentials. Literal loopback, private, reserved, and link-local IP addresses and local/internal hostnames are rejected. The application stores the string and retrieval timestamp but never resolves DNS, opens a socket, follows redirects, fetches content, or renders remote markup. Because there is no outbound request in this milestone, DNS rebinding is outside the execution path; any future fetcher must add resolution-time and redirect-hop network controls rather than reusing store-only validation as an SSRF defense.

External links open with `noopener`, `noreferrer`, and `nofollow`. They are displayed as user-supplied references, not endorsed or trusted content.

## Review and structured drafts

A reviewed calendar-category source may create an empty draft calendar covering the selected school year. A reviewed curriculum, pacing, or scope-and-sequence source may create an empty draft curriculum package. A reviewed course-guide or curriculum source may create a draft course from adult-entered required fields. Every draft is linked back to the source and audited. These actions do not extract dates, events, courses, units, lessons, standards, or other source content, and they do not activate the result.

### Calendar source workflow

The Academic Setup overview treats calendar evidence and calendar configuration as separate states:

- **Missing:** no related source and no compatible selected profile.
- **Source available:** one or more related sources exist, but no structured profile is available from the source.
- **Draft profile available:** a source-linked draft covers the school year but has not been selected.
- **Profile available:** another compatible draft or active profile exists but is not selected.
- **Complete:** the configuration selects an authorized draft or active profile covering the school year and matching the configured provider.

Exactly one related source links directly to its detail page; multiple sources link to the library filtered by calendar category, school year, and provider when selected. An unreviewed source explains that review is required. After review, an authorized adult may create an empty draft using the source title, provider, academic-year label, school-year date range, timezone, source URL, and a provenance note. The action links the profile to the source and redirects to the profile with instructions to add events and return to Academic Setup. It never parses the PDF or creates holiday, closure, break, or instructional dates.

PDF file versions have an authenticated inline-view route in addition to secure download. It reuses tenant-scoped source binding and download authorization, verifies the file belongs to the source, checks storage existence, and permits only the PDF MIME/extension pair. Responses use `application/pdf`, inline disposition, and `X-Content-Type-Options: nosniff`; private paths are never returned. DOCX, XLSX, CSV, TXT, images, and other formats remain download-only. No public storage URL is created.

Academic Setup reports related active-source counts for calendar, curriculum, and course areas. Counts are informational evidence and remain separate from the existing completion checklist, whose meaning is still based on configured structured records.

## Authorization

- Owners and administrators may view, add, manage, review, download, and archive sources.
- Teachers may view, add, manage, review, and download sources.
- Parents may view, add, and download sources but cannot manage metadata or review state.
- Tutors may view and download sources.
- Students have no source-library permission and are blocked from administrative routes.

Tenant-scoped binding and global scopes return no foreign source, file, or link records. Controlled target resolution also respects tenant/shared academic visibility.

## Audit and retention

Creating, editing, reviewing, archiving, linking, unlinking, uploading, replacing, downloading, and creating a structured draft produces tenant audit activity. Audit allowlists omit source URLs, notes, descriptions, disk names, private paths, generated filenames, and file contents. No credentials or remote content are logged.

Foreign keys are restrictive for sources and their evidence. There is no delete route. File replacement and source archival preserve history. The schema migration is additive and does not change existing users, tenants, school years, enrollments, students, configurations, or reference records.
