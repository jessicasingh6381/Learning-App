<?php

namespace App\Services;

use App\Contracts\LessonResourceFulfillmentProvider;
use App\Data\FulfilledLessonResourceData;
use App\Models\LessonResource;
use RuntimeException;

class MathLessonResourceProvider implements LessonResourceFulfillmentProvider
{
    public function key(): string { return 'math_lesson_foundation'; }
    public function strategy(): string { return 'deterministic_generation'; }

    public function supports(LessonResource $resource): bool
    {
        return $resource->category === 'lesson_resource'
            && in_array(data_get($resource->metadata, 'math_foundation_asset'), [
                'problem_solving_organizer', 'remainder_tasks', 'tool_representation_guide',
                'connected_representations', 'error_analysis', 'communication_checklist', 'written_response',
            ], true);
    }

    public function fulfill(LessonResource $resource): FulfilledLessonResourceData
    {
        return match (data_get($resource->metadata, 'math_foundation_asset')) {
            'problem_solving_organizer' => $this->graphic('analyze-plan-solve-justify-check-organizer.png', 'Analyze - Plan - Solve - Justify - Check', [
                'Analyze: What is known? What is being asked? Include quantities and units.',
                'Plan: Choose an operation, representation, or tool and explain why it fits.',
                'Solve: Show organized calculations or a representation.',
                'Justify: State the answer with units and connect it to the situation.',
                'Check: Use estimation, an inverse operation, or capacity bounds.',
            ]),
            'remainder_tasks' => $this->graphic('interpreting-remainders-task-reference.png', 'Interpreting Remainders: Capacity Problems', [
                '187 people; each bus holds at most 48 people.',
                '325 sheets needed; each pack contains 40 sheets.',
                '246 photographs; each album page holds 12 photographs.',
                'A remainder represents people, sheets, or photographs that still need a place.',
                'The least whole number of groups must have enough total capacity.',
            ]),
            'tool_representation_guide' => $this->graphic('tool-representation-guide.png', 'Tools and Representations', [
                'Mental math: simplify a relationship you understand.',
                'Estimation: build a benchmark to check reasonableness.',
                'Equation: show operations and equality.',
                'Table: organize repeated or changing quantities.',
                'Labeled bar diagram: show parts, wholes, comparisons, or equal shares.',
            ]),
            'connected_representations' => $this->graphic('connected-representations-reference.png', 'Connect Representations to Equations', [
                'Pantry: 18 crates × 24 cans, shared among 9 shelves.',
                'Reading challenge: 16 teams × 35 books, shared among 8 shelves.',
                'Garden: 24 rows × 15 plants, shared among 6 sections.',
                'Every number and visual part should have a label or explained meaning.',
                'A representation helps only when it makes a relationship clearer.',
            ]),
            'error_analysis' => $this->graphic('mathematical-error-analysis-reference.png', 'Notice - Test - Explain - Revise', [
                'Notice the claim and every mathematical step.',
                'Test the calculations and whether the result fits the situation.',
                'Explain the exact point where reasoning succeeds or fails.',
                'Revise the flawed step and write a complete conclusion.',
                'Support conclusions with equations, labels, and a check.',
            ]),
            'communication_checklist' => $this->graphic('mathematical-communication-checklist.png', 'Mathematical Communication Checklist', [
                'Identify the quantities and the question.',
                'Connect an equation or representation to the situation.',
                'Label or explain every important number.',
                'Use accurate, organized calculations and units.',
                'Check the result and revise at least one part for clarity.',
            ]),
            'written_response' => $this->graphic('launch-written-response-reference.png', 'Draft, Check, and Revise an Argument', [
                'First draft: state the plan, calculations, and conclusion.',
                'Check: verify quantities, equations, units, and reasonableness.',
                'Name one strength in the first draft.',
                'Make one purposeful revision for accuracy or clarity.',
                'Final response: make the reasoning understandable without oral help.',
            ]),
            default => throw new RuntimeException('The Math lesson resource is not configured.'),
        };
    }

    private function graphic(string $filename, string $title, array $lines): FulfilledLessonResourceData
    {
        $canvas = imagecreatetruecolor(1600, 1000);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        $ink = imagecolorallocate($canvas, 31, 53, 75);
        $blue = imagecolorallocate($canvas, 226, 239, 251);
        imagefill($canvas, 0, 0, $white);
        imagestring($canvas, 5, 55, 40, $title, $ink);
        foreach ($lines as $index => $line) {
            $y = 115 + ($index * 165);
            imagefilledrectangle($canvas, 50, $y, 1550, $y + 120, $blue);
            imagestring($canvas, 5, 75, $y + 48, $line, $ink);
        }
        ob_start(); imagepng($canvas); $contents = (string) ob_get_clean(); imagedestroy($canvas);

        return new FulfilledLessonResourceData(
            contents: $contents, filename: $filename, mimeType: 'image/png',
            sourceUrl: rtrim((string) config('app.url'), '/').'/internal-instructional-assets',
            sourceAttribution: 'Deterministically generated by Learning-App from the approved Math lesson content',
            licenseName: 'Learning-App internal instructional asset',
            licenseUrl: rtrim((string) config('app.url'), '/').'/internal-instructional-assets',
            providerMetadata: ['rendering_version' => 1, 'structured_source' => 'approved_math_lesson_blueprint'],
        );
    }
}
