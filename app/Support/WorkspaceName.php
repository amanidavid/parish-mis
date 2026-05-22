<?php

namespace App\Support;

use Illuminate\Support\Str;

class WorkspaceName
{
    public static function normalize(string $value): string
    {
        return Str::of($value)
            ->squish()
            ->lower()
            ->slug('_')
            ->value();
    }

    public static function display(string $name, ?string $displayName = null): string
    {
        $source = filled($displayName) ? $displayName : str_replace('_', ' ', self::normalize($name));

        return Str::headline(Str::squish($source));
    }
}
