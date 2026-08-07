<?php

namespace App\Http\Controllers;

use App\Models\AcademicSource;
use App\Models\LearningPlanSubjectPreference;
use App\Models\StudentEnrollment;
use App\Models\Subject;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class LearningPlanSubjectPreferenceController extends Controller
{
    public function hide(StudentEnrollment $enrollment, Subject $subject): RedirectResponse
    {
        $this->authorizeContext($enrollment, $subject);
        LearningPlanSubjectPreference::query()->updateOrCreate(
            ['student_enrollment_id' => $enrollment->id, 'subject_id' => $subject->id],
            ['is_hidden' => true, 'hidden_at' => now(), 'hidden_by_user_id' => auth()->id()],
        );

        return back()->with('success', "{$subject->name} hidden from this learning plan.");
    }

    public function show(StudentEnrollment $enrollment, Subject $subject): RedirectResponse
    {
        $this->authorizeContext($enrollment, $subject);
        LearningPlanSubjectPreference::query()->updateOrCreate(
            ['student_enrollment_id' => $enrollment->id, 'subject_id' => $subject->id],
            ['is_hidden' => false, 'hidden_at' => null, 'hidden_by_user_id' => null],
        );

        return back()->with('success', "{$subject->name} restored to this learning plan.");
    }

    private function authorizeContext(StudentEnrollment $enrollment, Subject $subject): void
    {
        Gate::authorize('create', AcademicSource::class);
        abort_unless(in_array($enrollment->status, ['planned', 'active'], true), 404);
        abort_unless($enrollment->student()->where('status', 'active')->exists(), 404);
        abort_unless($enrollment->schoolYear()->whereIn('status', ['draft', 'active'])->exists(), 404);
        abort_unless($subject->status === 'active', 404);
    }
}
