<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdministrativeUser
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_if($request->user()->student()->exists(), 403);

        return $next($request);
    }
}
