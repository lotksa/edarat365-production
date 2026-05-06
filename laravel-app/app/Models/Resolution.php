<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Resolution extends Model
{
    protected $fillable = [
        'resolution_number', 'meeting_id', 'title', 'resolution_type',
        'description', 'yes_votes', 'no_votes', 'abstain_votes', 'status',
    ];

    protected $casts = [
        'yes_votes'     => 'integer',
        'no_votes'      => 'integer',
        'abstain_votes' => 'integer',
    ];

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }
}
