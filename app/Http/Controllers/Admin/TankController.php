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
            'tanks' => Tank::with('fuelType')
                ->orderBy('fuel_type_id')
                ->orderBy('name')
                ->get()
                ->map(fn (Tank $tank) => [
                    'id' => $tank->id,
                    'fuel_type_id' => $tank->fuel_type_id,
                    'name' => $tank->name,
                    'capacity_liters' => $tank->capacity_liters,
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
            'tank' => $tank->only(['id', 'fuel_type_id', 'name', 'capacity_liters']),
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

    public function destroy(Tank $tank): RedirectResponse
    {
        $this->authorize('delete', $tank);

        $tank->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Tank deleted.')]);

        return to_route('admin.tanks.index');
    }
}
