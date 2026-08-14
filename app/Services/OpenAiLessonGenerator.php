<?php

namespace App\Services;

use App\Contracts\LessonGenerator;
use App\Data\GeneratedLessonData;
use App\Data\GeneratedLessonSectionData;
use App\Data\GeneratedLessonResourceData;
use App\Data\LessonGenerationContext;
use App\Exceptions\LessonGenerationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use JsonException;
use Throwable;

class OpenAiLessonGenerator implements LessonGenerator
{
    public function __construct(private readonly LessonGenerationInstructionStrategy $strategy) {}

    public function key(): string
    {
        return 'openai-responses';
    }

    public function version(): string
    {
        return (string) config('lesson-generation.openai.model');
    }

    public function generate(LessonGenerationContext $context): array
    {
        $apiKey = trim((string) config('lesson-generation.openai.api_key'));
        if ($apiKey === '') {
            throw LessonGenerationException::configuration();
        }

        try {
            $response = Http::withToken($apiKey)->acceptJson()->asJson()
                ->timeout((int) config('lesson-generation.openai.timeout', 180))
                ->post(rtrim((string) config('lesson-generation.openai.base_url'), '/').'/responses', [
                    'model' => $this->version(),
                    'input' => [
                        ['role' => 'system', 'content' => $this->systemPrompt($context)],
                        ['role' => 'user', 'content' => $this->contextPrompt($context)],
                    ],
                    'text' => ['format' => [
                        'type' => 'json_schema', 'name' => 'curriculum_unit_lessons',
                        'strict' => true, 'schema' => $this->schema(),
                    ]],
                    'max_output_tokens' => (int) config('lesson-generation.openai.max_output_tokens', 24000),
                ])->throw();
        } catch (ConnectionException|RequestException $exception) {
            $response = $exception instanceof RequestException ? $exception->response : null;
            $error = $response?->json('error');
            $error = is_array($error) ? $error : [];
            Log::warning('Lesson provider request failed.', [
                'provider' => $this->key(),
                'status' => $response?->status(),
                'error_type' => $this->sanitizedDiagnostic($error['type'] ?? null),
                'error_code' => $this->sanitizedDiagnostic($error['code'] ?? null),
                'error_param' => $this->sanitizedDiagnostic($error['param'] ?? null),
                'error_message' => $this->sanitizedDiagnostic($error['message'] ?? null, 1000),
                'request_id' => $this->sanitizedDiagnostic($response?->header('x-request-id')),
            ]);
            throw LessonGenerationException::provider();
        }

        $payload = $response->json();
        if (($payload['status'] ?? null) !== 'completed') {
            throw LessonGenerationException::incomplete();
        }
        $content = collect($payload['output'] ?? [])->where('type', 'message')
            ->flatMap(fn ($item) => $item['content'] ?? []);
        if ($content->contains(fn ($item) => ($item['type'] ?? null) === 'refusal')) {
            throw LessonGenerationException::refused();
        }
        $outputItem = $content->first(fn ($item) => ($item['type'] ?? null) === 'output_text');
        $outputText = is_array($outputItem) ? ($outputItem['text'] ?? null) : null;
        if (! is_string($outputText) || trim($outputText) === '') {
            throw LessonGenerationException::malformed();
        }

        try {
            $decoded = json_decode($outputText, true, 512, JSON_THROW_ON_ERROR);

            return array_map(fn (array $lesson) => new GeneratedLessonData(
                sequence: $lesson['sequence'],
                title: $lesson['title'],
                learningObjective: $lesson['learning_objective'],
                completionCriteria: $lesson['completion_criteria'],
                estimatedMinutes: $lesson['estimated_minutes'],
                estimatedPreparationMinutes: $lesson['estimated_preparation_minutes'],
                suggestedSessions: $lesson['suggested_sessions'],
                lessonMode: $lesson['lesson_mode'],
                sections: array_map(fn (array $section) => new GeneratedLessonSectionData(
                    sectionType: $section['section_type'], content: $section['content'],
                    audience: $section['audience'], title: $section['title'],
                    estimatedMinutes: $section['estimated_minutes'],
                ), $lesson['sections']),
                resources: array_map(fn (array $resource) => new GeneratedLessonResourceData(
                    category: $resource['category'], resourceType: $resource['resource_type'],
                    title: $resource['title'], description: $resource['description'],
                    deliveryType: $resource['delivery_type'], sortOrder: $resource['sort_order'],
                ), $lesson['resources']),
                curriculumComponentIds: $lesson['curriculum_component_ids'],
                curriculumStandardAlignmentIds: $lesson['curriculum_standard_alignment_ids'],
                metadata: ['provider_response_id' => $payload['id'] ?? null],
            ), $decoded['lessons'] ?? []);
        } catch (Throwable $exception) {
            if ($exception instanceof LessonGenerationException) {
                throw $exception;
            }
            throw LessonGenerationException::malformed();
        }
    }

