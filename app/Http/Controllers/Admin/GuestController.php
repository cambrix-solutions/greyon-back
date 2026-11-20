<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\AuthorizesAdminPanel;
use App\Http\Controllers\Concerns\ScopesReservations;
use App\Http\Controllers\Controller;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use App\Models\GuestNote;
use App\Services\AccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Spec "Guests" - perm `guests`. A guest is a person on one or more
 * reservations (matched by e-mail), limited to the hotels this seat can see.
 */
class GuestController extends Controller
{
    use AuthorizesAdminPanel, ScopesReservations;

    public function __construct(private readonly AccessService $access) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $guests = $this->guestList($request, $data['q'] ?? null);

        $perPage = (int) ($data['perPage'] ?? 25);
        $page = (int) ($data['page'] ?? 1);

        return response()->json([
            'guests' => $guests->forPage($page, $perPage)->values(),
            'meta' => [
                'total' => $guests->count(),
                'page' => $page,
                'perPage' => $perPage,
                'lastPage' => max(1, (int) ceil($guests->count() / $perPage)),
            ],
        ]);
    }

    /** GET /admin/guests/export?q= - the same list as index(), as CSV (no staff notes). */
    public function export(Request $request): StreamedResponse
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $guests = $this->guestList($request, $data['q'] ?? null);

        return response()->streamDownload(function () use ($guests) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Guest', 'Email', 'Phone', 'Reservations', 'Cancelled', 'Nights', 'Value', 'Last check-in', 'Last reservation']);

            foreach ($guests as $g) {
                fputcsv($out, array_map($this->safeCell(...), [
                    $g['fullName'], $g['email'], $g['phone'],
                    $g['reservations'], $g['cancelled'], $g['nights'], $g['reservedValue'],
                    $g['lastCheckIn'], $g['lastReservationAt'],
                ]));
            }
            fclose($out);
        }, 'guests-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return Collection<int, array<string, mixed>> newest first */
    private function guestList(Request $request, ?string $term): Collection
    {
        $notes = GuestNote::query()->pluck('note', 'email');

        return $this->scopedReservations($request)
            ->groupBy(fn (Booking $b) => mb_strtolower(trim($b->guest_email)))
            ->map(fn (Collection $rows, string $email) => $this->summary($email, $rows, $notes[$email] ?? null))
            ->when($term, function (Collection $all, string $term) {
                $term = mb_strtolower($term);

                return $all->filter(fn (array $g) => str_contains(mb_strtolower((string) $g['fullName']), $term)
                    || str_contains($g['email'], $term)
                    || str_contains((string) $g['phone'], $term));
            })
            ->sortByDesc('lastReservationAt')
            ->values();
    }

    /** Stops spreadsheet formulas: a cell starting with = + - @ is read as a formula by Excel. */
    private function safeCell(mixed $value): mixed
    {
        if (is_string($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$value;
        }

        return $value;
    }

    /** GET /admin/guests/profile?email= - guest summary, note and every reservation they can see. */
    public function show(Request $request): JsonResponse
    {
        $email = $this->emailParam($request);
        $rows = $this->reservationsFor($request, $email);

        return response()->json([
            'guest' => $this->summary($email, $rows, GuestNote::where('email', $email)->value('note')),
            'reservations' => BookingResource::collection($rows),
        ]);
    }

    /** PUT /admin/guests/note - {email, note}; an empty note clears it. */
    public function note(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $email = mb_strtolower(trim($data['email']));
        $this->reservationsFor($request, $email); // 404 unless this seat can see the guest

        $text = trim((string) ($data['note'] ?? ''));
        if ($text === '') {
            GuestNote::where('email', $email)->delete();

            return response()->json(['note' => null]);
        }

        GuestNote::updateOrCreate(
            ['email' => $email],
            ['note' => $text, 'updated_by_admin_id' => $this->actingAdmin($request)?->id]
        );

        return response()->json(['note' => $text]);
    }

    private function emailParam(Request $request): string
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255']]);

        return mb_strtolower(trim($data['email']));
    }

    /** @return Collection<int, Booking> */
    private function reservationsFor(Request $request, string $email): Collection
    {
        $rows = $this->scopedReservations($request)
            ->filter(fn (Booking $b) => mb_strtolower(trim($b->guest_email)) === $email)
            ->values();

        if ($rows->isEmpty()) {
            throw new HttpException(404, 'Not found.');
        }

        return $rows;
    }

    /**
     * @param  Collection<int, Booking>  $rows  newest first
     * @return array<string, mixed>
     */
    private function summary(string $email, Collection $rows, ?string $note): array
    {
        $latest = $rows->first();
        $kept = $rows->where('status', '!=', 'cancelled');

        return [
            'email' => $email,
            'fullName' => $latest->guest_full_name,
            'phone' => $latest->guest_phone,
            'reservations' => $rows->count(),
            'cancelled' => $rows->count() - $kept->count(),
            'nights' => $kept->sum(fn (Booking $b) => $b->nights()),
            // Value of reservations that were not cancelled - not money received.
            'reservedValue' => round((float) $kept->sum('total'), 2),
            'firstReservationAt' => $rows->last()->created_at?->toIso8601String(),
            'lastReservationAt' => $latest->created_at?->toIso8601String(),
            'lastCheckIn' => $rows->max('check_in')?->format('Y-m-d'),
            'note' => $note,
        ];
    }
}
