<?php

namespace Tests\Feature;

use App\Models\AcademicSource;
use App\Models\AcademicSourceFile;
use App\Models\SchoolYear;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Rules\AcademicSourceUpload;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AcademicSourceLibraryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_owner_can_add_private_upload_with_safe_metadata_and_checksum(): void
    {
        Storage::fake('local');
        [$owner, $tenant] = $this->tenantUser();
        $year = $this->schoolYear($owner, $tenant);

        $response = $this->actingIn($owner, $tenant)->post('/academic-setup/sources', [
            ...$this->sourcePayload(['school_year_id' => $year->id]),
            'source_file' => $this->pdf('district-calendar.pdf'),
        ]);

        $source = AcademicSource::query()->firstOrFail();
        $file = $source->currentFile()->firstOrFail();
        $response->assertRedirect(route('academic.sources.show', $source));
        $this->assertSame('unreviewed', $source->review_status);
        $this->assertSame('not_requested', $source->processing_status);
        $this->assertSame(1, $file->version_number);
        $this->assertSame(64, strlen($file->checksum_sha256));
        $this->assertNotSame($file->original_filename, $file->stored_filename);
        Storage::disk('local')->assertExists($file->stored_path);
        $this->assertDatabaseHas('academic_source_links', ['academic_source_id' => $source->id, 'link_type' => 'school_year', 'link_id' => $year->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'academic-source.file-uploaded']);
    }

    public function test_upload_rejects_mismatched_and_unsupported_content(): void
    {
        Storage::fake('local');
        [$owner, $tenant] = $this->tenantUser();

        $this->actingIn($owner, $tenant)->post('/academic-setup/sources', [
            ...$this->sourcePayload(),
            'source_file' => $this->fileWithContent('calendar.pdf', 'application/pdf', 'plain text, not a PDF'),
        ])->assertSessionHasErrors('source_file');

        $this->actingIn($owner, $tenant)->post('/academic-setup/sources', [
            ...$this->sourcePayload(),
            'source_file' => UploadedFile::fake()->createWithContent('calendar.exe', 'MZ'),
        ])->assertSessionHasErrors('source_file');
        $this->assertDatabaseCount('academic_sources', 0);
    }

    public function test_every_documented_upload_extension_accepts_matching_content(): void
    {
        foreach (['pdf', 'png', 'jpg', 'jpeg', 'webp', 'docx', 'xlsx', 'csv', 'txt'] as $extension) {
            $validator = Validator::make(
                ['source_file' => $this->allowedFile($extension)],
                ['source_file' => [new AcademicSourceUpload]],
            );
            $this->assertFalse($validator->fails(), $extension.': '.$validator->errors()->first('source_file'));
        }
    }

    public function test_url_sources_are_store_only_and_reject_internal_or_dangerous_urls(): void
    {
        [$owner, $tenant] = $this->tenantUser();
        $payload = $this->sourcePayload(['source_kind' => 'url', 'source_file' => null]);

        foreach (['javascript:alert(1)', 'file:///etc/passwd', 'http://localhost/calendar', 'http://127.0.0.1/a', 'http://10.1.2.3/a', 'https://user:pass@example.com/a'] as $url) {
            $this->actingIn($owner, $tenant)->post('/academic-setup/sources', [...$payload, 'source_url' => $url])
                ->assertSessionHasErrors('source_url');
        }

        $this->actingIn($owner, $tenant)->post('/academic-setup/sources', [...$payload, 'source_url' => 'https://www.example.edu/calendar.pdf'])
            ->assertRedirect();
        $this->assertDatabaseHas('academic_sources', ['source_url' => 'https://www.example.edu/calendar.pdf', 'source_kind' => 'url']);
        $this->assertDatabaseCount('academic_source_files', 0);
    }

    public function test_manual_sources_are_tenant_assigned_and_require_identifying_content(): void
    {
        [$owner, $tenant] = $this->tenantUser();
        [, $otherTenant] = $this->tenantUser('owner', 'Other Tenant');
        $payload = $this->sourcePayload([
            'source_kind' => 'manual', 'description' => null, 'notes' => null,
            'tenant_id' => $otherTenant->id,
        ]);

        $this->actingIn($owner, $tenant)->post('/academic-setup/sources', $payload)
            ->assertSessionHasErrors(['description', 'tenant_id']);
        unset($payload['tenant_id']);
        $this->actingIn($owner, $tenant)->post('/academic-setup/sources', [...$payload, 'notes' => 'Printed reference held by the family.'])
            ->assertRedirect();

        $this->assertDatabaseHas('academic_sources', ['tenant_id' => $tenant->id, 'source_kind' => 'manual']);
        $this->assertDatabaseMissing('academic_sources', ['tenant_id' => $otherTenant->id]);
    }

    public function test_file_replacement_preserves_prior_version_and_download_is_authorized(): void
    {
        Storage::fake('local');
        [$owner, $tenant] = $this->tenantUser();
        $this->actingIn($owner, $tenant)->post('/academic-setup/sources', [...$this->sourcePayload(), 'source_file' => $this->pdf('v1.pdf')]);
        $source = AcademicSource::query()->firstOrFail();
        $first = $source->currentFile()->firstOrFail();

        $this->actingIn($owner, $tenant)->post("/academic-setup/sources/{$source->id}/files", ['source_file' => $this->pdf('v2.pdf', 'version two')])
            ->assertRedirect();
        $versions = AcademicSourceFile::query()->where('academic_source_id', $source->id)->orderBy('version_number')->get();
        $this->assertCount(2, $versions);
        $this->assertFalse($versions[0]->is_current);
        $this->assertTrue($versions[1]->is_current);
        Storage::disk('local')->assertExists($first->stored_path);

        $this->actingIn($owner, $tenant)->get("/academic-setup/sources/{$source->id}/files/{$first->id}/download")
            ->assertOk()->assertHeader('content-type', 'application/octet-stream');
        $this->assertDatabaseHas('audit_logs', ['action' => 'academic-source.file-downloaded']);
    }

    public function test_source_records_files_and_downloads_are_tenant_isolated(): void
    {
        Storage::fake('local');
        [$ownerA, $tenantA] = $this->tenantUser('owner', 'Tenant A');
        [$ownerB, $tenantB] = $this->tenantUser('owner', 'Tenant B');
        $this->actingIn($ownerA, $tenantA)->post('/academic-setup/sources', [...$this->sourcePayload(), 'source_file' => $this->pdf('private.pdf')]);
        $this->setContext($ownerA, $tenantA);
        $source = AcademicSource::query()->firstOrFail();
        $file = $source->currentFile()->firstOrFail();

        $this->actingIn($ownerB, $tenantB)->get("/academic-setup/sources/{$source->id}")->assertNotFound();
        $this->actingIn($ownerB, $tenantB)->get("/academic-setup/sources/{$source->id}/files/{$file->id}/download")->assertNotFound();
        $this->actingIn($ownerB, $tenantB)->get('/academic-setup/sources')->assertInertia(
            fn (Assert $page) => $page->where('sources.data', []),
        );

        app()->forgetScopedInstances();
        $this->assertSame(0, AcademicSource::query()->count());
        $this->assertSame(0, AcademicSourceFile::query()->count());
    }

    public function test_link_types_are_controlled_duplicates_fail_and_cross_tenant_targets_are_hidden(): void
    {
        [$ownerA, $tenantA] = $this->tenantUser('owner', 'Link Tenant A');
        [$ownerB, $tenantB] = $this->tenantUser('owner', 'Link Tenant B');
        $yearB = $this->schoolYear($ownerB, $tenantB);
        $this->setContext($ownerA, $tenantA);
        $source = AcademicSource::create([...$this->sourcePayload(['source_kind' => 'manual']), 'review_status' => 'unreviewed', 'processing_status' => 'not_requested']);

        $this->actingIn($ownerA, $tenantA)->post("/academic-setup/sources/{$source->id}/links", ['link_type' => 'school_year', 'link_id' => $yearB->id])
            ->assertSessionHasErrors('link_id');
        $this->actingIn($ownerA, $tenantA)->post("/academic-setup/sources/{$source->id}/links", ['link_type' => User::class, 'link_id' => $ownerA->id])
            ->assertSessionHasErrors('link_type');

        $yearA = $this->schoolYear($ownerA, $tenantA);
        $this->actingIn($ownerA, $tenantA)->post("/academic-setup/sources/{$source->id}/links", ['link_type' => 'school_year', 'link_id' => $yearA->id])
            ->assertRedirect();
        $this->actingIn($ownerA, $tenantA)->post("/academic-setup/sources/{$source->id}/links", ['link_type' => 'school_year', 'link_id' => $yearA->id])
            ->assertSessionHasErrors('link_id');
    }

    public function test_roles_follow_source_view_create_manage_review_and_download_matrix(): void
    {
        foreach ([
            'owner' => [200, 302], 'administrator' => [200, 302], 'teacher' => [200, 302],
            'parent' => [200, 403], 'tutor' => [403, 403], 'student' => [403, 403],
        ] as $role => [$createStatus, $reviewStatus]) {
            [$user, $tenant] = $this->tenantUser($role, ucfirst($role).' Tenant');
            $this->setContext($user, $tenant);
            $source = AcademicSource::create([...$this->sourcePayload(['source_kind' => 'manual']), 'review_status' => 'unreviewed', 'processing_status' => 'not_requested']);
            $this->actingIn($user, $tenant)->get('/academic-setup/sources/create')->assertStatus($createStatus);
            $this->actingIn($user, $tenant)->patch("/academic-setup/sources/{$source->id}/review", ['review_status' => 'in_review'])->assertStatus($reviewStatus);
        }
    }

    public function test_reviewed_calendar_source_creates_only_an_empty_linked_draft(): void
    {
        Storage::fake('local');
        [$owner, $tenant] = $this->tenantUser();
        $year = $this->schoolYear($owner, $tenant);
        $this->actingIn($owner, $tenant)->post('/academic-setup/sources', [
            ...$this->sourcePayload(['school_year_id' => $year->id]), 'source_file' => $this->pdf('calendar.pdf'),
        ]);
        $source = AcademicSource::query()->firstOrFail();
        $this->actingIn($owner, $tenant)->patch("/academic-setup/sources/{$source->id}/review", ['review_status' => 'in_review'])->assertRedirect();
        $this->actingIn($owner, $tenant)->patch("/academic-setup/sources/{$source->id}/review", ['review_status' => 'reviewed'])->assertRedirect();
        $this->actingIn($owner, $tenant)->post("/academic-setup/sources/{$source->id}/draft-calendar")->assertRedirect();

        $calendar = DB::table('calendar_profiles')->where('tenant_id', $tenant->id)->first();
        $this->assertNotNull($calendar);
        $this->assertSame('draft', $calendar->status);
        $this->assertDatabaseCount('calendar_events', 0);
        $this->assertDatabaseHas('academic_source_links', ['academic_source_id' => $source->id, 'link_type' => 'calendar_profile', 'link_id' => $calendar->id]);
    }

    public function test_archive_preserves_source_and_excludes_it_from_default_library(): void
    {
        [$owner, $tenant] = $this->tenantUser();
        $this->setContext($owner, $tenant);
        $source = AcademicSource::create([...$this->sourcePayload(['source_kind' => 'manual']), 'review_status' => 'unreviewed', 'processing_status' => 'not_requested']);

        $this->actingIn($owner, $tenant)->patch("/academic-setup/sources/{$source->id}/archive")->assertRedirect('/academic-setup/sources');
        $this->assertDatabaseHas('academic_sources', ['id' => $source->id, 'review_status' => 'archived']);
        $this->actingIn($owner, $tenant)->get('/academic-setup/sources')->assertInertia(fn (Assert $page) => $page->where('sources.data', []));
        $this->actingIn($owner, $tenant)->get('/academic-setup/sources?archived=archived')->assertInertia(fn (Assert $page) => $page->has('sources.data', 1));
    }

    public function test_overview_reports_related_source_counts_without_marking_configuration_complete(): void
    {
        [$owner, $tenant] = $this->tenantUser();
        $year = $this->schoolYear($owner, $tenant);
        $this->setContext($owner, $tenant);
        AcademicSource::create([...$this->sourcePayload(['source_kind' => 'manual', 'school_year_id' => $year->id]), 'review_status' => 'unreviewed', 'processing_status' => 'not_requested']);

        $this->actingIn($owner, $tenant)->get('/academic-setup?school_year_id='.$year->id)->assertInertia(fn (Assert $page) => $page
            ->where('sourceCounts.calendar', 1)
            ->where('checklist.calendar', false));
    }

    private function sourcePayload(array $overrides = []): array
    {
        return array_merge([
            'title' => '2026-2027 Calendar Source', 'description' => 'Source supplied for adult review.',
            'source_kind' => 'upload', 'source_category' => 'calendar', 'authority_level' => 'official_provider',
            'education_provider_id' => null, 'school_year_id' => null, 'grade_level_id' => null,
            'version_label' => '2026', 'academic_year_label' => '2026-2027', 'publication_date' => '2026-07-01',
            'source_url' => null, 'notes' => 'Verify manually.',
        ], $overrides);
    }

    private function pdf(string $name, string $marker = 'version one'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'source-pdf-');
        file_put_contents($path, "%PDF-1.4\n% {$marker}\n1 0 obj\n<<>>\nendobj\n%%EOF");

        return new UploadedFile($path, $name, 'application/pdf', null, true);
    }

    private function fileWithContent(string $name, string $mime, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'source-file-');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, $mime, null, true);
    }

    private function allowedFile(string $extension): UploadedFile
    {
        if ($extension === 'pdf') {
            return $this->pdf('document.pdf');
        }

        $path = tempnam(sys_get_temp_dir(), 'source-allowed-');
        $mime = match ($extension) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'csv' => 'text/csv',
            default => 'text/plain',
        };

        if (in_array($extension, ['png', 'jpg', 'jpeg', 'webp'], true)) {
            $image = imagecreatetruecolor(2, 2);
            match ($extension) {
                'png' => imagepng($image, $path),
                'webp' => imagewebp($image, $path),
                default => imagejpeg($image, $path),
            };
            imagedestroy($image);
        } elseif (in_array($extension, ['docx', 'xlsx'], true)) {
            $zip = new \ZipArchive;
            $zip->open($path, \ZipArchive::OVERWRITE);
            $zip->addFromString('[Content_Types].xml', '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
            $zip->addFromString($extension === 'docx' ? 'word/document.xml' : 'xl/workbook.xml', '<document/>');
            $zip->close();
        } else {
            file_put_contents($path, $extension === 'csv' ? "subject,grade\nMath,5\n" : 'Academic source notes.');
        }

        return new UploadedFile($path, 'document.'.$extension, $mime, null, true);
    }

    private function tenantUser(string $role = 'owner', string $name = 'Source Academy'): array
    {
        $user = User::factory()->create();
        $tenant = Tenant::create(['name' => $name, 'type' => 'homeschool_family', 'timezone' => 'America/Chicago', 'locale' => 'en', 'status' => 'active']);
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => $role, 'status' => 'active']);

        return [$user, $tenant];
    }

    private function schoolYear(User $user, Tenant $tenant): SchoolYear
    {
        $this->setContext($user, $tenant);

        return SchoolYear::create([
            'name' => '2026-2027', 'start_date' => '2026-08-12', 'end_date' => '2027-05-27',
            'timezone' => 'America/Chicago', 'status' => 'active', 'instructional_day_target' => 180,
            'instructional_week_type' => 'five_day', 'instructional_weekdays' => [1, 2, 3, 4, 5],
        ]);
    }

    private function actingIn(User $user, Tenant $tenant): static
    {
        return $this->actingAs($user)->withSession(['active_tenant_id' => $tenant->id]);
    }

    private function setContext(User $user, Tenant $tenant): void
    {
        $membership = TenantMembership::query()->where('tenant_id', $tenant->id)->where('user_id', $user->id)->firstOrFail();
        app(TenantContext::class)->set($tenant, $membership);
    }
}
