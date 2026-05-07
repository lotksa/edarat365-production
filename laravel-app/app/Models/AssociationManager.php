<?php

namespace App\Models;

use App\Models\Concerns\EncryptsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssociationManager extends Model
{
    use EncryptsAttributes;

    protected $fillable = [
        'full_name', 'national_id', 'phone', 'email', 'status',
    ];

    protected array $encryptable = [
        'national_id',
    ];

    protected array $blindHashable = [
        'national_id' => 'national_id_hash',
    ];

    public function associations(): HasMany
    {
        return $this->hasMany(Association::class, 'manager_id');
    }
}
