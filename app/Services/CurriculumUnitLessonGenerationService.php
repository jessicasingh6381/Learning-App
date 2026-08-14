<?php

namespace App\Services;

use App\Contracts\LessonGenerator;
use App\Exceptions\LessonGenerationException;
use App\Models\CurriculumUnit;
use App\Models\LessonPlan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class CurriculumUnitLessonGenerationService
{
    public function __construct(
        private readonly LessonGenerator $generator,
        private readonly LessonGenerationContextBuilder $contextBuilder,
        private readonly LessonGenerationOutputValidator $validator,
        private readonly LessonPlanService $lessonPlans,
        private readonly AuditService $audit,
        private readonly LessonResourceFulfillmentManager $resourceFulfillment,
    ) {}

    public function generate(LessonPlan $plan, CurriculumUnit $unit): LessonPlan
    {
        $this->lessonPlans->assertGenerationContext($plan, $unit);
        if (! in_array($plan->status, ['draft', 'failed'], true)) {
            throw ValidationException::withMessages(['generation' => 'Only draft or failed lesson plans can generate a unit.']);
        }
        if ($plan->lessons()->where('curriculum_unit_id', $unit->id)->exists()) {
            throw ValidationException::withMessages(['generation' => 'This unit already has lessons. Review the existing draft instead of generating duplicates.']);
        }

        $generating = $this->lessonPlans->beginGeneration(
            $plan->enrollment()->firstOrFail(),
            $plan->curriculumImport()->firstOrFail(),
            $this->generator->key(),
            $this->generator->version(),
            ['curriculum_unit_id' => $unit->id, 'lesson_mode' => 'full'],
        );
        if ($generating->id !== $plan->id) {
            throw ValidationException::withMessages(['generation' => 'The lesson-plan revision changed. Reload before generating.']);
        }

        try {
            $context = $this->contextBuilder->build($generating, $unit);
            $generated = $this->validator->validate($this->generator->generate($context), $context);
            $componentTypes = collect($context->components)->mapWithKeys(fn ($component) => [
                (int) $component['id'] => $component['type'],
            ]);

            $completed = DB::transaction(function () use ($generating, $unit, $generated, $componentTypes): LessonPlan {
                $nextSequence = (int) $generating->lessons()->max('sequence');
                foreach ($generated as $generatedLesson) {
                    $lesson = $this->lessonPlans->createLesson($generating, $unit, [
                        'sequence' => $nextSequence + $generatedLesson->sequence,
                        'title' => $generatedLesson->title,
                        'lesson_mode' => 'full',
                        'status' => 'draft',
                        'learning_objective' => $generatedLesson->learningObjective,
                        'completion_criteria' => $generatedLesson->completionCriteria,
                        'estimated_minutes' => $generatedLesson->estimatedMinutes,
                        'estimated_preparation_minutes' => $generatedLesson->estimatedPreparationMinutes,
                        'suggested_sessions' => $generatedLesson->suggestedSessions,
                        'generator_key' => $this->generator->key(),
                        'generator_version' => $this->generator->version(),
                        'generation_metadata' => [
                            ...$generatedLesson->metadata,
                            'unit_lesson_sequence' => $generatedLesson->sequence,
                        ],
                    ]);
                    foreach ($generatedLesson->sections as $sectionSequence => $section) {
                        $record = $lesson->sections()->create([
                            'section_type' => $section->sectionType,
                            'sequence' => $sectionSequence + 1,
                            'title' => $section->title,
                            'content' => $section->content,
                            'audience' => $section->audience,
                            'estimated_minutes' => $section->estimatedMinutes,
                            'metadata' => $section->metadata ?: null,
                        ]);
                        $this->audit->record('lesson-section.generated', $record, [], $record->toArray());
                    }
                    foreach ($generatedLesson->resources as $resource) {
                        $record = $lesson->resources()->create([
                            'category' => $resource->category,
                            'resource_type' => $resource->resourceType,
                            'title' => $resource->title,
                            'description' => $resource->description,
                            'delivery_type' => $resource->deliveryType,
                            'availability_status' => $resource->category === 'lesson_resource' ? 'needs_asset' : 'not_applicable',
                            'sort_order' => $resource->sortOrder,
                            'metadata' => $resource->metadata ?: null,
                        ]);
                        $this->audit->record('lesson-resource.generated', $record, [], $record->toArray());
                    }
                    $links = collect($generatedLesson->curriculumComponentIds)->mapWithKeys(
                        fn ($id, $sequence) => [(int) $id => [
                            'role' => $componentTypes->get((int) $id), 'sequence' => $sequence + 1,
                        ]]
                    )->all();
                    $this->lessonPlans->syncLessonProvenance(
                        $lesson,
                        $links,
                        $generatedLesson->curriculumStandardAlignmentIds,
                    );
                }

                return $this->lessonPlans->completeGeneration($generating)->load('lessons');
            });

            if (config('lesson-resources.automatic_fulfillment')) {
                foreach ($completed->lessons as $lesson) {
                    $this->resourceFulfillment->fulfillRequiredForLesson($lesson);
                }
            }

            return $completed;
        } catch (Throwable $exception) {
            $safeMessage = $exception instanceof LessonGenerationException
                ? $exception->getMessage()
                : 'Lesson generation failed safely. No lessons were saved.';
            $this->lessonPlans->failGeneration($generating->fresh(), $safeMessage);

            throw ValidationException::withMessages(['generation' => $safeMessage]);
        }
    }
}
