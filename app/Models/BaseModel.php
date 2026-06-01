<?php

namespace App\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

abstract class BaseModel extends Model
{
    protected $guarded = [];

    public function getConnectionName(): ?string
    {
        if ($this->connection !== null) {
            return $this->connection;
        }

        $class = static::class;

        if (str_starts_with($class, 'App\\Models\\Tenant\\')) {
            return (string) config('multitenancy.tenant_database_connection_name', 'tenant');
        }

        if (str_starts_with($class, 'App\\Models\\Landlord\\')) {
            return 'base';
        }

        return parent::getConnectionName();
    }

    protected static function booted(): void
    {
        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected function serializeDate(DateTimeInterface $date): string
    {
        return $date->format('Y-m-d H:i:s');
    }
}
