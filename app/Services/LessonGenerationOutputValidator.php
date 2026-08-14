<?php

namespace App\Services;

use App\Data\GeneratedLessonData;
use App\Data\GeneratedLessonSectionData;
use App\Data\GeneratedLessonResourceData;
use App\Data\LessonGenerationContext;
use App\Exceptions\LessonGenerationException;
use App\Models\Lesson;
use App\Models\LessonSection;
use App\Models\LessonResource;

class LessonGenerationOutputValidator
{
    public const MIN_LESSONS = 1;
    public const MAX_LESSONS = 12;
    public const SECTION_TYPES = [
        'teacher_preparation', 'common_materials', 'external_resources', 'materials',
        'introduction', 'hook', 'question', 'prediction', 'context', 'direct_instruction',
        'explanation', 'example', 'guided_practice', 'independent_practice', 'written_response',
        'check_for_understanding', 'investigation', 'observation', 'project_work', 'activity',
        'goal', 'demonstration', 'build', 'test', 'revise', 'deliverable', 'source_examination',
        'evidence_analysis', 'vocabulary', 'listening', 'speaking', 'reading', 'writing',
        'enrichment', 'reflection', 'wrap_up', 'exit_check', 'completion_criteria',
    ];

    /** @param list<GeneratedLessonData> $lessons @return list<GeneratedLessonData> */
    public function validate(array $lessons, LessonGenerationContext $context): array
    {
        if (count($lessons) < self::MIN_LESSONS || count($lessons) > self::MAX_LESSONS) {
            throw LessonGenerationException::malformed('A unit must generate between 1 and 12 lessons.');
        }
        $componentIds = collect($context->components)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $alignmentIds = collect($context->standardAlignments)->pluck('alignment_id')->map(fn ($id) => (int) $id)->all();
        $seenSequences = [];

        foreach ($lessons as $lesson) {
            if (! $lesson instanceof GeneratedLessonData) {
                throw LessonGenerationException::malformed();
            }
            if ($lesson->sequence < 1 || $lesson->sequence > count($lessons) || in_array($lesson->sequence, $seenSequences, true)) {
                throw LessonGenerationException::malformed('Lesson sequences must be unique and consecutive.');
            }
            $seenSequences[] = $lesson->sequence;
            if ($lesson->lessonMode !== 'full' || ! in_array($lesson->lessonMode, Lesson::MODES, true)) {
                throw LessonGenerationException::malformed('Only full lesson mode may be generated in this phase.');
            }
            $this->requiredText($lesson->title, 'Every lesson needs a title.', 3, 255);
            $this->requiredText($lesson->learningObjective, 'Every lesson needs a learning objective.', 10, 4000);
            $this->requiredText($lesson->completionCriteria, 'Every lesson needs completion criteria.', 10, 4000);
            if ($lesson->estimatedMinutes === null || $lesson->estimatedMinutes < 10 || $lesson->estimatedMinutes > 600) {
                throw LessonGenerationException::malformed('Student instructional time must be between 10 and 600 minutes.');
            }
            if ($lesson->estimatedPreparationMinutes < 0 || $lesson->estimatedPreparationMinutes > 240) {
                throw LessonGenerationException::malformed('Parent preparation time must be between 0 and 240 minutes.');
            }
            if ($lesson->suggestedSessions < 1 || $lesson->suggestedSessions > 10
                || $lesson->estimatedMinutes < $lesson->suggestedSessions * 10
                || (int) ceil($lesson->estimatedMinutes / $lesson->suggestedSessions) > 180) {
                throw LessonGenerationException::malformed('Suggested sessions must divide student time into 10- to 180-minute sessions.');
            }
            if (count($lesson->sections) < 2 || count($lesson->sections) > 16) {
                throw LessonGenerationException::malformed('Each lesson needs between 2 and 16 instructional sections.');
            }
            $hasInstruction = false;
            foreach ($lesson->sections as $section) {
                if (! $section instanceof GeneratedLessonSectionData
                    || ! in_array($section->sectionType, self::SECTION_TYPES, true)
                    || ! in_array($section->audience, LessonSection::AUDIENCES, true)) {
                    throw LessonGenerationException::malformed('A lesson section has an unsupported type or audience.');
                }
                $this->requiredText($section->content, 'Every lesson section needs instructional content.', 20, 12000);
                if ($section->estimatedMinutes !== null && ($section->estimatedMinutes < 1 || $section->estimatedMinutes > 180)) {
                    throw LessonGenerationException::malformed('Section duration must be between 1 and 180 minutes.');
                }
                $hasInstruction = $hasInstruction || (! in_array($section->sectionType, [
                    'teacher_preparation', 'common_materials', 'external_resources', 'materials',
                ], true)
                    && in_array($section->audience, ['student', 'shared'], true));
            }
            if (! $hasInstruction) {
                throw LessonGenerationException::malformed('Each lesson needs student-facing instructional content.');
            }
            if (count($lesson->resources) > 24) {
                throw LessonGenerationException::malformed('Each lesson may define at most 24 supplies and resources.');
            }
            $resourceOrders = [];
            foreach ($lesson->resources as $resource) {
                if (! $resource instanceof GeneratedLessonResourceData
                    || ! in_array($resource->category, LessonResource::CATEGORIES, true)
                    || ! in_array($resource->deliveryType, LessonResource::DELIVERY_TYPES, true)) {
                    throw LessonGenerationException::malformed('A lesson resource has an unsupported category or delivery type.');
                }
                $this->requiredText($resource->title, 'Every lesson resource needs a title.', 2, 255);
                if ($resource->sortOrder < 1 || $resource->sortOrder > 24
                    || isset($resourceOrders[$resource->category][$resource->sortOrder])) {
                    throw LessonGenerationException::malformed('Resource order must be unique within each resource category.');
                }
                $resourceOrders[$resource->category][$resource->sortOrder] = true;
                if ($resource->category === 'lesson_resource' && $resource->deliveryType === 'physical') {
                    throw LessonGenerationException::malformed('Lesson-provided instructional resources cannot use physical delivery.');
                }
                if ($resource->category !== 'lesson_resource' && $resource->deliveryType !== 'physical') {
                    throw LessonGenerationException::malformed('Student supplies and special materials must use physical delivery.');
                }
            }
            if (count($lesson->curriculumComponentIds) !== count(array_unique($lesson->curriculumComponentIds))) {
                throw LessonGenerationException::malformed('Curriculum component IDs must be unique within each lesson.');
            }
            if (count($lesson->curriculumStandardAlignmentIds) !== count(array_unique($lesson->curriculumStandardAlignmentIds))) {
                throw LessonGenerationException::malformed('Curriculum standard alignment IDs must be unique within each lesson.');
            }
            if (array_diff($lesson->curriculumComponentIds, $componentIds)
                || array_diff($lesson->curriculumStandardAlignmentIds, $alignmentIds)) {
                throw LessonGenerationException::malformed('Generated provenance contains curriculum records outside the selected unit.');
            }
            if (($componentIds || $alignmentIds)
                && ! $lesson->curriculumComponentIds && ! $lesson->curriculumStandardAlignmentIds) {
                throw LessonGenerationException::malformed('Every lesson must retain applicable curriculum provenance.');
            }
        }
        sort($seenSequences);
        if ($seenSequences !== range(1, count($lessons))) {
            throw LessonGenerationException::malformed('Lesson sequences must begin at 1 and remain consecutive.');
        }

        usort($lessons, fn ($left, $right) => $left->sequence <=> $right->sequence);

        return $lessons;
    }

    private function requiredText(?string $value, string $message, int $min, int $max): void
    {
        $length = mb_strlen(trim((string) $value));
        if ($length < $min || $length > $max) {
            throw LessonGenerationException::malformed($message);
        }
    }
}
