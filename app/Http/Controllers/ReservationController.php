<?php

namespace App\Http\Controllers;

use App\Models\Reservation;
use App\Services\SchedulingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ReservationController extends Controller
{
    public function index(Request $request): View
    {
        $reservations = $request->user()->reservations()->with('space.category')->orderByDesc('starts_at')->paginate(15);

        return view('reservations.index', compact('reservations'));
    }

    public function store(Request $request, SchedulingService $schedule): RedirectResponse
    {
        $data = $request->validate(['space_id' => 'required|integer|exists:spaces,id', 'starts_at' => 'required|date_format:Y-m-d\TH:i']);
        $reservation = $schedule->reserve($request->user(), (int) $data['space_id'], $data['starts_at']);

        return redirect()->route('reservations.show', $reservation)->with('success', 'Reserva confirmada com sucesso!');
    }

    public function show(Request $request, Reservation $reservation): View
    {
        abort_unless($request->user()->is_admin || $reservation->user_id === $request->user()->id, 403);
        $reservation->load('space.category', 'user');

        return view('reservations.show', compact('reservation'));
    }

    public function cancel(Request $request, Reservation $reservation, SchedulingService $schedule): RedirectResponse
    {
        $request->validate(['confirm' => 'accepted']);
        $schedule->cancel($reservation, $request->user());

        return back()->with('success', 'Reserva cancelada. O horário foi liberado.');
    }
}
