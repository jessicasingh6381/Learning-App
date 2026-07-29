<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EnrollmentController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SchoolYearController;
use App\Http\Controllers\StudentAccessController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\StudentPasswordController;
use App\Http\Controllers\StudentPortalController;
use App\Http\Controllers\TenantController;
use App\Http\Controllers\TenantMemberController;
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
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
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
