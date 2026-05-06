<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ActivityLog extends Model
{
    protected $fillable = [
        'subject_type', 'subject_id', 'action',
        'performer', 'old_values', 'new_values', 'description',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
    ];

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public static function record(
        string $subjectType,
        int $subjectId,
        string $action,
        ?string $description = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $performer = null
    ): self {
        return self::create([
            'subject_type' => $subjectType,
            'subject_id'   => $subjectId,
            'action'       => $action,
            'description'  => $description,
            'old_values'   => $oldValues,
            'new_values'   => $newValues,
            'performer'    => $performer ?? 'system',
        ]);
    }
}
