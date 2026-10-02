<?php

namespace App\Http\Middleware;

use App\Support\AllowedOrigins;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApplyAllowedOrigins
{
    public function handle(Request $request, Closure $next): Response
    {
        AllowedOrigins::apply();

        return $next($request);
    }
}
