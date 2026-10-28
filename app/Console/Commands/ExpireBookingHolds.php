<?php

namespace App\Console\Commands;

use App\Services\Booking\BookingService;
use Illuminate\Console\Command;

class ExpireBookingHolds extends Command
{
    protected $signature = 'mindease:expire-booking-holds';

    protected $description = 'Cancel unpaid pending bookings whose hold has lapsed, so the slot is offered again';

    public function handle(BookingService $bookings): int
    {
        $this->line('Lapsed holds: '.$bookings->expireHolds());

        return self::SUCCESS;
    }
}
