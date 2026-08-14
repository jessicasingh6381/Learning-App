<?php

namespace App\Http\Controllers\Academic;

use App\Http\Controllers\Controller;
use App\Models\AcademicSource;
use App\Models\CurriculumImport;
use App\Models\CurriculumImportProposal;
use App\Models\CurriculumPackageCourse;
use App\Services\CurriculumImportService;
use App\Services\CurriculumImportSetupService;
use App\Services\CurriculumParserCapabilityService;
use App\Services\CfisdGrade5ElarParentYearAtGlanceParser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CurriculumImportController extends Controller
{
    public function assessCapability(AcademicSource $source, CurriculumParserCapabilityService $capabilities): RedirectResponse
    {
        Gate::authorize('view', $source);
        abort_unless(Gate::allows('update', $source) || Gate::allows('review', $source), 403);
        $capability = $capabilities->assess($source);

        return back()->with('success', match ($capability->state) {
            'supported' => 'Outline extraction is supported for this document.',
            'unsupported' => 'This PDF is saved, but outline extraction is not available for its format yet.',
            'ambiguous' => 'This PDF needs format review before outline extraction.',
            default => $capability->userMessage,
        });
    }

    public function store(
        Request $request,
        AcademicSource $source,
        CurriculumImportSetupService $setup,
    ): RedirectResponse
    {
        Gate::authorize('curriculum.manage');
        Gate::authorize('update', $source);
        $expectedMappingId = null;
        if ($request->filled('curriculum_package_course_id')) {
            $data = $request->validate(['curriculum_package_course_id' => ['integer']]);
            $expectedMappingId = CurriculumPackageCourse::query()
                ->findOrFail($data['curriculum_package_course_id'])->id;
        }
        $import = $setup->start($source, $expectedMappingId);

        return redirect()->route('academic.curriculum-imports.show', $import)
            ->with('success', $import->status === 'review' ? 'Curriculum outline extracted. Review every proposal before approval.' : 'Existing curriculum import opened.');
    }

    public function show(CurriculumImport $curriculumImport): Response
    {
        $curriculumImport->load([
            'source', 'sourceFile', 'subject', 'gradeLevel', 'schoolYear', 'standardsFramework',
            'packageCourse.curriculumPackage', 'packageCourse.course', 'proposals', 'approvedBy:id,name',
        ]);
        Gate::authorize('view', $curriculumImport->source);
        Gate::authorize('curriculum.view');
        $proposals = $curriculumImport->proposals;
        $periods = $proposals->where('proposal_type', 'period')->sortBy('sequence');
        $outlineGroups = $periods->isNotEmpty()
            ? $periods->map(fn (CurriculumImportProposal $period) => [
                ...$this->proposal($period),
                'children' => $proposals->where('parent_proposal_id', $period->id)->sortBy('sequence')
                    ->map(fn (CurriculumImportProposal $proposal) => $this->proposalTree($proposal, $proposals))->values(),
            ])->values()
            : collect([[
                'id' => 'course-outline', 'proposal_type' => 'course', 'name' => 'Course outline',
                'children' => $proposals->whereNull('parent_proposal_id')->whereIn('proposal_type', ['unit', 'assessment'])->sortBy('sequence')
                    ->map(fn (CurriculumImportProposal $proposal) => $this->proposalTree($proposal, $proposals))->values(),
            ]]);

        return Inertia::render('Academic/CurriculumImports/Show', [
            'curriculumImport' => [
                'id' => $curriculumImport->id, 'status' => $curriculumImport->status,
                'parser_key' => $curriculumImport->parser_key, 'parser_version' => $curriculumImport->parser_version,
                'extraction_method' => $curriculumImport->extraction_method,
                'diagnostic' => $curriculumImport->diagnostic, 'source_title' => $curriculumImport->source_title,
                'source_revision_date' => $curriculumImport->source_revision_date?->format('Y-m-d'),
                'review_version' => $curriculumImport->review_version,
                'approved_at' => $curriculumImport->approved_at?->toIso8601String(),
                'approved_by' => $curriculumImport->approvedBy?->name,
                'included_count' => $proposals->where('included', true)->count(),
                'excluded_count' => $proposals->where('included', false)->count(),
            ],
            'source' => [
                'id' => $curriculumImport->source->id, 'title' => $curriculumImport->source->title,
                'file' => ['id' => $curriculumImport->sourceFile->id, 'name' => $curriculumImport->sourceFile->original_filename],
            ],
            'context' => [
                'subject' => $curriculumImport->subject->name,
                'grade' => $curriculumImport->gradeLevel?->name,
                'school_year' => $curriculumImport->schoolYear?->name,
                'framework' => $curriculumImport->standardsFramework->name,
                'package' => $curriculumImport->packageCourse->curriculumPackage->name,
                'course' => $curriculumImport->packageCourse->course->name,
            ],
            'periods' => $outlineGroups,
            'unitTypes' => CurriculumImportService::UNIT_TYPES,
            'componentTypes' => CurriculumImportService::COMPONENT_TYPES,
            'canManage' => $curriculumImport->status === 'review'
                && Gate::allows('curriculum.manage') && Gate::allows('update', $curriculumImport->source),
            'canReextract' => $curriculumImport->status === 'review'
                && $curriculumImport->parser_key === CfisdGrade5ElarParentYearAtGlanceParser::KEY
                && Gate::allows('curriculum.manage') && Gate::allows('update', $curriculumImport->source),
        ]);
    }

    public function reextract(CurriculumImport $curriculumImport, CurriculumImportService $service): RedirectResponse
    {
        $this->authorizeManage($curriculumImport);
        $service->reextract($curriculumImport);
        return back()->with('success', 'Outline re-extracted from the same source PDF. The prior proposal generation was retained as superseded history.');
    }

    public function bulkUpdate(Request $request, CurriculumImport $curriculumImport, CurriculumImportService $service): RedirectResponse
    {
        $this->authorizeManage($curriculumImport);
        $validator = Validator::make($request->all(), [
            'proposals' => ['required', 'array', 'min:1'],
            'proposals.*.id' => ['required', 'integer', 'distinct'],
            'proposals.*.parent_proposal_id' => ['nullable', 'integer'],
            'proposals.*.included' => ['required', 'boolean'],
            'proposals.*.sequence' => ['required', 'integer', 'between:1,65535'],
            'proposals.*.name' => ['required', 'string', 'max:255'],
            'proposals.*.description' => ['nullable', 'string', 'max:10000'],
            'proposals.*.summary' => ['nullable', 'string', 'max:2000'],
            'proposals.*.planned_start_date' => ['present', 'nullable', 'date_format:Y-m-d'],
            'proposals.*.planned_end_date' => ['present', 'nullable', 'date_format:Y-m-d'],
            'proposals.*.estimated_days' => ['present', 'nullable', 'integer', 'between:1,366'],
            'proposals.*.unit_type' => ['nullable', Rule::in(CurriculumImportService::UNIT_TYPES)],
            'proposals.*.component_type' => ['nullable', Rule::in(CurriculumImportService::COMPONENT_TYPES)],
            'proposals.*.standard_codes' => ['present', 'array'],
            'proposals.*.standard_codes.*' => ['string', 'max:100'],
        ]);
        $validator->after(function ($validator) use ($request): void {
            foreach ($request->input('proposals', []) as $key => $row) {
                if (! is_array($row)) continue;
                if ((string) $key !== (string) ($row['id'] ?? '')) {
                    $validator->errors()->add("proposals.{$key}.id", 'The proposal ID does not match its review row.');
                }
                if (! empty($row['planned_start_date']) && ! empty($row['planned_end_date'])
                    && $row['planned_end_date'] < $row['planned_start_date']) {
                    $validator->errors()->add("proposals.{$key}.planned_end_date", 'The end date must be on or after the start date.');
                }
            }
        });
        $service->bulkUpdate($curriculumImport, $validator->validate()['proposals']);

        return back()->with('success', 'Curriculum review changes saved.');
    }

    public function approve(Request $request, CurriculumImport $curriculumImport, CurriculumImportService $service): RedirectResponse
    {
        $this->authorizeManage($curriculumImport);
        $data = $request->validate(['review_version' => ['required', 'integer', 'min:0']]);
        $service->approve($curriculumImport, $data['review_version']);

        return back()->with('success', 'Curriculum import approved into the draft outline.');
    }

    private function authorizeManage(CurriculumImport $import): void
    {
        $import->loadMissing('source');
        Gate::authorize('curriculum.manage');
        Gate::authorize('update', $import->source);
    }

    private function proposal(CurriculumImportProposal $proposal): array
    {
        return [
            ...$proposal->only([
                'id', 'parent_proposal_id', 'proposal_type', 'included', 'sequence', 'name', 'description', 'summary',
                'estimated_days', 'unit_type', 'component_type', 'reporting_period', 'standard_codes', 'source_page',
                'raw_text', 'parser_note', 'parser_metadata', 'confidence', 'manually_edited',
            ]),
            'planned_start_date' => $proposal->planned_start_date?->format('Y-m-d'),
            'planned_end_date' => $proposal->planned_end_date?->format('Y-m-d'),
            'warnings' => collect([
                ($proposal->confidence ?? 1) < .8 ? 'Low-confidence extraction' : null,
                str_contains(mb_strtolower($proposal->parser_note ?? ''), 'flagged for review') ? $proposal->parser_note : null,
            ])->filter()->values()->all(),
        ];
    }

    private function proposalTree(CurriculumImportProposal $proposal, $proposals): array
    {
        return [
            ...$this->proposal($proposal),
            'children' => $proposals->where('parent_proposal_id', $proposal->id)->sortBy('sequence')
                ->map(fn (CurriculumImportProposal $child) => $this->proposalTree($child, $proposals))->values(),
        ];
    }
}
