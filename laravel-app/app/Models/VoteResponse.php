<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VoteResponse extends Model
{
    protected $fillable = [
        'vote_id', 'vote_phase_id', 'owner_id',
        'response', 'voted_at',
    ];

    protected $casts = [
        'voted_at' => 'datetime',
    ];

    public function vote(): BelongsTo
    {
        return $this->belongsTo(Vote::class);
    }

    public function phase(): BelongsTo
    {
        return $this->belongsTo(VotePhase::class, 'vote_phase_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class);
    }
}
