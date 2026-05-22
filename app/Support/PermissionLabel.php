<?php

namespace App\Support;

use Illuminate\Support\Str;

class PermissionLabel
{
    public static function moduleFromName(string $permissionName): string
    {
        return Str::of($permissionName)->before('.')->replace('_', ' ')->lower()->value();
    }

    public static function actionFromName(string $permissionName): string
    {
        return Str::of($permissionName)->after('.')->replace('_', ' ')->lower()->value();
    }

    public static function displayNameFromName(string $permissionName): string
    {
        $module = self::moduleFromName($permissionName);
        $action = self::actionFromName($permissionName);

        return Str::headline(trim($action.' '.$module));
    }
}
