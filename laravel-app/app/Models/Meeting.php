<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Meeting extends Model
{
    protected $fillable = [
        'meeting_number', 'title', 'type', 'scheduled_at',
        'association_id', 'property_id',
        'agenda', 'agenda_items', 'location', 'minutes', 'status', 'notes',
        'attendance_type', 'attendance_scope_id',
        'is_remote', 'remote_platform', 'remote_link',
        'invitees', 'attendees', 'manager_name', 'manager_id',
    ];

    protected $casts = [
        'scheduled_at'  => 'datetime',
        'agenda_items'  => 'array',
        'invitees'      => 'array',
        'attendees'     => 'array',
        'is_remote'     => 'boolean',
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
