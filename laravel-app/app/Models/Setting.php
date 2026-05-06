<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = ['key', 'value'];

    protected $casts = [
        'value' => 'array',
    ];

    public static function getByKey(string $key, array $default = []): array
    {
        $row = static::where('key', $key)->first();
        return $row?->value ?? $default;
    }

    public static function setByKey(string $key, array $value): static
    {
        return static::updateOrCreate(
            ['key' => $key],
            ['value' => $value]
        );
    }
}
