<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Shift;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings > Shifts (station admins). A station with one active shift sees no shift selector
 * anywhere. Readings run in order of day, then shift position, so positions never change: a new
 * shift goes at the end, and a shift with readings can only be deactivated, not deleted.
 */
class ShiftController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('settings/shifts', [
            'shifts' => Shift::withCount('readings')->orderBy('position')->get()->map(fn (Shift $shift) => [
                ...$shift->toOption(),
                'custom_name' => $shift->name,
                'used' => $shift->readings_count > 0,
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $shift = Shift::create([
            ...$data,
            'position' => (int) Shift::max('position') + 1,
            'is_active' => true,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':shift added.', ['shift' => $shift->label()])]);

        return to_route('shifts.index');
    }

    public function update(Request $request, Shift $shift): RedirectResponse
    {
        $data = $this->validated($request) + $request->validate(['is_active' => ['required', 'boolean']]);

        if (! $data['is_active'] && ! Shift::active()->whereKeyNot($shift->id)->exists()) {
            throw ValidationException::withMessages(['is_active' => __('At least one shift must stay active.')]);
        }

        $shift->update($data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':shift saved.', ['shift' => $shift->label()])]);

        return to_route('shifts.index');
    }

    public function destroy(Shift $shift): RedirectResponse
    {
        if ($shift->readings()->exists()) {
            throw ValidationException::withMessages(['shift' => __('This shift has readings, so it can only be deactivated.')]);
        }

        if (! Shift::active()->whereKeyNot($shift->id)->exists()) {
            throw ValidationException::withMessages(['shift' => __('At least one shift must stay active.')]);
        }

        $shift->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Shift deleted.')]);

        return to_route('shifts.index');
    }

    /**
     * @return array{name: ?string, starts_at: ?string, ends_at: ?string}
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:60'],
            'starts_at' => ['nullable', 'date_format:H:i'],
            'ends_at' => ['nullable', 'date_format:H:i'],
        ]);

        return [
            'name' => filled($data['name'] ?? null) ? trim($data['name']) : null,
            'starts_at' => $data['starts_at'] ?? null,
            'ends_at' => $data['ends_at'] ?? null,
        ];
    }
}
