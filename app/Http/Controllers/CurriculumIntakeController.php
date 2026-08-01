<?php

namespace App\Http\Controllers;

use App\Http\Requests\CurriculumIntakeRequest;
use App\Models\AcademicSource;
use App\Models\CurriculumPackage;
use App\Models\StudentEnrollment;
use App\Services\AcademicSourceFileService;
use App\Services\AcademicSourceLinkService;
use App\Services\AuditService;
use App\Services\CurriculumIntakeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CurriculumIntakeController extends Controller
{
    public function index(Request $request, CurriculumIntakeService $intake): Response
    {
        Gate::authorize('create', AcademicSource::class);

        $data = $intake->build($request->integer('student_id') ?: null, $request->integer('school_year_id') ?: null);

        return Inertia::render('Workspace/CurriculumIntake', [
            ...$data,
            'selectedSubjectId' => collect($data['subjects'])->contains('id', $request->integer('subject_id'))
                ? $request->integer('subject_id')
                : null,
            'maxUploadMegabytes' => (int) config('academic_sources.max_upload_kilobytes') / 1024,
        ]);
    }

    public function store(
        CurriculumIntakeRequest $request,
        AuditService $audit,
        AcademicSourceFileService $files,
        AcademicSourceLinkService $links,
    ): RedirectResponse {
        $data = $request->validated();
        $enrollment = StudentEnrollment::query()
            ->where('student_id', $data['student_id'])
            ->where('school_year_id', $data['school_year_id'])
            ->whereIn('status', ['planned', 'active'])
            ->firstOrFail();
        $upload = $request->file('source_file');
        $providerId = in_array($data['source_origin'], ['provider', 'publisher'], true) ? $data['education_provider_id'] : null;

        DB::transaction(function () use ($data, $enrollment, $upload, $providerId, $audit, $files, $links): void {
            $source = AcademicSource::create([
                'education_provider_id' => $providerId,
                'school_year_id' => $enrollment->school_year_id,
                'grade_level_id' => $enrollment->grade_level_id,
                'title' => $data['title'],
                'description' => $data['source_kind'] === 'manual' ? $data['manual_reference'] : null,
                'source_kind' => $data['source_kind'],
                'source_category' => 'curriculum',
                'authority_level' => match ($data['source_origin']) {
                    'provider', 'publisher' => 'official_provider',
                    'custom' => 'tenant_created',
                    default => 'third_party',
                },
                'review_status' => 'unreviewed',
                'processing_status' => 'not_requested',
                'source_url' => $data['source_url'] ?? null,
                'retrieved_at' => $data['source_kind'] === 'url' ? now() : null,
                'version_label' => $data['version_label'] ?? null,
                'academic_year_label' => $enrollment->schoolYear->name,
            ]);
            $audit->record('academic-source.created', $source, [], $source->toArray());

            foreach (['education_provider' => $providerId, 'school_year' => $enrollment->school_year_id, 'grade_level' => $enrollment->grade_level_id, 'subject' => $data['subject_id']] as $type => $id) {
                if ($id) {
                    $links->add($source, $type, (int) $id, $audit);
                }
            }

            if ($upload) {
                $files->store($source, $upload, $audit);
            }
        });

        return redirect()->route('workspace.curriculum-intake', [
            'student_id' => $data['student_id'],
            'school_year_id' => $data['school_year_id'],
            'subject_id' => $data['subject_id'],
        ])->with('success', 'Curriculum source added. Review it before starting a curriculum outline.');
    }

    public function createDraft(
        AcademicSource $source,
        AcademicSourceLinkService $links,
        AuditService $audit,
    ): RedirectResponse {
        Gate::authorize('curriculum.manage');
        Gate::authorize('update', $source);

        if ($source->source_category !== 'curriculum' || $source->review_status !== 'reviewed') {
            throw ValidationException::withMessages(['review_status' => 'Mark this curriculum source reviewed before creating a draft outline.']);
        }

        $existingId = $source->links()->where('link_type', 'curriculum_package')->value('link_id');
        if ($existingId && $existing = CurriculumPackage::query()->find($existingId)) {
            return redirect()->route('academic.curriculum.show', $existing)->with('success', 'The existing draft curriculum outline is ready to open.');
        }

        $package = DB::transaction(function () use ($source, $links, $audit): CurriculumPackage {
            $package = CurriculumPackage::create([
                'education_provider_id' => $source->education_provider_id,
                'standards_framework_id' => null,
                'name' => $source->title,
                'version_label' => $source->version_label ?: ($source->academic_year_label ?: 'Draft 1'),
                'description' => null,
                'status' => 'draft',
                'source_url' => $source->source_kind === 'url' ? $source->source_url : null,
            ]);
            $audit->record('curriculum-package.created', $package, [], $package->toArray());
            $links->add($source, 'curriculum_package', $package->id, $audit);
            $audit->record('academic-source.structured-draft-created', $source);

            return $package;
        });

        return redirect()->route('academic.curriculum.show', $package)->with('success', 'Draft curriculum outline created. No courses, units, or lessons were generated.');
    }
}
