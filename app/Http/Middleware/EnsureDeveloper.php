<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureDeveloper
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->isDeveloper() || ! $request->user()->is_active) {
            abort(403, 'Esta seccion esta reservada para el desarrollador.');
        }

        return $next($request);
    }
}
