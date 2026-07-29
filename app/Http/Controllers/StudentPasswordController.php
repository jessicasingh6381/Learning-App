<?php

namespace App\Http\Controllers;

use App\Http\Requests\StudentPasswordChangeRequest;
use App\Services\AuditService;
use App\Services\StudentAccessService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class StudentPasswordController extends Controller
{
    public function edit(): Response
    {
        return Inertia::render('StudentPortal/ChangePassword');
    }

    public function update(
        StudentPasswordChangeRequest $request,
        StudentAccessService $access,
        AuditService $audit,
    ): RedirectResponse {
        $access->changeOwnPassword($request->user(), $request->validated('password'), $audit);
        $request->user()->refresh();
        $request->session()->regenerate();

        return redirect()->route('student.home')
            ->with('success', 'Your password has been changed.');
    }
}
