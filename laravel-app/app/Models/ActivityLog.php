<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ActivityLog extends Model
{
    protected $fillable = [
        'subject_type', 'subject_id', 'action',
        'performer', 'performer_id', 'old_values', 'new_values', 'description',
    ];

    protected $casts = [
        'old_values'   => 'array',
        'new_values'   => 'array',
        'performer_id' => 'integer',
    ];

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Convenience relation back to the user who performed the action.
     * Nullable — older rows pre-date the `performer_id` column and rows
     * created by background jobs / system seeders intentionally have no
     * authenticated performer.
     */
    public function performerUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performer_id');
    }

    /**
     * Recording entry-point used by every controller. The signature is
     * backward-compatible: existing call sites continue to work unchanged
     * and now automatically attribute the action to the authenticated user
     * (when one exists), which powers the per-user activity timeline on
     * the User Detail page.
     */
    public static function record(
        string $subjectType,
        int $subjectId,
        string $action,
        ?string $description = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $performer = null
    ): self {
        $authUser = null;
        try {
            // auth() is unavailable in some test/console contexts; guard so
            // seeders + queue workers do not throw when there is no request.
            $authUser = auth()->user();
        } catch (\Throwable $e) {
            $authUser = null;
        }

        return self::create([
            'subject_type' => $subjectType,
            'subject_id'   => $subjectId,
            'action'       => $action,
            'description'  => $description,
            'old_values'   => $oldValues,
            'new_values'   => $newValues,
            'performer'    => $performer ?? ($authUser?->name ?? 'system'),
            'performer_id' => $authUser?->id,
        ]);
    }
}
