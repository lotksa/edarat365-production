<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LegalRepresentative extends Model
{
    protected $fillable = [
        'name', 'email', 'phone', 'specialty', 'license_number',
        'firm_name', 'user_id', 'status', 'notes', 'created_by',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function casePermissions(): HasMany
    {
        return $this->hasMany(CasePermission::class, 'representative_id');
    }
}
