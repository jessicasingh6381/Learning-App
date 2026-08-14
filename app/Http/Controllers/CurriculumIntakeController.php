<?php

namespace App\Http\Controllers;

use App\Http\Requests\CurriculumIntakeRequest;
use App\Models\AcademicSource;
use App\Models\CurriculumPackage;
use App\Models\SchoolYear;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\Subject;
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
    public function index(Request $request, CurriculumIntakeService $intake): Response|RedirectResponse
    {
        Gate::authorize('create', AcademicSource::class);

        if ($request->filled(['student_id', 'school_year_id', 'subject_id'])) {
            $context = $intake->buildAdd(
                $request->integer('student_id'),
                $request->integer('school_year_id'),
                $request->integer('subject_id'),
            );

            return redirect()->route('workspace.curriculum-intake.subject.create', [
                'student' => $context['selectedContext']['student_id'],
                'schoolYear' => $context['selectedContext']['school_year_id'],
                'subject' => $context['selectedSubject']['id'],
            ]);
        }

        $data = $intake->build($request->integer('student_id') ?: null, $request->integer('school_year_id') ?: null);

        return Inertia::render('Workspace/CurriculumIntake', [
            ...$data,
            'entryMode' => 'overview',
            'selectedSubject' => null,
            'contextProvider' => null,
            'backUrl' => route('workspace.learning-plan', $data['selectedContext'] ? ['student_id' => $data['selectedContext']['student_id']] : []),
            'maxUploadMegabytes' => (int) config('academic_sources.max_upload_kilobytes') / 1024,
        ]);
    }

    public function create(
        Request $request,
        Student $student,
        SchoolYear $schoolYear,
        Subject $subject,
        CurriculumIntakeService $intake,
    ): Response {
        Gate::authorize('create', AcademicSource::class);
        $data = $intake->buildAdd($student->id, $schoolYear->id, $subject->id);
        $fromOverview = $request->query('from') === 'overview';
        $sourceIntent = $request->query('intent') === 'pacing' ? 'pacing' : null;

        return Inertia::render('Workspace/CurriculumIntake', [
            ...$data,
            'entryMode' => 'add',
            'backUrl' => $fromOverview
                ? route('workspace.curriculum-intake', ['student_id' => $student->id, 'school_year_id' => $schoolYear->id])
                : route('workspace.learning-plan', ['student_id' => $student->id]),
            'returnTo' => $fromOverview ? 'overview' : 'learning-plan',
            'sourceIntent' => $sourceIntent,
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

        $this->persist($data, $enrollment, $upload, $providerId, $audit, $files, $links);

        return redirect()->route('workspace.curriculum-intake', [
            'student_id' => $data['student_id'],
            'school_year_id' => $data['school_year_id'],
        ])->with('success', 'Curriculum source added. Review it before starting a curriculum outline.');
    }

    public function storeSubject(
        CurriculumIntakeRequest $request,
        Student $student,
        SchoolYear $schoolYear,
        Subject $subject,
        CurriculumIntakeService $intake,
        AuditService $audit,
        AcademicSourceFileService $files,
        AcademicSourceLinkService $links,
    ): RedirectResponse {
        $context = $intake->buildAdd($student->id, $schoolYear->id, $subject->id);
        $provider = $context['contextProvider'];
        $data = [
            ...$request->validated(),
            'student_id' => $student->id,
            'school_year_id' => $schoolYear->id,
            'subject_id' => $subject->id,
            'source_origin' => $provider
                ? ($provider['provider_type'] === 'curriculum_publisher' ? 'publisher' : 'provider')
                : 'custom',
            'education_provider_id' => $provider['id'] ?? null,
        ];
        $enrollment = StudentEnrollment::query()
            ->where('student_id', $student->id)
            ->where('school_year_id', $schoolYear->id)
            ->whereIn('status', ['planned', 'active'])
            ->firstOrFail();

        $sourceCategory = $request->query('intent') === 'pacing' ? 'pacing' : 'curriculum';
        $this->persist($data, $enrollment, $request->file('source_file'), $provider['id'] ?? null, $audit, $files, $links, $sourceCategory);

        return redirect()->route('workspace.curriculum-intake', [
            'student_id' => $student->id,
            'school_year_id' => $schoolYear->id,
        ])->with('success', 'Curriculum source added. Review it before starting a curriculum outline.');
    }

    private function persist(
        array $data,
        StudentEnrollment $enrollment,
        mixed $upload,
        ?int $providerId,
        AuditService $audit,
        AcademicSourceFileService $files,
        AcademicSourceLinkService $links,
        string $sourceCategory = 'curriculum',
    ): void {
        DB::transaction(function () use ($data, $enrollment, $upload, $providerId, $audit, $files, $links, $sourceCategory): void {
            $source = AcademicSource::create([
                'education_provider_id' => $providerId,
                'school_year_id' => $enrollment->school_year_id,
                'grade_level_id' => $enrollment->grade_level_id,
                'title' => $data['title'],
                'description' => $data['source_kind'] === 'manual' ? $data['manual_reference'] : null,
                'source_kind' => $data['source_kind'],
                'source_category' => $sourceCategory,
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
    }

    public function createDraft(
        AcademicSource $source,
        AcademicSourceLinkService $links,
        AuditService $audit,
    ): RedirectResponse {
        Gate::authorize('curriculum.manage');
        Gate::authorize('update', $source);

        if (! in_array($source->source_category, ['curriculum', 'pacing', 'scope_and_sequence'], true) || $source->review_status !== 'reviewed') {
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
