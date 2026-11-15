<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What a guest may see about their own reservation: confirmation, receipt
 * pricing and a timeline - no internal notes, no staff identities.
 *
 * @mixin \App\Models\Booking
 */
class ReservationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'reference' => $this->reference,
            'status' => $this->status,
            'checkIn' => $this->check_in?->format('Y-m-d'),
            'checkOut' => $this->check_out?->format('Y-m-d'),
            'nights' => $this->nights(),
            'rooms' => $this->rooms,
            'adults' => $this->adults,
            'children' => $this->children,
            'guest' => [
                'fullName' => $this->guest_full_name,
                'email' => $this->guest_email,
                'phone' => $this->guest_phone,
                'specialRequests' => $this->special_requests,
            ],
            'hotel' => $this->whenLoaded('hotel', fn () => [
                'name' => $this->hotel->name,
                'slug' => $this->hotel->slug,
                'address' => $this->hotel->address,
                'area' => $this->hotel->area,
                'phone' => $this->hotel->phone,
                'email' => $this->hotel->email,
                'heroImage' => $this->hotel->hero_image,
                'checkInTime' => $this->hotel->check_in_time,
                'checkOutTime' => $this->hotel->check_out_time,
            ]),
            'roomType' => $this->whenLoaded('roomType', fn () => [
                'name' => $this->roomType->name,
                'bedType' => $this->roomType->bed_type,
            ]),
            'ratePlan' => $this->whenLoaded('ratePlan', fn () => [
                'name' => $this->ratePlan->name,
                'mealBenefit' => $this->ratePlan->meal_benefit,
                'cancellationPolicy' => $this->ratePlan->cancellation_policy,
            ]),
            'pricing' => [
                'subtotal' => $this->subtotal !== null ? (float) $this->subtotal : null,
                'taxesFees' => $this->taxes_fees !== null ? (float) $this->taxes_fees : null,
                'total' => $this->total !== null ? (float) $this->total : null,
            ],
            'timeline' => $this->whenLoaded('events', fn () => $this->events->map(fn ($e) => [
                'type' => $e->type,
                'status' => $e->to_status,
                'fromStatus' => $e->from_status,
                'at' => $e->created_at?->toIso8601String(),
            ])->values()),
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
