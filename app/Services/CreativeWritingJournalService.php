<?php

namespace App\Services;

use App\Models\CreativeWritingEntry;
use App\Models\CreativeWritingPrompt;
use App\Models\StudentEnrollment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CreativeWritingJournalService
{
    public function __construct(private InstructionalDateService $dates, private CreativeWritingStoryCheckService $storyCheck, private AuditService $audit) {}

    public function today(StudentEnrollment $enrollment): ?CreativeWritingEntry
    {
        return $this->assignmentForDate($enrollment,$this->dates->today($enrollment));
    }

    public function assignmentForDate(StudentEnrollment $enrollment,string $date): ?CreativeWritingEntry
    {
        $existing=CreativeWritingEntry::query()->where('student_id',$enrollment->student_id)->whereDate('instructional_date',$date)->first();
        if($existing) return $existing;
        if($enrollment->status!=='active' || !$this->dates->isInstructional($enrollment,$date)) return null;
        return DB::transaction(function()use($enrollment,$date){
            StudentEnrollment::query()->whereKey($enrollment->id)->lockForUpdate()->firstOrFail();
            $existing=CreativeWritingEntry::query()->where('student_id',$enrollment->student_id)->whereDate('instructional_date',$date)->first();
            if($existing)return $existing;
            $eligible=$this->eligiblePrompts($enrollment);
            if(!$eligible->exists())throw ValidationException::withMessages(['journal'=>'No active creative-writing prompts are available for this grade level.']);
            $used=CreativeWritingEntry::query()->where('student_enrollment_id',$enrollment->id)->pluck('creative_writing_prompt_id');
            $unused=(clone $eligible)->whereNotIn('id',$used);
            $prompt=($unused->exists()?$unused:$eligible)->inRandomOrder()->firstOrFail();
            $entry=CreativeWritingEntry::create(['student_id'=>$enrollment->student_id,'student_enrollment_id'=>$enrollment->id,'school_year_id'=>$enrollment->school_year_id,'creative_writing_prompt_id'=>$prompt->id,'instructional_date'=>$date,'prompt_title_snapshot'=>$prompt->title,'prompt_snapshot'=>$prompt->prompt,'include_hints_snapshot'=>$prompt->include_hints,'category_snapshot'=>$prompt->category,'status'=>'assigned','word_count'=>0,'assigned_at'=>now()]);
            $this->audit->record('creative-writing-entry.assigned',$entry,[],$entry->toArray());
            return $entry;
        });
    }

    public function saveDraft(CreativeWritingEntry $entry,string $response): CreativeWritingEntry
    {
        if($entry->status==='submitted')throw ValidationException::withMessages(['journal'=>'This journal has already been submitted.']);
        $before=$entry->toArray();$trimmed=trim($response);$entry->update(['response'=>$response,'status'=>$trimmed===''?'assigned':'in_progress','word_count'=>$this->storyCheck->wordCount($response),'started_at'=>$trimmed!==''?($entry->started_at??now()):$entry->started_at,'last_saved_at'=>now()]);
        $this->audit->record('creative-writing-entry.draft-saved',$entry,$before,$entry->fresh()->toArray());
        return $entry->fresh();
    }

    public function submit(CreativeWritingEntry $entry,string $response): CreativeWritingEntry
    {
        if(trim($response)==='')throw ValidationException::withMessages(['response'=>'Write something for your story before submitting.']);
        if($entry->status==='submitted')return $entry;
        $this->saveDraft($entry,$response);$entry=$entry->fresh();$before=$entry->toArray();$entry->update(['status'=>'submitted','submitted_at'=>now(),'last_saved_at'=>now()]);$this->audit->record('creative-writing-entry.submitted',$entry,$before,$entry->fresh()->toArray());
        return $entry->fresh();
    }

    public function storyCheck(CreativeWritingEntry $entry): array { return $this->storyCheck->check($entry->response); }

    private function eligiblePrompts(StudentEnrollment $enrollment): Builder
    {
        $sort=(int)$enrollment->gradeLevel()->value('sort_order');
        return CreativeWritingPrompt::query()->where('active',true)
            ->where(fn($q)=>$q->whereNull('minimum_grade_level_id')->orWhereHas('minimumGradeLevel',fn($grade)=>$grade->where('sort_order','<=',$sort)))
            ->where(fn($q)=>$q->whereNull('maximum_grade_level_id')->orWhereHas('maximumGradeLevel',fn($grade)=>$grade->where('sort_order','>=',$sort)));
    }
}
