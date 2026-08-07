<?php

use App\Http\Controllers\Academic\AcademicOverviewController;
use App\Http\Controllers\Academic\AcademicSourceController;
use App\Http\Controllers\Academic\CalendarEventController;
use App\Http\Controllers\Academic\CalendarImportController;
use App\Http\Controllers\Academic\CalendarProfileController;
use App\Http\Controllers\Academic\CourseController;
use App\Http\Controllers\Academic\CurriculumPackageController;
use App\Http\Controllers\Academic\CurriculumImportController;
use App\Http\Controllers\Academic\CurriculumFormatProfileController;
use App\Http\Controllers\Academic\StandardsImportController;
use App\Http\Controllers\Academic\EducationProviderController;
use App\Http\Controllers\Academic\StandardsFrameworkController;
use App\Http\Controllers\Academic\SubjectController;
use App\Http\Controllers\CurriculumIntakeController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EnrollmentController;
use App\Http\Controllers\LearningPlanSubjectPreferenceController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SchoolYearController;
use App\Http\Controllers\StudentAccessController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\StudentPasswordController;
use App\Http\Controllers\StudentPortalController;
use App\Http\Controllers\TenantController;
use App\Http\Controllers\TenantMemberController;
use App\Http\Controllers\WorkspaceController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::middleware(['auth', 'admin.user'])->group(function () {
    Route::get('/tenants/create', [TenantController::class, 'create'])->name('tenants.create');
    Route::post('/tenants', [TenantController::class, 'store'])->name('tenants.store');
    Route::post('/tenants/{tenant}/switch', [TenantController::class, 'switch'])->name('tenants.switch');
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

Route::middleware(['auth', 'admin.user', 'tenant'])->group(function () {
    Route::get('/dashboard', [WorkspaceController::class, 'home'])->name('dashboard');
    Route::get('/learning-plan', [WorkspaceController::class, 'learningPlan'])->name('workspace.learning-plan');
    Route::patch('/learning-plan/enrollments/{enrollment}/subjects/{subject}/hide', [LearningPlanSubjectPreferenceController::class, 'hide'])->name('workspace.learning-plan.subjects.hide');
    Route::patch('/learning-plan/enrollments/{enrollment}/subjects/{subject}/show', [LearningPlanSubjectPreferenceController::class, 'show'])->name('workspace.learning-plan.subjects.show');
    Route::get('/learning-plan/curriculum-intake', [CurriculumIntakeController::class, 'index'])->name('workspace.curriculum-intake');
    Route::post('/learning-plan/curriculum-intake', [CurriculumIntakeController::class, 'store'])->name('workspace.curriculum-intake.store');
    Route::get('/learning-plan/curriculum-intake/students/{student}/school-years/{schoolYear}/subjects/{subject}/add', [CurriculumIntakeController::class, 'create'])->name('workspace.curriculum-intake.subject.create');
    Route::post('/learning-plan/curriculum-intake/students/{student}/school-years/{schoolYear}/subjects/{subject}', [CurriculumIntakeController::class, 'storeSubject'])->name('workspace.curriculum-intake.subject.store');
    Route::post('/learning-plan/curriculum-intake/sources/{source}/draft', [CurriculumIntakeController::class, 'createDraft'])->name('workspace.curriculum-intake.draft');
    Route::get('/calendar', [WorkspaceController::class, 'calendar'])->name('workspace.calendar');
    Route::get('/workspace/{section}', [WorkspaceController::class, 'placeholder'])
        ->whereIn('section', ['assignments', 'gradebook', 'attendance', 'reports'])
        ->name('workspace.placeholder');
    Route::get('/settings', [WorkspaceController::class, 'settings'])->name('workspace.settings');
    Route::get('/settings/foundation', DashboardController::class)->name('settings.foundation');
    Route::resource('students', StudentController::class)->except('destroy');
    Route::patch('/students/{student}/archive', [StudentController::class, 'archive'])->name('students.archive');
    Route::get('/students/{student}/access', [StudentAccessController::class, 'show'])->name('students.access.show');
    Route::post('/students/{student}/access', [StudentAccessController::class, 'enable'])->name('students.access.enable');
    Route::patch('/students/{student}/access/username', [StudentAccessController::class, 'updateUsername'])->name('students.access.username');
    Route::put('/students/{student}/access/password', [StudentAccessController::class, 'resetPassword'])->name('students.access.password');
    Route::patch('/students/{student}/access/disable', [StudentAccessController::class, 'disable'])->name('students.access.disable');
    Route::patch('/students/{student}/access/reenable', [StudentAccessController::class, 'reenable'])->name('students.access.reenable');
    Route::resource('school-years', SchoolYearController::class)->except(['show', 'destroy']);
    Route::get('/enrollments/create', [EnrollmentController::class, 'create'])->name('enrollments.create');
    Route::post('/enrollments', [EnrollmentController::class, 'store'])->name('enrollments.store');
    Route::get('/members', [TenantMemberController::class, 'index'])->name('members.index');
    Route::patch('/members/{membership}', [TenantMemberController::class, 'update'])->name('members.update');

    Route::prefix('academic-setup')->name('academic.')->group(function () {
        Route::get('/', [AcademicOverviewController::class, 'index'])->name('overview');
        Route::post('/configuration', [AcademicOverviewController::class, 'store'])->name('configuration.store');
        Route::post('/configuration/copy', [AcademicOverviewController::class, 'copy'])->name('configuration.copy');

        Route::resource('sources', AcademicSourceController::class)->except('destroy');
        Route::patch('/sources/{source}/review', [AcademicSourceController::class, 'review'])->name('sources.review');
        Route::patch('/sources/{source}/archive', [AcademicSourceController::class, 'archive'])->name('sources.archive');
        Route::post('/sources/{source}/files', [AcademicSourceController::class, 'replaceFile'])->name('sources.files.store');
        Route::get('/sources/{source}/files/{file}/view', [AcademicSourceController::class, 'viewFile'])->name('sources.files.view');
        Route::get('/sources/{source}/files/{file}/download', [AcademicSourceController::class, 'download'])->name('sources.files.download');
        Route::post('/sources/{source}/links', [AcademicSourceController::class, 'addLink'])->name('sources.links.store');
        Route::delete('/sources/{source}/links/{link}', [AcademicSourceController::class, 'removeLink'])->name('sources.links.destroy');
        Route::post('/sources/{source}/draft-calendar', [AcademicSourceController::class, 'createCalendar'])->name('sources.draft-calendar');
        Route::post('/sources/{source}/calendar-imports', [CalendarImportController::class, 'store'])->name('sources.calendar-imports.store');
        Route::delete('/sources/{source}/calendar-imports/{calendarImport}', [CalendarImportController::class, 'destroy'])->name('sources.calendar-imports.destroy');
        Route::get('/calendar-imports/{calendarImport}', [CalendarImportController::class, 'show'])->name('calendar-imports.show');
        Route::post('/calendar-imports/{calendarImport}/proposals', [CalendarImportController::class, 'storeProposal'])->name('calendar-imports.proposals.store');
        Route::put('/calendar-imports/{calendarImport}/proposals', [CalendarImportController::class, 'bulkUpdateProposals'])->name('calendar-imports.proposals.bulk-update');
        Route::patch('/calendar-imports/{calendarImport}/proposals/{proposal}', [CalendarImportController::class, 'updateProposal'])->name('calendar-imports.proposals.update');
        Route::post('/calendar-imports/{calendarImport}/approve', [CalendarImportController::class, 'approve'])->name('calendar-imports.approve');
        Route::post('/sources/{source}/draft-curriculum', [AcademicSourceController::class, 'createCurriculum'])->name('sources.draft-curriculum');
        Route::post('/sources/{source}/draft-course', [AcademicSourceController::class, 'createCourse'])->name('sources.draft-course');
        Route::post('/sources/{source}/curriculum-capability', [CurriculumImportController::class, 'assessCapability'])->name('sources.curriculum-capability.store');
        Route::get('/sources/{source}/curriculum-format-setup', [CurriculumFormatProfileController::class, 'create'])->name('sources.curriculum-format-setup.create');
        Route::post('/sources/{source}/curriculum-format-setup', [CurriculumFormatProfileController::class, 'store'])->name('sources.curriculum-format-setup.store');
        Route::post('/sources/{source}/standards-imports', [StandardsImportController::class, 'store'])->name('sources.standards-imports.store');
        Route::get('/standards-imports/{curriculumImport}', [StandardsImportController::class, 'show'])->name('standards-imports.show');
        Route::put('/standards-imports/{curriculumImport}/review', [StandardsImportController::class, 'bulkUpdate'])->name('standards-imports.review.update');
        Route::post('/standards-imports/{curriculumImport}/approve', [StandardsImportController::class, 'approve'])->name('standards-imports.approve');
        Route::get('/curriculum-format-profiles/{profile}', [CurriculumFormatProfileController::class, 'show'])->name('curriculum-format-profiles.show');
        Route::put('/curriculum-format-profiles/{profile}', [CurriculumFormatProfileController::class, 'update'])->name('curriculum-format-profiles.update');
        Route::post('/curriculum-format-profiles/{profile}/activate', [CurriculumFormatProfileController::class, 'activate'])->name('curriculum-format-profiles.activate');
        Route::post('/sources/{source}/curriculum-imports', [CurriculumImportController::class, 'store'])->name('sources.curriculum-imports.store');
        Route::get('/curriculum-imports/{curriculumImport}', [CurriculumImportController::class, 'show'])->name('curriculum-imports.show');
        Route::put('/curriculum-imports/{curriculumImport}/proposals', [CurriculumImportController::class, 'bulkUpdate'])->name('curriculum-imports.proposals.bulk-update');
        Route::post('/curriculum-imports/{curriculumImport}/reextract', [CurriculumImportController::class, 'reextract'])->name('curriculum-imports.reextract');
        Route::post('/curriculum-imports/{curriculumImport}/approve', [CurriculumImportController::class, 'approve'])->name('curriculum-imports.approve');

        Route::resource('providers', EducationProviderController::class)
            ->except(['show', 'destroy'])
            ->parameters(['providers' => 'provider']);
        Route::resource('calendars', CalendarProfileController::class)
            ->parameters(['calendars' => 'calendar']);
        Route::patch('/calendars/{calendar}/archive', [CalendarProfileController::class, 'archive'])->name('calendars.archive');
        Route::patch('/calendars/{calendar}/restore', [CalendarProfileController::class, 'restore'])->name('calendars.restore');
        Route::post('/calendars/{calendar}/events', [CalendarEventController::class, 'store'])->name('calendars.events.store');
        Route::patch('/calendars/{calendar}/events/{event}', [CalendarEventController::class, 'update'])->name('calendars.events.update');
        Route::resource('standards', StandardsFrameworkController::class)
            ->except(['show', 'destroy'])
            ->parameters(['standards' => 'framework']);
        Route::resource('subjects', SubjectController::class)
            ->except(['show', 'destroy']);
        Route::resource('courses', CourseController::class)
            ->except(['show', 'destroy']);
        Route::resource('curriculum', CurriculumPackageController::class)
            ->except('destroy')
            ->parameters(['curriculum' => 'package']);
        Route::post('/curriculum/{package}/courses', [CurriculumPackageController::class, 'addCourse'])->name('curriculum.courses.store');
        Route::patch('/curriculum/{package}/courses/{mapping}', [CurriculumPackageController::class, 'updateCourse'])->name('curriculum.courses.update');
        Route::delete('/curriculum/{package}/courses/{mapping}', [CurriculumPackageController::class, 'removeCourse'])->name('curriculum.courses.destroy');
    });
});

Route::middleware(['auth', 'student.access'])->prefix('student')->name('student.')->group(function () {
    Route::get('/password/change', [StudentPasswordController::class, 'edit'])->name('password.edit');
    Route::put('/password/change', [StudentPasswordController::class, 'update'])->name('password.update');

    Route::middleware('student.password')->group(function () {
        Route::get('/', [StudentPortalController::class, 'home'])->name('home');
        Route::get('/learning', [StudentPortalController::class, 'learning'])->name('learning');
        Route::get('/profile', [StudentPortalController::class, 'profile'])->name('profile');
    });
});

require __DIR__.'/auth.php';
