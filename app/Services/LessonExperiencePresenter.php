<?php

namespace App\Services;

use App\Models\StudentLessonProgress;

class LessonExperiencePresenter
{
    public function props(StudentLessonProgress $progress, bool $preview, callable $responseUrl, callable $draftUrl, callable $resourceUrl): array
    {
        $progress->loadMissing(['enrollment.student', 'enrollment.gradeLevel', 'experience.lesson.lessonPlan.packageCourse.course.subject', 'experience.lesson.resources', 'experience.activities', 'responses']);
        $experience = $progress->experience;
        $responses = $progress->responses->keyBy('lesson_activity_id');
        $completed = $responses->whereIn('status', ['completed', 'submitted'])->count();
        $total = $experience->activities->count();
        $resources = $preview ? $experience->lesson->resources : $experience->lesson->resources->filter(fn ($resource) => $resource->category !== 'lesson_resource' || $resource->isAvailable());

        return [
            'preview' => $preview,
            'student' => ['display_name' => $progress->enrollment->student->display_name, 'grade_level' => $progress->enrollment->gradeLevel->name],
            'lesson' => ['id' => $experience->lesson->id, 'title' => $experience->lesson->title, 'learning_objective' => $experience->lesson->learning_objective, 'estimated_minutes' => $experience->lesson->estimated_minutes, 'subject' => $experience->lesson->lessonPlan->packageCourse->course->subject->name, 'resource_complete' => $experience->lesson->resources
                ->filter(fn ($resource) => (bool) data_get($resource->metadata, 'student_experience_required', $resource->category === 'lesson_resource' || $resource->category === 'special_material'))
                ->every(fn ($resource) => $resource->category === 'lesson_resource' ? $resource->isAvailable() : in_array($resource->availability_status, ['ready', 'not_applicable'], true))],
            'experience' => ['id' => $experience->id, 'theme_key' => $experience->theme_key, 'mission_title' => $experience->mission_title, 'mission_brief' => $experience->mission_brief, 'completion_title' => $experience->completion_title, 'completion_message' => $experience->completion_message],
            'progress' => ['id' => $progress->id, 'status' => $progress->status, 'current_activity_id' => $progress->current_activity_id, 'completed_count' => $completed, 'total_count' => $total, 'percent' => $total ? (int) round(($completed / $total) * 100) : 0],
            'activities' => $experience->activities->map(function ($activity) use ($responses, $responseUrl, $draftUrl) {
                $saved = $responses->get($activity->id);
                return [
                    'id' => $activity->id, 'sequence' => $activity->sequence, 'type' => $activity->activity_type,
                    'title' => $activity->display_title, 'instructions' => $activity->student_instructions,
                    'content' => $activity->content, 'interaction' => $activity->interaction_data,
                    'hints' => $activity->hints ?? [], 'reward_label' => $activity->reward_label,
                    'theme_key' => $activity->theme_key, 'requires_teacher_review' => $activity->requires_teacher_review,
                    'response_url' => $responseUrl($activity), 'draft_url' => (in_array(($activity->interaction_data['map_mode'] ?? null), ['builder', 'region_builder'], true) || isset($activity->interaction_data['analysis_builder']) || isset($activity->interaction_data['systems_map_builder']) || isset($activity->interaction_data['science_work_builder']) || isset($activity->interaction_data['math_work_builder']) || isset($activity->interaction_data['elar_response_builder']) || isset($activity->interaction_data['technology_code_builder']) || isset($activity->interaction_data['language_passport_builder']) || isset($activity->interaction_data['language_work_builder'])) ? $draftUrl($activity) : null, 'saved_response' => $saved?->response,
                    'response_status' => $saved?->status, 'is_correct' => $saved?->is_correct,
                    'feedback' => $saved?->feedback, 'teacher_review_status' => $saved?->teacher_review_status,
                ];
            })->all(),
            'resource_groups' => $resources->groupBy('category')->map(fn ($resources) => $resources->map(fn ($resource) => [
                'id' => $resource->id, 'title' => $resource->title, 'description' => $resource->description,
                'resource_type' => $resource->resource_type, 'delivery_type' => $resource->delivery_type,
                'availability_status' => $resource->availability_status,
                'student_experience_required' => $resource->category === 'special_material'
                    || (bool) data_get($resource->metadata, 'student_experience_required', false),
                'source_attribution' => $resource->source_attribution,
                'license_name' => $resource->license_name,
                'url' => $resource->isAvailable() ? $resourceUrl($resource) : null,
            ])->values()->all())->all(),
        ];
    }
}
