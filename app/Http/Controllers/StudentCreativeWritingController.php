<?php

namespace App\Http\Controllers;

use App\Models\CreativeWritingEntry;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Services\CreativeWritingJournalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StudentCreativeWritingController extends Controller
{
    public function today(Request $request,CreativeWritingJournalService $service): RedirectResponse
    {
        $entry=$service->today($this->enrollment($request));
        return $entry?redirect()->route('student.writing-journal.show',$entry):redirect()->route('student.home')->with('success','Today is not an instructional day, so there is no new writing mission.');
    }

    public function index(Request $request): Response
    {
        $enrollment=$this->enrollment($request);$entries=$enrollment->creativeWritingEntries()->orderByDesc('instructional_date')->get();
        return Inertia::render('StudentJournal/History',['entries'=>$entries->map(fn($entry)=>['id'=>$entry->id,'date'=>$entry->instructional_date->format('Y-m-d'),'title'=>$entry->prompt_title_snapshot,'category'=>$entry->category_snapshot,'status'=>$entry->status,'word_count'=>$entry->word_count,'submitted_at'=>$entry->submitted_at?->toIso8601String(),'url'=>route('student.writing-journal.show',$entry)])->values()]);
    }

    public function show(Request $request,CreativeWritingEntry $entry,CreativeWritingJournalService $service): Response
    {
        $this->owned($request,$entry);
        return Inertia::render('StudentJournal/Show',['entry'=>$this->entryProps($entry,$service),'history_url'=>route('student.writing-journal.index'),'autosave_url'=>route('student.writing-journal.draft',$entry),'submit_url'=>route('student.writing-journal.submit',$entry)]);
    }

    public function saveDraft(Request $request,CreativeWritingEntry $entry,CreativeWritingJournalService $service): JsonResponse
    {
        $this->owned($request,$entry);$validated=$request->validate(['response'=>['present','string','max:50000']]);$entry=$service->saveDraft($entry,$validated['response']);
        return response()->json(['saved'=>true,'status'=>$entry->status,'word_count'=>$entry->word_count,'last_saved_at'=>$entry->last_saved_at?->toIso8601String(),'story_check'=>$service->storyCheck($entry)]);
    }

    public function submit(Request $request,CreativeWritingEntry $entry,CreativeWritingJournalService $service): RedirectResponse
    {
        $this->owned($request,$entry);$validated=$request->validate(['response'=>['required','string','max:50000']]);$service->submit($entry,$validated['response']);
        return back()->with('success','Journal submitted! Your story is safely saved.');
    }

    private function enrollment(Request $request): StudentEnrollment
    {
        $student=Student::query()->where('user_id',$request->user()->id)->firstOrFail();
        return $student->enrollments()->where('status','active')->with(['schoolYear','gradeLevel'])->firstOrFail();
    }

    private function owned(Request $request,CreativeWritingEntry $entry): void
    {
        $student=Student::query()->where('user_id',$request->user()->id)->firstOrFail();
        abort_unless($entry->student_id===$student->id && $entry->student_enrollment_id===$this->enrollment($request)->id,404);
    }

    private function entryProps(CreativeWritingEntry $entry,CreativeWritingJournalService $service): array
    {
        return ['id'=>$entry->id,'date'=>$entry->instructional_date->format('Y-m-d'),'title'=>$entry->prompt_title_snapshot,'prompt'=>$entry->prompt_snapshot,'include_hints'=>$entry->include_hints_snapshot,'category'=>$entry->category_snapshot,'response'=>$entry->response??'','status'=>$entry->status,'word_count'=>$entry->word_count,'assigned_at'=>$entry->assigned_at?->toIso8601String(),'started_at'=>$entry->started_at?->toIso8601String(),'last_saved_at'=>$entry->last_saved_at?->toIso8601String(),'submitted_at'=>$entry->submitted_at?->toIso8601String(),'teacher_feedback'=>$entry->teacher_feedback,'story_check'=>$service->storyCheck($entry)];
    }
}
