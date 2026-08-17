<?php

use App\Http\Middleware\EnsureAdministrativeUser;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequireStudentPasswordChange;
use App\Http\Middleware\ResolveActiveTenant;
use App\Http\Middleware\ResolveStudentPortal;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands()
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'admin.user' => EnsureAdministrativeUser::class,
            'student.access' => ResolveStudentPortal::class,
            'student.password' => RequireStudentPasswordChange::class,
            'tenant' => ResolveActiveTenant::class,
        ]);
        $middleware->prependToPriorityList(
            SubstituteBindings::class,
            ResolveActiveTenant::class,
        );
        $middleware->prependToPriorityList(
            SubstituteBindings::class,
            ResolveStudentPortal::class,
        );
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        //
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