    private function systemPrompt(LessonGenerationContext $context): string
    {
        $guidance = implode("\n- ", $this->strategy->guidance($context));

        return <<<PROMPT
You create draft, teacher-ready homeschool lessons for one student and one approved curriculum unit.

The approved curriculum context is authoritative. Treat its named topics, concepts, skills, milestones, assessments, and exact standards as curriculum scope. Separately provide the explanations, commonly accepted examples, practice, and teaching strategies needed to teach that scope. Do not claim an instructional example came from the approved source unless the context says so. Do not invent unrelated curriculum, facts presented as source requirements, or standards. If source detail is limited, say so in teacher guidance rather than converting every objective into a generic skill exercise.

Generate only full-mode lessons appropriate to the supplied enrolled grade. Determine a reasonable lesson count from curriculum scope; do not assume one lesson per unit or component. Each lesson must contain enough substance for a parent/teacher to teach it, including what to explain, a model or example when appropriate, what the student does, and how understanding is checked.

Report estimated_minutes as student instructional time only. Report estimated_preparation_minutes separately for parent preparation, locating or printing resources, setup, and cleanup. Report suggested_sessions as 1 for a normal sitting and more than 1 when the workload realistically needs multiple instructional sessions. For assessments and projects, prefer reviewing or revising existing products when appropriate, distinguish creation, revision, presentation, and reflection, and do not stack too many new deliverables into one sitting.

Use a one-student homeschool setting with home-accessible materials. Do not assume classroom stations, groups of students, classroom supply sets, multiple teachers, or school-only equipment unless the curriculum requires it. Represent every material need in resources, never as one flat materials list. Use category student_supply only for ordinary items the student is expected to have, such as pencils, erasers, rulers, colored pencils, and paper. Use category lesson_resource for instructional artifacts the application should provide, such as maps, passages, worksheets, source excerpts, organizers, timelines, charts, diagrams, and checklists. Describe each lesson resource precisely enough to create or attach it; do not tell the family to locate a generic map, atlas, source, or worksheet. Use category special_material only for real-world or household items the application cannot provide. Do not invent URLs, fabricate source documents, or imply a resource was supplied by the curriculum when it was not. Generated lessons are drafts requiring human review and must never be presented as approved.

Use only curriculum_component_ids and curriculum_standard_alignment_ids present in the supplied JSON. Attach a component or standard only when the lesson meaningfully teaches, practices, or assesses it; theoretical relevance is insufficient, and broad process standards should not be attached automatically. Preserve lesson order with consecutive sequence values beginning at 1.

Preserve strong instructional structure, but choose sections according to the learning need instead of mechanically repeating one template. Inquiry lessons may move through question, prediction, investigation, observation, explanation, and reflection. Project lessons may use goal, demonstration, build, test, revise, and deliverable. Language lessons may use vocabulary, listening, modeled speaking, guided speaking, reading, and writing. Source-based lessons may use context, source examination, modeling, evidence analysis, and response. Use only section types from the schema and include the modeling, practice, action, and understanding checks that the actual lesson requires.

Component-sensitive guidance:
- {$guidance}
PROMPT;
    }

