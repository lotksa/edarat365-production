<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Owner extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id', 'national_id', 'full_name', 'avatar',
        'phone', 'email', 'status', 'previous_account_id',
        'has_national_address', 'address_type', 'address_short_code',
        'address_region', 'address_city', 'address_district', 'address_street',
        'address_building_no', 'address_additional_no', 'address_postal_code', 'address_unit_no',
    ];

    protected $casts = [
        'has_national_address' => 'boolean',
    ];

    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class, 'subject_id')
            ->where('subject_type', 'owner')
            ->orderByDesc('created_at');
    }

    public function previousAccount(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_account_id')
            ->withTrashed();
    }

    protected static function booted(): void
    {
        static::creating(function (Owner $owner) {
            if (empty($owner->account_number)) {
                $last = static::query()->max('account_number');
                $owner->account_number = $last ? ((int) $last + 1) : 100000;
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function units(): BelongsToMany
    {
        return $this->belongsToMany(Unit::class, 'unit_owners')
            ->using(UnitOwner::class)
            ->withPivot('ownership_ratio')
            ->withTimestamps();
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    public function maintenanceRequests(): HasMany
    {
        return $this->hasMany(MaintenanceRequest::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function vouchers(): HasMany
    {
        return $this->hasMany(Voucher::class);
    }

    public function voteResponses(): HasMany
    {
        return $this->hasMany(VoteResponse::class);
    }
}
