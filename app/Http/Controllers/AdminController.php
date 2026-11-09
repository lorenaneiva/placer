<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Reservation;
use App\Models\ScheduleBlock;
use App\Models\Space;
use App\Services\SchedulingService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AdminController extends Controller
{
    public function dashboard(): View
    {
        return view('admin.dashboard', ['categories' => Category::count(), 'spaces' => Space::count(), 'reservations' => Reservation::where('status', 'confirmed')->where('starts_at', '>', now())->count(), 'blocks' => ScheduleBlock::where('ends_at', '>', now())->count()]);
    }

    public function categories(): View
    {
        $categories = Category::withCount(['spaces', 'reservations as future_reservations_count' => fn ($query) => $query->where('status', 'confirmed')->where('starts_at', '>', now())])->orderBy('name')->paginate(15);

        return view('admin.categories', compact('categories'));
    }

    public function categoryForm(?Category $category = null): View
    {
        return view('admin.category-form', ['category' => $category ?? new Category(['is_active' => true, 'max_advance_days' => 21])]);
    }

    public function saveCategory(Request $request, ?Category $category = null): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255', Rule::unique('categories')->ignore($category?->id)], 'max_advance_days' => 'required|integer|min:0|max:365', 'is_active' => 'required|boolean']);
        DB::transaction(function () use ($category, $data) {
            if ($category) {
                Category::whereKey($category->id)->lockForUpdate()->firstOrFail()->update($data);
            } else {
                Category::create($data);
            }
        }, 3);
        $count = $category && ! $data['is_active'] ? Reservation::whereHas('space', fn ($q) => $q->where('category_id', $category->id))->where('status', 'confirmed')->where('starts_at', '>', now())->count() : 0;

        return redirect()->route('admin.categories')->with('success', 'Categoria salva.'.($count ? " Atenção: {$count} reserva(s) futura(s) preservada(s) nesta categoria desativada." : ''));
    }

    public function spaces(): View
    {
        $spaces = Space::with('category')->withCount(['reservations as future_reservations_count' => fn ($q) => $q->where('status', 'confirmed')->where('starts_at', '>', now())])->orderBy('category_id')->paginate(15);

        return view('admin.spaces', compact('spaces'));
    }

    public function spaceForm(?Space $space = null): View
    {
        return view('admin.space-form', ['space' => $space ?? new Space(['is_active' => true]), 'categories' => Category::orderBy('name')->get()]);
    }

    public function saveSpace(Request $request, ?Space $space = null): RedirectResponse
    {
        $data = $request->validate(['category_id' => 'required|integer|exists:categories,id', 'identifier' => ['required', 'string', 'max:255', Rule::unique('spaces')->where('category_id', $request->input('category_id'))->ignore($space?->id)], 'description' => 'nullable|string|max:5000', 'hourly_price' => 'required|numeric|min:0|max:99999999.99|decimal:0,2', 'capacity' => 'required|integer|min:1|max:1000000', 'is_active' => 'required|boolean']);
        DB::transaction(function () use ($space, $data) {
            $current = $space ? Space::whereKey($space->id)->lockForUpdate()->firstOrFail() : null;
            $category = Category::whereKey($data['category_id'])->sharedLock()->firstOrFail();
            if (! $category->is_active && (! $current || $current->category_id !== $category->id || $data['is_active'])) {
                throw ValidationException::withMessages(['category_id' => 'Escolha uma categoria ativa para cadastrar ou ativar o espaço.']);
            }
            if ($current) {
                $current->update($data);
            } else {
                Space::create($data);
            }
        }, 3);
        $count = $space && ! $data['is_active'] ? $space->reservations()->where('status', 'confirmed')->where('starts_at', '>', now())->count() : 0;

        return redirect()->route('admin.spaces')->with('success', 'Espaço salvo.'.($count ? " Atenção: {$count} reserva(s) futura(s) preservada(s) neste espaço desativado." : ''));
    }

    public function reservations(Request $request): View
    {
        $request->validate(['date' => 'nullable|date_format:Y-m-d', 'space_id' => 'nullable|integer|exists:spaces,id', 'status' => ['nullable', Rule::in(['confirmed', 'cancelled'])]]);
        $reservations = Reservation::with('space.category', 'user')->when($request->filled('date'), function ($q) use ($request) {
            $start = CarbonImmutable::parse($request->date, config('placer.timezone'))->startOfDay()->utc();
            $q->where('starts_at', '>=', $start)->where('starts_at', '<', $start->addDay());
        })->when($request->filled('space_id'), fn ($q) => $q->where('space_id', $request->integer('space_id')))->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))->orderByDesc('starts_at')->paginate(20)->withQueryString();

        return view('admin.reservations', ['reservations' => $reservations, 'spaces' => Space::with('category')->get()]);
    }

    public function reservationForm(Request $request, Reservation $reservation, SchedulingService $schedule): View
    {
        $request->validate(['space_id' => 'nullable|integer|exists:spaces,id', 'date' => 'nullable|date_format:Y-m-d']);
        $space = Space::with('category')->findOrFail($request->input('space_id') ?: $reservation->space_id);
        $date = $request->input('date') ?: $reservation->starts_at->timezone(config('placer.timezone'))->toDateString();

        return view('admin.reservation-form', ['reservation' => $reservation, 'spaces' => Space::with('category')->get(), 'selectedSpace' => $space, 'date' => $date, 'slots' => $schedule->slots($space, $date, $reservation->id)]);
    }

    public function reschedule(Request $request, Reservation $reservation, SchedulingService $schedule): RedirectResponse
    {
        $data = $request->validate(['space_id' => 'required|integer|exists:spaces,id', 'starts_at' => 'required|date_format:Y-m-d\TH:i']);
        $schedule->reserve($reservation->user, (int) $data['space_id'], $data['starts_at'], $reservation);

        return redirect()->route('reservations.show', $reservation)->with('success', 'Reserva reagendada com sucesso.');
    }

    public function blocks(): View
    {
        return view('admin.blocks', ['blocks' => ScheduleBlock::with('space.category', 'creator')->orderByDesc('starts_at')->paginate(20), 'spaces' => Space::with('category')->get()]);
    }

    public function saveBlock(Request $request, SchedulingService $schedule): RedirectResponse
    {
        $data = $request->validate(['space_id' => 'required|integer|exists:spaces,id', 'starts_at' => 'required|date_format:Y-m-d\TH:i', 'ends_at' => 'required|date_format:Y-m-d\TH:i', 'reason' => 'nullable|string|max:1000']);
        $schedule->block($request->user(), (int) $data['space_id'], $data['starts_at'], $data['ends_at'], $data['reason'] ?? null);

        return redirect()->route('admin.blocks')->with('success', 'Bloqueio cadastrado.');
    }

    public function deleteBlock(Request $request, ScheduleBlock $block, SchedulingService $schedule): RedirectResponse
    {
        $request->validate(['confirm' => 'accepted']);
        $schedule->unblock($block);

        return back()->with('success', 'Bloqueio removido.');
    }
}
