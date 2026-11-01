<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Meeting extends Model
{
    protected static function booted(): void
    {
        static::updating(function (Meeting $meeting) {
            if ($meeting->getOriginal('documented_at') && $meeting->isDirty([
                'association_id', 'scheduled_at', 'type', 'status', 'minutes', 'attendees',
                'quorum_snapshot', 'documented_at', 'minutes_signed_at', 'meeting_call', 'previous_meeting_id',
            ])) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'minutes' => ['السجل الموثّق محفوظ؛ لا يمكن إعادة كتابة المحضر أو الحضور أو النصاب.'],
                ]);
            }
        });
    }

    protected $fillable = [
        'meeting_number', 'title', 'type', 'scheduled_at',
        'association_id', 'property_id',
        'agenda', 'agenda_items', 'location', 'minutes', 'status', 'notes',
        'attendance_type', 'attendance_scope_id',
        'is_remote', 'remote_platform', 'remote_link',
        'invitees', 'attendees', 'manager_name', 'manager_id',
        'invitation_date', 'meeting_call', 'previous_meeting_id', 'documented_at',
        'minutes_signed_at', 'quorum_snapshot', 'meeting_format',
    ];

    protected $casts = [
        'scheduled_at'  => 'datetime',
        'agenda_items'  => 'array',
        'invitees'      => 'array',
        'attendees'     => 'array',
        'is_remote'     => 'boolean',
        'invitation_date' => 'date',
        'documented_at' => 'datetime',
        'minutes_signed_at' => 'datetime',
        'quorum_snapshot' => 'array',
        'meeting_call' => 'integer',
    ];

    public function association(): BelongsTo
    {
        return $this->belongsTo(Association::class);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function resolutions(): HasMany
    {
        return $this->hasMany(Resolution::class);
    }

    public function votes(): HasMany
    {
        return $this->hasMany(Vote::class);
    }
}
