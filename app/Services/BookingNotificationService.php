<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\Booking;
use App\Models\Developer;
use App\Models\Hotel;
use App\Models\StaffNotification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Fan-out booking alerts to:
 * - every active Developer (all hotels)
 * - every active Admin who canAccessHotel(booking.hotel_id)
 *   (org admin = global; manager = assigned locations; hotel_admin = assigned hotels)
 */
class BookingNotificationService
{
    public function __construct(
        private readonly AccessService $access,
    ) {}

    public function notifyCreated(Booking $booking): void
    {
        $booking->loadMissing('hotel.location');
        $hotel = $booking->hotel;
        if (! $hotel) {
            return;
        }

        $this->fanOut(
            booking: $booking,
            hotel: $hotel,
            type: 'booking.created',
            title: 'New reservation',
            body: sprintf(
                '%s · %s · %s → %s · %s',
                $booking->reference,
                $hotel->name,
                $booking->check_in?->format('Y-m-d') ?? '',
                $booking->check_out?->format('Y-m-d') ?? '',
                $booking->guest_full_name,
            ),
            data: [
                'status' => $booking->status,
                'reference' => $booking->reference,
                'guestName' => $booking->guest_full_name,
            ],
        );
    }

    public function notifyStatusChanged(Booking $booking, string $from, string $to): void
    {
        if ($from === $to) {
            return;
        }

        $booking->loadMissing('hotel.location');
        $hotel = $booking->hotel;
        if (! $hotel) {
            return;
        }

        $this->fanOut(
            booking: $booking,
            hotel: $hotel,
            type: 'booking.status_changed',
            title: 'Reservation '.$to,
            body: sprintf(
                '%s · %s · %s → %s',
                $booking->reference,
                $hotel->name,
                $from,
                $to,
            ),
            data: [
                'status' => $to,
                'fromStatus' => $from,
                'reference' => $booking->reference,
                'guestName' => $booking->guest_full_name,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function fanOut(
        Booking $booking,
        Hotel $hotel,
        string $type,
        string $title,
        string $body,
        array $data,
    ): void {
        $locationId = $hotel->location_id;

        foreach ($this->recipientsForHotel((int) $hotel->id) as $recipient) {
            StaffNotification::create([
                'notifiable_type' => $recipient::class,
                'notifiable_id' => $recipient->getKey(),
                'type' => $type,
                'booking_id' => $booking->id,
                'hotel_id' => $hotel->id,
                'location_id' => $locationId,
                'title' => $title,
                'body' => $body,
                'data' => [
                    ...$data,
                    'bookingId' => $booking->id,
                    'hotelId' => $hotel->id,
                    'hotelName' => $hotel->name,
                    'locationId' => $locationId,
                ],
            ]);
        }
    }

    /**
     * @return Collection<int, Model>
     */
    private function recipientsForHotel(int $hotelId): Collection
    {
        $developers = Developer::query()
            ->where('status', 'active')
            ->get();

        $admins = Admin::query()
            ->where('status', 'active')
            ->with(['packages.roles'])
            ->get()
            ->filter(fn (Admin $admin) => $this->access->canAccessHotel($admin, $hotelId))
            ->values();

        return $developers->concat($admins)->values();
    }
}
