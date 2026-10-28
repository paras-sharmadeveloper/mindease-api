<?php

namespace App\Services\Booking;

use App\Models\Booking;
use App\Models\Therapist;
use Carbon\CarbonImmutable;

/**
 * Bookable start times (UTC) for a therapist.
 *
 * Weekly windows are wall-clock times in the therapist's timezone (DST-safe), cut into
 * session_minutes slots every session_minutes + buffer_minutes. A slot is dropped when it
 * overlaps an availability exception or a booking that holds its slot, is inside the
 * minimum notice, or is past max_days_ahead.
 */
class SlotService
{
    /** @return list<CarbonImmutable> */
    public function slots(Therapist $therapist, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $config = config('mindease.bookings');
        $session = $config['session_minutes'];
        $step = $session + $config['buffer_minutes'];

        $now = CarbonImmutable::now();
        $from = $from->utc()->max($now->addHours($config['min_notice_hours']));
        $to = $to->utc()->min($now->addDays($config['max_days_ahead']));

        if ($from >= $to) {
            return [];
        }

        $windows = $therapist->availability()->get()->groupBy('weekday');
        $blocked = $therapist->exceptions()
            ->where('ends_at', '>', $from)->where('starts_at', '<', $to->addMinutes($session))
            ->get(['starts_at', 'ends_at'])
            ->concat($therapist->bookings()->holdingSlot()
                ->where('ends_at', '>', $from)->where('starts_at', '<', $to->addMinutes($session))
                ->get(['starts_at', 'ends_at']));

        $slots = [];
        $tz = $therapist->timezone;

        for ($day = $from->setTimezone($tz)->startOfDay(); $day <= $to->setTimezone($tz); $day = $day->addDay()) {
            foreach ($windows->get($day->dayOfWeek, []) as $window) {
                $end = $day->setTimeFromTimeString($window->end_time);

                for ($start = $day->setTimeFromTimeString($window->start_time); $start->addMinutes($session) <= $end; $start = $start->addMinutes($step)) {
                    $startUtc = $start->utc();
                    $endUtc = $startUtc->addMinutes($session);

                    if ($startUtc < $from || $startUtc >= $to) {
                        continue;
                    }
                    if ($blocked->contains(fn ($b) => $b->starts_at < $endUtc && $b->ends_at > $startUtc)) {
                        continue;
                    }

                    $slots[Booking::slotLock($startUtc)] = $startUtc;
                }
            }
        }

        ksort($slots);

        return array_values($slots);
    }

    public function isBookable(Therapist $therapist, CarbonImmutable $startsAt): bool
    {
        $match = $this->slots($therapist, $startsAt, $startsAt->addMinute());

        return $match !== [] && $match[0]->equalTo($startsAt);
    }
}
