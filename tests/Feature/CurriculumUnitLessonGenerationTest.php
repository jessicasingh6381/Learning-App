<?php

namespace Tests\Feature;

use App\Contracts\LessonGenerator;
use App\Data\GeneratedLessonData;
use App\Data\GeneratedLessonSectionData;
use App\Data\GeneratedLessonResourceData;
use App\Data\LessonGenerationContext;
use App\Exceptions\LessonGenerationException;
use App\Models\AcademicSource;
use App\Models\Course;
use App\Models\CurriculumImport;
use App\Models\CurriculumPackage;
use App\Models\CurriculumUnit;
use App\Models\CurriculumUnitComponent;
use App\Models\CurriculumUnitStandardAlignment;
use App\Models\EducationProvider;
use App\Models\GradeLevel;
use App\Models\LessonPlan;
use App\Models\StandardsFramework;
use App\Models\StudentEnrollment;
use App\Models\Subject;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\AuditService;
use App\Services\CurriculumUnitLessonGenerationService;
use App\Services\LessonPlanService;
use App\Services\LessonGenerationOutputValidator;
use App\Services\OpenAiLessonGenerator;
use App\Tenancy\TenantContext;
use Database\Seeders\AcademicReferenceSeeder;
use Database\Seeders\GradeLevelSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class CurriculumUnitLessonGenerationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed([GradeLevelSeeder::class, AcademicReferenceSeeder::class]);
    }

    public function test_generation_uses_exact_context_and_persists_order_sections_and_provenance(): void
    {
        $context = $this->context();
        $fake = new FakeLessonGenerator($this->validLessons($context, 2));
        $this->app->instance(LessonGenerator::class, $fake);

        $plan = app(CurriculumUnitLessonGenerationService::class)->generate($context['plan'], $context['unit']);

        $this->assertSame($context['grade']->name, $fake->context->grade['name']);
        $this->assertSame($context['subject']->name, $fake->context->subject['name']);
        $this->assertSame($context['enrollment']->id, $fake->context->enrollment['id']);
        $this->assertSame($context['unit']->id, $fake->context->unit['id']);
        $this->assertSame('full', $fake->context->lessonMode);
        $this->assertSame('draft', $plan->status);
        $lessons = $plan->lessons()->with(['allSections', 'curriculumComponents', 'standardAlignments'])->get();
        $this->assertSame(['Lesson 1', 'Lesson 2'], $lessons->pluck('title')->all());
        $this->assertSame([1, 2], $lessons->pluck('sequence')->all());
        $this->assertSame(['full', 'full'], $lessons->pluck('lesson_mode')->all());
        $this->assertSame(10, $lessons->first()->estimated_preparation_minutes);
        $this->assertSame(1, $lessons->first()->suggested_sessions);
        $this->assertSame(
            ['common_materials', 'external_resources', 'direct_instruction', 'guided_practice'],
            $lessons->first()->allSections->pluck('section_type')->all(),
        );
        $this->assertStringContainsString(
            'labeled reference sheet',
            $lessons->first()->allSections->where('section_type', 'external_resources')->first()->content,
        );
        $this->assertTrue($lessons->first()->curriculumComponents->first()->is($context['component']));
        $this->assertTrue($lessons->first()->standardAlignments->first()->is($context['alignment']));
    }

    public function test_only_approved_matching_unit_context_can_generate(): void
    {
        $context = $this->context();
        $this->app->instance(LessonGenerator::class, new FakeLessonGenerator($this->validLessons($context)));
        $context['import']->update(['status' => 'review']);
        $this->expectGenerationFailure(fn () => app(CurriculumUnitLessonGenerationService::class)
            ->generate($context['plan'], $context['unit']));
        $this->assertDatabaseCount('lessons', 0);

        $context['import']->update(['status' => 'approved']);
        $this->expectGenerationFailure(fn () => app(CurriculumUnitLessonGenerationService::class)
            ->generate($context['plan']->fresh(), $context['foreignUnit']));
        $this->assertDatabaseCount('lessons', 0);
    }

    public function test_malformed_output_fails_without_partial_lessons_and_retry_succeeds(): void
    {
        $context = $this->context();
        $malformed = $this->validLessons($context);
        $malformed[0] = new GeneratedLessonData(
            sequence: 1, title: 'Bad', learningObjective: 'short', completionCriteria: 'short',
            estimatedMinutes: 5, sections: [], curriculumComponentIds: [999],
        );
        $this->app->instance(LessonGenerator::class, new FakeLessonGenerator($malformed));
        $this->expectGenerationFailure(fn () => app(CurriculumUnitLessonGenerationService::class)
            ->generate($context['plan'], $context['unit']));
        $this->assertSame('failed', $context['plan']->fresh()->status);
        $this->assertDatabaseCount('lessons', 0);

        $this->app->instance(LessonGenerator::class, new FakeLessonGenerator($this->validLessons($context)));
        $retried = app(CurriculumUnitLessonGenerationService::class)->generate($context['plan']->fresh(), $context['unit']);
        $this->assertSame('draft', $retried->status);
        $this->assertCount(1, $retried->lessons);
    }

    public function test_mid_persistence_failure_rolls_back_every_lesson_and_can_retry(): void
    {
        $context = $this->context();
        $this->app->instance(LessonGenerator::class, new FakeLessonGenerator($this->validLessons($context, 2)));
        $audit = Mockery::mock(AuditService::class);
        $created = 0;
        $audit->shouldReceive('record')->andReturnUsing(function (string $action) use (&$created): void {
            if ($action === 'lesson.created' && ++$created === 2) {
                throw new RuntimeException('simulated persistence failure');
            }
        });
        $this->app->instance(AuditService::class, $audit);
        $this->expectGenerationFailure(fn () => app(CurriculumUnitLessonGenerationService::class)
            ->generate($context['plan'], $context['unit']));
        $this->assertDatabaseCount('lessons', 0);
        $this->assertDatabaseCount('lesson_sections', 0);
        $this->assertSame('failed', $context['plan']->fresh()->status);
    }

    public function test_existing_drafts_and_approved_work_are_never_overwritten(): void
    {
        $context = $this->context();
        $this->app->instance(LessonGenerator::class, new FakeLessonGenerator($this->validLessons($context)));
        $service = app(CurriculumUnitLessonGenerationService::class);
        $plan = $service->generate($context['plan'], $context['unit']);
        $this->expectGenerationFailure(fn () => $service->generate($plan->fresh(), $context['unit']));
        $this->assertDatabaseCount('lessons', 1);

        $lessonPlans = app(LessonPlanService::class);
        $lesson = $plan->lessons()->firstOrFail();
        $lessonPlans->approveLesson($lessonPlans->markLessonReviewed($lesson));
        $approved = $lessonPlans->approve($lessonPlans->markReviewed($plan->fresh()));
        $this->expectGenerationFailure(fn () => $service->generate($approved, $context['foreignUnit']));
        $this->assertSame('approved', $approved->fresh()->status);
        $this->assertDatabaseCount('lessons', 1);
    }

    public function test_generation_route_is_tenant_isolated_and_drafts_are_absent_from_student_portal(): void
    {
        $context = $this->context();
        $this->app->instance(LessonGenerator::class, new FakeLessonGenerator($this->validLessons($context)));
        app(CurriculumUnitLessonGenerationService::class)->generate($context['plan'], $context['unit']);

        [$otherUser, $otherTenant] = $this->adult('Other Academy');
        $this->actingIn($otherUser, $otherTenant)->post(route('lesson-plans.units.generate', [$context['plan'], $context['unit']]))->assertNotFound();

        $studentUser = User::factory()->create(['email' => null, 'username' => 'lesson.student', 'must_change_password' => false]);
        TenantMembership::create(['tenant_id' => $context['tenant']->id, 'user_id' => $studentUser->id, 'role' => 'student', 'status' => 'active']);
        $context['student']->update(['user_id' => $studentUser->id, 'student_access_enabled_at' => now()]);
        $this->actingIn($studentUser, $context['tenant'])->get('/student/learning')->assertInertia(fn (Assert $page) => $page
            ->component('StudentPortal/Learning')->missing('lessons')->missing('lessonPlan'));
    }

    public function test_openai_provider_requests_strict_schema_and_maps_structured_output(): void
    {
        $context = $this->context();
        config()->set('lesson-generation.openai.api_key', 'test-key');
        config()->set('lesson-generation.openai.model', 'test-model');
        $responseLesson = [
            'sequence' => 1, 'title' => 'Structured Lesson',
            'learning_objective' => 'The student will explain the approved concept using evidence.',
            'completion_criteria' => 'The student provides an accurate explanation and supported example.',
            'estimated_minutes' => 90, 'estimated_preparation_minutes' => 15,
            'suggested_sessions' => 2, 'lesson_mode' => 'full',
            'curriculum_component_ids' => [$context['component']->id],
            'curriculum_standard_alignment_ids' => [$context['alignment']->id],
            'sections' => [
                ['section_type' => 'direct_instruction', 'title' => 'Teach', 'content' => 'Explain the approved concept clearly and model how evidence supports an answer.', 'audience' => 'shared', 'estimated_minutes' => 20],
                ['section_type' => 'check_for_understanding', 'title' => 'Check', 'content' => 'Ask the student to explain the idea independently and support it with one example.', 'audience' => 'student', 'estimated_minutes' => 10],
            ],
            'resources' => [
                ['category' => 'student_supply', 'resource_type' => 'supply', 'title' => 'Pencil', 'description' => null, 'delivery_type' => 'physical', 'sort_order' => 1],
                ['category' => 'lesson_resource', 'resource_type' => 'graphic_organizer', 'title' => 'Evidence Organizer', 'description' => 'A printable organizer for the lesson evidence.', 'delivery_type' => 'printable', 'sort_order' => 1],
            ],
        ];
        $responsePayload = [
            'id' => 'resp_test',
            'status' => 'completed',
            'output' => [[
                'type' => 'message',
                'content' => [[
                    'type' => 'output_text',
                    'text' => json_encode(['lessons' => [$responseLesson]]),
                ]],
            ]],
        ];
        Http::fake(['*/responses' => Http::response($responsePayload)]);
        $generationContext = app(\App\Services\LessonGenerationContextBuilder::class)->build($context['plan'], $context['unit']);
        $lessons = app(OpenAiLessonGenerator::class)->generate($generationContext);
        $this->assertSame('Structured Lesson', $lessons[0]->title);
        $this->assertSame(90, $lessons[0]->estimatedMinutes);
        $this->assertSame(15, $lessons[0]->estimatedPreparationMinutes);
        $this->assertSame(2, $lessons[0]->suggestedSessions);
        $this->assertSame('student_supply', $lessons[0]->resources[0]->category);
        $this->assertSame('Evidence Organizer', $lessons[0]->resources[1]->title);
        $this->assertSame('resp_test', $lessons[0]->metadata['provider_response_id']);
        Http::assertSent(function ($request): bool {
            $schemaArray = $request['text']['format']['schema'];
            $schema = json_encode($schemaArray, JSON_THROW_ON_ERROR);
            $resourceVariants = data_get($schemaArray, 'properties.lessons.items.properties.resources.items.anyOf');
            $unsupportedKeywords = [
                'uniqueItems', 'minLength', 'maxLength', 'allOf', 'not',
                'dependentRequired', 'dependentSchemas', 'if', 'then', 'else', 'patternProperties',
            ];

            return $request['model'] === 'test-model'
                && $request['text']['format']['type'] === 'json_schema'
                && $request['text']['format']['strict'] === true
                && collect($unsupportedKeywords)->every(
                    fn (string $keyword) => ! str_contains($schema, '"'.$keyword.'":')
                )
                && is_array($resourceVariants)
                && count($resourceVariants) === 3
                && data_get($resourceVariants, '0.properties.category.enum') === ['student_supply']
                && data_get($resourceVariants, '0.properties.delivery_type.enum') === ['physical']
                && data_get($resourceVariants, '1.properties.category.enum') === ['special_material']
                && data_get($resourceVariants, '1.properties.delivery_type.enum') === ['physical']
                && data_get($resourceVariants, '2.properties.category.enum') === ['lesson_resource']
                && ! in_array('physical', data_get($resourceVariants, '2.properties.delivery_type.enum', []), true)
                && str_contains($schema, 'estimated_preparation_minutes')
                && str_contains($schema, 'suggested_sessions')
                && str_contains($schema, 'student_supply')
                && str_contains($schema, 'lesson_resource')
                && str_contains($schema, 'special_material')
                && str_contains($schema, 'external_resources')
                && str_contains($request['input'][0]['content'], 'approved curriculum context is authoritative')
                && str_contains($request['input'][0]['content'], 'student instructional time only')
                && str_contains($request['input'][0]['content'], 'do not tell the family to locate')
                && str_contains($request['input'][0]['content'], 'theoretical relevance is insufficient')
                && str_contains($request['input'][0]['content'], 'instead of mechanically repeating one template');
        });
    }

    public function test_multi_session_timing_is_valid_and_persists_separately(): void
    {
        $context = $this->context();
        $lesson = $this->validLessons($context)[0];
        $multiSession = new GeneratedLessonData(
            sequence: 1, title: $lesson->title,
            learningObjective: $lesson->learningObjective, completionCriteria: $lesson->completionCriteria,
            estimatedMinutes: 180, estimatedPreparationMinutes: 25, suggestedSessions: 2,
            sections: $lesson->sections, curriculumComponentIds: $lesson->curriculumComponentIds,
            curriculumStandardAlignmentIds: [],
        );
        $this->app->instance(LessonGenerator::class, new FakeLessonGenerator([$multiSession]));

        $plan = app(CurriculumUnitLessonGenerationService::class)->generate($context['plan'], $context['unit']);
        $persisted = $plan->lessons()->sole();

        $this->assertSame(180, $persisted->estimated_minutes);
        $this->assertSame(25, $persisted->estimated_preparation_minutes);
        $this->assertSame(2, $persisted->suggested_sessions);
        $this->assertCount(0, $persisted->standardAlignments);
        $this->assertCount(1, $persisted->curriculumComponents);
    }

    public function test_generation_persists_supplies_lesson_resources_and_special_materials_separately(): void
    {
        $context = $this->context();
        $valid = $this->validLessons($context)[0];
        $lesson = new GeneratedLessonData(
            sequence: 1, title: $valid->title, learningObjective: $valid->learningObjective,
            completionCriteria: $valid->completionCriteria, estimatedMinutes: $valid->estimatedMinutes,
            sections: $valid->sections,
            resources: [
                new GeneratedLessonResourceData('student_supply', 'supply', 'Pencil and eraser', null, 'physical', 1),
                new GeneratedLessonResourceData('lesson_resource', 'worksheet', 'Evidence Organizer', 'A printable evidence organizer.', 'printable', 1),
                new GeneratedLessonResourceData('special_material', 'household_material', 'Cardboard', 'One small reusable piece.', 'physical', 1),
            ],
            curriculumComponentIds: $valid->curriculumComponentIds,
            curriculumStandardAlignmentIds: $valid->curriculumStandardAlignmentIds,
        );
        $this->app->instance(LessonGenerator::class, new FakeLessonGenerator([$lesson]));

        $plan = app(CurriculumUnitLessonGenerationService::class)->generate($context['plan'], $context['unit']);
        $resources = $plan->lessons()->firstOrFail()->resources()->get()->keyBy('category');
        $this->assertSame('not_applicable', $resources['student_supply']->availability_status);
        $this->assertSame('needs_asset', $resources['lesson_resource']->availability_status);
        $this->assertSame('not_applicable', $resources['special_material']->availability_status);
        $this->assertSame('Evidence Organizer', $resources['lesson_resource']->title);
    }

    public function test_output_validator_rejects_malformed_timing_and_sessions(): void
    {
        $context = $this->context();
        $generationContext = app(\App\Services\LessonGenerationContextBuilder::class)->build($context['plan'], $context['unit']);
        $valid = $this->validLessons($context)[0];
        $invalidCases = [
            [9, 10, 1, 'Student instructional time'],
            [601, 10, 4, 'Student instructional time'],
            [45, -1, 1, 'Parent preparation time'],
            [45, 241, 1, 'Parent preparation time'],
            [45, 10, 0, 'Suggested sessions'],
            [45, 10, 5, 'Suggested sessions'],
            [181, 10, 1, 'Suggested sessions'],
        ];

        foreach ($invalidCases as [$studentMinutes, $preparationMinutes, $sessions, $message]) {
            $lesson = new GeneratedLessonData(
                sequence: 1, title: $valid->title,
                learningObjective: $valid->learningObjective, completionCriteria: $valid->completionCriteria,
                estimatedMinutes: $studentMinutes, estimatedPreparationMinutes: $preparationMinutes,
                suggestedSessions: $sessions, sections: $valid->sections,
                curriculumComponentIds: $valid->curriculumComponentIds,
                curriculumStandardAlignmentIds: $valid->curriculumStandardAlignmentIds,
            );

            try {
                app(LessonGenerationOutputValidator::class)->validate([$lesson], $generationContext);
                $this->fail('Expected invalid timing or session output to be rejected.');
            } catch (LessonGenerationException $exception) {
                $this->assertStringContainsString($message, $exception->getMessage());
            }
        }
    }

    public function test_output_validator_rejects_duplicate_component_ids(): void
    {
        $context = $this->context();
        $generationContext = app(\App\Services\LessonGenerationContextBuilder::class)->build($context['plan'], $context['unit']);
        $lesson = $this->validLessons($context)[0];
        $duplicate = new GeneratedLessonData(
            sequence: $lesson->sequence, title: $lesson->title,
            learningObjective: $lesson->learningObjective, completionCriteria: $lesson->completionCriteria,
            estimatedMinutes: $lesson->estimatedMinutes, sections: $lesson->sections,
            curriculumComponentIds: [$context['component']->id, $context['component']->id],
            curriculumStandardAlignmentIds: $lesson->curriculumStandardAlignmentIds,
        );

        $this->expectException(LessonGenerationException::class);
        $this->expectExceptionMessage('Curriculum component IDs must be unique');
        app(LessonGenerationOutputValidator::class)->validate([$duplicate], $generationContext);
    }

    public function test_output_validator_rejects_duplicate_standard_alignment_ids(): void
    {
        $context = $this->context();
        $generationContext = app(\App\Services\LessonGenerationContextBuilder::class)->build($context['plan'], $context['unit']);
        $lesson = $this->validLessons($context)[0];
        $duplicate = new GeneratedLessonData(
            sequence: $lesson->sequence, title: $lesson->title,
            learningObjective: $lesson->learningObjective, completionCriteria: $lesson->completionCriteria,
            estimatedMinutes: $lesson->estimatedMinutes, sections: $lesson->sections,
            curriculumComponentIds: $lesson->curriculumComponentIds,
            curriculumStandardAlignmentIds: [$context['alignment']->id, $context['alignment']->id],
        );

        $this->expectException(LessonGenerationException::class);
        $this->expectExceptionMessage('Curriculum standard alignment IDs must be unique');
        app(LessonGenerationOutputValidator::class)->validate([$duplicate], $generationContext);
    }

    public function test_output_validator_accepts_unique_ids_and_still_rejects_foreign_ids(): void
    {
        $context = $this->context();
        $generationContext = app(\App\Services\LessonGenerationContextBuilder::class)->build($context['plan'], $context['unit']);
        $validator = app(LessonGenerationOutputValidator::class);
        $valid = $this->validLessons($context);

        $this->assertSame($valid, $validator->validate($valid, $generationContext));

        $lesson = $valid[0];
        $foreign = new GeneratedLessonData(
            sequence: $lesson->sequence, title: $lesson->title,
            learningObjective: $lesson->learningObjective, completionCriteria: $lesson->completionCriteria,
            estimatedMinutes: $lesson->estimatedMinutes, sections: $lesson->sections,
            curriculumComponentIds: [999999],
            curriculumStandardAlignmentIds: $lesson->curriculumStandardAlignmentIds,
        );

        try {
            $validator->validate([$foreign], $generationContext);
            $this->fail('Expected foreign curriculum provenance to be rejected.');
        } catch (LessonGenerationException $exception) {
            $this->assertStringContainsString('outside the selected unit', $exception->getMessage());
        }
    }

    public function test_openai_provider_logs_only_sanitized_failure_diagnostics(): void
    {
        $context = $this->context();
        config()->set('lesson-generation.openai.api_key', 'secret-test-key');
        config()->set('lesson-generation.openai.model', 'test-model');
        Log::spy();
        Http::fake(['*/responses' => Http::response(['error' => [
            'type' => 'invalid_request_error',
            'code' => 'invalid_json_schema',
            'param' => 'text.format.schema',
            'message' => "Invalid schema.\nuniqueItems is not permitted.",
        ]], 400, ['x-request-id' => 'req_sanitized_123'])]);
        $generationContext = app(\App\Services\LessonGenerationContextBuilder::class)->build($context['plan'], $context['unit']);

        try {
            app(OpenAiLessonGenerator::class)->generate($generationContext);
            $this->fail('Expected the provider request to fail.');
        } catch (LessonGenerationException $exception) {
            $this->assertSame('The lesson provider could not complete generation. No lessons were saved.', $exception->getMessage());
        }

        Log::shouldHaveReceived('warning')->once()->with(
            'Lesson provider request failed.',
            Mockery::on(function (array $diagnostic): bool {
                $this->assertSame([
                    'provider', 'status', 'error_type', 'error_code', 'error_param', 'error_message', 'request_id',
                ], array_keys($diagnostic));
                $this->assertSame('openai-responses', $diagnostic['provider']);
                $this->assertSame(400, $diagnostic['status']);
                $this->assertSame('invalid_request_error', $diagnostic['error_type']);
                $this->assertSame('invalid_json_schema', $diagnostic['error_code']);
                $this->assertSame('text.format.schema', $diagnostic['error_param']);
                $this->assertSame('Invalid schema. uniqueItems is not permitted.', $diagnostic['error_message']);
                $this->assertSame('req_sanitized_123', $diagnostic['request_id']);
                $this->assertStringNotContainsString('secret-test-key', json_encode($diagnostic, JSON_THROW_ON_ERROR));

                return true;
            }),
        );
    }

    private function validLessons(array $context, int $count = 1): array
    {
        return collect(range(1, $count))->map(fn ($sequence) => new GeneratedLessonData(
            sequence: $sequence,
            title: "Lesson {$sequence}",
            learningObjective: 'The student will explain the approved concept and apply the selected skill.',
            completionCriteria: 'The student accurately explains the concept and completes the guided check.',
            estimatedMinutes: 45,
            estimatedPreparationMinutes: 10,
            suggestedSessions: 1,
            sections: [
                new GeneratedLessonSectionData('common_materials', 'Pencil, paper, and colored pencils from ordinary household supplies.', 'shared', 'Common materials', null),
                new GeneratedLessonSectionData('external_resources', 'One labeled reference sheet showing the approved concept and the exact features the student must interpret; no URL is assumed.', 'teacher', 'External resource', null),
                new GeneratedLessonSectionData('direct_instruction', 'Explain the approved concept with a clear model and a concrete age-appropriate example.', 'shared', 'Teach', 20),
                new GeneratedLessonSectionData('guided_practice', 'Guide the student through an application, then ask the student to explain each choice.', 'student', 'Practice', 20),
            ],
            curriculumComponentIds: [$context['component']->id],
            curriculumStandardAlignmentIds: [$context['alignment']->id],
        ))->all();
    }

    private function context(): array
    {
        [$user, $tenant] = $this->adult();
        $this->setContext($user, $tenant);
        $grade = GradeLevel::query()->where('code', 'G5')->firstOrFail();
        $student = $tenant->students()->create(['first_name' => 'Student', 'last_name' => 'Example', 'status' => 'active']);
        $year = $tenant->schoolYears()->create(['name' => 'Current Year', 'start_date' => '2026-08-01', 'end_date' => '2027-05-31', 'timezone' => 'UTC', 'status' => 'active']);
        $enrollment = StudentEnrollment::create(['student_id' => $student->id, 'school_year_id' => $year->id, 'grade_level_id' => $grade->id, 'enrollment_date' => '2026-08-01', 'status' => 'active']);
        $provider = EducationProvider::create(['name' => 'Example Provider', 'provider_type' => 'publisher', 'status' => 'active']);
        $framework = StandardsFramework::create(['education_provider_id' => $provider->id, 'name' => 'Example Standards', 'version_label' => '1', 'status' => 'active']);
        $subject = Subject::query()->where('code', 'MATH')->firstOrFail();
        $course = Course::create(['subject_id' => $subject->id, 'standards_framework_id' => $framework->id, 'name' => 'Example Course', 'code' => 'GEN-COURSE', 'minimum_grade_level_id' => $grade->id, 'maximum_grade_level_id' => $grade->id, 'status' => 'draft']);
        $package = CurriculumPackage::create(['education_provider_id' => $provider->id, 'standards_framework_id' => $framework->id, 'name' => 'Generation Curriculum', 'version_label' => '1', 'status' => 'draft']);
        $mapping = $package->courseMappings()->create(['course_id' => $course->id, 'grade_level_id' => $grade->id, 'sort_order' => 1, 'required' => true]);
        $source = AcademicSource::create(['education_provider_id' => $provider->id, 'school_year_id' => $year->id, 'grade_level_id' => $grade->id, 'title' => 'Generation Source', 'source_kind' => 'upload', 'source_category' => 'curriculum', 'authority_level' => 'tenant_created', 'review_status' => 'reviewed', 'processing_status' => 'completed']);
        $file = $source->files()->create(['uploaded_by_user_id' => $user->id, 'version_number' => 1, 'current_key' => 'current', 'is_current' => true, 'disk' => 'local', 'stored_path' => 'test/generation.pdf', 'stored_filename' => 'generation.pdf', 'original_filename' => 'generation.pdf', 'mime_type' => 'application/pdf', 'extension' => 'pdf', 'file_size' => 100, 'checksum_sha256' => str_repeat('b', 64), 'uploaded_at' => now()]);
        $import = CurriculumImport::create(['academic_source_id' => $source->id, 'academic_source_file_id' => $file->id, 'curriculum_package_id' => $package->id, 'curriculum_package_course_id' => $mapping->id, 'subject_id' => $subject->id, 'grade_level_id' => $grade->id, 'school_year_id' => $year->id, 'standards_framework_id' => $framework->id, 'created_by_user_id' => $user->id, 'approved_by_user_id' => $user->id, 'status' => 'approved', 'parser_key' => 'generation-test', 'parser_version' => '1', 'approved_at' => now()]);
        $unitProposal = $import->proposals()->create(['proposal_type' => 'unit', 'included' => true, 'sequence' => 1, 'name' => 'Unit One', 'unit_type' => 'instructional']);
        $unit = CurriculumUnit::create(['curriculum_package_course_id' => $mapping->id, 'name' => 'Unit One', 'summary' => 'A substantive unit summary for generation.', 'sequence' => 1, 'unit_type' => 'instructional', 'included' => true, 'academic_source_id' => $source->id, 'academic_source_file_id' => $file->id, 'curriculum_import_id' => $import->id, 'curriculum_import_proposal_id' => $unitProposal->id, 'parser_key' => 'generation-test', 'parser_version' => '1']);
        $componentProposal = $import->proposals()->create(['parent_proposal_id' => $unitProposal->id, 'proposal_type' => 'component', 'included' => true, 'sequence' => 1, 'name' => 'Core Skill', 'component_type' => 'skill']);
        $component = CurriculumUnitComponent::create(['curriculum_unit_id' => $unit->id, 'component_type' => 'skill', 'name' => 'Core Skill', 'description' => 'Apply the approved skill accurately.', 'sequence' => 1, 'academic_source_id' => $source->id, 'academic_source_file_id' => $file->id, 'curriculum_import_id' => $import->id, 'curriculum_import_proposal_id' => $componentProposal->id, 'parser_key' => 'generation-test', 'parser_version' => '1']);
        $alignment = CurriculumUnitStandardAlignment::create(['curriculum_unit_id' => $unit->id, 'standards_framework_id' => $framework->id, 'standard_code' => 'STD.1', 'normalized_code' => 'STD.1', 'academic_source_id' => $source->id, 'academic_source_file_id' => $file->id, 'curriculum_import_id' => $import->id, 'curriculum_import_proposal_id' => $unitProposal->id, 'parser_key' => 'generation-test', 'parser_version' => '1']);
        $foreignImport = CurriculumImport::create(['academic_source_id' => $source->id, 'academic_source_file_id' => $file->id, 'subject_id' => $subject->id, 'grade_level_id' => $grade->id, 'school_year_id' => $year->id, 'standards_framework_id' => $framework->id, 'import_type' => 'standards', 'created_by_user_id' => $user->id, 'approved_by_user_id' => $user->id, 'status' => 'approved', 'parser_key' => 'foreign-test', 'parser_version' => '1', 'approved_at' => now()]);
        $foreignProposal = $foreignImport->proposals()->create(['proposal_type' => 'unit', 'included' => true, 'sequence' => 2, 'name' => 'Foreign Unit', 'unit_type' => 'instructional']);
        $foreignUnit = CurriculumUnit::create(['curriculum_package_course_id' => $mapping->id, 'name' => 'Foreign Unit', 'sequence' => 2, 'unit_type' => 'instructional', 'included' => true, 'academic_source_id' => $source->id, 'academic_source_file_id' => $file->id, 'curriculum_import_id' => $foreignImport->id, 'curriculum_import_proposal_id' => $foreignProposal->id, 'parser_key' => 'foreign-test', 'parser_version' => '1']);
        $plan = app(LessonPlanService::class)->createDraft($enrollment, $import);

        return compact('user', 'tenant', 'student', 'year', 'grade', 'enrollment', 'subject', 'import', 'unit', 'foreignUnit', 'component', 'alignment', 'plan');
    }

    private function expectGenerationFailure(callable $callback): void
    {
        try { $callback(); $this->fail('Expected generation validation failure.'); }
        catch (ValidationException $exception) { $this->assertNotEmpty($exception->errors()); }
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

class FakeLessonGenerator implements LessonGenerator
{
    public ?LessonGenerationContext $context = null;

    public function __construct(private readonly array $lessons, private readonly ?LessonGenerationException $failure = null) {}
    public function key(): string { return 'fake-lessons'; }
    public function version(): string { return '1'; }
    public function generate(LessonGenerationContext $context): array
    {
        $this->context = $context;
        if ($this->failure) { throw $this->failure; }
        return $this->lessons;
    }
}
