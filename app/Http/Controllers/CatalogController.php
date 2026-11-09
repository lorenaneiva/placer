<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Space;
use App\Services\SchedulingService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CatalogController extends Controller
{
    public function index(Request $request): View
    {
        $request->validate(['category' => 'nullable|integer|exists:categories,id']);
        $categories = Category::where('is_active', true)->orderBy('name')->get();
        $spaces = Space::with('category')->where('is_active', true)->whereHas('category', fn ($q) => $q->where('is_active', true))->when($request->filled('category'), fn ($q) => $q->where('category_id', $request->integer('category')))->orderBy('identifier')->paginate(12)->withQueryString();

        return view('catalog', compact('categories', 'spaces'));
    }

    public function show(Request $request, Space $space, SchedulingService $schedule): View
    {
        abort_unless($space->is_active && $space->category->is_active, 404);
        $request->validate(['date' => 'nullable|date_format:Y-m-d']);
        $date = $request->input('date') ?: now(config('placer.timezone'))->toDateString();
        if (! $request->user()) {
            $request->session()->put('url.intended', route('spaces.show', ['space' => $space, 'date' => $date]));
        }
        $slots = $schedule->slots($space, $date);

        return view('space', compact('space', 'date', 'slots'));
    }
}
