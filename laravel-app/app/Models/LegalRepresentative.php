<?php

namespace App\Models;

use App\Models\Concerns\EncryptsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LegalRepresentative extends Model
{
    use EncryptsAttributes;

    protected $fillable = [
        'name', 'email', 'phone', 'specialty', 'license_number',
        'firm_name', 'user_id', 'status', 'notes', 'created_by',
    ];

    protected array $encryptable = [
        'license_number',
    ];

    protected array $blindHashable = [
        'license_number' => 'license_number_hash',
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
