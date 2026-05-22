<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

class ApiResponse
{
    public static function resource(
        JsonResource|AnonymousResourceCollection $resource,
        string $message,
        int $status = 200
    ): JsonResponse {
        return $resource
            ->additional([
                'success' => true,
                'message' => $message,
                'errors' => null,
            ])
            ->response()
            ->setStatusCode($status);
    }

    public static function success(string $message, array|null $data = null, int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'errors' => null,
        ], $status);
    }

    public static function error(string $message, array|null $errors = null, int $status = 422): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'data' => null,
            'errors' => $errors,
        ], $status);
    }

    public static function validation(array $errors, ?string $message = null): JsonResponse
    {
        return self::error($message ?? ApiMessages::VALIDATION_FAILED, $errors, 422);
    }

    public static function unauthorized(array|null $errors = null, ?string $message = null): JsonResponse
    {
        return self::error($message ?? ApiMessages::AUTHENTICATION_REQUIRED, $errors, 401);
    }

    public static function forbidden(array|null $errors = null, ?string $message = null): JsonResponse
    {
        return self::error($message ?? ApiMessages::ACCESS_DENIED, $errors, 403);
    }

    public static function badRequest(array|null $errors = null, ?string $message = null): JsonResponse
    {
        return self::error($message ?? ApiMessages::VALIDATION_FAILED, $errors, 400);
    }

    public static function notFound(array|null $errors = null, ?string $message = null): JsonResponse
    {
        return self::error($message ?? ApiMessages::RESOURCE_NOT_FOUND, $errors, 404);
    }

    public static function tooManyRequests(array|null $errors = null, ?string $message = null): JsonResponse
    {
        return self::error($message ?? ApiMessages::TOO_MANY_REQUESTS, $errors, 429);
    }

    public static function serverError(array|null $errors = null, ?string $message = null): JsonResponse
    {
        return self::error($message ?? ApiMessages::SERVER_ERROR, $errors, 500);
    }
}