    private function contextPrompt(LessonGenerationContext $context): string
    {
        return "Generate a complete lesson sequence for this one approved unit. Return only the required structured response.\n\n"
            .json_encode($context->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'lessons' => [
                    'type' => 'array', 'minItems' => 1, 'maxItems' => 12,
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'sequence' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 12],
                            'title' => ['type' => 'string'],
                            'learning_objective' => ['type' => 'string'],
                            'completion_criteria' => ['type' => 'string'],
                            'estimated_minutes' => ['type' => 'integer', 'minimum' => 10, 'maximum' => 600],
                            'estimated_preparation_minutes' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 240],
                            'suggested_sessions' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10],
                            'lesson_mode' => ['type' => 'string', 'enum' => ['full']],
                            'curriculum_component_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                            'curriculum_standard_alignment_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                            'sections' => [
                                'type' => 'array', 'minItems' => 2, 'maxItems' => 16,
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'section_type' => ['type' => 'string', 'enum' => LessonGenerationOutputValidator::SECTION_TYPES],
                                        'title' => ['type' => ['string', 'null']],
                                        'content' => ['type' => 'string'],
                                        'audience' => ['type' => 'string', 'enum' => ['teacher', 'student', 'shared']],
                                        'estimated_minutes' => ['type' => ['integer', 'null'], 'minimum' => 1, 'maximum' => 180],
                                    ],
                                    'required' => ['section_type', 'title', 'content', 'audience', 'estimated_minutes'],
                                    'additionalProperties' => false,
                                ],
                            ],
                            'resources' => [
                                'type' => 'array', 'maxItems' => 24,
                                'items' => [
                                    'anyOf' => [
                                        $this->resourceSchema(
                                            'student_supply',
                                            ['supply', 'other'],
                                            ['physical'],
                                        ),
                                        $this->resourceSchema(
                                            'special_material',
                                            ['household_material', 'other'],
                                            ['physical'],
                                        ),
                                        $this->resourceSchema(
                                            'lesson_resource',
                                            ['blank_map', 'reference_map', 'worksheet', 'passage', 'graphic_organizer', 'timeline', 'source_excerpt', 'chart', 'diagram', 'checklist', 'photograph', 'dataset', 'other'],
                                            ['embedded', 'viewable', 'printable', 'downloadable', 'interactive'],
                                        ),
                                    ],
                                ],
                            ],
                        ],
                        'required' => [
                            'sequence', 'title', 'learning_objective', 'completion_criteria',
                            'estimated_minutes', 'estimated_preparation_minutes', 'suggested_sessions',
                            'lesson_mode', 'curriculum_component_ids',
                            'curriculum_standard_alignment_ids', 'sections', 'resources',
                        ],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['lessons'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @param  list<string>  $resourceTypes
     * @param  list<string>  $deliveryTypes
     * @return array<string, mixed>
     */
    private function resourceSchema(string $category, array $resourceTypes, array $deliveryTypes): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'category' => ['type' => 'string', 'enum' => [$category]],
                'resource_type' => ['type' => 'string', 'enum' => $resourceTypes],
                'title' => ['type' => 'string'],
                'description' => ['type' => ['string', 'null']],
                'delivery_type' => ['type' => 'string', 'enum' => $deliveryTypes],
                'sort_order' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 24],
            ],
            'required' => ['category', 'resource_type', 'title', 'description', 'delivery_type', 'sort_order'],
            'additionalProperties' => false,
        ];
    }

    private function sanitizedDiagnostic(mixed $value, int $limit = 255): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = preg_replace('/[\r\n\t]+/', ' ', trim((string) $value));

        return $value === '' ? null : Str::limit($value, $limit, '...');
    }
}
