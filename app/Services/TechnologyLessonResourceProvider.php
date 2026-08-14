<?php

namespace App\Services;

use App\Contracts\LessonResourceFulfillmentProvider;
use App\Data\FulfilledLessonResourceData;
use App\Models\LessonResource;
use RuntimeException;

class TechnologyLessonResourceProvider implements LessonResourceFulfillmentProvider
{
    public function key(): string { return 'technology_lesson_foundation'; }
    public function strategy(): string { return 'deterministic_generation'; }

    public function supports(LessonResource $resource): bool
    {
        return $resource->category === 'lesson_resource'
            && in_array(data_get($resource->metadata, 'technology_foundation_asset'), [
                'python_print_reference', 'variable_reference', 'input_flow_reference',
                'spacecraft_profile_reference', 'numeric_resource_reference',
            ], true);
    }

    public function fulfill(LessonResource $resource): FulfilledLessonResourceData
    {
        $asset = data_get($resource->metadata, 'technology_foundation_asset');
        if (! $this->supports($resource)) throw new RuntimeException('The Technology lesson resource is not configured.');
        $payload = [
            'schema' => 'technology_instructional_resource_v1',
            'kind' => 'interactive_reference',
            'provenance' => 'Created by Learning-App from the approved Technology Unit 1 Lesson 1 print(), strings, and statement-order scope.',
            'concepts' => [
                'statement' => 'One instruction in a program.',
                'string' => 'Text placed inside matching quotation marks.',
                'print' => 'A Python function that displays a value.',
                'sequence' => 'The order in which instructions run, normally from top to bottom.',
            ],
            'safe_preview' => [
                'scope' => 'Only simple print statements containing quoted text are interpreted for an output preview.',
                'not_execution' => true,
                'unsupported_code' => 'Any other Python is shown as unsupported and is never sent to or executed by the Laravel server.',
            ],
            'anatomy' => [
                ['part' => 'print', 'job' => 'Names the display function.'],
                ['part' => 'parentheses', 'job' => 'Hold the value the function receives.'],
                ['part' => 'quotation marks', 'job' => 'Mark the beginning and end of a text string.'],
                ['part' => 'string', 'job' => 'Contains the exact mission message to display.'],
            ],
            'workflow' => [
                'Read the statements from the first line downward.',
                'Predict which quoted message will appear first, second, third, and fourth.',
                'Use the safe statement preview to compare the displayed lines with the prediction.',
                'Move or edit one statement at a time, then preview and explain what changed.',
                'Save the four-line briefing as the first milestone in the Astronaut & Spacecraft Mission Builder.',
            ],
            'troubleshooting' => [
                'Each lesson-preview line must contain one print statement.',
                'Put the message inside matching single or double quotation marks.',
                'Close both parentheses after the quoted message.',
                'If a line is unsupported, compare it with the starter pattern before changing another line.',
            ],
            'starter_code' => "print(\"MISSION: ORBITAL EXPLORER\")\nprint(\"Launch sequence started\")\nprint(\"Destination: Moon\")\nprint(\"Objective: Study the lunar surface\")",
        ];
        $filename = 'python-print-reference.json';
        if ($asset !== 'python_print_reference') {
            $definition = match ($asset) {
                'variable_reference' => [
                    'title' => 'Stored Mission Data Guide',
                    'provenance' => 'Created by Learning-App from the approved Technology Lesson 2 variables, strings, meaningful names, output, and reassignment scope.',
                    'concepts' => ['stored_information' => 'A program can keep information so it can use it later.', 'variable' => 'A meaningful name that refers to stored information.', 'assignment' => 'An equals sign stores the value on its right under the name on its left.', 'reassignment' => 'Using the same name again replaces its earlier stored value.'],
                    'starter_code' => "destination = \"Moon\"\nprint(\"Destination:\", destination)\ndestination = \"Mars\"\nprint(\"Updated destination:\", destination)",
                    'workflow' => ['Observe the displayed destination.', 'Predict what changes after the second destination line.', 'Change one stored text value.', 'Preview again and compare.', 'Use meaningful underscore-separated names for four mission details.'],
                ],
                'input_flow_reference' => [
                    'title' => 'Input–Store–Display Flow Guide',
                    'provenance' => 'Created by Learning-App from the approved Technology Lesson 3 input prompts, stored responses, and astronaut-profile scope.',
                    'concepts' => ['prompt' => 'A clear question telling the user what to type.', 'input' => 'Pauses for a keyboard response in a real Python runtime.', 'store' => 'Keeps the response under a meaningful variable name.', 'display' => 'Uses print() to show the stored response later.'],
                    'starter_code' => "commander_name = input(\"Enter commander name: \" )\nprint(\"Commander:\", commander_name)",
                    'workflow' => ['Read the prompt.', 'Provide a simulated test response.', 'Trace the response into its variable.', 'Preview the labeled output.', 'Build four clear astronaut-profile questions.'],
                ],
                'spacecraft_profile_reference' => [
                    'title' => 'Spacecraft Profile Data Guide',
                    'provenance' => 'Created by Learning-App from the approved Technology Lesson 4 multiple-string-variable and spacecraft-profile scope.',
                    'concepts' => ['related_data' => 'Separate names keep related spacecraft details from replacing one another.', 'meaningful_names' => 'Names such as rocket_name explain their purpose.', 'underscores' => 'Underscores connect words because variable names cannot contain spaces.', 'matching_labels' => 'Each output label must display the intended stored value.'],
                    'starter_code' => "rocket_name = \"Pathfinder\"\ncall_sign = \"Silver Comet\"\nprint(\"Rocket:\", rocket_name)\nprint(\"Call sign:\", call_sign)",
                    'workflow' => ['Observe a two-item profile.', 'Find a crossed-label mistake.', 'Repair the label-to-variable match.', 'Add the five required profile values.', 'Preview and verify every label.'],
                ],
                'numeric_resource_reference' => [
                    'title' => 'Spacecraft Numeric Resources Guide',
                    'provenance' => 'Created by Learning-App from the approved Technology Lesson 5 integers, decimal values, numeric resources, and reassignment scope.',
                    'concepts' => ['text' => 'Quotation marks make a value text, even when the characters look like a number.', 'integer' => 'A whole-number value such as 3 or 100.', 'decimal' => 'A numeric value with a decimal point, commonly represented by Python as a float.', 'numeric_update' => 'Reassignment replaces an earlier resource number with a new one.'],
                    'starter_code' => "battery_power = 88.5\nprint(\"Battery power:\", battery_power)\nbattery_power = 84.0\nprint(\"Updated battery power:\", battery_power)",
                    'workflow' => ['Compare quoted text with unquoted numbers.', 'Observe an integer and a decimal.', 'Predict an updated resource output.', 'Track five numeric spacecraft resources.', 'Update at least two resources and preview the result.'],
                ],
                default => throw new RuntimeException('The Technology lesson resource is not configured.'),
            };
            $payload = [
                'schema' => 'technology_instructional_resource_v1', 'kind' => 'interactive_reference',
                'title' => $definition['title'], 'provenance' => $definition['provenance'], 'concepts' => $definition['concepts'],
                'safe_preview' => ['scope' => 'Only lesson-approved string or numeric assignments, input() prompts with simulated responses, and print() lines are interpreted.', 'not_execution' => true, 'unsupported_code' => 'Unsupported syntax is rejected and is never sent to or executed by Laravel, PHP, the host operating system, or an external service.'],
                'starter_code' => $definition['starter_code'], 'workflow' => $definition['workflow'],
                'troubleshooting' => ['Read the line identified by the preview.', 'Compare it with the visible starter pattern.', 'Change only one small part.', 'Preview again and observe the result.', 'Use Reset starter code if the structure is no longer recognizable.'],
                'persistence' => ['source_code' => true, 'simulated_inputs' => true, 'prediction' => true, 'reflection' => true, 'autosave' => true],
            ];
            $filename = str_replace('_', '-', $asset).'.json';
        }
        return new FulfilledLessonResourceData(
            contents: json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            filename: $filename, mimeType: 'application/json',
            sourceUrl: rtrim((string) config('app.url'), '/').'/internal-instructional-assets',
            sourceAttribution: 'Learning-App original instructional content derived from the approved Technology lesson scope',
            licenseName: 'Learning-App original instructional content',
            licenseUrl: rtrim((string) config('app.url'), '/').'/internal-instructional-assets',
            providerMetadata: ['resource_schema' => 'technology_instructional_resource_v1', 'content_origin' => 'application_created', 'rendering_version' => 1],
        );
    }
}
