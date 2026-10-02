<?php

namespace App\Http\Middleware;

use App\Support\AllowedOrigins;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RestrictMediaOrigin
{
    public function handle(Request $request, Closure $next): Response
    {
        $allowed = AllowedOrigins::all();
        $origin = $this->caller($request);
        if (! is_string($origin) || ! in_array($origin, $allowed, true)) {
            return response('', 403);
        }

        $response = $next($request);
        $response->headers->set('Access-Control-Allow-Origin', $origin);
        $response->headers->set('Vary', 'Origin');
        $response->headers->set('Cache-Control', 'private, max-age=3600');

        return $response;
    }

    private function caller(Request $request): ?string
    {
        $origin = $request->headers->get('Origin');
        if (is_string($origin) && $origin !== '') {
            return rtrim($origin, '/');
        }

        $referer = $request->headers->get('Referer');
        if (! is_string($referer) || $referer === '') {
            return null;
        }
        $parts = parse_url($referer);
        if (! isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return $parts['scheme'].'://'.$parts['host'].$port;
    }
}
