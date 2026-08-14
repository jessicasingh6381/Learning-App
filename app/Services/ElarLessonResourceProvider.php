<?php

namespace App\Services;

use App\Contracts\LessonResourceFulfillmentProvider;
use App\Data\FulfilledLessonResourceData;
use App\Models\LessonResource;
use RuntimeException;

class ElarLessonResourceProvider implements LessonResourceFulfillmentProvider
{
    public function key(): string { return 'elar_lesson_foundation'; }
    public function strategy(): string { return 'deterministic_generation'; }

    public function supports(LessonResource $resource): bool
    {
        return $resource->category === 'lesson_resource'
            && in_array(data_get($resource->metadata, 'elar_foundation_asset'), [
                'active_reading_passage', 'active_reading_toolkit', 'mara_folding_cart_passage',
                'central_idea_summary_guide', 'point_of_view_inference_guide',
            ], true);
    }

    public function fulfill(LessonResource $resource): FulfilledLessonResourceData
    {
        $asset = data_get($resource->metadata, 'elar_foundation_asset');
        $payload = match ($asset) {
            'active_reading_passage' => [
                'schema' => 'elar_instructional_resource_v1',
                'kind' => 'reading_passage',
                'provenance' => ElarLessonContent::passage()['source_note'],
                'content' => ElarLessonContent::passage(),
            ],
            'active_reading_toolkit' => [
                'schema' => 'elar_instructional_resource_v1',
                'kind' => 'interactive_reference',
                'provenance' => 'Created by Learning-App from the approved Lesson 1 monitor-and-clarify and syllable-pattern scope.',
                'routine' => ElarLessonContent::routine(),
                'syllable_patterns' => ElarLessonContent::syllablePatterns(),
            ],
            'mara_folding_cart_passage' => [
                'schema' => 'elar_instructional_resource_v1',
                'kind' => 'reading_passage',
                'provenance' => ElarLessonContent::maraPassage()['source_note'],
                'content' => ElarLessonContent::maraPassage(),
            ],
            'central_idea_summary_guide' => [
                'schema' => 'elar_instructional_resource_v1',
                'kind' => 'interactive_reference',
                'provenance' => 'Created by Learning-App from the approved Lesson 2 central-idea, supporting-detail, summary, monitor-and-clarify, and text-evidence scope.',
                'concepts' => [
                    'topic' => 'The broad subject, usually a word or short phrase.',
                    'central_idea' => 'The most important point the author develops about the topic across the text.',
                    'key_detail' => 'An important event or fact that explains, proves, or illustrates the central idea.',
                    'summary' => 'A brief, objective account of the central idea and only the most important details, stated in the reader’s own words.',
                ],
                'workflow' => [
                    'Name the broad topic without mistaking it for a complete central idea.',
                    'Track repeated problems, actions, results, and lessons across the whole passage.',
                    'Use the importance test to keep details that support the larger point and omit colorful but unnecessary details.',
                    'State a central idea that fits the beginning, middle, and end of the passage.',
                    'Paraphrase the central idea and major details in logical order without adding opinions or invented information.',
                ],
                'model' => 'The orange tape is true but minor because removing it does not change Mara’s problem, testing process, result, or lesson. Recording a failed test is important because it begins a repeated pattern of using setbacks to improve the design.',
            ],
            'point_of_view_inference_guide' => [
                'schema' => 'elar_instructional_resource_v1',
                'kind' => 'interactive_reference',
                'provenance' => 'Created by Learning-App from the approved Lesson 3 point-of-view, inference, response, and text-evidence scope.',
                'concepts' => [
                    'point_of_view' => 'The position from which a narrator presents the events and information.',
                    'inference' => 'A conclusion formed by combining text evidence with reasoning.',
                    'text_evidence' => 'Specific words, actions, or details in the text that support an idea.',
                ],
                'workflow' => [
                    'Identify whether the narrator uses first-person or third-person language.',
                    'Notice whose actions, thoughts, or feelings the narrator reveals and what remains unknown.',
                    'Separate directly stated information from a conclusion a reader must form.',
                    'State a focused inference that answers the assigned question.',
                    'Select specific evidence, then explain how each detail makes the inference reasonable.',
                ],
                'model' => 'The text states that Mara records the failed side lock and studies the hinge. A reader can infer that she is determined because she turns a setback into information for another attempt instead of abandoning the project.',
            ],
            default => throw new RuntimeException('The ELAR lesson resource is not configured.'),
        };

        return new FulfilledLessonResourceData(
            contents: json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            filename: $asset.'.json',
            mimeType: 'application/json',
            sourceUrl: rtrim((string) config('app.url'), '/').'/internal-instructional-assets',
            sourceAttribution: 'Application-created instructional content generated deterministically by Learning-App from the approved ELAR lesson scope',
            licenseName: 'Learning-App original instructional content',
            licenseUrl: rtrim((string) config('app.url'), '/').'/internal-instructional-assets',
            providerMetadata: [
                'resource_schema' => 'elar_instructional_resource_v1',
                'content_origin' => 'application_created',
                'curriculum_source_text' => false,
                'rendering_version' => 1,
            ],
        );
    }
}
