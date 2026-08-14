<?php

namespace App\Http\Controllers\Academic;

use App\Http\Controllers\Controller;
use App\Models\AcademicSource;
use App\Models\CurriculumImport;
use App\Models\CurriculumImportProposal;
use App\Models\StudentEnrollment;
use App\Services\CurriculumIntakeService;
use App\Services\StandardsDocumentMetadataNormalizer;
use App\Services\StandardsImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;

class StandardsImportController extends Controller
{
    public function store(AcademicSource $source, StandardsImportService $service): RedirectResponse
    {
        Gate::authorize('curriculum.manage'); Gate::authorize('update', $source);
        $import = $service->start($source);
        return redirect()->route('academic.standards-imports.show', $import)
            ->with('success', $import->status === 'review' ? 'Grade-specific standards extracted. Review every item before approval.' : 'Existing standards import opened.');
    }

    public function show(
        Request $request,
        CurriculumImport $curriculumImport,
        StandardsDocumentMetadataNormalizer $metadataNormalizer,
        CurriculumIntakeService $curriculumIntake,
    ): Response
    {
        abort_unless($curriculumImport->import_type === 'standards', 404);
        $curriculumImport->load(['source', 'sourceFile', 'subject', 'gradeLevel', 'schoolYear', 'standardsFramework', 'proposals', 'approvedBy:id,name']);
        Gate::authorize('view', $curriculumImport->source); Gate::authorize('curriculum.view');
        $proposals = $curriculumImport->proposals;
        $strands = $proposals->where('proposal_type', 'strand')->sortBy('sequence')->map(fn ($strand) => [
            ...$this->proposal($strand),
            'children' => $proposals->where('parent_proposal_id', $strand->id)->sortBy('sequence')->map(fn ($standard) => [
                ...$this->proposal($standard),
                'children' => $proposals->where('parent_proposal_id', $standard->id)->sortBy('sequence')->map(fn ($expectation) => $this->proposal($expectation))->values(),
            ])->values(),
        ])->values();
        $documentMetadata = $metadataNormalizer->normalize($curriculumImport->document_metadata ?? [], $curriculumImport->adopted_label);
        $documentMetadata['implementation_label'] = $documentMetadata['effective_label'];
        $documentMetadata['update_label'] = $documentMetadata['version_label'];
        $nextStep = null;
        if ($curriculumImport->status === 'approved' && Gate::allows('create', AcademicSource::class)) {
            $enrollmentQuery = StudentEnrollment::query()
                ->where('school_year_id', $curriculumImport->school_year_id)
                ->where('grade_level_id', $curriculumImport->grade_level_id)
                ->whereIn('status', ['planned', 'active'])
                ->whereHas('student', fn ($query) => $query->where('status', 'active'));
            if ($request->filled('student_id')) {
                $enrollmentQuery->where('student_id', $request->integer('student_id'));
            }
            $enrollment = $enrollmentQuery->orderByRaw("case when status = 'active' then 0 else 1 end")->orderBy('id')->first();
            if ($enrollment) {
                $subject = collect($curriculumIntake->build($enrollment->student_id, $enrollment->school_year_id)['subjects'])
                    ->firstWhere('id', $curriculumImport->subject_id);
                if (($subject['workflow_state'] ?? null) === 'standards_imported_pacing_needed') {
                    $nextStep = ['label' => $subject['primary_action_label'], 'url' => $subject['primary_action_url']];
                }
            }
        }
        return Inertia::render('Academic/StandardsImports/Show', [
            'standardsImport' => [
                ...$curriculumImport->only(['id', 'status', 'parser_key', 'parser_version', 'extraction_method', 'diagnostic', 'document_section', 'adopted_label', 'introduction_text', 'review_version']),
                'document_metadata' => $documentMetadata,
                'approved_at' => $curriculumImport->approved_at?->toIso8601String(), 'approved_by' => $curriculumImport->approvedBy?->name,
                'included_count' => $proposals->where('included', true)->count(), 'excluded_count' => $proposals->where('included', false)->count(),
            ],
            'source' => ['id' => $curriculumImport->source->id, 'title' => $curriculumImport->source->title,
                'file' => ['id' => $curriculumImport->sourceFile->id, 'name' => $curriculumImport->sourceFile->original_filename]],
            'context' => ['subject' => $curriculumImport->subject->name, 'grade' => $curriculumImport->gradeLevel?->name,
                'school_year' => $curriculumImport->schoolYear?->name, 'framework' => $curriculumImport->standardsFramework->name],
            'strands' => $strands,
            'canManage' => $curriculumImport->status === 'review' && Gate::allows('curriculum.manage') && Gate::allows('update', $curriculumImport->source),
            'nextStep' => $nextStep,
        ]);
    }

    public function bulkUpdate(Request $request, CurriculumImport $curriculumImport, StandardsImportService $service): RedirectResponse
    {
        $this->authorizeManage($curriculumImport);
        $validator = Validator::make($request->all(), [
            'proposals' => ['required', 'array', 'min:1'], 'proposals.*.id' => ['required', 'integer', 'distinct'],
            'proposals.*.included' => ['required', 'boolean'], 'proposals.*.sequence' => ['required', 'integer', 'between:1,65535'],
            'proposals.*.standard_code' => ['present', 'nullable', 'string', 'max:100'],
            'proposals.*.statement' => ['required', 'string', 'max:20000'],
        ]);
        $validator->after(function ($validator) use ($request): void {
            foreach ($request->input('proposals', []) as $key => $row) if (is_array($row) && (string) $key !== (string) ($row['id'] ?? '')) {
                $validator->errors()->add("proposals.{$key}.id", 'The proposal ID does not match its review row.');
            }
        });
        $service->bulkUpdate($curriculumImport, $validator->validate()['proposals']);
        return back()->with('success', 'Standards review changes saved.');
    }

    public function approve(Request $request, CurriculumImport $curriculumImport, StandardsImportService $service): RedirectResponse
    {
        $this->authorizeManage($curriculumImport);
        $data = $request->validate(['review_version' => ['required', 'integer', 'min:0']]);
        $service->approve($curriculumImport, $data['review_version']);
        return back()->with('success', 'Standards imported as reusable references. Pacing guide still needed.');
    }

    private function authorizeManage(CurriculumImport $import): void
    {
        abort_unless($import->import_type === 'standards', 404); $import->loadMissing('source');
        Gate::authorize('curriculum.manage'); Gate::authorize('update', $import->source);
    }
    private function proposal(CurriculumImportProposal $proposal): array
    {
        return $proposal->only(['id', 'parent_proposal_id', 'proposal_type', 'included', 'sequence', 'name', 'strand',
            'standard_code', 'normalized_code', 'statement', 'source_page', 'raw_text', 'parser_note', 'confidence', 'manually_edited']);
    }
}
