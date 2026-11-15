<?php

namespace App\Services;

use App\Models\Hotel;
use App\Models\Offer;
use Illuminate\Support\Collection;

/** Picks the offer a quote gets. Read-only. */
class OfferService
{
    /** @return Collection<int, Offer> */
    public function published(): Collection
    {
        return Offer::query()->where('status', 'published')->orderBy('id')->get();
    }

    /**
     * The applicable offer with the biggest discount (lowest id on a tie).
     *
     * @param  Collection<int, Offer>  $offers  from published()
     * @return array{offer: Offer, discount: float}|null
     */
    public function best(Collection $offers, Hotel $hotel, string $checkIn, string $checkOut, int $nights, float $subtotal, ?string $code): ?array
    {
        $code = $code !== null && trim($code) !== '' ? strtoupper(trim($code)) : null;
        $best = null;

        foreach ($offers as $offer) {
            if (! $offer->appliesTo($hotel, $checkIn, $checkOut, $nights, $code)) {
                continue;
            }

            $discount = $offer->discountFor($subtotal);
            if ($discount > 0 && ($best === null || $discount > $best['discount'])) {
                $best = ['offer' => $offer, 'discount' => $discount];
            }
        }

        return $best;
    }
}
