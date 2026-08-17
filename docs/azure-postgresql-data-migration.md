# Cosmic Quest Academy data migration runbook

This runbook transfers application-owned data from local MySQL to the already-migrated PostgreSQL 16 schema. It never drops, truncates, or recreates tables. The executor only accepts an empty target and performs all PostgreSQL writes in one transaction.

## Scope

The ordered manifest is `App\Services\AcademyMigrationManifest::TABLES`. It includes users and password hashes, tenants and memberships, students and enrollments, school years, academic configuration, CFISD calendar/import records, curriculum/import records and standards, approved/draft lesson plans, generated lessons and resources, student lesson progress/responses, creative-writing prompts/entries, and audit logs.

The tool excludes `migrations`, `sessions`, `password_reset_tokens`, `cache`, `cache_locks`, `jobs`, `job_batches`, and `failed_jobs`. Those contain schema history or disposable runtime state and start fresh on PostgreSQL.

## Credentials and environment

Copy `.env.migration.example` to the ignored `.env.migration` file and fill in both connections locally. Never commit that file. Use a read-only MySQL source account if possible. The PostgreSQL account needs insert/update and sequence access.

The safety guard requires:

- source driver `mysql`;
- target driver `pgsql`, server major version 16;
- distinct source/target endpoints;
- target database and host listed in the explicit allowlists (defaults: `cosmic_academy` and `cosmic-academy-pg.postgres.database.azure.com`);
- an empty target across every application table;
- exact `--confirm-target` text for execution.

## Database commands

Quiesce writes to the local application and take provider-native backups before the final run.

```powershell
# Read-only planning pass; this is the default mode.
php artisan academy-data:migrate --env=migration

# Actual one-time transfer. Do not run until the dry run and backups are approved.
php artisan academy-data:migrate --env=migration --execute --confirm-target=cosmic_academy

# Independent read-only post-transfer validation.
php artisan academy-data:validate --env=migration
```

The command reports source and target counts before either mode. The dry run verifies drivers, PostgreSQL version, target name, schema parity, and an empty target. It reads and transforms every source row, validates JSON and dates, and performs no target writes. Execution preserves primary keys, defers and restores self-referencing keys, converts MySQL `0`/`1` values for PostgreSQL booleans, validates JSON without changing valid JSON text, preserves nulls/text/decimals/timestamps and string case exactly, rejects zero dates, resets ID sequences, and validates before commit. No rows are intentionally skipped. Any exception rolls back the target transaction.

Post-validation compares every table count and ordered primary-key set; separately reports user, membership, enrollment, school-year, CFISD-imported calendar, curriculum, generated-plan, generated-lesson, approved-lesson, resource, progress/response, creative-writing, and audit metrics; checks every PostgreSQL foreign key for orphans (including composite keys); checks sequences against `MAX(id)`; and verifies each user password hash was copied byte-for-byte and has a PHP-recognized format. Password values are never logged.

## Private file migration

Database rows reference files on Laravel's private `local` disk at `C:\xampp\htdocs\Learning-App\storage\app\private`: `academic_source_files.disk/stored_path` and `lesson_resources.asset_disk/asset_path` are the only database-backed file references found. The current local database has 47 references (9 academic-source files and 38 lesson-resource assets), totaling 11,455,638 bytes. The local read-only validator confirmed that all references exist and all recorded checksums match.

Create an empty staging directory outside the repository and local storage root. First inventory it, then explicitly stage the files:

```powershell
New-Item -ItemType Directory -Force "C:\migration-staging\cosmic-academy-private"
php artisan academy-files:stage "C:\migration-staging\cosmic-academy-private" --env=migration
php artisan academy-files:stage "C:\migration-staging\cosmic-academy-private" --env=migration --execute --confirm-destination="C:\migration-staging\cosmic-academy-private"
```

The stager copies only database-referenced paths, preserves relative directories, checks SHA-256 when stored in the database, skips identical files on repeat runs, and refuses to overwrite different files.

Preview the Azure uploads (no Azure calls), then run the explicitly confirmed upload only after review:

```powershell
.\scripts\Upload-AcademyFilesToAzure.ps1 -StagingDirectory "C:\migration-staging\cosmic-academy-private"
.\scripts\Upload-AcademyFilesToAzure.ps1 -StagingDirectory "C:\migration-staging\cosmic-academy-private" -Execute -ConfirmTarget 'Cosmic-Academy:/home/site/storage/app/private'
```

Before uploading, the script uses the signed-in Azure CLI identity and Kudu's read API to compare every existing remote file. It skips identical files and refuses the whole upload preflight if any remote path has different content. It then uses `az webapp deploy --type static --clean false --restart false` for missing files, preserving paths under `/home/site/storage/app/private`. It contains no Azure or database credentials.

Set these App Service settings before testing uploads or resources:

```text
FILESYSTEM_DISK=local
LESSON_RESOURCE_DISK=local
LOCAL_FILESYSTEM_ROOT=/home/site/storage/app/private
WEBSITES_ENABLE_APP_SERVICE_STORAGE=true
```

The `/home` content share is persistent across restarts. Keeping private files outside `/home/site/wwwroot` prevents normal code ZIP deployments from cleaning them. If the app later scales or needs an independent storage lifecycle, mount Azure Files at a dedicated path and point `LOCAL_FILESYSTEM_ROOT` to that mount.

No symbolic link is required: both academic-source downloads and lesson-resource responses stream through authenticated Laravel controllers from the private disk. `php artisan storage:link` only exposes the separate `public` disk and is not part of this migration.

After transferring the database and files, run this in the App Service SSH console:

```bash
php artisan optimize:clear
php artisan academy-files:validate
```

The validator is read-only and checks every active PostgreSQL file reference against the configured Laravel disk and recorded checksum.

## Cutover and rollback

1. Back up MySQL and the empty PostgreSQL schema, and stop all local writes.
2. Run the database and file dry runs again from the frozen source.
3. Stage and upload private files.
4. Execute the database transfer once.
5. Run both validators and inspect migration logs without printing credentials or row contents.
6. Confirm App Service uses `DB_CONNECTION=pgsql`, port `5432`, `DB_SSLMODE=require`, and the persistent filesystem settings; then clear cached configuration.
7. Smoke-test owner login, student login, tenant context, calendar, curriculum, approved/generated lessons, resource downloads, progress, and creative writing.

Before cutover, rollback means continuing to use the untouched MySQL application. A failure during execution automatically rolls back PostgreSQL. After a successful commit, do not rerun into the populated target and do not manually truncate it. Keep MySQL and staged-file backups intact; if rollback is required, point the site back to MySQL. A retry requires a separately approved clean PostgreSQL database or an explicitly reviewed restore/cleanup procedure.
