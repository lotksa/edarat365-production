<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VotePhase extends Model
{
    protected $fillable = [
        'vote_id', 'phase_number',
        'start_date', 'end_date',
        'votes_yes', 'votes_no', 'votes_abstain',
        'quorum_met', 'status',
    ];

    protected $casts = [
        'start_date'   => 'date',
        'end_date'     => 'date',
        'quorum_met'   => 'boolean',
        'phase_number' => 'integer',
        'votes_yes'    => 'integer',
        'votes_no'     => 'integer',
        'votes_abstain'=> 'integer',
    ];

    public function vote(): BelongsTo
    {
        return $this->belongsTo(Vote::class);
    }

    public function responses(): HasMany
    {
        return $this->hasMany(VoteResponse::class);
    }
}
