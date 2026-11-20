<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Booking
 */
class BookingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'userId' => $this->user_id,
            'reference' => $this->reference,
            'hotelId' => $this->hotel_id,
            'roomTypeId' => $this->room_type_id,
            'ratePlanId' => $this->rate_plan_id,
            'hotel' => $this->whenLoaded('hotel', fn () => [
                'id' => $this->hotel->id,
                'name' => $this->hotel->name,
                'slug' => $this->hotel->slug,
                'heroImage' => $this->hotel->hero_image,
            ]),
            'roomType' => $this->whenLoaded('roomType', fn () => [
                'id' => $this->roomType->id,
                'name' => $this->roomType->name,
            ]),
            'ratePlan' => $this->whenLoaded('ratePlan', fn () => [
                'id' => $this->ratePlan->id,
                'name' => $this->ratePlan->name,
            ]),
            'checkIn' => $this->check_in?->format('Y-m-d'),
            'checkOut' => $this->check_out?->format('Y-m-d'),
            'rooms' => $this->rooms,
            'adults' => $this->adults,
            'children' => $this->children,
            'guest' => array_filter([
                'fullName' => $this->guest_full_name,
                'email' => $this->guest_email,
                'phone' => $this->guest_phone,
                'specialRequests' => $this->special_requests,
            ], fn ($v) => $v !== null && $v !== ''),
            'guestFullName' => $this->guest_full_name,
            'guestEmail' => $this->guest_email,
            'guestPhone' => $this->guest_phone,
            'specialRequests' => $this->special_requests,
            'subtotal' => $this->subtotal !== null ? (float) $this->subtotal : null,
            'taxesFees' => $this->taxes_fees !== null ? (float) $this->taxes_fees : null,
            'total' => $this->total !== null ? (float) $this->total : null,
            'status' => $this->status,
            'source' => $this->source,
            'notes' => $this->when(! $request->routeIs('public.*', 'my.*'), $this->notes),
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
