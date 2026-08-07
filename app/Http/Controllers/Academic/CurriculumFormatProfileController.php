<?php

namespace App\Http\Controllers\Academic;

use App\Http\Controllers\Controller;
use App\Models\AcademicSource;
use App\Models\AcademicSourceFile;
use App\Models\CurriculumFormatProfile;
use App\Services\AuditService;
use App\Services\CurriculumDocumentStructureDetector;
use App\Services\CurriculumParserCapabilityService;
use App\Services\CurriculumSourcePdfExtractor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CurriculumFormatProfileController extends Controller
{
    public function create(AcademicSource $source, CurriculumSourcePdfExtractor $extractor, CurriculumDocumentStructureDetector $detector): Response
    {
        $this->authorizeSetup($source);
        $source->loadMissing(['currentFile', 'educationProvider', 'gradeLevel', 'schoolYear', 'links']);
        $this->assertSetupSource($source);
        if ($profile = CurriculumFormatProfile::query()->where('example_academic_source_file_id', $source->currentFile->id)->first()) {
            return $this->render($profile, $source, $profile->detected_structure);
        }
        return $this->render(null, $source, $detector->detect($extractor->extract($source->currentFile), $source));
    }

    public function store(AcademicSource $source, CurriculumSourcePdfExtractor $extractor, CurriculumDocumentStructureDetector $detector, AuditService $audit): RedirectResponse
    {
        $this->authorizeSetup($source);
        $source->loadMissing(['currentFile', 'educationProvider', 'gradeLevel', 'schoolYear', 'links']);
        $this->assertSetupSource($source);
        $detected = $detector->detect($extractor->extract($source->currentFile), $source);
        $subjectId = $source->links->firstWhere('link_type', 'subject')?->link_id;
        $profile = DB::transaction(function () use ($source, $subjectId, $detected, $detector, $audit) {
            AcademicSource::query()->whereKey($source->id)->lockForUpdate()->firstOrFail();
            AcademicSourceFile::query()->whereKey($source->currentFile->id)->lockForUpdate()->firstOrFail();
            $existing = CurriculumFormatProfile::query()->where('example_academic_source_file_id', $source->currentFile->id)->lockForUpdate()->first();
            if ($existing) return $existing;
            $confirmedHeadings = collect($detected['headings'])->take(4)->values()->all();
            $created = CurriculumFormatProfile::create([
                'ownership_scope' => 'tenant', 'education_provider_id' => $source->education_provider_id, 'subject_id' => $subjectId,
                'minimum_grade_level_id' => $source->grade_level_id, 'maximum_grade_level_id' => $source->grade_level_id,
                'example_academic_source_id' => $source->id, 'example_academic_source_file_id' => $source->currentFile->id,
                'name' => ($detected['title'] ?: $source->title).' format', 'document_family' => 'Unclassified curriculum document',
                'file_type' => $source->currentFile->mime_type, 'recognition_fingerprints' => $detector->fingerprints($detected, $confirmedHeadings),
                'mapping_rules' => ['strategy' => $detected['suggested_strategy'], 'confirmed_period_headings' => $confirmedHeadings, 'confirmed_unit_rows' => [], 'confirmed_assessment_rows' => []],
                'detected_structure' => $detected, 'profile_version' => 1, 'status' => 'draft', 'created_by_user_id' => auth()->id(),
            ]);
            $audit->record('curriculum-format-profile.created', $created, [], $created->toArray());
            return $created;
        });
        return redirect()->route('academic.curriculum-format-profiles.show', $profile)->with('success', 'Document format setup started. No curriculum import was created.');
    }

    public function show(CurriculumFormatProfile $profile): Response
    {
        $profile->loadMissing(['source.currentFile', 'source.educationProvider', 'source.gradeLevel', 'source.schoolYear', 'source.links']);
        $this->authorizeSetup($profile->source);
        return $this->render($profile, $profile->source, $profile->detected_structure);
    }

    public function update(Request $request, CurriculumFormatProfile $profile, CurriculumDocumentStructureDetector $detector, AuditService $audit): RedirectResponse
    {
        $profile->loadMissing('source.currentFile'); $this->authorizeSetup($profile->source);
        if ($profile->status !== 'draft') throw ValidationException::withMessages(['profile' => 'Only a draft format profile can be edited.']);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'], 'document_family' => ['required', 'string', 'max:100'],
            'strategy' => ['required', Rule::in(['positioned_date_unit_table', 'confirmed_heading_rows'])],
            'confirmed_period_headings' => ['required', 'array', 'min:1'], 'confirmed_period_headings.*' => ['string', 'max:500'],
            'confirmed_unit_rows' => ['required', 'array', 'min:1'], 'confirmed_unit_rows.*' => ['string', 'max:500'],
            'confirmed_assessment_rows' => ['array'], 'confirmed_assessment_rows.*' => ['string', 'max:500'],
        ]);
        $detected = $profile->detected_structure;
        foreach (['confirmed_period_headings' => 'headings', 'confirmed_unit_rows' => 'unit_rows', 'confirmed_assessment_rows' => 'assessment_rows'] as $field => $candidateField) {
            if (array_diff($data[$field] ?? [], $detected[$candidateField] ?? [])) throw ValidationException::withMessages([$field => 'Selections must come from the detected private PDF structure.']);
        }
        $before = $profile->toArray();
        $profile->update([
            'name' => trim($data['name']), 'document_family' => trim($data['document_family']),
            'recognition_fingerprints' => $detector->fingerprints($detected, $data['confirmed_period_headings']),
            'mapping_rules' => collect($data)->only(['strategy', 'confirmed_period_headings', 'confirmed_unit_rows', 'confirmed_assessment_rows'])->all(),
        ]);
        $audit->record('curriculum-format-profile.updated', $profile, $before, $profile->fresh()->toArray());
        return back()->with('success', 'Draft format mapping saved. Review it before activation.');
    }

    public function activate(CurriculumFormatProfile $profile, AuditService $audit): RedirectResponse
    {
        $profile->loadMissing('source.currentFile'); $this->authorizeSetup($profile->source);
        DB::transaction(function () use ($profile, $audit): void {
            $locked = CurriculumFormatProfile::query()->whereKey($profile->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'active') return;
            if ($locked->status !== 'draft' || empty($locked->mapping_rules['confirmed_period_headings']) || empty($locked->mapping_rules['confirmed_unit_rows'])) {
                throw ValidationException::withMessages(['profile' => 'Save at least one confirmed reporting period and unit row before activation.']);
            }
            $source = $locked->source()->lockForUpdate()->firstOrFail();
            $file = AcademicSourceFile::query()->whereKey($locked->example_academic_source_file_id)->lockForUpdate()->firstOrFail();
            if ($source->archived_at || $source->review_status !== 'reviewed' || ! $source->currentFile || $source->currentFile->id !== $file->id) {
                throw ValidationException::withMessages(['profile' => 'The reviewed source and current PDF must remain unchanged before activation.']);
            }
            $before = $locked->toArray();
            $locked->update(['status' => 'active', 'reviewed_by_user_id' => auth()->id(), 'activated_at' => now()]);
            $audit->record('curriculum-format-profile.activated', $locked, $before, $locked->fresh()->toArray());
        });
        app(CurriculumParserCapabilityService::class)->assess($profile->source, true);
        return redirect()->route('academic.sources.show', $profile->example_academic_source_id)->with('success', 'Document format activated and outline support reassessed. No curriculum import was created.');
    }

    private function render(?CurriculumFormatProfile $profile, AcademicSource $source, array $detected): Response
    {
        return Inertia::render('Academic/CurriculumFormats/Show', [
            'profile' => $profile ? $profile->only(['id', 'name', 'document_family', 'status', 'profile_version', 'mapping_rules']) : null,
            'source' => ['id' => $source->id, 'title' => $source->title, 'provider' => $source->educationProvider?->name, 'grade' => $source->gradeLevel?->name, 'school_year' => $source->schoolYear?->name, 'file_name' => $source->currentFile?->original_filename],
            'detected' => $detected, 'canManage' => Gate::allows('curriculum.manage') && Gate::allows('update', $source),
        ]);
    }

    private function authorizeSetup(AcademicSource $source): void { Gate::authorize('curriculum.manage'); Gate::authorize('update', $source); }
    private function assertSetupSource(AcademicSource $source): void
    {
        if ($source->archived_at || $source->review_status !== 'reviewed' || ! in_array($source->source_category, ['curriculum', 'pacing', 'scope_and_sequence'], true)
            || ! $source->currentFile || $source->currentFile->mime_type !== 'application/pdf' || $source->currentFile->extension !== 'pdf') {
            throw ValidationException::withMessages(['source' => 'Use a reviewed, active curriculum PDF for document-format setup.']);
        }
    }
}
