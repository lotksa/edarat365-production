<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Vote extends Model
{
    protected $fillable = [
        'vote_number', 'title', 'description',
        'association_id', 'meeting_id', 'created_by',
        'total_voters', 'quorum_percentage',
        'status', 'current_phase',
    ];

    protected $casts = [
        'total_voters'      => 'integer',
        'quorum_percentage' => 'integer',
        'current_phase'     => 'integer',
    ];

    public function association(): BelongsTo
    {
        return $this->belongsTo(Association::class);
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    public function phases(): HasMany
    {
        return $this->hasMany(VotePhase::class);
    }

    public function responses(): HasMany
    {
        return $this->hasMany(VoteResponse::class);
    }
}
