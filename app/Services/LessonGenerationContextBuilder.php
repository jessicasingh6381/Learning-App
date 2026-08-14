<?php

namespace App\Services;

use App\Data\LessonGenerationContext;
use App\Models\CurriculumUnit;
use App\Models\LessonPlan;
use App\Tenancy\TenantContext;

class LessonGenerationContextBuilder
{
    public function build(LessonPlan $plan, CurriculumUnit $unit): LessonGenerationContext
    {
        $plan->loadMissing([
            'enrollment.student', 'enrollment.schoolYear', 'enrollment.gradeLevel',
            'curriculumImport.source.currentFile', 'curriculumImport.curriculumPackage',
            'packageCourse.course.subject',
        ]);
        $unit->loadMissing(['allComponents', 'standardAlignments.standard']);
        $components = $unit->allComponents->map(fn ($component) => [
            'id' => $component->id, 'parent_id' => $component->parent_component_id,
            'type' => $component->component_type, 'name' => $component->name,
            'description' => $component->description, 'sequence' => $component->sequence,
            'source_page' => $component->source_page, 'source_note' => $component->source_note,
            'metadata' => $component->metadata ?? [],
        ])->values();
        $byTypes = fn (array $types) => $components->whereIn('type', $types)->values()->all();

        return new LessonGenerationContext(
            tenant: ['id' => $plan->tenant_id, 'name' => app(TenantContext::class)->tenant()->name, 'instruction_context' => 'homeschool'],
            student: [
                'id' => $plan->enrollment->student->id,
                'display_name' => $plan->enrollment->student->display_name,
                'preferred_name' => $plan->enrollment->student->preferred_name,
            ],
            enrollment: ['id' => $plan->enrollment->id, 'status' => $plan->enrollment->status],
            schoolYear: ['id' => $plan->enrollment->schoolYear->id, 'name' => $plan->enrollment->schoolYear->name],
            grade: [
                'id' => $plan->enrollment->gradeLevel->id,
                'name' => $plan->enrollment->gradeLevel->name,
                'code' => $plan->enrollment->gradeLevel->code,
            ],
            subject: [
                'id' => $plan->packageCourse->course->subject->id,
                'name' => $plan->packageCourse->course->subject->name,
                'code' => $plan->packageCourse->course->subject->code,
            ],
            course: [
                'id' => $plan->packageCourse->course->id,
                'name' => $plan->packageCourse->course->name,
                'code' => $plan->packageCourse->course->code,
            ],
            curriculum: [
                'approved_import_id' => $plan->curriculum_import_id,
                'package_course_id' => $plan->curriculum_package_course_id,
                'package' => $plan->curriculumImport->curriculumPackage?->name,
                'source' => $plan->curriculumImport->source->title,
                'source_file' => $plan->curriculumImport->source->currentFile?->original_filename,
                'source_version' => $plan->curriculumImport->source->version_label,
                'approved_at' => $plan->curriculumImport->approved_at?->toIso8601String(),
            ],
            unit: [
                'id' => $unit->id, 'sequence' => $unit->sequence, 'name' => $unit->name,
                'summary' => $unit->summary, 'unit_type' => $unit->unit_type,
                'estimated_instructional_days' => $unit->estimated_days,
                'duration_text' => $unit->metadata['duration_text'] ?? null,
                'source_page' => $unit->source_page, 'source_note' => $unit->source_note,
            ],
            components: $components->all(),
            objectives: $byTypes(['objective', 'learning_objective', 'outcome']),
            skills: $byTypes(['skill', 'foundational_skill', 'revising', 'conventions']),
            assessments: $byTypes(['assessment', 'assessment_support', 'check_for_understanding']),
            projectMilestones: $byTypes(['project', 'project_milestone']),
            standardAlignments: $unit->standardAlignments->map(fn ($alignment) => [
                'alignment_id' => $alignment->id, 'code' => $alignment->standard_code,
                'title' => $alignment->standard?->title, 'statement' => $alignment->standard?->statement,
                'strand' => $alignment->standard?->strand, 'source_page' => $alignment->source_page,
            ])->values()->all(),
        );
    }
}
