<?php

use Illuminate\Foundation\Application;

$cachedRoutes = __DIR__.'/cache/routes-v7.php';
if (is_file($cachedRoutes)) {
    foreach ([dirname(__DIR__).'/routes/api.php', dirname(__DIR__).'/routes/legacy.php', dirname(__DIR__).'/routes/web.php'] as $routesFile) {
        if (is_file($routesFile) && filemtime($routesFile) > filemtime($cachedRoutes)) {
            @unlink($cachedRoutes);
            break;
        }
    }
}
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::middleware('api')->group(base_path('routes/legacy.php'));
            Route::middleware('media.origin')->get('/uploads/{path}', [\App\Http\Controllers\MediaFileController::class, 'show'])->where('path', '.*');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(\App\Http\Middleware\ApplyAllowedOrigins::class);
        $middleware->alias([
            'token' => \App\Http\Middleware\AuthenticateToken::class,
            'media.origin' => \App\Http\Middleware\RestrictMediaOrigin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (\Illuminate\Validation\ValidationException $e, \Illuminate\Http\Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'message' => collect($e->errors())->flatten()->first(),
                    'errors' => $e->errors(),
                ], 422);
            }
        });

        $exceptions->render(function (\Throwable $e, \Illuminate\Http\Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }
            if ($e instanceof \Illuminate\Auth\AuthenticationException) {
                return response()->json(['message' => 'Please sign in again.'], 401);
            }

            $status = $e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface
                ? $e->getStatusCode()
                : 500;
            $message = trim($e->getMessage());
            $technical = $status >= 500
                || preg_match('/sqlstate|stack trace|vendor[\/\\\\]|\\.php\\b|illuminate\\\\|app\\\\models|syntax error|column not found|connection:|no query results/i', $message) === 1;

            if ($technical || $message === '') {
                return response()->json([
                    'message' => 'Something went wrong, try again later or contact system admin',
                ], $status >= 400 ? $status : 500);
            }

            return response()->json(['message' => $message], $status);
        });
    })->create();
