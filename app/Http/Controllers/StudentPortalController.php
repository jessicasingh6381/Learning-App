<?php

namespace App\Http\Controllers;

use App\Models\Student;
use App\Services\LessonAvailabilityService;
use App\Services\CreativeWritingJournalService;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StudentPortalController extends Controller
{
    public function home(Request $request, CreativeWritingJournalService $journal): Response
    {
        $props=$this->portalProps($request);$student=Student::query()->where('user_id',$request->user()->id)->firstOrFail();$enrollment=$student->enrollments()->where('status','active')->with(['schoolYear','gradeLevel'])->first();$entry=$enrollment?$journal->today($enrollment):null;
        $props['writingJournal']=$entry?['id'=>$entry->id,'date'=>$entry->instructional_date->format('Y-m-d'),'title'=>$entry->prompt_title_snapshot,'prompt'=>$entry->prompt_snapshot,'include_hints'=>$entry->include_hints_snapshot,'category'=>$entry->category_snapshot,'status'=>$entry->status,'word_count'=>$entry->word_count,'url'=>route('student.writing-journal.show',$entry)]:null;
        return Inertia::render('StudentPortal/Home', $props);
    }

    public function learning(Request $request, LessonAvailabilityService $availability): Response
    {
        $props = $this->portalProps($request);
        $student = Student::query()->where('user_id', $request->user()->id)->firstOrFail();
        $enrollment = $student->enrollments()->where('status', 'active')->first();
        $props['subjects'] = $enrollment ? $availability->nextForEnrollment($enrollment) : [];

        return Inertia::render('StudentPortal/Learning', $props);
    }

    public function profile(Request $request): Response
    {
        return Inertia::render('StudentPortal/Profile', $this->portalProps($request));
    }

    private function portalProps(Request $request): array
    {
        $student = Student::query()
            ->where('user_id', $request->user()->id)
            ->firstOrFail();
        $enrollment = $student->enrollments()
            ->with(['schoolYear:id,name', 'gradeLevel:id,name'])
            ->where('status', 'active')
            ->first();

        return [
            'student' => [
                'first_name' => $student->first_name,
                'last_name' => $student->last_name,
                'preferred_name' => $student->preferred_name,
                'display_name' => $student->display_name,
            ],
            'academy' => app(TenantContext::class)->tenant()->name,
            'username' => $request->user()->username,
            'enrollment' => $enrollment ? [
                'school_year' => $enrollment->schoolYear->name,
                'grade_level' => $enrollment->gradeLevel->name,
                'status' => $enrollment->status,
            ] : null,
        ];
    }
}
