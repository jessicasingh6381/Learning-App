<?php

namespace Tests\Feature;

use App\Models\AcademicSource;
use App\Models\Course;
use App\Models\CurriculumImport;
use App\Models\CurriculumPackage;
use App\Models\CurriculumUnit;
use App\Models\CurriculumUnitComponent;
use App\Models\CurriculumUnitStandardAlignment;
use App\Models\EducationProvider;
use App\Models\GradeLevel;
use App\Models\Lesson;
use App\Models\LessonExperience;
use App\Models\LessonPlan;
use App\Models\StandardsFramework;
use App\Models\StudentEnrollment;
use App\Models\Subject;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\LessonPlanService;
use App\Services\LessonExperienceService;
use App\Services\LessonResourceAssetValidator;
use App\Services\LessonResourceFulfillmentManager;
use App\Services\StructuredCustomCurriculumParser;
use App\Tenancy\TenantContext;
use Database\Seeders\AcademicReferenceSeeder;
use Database\Seeders\GradeLevelSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LessonPlanFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed([GradeLevelSeeder::class, AcademicReferenceSeeder::class]);
    }

    public function test_lesson_plan_requires_approved_materialized_matching_curriculum(): void
    {
        $context = $this->context();
        $service = app(LessonPlanService::class);
        $context['import']->update(['status' => 'review']);
        $this->expectValidation(fn () => $service->createDraft($context['enrollment'], $context['import']), 'curriculum_import_id');

        $context['import']->update(['status' => 'approved', 'school_year_id' => $context['otherYear']->id]);
        $this->expectValidation(fn () => $service->createDraft($context['enrollment'], $context['import']), 'curriculum_import_id');

        $context['import']->update(['school_year_id' => $context['year']->id, 'grade_level_id' => $context['otherGrade']->id]);
        $this->expectValidation(fn () => $service->createDraft($context['enrollment'], $context['import']), 'curriculum_import_id');
    }

    public function test_incorrect_subject_course_mapping_is_rejected(): void
    {
        $context = $this->context();
        $otherSubject = Subject::create(['name' => 'Different Subject', 'code' => 'DIFFERENT', 'status' => 'active']);
        $otherCourse = Course::create(['subject_id' => $otherSubject->id, 'name' => 'Different Course', 'code' => 'DIFFERENT-COURSE', 'status' => 'draft']);
        $context['mapping']->update(['course_id' => $otherCourse->id]);

        $this->expectValidation(
            fn () => app(LessonPlanService::class)->createDraft($context['enrollment'], $context['import']),
            'curriculum_import_id'
        );
    }

    public function test_lesson_provenance_components_alignments_and_default_mode_work(): void
    {
        $context = $this->context();
        $service = app(LessonPlanService::class);
        $plan = $service->createDraft($context['enrollment'], $context['import']);
        $lesson = $service->createLesson($plan, $context['unit'], ['sequence' => 1, 'title' => 'Foundation Lesson']);
        $lesson = $service->syncLessonProvenance($lesson, [
            $context['component']->id => ['role' => 'objective', 'sequence' => 1],
        ], [$context['alignment']->id]);

        $lesson = Lesson::with(['lessonPlan.curriculumImport.source', 'curriculumUnit', 'curriculumComponents', 'standardAlignments'])
            ->findOrFail($lesson->id);
        $this->assertSame('full', $lesson->lesson_mode);
        $this->assertNull($lesson->estimated_preparation_minutes);
        $this->assertNull($lesson->suggested_sessions);
        $this->assertTrue($lesson->curriculumUnit->is($context['unit']));
        $this->assertTrue($lesson->curriculumComponents->first()->is($context['component']));
        $this->assertTrue($lesson->standardAlignments->first()->is($context['alignment']));
        $this->assertTrue($lesson->lessonPlan->curriculumImport->source->is($context['source']));
        $service->assertLessonProvenance($plan, $lesson);
    }

    public function test_elar_lesson_one_teaches_before_practice_and_persists_text_evidence(): void
    {
        Storage::fake('local');
        $context = $this->context();
        $plan = app(LessonPlanService::class)->createDraft($context['enrollment'], $context['import']);
        $lesson = $plan->lessons()->create([
            'curriculum_unit_id' => $context['unit']->id, 'sequence' => 1,
            'title' => 'Launching Literacy: Active Reading and Syllable Review', 'lesson_mode' => 'full', 'status' => 'draft',
            'learning_objective' => 'Use a monitor-and-clarify routine and distinguish four syllable patterns.',
            'completion_criteria' => 'Clarify meaning and correctly classify ten syllables.', 'estimated_minutes' => 55,
        ]);
        foreach ([
            ['introduction', 'Active reading'], ['direct_instruction', 'Syllable patterns'], ['demonstration', 'Modeled clarification'],
            ['guided_practice', 'Guided reading'], ['independent_practice', 'Independent sort'], ['exit_check', 'Repair check'],
        ] as $index => [$type, $title]) {
            $lesson->sections()->create(['section_type' => $type, 'sequence' => $index + 1, 'title' => $title, 'content' => $title.' source section content for the student experience.', 'audience' => 'shared']);
        }

        $service = app(LessonExperienceService::class);
        $experience = $service->provisionElarActiveReadingPrototype($lesson);
        app(LessonResourceFulfillmentManager::class)->fulfillRequiredForLesson($lesson);
        $activities = $experience->activities()->get()->keyBy('sequence');
        $this->assertSame(range(1, 8), $activities->keys()->all());
        $this->assertSame(['instruction', 'instruction', 'instruction', 'project', 'instruction', 'matching', 'matching', 'question_set'], $activities->pluck('activity_type')->all());
        $this->assertSame('Application-created instructional text', data_get($activities[3]->interaction_data, 'reading_passage.source_label'));
        $this->assertNotEmpty(data_get($activities[4]->interaction_data, 'reading_passage.paragraphs'));
        $this->assertCount(4, data_get($activities[5]->interaction_data, 'syllable_patterns'));
        $this->assertSame(['repair', 'closed', 'open', 'vce', 'stable'], collect(data_get($activities[8]->interaction_data, 'questions'))->pluck('id')->all());
        $this->assertTrue($lesson->resources()->where('category', 'lesson_resource')->where('availability_status', 'ready')->count() >= 2, $lesson->resources()->get()->map->only(['title', 'availability_status', 'fulfillment_error'])->toJson());
        $readiness = app(\App\Services\LessonReadinessService::class)->check($lesson->fresh());
        $this->assertTrue($readiness['ready'], json_encode($readiness['blockers']));

        $progress = $service->progress($experience, $context['enrollment'], true, $context['user']);
        foreach ([1, 2, 3] as $sequence) $progress = $service->respond($progress, $activities[$sequence], ['acknowledged' => true]);
        $draft = ['elar_work' => ['confusion_one' => 'Uneven flow', 'evidence_one' => 'p2s4', 'strategy_one' => 'reread', 'clarification_one' => '', 'confusion_two' => '', 'evidence_two' => '', 'strategy_two' => '', 'clarification_two' => '']];
        $service->saveDraft($progress, $activities[4], $draft);
        $this->assertSame($draft, $progress->responses()->where('lesson_activity_id', $activities[4]->id)->firstOrFail()->response);
        $wrong = ['elar_work' => ['confusion_one' => 'Uneven flow', 'evidence_one' => 'p2s1', 'strategy_one' => 'reread', 'clarification_one' => 'It tells me only what a prototype is.', 'confusion_two' => 'Meaning of reliable', 'evidence_two' => 'p4s3', 'strategy_two' => 'context', 'clarification_two' => 'Repeated tests show that the tool keeps working.']];
        $retry = $service->respond($progress, $activities[4], $wrong);
        $this->assertSame($activities[4]->id, $retry->current_activity_id);
        $this->assertStringContainsString('paragraphs 2 and 3', $retry->responses->firstWhere('lesson_activity_id', $activities[4]->id)->feedback);
        $progress = $service->respond($retry, $activities[4], ['elar_work' => ['confusion_one' => 'Uneven flow', 'evidence_one' => 'p2s4', 'strategy_one' => 'reread', 'clarification_one' => 'Her notes showed where the pressure was strongest.', 'confusion_two' => 'Meaning of reliable', 'evidence_two' => 'p4s3', 'strategy_two' => 'context', 'clarification_two' => 'Repeated tests show that the tool keeps working.']]);
        $this->assertSame($activities[5]->id, $progress->current_activity_id);
        $progress = $service->respond($progress, $activities[5], ['acknowledged' => true]);
        $guided = ['matches' => ['rob' => 'closed', 'pi' => 'open', 'make' => 'final_vce', 'tion' => 'stable_final']];
        $wrongGuided = $guided; $wrongGuided['matches']['rob'] = 'open';
        $retry = $service->respond($progress, $activities[6], $wrongGuided);
        $this->assertSame($activities[6]->id, $retry->current_activity_id);
        $this->assertStringContainsString('consonant b', $retry->responses->firstWhere('lesson_activity_id', $activities[6]->id)->feedback);
        $progress = $service->respond($retry, $activities[6], $guided);
        $eightOfTen = ['matches' => ['kit' => 'closed', 'plan' => 'closed', 'go' => 'open', 'he' => 'open', 'type' => 'final_vce', 'hope' => 'final_vce', 'tion' => 'stable_final', 'ble' => 'stable_final', 'ven' => 'open', 'pi' => 'closed']];
        $progress = $service->respond($progress, $activities[7], $eightOfTen);
        $this->assertSame($activities[8]->id, $progress->current_activity_id);
        $wrongExit = ['answers' => ['repair' => 'skip', 'closed' => 'kit', 'open' => 'pi', 'vce' => 'hope', 'stable' => 'tion']];
        $retry = $service->respond($progress, $activities[8], $wrongExit);
        $this->assertSame($activities[8]->id, $retry->current_activity_id);
        $this->assertStringContainsString('Skipping removes evidence', $retry->responses->firstWhere('lesson_activity_id', $activities[8]->id)->feedback);
        $progress = $service->respond($retry, $activities[8], ['answers' => ['repair' => 'stop_name', 'closed' => 'kit', 'open' => 'pi', 'vce' => 'hope', 'stable' => 'tion']]);
        $this->assertSame('completed', $progress->status);
        $this->assertSame('preview', $experience->fresh()->status);
        $this->assertSame('draft', $lesson->fresh()->status);
        $this->assertSame('draft', $plan->fresh()->status);
    }

    public function test_elar_lessons_two_and_three_teach_and_persist_summary_and_inference_work(): void
    {
        Storage::fake('local');
        $context = $this->context();
        $plan = app(LessonPlanService::class)->createDraft($context['enrollment'], $context['import']);
        $lessonTwo = $plan->lessons()->create([
            'curriculum_unit_id' => $context['unit']->id, 'sequence' => 2,
            'title' => 'Narrative Nonfiction: Central Idea and Summary', 'status' => 'draft',
            'learning_objective' => 'Identify the central idea and supporting details in narrative nonfiction and write an objective summary.',
            'completion_criteria' => 'State a central idea, select three details, and write a 4–6 sentence summary.', 'estimated_minutes' => 60,
        ]);
        foreach ([
            ['teacher_preparation', 'Prepare', 'teacher'], ['hook', 'Story facts', 'shared'], ['direct_instruction', 'Central idea', 'shared'],
            ['demonstration', 'Importance test', 'teacher'], ['reading', 'Read', 'student'], ['guided_practice', 'Build idea', 'shared'],
            ['written_response', 'Summary', 'student'], ['exit_check', 'Check', 'shared'],
        ] as $index => [$type, $title, $audience]) {
            $lessonTwo->sections()->create(['section_type' => $type, 'sequence' => $index + 1, 'title' => $title, 'content' => $title.' source.', 'audience' => $audience]);
        }
        $lessonTwo->resources()->create(['category' => 'lesson_resource', 'resource_type' => 'passage', 'title' => 'Mara and the Folding Cart', 'delivery_type' => 'printable', 'availability_status' => 'needs_asset', 'sort_order' => 1]);
        $lessonTwo->resources()->create(['category' => 'lesson_resource', 'resource_type' => 'graphic_organizer', 'title' => 'Organizer', 'delivery_type' => 'printable', 'availability_status' => 'needs_asset', 'sort_order' => 2]);
        $lessonTwo->resources()->create(['category' => 'student_supply', 'resource_type' => 'supply', 'title' => 'Pencil', 'delivery_type' => 'physical', 'availability_status' => 'not_applicable', 'sort_order' => 1]);

        $lessonThree = $plan->lessons()->create([
            'curriculum_unit_id' => $context['unit']->id, 'sequence' => 3,
            'title' => 'Point of View, Inference, and Text Evidence', 'status' => 'draft',
            'learning_objective' => 'Analyze point of view, make an inference, and cite evidence.',
            'completion_criteria' => 'Identify point of view and support an inference with two details.', 'estimated_minutes' => 55,
        ]);
        foreach ([
            ['introduction', 'Narrator', 'shared'], ['demonstration', 'Model', 'teacher'], ['source_examination', 'Reread', 'student'],
            ['guided_practice', 'Compare', 'shared'], ['independent_practice', 'Respond', 'student'], ['check_for_understanding', 'Check', 'shared'],
        ] as $index => [$type, $title, $audience]) {
            $lessonThree->sections()->create(['section_type' => $type, 'sequence' => $index + 1, 'title' => $title, 'content' => $title.' source.', 'audience' => $audience]);
        }
        $lessonThree->resources()->create(['category' => 'lesson_resource', 'resource_type' => 'passage', 'title' => 'Mara and the Folding Cart', 'delivery_type' => 'printable', 'availability_status' => 'needs_asset', 'sort_order' => 1]);
        $lessonThree->resources()->create(['category' => 'lesson_resource', 'resource_type' => 'graphic_organizer', 'title' => 'Organizer', 'delivery_type' => 'printable', 'availability_status' => 'needs_asset', 'sort_order' => 2]);

        $service = app(LessonExperienceService::class);
        $summaryExperience = $service->provisionElarCentralIdeaSummaryPrototype($lessonTwo);
        $inferenceExperience = $service->provisionElarPointOfViewInferencePrototype($lessonThree);
        app(LessonResourceFulfillmentManager::class)->fulfillRequiredForLesson($lessonTwo);
        app(LessonResourceFulfillmentManager::class)->fulfillRequiredForLesson($lessonThree);
        $this->assertSame(7, $summaryExperience->activities()->count());
        $this->assertSame(6, $inferenceExperience->activities()->count());
        $this->assertSame('Learning-App original content', data_get($summaryExperience->activities()->where('sequence', 2)->value('interaction_data'), 'reading_passage.source_label'));
        $this->assertSame('Learning-App original content', data_get($inferenceExperience->activities()->where('sequence', 2)->value('interaction_data'), 'reading_passage.source_label'));
        $passageText = collect(\App\Services\ElarLessonContent::maraPassage()['paragraphs'])
            ->flatMap(fn ($paragraph) => collect($paragraph['sentences'])->pluck('text'))->implode(' ');
        $this->assertSame(700, str_word_count($passageText));
        $lessonTwoReadiness = app(\App\Services\LessonReadinessService::class)->check($lessonTwo->fresh());
        $lessonThreeReadiness = app(\App\Services\LessonReadinessService::class)->check($lessonThree->fresh());
        $this->assertTrue($lessonTwoReadiness['ready'], json_encode([$lessonTwoReadiness['blockers'], $lessonTwo->resources()->get()->map->only(['title', 'availability_status', 'fulfillment_error', 'metadata'])]));
        $this->assertTrue($lessonThreeReadiness['ready'], json_encode([$lessonThreeReadiness['blockers'], $lessonThree->resources()->get()->map->only(['title', 'availability_status', 'fulfillment_error', 'metadata'])]));
        $this->assertSame(0, $lessonTwo->fresh()->estimated_preparation_minutes);
        $this->assertSame(0, $lessonThree->fresh()->estimated_preparation_minutes);

        $summaryActivities = $summaryExperience->activities()->get()->keyBy('sequence');
        $progress = $service->progress($summaryExperience, $context['enrollment'], true, $context['user']);
        $progress = $service->respond($progress, $summaryActivities[1], ['acknowledged' => true]);
        $progress = $service->respond($progress, $summaryActivities[2], ['acknowledged' => true]);
        $wrong = $service->respond($progress, $summaryActivities[3], ['answers' => ['topic' => 'idea', 'central' => 'patient', 'minor' => 'orange']]);
        $this->assertSame($summaryActivities[3]->id, $wrong->current_activity_id);
        $progress = $service->respond($wrong, $summaryActivities[3], ['answers' => ['topic' => 'cart', 'central' => 'patient', 'minor' => 'orange']]);
        $progress = $service->respond($progress, $summaryActivities[4], ['elar_work' => ['early_detail' => 'm3s2', 'middle_detail' => 'm4s4', 'late_detail' => 'm7s4', 'central_idea' => 'Patient revision turns Mara’s uncertain idea into a dependable tool for volunteers.']]);
        $progress = $service->respond($progress, $summaryActivities[5], ['acknowledged' => true]);
        $summaryDraft = ['elar_work' => ['problem_evidence' => 'm1s3', 'process_evidence' => 'm4s4', 'result_evidence' => 'm6s4', 'central_idea' => 'Mara’s patient testing and revision create a dependable tool.', 'summary' => str_repeat('Mara tests and improves her folding cart in her own words. ', 4)]];
        $service->saveDraft($progress, $summaryActivities[6], $summaryDraft);
        $this->assertSame($summaryDraft, $progress->responses()->where('lesson_activity_id', $summaryActivities[6]->id)->firstOrFail()->response);
        $progress = $service->respond($progress, $summaryActivities[6], $summaryDraft);
        $progress = $service->respond($progress, $summaryActivities[7], ['answers' => ['whole' => 'topic', 'omit' => 'orange', 'own_words' => 'paraphrase']]);
        $this->assertSame('completed', $progress->status);

        $inferenceActivities = $inferenceExperience->activities()->get()->keyBy('sequence');
        $progress = $service->progress($inferenceExperience, $context['enrollment'], true, $context['user']);
        $progress = $service->respond($progress, $inferenceActivities[1], ['acknowledged' => true]);
        $progress = $service->respond($progress, $inferenceActivities[2], ['acknowledged' => true]);
        $progress = $service->respond($progress, $inferenceActivities[3], ['answers' => ['pov' => 'third', 'fact' => 'notebook', 'inference' => 'persistent']]);
        $progress = $service->respond($progress, $inferenceActivities[4], ['answers' => ['evidence' => 'soil', 'reasoning' => 'continues']]);
        $inferenceDraft = ['elar_work' => ['point_of_view' => 'mara', 'inference' => 'Mara uses setbacks as information and continues improving her work.', 'evidence_one' => 'm3s2', 'reasoning_one' => 'Recording the problem shows that she studies the failure instead of quitting.', 'evidence_two' => 'm7s3', 'reasoning_two' => 'Planning another test shows that her persistence continues after success.']];
        $service->saveDraft($progress, $inferenceActivities[5], $inferenceDraft);
        $this->assertSame($inferenceDraft, $progress->responses()->where('lesson_activity_id', $inferenceActivities[5]->id)->firstOrFail()->response);
        $progress = $service->respond($progress, $inferenceActivities[5], $inferenceDraft);
        $progress = $service->respond($progress, $inferenceActivities[6], ['answers' => ['perspective' => 'mara', 'supported' => 'learns', 'connection' => 'reason']]);
        $this->assertSame('completed', $progress->status);
        $this->assertSame('draft', $plan->fresh()->status);
        $this->assertSame('preview', $summaryExperience->fresh()->status);
        $this->assertSame('preview', $inferenceExperience->fresh()->status);
    }

    public function test_status_transitions_and_approved_revision_immutability_work(): void
    {
        $context = $this->context();
        $service = app(LessonPlanService::class);
        $plan = $service->beginGeneration($context['enrollment'], $context['import'], 'test-generator', '1.0');
        $this->assertSame('generating', $plan->status);
        $plan = $service->failGeneration($plan, 'Safe failure detail');
        $this->assertSame('failed', $plan->status);
        $plan = $service->beginGeneration($context['enrollment'], $context['import'], 'test-generator', '1.1');
        $plan = $service->completeGeneration($plan);
        $lesson = $plan->lessons()->create(['curriculum_unit_id' => $context['unit']->id, 'sequence' => 1, 'title' => 'Lesson']);
        $lesson = $service->markLessonReviewed($lesson);
        $lesson = $service->approveLesson($lesson);
        $plan = $service->markReviewed($plan);
        $plan = $service->approve($plan);
        $this->assertSame('approved', $plan->status);
        $this->expectValidation(fn () => $service->markEdited($plan), 'lesson_plan');

        $revision = $service->createDraft($context['enrollment'], $context['import']);
        $this->assertNotSame($plan->id, $revision->id);
        $this->assertSame(2, $revision->revision);
        $this->assertSame('approved', $plan->fresh()->status);
        $this->assertSame($revision->id, $plan->fresh()->superseded_by_lesson_plan_id);
    }

    public function test_tenant_scope_hides_plans_and_routes_from_another_tenant(): void
    {
        $context = $this->context();
        $plan = app(LessonPlanService::class)->createDraft($context['enrollment'], $context['import']);
        [$otherUser, $otherTenant] = $this->adult('Other Academy');
        $this->setContext($otherUser, $otherTenant);

        $this->assertNull(LessonPlan::find($plan->id));
        $this->actingIn($otherUser, $otherTenant)->get(route('lesson-plans.show', $plan->id))->assertNotFound();
    }

    public function test_learning_plan_shows_not_created_then_draft_lesson_plan_state(): void
    {
        $context = $this->context();
        $this->actingIn($context['user'], $context['tenant'])->get('/learning-plan')->assertInertia(fn (Assert $page) => $page
            ->component('Workspace/LearningPlan')
            ->where('curriculumBySubject', fn ($subjects) => $subjects->contains(fn ($subject) =>
                $subject['curriculum_import_id'] === $context['import']->id
                && $subject['lesson_plan'] === null
                && $subject['lesson_plan_create_url'] === route('lesson-plans.store', [$context['enrollment'], $context['import']])
            ))
            ->where('lessonPlanManageable', true));

        $plan = app(LessonPlanService::class)->createDraft($context['enrollment'], $context['import']);
        $this->actingIn($context['user'], $context['tenant'])->get('/learning-plan')->assertInertia(fn (Assert $page) => $page
            ->where('curriculumBySubject', fn ($subjects) => $subjects->contains(fn ($subject) =>
                ($subject['lesson_plan']['id'] ?? null) === $plan->id
                && $subject['lesson_plan']['status'] === 'draft'
                && $subject['lesson_plan']['lesson_count'] === 0
            )));
    }

    public function test_review_and_lesson_pages_expose_clean_curriculum_context(): void
    {
        $context = $this->context();
        $plan = app(LessonPlanService::class)->createDraft($context['enrollment'], $context['import']);
        $lesson = $plan->lessons()->create([
            'curriculum_unit_id' => $context['unit']->id, 'sequence' => 1, 'title' => 'Inspect the Evidence',
            'learning_objective' => 'Explain the curriculum objective.', 'completion_criteria' => 'Provide a supported response.',
            'estimated_minutes' => 70, 'estimated_preparation_minutes' => 12, 'suggested_sessions' => 2,
        ]);
        $lesson->sections()->create(['section_type' => 'external_resources', 'sequence' => 1, 'title' => 'External resource', 'content' => 'A labeled reference map showing every required feature.', 'audience' => 'teacher']);
        $lesson->sections()->create(['section_type' => 'instruction', 'sequence' => 2, 'title' => 'Learn', 'content' => 'Read and discuss.', 'audience' => 'shared']);
        $lesson->curriculumComponents()->attach($context['component']->id, ['tenant_id' => $context['tenant']->id, 'role' => 'objective']);
        $lesson->standardAlignments()->attach($context['alignment']->id, ['tenant_id' => $context['tenant']->id]);

        $this->actingIn($context['user'], $context['tenant'])->get(route('lesson-plans.show', $plan))->assertInertia(fn (Assert $page) => $page
            ->component('LessonPlans/Show')->where('lessonPlan.lesson_count', 1)
            ->where('lessonPlan.lessons.0.curriculum_unit', 'Unit One')
            ->where('lessonPlan.lessons.0.estimated_minutes', 70)
            ->where('lessonPlan.lessons.0.estimated_preparation_minutes', 12)
            ->where('lessonPlan.lessons.0.suggested_sessions', 2));
        $this->actingIn($context['user'], $context['tenant'])->get(route('lesson-plans.lessons.show', [$plan, $lesson]))->assertInertia(fn (Assert $page) => $page
            ->component('Lessons/Show')->where('lesson.title', 'Inspect the Evidence')
            ->where('lesson.estimated_minutes', 70)
            ->where('lesson.estimated_preparation_minutes', 12)
            ->where('lesson.suggested_sessions', 2)
            ->where('lesson.components.0.name', 'Core Objective')
            ->where('lesson.standards.0.code', 'STD.1')
            ->where('lesson.sections.0.type', 'external_resources')
            ->where('lesson.sections.0.content', 'A labeled reference map showing every required feature.')
            ->where('lesson.sections.1.audience', 'shared')
            ->where('lesson.provenance.unit', 'Unit One'));
    }

    public function test_teacher_can_preview_draft_experience_without_teacher_sections_or_answer_keys(): void
    {
        $context = $this->context();
        [$plan, $lesson] = $this->mapLesson($context);
        $before = [
            'unit' => $lesson->curriculum_unit_id,
            'components' => $lesson->curriculumComponents()->pluck('curriculum_unit_components.id')->all(),
            'standards' => $lesson->standardAlignments()->pluck('curriculum_unit_standard_alignments.id')->all(),
            'lesson_count' => $plan->lessons()->count(),
        ];
        app(LessonExperienceService::class)->provisionMapMissionPrototype($lesson);

        $this->actingIn($context['user'], $context['tenant'])
            ->get(route('lesson-plans.lessons.experience-preview', [$plan, $lesson]))
            ->assertInertia(fn (Assert $page) => $page
                ->component('StudentExperiences/Show')->where('preview', true)
                ->where('lesson.title', 'Reading and Creating Maps of the United States')
                ->where('progress.total_count', 7)
                ->where('activities.0.title', 'Accept the Map Mission')
                ->where('activities.4.interaction.map_mode', 'reference')
                ->where('activities.4.interaction.fields.0.label', 'What does the star symbol represent on this map?')
                ->where('activities.4.interaction.fields.0.control', 'multiple_choice')
                ->where('activities.4.interaction.fields.1.label', 'Is Texas east or west of Florida? Explain how you know using the map.')
                ->where('activities.4.interaction.fields.2.label', 'Does this map show how many people live in each state?')
                ->where('resource_groups.student_supply.0.title', 'Pencil and eraser')
                ->where('resource_groups.student_supply.0.student_experience_required', false)
                ->where('resource_groups.lesson_resource.0.title', 'Blank U.S. Outline Map')
                ->where('resource_groups.lesson_resource.0.url', null)
                ->where('resource_groups.lesson_resource.2.title', 'Explore the United States')
                ->where('resource_groups.lesson_resource.2.delivery_type', 'interactive')
                ->missing('activities.0.answer_data')
                ->where('activities', fn ($activities) => ! str_contains(json_encode($activities), 'Private teacher setup')));
        $this->actingIn($context['user'], $context['tenant'])
            ->get(route('lesson-plans.lessons.show', [$plan, $lesson]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('lesson.resource_groups.student_supply.0.title', 'Pencil and eraser')
                ->where('lesson.resource_groups.lesson_resource.0.title', 'Blank U.S. Outline Map')
                ->where('lesson.resource_groups.lesson_resource.0.availability_status', 'needs_asset'));

        $lesson->refresh();
        $this->assertSame($before['unit'], $lesson->curriculum_unit_id);
        $this->assertSame($before['components'], $lesson->curriculumComponents()->pluck('curriculum_unit_components.id')->all());
        $this->assertSame($before['standards'], $lesson->standardAlignments()->pluck('curriculum_unit_standard_alignments.id')->all());
        $this->assertSame($before['lesson_count'], $plan->lessons()->count());
        $this->assertDatabaseCount('lesson_experiences', 1);
        $this->assertDatabaseCount('lesson_resources', 6);
    }

    public function test_draft_experience_is_not_available_on_normal_student_route_and_other_tenants_are_isolated(): void
    {
        $context = $this->context();
        [$plan, $lesson] = $this->mapLesson($context);
        app(LessonExperienceService::class)->provisionMapMissionPrototype($lesson);
        $studentUser = User::factory()->create(['must_change_password' => false]);
        TenantMembership::create(['tenant_id' => $context['tenant']->id, 'user_id' => $studentUser->id, 'role' => 'student', 'status' => 'active']);
        $context['enrollment']->student->update(['user_id' => $studentUser->id, 'student_access_enabled_at' => now()]);

        $this->actingIn($studentUser, $context['tenant'])->get(route('student.lessons.experience.show', $lesson))->assertNotFound();

        [$otherUser, $otherTenant] = $this->adult('Other Academy');
        $this->actingIn($otherUser, $otherTenant)->get(route('lesson-plans.lessons.experience-preview', [$plan, $lesson]))->assertNotFound();
        $this->setContext($otherUser, $otherTenant);
        $this->assertNull(LessonExperience::find(1));
    }

    public function test_activity_responses_validate_persist_resume_and_complete_without_grades_or_mastery(): void
    {
        $context = $this->context();
        [$plan, $lesson] = $this->mapLesson($context);
        $service = app(LessonExperienceService::class);
        $experience = $service->provisionMapMissionPrototype($lesson);
        $progress = $service->progress($experience, $context['enrollment'], true, $context['user']);
        $this->assertSame($progress->id, $service->progress($experience, $context['enrollment'], true, $context['user'])->id);

        $activities = $experience->activities()->get()->keyBy('sequence');
        $service->respond($progress, $activities[1], ['acknowledged' => true]);
        $service->respond($progress, $activities[2], ['acknowledged' => true]);
        $wrong = $service->respond($progress, $activities[3], ['selected' => 'title']);
        $this->assertSame('in_progress', $wrong->responses->firstWhere('lesson_activity_id', $activities[3]->id)->status);
        $this->assertFalse($wrong->responses->firstWhere('lesson_activity_id', $activities[3]->id)->is_correct);
        $service->respond($progress, $activities[3], ['selected' => 'legend']);
        $service->respond($progress, $activities[4], ['matches' => ['title' => 'subject', 'orientation' => 'direction', 'legend' => 'symbols', 'scale' => 'distance']]);
        $service->respond($progress, $activities[5], ['symbol_meaning' => 'national_capital', 'relative_location' => 'Texas is west of Florida because it appears to the left when north is up.', 'limitation' => 'no_population']);
        $afterMap = $service->respond($progress, $activities[6], $this->digitalMapResponse());
        $this->assertSame($activities[7]->id, $afterMap->current_activity_id);
        $finished = $service->respond($progress, $activities[7], ['answers' => ['title_job' => 'a', 'orientation_job' => 'b', 'consistency' => 'a']]);

        $this->assertSame('completed', $finished->status);
        $this->assertNotNull($finished->completed_at);
        $this->assertSame(7, $finished->responses->whereIn('status', ['completed', 'submitted'])->count());
        $this->assertSame(2, $finished->responses->where('teacher_review_status', 'pending')->count());
        $this->assertFalse(\Schema::hasTable('grades'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'student-lesson-progress.completed']);
    }

    public function test_available_experience_still_requires_the_assigned_student_enrollment(): void
    {
        $context = $this->context();
        [$plan, $lesson] = $this->mapLesson($context);
        $experience = app(LessonExperienceService::class)->provisionMapMissionPrototype($lesson);
        $plan->update(['status' => 'approved']);
        $lesson->update(['status' => 'approved']);
        $experience->update(['status' => 'available']);

        $otherUser = User::factory()->create(['must_change_password' => false]);
        TenantMembership::create(['tenant_id' => $context['tenant']->id, 'user_id' => $otherUser->id, 'role' => 'student', 'status' => 'active']);
        $otherStudent = $context['tenant']->students()->create(['user_id' => $otherUser->id, 'student_access_enabled_at' => now(), 'first_name' => 'Other', 'last_name' => 'Student', 'status' => 'active']);
        StudentEnrollment::create(['student_id' => $otherStudent->id, 'school_year_id' => $context['year']->id, 'grade_level_id' => $context['grade']->id, 'enrollment_date' => '2026-08-01', 'status' => 'active']);

        $this->actingIn($otherUser, $context['tenant'])->get(route('student.lessons.experience.show', $lesson))->assertNotFound();
        $this->assertDatabaseCount('student_lesson_progress', 0);
    }

    public function test_ready_lesson_resource_is_securely_viewable_while_missing_assets_fail_closed(): void
    {
        Storage::fake('local');
        $context = $this->context();
        [$plan, $lesson] = $this->mapLesson($context);
        app(LessonExperienceService::class)->provisionMapMissionPrototype($lesson);
        $blankMap = $lesson->resources()->where('resource_type', 'blank_map')->firstOrFail();

        $this->actingIn($context['user'], $context['tenant'])
            ->get(route('lesson-plans.lessons.resources.show', [$plan, $lesson, $blankMap]))->assertNotFound();

        Storage::disk('local')->put('lesson-resources/outline-map.pdf', 'safe-pdf-fixture');
        $blankMap->update(['availability_status' => 'ready', 'asset_disk' => 'local', 'asset_path' => 'lesson-resources/outline-map.pdf', 'original_filename' => 'outline-map.pdf', 'mime_type' => 'application/pdf']);
        $this->actingIn($context['user'], $context['tenant'])
            ->get(route('lesson-plans.lessons.resources.show', [$plan, $lesson, $blankMap]))
            ->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_vetted_map_provider_fulfills_both_required_maps_with_private_provenance(): void
    {
        Storage::fake('local');
        Http::fake([
            'https://store.usgs.gov/assets/yimages/PDF/101263.pdf' => Http::response('%PDF-1.7 blank-map', 200, ['Content-Type' => 'application/pdf']),
            'https://store.usgs.gov/assets/yimages/PDF/101211.pdf' => Http::response('%PDF-1.7 labeled-map', 200, ['Content-Type' => 'application/pdf']),
        ]);
        $validator = \Mockery::mock(LessonResourceAssetValidator::class);
        $validator->shouldReceive('validate')->twice()->andReturn([
            'validation_version' => 1, 'page_count' => 1, 'page_width_inches' => 41,
            'page_height_inches' => 27, 'pdf_parseable' => true,
        ]);
        $this->app->instance(LessonResourceAssetValidator::class, $validator);

        $context = $this->context();
        [, $lesson] = $this->mapLesson($context);
        app(LessonExperienceService::class)->provisionMapMissionPrototype($lesson);
        $manager = app(LessonResourceFulfillmentManager::class);
        $results = $lesson->resources()->whereIn('resource_type', ['blank_map', 'reference_map'])->get()
            ->map(fn ($resource) => $manager->fulfill($resource))->all();

        $this->assertCount(2, $results);
        foreach ($lesson->resources()->whereIn('resource_type', ['blank_map', 'reference_map'])->get() as $resource) {
            $this->assertSame('ready', $resource->availability_status);
            $this->assertSame('authoritative_retrieval', $resource->fulfillment_strategy);
            $this->assertSame('usgs_maps', $resource->fulfillment_provider);
            $this->assertSame('U.S. Public Domain', $resource->license_name);
            $this->assertSame(64, strlen($resource->checksum_sha256));
            $this->assertSame(1, $resource->validation_metadata['page_count']);
            Storage::disk('local')->assertExists($resource->asset_path);
        }
        Http::assertSentCount(2);
    }

    public function test_failed_validation_never_marks_or_serves_a_resource_as_ready(): void
    {
        Storage::fake('local');
        Http::fake(['https://store.usgs.gov/assets/yimages/PDF/101263.pdf' => Http::response('not a pdf')]);
        $context = $this->context();
        [$plan, $lesson] = $this->mapLesson($context);
        app(LessonExperienceService::class)->provisionMapMissionPrototype($lesson);
        $blankMap = $lesson->resources()->where('resource_type', 'blank_map')->firstOrFail();

        $result = app(LessonResourceFulfillmentManager::class)->fulfill($blankMap);

        $this->assertSame('unavailable', $result->availability_status);
        $this->assertNull($result->asset_path);
        $this->assertNotNull($result->fulfillment_error);
        $this->actingIn($context['user'], $context['tenant'])
            ->get(route('lesson-plans.lessons.resources.show', [$plan, $lesson, $result]))->assertNotFound();
    }

    public function test_interactive_resource_is_private_and_does_not_change_other_lessons_or_provenance(): void
    {
        Storage::fake('local');
        $context = $this->context();
        [$plan, $lesson] = $this->mapLesson($context);
        $other = $plan->lessons()->create([
            'curriculum_unit_id' => $context['unit']->id, 'sequence' => 2, 'title' => 'Another Lesson',
            'status' => 'draft', 'learning_objective' => 'Keep this lesson unchanged.',
        ]);
        $before = [
            'lesson' => $lesson->only(['curriculum_unit_id', 'title', 'learning_objective', 'completion_criteria']),
            'components' => $lesson->curriculumComponents()->pluck('curriculum_unit_components.id')->all(),
            'standards' => $lesson->standardAlignments()->pluck('curriculum_unit_standard_alignments.id')->all(),
            'other' => $other->only(['curriculum_unit_id', 'sequence', 'title', 'status', 'learning_objective']),
        ];
        app(LessonExperienceService::class)->provisionMapMissionPrototype($lesson);
        $interactive = $lesson->resources()->where('resource_type', 'interactive_us_map')->sole();
        $contents = json_encode(['type' => 'FeatureCollection', 'features' => []]);
        Storage::disk('local')->put('lesson-resources/states.geojson', $contents);
        $interactive->update([
            'availability_status' => 'ready', 'asset_disk' => 'local', 'asset_path' => 'lesson-resources/states.geojson',
            'original_filename' => 'states.geojson', 'mime_type' => 'application/geo+json',
            'checksum_sha256' => hash('sha256', $contents),
        ]);

        $this->actingIn($context['user'], $context['tenant'])
            ->get(route('lesson-plans.lessons.resources.show', [$plan, $lesson, $interactive]))
            ->assertOk()->assertHeader('content-type', 'application/geo+json');
        $studentUser = User::factory()->create(['must_change_password' => false]);
        TenantMembership::create(['tenant_id' => $context['tenant']->id, 'user_id' => $studentUser->id, 'role' => 'student', 'status' => 'active']);
        $context['enrollment']->student->update(['user_id' => $studentUser->id, 'student_access_enabled_at' => now()]);
        $this->actingIn($studentUser, $context['tenant'])
            ->get(route('student.lessons.resources.show', [$lesson, $interactive]))->assertNotFound();
        [$otherUser, $otherTenant] = $this->adult('Other Interactive Tenant');
        $this->actingIn($otherUser, $otherTenant)
            ->get(route('lesson-plans.lessons.resources.show', [$plan, $lesson, $interactive]))->assertNotFound();

        $this->setContext($context['user'], $context['tenant']);
        $lesson->refresh();
        $this->assertSame($before['lesson'], $lesson->only(array_keys($before['lesson'])));
        $this->assertSame($before['components'], $lesson->curriculumComponents()->pluck('curriculum_unit_components.id')->all());
        $this->assertSame($before['standards'], $lesson->standardAlignments()->pluck('curriculum_unit_standard_alignments.id')->all());
        $this->assertSame($before['other'], $other->fresh()->only(array_keys($before['other'])));
        $this->assertDatabaseMissing('lesson_resources', ['lesson_id' => $other->id]);
    }

    public function test_incomplete_automatic_and_project_responses_are_rejected(): void
    {
        $context = $this->context();
        [, $lesson] = $this->mapLesson($context);
        $service = app(LessonExperienceService::class);
        $experience = $service->provisionMapMissionPrototype($lesson);
        $progress = $service->progress($experience, $context['enrollment'], true, $context['user']);
        $activities = $experience->activities()->get()->keyBy('sequence');
        $wrong = $service->respond($progress, $activities[4], ['matches' => ['title' => 'subject']]);
        $this->assertSame('in_progress', $wrong->responses->firstWhere('lesson_activity_id', $activities[4]->id)->status);
        $this->assertFalse($wrong->responses->firstWhere('lesson_activity_id', $activities[4]->id)->is_correct);
        $this->expectValidation(fn () => $service->respond($progress, $activities[5], ['symbol_meaning' => 'invented_option', 'relative_location' => 'Texas is west of Florida.', 'limitation' => 'no_population']), 'response');
        foreach (['title', 'orientation', 'symbols', 'legend', 'labels', 'reflections'] as $missing) {
            $response = $this->digitalMapResponse();
            if ($missing === 'title') $response['map']['title'] = '';
            if ($missing === 'orientation') $response['map']['show_orientation'] = false;
            if ($missing === 'symbols') array_pop($response['map']['features']);
            if ($missing === 'legend') $response['map']['features'][0]['legend_label'] = '';
            if ($missing === 'labels') $response['map']['features'][1]['state_fips'] = $response['map']['features'][0]['state_fips'];
            if ($missing === 'reflections') $response['reflections']['information_shown'] = '';
            $this->expectValidation(fn () => $service->respond($progress, $activities[6], $response), 'response');
        }
    }

    public function test_digital_map_builder_draft_persists_and_restores_without_advancing(): void
    {
        $context = $this->context();
        [$plan, $lesson] = $this->mapLesson($context);
        $service = app(LessonExperienceService::class);
        $experience = $service->provisionMapMissionPrototype($lesson);
        $progress = $service->progress($experience, $context['enrollment'], true, $context['user']);
        $activity = $experience->activities()->where('sequence', 6)->firstOrFail();
        $partial = $this->digitalMapResponse();
        $partial['map']['title'] = 'Kai’s Saved Explorer Map';
        $partial['map']['show_orientation'] = false;
        $partial['map']['features'][1] = ['state_fips' => '', 'marker_key' => '', 'legend_label' => ''];
        $partial['map']['features'][2] = ['state_fips' => '', 'marker_key' => '', 'legend_label' => ''];
        $partial['reflections'] = ['information_shown' => '', 'symbol_explanation' => '', 'relative_location' => ''];

        $this->actingIn($context['user'], $context['tenant'])
            ->postJson(route('lesson-plans.lessons.experience-preview.draft', [$plan, $lesson, $progress, $activity]), ['response' => $partial])
            ->assertOk()->assertJson(['saved' => true]);

        $saved = $progress->responses()->where('lesson_activity_id', $activity->id)->firstOrFail();
        $this->assertSame('in_progress', $saved->status);
        $this->assertSame($partial, $saved->response);
        $this->assertSame('in_progress', $progress->fresh()->status);
        $this->assertSame($experience->activities()->where('sequence', 1)->value('id'), $progress->fresh()->current_activity_id);
        $this->actingIn($context['user'], $context['tenant'])
            ->get(route('lesson-plans.lessons.experience-preview', [$plan, $lesson]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('activities.5.saved_response.map.title', 'Kai’s Saved Explorer Map')
                ->where('activities.5.saved_response.map.show_orientation', false)
                ->where('activities.5.response_status', 'in_progress')
                ->where('activities.5.draft_url', route('lesson-plans.lessons.experience-preview.draft', [$plan, $lesson, $progress, $activity])));
        $this->assertDatabaseHas('audit_logs', ['action' => 'student-activity-response.draft-saved', 'auditable_id' => (string) $saved->id]);
        $stepSix = $activity->only(['student_instructions', 'content', 'interaction_data']);
        $this->assertStringNotContainsStringIgnoringCase('paper', json_encode($stepSix));
        $this->assertStringNotContainsStringIgnoringCase('pencil', json_encode($stepSix));
        $this->assertStringNotContainsStringIgnoringCase('ruler', json_encode($stepSix));
        $this->assertStringNotContainsStringIgnoringCase('print', json_encode($stepSix));
    }

    public function test_lesson_two_regions_experience_is_digital_persistent_and_preserves_provenance(): void
    {
        $context = $this->context();
        [$plan, $lesson] = $this->regionsLesson($context);
        $before = [
            'lesson' => $lesson->only(['curriculum_unit_id', 'lesson_mode', 'status', 'learning_objective', 'completion_criteria']),
            'components' => $lesson->curriculumComponents()->pluck('curriculum_unit_components.id')->all(),
            'standards' => $lesson->standardAlignments()->pluck('curriculum_unit_standard_alignments.id')->all(),
        ];
        $service = app(LessonExperienceService::class);
        $experience = $service->provisionRegionsMissionPrototype($lesson);
        $activities = $experience->activities()->get()->keyBy('sequence');

        $this->assertCount(7, $activities);
        $this->assertSame(range(1, 7), $activities->pluck('sequence')->values()->all());
        $this->assertTrue($activities->every(fn ($activity) => $activity->sourceSection->lesson_id === $lesson->id));
        $this->assertSame(['interactive_us_map', 'physical_us_map'], $lesson->resources()->pluck('resource_type')->all());
        $this->assertSame(['needs_asset', 'needs_asset'], $lesson->resources()->pluck('availability_status')->all());
        $delivery = json_encode($activities->map->only(['student_instructions', 'content', 'interaction_data'])->all());
        foreach (['paper', 'pencil', 'ruler', 'print', 'atlas', 'search the internet', 'attach asset'] as $dependency) {
            $this->assertStringNotContainsStringIgnoringCase($dependency, $delivery);
        }

        $progress = $service->progress($experience, $context['enrollment'], true, $context['user']);
        $partial = $this->regionMapResponse();
        $partial['map']['regions'][2]['state_fips'][1] = '';
        $partial['reflections']['boundary_evidence'] = '';
        $service->saveDraft($progress, $activities[6], $partial);
        $this->assertSame($partial, $progress->responses()->where('lesson_activity_id', $activities[6]->id)->firstOrFail()->response);
        $this->expectValidation(fn () => $service->respond($progress, $activities[6], $partial), 'response');

        $progress = $service->respond($progress, $activities[1], ['acknowledged' => true]);
        $progress = $service->respond($progress, $activities[2], ['matches' => [
            'rocky_mountains' => 'physical', 'great_plains' => 'physical', 'great_lakes' => 'physical',
            'texas_oklahoma' => 'political', 'california' => 'political', 'washington_dc' => 'political',
        ]]);
        $progress = $service->respond($progress, $activities[3], ['acknowledged' => true]);
        $progress = $service->respond($progress, $activities[4], ['selected' => 'south_legend']);
        $wrong = $service->respond($progress, $activities[5], ['answers' => ['high_relief' => 'great_plains', 'shared_boundary' => 'state_boundary', 'criterion_change' => 'can_change']]);
        $this->assertSame($activities[5]->id, $wrong->current_activity_id);
        $this->assertSame('in_progress', $wrong->responses->firstWhere('lesson_activity_id', $activities[5]->id)->status);
        $progress = $service->respond($wrong, $activities[5], ['answers' => ['high_relief' => 'rockies', 'shared_boundary' => 'state_boundary', 'criterion_change' => 'can_change']]);
        $progress = $service->respond($progress, $activities[6], $this->regionMapResponse());
        $this->assertSame($activities[7]->id, $progress->current_activity_id);
        $this->assertSame('submitted', $progress->responses->firstWhere('lesson_activity_id', $activities[6]->id)->status);
        $progress = $service->respond($progress, $activities[7], ['answers' => ['physical' => 'mountain', 'political' => 'state', 'region' => 'criterion_evidence']]);
        $this->assertSame('completed', $progress->status);

        $lesson->refresh();
        $this->assertSame($before['lesson'], $lesson->only(array_keys($before['lesson'])));
        $this->assertSame($before['components'], $lesson->curriculumComponents()->pluck('curriculum_unit_components.id')->all());
        $this->assertSame($before['standards'], $lesson->standardAlignments()->pluck('curriculum_unit_standard_alignments.id')->all());
        $this->actingIn($context['user'], $context['tenant'])
            ->get(route('lesson-plans.lessons.experience-preview', [$plan, $lesson]))->assertOk();
    }

    public function test_lesson_three_settlement_experience_is_digital_persistent_and_preserves_provenance(): void
    {
        $context = $this->context();
        [$plan, $lesson] = $this->settlementLesson($context);
        $before = [
            'lesson' => $lesson->only(['curriculum_unit_id', 'lesson_mode', 'status', 'learning_objective', 'completion_criteria']),
            'components' => $lesson->curriculumComponents()->pluck('curriculum_unit_components.id')->all(),
            'standards' => $lesson->standardAlignments()->pluck('curriculum_unit_standard_alignments.id')->all(),
        ];
        $service = app(LessonExperienceService::class);
        $experience = $service->provisionSettlementMissionPrototype($lesson);
        $activities = $experience->activities()->get()->keyBy('sequence');

        $this->assertCount(7, $activities);
        $this->assertSame(range(1, 7), $activities->pluck('sequence')->values()->all());
        $this->assertTrue($activities->every(fn ($activity) => $activity->sourceSection->lesson_id === $lesson->id));
        $this->assertSame(['interactive_us_map', 'us_population_density_data', 'physical_us_map'], $lesson->resources()->pluck('resource_type')->all());
        $delivery = json_encode($activities->map->only(['student_instructions', 'content', 'interaction_data'])->all());
        foreach (['paper', 'pencil', 'ruler', 'print', 'atlas', 'search the internet', 'attach asset'] as $dependency) {
            $this->assertStringNotContainsStringIgnoringCase($dependency, $delivery);
        }

        $progress = $service->progress($experience, $context['enrollment'], true, $context['user']);
        $partial = $this->settlementAnalysisResponse();
        $partial['analysis']['patterns'][1] = '';
        $service->saveDraft($progress, $activities[6], $partial);
        $this->assertSame($partial, $progress->responses()->where('lesson_activity_id', $activities[6]->id)->firstOrFail()->response);
        $this->expectValidation(fn () => $service->respond($progress, $activities[6], $partial), 'response');

        $progress = $service->respond($progress, $activities[1], ['acknowledged' => true]);
        $progress = $service->respond($progress, $activities[2], ['acknowledged' => true]);
        $progress = $service->respond($progress, $activities[3], ['matches' => ['visible_value' => 'observation', 'comparison' => 'pattern', 'possible_cause' => 'inference']]);
        $wrong = $service->respond($progress, $activities[4], ['selected' => 'jobs']);
        $this->assertSame($activities[4]->id, $wrong->current_activity_id);
        $progress = $service->respond($wrong, $activities[4], ['selected' => 'density']);
        $progress = $service->respond($progress, $activities[5], ['answers' => ['highest' => 'new_york', 'lowest' => 'wyoming', 'claim' => 'possible']]);
        $progress = $service->respond($progress, $activities[6], $this->settlementAnalysisResponse());
        $this->assertSame($activities[7]->id, $progress->current_activity_id);
        $this->assertSame('submitted', $progress->responses->firstWhere('lesson_activity_id', $activities[6]->id)->status);
        $progress = $service->respond($progress, $activities[7], ['answers' => ['observation' => 'ny_wy', 'inference' => 'may', 'limitation' => 'more']]);
        $this->assertSame('completed', $progress->status);

        $lesson->refresh();
        $this->assertSame($before['lesson'], $lesson->only(array_keys($before['lesson'])));
        $this->assertSame($before['components'], $lesson->curriculumComponents()->pluck('curriculum_unit_components.id')->all());
        $this->assertSame($before['standards'], $lesson->standardAlignments()->pluck('curriculum_unit_standard_alignments.id')->all());
        $this->actingIn($context['user'], $context['tenant'])
            ->get(route('lesson-plans.lessons.experience-preview', [$plan, $lesson]))
            ->assertInertia(fn (Assert $page) => $page->where('activities.5.saved_response.analysis.inference', 'Access to transportation might influence the pattern.')->where('activities.5.draft_url', route('lesson-plans.lessons.experience-preview.draft', [$plan, $lesson, $progress, $activities[6]])));
    }

    public function test_science_lesson_one_experience_is_digital_persistent_and_uses_authoritative_evidence(): void
    {
        $context = $this->context();
        [$plan, $lesson] = $this->scienceLesson($context);
        Http::fake([
            '*Big%20Hickory%20Beach%20Before*' => Http::response($this->testJpeg(), 200, ['Content-Type' => 'image/jpeg']),
            '*Big%20Hickory%20Beach%20After*' => Http::response($this->testJpeg(), 200, ['Content-Type' => 'image/jpeg']),
        ]);
        $service = app(LessonExperienceService::class);
        $experience = $service->provisionEarthProcessesMissionPrototype($lesson);
        app(LessonResourceFulfillmentManager::class)->fulfillRequiredForLesson($lesson);
        $activities = $experience->activities()->get()->keyBy('sequence');

        $this->assertCount(7, $activities);
        $this->assertSame(range(1, 7), $activities->pluck('sequence')->values()->all());
        $this->assertSame(['ready'], $lesson->resources()->where('category', 'lesson_resource')->pluck('availability_status')->unique()->values()->all());
        $this->assertCount(1, $lesson->standardAlignments);
        $delivery = json_encode($activities->map->only(['student_instructions', 'content', 'interaction_data'])->all());
        foreach (['paper', 'pencil', 'ruler', 'print', 'search the internet', 'attach asset'] as $dependency) {
            $this->assertStringNotContainsStringIgnoringCase($dependency, $delivery);
        }

        $progress = $service->progress($experience, $context['enrollment'], true, $context['user']);
        $partial = $this->systemsMapResponse();
        $partial['systems_map']['question'] = '';
        $service->saveDraft($progress, $activities[6], $partial);
        $this->assertSame($partial, $progress->responses()->where('lesson_activity_id', $activities[6]->id)->firstOrFail()->response);
        $this->expectValidation(fn () => $service->respond($progress, $activities[6], $partial), 'response');

        $progress = $service->respond($progress, $activities[1], ['acknowledged' => true]);
        $wrong = $service->respond($progress, $activities[2], ['answers' => ['visible' => 'wind_speed', 'explanation' => 'two_images']]);
        $this->assertSame($activities[2]->id, $wrong->current_activity_id);
        $progress = $service->respond($wrong, $activities[2], ['answers' => ['visible' => 'sand', 'explanation' => 'water_moved']]);
        $progress = $service->respond($progress, $activities[3], ['acknowledged' => true]);
        $progress = $service->respond($progress, $activities[4], ['matches' => ['loosen' => 'weathering', 'carry' => 'erosion', 'drop' => 'deposition']]);
        $progress = $service->respond($progress, $activities[5], ['answers' => ['dune' => 'wind', 'glacier' => 'ice', 'delta' => 'deposition', 'rock' => 'sedimentary']]);
        $progress = $service->respond($progress, $activities[6], $this->systemsMapResponse());
        $this->assertSame($activities[7]->id, $progress->current_activity_id);
        $progress = $service->respond($progress, $activities[7], ['connection_explanation' => 'Moving water carries sediment from one place to another.']);
        $this->assertSame('completed', $progress->status);
        $this->assertSame('draft', $lesson->fresh()->status);
        $this->assertSame('preview', $experience->fresh()->status);
    }

    public function test_science_lessons_two_and_three_are_structured_persistent_and_resource_complete(): void
    {
        Storage::fake('local');
        $context = $this->context();
        [$plan, $water] = $this->scienceSequenceLesson($context, 2);
        [, $weather] = $this->scienceSequenceLesson($context, 3, $plan);
        $service = app(LessonExperienceService::class);

        $waterExperience = $service->provisionWaterCycleMissionPrototype($water);
        $weatherExperience = $service->provisionWeatherInteractionsMissionPrototype($weather);
        app(LessonResourceFulfillmentManager::class)->fulfillRequiredForLesson($water);
        app(LessonResourceFulfillmentManager::class)->fulfillRequiredForLesson($weather);
        $this->assertSame(range(1, 7), $waterExperience->activities->pluck('sequence')->values()->all());
        $this->assertSame(range(1, 7), $weatherExperience->activities->pluck('sequence')->values()->all());
        $this->assertTrue($waterExperience->activities->every(fn ($activity) => $activity->sourceSection->lesson_id === $water->id));
        $this->assertTrue($weatherExperience->activities->every(fn ($activity) => $activity->sourceSection->lesson_id === $weather->id));
        $this->assertSame(['ready'], $water->resources()->where('category', 'lesson_resource')->pluck('availability_status')->unique()->values()->all());
        $this->assertSame(['ready'], $weather->resources()->where('category', 'lesson_resource')->pluck('availability_status')->unique()->values()->all());
        $this->assertSame(['Clear bowl', 'Warm water', 'Plastic wrap and rubber band', 'Ice cubes', 'Towel'], $water->resources()->where('category', 'special_material')->get()->filter(fn ($item) => data_get($item->metadata, 'student_experience_required'))->pluck('title')->values()->all());
        $this->assertSame(['Two matching saucers and water', 'Dropper or teaspoon'], $weather->resources()->where('category', 'special_material')->get()->filter(fn ($item) => data_get($item->metadata, 'student_experience_required'))->pluck('title')->values()->all());

        $waterActivities = $waterExperience->activities()->get()->keyBy('sequence');
        $progress = $service->progress($waterExperience, $context['enrollment'], true, $context['user']);
        $partial = ['science_work' => array_fill_keys(['beginning_observation', 'ending_observation', 'droplet_evidence', 'evaporation_evidence', 'condensation_evidence', 'model_limitation'], '')];
        $partial['science_work']['beginning_observation'] = 'Warm water is visible in the bowl.';
        $service->saveDraft($progress, $waterActivities[5], $partial);
        $this->assertSame($partial, $progress->responses()->where('lesson_activity_id', $waterActivities[5]->id)->firstOrFail()->response);
        $this->expectValidation(fn () => $service->respond($progress, $waterActivities[5], $partial), 'response');
        $progress = $service->respond($progress, $waterActivities[1], ['return_to_atmosphere' => 'It may rise into the air.', 'energy_source' => 'The Sun may add energy.']);
        $progress = $service->respond($progress, $waterActivities[2], ['acknowledged' => true]);
        $progress = $service->respond($progress, $waterActivities[3], ['acknowledged' => true]);
        $progress = $service->respond($progress, $waterActivities[4], ['droplet_location' => 'Under the cold plastic wrap.', 'process_prediction' => 'Cooling may cause condensation.']);
        $progress = $service->respond($progress, $waterActivities[5], ['science_work' => [
            'beginning_observation' => 'Warm water and clear plastic are visible at the start.', 'ending_observation' => 'The inside becomes misty and droplets form.',
            'droplet_evidence' => 'Droplets form under the wrap and some fall.', 'evaporation_evidence' => 'Liquid water leaves the warm surface as invisible vapor.',
            'condensation_evidence' => 'Droplets appear where vapor meets the cold wrap.', 'model_limitation' => 'The bowl has no land, river, or full atmosphere.',
        ]]);
        $wrong = $service->respond($progress, $waterActivities[6], ['answers' => ['evaporation' => 'collection', 'condensation' => 'condensation', 'falling' => 'precipitation', 'limitation' => 'small_model']]);
        $this->assertSame($waterActivities[6]->id, $wrong->current_activity_id);
        $progress = $service->respond($wrong, $waterActivities[6], ['answers' => ['evaporation' => 'evaporation', 'condensation' => 'condensation', 'falling' => 'precipitation', 'limitation' => 'small_model']]);
        $progress = $service->respond($progress, $waterActivities[7], ['science_work' => [
            'upward_arrow' => 'evaporation', 'cloud_droplets' => 'condensation', 'downward_water' => 'precipitation', 'stored_water' => 'collection', 'energy_source' => 'sun',
            'cycle_explanation' => 'Solar energy causes evaporation from the ocean. Vapor cools and condenses, precipitation falls, and water collects before moving again.',
        ]]);
        $this->assertSame('completed', $progress->status);

        $weatherActivities = $weatherExperience->activities()->get()->keyBy('sequence');
        $weatherProgress = $service->progress($weatherExperience, $context['enrollment'], true, $context['user']);
        $draft = ['science_work' => array_fill_keys(['warm_location', 'cool_location', 'controls', 'warm_start', 'cool_start', 'warm_ten', 'cool_ten', 'warm_twenty', 'cool_twenty', 'cause_effect', 'limitation'], '')];
        $draft['science_work']['warm_location'] = 'Near a sunny window';
        $service->saveDraft($weatherProgress, $weatherActivities[4], $draft);
        $this->assertSame($draft, $weatherProgress->responses()->where('lesson_activity_id', $weatherActivities[4]->id)->firstOrFail()->response);
        $this->expectValidation(fn () => $service->respond($weatherProgress, $weatherActivities[4], $draft), 'response');
        $weatherProgress = $service->respond($weatherProgress, $weatherActivities[1], ['acknowledged' => true]);
        $weatherProgress = $service->respond($weatherProgress, $weatherActivities[2], ['matches' => ['humidity' => 'humidity', 'cloud' => 'cloud', 'precipitation' => 'precipitation', 'water_vapor' => 'water_vapor']]);
        $weatherProgress = $service->respond($weatherProgress, $weatherActivities[3], ['acknowledged' => true]);
        $weatherProgress = $service->respond($weatherProgress, $weatherActivities[4], ['science_work' => [
            'warm_location' => 'Near a sunny indoor window', 'cool_location' => 'On a shaded indoor table', 'controls' => 'Matching saucers, equal drops, and the same times',
            'warm_start' => 'full', 'cool_start' => 'full', 'warm_ten' => 'smaller', 'cool_ten' => 'full', 'warm_twenty' => 'gone', 'cool_twenty' => 'smaller',
            'cause_effect' => 'The warmer drop disappeared faster, showing that added energy increased evaporation.', 'limitation' => 'Two saucers do not reproduce the scale or moving air of an ocean.',
        ]]);
        $weatherProgress = $service->respond($weatherProgress, $weatherActivities[5], ['answers' => ['highest_air' => 'd1_4', 'before_rain' => 'rise', 'evidence' => 'values', 'limitation' => 'short']]);
        $weatherProgress = $service->respond($weatherProgress, $weatherActivities[6], ['science_work' => [
            'claim' => 'Increasing humidity and cloud cover occurred before precipitation appeared.', 'evidence_one' => 'Day 2 noon had 82% humidity and 85% cloud cover.',
            'evidence_two' => 'Day 2 at 8 p.m. had 90% humidity and 5.8 mm precipitation.', 'reasoning' => 'Cooling vapor can condense into cloud droplets that grow and later fall as precipitation.',
            'limitation' => 'Two days cannot prove this pattern always causes rain.',
        ]]);
        $weatherProgress = $service->respond($weatherProgress, $weatherActivities[7], ['answers' => ['supported' => 'humidity_clouds', 'unsupported' => 'next_week', 'interaction' => 'condense']]);
        $this->assertSame('completed', $weatherProgress->status);
        $this->assertSame('draft', $water->fresh()->status);
        $this->assertSame('draft', $weather->fresh()->status);
        $this->assertSame([$context['component']->id], $water->curriculumComponents()->pluck('curriculum_unit_components.id')->all());
        $this->assertSame([$context['alignment']->id], $weather->standardAlignments()->pluck('curriculum_unit_standard_alignments.id')->all());
    }

    public function test_math_lesson_one_is_first_exposure_digital_persistent_and_instructional(): void
    {
        Storage::fake('local');
        $context = $this->context();
        [$plan, $lesson] = $this->mathProblemSolvingLesson($context);
        $before = ['lesson' => $lesson->only(['title', 'learning_objective', 'completion_criteria', 'estimated_minutes', 'lesson_mode', 'status']), 'components' => [], 'alignments' => [$context['alignment']->id]];
        $service = app(LessonExperienceService::class);
        $experience = $service->provisionMathProblemSolvingPrototype($lesson);
        app(LessonResourceFulfillmentManager::class)->fulfillRequiredForLesson($lesson);
        $activities = $experience->activities()->get()->keyBy('sequence');
        $this->assertSame(range(1, 10), $activities->pluck('sequence')->values()->all());
        $this->assertSame(['Meet the Five-Part Routine', 'Analyze the Bus Problem', 'Plan the Bus Solution', 'Solve the Bus Capacity', 'Justify and Check the Bus Answer'], $activities->take(5)->pluck('display_title')->values()->all());
        $beforeVocabulary = json_encode($activities->take(6)->map->only(['display_title', 'student_instructions', 'content', 'interaction_data', 'feedback'])->all());
        $this->assertStringNotContainsStringIgnoringCase('capacity bounds', $beforeVocabulary);
        $this->assertSame('Name the Check: Capacity Bounds', $activities[7]->display_title);
        $this->assertStringContainsString('nearby amounts that help us check', $activities[7]->content);
        $this->assertTrue($activities->every(fn ($activity) => $activity->sourceSection->lesson_id === $lesson->id));
        $this->assertSame(['ready'], $lesson->resources()->where('category', 'lesson_resource')->pluck('availability_status')->unique()->values()->all());
        $delivery = json_encode($activities->map->only(['student_instructions', 'content', 'interaction_data'])->all());
        foreach (['work on paper', 'pencil', 'print the', 'outside website', 'ask a parent'] as $dependency) $this->assertStringNotContainsStringIgnoringCase($dependency, $delivery);
        $this->assertTrue($lesson->resources()->where('category', 'student_supply')->get()->every(fn ($resource) => data_get($resource->metadata, 'student_experience_required') === false));
        $this->assertTrue($lesson->resources()->where('category', 'lesson_resource')->get()->every(fn ($resource) => data_get($resource->metadata, 'optional_teacher_fallback') === true && data_get($resource->metadata, 'student_experience_required') === false));
        $this->assertSame(0, $lesson->fresh()->estimated_preparation_minutes);
        $this->assertStringContainsString('No ordinary parent preparation is required', $lesson->allSections()->where('section_type', 'teacher_preparation')->firstOrFail()->content);
        $this->assertStringContainsString('type="number"', file_get_contents(resource_path('js/Components/Lessons/MathWorkBuilder.vue')));

        $progress = $service->progress($experience, $context['enrollment'], true, $context['user']);
        $guided = $this->guidedMathResponse();
        $partial = $guided; $partial['math_work']['nine_enough'] = '';
        $service->saveDraft($progress, $activities[6], $partial);
        $this->assertSame($partial, $progress->responses()->where('lesson_activity_id', $activities[6]->id)->firstOrFail()->response);
        $this->expectValidation(fn () => $service->respond($progress, $activities[6], $partial), 'response');
        $progress = $service->respond($progress, $activities[1], ['acknowledged' => true]);
        $wrong = $service->respond($progress, $activities[2], ['answers' => ['quantities' => '187_4', 'units' => 'buses', 'asked' => 'least_buses']]);
        $this->assertSame($activities[2]->id, $wrong->current_activity_id);
        $this->assertStringContainsString('total number of people', $wrong->responses->firstWhere('lesson_activity_id', $activities[2]->id)->feedback);
        $progress = $service->respond($wrong, $activities[2], ['answers' => ['quantities' => '187_48', 'units' => 'buses', 'asked' => 'least_buses']]);
        $wrong = $service->respond($progress, $activities[3], ['selected' => 'add_once']);
        $this->assertStringContainsString('equal groups of 48', $wrong->responses->firstWhere('lesson_activity_id', $activities[3]->id)->feedback);
        $progress = $service->respond($wrong, $activities[3], ['selected' => 'nearby_multiples']);
        $wrong = $service->respond($progress, $activities[4], ['math_work' => ['three_capacity' => '143', 'four_capacity' => '192']]);
        $this->assertSame($activities[4]->id, $wrong->current_activity_id);
        $progress = $service->respond($wrong, $activities[4], ['math_work' => ['three_capacity' => '144', 'four_capacity' => '192']]);
        $wrong = $service->respond($progress, $activities[5], ['answers' => ['remainder' => 'people_without_seats', 'answer' => 'three', 'check' => 'bounds']]);
        $this->assertStringContainsString('Would 3 buses hold 187 people?', $wrong->responses->firstWhere('lesson_activity_id', $activities[5]->id)->feedback);
        $progress = $service->respond($wrong, $activities[5], ['answers' => ['remainder' => 'people_without_seats', 'answer' => 'four', 'check' => 'bounds']]);
        $wrongGuided = $guided; $wrongGuided['math_work']['eight_capacity'] = '300';
        $wrong = $service->respond($progress, $activities[6], $wrongGuided);
        $this->assertSame($activities[6]->id, $wrong->current_activity_id);
        $this->assertStringContainsString('40 × 8', $wrong->responses->firstWhere('lesson_activity_id', $activities[6]->id)->feedback);
        $progress = $service->respond($wrong, $activities[6], $guided);
        $progress = $service->respond($progress, $activities[7], ['acknowledged' => true]);
        $wrong = $service->respond($progress, $activities[8], ['math_work' => ['decision' => 'buy_8', 'reasoning' => 'Eight packs leave five sheets uncovered, but I chose eight.']]);
        $this->assertSame($activities[8]->id, $wrong->current_activity_id);
        $this->assertStringContainsString('cannot be ignored', $wrong->responses->firstWhere('lesson_activity_id', $activities[8]->id)->feedback);
        $progress = $service->respond($wrong, $activities[8], $this->packExplanationResponse());
        $progress = $service->respond($progress, $activities[9], $this->independentMathResponse());
        $this->assertSame($activities[10]->id, $progress->current_activity_id);
        $progress = $service->respond($progress, $activities[10], ['answers' => ['lower' => 'short', 'upper' => 'enough', 'least' => 'bounds']]);
        $this->assertSame('completed', $progress->status);
        $this->assertSame($before['lesson'], $lesson->fresh()->only(array_keys($before['lesson'])));
        $this->assertSame($before['components'], $lesson->curriculumComponents()->pluck('curriculum_unit_components.id')->all());
        $this->assertSame($before['alignments'], $lesson->standardAlignments()->pluck('curriculum_unit_standard_alignments.id')->all());
        $this->assertSame('preview', $experience->fresh()->status);
        $this->assertSame('draft', $plan->fresh()->status);
    }

    public function test_math_lesson_two_teaches_and_persists_connected_representations(): void
    {
        Storage::fake('local');
        $context = $this->context();
        [$plan, $lesson] = $this->mathRepresentationsLesson($context);
        $before = ['lesson' => $lesson->only(['title', 'learning_objective', 'completion_criteria', 'estimated_minutes', 'lesson_mode', 'status']), 'components' => [], 'alignments' => [$context['alignment']->id]];
        $service = app(LessonExperienceService::class);
        $experience = $service->provisionMathRepresentationsPrototype($lesson);
        app(LessonResourceFulfillmentManager::class)->fulfillRequiredForLesson($lesson);
        $activities = $experience->activities()->get()->keyBy('sequence');
        $this->assertSame(range(1, 8), $activities->pluck('sequence')->values()->all());
        $this->assertSame(['Tools Help Us See Relationships', 'Match Each Representation to Its Job', 'Worked Example: Pantry Shelves', 'Connect the Pantry Model and Equations'], $activities->take(4)->pluck('display_title')->values()->all());
        $this->assertTrue($activities->every(fn ($activity) => $activity->sourceSection->lesson_id === $lesson->id));
        $lessonResources = $lesson->resources()->where('category', 'lesson_resource')->get();
        $this->assertTrue($lessonResources->every(fn ($resource) => $resource->availability_status === 'ready' && data_get($resource->metadata, 'student_experience_required') === false), $lessonResources->map->only(['title', 'availability_status', 'fulfillment_error', 'metadata'])->toJson());
        $this->assertTrue($lesson->resources()->where('category', 'student_supply')->get()->every(fn ($resource) => data_get($resource->metadata, 'student_experience_required') === false));
        $this->assertSame(0, $lesson->fresh()->estimated_preparation_minutes);
        $delivery = json_encode($activities->map->only(['student_instructions', 'content', 'interaction_data'])->all());
        foreach (['print', 'paper', 'pencil', 'ruler', 'outside website', 'ask a parent'] as $dependency) $this->assertStringNotContainsStringIgnoringCase($dependency, $delivery);

        $progress = $service->progress($experience, $context['enrollment'], true, $context['user']);
        $progress = $service->respond($progress, $activities[1], ['acknowledged' => true]);
        $progress = $service->respond($progress, $activities[2], ['matches' => ['estimate' => 'benchmark', 'equation' => 'operations', 'table' => 'organize', 'bar' => 'parts']]);
        $progress = $service->respond($progress, $activities[3], ['acknowledged' => true]);
        $progress = $service->respond($progress, $activities[4], ['answers' => ['whole' => 'all_cans', 'sections' => 'shelves', 'efficient' => 'crates_per_shelf']]);
        $guided = $this->guidedRepresentationResponse();
        $partial = $guided; $partial['math_work']['connection'] = '';
        $service->saveDraft($progress, $activities[5], $partial);
        $this->assertSame($partial, $progress->responses()->where('lesson_activity_id', $activities[5]->id)->firstOrFail()->response);
        $wrong = $guided; $wrong['math_work']['teams_per_shelf'] = '8';
        $retry = $service->respond($progress, $activities[5], $wrong);
        $this->assertSame($activities[5]->id, $retry->current_activity_id);
        $this->assertStringContainsString('16 ÷ 8 = 2', $retry->responses->firstWhere('lesson_activity_id', $activities[5]->id)->feedback);
        $progress = $service->respond($retry, $activities[5], $guided);
        $progress = $service->respond($progress, $activities[6], ['answers' => ['same' => 'same_quantities', 'efficient' => 'small_relationship']]);
        $independent = $this->independentRepresentationResponse();
        $wrong = $independent; $wrong['math_work']['answer'] = '4';
        $retry = $service->respond($progress, $activities[7], $wrong);
        $this->assertSame($activities[7]->id, $retry->current_activity_id);
        $progress = $service->respond($retry, $activities[7], $independent);
        $progress = $service->respond($progress, $activities[8], ['answers' => ['four' => 'rows', 'sixty' => 'plants']]);
        $this->assertSame('completed', $progress->status);
        $this->assertSame($before['lesson'], $lesson->fresh()->only(array_keys($before['lesson'])));
        $this->assertSame($before['components'], $lesson->curriculumComponents()->pluck('curriculum_unit_components.id')->all());
        $this->assertSame($before['alignments'], $lesson->standardAlignments()->pluck('curriculum_unit_standard_alignments.id')->all());
        $this->assertSame('preview', $experience->fresh()->status);
        $this->assertSame('draft', $plan->fresh()->status);
    }

    public function test_math_lesson_three_models_error_analysis_before_digital_revision(): void
    {
        Storage::fake('local');
        $context = $this->context();
        [$plan, $lesson] = $this->mathReasoningLesson($context);
        $before = ['lesson' => $lesson->only(['title', 'learning_objective', 'completion_criteria', 'estimated_minutes', 'lesson_mode', 'status']), 'components' => [], 'alignments' => [$context['alignment']->id]];
        $service = app(LessonExperienceService::class);
        $experience = $service->provisionMathReasoningPrototype($lesson);
        app(LessonResourceFulfillmentManager::class)->fulfillRequiredForLesson($lesson);
        $activities = $experience->activities()->get()->keyBy('sequence');
        $this->assertSame(range(1, 8), $activities->pluck('sequence')->values()->all());
        $this->assertSame(['What Makes an Argument Trustworthy?', 'Learn Notice–Test–Explain–Revise', 'Worked Example: The Ticket Claim'], $activities->take(3)->pluck('display_title')->values()->all());
        $lessonResources = $lesson->resources()->where('category', 'lesson_resource')->get();
        $this->assertTrue($lessonResources->every(fn ($resource) => $resource->availability_status === 'ready' && data_get($resource->metadata, 'optional_teacher_fallback') === true), $lessonResources->map->only(['title', 'availability_status', 'fulfillment_error', 'metadata'])->toJson());
        $this->assertSame(0, $lesson->fresh()->estimated_preparation_minutes);
        $delivery = json_encode($activities->map->only(['student_instructions', 'content', 'interaction_data'])->all());
        foreach (['print', 'paper', 'pencil', 'outside website', 'ask a parent'] as $dependency) $this->assertStringNotContainsStringIgnoringCase($dependency, $delivery);

        $progress = $service->progress($experience, $context['enrollment'], true, $context['user']);
        $progress = $service->respond($progress, $activities[1], ['acknowledged' => true]);
        $progress = $service->respond($progress, $activities[2], ['acknowledged' => true]);
        $progress = $service->respond($progress, $activities[3], ['acknowledged' => true]);
        $progress = $service->respond($progress, $activities[4], ['answers' => ['flaw' => 'full_group', 'correction' => 'thirteen']]);
        $retry = $service->respond($progress, $activities[5], ['answers' => ['supported' => 'b', 'evidence' => 'partials', 'b_flaw' => 'wrong_no_evidence']]);
        $this->assertSame($activities[5]->id, $retry->current_activity_id);
        $progress = $service->respond($retry, $activities[5], ['answers' => ['supported' => 'a', 'evidence' => 'partials', 'b_flaw' => 'wrong_no_evidence']]);
        $revision = $this->postcardRevisionResponse();
        $partial = $revision; $partial['math_work']['revision'] = '';
        $service->saveDraft($progress, $activities[6], $partial);
        $this->assertSame($partial, $progress->responses()->where('lesson_activity_id', $activities[6]->id)->firstOrFail()->response);
        $wrong = $revision; $wrong['math_work']['answer'] = '22';
        $retry = $service->respond($progress, $activities[6], $wrong);
        $this->assertSame($activities[6]->id, $retry->current_activity_id);
        $progress = $service->respond($retry, $activities[6], $revision);
        $argument = $this->snackArgumentResponse();
        $partial = $argument; $partial['math_work']['final'] = '';
        $service->saveDraft($progress, $activities[7], $partial);
        $this->assertSame($partial, $progress->responses()->where('lesson_activity_id', $activities[7]->id)->firstOrFail()->response);
        $wrong = $argument; $wrong['math_work']['check'] = '486';
        $retry = $service->respond($progress, $activities[7], $wrong);
        $this->assertSame($activities[7]->id, $retry->current_activity_id);
        $progress = $service->respond($retry, $activities[7], $argument);
        $progress = $service->respond($progress, $activities[8], ['clearest_part' => 'The multiplication check reconnects every snack bag.', 'future_check' => 'I will check labels, calculations, units, and whether the answer fits.']);
        $this->assertSame('completed', $progress->status);
        $this->assertSame($before['lesson'], $lesson->fresh()->only(array_keys($before['lesson'])));
        $this->assertSame($before['components'], $lesson->curriculumComponents()->pluck('curriculum_unit_components.id')->all());
        $this->assertSame($before['alignments'], $lesson->standardAlignments()->pluck('curriculum_unit_standard_alignments.id')->all());
        $this->assertSame('preview', $experience->fresh()->status);
        $this->assertSame('draft', $plan->fresh()->status);
    }

    private function settlementLesson(array $context): array
    {
        $plan = app(LessonPlanService::class)->createDraft($context['enrollment'], $context['import']);
        $lesson = $plan->lessons()->create([
            'curriculum_unit_id' => $context['unit']->id, 'sequence' => 3,
            'title' => 'Geographic Data and Settlement Patterns', 'lesson_mode' => 'full', 'status' => 'draft',
            'learning_objective' => 'Interpret geographic data to identify a settlement pattern and make a supported inference.',
            'completion_criteria' => 'Identify two patterns, cite evidence, and distinguish an observation from an inference.', 'estimated_minutes' => 60,
        ]);
        foreach ([
            ['teacher_preparation', 'Prepare data', 'teacher'], ['materials', 'Materials', 'shared'],
            ['hook', 'Settlement factors', 'shared'], ['direct_instruction', 'Evidence moves', 'shared'],
            ['example', 'Read actual maps', 'shared'], ['guided_practice', 'Compare evidence', 'student'],
            ['independent_practice', 'Analyze patterns', 'student'], ['exit_check', 'Check evidence', 'shared'],
        ] as $index => [$type, $content, $audience]) {
            $lesson->sections()->create(['section_type' => $type, 'sequence' => $index + 1, 'title' => $content, 'content' => $content, 'audience' => $audience]);
        }
        $lesson->curriculumComponents()->attach($context['component']->id, ['tenant_id' => $context['tenant']->id, 'role' => 'skill']);
        $lesson->standardAlignments()->attach($context['alignment']->id, ['tenant_id' => $context['tenant']->id]);
        return [$plan, $lesson];
    }

    private function scienceLesson(array $context): array
    {
        $plan = app(LessonPlanService::class)->createDraft($context['enrollment'], $context['import']);
        $lesson = $plan->lessons()->create([
            'curriculum_unit_id' => $context['unit']->id, 'sequence' => 1,
            'title' => 'Introducing Earth Processes as Connected Systems', 'lesson_mode' => 'full', 'status' => 'draft',
            'learning_objective' => 'Identify major Earth processes and describe how their parts interact.',
            'completion_criteria' => 'Build a systems map with five terms, three connections, and one question.', 'estimated_minutes' => 50,
        ]);
        foreach ([
            ['teacher_preparation', 'Preparation', 'teacher'], ['hook', 'Changing landscape', 'shared'],
            ['direct_instruction', 'Earth processes', 'shared'], ['example', 'Cause chain', 'teacher'],
            ['guided_practice', 'Sort and connect', 'student'], ['independent_practice', 'Build systems map', 'student'],
            ['exit_check', 'Explain a connection', 'shared'],
        ] as $index => [$type, $content, $audience]) {
            $lesson->sections()->create(['section_type' => $type, 'sequence' => $index + 1, 'title' => $content, 'content' => $content, 'audience' => $audience]);
        }
        $lesson->curriculumComponents()->attach($context['component']->id, ['tenant_id' => $context['tenant']->id, 'role' => 'concept', 'sequence' => 1]);
        foreach ([
            ['photograph', 'Changing Landscapes Photograph Set', 'viewable'],
            ['other', 'Earth Process Sorting Cards', 'printable'],
            ['graphic_organizer', 'Earth Processes Systems Map', 'printable'],
        ] as $index => [$type, $title, $delivery]) {
            $lesson->resources()->create(['category' => 'lesson_resource', 'resource_type' => $type, 'title' => $title, 'delivery_type' => $delivery, 'availability_status' => 'needs_asset', 'sort_order' => $index + 1]);
        }
        return [$plan, $lesson];
    }

    private function scienceSequenceLesson(array $context, int $sequence, ?LessonPlan $plan = null): array
    {
        $plan ??= app(LessonPlanService::class)->createDraft($context['enrollment'], $context['import']);
        $plan->packageCourse->course()->update(['subject_id' => Subject::query()->where('code', 'SCI')->value('id')]);
        $definition = $sequence === 2 ? [
            'The Sun, Ocean, and Water Cycle', 'Model the water cycle and explain how energy from the Sun and interactions with large bodies of water move water through the system.',
            'Kai constructs and labels a water-cycle model and accurately explains evaporation, condensation, precipitation, collection, and the role of solar energy.', 100,
            [['teacher_preparation', 'Set Up the Model', 'teacher'], ['question', 'What Keeps Water Moving?', 'shared'], ['direct_instruction', 'Water-Cycle Processes', 'shared'], ['demonstration', 'Read a Water-Cycle Model', 'teacher'], ['prediction', 'Covered-Bowl Model', 'student'], ['investigation', 'Observe Evaporation and Condensation', 'shared'], ['evidence_analysis', 'Match Evidence to Processes', 'shared'], ['written_response', 'Explain the Cycle', 'student']],
            [['student_supply', 'supply', 'Pencil and colored pencils'], ['special_material', 'household_material', 'Clear bowl'], ['special_material', 'household_material', 'Warm water'], ['special_material', 'household_material', 'Plastic wrap and rubber band'], ['special_material', 'household_material', 'Ice cubes'], ['special_material', 'household_material', 'Towel'], ['lesson_resource', 'diagram', 'Grade 5 Water-Cycle Diagram'], ['lesson_resource', 'worksheet', 'Covered-Bowl Water-Cycle Investigation Sheet']],
        ] : [
            'Water-Cycle Interactions and Weather', 'Use temperature, evaporation, and condensation evidence to explain how water-cycle interactions contribute to weather conditions.',
            'Kai accurately analyzes the provided weather data, explains two cause-and-effect relationships, and distinguishes weather evidence from an unsupported weather prediction.', 95,
            [['context', 'From Water Movement to Weather', 'shared'], ['vocabulary', 'Weather-Water Terms', 'shared'], ['demonstration', 'Comparing Evaporation Conditions', 'teacher'], ['investigation', 'Observe Water Loss', 'student'], ['evidence_analysis', 'Analyze a Coastal Weather Dataset', 'shared'], ['guided_practice', 'Claim, Evidence, Reasoning', 'shared'], ['exit_check', 'Supported or Unsupported?', 'student']],
            [['student_supply', 'supply', 'Pencil and ruler'], ['special_material', 'household_material', 'Two matching saucers and water'], ['special_material', 'household_material', 'Dropper or teaspoon'], ['special_material', 'household_material', 'Timer'], ['lesson_resource', 'worksheet', 'Evaporation Conditions Observation Sheet'], ['lesson_resource', 'dataset', 'Two-Day Coastal Weather Dataset'], ['lesson_resource', 'graphic_organizer', 'Weather Claim-Evidence-Reasoning Organizer']],
        ];
        [$title, $objective, $criteria, $minutes, $sections, $resources] = $definition;
        $lesson = $plan->lessons()->create(['curriculum_unit_id' => $context['unit']->id, 'sequence' => $sequence, 'title' => $title, 'lesson_mode' => 'full', 'status' => 'draft', 'learning_objective' => $objective, 'completion_criteria' => $criteria, 'estimated_minutes' => $minutes, 'suggested_sessions' => 2]);
        foreach ($sections as $index => [$type, $sectionTitle, $audience]) $lesson->sections()->create(['section_type' => $type, 'sequence' => $index + 1, 'title' => $sectionTitle, 'content' => $sectionTitle, 'audience' => $audience]);
        $lesson->curriculumComponents()->attach($context['component']->id, ['tenant_id' => $context['tenant']->id, 'role' => 'concept', 'sequence' => 1]);
        $lesson->standardAlignments()->attach($context['alignment']->id, ['tenant_id' => $context['tenant']->id]);
        foreach ($resources as $index => [$category, $type, $title]) $lesson->resources()->create(['category' => $category, 'resource_type' => $type, 'title' => $title, 'delivery_type' => $category === 'lesson_resource' ? 'printable' : 'physical', 'availability_status' => $category === 'lesson_resource' ? 'needs_asset' : 'not_applicable', 'sort_order' => $index + 1]);
        return [$plan, $lesson];
    }

    private function mathProblemSolvingLesson(array $context): array
    {
        $plan = app(LessonPlanService::class)->createDraft($context['enrollment'], $context['import']);
        $lesson = $plan->lessons()->create([
            'curriculum_unit_id' => $context['unit']->id, 'sequence' => 1, 'title' => 'Launch a Reliable Problem-Solving Process',
            'lesson_mode' => 'full', 'status' => 'draft', 'learning_objective' => 'Analyze an everyday multistep problem, choose a strategy, solve it, and justify why the answer is reasonable.',
            'completion_criteria' => 'Complete guided and independent organizers with a representation, answer with units, and reasonableness check.', 'estimated_minutes' => 50, 'estimated_preparation_minutes' => 10,
        ]);
        foreach ([
            ['teacher_preparation', 'Preparation', 'teacher'], ['introduction', 'What strong problem solvers do', 'shared'],
            ['direct_instruction', 'The five-part routine', 'shared'], ['example', 'Model: buses for a trip', 'shared'],
            ['guided_practice', 'Plan a supply order', 'shared'], ['independent_practice', 'Apply the routine independently', 'student'],
            ['exit_check', 'Explain the check', 'student'], ['completion_criteria', 'Evidence of completion', 'teacher'],
        ] as $index => [$type, $title, $audience]) $lesson->sections()->create(['section_type' => $type, 'sequence' => $index + 1, 'title' => $title, 'content' => $title.' with enough source detail for the student activity.', 'audience' => $audience]);
        $lesson->standardAlignments()->attach($context['alignment']->id, ['tenant_id' => $context['tenant']->id]);
        foreach ([['graphic_organizer', 'Analyze–Plan–Solve–Justify–Check Organizer'], ['worksheet', 'Interpreting Remainders Task Sheet']] as $index => [$type, $title]) {
            $lesson->resources()->create(['category' => 'lesson_resource', 'resource_type' => $type, 'title' => $title, 'delivery_type' => 'printable', 'availability_status' => 'needs_asset', 'sort_order' => $index + 1]);
        }
        foreach (['Pencil and eraser', 'Lined or blank paper'] as $index => $title) $lesson->resources()->create(['category' => 'student_supply', 'resource_type' => 'supply', 'title' => $title, 'delivery_type' => 'physical', 'availability_status' => 'not_applicable', 'sort_order' => $index + 1]);
        return [$plan, $lesson];
    }

    private function mathRepresentationsLesson(array $context): array
    {
        return $this->mathFollowupLesson($context, 2, 'Choose Tools and Represent Mathematics',
            'Select and use an efficient tool or representation to solve a multistep problem, then connect the representation to an equation and the quantities in the situation.',
            'Compare approaches, solve guided and independent tasks, and explain how a diagram or table corresponds to equations.', 50,
            [['teacher_preparation', 'Prepare representations', 'teacher'], ['hook', 'Which tool would help?', 'shared'], ['direct_instruction', 'Representations reveal relationships', 'shared'], ['demonstration', 'Model connected equations', 'shared'], ['guided_practice', 'Compare two approaches', 'shared'], ['independent_practice', 'Select and defend', 'student'], ['exit_check', 'Connect quantities', 'student'], ['completion_criteria', 'Review', 'teacher']],
            [['lesson_resource', 'chart', 'Tool and Representation Guide'], ['lesson_resource', 'worksheet', 'Connected Representations Practice'], ['student_supply', 'supply', 'Pencil and eraser'], ['student_supply', 'supply', 'Ruler']]);
    }

    private function mathReasoningLesson(array $context): array
    {
        return $this->mathFollowupLesson($context, 3, 'Explain, Evaluate, and Revise Mathematical Reasoning',
            'Evaluate another person’s mathematical argument and communicate a corrected solution using precise words, equations, and a supporting representation.',
            'Identify the division error, provide a corrected answer with evidence, and submit a final explanation another reader can follow.', 55,
            [['teacher_preparation', 'Prepare assessment', 'teacher'], ['introduction', 'A correct answer is not enough', 'shared'], ['direct_instruction', 'Evaluate an argument', 'shared'], ['example', 'Model error analysis', 'shared'], ['activity', 'Evaluate proposed solutions', 'student'], ['written_response', 'Create and revise', 'student'], ['reflection', 'Reflect', 'shared'], ['completion_criteria', 'Review', 'teacher']],
            [['lesson_resource', 'worksheet', 'Mathematical Error Analysis Page'], ['lesson_resource', 'checklist', 'Mathematical Communication Checklist'], ['lesson_resource', 'worksheet', 'Launch Written Response Page'], ['student_supply', 'supply', 'Pencil and eraser'], ['student_supply', 'supply', 'Lined or blank paper']]);
    }

    private function mathFollowupLesson(array $context, int $sequence, string $title, string $objective, string $criteria, int $minutes, array $sections, array $resources): array
    {
        $plan = app(LessonPlanService::class)->createDraft($context['enrollment'], $context['import']);
        $lesson = $plan->lessons()->create(['curriculum_unit_id' => $context['unit']->id, 'sequence' => $sequence, 'title' => $title, 'lesson_mode' => 'full', 'status' => 'draft', 'learning_objective' => $objective, 'completion_criteria' => $criteria, 'estimated_minutes' => $minutes, 'estimated_preparation_minutes' => 10]);
        foreach ($sections as $index => [$type, $sectionTitle, $audience]) $lesson->sections()->create(['section_type' => $type, 'sequence' => $index + 1, 'title' => $sectionTitle, 'content' => $sectionTitle.' with complete source details.', 'audience' => $audience]);
        $lesson->standardAlignments()->attach($context['alignment']->id, ['tenant_id' => $context['tenant']->id]);
        foreach ($resources as $index => [$category, $type, $resourceTitle]) $lesson->resources()->create(['category' => $category, 'resource_type' => $type, 'title' => $resourceTitle, 'delivery_type' => $category === 'lesson_resource' ? 'printable' : 'physical', 'availability_status' => $category === 'lesson_resource' ? 'needs_asset' : 'not_applicable', 'sort_order' => $index + 1]);
        return [$plan, $lesson];
    }

    private function guidedMathResponse(): array
    {
        return ['math_work' => ['total_needed' => '325', 'per_group' => '40', 'plan' => 'capacity_compare', 'eight_capacity' => '320', 'eight_enough' => 'no', 'nine_capacity' => '360', 'nine_enough' => 'yes', 'answer' => '9', 'units' => 'packs']];
    }

    private function packExplanationResponse(): array
    {
        return ['math_work' => ['decision' => 'buy_9', 'reasoning' => 'Eight packs hold only 320 sheets, so five sheets are uncovered and a ninth whole pack is needed.']];
    }

    private function independentMathResponse(): array
    {
        return ['math_work' => ['total_needed' => '246', 'per_group' => '12', 'plan' => 'capacity_compare', 'twenty_capacity' => '240', 'twenty_enough' => 'no', 'twenty_one_capacity' => '252', 'answer' => '21', 'units' => 'pages', 'justification' => 'Twenty pages leave six photographs without a place, so one additional page is needed.', 'check' => 'Twenty pages hold only 240, while twenty-one pages hold 252 and cover all 246 photographs.']];
    }

    private function guidedRepresentationResponse(): array
    {
        return ['math_work' => ['representation' => 'bar_equations', 'teams_per_shelf' => '2', 'books_per_shelf' => '70', 'answer' => '70', 'equation' => 'combined', 'connection' => 'The eight equal shelf sections match dividing all books among eight shelves, and both equations give 70 books per shelf.']];
    }

    private function independentRepresentationResponse(): array
    {
        return ['math_work' => ['representation' => 'table_equations', 'why' => 'A labeled table can organize the six equal sections and the rows assigned to each section.', 'rows_per_section' => '4', 'plants_per_section' => '60', 'equation' => 'combined', 'meaning_four' => 'Four is the number of garden rows assigned to each section.', 'answer' => '60', 'connection' => 'The table shows four rows in each of six sections, and multiplying four rows by fifteen plants matches the equation and gives sixty plants per section.']];
    }

    private function postcardRevisionResponse(): array
    {
        return ['math_work' => ['answer' => '24', 'evidence' => 'partials', 'revision' => 'Response B should say 24 postcards per tray because 16 times 20 is 320 and 16 times 4 is 64, which together account for all 384 postcards.']];
    }

    private function snackArgumentResponse(): array
    {
        return ['math_work' => ['plan' => 'partial_products', 'twenty_boxes' => '360', 'six_boxes' => '108', 'answer' => '26', 'units' => 'bags_per_box', 'draft' => 'Each box receives 26 snack bags because 18 times 20 is 360 and 18 times 6 is 108, which combine to 468.', 'check' => '468', 'strength' => 'The partial products label how all 468 snack bags are accounted for.', 'revision_note' => 'I will state the answer unit and connect the multiplication check to all eighteen boxes.', 'final' => 'Each of the 18 boxes receives 26 snack bags. Eighteen times 20 is 360 and eighteen times 6 is 108; together they equal 468. Therefore 18 times 26 reconstructs every snack bag, so 26 bags per box is correct.']];
    }

    private function systemsMapResponse(): array
    {
        return ['systems_map' => [
            'terms' => ['water', 'rock', 'weathering', 'erosion', 'sediment'],
            'connections' => [
                ['from' => 'water', 'relationship' => 'breaks', 'to' => 'rock'],
                ['from' => 'weathering', 'relationship' => 'breaks', 'to' => 'rock'],
                ['from' => 'water', 'relationship' => 'carries', 'to' => 'sediment'],
            ],
            'question' => 'How does moving water change a rocky coastline over time?',
        ]];
    }

    private function testJpeg(): string
    {
        $image = imagecreatetruecolor(1300, 800);
        imagefill($image, 0, 0, imagecolorallocate($image, 210, 190, 130));
        ob_start(); imagejpeg($image, null, 85); $contents = (string) ob_get_clean(); imagedestroy($image);
        return $contents;
    }

    public function test_technology_lesson_one_teaches_before_code_and_persists_safe_preview_work(): void
    {
        Storage::fake('local');
        $context = $this->context();
        $plan = app(LessonPlanService::class)->createDraft($context['enrollment'], $context['import']);
        $lesson = $plan->lessons()->create([
            'curriculum_unit_id' => $context['unit']->id, 'sequence' => 1,
            'title' => 'Mission Briefing: Instructions in Order', 'lesson_mode' => 'full', 'status' => 'draft',
            'learning_objective' => 'Run a Python program, explain statement order, and use print() with strings.',
            'completion_criteria' => 'Create four ordered print statements and explain how order changes output.',
            'estimated_minutes' => 45,
        ]);
        foreach ([
            ['teacher_preparation', 'Prepare', 'teacher'], ['hook', 'Act Like a Computer', 'shared'],
            ['direct_instruction', 'Statements, Strings, and print()', 'shared'], ['demonstration', 'Model a Mission Briefing', 'shared'],
            ['guided_practice', 'Repair and Reorder', 'student'], ['build', 'Create the Mission Briefing', 'student'],
        ] as $index => [$type, $title, $audience]) {
            $lesson->sections()->create(['section_type' => $type, 'sequence' => $index + 1, 'title' => $title, 'content' => $title.' source content.', 'audience' => $audience]);
        }
        $lesson->resources()->create(['category' => 'lesson_resource', 'resource_type' => 'worksheet', 'title' => 'Mission Briefing Planning Sheet', 'delivery_type' => 'printable', 'availability_status' => 'needs_asset', 'sort_order' => 1]);
        $lesson->resources()->create(['category' => 'special_material', 'resource_type' => 'other', 'title' => 'Computer with a Python 3 Coding Environment', 'delivery_type' => 'physical', 'availability_status' => 'not_applicable', 'sort_order' => 1]);
        $lesson->curriculumComponents()->attach($context['component']->id, ['tenant_id' => $context['tenant']->id, 'role' => 'objective', 'sequence' => 1]);

        $service = app(LessonExperienceService::class);
        $experience = $service->provisionTechnologyMissionBriefingPrototype($lesson);
        app(LessonResourceFulfillmentManager::class)->fulfillRequiredForLesson($lesson);
        $activities = $experience->activities()->get()->keyBy('sequence');
        $this->assertSame(range(1, 7), $activities->keys()->all());
        $this->assertSame(['instruction', 'project', 'multiple_choice', 'instruction', 'project', 'project', 'question_set'], $activities->pluck('activity_type')->all());
        $this->assertSame('Make One Message Appear', $activities[2]->display_title);
        $this->assertSame('Now Name What You Just Did', $activities[4]->display_title);
        $this->assertTrue((bool) data_get($activities[3]->interaction_data, 'ungraded'));
        $beforeVocabulary = json_encode($activities->take(3)->map->only(['display_title', 'student_instructions', 'content', 'interaction_data'])->all());
        foreach (['function name', 'argument', 'value terminology', 'syntax grammar'] as $jargon) $this->assertStringNotContainsStringIgnoringCase($jargon, $beforeVocabulary);
        $technologyReadiness = app(\App\Services\LessonReadinessService::class)->check($lesson->fresh());
        $this->assertTrue($technologyReadiness['ready'], json_encode([$technologyReadiness['blockers'], $lesson->resources()->get()->map->only(['title', 'availability_status', 'asset_disk', 'asset_path', 'fulfillment_error', 'metadata'])]));
        $this->assertSame('ready', $lesson->resources()->where('title', 'Python Print and Statement-Order Reference')->firstOrFail()->availability_status);
        $this->assertFalse((bool) data_get($lesson->resources()->where('title', 'Mission Briefing Planning Sheet')->firstOrFail()->metadata, 'student_experience_required'));

        $progress = $service->progress($experience, $context['enrollment'], true, $context['user']);
        $progress = $service->respond($progress, $activities[1], ['acknowledged' => true]);
        $firstMessage = ['technology_work' => ['source' => 'print("Mars Mission Ready")', 'prediction' => 'Mission Control Online will appear.', 'reflection' => 'The preview changed to my new Mars mission message.']];
        $unchanged = $firstMessage; $unchanged['technology_work']['source'] = 'print("Mission Control Online")';
        $this->expectValidation(fn () => $service->respond($progress, $activities[2], $unchanged), 'response');
        $service->saveDraft($progress, $activities[2], $firstMessage);
        $progress = $service->respond($progress, $activities[2], $firstMessage);
        $progress = $service->respond($progress, $activities[3], ['selected' => 'launch']);
        $this->assertNull($progress->responses->firstWhere('lesson_activity_id', $activities[3]->id)->is_correct);
        $progress = $service->respond($progress, $activities[4], ['acknowledged' => true]);
        $guided = ['technology_work' => [
            'source' => "print(\"MISSION: ORBITAL EXPLORER\")\nprint(\"Launch sequence started\")\nprint(\"Destination: Moon\")\nprint(\"Objective: Study the lunar surface\")",
            'prediction' => 'The launch message will be second.', 'reflection' => 'Moving the statement moved the same output line.',
        ]];
        $draft = $guided; $draft['technology_work']['reflection'] = '';
        $service->saveDraft($progress, $activities[5], $draft);
        $this->assertSame($draft, $progress->responses()->where('lesson_activity_id', $activities[5]->id)->firstOrFail()->response);
        $progress = $service->respond($progress, $activities[5], $guided);
        $custom = ['technology_work' => [
            'source' => "print(\"MISSION: RED PLANET SCOUT\")\nprint(\"Crew ready\")\nprint(\"Destination: Mars\")\nprint(\"Objective: Map the landing zone\")",
            'prediction' => 'My mission title will appear first.', 'reflection' => 'The order introduces the mission before its destination and objective.',
        ]];
        $progress = $service->respond($progress, $activities[6], $custom);
        $progress = $service->respond($progress, $activities[7], ['answers' => ['string' => 'quotes', 'order' => 'top_down']]);
        $this->assertSame('completed', $progress->status);
        $this->assertSame('preview', $experience->fresh()->status);
        $this->assertSame('draft', $lesson->fresh()->status);
        $this->assertSame('draft', $plan->fresh()->status);
        $this->assertStringNotContainsStringIgnoringCase('external coding', json_encode($activities->map->only(['student_instructions', 'content', 'interaction_data'])->all()));
    }

    public function test_technology_lessons_two_through_five_provision_ready_safe_preview_experiences(): void
    {
        Storage::fake('local');
        $context = $this->context();
        $plan = app(LessonPlanService::class)->createDraft($context['enrollment'], $context['import']);
        $definitions = [
            2 => ['Mission Data: Creating and Updating Variables', ['hook', 'direct_instruction', 'demonstration', 'guided_practice', 'build'], 'provisionTechnologyVariablesPrototype'],
            3 => ['Astronaut Profile: Collecting Input', ['question', 'direct_instruction', 'demonstration', 'guided_practice', 'build', 'check_for_understanding'], 'provisionTechnologyInputPrototype'],
            4 => ['Build the Spacecraft with Multiple String Variables', ['context', 'demonstration', 'guided_practice', 'build', 'check_for_understanding'], 'provisionTechnologySpacecraftProfilePrototype'],
            5 => ['Spacecraft Resources: Integers, Decimals, and Updates', ['activity', 'direct_instruction', 'demonstration', 'guided_practice', 'build'], 'provisionTechnologyNumericResourcesPrototype'],
        ];
        $service = app(LessonExperienceService::class);
        $lessons = [];

        foreach ($definitions as $sequence => [$title, $sectionTypes, $provisioner]) {
            $lesson = $plan->lessons()->create([
                'curriculum_unit_id' => $context['unit']->id, 'sequence' => $sequence, 'title' => $title,
                'lesson_mode' => 'full', 'status' => 'draft', 'learning_objective' => "Stored Technology objective {$sequence}.",
                'completion_criteria' => "Stored Technology completion criteria {$sequence}.", 'estimated_minutes' => 45,
            ]);
            foreach ($sectionTypes as $index => $type) {
                $lesson->sections()->create(['section_type' => $type, 'sequence' => $index + 1, 'title' => ucfirst(str_replace('_', ' ', $type)), 'content' => "Stored {$type} authority.", 'audience' => $type === 'build' ? 'student' : 'shared']);
            }
            $lesson->resources()->create(['category' => 'lesson_resource', 'resource_type' => 'worksheet', 'title' => "Original printable {$sequence}", 'delivery_type' => 'printable', 'availability_status' => 'needs_asset', 'sort_order' => 1]);
            $lesson->curriculumComponents()->attach($context['component']->id, ['tenant_id' => $context['tenant']->id, 'role' => 'objective', 'sequence' => 1]);
            $before = $lesson->only(['title', 'learning_objective', 'completion_criteria', 'estimated_minutes', 'lesson_mode', 'status']);
            $experience = $service->{$provisioner}($lesson);
            app(LessonResourceFulfillmentManager::class)->fulfillRequiredForLesson($lesson);
            $activities = $experience->activities()->get()->keyBy('sequence');

            $this->assertSame(range(1, 7), $activities->keys()->all());
            $this->assertSame('preview', $experience->status);
            $this->assertSame('draft', $lesson->fresh()->status);
            $this->assertSame($before, $lesson->fresh()->only(array_keys($before)));
            $this->assertSame([$context['component']->id], $lesson->curriculumComponents()->pluck('curriculum_unit_components.id')->all());
            if ($sequence !== 4) {
                $this->assertTrue((bool) data_get($activities[3]->interaction_data, 'ungraded'), "Lesson {$sequence} prediction must be ungraded.");
            } else {
                $this->assertNotEmpty(data_get($activities[5]->interaction_data, 'technology_code_builder.prediction_label'));
            }
            $delivery = json_encode($activities->map->only(['student_instructions', 'content', 'interaction_data'])->all());
            foreach (['external coding website', 'ask a parent', 'print this', 'paper and pencil'] as $dependency) {
                $this->assertStringNotContainsStringIgnoringCase($dependency, $delivery);
            }
            $resource = $lesson->resources()->where('sort_order', 20)->firstOrFail();
            $this->assertSame('ready', $resource->availability_status);
            $this->assertTrue((bool) data_get($resource->metadata, 'student_experience_required'));
            $readiness = app(\App\Services\LessonReadinessService::class)->check($lesson->fresh());
            $this->assertTrue($readiness['ready'], json_encode($readiness['blockers']));
            $lessons[$sequence] = [$lesson, $experience, $activities];
        }

        [$lesson2, $experience2, $a] = $lessons[2];
        $progress = $service->progress($experience2, $context['enrollment'], true, $context['user']);
        $progress = $service->respond($progress, $a[1], ['acknowledged' => true]);
        $progress = $service->respond($progress, $a[2], ['acknowledged' => true]);
        $progress = $service->respond($progress, $a[3], ['selected' => 'moon']);
        $this->assertNull($progress->responses->firstWhere('lesson_activity_id', $a[3]->id)->is_correct);
        $guided2 = ['technology_work' => ['source' => data_get($a[4]->interaction_data, 'technology_code_builder.starter_code'), 'prediction' => 'Moon appears before Mars.', 'reflection' => 'The name stayed the same while its stored destination changed.']];
        $service->saveDraft($progress, $a[4], $guided2);
        $this->assertSame($guided2, $progress->responses()->where('lesson_activity_id', $a[4]->id)->firstOrFail()->response);
        $progress = $service->respond($progress, $a[4], $guided2);
        $progress = $service->respond($progress, $a[5], ['acknowledged' => true]);
        $project2 = ['technology_work' => ['source' => data_get($a[6]->interaction_data, 'technology_code_builder.starter_code'), 'prediction' => 'Mars is the final destination.', 'reflection' => 'destination is meaningful because it identifies the stored place and its later update.']];
        $progress = $service->respond($progress, $a[6], $project2);
        $progress = $service->respond($progress, $a[7], ['answers' => ['variable' => 'stored', 'meaningful' => 'purpose']]);
        $this->assertSame('completed', $progress->status);

        [$lesson3, $experience3, $a] = $lessons[3];
        $progress = $service->progress($experience3, $context['enrollment'], true, $context['user']);
        foreach ([1, 2] as $step) $progress = $service->respond($progress, $a[$step], ['acknowledged' => true]);
        $progress = $service->respond($progress, $a[3], ['selected' => 'kai']);
        $this->assertNull($progress->responses->firstWhere('lesson_activity_id', $a[3]->id)->is_correct);
        $guided3 = ['technology_work' => ['source' => data_get($a[4]->interaction_data, 'technology_code_builder.starter_code'), 'prediction' => 'The two test names appear.', 'reflection' => 'Each prompt response is stored under its matching name and displayed later.', 'inputs' => ['commander_name' => 'Kai', 'pilot_name' => 'Nova']]];
        $service->saveDraft($progress, $a[4], $guided3);
        $this->assertSame($guided3, $progress->responses()->where('lesson_activity_id', $a[4]->id)->firstOrFail()->response);
        $progress = $service->respond($progress, $a[4], $guided3);
        $progress = $service->respond($progress, $a[5], ['acknowledged' => true]);
        $project3 = ['technology_work' => ['source' => data_get($a[6]->interaction_data, 'technology_code_builder.starter_code'), 'prediction' => 'All four profile responses appear.', 'reflection' => 'The spacecraft prompt asks, spacecraft_name stores, and print displays the response.', 'inputs' => ['commander_name' => 'Kai', 'pilot_name' => 'Nova', 'mission_name' => 'Red Planet Scout', 'spacecraft_name' => 'Odyssey']]];
        $progress = $service->respond($progress, $a[6], $project3);
        $retry = $service->respond($progress, $a[7], ['answers' => ['prompt' => 'variable', 'store' => 'spacecraft', 'later' => 'use_variable']]);
        $this->assertSame($a[7]->id, $retry->current_activity_id);
        $progress = $service->respond($retry, $a[7], ['answers' => ['prompt' => 'question', 'store' => 'spacecraft', 'later' => 'use_variable']]);
        $this->assertSame('completed', $progress->status);

        [$lesson4, $experience4, $a] = $lessons[4];
        $progress = $service->progress($experience4, $context['enrollment'], true, $context['user']);
        foreach ([1, 2] as $step) $progress = $service->respond($progress, $a[$step], ['acknowledged' => true]);
        $retry = $service->respond($progress, $a[3], ['selected' => 'call']);
        $this->assertSame($a[3]->id, $retry->current_activity_id);
        $progress = $service->respond($retry, $a[3], ['selected' => 'rocket']);
        $progress = $service->respond($progress, $a[4], ['acknowledged' => true]);
        foreach ([5, 6] as $step) {
            $response = ['technology_work' => ['source' => data_get($a[$step]->interaction_data, 'technology_code_builder.starter_code'), 'prediction' => 'Every stored detail appears beside its matching label.', 'reflection' => 'The meaningful names make each spacecraft detail and matching output easy to trace.']];
            if ($step === 5) $service->saveDraft($progress, $a[$step], $response);
            $progress = $service->respond($progress, $a[$step], $response);
        }
        $progress = $service->respond($progress, $a[7], ['answers' => ['label' => 'payload_value', 'separate' => 'preserve']]);
        $this->assertSame('completed', $progress->status);

        [$lesson5, $experience5, $a] = $lessons[5];
        $progress = $service->progress($experience5, $context['enrollment'], true, $context['user']);
        $progress = $service->respond($progress, $a[1], ['answers' => ['quoted' => 'text', 'whole' => 'integer', 'decimal' => 'decimal']]);
        $progress = $service->respond($progress, $a[2], ['acknowledged' => true]);
        $progress = $service->respond($progress, $a[3], ['selected' => 'old']);
        $this->assertNull($progress->responses->firstWhere('lesson_activity_id', $a[3]->id)->is_correct);
        $progress = $service->respond($progress, $a[4], ['acknowledged' => true]);
        foreach ([5, 6] as $step) {
            $response = ['technology_work' => ['source' => data_get($a[$step]->interaction_data, 'technology_code_builder.starter_code'), 'prediction' => 'Initial values appear before their updated values.', 'reflection' => 'The integer has no decimal point, the decimal does, and both are unquoted numeric values.']];
            if ($step === 5) $service->saveDraft($progress, $a[$step], $response);
            $progress = $service->respond($progress, $a[$step], $response);
        }
        $retry = $service->respond($progress, $a[7], ['answers' => ['integer' => 'oxygen', 'decimal' => 'battery', 'update' => 'replace']]);
        $this->assertSame($a[7]->id, $retry->current_activity_id);
        $progress = $service->respond($retry, $a[7], ['answers' => ['integer' => 'crew', 'decimal' => 'battery', 'update' => 'replace']]);
        $this->assertSame('completed', $progress->status);
        $this->assertSame('draft', $plan->fresh()->status);
    }

    public function test_spanish_lesson_one_teaches_greetings_before_a_persistent_digital_passport_build(): void
    {
        Storage::fake('local');
        $context = $this->context();
        $spanish = Subject::create(['name' => 'World Languages', 'code' => 'LANG', 'status' => 'active']);
        $context['mapping']->course()->update(['subject_id' => $spanish->id]);
        $context['import']->update(['subject_id' => $spanish->id]);
        $context['unit']->update(['name' => 'Unit 1 - Hola, Soy Yo']);
        $plan = app(LessonPlanService::class)->createDraft($context['enrollment'], $context['import']->fresh());
        $lesson = $plan->lessons()->create([
            'curriculum_unit_id' => $context['unit']->id, 'sequence' => 1, 'title' => 'Hola y adiós: Greetings and Farewells',
            'lesson_mode' => 'full', 'status' => 'draft',
            'learning_objective' => 'Use common Spanish greetings and farewells appropriately and begin the greeting section of Mi Pasaporte Español.',
            'completion_criteria' => 'Select and say greetings and farewells and complete the passport greeting card.',
            'estimated_minutes' => 35, 'estimated_preparation_minutes' => 10, 'suggested_sessions' => 1,
        ]);
        foreach ([['hook','How do greetings change?','shared'],['vocabulary','Greeting and farewell phrases','shared'],['guided_practice','Choose the phrase','shared'],['build','Build 1: Greeting card','student'],['check_for_understanding','Greeting challenge','shared']] as $index => [$type,$title,$audience]) {
            $lesson->sections()->create(['section_type'=>$type,'sequence'=>$index+1,'title'=>$title,'content'=>"Stored {$title} curriculum-aligned lesson content.",'audience'=>$audience]);
        }
        foreach ([['chart','Spanish Greetings and Farewells Chart'],['worksheet','Greeting Situation Cards'],['other','Mi Pasaporte Español Template']] as $index => [$type,$title]) {
            $lesson->resources()->create(['category'=>'lesson_resource','resource_type'=>$type,'title'=>$title,'delivery_type'=>'printable','availability_status'=>'needs_asset','sort_order'=>$index+1]);
        }
        $lesson->resources()->create(['category'=>'student_supply','resource_type'=>'supply','title'=>'Pencil and colored pencils','delivery_type'=>'physical','availability_status'=>'not_applicable','sort_order'=>1]);
        $lesson->curriculumComponents()->attach($context['component']->id, ['tenant_id'=>$context['tenant']->id,'role'=>'objective','sequence'=>1]);
        $before = $lesson->only(['title','learning_objective','completion_criteria','estimated_minutes','suggested_sessions','status']);
        $service = app(LessonExperienceService::class);
        $experience = $service->provisionSpanishGreetingsPrototype($lesson);
        app(LessonResourceFulfillmentManager::class)->fulfillRequiredForLesson($lesson);
        $activities = $experience->activities()->get()->keyBy('sequence');

        $this->assertSame(range(1, 8), $activities->keys()->all());
        $this->assertSame(['instruction','instruction','multiple_choice','matching','instruction','question_set','project','question_set'], $activities->pluck('activity_type')->all());
        $this->assertSame('Hola', data_get($activities[1]->interaction_data, 'language_phrases.phrases.0.spanish'));
        $this->assertTrue((bool) data_get($activities[3]->interaction_data, 'language_phrases.hide_text'));
        $this->assertFalse((bool) data_get($lesson->resources()->where('title','Spanish Greetings and Farewells Chart')->firstOrFail()->metadata, 'student_experience_required'));
        $digital = $lesson->resources()->where('title','Saludos y despedidas Interactive Phrase Guide')->firstOrFail();
        $this->assertSame('ready', $digital->availability_status);
        $this->assertSame('learning_app_spanish_foundation_v1', $digital->fulfillment_provider);
        $this->assertTrue(app(\App\Services\LessonReadinessService::class)->ready($lesson->fresh()));
        $delivery = json_encode($activities->map->only(['student_instructions','content','interaction_data'])->all());
        foreach (['external website','print the','paper','pencil','record your voice','pronunciation score'] as $dependency) $this->assertStringNotContainsStringIgnoringCase($dependency, $delivery);

        $progress = $service->progress($experience, $context['enrollment'], true, $context['user']);
        $progress = $service->respond($progress,$activities[1],['acknowledged'=>true]);
        $progress = $service->respond($progress,$activities[2],['acknowledged'=>true]);
        $retry = $service->respond($progress,$activities[3],['selected'=>'afternoon']);
        $this->assertSame($activities[3]->id,$retry->current_activity_id);
        $this->assertStringContainsString('días',$retry->responses->firstWhere('lesson_activity_id',$activities[3]->id)->feedback);
        $progress = $service->respond($retry,$activities[3],['selected'=>'morning']);
        $progress = $service->respond($progress,$activities[4],['matches'=>['general'=>'hola','morning'=>'buenos_dias','afternoon'=>'buenas_tardes','goodbye'=>'adios','later'=>'hasta_luego']]);
        $progress = $service->respond($progress,$activities[5],['acknowledged'=>true]);
        $progress = $service->respond($progress,$activities[6],['answers'=>['arrival'=>'hola','return'=>'later']]);
        $passport = ['language_work'=>['greetings'=>['hola','buenos_dias'],'farewells'=>['adios','hasta_luego'],'practice_line'=>'Hola, Kai. Hasta luego.','reason'=>'Hasta luego fits because I expect to meet the person again later.','speaking_self_check'=>true]];
        $service->saveDraft($progress,$activities[7],$passport);
        $this->assertSame($passport,$progress->responses()->where('lesson_activity_id',$activities[7]->id)->firstOrFail()->response);
        $progress = $service->respond($progress,$activities[7],$passport);
        $this->assertSame('submitted',$progress->responses->firstWhere('lesson_activity_id',$activities[7]->id)->status);
        $this->assertNull($progress->responses->firstWhere('lesson_activity_id',$activities[7]->id)->is_correct);
        $retry = $service->respond($progress,$activities[8],['answers'=>['sunrise'=>'tardes','three_pm'=>'tardes','return'=>'later']]);
        $this->assertSame($activities[8]->id,$retry->current_activity_id);
        $progress = $service->respond($retry,$activities[8],['answers'=>['sunrise'=>'dias','three_pm'=>'tardes','return'=>'later']]);
        $this->assertSame('completed',$progress->status);
        $this->assertSame('preview',$experience->fresh()->status);
        $this->assertSame($before,$lesson->fresh()->only(array_keys($before)));
        $this->assertSame('draft',$plan->fresh()->status);
    }

    public function test_spanish_lessons_two_through_four_are_scaffolded_persistent_preview_missions(): void
    {
        Storage::fake('local');
        $context=$this->context();
        $spanish=Subject::create(['name'=>'World Languages','code'=>'LANG','status'=>'active']);
        $context['mapping']->course()->update(['subject_id'=>$spanish->id]);$context['import']->update(['subject_id'=>$spanish->id]);$context['unit']->update(['name'=>'Unit 1 - Hola, Soy Yo']);
        $plan=app(LessonPlanService::class)->createDraft($context['enrollment'],$context['import']->fresh());
        $definitions=[
            2=>['The Five Spanish Vowels',['introduction','demonstration','guided_practice','activity','exit_check']],
            3=>['¿Cómo te llamas? Asking and Answering Names',['vocabulary','listening','speaking','build','exit_check']],
            4=>['Polite Expressions in Conversation',['context','example','guided_practice','speaking','exit_check']],
            5=>['¿Cuántos años tienes? Saying Age',['introduction']],
        ];
        $lessons=[];
        foreach($definitions as $sequence=>[$title,$sections]){
            $lesson=$plan->lessons()->create(['curriculum_unit_id'=>$context['unit']->id,'sequence'=>$sequence,'title'=>$title,'status'=>'draft','learning_objective'=>'Stored Spanish lesson objective.','completion_criteria'=>'Stored Spanish completion criteria.','estimated_minutes'=>30,'estimated_preparation_minutes'=>10,'suggested_sessions'=>1]);
            foreach($sections as $index=>$type)$lesson->sections()->create(['section_type'=>$type,'sequence'=>$index+1,'title'=>ucwords(str_replace('_',' ',$type)),'content'=>'Stored curriculum-aligned lesson content.','audience'=>$type==='exit_check'&&$sequence===2?'teacher':($type==='build'?'student':'shared')]);
            $lesson->resources()->create(['category'=>'lesson_resource','resource_type'=>'chart','title'=>"Lesson {$sequence} printable",'delivery_type'=>'printable','availability_status'=>'needs_asset','sort_order'=>1]);
            $lesson->curriculumComponents()->attach($context['component']->id,['tenant_id'=>$context['tenant']->id,'role'=>'objective','sequence'=>1]);$lessons[$sequence]=$lesson;
        }
        $service=app(LessonExperienceService::class);
        $experiences=[2=>$service->provisionSpanishVowelsPrototype($lessons[2]),3=>$service->provisionSpanishNamesPrototype($lessons[3]),4=>$service->provisionSpanishCourtesyPrototype($lessons[4])];
        foreach($lessons as $sequence=>$lesson){if($sequence<5)app(LessonResourceFulfillmentManager::class)->fulfillRequiredForLesson($lesson);}
        $this->assertNull($lessons[5]->fresh()->experience);$this->assertSame('draft',$plan->fresh()->status);
        foreach($experiences as $sequence=>$experience){
            $this->assertSame('preview',$experience->fresh()->status);$this->assertSame('draft',$lessons[$sequence]->fresh()->status);
            $this->assertTrue(app(\App\Services\LessonReadinessService::class)->ready($lessons[$sequence]->fresh()));
            $delivery=json_encode($experience->activities->map->only(['student_instructions','content','interaction_data'])->all());
            foreach(['external website','print the','record your voice','pronunciation score'] as $dependency)$this->assertStringNotContainsStringIgnoringCase($dependency,$delivery);
            $this->assertNotNull(data_get($experience->activities->first()->interaction_data,'language_phrases'));
        }

        $a=$experiences[2]->activities()->get()->keyBy('sequence');$progress=$service->progress($experiences[2],$context['enrollment'],true,$context['user']);
        $progress=$service->respond($progress,$a[1],['acknowledged'=>true]);$retry=$service->respond($progress,$a[2],['selected'=>'u']);$this->assertSame($a[2]->id,$retry->current_activity_id);$this->assertStringContainsString('Replay',$retry->responses->firstWhere('lesson_activity_id',$a[2]->id)->feedback);
        $progress=$service->respond($retry,$a[2],['selected'=>'i']);$progress=$service->respond($progress,$a[3],['acknowledged'=>true]);$progress=$service->respond($progress,$a[4],['matches'=>['hola_o'=>'o','tardes_e'=>'e','dias_i'=>'i','luego_u'=>'u','gracias_a'=>'a']]);$progress=$service->respond($progress,$a[5],['acknowledged'=>true]);
        $work=['language_practice'=>['words'=>['hola','dias','tardes','adios','luego','gracias'],'revisit'=>'e','speaking_self_check'=>true]];$service->saveDraft($progress,$a[6],$work);$this->assertSame($work,$progress->responses()->where('lesson_activity_id',$a[6]->id)->firstOrFail()->response);$progress=$service->respond($progress,$a[6],$work);$progress=$service->respond($progress,$a[7],['answers'=>['dias'=>'i','luego'=>'u','steady'=>'consistent']]);$this->assertSame('completed',$progress->status);

        $a=$experiences[3]->activities()->get()->keyBy('sequence');$progress=$service->progress($experiences[3],$context['enrollment'],true,$context['user']);
        $progress=$service->respond($progress,$a[1],['acknowledged'=>true]);$progress=$service->respond($progress,$a[2],['selected'=>'me_llamo']);$progress=$service->respond($progress,$a[3],['acknowledged'=>true]);$progress=$service->respond($progress,$a[4],['matches'=>['ask'=>'question','answer_name'=>'me_llamo','answer_identity'=>'soy','meet'=>'mucho_gusto']]);$progress=$service->respond($progress,$a[5],['acknowledged'=>true]);$progress=$service->respond($progress,$a[6],['answers'=>['name_answer'=>'me_llamo','meeting'=>'mucho']]);
        $work=['language_practice'=>['answer_frame'=>'me_llamo','name'=>'Kai','nickname'=>'','introduction'=>'Me llamo Kai.','speaking_self_check'=>true]];$service->saveDraft($progress,$a[7],$work);$progress=$service->respond($progress,$a[7],$work);$retry=$service->respond($progress,$a[8],['answers'=>['ask'=>'soy','answer'=>'soy','close'=>'gusto']]);$this->assertSame($a[8]->id,$retry->current_activity_id);$progress=$service->respond($retry,$a[8],['answers'=>['ask'=>'question','answer'=>'soy','close'=>'gusto']]);$this->assertSame('completed',$progress->status);

        $a=$experiences[4]->activities()->get()->keyBy('sequence');$progress=$service->progress($experiences[4],$context['enrollment'],true,$context['user']);
        $progress=$service->respond($progress,$a[1],['acknowledged'=>true]);$progress=$service->respond($progress,$a[2],['selected'=>'meeting']);$progress=$service->respond($progress,$a[3],['acknowledged'=>true]);$progress=$service->respond($progress,$a[4],['matches'=>['meeting'=>'mucho_gusto','help'=>'gracias','request'=>'por_favor']]);$progress=$service->respond($progress,$a[5],['answers'=>['name'=>'gusto','thanks'=>'gracias','please'=>'favor']]);
        $work=['language_practice'=>['situation'=>'meeting','exchange'=>'Hola. Soy Kai. — Mucho gusto.','speaking_self_check'=>true]];$service->saveDraft($progress,$a[6],$work);$progress=$service->respond($progress,$a[6],$work);$progress=$service->respond($progress,$a[7],['answers'=>['meeting'=>'gusto','help'=>'gracias','request'=>'favor']]);$this->assertSame('completed',$progress->status);
    }

    private function mapLesson(array $context): array
    {
        $plan = app(LessonPlanService::class)->createDraft($context['enrollment'], $context['import']);
        $lesson = $plan->lessons()->create([
            'curriculum_unit_id' => $context['unit']->id, 'sequence' => 1,
            'title' => 'Reading and Creating Maps of the United States', 'status' => 'draft',
            'learning_objective' => 'Read a United States map and create a clear reference map.',
            'completion_criteria' => 'Create a map with title, orientation, legend, labels, and three symbols.', 'estimated_minutes' => 55,
        ]);
        $lesson->sections()->create(['section_type' => 'teacher_preparation', 'sequence' => 1, 'title' => 'Teacher only', 'content' => 'Private teacher setup', 'audience' => 'teacher']);
        foreach ([
            ['hook', 'Mission hook', 'shared'], ['direct_instruction', 'Map tools', 'shared'],
            ['guided_practice', 'Read the map', 'student'], ['independent_practice', 'Build the map', 'student'],
            ['exit_check', 'Check skills', 'shared'],
        ] as $index => [$type, $content, $audience]) {
            $lesson->sections()->create(['section_type' => $type, 'sequence' => $index + 2, 'title' => $content, 'content' => $content, 'audience' => $audience]);
        }
        $lesson->curriculumComponents()->attach($context['component']->id, ['tenant_id' => $context['tenant']->id, 'role' => 'skill']);
        $lesson->standardAlignments()->attach($context['alignment']->id, ['tenant_id' => $context['tenant']->id]);
        return [$plan, $lesson];
    }

    private function regionsLesson(array $context): array
    {
        $plan = app(LessonPlanService::class)->createDraft($context['enrollment'], $context['import']);
        $lesson = $plan->lessons()->create([
            'curriculum_unit_id' => $context['unit']->id, 'sequence' => 2,
            'title' => 'Physical and Political Regions of the United States', 'lesson_mode' => 'full', 'status' => 'draft',
            'learning_objective' => 'Distinguish physical information from political information and organize places into regions using stated criteria and map evidence.',
            'completion_criteria' => 'Classify six features and create a regional map with clear boundaries, labels, legend, and criterion.', 'estimated_minutes' => 60,
        ]);
        foreach ([
            ['teacher_preparation', 'Prepare maps', 'teacher'], ['materials', 'Materials', 'shared'],
            ['introduction', 'Two map views', 'shared'], ['direct_instruction', 'Classify features', 'shared'],
            ['example', 'Model a region', 'shared'], ['guided_practice', 'Use evidence', 'student'],
            ['independent_practice', 'Create regions', 'student'], ['check_for_understanding', 'Defend regions', 'shared'],
        ] as $index => [$type, $content, $audience]) {
            $lesson->sections()->create(['section_type' => $type, 'sequence' => $index + 1, 'title' => $content, 'content' => $content, 'audience' => $audience]);
        }
        $lesson->curriculumComponents()->attach($context['component']->id, ['tenant_id' => $context['tenant']->id, 'role' => 'skill']);
        $lesson->standardAlignments()->attach($context['alignment']->id, ['tenant_id' => $context['tenant']->id]);
        return [$plan, $lesson];
    }

    private function context(): array
    {
        [$user, $tenant] = $this->adult();
        $this->setContext($user, $tenant);
        $grade = GradeLevel::query()->where('code', 'G5')->firstOrFail();
        $otherGrade = GradeLevel::query()->where('code', 'G4')->firstOrFail();
        $student = $tenant->students()->create(['first_name' => 'Student', 'last_name' => 'Example', 'status' => 'active']);
        $year = $tenant->schoolYears()->create(['name' => 'Current Year', 'start_date' => '2026-08-01', 'end_date' => '2027-05-31', 'timezone' => 'UTC', 'status' => 'active']);
        $otherYear = $tenant->schoolYears()->create(['name' => 'Other Year', 'start_date' => '2027-08-01', 'end_date' => '2028-05-31', 'timezone' => 'UTC', 'status' => 'draft']);
        $enrollment = StudentEnrollment::create(['student_id' => $student->id, 'school_year_id' => $year->id, 'grade_level_id' => $grade->id, 'enrollment_date' => '2026-08-01', 'status' => 'active']);
        $provider = EducationProvider::create(['name' => 'Example Provider', 'provider_type' => 'publisher', 'status' => 'active']);
        $framework = StandardsFramework::create(['education_provider_id' => $provider->id, 'name' => 'Example Standards', 'version_label' => '1', 'status' => 'active']);
        $subject = Subject::query()->where('code', 'MATH')->firstOrFail();
        $course = Course::create(['subject_id' => $subject->id, 'standards_framework_id' => $framework->id, 'name' => 'Example Course', 'code' => 'EXAMPLE-COURSE', 'minimum_grade_level_id' => $grade->id, 'maximum_grade_level_id' => $grade->id, 'status' => 'draft']);
        $package = CurriculumPackage::create(['education_provider_id' => $provider->id, 'standards_framework_id' => $framework->id, 'name' => 'Example Curriculum', 'version_label' => '1', 'status' => 'draft']);
        $mapping = $package->courseMappings()->create(['course_id' => $course->id, 'grade_level_id' => $grade->id, 'sort_order' => 1, 'required' => true]);
        $source = AcademicSource::create(['education_provider_id' => $provider->id, 'school_year_id' => $year->id, 'grade_level_id' => $grade->id, 'title' => 'Example Curriculum Source', 'source_kind' => 'upload', 'source_category' => 'curriculum', 'authority_level' => 'tenant_created', 'review_status' => 'reviewed', 'processing_status' => 'completed']);
        foreach (['education_provider' => $provider->id, 'school_year' => $year->id, 'grade_level' => $grade->id, 'subject' => $subject->id] as $type => $id) {
            $source->links()->create(['link_type' => $type, 'link_id' => $id]);
        }
        $file = $source->files()->create(['uploaded_by_user_id' => $user->id, 'version_number' => 1, 'current_key' => 'current', 'is_current' => true, 'disk' => 'local', 'stored_path' => 'test/example.pdf', 'stored_filename' => 'example.pdf', 'original_filename' => 'example.pdf', 'mime_type' => 'application/pdf', 'extension' => 'pdf', 'file_size' => 100, 'checksum_sha256' => str_repeat('a', 64), 'uploaded_at' => now()]);
        $import = CurriculumImport::create(['academic_source_id' => $source->id, 'academic_source_file_id' => $file->id, 'curriculum_package_id' => $package->id, 'curriculum_package_course_id' => $mapping->id, 'subject_id' => $subject->id, 'grade_level_id' => $grade->id, 'school_year_id' => $year->id, 'standards_framework_id' => $framework->id, 'created_by_user_id' => $user->id, 'approved_by_user_id' => $user->id, 'status' => 'approved', 'parser_key' => StructuredCustomCurriculumParser::KEY, 'parser_version' => '1', 'approved_at' => now()]);
        $unitProposal = $import->proposals()->create(['proposal_type' => 'unit', 'included' => true, 'sequence' => 1, 'name' => 'Unit One', 'unit_type' => 'instructional']);
        $unit = CurriculumUnit::create(['curriculum_package_course_id' => $mapping->id, 'name' => 'Unit One', 'sequence' => 1, 'unit_type' => 'instructional', 'included' => true, 'academic_source_id' => $source->id, 'academic_source_file_id' => $file->id, 'curriculum_import_id' => $import->id, 'curriculum_import_proposal_id' => $unitProposal->id, 'parser_key' => 'test', 'parser_version' => '1']);
        $componentProposal = $import->proposals()->create(['parent_proposal_id' => $unitProposal->id, 'proposal_type' => 'component', 'included' => true, 'sequence' => 1, 'name' => 'Core Objective', 'component_type' => 'objective']);
        $component = CurriculumUnitComponent::create(['curriculum_unit_id' => $unit->id, 'component_type' => 'objective', 'name' => 'Core Objective', 'description' => 'Understand the central idea.', 'sequence' => 1, 'academic_source_id' => $source->id, 'academic_source_file_id' => $file->id, 'curriculum_import_id' => $import->id, 'curriculum_import_proposal_id' => $componentProposal->id, 'parser_key' => 'test', 'parser_version' => '1']);
        $alignment = CurriculumUnitStandardAlignment::create(['curriculum_unit_id' => $unit->id, 'standards_framework_id' => $framework->id, 'standard_code' => 'STD.1', 'normalized_code' => 'STD.1', 'academic_source_id' => $source->id, 'academic_source_file_id' => $file->id, 'curriculum_import_id' => $import->id, 'curriculum_import_proposal_id' => $unitProposal->id, 'parser_key' => 'test', 'parser_version' => '1']);

        return compact('user', 'tenant', 'grade', 'otherGrade', 'year', 'otherYear', 'enrollment', 'mapping', 'source', 'import', 'unit', 'component', 'alignment');
    }

    private function expectValidation(callable $callback, string $key): void
    {
        try {
            $callback();
            $this->fail('Expected validation failure.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($key, $exception->errors());
        }
    }

    private function digitalMapResponse(): array
    {
        return [
            'map' => [
                'title' => 'Kai’s Three-State Explorer Map',
                'show_orientation' => true,
                'features' => [
                    ['state_fips' => '06', 'marker_key' => 'blue_circle', 'legend_label' => 'A place on the Pacific coast'],
                    ['state_fips' => '12', 'marker_key' => 'gold_star', 'legend_label' => 'A place on the Atlantic coast'],
                    ['state_fips' => '48', 'marker_key' => 'green_triangle', 'legend_label' => 'A place between the coasts'],
                ],
            ],
            'reflections' => [
                'information_shown' => 'My map shows three states I chose in different parts of the country.',
                'symbol_explanation' => 'The gold star represents a place on the Atlantic coast.',
                'relative_location' => 'California is west of Texas because it is left of Texas when north is up.',
            ],
        ];
    }

    private function regionMapResponse(): array
    {
        return [
            'map' => [
                'title' => 'Kai’s Three U.S. Location Regions',
                'criterion' => 'relative location from west to east',
                'regions' => [
                    ['id' => 'region_1', 'name' => 'Western', 'color_key' => 'teal', 'state_fips' => ['06', '53']],
                    ['id' => 'region_2', 'name' => 'Central', 'color_key' => 'gold', 'state_fips' => ['40', '48']],
                    ['id' => 'region_3', 'name' => 'Eastern', 'color_key' => 'coral', 'state_fips' => ['12', '36']],
                ],
            ],
            'reflections' => [
                'boundary_evidence' => 'California and Washington are west of the states in my central region.',
                'different_criterion' => 'A mountain-based region could cross parts of several state boundaries.',
            ],
        ];
    }

    private function settlementAnalysisResponse(): array
    {
        return ['analysis' => [
            'observations' => [
                ['state_fips' => '36', 'statement' => 'New York shows a much higher density value.'],
                ['state_fips' => '56', 'statement' => 'Wyoming shows the lowest labeled density value.'],
            ],
            'patterns' => ['The eastern labeled states are denser than Wyoming.', 'Darker colors correspond to larger density values.'],
            'inference' => 'Access to transportation might influence the pattern.',
            'limitation' => 'We would need historical and economic sources to test that explanation.',
        ]];
    }

    private function adult(string $name = 'Example Academy'): array
    {
        $user = User::factory()->create();
        $tenant = Tenant::create(['name' => $name, 'type' => 'homeschool_family', 'timezone' => 'UTC', 'locale' => 'en', 'status' => 'active']);
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => 'owner', 'status' => 'active']);

        return [$user, $tenant];
    }

    private function actingIn(User $user, Tenant $tenant): static
    {
        return $this->actingAs($user)->withSession(['active_tenant_id' => $tenant->id]);
    }

    private function setContext(User $user, Tenant $tenant): void
    {
        $membership = TenantMembership::query()->where('tenant_id', $tenant->id)->where('user_id', $user->id)->firstOrFail();
        app(TenantContext::class)->set($tenant, $membership);
        $this->actingAs($user);
    }
}
