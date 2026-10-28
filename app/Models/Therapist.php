<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Therapist extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['specialties' => 'array', 'active' => 'boolean', 'price_cents' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function availability(): HasMany
    {
        return $this->hasMany(TherapistAvailability::class);
    }

    public function exceptions(): HasMany
    {
        return $this->hasMany(AvailabilityException::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function priceCents(): int
    {
        return $this->price_cents ?? config('mindease.bookings.default_price_cents');
    }
}
