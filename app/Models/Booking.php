<?php

namespace App\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Status flow: pending (slot held, awaiting payment) -> confirmed (Stripe webhook only)
 * -> completed | no_show (panel). pending/confirmed -> cancelled.
 */
class Booking extends Model
{
    use HasUuids;

    public const PENDING = 'pending';

    public const CONFIRMED = 'confirmed';

    public const CANCELLED = 'cancelled';

    public const COMPLETED = 'completed';

    public const NO_SHOW = 'no_show';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'hold_expires_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'price_cents' => 'integer',
            'notes' => 'encrypted',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function therapist(): BelongsTo
    {
        return $this->belongsTo(Therapist::class);
    }

    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class);
    }

    /** Bookings that block their slot: confirmed/completed/no_show, or pending with a live hold. */
    public function scopeHoldingSlot(Builder $query): void
    {
        $query->whereNotNull('slot_lock')->where(fn (Builder $q) => $q
            ->where('status', '!=', self::PENDING)
            ->orWhere('hold_expires_at', '>', now()));
    }

    public static function slotLock(DateTimeInterface $startsAt): string
    {
        return Carbon::instance($startsAt)->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
