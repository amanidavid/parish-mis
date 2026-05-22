<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use App\Http\Middleware\AdminJwtAuth;
use App\Http\Middleware\ApiExceptionHandler;
use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\JwtAuth;
use App\Http\Middleware\TenantFromHeader;
use App\Support\ApiMessages;
use App\Support\ApiResponse;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->appendToGroup('api', [
            ForceJsonResponse::class,
            ApiExceptionHandler::class,
        ]);

        $middleware->alias([
            'admin.jwt.auth' => AdminJwtAuth::class,
            'jwt.auth' => JwtAuth::class,
            'tenant' => TenantFromHeader::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (Throwable $exception, $request) {
            if (!$request->is('api/*') && !$request->expectsJson()) {
                return null;
            }

            return match (true) {
                $exception instanceof ValidationException => ApiResponse::validation($exception->errors()),
                $exception instanceof AuthenticationException => ApiResponse::unauthorized(['auth' => [ApiMessages::AUTHENTICATION_REQUIRED]]),
                $exception instanceof AuthorizationException => ApiResponse::forbidden(['auth' => [ApiMessages::ACCESS_DENIED]]),
                $exception instanceof ModelNotFoundException => ApiResponse::notFound(),
                $exception instanceof NotFoundHttpException => ApiResponse::notFound(),
                default => ApiResponse::serverError(),
            };
        });
    })->create();
