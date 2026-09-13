<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreTankRequest;
use App\Http\Requests\Admin\UpdateTankRequest;
use App\Models\FuelType;
use App\Models\Tank;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class TankController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', Tank::class);

        return Inertia::render('admin/tanks/index', [
            // Soft-deleted tanks are excluded automatically (SoftDeletes' global scope) --
            // inactive ones stay listed here since this is the admin control panel itself,
            // just filtered out of every operational selector elsewhere in the app.
            'tanks' => Tank::with('fuelType')
                ->orderBy('fuel_type_id')
                ->orderBy('name')
                ->get()
                ->map(fn (Tank $tank) => [
                    'id' => $tank->id,
                    'fuel_type_id' => $tank->fuel_type_id,
                    'name' => $tank->name,
                    'capacity_liters' => $tank->capacity_liters,
                    'is_active' => $tank->is_active,
                    'fuel_type' => $tank->fuelType->only(['id', 'name']),
                ]),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Tank::class);

        return Inertia::render('admin/tanks/create', [
            'fuelTypes' => FuelType::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(StoreTankRequest $request): RedirectResponse
    {
        $this->authorize('create', Tank::class);

        Tank::create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Tank created.')]);

        return to_route('admin.tanks.index');
    }

    public function edit(Tank $tank): Response
    {
        $this->authorize('update', $tank);

        return Inertia::render('admin/tanks/edit', [
            'tank' => $tank->only(['id', 'fuel_type_id', 'name', 'capacity_liters', 'is_active']),
            'fuelTypes' => FuelType::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(UpdateTankRequest $request, Tank $tank): RedirectResponse
    {
        $this->authorize('update', $tank);

        $tank->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Tank updated.')]);

        return to_route('admin.tanks.index');
    }

    /**
     * Flips is_active from the tanks list itself -- a quick operational switch, separate from
     * the full edit form. Inactive just means "not offered for new assignments"; existing pumps/
     * readings/transfers that already reference this tank are completely unaffected.
     */
    public function toggleActive(Tank $tank): RedirectResponse
    {
        $this->authorize('update', $tank);

        $tank->update(['is_active' => ! $tank->is_active]);

        $message = $tank->is_active ? __('Tank activated.') : __('Tank deactivated.');
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }

    public function destroy(Tank $tank): RedirectResponse
    {
        $this->authorize('delete', $tank);

        // A real delete would hit tank_transfers.from_tank_id/to_tank_id's restrictOnDelete()
        // (or silently cascade/null-out transactions, readings, top-ups, inventory entries) --
        // soft-deleting instead leaves every historical row's tank_id pointing at a real,
        // still-resolvable row (see the withTrashed() relations on those models), so nothing
        // breaks and no FK constraint ever fires. The tank itself disappears from every normal
        // query (active lists, dashboards, selectors) via SoftDeletes' global scope.
        $tank->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Tank deleted.')]);

        return to_route('admin.tanks.index');
    }
}
