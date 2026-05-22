<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Throwable;

class ApiExceptionHandler
{
    public function handle(Request $request, Closure $next)
    {
        try {
            return $next($request);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return ApiResponse::validation($e->errors());
        } catch (\Illuminate\Auth\AuthenticationException $e) {
            return ApiResponse::unauthorized();
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            return ApiResponse::forbidden();
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return ApiResponse::notFound();
        } catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e) {
            return ApiResponse::notFound();
        } catch (Throwable $e) {
            return ApiResponse::serverError();
        }
    }
}
