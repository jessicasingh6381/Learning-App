<?php

namespace App\Services;

use App\Models\Lesson;
use App\Models\LessonActivity;
use App\Models\LessonExperience;
use App\Models\StudentActivityResponse;
use App\Models\StudentEnrollment;
use App\Models\StudentLessonProgress;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LessonExperienceService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly LessonResourceFulfillmentManager $resourceFulfillment,
        private readonly LessonPlanService $lessonPlans,
    ) {}

    public function createFromBlueprint(Lesson $lesson, array $experienceData, array $activities, bool $synchronizePreview = false): LessonExperience
    {
        return DB::transaction(function () use ($lesson, $experienceData, $activities, $synchronizePreview): LessonExperience {
            $lesson->loadMissing('allSections');
            $sectionIds = $lesson->allSections->keyBy('id');
            $experience = $lesson->experience()->firstOrCreate(
                ['lesson_id' => $lesson->id],
                Arr::only($experienceData, ['status', 'theme_key', 'mission_title', 'mission_brief', 'completion_title', 'completion_message', 'source_version'])
            );
            if ($experience->wasRecentlyCreated) {
                $this->audit->record('lesson-experience.created', $experience, [], $experience->toArray());
            }

            if ($synchronizePreview && ! $experience->wasRecentlyCreated) {
                $experience->status === 'preview'
                    || throw ValidationException::withMessages(['lesson' => 'Only a preview experience can be synchronized in place.']);
                $experience->progresses()->where('is_preview', false)->doesntExist()
                    || throw ValidationException::withMessages(['lesson' => 'A student-facing experience cannot be replaced in place.']);
                $experience->progresses()->get()->each->delete();
                $before = $experience->toArray();
                $experience->update(Arr::only($experienceData, ['status', 'theme_key', 'mission_title', 'mission_brief', 'completion_title', 'completion_message', 'source_version']));
                if ($before !== $experience->fresh()->toArray()) {
                    $this->audit->record('lesson-experience.preview-synchronized', $experience, $before, $experience->fresh()->toArray());
                }
            }

            $sequences = [];
            foreach ($activities as $activityData) {
                $source = $sectionIds->get($activityData['source_lesson_section_id'] ?? null);
                if (! $source || $source->audience === 'teacher') {
                    throw ValidationException::withMessages(['activities' => 'Student activities must reference a student-safe section from this lesson.']);
                }
                $sequences[] = $activityData['sequence'];
                $existingActivity = $synchronizePreview
                    ? $experience->activities()->where('sequence', $activityData['sequence'])->first()
                    : null;
                $activityBefore = $existingActivity?->toArray();
                $activity = $synchronizePreview ? $experience->activities()->updateOrCreate(
                    ['sequence' => $activityData['sequence']],
                    Arr::except($activityData, ['sequence'])
                ) : $experience->activities()->firstOrCreate(
                    ['sequence' => $activityData['sequence']],
                    Arr::except($activityData, ['sequence'])
                );
                if ($activity->wasRecentlyCreated) {
                    $this->audit->record('lesson-activity.created', $activity, [], $activity->toArray());
                } elseif ($activityBefore !== null && $activityBefore !== $activity->fresh()->toArray()) {
                    $this->audit->record('lesson-activity.preview-synchronized', $activity, $activityBefore, $activity->fresh()->toArray());
                }
            }

            if ($synchronizePreview) {
                $experience->activities()->whereNotIn('sequence', $sequences)->get()->each->delete();
            }

            return $experience->load('activities');
        });
    }

    public function provisionMapMissionPrototype(Lesson $lesson): LessonExperience
    {
        if ($lesson->title !== 'Reading and Creating Maps of the United States' || $lesson->sequence !== 1) {
            throw ValidationException::withMessages(['lesson' => 'The map mission prototype is reserved for the selected Lesson 1.']);
        }
        $sections = $lesson->allSections()->get()->keyBy('section_type');
        foreach (['hook', 'direct_instruction', 'guided_practice', 'independent_practice', 'exit_check'] as $required) {
            if (! $sections->has($required)) {
                throw ValidationException::withMessages(['lesson' => "The selected lesson is missing its {$required} source section."]);
            }
        }

        $source = fn (string $type): int => $sections->get($type)->id;
        $activities = [
            [
                'sequence' => 1, 'source_lesson_section_id' => $source('hook'), 'activity_type' => 'instruction',
                'display_title' => 'Accept the Map Mission', 'student_instructions' => 'Read your mission, gather the map supplies listed below, and choose Start mission.',
                'content' => 'A fellow explorer needs a United States reference map that is clear enough to use without guessing. Your mission is to decode how maps communicate, then build a map with a title, direction, a legend, labels, and consistent symbols.',
                'interaction_data' => ['student_supplies' => ['Pencil and eraser', 'Ruler and colored pencils', 'Paper']],
                'completion_condition' => ['type' => 'acknowledge'], 'reward_label' => 'Mission accepted', 'theme_key' => 'mission',
            ],
            [
                'sequence' => 2, 'source_lesson_section_id' => $source('direct_instruction'), 'activity_type' => 'instruction',
                'display_title' => 'Pack Your Map-Reader Toolkit', 'student_instructions' => 'Study each map tool. Say its job in your own words before moving on.',
                'content' => 'Mapmakers choose details that fit a purpose. A strong reader checks the title, finds north, studies the legend, reads labels and patterns, and uses scale when distance matters.',
                'interaction_data' => ['facts' => [
                    ['label' => 'Title', 'detail' => 'Names the map’s subject and purpose.'], ['label' => 'Orientation', 'detail' => 'Shows direction, often with a north arrow or compass rose.'],
                    ['label' => 'Legend', 'detail' => 'Explains the map’s colors and symbols.'], ['label' => 'Labels', 'detail' => 'Name places and features.'],
                    ['label' => 'Scale', 'detail' => 'Connects map distance with real distance.'], ['label' => 'Symbols', 'detail' => 'Represent selected information consistently.'],
                ]],
                'completion_condition' => ['type' => 'acknowledge'], 'reward_label' => 'Toolkit packed', 'theme_key' => 'learn',
            ],
            [
                'sequence' => 3, 'source_lesson_section_id' => $source('guided_practice'), 'activity_type' => 'multiple_choice',
                'display_title' => 'Legend Decoder', 'student_instructions' => 'Choose the map element that tells what a blue line or shaded area represents.',
                'content' => 'You notice a blue line on a map, but you do not know what it means. Where should you look first?',
                'interaction_data' => ['choices' => [['id' => 'title', 'label' => 'Title'], ['id' => 'legend', 'label' => 'Legend'], ['id' => 'north_arrow', 'label' => 'North arrow'], ['id' => 'scale', 'label' => 'Scale']]],
                'answer_data' => ['correct' => 'legend'], 'feedback' => ['correct' => 'Exactly. The legend decodes the colors and symbols.', 'incorrect' => 'Try again: look for the map tool that explains symbols and colors.'],
                'completion_condition' => ['type' => 'correct'], 'reward_label' => 'Code cracked', 'hints' => ['Which tool works like a key?'], 'theme_key' => 'challenge',
            ],
            [
                'sequence' => 4, 'source_lesson_section_id' => $source('direct_instruction'), 'activity_type' => 'matching',
                'display_title' => 'Match the Map Tools', 'student_instructions' => 'Match each map tool to the job it performs.',
                'interaction_data' => ['prompts' => [['id' => 'title', 'label' => 'Title'], ['id' => 'orientation', 'label' => 'Orientation'], ['id' => 'legend', 'label' => 'Legend'], ['id' => 'scale', 'label' => 'Scale']], 'options' => [['id' => 'subject', 'label' => 'Identifies the subject'], ['id' => 'direction', 'label' => 'Shows direction'], ['id' => 'symbols', 'label' => 'Explains symbols and colors'], ['id' => 'distance', 'label' => 'Relates map distance to real distance']]],
                'answer_data' => ['matches' => ['title' => 'subject', 'orientation' => 'direction', 'legend' => 'symbols', 'scale' => 'distance']],
                'feedback' => ['correct' => 'All tools matched. Your map-reading kit is ready.', 'incorrect' => 'Some tools are mismatched. Revisit the toolkit cards and try again.'],
                'completion_condition' => ['type' => 'correct'], 'reward_label' => 'Tools mastered', 'hints' => ['Think about the question each tool answers.'], 'theme_key' => 'challenge',
            ],
            [
                'sequence' => 5, 'source_lesson_section_id' => $source('guided_practice'), 'activity_type' => 'short_response',
                'display_title' => 'Read a Real Reference Map', 'student_instructions' => 'Use your reference map—not memory—to record three pieces of evidence.',
                'content' => 'Read the title, orientation, legend, scale, and labels before answering.',
                'interaction_data' => ['map_mode' => 'reference', 'fields' => [
                    ['id' => 'symbol_meaning', 'label' => 'What does the star symbol represent on this map?', 'control' => 'multiple_choice', 'choices' => [
                        ['id' => 'national_capital', 'label' => 'The national capital, Washington, D.C.'],
                        ['id' => 'state_boundary', 'label' => 'A state boundary'],
                        ['id' => 'census_region', 'label' => 'A Census region'],
                    ]],
                    ['id' => 'relative_location', 'label' => 'Is Texas east or west of Florida? Explain how you know using the map.', 'control' => 'short_response'],
                    ['id' => 'limitation', 'label' => 'Does this map show how many people live in each state?', 'control' => 'multiple_choice', 'choices' => [
                        ['id' => 'no_population', 'label' => 'No. The map shows regions and selected places, but it does not show state population numbers.'],
                        ['id' => 'yes_population', 'label' => 'Yes. The region colors show how many people live in each state.'],
                    ]],
                ]],
                'completion_condition' => ['type' => 'required_fields'], 'reward_label' => 'Evidence recorded', 'requires_teacher_review' => true, 'theme_key' => 'practice',
            ],
            [
                'sequence' => 6, 'source_lesson_section_id' => $source('independent_practice'), 'activity_type' => 'project',
                'display_title' => 'Build Your Explorer Reference Map', 'student_instructions' => 'Build your reference map in the digital map builder. Watch the map and completion checks update as you add each required element.',
                'content' => 'Your map must communicate clearly to another reader. Give every marker one consistent meaning and explain it in your legend.',
                'interaction_data' => [
                    'map_mode' => 'builder',
                    'map_builder' => ['minimum_features' => 3, 'allowed_marker_keys' => ['blue_circle', 'gold_star', 'green_triangle', 'purple_square']],
                    'reflection_fields' => [
                        ['id' => 'information_shown', 'label' => 'What information does your map show?'],
                        ['id' => 'symbol_explanation', 'label' => 'Choose one symbol or color. What does it represent on your map?'],
                        ['id' => 'relative_location', 'label' => 'Choose one labeled place. Where is it located relative to another labeled place on your map?'],
                    ],
                ],
                'completion_condition' => ['type' => 'digital_map_submission'], 'reward_label' => 'Map submitted for review', 'requires_teacher_review' => true, 'theme_key' => 'create',
            ],
            [
                'sequence' => 7, 'source_lesson_section_id' => $source('exit_check'), 'activity_type' => 'question_set',
                'display_title' => 'Final Map-Reading Check', 'student_instructions' => 'Answer all three questions without help. You can try again if a tool needs another look.',
                'interaction_data' => ['questions' => [
                    ['id' => 'title_job', 'prompt' => 'Which statement best describes a map title?', 'choices' => [['id' => 'a', 'label' => 'It identifies the map’s subject.'], ['id' => 'b', 'label' => 'It measures real distance.'], ['id' => 'c', 'label' => 'It always points north.']]],
                    ['id' => 'orientation_job', 'prompt' => 'Which tool helps you state that one place is east of another?', 'choices' => [['id' => 'a', 'label' => 'Legend'], ['id' => 'b', 'label' => 'Orientation indicator'], ['id' => 'c', 'label' => 'Title']]],
                    ['id' => 'consistency', 'prompt' => 'What must be true of every symbol used on your map?', 'choices' => [['id' => 'a', 'label' => 'It appears in the legend with the same meaning.'], ['id' => 'b', 'label' => 'It uses a different color each time.'], ['id' => 'c', 'label' => 'It replaces all labels.']]],
                ]],
                'answer_data' => ['answers' => ['title_job' => 'a', 'orientation_job' => 'b', 'consistency' => 'a']],
                'feedback' => ['correct' => 'Field check passed. You can explain and use the essential map tools.', 'incorrect' => 'One or more tools need another look. Review the toolkit, then try the field check again.'],
                'completion_condition' => ['type' => 'correct'], 'reward_label' => 'Field check passed', 'theme_key' => 'check',
            ],
        ];

        $experience = $this->createFromBlueprint($lesson, [
            'status' => 'preview', 'theme_key' => 'social-studies-explorer',
            'mission_title' => 'U.S. Mapmaker Mission',
            'mission_brief' => 'Decode the tools mapmakers use, gather evidence from a real map, and create a reference map another explorer could actually follow.',
            'completion_title' => 'Map Mission Complete',
            'completion_message' => 'You decoded the map tools and submitted a reference map for your teacher to inspect. No grade or mastery score has been assigned.',
            'source_version' => 'lesson-1-map-mission-v1',
        ], $activities);
        $this->provisionMapMissionResourceRequirements($lesson);

        return $experience;
    }

    public function provisionRegionsMissionPrototype(Lesson $lesson): LessonExperience
    {
        if ($lesson->title !== 'Physical and Political Regions of the United States' || $lesson->sequence !== 2) {
            throw ValidationException::withMessages(['lesson' => 'The regions mission is reserved for the selected Lesson 2.']);
        }
        $sections = $lesson->allSections()->get()->keyBy('section_type');
        foreach (['introduction', 'direct_instruction', 'example', 'guided_practice', 'independent_practice', 'check_for_understanding'] as $required) {
            if (! $sections->has($required)) {
                throw ValidationException::withMessages(['lesson' => "The selected lesson is missing its {$required} source section."]);
            }
        }

        $source = fn (string $type): int => $sections->get($type)->id;
        $activities = [
            [
                'sequence' => 1, 'source_lesson_section_id' => $source('introduction'), 'activity_type' => 'instruction',
                'display_title' => 'Compare Two Views of the United States',
                'student_instructions' => 'Study both maps beside this explanation. Compare what each map emphasizes, then confirm when you can explain the difference.',
                'content' => 'A physical map emphasizes natural features and elevation. A political map emphasizes human-created areas and places, such as state boundaries, state names, and a national capital. The same country can be mapped differently because each map has a different purpose.',
                'interaction_data' => ['map_mode' => 'comparison'],
                'completion_condition' => ['type' => 'acknowledge'], 'reward_label' => 'Maps compared', 'theme_key' => 'learn',
            ],
            [
                'sequence' => 2, 'source_lesson_section_id' => $source('direct_instruction'), 'activity_type' => 'matching',
                'display_title' => 'Physical or Political?',
                'student_instructions' => 'Use the maps directly above the choices. Classify all six visible features by what they represent.',
                'content' => 'Natural land and water features are physical. Human-created boundaries and officially named government places are political.',
                'interaction_data' => ['map_mode' => 'comparison', 'prompts' => [
                    ['id' => 'rocky_mountains', 'label' => 'Rocky Mountains'], ['id' => 'great_plains', 'label' => 'Great Plains'],
                    ['id' => 'great_lakes', 'label' => 'Great Lakes'], ['id' => 'texas_oklahoma', 'label' => 'Boundary between Texas and Oklahoma'],
                    ['id' => 'california', 'label' => 'California state area'], ['id' => 'washington_dc', 'label' => 'Washington, D.C., national capital'],
                ], 'options' => [['id' => 'physical', 'label' => 'Physical feature'], ['id' => 'political', 'label' => 'Political feature']]],
                'answer_data' => ['matches' => ['rocky_mountains' => 'physical', 'great_plains' => 'physical', 'great_lakes' => 'physical', 'texas_oklahoma' => 'political', 'california' => 'political', 'washington_dc' => 'political']],
                'feedback' => ['correct' => 'All six features are classified correctly.', 'incorrect' => 'Try again. Ask whether each feature comes from nature or from a human-created government boundary or place.'],
                'completion_condition' => ['type' => 'correct'], 'reward_label' => 'Features classified', 'theme_key' => 'challenge',
            ],
            [
                'sequence' => 3, 'source_lesson_section_id' => $source('direct_instruction'), 'activity_type' => 'instruction',
                'display_title' => 'How a Region Is Made',
                'student_instructions' => 'Read the four-step method and inspect the Census-region example. Notice that its criterion groups complete states.',
                'content' => 'A region is an area grouped by a shared characteristic. First name the organizing criterion. Next find places that match it. Then identify the region boundary. Finally cite visible map evidence. On this example, the criterion is the U.S. Census Bureau regional classification, so complete states are grouped and colored together. A landform criterion could produce different boundaries.',
                'interaction_data' => ['map_mode' => 'reference'],
                'completion_condition' => ['type' => 'acknowledge'], 'reward_label' => 'Region method learned', 'theme_key' => 'learn',
            ],
            [
                'sequence' => 4, 'source_lesson_section_id' => $source('example'), 'activity_type' => 'multiple_choice',
                'display_title' => 'Find the Criterion and Evidence',
                'student_instructions' => 'Use the visible Census-region map and its legend to choose the evidence that supports the grouping.',
                'content' => 'Why can Texas and Florida be grouped together on this particular reference map?',
                'interaction_data' => ['map_mode' => 'reference', 'choices' => [
                    ['id' => 'south_legend', 'label' => 'The legend and matching color place both states in the South Census region.'],
                    ['id' => 'same_state', 'label' => 'They are two labels for the same state.'],
                    ['id' => 'highest_land', 'label' => 'Both states contain the map’s highest white elevations.'],
                ]],
                'answer_data' => ['correct' => 'south_legend'],
                'feedback' => ['correct' => 'Correct. The stated criterion, legend, and matching color provide the evidence.', 'incorrect' => 'Try again using the legend color shown for Texas and Florida.'],
                'completion_condition' => ['type' => 'correct'], 'reward_label' => 'Evidence found', 'theme_key' => 'challenge',
            ],
            [
                'sequence' => 5, 'source_lesson_section_id' => $source('guided_practice'), 'activity_type' => 'question_set',
                'display_title' => 'Use Map Evidence',
                'student_instructions' => 'Keep both maps visible while answering each concrete evidence question.',
                'interaction_data' => ['map_mode' => 'comparison', 'questions' => [
                    ['id' => 'high_relief', 'prompt' => 'On the physical map, which labeled feature contains much of the orange, red, and white high-relief land?', 'choices' => [['id' => 'rockies', 'label' => 'Rocky Mountains'], ['id' => 'great_plains', 'label' => 'Great Plains'], ['id' => 'great_lakes', 'label' => 'Great Lakes']]],
                    ['id' => 'shared_boundary', 'prompt' => 'On the political map, what is the line shared by Texas and Oklahoma?', 'choices' => [['id' => 'state_boundary', 'label' => 'A political state boundary'], ['id' => 'mountain_range', 'label' => 'A physical mountain range'], ['id' => 'river_only', 'label' => 'A river with no political meaning']]],
                    ['id' => 'criterion_change', 'prompt' => 'If a mapmaker changes the criterion from complete states to high-elevation land, what can happen to the region boundary?', 'choices' => [['id' => 'can_change', 'label' => 'It can change because different places match the new criterion.'], ['id' => 'never_changes', 'label' => 'It must stay on the same state boundaries.'], ['id' => 'no_criterion', 'label' => 'A region does not need a criterion.']]],
                ]],
                'answer_data' => ['answers' => ['high_relief' => 'rockies', 'shared_boundary' => 'state_boundary', 'criterion_change' => 'can_change']],
                'feedback' => ['correct' => 'You used physical, political, and criterion evidence correctly.', 'incorrect' => 'One answer needs another look. Use the labels, boundary lines, and elevation-color key on the maps.'],
                'completion_condition' => ['type' => 'correct'], 'reward_label' => 'Evidence applied', 'theme_key' => 'challenge',
            ],
            [
                'sequence' => 6, 'source_lesson_section_id' => $source('independent_practice'), 'activity_type' => 'project',
                'display_title' => 'Build a Regional Reference Layer',
                'student_instructions' => 'Build three colored regions from complete states. Name your criterion and regions, then use map evidence to defend one boundary.',
                'content' => 'Choose one clear organizing criterion. Add two different states to each of three regions. The map will create the shading, state labels, boundaries, and legend for you.',
                'interaction_data' => [
                    'map_mode' => 'region_builder',
                    'region_builder' => ['minimum_regions' => 3, 'minimum_states_per_region' => 2, 'color_keys' => ['teal', 'gold', 'coral']],
                    'reflection_fields' => [
                        ['id' => 'boundary_evidence', 'label' => 'Choose one region. What visible location or map evidence supports grouping those states together?'],
                        ['id' => 'different_criterion', 'label' => 'If you used a physical-landform criterion instead, how might one boundary change?'],
                    ],
                ],
                'completion_condition' => ['type' => 'digital_region_map_submission'], 'reward_label' => 'Regional layer submitted', 'requires_teacher_review' => true, 'theme_key' => 'create',
            ],
            [
                'sequence' => 7, 'source_lesson_section_id' => $source('check_for_understanding'), 'activity_type' => 'question_set',
                'display_title' => 'Regions Field Check',
                'student_instructions' => 'Answer all three questions. Correct answers complete the lesson; an incorrect answer can be retried.',
                'interaction_data' => ['map_mode' => 'reference', 'questions' => [
                    ['id' => 'physical', 'prompt' => 'Which is physical information?', 'choices' => [['id' => 'mountain', 'label' => 'A mountain range'], ['id' => 'state', 'label' => 'A state boundary'], ['id' => 'capital', 'label' => 'A national capital']]],
                    ['id' => 'political', 'prompt' => 'Which is political information?', 'choices' => [['id' => 'lake', 'label' => 'A naturally formed lake'], ['id' => 'plain', 'label' => 'A broad plain'], ['id' => 'state', 'label' => 'A state boundary']]],
                    ['id' => 'region', 'prompt' => 'What makes a regional boundary defensible?', 'choices' => [['id' => 'criterion_evidence', 'label' => 'A stated criterion supported by visible map evidence'], ['id' => 'random', 'label' => 'A random line with no explanation'], ['id' => 'single_system', 'label' => 'Using the only regional system that can exist']]],
                ]],
                'answer_data' => ['answers' => ['physical' => 'mountain', 'political' => 'state', 'region' => 'criterion_evidence']],
                'feedback' => ['correct' => 'Field check passed. You can distinguish map information and defend a regional choice.', 'incorrect' => 'Review whether the information is natural or political, and remember that a region needs a criterion and evidence.'],
                'completion_condition' => ['type' => 'correct'], 'reward_label' => 'Field check passed', 'theme_key' => 'check',
            ],
        ];

        $experience = $this->createFromBlueprint($lesson, [
            'status' => 'preview', 'theme_key' => 'social-studies-explorer',
            'mission_title' => 'U.S. Regions Evidence Mission',
            'mission_brief' => 'Compare physical and political maps, learn how criteria create regions, and build a defensible regional map layer.',
            'completion_title' => 'Regions Mission Complete',
            'completion_message' => 'You classified physical and political information and submitted a regional map supported by evidence.',
            'source_version' => 'lesson-2-regions-mission-v1',
        ], $activities);
        $this->provisionRegionsMissionResources($lesson);

        return $experience;
    }

    private function provisionRegionsMissionResources(Lesson $lesson): void
    {
        $resources = [
            ['category' => 'lesson_resource', 'resource_type' => 'interactive_us_map', 'title' => 'Interactive United States Political Map', 'description' => 'Authoritative state boundaries and labels for political-map and region activities.', 'delivery_type' => 'interactive', 'availability_status' => 'needs_asset', 'sort_order' => 1, 'metadata' => ['supported_modes' => ['comparison', 'reference', 'region_builder']]],
            ['category' => 'lesson_resource', 'resource_type' => 'physical_us_map', 'title' => 'United States Physical Relief Map', 'description' => 'A USGS topography image showing elevation patterns, mountain ranges, plains, and major water areas.', 'delivery_type' => 'viewable', 'availability_status' => 'needs_asset', 'sort_order' => 2, 'metadata' => ['student_experience_required' => true]],
        ];
        foreach ($resources as $data) {
            $resource = $lesson->resources()->firstOrCreate(['category' => $data['category'], 'sort_order' => $data['sort_order']], $data);
            if ($resource->wasRecentlyCreated) {
                $this->audit->record('lesson-resource.regions-mission-defined', $resource, [], $resource->toArray());
            }
        }
        if (config('lesson-resources.automatic_fulfillment')) {
            $this->resourceFulfillment->fulfillRequiredForLesson($lesson);
        }
    }

    public function provisionSettlementMissionPrototype(Lesson $lesson): LessonExperience
    {
        if ($lesson->title !== 'Geographic Data and Settlement Patterns' || $lesson->sequence !== 3) {
            throw ValidationException::withMessages(['lesson' => 'The settlement evidence mission is reserved for the selected Lesson 3.']);
        }
        $sections = $lesson->allSections()->get()->keyBy('section_type');
        foreach (['hook', 'direct_instruction', 'example', 'guided_practice', 'independent_practice', 'exit_check'] as $required) {
            if (! $sections->has($required)) {
                throw ValidationException::withMessages(['lesson' => "The selected lesson is missing its {$required} source section."]);
            }
        }

        $source = fn (string $type): int => $sections->get($type)->id;
        $map = ['map_mode' => 'settlement_data'];
        $activities = [
            [
                'sequence' => 1, 'source_lesson_section_id' => $source('hook'), 'activity_type' => 'instruction',
                'display_title' => 'Why Do People Settle in Some Places?',
                'student_instructions' => 'Study the population-density and physical maps. Notice where people are concentrated, then consider factors that could influence those patterns.',
                'content' => 'Water, landforms, climate, transportation, work, resources, and communities can influence settlement. A map pattern can suggest a question or possible explanation, but one map alone does not prove why people chose a place.',
                'interaction_data' => $map, 'completion_condition' => ['type' => 'acknowledge'], 'reward_label' => 'Patterns noticed', 'theme_key' => 'learn',
            ],
            [
                'sequence' => 2, 'source_lesson_section_id' => $source('direct_instruction'), 'activity_type' => 'instruction',
                'display_title' => 'Observation, Pattern, or Inference?',
                'student_instructions' => 'Learn the three evidence moves, then use them in order.',
                'content' => 'An observation states a visible fact, such as a labeled density value. A pattern compares two or more observations. An inference offers a possible explanation supported by evidence. Careful geographers use words such as may, might, possible, or could when the maps do not prove a cause.',
                'interaction_data' => $map, 'completion_condition' => ['type' => 'acknowledge'], 'reward_label' => 'Evidence moves learned', 'theme_key' => 'learn',
            ],
            [
                'sequence' => 3, 'source_lesson_section_id' => $source('example'), 'activity_type' => 'matching',
                'display_title' => 'Sort the Evidence Moves', 'student_instructions' => 'Classify each statement using the maps and evidence values shown above.',
                'interaction_data' => $map + [
                    'prompts' => [
                        ['id' => 'visible_value', 'label' => 'The map labels Wyoming with fewer people per square mile than New York.'],
                        ['id' => 'comparison', 'label' => 'The labeled eastern states on this map are denser than the labeled western states.'],
                        ['id' => 'possible_cause', 'label' => 'Access to water or transportation might influence where people settled.'],
                    ],
                    'options' => [['id' => 'observation', 'label' => 'Observation'], ['id' => 'pattern', 'label' => 'Pattern'], ['id' => 'inference', 'label' => 'Inference']],
                ],
                'answer_data' => ['matches' => ['visible_value' => 'observation', 'comparison' => 'pattern', 'possible_cause' => 'inference']],
                'feedback' => ['correct' => 'Correct. You separated visible evidence, a comparison pattern, and a possible explanation.', 'incorrect' => 'Try again: an observation is directly visible, a pattern compares evidence, and an inference is a possible explanation.'],
                'completion_condition' => ['type' => 'correct'], 'reward_label' => 'Evidence sorted', 'theme_key' => 'challenge',
            ],
            [
                'sequence' => 4, 'source_lesson_section_id' => $source('guided_practice'), 'activity_type' => 'multiple_choice',
                'display_title' => 'Choose a Direct Observation', 'student_instructions' => 'Choose only the statement that the displayed data directly supports.',
                'content' => 'Which statement is a direct observation rather than an inference?',
                'interaction_data' => $map + ['choices' => [
                    ['id' => 'density', 'label' => 'New York has a higher displayed population density than Wyoming.'],
                    ['id' => 'jobs', 'label' => 'New York is denser only because it has more jobs.'],
                    ['id' => 'preference', 'label' => 'People in Wyoming do not like living near one another.'],
                ]],
                'answer_data' => ['correct' => 'density'],
                'feedback' => ['correct' => 'Exactly. Both density values are visible; the map does not prove the other claims.', 'incorrect' => 'Try again. Choose the claim you can verify directly from the displayed density values.'],
                'completion_condition' => ['type' => 'correct'], 'reward_label' => 'Observation verified', 'theme_key' => 'challenge',
            ],
            [
                'sequence' => 5, 'source_lesson_section_id' => $source('guided_practice'), 'activity_type' => 'question_set',
                'display_title' => 'Compare Visible Settlement Evidence', 'student_instructions' => 'Keep both maps visible and answer from their labels, legend, and physical features.',
                'interaction_data' => $map + ['questions' => [
                    ['id' => 'highest', 'prompt' => 'Which labeled state has the highest displayed population density?', 'choices' => [['id' => 'new_york', 'label' => 'New York'], ['id' => 'texas', 'label' => 'Texas'], ['id' => 'wyoming', 'label' => 'Wyoming']]],
                    ['id' => 'lowest', 'prompt' => 'Which labeled state has the lowest displayed population density?', 'choices' => [['id' => 'florida', 'label' => 'Florida'], ['id' => 'california', 'label' => 'California'], ['id' => 'wyoming', 'label' => 'Wyoming']]],
                    ['id' => 'claim', 'prompt' => 'What can the population and physical maps support?', 'choices' => [['id' => 'possible', 'label' => 'Physical geography may be one influence on settlement, but more evidence is needed.'], ['id' => 'proof', 'label' => 'Elevation alone proves exactly why every person chose a home.'], ['id' => 'none', 'label' => 'The maps contain no information that can be compared.']]],
                ]],
                'answer_data' => ['answers' => ['highest' => 'new_york', 'lowest' => 'wyoming', 'claim' => 'possible']],
                'feedback' => ['correct' => 'Good evidence work. You compared visible values and kept the conclusion appropriately cautious.', 'incorrect' => 'Recheck the labeled values and remember that two maps can suggest, but not prove, a cause.'],
                'completion_condition' => ['type' => 'correct'], 'reward_label' => 'Evidence compared', 'theme_key' => 'challenge',
            ],
            [
                'sequence' => 6, 'source_lesson_section_id' => $source('independent_practice'), 'activity_type' => 'project',
                'display_title' => 'Build a Settlement Evidence Organizer',
                'student_instructions' => 'Use the maps to record two observations, two patterns, one cautious inference, and one limitation. Your work saves automatically.',
                'content' => 'Begin with what the maps directly show. Compare those observations before offering a possible geographic influence. Finish by naming evidence you would still need.',
                'interaction_data' => $map + ['analysis_builder' => ['location_choices' => [
                    ['state_fips' => '06', 'label' => 'California'], ['state_fips' => '12', 'label' => 'Florida'],
                    ['state_fips' => '36', 'label' => 'New York'], ['state_fips' => '48', 'label' => 'Texas'], ['state_fips' => '56', 'label' => 'Wyoming'],
                ]]],
                'completion_condition' => ['type' => 'digital_evidence_analysis_submission'], 'reward_label' => 'Evidence organizer submitted', 'requires_teacher_review' => true, 'theme_key' => 'create',
            ],
            [
                'sequence' => 7, 'source_lesson_section_id' => $source('exit_check'), 'activity_type' => 'question_set',
                'display_title' => 'Settlement Evidence Check', 'student_instructions' => 'Identify a direct observation, a supported inference, and an unsupported guess.',
                'interaction_data' => $map + ['questions' => [
                    ['id' => 'observation', 'prompt' => 'Which statement is directly visible on the map?', 'choices' => [['id' => 'ny_wy', 'label' => 'New York has a higher displayed density than Wyoming.'], ['id' => 'reason', 'label' => 'Jobs caused every New York settlement.'], ['id' => 'future', 'label' => 'Wyoming will become the densest state next year.']]],
                    ['id' => 'inference', 'prompt' => 'Which is a properly cautious inference?', 'choices' => [['id' => 'may', 'label' => 'Physical geography may influence some settlement patterns.'], ['id' => 'proves', 'label' => 'The physical map proves the only cause of settlement.'], ['id' => 'random', 'label' => 'Settlement has no relationship to any geographic factor.']]],
                    ['id' => 'limitation', 'prompt' => 'Why can these two maps not prove one cause?', 'choices' => [['id' => 'more', 'label' => 'They do not show every historical, economic, and human factor; more sources are needed.'], ['id' => 'color', 'label' => 'Maps with color never contain evidence.'], ['id' => 'labels', 'label' => 'State labels make comparison impossible.']]],
                ]],
                'answer_data' => ['answers' => ['observation' => 'ny_wy', 'inference' => 'may', 'limitation' => 'more']],
                'feedback' => ['correct' => 'Field check passed. You can distinguish observations, patterns, and supported inferences.', 'incorrect' => 'Try again. Direct evidence is visible, while a supported inference stays cautious and acknowledges limits.'],
                'completion_condition' => ['type' => 'correct'], 'reward_label' => 'Evidence check passed', 'theme_key' => 'check',
            ],
        ];

        $experience = $this->createFromBlueprint($lesson, [
            'status' => 'preview', 'theme_key' => 'social-studies-explorer',
            'mission_title' => 'Settlement Evidence Mission',
            'mission_brief' => 'Use authoritative population-density and physical maps to identify settlement patterns and make a careful geographic inference.',
            'completion_title' => 'Settlement Evidence Mission Complete',
            'completion_message' => 'You used visible data to separate observations, patterns, and supported inferences. Your evidence organizer is saved for review.',
            'source_version' => 'lesson-3-settlement-mission-v1',
        ], $activities);
        $this->provisionSettlementMissionResources($lesson);

        return $experience;
    }

    public function provisionEarthProcessesMissionPrototype(Lesson $lesson): LessonExperience
    {
        if ($lesson->title !== 'Introducing Earth Processes as Connected Systems' || $lesson->sequence !== 1) {
            throw ValidationException::withMessages(['lesson' => 'The Earth-processes mission is reserved for the selected Science Lesson 1.']);
        }
        $sections = $lesson->allSections()->get()->keyBy('section_type');
        foreach (['hook', 'direct_instruction', 'guided_practice', 'independent_practice', 'exit_check'] as $required) {
            if (! $sections->has($required)) {
                throw ValidationException::withMessages(['lesson' => "The selected lesson is missing its {$required} source section."]);
            }
        }

        $source = fn (string $type): int => $sections->get($type)->id;
        $processTerms = ['sunlight', 'water', 'wind', 'ice', 'rock', 'weathering', 'erosion', 'deposition', 'sediment', 'sedimentary rock'];
        $relationships = ['breaks', 'carries', 'drops', 'heats', 'cools', 'builds up'];
        $allowedConnections = [
            ['from' => 'water', 'relationship' => 'breaks', 'to' => 'rock'],
            ['from' => 'weathering', 'relationship' => 'breaks', 'to' => 'rock'],
            ['from' => 'water', 'relationship' => 'carries', 'to' => 'sediment'],
            ['from' => 'wind', 'relationship' => 'carries', 'to' => 'sediment'],
            ['from' => 'ice', 'relationship' => 'carries', 'to' => 'rock'],
            ['from' => 'erosion', 'relationship' => 'carries', 'to' => 'sediment'],
            ['from' => 'water', 'relationship' => 'drops', 'to' => 'sediment'],
            ['from' => 'deposition', 'relationship' => 'drops', 'to' => 'sediment'],
            ['from' => 'sediment', 'relationship' => 'builds up', 'to' => 'sedimentary rock'],
            ['from' => 'sunlight', 'relationship' => 'heats', 'to' => 'water'],
        ];
        $activities = [
            [
                'sequence' => 1, 'source_lesson_section_id' => $source('hook'), 'activity_type' => 'instruction',
                'display_title' => 'Investigate a Changing Coast',
                'student_instructions' => 'Study the two USGS photographs of the same Florida coast before and after Hurricane Ian. Use only visible evidence at first.',
                'content' => 'An observation names something the image actually shows. An explanation proposes why it changed. In the AFTER image, look for less vegetation, strips of sand across the land, and a changed shoreline. Those are observations; moving storm water causing erosion and deposition is an explanation scientists can test.',
                'interaction_data' => ['science_visual' => 'coastal_change'],
                'completion_condition' => ['type' => 'acknowledge'], 'reward_label' => 'Evidence inspected', 'theme_key' => 'learn',
            ],
            [
                'sequence' => 2, 'source_lesson_section_id' => $source('hook'), 'activity_type' => 'question_set',
                'display_title' => 'Observation or Explanation?', 'student_instructions' => 'Keep the photographs visible. Choose the statement that matches each question.',
                'interaction_data' => ['science_visual' => 'coastal_change', 'questions' => [
                    ['id' => 'visible', 'prompt' => 'Which change is directly visible in the AFTER photograph?', 'choices' => [['id' => 'sand', 'label' => 'More strips of pale sand cross the land.'], ['id' => 'wind_speed', 'label' => 'The wind blew at exactly 120 miles per hour.'], ['id' => 'years', 'label' => 'The change took ten years.']]],
                    ['id' => 'explanation', 'prompt' => 'Which statement is a possible explanation rather than a direct observation?', 'choices' => [['id' => 'water_moved', 'label' => 'Storm water may have moved and dropped sand.'], ['id' => 'two_images', 'label' => 'The evidence contains two photographs.'], ['id' => 'after_less_green', 'label' => 'The AFTER image has less green vegetation.']]],
                ]],
                'answer_data' => ['answers' => ['visible' => 'sand', 'explanation' => 'water_moved']],
                'feedback' => ['correct' => 'Correct. You separated visible evidence from a testable explanation.', 'incorrect' => 'Try again. An observation is visible in the photograph; an explanation suggests a cause.'],
                'completion_condition' => ['type' => 'correct'], 'reward_label' => 'Evidence sorted', 'theme_key' => 'challenge',
            ],
            [
                'sequence' => 3, 'source_lesson_section_id' => $source('direct_instruction'), 'activity_type' => 'instruction',
                'display_title' => 'Meet Earth’s Change Team',
                'student_instructions' => 'Study each process and agent. Say what changes and what moves before continuing.',
                'content' => 'A process is a series of changes. Weathering breaks rock into smaller pieces. Erosion carries those pieces, called sediment, to a new place. Deposition drops sediment when water, wind, or ice slows. Sunlight supplies energy that drives water movement and weather. Layers of sediment can build up, compact, and cement into sedimentary rock over time.',
                'interaction_data' => ['science_visual' => 'process_model', 'facts' => [
                    ['label' => 'Weathering', 'detail' => 'breaks Earth material'], ['label' => 'Erosion', 'detail' => 'moves sediment'],
                    ['label' => 'Deposition', 'detail' => 'drops sediment'], ['label' => 'Agents', 'detail' => 'water, wind, and moving ice'],
                    ['label' => 'Energy and time', 'detail' => 'sunlight drives cycles; long periods allow layers and rock to form'],
                ]],
                'completion_condition' => ['type' => 'acknowledge'], 'reward_label' => 'Processes learned', 'theme_key' => 'learn',
            ],
            [
                'sequence' => 4, 'source_lesson_section_id' => $source('direct_instruction'), 'activity_type' => 'matching',
                'display_title' => 'Follow Rain’s Cause-and-Effect Chain', 'student_instructions' => 'Match each event to the Earth-process name it demonstrates.',
                'content' => 'Rain loosens pieces of exposed rock. Runoff carries the pieces downhill. Slower water drops them at the bottom.',
                'interaction_data' => ['science_visual' => 'cause_chain', 'prompts' => [
                    ['id' => 'loosen', 'label' => 'Rock pieces break loose'], ['id' => 'carry', 'label' => 'Runoff carries pieces downhill'], ['id' => 'drop', 'label' => 'Slower water drops the pieces'],
                ], 'options' => [['id' => 'weathering', 'label' => 'Weathering'], ['id' => 'erosion', 'label' => 'Erosion'], ['id' => 'deposition', 'label' => 'Deposition']]],
                'answer_data' => ['matches' => ['loosen' => 'weathering', 'carry' => 'erosion', 'drop' => 'deposition']],
                'feedback' => ['correct' => 'Chain complete: weathering supplies sediment, erosion moves it, and deposition drops it.', 'incorrect' => 'Try again: first material breaks, then it moves, then it drops.'],
                'completion_condition' => ['type' => 'correct'], 'reward_label' => 'Chain modeled', 'theme_key' => 'challenge',
            ],
            [
                'sequence' => 5, 'source_lesson_section_id' => $source('guided_practice'), 'activity_type' => 'question_set',
                'display_title' => 'Sort the Process Evidence', 'student_instructions' => 'Read each evidence card and identify its main process or moving agent.',
                'interaction_data' => ['science_visual' => 'process_cards', 'questions' => [
                    ['id' => 'dune', 'prompt' => 'A sand dune slowly shifts as grains blow across the ground. What is the main moving agent?', 'choices' => [['id' => 'wind', 'label' => 'Wind'], ['id' => 'ice', 'label' => 'Ice'], ['id' => 'sunlight', 'label' => 'Sunlight alone']]],
                    ['id' => 'glacier', 'prompt' => 'A glacier carries rocks downhill. What is the main moving agent?', 'choices' => [['id' => 'water', 'label' => 'Liquid water'], ['id' => 'ice', 'label' => 'Moving ice'], ['id' => 'cement', 'label' => 'Cementation']]],
                    ['id' => 'delta', 'prompt' => 'A river slows at its mouth and leaves sediment behind. Which process is occurring?', 'choices' => [['id' => 'weathering', 'label' => 'Weathering'], ['id' => 'erosion', 'label' => 'Erosion'], ['id' => 'deposition', 'label' => 'Deposition']]],
                    ['id' => 'rock', 'prompt' => 'Sediment layers are pressed and bound together over time. What can form?', 'choices' => [['id' => 'sedimentary', 'label' => 'Sedimentary rock'], ['id' => 'wind', 'label' => 'Wind'], ['id' => 'runoff', 'label' => 'Runoff']]],
                ]],
                'answer_data' => ['answers' => ['dune' => 'wind', 'glacier' => 'ice', 'delta' => 'deposition', 'rock' => 'sedimentary']],
                'feedback' => ['correct' => 'Every evidence card is connected to the process or agent it shows.', 'incorrect' => 'One card needs another look. Focus on what moves, what drops, or what builds into rock.'],
                'completion_condition' => ['type' => 'correct'], 'reward_label' => 'Evidence connected', 'theme_key' => 'challenge',
            ],
            [
                'sequence' => 6, 'source_lesson_section_id' => $source('independent_practice'), 'activity_type' => 'project',
                'display_title' => 'Build an Earth Systems Map',
                'student_instructions' => 'Choose at least five terms, build three accurate cause-and-effect arrows, and add one investigation question. Your work saves automatically.',
                'content' => 'A systems map shows how parts interact. Each arrow must read as a sensible statement, such as “water carries sediment.”',
                'interaction_data' => ['systems_map_builder' => ['minimum_terms' => 5, 'minimum_connections' => 3, 'terms' => $processTerms, 'relationships' => $relationships, 'allowed_connections' => $allowedConnections]],
                'completion_condition' => ['type' => 'digital_systems_map_submission'], 'reward_label' => 'Systems map built', 'requires_teacher_review' => true, 'theme_key' => 'create',
            ],
            [
                'sequence' => 7, 'source_lesson_section_id' => $source('exit_check'), 'activity_type' => 'short_response',
                'display_title' => 'Explain One Connection',
                'student_instructions' => 'Review your systems map directly above, then explain one arrow as a complete cause-and-effect statement.',
                'interaction_data' => ['reference_activity_sequence' => 6, 'fields' => [['id' => 'connection_explanation', 'label' => 'Choose one arrow from your map. How does the first part cause or affect the next part?']]],
                'completion_condition' => ['type' => 'required_fields'], 'reward_label' => 'Connection explained', 'requires_teacher_review' => true, 'theme_key' => 'check',
            ],
        ];

        $experience = $this->createFromBlueprint($lesson, [
            'status' => 'preview', 'theme_key' => 'science-earth-systems',
            'mission_title' => 'Earth Systems Investigation',
            'mission_brief' => 'Use real coastal evidence to learn how Earth materials break, move, settle, and connect in a changing system.',
            'completion_title' => 'Earth Systems Investigation Complete',
            'completion_message' => 'You used evidence, connected Earth processes, and built a systems map with a question for future investigation.',
            'source_version' => 'science-lesson-1-earth-systems-v1',
        ], $activities);

        $componentLinks = $lesson->curriculumComponents->mapWithKeys(fn ($component) => [$component->id => [
            'role' => $component->pivot->role, 'sequence' => $component->pivot->sequence,
        ]])->all();
        $alignmentIds = $lesson->curriculumUnit->standardAlignments()->pluck('id')->all();
        $this->lessonPlans->syncLessonProvenance($lesson, $componentLinks, $alignmentIds);
        $this->provisionEarthProcessesMissionResources($lesson);

        return $experience->fresh('activities');
    }

    public function provisionWaterCycleMissionPrototype(Lesson $lesson): LessonExperience
    {
        $this->assertScienceLesson($lesson, 2, 'The Sun, Ocean, and Water Cycle');
        $sections = $lesson->allSections()->get()->keyBy('section_type');
        foreach (['question', 'direct_instruction', 'prediction', 'investigation', 'evidence_analysis', 'written_response'] as $required) {
            if (! $sections->has($required)) {
                throw ValidationException::withMessages(['lesson' => "The selected lesson is missing its {$required} source section."]);
            }
        }
        $source = fn (string $type): int => $sections->get($type)->id;
        $activities = [
            [
                'sequence' => 1, 'source_lesson_section_id' => $source('question'), 'activity_type' => 'short_response',
                'display_title' => 'What Keeps Water Moving?',
                'student_instructions' => 'Record your starting idea. This is a prediction, so it is saved without being graded.',
                'content' => 'Water collects in oceans, lakes, rivers, soil, and ice. Think about how some of that water can return to the atmosphere and what might supply the energy.',
                'interaction_data' => ['ungraded' => true, 'fields' => [
                    ['id' => 'return_to_atmosphere', 'label' => 'How might collected water return to the atmosphere?'],
                    ['id' => 'energy_source', 'label' => 'What might provide the energy?'],
                ]],
                'feedback' => ['correct' => 'Your starting explanation is saved. You will compare it with the model as you learn.'],
                'completion_condition' => ['type' => 'required_fields'], 'reward_label' => 'Starting idea saved', 'theme_key' => 'learn',
            ],
            [
                'sequence' => 2, 'source_lesson_section_id' => $source('direct_instruction'), 'activity_type' => 'instruction',
                'display_title' => 'Water-Cycle Processes',
                'student_instructions' => 'Follow water through each process. Pay special attention to where energy enters the system and where water can be stored.',
                'content' => 'Evaporation changes liquid water into invisible water vapor. Cooling water vapor condenses into tiny liquid droplets that can form clouds. Precipitation returns water to Earth. Water collects in oceans and other reservoirs, runs over land, or infiltrates soil. The Sun supplies energy for evaporation, and oceans store a large amount of Earth’s water.',
                'interaction_data' => ['science_visual' => 'water_cycle'],
                'completion_condition' => ['type' => 'acknowledge'], 'reward_label' => 'Processes traced', 'theme_key' => 'learn',
            ],
            [
                'sequence' => 3, 'source_lesson_section_id' => $source('direct_instruction'), 'activity_type' => 'instruction',
                'display_title' => 'Read a Water-Cycle Model',
                'student_instructions' => 'Trace the arrows from ocean to atmosphere, onto land, and back toward the ocean. Notice that water can pause in storage instead of moving in one fixed circle.',
                'content' => 'Start at the ocean: solar energy supports evaporation. Water vapor rises and cools, condensation forms cloud droplets, and precipitation can fall on land. Runoff can flow through rivers toward the ocean, while some water infiltrates soil or remains stored.',
                'interaction_data' => ['science_visual' => 'water_cycle_pathway'],
                'completion_condition' => ['type' => 'acknowledge'], 'reward_label' => 'Model read', 'theme_key' => 'learn',
            ],
            [
                'sequence' => 4, 'source_lesson_section_id' => $source('prediction'), 'activity_type' => 'short_response',
                'display_title' => 'Predict the Covered-Bowl Model',
                'student_instructions' => 'Before the investigation, predict where droplets will appear and name the process you think will cause them. Predictions are not marked right or wrong.',
                'interaction_data' => ['ungraded' => true, 'science_visual' => 'covered_bowl', 'fields' => [
                    ['id' => 'droplet_location', 'label' => 'Where do you predict droplets will appear?'],
                    ['id' => 'process_prediction', 'label' => 'Which water-cycle process could cause those droplets, and why?'],
                ]],
                'feedback' => ['correct' => 'Prediction saved. Now collect evidence and compare it with your idea.'],
                'completion_condition' => ['type' => 'required_fields'], 'reward_label' => 'Prediction saved', 'theme_key' => 'challenge',
            ],
            [
                'sequence' => 5, 'source_lesson_section_id' => $source('investigation'), 'activity_type' => 'project',
                'display_title' => 'Observe Evaporation and Condensation',
                'student_instructions' => 'Have an adult provide the warm water. Build the covered-bowl model, keep it steady, and record what you observe before and after 15–20 minutes. Do not drink the water.',
                'content' => 'Place about 250 mL of warm water in a clear bowl. Seal the top with plastic wrap and a rubber band, then place 4–6 ice cubes on the wrap. The bowl is a small model of selected processes, not a complete atmosphere or ocean.',
                'interaction_data' => ['science_visual' => 'covered_bowl', 'science_work_builder' => [
                    'kind' => 'covered_bowl', 'submit_label' => 'Submit investigation evidence and continue',
                    'sections' => [
                        ['title' => 'Beginning model', 'fields' => [
                            ['id' => 'beginning_observation', 'label' => 'Describe the water, inside of the bowl, and plastic wrap at the start.', 'control' => 'textarea', 'minimum_length' => 8],
                        ]],
                        ['title' => 'Evidence after 15–20 minutes', 'fields' => [
                            ['id' => 'ending_observation', 'label' => 'What changed inside the model?', 'control' => 'textarea', 'minimum_length' => 8],
                            ['id' => 'droplet_evidence', 'label' => 'Where did droplets form or fall?', 'control' => 'textarea', 'minimum_length' => 8],
                            ['id' => 'evaporation_evidence', 'label' => 'What evidence represents evaporation?', 'control' => 'textarea', 'minimum_length' => 8],
                            ['id' => 'condensation_evidence', 'label' => 'What evidence represents condensation?', 'control' => 'textarea', 'minimum_length' => 8],
                            ['id' => 'model_limitation', 'label' => 'Name one way this bowl differs from Earth’s full water cycle.', 'control' => 'textarea', 'minimum_length' => 8],
                        ]],
                    ],
                ]],
                'completion_condition' => ['type' => 'structured_science_work'], 'reward_label' => 'Investigation recorded', 'requires_teacher_review' => true, 'theme_key' => 'create',
            ],
            [
                'sequence' => 6, 'source_lesson_section_id' => $source('evidence_analysis'), 'activity_type' => 'question_set',
                'display_title' => 'Match Evidence to Processes',
                'student_instructions' => 'Use the covered-bowl evidence to identify the modeled process and the model’s limitation.',
                'interaction_data' => ['science_visual' => 'covered_bowl', 'questions' => [
                    ['id' => 'evaporation', 'prompt' => 'Warm liquid water becomes invisible water vapor. Which process does this model?', 'choices' => [['id' => 'evaporation', 'label' => 'Evaporation'], ['id' => 'condensation', 'label' => 'Condensation'], ['id' => 'collection', 'label' => 'Collection']]],
                    ['id' => 'condensation', 'prompt' => 'Water droplets form beneath the cold plastic wrap. Which process does this model?', 'choices' => [['id' => 'precipitation', 'label' => 'Precipitation'], ['id' => 'condensation', 'label' => 'Condensation'], ['id' => 'runoff', 'label' => 'Runoff']]],
                    ['id' => 'falling', 'prompt' => 'A droplet grows and falls back into the bowl. Which process does that best represent?', 'choices' => [['id' => 'precipitation', 'label' => 'Precipitation'], ['id' => 'infiltration', 'label' => 'Infiltration'], ['id' => 'evaporation', 'label' => 'Evaporation']]],
                    ['id' => 'limitation', 'prompt' => 'Which is a real limitation of the bowl model?', 'choices' => [['id' => 'small_model', 'label' => 'It does not include Earth’s full atmosphere, land, rivers, and ocean.'], ['id' => 'no_water', 'label' => 'It contains no water.'], ['id' => 'proves_all_weather', 'label' => 'It proves every weather event happens the same way.']]],
                ]],
                'answer_data' => ['answers' => ['evaporation' => 'evaporation', 'condensation' => 'condensation', 'falling' => 'precipitation', 'limitation' => 'small_model']],
                'feedback' => ['correct' => 'Correct. You connected each observation to a process and recognized what the model cannot show.', 'incorrect' => 'Try again. Match each visible change to the process definition, and remember that a bowl is only a small model.'],
                'completion_condition' => ['type' => 'correct'], 'reward_label' => 'Evidence matched', 'theme_key' => 'challenge',
            ],
            [
                'sequence' => 7, 'source_lesson_section_id' => $source('written_response'), 'activity_type' => 'project',
                'display_title' => 'Build and Explain the Water Cycle',
                'student_instructions' => 'Label each part of the digital model, then explain how the Sun and ocean help keep water moving.',
                'interaction_data' => ['science_visual' => 'water_cycle_unlabeled', 'science_work_builder' => [
                    'kind' => 'water_cycle_model', 'submit_label' => 'Submit water-cycle model and continue',
                    'sections' => [[ 'title' => 'Label the model', 'fields' => [
                        ['id' => 'upward_arrow', 'label' => '1. Liquid water moving upward as vapor', 'control' => 'select', 'choices' => [['id' => 'evaporation', 'label' => 'Evaporation'], ['id' => 'condensation', 'label' => 'Condensation'], ['id' => 'precipitation', 'label' => 'Precipitation']]],
                        ['id' => 'cloud_droplets', 'label' => '2. Vapor cooling into tiny droplets', 'control' => 'select', 'choices' => [['id' => 'collection', 'label' => 'Collection'], ['id' => 'condensation', 'label' => 'Condensation'], ['id' => 'runoff', 'label' => 'Runoff']]],
                        ['id' => 'downward_water', 'label' => '3. Water falling from clouds', 'control' => 'select', 'choices' => [['id' => 'precipitation', 'label' => 'Precipitation'], ['id' => 'infiltration', 'label' => 'Infiltration'], ['id' => 'evaporation', 'label' => 'Evaporation']]],
                        ['id' => 'stored_water', 'label' => '4. Water gathered in the ocean or another reservoir', 'control' => 'select', 'choices' => [['id' => 'collection', 'label' => 'Collection'], ['id' => 'condensation', 'label' => 'Condensation'], ['id' => 'precipitation', 'label' => 'Precipitation']]],
                        ['id' => 'energy_source', 'label' => '5. Main energy source for evaporation', 'control' => 'select', 'choices' => [['id' => 'sun', 'label' => 'The Sun'], ['id' => 'cloud', 'label' => 'A cloud'], ['id' => 'soil', 'label' => 'Soil']]],
                    ]], [ 'title' => 'Explain the system', 'fields' => [
                        ['id' => 'cycle_explanation', 'label' => 'Use evaporation, condensation, precipitation, and collection to explain how solar energy and the ocean help keep water moving.', 'control' => 'textarea', 'minimum_length' => 40],
                    ]]],
                    'expected_values' => ['upward_arrow' => 'evaporation', 'cloud_droplets' => 'condensation', 'downward_water' => 'precipitation', 'stored_water' => 'collection', 'energy_source' => 'sun'],
                ]],
                'completion_condition' => ['type' => 'structured_science_work'], 'reward_label' => 'Cycle modeled', 'requires_teacher_review' => true, 'theme_key' => 'check',
            ],
        ];

        $experience = $this->createFromBlueprint($lesson, [
            'status' => 'preview', 'theme_key' => 'science-water-cycle', 'mission_title' => 'Water-Cycle Systems Lab',
            'mission_brief' => 'Use a digital model and a covered-bowl investigation to trace how solar energy moves water through interacting parts of Earth’s system.',
            'completion_title' => 'Water-Cycle Systems Lab Complete',
            'completion_message' => 'You modeled water-cycle processes, used evidence from an investigation, and explained the roles of the Sun and ocean.',
            'source_version' => 'science-lesson-2-water-cycle-v1',
        ], $activities);
        $this->provisionScienceMissionResources($lesson, [
            'Grade 5 Water-Cycle Diagram' => ['delivery_type' => 'interactive', 'asset' => 'water_cycle_diagram'],
            'Covered-Bowl Water-Cycle Investigation Sheet' => ['delivery_type' => 'embedded', 'asset' => 'covered_bowl_sheet'],
        ], ['Clear bowl', 'Warm water', 'Plastic wrap and rubber band', 'Ice cubes', 'Towel']);
        return $experience->fresh('activities');
    }

    public function provisionWeatherInteractionsMissionPrototype(Lesson $lesson): LessonExperience
    {
        $this->assertScienceLesson($lesson, 3, 'Water-Cycle Interactions and Weather');
        $sections = $lesson->allSections()->get()->keyBy('section_type');
        foreach (['context', 'vocabulary', 'investigation', 'evidence_analysis', 'guided_practice', 'exit_check'] as $required) {
            if (! $sections->has($required)) throw ValidationException::withMessages(['lesson' => "The selected lesson is missing its {$required} source section."]);
        }
        $source = fn (string $type): int => $sections->get($type)->id;
        $dataset = [
            ['time' => 'Day 1, 8 a.m.', 'air' => 22, 'water' => 25, 'humidity' => 64, 'cloud' => 20, 'precipitation' => 0.0],
            ['time' => 'Day 1, noon', 'air' => 29, 'water' => 26, 'humidity' => 58, 'cloud' => 15, 'precipitation' => 0.0],
            ['time' => 'Day 1, 4 p.m.', 'air' => 31, 'water' => 27, 'humidity' => 62, 'cloud' => 30, 'precipitation' => 0.0],
            ['time' => 'Day 1, 8 p.m.', 'air' => 26, 'water' => 27, 'humidity' => 72, 'cloud' => 55, 'precipitation' => 0.0],
            ['time' => 'Day 2, 8 a.m.', 'air' => 24, 'water' => 27, 'humidity' => 78, 'cloud' => 70, 'precipitation' => 0.0],
            ['time' => 'Day 2, noon', 'air' => 28, 'water' => 28, 'humidity' => 82, 'cloud' => 85, 'precipitation' => 0.0],
            ['time' => 'Day 2, 4 p.m.', 'air' => 27, 'water' => 28, 'humidity' => 88, 'cloud' => 95, 'precipitation' => 2.4],
            ['time' => 'Day 2, 8 p.m.', 'air' => 24, 'water' => 27, 'humidity' => 90, 'cloud' => 100, 'precipitation' => 5.8],
        ];
        $activities = [
            [
                'sequence' => 1, 'source_lesson_section_id' => $source('context'), 'activity_type' => 'instruction',
                'display_title' => 'From Water Movement to Weather',
                'student_instructions' => 'Review how water moves, then trace which water-cycle evidence can appear in current atmospheric conditions.',
                'content' => 'Weather describes current atmospheric conditions. Temperature, invisible water vapor, visible cloud droplets or ice crystals, precipitation, and moving air interact. These connections can contribute to weather, but one factor alone does not explain every weather condition.',
                'interaction_data' => ['science_visual' => 'weather_connections'], 'completion_condition' => ['type' => 'acknowledge'], 'reward_label' => 'Connections traced', 'theme_key' => 'learn',
            ],
            [
                'sequence' => 2, 'source_lesson_section_id' => $source('vocabulary'), 'activity_type' => 'matching',
                'display_title' => 'Weather–Water Terms', 'student_instructions' => 'Match each term to the evidence it describes.',
                'interaction_data' => ['prompts' => [
                    ['id' => 'humidity', 'label' => 'Amount of water vapor in the air'], ['id' => 'cloud', 'label' => 'Visible tiny water droplets or ice crystals'],
                    ['id' => 'precipitation', 'label' => 'Water falling from clouds'], ['id' => 'water_vapor', 'label' => 'The invisible gas form of water'],
                ], 'options' => [['id' => 'humidity', 'label' => 'Humidity'], ['id' => 'cloud', 'label' => 'Cloud'], ['id' => 'precipitation', 'label' => 'Precipitation'], ['id' => 'water_vapor', 'label' => 'Water vapor']]],
                'answer_data' => ['matches' => ['humidity' => 'humidity', 'cloud' => 'cloud', 'precipitation' => 'precipitation', 'water_vapor' => 'water_vapor']],
                'feedback' => ['correct' => 'Correct. You distinguished invisible vapor from visible cloud material and precipitation.', 'incorrect' => 'Try again. Remember: water vapor is invisible; clouds contain visible droplets or ice crystals.'],
                'completion_condition' => ['type' => 'correct'], 'reward_label' => 'Terms matched', 'theme_key' => 'challenge',
            ],
            [
                'sequence' => 3, 'source_lesson_section_id' => $source('investigation'), 'activity_type' => 'instruction',
                'display_title' => 'Compare Evaporation Conditions',
                'student_instructions' => 'Set up a fair comparison with an adult: equal water drops on matching saucers, one in a warmer or sunnier indoor location and one in a cooler shaded location.',
                'content' => 'Keep the saucers, starting drop size, water, and observation times the same. Change only the location condition. The model compares evaporation under two conditions; it does not reproduce ocean-scale weather.',
                'interaction_data' => ['science_visual' => 'evaporation_comparison'], 'completion_condition' => ['type' => 'acknowledge'], 'reward_label' => 'Fair test prepared', 'theme_key' => 'learn',
            ],
            [
                'sequence' => 4, 'source_lesson_section_id' => $source('investigation'), 'activity_type' => 'project',
                'display_title' => 'Observe Water Loss',
                'student_instructions' => 'Observe both drops at the same intervals. Use the in-app size descriptions to record evidence, then explain the cause-and-effect relationship you observed.',
                'content' => 'Place equal drops on matching saucers. Put one in the warmer or sunnier indoor location and one in cooler shade. Compare them at the start, after about 10 minutes, and after about 20 minutes.',
                'interaction_data' => ['science_visual' => 'evaporation_comparison', 'science_work_builder' => [
                    'kind' => 'evaporation_observation', 'submit_label' => 'Submit evaporation evidence and continue',
                    'sections' => [
                        ['title' => 'Conditions and controls', 'fields' => [
                            ['id' => 'warm_location', 'label' => 'Describe the warmer or sunnier indoor location.', 'control' => 'textarea', 'minimum_length' => 5],
                            ['id' => 'cool_location', 'label' => 'Describe the cooler shaded location.', 'control' => 'textarea', 'minimum_length' => 5],
                            ['id' => 'controls', 'label' => 'What did you keep the same?', 'control' => 'textarea', 'minimum_length' => 8],
                        ]],
                        ['title' => 'Same-time observations', 'fields' => [
                            ['id' => 'warm_start', 'label' => 'Warmer location — start', 'control' => 'select', 'choices' => [['id' => 'full', 'label' => 'Full starting drop'], ['id' => 'smaller', 'label' => 'Smaller drop'], ['id' => 'gone', 'label' => 'No visible drop']]],
                            ['id' => 'cool_start', 'label' => 'Cooler location — start', 'control' => 'select', 'choices' => [['id' => 'full', 'label' => 'Full starting drop'], ['id' => 'smaller', 'label' => 'Smaller drop'], ['id' => 'gone', 'label' => 'No visible drop']]],
                            ['id' => 'warm_ten', 'label' => 'Warmer location — about 10 minutes', 'control' => 'select', 'choices' => [['id' => 'full', 'label' => 'About the starting size'], ['id' => 'smaller', 'label' => 'Smaller'], ['id' => 'gone', 'label' => 'No visible drop']]],
                            ['id' => 'cool_ten', 'label' => 'Cooler location — about 10 minutes', 'control' => 'select', 'choices' => [['id' => 'full', 'label' => 'About the starting size'], ['id' => 'smaller', 'label' => 'Smaller'], ['id' => 'gone', 'label' => 'No visible drop']]],
                            ['id' => 'warm_twenty', 'label' => 'Warmer location — about 20 minutes', 'control' => 'select', 'choices' => [['id' => 'full', 'label' => 'About the starting size'], ['id' => 'smaller', 'label' => 'Smaller'], ['id' => 'gone', 'label' => 'No visible drop']]],
                            ['id' => 'cool_twenty', 'label' => 'Cooler location — about 20 minutes', 'control' => 'select', 'choices' => [['id' => 'full', 'label' => 'About the starting size'], ['id' => 'smaller', 'label' => 'Smaller'], ['id' => 'gone', 'label' => 'No visible drop']]],
                        ]],
                        ['title' => 'Evidence conclusion', 'fields' => [
                            ['id' => 'cause_effect', 'label' => 'How did the condition affect the rate of water loss? Use your observations as evidence.', 'control' => 'textarea', 'minimum_length' => 20],
                            ['id' => 'limitation', 'label' => 'Why can this small test not prove what always happens over an ocean?', 'control' => 'textarea', 'minimum_length' => 8],
                        ]],
                    ],
                ]],
                'completion_condition' => ['type' => 'structured_science_work'], 'reward_label' => 'Water-loss evidence recorded', 'requires_teacher_review' => true, 'theme_key' => 'create',
            ],
            [
                'sequence' => 5, 'source_lesson_section_id' => $source('evidence_analysis'), 'activity_type' => 'question_set',
                'display_title' => 'Analyze a Coastal Weather Dataset',
                'student_instructions' => 'Use the visible two-day instructional dataset. Choose only conclusions supported by these eight observations.',
                'content' => 'This short instructional dataset helps you practice finding patterns. It is not enough evidence to make a universal weather rule.',
                'interaction_data' => ['weather_dataset' => $dataset, 'questions' => [
                    ['id' => 'highest_air', 'prompt' => 'When was the highest air temperature recorded?', 'choices' => [['id' => 'd1_4', 'label' => 'Day 1, 4 p.m.'], ['id' => 'd2_8', 'label' => 'Day 2, 8 a.m.'], ['id' => 'd2_8pm', 'label' => 'Day 2, 8 p.m.']]],
                    ['id' => 'before_rain', 'prompt' => 'What pattern appears before and during the recorded precipitation?', 'choices' => [['id' => 'rise', 'label' => 'Humidity and cloud cover rise.'], ['id' => 'fall', 'label' => 'Humidity and cloud cover both fall to zero.'], ['id' => 'same', 'label' => 'Every value stays the same.']]],
                    ['id' => 'evidence', 'prompt' => 'Which pair is direct evidence from Day 2 at 8 p.m.?', 'choices' => [['id' => 'values', 'label' => '90% humidity and 5.8 mm precipitation'], ['id' => 'storm', 'label' => 'A hurricane definitely occurred'], ['id' => 'forecast', 'label' => 'It will rain next week']]],
                    ['id' => 'limitation', 'prompt' => 'Which limitation should be stated?', 'choices' => [['id' => 'short', 'label' => 'Eight observations over two days cannot establish a universal rule.'], ['id' => 'none', 'label' => 'The table proves all coastal weather patterns.'], ['id' => 'no_numbers', 'label' => 'The table contains no measurements.']]],
                ]],
                'answer_data' => ['answers' => ['highest_air' => 'd1_4', 'before_rain' => 'rise', 'evidence' => 'values', 'limitation' => 'short']],
                'feedback' => ['correct' => 'Correct. Your conclusions use recorded values and keep the short dataset’s limitation in view.', 'incorrect' => 'Try again. Read the row labels and values, then reject claims that go beyond these two days.'],
                'completion_condition' => ['type' => 'correct'], 'reward_label' => 'Dataset analyzed', 'theme_key' => 'challenge',
            ],
            [
                'sequence' => 6, 'source_lesson_section_id' => $source('guided_practice'), 'activity_type' => 'project',
                'display_title' => 'Build a Claim–Evidence–Reasoning Explanation',
                'student_instructions' => 'Use the coastal dataset to build a second cause-and-effect explanation. Cite measured evidence and explain why it supports your claim.',
                'content' => 'Model: Claim — warmer conditions increased evaporation in the saucer comparison. Evidence — the warmer-location drop became smaller sooner. Reasoning — added energy helps liquid water change to vapor. Now build a separate explanation from the dataset.',
                'interaction_data' => ['weather_dataset' => $dataset, 'science_work_builder' => [
                    'kind' => 'cer', 'submit_label' => 'Submit CER and continue',
                    'sections' => [[ 'title' => 'Your coastal-weather CER', 'fields' => [
                        ['id' => 'claim', 'label' => 'Write a cause-and-effect claim about the humidity, cloud cover, and precipitation pattern.', 'control' => 'textarea', 'minimum_length' => 15],
                        ['id' => 'evidence_one', 'label' => 'Cite one exact time and measured value.', 'control' => 'textarea', 'minimum_length' => 10],
                        ['id' => 'evidence_two', 'label' => 'Cite a second exact time and measured value.', 'control' => 'textarea', 'minimum_length' => 10],
                        ['id' => 'reasoning', 'label' => 'Explain how water-cycle interactions connect the evidence to your claim.', 'control' => 'textarea', 'minimum_length' => 25],
                        ['id' => 'limitation', 'label' => 'State what this two-day dataset cannot prove.', 'control' => 'textarea', 'minimum_length' => 10],
                    ]]],
                ]],
                'completion_condition' => ['type' => 'structured_science_work'], 'reward_label' => 'CER built', 'requires_teacher_review' => true, 'theme_key' => 'create',
            ],
            [
                'sequence' => 7, 'source_lesson_section_id' => $source('exit_check'), 'activity_type' => 'question_set',
                'display_title' => 'Supported or Unsupported?',
                'student_instructions' => 'Complete the final evidence check. Use only what the investigation, process model, and dataset support.',
                'interaction_data' => ['weather_dataset' => $dataset, 'questions' => [
                    ['id' => 'supported', 'prompt' => 'Which pattern is supported by the two-day dataset?', 'choices' => [['id' => 'humidity_clouds', 'label' => 'Higher humidity and cloud cover appeared before and during recorded precipitation.'], ['id' => 'always', 'label' => 'High humidity always causes rain everywhere.'], ['id' => 'weekly', 'label' => 'The same rain will return exactly one week later.']]],
                    ['id' => 'unsupported', 'prompt' => 'Which prediction should be rejected as unsupported?', 'choices' => [['id' => 'next_week', 'label' => 'It will rain at the same time next week.'], ['id' => 'two_days', 'label' => 'The table contains two days of observations.'], ['id' => 'humidity_rose', 'label' => 'Humidity rose from Day 1 noon to Day 2 evening.']]],
                    ['id' => 'interaction', 'prompt' => 'Which explanation correctly connects water in the atmosphere to precipitation?', 'choices' => [['id' => 'condense', 'label' => 'Water vapor can cool and condense; droplets can grow and fall as precipitation.'], ['id' => 'visible_vapor', 'label' => 'Invisible water vapor falls directly as visible rain without changing.'], ['id' => 'one_factor', 'label' => 'One temperature reading proves every weather condition.']]],
                ]],
                'answer_data' => ['answers' => ['supported' => 'humidity_clouds', 'unsupported' => 'next_week', 'interaction' => 'condense']],
                'feedback' => ['correct' => 'Final check passed. You used evidence, explained an interaction, and rejected a prediction the data cannot support.', 'incorrect' => 'Try again. Separate measured patterns from claims or predictions that go beyond the evidence.'],
                'completion_condition' => ['type' => 'correct'], 'reward_label' => 'Weather evidence checked', 'theme_key' => 'check',
            ],
        ];
        $experience = $this->createFromBlueprint($lesson, [
            'status' => 'preview', 'theme_key' => 'science-weather-water', 'mission_title' => 'Water and Weather Evidence Lab',
            'mission_brief' => 'Compare evaporation conditions, analyze a two-day coastal dataset, and use evidence to explain how water-cycle interactions contribute to weather.',
            'completion_title' => 'Water and Weather Evidence Lab Complete',
            'completion_message' => 'You analyzed temperature, evaporation, humidity, clouds, and precipitation without making claims beyond the evidence.',
            'source_version' => 'science-lesson-3-weather-water-v1',
        ], $activities);
        $this->provisionScienceMissionResources($lesson, [
            'Evaporation Conditions Observation Sheet' => ['delivery_type' => 'embedded', 'asset' => 'evaporation_observation'],
            'Two-Day Coastal Weather Dataset' => ['delivery_type' => 'embedded', 'asset' => 'coastal_weather_dataset'],
            'Weather Claim-Evidence-Reasoning Organizer' => ['delivery_type' => 'embedded', 'asset' => 'weather_cer'],
        ], ['Two matching saucers and water', 'Dropper or teaspoon']);
        return $experience->fresh('activities');
    }

    public function provisionMathProblemSolvingPrototype(Lesson $lesson): LessonExperience
    {
        $subject = $lesson->lessonPlan()->with('packageCourse.course.subject')->firstOrFail()->packageCourse->course->subject;
        if ($subject->code !== 'MATH' || $lesson->sequence !== 1 || $lesson->title !== 'Launch a Reliable Problem-Solving Process') {
            throw ValidationException::withMessages(['lesson' => 'The Math problem-solving experience is reserved for the selected existing Math Lesson 1.']);
        }
        $sections = $lesson->allSections()->get()->keyBy('section_type');
        foreach (['introduction', 'direct_instruction', 'example', 'guided_practice', 'independent_practice', 'exit_check'] as $required) {
            if (! $sections->has($required)) throw ValidationException::withMessages(['lesson' => "The selected lesson is missing its {$required} source section."]);
        }
        $source = fn (string $type): int => $sections->get($type)->id;
        $activities = [
            [
                'sequence' => 1, 'source_lesson_section_id' => $source('direct_instruction'), 'activity_type' => 'instruction',
                'display_title' => 'Meet the Five-Part Routine',
                'student_instructions' => 'Learn the routine before solving. Read what each step does, then continue when you can name the five steps in order.',
                'content' => 'Strong problem solvers do more than calculate. They understand what the problem asks, choose a plan, solve carefully, explain the answer in context, and check whether it makes sense.',
                'interaction_data' => ['math_visual' => ['mode' => 'routine']],
                'feedback' => ['correct' => 'Routine ready: Analyze, Plan, Solve, Justify, Check. Now use it one step at a time.'],
                'completion_condition' => ['type' => 'acknowledge'], 'reward_label' => 'Routine learned', 'theme_key' => 'learn',
            ],
            [
                'sequence' => 2, 'source_lesson_section_id' => $source('example'), 'activity_type' => 'question_set',
                'display_title' => 'Analyze the Bus Problem',
                'student_instructions' => 'Do not calculate yet. Select the important quantities, their units, and exactly what the problem asks you to find.',
                'content' => 'A community program is taking 187 people on a trip. Each bus holds at most 48 people. What is the least number of buses needed?',
                'interaction_data' => ['math_visual' => ['mode' => 'capacity', 'reveal' => 'setup', 'total' => 187, 'group_size' => 48, 'lower_groups' => 3, 'upper_groups' => 4, 'item_unit' => 'people', 'group_unit' => 'buses'], 'questions' => [
                    ['id' => 'quantities', 'prompt' => 'Which quantities are important?', 'choices' => [['id' => '187_48', 'label' => '187 people and 48 people per bus'], ['id' => '187_4', 'label' => '187 buses and 4 people'], ['id' => '48_1', 'label' => '48 buses and 1 trip']]],
                    ['id' => 'units', 'prompt' => 'Which units describe the answer?', 'choices' => [['id' => 'buses', 'label' => 'buses'], ['id' => 'people', 'label' => 'people'], ['id' => 'seats', 'label' => 'extra seats']]],
                    ['id' => 'asked', 'prompt' => 'What must the answer tell us?', 'choices' => [['id' => 'least_buses', 'label' => 'The least whole number of buses that can hold everyone'], ['id' => 'people_left', 'label' => 'How many people should stay home'], ['id' => 'empty_seats', 'label' => 'Only the number of empty seats']]],
                ], 'answer_feedback' => [
                    'quantities' => ['default' => 'Look again for the total number of people and the maximum number each bus can hold.'],
                    'units' => ['default' => 'Check what the question asks you to count, not what is being placed into the groups.'],
                    'asked' => ['default' => 'The words “least number of buses needed” tell you exactly what the final answer must report.'],
                ]],
                'answer_data' => ['answers' => ['quantities' => '187_48', 'units' => 'buses', 'asked' => 'least_buses']],
                'feedback' => ['correct' => 'Analyze complete. You found the total, the capacity per bus, and the required answer unit.', 'incorrect' => 'Re-read the question and separate what is known from what must be found.'],
                'completion_condition' => ['type' => 'correct'], 'reward_label' => 'Problem analyzed', 'theme_key' => 'learn',
            ],
            [
                'sequence' => 3, 'source_lesson_section_id' => $source('example'), 'activity_type' => 'multiple_choice',
                'display_title' => 'Plan the Bus Solution',
                'student_instructions' => 'Choose a plan before calculating. Think about which plan can test whether a whole number of buses has enough capacity.',
                'content' => 'One useful plan is to compare nearby multiples of 48. A multiple is the amount made by multiplying 48 by a whole number of buses. Comparing nearby multiples lets us find the last amount that is too small and the next amount that is enough. Which option describes that plan?',
                'interaction_data' => ['math_visual' => ['mode' => 'capacity', 'reveal' => 'setup', 'total' => 187, 'group_size' => 48, 'lower_groups' => 3, 'upper_groups' => 4, 'item_unit' => 'people', 'group_unit' => 'buses'], 'choices' => [
                    ['id' => 'nearby_multiples', 'label' => 'Compare nearby multiples of 48 until the capacity reaches 187.'],
                    ['id' => 'add_once', 'label' => 'Add 187 + 48 one time.'],
                    ['id' => 'subtract_units', 'label' => 'Subtract 48 people from the word “buses.”'],
                ], 'choice_feedback' => [
                    'add_once' => 'Adding one bus capacity to the total does not tell how many buses are needed. Choose a plan that builds equal groups of 48.',
                    'subtract_units' => 'Quantities can only be combined meaningfully. Compare groups of 48 people with the total of 187 people.',
                ]],
                'answer_data' => ['correct' => 'nearby_multiples'],
                'feedback' => ['correct' => 'Good plan. Nearby multiples show both the last insufficient capacity and the first sufficient capacity.', 'incorrect' => 'Choose a plan that compares whole groups of 48 with the target of 187.'],
                'completion_condition' => ['type' => 'correct'], 'reward_label' => 'Plan selected', 'theme_key' => 'learn',
            ],
            [
                'sequence' => 4, 'source_lesson_section_id' => $source('example'), 'activity_type' => 'project',
                'display_title' => 'Solve the Bus Capacity',
                'student_instructions' => 'Complete the two nearby multiplication facts. The model will reveal the comparison after your calculations are correct.',
                'content' => 'Test 3 buses and then 4 buses. Enter the total capacity for each whole-number choice.',
                'interaction_data' => ['math_visual' => ['mode' => 'capacity', 'reveal' => 'groups', 'total' => 187, 'group_size' => 48, 'lower_groups' => 3, 'upper_groups' => 4, 'item_unit' => 'people', 'group_unit' => 'buses'], 'math_work_builder' => [
                    'submit_label' => 'Check the calculations', 'teacher_review_required' => false, 'sections' => [
                        ['title' => 'Solve', 'fields' => [
                            ['id' => 'three_capacity', 'label' => '48 × 3 =', 'control' => 'number'],
                            ['id' => 'four_capacity', 'label' => '48 × 4 =', 'control' => 'number'],
                        ]],
                    ],
                    'expected_values' => ['three_capacity' => '144', 'four_capacity' => '192'],
                    'field_feedback' => [
                        'three_capacity' => 'Recheck 48 × 3. You can use 50 × 3 = 150, then subtract 2 × 3.',
                        'four_capacity' => 'Recheck 48 × 4. Double 48 to get 96, then double 96.',
                    ],
                ]],
                'feedback' => ['correct' => 'Calculated: 3 buses hold 144 people, and 4 buses hold 192 people. Now interpret those capacities in the situation.'],
                'completion_condition' => ['type' => 'correct_structured_math_work'], 'reward_label' => 'Capacity calculated', 'theme_key' => 'learn',
            ],
            [
                'sequence' => 5, 'source_lesson_section_id' => $source('example'), 'activity_type' => 'question_set',
                'display_title' => 'Justify and Check the Bus Answer',
                'student_instructions' => 'Use the two nearby capacity amounts to decide what the remainder means, justify the answer, and check that it is reasonable.',
                'content' => 'Three buses hold 144 people, which leaves 43 people without seats. Four buses hold 192 people. What should the program do?',
                'interaction_data' => ['math_visual' => ['mode' => 'capacity', 'reveal' => 'both', 'total' => 187, 'group_size' => 48, 'lower_groups' => 3, 'upper_groups' => 4, 'item_unit' => 'people', 'group_unit' => 'buses'], 'questions' => [
                    ['id' => 'remainder', 'prompt' => 'What does the remainder of 43 represent?', 'choices' => [['id' => 'people_without_seats', 'label' => '43 people who still need seats'], ['id' => 'extra_buses', 'label' => '43 extra buses'], ['id' => 'ignore', 'label' => 'A number that can be ignored']]],
                    ['id' => 'answer', 'prompt' => 'What is the least number of buses needed?', 'choices' => [['id' => 'four', 'label' => '4 buses'], ['id' => 'three', 'label' => '3 buses'], ['id' => 'forty_three', 'label' => '43 buses']]],
                    ['id' => 'check', 'prompt' => 'Which comparison proves the answer is reasonable?', 'choices' => [['id' => 'bounds', 'label' => '144 < 187 ≤ 192, so 3 buses are short and 4 are enough.'], ['id' => 'repeat', 'label' => '187 is the largest number, so it must be the answer.'], ['id' => 'drop', 'label' => 'The quotient is 3, so the remainder should always be dropped.']]],
                ], 'answer_feedback' => [
                    'remainder' => ['default' => 'The remainder is part of the original 187 people. Ask who would still need a seat after filling 3 buses.'],
                    'answer' => ['three' => 'Would 3 buses hold 187 people? Their total capacity is only 144.', 'default' => 'The answer must be a whole number of buses with enough capacity for every person.'],
                    'check' => ['default' => 'A check compares the last insufficient capacity with the first sufficient capacity.'],
                ]],
                'answer_data' => ['answers' => ['remainder' => 'people_without_seats', 'answer' => 'four', 'check' => 'bounds']],
                'feedback' => ['correct' => 'Worked example complete. Four buses are needed because every person needs a seat, and comparing 144 with 192 proves 4 is the least sufficient number.', 'incorrect' => 'Use the capacity comparison to connect the calculation back to people and buses.'],
                'completion_condition' => ['type' => 'correct'], 'reward_label' => 'Example justified and checked', 'theme_key' => 'learn',
            ],
            [
                'sequence' => 6, 'source_lesson_section_id' => $source('guided_practice'), 'activity_type' => 'project',
                'display_title' => 'Guided Practice: Test 8 and 9 Packs',
                'student_instructions' => 'Work through the pack amounts one concrete step at a time. Decide whether each amount is enough before naming the checking strategy.',
                'content' => 'An art club needs 325 sheets of colored paper. Paper is sold in packs of 40 sheets. What is the least number of packs the club must buy?',
                'interaction_data' => ['math_visual' => ['mode' => 'capacity', 'reveal' => 'groups', 'total' => 325, 'group_size' => 40, 'lower_groups' => 8, 'upper_groups' => 9, 'item_unit' => 'sheets', 'group_unit' => 'packs'], 'math_work_builder' => [
                    'submit_label' => 'Check 8 and 9 packs', 'teacher_review_required' => false, 'sections' => [
                        ['title' => 'Analyze', 'fields' => [
                            ['id' => 'total_needed', 'label' => 'How many sheets are needed?', 'control' => 'number'],
                            ['id' => 'per_group', 'label' => 'How many sheets are in each pack?', 'control' => 'number'],
                        ]],
                        ['title' => 'Calculate and compare', 'fields' => [
                            ['id' => 'plan', 'label' => 'Which plan helps us test whole packs?', 'control' => 'select', 'choices' => [['id' => 'capacity_compare', 'label' => 'Compare the sheets held by 8 packs and 9 packs'], ['id' => 'add_325_40', 'label' => 'Add 325 + 40 one time'], ['id' => 'subtract_units', 'label' => 'Subtract the words sheets and packs']]],
                            ['id' => 'eight_capacity', 'label' => '8 packs hold 8 × 40 = ___ sheets.', 'control' => 'number'],
                            ['id' => 'eight_enough', 'label' => 'Are 8 packs enough for 325 sheets?', 'control' => 'select', 'choices' => [['id' => 'yes', 'label' => 'Yes'], ['id' => 'no', 'label' => 'No']]],
                            ['id' => 'nine_capacity', 'label' => '9 packs hold 9 × 40 = ___ sheets.', 'control' => 'number'],
                            ['id' => 'nine_enough', 'label' => 'Are 9 packs enough for 325 sheets?', 'control' => 'select', 'choices' => [['id' => 'yes', 'label' => 'Yes'], ['id' => 'no', 'label' => 'No']]],
                        ]],
                        ['title' => 'Choose the least amount that works', 'fields' => [
                            ['id' => 'answer', 'label' => 'Least number of packs', 'control' => 'number'],
                            ['id' => 'units', 'label' => 'Answer unit', 'control' => 'select', 'choices' => [['id' => 'packs', 'label' => 'packs'], ['id' => 'sheets', 'label' => 'sheets']]],
                        ]],
                    ],
                    'expected_values' => ['total_needed' => '325', 'per_group' => '40', 'plan' => 'capacity_compare', 'eight_capacity' => '320', 'eight_enough' => 'no', 'nine_capacity' => '360', 'nine_enough' => 'yes', 'answer' => '9', 'units' => 'packs'],
                    'field_feedback' => [
                        'total_needed' => 'Analyze the problem again: the club needs 325 sheets.', 'per_group' => 'Each pack contains 40 sheets.',
                        'plan' => 'Test whole packs by comparing how many sheets 8 packs and 9 packs can hold.',
                        'eight_capacity' => 'Recheck 40 × 8. Eight groups of 40 make 320.', 'eight_enough' => 'Compare 320 with 325. A capacity below the amount needed is not enough.',
                        'nine_capacity' => 'Recheck 40 × 9. Nine groups of 40 make 360.', 'nine_enough' => 'Compare 360 with 325. Since 360 is at least 325, 9 packs are enough.', 'answer' => 'Choose the least whole number whose capacity reaches at least 325.',
                        'units' => 'The question asks how many packs the club must buy.',
                    ],
                ]],
                'feedback' => ['correct' => 'You proved it: 8 packs hold 320 sheets and are not enough; 9 packs hold 360 sheets and are enough. Therefore 9 is the least number of packs needed.'],
                'completion_condition' => ['type' => 'correct_structured_math_work'], 'reward_label' => 'Pack amounts compared', 'theme_key' => 'create',
            ],
            [
                'sequence' => 7, 'source_lesson_section_id' => $source('guided_practice'), 'activity_type' => 'instruction',
                'display_title' => 'Name the Check: Capacity Bounds',
                'student_instructions' => 'Now name the reasoning you just used. Read the definition and connect each nearby amount to the 325-sheet target.',
                'content' => 'Capacity bounds are nearby amounts that help us check whether a group is large enough. Here, 320 sheets is the lower capacity bound: it is close but not enough. The next nearby amount, 360 sheets, is the upper capacity bound: it is enough. Together, these bounds show that 9 packs is the least whole number that works.',
                'interaction_data' => ['math_visual' => ['mode' => 'capacity', 'reveal' => 'both', 'total' => 325, 'group_size' => 40, 'lower_groups' => 8, 'upper_groups' => 9, 'item_unit' => 'sheets', 'group_unit' => 'packs']],
                'feedback' => ['correct' => 'You learned the vocabulary after using the idea: 320 and 360 are nearby capacity bounds around the 325-sheet target.'],
                'completion_condition' => ['type' => 'acknowledge'], 'reward_label' => 'Capacity bounds named', 'theme_key' => 'learn',
            ],
            [
                'sequence' => 8, 'source_lesson_section_id' => $source('guided_practice'), 'activity_type' => 'project',
                'display_title' => 'Explain Why 9 Packs Are Needed',
                'student_instructions' => 'Use the same visible numbers to interpret the remainder and explain the decision in your own words. Your response saves automatically.',
                'content' => 'Eight packs hold 320 sheets, leaving 5 of the required 325 sheets uncovered. Nine packs hold 360 sheets. Why must the club buy 9 packs instead of 8?',
                'interaction_data' => ['math_visual' => ['mode' => 'capacity', 'reveal' => 'both', 'total' => 325, 'group_size' => 40, 'lower_groups' => 8, 'upper_groups' => 9, 'item_unit' => 'sheets', 'group_unit' => 'packs'], 'math_work_builder' => [
                    'submit_label' => 'Save the explanation', 'teacher_review_required' => true, 'sections' => [
                        ['title' => 'Interpret and explain', 'fields' => [
                            ['id' => 'decision', 'label' => 'What must the club do?', 'control' => 'select', 'choices' => [['id' => 'buy_9', 'label' => 'Buy 9 packs so all 325 sheets are covered'], ['id' => 'buy_8', 'label' => 'Buy 8 packs and ignore the 5 uncovered sheets'], ['id' => 'buy_5', 'label' => 'Buy 5 packs because the remainder is 5']]],
                            ['id' => 'reasoning', 'label' => 'Why are 9 packs needed instead of 8?', 'control' => 'textarea', 'minimum_length' => 20],
                        ]],
                    ],
                    'expected_values' => ['decision' => 'buy_9'],
                    'field_feedback' => ['decision' => 'Eight packs cover only 320 sheets. The 5 uncovered sheets cannot be ignored, so another whole pack is needed.'],
                ]],
                'feedback' => ['correct' => 'Your decision and explanation are saved. You connected the remainder, the nearby capacity amounts, and the least whole number of packs.'],
                'completion_condition' => ['type' => 'correct_structured_math_work'], 'reward_label' => 'Pack decision explained', 'requires_teacher_review' => true, 'theme_key' => 'create',
            ],
            [
                'sequence' => 9, 'source_lesson_section_id' => $source('independent_practice'), 'activity_type' => 'project',
                'display_title' => 'Independent Practice: Album Pages',
                'student_instructions' => 'Complete all five parts independently. The problem and grouping model remain visible, and your digital work saves automatically.',
                'content' => 'A family is placing 246 photographs into album pages. Each page holds 12 photographs. What is the least number of pages needed?',
                'interaction_data' => ['math_visual' => ['mode' => 'capacity', 'reveal' => 'groups', 'total' => 246, 'group_size' => 12, 'lower_groups' => 20, 'upper_groups' => 21, 'item_unit' => 'photographs', 'group_unit' => 'pages'], 'math_work_builder' => [
                    'submit_label' => 'Check independent organizer', 'sections' => [
                        ['title' => 'Analyze', 'fields' => [
                            ['id' => 'total_needed', 'label' => 'How many photographs need a place?', 'control' => 'number'],
                            ['id' => 'per_group', 'label' => 'How many photographs fit on each page?', 'control' => 'number'],
                        ]],
                        ['title' => 'Plan and solve', 'fields' => [
                            ['id' => 'plan', 'label' => 'Which plan fits?', 'control' => 'select', 'choices' => [['id' => 'capacity_compare', 'label' => 'Compare nearby multiples of 12'], ['id' => 'add', 'label' => 'Add 246 + 12 once'], ['id' => 'ignore_remainder', 'label' => 'Divide and ignore any remainder']]],
                            ['id' => 'twenty_capacity', 'label' => '12 × 20 =', 'control' => 'number'],
                            ['id' => 'twenty_enough', 'label' => 'Are 20 pages enough for all 246 photographs?', 'control' => 'select', 'choices' => [['id' => 'yes', 'label' => 'Yes'], ['id' => 'no', 'label' => 'No']]],
                            ['id' => 'twenty_one_capacity', 'label' => '12 × 21 =', 'control' => 'number'],
                        ]],
                        ['title' => 'Justify and check', 'fields' => [
                            ['id' => 'answer', 'label' => 'Least number of pages', 'control' => 'number'],
                            ['id' => 'units', 'label' => 'Answer unit', 'control' => 'select', 'choices' => [['id' => 'pages', 'label' => 'pages'], ['id' => 'photographs', 'label' => 'photographs']]],
                            ['id' => 'justification', 'label' => 'Explain what happens to the photographs beyond the capacity of 20 pages.', 'control' => 'textarea', 'minimum_length' => 20],
                            ['id' => 'check', 'label' => 'Why is your answer reasonable, and why is 20 not enough?', 'control' => 'textarea', 'minimum_length' => 20],
                        ]],
                    ],
                    'expected_values' => ['total_needed' => '246', 'per_group' => '12', 'plan' => 'capacity_compare', 'twenty_capacity' => '240', 'twenty_enough' => 'no', 'twenty_one_capacity' => '252', 'answer' => '21', 'units' => 'pages'],
                    'field_feedback' => [
                        'total_needed' => 'Analyze the problem again: 246 photographs need a place.', 'per_group' => 'Each album page holds 12 photographs.',
                        'plan' => 'Compare nearby multiples of 12 so you can test the lower and upper capacity bounds.',
                        'twenty_capacity' => 'Recheck 12 × 20. Twenty groups of 12 make 240.', 'twenty_enough' => 'Compare 240 spaces with 246 photographs. Six photographs would still need a place.',
                        'twenty_one_capacity' => 'One more group of 12 makes 252 spaces in all.', 'answer' => 'Choose the least whole number of pages with room for all 246 photographs.',
                        'units' => 'The question asks for a number of album pages.',
                    ],
                ]],
                'feedback' => ['correct' => 'Your organizer shows that 20 pages hold 240 photographs and leave 6 without a place; 21 pages hold 252 and are sufficient.'],
                'completion_condition' => ['type' => 'correct_structured_math_work'], 'reward_label' => 'Independent problem solved', 'requires_teacher_review' => true, 'theme_key' => 'create',
            ],
            [
                'sequence' => 10, 'source_lesson_section_id' => $source('exit_check'), 'activity_type' => 'question_set',
                'display_title' => 'Explain the Check',
                'student_instructions' => 'Use the album-page model and your organizer. Complete the final check without calculating a different problem.',
                'content' => 'Why is 21 pages reasonable, and why would 20 pages not be enough for 246 photographs?',
                'interaction_data' => ['math_visual' => ['mode' => 'capacity', 'total' => 246, 'group_size' => 12, 'lower_groups' => 20, 'upper_groups' => 21, 'item_unit' => 'photographs', 'group_unit' => 'pages'], 'questions' => [
                    ['id' => 'lower', 'prompt' => 'What does 20 × 12 = 240 show?', 'choices' => [['id' => 'short', 'label' => 'Twenty pages are 6 spaces short of 246.'], ['id' => 'enough', 'label' => 'Twenty pages are enough for all photographs.'], ['id' => 'extra', 'label' => 'Twenty pages have 20 extra spaces.']]],
                    ['id' => 'upper', 'prompt' => 'What does 21 × 12 = 252 show?', 'choices' => [['id' => 'enough', 'label' => 'Twenty-one pages have enough capacity for all 246 photographs.'], ['id' => 'short', 'label' => 'Twenty-one pages are 6 spaces short.'], ['id' => 'photos', 'label' => 'The family has 252 photographs.']]],
                    ['id' => 'least', 'prompt' => 'Why is 21 the least sufficient whole number of pages?', 'choices' => [['id' => 'bounds', 'label' => 'Twenty is insufficient, while the next whole page count, 21, is sufficient.'], ['id' => 'remainder', 'label' => 'A remainder always becomes the final answer.'], ['id' => 'largest', 'label' => 'Twenty-one is the largest number in the problem.']]],
                ]],
                'answer_data' => ['answers' => ['lower' => 'short', 'upper' => 'enough', 'least' => 'bounds']],
                'feedback' => ['correct' => 'Final check passed. You used lower and upper capacity bounds, included the context, and interpreted the remainder.', 'incorrect' => 'Compare each capacity directly with 246: 240 is below the needed amount, while 252 reaches it. Then choose the first whole page count that is sufficient.'],
                'completion_condition' => ['type' => 'correct'], 'reward_label' => 'Reasonableness checked', 'theme_key' => 'check',
            ],
        ];

        $experience = $this->createFromBlueprint($lesson, [
            'status' => 'preview', 'theme_key' => 'math-problem-solving', 'mission_title' => 'Problem-Solving Launch Lab',
            'mission_brief' => 'Learn a reliable five-part routine, build a complete example one step at a time, then solve guided and independent remainder problems.',
            'completion_title' => 'Problem-Solving Launch Lab Complete',
            'completion_message' => 'You analyzed, planned, solved, justified, and checked a capacity problem while interpreting its remainder in context.',
            'source_version' => 'math-lesson-1-problem-solving-v3',
        ], $activities, synchronizePreview: true);

        if ($lesson->estimated_preparation_minutes !== 0) {
            $before = $lesson->toArray();
            $lesson->update(['estimated_preparation_minutes' => 0]);
            $this->audit->record('lesson.math-digital-preparation-updated', $lesson, $before, $lesson->fresh()->toArray());
        }
        $teacherPreparation = $sections->get('teacher_preparation');
        if ($teacherPreparation) {
            $digitalPreparation = 'No ordinary parent preparation is required. The app teaches the five-part routine, displays every problem, saves Kai’s structured work, and provides immediate instructional feedback. The generated organizer and remainder reference remain available only as optional teacher fallbacks. Before teaching, review the attached standards in the district’s official standards document to confirm local wording and intent.';
            if ($teacherPreparation->content !== $digitalPreparation) {
                $before = $teacherPreparation->toArray();
                $teacherPreparation->update(['content' => $digitalPreparation]);
                $this->audit->record('lesson-section.math-digital-preparation-updated', $teacherPreparation, $before, $teacherPreparation->fresh()->toArray());
            }
        }
        foreach ($lesson->resources()->get() as $resource) {
            $before = $resource->toArray();
            $asset = match ($resource->title) {
                'Analyze–Plan–Solve–Justify–Check Organizer' => 'problem_solving_organizer',
                'Interpreting Remainders Task Sheet' => 'remainder_tasks',
                default => null,
            };
            if ($resource->category === 'lesson_resource' && $asset) {
                $description = $asset === 'problem_solving_organizer'
                    ? 'Optional teacher fallback showing the five-part routine. Kai’s normal organizer is interactive and saved inside the lesson.'
                    : 'Optional teacher reference containing the three approved lesson problems. Kai completes the normal lesson interactively in the app.';
                $resource->update(['description' => $description, 'delivery_type' => 'embedded', 'availability_status' => 'needs_asset', 'metadata' => [...($resource->metadata ?? []), 'math_foundation_asset' => $asset, 'student_experience_required' => false, 'optional_teacher_fallback' => true]]);
            } elseif ($resource->category === 'student_supply') {
                $resource->update(['metadata' => [...($resource->metadata ?? []), 'student_experience_required' => false]]);
            }
            if ($before !== $resource->fresh()->toArray()) $this->audit->record('lesson-resource.math-experience-defined', $resource, $before, $resource->fresh()->toArray());
        }
        if (config('lesson-resources.automatic_fulfillment')) $this->resourceFulfillment->fulfillRequiredForLesson($lesson);
        return $experience->fresh('activities');
    }

    public function provisionElarActiveReadingPrototype(Lesson $lesson): LessonExperience
    {
        if ($lesson->title !== ElarLessonContent::LESSON_ONE_TITLE || $lesson->sequence !== 1) {
            throw ValidationException::withMessages(['lesson' => 'The active-reading preview is reserved for the selected ELAR Lesson 1.']);
        }
        $sections = $lesson->allSections()->get()->keyBy('section_type');
        foreach (['introduction', 'direct_instruction', 'demonstration', 'guided_practice', 'independent_practice', 'exit_check'] as $required) {
            if (! $sections->has($required)) {
                throw ValidationException::withMessages(['lesson' => "The selected lesson is missing its {$required} source section."]);
            }
        }
        $source = fn (string $type): int => $sections->get($type)->id;
        $passage = ElarLessonContent::passage();
        $patterns = ElarLessonContent::syllablePatterns();
        $activities = [
            [
                'sequence' => 1, 'source_lesson_section_id' => $source('introduction'), 'activity_type' => 'instruction',
                'display_title' => 'Meet the Reader’s Repair Kit',
                'student_instructions' => 'Read the quick example, then study five words that appear in today’s passage.',
                'content' => 'Active reading means noticing whether a text makes sense while you read. A reading glitch can happen when a word is unfamiliar, a sentence is complicated, or two ideas do not seem to connect. Example: “Her first prototype—an early model built to test an idea—looked promising.” If prototype is unfamiliar, the words after the dash provide a definition. You noticed a glitch and found a clue; you did not have to guess or search outside the lesson.',
                'interaction_data' => ['facts' => collect($passage['vocabulary'])->map(fn ($item) => ['label' => $item['word'], 'detail' => $item['definition'].' Example: '.$item['example']])->all()],
                'feedback' => ['correct' => 'Vocabulary tools ready.'], 'completion_condition' => ['type' => 'acknowledge'], 'reward_label' => 'Words unlocked', 'theme_key' => 'learn',
            ],
            [
                'sequence' => 2, 'source_lesson_section_id' => $source('introduction'), 'activity_type' => 'instruction',
                'display_title' => 'Learn Stop, Name, Choose, Check',
                'student_instructions' => 'Read each move in order. Then say what each move helps a reader do.',
                'content' => 'Monitoring means checking your own understanding as you read. Clarifying means using the text or a provided reference to repair meaning. Stop–Name–Choose–Check gives you four concrete moves whenever meaning becomes blurry.',
                'interaction_data' => ['facts' => collect(ElarLessonContent::routine())->map(fn ($item) => ['label' => $item['name'], 'detail' => $item['detail']])->all()],
                'feedback' => ['correct' => 'You know the four repair moves.'], 'completion_condition' => ['type' => 'acknowledge'], 'reward_label' => 'Routine learned', 'theme_key' => 'learn',
            ],
            [
                'sequence' => 3, 'source_lesson_section_id' => $source('direct_instruction'), 'activity_type' => 'instruction',
                'display_title' => 'Watch a Reader Think',
                'student_instructions' => 'Follow the model beside paragraph 2. Notice how the reader checks a meaning instead of guessing.',
                'content' => 'Think-aloud: “The word prototype is unfamiliar, so I stop instead of reading past it. I name exactly what I need: What did Nia build? I notice a dash and the words ‘an early model built to test an idea.’ I choose context clues because the sentence gives an explanation. I replace prototype with early model and reread the whole sentence. It now tells me that Nia built a test version, so the meaning fits. My confusion is resolved—that is my check.”',
                'interaction_data' => ['reading_passage' => $passage, 'focus_sentence_ids' => ['p2s1']],
                'feedback' => ['correct' => 'You traced every step in the model.'], 'completion_condition' => ['type' => 'acknowledge'], 'reward_label' => 'Thinking traced', 'theme_key' => 'learn',
            ],
            [
                'sequence' => 4, 'source_lesson_section_id' => $source('guided_practice'), 'activity_type' => 'project',
                'display_title' => 'Clarify with Text Evidence',
                'student_instructions' => 'Read the passage. Record two confusion points, select a sentence that helps repair each one, choose a strategy, and explain what became clearer.',
                'content' => 'The passage stays beside your response so you can point to evidence instead of copying it.',
                'interaction_data' => ['reading_passage' => $passage, 'elar_response_builder' => [
                    'fields' => [
                        ['id' => 'confusion_one', 'label' => 'Confusion point 1: What did not make sense yet?', 'control' => 'textarea', 'minimum_length' => 5],
                        ['id' => 'evidence_one', 'label' => 'Select the sentence that best clarifies the uneven flow.', 'control' => 'evidence_select', 'choices' => ElarLessonContent::evidenceChoices([2, 3])],
                        ['id' => 'strategy_one', 'label' => 'Which repair strategy did you use first?', 'control' => 'select', 'choices' => [
                            ['id' => 'reread', 'label' => 'Reread'], ['id' => 'read_ahead', 'label' => 'Read ahead'], ['id' => 'word_parts', 'label' => 'Study word parts'], ['id' => 'context', 'label' => 'Use context'],
                        ]],
                        ['id' => 'clarification_one', 'label' => 'What became clearer? Explain how your selected sentence helped.', 'control' => 'textarea', 'minimum_length' => 12],
                        ['id' => 'confusion_two', 'label' => 'Confusion point 2: What did you need to understand about reliable?', 'control' => 'textarea', 'minimum_length' => 5],
                        ['id' => 'evidence_two', 'label' => 'Select the sentence that gives the strongest evidence that the tool was reliable.', 'control' => 'evidence_select', 'choices' => ElarLessonContent::evidenceChoices([4])],
                        ['id' => 'strategy_two', 'label' => 'Which repair strategy did you use next?', 'control' => 'select', 'choices' => [
                            ['id' => 'reread', 'label' => 'Reread'], ['id' => 'read_ahead', 'label' => 'Read ahead'], ['id' => 'word_parts', 'label' => 'Study word parts'], ['id' => 'context', 'label' => 'Use context'],
                        ]],
                        ['id' => 'clarification_two', 'label' => 'Explain how the selected evidence clarifies reliable.', 'control' => 'textarea', 'minimum_length' => 12],
                    ],
                    'expected_values' => ['evidence_one' => ['p2s4', 'p3s4'], 'evidence_two' => ['p4s3', 'p4s4']],
                    'field_feedback' => [
                        'evidence_one' => 'Look again at paragraphs 2 and 3. Which sentence gives Nia information that helps explain or repair the uneven flow?',
                        'evidence_two' => 'Reliability means working dependably again and again. Look for repeated testing or repeated results in paragraph 4.',
                    ],
                    'teacher_review_required' => true,
                ]],
                'feedback' => ['correct' => 'Your evidence and explanation are saved for teacher review.'], 'completion_condition' => ['type' => 'structured_elar_response'], 'reward_label' => 'Meaning repaired', 'requires_teacher_review' => true, 'theme_key' => 'create',
            ],
            [
                'sequence' => 5, 'source_lesson_section_id' => $source('direct_instruction'), 'activity_type' => 'instruction',
                'display_title' => 'Decode Four Syllable Patterns',
                'student_instructions' => 'Study how each example is divided, what ends the syllable, and what its vowel says. VCe is explained on its card.',
                'content' => 'A syllable is a word part with one spoken vowel sound. Its spelling pattern can help you decode, or pronounce, it. These patterns are useful clues rather than rules without exceptions.',
                'interaction_data' => ['syllable_patterns' => $patterns],
                'feedback' => ['correct' => 'Pattern clues ready.'], 'completion_condition' => ['type' => 'acknowledge'], 'reward_label' => 'Patterns decoded', 'theme_key' => 'learn',
            ],
            [
                'sequence' => 6, 'source_lesson_section_id' => $source('guided_practice'), 'activity_type' => 'matching',
                'display_title' => 'Sort Four Together', 'student_instructions' => 'Match each bold syllable or word to its pattern. Use the ending and vowel clue.',
                'content' => 'Try these guided examples: robot begins with rob; pilot begins with pi; make ends in silent e; invention ends with tion.',
                'interaction_data' => ['prompts' => [
                    ['id' => 'rob', 'label' => 'rob in robot'], ['id' => 'pi', 'label' => 'pi in pilot'], ['id' => 'make', 'label' => 'make'], ['id' => 'tion', 'label' => 'tion in invention'],
                ], 'options' => collect($patterns)->map(fn ($pattern) => Arr::only($pattern, ['id', 'label']))->all(), 'answer_feedback' => [
                    'rob' => ['default' => 'Rob ends in consonant b, which closes in the vowel o. Listen for the short /ŏ/ sound.'],
                    'pi' => ['default' => 'Pi ends in the vowel i. Nothing closes it in, and i says its name /ī/.'],
                    'make' => ['default' => 'In make, a vowel comes before consonant k and silent e: vowel–consonant–e.'],
                    'tion' => ['default' => 'The ending -tion has a dependable spelling and “shun” sound, so it is stable final.'],
                ]],
                'answer_data' => ['matches' => ['rob' => 'closed', 'pi' => 'open', 'make' => 'final_vce', 'tion' => 'stable_final']],
                'feedback' => ['correct' => 'All four clues match.', 'incorrect' => 'Check what ends each syllable: a consonant, a vowel, silent e, or a dependable final spelling. Then retry.'],
                'completion_condition' => ['type' => 'correct'], 'reward_label' => 'Guided sort complete', 'hints' => ['Say each syllable aloud, then inspect its final letters.'], 'theme_key' => 'challenge',
            ],
            [
                'sequence' => 7, 'source_lesson_section_id' => $source('independent_practice'), 'activity_type' => 'matching',
                'display_title' => 'Independent Syllable Sort', 'student_instructions' => 'Classify all ten items. If one needs repair, use the feedback and try again.',
                'content' => 'Use the same four clues without a worked answer beside you.',
                'interaction_data' => ['prompts' => [
                    ['id' => 'kit', 'label' => 'kit'], ['id' => 'plan', 'label' => 'plan'], ['id' => 'go', 'label' => 'go'], ['id' => 'he', 'label' => 'he'], ['id' => 'type', 'label' => 'type'],
                    ['id' => 'hope', 'label' => 'hope'], ['id' => 'tion', 'label' => 'tion in adjustment'], ['id' => 'ble', 'label' => 'ble in reliable'], ['id' => 'ven', 'label' => 'ven in invention'], ['id' => 'pi', 'label' => 'pi in pilot'],
                ], 'options' => collect($patterns)->map(fn ($pattern) => Arr::only($pattern, ['id', 'label']))->all(), 'minimum_correct' => 8],
                'answer_data' => ['matches' => ['kit' => 'closed', 'plan' => 'closed', 'go' => 'open', 'he' => 'open', 'type' => 'final_vce', 'hope' => 'final_vce', 'tion' => 'stable_final', 'ble' => 'stable_final', 'ven' => 'closed', 'pi' => 'open']],
                'feedback' => ['correct' => 'Criterion met: you classified at least 8 of 10 using the spelling and vowel clues.', 'incorrect' => 'Fewer than eight match yet. Say each item aloud, inspect how it ends, and compare it with the four pattern cards.'],
                'completion_condition' => ['type' => 'correct'], 'reward_label' => 'Ten patterns sorted', 'hints' => ['A syllable can be part of a longer word. Classify only the bold or named part.'], 'theme_key' => 'challenge',
            ],
            [
                'sequence' => 8, 'source_lesson_section_id' => $source('exit_check'), 'activity_type' => 'question_set',
                'display_title' => 'Reader’s Repair Check', 'student_instructions' => 'Complete the five-part check using only the routine and four syllable patterns taught in this lesson.',
                'content' => 'Show that you can begin a clarification repair and recognize each of the four syllable patterns.',
                'interaction_data' => ['questions' => [
                    ['id' => 'repair', 'prompt' => 'A sentence is still confusing after one reading. What should an active reader do first?', 'choices' => [['id' => 'stop_name', 'label' => 'Stop and name exactly what is unclear.'], ['id' => 'skip', 'label' => 'Skip the entire paragraph.'], ['id' => 'guess', 'label' => 'Choose a meaning without checking.']]],
                    ['id' => 'closed', 'prompt' => 'Which item is a closed syllable?', 'choices' => [['id' => 'kit', 'label' => 'kit'], ['id' => 'go', 'label' => 'go'], ['id' => 'make', 'label' => 'make']]],
                    ['id' => 'open', 'prompt' => 'Which item is an open syllable?', 'choices' => [['id' => 'plan', 'label' => 'plan'], ['id' => 'pi', 'label' => 'pi in pilot'], ['id' => 'type', 'label' => 'type']]],
                    ['id' => 'vce', 'prompt' => 'Which word shows the final VCe pattern?', 'choices' => [['id' => 'hope', 'label' => 'hope'], ['id' => 'kit', 'label' => 'kit'], ['id' => 'tion', 'label' => 'tion in invention']]],
                    ['id' => 'stable', 'prompt' => 'Which item is a stable-final syllable?', 'choices' => [['id' => 'rob', 'label' => 'rob in robot'], ['id' => 'tion', 'label' => 'tion in invention'], ['id' => 'he', 'label' => 'he']]],
                ], 'answer_feedback' => [
                    'repair' => ['skip' => 'Skipping removes evidence you may need. Start the repair routine by stopping and naming the exact confusion.', 'guess' => 'A guess is not checked. Begin by stopping and naming what is unclear.'],
                    'closed' => ['default' => 'A closed syllable ends in a consonant that closes in a short vowel. Inspect the ending of kit.'],
                    'open' => ['default' => 'An open syllable ends in its vowel. Inspect pi and listen for i saying its name.'],
                    'vce' => ['default' => 'VCe means vowel–consonant–silent e. Find the word with that exact ending pattern.'],
                    'stable' => ['default' => 'Look for a dependable final spelling and sound, such as -tion (“shun”).'],
                ]],
                'answer_data' => ['answers' => ['repair' => 'stop_name', 'closed' => 'kit', 'open' => 'pi', 'vce' => 'hope', 'stable' => 'tion']],
                'feedback' => ['correct' => 'Repair check passed: you identified the first routine move and all four syllable patterns.', 'incorrect' => 'Use the targeted clue, review the matching pattern card, and retry.'],
                'completion_condition' => ['type' => 'correct'], 'reward_label' => 'Repair check passed', 'theme_key' => 'check',
            ],
        ];

        $experience = $this->createFromBlueprint($lesson, [
            'status' => 'preview', 'theme_key' => 'elar-reader-lab', 'mission_title' => 'Reader’s Repair Lab',
            'mission_brief' => 'Learn how strong readers repair confusing meaning, then use spelling clues to decode syllables in an inventor story.',
            'completion_title' => 'Reader’s Repair Mission Complete',
            'completion_message' => 'You clarified meaning with evidence and used four syllable patterns as decoding tools. Your explanation is saved for teacher review.',
            'source_version' => 'elar-active-reading-v1',
        ], $activities, true);
        $this->provisionElarActiveReadingResources($lesson);

        return $experience;
    }

    public function provisionElarCentralIdeaSummaryPrototype(Lesson $lesson): LessonExperience
    {
        if ($lesson->title !== ElarLessonContent::LESSON_TWO_TITLE || $lesson->sequence !== 2) {
            throw ValidationException::withMessages(['lesson' => 'The central-idea preview is reserved for the selected ELAR Lesson 2.']);
        }
        $sections = $lesson->allSections()->get()->keyBy('section_type');
        foreach (['hook', 'direct_instruction', 'reading', 'guided_practice', 'written_response', 'exit_check'] as $required) {
            if (! $sections->has($required)) throw ValidationException::withMessages(['lesson' => "The selected lesson is missing its {$required} source section."]);
        }
        $source = fn (string $type): int => $sections->get($type)->id;
        $passage = ElarLessonContent::maraPassage();
        $evidence = fn (?array $paragraphs = null): array => ElarLessonContent::maraEvidenceChoices($paragraphs);
        $centralIdea = 'Patient testing and revision help Mara turn an uncertain idea into a dependable tool that meets people’s needs.';
        $activities = [
            [
                'sequence' => 1, 'source_lesson_section_id' => $source('hook'), 'activity_type' => 'instruction',
                'display_title' => 'Meet the Meaning Makers', 'student_instructions' => 'Learn four reading tools before using them with Mara’s story.',
                'content' => 'A topic names the broad subject: “a folding cart.” A central idea is a complete statement about what the author develops across the whole text. Key details support that statement. A summary restates the central idea and only the most important details in your own words, without opinions.',
                'interaction_data' => ['facts' => [
                    ['label' => 'Topic', 'detail' => 'The broad subject, usually a word or short phrase. Example: a folding cart.'],
                    ['label' => 'Central idea', 'detail' => 'The most important point developed across the whole text. It must be a complete thought, not just a topic.'],
                    ['label' => 'Key detail', 'detail' => 'An event or fact that explains, proves, or illustrates the central idea.'],
                    ['label' => 'Objective summary', 'detail' => 'A brief account of the central idea and major details in logical order, written in your own words without opinions.'],
                ]],
                'feedback' => ['correct' => 'You have the tools for deciding what matters most.'], 'completion_condition' => ['type' => 'acknowledge'], 'reward_label' => 'Meaning makers ready', 'theme_key' => 'learn',
            ],
            [
                'sequence' => 2, 'source_lesson_section_id' => $source('direct_instruction'), 'activity_type' => 'instruction',
                'display_title' => 'Read and Watch Importance Thinking', 'student_instructions' => 'Read Mara’s story, then follow the model that tests an important detail against a minor one.',
                'content' => 'Model: Mara records “sides need a lock” and studies the hinge instead of quitting. That action matters because it begins the testing-and-revision pattern developed through the whole story. The orange tape is vivid and true, but removing it would not change the problem, major actions, result, or lesson—so it is minor. The importance test asks: Would a reader still understand the text’s larger point without this detail?',
                'interaction_data' => ['reading_passage' => $passage, 'focus_sentence_ids' => ['m3s2', 'm2s3']],
                'feedback' => ['correct' => 'You saw how a reader keeps a key detail and leaves out a minor one.'], 'completion_condition' => ['type' => 'acknowledge'], 'reward_label' => 'Importance tested', 'theme_key' => 'learn',
            ],
            [
                'sequence' => 3, 'source_lesson_section_id' => $source('direct_instruction'), 'activity_type' => 'question_set',
                'display_title' => 'Topic, Idea, or Detail?', 'student_instructions' => 'Use the definitions and model to choose the best answer for each distinction.',
                'interaction_data' => ['questions' => [
                    ['id' => 'topic', 'prompt' => 'Which choice is only the topic?', 'choices' => [['id' => 'cart', 'label' => 'A folding garden cart'], ['id' => 'idea', 'label' => 'Patient testing helps Mara improve a useful cart.'], ['id' => 'detail', 'label' => 'Mara records problems and changes her design.']]],
                    ['id' => 'central', 'prompt' => 'Which choice could represent the whole text’s central idea?', 'choices' => [['id' => 'tape', 'label' => 'Mara used orange tape on one corner.'], ['id' => 'patient', 'label' => $centralIdea], ['id' => 'vegetables', 'label' => 'The boxes held vegetables.']]],
                    ['id' => 'minor', 'prompt' => 'Which true detail is too minor for a concise summary?', 'choices' => [['id' => 'orange', 'label' => 'The first model used bright orange tape.'], ['id' => 'problem', 'label' => 'Volunteers needed a cart that was sturdy and easy to store.'], ['id' => 'result', 'label' => 'The improved cart carried four boxes in one trip.']]],
                ], 'answer_feedback' => [
                    'topic' => ['default' => 'A topic is only the broad subject, not a complete statement or one supporting event.'],
                    'central' => ['default' => 'The central idea must fit the problem, repeated testing, successful result, and lesson across the whole passage.'],
                    'minor' => ['default' => 'Use the importance test: remove the detail and ask whether the problem, major actions, result, and lesson remain clear.'],
                ]],
                'answer_data' => ['answers' => ['topic' => 'cart', 'central' => 'patient', 'minor' => 'orange']],
                'feedback' => ['correct' => 'You separated the broad topic, whole-text idea, and minor detail.', 'incorrect' => 'Use the targeted clue and try again.'], 'completion_condition' => ['type' => 'correct'], 'reward_label' => 'Ideas sorted', 'theme_key' => 'challenge',
            ],
            [
                'sequence' => 4, 'source_lesson_section_id' => $source('guided_practice'), 'activity_type' => 'project',
                'display_title' => 'Build the Central Idea', 'student_instructions' => 'Keep the passage beside your work. Select three details from different parts, then explain the single idea they build together.',
                'content' => 'Choose details about Mara’s response to the problem, what her tests reveal, and what the completed process shows. Your selections are saved with your explanation.',
                'interaction_data' => ['reading_passage' => $passage, 'elar_response_builder' => [
                    'fields' => [
                        ['id' => 'early_detail', 'label' => 'Early evidence: Which sentence shows how Mara responds to her first setback?', 'control' => 'evidence_select', 'choices' => $evidence([3])],
                        ['id' => 'middle_detail', 'label' => 'Middle evidence: Which sentence explains why controlled testing helps Mara learn?', 'control' => 'evidence_select', 'choices' => $evidence([4])],
                        ['id' => 'late_detail', 'label' => 'Later evidence: Which sentence best states what Mara learned from the whole process?', 'control' => 'evidence_select', 'choices' => $evidence([7])],
                        ['id' => 'central_idea', 'label' => 'Complete the thought: Across the whole text, the author mainly shows that…', 'control' => 'textarea', 'minimum_length' => 30],
                    ],
                    'expected_values' => ['early_detail' => 'm3s2', 'middle_detail' => 'm4s4', 'late_detail' => 'm7s4'],
                    'field_feedback' => [
                        'early_detail' => 'Look for the action Mara takes instead of abandoning the model.',
                        'middle_detail' => 'Look for the sentence that explains what changing one feature lets Mara determine.',
                        'late_detail' => 'Look for the final sentence that connects observation, feedback, revision, and a dependable result.',
                    ], 'teacher_review_required' => false,
                ]],
                'feedback' => ['correct' => 'Your three saved details support a whole-text central idea.'], 'completion_condition' => ['type' => 'structured_elar_response'], 'reward_label' => 'Central idea built', 'theme_key' => 'create',
            ],
            [
                'sequence' => 5, 'source_lesson_section_id' => $source('direct_instruction'), 'activity_type' => 'instruction',
                'display_title' => 'Turn Notes into a Summary', 'student_instructions' => 'Study how the model combines and paraphrases instead of copying every event.',
                'content' => 'Summary plan: name the problem; combine the main tests and revisions; state the useful result; finish with what the process shows. Model sentence: “After noticing that volunteers needed a sturdy cart they could store easily, Mara designed and repeatedly tested a folding model.” This puts paragraphs 1–4 into new words and leaves out the tape color, exact vegetables, and personal opinions.',
                'interaction_data' => ['reading_passage' => $passage, 'focus_sentence_ids' => ['m1s3', 'm1s4', 'm3s2', 'm4s4']],
                'feedback' => ['correct' => 'You are ready to write a concise, objective summary in your own words.'], 'completion_condition' => ['type' => 'acknowledge'], 'reward_label' => 'Summary plan ready', 'theme_key' => 'learn',
            ],
            [
                'sequence' => 6, 'source_lesson_section_id' => $source('written_response'), 'activity_type' => 'project',
                'display_title' => 'Write Your Objective Summary', 'student_instructions' => 'Select three summary-worthy details, then write 4–6 sentences in your own words. Include the central idea, major problem/actions/result, and no opinion or invented information.',
                'content' => 'Your selected sentences remain visible as evidence. Use them as support, but paraphrase rather than copying them into your summary.',
                'interaction_data' => ['reading_passage' => $passage, 'elar_response_builder' => [
                    'fields' => [
                        ['id' => 'problem_evidence', 'label' => 'Select the detail that best establishes the problem to solve.', 'control' => 'evidence_select', 'choices' => $evidence([1])],
                        ['id' => 'process_evidence', 'label' => 'Select the detail that best represents Mara’s testing process.', 'control' => 'evidence_select', 'choices' => $evidence([3, 4])],
                        ['id' => 'result_evidence', 'label' => 'Select the detail that best states the successful result.', 'control' => 'evidence_select', 'choices' => $evidence([6])],
                        ['id' => 'central_idea', 'label' => 'State the central idea in one complete sentence.', 'control' => 'textarea', 'minimum_length' => 30],
                        ['id' => 'summary', 'label' => 'Write your 4–6 sentence objective summary in your own words.', 'control' => 'textarea', 'minimum_length' => 140],
                    ],
                    'expected_values' => ['problem_evidence' => ['m1s3', 'm1s4'], 'process_evidence' => ['m3s2', 'm4s4'], 'result_evidence' => 'm6s4'],
                    'field_feedback' => [
                        'problem_evidence' => 'Choose the sentence that establishes the volunteers’ need or Mara’s design goal, not a small setting detail.',
                        'process_evidence' => 'Choose a sentence that represents learning through recording or controlled testing.',
                        'result_evidence' => 'Choose the sentence that tells what the finished cart accomplished and how it met the storage need.',
                    ], 'teacher_review_required' => true,
                ]],
                'feedback' => ['correct' => 'Your evidence and summary are saved for teacher review.'], 'completion_condition' => ['type' => 'structured_elar_response'], 'reward_label' => 'Summary submitted', 'requires_teacher_review' => true, 'theme_key' => 'create',
            ],
            [
                'sequence' => 7, 'source_lesson_section_id' => $source('exit_check'), 'activity_type' => 'question_set',
                'display_title' => 'Central-Idea Mission Check', 'student_instructions' => 'Check the decisions that make a summary accurate, concise, objective, and original.',
                'interaction_data' => ['questions' => [
                    ['id' => 'whole', 'prompt' => 'Why is “a folding cart” not a central idea?', 'choices' => [['id' => 'topic', 'label' => 'It names only the topic and does not state what the text develops about it.'], ['id' => 'false', 'label' => 'It is not mentioned in the passage.'], ['id' => 'opinion', 'label' => 'It is an opinion.']]],
                    ['id' => 'omit', 'prompt' => 'Which detail should an objective summary omit?', 'choices' => [['id' => 'orange', 'label' => 'The tape on the model was bright orange.'], ['id' => 'tests', 'label' => 'Mara tested and revised the cart.'], ['id' => 'delivery', 'label' => 'The cart carried four boxes in one trip.']]],
                    ['id' => 'own_words', 'prompt' => 'What does “in your own words” require?', 'choices' => [['id' => 'paraphrase', 'label' => 'Combine and restate the important ideas without copying long sentences.'], ['id' => 'invent', 'label' => 'Add events that would make the story more exciting.'], ['id' => 'opinion', 'label' => 'Explain whether you liked Mara’s invention.']]],
                ], 'answer_feedback' => [
                    'whole' => ['default' => 'A central idea is a complete claim the author develops across the whole text; a topic only names the subject.'],
                    'omit' => ['default' => 'Keep the problem, major actions, result, and lesson. Omit colorful details that do not change those ideas.'],
                    'own_words' => ['default' => 'A summary paraphrases the author’s important ideas; it does not invent events or add personal reactions.'],
                ]],
                'answer_data' => ['answers' => ['whole' => 'topic', 'omit' => 'orange', 'own_words' => 'paraphrase']],
                'feedback' => ['correct' => 'You can distinguish a topic from a central idea and shape an objective summary.', 'incorrect' => 'Review the targeted explanation and retry.'], 'completion_condition' => ['type' => 'correct'], 'reward_label' => 'Summary mission complete', 'theme_key' => 'check',
            ],
        ];

        $experience = $this->createFromBlueprint($lesson, [
            'status' => 'preview', 'theme_key' => 'elar-reader-lab', 'mission_title' => 'The Big-Idea Briefing',
            'mission_brief' => 'Read an inventor story, decide which details truly matter, and build an accurate summary in your own words.',
            'completion_title' => 'Big-Idea Briefing Complete',
            'completion_message' => 'You identified a supported central idea and submitted an objective summary with saved text evidence.',
            'source_version' => 'elar-central-idea-v1',
        ], $activities, true);
        $this->provisionElarDigitalResources($lesson, 'central_idea_summary_guide');

        return $experience;
    }

    public function provisionElarPointOfViewInferencePrototype(Lesson $lesson): LessonExperience
    {
        if ($lesson->title !== ElarLessonContent::LESSON_THREE_TITLE || $lesson->sequence !== 3) {
            throw ValidationException::withMessages(['lesson' => 'The inference preview is reserved for the selected ELAR Lesson 3.']);
        }
        $sections = $lesson->allSections()->get()->keyBy('section_type');
        foreach (['introduction', 'source_examination', 'guided_practice', 'independent_practice', 'check_for_understanding'] as $required) {
            if (! $sections->has($required)) throw ValidationException::withMessages(['lesson' => "The selected lesson is missing its {$required} source section."]);
        }
        $source = fn (string $type): int => $sections->get($type)->id;
        $passage = ElarLessonContent::maraPassage();
        $evidence = fn (?array $paragraphs = null): array => ElarLessonContent::maraEvidenceChoices($paragraphs);
        $activities = [
            [
                'sequence' => 1, 'source_lesson_section_id' => $source('introduction'), 'activity_type' => 'instruction',
                'display_title' => 'Learn the Evidence Chain', 'student_instructions' => 'Learn how point of view, facts, inferences, evidence, and reasoning work together before analyzing the passage.',
                'content' => 'Point of view is the position from which a narrator presents events. This passage uses third person: the narrator says Mara and she, not I. A directly stated fact appears in the words on the page. An inference is a conclusion the reader forms by combining a stated detail with reasoning. Text evidence is the exact action or detail that supports the inference; reasoning explains why it supports that idea.',
                'interaction_data' => ['facts' => [
                    ['label' => 'Third-person narrator', 'detail' => 'Uses names, he, she, or they. The narrator is outside the events and can reveal selected thoughts or feelings.'],
                    ['label' => 'Perspective', 'detail' => 'Whose experience and thinking the narration mainly follows. Here, readers mainly follow Mara.'],
                    ['label' => 'Direct fact', 'detail' => 'Information the text states plainly. No conclusion is needed.'],
                    ['label' => 'Inference', 'detail' => 'A conclusion made from evidence plus reasoning—not a guess.'],
                    ['label' => 'Text evidence', 'detail' => 'Specific words, actions, or details that make an inference supportable.'],
                ]],
                'feedback' => ['correct' => 'You know every part of an evidence chain.'], 'completion_condition' => ['type' => 'acknowledge'], 'reward_label' => 'Evidence chain learned', 'theme_key' => 'learn',
            ],
            [
                'sequence' => 2, 'source_lesson_section_id' => $source('source_examination'), 'activity_type' => 'instruction',
                'display_title' => 'Read Through Mara’s Perspective', 'student_instructions' => 'Read the passage, watching what the narrator reveals about Mara and what remains unknown about other characters.',
                'content' => 'The narrator calls Mara by name and reveals that she “wanted to push the crooked model aside.” That gives readers access to Mara’s private reaction. The uncle’s suggestion is stated, but his private thoughts are not. This third-person narration mainly follows Mara’s perspective.',
                'interaction_data' => ['reading_passage' => $passage, 'focus_sentence_ids' => ['m3s1', 'm3s3']],
                'feedback' => ['correct' => 'You traced what the narrator lets readers know about Mara.'], 'completion_condition' => ['type' => 'acknowledge'], 'reward_label' => 'Perspective traced', 'theme_key' => 'learn',
            ],
            [
                'sequence' => 3, 'source_lesson_section_id' => $source('introduction'), 'activity_type' => 'question_set',
                'display_title' => 'Fact or Inference?', 'student_instructions' => 'Classify what the text states and what a reader must conclude.',
                'interaction_data' => ['questions' => [
                    ['id' => 'pov', 'prompt' => 'What point of view does “Mara measured… She built…” signal?', 'choices' => [['id' => 'third', 'label' => 'Third person'], ['id' => 'first', 'label' => 'First person'], ['id' => 'mara_narrates', 'label' => 'Mara tells the story using I.']]],
                    ['id' => 'fact', 'prompt' => 'Which statement is directly stated?', 'choices' => [['id' => 'notebook', 'label' => 'Mara wrote “sides need a lock” in her notebook.'], ['id' => 'determined', 'label' => 'Mara is determined.'], ['id' => 'proud', 'label' => 'Her uncle feels proud of her.']]],
                    ['id' => 'inference', 'prompt' => 'Which statement is an inference rather than a directly stated fact?', 'choices' => [['id' => 'four', 'label' => 'The cart carried four boxes.'], ['id' => 'persistent', 'label' => 'Mara responds to setbacks with persistence.'], ['id' => 'tabs', 'label' => 'The tabs held during a test.']]],
                ], 'answer_feedback' => [
                    'pov' => ['default' => 'Look at the pronouns and who tells the story: the narrator says Mara and she, not I.'],
                    'fact' => ['default' => 'A direct fact can be pointed to word-for-word in the passage; a feeling or trait may require inference.'],
                    'inference' => ['default' => 'An inference is a conclusion that needs evidence and reasoning. The other choices repeat stated events.'],
                ]],
                'answer_data' => ['answers' => ['pov' => 'third', 'fact' => 'notebook', 'inference' => 'persistent']],
                'feedback' => ['correct' => 'You separated stated information from a supported conclusion.', 'incorrect' => 'Use the targeted clue and retry.'], 'completion_condition' => ['type' => 'correct'], 'reward_label' => 'Facts classified', 'theme_key' => 'challenge',
            ],
            [
                'sequence' => 4, 'source_lesson_section_id' => $source('guided_practice'), 'activity_type' => 'question_set',
                'display_title' => 'Model Evidence Plus Reasoning', 'student_instructions' => 'Follow the model, then choose the evidence and reasoning that actually support the inference.',
                'content' => 'Model: I infer that Mara is determined. Evidence: after wanting to stop, she records the problem and studies the hinge. Reasoning: continuing to study and revise a difficult design instead of abandoning it demonstrates determination. Evidence alone is not the explanation; reasoning connects the action to the trait.',
                'interaction_data' => ['reading_passage' => $passage, 'focus_sentence_ids' => ['m3s1', 'm3s2'], 'questions' => [
                    ['id' => 'evidence', 'prompt' => 'Which later sentence gives additional evidence that Mara treats setbacks as information?', 'choices' => [['id' => 'soil', 'label' => 'She adds the soil problem to a list for the next testing round.'], ['id' => 'brown', 'label' => 'The full-size prototype is plain brown.'], ['id' => 'herbs', 'label' => 'One box contains herbs.']]],
                    ['id' => 'reasoning', 'prompt' => 'Why does that evidence support the persistence inference?', 'choices' => [['id' => 'continues', 'label' => 'She uses a new problem to plan more work instead of treating it as a reason to quit.'], ['id' => 'color', 'label' => 'The cart has more than one color.'], ['id' => 'guess', 'label' => 'Persistent people probably like gardens.']]],
                ], 'answer_feedback' => [
                    'evidence' => ['default' => 'Choose an action that shows what Mara does when she notices another problem.'],
                    'reasoning' => ['default' => 'Explain how the selected action demonstrates continuing through difficulty; do not add an unrelated guess.'],
                ]],
                'answer_data' => ['answers' => ['evidence' => 'soil', 'reasoning' => 'continues']],
                'feedback' => ['correct' => 'The action and reasoning form a supported inference.', 'incorrect' => 'The inference may be possible, but that choice does not support it. Try again.'], 'completion_condition' => ['type' => 'correct'], 'reward_label' => 'Evidence connected', 'theme_key' => 'challenge',
            ],
            [
                'sequence' => 5, 'source_lesson_section_id' => $source('independent_practice'), 'activity_type' => 'project',
                'display_title' => 'Build Your Inference Case', 'student_instructions' => 'Answer the specific question about setbacks. State one inference, select two relevant sentences, and explain how each sentence supports your conclusion.',
                'content' => 'Question: What can the reader infer about how Mara responds to setbacks? Use the frame “I infer ___ because the text states ___; this suggests ___.” Your selected evidence stays saved beside your explanations.',
                'interaction_data' => ['reading_passage' => $passage, 'elar_response_builder' => [
                    'fields' => [
                        ['id' => 'point_of_view', 'label' => 'Whose experience and thinking does the third-person narrator mainly follow?', 'control' => 'select', 'choices' => [['id' => 'mara', 'label' => 'Mara’s'], ['id' => 'uncle', 'label' => 'Her uncle’s'], ['id' => 'volunteer', 'label' => 'The volunteer’s']]],
                        ['id' => 'inference', 'label' => 'State your inference about how Mara responds to setbacks.', 'control' => 'textarea', 'minimum_length' => 25],
                        ['id' => 'evidence_one', 'label' => 'Select evidence from the first setback.', 'control' => 'evidence_select', 'choices' => $evidence([3])],
                        ['id' => 'reasoning_one', 'label' => 'Explain how this action supports your inference.', 'control' => 'textarea', 'minimum_length' => 30],
                        ['id' => 'evidence_two', 'label' => 'Select evidence from a later setback.', 'control' => 'evidence_select', 'choices' => $evidence([7])],
                        ['id' => 'reasoning_two', 'label' => 'Explain how this later action strengthens your inference.', 'control' => 'textarea', 'minimum_length' => 30],
                    ],
                    'expected_values' => ['point_of_view' => 'mara', 'evidence_one' => ['m3s2', 'm3s4'], 'evidence_two' => ['m7s2', 'm7s3']],
                    'field_feedback' => [
                        'point_of_view' => 'Look at whose private reaction the narrator reveals and whose design process the passage follows.',
                        'evidence_one' => 'Select an action that shows Mara studying, recording, or responding constructively to the failed model.',
                        'evidence_two' => 'Select what Mara notices or does when a new soil problem appears after the successful delivery.',
                    ], 'teacher_review_required' => true,
                ]],
                'feedback' => ['correct' => 'Your point-of-view analysis, inference, evidence, and reasoning are saved for teacher review.'], 'completion_condition' => ['type' => 'structured_elar_response'], 'reward_label' => 'Inference case submitted', 'requires_teacher_review' => true, 'theme_key' => 'create',
            ],
            [
                'sequence' => 6, 'source_lesson_section_id' => $source('check_for_understanding'), 'activity_type' => 'question_set',
                'display_title' => 'Evidence Case Check', 'student_instructions' => 'Choose the statements that keep an inference grounded in this text.',
                'interaction_data' => ['questions' => [
                    ['id' => 'perspective', 'prompt' => 'What does the narrator let readers know directly?', 'choices' => [['id' => 'mara', 'label' => 'Mara briefly wants to set the model aside.'], ['id' => 'uncle', 'label' => 'Exactly why her uncle suggests string.'], ['id' => 'volunteer', 'label' => 'Everything the volunteer thinks about Mara.']]],
                    ['id' => 'supported', 'prompt' => 'Which inference is best supported by Mara’s notebook entries and repeated tests?', 'choices' => [['id' => 'learns', 'label' => 'Mara uses setbacks to guide continued improvement.'], ['id' => 'hates', 'label' => 'Mara dislikes working with other people.'], ['id' => 'perfect', 'label' => 'Mara believes every first design should be perfect.']]],
                    ['id' => 'connection', 'prompt' => 'What must come after citing evidence in a strong response?', 'choices' => [['id' => 'reason', 'label' => 'Reasoning that explains how the detail supports the inference.'], ['id' => 'new', 'label' => 'A new fact that is not in the text.'], ['id' => 'opinion', 'label' => 'A personal rating of the story.']]],
                ], 'answer_feedback' => [
                    'perspective' => ['default' => 'Look at what the narrator explicitly reveals about Mara’s inner reaction, not thoughts the text withholds.'],
                    'supported' => ['default' => 'Choose the conclusion supported by repeated recording, testing, and revision across the passage.'],
                    'connection' => ['default' => 'Evidence needs reasoning that connects the quoted or selected action to the inference.'],
                ]],
                'answer_data' => ['answers' => ['perspective' => 'mara', 'supported' => 'learns', 'connection' => 'reason']],
                'feedback' => ['correct' => 'You can distinguish perspective, inference, evidence, and reasoning.', 'incorrect' => 'Use the targeted evidence clue and retry.'], 'completion_condition' => ['type' => 'correct'], 'reward_label' => 'Evidence case complete', 'theme_key' => 'check',
            ],
        ];

        $experience = $this->createFromBlueprint($lesson, [
            'status' => 'preview', 'theme_key' => 'elar-reader-lab', 'mission_title' => 'The Evidence Case File',
            'mission_brief' => 'Track the narrator’s perspective, separate facts from inferences, and prove a conclusion with two saved details.',
            'completion_title' => 'Evidence Case Solved',
            'completion_message' => 'You analyzed point of view and submitted a supported inference with two evidence-and-reasoning connections.',
            'source_version' => 'elar-inference-v1',
        ], $activities, true);
        $this->provisionElarDigitalResources($lesson, 'point_of_view_inference_guide');

        return $experience;
    }

    public function provisionMathRepresentationsPrototype(Lesson $lesson): LessonExperience
    {
        $this->assertMathLesson($lesson, 2, 'Choose Tools and Represent Mathematics');
        $sections = $lesson->allSections()->get()->keyBy('section_type');
        foreach (['hook', 'direct_instruction', 'demonstration', 'guided_practice', 'independent_practice', 'exit_check'] as $required) {
            if (! $sections->has($required)) throw ValidationException::withMessages(['lesson' => "The selected lesson is missing its {$required} source section."]);
        }
        $source = fn (string $type): int => $sections->get($type)->id;
        $toolCards = [
            ['label' => 'Mental math', 'detail' => 'Simplifies a relationship you already understand.', 'example' => '18 ÷ 9 = 2 can be seen without writing a long calculation.'],
            ['label' => 'Estimation', 'detail' => 'Creates a nearby benchmark for checking an answer.', 'example' => '18 is near 20, so 20 × 24 is near 480.'],
            ['label' => 'Equation', 'detail' => 'Shows operations and equality with symbols.', 'example' => '(18 × 24) ÷ 9 = 48.'],
            ['label' => 'Table', 'detail' => 'Organizes repeated or changing quantities in rows and columns.', 'example' => 'List shelves and the equal number assigned to each.'],
            ['label' => 'Labeled bar diagram', 'detail' => 'Shows a whole divided into parts or equal shares.', 'example' => 'A 432-can bar divided into 9 equal shelf sections.'],
        ];
        $activities = [
            [
                'sequence' => 1, 'source_lesson_section_id' => $source('hook'), 'activity_type' => 'instruction',
                'display_title' => 'Tools Help Us See Relationships',
                'student_instructions' => 'Learn what each tool or representation is for before choosing one. A useful tool supports thinking; it does not replace understanding the quantities.',
                'content' => 'The same tool is not best for every problem. Choose a tool because it makes a relationship clearer, organizes information, reduces errors, or helps check the result.',
                'interaction_data' => ['math_visual' => ['mode' => 'concept_cards', 'aria_label' => 'Tool and representation guide', 'cards' => $toolCards]],
                'feedback' => ['correct' => 'You now have five choices and a reason for using each one.'], 'completion_condition' => ['type' => 'acknowledge'], 'reward_label' => 'Tool purposes learned', 'theme_key' => 'learn',
            ],
            [
                'sequence' => 2, 'source_lesson_section_id' => $source('direct_instruction'), 'activity_type' => 'matching',
                'display_title' => 'Match Each Representation to Its Job',
                'student_instructions' => 'Match each representation to the relationship it communicates most directly.',
                'interaction_data' => ['math_visual' => ['mode' => 'concept_cards', 'aria_label' => 'Representation reminders', 'cards' => array_slice($toolCards, 1)], 'prompts' => [
                    ['id' => 'estimate', 'label' => 'Estimation'], ['id' => 'equation', 'label' => 'Equation'], ['id' => 'table', 'label' => 'Table'], ['id' => 'bar', 'label' => 'Labeled bar diagram'],
                ], 'options' => [
                    ['id' => 'benchmark', 'label' => 'Creates a nearby benchmark'], ['id' => 'operations', 'label' => 'Shows operations and equality'], ['id' => 'organize', 'label' => 'Organizes quantities in rows and columns'], ['id' => 'parts', 'label' => 'Shows a whole split into parts or equal shares'],
                ]],
                'answer_data' => ['matches' => ['estimate' => 'benchmark', 'equation' => 'operations', 'table' => 'organize', 'bar' => 'parts']],
                'feedback' => ['correct' => 'Each representation is connected to the job it performs.', 'incorrect' => 'Revisit the guide. Ask what relationship each representation makes easiest to see.'],
                'completion_condition' => ['type' => 'correct'], 'reward_label' => 'Representation jobs matched', 'theme_key' => 'challenge',
            ],
            [
                'sequence' => 3, 'source_lesson_section_id' => $source('demonstration'), 'activity_type' => 'instruction',
                'display_title' => 'Worked Example: Pantry Shelves',
                'student_instructions' => 'Follow the labeled equal-share bar and both connected equation paths. Notice what every number represents.',
                'content' => 'A pantry has 18 crates with 24 cans in each crate. The cans are divided equally among 9 shelves. First find 18 × 24 = 432 cans, then share 432 among 9 shelves: 432 ÷ 9 = 48 cans per shelf. A more efficient relationship is 18 ÷ 9 = 2 crates’ worth per shelf, then 2 × 24 = 48 cans. Both paths describe the same quantities and result.',
                'interaction_data' => ['math_visual' => ['mode' => 'equal_share', 'total_label' => 'All cans', 'total' => 432, 'groups' => 9, 'group_unit' => 'shelves', 'item_unit' => 'cans', 'per_group' => 48, 'caption' => 'Each of the 9 equal sections represents one shelf with 48 cans.']],
                'feedback' => ['correct' => 'The bar, the combined equation, and the efficient two-step equation all represent 48 cans on each shelf.'],
                'completion_condition' => ['type' => 'acknowledge'], 'reward_label' => 'Connected example modeled', 'theme_key' => 'learn',
            ],
            [
                'sequence' => 4, 'source_lesson_section_id' => $source('demonstration'), 'activity_type' => 'question_set',
                'display_title' => 'Connect the Pantry Model and Equations',
                'student_instructions' => 'Use the complete pantry model beside these questions. Identify what the numbers and equal sections mean.',
                'content' => 'The bar shows 432 cans divided into 9 equal shelf sections. Each section contains 48 cans.',
                'interaction_data' => ['math_visual' => ['mode' => 'equal_share', 'total_label' => 'All cans', 'total' => 432, 'groups' => 9, 'group_unit' => 'shelves', 'item_unit' => 'cans', 'per_group' => 48], 'questions' => [
                    ['id' => 'whole', 'prompt' => 'What does 432 represent?', 'choices' => [['id' => 'all_cans', 'label' => 'All cans in the 18 crates'], ['id' => 'shelves', 'label' => 'The number of shelves'], ['id' => 'per_shelf', 'label' => 'The cans on one shelf']]],
                    ['id' => 'sections', 'prompt' => 'Why is the bar divided into 9 equal sections?', 'choices' => [['id' => 'shelves', 'label' => 'There are 9 shelves receiving equal shares'], ['id' => 'crates', 'label' => 'There are 9 cans in every crate'], ['id' => 'estimate', 'label' => 'Nine is only an estimate']]],
                    ['id' => 'efficient', 'prompt' => 'What does 18 ÷ 9 = 2 mean in the efficient approach?', 'choices' => [['id' => 'crates_per_shelf', 'label' => 'Each shelf receives the cans from 2 crates’ worth'], ['id' => 'cans_per_crate', 'label' => 'Each crate contains 2 cans'], ['id' => 'two_shelves', 'label' => 'Only 2 shelves are used']]],
                ], 'answer_feedback' => [
                    'whole' => ['default' => 'The whole bar must represent the total produced by 18 crates × 24 cans.'],
                    'sections' => ['default' => 'Connect the number of equal bar sections to the number of shelves in the problem.'],
                    'efficient' => ['default' => 'Dividing 18 crates among 9 shelves gives 2 crates’ worth for each shelf.'],
                ]],
                'answer_data' => ['answers' => ['whole' => 'all_cans', 'sections' => 'shelves', 'efficient' => 'crates_per_shelf']],
                'feedback' => ['correct' => 'You connected the labels, bar sections, and efficient equation to the pantry quantities.', 'incorrect' => 'Use the labels in the problem and the model rather than treating the numbers as unlabeled values.'],
                'completion_condition' => ['type' => 'correct'], 'reward_label' => 'Model connections explained', 'theme_key' => 'challenge',
            ],
            [
                'sequence' => 5, 'source_lesson_section_id' => $source('guided_practice'), 'activity_type' => 'project',
                'display_title' => 'Guided Practice: Reading Challenge Shelves',
                'student_instructions' => 'Build the connection with guided fields. The equal-share model keeps every quantity visible, and your work saves automatically.',
                'content' => 'A reading challenge has 16 teams. Each team reads 35 books. The books are displayed equally on 8 shelves. How many books are displayed on each shelf?',
                'interaction_data' => ['math_visual' => ['mode' => 'equal_share', 'total_label' => 'Teams’ books', 'total' => '16 teams × 35 books', 'groups' => 8, 'group_unit' => 'shelves', 'item_unit' => 'books', 'caption' => 'First decide how many teams’ books belong to each shelf.'], 'math_work_builder' => [
                    'submit_label' => 'Check the guided representation', 'teacher_review_required' => true, 'sections' => [
                        ['title' => 'Choose and label', 'fields' => [
                            ['id' => 'representation', 'label' => 'Which combination will show the equal sharing and the calculations?', 'control' => 'select', 'choices' => [['id' => 'bar_equations', 'label' => 'A labeled equal-share bar and equations'], ['id' => 'calculator_only', 'label' => 'A calculator answer with no labels'], ['id' => 'unlabeled_number', 'label' => 'One unlabeled number']]],
                            ['id' => 'teams_per_shelf', 'label' => '16 teams ÷ 8 shelves = ___ teams’ books per shelf', 'control' => 'number'],
                            ['id' => 'books_per_shelf', 'label' => '2 teams × 35 books = ___ books per shelf', 'control' => 'number'],
                            ['id' => 'answer', 'label' => 'Books displayed on each shelf', 'control' => 'number'],
                        ]],
                        ['title' => 'Connect the representation', 'fields' => [
                            ['id' => 'equation', 'label' => 'Which equation represents the complete situation?', 'control' => 'select', 'choices' => [['id' => 'combined', 'label' => '(16 × 35) ÷ 8 = 70'], ['id' => 'add', 'label' => '16 + 35 + 8 = 59'], ['id' => 'divide_wrong', 'label' => '35 ÷ 16 = 8']]],
                            ['id' => 'connection', 'label' => 'Explain how the 8 equal shelf sections and your equations describe the same situation.', 'control' => 'textarea', 'minimum_length' => 25],
                        ]],
                    ],
                    'expected_values' => ['representation' => 'bar_equations', 'teams_per_shelf' => '2', 'books_per_shelf' => '70', 'answer' => '70', 'equation' => 'combined'],
                    'field_feedback' => [
                        'representation' => 'Choose a representation that shows both the equal shelf shares and the calculations with labels.',
                        'teams_per_shelf' => 'Share 16 teams equally among 8 shelves: 16 ÷ 8 = 2.',
                        'books_per_shelf' => 'Each shelf receives 2 teams’ books, and each team read 35 books: 2 × 35 = 70.',
                        'answer' => 'The question asks for books on each shelf, not teams on each shelf.',
                        'equation' => 'The complete equation must multiply to find all books and divide them equally among 8 shelves.',
                    ],
                ]],
                'feedback' => ['correct' => 'Your labeled representation and equations both show 70 books on each shelf.'],
                'completion_condition' => ['type' => 'correct_structured_math_work'], 'reward_label' => 'Guided representation connected', 'requires_teacher_review' => true, 'theme_key' => 'create',
            ],
            [
                'sequence' => 6, 'source_lesson_section_id' => $source('guided_practice'), 'activity_type' => 'question_set',
                'display_title' => 'Compare Two Correct Approaches',
                'student_instructions' => 'Both approaches reach 70. Compare what each one calculates first and identify why the efficient approach works.',
                'content' => 'Approach 1: (16 × 35) ÷ 8 = 560 ÷ 8 = 70. Approach 2: 16 ÷ 8 = 2 teams’ books per shelf, then 2 × 35 = 70.',
                'interaction_data' => ['math_visual' => ['mode' => 'equation_steps', 'steps' => [
                    ['equation' => '(16 × 35) ÷ 8 = 70', 'meaning' => 'Find all books, then share them among 8 shelves.'],
                    ['equation' => '16 ÷ 8 = 2; 2 × 35 = 70', 'meaning' => 'Share the teams first, then count the books from 2 teams.'],
                ]], 'questions' => [
                    ['id' => 'same', 'prompt' => 'Why can both approaches be correct?', 'choices' => [['id' => 'same_quantities', 'label' => 'They represent the same teams, books, and equal shelf shares in different orders'], ['id' => 'same_symbols', 'label' => 'They use exactly the same symbols in the same order'], ['id' => 'always', 'label' => 'Any two equations for a word problem are automatically correct']]],
                    ['id' => 'efficient', 'prompt' => 'Why is dividing 16 by 8 first efficient?', 'choices' => [['id' => 'small_relationship', 'label' => 'It uses the simple relationship of 2 teams’ books per shelf before multiplying'], ['id' => 'removes_books', 'label' => 'It removes the 35 books from the problem'], ['id' => 'estimate_only', 'label' => 'It changes the exact problem into an estimate']]],
                ], 'answer_feedback' => ['same' => ['default' => 'A correct alternative must keep the same quantities and equal-sharing relationship.'], 'efficient' => ['default' => 'Look at the simple whole-number relationship between 16 teams and 8 shelves.']]],
                'answer_data' => ['answers' => ['same' => 'same_quantities', 'efficient' => 'small_relationship']],
                'feedback' => ['correct' => 'You compared two valid approaches and explained why one uses a simpler relationship first.', 'incorrect' => 'Track what every number represents in both equation paths.'],
                'completion_condition' => ['type' => 'correct'], 'reward_label' => 'Approaches compared', 'theme_key' => 'challenge',
            ],
            [
                'sequence' => 7, 'source_lesson_section_id' => $source('independent_practice'), 'activity_type' => 'project',
                'display_title' => 'Independent Practice: Garden Sections',
                'student_instructions' => 'Select and defend a representation, solve the complete problem, and connect every important number to the situation. Your work saves automatically.',
                'content' => 'Twenty-four garden rows each contain 15 plants. The plants are divided equally into 6 sections. How many plants are in each section?',
                'interaction_data' => ['math_visual' => ['mode' => 'equal_share', 'total_label' => 'Garden rows', 'total' => 24, 'groups' => 6, 'group_unit' => 'sections', 'item_unit' => 'rows', 'caption' => 'Use the six equal sections to organize the 24 rows before counting plants.'], 'math_work_builder' => [
                    'submit_label' => 'Check and save the garden representation', 'teacher_review_required' => true, 'sections' => [
                        ['title' => 'Choose and solve', 'fields' => [
                            ['id' => 'representation', 'label' => 'Which representation will you use?', 'control' => 'select', 'choices' => [['id' => 'bar_equations', 'label' => 'A labeled equal-share bar and equations'], ['id' => 'table_equations', 'label' => 'A labeled table and equations'], ['id' => 'answer_only', 'label' => 'An answer without labels or work']]],
                            ['id' => 'why', 'label' => 'Why does your selected representation fit this problem?', 'control' => 'textarea', 'minimum_length' => 20],
                            ['id' => 'rows_per_section', 'label' => '24 rows ÷ 6 sections = rows per section', 'control' => 'number'],
                            ['id' => 'plants_per_section', 'label' => 'Rows per section × 15 plants = plants per section', 'control' => 'number'],
                            ['id' => 'equation', 'label' => 'Which combined equation matches?', 'control' => 'select', 'choices' => [['id' => 'combined', 'label' => '(24 × 15) ÷ 6 = 60'], ['id' => 'add', 'label' => '24 + 15 + 6 = 45'], ['id' => 'wrong_order', 'label' => '24 ÷ 15 × 6 = 60']]],
                        ]],
                        ['title' => 'Connect and conclude', 'fields' => [
                            ['id' => 'meaning_four', 'label' => 'What does the 4 represent?', 'control' => 'textarea', 'minimum_length' => 15],
                            ['id' => 'answer', 'label' => 'Final number of plants in each section', 'control' => 'number'],
                            ['id' => 'connection', 'label' => 'Explain how your representation corresponds to the equations and final answer.', 'control' => 'textarea', 'minimum_length' => 30],
                        ]],
                    ],
                    'expected_values' => ['representation' => ['bar_equations', 'table_equations'], 'rows_per_section' => '4', 'plants_per_section' => '60', 'equation' => 'combined', 'answer' => '60'],
                    'field_feedback' => [
                        'representation' => 'Choose a labeled bar or table paired with equations; an answer without labels does not show the relationships.',
                        'rows_per_section' => 'Share 24 rows equally among 6 sections: 24 ÷ 6 = 4 rows per section.',
                        'plants_per_section' => 'Each section receives 4 rows, with 15 plants in each row: 4 × 15 = 60.',
                        'equation' => 'The complete situation multiplies rows by plants per row, then divides all plants among 6 sections.',
                        'answer' => 'Four counts rows per section. The question asks for plants per section.'],
                ]],
                'feedback' => ['correct' => 'Your representation, equations, labels, and explanation are saved for review.'],
                'completion_condition' => ['type' => 'correct_structured_math_work'], 'reward_label' => 'Independent representation completed', 'requires_teacher_review' => true, 'theme_key' => 'create',
            ],
            [
                'sequence' => 8, 'source_lesson_section_id' => $source('exit_check'), 'activity_type' => 'question_set',
                'display_title' => 'Connect 4 Rows to 60 Plants',
                'student_instructions' => 'Complete the final quantity check using the garden problem and its labeled equal-share model.',
                'content' => 'The garden has 24 rows shared among 6 sections, and every row contains 15 plants.',
                'interaction_data' => ['math_visual' => ['mode' => 'equal_share', 'total_label' => 'Garden rows', 'total' => 24, 'groups' => 6, 'group_unit' => 'sections', 'item_unit' => 'rows', 'per_group' => 4], 'questions' => [
                    ['id' => 'four', 'prompt' => 'What does 4 represent?', 'choices' => [['id' => 'rows', 'label' => '4 rows assigned to each section'], ['id' => 'plants', 'label' => '4 plants in each section'], ['id' => 'sections', 'label' => 'Only 4 garden sections']]],
                    ['id' => 'sixty', 'prompt' => 'Why is the final answer 60 rather than 4?', 'choices' => [['id' => 'plants', 'label' => 'Each section has 4 rows × 15 plants, which is 60 plants'], ['id' => 'largest', 'label' => 'Sixty is simply the largest number'], ['id' => 'estimate', 'label' => 'Four is only an estimate of 60']]],
                ], 'answer_feedback' => ['four' => ['default' => 'The result of 24 rows ÷ 6 sections must be labeled in rows per section.'], 'sixty' => ['default' => 'The question asks for plants, so connect the 4 rows to 15 plants in every row.']]],
                'answer_data' => ['answers' => ['four' => 'rows', 'sixty' => 'plants']],
                'feedback' => ['correct' => 'You explained both intermediate and final quantities with accurate units.', 'incorrect' => 'Label each result with the quantity it counts.'],
                'completion_condition' => ['type' => 'correct'], 'reward_label' => 'Quantities connected', 'theme_key' => 'check',
            ],
        ];
        $experience = $this->createFromBlueprint($lesson, [
            'status' => 'preview', 'theme_key' => 'math-representations', 'mission_title' => 'Representation Connection Lab',
            'mission_brief' => 'Learn what Math tools communicate, connect a modeled bar to equations, compare two approaches, and build an independent labeled representation.',
            'completion_title' => 'Representation Connection Lab Complete', 'completion_message' => 'You selected, labeled, solved with, and defended a representation that matches the quantities and equations.',
            'source_version' => 'math-lesson-2-representations-v1',
        ], $activities, synchronizePreview: true);
        $this->provisionMathLessonResources($lesson, [
            'Tool and Representation Guide' => 'tool_representation_guide',
            'Connected Representations Practice' => 'connected_representations',
        ], 'No ordinary parent preparation is required. The app teaches the tool purposes, displays every problem and model, and saves Kai’s guided and independent representation work. Generated reference pages remain optional teacher fallbacks. Review the official district standards language before treating the sparse code-only alignments as complete statements.');
        return $experience->fresh('activities');
    }

    public function provisionMathReasoningPrototype(Lesson $lesson): LessonExperience
    {
        $this->assertMathLesson($lesson, 3, 'Explain, Evaluate, and Revise Mathematical Reasoning');
        $sections = $lesson->allSections()->get()->keyBy('section_type');
        foreach (['introduction', 'direct_instruction', 'example', 'activity', 'written_response', 'reflection'] as $required) {
            if (! $sections->has($required)) throw ValidationException::withMessages(['lesson' => "The selected lesson is missing its {$required} source section."]);
        }
        $source = fn (string $type): int => $sections->get($type)->id;
        $argumentCards = [
            ['label' => 'Quantities', 'detail' => 'Name what every important number counts.'],
            ['label' => 'Connected steps', 'detail' => 'Show how each equation or representation follows from the situation.'],
            ['label' => 'Conclusion', 'detail' => 'Answer the actual question with units.'],
            ['label' => 'Evidence', 'detail' => 'Include a calculation, representation, counterexample, or check.'],
            ['label' => 'Revision', 'detail' => 'Improve accuracy or clarity after testing the first explanation.'],
        ];
        $routineCards = [
            ['label' => 'Notice', 'detail' => 'Identify the claim and every step.'],
            ['label' => 'Test', 'detail' => 'Check calculations and whether the result fits the situation.'],
            ['label' => 'Explain', 'detail' => 'Name the exact point where reasoning succeeds or fails.'],
            ['label' => 'Revise', 'detail' => 'Replace the flawed step and write a complete corrected conclusion.'],
        ];
        $activities = [
            [
                'sequence' => 1, 'source_lesson_section_id' => $source('introduction'), 'activity_type' => 'instruction',
                'display_title' => 'What Makes an Argument Trustworthy?',
                'student_instructions' => 'Learn the parts a reader needs before evaluating anyone’s reasoning. A correct number alone is not a complete mathematical argument.',
                'content' => 'A strong argument names quantities, connects its steps, uses accurate symbols and words, answers the question with units, and gives evidence that the result is reasonable. Revising an explanation is part of doing mathematics, not a punishment.',
                'interaction_data' => ['math_visual' => ['mode' => 'concept_cards', 'aria_label' => 'Parts of a strong mathematical argument', 'cards' => $argumentCards]],
                'feedback' => ['correct' => 'You know what evidence to look for in a mathematical argument.'], 'completion_condition' => ['type' => 'acknowledge'], 'reward_label' => 'Argument parts learned', 'theme_key' => 'learn',
            ],
            [
                'sequence' => 2, 'source_lesson_section_id' => $source('direct_instruction'), 'activity_type' => 'instruction',
                'display_title' => 'Learn Notice–Test–Explain–Revise',
                'student_instructions' => 'Learn the four-step evaluation routine before applying it. Read each step in order.',
                'content' => 'Notice the claim and steps. Test both calculations and context. Explain the precise success or failure. Revise the flawed step and conclusion. Useful sentence starts include “The claim is incorrect because…” and “The corrected conclusion is….”',
                'interaction_data' => ['math_visual' => ['mode' => 'concept_cards', 'aria_label' => 'Notice Test Explain Revise routine', 'cards' => $routineCards]],
                'feedback' => ['correct' => 'The evaluation routine is ready: Notice, Test, Explain, Revise.'], 'completion_condition' => ['type' => 'acknowledge'], 'reward_label' => 'Evaluation routine learned', 'theme_key' => 'learn',
            ],
            [
                'sequence' => 3, 'source_lesson_section_id' => $source('example'), 'activity_type' => 'instruction',
                'display_title' => 'Worked Example: The Ticket Claim',
                'student_instructions' => 'Watch the complete routine applied to one flawed argument before evaluating a new one.',
                'content' => 'Claim: “156 tickets shared equally among 12 families gives each family 12 tickets, with 12 left over.” Notice: the claim reports a remainder equal to the divisor. Test: 12 × 12 = 144 and 156 − 144 = 12. Explain: those 12 leftover tickets form one more complete group because there are 12 families. Revise: give one more ticket to each family. Then 12 × 13 = 156, so every family receives 13 tickets and none remain.',
                'interaction_data' => ['math_visual' => ['mode' => 'equation_steps', 'steps' => [
                    ['equation' => '12 × 12 = 144; 156 − 144 = 12', 'meaning' => 'The proposed work leaves 12 tickets.'],
                    ['equation' => '12 leftovers ÷ 12 families = 1 more each', 'meaning' => 'A remainder equal to the divisor forms another complete group.'],
                    ['equation' => '12 × 13 = 156', 'meaning' => 'The corrected answer is 13 tickets per family with none left.'],
                ]]],
                'feedback' => ['correct' => 'The model noticed, tested, explained, and revised the exact error.'], 'completion_condition' => ['type' => 'acknowledge'], 'reward_label' => 'Error analysis modeled', 'theme_key' => 'learn',
            ],
            [
                'sequence' => 4, 'source_lesson_section_id' => $source('example'), 'activity_type' => 'question_set',
                'display_title' => 'Trace the Ticket Revision',
                'student_instructions' => 'Use the complete worked example beside these questions to identify the exact flaw and correction.',
                'content' => 'The proposed answer leaves 12 tickets, and the divisor is 12 families.',
                'interaction_data' => ['math_visual' => ['mode' => 'equation_steps', 'steps' => [
                    ['equation' => '12 × 12 = 144; remainder 12', 'meaning' => 'This cannot be the final division result because the remainder equals the divisor.'],
                    ['equation' => '12 × 13 = 156', 'meaning' => 'One more ticket per family distributes every ticket.'],
                ]], 'questions' => [
                    ['id' => 'flaw', 'prompt' => 'Why is “12 remainder 12” not a valid final result?', 'choices' => [['id' => 'full_group', 'label' => 'The 12 leftovers make one complete group of 12'], ['id' => 'too_small', 'label' => 'A remainder must always be larger than the divisor'], ['id' => 'ignore', 'label' => 'Leftover tickets should always be ignored']]],
                    ['id' => 'correction', 'prompt' => 'What is the corrected conclusion?', 'choices' => [['id' => 'thirteen', 'label' => 'Each family receives 13 tickets and none remain'], ['id' => 'twelve', 'label' => 'Each family receives 12 tickets and 12 remain'], ['id' => 'one', 'label' => 'Each family receives 1 ticket']]],
                ], 'answer_feedback' => ['flaw' => ['default' => 'Compare the 12 leftovers with the 12 families. They can be shared one per family.'], 'correction' => ['default' => 'Test the revised answer with 12 families × tickets per family = 156.']]],
                'answer_data' => ['answers' => ['flaw' => 'full_group', 'correction' => 'thirteen']],
                'feedback' => ['correct' => 'You identified the exact error and the corrected conclusion.', 'incorrect' => 'Use the modeled test rather than judging only the final number.'],
                'completion_condition' => ['type' => 'correct'], 'reward_label' => 'Modeled revision traced', 'theme_key' => 'challenge',
            ],
            [
                'sequence' => 5, 'source_lesson_section_id' => $source('activity'), 'activity_type' => 'question_set',
                'display_title' => 'Evaluate Two Postcard Solutions',
                'student_instructions' => 'Apply the routine to both complete responses. Every claim and calculation needed is shown here.',
                'content' => 'Problem: A museum packs 384 postcards equally into 16 display trays. Response A says 24 because 16 × 20 = 320, 16 × 4 = 64, and 320 + 64 = 384. Response B says 22 because “384 ÷ 16 gives 22,” but provides no calculation or evidence.',
                'interaction_data' => ['math_visual' => ['mode' => 'equation_steps', 'steps' => [
                    ['equation' => 'Response A: 16 × 20 + 16 × 4 = 320 + 64 = 384', 'meaning' => 'The partial products verify 16 × 24 = 384.'],
                    ['equation' => 'Response B: 384 ÷ 16 = 22', 'meaning' => 'The claimed quotient has no supporting calculation. Test it before accepting it.'],
                ]], 'questions' => [
                    ['id' => 'supported', 'prompt' => 'Which response is correct and sufficiently supported?', 'choices' => [['id' => 'a', 'label' => 'Response A'], ['id' => 'b', 'label' => 'Response B'], ['id' => 'both', 'label' => 'Both responses']]],
                    ['id' => 'evidence', 'prompt' => 'Which evidence verifies Response A?', 'choices' => [['id' => 'partials', 'label' => '16 × 20 = 320 and 16 × 4 = 64; together they make 384'], ['id' => 'claim', 'label' => 'It says 24, so no check is needed'], ['id' => 'larger', 'label' => '24 is larger than 22']]],
                    ['id' => 'b_flaw', 'prompt' => 'What is specifically missing or incorrect in Response B?', 'choices' => [['id' => 'wrong_no_evidence', 'label' => 'The quotient 22 is incorrect and no calculation supports it'], ['id' => 'too_many_words', 'label' => 'It uses too many mathematical words'], ['id' => 'no_estimate_only', 'label' => 'Every correct response must use estimation and no other check']]],
                ], 'answer_feedback' => [
                    'supported' => ['default' => 'Test each claimed quotient against 16 equal trays.'],
                    'evidence' => ['default' => 'Evidence should mathematically reconnect 24 trays’ shares to all 384 postcards.'],
                    'b_flaw' => ['default' => 'A specific evaluation names both the incorrect claim and its missing support.'],
                ]],
                'answer_data' => ['answers' => ['supported' => 'a', 'evidence' => 'partials', 'b_flaw' => 'wrong_no_evidence']],
                'feedback' => ['correct' => 'You evaluated correctness and evidence instead of trusting an unsupported answer.', 'incorrect' => 'Use Notice and Test: check the calculations and the evidence attached to each claim.'],
                'completion_condition' => ['type' => 'correct'], 'reward_label' => 'Arguments evaluated', 'theme_key' => 'challenge',
            ],
            [
                'sequence' => 6, 'source_lesson_section_id' => $source('activity'), 'activity_type' => 'project',
                'display_title' => 'Revise Response B',
                'student_instructions' => 'Replace the unsupported claim with a correct, evidence-based argument. Your revision saves automatically.',
                'content' => 'Revise Response B for the 384 postcards shared equally among 16 trays problem so another reader can verify it.',
                'interaction_data' => ['math_visual' => ['mode' => 'equation_steps', 'steps' => [
                    ['equation' => '16 × 20 = 320', 'meaning' => 'Twenty postcards per tray account for 320 postcards.'],
                    ['equation' => '384 − 320 = 64; 16 × 4 = 64', 'meaning' => 'Four more postcards per tray account for the remaining 64.'],
                ]], 'math_work_builder' => [
                    'submit_label' => 'Check and save the revision', 'teacher_review_required' => true, 'sections' => [
                        ['title' => 'Correct and support', 'fields' => [
                            ['id' => 'answer', 'label' => 'Correct postcards per tray', 'control' => 'number'],
                            ['id' => 'evidence', 'label' => 'Which evidence verifies the answer?', 'control' => 'select', 'choices' => [['id' => 'partials', 'label' => '16 × 20 + 16 × 4 = 320 + 64 = 384'], ['id' => 'unsupported', 'label' => '384 ÷ 16 = 22 because Response B says so'], ['id' => 'compare', 'label' => '24 is correct only because it is larger than 22']]],
                            ['id' => 'revision', 'label' => 'Write a corrected argument that explains the quantities and evidence.', 'control' => 'textarea', 'minimum_length' => 35],
                        ]],
                    ],
                    'expected_values' => ['answer' => '24', 'evidence' => 'partials'],
                    'field_feedback' => ['answer' => 'Test the quotient: 16 × 24 = 384.', 'evidence' => 'Choose evidence that reconstructs all 384 postcards using 16 equal trays.'],
                ]],
                'feedback' => ['correct' => 'Your corrected Response B includes an accurate conclusion and verifiable evidence.'],
                'completion_condition' => ['type' => 'correct_structured_math_work'], 'reward_label' => 'Argument revised', 'requires_teacher_review' => true, 'theme_key' => 'create',
            ],
            [
                'sequence' => 7, 'source_lesson_section_id' => $source('written_response'), 'activity_type' => 'project',
                'display_title' => 'Build and Revise a Snack-Box Argument',
                'student_instructions' => 'Create a complete argument, check it, name one strength, and make a purposeful revision. Every stage saves automatically.',
                'content' => 'A volunteer group packs 468 snack bags equally into 18 boxes. How many snack bags go in each box?',
                'interaction_data' => ['math_visual' => ['mode' => 'equal_share', 'total_label' => 'Snack bags', 'total' => 468, 'groups' => 18, 'group_unit' => 'boxes', 'item_unit' => 'bags', 'caption' => 'The 468 bags must be split into 18 equal box sections.'], 'math_work_builder' => [
                    'submit_label' => 'Check and submit the revised argument', 'teacher_review_required' => true, 'sections' => [
                        ['title' => 'First draft and calculation', 'fields' => [
                            ['id' => 'plan', 'label' => 'Which plan provides visible evidence?', 'control' => 'select', 'choices' => [['id' => 'partial_products', 'label' => 'Use partial products to build 18 × ? = 468'], ['id' => 'guess', 'label' => 'Write a quotient without testing it'], ['id' => 'add', 'label' => 'Add 468 + 18']]],
                            ['id' => 'twenty_boxes', 'label' => '18 × 20 =', 'control' => 'number'],
                            ['id' => 'six_boxes', 'label' => '18 × 6 =', 'control' => 'number'],
                            ['id' => 'answer', 'label' => 'Snack bags in each box', 'control' => 'number'],
                            ['id' => 'units', 'label' => 'Answer unit', 'control' => 'select', 'choices' => [['id' => 'bags_per_box', 'label' => 'snack bags per box'], ['id' => 'boxes', 'label' => 'boxes'], ['id' => 'bags_total', 'label' => 'total snack bags']]],
                            ['id' => 'draft', 'label' => 'Write your first complete argument with the conclusion and evidence.', 'control' => 'textarea', 'minimum_length' => 40],
                        ]],
                        ['title' => 'Check and revise', 'fields' => [
                            ['id' => 'check', 'label' => '18 × 26 =', 'control' => 'number'],
                            ['id' => 'strength', 'label' => 'Name one part of your first draft that makes the reasoning easy to follow.', 'control' => 'textarea', 'minimum_length' => 20],
                            ['id' => 'revision_note', 'label' => 'What will you revise for accuracy or clarity?', 'control' => 'textarea', 'minimum_length' => 20],
                            ['id' => 'final', 'label' => 'Write the revised final argument so it can be understood without oral explanation.', 'control' => 'textarea', 'minimum_length' => 50],
                        ]],
                    ],
                    'expected_values' => ['plan' => 'partial_products', 'twenty_boxes' => '360', 'six_boxes' => '108', 'answer' => '26', 'units' => 'bags_per_box', 'check' => '468'],
                    'field_feedback' => [
                        'plan' => 'Choose a plan that produces visible evidence another reader can test.',
                        'twenty_boxes' => 'Recheck 18 × 20: 18 × 2 = 36, then multiply by 10.',
                        'six_boxes' => 'Recheck 18 × 6.',
                        'answer' => 'Combine 20 bags per box and 6 bags per box.',
                        'units' => 'The question asks how many snack bags go in each box.',
                        'check' => 'Multiply 18 boxes by 26 bags per box to reconstruct all 468 bags.',
                    ],
                ]],
                'feedback' => ['correct' => 'Your first draft, check, revision decision, and final argument are saved for review.'],
                'completion_condition' => ['type' => 'correct_structured_math_work'], 'reward_label' => 'Complete argument revised', 'requires_teacher_review' => true, 'theme_key' => 'create',
            ],
            [
                'sequence' => 8, 'source_lesson_section_id' => $source('reflection'), 'activity_type' => 'short_response',
                'display_title' => 'Reflect on Mathematical Communication',
                'student_instructions' => 'Reflect on the argument you just completed and the three habits from this launch sequence.',
                'interaction_data' => ['fields' => [
                    ['id' => 'clearest_part', 'label' => 'Which part of your final response makes your reasoning easiest to follow?', 'minimum_length' => 15],
                    ['id' => 'future_check', 'label' => 'What will you check when you solve an unfamiliar problem in the future?', 'minimum_length' => 15],
                ]],
                'completion_condition' => ['type' => 'required_fields'], 'reward_label' => 'Communication reflected on', 'requires_teacher_review' => true, 'theme_key' => 'check',
            ],
        ];
        $experience = $this->createFromBlueprint($lesson, [
            'status' => 'preview', 'theme_key' => 'math-reasoning', 'mission_title' => 'Reasoning Revision Studio',
            'mission_brief' => 'Learn an evaluation routine, study a complete error analysis, evaluate competing solutions, and revise your own evidence-based argument.',
            'completion_title' => 'Reasoning Revision Studio Complete', 'completion_message' => 'You noticed, tested, explained, and revised mathematical reasoning using precise quantities, equations, evidence, and units.',
            'source_version' => 'math-lesson-3-reasoning-v1',
        ], $activities, synchronizePreview: true);
        $this->provisionMathLessonResources($lesson, [
            'Mathematical Error Analysis Page' => 'error_analysis',
            'Mathematical Communication Checklist' => 'communication_checklist',
            'Launch Written Response Page' => 'written_response',
        ], 'No ordinary parent preparation is required. The app teaches the evaluation routine, displays every claim and problem, and saves Kai’s draft, check, revision, and final argument. Generated pages remain optional teacher fallbacks. Review the official district standards language before treating the sparse code-only alignments as complete statements.');
        return $experience->fresh('activities');
    }

    private function assertMathLesson(Lesson $lesson, int $sequence, string $title): void
    {
        $subject = $lesson->lessonPlan()->with('packageCourse.course.subject')->firstOrFail()->packageCourse->course->subject;
        if ($subject->code !== 'MATH' || $lesson->sequence !== $sequence || $lesson->title !== $title) {
            throw ValidationException::withMessages(['lesson' => "The Math Lesson {$sequence} experience is reserved for the selected existing lesson."]);
        }
    }

    private function provisionMathLessonResources(Lesson $lesson, array $definitions, string $teacherPreparation): void
    {
        if ($lesson->estimated_preparation_minutes !== 0) {
            $before = $lesson->toArray();
            $lesson->update(['estimated_preparation_minutes' => 0]);
            $this->audit->record('lesson.math-digital-preparation-updated', $lesson, $before, $lesson->fresh()->toArray());
        }
        $preparation = $lesson->allSections()->where('section_type', 'teacher_preparation')->first();
        if ($preparation && $preparation->content !== $teacherPreparation) {
            $before = $preparation->toArray();
            $preparation->update(['content' => $teacherPreparation]);
            $this->audit->record('lesson-section.math-digital-preparation-updated', $preparation, $before, $preparation->fresh()->toArray());
        }
        foreach ($lesson->resources()->get() as $resource) {
            $before = $resource->toArray();
            $asset = $definitions[$resource->title] ?? null;
            if ($resource->category === 'lesson_resource' && $asset) {
                $resource->update([
                    'description' => 'Optional teacher fallback generated from the approved lesson. Kai’s normal work is interactive and saved inside the lesson.',
                    'delivery_type' => 'embedded', 'availability_status' => 'needs_asset',
                    'metadata' => [...($resource->metadata ?? []), 'math_foundation_asset' => $asset, 'student_experience_required' => false, 'optional_teacher_fallback' => true],
                ]);
            } elseif ($resource->category === 'student_supply') {
                $resource->update(['metadata' => [...($resource->metadata ?? []), 'student_experience_required' => false]]);
            }
            if ($before !== $resource->fresh()->toArray()) $this->audit->record('lesson-resource.math-experience-defined', $resource, $before, $resource->fresh()->toArray());
        }
        if (config('lesson-resources.automatic_fulfillment')) $this->resourceFulfillment->fulfillRequiredForLesson($lesson);
    }

    private function assertScienceLesson(Lesson $lesson, int $sequence, string $title): void
    {
        $subject = $lesson->lessonPlan()->with('packageCourse.course.subject')->firstOrFail()->packageCourse->course->subject;
        if ($subject->name !== 'Science' || $lesson->sequence !== $sequence || $lesson->title !== $title) {
            throw ValidationException::withMessages(['lesson' => "The Science Lesson {$sequence} experience is reserved for the selected existing lesson."]);
        }
    }

    private function provisionScienceMissionResources(Lesson $lesson, array $definitions, array $requiredMaterials): void
    {
        foreach ($lesson->resources()->get() as $resource) {
            $before = $resource->toArray();
            if ($resource->category === 'lesson_resource' && isset($definitions[$resource->title])) {
                $definition = $definitions[$resource->title];
                $resource->update([
                    'delivery_type' => $definition['delivery_type'], 'availability_status' => 'needs_asset',
                    'metadata' => [...($resource->metadata ?? []), 'science_foundation_asset' => $definition['asset'], 'student_experience_required' => true],
                ]);
            } elseif (in_array($resource->category, ['student_supply', 'special_material'], true)) {
                $resource->update(['metadata' => [...($resource->metadata ?? []), 'student_experience_required' => in_array($resource->title, $requiredMaterials, true)]]);
            }
            if ($before !== $resource->fresh()->toArray()) $this->audit->record('lesson-resource.science-experience-defined', $resource, $before, $resource->fresh()->toArray());
        }
        if (config('lesson-resources.automatic_fulfillment')) $this->resourceFulfillment->fulfillRequiredForLesson($lesson);
    }

    public function provisionTechnologyMissionBriefingPrototype(Lesson $lesson): LessonExperience
    {
        if ($lesson->title !== 'Mission Briefing: Instructions in Order' || $lesson->sequence !== 1) {
            throw ValidationException::withMessages(['lesson' => 'The Technology mission briefing is reserved for the selected Lesson 1.']);
        }
        $sections = $lesson->allSections()->get()->keyBy('section_type');
        foreach (['hook', 'direct_instruction', 'demonstration', 'guided_practice', 'build'] as $required) {
            if (! $sections->has($required)) throw ValidationException::withMessages(['lesson' => "The selected lesson is missing its {$required} source section."]);
        }
        $source = fn (string $type): int => $sections->get($type)->id;
        $starter = "print(\"MISSION: ORBITAL EXPLORER\")\nprint(\"Launch sequence started\")\nprint(\"Destination: Moon\")\nprint(\"Objective: Study the lunar surface\")";
        $guided = "print(\"MISSION: ORBITAL EXPLORER\")\nprint(\"Destination: Moon\")\nprint(\"Launch sequence started\")\nprint(\"Objective: Study the lunar surface\")";
        $activities = [
            [
                'sequence' => 1, 'source_lesson_section_id' => $source('hook'), 'activity_type' => 'instruction',
                'display_title' => 'Your First Python Mission',
                'student_instructions' => 'Read the short mission briefing, then continue to make your first message appear.',
                'content' => 'Today you will tell Python what message to show, change that message, and build a four-line space briefing. No Python experience is expected. If you have used block coding, the idea is similar—but here you type instructions instead of snapping blocks together.',
                'interaction_data' => ['facts' => [['label' => 'First win', 'detail' => 'Make one message appear, then change it.'], ['label' => 'Mission goal', 'detail' => 'Build a title, launch message, destination, and objective.'], ['label' => 'Safety', 'detail' => 'This lesson uses a limited browser preview and never runs your code on the Learning-App server.']]],
                'completion_condition' => ['type' => 'acknowledge'], 'reward_label' => 'Mission accepted', 'theme_key' => 'mission',
            ],
            [
                'sequence' => 2, 'source_lesson_section_id' => $source('direct_instruction'), 'activity_type' => 'project',
                'display_title' => 'Make One Message Appear',
                'student_instructions' => 'Look at the one line below. Predict which words will appear, preview it, then replace the words inside the quotation marks with your own mission message.',
                'content' => 'This line tells Python to show a message on the screen. For now, focus on the words between the quotation marks—you do not need to memorize any code vocabulary.',
                'interaction_data' => ['technology_code_builder' => [
                    'starter_code' => 'print("Mission Control Online")', 'minimum_statements' => 1,
                    'require_changed_from_starter' => true, 'teacher_review_required' => false,
                    'prediction_label' => 'Before previewing, which words do you think will appear?',
                    'reflection_label' => 'What changed in the preview after you changed the words?',
                    'submit_label' => 'Save my first message and continue',
                ]],
                'feedback' => ['correct' => 'First Python win: you changed the text and changed the displayed message.'],
                'completion_condition' => ['type' => 'structured_technology_code'], 'reward_label' => 'First message changed', 'theme_key' => 'create',
            ],
            [
                'sequence' => 3, 'source_lesson_section_id' => $source('demonstration'), 'activity_type' => 'multiple_choice',
                'display_title' => 'Which Message Comes First?',
                'student_instructions' => 'Look at the two lines and make a prediction. After you choose, the illustrated output will be revealed. Predictions are not graded.',
                'content' => 'Python normally starts with the top line and then moves down.',
                'interaction_data' => [
                    'ungraded' => true,
                    'choices' => [['id' => 'online', 'label' => 'Mission Control Online'], ['id' => 'launch', 'label' => 'Launch sequence started']],
                    'code_display' => ['language' => 'python', 'source' => "print(\"Mission Control Online\")\nprint(\"Launch sequence started\")", 'output' => ['Mission Control Online', 'Launch sequence started'], 'hide_output_until_response' => true, 'execution_notice' => 'Illustrated output; no Python code was executed.'],
                ],
                'feedback' => ['correct' => 'Now compare your prediction with the revealed output: Python followed the lines from top to bottom.'],
                'completion_condition' => ['type' => 'acknowledge_prediction'], 'reward_label' => 'Order observed', 'theme_key' => 'challenge',
            ],
            [
                'sequence' => 4, 'source_lesson_section_id' => $source('direct_instruction'), 'activity_type' => 'instruction',
                'display_title' => 'Now Name What You Just Did',
                'student_instructions' => 'Connect these four useful names to the code behavior you already tried.',
                'content' => 'You made Python display words, changed those words, and watched two instructions work from top to bottom. Programmers use short names for those ideas.',
                'interaction_data' => ['facts' => [
                    ['label' => 'print()', 'detail' => 'Tells Python to display something. The parentheses show Python what goes with print.'],
                    ['label' => 'Text, or a string', 'detail' => 'Words go inside quotation marks. Programmers call this kind of text a string.'],
                    ['label' => 'Sequence', 'detail' => 'Python normally follows instructions from the top line down.'],
                    ['label' => 'Statement', 'detail' => 'Each individual instruction in a Python program is called a statement.'],
                ]],
                'completion_condition' => ['type' => 'acknowledge'], 'reward_label' => 'Ideas named', 'theme_key' => 'learn',
            ],
            [
                'sequence' => 5, 'source_lesson_section_id' => $source('guided_practice'), 'activity_type' => 'project',
                'display_title' => 'Guided Reorder Lab',
                'student_instructions' => 'The example starts in a mixed-up order. Move the launch line above the destination line, predict the second message, preview it, then explain what changed.',
                'content' => 'You already changed one message and observed two lines. Now use the same top-to-bottom idea with four provided lines; you do not need to type the starter code.',
                'interaction_data' => ['technology_code_builder' => ['starter_code' => $guided, 'minimum_statements' => 4, 'required_outputs' => ['MISSION: ORBITAL EXPLORER', 'Launch sequence started', 'Destination: Moon', 'Objective: Study the lunar surface'], 'expected_order' => ['MISSION: ORBITAL EXPLORER', 'Launch sequence started', 'Destination: Moon', 'Objective: Study the lunar surface'], 'prediction_label' => 'Before previewing, what do you expect the second output line to be?', 'reflection_label' => 'How did moving the statement change the output?', 'submit_label' => 'Save guided code and continue']],
                'feedback' => ['correct' => 'Your four statements are in mission order and your explanation is saved.'],
                'completion_condition' => ['type' => 'structured_technology_code'], 'reward_label' => 'Briefing reordered', 'requires_teacher_review' => true, 'theme_key' => 'create',
            ],
            [
                'sequence' => 6, 'source_lesson_section_id' => $source('build'), 'activity_type' => 'project',
                'display_title' => 'Build Your Own Mission Briefing',
                'student_instructions' => 'Customize all four messages, predict the first output line, preview the statements, and explain why their order makes sense.',
                'content' => 'This is your independent challenge after two modeled examples and the guided reorder. Keep at least four simple print statements: mission title, launch message, destination, and objective. Starter code is provided.',
                'interaction_data' => ['technology_code_builder' => ['starter_code' => $starter, 'minimum_statements' => 4, 'prediction_label' => 'Before previewing, what will appear first?', 'reflection_label' => 'Why is this a clear order for your mission briefing?', 'submit_label' => 'Save mission briefing and continue']],
                'feedback' => ['correct' => 'Mission briefing saved for teacher review.'],
                'completion_condition' => ['type' => 'structured_technology_code'], 'reward_label' => 'Mission briefing built', 'requires_teacher_review' => true, 'theme_key' => 'create',
            ],
            [
                'sequence' => 7, 'source_lesson_section_id' => $source('build'), 'activity_type' => 'question_set',
                'display_title' => 'Mission-Control Check',
                'student_instructions' => 'Answer both checks using what you saw, changed, previewed, and then named.',
                'interaction_data' => ['questions' => [
                    ['id' => 'string', 'prompt' => 'What makes “Destination: Mars” a string in a print statement?', 'choices' => [['id' => 'quotes', 'label' => 'It is text inside matching quotation marks.'], ['id' => 'capital', 'label' => 'It begins with a capital letter.'], ['id' => 'space', 'label' => 'It describes space.']]],
                    ['id' => 'order', 'prompt' => 'How do these simple statements normally run?', 'choices' => [['id' => 'top_down', 'label' => 'From top to bottom.'], ['id' => 'random', 'label' => 'In a random order.'], ['id' => 'bottom_up', 'label' => 'From bottom to top.']]],
                ]],
                'answer_data' => ['answers' => ['string' => 'quotes', 'order' => 'top_down']],
                'feedback' => ['correct' => 'Preflight check passed: you can identify text in quotation marks and explain top-to-bottom order.', 'incorrect' => 'Review the “Now Name What You Just Did” step and trace the two-line example from the top.'],
                'completion_condition' => ['type' => 'correct'], 'reward_label' => 'Preflight passed', 'theme_key' => 'check',
            ],
        ];
        $experience = $this->createFromBlueprint($lesson, [
            'status' => 'preview', 'theme_key' => 'technology-mission-control',
            'mission_title' => 'Mission Control: First Python Briefing',
            'mission_brief' => 'Learn how Python-style print statements display text in order, predict a change, then build the first saved piece of your Astronaut & Spacecraft Mission Builder.',
            'completion_title' => 'Briefing Uploaded',
            'completion_message' => 'You traced statement order and saved a four-part mission briefing for your teacher to inspect. No Python ran on the Learning-App server.',
            'source_version' => 'technology-unit-1-lesson-1-v2',
        ], $activities, true);
        $this->provisionTechnologyMissionResources($lesson);
        return $experience;
    }

    public function provisionSpanishGreetingsPrototype(Lesson $lesson): LessonExperience
    {
        $lesson->loadMissing('curriculumUnit', 'lessonPlan.curriculumImport.subject');
        $subject = mb_strtolower($lesson->lessonPlan->curriculumImport->subject->name.' '.$lesson->lessonPlan->curriculumImport->subject->code);
        if ($lesson->sequence !== 1 || $lesson->title !== 'Hola y adiós: Greetings and Farewells'
            || $lesson->curriculumUnit->sequence !== 1 || $lesson->curriculumUnit->name !== 'Unit 1 - Hola, Soy Yo'
            || (! str_contains($subject, 'language') && ! str_contains($subject, 'spanish'))) {
            throw ValidationException::withMessages(['lesson' => 'This Spanish preview is reserved for the approved Unit 1 greeting lesson.']);
        }
        $sections = $lesson->allSections()->get()->keyBy('section_type');
        foreach (['hook', 'vocabulary', 'guided_practice', 'build', 'check_for_understanding'] as $required) {
            if (! $sections->has($required)) throw ValidationException::withMessages(['lesson' => "The selected Spanish lesson is missing its {$required} source section."]);
        }
        $source = fn (string $type): int => $sections->get($type)->id;
        $phrases = [
            ['id'=>'hola','spanish'=>'Hola','meaning'=>'Hello','use'=>'A general greeting','visual'=>'👋','pronunciation_aid'=>'OH-lah'],
            ['id'=>'buenos_dias','spanish'=>'Buenos días','meaning'=>'Good morning','use'=>'Use it in the morning','visual'=>'🌅','pronunciation_aid'=>'BWEH-nohs DEE-ahs'],
            ['id'=>'buenas_tardes','spanish'=>'Buenas tardes','meaning'=>'Good afternoon','use'=>'Use it in the afternoon','visual'=>'☀️','pronunciation_aid'=>'BWEH-nahs TAR-dehs'],
            ['id'=>'adios','spanish'=>'Adiós','meaning'=>'Goodbye','use'=>'A farewell','visual'=>'🚀','pronunciation_aid'=>'ah-DYOHS'],
            ['id'=>'hasta_luego','spanish'=>'Hasta luego','meaning'=>'See you later','use'=>'A farewell when you expect to meet again','visual'=>'🔁','pronunciation_aid'=>'AHS-tah LWEH-goh'],
        ];
        $player = fn (array $selected, bool $hideText = false): array => ['language'=>'es-MX','rate'=>0.78,'hide_text'=>$hideText,'phrases'=>collect($phrases)->whereIn('id',$selected)->values()->all()];
        $activities = [
            [
                'sequence'=>1,'source_lesson_section_id'=>$source('hook'),'activity_type'=>'instruction','display_title'=>'Your First Spanish Greeting',
                'student_instructions'=>'Listen to the greeting, look at its meaning, then say it when you feel ready. Replay is always okay.',
                'content'=>'Hola, Kai. This one useful word lets you greet someone in Spanish right away. Hola means hello and works in many situations.',
                'interaction_data'=>['language_phrases'=>$player(['hola']),'facts'=>[['label'=>'Tiny conversation','detail'=>'Someone says “Hola, Kai.” You can answer “Hola.”']]],
                'feedback'=>['correct'=>'¡Muy bien! You have already used your first Spanish greeting.'],'completion_condition'=>['type'=>'acknowledge'],'reward_label'=>'First hola','theme_key'=>'mission',
            ],
            [
                'sequence'=>2,'source_lesson_section_id'=>$source('vocabulary'),'activity_type'=>'instruction','display_title'=>'Meet Five Mission Phrases',
                'student_instructions'=>'Hear each phrase before trying it. Use the picture, meaning, and situation clue; repeat only when you are ready.',
                'content'=>'Three phrases greet someone. Two phrases help you leave. You do not need to memorize them all at once—listen, notice, and replay.',
                'interaction_data'=>['language_phrases'=>$player(array_column($phrases,'id'))],
                'feedback'=>['correct'=>'You heard the complete phrase team. Next you will recognize one by sound.'],'completion_condition'=>['type'=>'acknowledge'],'reward_label'=>'Phrase team met','theme_key'=>'learn',
            ],
            [
                'sequence'=>3,'source_lesson_section_id'=>$source('vocabulary'),'activity_type'=>'multiple_choice','display_title'=>'Hear the Morning Greeting',
                'student_instructions'=>'Play the hidden Spanish phrase as many times as you need. Which situation matches what you hear?',
                'content'=>'The words are hidden for this listening check, but this is a retry-friendly practice—not a one-chance test.',
                'interaction_data'=>['language_phrases'=>$player(['buenos_dias'],true),'choices'=>[['id'=>'morning','label'=>'Meeting someone in the morning'],['id'=>'afternoon','label'=>'Meeting someone in the afternoon'],['id'=>'leaving','label'=>'Leaving for the day']],'choice_feedback'=>['afternoon'=>'Listen again for días. The modeled phrase is the greeting used in the morning.','leaving'=>'This phrase welcomes someone rather than ending the conversation. Replay it and compare with the morning card.']],
                'answer_data'=>['correct'=>'morning'],'feedback'=>['correct'=>'Yes—Buenos días is the morning greeting.','incorrect'=>'Listen again and connect the phrase to its time-of-day picture.'],'completion_condition'=>['type'=>'correct'],'reward_label'=>'Morning signal heard','hints'=>['Replay the audio, then compare its sound with the five phrase cards you just explored.'],'theme_key'=>'challenge',
            ],
            [
                'sequence'=>4,'source_lesson_section_id'=>$source('guided_practice'),'activity_type'=>'matching','display_title'=>'Match Phrases to Moments',
                'student_instructions'=>'Match each moment to the Spanish phrase that communicates the right message.',
                'content'=>'Use meaning and context. A greeting starts an exchange; a farewell ends it.',
                'interaction_data'=>['prompts'=>[['id'=>'general','label'=>'You greet someone at any time.'],['id'=>'morning','label'=>'You meet someone before lunch.'],['id'=>'afternoon','label'=>'You meet someone after lunch.'],['id'=>'goodbye','label'=>'You are leaving.'],['id'=>'later','label'=>'You are leaving but expect to meet again.']],'options'=>collect($phrases)->map(fn($phrase)=>['id'=>$phrase['id'],'label'=>$phrase['spanish']])->all(),'answer_feedback'=>['morning'=>['default'=>'Look for the phrase whose meaning is good morning.'],'afternoon'=>['default'=>'Look for the phrase whose meaning is good afternoon.'],'later'=>['default'=>'Which farewell specifically means see you later?']]],
                'answer_data'=>['matches'=>['general'=>'hola','morning'=>'buenos_dias','afternoon'=>'buenas_tardes','goodbye'=>'adios','later'=>'hasta_luego']],
                'feedback'=>['correct'=>'Every phrase is connected to a useful moment.','incorrect'=>'Use the targeted clue, then adjust that one match and try again.'],'completion_condition'=>['type'=>'correct'],'reward_label'=>'Moments matched','theme_key'=>'learn',
            ],
            [
                'sequence'=>5,'source_lesson_section_id'=>$source('guided_practice'),'activity_type'=>'instruction','display_title'=>'Listen, Repeat, and Self-Check',
                'student_instructions'=>'Choose three phrases. For each one: listen, replay, repeat softly, then try once without speaking along with the model.',
                'content'=>'Your goal is understandable communication, not a perfect accent. Notice one sound you want to make clearer, replay the model, and try again. Nothing records or grades your voice.',
                'interaction_data'=>['language_phrases'=>$player(array_column($phrases,'id')),'facts'=>[['label'=>'Practice routine','detail'=>'Listen → repeat → try alone → replay one useful part.'],['label'=>'Self-check','detail'=>'Ask: Could a listener understand which greeting or farewell I meant?']]],
                'feedback'=>['correct'=>'Speaking rehearsal complete. Your voice was practiced, not recorded or automatically scored.'],'completion_condition'=>['type'=>'acknowledge'],'reward_label'=>'Voice practiced','theme_key'=>'learn',
            ],
            [
                'sequence'=>6,'source_lesson_section_id'=>$source('guided_practice'),'activity_type'=>'question_set','display_title'=>'Complete Two Tiny Exchanges',
                'student_instructions'=>'Choose the phrase that makes each short exchange communicate clearly.',
                'content'=>'Read only a few words at a time. The Spanish phrases are the same ones you have already heard and practiced.',
                'interaction_data'=>['questions'=>[['id'=>'arrival','prompt'=>'A classmate says “Hola, Kai.” What can you say back?','choices'=>[['id'=>'hola','label'=>'Hola.'],['id'=>'adios','label'=>'Adiós.'],['id'=>'later','label'=>'Hasta luego.']]],['id'=>'return','prompt'=>'You are leaving now but will meet again later. What fits?','choices'=>[['id'=>'later','label'=>'Hasta luego.'],['id'=>'morning','label'=>'Buenos días.'],['id'=>'afternoon','label'=>'Buenas tardes.']]]],'answer_feedback'=>['arrival'=>['adios'=>'Adiós ends an exchange. Which phrase answers a greeting with a greeting?','later'=>'Hasta luego means see you later. Which phrase simply says hello?'],'return'=>['morning'=>'Buenos días begins a morning exchange. Which farewell promises see you later?','afternoon'=>'Buenas tardes begins an afternoon exchange. Which farewell promises see you later?']]],
                'answer_data'=>['answers'=>['arrival'=>'hola','return'=>'later']],'feedback'=>['correct'=>'Both mini exchanges communicate clearly.','incorrect'=>'Use the targeted language clue and retry.'],'completion_condition'=>['type'=>'correct'],'reward_label'=>'Exchanges completed','theme_key'=>'challenge',
            ],
            [
                'sequence'=>7,'source_lesson_section_id'=>$source('build'),'activity_type'=>'project','display_title'=>'Build Your Digital Passport Greeting Card',
                'student_instructions'=>'Choose at least two greetings and both farewells, type one short modeled line, explain one situation choice, and complete the speaking self-check.',
                'content'=>'This is Build 1 of Mi Pasaporte Español. Everything is digital and autosaved; no printing, folding, or art supplies are needed.',
                'interaction_data'=>['language_phrases'=>$player(array_column($phrases,'id')),'language_passport_builder'=>['greetings'=>collect($phrases)->take(3)->values()->all(),'farewells'=>collect($phrases)->slice(3)->values()->all(),'minimum_greetings'=>2,'minimum_farewells'=>2,'writing_model'=>'Hola, Kai. Hasta luego.','submit_label'=>'Save passport card and continue']],
                'feedback'=>['correct'=>'Your greeting card is saved in Mi Pasaporte Español for teacher review.'],'completion_condition'=>['type'=>'structured_language_passport'],'reward_label'=>'Passport greeting card built','requires_teacher_review'=>true,'theme_key'=>'create',
            ],
            [
                'sequence'=>8,'source_lesson_section_id'=>$source('check_for_understanding'),'activity_type'=>'question_set','display_title'=>'Greeting Mission Check',
                'student_instructions'=>'Choose a fitting phrase for three new situations. If one misses, use its language clue and retry.',
                'content'=>'You have heard, read, spoken, matched, and written these phrases. Now choose them in fresh moments.',
                'interaction_data'=>['questions'=>[['id'=>'sunrise','prompt'=>'You arrive early in the morning.','choices'=>[['id'=>'dias','label'=>'Buenos días.'],['id'=>'tardes','label'=>'Buenas tardes.'],['id'=>'adios','label'=>'Adiós.']]],['id'=>'three_pm','prompt'=>'You greet someone at 3:00 p.m.','choices'=>[['id'=>'tardes','label'=>'Buenas tardes.'],['id'=>'dias','label'=>'Buenos días.'],['id'=>'later','label'=>'Hasta luego.']]],['id'=>'return','prompt'=>'You leave but plan to meet again later.','choices'=>[['id'=>'later','label'=>'Hasta luego.'],['id'=>'hola','label'=>'Hola.'],['id'=>'tardes','label'=>'Buenas tardes.']]]],'answer_feedback'=>['sunrise'=>['default'=>'Sunrise is in the morning. Which phrase means good morning?'],'three_pm'=>['default'=>'3:00 p.m. is in the afternoon. Which phrase means good afternoon?'],'return'=>['default'=>'Look for the farewell that specifically means see you later.']]],
                'answer_data'=>['answers'=>['sunrise'=>'dias','three_pm'=>'tardes','return'=>'later']],'feedback'=>['correct'=>'¡Excelente! You can choose and use greetings and farewells in context.','incorrect'=>'Use the targeted clue, replay a model if useful, and retry.'],'completion_condition'=>['type'=>'correct'],'reward_label'=>'Greeting mission complete','theme_key'=>'check',
            ],
        ];
        $experience = $this->createFromBlueprint($lesson, [
            'status'=>'preview','theme_key'=>'spanish-cosmic-passport','mission_title'=>'¡Hola! Your First Spanish Mission',
            'mission_brief'=>'Hear, recognize, say, read, and write five useful greetings and farewells—then add them to your digital Mi Pasaporte Español.',
            'completion_title'=>'First Passport Stamp Earned','completion_message'=>'You communicated with greetings and farewells and completed the first section of Mi Pasaporte Español.',
            'source_version'=>'spanish-unit-1-lesson-1-v1',
        ], $activities, true);
        $this->provisionSpanishGreetingResources($lesson);
        return $experience;
    }

    private function provisionSpanishGreetingResources(Lesson $lesson): void
    {
        foreach ($lesson->resources()->get() as $resource) {
            $before = $resource->toArray();
            $resource->update(['availability_status'=>'not_applicable','metadata'=>[...($resource->metadata??[]),'student_experience_required'=>false,'optional_teacher_fallback'=>true,'superseded_by_digital_experience'=>true]]);
            $this->audit->record('lesson-resource.spanish-print-dependency-retired',$resource,$before,$resource->fresh()->toArray());
        }
        $resource = $lesson->resources()->updateOrCreate(['category'=>'lesson_resource','sort_order'=>20],[
            'resource_type'=>'interactive_reference','title'=>'Saludos y despedidas Interactive Phrase Guide',
            'description'=>'Embedded visual phrase cards, pronunciation support, replay configuration, and the digital passport-card model.',
            'delivery_type'=>'embedded','availability_status'=>'needs_asset',
            'metadata'=>['spanish_foundation_asset'=>'greetings_reference','student_experience_required'=>true,'content_origin'=>'application_created'],
        ]);
        $this->audit->record('lesson-resource.spanish-experience-defined',$resource,[],$resource->toArray());
        $lesson->update(['estimated_preparation_minutes'=>0]);
        if (config('lesson-resources.automatic_fulfillment')) $this->resourceFulfillment->fulfillRequiredForLesson($lesson);
    }

    public function provisionSpanishVowelsPrototype(Lesson $lesson): LessonExperience
    {
        $sections = $this->assertSpanishLesson($lesson, 2, 'The Five Spanish Vowels', ['introduction','demonstration','guided_practice','activity']);
        $source = fn (string $type): int => $sections->get($type)->id;
        $vowels = [
            ['id'=>'a','spanish'=>'a','meaning'=>'a steady sound like the a in father','use'=>'hola, gracias','visual'=>'A','pronunciation_aid'=>'ah'],
            ['id'=>'e','spanish'=>'e','meaning'=>'a short, steady eh sound','use'=>'tardes, tengo','visual'=>'E','pronunciation_aid'=>'eh'],
            ['id'=>'i','spanish'=>'i','meaning'=>'a steady ee sound','use'=>'días, bien','visual'=>'I','pronunciation_aid'=>'ee'],
            ['id'=>'o','spanish'=>'o','meaning'=>'a short, clear oh sound','use'=>'hola, soy','visual'=>'O','pronunciation_aid'=>'oh'],
            ['id'=>'u','spanish'=>'u','meaning'=>'a steady oo sound','use'=>'luego, gusto','visual'=>'U','pronunciation_aid'=>'oo'],
        ];
        $words = [
            ['id'=>'hola','spanish'=>'hola','meaning'=>'hello','use'=>'notice o, then a','visual'=>'👋','pronunciation_aid'=>'OH-lah'],
            ['id'=>'dias','spanish'=>'días','meaning'=>'days; part of buenos días','use'=>'notice í, then a','visual'=>'🌅','pronunciation_aid'=>'DEE-ahs'],
            ['id'=>'tardes','spanish'=>'tardes','meaning'=>'afternoon; part of buenas tardes','use'=>'notice a, then e','visual'=>'☀️','pronunciation_aid'=>'TAR-dehs'],
            ['id'=>'adios','spanish'=>'adiós','meaning'=>'goodbye','use'=>'notice a, i, then ó','visual'=>'🚀','pronunciation_aid'=>'ah-DYOHS'],
            ['id'=>'luego','spanish'=>'luego','meaning'=>'later; part of hasta luego','use'=>'notice u, e, then o','visual'=>'🔁','pronunciation_aid'=>'LWEH-goh'],
            ['id'=>'gracias','spanish'=>'gracias','meaning'=>'thank you','use'=>'notice a, i, then a','visual'=>'⭐','pronunciation_aid'=>'GRAH-syahs'],
        ];
        $player = fn (array $phrases, bool $hide = false): array => ['language'=>'es-MX','rate'=>0.72,'hide_text'=>$hide,'phrases'=>$phrases];
        $activities = [
            ['sequence'=>1,'source_lesson_section_id'=>$source('introduction'),'activity_type'=>'instruction','display_title'=>'Hear the Five Steady Signals','student_instructions'=>'Play each vowel, watch its letter, and echo only when you are ready. Replay is encouraged.','content'=>'Spanish has the same five vowel letters as English, but each one usually keeps a more dependable sound. English comparisons are only a helpful starting point.','interaction_data'=>['language_phrases'=>$player($vowels)],'feedback'=>['correct'=>'You heard all five vowel signals before being asked to identify them.'],'completion_condition'=>['type'=>'acknowledge'],'reward_label'=>'Vowels heard','theme_key'=>'mission'],
            ['sequence'=>2,'source_lesson_section_id'=>$source('demonstration'),'activity_type'=>'multiple_choice','display_title'=>'Which Vowel Did You Hear?','student_instructions'=>'Listen to the hidden vowel as often as you need. Choose the letter that matches the sound.','content'=>'This is retry-friendly listening practice, not a memory test.','interaction_data'=>['language_phrases'=>$player([$vowels[2]],true),'choices'=>[['id'=>'a','label'=>'a'],['id'=>'i','label'=>'i'],['id'=>'u','label'=>'u']],'choice_feedback'=>['a'=>'Replay the sound. The modeled a sounds like ah; this sound is closer to ee.','u'=>'Replay and compare: u sounds like oo, while the hidden vowel sounds like ee.']],'answer_data'=>['correct'=>'i'],'feedback'=>['correct'=>'Yes—the steady ee sound belongs to Spanish i.','incorrect'=>'Listen again and compare the three vowel cards.'],'completion_condition'=>['type'=>'correct'],'reward_label'=>'Vowel signal recognized','theme_key'=>'challenge'],
            ['sequence'=>3,'source_lesson_section_id'=>$source('guided_practice'),'activity_type'=>'instruction','display_title'=>'Find Vowels in Words You Know','student_instructions'=>'Hear each familiar Unit 1 word. Follow its vowels from left to right, then try the whole word.','content'=>'These are greetings and courtesy words from this unit. No new word list is being added. First notice the steady vowels; then hear the word at a natural pace.','interaction_data'=>['language_phrases'=>$player($words)],'feedback'=>['correct'=>'You connected the five sounds to familiar words.'],'completion_condition'=>['type'=>'acknowledge'],'reward_label'=>'Word vowels traced','theme_key'=>'learn'],
            ['sequence'=>4,'source_lesson_section_id'=>$source('activity'),'activity_type'=>'matching','display_title'=>'Sort by the Highlighted Vowel','student_instructions'=>'Match each familiar word to the highlighted target vowel named in its clue.','content'=>'A word can contain several vowels. Match only the vowel identified in the clue.','interaction_data'=>['prompts'=>[['id'=>'hola_o','label'=>'h[o]la'],['id'=>'tardes_e','label'=>'tard[e]s'],['id'=>'dias_i','label'=>'d[í]as'],['id'=>'luego_u','label'=>'l[u]ego'],['id'=>'gracias_a','label'=>'gr[a]cias']],'options'=>collect($vowels)->map(fn($item)=>['id'=>$item['id'],'label'=>$item['spanish'].' — '.$item['pronunciation_aid']])->all(),'answer_feedback'=>['hola_o'=>['default'=>'Look only at the letter inside brackets in h[o]la.'],'tardes_e'=>['default'=>'The highlighted letter in tard[e]s makes the steady eh sound.'],'dias_i'=>['default'=>'The accent does not change the vowel identity: í is still i.'],'luego_u'=>['default'=>'Look at the first vowel in l[u]ego.'],'gracias_a'=>['default'=>'Look at the first highlighted vowel in gr[a]cias.']]],'answer_data'=>['matches'=>['hola_o'=>'o','tardes_e'=>'e','dias_i'=>'i','luego_u'=>'u','gracias_a'=>'a']],'feedback'=>['correct'=>'All five highlighted vowels are sorted.','incorrect'=>'Use the targeted letter clue and retry that match.'],'completion_condition'=>['type'=>'correct'],'reward_label'=>'Vowels sorted','theme_key'=>'challenge'],
            ['sequence'=>5,'source_lesson_section_id'=>$source('guided_practice'),'activity_type'=>'instruction','display_title'=>'Listen, Read, and Try Six Words','student_instructions'=>'For each word: listen, read along, repeat, then replay one sound you want to steady.','content'=>'Practice hola, días, tardes, adiós, luego, and gracias. Aim to be understandable, not to copy a perfect accent. Your voice is not recorded or automatically graded.','interaction_data'=>['language_phrases'=>$player($words),'facts'=>[['label'=>'Practice path','detail'=>'Listen → read along → repeat → replay one useful part.'],['label'=>'Honest check','detail'=>'You decide whether each vowel stayed clear enough to recognize.']]],'feedback'=>['correct'=>'Six-word speaking practice complete.'],'completion_condition'=>['type'=>'acknowledge'],'reward_label'=>'Six words practiced','theme_key'=>'learn'],
            ['sequence'=>6,'source_lesson_section_id'=>$source('activity'),'activity_type'=>'project','display_title'=>'Save Your Vowel Practice Check','student_instructions'=>'Choose all six words you practiced, name one sound to revisit, and complete the speaking self-check. Your choices autosave.','content'=>'This small practice record helps a parent or teacher know what to celebrate and what to warm up next time.','interaction_data'=>['language_phrases'=>$player($words),'language_work_builder'=>['model'=>'hola · días · tardes · adiós · luego · gracias','model_support'=>'Listen before selecting your practice record.','teacher_review_required'=>true,'fields'=>[['id'=>'words','label'=>'Words I listened to and practiced','control'=>'multi_select','minimum_selected'=>6,'required_message'=>'Choose all six modeled words after practicing them.','choices'=>collect($words)->map(fn($item)=>['id'=>$item['id'],'label'=>$item['spanish'],'support'=>$item['meaning']])->all()],['id'=>'revisit','label'=>'One vowel I want to replay next time','control'=>'select','choices'=>collect($vowels)->map(fn($item)=>['id'=>$item['id'],'label'=>$item['spanish'].' — '.$item['pronunciation_aid']])->all()],['id'=>'speaking_self_check','label'=>'I listened first, practiced the six words aloud, and chose an attempt I could say understandably.','control'=>'checkbox','preview'=>false]],'preview_title'=>'My Vowel Practice']], 'feedback'=>['correct'=>'Your vowel practice check is saved for teacher review.'],'completion_condition'=>['type'=>'structured_language_work'],'reward_label'=>'Practice saved','requires_teacher_review'=>true,'theme_key'=>'create'],
            ['sequence'=>7,'source_lesson_section_id'=>$source('activity'),'activity_type'=>'question_set','display_title'=>'Five-Vowel Mission Check','student_instructions'=>'Use the sounds and familiar words you practiced. Incorrect choices stay here with a useful clue so you can retry.','interaction_data'=>['questions'=>[['id'=>'dias','prompt'=>'Which vowel begins the strong first sound in días?','choices'=>[['id'=>'i','label'=>'i'],['id'=>'e','label'=>'e'],['id'=>'u','label'=>'u']]],['id'=>'luego','prompt'=>'Which vowel starts luego?','choices'=>[['id'=>'u','label'=>'u'],['id'=>'o','label'=>'o'],['id'=>'a','label'=>'a']]],['id'=>'steady','prompt'=>'What is the main beginner idea about Spanish vowels?','choices'=>[['id'=>'consistent','label'=>'Each vowel usually keeps a steady sound.'],['id'=>'silent','label'=>'Most vowels are silent.'],['id'=>'random','label'=>'Their sounds change randomly.']]]],'answer_feedback'=>['dias'=>['default'=>'Replay días and look at its first marked vowel: í.'],'luego'=>['default'=>'Look at the first vowel in luego, then replay the word.'],'steady'=>['default'=>'Think back to the five steady signals from Step 1.']]],'answer_data'=>['answers'=>['dias'=>'i','luego'=>'u','steady'=>'consistent']],'feedback'=>['correct'=>'¡Excelente! You can recognize the five vowels in familiar words.','incorrect'=>'Use the targeted sound clue and try again.'],'completion_condition'=>['type'=>'correct'],'reward_label'=>'Vowel mission complete','theme_key'=>'check'],
        ];
        $experience = $this->createFromBlueprint($lesson,['status'=>'preview','theme_key'=>'spanish-cosmic-passport','mission_title'=>'The Five Vowel Signals','mission_brief'=>'Hear, recognize, read, and practice the five steady Spanish vowel sounds inside familiar Unit 1 words.','completion_title'=>'Vowel Signals Locked In','completion_message'=>'You recognized all five vowels and saved an honest six-word speaking practice check.','source_version'=>'spanish-unit-1-lesson-2-v1'],$activities,true);
        $this->provisionSpanishDigitalResources($lesson,'vowel_reference','Spanish Vowel Interactive Sound Guide','Embedded five-vowel audio, familiar-word examples, and replayable pronunciation support.');
        return $experience;
    }

    public function provisionSpanishNamesPrototype(Lesson $lesson): LessonExperience
    {
        $sections = $this->assertSpanishLesson($lesson, 3, '¿Cómo te llamas? Asking and Answering Names', ['vocabulary','listening','speaking','build','exit_check']);
        $source = fn (string $type): int => $sections->get($type)->id;
        $phrases = [
            ['id'=>'question','spanish'=>'¿Cómo te llamas?','meaning'=>'What is your name?','use'=>'Ask someone’s name','visual'=>'❓','pronunciation_aid'=>'KOH-moh teh YAH-mahs'],
            ['id'=>'me_llamo','spanish'=>'Me llamo Kai.','meaning'=>'My name is Kai.','use'=>'One complete answer','visual'=>'🪪','pronunciation_aid'=>'meh YAH-moh kai'],
            ['id'=>'soy','spanish'=>'Soy Kai.','meaning'=>'I am Kai.','use'=>'Another complete answer','visual'=>'⭐','pronunciation_aid'=>'soy kai'],
            ['id'=>'mucho_gusto','spanish'=>'Mucho gusto.','meaning'=>'Nice to meet you.','use'=>'After meeting someone','visual'=>'🤝','pronunciation_aid'=>'MOO-choh GOOS-toh'],
        ];
        $player = fn(array $ids,bool $hide=false):array=>['language'=>'es-MX','rate'=>0.75,'hide_text'=>$hide,'phrases'=>collect($phrases)->whereIn('id',$ids)->values()->all()];
        $activities = [
            ['sequence'=>1,'source_lesson_section_id'=>$source('vocabulary'),'activity_type'=>'instruction','display_title'=>'Hear a Name Exchange First','student_instructions'=>'Listen to the question and two possible answers before trying to build one. Replay each short line.','content'=>'Start with Hola from Lesson 1. Then one person asks ¿Cómo te llamas? You may answer Me llamo Kai. or Soy Kai. Both are complete beginner answers.','interaction_data'=>['language_phrases'=>$player(['question','me_llamo','soy','mucho_gusto']),'facts'=>[['label'=>'Model exchange','detail'=>'Hola. ¿Cómo te llamas? — Me llamo Kai. Mucho gusto.']]],'feedback'=>['correct'=>'You heard the whole exchange before being asked to use it.'],'completion_condition'=>['type'=>'acknowledge'],'reward_label'=>'Exchange heard','theme_key'=>'mission'],
            ['sequence'=>2,'source_lesson_section_id'=>$source('listening'),'activity_type'=>'multiple_choice','display_title'=>'Which Answer Did You Hear?','student_instructions'=>'Play the hidden answer. Did the speaker use Me llamo… or Soy…?','content'=>'Both patterns can introduce a name. Listen for the longer beginning Me llamo.','interaction_data'=>['language_phrases'=>$player(['me_llamo'],true),'choices'=>[['id'=>'me_llamo','label'=>'Me llamo Kai.'],['id'=>'soy','label'=>'Soy Kai.']],'choice_feedback'=>['soy'=>'Replay and listen for two words before Kai: Me llamo.']],'answer_data'=>['correct'=>'me_llamo'],'feedback'=>['correct'=>'Yes—the speaker used Me llamo Kai.','incorrect'=>'Listen again for the beginning of the answer.'],'completion_condition'=>['type'=>'correct'],'reward_label'=>'Answer pattern heard','theme_key'=>'challenge'],
            ['sequence'=>3,'source_lesson_section_id'=>$source('vocabulary'),'activity_type'=>'instruction','display_title'=>'Notice the Written Question','student_instructions'=>'Read the model and notice the question marks. Then listen once more.','content'=>'Spanish writes a question with an opening mark ¿ and a closing mark ?. You do not need a grammar label: the marks show where the question begins and ends.','interaction_data'=>['language_phrases'=>$player(['question']),'facts'=>[['label'=>'Question','detail'=>'¿Cómo te llamas?'],['label'=>'Answer choice 1','detail'=>'Me llamo Kai.'],['label'=>'Answer choice 2','detail'=>'Soy Kai.']]],'feedback'=>['correct'=>'You can see where the question begins and ends.'],'completion_condition'=>['type'=>'acknowledge'],'reward_label'=>'Question marks spotted','theme_key'=>'learn'],
            ['sequence'=>4,'source_lesson_section_id'=>$source('listening'),'activity_type'=>'matching','display_title'=>'Match Each Line to Its Job','student_instructions'=>'Match the four modeled lines to what each one communicates.','content'=>'Use the English meaning support; the goal is communication, not memorizing labels.','interaction_data'=>['prompts'=>[['id'=>'ask','label'=>'Ask someone’s name'],['id'=>'answer_name','label'=>'Say “My name is Kai”'],['id'=>'answer_identity','label'=>'Say “I am Kai”'],['id'=>'meet','label'=>'Say “Nice to meet you”']],'options'=>collect($phrases)->map(fn($p)=>['id'=>$p['id'],'label'=>$p['spanish']])->all(),'answer_feedback'=>['ask'=>['default'=>'Look for the line with opening and closing question marks.'],'answer_name'=>['default'=>'Me llamo… is the model meaning My name is…'],'answer_identity'=>['default'=>'Soy… is the shorter model meaning I am…'],'meet'=>['default'=>'Which phrase closes a first meeting politely?']]],'answer_data'=>['matches'=>['ask'=>'question','answer_name'=>'me_llamo','answer_identity'=>'soy','meet'=>'mucho_gusto']],'feedback'=>['correct'=>'Every line now has a clear conversation job.','incorrect'=>'Use the targeted meaning clue and retry.'],'completion_condition'=>['type'=>'correct'],'reward_label'=>'Conversation jobs matched','theme_key'=>'learn'],
            ['sequence'=>5,'source_lesson_section_id'=>$source('speaking'),'activity_type'=>'instruction','display_title'=>'Listen, Repeat, Then Switch Roles','student_instructions'=>'First listen to both roles. Repeat with the model, then try the answer role and the question role on your own.','content'=>'Practice slowly: Hola. ¿Cómo te llamas? — Me llamo Kai. Mucho gusto. Then switch Me llamo Kai. for Soy Kai. Understandable rhythm matters more than speed. Nothing records or grades your voice.','interaction_data'=>['language_phrases'=>$player(['question','me_llamo','soy','mucho_gusto']),'facts'=>[['label'=>'Role A','detail'=>'Hola. ¿Cómo te llamas?'],['label'=>'Role B','detail'=>'Me llamo Kai. Mucho gusto.']]],'feedback'=>['correct'=>'You practiced both roles with a model first.'],'completion_condition'=>['type'=>'acknowledge'],'reward_label'=>'Roles practiced','theme_key'=>'learn'],
            ['sequence'=>6,'source_lesson_section_id'=>$source('speaking'),'activity_type'=>'question_set','display_title'=>'Complete Two Guided Exchanges','student_instructions'=>'Choose the line that answers each speaker. These use only phrases already modeled.','interaction_data'=>['questions'=>[['id'=>'name_answer','prompt'=>'Hola. ¿Cómo te llamas?','choices'=>[['id'=>'me_llamo','label'=>'Me llamo Kai.'],['id'=>'adios','label'=>'Adiós.'],['id'=>'question','label'=>'¿Cómo te llamas?']]],['id'=>'meeting','prompt'=>'A new person says “Soy Luna.” What polite line fits next?','choices'=>[['id'=>'mucho','label'=>'Mucho gusto.'],['id'=>'question','label'=>'¿Cómo te llamas?'],['id'=>'morning','label'=>'Buenos días.']]]],'answer_feedback'=>['name_answer'=>['default'=>'The speaker asked for a name, so answer with a complete name sentence.'],'meeting'=>['default'=>'The name was just shared. Which phrase means nice to meet you?']]],'answer_data'=>['answers'=>['name_answer'=>'me_llamo','meeting'=>'mucho']],'feedback'=>['correct'=>'Both guided exchanges communicate clearly.','incorrect'=>'Use the targeted exchange clue and retry.'],'completion_condition'=>['type'=>'correct'],'reward_label'=>'Exchanges completed','theme_key'=>'challenge'],
            ['sequence'=>7,'source_lesson_section_id'=>$source('build'),'activity_type'=>'project','display_title'=>'Build 2: Add My Name','student_instructions'=>'Choose a modeled answer frame, type your name sentence, and complete the speaking self-check. Everything autosaves.','content'=>'This adds the existing Build 2 milestone to Mi Pasaporte Español. A nickname is optional; no decoration or paper is required.','interaction_data'=>['language_phrases'=>$player(['question','me_llamo','soy']),'language_work_builder'=>['model'=>'Me llamo Kai. / Soy Kai.','model_support'=>'Choose either complete pattern and keep the period.','teacher_review_required'=>true,'preview_title'=>'Build 2 · My Name','fields'=>[['id'=>'answer_frame','label'=>'Choose my answer pattern','control'=>'select','choices'=>[['id'=>'me_llamo','label'=>'Me llamo…','support'=>'My name is…'],['id'=>'soy','label'=>'Soy…','support'=>'I am…']]],['id'=>'name','label'=>'Name for my passport','control'=>'text','minimum_length'=>2,'maximum_length'=>80,'placeholder'=>'Kai'],['id'=>'nickname','label'=>'Optional nickname','control'=>'text','minimum_length'=>0,'maximum_length'=>80,'placeholder'=>'Leave blank if you do not want one','required'=>false],['id'=>'introduction','label'=>'Type one complete modeled introduction','control'=>'text','minimum_length'=>6,'maximum_length'=>160,'placeholder'=>'Me llamo Kai.'],['id'=>'speaking_self_check','label'=>'I listened to the model, practiced my complete introduction aloud, and chose an understandable attempt.','control'=>'checkbox','preview'=>false]]]], 'feedback'=>['correct'=>'Your name section is saved in Mi Pasaporte Español for teacher review.'],'completion_condition'=>['type'=>'structured_language_work'],'reward_label'=>'Name added','requires_teacher_review'=>true,'theme_key'=>'create'],
            ['sequence'=>8,'source_lesson_section_id'=>$source('exit_check'),'activity_type'=>'question_set','display_title'=>'Name-Exchange Mission Check','student_instructions'=>'Choose a fitting question, answer, and meeting phrase. Incorrect answers stay open for another try.','interaction_data'=>['questions'=>[['id'=>'ask','prompt'=>'Which line asks a person’s name?','choices'=>[['id'=>'question','label'=>'¿Cómo te llamas?'],['id'=>'soy','label'=>'Soy Kai.'],['id'=>'gusto','label'=>'Mucho gusto.']]],['id'=>'answer','prompt'=>'Which line is a complete answer to that question?','choices'=>[['id'=>'soy','label'=>'Soy Kai.'],['id'=>'question','label'=>'¿Cómo te llamas?'],['id'=>'later','label'=>'Hasta luego.']]],['id'=>'close','prompt'=>'Which line means nice to meet you?','choices'=>[['id'=>'gusto','label'=>'Mucho gusto.'],['id'=>'hola','label'=>'Hola.'],['id'=>'adios','label'=>'Adiós.']]]],'answer_feedback'=>['ask'=>['default'=>'The name question has both ¿ and ?.'],'answer'=>['default'=>'Choose the line that tells the speaker’s name.'],'close'=>['default'=>'Choose the phrase used after meeting someone.']]],'answer_data'=>['answers'=>['ask'=>'question','answer'=>'soy','close'=>'gusto']],'feedback'=>['correct'=>'¡Mucho gusto! You can ask and answer a name in a short exchange.','incorrect'=>'Use the targeted phrase clue and retry.'],'completion_condition'=>['type'=>'correct'],'reward_label'=>'Name exchange complete','theme_key'=>'check'],
        ];
        $experience=$this->createFromBlueprint($lesson,['status'=>'preview','theme_key'=>'spanish-cosmic-passport','mission_title'=>'Name-Exchange Mission','mission_brief'=>'Hear, recognize, ask, answer, and save a short Spanish name introduction using the approved Unit 1 patterns.','completion_title'=>'Name Stamp Added','completion_message'=>'You asked and answered a name, practiced both roles, and saved Build 2 in Mi Pasaporte Español.','source_version'=>'spanish-unit-1-lesson-3-v1'],$activities,true);
        $this->provisionSpanishDigitalResources($lesson,'name_reference','Name Exchange Interactive Phrase Guide','Embedded name-question, answer-pattern, meeting phrase, audio, and Build 2 support.');
        return $experience;
    }

    public function provisionSpanishCourtesyPrototype(Lesson $lesson): LessonExperience
    {
        $sections=$this->assertSpanishLesson($lesson,4,'Polite Expressions in Conversation',['context','example','guided_practice','speaking','exit_check']);$source=fn(string $type):int=>$sections->get($type)->id;
        $phrases=[['id'=>'por_favor','spanish'=>'Por favor.','meaning'=>'Please.','use'=>'Add courtesy to a simple modeled request','visual'=>'🙏','pronunciation_aid'=>'por fah-VOR'],['id'=>'gracias','spanish'=>'Gracias.','meaning'=>'Thank you.','use'=>'After receiving help or kindness','visual'=>'⭐','pronunciation_aid'=>'GRAH-syahs'],['id'=>'mucho_gusto','spanish'=>'Mucho gusto.','meaning'=>'Nice to meet you.','use'=>'After names are shared','visual'=>'🤝','pronunciation_aid'=>'MOO-choh GOOS-toh']];
        $player=fn(array $ids,bool $hide=false):array=>['language'=>'es-MX','rate'=>0.75,'hide_text'=>$hide,'phrases'=>collect($phrases)->whereIn('id',$ids)->values()->all()];
        $activities=[
            ['sequence'=>1,'source_lesson_section_id'=>$source('context'),'activity_type'=>'instruction','display_title'=>'Hear Courtesy in Three Moments','student_instructions'=>'Listen to each short expression and connect it to its situation. Replay before repeating.','content'=>'Por favor means please. Gracias means thank you. Mucho gusto means nice to meet you. Each one has a job; polite words are not added at random.','interaction_data'=>['language_phrases'=>$player(array_column($phrases,'id'))],'feedback'=>['correct'=>'You heard all three expressions in meaningful situations.'],'completion_condition'=>['type'=>'acknowledge'],'reward_label'=>'Courtesy signals heard','theme_key'=>'mission'],
            ['sequence'=>2,'source_lesson_section_id'=>$source('example'),'activity_type'=>'multiple_choice','display_title'=>'What Did the New Person Say?','student_instructions'=>'Listen to the hidden expression. Which situation fits it?','content'=>'Replay as often as needed. This is practice after the model, not a one-chance test.','interaction_data'=>['language_phrases'=>$player(['mucho_gusto'],true),'choices'=>[['id'=>'meeting','label'=>'Two people just shared their names.'],['id'=>'help','label'=>'Someone just helped.'],['id'=>'request','label'=>'Someone is making a polite request.']],'choice_feedback'=>['help'=>'Gracias fits after help. Replay for the phrase that means nice to meet you.','request'=>'Por favor fits a polite request. Replay for the first-meeting phrase.']],'answer_data'=>['correct'=>'meeting'],'feedback'=>['correct'=>'Yes—Mucho gusto fits after names are shared.','incorrect'=>'Listen again and compare the three situation cards.'],'completion_condition'=>['type'=>'correct'],'reward_label'=>'Meeting phrase heard','theme_key'=>'challenge'],
            ['sequence'=>3,'source_lesson_section_id'=>$source('example'),'activity_type'=>'instruction','display_title'=>'See Each Expression in Context','student_instructions'=>'Read and hear three tiny modeled moments before choosing expressions yourself.','content'=>'Meeting: “Soy Kai.” — “Mucho gusto.” Help: someone helps you — “Gracias.” Request: “The blue card, por favor.” The request stays partly in English because this lesson does not introduce new request grammar.','interaction_data'=>['language_phrases'=>$player(array_column($phrases,'id')),'facts'=>[['label'=>'Meeting','detail'=>'Soy Kai. — Mucho gusto.'],['label'=>'Receiving help','detail'=>'Someone helps. — Gracias.'],['label'=>'Simple request','detail'=>'The blue card, por favor.']]],'feedback'=>['correct'=>'You saw purpose before formal practice.'],'completion_condition'=>['type'=>'acknowledge'],'reward_label'=>'Contexts modeled','theme_key'=>'learn'],
            ['sequence'=>4,'source_lesson_section_id'=>$source('guided_practice'),'activity_type'=>'matching','display_title'=>'Match Expression to Situation','student_instructions'=>'Choose the expression whose meaning fits each moment.','interaction_data'=>['prompts'=>[['id'=>'meeting','label'=>'You and Luna just shared your names.'],['id'=>'help','label'=>'Someone helps you find the right card.'],['id'=>'request','label'=>'You ask for the red card politely.']],'options'=>collect($phrases)->map(fn($p)=>['id'=>$p['id'],'label'=>$p['spanish']])->all(),'answer_feedback'=>['meeting'=>['default'=>'Which expression means nice to meet you?'],'help'=>['default'=>'Which expression means thank you?'],'request'=>['default'=>'Which expression means please?']]],'answer_data'=>['matches'=>['meeting'=>'mucho_gusto','help'=>'gracias','request'=>'por_favor']],'feedback'=>['correct'=>'Each expression matches its communication job.','incorrect'=>'Use the targeted meaning clue and retry.'],'completion_condition'=>['type'=>'correct'],'reward_label'=>'Courtesy matched','theme_key'=>'learn'],
            ['sequence'=>5,'source_lesson_section_id'=>$source('guided_practice'),'activity_type'=>'question_set','display_title'=>'Respond in Three Guided Moments','student_instructions'=>'Choose only from the three expressions you heard and practiced.','interaction_data'=>['questions'=>[['id'=>'name','prompt'=>'Luna says “Me llamo Luna.” What fits after the introduction?','choices'=>[['id'=>'gusto','label'=>'Mucho gusto.'],['id'=>'gracias','label'=>'Gracias.'],['id'=>'favor','label'=>'Por favor.']]],['id'=>'thanks','prompt'=>'Someone hands you the card you needed.','choices'=>[['id'=>'gracias','label'=>'Gracias.'],['id'=>'gusto','label'=>'Mucho gusto.'],['id'=>'favor','label'=>'Por favor.']]],['id'=>'please','prompt'=>'You politely ask for a card.','choices'=>[['id'=>'favor','label'=>'Por favor.'],['id'=>'gracias','label'=>'Gracias.'],['id'=>'gusto','label'=>'Mucho gusto.']]]],'answer_feedback'=>['name'=>['default'=>'Names were just shared. Which phrase means nice to meet you?'],'thanks'=>['default'=>'Help was received. Which phrase means thank you?'],'please'=>['default'=>'This is a request. Which phrase means please?']]],'answer_data'=>['answers'=>['name'=>'gusto','thanks'=>'gracias','please'=>'favor']],'feedback'=>['correct'=>'All three responses fit their moments.','incorrect'=>'Use the targeted situation clue and retry.'],'completion_condition'=>['type'=>'correct'],'reward_label'=>'Guided moments complete','theme_key'=>'challenge'],
            ['sequence'=>6,'source_lesson_section_id'=>$source('speaking'),'activity_type'=>'project','display_title'=>'Build a Courteous Mini-Exchange','student_instructions'=>'Choose one familiar situation, type a two-line exchange using its modeled expression, and practice it aloud after listening. Your work autosaves.','content'=>'Keep the exchange short. Use greetings/name language from Lessons 1–3 plus one of today’s expressions; no new request grammar is required.','interaction_data'=>['language_phrases'=>$player(array_column($phrases,'id')),'language_work_builder'=>['model'=>'Hola. Soy Kai. — Mucho gusto.','model_support'=>'A complete example uses familiar language plus one fitting courtesy expression.','teacher_review_required'=>true,'preview_title'=>'My Courteous Exchange','fields'=>[['id'=>'situation','label'=>'Choose a familiar situation','control'=>'select','choices'=>[['id'=>'meeting','label'=>'Meeting someone','support'=>'Use mucho gusto.'],['id'=>'help','label'=>'Receiving help','support'=>'Use gracias.'],['id'=>'request','label'=>'A simple modeled request','support'=>'Use por favor.']]],['id'=>'exchange','label'=>'Type a short two-line exchange','control'=>'textarea','minimum_length'=>12,'maximum_length'=>300,'placeholder'=>'Hola. Soy Kai. — Mucho gusto.'],['id'=>'speaking_self_check','label'=>'I listened to the model, practiced my exchange aloud, and checked that the polite expression fits the situation.','control'=>'checkbox','preview'=>false]]]], 'feedback'=>['correct'=>'Your courteous mini-exchange is saved for teacher review.'],'completion_condition'=>['type'=>'structured_language_work'],'reward_label'=>'Courtesy exchange saved','requires_teacher_review'=>true,'theme_key'=>'create'],
            ['sequence'=>7,'source_lesson_section_id'=>$source('exit_check'),'activity_type'=>'question_set','display_title'=>'Courtesy Mission Check','student_instructions'=>'Choose the expression for one meeting, one receiving-help moment, and one request.','interaction_data'=>['questions'=>[['id'=>'meeting','prompt'=>'You have just learned someone’s name.','choices'=>[['id'=>'gusto','label'=>'Mucho gusto.'],['id'=>'gracias','label'=>'Gracias.'],['id'=>'favor','label'=>'Por favor.']]],['id'=>'help','prompt'=>'Someone has just helped you.','choices'=>[['id'=>'gracias','label'=>'Gracias.'],['id'=>'favor','label'=>'Por favor.'],['id'=>'gusto','label'=>'Mucho gusto.']]],['id'=>'request','prompt'=>'You are asking for something politely.','choices'=>[['id'=>'favor','label'=>'Por favor.'],['id'=>'gusto','label'=>'Mucho gusto.'],['id'=>'gracias','label'=>'Gracias.']]]],'answer_feedback'=>['meeting'=>['default'=>'Use the expression for meeting someone.'],'help'=>['default'=>'Use the expression that thanks someone.'],'request'=>['default'=>'Use the expression that adds please to a request.']]],'answer_data'=>['answers'=>['meeting'=>'gusto','help'=>'gracias','request'=>'favor']],'feedback'=>['correct'=>'¡Muy bien! You can choose polite expressions by meaning and situation.','incorrect'=>'Use the targeted communication clue and retry.'],'completion_condition'=>['type'=>'correct'],'reward_label'=>'Courtesy mission complete','theme_key'=>'check'],
        ];
        $experience=$this->createFromBlueprint($lesson,['status'=>'preview','theme_key'=>'spanish-cosmic-passport','mission_title'=>'Courtesy Signal Mission','mission_brief'=>'Hear, recognize, say, and use por favor, gracias, and mucho gusto in brief familiar exchanges.','completion_title'=>'Courtesy Signals Ready','completion_message'=>'You used all three polite expressions by meaning and saved a short courteous exchange.','source_version'=>'spanish-unit-1-lesson-4-v1'],$activities,true);
        $this->provisionSpanishDigitalResources($lesson,'courtesy_reference','Polite Expressions Interactive Phrase Guide','Embedded courtesy-expression audio, context models, replay, and guided response support.');
        return $experience;
    }

    private function assertSpanishLesson(Lesson $lesson, int $sequence, string $title, array $requiredSections): \Illuminate\Support\Collection
    {
        $lesson->loadMissing('curriculumUnit','lessonPlan.curriculumImport.subject');
        $subject=mb_strtolower($lesson->lessonPlan->curriculumImport->subject->name.' '.$lesson->lessonPlan->curriculumImport->subject->code);
        if ($lesson->sequence!==$sequence || $lesson->title!==$title || $lesson->curriculumUnit->sequence!==1 || $lesson->curriculumUnit->name!=='Unit 1 - Hola, Soy Yo' || (!str_contains($subject,'language') && !str_contains($subject,'spanish'))) throw ValidationException::withMessages(['lesson'=>'This Spanish preview is reserved for its approved Unit 1 lesson.']);
        $sections=$lesson->allSections()->get()->keyBy('section_type');
        foreach($requiredSections as $required) if(!$sections->has($required)) throw ValidationException::withMessages(['lesson'=>"The selected Spanish lesson is missing its {$required} source section."]);
        return $sections;
    }

    private function provisionSpanishDigitalResources(Lesson $lesson, string $asset, string $title, string $description): void
    {
        foreach($lesson->resources()->get() as $resource){$before=$resource->toArray();$resource->update(['availability_status'=>'not_applicable','metadata'=>[...($resource->metadata??[]),'student_experience_required'=>false,'optional_teacher_fallback'=>true,'superseded_by_digital_experience'=>true]]);$this->audit->record('lesson-resource.spanish-print-dependency-retired',$resource,$before,$resource->fresh()->toArray());}
        $resource=$lesson->resources()->updateOrCreate(['category'=>'lesson_resource','sort_order'=>20],['resource_type'=>'interactive_reference','title'=>$title,'description'=>$description,'delivery_type'=>'embedded','availability_status'=>'needs_asset','metadata'=>['spanish_foundation_asset'=>$asset,'student_experience_required'=>true,'content_origin'=>'application_created']]);
        $this->audit->record('lesson-resource.spanish-experience-defined',$resource,[],$resource->toArray());$lesson->update(['estimated_preparation_minutes'=>0]);
        if(config('lesson-resources.automatic_fulfillment'))$this->resourceFulfillment->fulfillRequiredForLesson($lesson);
    }

    public function provisionTechnologyVariablesPrototype(Lesson $lesson): LessonExperience
    {
        $this->assertTechnologyLesson($lesson, 2, 'Mission Data: Creating and Updating Variables', ['hook','direct_instruction','demonstration','guided_practice','build']);
        $sections = $lesson->allSections()->get()->keyBy('section_type'); $source = fn (string $type): int => $sections->get($type)->id;
        $example = "destination = \"Moon\"\nprint(\"Destination:\", destination)\ndestination = \"Mars\"\nprint(\"Updated destination:\", destination)";
        $project = "mission_name = \"Orbital Explorer\"\ncommander_name = \"Kai\"\ndestination = \"Moon\"\nlaunch_status = \"Preparing\"\nprint(\"Mission:\", mission_name)\nprint(\"Commander:\", commander_name)\nprint(\"Destination:\", destination)\nprint(\"Status:\", launch_status)\ndestination = \"Mars\"\nprint(\"Updated destination:\", destination)";
        $activities = [
            ['sequence'=>1,'source_lesson_section_id'=>$source('hook'),'activity_type'=>'instruction','display_title'=>'A Label That Keeps Information','student_instructions'=>'Watch what happens when the label stays the same but the destination changes.','content'=>'Imagine a mission display with a slot named destination. It can show Moon now and Mars later. A program needs the same kind of named storage.','interaction_data'=>['facts'=>[['label'=>'Same label','detail'=>'destination still describes what the information means.'],['label'=>'New information','detail'=>'Mars replaces Moon without replacing the label.']]],'completion_condition'=>['type'=>'acknowledge'],'reward_label'=>'Storage idea spotted','theme_key'=>'mission'],
            ['sequence'=>2,'source_lesson_section_id'=>$source('demonstration'),'activity_type'=>'instruction','display_title'=>'See Stored Information Change','student_instructions'=>'Trace the example from top to bottom and compare the two destination outputs.','content'=>'The first line stores Moon. A later line stores Mars under the same name. Storing does not display anything by itself, so print() is used when the program should show the information.','interaction_data'=>['code_display'=>['source'=>$example,'output'=>['Destination: Moon','Updated destination: Mars'],'execution_notice'=>'Illustrated output from the lesson-approved simulator; no Python code was executed.']],'completion_condition'=>['type'=>'acknowledge'],'reward_label'=>'Change traced','theme_key'=>'learn'],
            ['sequence'=>3,'source_lesson_section_id'=>$source('demonstration'),'activity_type'=>'multiple_choice','display_title'=>'Predict the Updated Destination','student_instructions'=>'Which destination do you predict the final line will display? This prediction is saved but not graded.','content'=>'Read the assignments from the top down before choosing.','interaction_data'=>['ungraded'=>true,'choices'=>[['id'=>'moon','label'=>'Moon'],['id'=>'mars','label'=>'Mars']],'code_display'=>['source'=>$example,'output'=>['Destination: Moon','Updated destination: Mars'],'hide_output_until_response'=>true,'execution_notice'=>'Illustrated output; no Python code was executed.']],'feedback'=>['correct'=>'Compare your prediction with the revealed output. The later stored value is the one the final print() line uses.'],'completion_condition'=>['type'=>'acknowledge_prediction'],'reward_label'=>'Update observed','theme_key'=>'challenge'],
            ['sequence'=>4,'source_lesson_section_id'=>$source('guided_practice'),'activity_type'=>'project','display_title'=>'Guided Destination Update','student_instructions'=>'Change the starting and updated destinations, predict the two outputs, preview them, and explain what stayed the same.','content'=>'The working structure is provided. Edit only the quoted destination text first; keep the destination name and both print lines.','interaction_data'=>['technology_code_builder'=>['starter_code'=>$example,'minimum_statements'=>2,'required_variable_names'=>['destination'],'minimum_updated_variables'=>1,'minimum_prints'=>2,'required_output_labels'=>['Destination:','Updated destination:'],'teacher_review_required'=>false,'prediction_label'=>'What two destination lines do you expect to see?','reflection_label'=>'What stayed the same, and what changed?','submit_label'=>'Save destination update and continue']],'feedback'=>['correct'=>'You changed stored information while keeping its meaningful name.'],'completion_condition'=>['type'=>'structured_technology_code'],'reward_label'=>'Destination updated','theme_key'=>'create'],
            ['sequence'=>5,'source_lesson_section_id'=>$source('direct_instruction'),'activity_type'=>'instruction','display_title'=>'Now Name the Storage Idea','student_instructions'=>'Connect the names programmers use to the behavior you already observed and changed.','content'=>'A variable is a meaningful name that refers to stored information. The equals sign stores the value on the right under the name on the left. Storing a new value under the same name is an update, also called reassignment.','interaction_data'=>['facts'=>[['label'=>'Variable','detail'=>'A name that refers to stored information.'],['label'=>'Meaningful name','detail'=>'destination explains more than a name such as x.'],['label'=>'Underscore','detail'=>'Connects words in names such as mission_name because spaces are not allowed.'],['label'=>'Assignment','detail'=>'Stores the right-side value under the left-side name; it does not display by itself.']]],'completion_condition'=>['type'=>'acknowledge'],'reward_label'=>'Storage named','theme_key'=>'learn'],
            ['sequence'=>6,'source_lesson_section_id'=>$source('build'),'activity_type'=>'project','display_title'=>'Upgrade the Mission Briefing with Variables','student_instructions'=>'Customize four stored text values, preview their labeled output, update one value, and explain how one variable works.','content'=>'Starter code already contains mission_name, commander_name, destination, and launch_status. Keep all four meaningful names and replace only the mission information you want to customize.','interaction_data'=>['technology_code_builder'=>['starter_code'=>$project,'minimum_statements'=>5,'required_variable_names'=>['mission_name','commander_name','destination','launch_status'],'minimum_updated_variables'=>1,'minimum_prints'=>5,'required_output_labels'=>['Mission:','Commander:','Destination:','Status:','Updated destination:'],'prediction_label'=>'Which value will the final destination line display?','reflection_label'=>'Choose one variable. What information does its name refer to, and how did its value change or stay the same?','submit_label'=>'Save mission variables and continue']],'feedback'=>['correct'=>'Your four-variable mission upgrade is saved for teacher review.'],'completion_condition'=>['type'=>'structured_technology_code'],'reward_label'=>'Mission data upgraded','requires_teacher_review'=>true,'theme_key'=>'create'],
            ['sequence'=>7,'source_lesson_section_id'=>$source('build'),'activity_type'=>'question_set','display_title'=>'Mission Data Check','student_instructions'=>'Check the two ideas you used in your code.','interaction_data'=>['questions'=>[['id'=>'variable','prompt'=>'What does the name destination do?','choices'=>[['id'=>'stored','label'=>'It refers to stored destination information.'],['id'=>'display','label'=>'It automatically displays words without print().'],['id'=>'random','label'=>'It chooses a random place.']]],['id'=>'meaningful','prompt'=>'Why is mission_name clearer than x?','choices'=>[['id'=>'purpose','label'=>'It explains what the stored information means.'],['id'=>'longer','label'=>'Longer names always make code run faster.'],['id'=>'quotes','label'=>'It adds quotation marks automatically.']]]],'answer_feedback'=>['variable'=>['display'=>'Storing and displaying are separate jobs. Which name refers to the saved information?','random'=>'Nothing in this lesson chooses randomly.'],'meaningful'=>['longer'=>'Think about what another reader can understand from the name.','quotes'=>'Variable names do not add quotation marks.']]],'answer_data'=>['answers'=>['variable'=>'stored','meaningful'=>'purpose']],'feedback'=>['correct'=>'Mission data check passed. You can store, update, display, and explain named information.','incorrect'=>'Use the targeted hint, then revisit the example and try again.'],'completion_condition'=>['type'=>'correct'],'reward_label'=>'Data check passed','theme_key'=>'check'],
        ];
        $experience = $this->createFromBlueprint($lesson,['status'=>'preview','theme_key'=>'technology-mission-control','mission_title'=>'Mission Data Lab','mission_brief'=>'Turn fixed briefing text into stored mission information that can be displayed and updated.','completion_title'=>'Mission Data Stored','completion_message'=>'You created four meaningful text variables, displayed them, and updated one value.','source_version'=>'technology-unit-1-lesson-2-v1'],$activities,true);
        $this->provisionTechnologyDigitalResources($lesson,'variable_reference','Stored Mission Data Guide','Embedded variable, meaningful-name, output, and update examples.'); return $experience;
    }

    public function provisionTechnologyInputPrototype(Lesson $lesson): LessonExperience
    {
        $this->assertTechnologyLesson($lesson, 3, 'Astronaut Profile: Collecting Input', ['question','direct_instruction','demonstration','guided_practice','build','check_for_understanding']);
        $sections=$lesson->allSections()->get()->keyBy('section_type');$source=fn(string $type):int=>$sections->get($type)->id;
        $one="commander_name = input(\"Enter commander name: \" )\nprint(\"Commander:\", commander_name)";
        $two="commander_name = input(\"Enter commander name: \" )\npilot_name = input(\"Enter pilot name: \" )\nprint(\"Commander:\", commander_name)\nprint(\"Pilot:\", pilot_name)";
        $four="commander_name = input(\"Enter commander name: \" )\npilot_name = input(\"Enter pilot name: \" )\nmission_name = input(\"Enter mission name: \" )\nspacecraft_name = input(\"Enter spacecraft name: \" )\nprint(\"Commander:\", commander_name)\nprint(\"Pilot:\", pilot_name)\nprint(\"Mission:\", mission_name)\nprint(\"Spacecraft:\", spacecraft_name)";
        $fields=[['id'=>'commander_name','label'=>'Commander test response','placeholder'=>'Kai'],['id'=>'pilot_name','label'=>'Pilot test response','placeholder'=>'Nova'],['id'=>'mission_name','label'=>'Mission test response','placeholder'=>'Red Planet Scout'],['id'=>'spacecraft_name','label'=>'Spacecraft test response','placeholder'=>'Odyssey']];
        $activities=[
            ['sequence'=>1,'source_lesson_section_id'=>$source('question'),'activity_type'=>'instruction','display_title'=>'How Can One Program Meet Different Crews?','student_instructions'=>'Consider how the same program could build a different profile for each person without changing its code first.','content'=>'A program can ask a clear question, wait for a response, keep that response, and use it later. Today you will build that path into the astronaut profile.','interaction_data'=>['facts'=>[['label'=>'Problem','detail'=>'Fixed text gives every crew the same name.'],['label'=>'Goal','detail'=>'Let the user supply commander, pilot, mission, and spacecraft information.']]],'completion_condition'=>['type'=>'acknowledge'],'reward_label'=>'Profile problem found','theme_key'=>'mission'],
            ['sequence'=>2,'source_lesson_section_id'=>$source('demonstration'),'activity_type'=>'instruction','display_title'=>'Watch One Response Travel','student_instructions'=>'Trace the commander response through the visible question, storage name, and output.','content'=>'In a real Python runtime, input() shows the question and waits for typing. This safe lesson preview uses the test response shown with the example instead of opening a real keyboard process.','interaction_data'=>['code_display'=>['source'=>$one,'output'=>['Commander: Kai'],'execution_notice'=>'Illustrated with the test response Kai; no Python code was executed.'],'facts'=>[['label'=>'Ask','detail'=>'Enter commander name clearly tells the user what to type.'],['label'=>'Keep','detail'=>'commander_name refers to the response.'],['label'=>'Use','detail'=>'The print line displays that stored response later.']]],'completion_condition'=>['type'=>'acknowledge'],'reward_label'=>'Response traced','theme_key'=>'learn'],
            ['sequence'=>3,'source_lesson_section_id'=>$source('demonstration'),'activity_type'=>'multiple_choice','display_title'=>'Predict a New Commander Output','student_instructions'=>'If the test response is Nova, what do you predict the labeled output will show? Predictions are not graded.','content'=>'The code stays the same; only the response supplied while testing changes.','interaction_data'=>['ungraded'=>true,'choices'=>[['id'=>'kai','label'=>'Commander: Kai'],['id'=>'nova','label'=>'Commander: Nova']],'code_display'=>['source'=>$one,'output'=>['Commander: Nova'],'hide_output_until_response'=>true,'execution_notice'=>'Illustrated with the test response Nova; no Python code was executed.']],'feedback'=>['correct'=>'Compare your prediction with the revealed output: the later print line used the new stored response.'],'completion_condition'=>['type'=>'acknowledge_prediction'],'reward_label'=>'Input effect observed','theme_key'=>'challenge'],
            ['sequence'=>4,'source_lesson_section_id'=>$source('guided_practice'),'activity_type'=>'project','display_title'=>'Build Two Profile Questions Together','student_instructions'=>'Provide two test responses, improve either prompt if you wish, preview the labeled profile, and explain the response path.','content'=>'Commander and pilot starter lines are provided. Keep the two meaningful variable names, clear prompts, and matching output labels.','interaction_data'=>['technology_code_builder'=>['starter_code'=>$two,'minimum_statements'=>2,'input_fields'=>array_slice($fields,0,2),'required_input_variables'=>['commander_name','pilot_name'],'required_variable_names'=>['commander_name','pilot_name'],'minimum_prints'=>2,'required_output_labels'=>['Commander:','Pilot:'],'teacher_review_required'=>false,'prediction_label'=>'What commander and pilot lines will your test responses produce?','reflection_label'=>'How does one response travel from its prompt to the labeled output?','submit_label'=>'Save two-question profile and continue']],'feedback'=>['correct'=>'Both test responses traveled through the intended variables to matching output labels.'],'completion_condition'=>['type'=>'structured_technology_code'],'reward_label'=>'Two questions connected','theme_key'=>'create'],
            ['sequence'=>5,'source_lesson_section_id'=>$source('direct_instruction'),'activity_type'=>'instruction','display_title'=>'Now Name the Input Flow','student_instructions'=>'Name each part of the ask, store, and display behavior you have already traced.','content'=>'input() displays a prompt and, in a real runtime, waits for keyboard text. Assignment stores that text under a meaningful variable name. A later print() line can display it.','interaction_data'=>['facts'=>[['label'=>'Prompt','detail'=>'A clear question telling the user what to type.'],['label'=>'input()','detail'=>'Gets the response in a real Python runtime; this lesson supplies safe simulated responses.'],['label'=>'Store','detail'=>'Assignment keeps the response under a name such as commander_name.'],['label'=>'Display','detail'=>'print() uses the stored name later.']]],'completion_condition'=>['type'=>'acknowledge'],'reward_label'=>'Input flow named','theme_key'=>'learn'],
            ['sequence'=>6,'source_lesson_section_id'=>$source('build'),'activity_type'=>'project','display_title'=>'Add the Four-Part Astronaut Profile','student_instructions'=>'Enter four test responses, customize clear prompts if desired, preview all four labeled lines, and explain one complete path.','content'=>'The full starter structure is provided after the welcome portion of the project. Keep commander, pilot, mission, and spacecraft questions and use every stored response in output.','interaction_data'=>['technology_code_builder'=>['starter_code'=>$four,'minimum_statements'=>4,'input_fields'=>$fields,'required_input_variables'=>['commander_name','pilot_name','mission_name','spacecraft_name'],'required_variable_names'=>['commander_name','pilot_name','mission_name','spacecraft_name'],'minimum_prints'=>4,'required_output_labels'=>['Commander:','Pilot:','Mission:','Spacecraft:'],'prediction_label'=>'What four profile lines will your test responses produce?','reflection_label'=>'Trace one response: what asks for it, what stores it, and what displays it?','submit_label'=>'Save astronaut profile and continue']],'feedback'=>['correct'=>'Your four-part astronaut profile is saved for teacher review.'],'completion_condition'=>['type'=>'structured_technology_code'],'reward_label'=>'Astronaut profile built','requires_teacher_review'=>true,'theme_key'=>'create'],
            ['sequence'=>7,'source_lesson_section_id'=>$source('check_for_understanding'),'activity_type'=>'question_set','display_title'=>'Trace One Response','student_instructions'=>'Trace a spacecraft response through the program.','interaction_data'=>['questions'=>[['id'=>'prompt','prompt'=>'Which part tells the user what to enter?','choices'=>[['id'=>'question','label'=>'The text inside input()'],['id'=>'variable','label'=>'The later print label'],['id'=>'output','label'=>'The saved filename']]],['id'=>'store','prompt'=>'Where is the typed spacecraft response kept?','choices'=>[['id'=>'spacecraft','label'=>'spacecraft_name'],['id'=>'quotes','label'=>'Inside the prompt quotation marks'],['id'=>'print','label'=>'Inside the word print']]],['id'=>'later','prompt'=>'How does the program display the response later?','choices'=>[['id'=>'use_variable','label'=>'It uses spacecraft_name in print().'],['id'=>'ask_again','label'=>'It must ask the question again.'],['id'=>'fixed','label'=>'It displays only fixed text.']]]]],'answer_data'=>['answers'=>['prompt'=>'question','store'=>'spacecraft','later'=>'use_variable']],'feedback'=>['correct'=>'Profile trace passed. You can follow a response from prompt to storage to output.','incorrect'=>'Follow the visible flow: ask, store under a name, then use that name in print().'],'completion_condition'=>['type'=>'correct'],'reward_label'=>'Profile trace passed','theme_key'=>'check'],
        ];
        $experience=$this->createFromBlueprint($lesson,['status'=>'preview','theme_key'=>'technology-mission-control','mission_title'=>'Astronaut Profile Link','mission_brief'=>'Teach the mission program to ask for four crew and spacecraft details, keep them, and display them later.','completion_title'=>'Astronaut Profile Connected','completion_message'=>'You built and traced four clear input–store–display paths.','source_version'=>'technology-unit-1-lesson-3-v1'],$activities,true);
        $this->provisionTechnologyDigitalResources($lesson,'input_flow_reference','Input–Store–Display Flow Guide','Embedded prompts, simulated-input flow, starter code, and profile reference.'); return $experience;
    }

    public function provisionTechnologySpacecraftProfilePrototype(Lesson $lesson): LessonExperience
    {
        $this->assertTechnologyLesson($lesson,4,'Build the Spacecraft with Multiple String Variables',['context','demonstration','guided_practice','build','check_for_understanding']);
        $sections=$lesson->allSections()->get()->keyBy('section_type');$source=fn(string $type):?int=>$sections->get($type)?->id;
        $two="rocket_name = \"Pathfinder\"\ncall_sign = \"Silver Comet\"\nprint(\"Rocket:\", rocket_name)\nprint(\"Call sign:\", call_sign)";
        $crossed="rocket_name = \"Pathfinder\"\ncall_sign = \"Silver Comet\"\nprint(\"Rocket:\", call_sign)\nprint(\"Call sign:\", rocket_name)";
        $guided="rocket_name = \"Pathfinder\"\ndestination = \"Europa\"\npayload = \"Ice scanner\"\nprint(\"Rocket:\", rocket_name)\nprint(\"Destination:\", destination)\nprint(\"Payload:\", payload)";
        $five="rocket_name = \"Pathfinder\"\ndestination = \"Europa\"\npayload = \"Ice scanner\"\ncrew_role = \"Navigation specialist\"\ncall_sign = \"Silver Comet\"\nprint(\"Rocket:\", rocket_name)\nprint(\"Destination:\", destination)\nprint(\"Payload:\", payload)\nprint(\"Crew role:\", crew_role)\nprint(\"Call sign:\", call_sign)";
        $activities=[
            ['sequence'=>1,'source_lesson_section_id'=>$source('context'),'activity_type'=>'instruction','display_title'=>'One Spacecraft, Five Details','student_instructions'=>'See why one storage name cannot clearly hold every spacecraft detail.','content'=>'A spacecraft profile needs a rocket name, destination, payload, crew role, and call sign at the same time. Separate meaningful names keep one detail from replacing another.','interaction_data'=>['facts'=>[['label'=>'Five details','detail'=>'Each detail needs its own named storage place.'],['label'=>'One profile','detail'=>'The five stored values can be displayed together as a readable profile.']]],'completion_condition'=>['type'=>'acknowledge'],'reward_label'=>'Profile need identified','theme_key'=>'mission'],
            ['sequence'=>2,'source_lesson_section_id'=>$source('demonstration'),'activity_type'=>'instruction','display_title'=>'See a Two-Item Spacecraft Profile','student_instructions'=>'Trace each output label to the stored name beside it.','content'=>'The label Rocket: is paired with rocket_name, while Call sign: is paired with call_sign. That match makes the output truthful and readable.','interaction_data'=>['code_display'=>['source'=>$two,'output'=>['Rocket: Pathfinder','Call sign: Silver Comet'],'execution_notice'=>'Illustrated output from the safe lesson simulator; no Python code was executed.']],'completion_condition'=>['type'=>'acknowledge'],'reward_label'=>'Profile traced','theme_key'=>'learn'],
            ['sequence'=>3,'source_lesson_section_id'=>$source('demonstration'),'activity_type'=>'multiple_choice','display_title'=>'Spot the Crossed Profile Wires','student_instructions'=>'The two output variables are crossed. Which repair makes the Rocket label show the rocket name?','content'=>$crossed,'interaction_data'=>['choices'=>[['id'=>'rocket','label'=>'Use rocket_name after "Rocket:"'],['id'=>'call','label'=>'Keep call_sign after "Rocket:"'],['id'=>'rename','label'=>'Rename both variables x']],'choice_feedback'=>['call'=>'That would keep the misleading crossed output. Follow the Rocket label to the value it should describe.','rename'=>'Names such as x would hide the purpose instead of repairing the match.']],'answer_data'=>['correct'=>'rocket'],'feedback'=>['correct'=>'Correct. The Rocket label must display rocket_name.','incorrect'=>'Trace the output label to the stored detail with the same meaning.'],'completion_condition'=>['type'=>'correct'],'reward_label'=>'Crossed wire repaired','hints'=>['Match meaning to meaning: Rocket with rocket_name.'],'theme_key'=>'challenge'],
            ['sequence'=>4,'source_lesson_section_id'=>$source('direct_instruction') ?? $source('context'),'activity_type'=>'instruction','display_title'=>'Name Profiles Clearly','student_instructions'=>'Use names that tell another reader exactly which spacecraft detail is stored.','content'=>'Names such as rocket_name and crew_role communicate purpose. Python variable names cannot contain spaces, so an underscore can connect words. Labels and variable names have different jobs: the label helps the person reading output; the variable name helps the programmer follow stored information.','interaction_data'=>['facts'=>[['label'=>'rocket_name','detail'=>'Stores the rocket name.'],['label'=>'crew_role','detail'=>'Stores the crew member’s mission job.'],['label'=>'Output label','detail'=>'Readable text such as Crew role: shown beside the value.']]],'completion_condition'=>['type'=>'acknowledge'],'reward_label'=>'Names clarified','theme_key'=>'learn'],
            ['sequence'=>5,'source_lesson_section_id'=>$source('guided_practice'),'activity_type'=>'project','display_title'=>'Guided Three-Detail Profile','student_instructions'=>'Customize the three supplied values, predict the output, preview it, and verify that every label matches its stored name.','content'=>'Rocket, destination, and payload starter code is complete. Change the quoted values without replacing the meaningful names or matching output labels.','interaction_data'=>['technology_code_builder'=>['starter_code'=>$guided,'minimum_statements'=>3,'required_variable_names'=>['rocket_name','destination','payload'],'minimum_prints'=>3,'required_output_labels'=>['Rocket:','Destination:','Payload:'],'teacher_review_required'=>false,'prediction_label'=>'What three labeled lines will your customized values produce?','reflection_label'=>'How did you verify that each label uses the intended stored value?','submit_label'=>'Save guided profile and continue']],'feedback'=>['correct'=>'Your three profile labels match the intended stored values.'],'completion_condition'=>['type'=>'structured_technology_code'],'reward_label'=>'Three details connected','theme_key'=>'create'],
            ['sequence'=>6,'source_lesson_section_id'=>$source('build'),'activity_type'=>'project','display_title'=>'Build the Complete Spacecraft Profile','student_instructions'=>'Customize all five stored details, preview the complete profile, and explain why one meaningful name is useful.','content'=>'The five required variables and matching output lines are provided. Keep rocket_name, destination, payload, crew_role, and call_sign while making the spacecraft your own.','interaction_data'=>['technology_code_builder'=>['starter_code'=>$five,'minimum_statements'=>5,'required_variable_names'=>['rocket_name','destination','payload','crew_role','call_sign'],'minimum_prints'=>5,'required_output_labels'=>['Rocket:','Destination:','Payload:','Crew role:','Call sign:'],'prediction_label'=>'What five labeled profile lines do you expect?','reflection_label'=>'Choose one variable name and explain why it is clearer than var1 or x.','submit_label'=>'Save spacecraft profile and continue']],'feedback'=>['correct'=>'Your five-detail spacecraft profile is saved for teacher review.'],'completion_condition'=>['type'=>'structured_technology_code'],'reward_label'=>'Spacecraft profile built','requires_teacher_review'=>true,'theme_key'=>'create'],
            ['sequence'=>7,'source_lesson_section_id'=>$source('check_for_understanding'),'activity_type'=>'question_set','display_title'=>'Profile Systems Check','student_instructions'=>'Confirm that you can trace labels, variable names, and stored values.','interaction_data'=>['questions'=>[['id'=>'label','prompt'=>'In print("Payload:", payload), what should appear beside Payload:?','choices'=>[['id'=>'payload_value','label'=>'The value stored under payload'],['id'=>'rocket_value','label'=>'The value stored under rocket_name'],['id'=>'word_only','label'=>'Only the word print']]],['id'=>'separate','prompt'=>'Why use separate variables for five details?','choices'=>[['id'=>'preserve','label'=>'Each detail can be stored and displayed without replacing the others.'],['id'=>'speed','label'=>'Five variables always run five times faster.'],['id'=>'hide','label'=>'They hide the meaning of the profile.']]]]],'answer_data'=>['answers'=>['label'=>'payload_value','separate'=>'preserve']],'feedback'=>['correct'=>'Systems check passed. Every label, name, and value has a clear job.','incorrect'=>'Trace the matching name and label in the visible two-item example, then retry.'],'completion_condition'=>['type'=>'correct'],'reward_label'=>'Profile check passed','theme_key'=>'check'],
        ];
        $experience=$this->createFromBlueprint($lesson,['status'=>'preview','theme_key'=>'technology-mission-control','mission_title'=>'Spacecraft Profile Assembly','mission_brief'=>'Organize five related spacecraft details under clear names and display a readable profile.','completion_title'=>'Spacecraft Profile Assembled','completion_message'=>'You stored and displayed five correctly labeled spacecraft details.','source_version'=>'technology-unit-1-lesson-4-v1'],$activities,true);
        $this->provisionTechnologyDigitalResources($lesson,'spacecraft_profile_reference','Spacecraft Profile Data Guide','Embedded meaningful-name, matching-label, starter-code, and profile-check reference.'); return $experience;
    }

    public function provisionTechnologyNumericResourcesPrototype(Lesson $lesson): LessonExperience
    {
        $this->assertTechnologyLesson($lesson,5,'Spacecraft Resources: Integers, Decimals, and Updates',['activity','direct_instruction','demonstration','guided_practice','build']);
        $sections=$lesson->allSections()->get()->keyBy('section_type');$source=fn(string $type):int=>$sections->get($type)->id;
        $battery="battery_power = 88.5\nprint(\"Battery power:\", battery_power)\nbattery_power = 84.0\nprint(\"Updated battery power:\", battery_power)";
        $guided="fuel = 100\noxygen = 92.5\ncrew_count = 3\nprint(\"Fuel:\", fuel)\nprint(\"Oxygen:\", oxygen)\nprint(\"Crew count:\", crew_count)\nfuel = 95\nprint(\"Updated fuel:\", fuel)";
        $five="fuel = 100\noxygen = 92.5\nbattery_power = 88.5\ncargo_mass = 250.75\ncrew_count = 3\nprint(\"Fuel:\", fuel)\nprint(\"Oxygen:\", oxygen)\nprint(\"Battery power:\", battery_power)\nprint(\"Cargo mass:\", cargo_mass)\nprint(\"Crew count:\", crew_count)\nfuel = 95\nbattery_power = 84.0\nprint(\"Updated fuel:\", fuel)\nprint(\"Updated battery power:\", battery_power)";
        $activities=[
            ['sequence'=>1,'source_lesson_section_id'=>$source('activity'),'activity_type'=>'question_set','display_title'=>'Text or Number?','student_instructions'=>'Compare each value by how it is written. Use the quotation marks and decimal point as visible clues.','content'=>'A mission computer may need to calculate with resource amounts. Quotation marks make characters text, while unquoted whole and decimal values can be treated as numbers in later calculations.','interaction_data'=>['questions'=>[['id'=>'quoted','prompt'=>'Which example is text?','choices'=>[['id'=>'text','label'=>'"5"'],['id'=>'integer','label'=>'5'],['id'=>'decimal','label'=>'5.5']]],['id'=>'whole','prompt'=>'Which example is a whole-number value?','choices'=>[['id'=>'integer','label'=>'5'],['id'=>'text','label'=>'"5"'],['id'=>'decimal','label'=>'5.5']]],['id'=>'decimal','prompt'=>'Which example is a decimal value?','choices'=>[['id'=>'decimal','label'=>'5.5'],['id'=>'text','label'=>'"5"'],['id'=>'integer','label'=>'5']]]]],'answer_data'=>['answers'=>['quoted'=>'text','whole'=>'integer','decimal'=>'decimal']],'feedback'=>['correct'=>'You used the visible clues to distinguish text, a whole number, and a decimal value.','incorrect'=>'Check quotation marks first, then look for a decimal point.'],'completion_condition'=>['type'=>'correct'],'reward_label'=>'Values sorted','theme_key'=>'mission'],
            ['sequence'=>2,'source_lesson_section_id'=>$source('direct_instruction'),'activity_type'=>'instruction','display_title'=>'See Three Resources Stored as Numbers','student_instructions'=>'Observe how whole and decimal resource values are written without quotation marks.','content'=>'fuel stores 100, oxygen stores 92.5, and crew_count stores 3. These are numeric values because they are not inside quotation marks.','interaction_data'=>['code_display'=>['source'=>"fuel = 100\noxygen = 92.5\ncrew_count = 3\nprint(\"Fuel:\", fuel)\nprint(\"Oxygen:\", oxygen)\nprint(\"Crew count:\", crew_count)",'output'=>['Fuel: 100','Oxygen: 92.5','Crew count: 3'],'execution_notice'=>'Illustrated output from the safe lesson simulator; no Python code was executed.']],'completion_condition'=>['type'=>'acknowledge'],'reward_label'=>'Numbers observed','theme_key'=>'learn'],
            ['sequence'=>3,'source_lesson_section_id'=>$source('demonstration'),'activity_type'=>'multiple_choice','display_title'=>'Predict the Battery Update','student_instructions'=>'What final battery value do you predict after the later assignment? Predictions are not graded.','content'=>'The name battery_power stays the same while a later numeric value replaces the earlier one.','interaction_data'=>['ungraded'=>true,'choices'=>[['id'=>'old','label'=>'88.5'],['id'=>'new','label'=>'84.0']],'code_display'=>['source'=>$battery,'output'=>['Battery power: 88.5','Updated battery power: 84.0'],'hide_output_until_response'=>true,'execution_notice'=>'Illustrated output; no Python code was executed.']],'feedback'=>['correct'=>'Compare your prediction with the revealed output. The later assignment supplied the final battery value.'],'completion_condition'=>['type'=>'acknowledge_prediction'],'reward_label'=>'Numeric update observed','theme_key'=>'challenge'],
            ['sequence'=>4,'source_lesson_section_id'=>$source('direct_instruction'),'activity_type'=>'instruction','display_title'=>'Now Name the Number Types','student_instructions'=>'Attach the useful Python names to the number behavior you have already observed.','content'=>'An integer is a whole-number value. A decimal value includes a decimal point; Python commonly represents it as a float. Quotation marks would make either example text—a string—instead of a numeric value.','interaction_data'=>['facts'=>[['label'=>'Integer','detail'=>'A whole number such as 3 or 100.'],['label'=>'Decimal / float','detail'=>'A numeric value with a decimal point such as 92.5.'],['label'=>'String','detail'=>'Quoted text such as "5"; it is not the numeric value 5.'],['label'=>'Reassignment','detail'=>'Stores a new numeric value under an existing name.']]],'completion_condition'=>['type'=>'acknowledge'],'reward_label'=>'Number types named','theme_key'=>'learn'],
            ['sequence'=>5,'source_lesson_section_id'=>$source('guided_practice'),'activity_type'=>'project','display_title'=>'Guided Resource Console','student_instructions'=>'Customize the three numeric values, update fuel once, preview the console, and explain the update.','content'=>'Starter code includes fuel, oxygen, and crew count. Keep all values unquoted so the simulator treats them as numbers.','interaction_data'=>['technology_code_builder'=>['starter_code'=>$guided,'minimum_statements'=>4,'required_numeric_variables'=>['fuel','oxygen','crew_count'],'required_variable_names'=>['fuel','oxygen','crew_count'],'minimum_updated_variables'=>1,'minimum_prints'=>4,'required_output_labels'=>['Fuel:','Oxygen:','Crew count:','Updated fuel:'],'teacher_review_required'=>false,'prediction_label'=>'What initial and updated resource lines do you expect?','reflection_label'=>'What stayed the same when fuel changed, and what was replaced?','submit_label'=>'Save guided resources and continue']],'feedback'=>['correct'=>'Your resource values remained numeric and the fuel update worked.'],'completion_condition'=>['type'=>'structured_technology_code'],'reward_label'=>'Resource console tested','theme_key'=>'create'],
            ['sequence'=>6,'source_lesson_section_id'=>$source('build'),'activity_type'=>'project','display_title'=>'Build the Five-Resource Mission Console','student_instructions'=>'Customize five numeric resources, update at least two, preview initial and updated outputs, and explain your choices.','content'=>'The required fuel, oxygen, battery power, cargo mass, and crew count structure is provided. Use practice values; scientific accuracy is not required in this programming lesson.','interaction_data'=>['technology_code_builder'=>['starter_code'=>$five,'minimum_statements'=>7,'required_numeric_variables'=>['fuel','oxygen','battery_power','cargo_mass','crew_count'],'required_variable_names'=>['fuel','oxygen','battery_power','cargo_mass','crew_count'],'minimum_updated_variables'=>2,'minimum_prints'=>7,'required_output_labels'=>['Fuel:','Oxygen:','Battery power:','Cargo mass:','Crew count:','Updated fuel:','Updated battery power:'],'prediction_label'=>'Which two updated values will appear at the end?','reflection_label'=>'Identify one integer and one decimal in your console, then explain why neither uses quotation marks.','submit_label'=>'Save resource console and continue']],'feedback'=>['correct'=>'Your five-resource console and two numeric updates are saved for teacher review.'],'completion_condition'=>['type'=>'structured_technology_code'],'reward_label'=>'Resource console built','requires_teacher_review'=>true,'theme_key'=>'create'],
            ['sequence'=>7,'source_lesson_section_id'=>$source('build'),'activity_type'=>'question_set','display_title'=>'Resource Type Check','student_instructions'=>'Check the value types and update behavior used in your console.','interaction_data'=>['questions'=>[['id'=>'integer','prompt'=>'Which resource example is an integer?','choices'=>[['id'=>'crew','label'=>'crew_count = 3'],['id'=>'oxygen','label'=>'oxygen = 92.5'],['id'=>'quoted','label'=>'fuel = "100"']]],['id'=>'decimal','prompt'=>'Which resource example is numeric with a decimal point?','choices'=>[['id'=>'battery','label'=>'battery_power = 84.0'],['id'=>'text','label'=>'battery_power = "84.0"'],['id'=>'word','label'=>'battery_power = "full"']]],['id'=>'update','prompt'=>'What does a later fuel = 95 do?','choices'=>[['id'=>'replace','label'=>'It replaces the earlier value stored under fuel.'],['id'=>'print','label'=>'It displays 95 automatically.'],['id'=>'rename','label'=>'It renames fuel.']]]]],'answer_data'=>['answers'=>['integer'=>'crew','decimal'=>'battery','update'=>'replace']],'feedback'=>['correct'=>'Resource check passed. You can distinguish numeric types and explain an update.','incorrect'=>'Use quotation marks, the decimal point, and the later assignment as your clues.'],'completion_condition'=>['type'=>'correct'],'reward_label'=>'Resource check passed','theme_key'=>'check'],
        ];
        $experience=$this->createFromBlueprint($lesson,['status'=>'preview','theme_key'=>'technology-mission-control','mission_title'=>'Spacecraft Resource Console','mission_brief'=>'Store five resource amounts as numbers, observe whole and decimal values, and update two resources.','completion_title'=>'Resource Console Online','completion_message'=>'You stored five numeric resources, identified integer and decimal values, and updated two resources.','source_version'=>'technology-unit-1-lesson-5-v1'],$activities,true);
        $this->provisionTechnologyDigitalResources($lesson,'numeric_resource_reference','Spacecraft Numeric Resources Guide','Embedded text-versus-number, integer, decimal, update, and resource-console reference.'); return $experience;
    }

    private function assertTechnologyLesson(Lesson $lesson, int $sequence, string $title, array $requiredSections): void
    {
        if ($lesson->sequence !== $sequence || $lesson->title !== $title) throw ValidationException::withMessages(['lesson'=>'This Technology preview is reserved for its selected generated lesson.']);
        $sections=$lesson->allSections()->get()->keyBy('section_type'); foreach($requiredSections as $required) if(!$sections->has($required)) throw ValidationException::withMessages(['lesson'=>"The selected lesson is missing its {$required} source section."]);
    }

    private function provisionTechnologyDigitalResources(Lesson $lesson, string $asset, string $title, string $description): void
    {
        $lesson->resources()->get()->each(function($resource):void{$before=$resource->toArray();$resource->update(['availability_status'=>'not_applicable','metadata'=>[...($resource->metadata??[]),'student_experience_required'=>false,'optional_teacher_fallback'=>true,'superseded_by_digital_experience'=>true]]);if($before!==$resource->fresh()->toArray())$this->audit->record('lesson-resource.technology-external-dependency-retired',$resource,$before,$resource->fresh()->toArray());});
        $resource=$lesson->resources()->updateOrCreate(['category'=>'lesson_resource','sort_order'=>20],['category'=>'lesson_resource','resource_type'=>'interactive_reference','title'=>$title,'description'=>$description,'delivery_type'=>'embedded','availability_status'=>'needs_asset','metadata'=>['technology_foundation_asset'=>$asset,'student_experience_required'=>true,'content_origin'=>'application_created']]);
        $this->audit->record('lesson-resource.technology-experience-defined',$resource,[],$resource->toArray());$lesson->update(['estimated_preparation_minutes'=>0]);if(config('lesson-resources.automatic_fulfillment'))$this->resourceFulfillment->fulfillRequiredForLesson($lesson);
    }

    private function provisionTechnologyMissionResources(Lesson $lesson): void
    {
        $lesson->resources()->get()->each(function ($resource): void {
            $before = $resource->toArray();
            $resource->update(['availability_status' => 'not_applicable', 'metadata' => [...($resource->metadata ?? []), 'student_experience_required' => false, 'optional_teacher_fallback' => true, 'superseded_by_digital_experience' => true]]);
            $this->audit->record('lesson-resource.technology-external-dependency-retired', $resource, $before, $resource->fresh()->toArray());
        });
        $resource = $lesson->resources()->updateOrCreate(['category' => 'lesson_resource', 'sort_order' => 20], [
            'category' => 'lesson_resource', 'resource_type' => 'interactive_reference',
            'title' => 'Python Print and Statement-Order Reference',
            'description' => 'Embedded concept guide, starter code, and documented safe-preview limits for this lesson.',
            'delivery_type' => 'embedded', 'availability_status' => 'needs_asset',
            'metadata' => ['technology_foundation_asset' => 'python_print_reference', 'student_experience_required' => true, 'content_origin' => 'application_created'],
        ]);
        $this->audit->record('lesson-resource.technology-experience-defined', $resource, [], $resource->toArray());
        $lesson->update(['estimated_preparation_minutes' => 0]);
        if (config('lesson-resources.automatic_fulfillment')) $this->resourceFulfillment->fulfillRequiredForLesson($lesson);
    }

    private function provisionEarthProcessesMissionResources(Lesson $lesson): void
    {
        $definitions = [
            'Changing Landscapes Photograph Set' => ['delivery_type' => 'viewable', 'asset' => 'coastal_change'],
            'Earth Process Sorting Cards' => ['delivery_type' => 'embedded', 'asset' => 'process_cards'],
            'Earth Processes Systems Map' => ['delivery_type' => 'interactive', 'asset' => 'systems_map'],
        ];
        foreach ($lesson->resources()->where('category', 'lesson_resource')->get() as $resource) {
            $definition = $definitions[$resource->title] ?? null;
            if (! $definition) continue;
            $before = $resource->toArray();
            $resource->update([
                'delivery_type' => $definition['delivery_type'],
                'availability_status' => 'needs_asset',
                'metadata' => [...($resource->metadata ?? []), 'science_foundation_asset' => $definition['asset'], 'student_experience_required' => true],
            ]);
            $this->audit->record('lesson-resource.science-mission-defined', $resource, $before, $resource->fresh()->toArray());
        }
        if (config('lesson-resources.automatic_fulfillment')) {
            $this->resourceFulfillment->fulfillRequiredForLesson($lesson);
        }
    }

    private function provisionElarActiveReadingResources(Lesson $lesson): void
    {
        $definitions = [
            ['resource_type' => 'passage', 'title' => 'Nia’s Water-Saver Prototype', 'description' => 'The complete application-created five-paragraph instructional passage embedded in the student preview.', 'asset' => 'active_reading_passage', 'sort_order' => 1],
            ['resource_type' => 'interactive_reference', 'title' => 'Active Reading and Syllable Toolkit', 'description' => 'Embedded vocabulary, Stop–Name–Choose–Check routine, and four syllable-pattern reference cards.', 'asset' => 'active_reading_toolkit', 'sort_order' => 2],
        ];
        $existing = $lesson->resources()->where('category', 'lesson_resource')->get();
        foreach ($existing as $resource) {
            $before = $resource->toArray();
            $resource->update([
                'availability_status' => 'not_applicable',
                'metadata' => [...($resource->metadata ?? []), 'student_experience_required' => false, 'superseded_by_digital_experience' => true],
            ]);
            $this->audit->record('lesson-resource.elar-print-fallback-retired', $resource, $before, $resource->fresh()->toArray());
        }
        foreach ($definitions as $definition) {
            $resource = $lesson->resources()->updateOrCreate(['category' => 'lesson_resource', 'sort_order' => $definition['sort_order'] + 10], [
                'category' => 'lesson_resource', 'resource_type' => $definition['resource_type'],
                'title' => $definition['title'], 'description' => $definition['description'],
                'delivery_type' => 'embedded', 'availability_status' => 'needs_asset',
                'metadata' => ['elar_foundation_asset' => $definition['asset'], 'student_experience_required' => true, 'content_origin' => 'application_created'],
            ]);
            $this->audit->record('lesson-resource.elar-experience-defined', $resource, [], $resource->toArray());
        }
        $lesson->resources()->where('category', 'student_supply')->get()->each(function ($resource): void {
            $before = $resource->toArray();
            $resource->update(['metadata' => [...($resource->metadata ?? []), 'student_experience_required' => false, 'optional_teacher_fallback' => true]]);
            $this->audit->record('lesson-resource.elar-supply-optional', $resource, $before, $resource->fresh()->toArray());
        });
        $lesson->update(['estimated_preparation_minutes' => 0]);
        if (config('lesson-resources.automatic_fulfillment')) {
            $this->resourceFulfillment->fulfillRequiredForLesson($lesson);
        }
    }

    private function provisionElarDigitalResources(Lesson $lesson, string $guideAsset): void
    {
        $guide = $guideAsset === 'central_idea_summary_guide'
            ? ['title' => 'Central Idea and Summary Guide', 'description' => 'Embedded topic, central-idea, key-detail, importance-test, and objective-summary instruction.']
            : ['title' => 'Point of View and Inference Guide', 'description' => 'Embedded point-of-view, fact, inference, text-evidence, and reasoning instruction.'];
        $definitions = [
            ['resource_type' => 'passage', 'title' => 'Mara and the Folding Cart', 'description' => 'The complete numbered Learning-App original instructional narrative embedded beside the reading work.', 'asset' => 'mara_folding_cart_passage', 'sort_order' => 11],
            ['resource_type' => 'interactive_reference', 'title' => $guide['title'], 'description' => $guide['description'], 'asset' => $guideAsset, 'sort_order' => 12],
        ];
        $lesson->resources()->where('category', 'lesson_resource')->where('sort_order', '<', 10)->get()->each(function ($resource): void {
            $before = $resource->toArray();
            $resource->update([
                'availability_status' => 'not_applicable',
                'metadata' => [...($resource->metadata ?? []), 'student_experience_required' => false, 'superseded_by_digital_experience' => true],
            ]);
            $this->audit->record('lesson-resource.elar-print-fallback-retired', $resource, $before, $resource->fresh()->toArray());
        });
        foreach ($definitions as $definition) {
            $resource = $lesson->resources()->updateOrCreate(['category' => 'lesson_resource', 'sort_order' => $definition['sort_order']], [
                'category' => 'lesson_resource', 'resource_type' => $definition['resource_type'],
                'title' => $definition['title'], 'description' => $definition['description'],
                'delivery_type' => 'embedded', 'availability_status' => 'needs_asset',
                'metadata' => ['elar_foundation_asset' => $definition['asset'], 'student_experience_required' => true, 'content_origin' => 'application_created'],
            ]);
            $this->audit->record('lesson-resource.elar-experience-defined', $resource, [], $resource->toArray());
        }
        $lesson->resources()->where('category', 'student_supply')->get()->each(function ($resource): void {
            $before = $resource->toArray();
            $resource->update(['metadata' => [...($resource->metadata ?? []), 'student_experience_required' => false, 'optional_teacher_fallback' => true]]);
            $this->audit->record('lesson-resource.elar-supply-optional', $resource, $before, $resource->fresh()->toArray());
        });
        $lesson->update(['estimated_preparation_minutes' => 0]);
        if (config('lesson-resources.automatic_fulfillment')) $this->resourceFulfillment->fulfillRequiredForLesson($lesson);
    }

    private function provisionSettlementMissionResources(Lesson $lesson): void
    {
        $resources = [
            ['category' => 'lesson_resource', 'resource_type' => 'interactive_us_map', 'title' => 'Interactive United States Settlement Map', 'description' => 'Authoritative state geometry used to compare population-density evidence.', 'delivery_type' => 'interactive', 'availability_status' => 'needs_asset', 'sort_order' => 1, 'metadata' => ['supported_modes' => ['settlement_data']]],
            ['category' => 'lesson_resource', 'resource_type' => 'us_population_density_data', 'title' => '2020 Population Density by State', 'description' => '2020 Census population divided by Census Gazetteer state land area.', 'delivery_type' => 'embedded', 'availability_status' => 'needs_asset', 'sort_order' => 2, 'metadata' => ['student_experience_required' => true]],
            ['category' => 'lesson_resource', 'resource_type' => 'physical_us_map', 'title' => 'United States Physical Relief Map', 'description' => 'A USGS topography image for comparing settlement data with physical geography.', 'delivery_type' => 'viewable', 'availability_status' => 'needs_asset', 'sort_order' => 3, 'metadata' => ['student_experience_required' => true]],
        ];
        foreach ($resources as $data) {
            $resource = $lesson->resources()->firstOrCreate(['category' => $data['category'], 'sort_order' => $data['sort_order']], $data);
            if ($resource->wasRecentlyCreated) {
                $this->audit->record('lesson-resource.settlement-mission-defined', $resource, [], $resource->toArray());
            }
        }
        if (config('lesson-resources.automatic_fulfillment')) {
            $this->resourceFulfillment->fulfillRequiredForLesson($lesson);
        }
    }

    private function provisionMapMissionResourceRequirements(Lesson $lesson): void
    {
        $intro = $lesson->experience?->activities()->where('sequence', 1)->first();
        if ($intro && array_key_exists('materials', $intro->interaction_data ?? [])) {
            $before = $intro->toArray();
            $intro->update(['interaction_data' => ['student_supplies' => ['Pencil and eraser', 'Ruler and colored pencils', 'Paper']]]);
            $this->audit->record('lesson-activity.resources-separated', $intro, $before, $intro->fresh()->toArray());
        }
        $resources = [
            ['category' => 'student_supply', 'resource_type' => 'supply', 'title' => 'Pencil and eraser', 'description' => null, 'delivery_type' => 'physical', 'availability_status' => 'not_applicable', 'sort_order' => 1],
            ['category' => 'student_supply', 'resource_type' => 'supply', 'title' => 'Ruler and colored pencils', 'description' => null, 'delivery_type' => 'physical', 'availability_status' => 'not_applicable', 'sort_order' => 2],
            ['category' => 'student_supply', 'resource_type' => 'supply', 'title' => 'Paper', 'description' => 'Two sheets for the map-and-timeline toolkit.', 'delivery_type' => 'physical', 'availability_status' => 'not_applicable', 'sort_order' => 3],
            ['category' => 'lesson_resource', 'resource_type' => 'blank_map', 'title' => 'Blank U.S. Outline Map', 'description' => 'A clean printable United States outline with enough space for labels, symbols, and a legend.', 'delivery_type' => 'printable', 'availability_status' => 'needs_asset', 'sort_order' => 1],
            ['category' => 'lesson_resource', 'resource_type' => 'reference_map', 'title' => 'Labeled U.S. Reference Map', 'description' => 'A viewable physical or political reference map with title, orientation, legend, scale, labels, and symbols.', 'delivery_type' => 'viewable', 'availability_status' => 'needs_asset', 'sort_order' => 2],
            ['category' => 'lesson_resource', 'resource_type' => 'interactive_us_map', 'title' => 'Explore the United States', 'description' => 'An interactive state-boundary map for exploring state names and learning how map tools communicate.', 'delivery_type' => 'interactive', 'availability_status' => 'needs_asset', 'sort_order' => 3, 'metadata' => ['supported_modes' => ['explore']]],
        ];
        foreach ($resources as $data) {
            $resource = $lesson->resources()->firstOrCreate(['category' => $data['category'], 'sort_order' => $data['sort_order']], $data);
            if ($resource->wasRecentlyCreated) {
                $this->audit->record('lesson-resource.prototype-defined', $resource, [], $resource->toArray());
            }
        }
        if (config('lesson-resources.automatic_fulfillment')) {
            $this->resourceFulfillment->fulfillRequiredForLesson($lesson);
        }
    }

    public function progress(LessonExperience $experience, StudentEnrollment $enrollment, bool $preview, ?User $previewer = null): StudentLessonProgress
    {
        $this->assertEnrollmentOwnsExperience($experience, $enrollment);
        $first = $experience->activities()->firstOrFail();
        $progress = StudentLessonProgress::firstOrCreate(
            ['lesson_experience_id' => $experience->id, 'student_enrollment_id' => $enrollment->id, 'is_preview' => $preview],
            ['previewed_by_user_id' => $previewer?->id, 'current_activity_id' => $first->id, 'status' => 'in_progress', 'started_at' => now(), 'last_activity_at' => now()]
        );
        if ($progress->wasRecentlyCreated) {
            $this->audit->record('student-lesson-progress.started', $progress, [], $progress->toArray());
        }
        return $progress;
    }

    public function respond(StudentLessonProgress $progress, LessonActivity $activity, array $response): StudentLessonProgress
    {
        if ($activity->lesson_experience_id !== $progress->lesson_experience_id) {
            abort(404);
        }
        [$status, $correct, $feedback, $review] = $this->evaluate($activity, $response);
        $record = StudentActivityResponse::updateOrCreate(
            ['student_lesson_progress_id' => $progress->id, 'lesson_activity_id' => $activity->id],
            ['response' => $response, 'status' => $status, 'is_correct' => $correct, 'feedback' => $feedback, 'teacher_review_status' => $review, 'completed_at' => in_array($status, ['completed', 'submitted'], true) ? now() : null]
        );
        $this->audit->record('student-activity-response.saved', $record, [], $record->toArray());

        $completedIds = $progress->responses()->whereIn('status', ['completed', 'submitted'])->pluck('lesson_activity_id');
        if (in_array($record->status, ['completed', 'submitted'], true)) {
            $completedIds->push($record->lesson_activity_id);
        }
        $completedIds = $completedIds->unique();
        $next = $progress->experience->activities()->whereNotIn('id', $completedIds)->first();
        $before = $progress->toArray();
        $progress->update([
            'current_activity_id' => $next?->id ?? $activity->id,
            'status' => $next ? 'in_progress' : 'completed',
            'last_activity_at' => now(),
            'completed_at' => $next ? null : ($progress->completed_at ?? now()),
        ]);
        $this->audit->record($next ? 'student-lesson-progress.updated' : 'student-lesson-progress.completed', $progress, $before, $progress->fresh()->toArray());
        return $progress->fresh(['responses', 'experience.activities']);
    }

    public function saveDraft(StudentLessonProgress $progress, LessonActivity $activity, array $response): StudentActivityResponse
    {
        $mapMode = $activity->interaction_data['map_mode'] ?? null;
        abort_unless($activity->lesson_experience_id === $progress->lesson_experience_id
            && $activity->activity_type === 'project'
            && (in_array($mapMode, ['builder', 'region_builder'], true)
                || isset($activity->interaction_data['analysis_builder'])
                || isset($activity->interaction_data['systems_map_builder'])
                || isset($activity->interaction_data['science_work_builder'])
                || isset($activity->interaction_data['math_work_builder'])
                || isset($activity->interaction_data['elar_response_builder'])
                || isset($activity->interaction_data['technology_code_builder'])
                || isset($activity->interaction_data['language_passport_builder'])
                || isset($activity->interaction_data['language_work_builder'])), 404);
        if (isset($activity->interaction_data['language_work_builder'])) {
            return $this->saveLanguageWorkDraft($progress, $activity, $response);
        }
        if (isset($activity->interaction_data['language_passport_builder'])) {
            return $this->saveLanguagePassportDraft($progress, $activity, $response);
        }
        if (isset($activity->interaction_data['technology_code_builder'])) {
            return $this->saveTechnologyCodeDraft($progress, $activity, $response);
        }
        if (isset($activity->interaction_data['elar_response_builder'])) {
            return $this->saveElarResponseDraft($progress, $activity, $response);
        }
        if (isset($activity->interaction_data['math_work_builder'])) {
            return $this->saveMathWorkDraft($progress, $activity, $response);
        }
        if (isset($activity->interaction_data['science_work_builder'])) {
            return $this->saveScienceWorkDraft($progress, $activity, $response);
        }
        if (isset($activity->interaction_data['systems_map_builder'])) {
            return $this->saveSystemsMapDraft($progress, $activity, $response);
        }
        if (isset($activity->interaction_data['analysis_builder'])) {
            return $this->saveEvidenceAnalysisDraft($progress, $activity, $response);
        }
        if ($mapMode === 'region_builder') {
            return $this->saveRegionMapDraft($progress, $activity, $response);
        }
        $invalid = fn (string $message) => throw ValidationException::withMessages(['response' => $message]);
        $map = $response['map'] ?? null;
        $reflections = $response['reflections'] ?? null;
        is_array($map) && is_array($reflections) || $invalid('The digital map draft is malformed.');
        $map['title'] = $map['title'] ?? '';
        $map['show_orientation'] = $map['show_orientation'] ?? false;
        foreach ($map['features'] ?? [] as $index => $feature) {
            if (is_array($feature)) {
                $map['features'][$index] = [
                    'state_fips' => $feature['state_fips'] ?? '',
                    'marker_key' => $feature['marker_key'] ?? '',
                    'legend_label' => $feature['legend_label'] ?? '',
                ];
            }
        }
        foreach ($reflections as $key => $value) $reflections[$key] = $value ?? '';
        $response['map'] = $map;
        $response['reflections'] = $reflections;
        is_string($map['title'] ?? null) && mb_strlen($map['title']) <= 120 || $invalid('The map title is invalid.');
        is_bool($map['show_orientation'] ?? null) || $invalid('The orientation setting is invalid.');
        is_array($map['features'] ?? null) && count($map['features']) <= 10 || $invalid('The map has too many features.');
        $allowedMarkers = $activity->interaction_data['map_builder']['allowed_marker_keys'] ?? [];
        foreach ($map['features'] as $feature) {
            is_array($feature) || $invalid('A map feature is malformed.');
            $fips = $feature['state_fips'] ?? null;
            ($fips === '' || (is_string($fips) && in_array($fips, CensusStateGeometryResourceProvider::STATE_FIPS, true)))
                || $invalid('A map place is invalid.');
            $marker = $feature['marker_key'] ?? null;
            ($marker === '' || (is_string($marker) && in_array($marker, $allowedMarkers, true)))
                || $invalid('A map marker is invalid.');
            is_string($feature['legend_label'] ?? null) && mb_strlen($feature['legend_label']) <= 100
                || $invalid('A legend entry is invalid.');
        }
        $reflectionIds = collect($activity->interaction_data['reflection_fields'] ?? [])->pluck('id')->all();
        collect($reflections)->keys()->diff($reflectionIds)->isEmpty() || $invalid('The map draft contains an unknown reflection.');
        collect($reflections)->every(fn ($value) => is_string($value) && mb_strlen($value) <= 1000)
            || $invalid('A map reflection is invalid.');

        return DB::transaction(function () use ($progress, $activity, $response): StudentActivityResponse {
            $existing = $progress->responses()->where('lesson_activity_id', $activity->id)->first();
            if ($existing && in_array($existing->status, ['completed', 'submitted'], true)) {
                throw ValidationException::withMessages(['response' => 'Completed work cannot be changed.']);
            }
            $record = StudentActivityResponse::updateOrCreate(
                ['student_lesson_progress_id' => $progress->id, 'lesson_activity_id' => $activity->id],
                ['response' => $response, 'status' => 'in_progress', 'is_correct' => null, 'feedback' => null, 'teacher_review_status' => 'not_required', 'completed_at' => null]
            );
            $progress->update(['last_activity_at' => now()]);
            $this->audit->record('student-activity-response.draft-saved', $record, [], $record->toArray());
            return $record->fresh();
        });
    }

    private function saveElarResponseDraft(StudentLessonProgress $progress, LessonActivity $activity, array $response): StudentActivityResponse
    {
        $invalid = fn (string $message) => throw ValidationException::withMessages(['response' => $message]);
        $work = $response['elar_work'] ?? null;
        is_array($work) || $invalid('The ELAR response draft is malformed.');
        $fields = collect($activity->interaction_data['elar_response_builder']['fields'] ?? [])->keyBy('id');
        collect($work)->keys()->diff($fields->keys())->isEmpty() || $invalid('The ELAR response contains an unknown field.');
        foreach ($work as $id => $value) {
            is_string($value) && mb_strlen($value) <= 2000 || $invalid('An ELAR response field is invalid.');
        }

        return DB::transaction(function () use ($progress, $activity, $response): StudentActivityResponse {
            $existing = $progress->responses()->where('lesson_activity_id', $activity->id)->first();
            if ($existing && in_array($existing->status, ['completed', 'submitted'], true)) {
                throw ValidationException::withMessages(['response' => 'Completed work cannot be changed.']);
            }
            $record = StudentActivityResponse::updateOrCreate(
                ['student_lesson_progress_id' => $progress->id, 'lesson_activity_id' => $activity->id],
                ['response' => $response, 'status' => 'in_progress', 'is_correct' => null, 'feedback' => null, 'teacher_review_status' => 'not_required', 'completed_at' => null]
            );
            $progress->update(['last_activity_at' => now()]);
            $this->audit->record('student-activity-response.draft-saved', $record, [], $record->toArray());
            return $record->fresh();
        });
    }

    private function saveLanguagePassportDraft(StudentLessonProgress $progress, LessonActivity $activity, array $response): StudentActivityResponse
    {
        $invalid = fn (string $message) => throw ValidationException::withMessages(['response' => $message]);
        $work = $response['language_work'] ?? null;
        is_array($work) || $invalid('The passport-card draft is malformed.');
        collect($work)->keys()->diff(['greetings', 'farewells', 'practice_line', 'reason', 'speaking_self_check'])->isEmpty() || $invalid('The passport-card draft contains an unknown field.');
        foreach (['greetings', 'farewells'] as $field) {
            is_array($work[$field] ?? null) && count($work[$field]) <= 5 && collect($work[$field])->every(fn ($value) => is_string($value)) || $invalid('A passport phrase selection is invalid.');
            $work[$field] = array_values(array_unique($work[$field]));
        }
        foreach (['practice_line' => 240, 'reason' => 500] as $field => $limit) {
            is_string($work[$field] ?? null) && mb_strlen($work[$field]) <= $limit || $invalid('A passport writing field is invalid.');
        }
        is_bool($work['speaking_self_check'] ?? null) || $invalid('The speaking self-check is invalid.');
        $response['language_work'] = $work;
        return DB::transaction(function () use ($progress, $activity, $response): StudentActivityResponse {
            $existing = $progress->responses()->where('lesson_activity_id', $activity->id)->first();
            if ($existing && in_array($existing->status, ['completed', 'submitted'], true)) throw ValidationException::withMessages(['response' => 'Completed work cannot be changed.']);
            $record = StudentActivityResponse::updateOrCreate(
                ['student_lesson_progress_id' => $progress->id, 'lesson_activity_id' => $activity->id],
                ['response' => $response, 'status' => 'in_progress', 'is_correct' => null, 'feedback' => null, 'teacher_review_status' => 'not_required', 'completed_at' => null]
            );
            $progress->update(['last_activity_at' => now()]);
            $this->audit->record('student-activity-response.draft-saved', $record, [], $record->toArray());
            return $record->fresh();
        });
    }

    private function saveLanguageWorkDraft(StudentLessonProgress $progress, LessonActivity $activity, array $response): StudentActivityResponse
    {
        $invalid = fn (string $message) => throw ValidationException::withMessages(['response' => $message]);
        $work = $response['language_practice'] ?? null;
        is_array($work) || $invalid('The language-practice draft is malformed.');
        $fields = collect($activity->interaction_data['language_work_builder']['fields'] ?? [])->keyBy('id');
        collect($work)->keys()->diff($fields->keys())->isEmpty() || $invalid('The language-practice draft contains an unknown field.');
        foreach ($work as $id => $value) {
            $field = $fields->get($id);
            $field || $invalid('The language-practice draft contains an unknown field.');
            if (($field['control'] ?? 'text') === 'multi_select') {
                is_array($value) && count($value) <= 20 && collect($value)->every(fn ($item) => is_string($item)) || $invalid('A language-practice selection is invalid.');
            } elseif (($field['control'] ?? 'text') === 'checkbox') {
                is_bool($value) || $invalid('A language-practice self-check is invalid.');
            } else {
                is_string($value) && mb_strlen($value) <= (int) ($field['maximum_length'] ?? 1000) || $invalid('A language-practice writing field is invalid.');
            }
        }
        return DB::transaction(function () use ($progress, $activity, $response): StudentActivityResponse {
            $existing = $progress->responses()->where('lesson_activity_id', $activity->id)->first();
            if ($existing && in_array($existing->status, ['completed', 'submitted'], true)) throw ValidationException::withMessages(['response' => 'Completed work cannot be changed.']);
            $record = StudentActivityResponse::updateOrCreate(
                ['student_lesson_progress_id' => $progress->id, 'lesson_activity_id' => $activity->id],
                ['response' => $response, 'status' => 'in_progress', 'is_correct' => null, 'feedback' => null, 'teacher_review_status' => 'not_required', 'completed_at' => null]
            );
            $progress->update(['last_activity_at' => now()]);
            $this->audit->record('student-activity-response.draft-saved', $record, [], $record->toArray());
            return $record->fresh();
        });
    }

    private function saveTechnologyCodeDraft(StudentLessonProgress $progress, LessonActivity $activity, array $response): StudentActivityResponse
    {
        $invalid = fn (string $message) => throw ValidationException::withMessages(['response' => $message]);
        $work = $response['technology_work'] ?? null;
        is_array($work) || $invalid('The code draft is malformed.');
        collect($work)->keys()->diff(['source', 'prediction', 'reflection', 'inputs'])->isEmpty() || $invalid('The code draft contains an unknown field.');
        $normalized = [];
        foreach (['source' => 6000, 'prediction' => 500, 'reflection' => 1200] as $field => $limit) {
            $value = $work[$field] ?? '';
            is_string($value) && mb_strlen($value) <= $limit || $invalid('A code-work field is invalid.');
            $normalized[$field] = $value;
        }
        $inputFields = collect($activity->interaction_data['technology_code_builder']['input_fields'] ?? [])->keyBy('id');
        if ($inputFields->isNotEmpty() || array_key_exists('inputs', $work)) {
            $inputs = $work['inputs'] ?? [];
            is_array($inputs) && collect($inputs)->keys()->diff($inputFields->keys())->isEmpty() || $invalid('The simulated input draft is malformed.');
            $normalized['inputs'] = [];
            foreach ($inputFields as $id => $field) {
                $value = $inputs[$id] ?? '';
                is_string($value) && mb_strlen($value) <= 200 || $invalid('A simulated input response is invalid.');
                $normalized['inputs'][$id] = $value;
            }
        }
        $response['technology_work'] = $normalized;
        return DB::transaction(function () use ($progress, $activity, $response): StudentActivityResponse {
            $existing = $progress->responses()->where('lesson_activity_id', $activity->id)->first();
            if ($existing && in_array($existing->status, ['completed', 'submitted'], true)) throw ValidationException::withMessages(['response' => 'Completed work cannot be changed.']);
            $record = StudentActivityResponse::updateOrCreate(
                ['student_lesson_progress_id' => $progress->id, 'lesson_activity_id' => $activity->id],
                ['response' => $response, 'status' => 'in_progress', 'is_correct' => null, 'feedback' => null, 'teacher_review_status' => 'not_required', 'completed_at' => null]
            );
            $progress->update(['last_activity_at' => now()]);
            $this->audit->record('student-activity-response.draft-saved', $record, [], $record->toArray());
            return $record->fresh();
        });
    }

    private function saveSystemsMapDraft(StudentLessonProgress $progress, LessonActivity $activity, array $response): StudentActivityResponse
    {
        $invalid = fn (string $message) => throw ValidationException::withMessages(['response' => $message]);
        $map = $response['systems_map'] ?? null;
        is_array($map) || $invalid('The systems-map draft is malformed.');
        $allowedTerms = $activity->interaction_data['systems_map_builder']['terms'] ?? [];
        $allowedRelationships = $activity->interaction_data['systems_map_builder']['relationships'] ?? [];
        $allowedConnections = collect($activity->interaction_data['systems_map_builder']['allowed_connections'] ?? [])->map(fn ($connection) => implode('|', [$connection['from'], $connection['relationship'], $connection['to']]))->all();
        $terms = array_values($map['terms'] ?? []);
        $connections = array_values($map['connections'] ?? []);
        count($terms) <= 10 && collect($terms)->every(fn ($term) => is_string($term) && in_array($term, $allowedTerms, true))
            || $invalid('The systems map contains an invalid term.');
        count($terms) === count(array_unique($terms)) || $invalid('Choose each systems-map term only once.');
        count($connections) <= 10 || $invalid('The systems map contains too many connections.');
        foreach ($connections as $index => $connection) {
            is_array($connection) || $invalid('A systems-map connection is malformed.');
            $connections[$index] = ['from' => $connection['from'] ?? '', 'relationship' => $connection['relationship'] ?? '', 'to' => $connection['to'] ?? ''];
            foreach (['from', 'to'] as $endpoint) {
                ($connections[$index][$endpoint] === '' || in_array($connections[$index][$endpoint], $terms, true))
                    || $invalid('Every connection endpoint must use one of the selected terms.');
            }
            ($connections[$index]['relationship'] === '' || in_array($connections[$index]['relationship'], $allowedRelationships, true))
                || $invalid('A systems-map relationship is invalid.');
            if (! in_array('', $connections[$index], true) && $allowedConnections !== []) {
                in_array(implode('|', $connections[$index]), $allowedConnections, true)
                    || $invalid('Choose a scientifically supported systems-map connection.');
            }
        }
        $question = $map['question'] ?? '';
        is_string($question) && mb_strlen($question) <= 500 || $invalid('The investigation question is invalid.');
        $response['systems_map'] = ['terms' => $terms, 'connections' => $connections, 'question' => $question];

        return DB::transaction(function () use ($progress, $activity, $response): StudentActivityResponse {
            $existing = $progress->responses()->where('lesson_activity_id', $activity->id)->first();
            if ($existing && in_array($existing->status, ['completed', 'submitted'], true)) {
                throw ValidationException::withMessages(['response' => 'Completed work cannot be changed.']);
            }
            $record = StudentActivityResponse::updateOrCreate(
                ['student_lesson_progress_id' => $progress->id, 'lesson_activity_id' => $activity->id],
                ['response' => $response, 'status' => 'in_progress', 'is_correct' => null, 'feedback' => null, 'teacher_review_status' => 'not_required', 'completed_at' => null]
            );
            $progress->update(['last_activity_at' => now()]);
            $this->audit->record('student-activity-response.draft-saved', $record, [], $record->toArray());
            return $record->fresh();
        });
    }

    private function saveScienceWorkDraft(StudentLessonProgress $progress, LessonActivity $activity, array $response): StudentActivityResponse
    {
        $invalid = fn (string $message) => throw ValidationException::withMessages(['response' => $message]);
        $work = $response['science_work'] ?? null;
        is_array($work) || $invalid('The science-work draft is malformed.');
        $fields = collect($activity->interaction_data['science_work_builder']['sections'] ?? [])->flatMap(fn ($section) => $section['fields'] ?? []);
        $allowedIds = $fields->pluck('id')->all();
        collect($work)->keys()->diff($allowedIds)->isEmpty() || $invalid('The science-work draft contains an unknown field.');
        $normalized = [];
        foreach ($fields as $field) {
            $value = $work[$field['id']] ?? '';
            is_string($value) && mb_strlen($value) <= 2000 || $invalid('A science-work response is invalid.');
            if (($field['control'] ?? 'textarea') === 'select' && $value !== '') {
                in_array($value, collect($field['choices'] ?? [])->pluck('id')->all(), true) || $invalid('A science-work choice is invalid.');
            }
            $normalized[$field['id']] = $value;
        }
        $response['science_work'] = $normalized;

        return DB::transaction(function () use ($progress, $activity, $response): StudentActivityResponse {
            $existing = $progress->responses()->where('lesson_activity_id', $activity->id)->first();
            if ($existing && in_array($existing->status, ['completed', 'submitted'], true)) {
                throw ValidationException::withMessages(['response' => 'Completed work cannot be changed.']);
            }
            $record = StudentActivityResponse::updateOrCreate(
                ['student_lesson_progress_id' => $progress->id, 'lesson_activity_id' => $activity->id],
                ['response' => $response, 'status' => 'in_progress', 'is_correct' => null, 'feedback' => null, 'teacher_review_status' => 'not_required', 'completed_at' => null]
            );
            $progress->update(['last_activity_at' => now()]);
            $this->audit->record('student-activity-response.draft-saved', $record, [], $record->toArray());
            return $record->fresh();
        });
    }

    private function saveMathWorkDraft(StudentLessonProgress $progress, LessonActivity $activity, array $response): StudentActivityResponse
    {
        $invalid = fn (string $message) => throw ValidationException::withMessages(['response' => $message]);
        $work = $response['math_work'] ?? null;
        is_array($work) || $invalid('The Math organizer draft is malformed.');
        $fields = collect($activity->interaction_data['math_work_builder']['sections'] ?? [])->flatMap(fn ($section) => $section['fields'] ?? []);
        $allowedIds = $fields->pluck('id')->all();
        collect($work)->keys()->diff($allowedIds)->isEmpty() || $invalid('The Math organizer contains an unknown field.');
        $normalized = [];
        foreach ($fields as $field) {
            $value = $work[$field['id']] ?? '';
            (is_string($value) || is_int($value) || is_float($value)) || $invalid('A Math organizer response is invalid.');
            $value = (string) $value;
            mb_strlen($value) <= 2000 || $invalid('A Math organizer response is too long.');
            if (($field['control'] ?? 'textarea') === 'select' && $value !== '') {
                in_array($value, collect($field['choices'] ?? [])->pluck('id')->all(), true) || $invalid('A Math organizer choice is invalid.');
            }
            if (($field['control'] ?? null) === 'number' && $value !== '') {
                preg_match('/^-?\d+(?:\.\d+)?$/', $value) || $invalid('A numeric Math response is invalid.');
            }
            $normalized[$field['id']] = $value;
        }
        $response['math_work'] = $normalized;
        return DB::transaction(function () use ($progress, $activity, $response): StudentActivityResponse {
            $existing = $progress->responses()->where('lesson_activity_id', $activity->id)->first();
            if ($existing && in_array($existing->status, ['completed', 'submitted'], true)) throw ValidationException::withMessages(['response' => 'Completed work cannot be changed.']);
            $record = StudentActivityResponse::updateOrCreate(
                ['student_lesson_progress_id' => $progress->id, 'lesson_activity_id' => $activity->id],
                ['response' => $response, 'status' => 'in_progress', 'is_correct' => null, 'feedback' => null, 'teacher_review_status' => 'not_required', 'completed_at' => null]
            );
            $progress->update(['last_activity_at' => now()]);
            $this->audit->record('student-activity-response.draft-saved', $record, [], $record->toArray());
            return $record->fresh();
        });
    }

    private function saveEvidenceAnalysisDraft(StudentLessonProgress $progress, LessonActivity $activity, array $response): StudentActivityResponse
    {
        $invalid = fn (string $message) => throw ValidationException::withMessages(['response' => $message]);
        $analysis = $response['analysis'] ?? null;
        is_array($analysis) || $invalid('The evidence organizer draft is malformed.');
        $observations = $analysis['observations'] ?? null;
        $patterns = $analysis['patterns'] ?? null;
        is_array($observations) && count($observations) <= 10 || $invalid('The evidence organizer has too many observations.');
        is_array($patterns) && count($patterns) <= 10 || $invalid('The evidence organizer has too many patterns.');
        $allowedFips = collect($activity->interaction_data['analysis_builder']['location_choices'] ?? [])->pluck('state_fips')->all();
        foreach ($observations as $index => $observation) {
            is_array($observation) || $invalid('An observation is malformed.');
            $observations[$index] = ['state_fips' => $observation['state_fips'] ?? '', 'statement' => $observation['statement'] ?? ''];
            ($observations[$index]['state_fips'] === '' || in_array($observations[$index]['state_fips'], $allowedFips, true)) || $invalid('An observation location is invalid.');
            is_string($observations[$index]['statement']) && mb_strlen($observations[$index]['statement']) <= 500 || $invalid('An observation is invalid.');
        }
        foreach ($patterns as $index => $pattern) {
            is_string($pattern) && mb_strlen($pattern) <= 500 || $invalid('A pattern is invalid.');
            $patterns[$index] = $pattern;
        }
        foreach (['inference', 'limitation'] as $field) {
            $analysis[$field] = $analysis[$field] ?? '';
            is_string($analysis[$field]) && mb_strlen($analysis[$field]) <= 700 || $invalid("The {$field} is invalid.");
        }
        $response['analysis'] = ['observations' => array_values($observations), 'patterns' => array_values($patterns), 'inference' => $analysis['inference'], 'limitation' => $analysis['limitation']];

        return DB::transaction(function () use ($progress, $activity, $response): StudentActivityResponse {
            $existing = $progress->responses()->where('lesson_activity_id', $activity->id)->first();
            if ($existing && in_array($existing->status, ['completed', 'submitted'], true)) {
                throw ValidationException::withMessages(['response' => 'Completed work cannot be changed.']);
            }
            $record = StudentActivityResponse::updateOrCreate(
                ['student_lesson_progress_id' => $progress->id, 'lesson_activity_id' => $activity->id],
                ['response' => $response, 'status' => 'in_progress', 'is_correct' => null, 'feedback' => null, 'teacher_review_status' => 'not_required', 'completed_at' => null]
            );
            $progress->update(['last_activity_at' => now()]);
            $this->audit->record('student-activity-response.draft-saved', $record, [], $record->toArray());
            return $record->fresh();
        });
    }

    private function saveRegionMapDraft(StudentLessonProgress $progress, LessonActivity $activity, array $response): StudentActivityResponse
    {
        $invalid = fn (string $message) => throw ValidationException::withMessages(['response' => $message]);
        $map = $response['map'] ?? null;
        $reflections = $response['reflections'] ?? null;
        is_array($map) && is_array($reflections) || $invalid('The regional map draft is malformed.');
        $map['title'] = $map['title'] ?? '';
        $map['criterion'] = $map['criterion'] ?? '';
        is_string($map['title']) && mb_strlen($map['title']) <= 120 || $invalid('The regional map title is invalid.');
        is_string($map['criterion']) && mb_strlen($map['criterion']) <= 200 || $invalid('The regional criterion is invalid.');
        is_array($map['regions'] ?? null) && count($map['regions']) <= 6 || $invalid('The regional map has too many regions.');
        $allowedColors = $activity->interaction_data['region_builder']['color_keys'] ?? [];
        foreach ($map['regions'] as $index => $region) {
            is_array($region) || $invalid('A map region is malformed.');
            $map['regions'][$index] = [
                'id' => is_string($region['id'] ?? null) ? $region['id'] : "region_".($index + 1),
                'name' => $region['name'] ?? '', 'color_key' => $region['color_key'] ?? '',
                'state_fips' => array_values($region['state_fips'] ?? []),
            ];
            is_string($map['regions'][$index]['name']) && mb_strlen($map['regions'][$index]['name']) <= 100
                || $invalid('A region name is invalid.');
            ($map['regions'][$index]['color_key'] === '' || in_array($map['regions'][$index]['color_key'], $allowedColors, true))
                || $invalid('A region color is invalid.');
            count($map['regions'][$index]['state_fips']) <= 10 || $invalid('A region contains too many states.');
            foreach ($map['regions'][$index]['state_fips'] as $fips) {
                ($fips === '' || (is_string($fips) && in_array($fips, CensusStateGeometryResourceProvider::STATE_FIPS, true)))
                    || $invalid('A regional map place is invalid.');
            }
        }
        foreach ($reflections as $key => $value) $reflections[$key] = $value ?? '';
        $reflectionIds = collect($activity->interaction_data['reflection_fields'] ?? [])->pluck('id')->all();
        collect($reflections)->keys()->diff($reflectionIds)->isEmpty() || $invalid('The regional draft contains an unknown reflection.');
        collect($reflections)->every(fn ($value) => is_string($value) && mb_strlen($value) <= 1000)
            || $invalid('A regional reflection is invalid.');
        $response['map'] = $map;
        $response['reflections'] = $reflections;

        return DB::transaction(function () use ($progress, $activity, $response): StudentActivityResponse {
            $existing = $progress->responses()->where('lesson_activity_id', $activity->id)->first();
            if ($existing && in_array($existing->status, ['completed', 'submitted'], true)) {
                throw ValidationException::withMessages(['response' => 'Completed work cannot be changed.']);
            }
            $record = StudentActivityResponse::updateOrCreate(
                ['student_lesson_progress_id' => $progress->id, 'lesson_activity_id' => $activity->id],
                ['response' => $response, 'status' => 'in_progress', 'is_correct' => null, 'feedback' => null, 'teacher_review_status' => 'not_required', 'completed_at' => null]
            );
            $progress->update(['last_activity_at' => now()]);
            $this->audit->record('student-activity-response.draft-saved', $record, [], $record->toArray());
            return $record->fresh();
        });
    }

    private function evaluate(LessonActivity $activity, array $response): array
    {
        $invalid = fn (string $message) => throw ValidationException::withMessages(['response' => $message]);
        $feedback = $activity->feedback ?? [];
        if ($activity->activity_type === 'instruction') {
            ($response['acknowledged'] ?? false) === true || $invalid('Confirm that you are ready before continuing.');
            return ['completed', null, $feedback['correct'] ?? null, 'not_required'];
        }
        if ($activity->activity_type === 'multiple_choice') {
            $choiceIds = collect($activity->interaction_data['choices'] ?? [])->pluck('id')->all();
            in_array($response['selected'] ?? null, $choiceIds, true) || $invalid('Choose one of the available answers.');
            if (($activity->interaction_data['ungraded'] ?? false) === true) {
                return ['completed', null, $feedback['correct'] ?? 'Compare your prediction with what the example shows.', 'not_required'];
            }
            $correct = ($response['selected'] ?? null) === ($activity->answer_data['correct'] ?? null);
            $choiceFeedback = $activity->interaction_data['choice_feedback'][$response['selected']] ?? null;
            return [$correct ? 'completed' : 'in_progress', $correct, $correct ? ($feedback['correct'] ?? null) : ($choiceFeedback ?? $feedback['incorrect'] ?? null), 'not_required'];
        }
        if ($activity->activity_type === 'matching') {
            is_array($response['matches'] ?? null) || $invalid('Complete every match before checking your work.');
            $expected = $activity->answer_data['matches'] ?? [];
            ksort($expected); $actual = $response['matches']; ksort($actual);
            $minimumCorrect = (int) ($activity->interaction_data['minimum_correct'] ?? count($expected));
            $correctCount = collect($expected)->filter(fn ($value, $key) => ($actual[$key] ?? null) === $value)->count();
            $correct = count($actual) === count($expected) && $correctCount >= $minimumCorrect;
            if (! $correct) {
                $incorrectId = collect($expected)->keys()->first(fn ($id) => ($actual[$id] ?? null) !== $expected[$id]);
                $targeted = $activity->interaction_data['answer_feedback'][$incorrectId][$actual[$incorrectId] ?? 'default']
                    ?? $activity->interaction_data['answer_feedback'][$incorrectId]['default']
                    ?? $feedback['incorrect']
                    ?? null;
                return ['in_progress', false, $targeted, 'not_required'];
            }
            return ['completed', true, $feedback['correct'] ?? null, 'not_required'];
        }
        if ($activity->activity_type === 'question_set') {
            is_array($response['answers'] ?? null) || $invalid('Answer every question before checking your work.');
            $expected = $activity->answer_data['answers'] ?? [];
            $actual = $response['answers'];
            foreach ($activity->interaction_data['questions'] ?? [] as $question) {
                $choiceIds = collect($question['choices'] ?? [])->pluck('id')->all();
                in_array($actual[$question['id']] ?? null, $choiceIds, true) || $invalid('Answer every question before checking your work.');
            }
            ksort($expected); ksort($actual);
            $correct = $actual === $expected;
            if (! $correct) {
                $incorrectId = collect($expected)->keys()->first(fn ($id) => ($actual[$id] ?? null) !== $expected[$id]);
                $targeted = $activity->interaction_data['answer_feedback'][$incorrectId][$actual[$incorrectId] ?? 'default']
                    ?? $activity->interaction_data['answer_feedback'][$incorrectId]['default']
                    ?? $feedback['incorrect']
                    ?? null;
                return ['in_progress', false, $targeted, 'not_required'];
            }
            return ['completed', true, $feedback['correct'] ?? null, 'not_required'];
        }
        if ($activity->activity_type === 'short_response') {
            foreach ($activity->interaction_data['fields'] ?? [] as $field) {
                $value = $response[$field['id']] ?? null;
                if (($field['control'] ?? 'short_response') === 'multiple_choice') {
                    $choiceIds = collect($field['choices'] ?? [])->pluck('id')->all();
                    is_string($value) && in_array($value, $choiceIds, true) || $invalid('Choose one of the available answers for every choice question.');
                    continue;
                }
                is_string($value) && mb_strlen(trim($value)) >= (int) ($field['minimum_length'] ?? 3) || $invalid('Write a response for every field before continuing.');
            }
            if (($activity->interaction_data['ungraded'] ?? false) === true) {
                return ['completed', null, $feedback['correct'] ?? 'Your idea is saved.', 'not_required'];
            }
            return ['submitted', null, 'Saved for parent/teacher review.', 'pending'];
        }
        if ($activity->activity_type === 'project') {
            if (isset($activity->interaction_data['language_work_builder'])) {
                $work = $response['language_practice'] ?? null;
                is_array($work) || $invalid('Complete the language practice before submitting it.');
                $config = $activity->interaction_data['language_work_builder'];
                foreach ($config['fields'] ?? [] as $field) {
                    $value = $work[$field['id']] ?? null;
                    $control = $field['control'] ?? 'text';
                    if (($field['required'] ?? true) === false && ($value === null || $value === '' || $value === [])) {
                        continue;
                    }
                    if ($control === 'checkbox') {
                        $value === true || $invalid($field['required_message'] ?? 'Complete the speaking self-check before continuing.');
                    } elseif ($control === 'multi_select') {
                        $allowed = collect($field['choices'] ?? [])->pluck('id');
                        is_array($value) && count(array_unique($value)) >= (int) ($field['minimum_selected'] ?? 1)
                            && collect($value)->diff($allowed)->isEmpty()
                            || $invalid($field['required_message'] ?? 'Choose the requested practice items before continuing.');
                    } elseif ($control === 'select') {
                        in_array($value, collect($field['choices'] ?? [])->pluck('id')->all(), true)
                            || $invalid($field['required_message'] ?? 'Choose one available language option before continuing.');
                    } else {
                        is_string($value) && mb_strlen(trim($value)) >= (int) ($field['minimum_length'] ?? 3)
                            || $invalid($field['required_message'] ?? 'Complete each short language response before continuing.');
                    }
                }
                foreach ($config['expected_values'] ?? [] as $fieldId => $expected) {
                    if (($work[$fieldId] ?? null) !== $expected) {
                        return ['in_progress', false, $config['field_feedback'][$fieldId] ?? 'Look at the modeled phrase and try that part again.', 'not_required'];
                    }
                }
                $review = (bool) ($config['teacher_review_required'] ?? true);
                return [$review ? 'submitted' : 'completed', null, $feedback['correct'] ?? 'Your language work is saved.', $review ? 'pending' : 'not_required'];
            }
            if (isset($activity->interaction_data['language_passport_builder'])) {
                $work = $response['language_work'] ?? null;
                is_array($work) || $invalid('Complete the digital passport card before submitting it.');
                $config = $activity->interaction_data['language_passport_builder'];
                $allowedGreetings = collect($config['greetings'] ?? [])->pluck('id')->all();
                $allowedFarewells = collect($config['farewells'] ?? [])->pluck('id')->all();
                is_array($work['greetings'] ?? null) && is_array($work['farewells'] ?? null)
                    || $invalid('Choose passport phrases from the available lesson cards.');
                $greetings = array_values(array_unique($work['greetings'] ?? []));
                $farewells = array_values(array_unique($work['farewells'] ?? []));
                count($greetings) >= (int) ($config['minimum_greetings'] ?? 2)
                    && collect($greetings)->diff($allowedGreetings)->isEmpty()
                    || $invalid('Choose at least two of the lesson greetings for your passport card.');
                count($farewells) >= (int) ($config['minimum_farewells'] ?? 2)
                    && collect($farewells)->diff($allowedFarewells)->isEmpty()
                    || $invalid('Choose both lesson farewells for your passport card.');
                is_string($work['practice_line'] ?? null) && mb_strlen(trim($work['practice_line'])) >= 8
                    || $invalid('Type one short greeting-and-farewell line using the model.');
                $normalizedLine = mb_strtolower(str_replace(['á', 'é', 'í', 'ó', 'ú'], ['a', 'e', 'i', 'o', 'u'], $work['practice_line']));
                collect([...$allowedGreetings, ...$allowedFarewells])->contains(fn ($id) => str_contains($normalizedLine, str_replace('_', ' ', $id)))
                    || $invalid('Use at least one modeled Spanish greeting or farewell in your written line.');
                is_string($work['reason'] ?? null) && mb_strlen(trim($work['reason'])) >= 10
                    || $invalid('Explain why one phrase fits its situation. You may write this in English.');
                ($work['speaking_self_check'] ?? false) === true
                    || $invalid('Listen and practice aloud before completing your speaking self-check.');
                return ['submitted', null, $feedback['correct'] ?? 'Your first digital passport section is saved for teacher review.', 'pending'];
            }
            if (isset($activity->interaction_data['technology_code_builder'])) {
                $work = $response['technology_work'] ?? null;
                is_array($work) || $invalid('Complete the code workspace before submitting it.');
                foreach (['source', 'prediction', 'reflection'] as $field) is_string($work[$field] ?? null) || $invalid('Complete every code-work field.');
                mb_strlen(trim($work['prediction'])) >= 3 || $invalid('Write your prediction before continuing.');
                mb_strlen(trim($work['reflection'])) >= 10 || $invalid('Explain what the statement order does before continuing.');
                $config = $activity->interaction_data['technology_code_builder'];
                $analysis = $this->analyzeTechnologyCode($work['source'], is_array($work['inputs'] ?? null) ? $work['inputs'] : []);
                $outputs = $analysis['outputs'];
                $minimum = (int) ($config['minimum_statements'] ?? 4);
                $analysis['print_count'] >= $minimum || $invalid("Include at least {$minimum} print statement".($minimum === 1 ? '.' : 's.'));
                if (($config['require_changed_from_starter'] ?? false) === true && trim($work['source']) === trim((string) ($config['starter_code'] ?? ''))) {
                    $invalid('Change the words inside the quotation marks before continuing.');
                }
                foreach ($config['required_variable_names'] ?? [] as $name) array_key_exists($name, $analysis['assignment_counts']) || $invalid("Keep the required {$name} variable in your project.");
                foreach ($config['required_input_variables'] ?? [] as $name) {
                    ($analysis['types'][$name] ?? null) === 'input' || $invalid("Use input() to collect {$name}.");
                    is_string($work['inputs'][$name] ?? null) && trim($work['inputs'][$name]) !== '' || $invalid('Enter every test response before continuing.');
                }
                foreach ($config['required_numeric_variables'] ?? [] as $name) ($analysis['types'][$name] ?? null) === 'number' || $invalid("Store {$name} as a number without quotation marks.");
                $updated = collect($analysis['assignment_counts'])->filter(fn ($count) => $count > 1)->count();
                $updated >= (int) ($config['minimum_updated_variables'] ?? 0) || $invalid('Update more than one required resource value before continuing.');
                $analysis['print_count'] >= (int) ($config['minimum_prints'] ?? 0) || $invalid('Display every required value before continuing.');
                foreach ($config['required_output_labels'] ?? [] as $label) collect($outputs)->contains(fn ($output) => str_contains($output, $label)) || $invalid("Display the {$label} label with its stored value.");
                foreach ($config['required_outputs'] ?? [] as $required) in_array($required, $outputs, true) || $invalid('Keep all four required mission messages in the guided lab.');
                if (($expected = $config['expected_order'] ?? []) !== [] && $outputs !== $expected) {
                    return ['in_progress', false, 'Move the launch line above the destination line, then preview the order again.', 'not_required'];
                }
                $review = (bool) ($config['teacher_review_required'] ?? true);
                return [$review ? 'submitted' : 'completed', $review ? true : null, $feedback['correct'] ?? 'Code work saved.', $review ? 'pending' : 'not_required'];
            }
            if (isset($activity->interaction_data['elar_response_builder'])) {
                $work = $response['elar_work'] ?? null;
                is_array($work) || $invalid('Complete the ELAR response organizer before submitting it.');
                $config = $activity->interaction_data['elar_response_builder'];
                foreach ($config['fields'] ?? [] as $field) {
                    $value = $work[$field['id']] ?? null;
                    is_string($value) || $invalid('Complete every ELAR response field.');
                    if (in_array($field['control'] ?? null, ['select', 'evidence_select'], true)) {
                        in_array($value, collect($field['choices'] ?? [])->pluck('id')->all(), true)
                            || $invalid('Choose an available option for every ELAR response field.');
                    } else {
                        mb_strlen(trim($value)) >= (int) ($field['minimum_length'] ?? 3) && mb_strlen($value) <= 2000
                            || $invalid('Explain your reading clearly before continuing.');
                    }
                }
                foreach ($config['expected_values'] ?? [] as $fieldId => $expected) {
                    $matches = is_array($expected) ? in_array($work[$fieldId] ?? null, $expected, true) : ($work[$fieldId] ?? null) === $expected;
                    if (! $matches) {
                        return ['in_progress', false, $config['field_feedback'][$fieldId] ?? 'Reread the relevant passage section and try again.', 'not_required'];
                    }
                }
                $review = (bool) ($config['teacher_review_required'] ?? true);
                return [$review ? 'submitted' : 'completed', true, $feedback['correct'] ?? 'Your ELAR response is saved.', $review ? 'pending' : 'not_required'];
            }
            if (isset($activity->interaction_data['math_work_builder'])) {
                $work = $response['math_work'] ?? null;
                is_array($work) || $invalid('Complete the five-part Math organizer before checking it.');
                $config = $activity->interaction_data['math_work_builder'];
                foreach (collect($config['sections'] ?? [])->flatMap(fn ($section) => $section['fields'] ?? []) as $field) {
                    $value = $work[$field['id']] ?? null;
                    (is_string($value) || is_numeric($value)) || $invalid('Complete every Math organizer field.');
                    $value = (string) $value;
                    if (($field['control'] ?? null) === 'select') {
                        in_array($value, collect($field['choices'] ?? [])->pluck('id')->all(), true) || $invalid('Choose an available answer for every organizer choice.');
                    } elseif (($field['control'] ?? null) === 'number') {
                        preg_match('/^-?\d+(?:\.\d+)?$/', $value) || $invalid('Enter a number in every numeric organizer field.');
                    } else {
                        mb_strlen(trim($value)) >= (int) ($field['minimum_length'] ?? 3) || $invalid('Explain every reasoning and checking step before continuing.');
                    }
                }
                foreach ($config['expected_values'] ?? [] as $fieldId => $expected) {
                    $matches = is_array($expected)
                        ? in_array((string) ($work[$fieldId] ?? ''), array_map('strval', $expected), true)
                        : (string) ($work[$fieldId] ?? '') === (string) $expected;
                    if (! $matches) {
                        return ['in_progress', false, $config['field_feedback'][$fieldId] ?? 'Recheck this step of the organizer and try again.', 'not_required'];
                    }
                }
                $reviewRequired = (bool) ($config['teacher_review_required'] ?? true);
                return [$reviewRequired ? 'submitted' : 'completed', true, $feedback['correct'] ?? 'Your complete Math work is saved.', $reviewRequired ? 'pending' : 'not_required'];
            }
            if (isset($activity->interaction_data['science_work_builder'])) {
                $work = $response['science_work'] ?? null;
                is_array($work) || $invalid('Complete the structured science work before submitting it.');
                $config = $activity->interaction_data['science_work_builder'];
                foreach (collect($config['sections'] ?? [])->flatMap(fn ($section) => $section['fields'] ?? []) as $field) {
                    $value = $work[$field['id']] ?? null;
                    is_string($value) || $invalid('Complete every science-work field before submitting.');
                    if (($field['control'] ?? 'textarea') === 'select') {
                        in_array($value, collect($field['choices'] ?? [])->pluck('id')->all(), true)
                            || $invalid('Choose an available answer for every science-work choice.');
                    } else {
                        mb_strlen(trim($value)) >= (int) ($field['minimum_length'] ?? 3) && mb_strlen($value) <= 2000
                            || $invalid('Complete every science-work explanation with enough evidence.');
                    }
                }
                foreach ($config['expected_values'] ?? [] as $fieldId => $expected) {
                    ($work[$fieldId] ?? null) === $expected || $invalid('Check the labels on your water-cycle model before submitting.');
                }
                return ['submitted', null, 'Your structured science work is saved for parent/teacher review.', 'pending'];
            }
            if (isset($activity->interaction_data['systems_map_builder'])) {
                $map = $response['systems_map'] ?? null;
                is_array($map) || $invalid('Build your Earth systems map before submitting it.');
                $config = $activity->interaction_data['systems_map_builder'];
                $terms = $map['terms'] ?? null;
                is_array($terms) && count($terms) >= (int) $config['minimum_terms']
                    && count($terms) === count(array_unique($terms))
                    && collect($terms)->every(fn ($term) => is_string($term) && in_array($term, $config['terms'], true))
                    || $invalid('Choose at least five different Earth-system terms.');
                $connections = $map['connections'] ?? null;
                is_array($connections) && count($connections) >= (int) $config['minimum_connections']
                    || $invalid('Build at least three cause-and-effect connections.');
                $uniqueConnections = [];
                $allowedConnections = collect($config['allowed_connections'] ?? [])->map(fn ($connection) => implode('|', [$connection['from'], $connection['relationship'], $connection['to']]))->all();
                foreach ($connections as $connection) {
                    is_array($connection)
                        && in_array($connection['from'] ?? null, $terms, true)
                        && in_array($connection['to'] ?? null, $terms, true)
                        && ($connection['from'] ?? null) !== ($connection['to'] ?? null)
                        && in_array($connection['relationship'] ?? null, $config['relationships'], true)
                        || $invalid('Complete every connection with two different selected terms and a relationship.');
                    $uniqueConnections[] = implode('|', [$connection['from'], $connection['relationship'], $connection['to']]);
                    ($allowedConnections === [] || in_array(end($uniqueConnections), $allowedConnections, true))
                        || $invalid('Choose only scientifically supported cause-and-effect connections.');
                }
                count($uniqueConnections) === count(array_unique($uniqueConnections)) || $invalid('Build three different connections.');
                is_string($map['question'] ?? null) && mb_strlen(trim($map['question'])) >= 8 && mb_strlen($map['question']) <= 500
                    || $invalid('Add one complete question to investigate during the unit.');
                return ['submitted', null, 'Your Earth systems map is saved for parent/teacher review.', 'pending'];
            }
            if (isset($activity->interaction_data['analysis_builder'])) {
                $analysis = $response['analysis'] ?? null;
                is_array($analysis) || $invalid('Complete your evidence organizer before submitting it.');
                $observations = $analysis['observations'] ?? null;
                is_array($observations) && count($observations) >= 2 && count($observations) <= 10 || $invalid('Record at least two direct observations.');
                $allowedFips = collect($activity->interaction_data['analysis_builder']['location_choices'] ?? [])->pluck('state_fips')->all();
                $usedFips = [];
                foreach ($observations as $observation) {
                    is_array($observation) && in_array($observation['state_fips'] ?? null, $allowedFips, true) || $invalid('Choose an available labeled state for every observation.');
                    is_string($observation['statement'] ?? null) && mb_strlen(trim($observation['statement'])) >= 8 && mb_strlen($observation['statement']) <= 500 || $invalid('Explain every observation with visible map evidence.');
                    $usedFips[] = $observation['state_fips'];
                }
                count(array_unique($usedFips)) === count($usedFips) || $invalid('Use different labeled states for the observations.');
                $patterns = $analysis['patterns'] ?? null;
                is_array($patterns) && count($patterns) >= 2 && collect($patterns)->every(fn ($value) => is_string($value) && mb_strlen(trim($value)) >= 8 && mb_strlen($value) <= 500) || $invalid('Record at least two comparison patterns.');
                $inference = $analysis['inference'] ?? null;
                is_string($inference) && mb_strlen(trim($inference)) >= 8 && mb_strlen($inference) <= 700 && preg_match('/\b(may|might|possible|could)\b/i', $inference) || $invalid('Write a cautious inference using may, might, possible, or could.');
                $limitation = $analysis['limitation'] ?? null;
                is_string($limitation) && mb_strlen(trim($limitation)) >= 8 && mb_strlen($limitation) <= 700 || $invalid('Explain a limitation or additional evidence needed.');
                return ['submitted', null, 'Your settlement evidence organizer is saved for parent/teacher review.', 'pending'];
            }
            if (($activity->interaction_data['map_mode'] ?? null) === 'region_builder') {
                $map = $response['map'] ?? null;
                is_array($map) || $invalid('Build your digital regional map before submitting it.');
                is_string($map['title'] ?? null) && mb_strlen(trim($map['title'])) >= 3 && mb_strlen($map['title']) <= 120
                    || $invalid('Add a descriptive regional map title.');
                is_string($map['criterion'] ?? null) && mb_strlen(trim($map['criterion'])) >= 5 && mb_strlen($map['criterion']) <= 200
                    || $invalid('State the organizing criterion for your regions.');
                $minimumRegions = (int) ($activity->interaction_data['region_builder']['minimum_regions'] ?? 3);
                $minimumStates = (int) ($activity->interaction_data['region_builder']['minimum_states_per_region'] ?? 2);
                $allowedColors = $activity->interaction_data['region_builder']['color_keys'] ?? [];
                $regions = $map['regions'] ?? null;
                is_array($regions) && count($regions) >= $minimumRegions && count($regions) <= 6
                    || $invalid("Create at least {$minimumRegions} regions.");
                $usedStates = [];
                foreach ($regions as $region) {
                    is_array($region) || $invalid('Every region must be structured.');
                    is_string($region['name'] ?? null) && mb_strlen(trim($region['name'])) >= 3 && mb_strlen($region['name']) <= 100
                        || $invalid('Give every region a clear name.');
                    in_array($region['color_key'] ?? null, $allowedColors, true) || $invalid('Choose a color for every region.');
                    $stateFips = $region['state_fips'] ?? null;
                    is_array($stateFips) && count($stateFips) >= $minimumStates && count($stateFips) <= 10
                        || $invalid("Add at least {$minimumStates} states to every region.");
                    foreach ($stateFips as $fips) {
                        is_string($fips) && in_array($fips, CensusStateGeometryResourceProvider::STATE_FIPS, true)
                            || $invalid('Choose every regional place from the available U.S. states.');
                        $usedStates[] = $fips;
                    }
                }
                count(array_unique($usedStates)) === count($usedStates) || $invalid('Use each state in only one region.');
                $reflections = $response['reflections'] ?? null;
                is_array($reflections) || $invalid('Complete the regional-map reflection questions.');
                foreach ($activity->interaction_data['reflection_fields'] ?? [] as $field) {
                    $value = $reflections[$field['id']] ?? null;
                    is_string($value) && mb_strlen(trim($value)) >= 3 && mb_strlen($value) <= 1000
                        || $invalid('Complete every regional-map reflection before submitting.');
                }
                return ['submitted', null, 'Your regional map and evidence are saved for parent/teacher review.', 'pending'];
            }
            if (($activity->interaction_data['map_mode'] ?? null) === 'builder') {
                $map = $response['map'] ?? null;
                is_array($map) || $invalid('Build your digital map before submitting it.');
                is_string($map['title'] ?? null) && mb_strlen(trim($map['title'])) >= 3 && mb_strlen($map['title']) <= 120
                    || $invalid('Add a descriptive map title.');
                ($map['show_orientation'] ?? null) === true || $invalid('Turn on the north arrow before submitting your map.');

                $minimumFeatures = (int) ($activity->interaction_data['map_builder']['minimum_features'] ?? 3);
                $features = $map['features'] ?? null;
                is_array($features) && count($features) >= $minimumFeatures && count($features) <= 10
                    || $invalid("Add at least {$minimumFeatures} map symbols or colors.");
                $allowedMarkers = $activity->interaction_data['map_builder']['allowed_marker_keys'] ?? [];
                $stateFips = [];
                foreach ($features as $feature) {
                    is_array($feature) || $invalid('Every map feature must be structured.');
                    $fips = $feature['state_fips'] ?? null;
                    is_string($fips) && in_array($fips, CensusStateGeometryResourceProvider::STATE_FIPS, true)
                        || $invalid('Choose every labeled place from the available U.S. states.');
                    is_string($feature['marker_key'] ?? null) && in_array($feature['marker_key'], $allowedMarkers, true)
                        || $invalid('Choose an available symbol or color for every map feature.');
                    is_string($feature['legend_label'] ?? null) && mb_strlen(trim($feature['legend_label'])) >= 3 && mb_strlen($feature['legend_label']) <= 100
                        || $invalid('Explain every symbol or color in the map legend.');
                    $stateFips[] = $fips;
                }
                count(array_unique($stateFips)) === count($stateFips) || $invalid('Choose a different labeled place for each map feature.');

                $reflections = $response['reflections'] ?? null;
                is_array($reflections) || $invalid('Complete the map reflection questions.');
                foreach ($activity->interaction_data['reflection_fields'] ?? [] as $field) {
                    $value = $reflections[$field['id']] ?? null;
                    is_string($value) && mb_strlen(trim($value)) >= 3 && mb_strlen($value) <= 1000
                        || $invalid('Complete every map reflection before submitting.');
                }
                return ['submitted', null, 'Your digital map and reflections are saved for parent/teacher review.', 'pending'];
            }
            $required = collect($activity->interaction_data['checklist'] ?? [])->pluck('id');
            $checked = collect($response['checklist'] ?? []);
            $required->diff($checked)->isEmpty() || $invalid('Confirm every required map feature before submitting.');
            count($response['observations'] ?? []) >= 2 || $invalid('Record both “The map shows…” observations.');
            collect($response['observations'])->every(fn ($value) => is_string($value) && mb_strlen(trim($value)) >= 3) || $invalid('Complete both map observations.');
            is_string($response['limitation'] ?? null) && mb_strlen(trim($response['limitation'])) >= 3 || $invalid('Record what the map does not show.');
            return ['submitted', null, 'Your field log is saved. Keep the physical map for parent/teacher review.', 'pending'];
        }
        return $invalid('This activity type is not available yet.');
    }

    /** @return array{outputs:list<string>, assignment_counts:array<string,int>, types:array<string,string>, print_count:int} */
    private function analyzeTechnologyCode(string $source, array $inputs): array
    {
        $invalid = fn (string $message) => throw ValidationException::withMessages(['response' => $message]);
        $values = []; $types = []; $counts = []; $outputs = []; $printCount = 0;
        foreach (preg_split('/\R/', trim($source)) ?: [] as $lineNumber => $line) {
            if (trim($line) === '') continue;
            if (preg_match('/^\s*([A-Za-z_]\w*)\s*=\s*input\(\s*([\"\'])(.*?)\2\s*\)\s*$/u', $line, $match)) {
                $name = $match[1]; $values[$name] = (string) ($inputs[$name] ?? ''); $types[$name] = 'input'; $counts[$name] = ($counts[$name] ?? 0) + 1; continue;
            }
            if (preg_match('/^\s*([A-Za-z_]\w*)\s*=\s*([\"\'])(.*?)\2\s*$/u', $line, $match)) {
                $name = $match[1]; $values[$name] = $match[3]; $types[$name] = 'string'; $counts[$name] = ($counts[$name] ?? 0) + 1; continue;
            }
            if (preg_match('/^\s*([A-Za-z_]\w*)\s*=\s*(-?\d+(?:\.\d+)?)\s*$/', $line, $match)) {
                $name = $match[1]; $values[$name] = $match[2]; $types[$name] = 'number'; $counts[$name] = ($counts[$name] ?? 0) + 1; continue;
            }
            if (preg_match('/^\s*print\((.*)\)\s*$/u', $line, $match)) {
                $rendered = [];
                foreach (array_map('trim', explode(',', $match[1])) as $part) {
                    if (preg_match('/^([\"\'])(.*?)\1$/u', $part, $quoted)) $rendered[] = $quoted[2];
                    elseif (preg_match('/^[A-Za-z_]\w*$/', $part) && array_key_exists($part, $values)) $rendered[] = (string) $values[$part];
                    else $invalid('Line '.($lineNumber + 1).' uses an unsupported or not-yet-stored print value.');
                }
                $outputs[] = implode(' ', $rendered); $printCount++; continue;
            }
            $invalid('Line '.($lineNumber + 1).' is outside this lesson’s safe preview. Compare it with the provided example.');
        }
        return ['outputs' => $outputs, 'assignment_counts' => $counts, 'types' => $types, 'print_count' => $printCount];
    }

    private function assertEnrollmentOwnsExperience(LessonExperience $experience, StudentEnrollment $enrollment): void
    {
        $experience->loadMissing('lesson.lessonPlan');
        abort_unless($experience->tenant_id === $enrollment->tenant_id
            && $experience->lesson->lessonPlan->student_enrollment_id === $enrollment->id, 404);
    }
}
