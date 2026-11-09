<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Court;
use App\Models\CourtClosure;
use App\Models\PricingRule;
use Carbon\CarbonImmutable;

class SlotService
{
    // shortcut: remise et délai fixés dans le code ; à rendre réglables par le gérant avec la gestion des tarifs.
    public const FLASH_HOURS = 3;

    public const FLASH_PERCENT = 20;

    /** Un créneau devient « flash » quand il reste libre à moins de trois heures du début. */
    public function isFlash(CarbonImmutable $startUtc): bool
    {
        return $startUtc->isFuture() && $startUtc->lte(now()->addHours(self::FLASH_HOURS));
    }

    /** Prix réellement payé pour un créneau classique : tarif, moins la remise flash éventuelle. */
    public function priceCents(PricingRule $rule, CarbonImmutable $startUtc): int
    {
        return $this->isFlash($startUtc) ? (int) round($rule->price_cents * (100 - self::FLASH_PERCENT) / 100) : $rule->price_cents;
    }

    /** Règle de prix d'un créneau, selon l'heure et la date. */
    public function priceFor(CarbonImmutable $startUtc): ?PricingRule
    {
        $local = Clock::local($startUtc);

        return PricingRule::query()
            ->where('start_time', '<=', $local->format('H:i:s'))
            ->where('end_time', '>', $local->format('H:i:s'))
            ->whereDate('valid_from', '<=', $local->toDateString())
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', $local->toDateString()))
            ->orderByDesc('valid_from')
            ->first();
    }

    /** Créneaux d'un jour, terrain par terrain, sans aucune donnée personnelle. */
    public function forDate(string $date): array
    {
        $courts = Court::where('is_active', true)->orderBy('id')->get();
        $dayStart = Clock::slotStart($date, '00:00');
        $busy = Booking::query()
            ->whereIn('status', Booking::ACTIVE)
            ->where('starts_at', '<', $dayStart->addDay())
            ->where('ends_at', '>', $dayStart)
            ->where(fn ($q) => $q->where('status', '!=', 'pending_payment')->orWhere('hold_expires_at', '>', now()))
            ->get(['court_id', 'starts_at', 'ends_at']);

        $closures = CourtClosure::where('starts_at', '<', $dayStart->addDay())->where('ends_at', '>', $dayStart)->get(['court_id', 'starts_at', 'ends_at']);

        $rows = [];
        foreach (Clock::SLOTS as $time) {
            $start = Clock::slotStart($date, $time);
            $end = $start->addMinutes(Clock::SLOT_MINUTES);
            $cells = [];
            foreach ($courts as $court) {
                $closed = $closures->contains(fn ($c) => $c->court_id === $court->id && $c->starts_at < $end && $c->ends_at > $start);
                $taken = $closed || $busy->contains(fn ($b) => $b->court_id === $court->id && $b->starts_at < $end && $b->ends_at > $start);
                $rule = $this->priceFor($start);
                $cells[] = [
                    'court_id' => $court->id,
                    'available' => ! $taken && $rule !== null && $start->isFuture(),
                    'price_cents' => $rule ? $this->priceCents($rule, $start) : null,
                    'flash' => $rule !== null && ! $taken && $this->isFlash($start),
                    'past' => ! $start->isFuture(),
                    'closed' => $closed,
                    'off_peak' => $rule !== null && str_contains(strtolower($rule->name), 'creuses'),
                ];
            }
            $rows[] = ['time' => $time, 'end' => Clock::local($end)->format('H:i'), 'starts_at' => $start->toIso8601String(), 'cells' => $cells];
        }

        return [
            'courts' => $courts->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])->all(),
            'rows' => $rows,
        ];
    }
}
