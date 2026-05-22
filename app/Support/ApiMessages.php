<?php

namespace App\Support;

use Illuminate\Support\Str;

final class ApiMessages
{
    public const VALIDATION_FAILED = 'Some submitted information needs attention.';
    public const AUTHENTICATION_REQUIRED = 'Authentication is required to continue.';
    public const ACCESS_DENIED = 'You are not allowed to perform this action.';
    public const RESOURCE_NOT_FOUND = 'The requested resource could not be found.';
    public const SERVER_ERROR = 'Something went wrong while processing your request. Please try again.';
    public const TOO_MANY_REQUESTS = 'Too many requests were made. Please wait a moment and try again.';
    public const INVALID_TENANT_HEADER = 'A valid workspace identifier is required to continue.';
    public const TENANT_NOT_FOUND = 'The selected workspace could not be found.';
    public const INVALID_SESSION = 'Your session is invalid or has expired. Please sign in again.';
    public const TENANT_CONTEXT_UNAVAILABLE = 'The active workspace could not be resolved for this request.';

    public static function listRetrieved(string $resource): string
    {
        return self::label($resource).' retrieved successfully.';
    }

    public static function detailsRetrieved(string $resource): string
    {
        return self::singularLabel($resource).' details retrieved successfully.';
    }

    public static function created(string $resource): string
    {
        return self::singularLabel($resource).' created successfully.';
    }

    public static function updated(string $resource): string
    {
        return self::singularLabel($resource).' updated successfully.';
    }

    public static function deleted(string $resource): string
    {
        return self::singularLabel($resource).' deleted successfully.';
    }

    public static function queued(string $resource): string
    {
        return self::singularLabel($resource).' has been queued successfully.';
    }

    public static function failed(string $resource): string
    {
        return self::singularLabel($resource).' could not be processed.';
    }

    public static function duplicate(string $resource): string
    {
        return 'A '.Str::lower(self::singularLabel($resource)).' with the same details already exists.';
    }

    public static function invalidReference(string $resource): string
    {
        return self::singularLabel($resource).' could not be found.';
    }

    private static function label(string $value): string
    {
        return Str::ucfirst(str_replace('_', ' ', trim($value)));
    }

    private static function singularLabel(string $value): string
    {
        return Str::ucfirst(Str::singular(str_replace('_', ' ', trim($value))));
    }
}
