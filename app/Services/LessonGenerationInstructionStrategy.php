<?php

namespace App\Services;

use App\Data\LessonGenerationContext;

class LessonGenerationInstructionStrategy
{
    /** @return list<string> */
    public function guidance(LessonGenerationContext $context): array
    {
        $types = collect($context->components)->pluck('type');
        $guidance = [];
        if ($types->intersect(['project', 'project_milestone'])->isNotEmpty()) {
            $guidance[] = 'Treat project goals and milestones as concrete build, test, revision, and delivery steps. Reuse prior work where appropriate and recommend multiple sessions when creation plus revision or presentation would overload one sitting.';
        }
        if ($types->intersect(['vocabulary', 'listening', 'speaking', 'reading', 'writing'])->isNotEmpty()) {
            $guidance[] = 'Balance receptive and productive language practice using short, age-appropriate exchanges and repeated retrieval.';
        }
        if ($types->intersect(['skill', 'foundational_skill', 'concept', 'objective'])->isNotEmpty()) {
            $guidance[] = 'Teach named subject concepts and content explicitly when the approved context supports them, then model application, guide practice, and ask the student to demonstrate independently.';
        }
        if ($types->intersect(['assessment', 'assessment_support'])->isNotEmpty()) {
            $guidance[] = 'Use assessment guidance as a check for understanding, not as permission to introduce unrelated content. Separate creation, revision, presentation, and reflection, and make the workload and session count realistic for the enrolled grade.';
        }

        $subject = mb_strtolower((string) ($context->subject['name'] ?? ''));
        if (str_contains($subject, 'science')) {
            $guidance[] = 'Let investigations follow the scientific need: question, prediction, investigation, observation, explanation, and reflection when appropriate.';
        } elseif (str_contains($subject, 'technology')) {
            $guidance[] = 'Let project lessons follow goal, demonstration, build, test, revise, and deliverable when appropriate.';
        } elseif (str_contains($subject, 'spanish') || str_contains($subject, 'language')) {
            $guidance[] = 'Balance vocabulary, listening, modeled and guided speaking, reading, and short writing according to the language objective.';
        } elseif (str_contains($subject, 'social studies')) {
            $guidance[] = 'Teach approved named places, regions, periods, vocabulary, geographic features, and concepts concretely when present; for source lessons, use context, source examination, modeling, evidence analysis, and response as appropriate.';
        }

        return $guidance ?: ['Use the unit summary and standards to create explicit instruction, a worked example or model, supported practice, and a clear understanding check.'];
    }
}
