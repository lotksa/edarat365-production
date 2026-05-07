<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoginOtp extends Model
{
    protected $fillable = [
        'identifier',
        'channel',
        'purpose',
        'code',
        'code_hash',
        'expires_at',
        'used_at',
        'attempts',
    ];

    protected $hidden = [
        'code',
        'code_hash',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at'    => 'datetime',
            'attempts'   => 'integer',
        ];
    }
}
