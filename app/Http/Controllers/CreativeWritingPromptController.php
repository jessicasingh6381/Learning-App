<?php

namespace App\Http\Controllers;

use App\Models\CreativeWritingPrompt;
use App\Models\GradeLevel;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CreativeWritingPromptController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('writing-journal.view');$prompts=CreativeWritingPrompt::query()->with(['minimumGradeLevel','maximumGradeLevel'])->orderByDesc('active')->orderBy('title')->get();
        return Inertia::render('CreativeWriting/Prompts',['prompts'=>$prompts->map(fn($prompt)=>['id'=>$prompt->id,'title'=>$prompt->title,'prompt'=>$prompt->prompt,'include_hints'=>$prompt->include_hints,'category'=>$prompt->category,'minimum_grade_level_id'=>$prompt->minimum_grade_level_id,'maximum_grade_level_id'=>$prompt->maximum_grade_level_id,'grade_range'=>trim(($prompt->minimumGradeLevel?->name??'All').' – '.($prompt->maximumGradeLevel?->name??'All')),'active'=>$prompt->active,'source_type'=>$prompt->source_type]),'grade_levels'=>GradeLevel::query()->where('is_active',true)->orderBy('sort_order')->get(['id','name']),'can_manage'=>Gate::allows('writing-journal.manage')]);
    }

    public function store(Request $request,AuditService $audit): RedirectResponse
    {
        Gate::authorize('writing-journal.manage');$data=$this->validated($request);$prompt=CreativeWritingPrompt::create([...$data,'source_type'=>'teacher_created','created_by_user_id'=>$request->user()->id]);$audit->record('creative-writing-prompt.created',$prompt,[],$prompt->toArray());return back()->with('success','Creative-writing prompt added.');
    }

    public function update(Request $request,CreativeWritingPrompt $prompt,AuditService $audit): RedirectResponse
    {
        Gate::authorize('writing-journal.manage');$data=$this->validated($request);$before=$prompt->toArray();$prompt->update($data);$audit->record('creative-writing-prompt.updated',$prompt,$before,$prompt->fresh()->toArray());return back()->with('success','Creative-writing prompt updated. Existing journal snapshots were preserved.');
    }

    public function toggle(CreativeWritingPrompt $prompt,AuditService $audit): RedirectResponse
    {
        Gate::authorize('writing-journal.manage');$before=$prompt->toArray();$prompt->update(['active'=>!$prompt->active]);$audit->record('creative-writing-prompt.'.($prompt->active?'activated':'deactivated'),$prompt,$before,$prompt->fresh()->toArray());return back()->with('success',$prompt->active?'Prompt activated.':'Prompt deactivated for future assignments.');
    }

    private function validated(Request $request): array
    {
        $data=$request->validate(['title'=>['required','string','max:255'],'prompt'=>['required','string','max:5000'],'include_hints'=>['required','array','min:1','max:10'],'include_hints.*'=>['required','string','max:255'],'category'=>['nullable','string','max:80'],'minimum_grade_level_id'=>['nullable','integer',Rule::exists('grade_levels','id')->where('is_active',true)],'maximum_grade_level_id'=>['nullable','integer',Rule::exists('grade_levels','id')->where('is_active',true)],'active'=>['required','boolean']]);
        if(($data['minimum_grade_level_id']??null)&&($data['maximum_grade_level_id']??null)){$grades=GradeLevel::query()->whereIn('id',[$data['minimum_grade_level_id'],$data['maximum_grade_level_id']])->pluck('sort_order','id');if(($grades[$data['minimum_grade_level_id']]??0)>($grades[$data['maximum_grade_level_id']]??0))throw ValidationException::withMessages(['maximum_grade_level_id'=>'The maximum grade must be at or above the minimum grade.']);}
        return $data;
    }
}
