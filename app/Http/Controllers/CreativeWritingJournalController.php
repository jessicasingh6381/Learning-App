<?php

namespace App\Http\Controllers;

use App\Models\CreativeWritingEntry;
use App\Models\Student;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class CreativeWritingJournalController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('writing-journal.view');$studentId=$request->integer('student_id')?:null;
        $students=Student::query()->where('status','active')->orderBy('last_name')->orderBy('first_name')->get(['id','first_name','last_name','preferred_name']);
        $entries=CreativeWritingEntry::query()->with('student')->when($studentId,fn($q)=>$q->where('student_id',$studentId))->orderByDesc('instructional_date')->limit(200)->get();
        return Inertia::render('CreativeWriting/Index',['students'=>$students->map(fn($student)=>['id'=>$student->id,'name'=>$student->display_name]),'selected_student_id'=>$studentId,'entries'=>$entries->map(fn($entry)=>['id'=>$entry->id,'student'=>$entry->student->display_name,'date'=>$entry->instructional_date->format('Y-m-d'),'title'=>$entry->prompt_title_snapshot,'status'=>$entry->status,'word_count'=>$entry->word_count,'submitted_at'=>$entry->submitted_at?->toIso8601String(),'url'=>route('creative-writing.show',$entry)])->values(),'can_manage'=>Gate::allows('writing-journal.manage')]);
    }

    public function show(CreativeWritingEntry $entry): Response
    {
        Gate::authorize('writing-journal.view');$entry->load(['student','enrollment.schoolYear','feedbackBy']);
        return Inertia::render('CreativeWriting/Show',['entry'=>['id'=>$entry->id,'student'=>$entry->student->display_name,'school_year'=>$entry->enrollment->schoolYear->name,'date'=>$entry->instructional_date->format('Y-m-d'),'title'=>$entry->prompt_title_snapshot,'prompt'=>$entry->prompt_snapshot,'include_hints'=>$entry->include_hints_snapshot,'category'=>$entry->category_snapshot,'response'=>$entry->response,'status'=>$entry->status,'word_count'=>$entry->word_count,'assigned_at'=>$entry->assigned_at?->toIso8601String(),'started_at'=>$entry->started_at?->toIso8601String(),'last_saved_at'=>$entry->last_saved_at?->toIso8601String(),'submitted_at'=>$entry->submitted_at?->toIso8601String(),'teacher_feedback'=>$entry->teacher_feedback,'feedback_by'=>$entry->feedbackBy?->name,'feedback_at'=>$entry->feedback_at?->toIso8601String()],'can_manage'=>Gate::allows('writing-journal.manage')]);
    }

    public function feedback(Request $request,CreativeWritingEntry $entry,AuditService $audit): RedirectResponse
    {
        Gate::authorize('writing-journal.manage');$validated=$request->validate(['teacher_feedback'=>['nullable','string','max:5000']]);$before=$entry->toArray();$feedback=trim((string)($validated['teacher_feedback']??''));$entry->update(['teacher_feedback'=>$feedback===''?null:$feedback,'feedback_by_user_id'=>$feedback===''?null:$request->user()->id,'feedback_at'=>$feedback===''?null:now()]);$audit->record('creative-writing-entry.feedback-updated',$entry,$before,$entry->fresh()->toArray());
        return back()->with('success','Journal feedback saved.');
    }
}
