<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreFuelTypeRequest;
use App\Http\Requests\Admin\UpdateFuelTypeRequest;
use App\Models\FuelType;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class FuelTypeController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', FuelType::class);

        return Inertia::render('admin/fuel-types/index', [
            'fuelTypes' => FuelType::orderBy('name')->get(['id', 'name', 'slug']),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', FuelType::class);

        return Inertia::render('admin/fuel-types/create');
    }

    public function store(StoreFuelTypeRequest $request): RedirectResponse
    {
        $this->authorize('create', FuelType::class);

        FuelType::create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Fuel type created.')]);

        return to_route('admin.fuel-types.index');
    }

    public function edit(FuelType $fuelType): Response
    {
        $this->authorize('update', $fuelType);

        return Inertia::render('admin/fuel-types/edit', [
            'fuelType' => $fuelType->only(['id', 'name', 'slug']),
        ]);
    }

    public function update(UpdateFuelTypeRequest $request, FuelType $fuelType): RedirectResponse
    {
        $this->authorize('update', $fuelType);

        $fuelType->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Fuel type updated.')]);

        return to_route('admin.fuel-types.index');
    }

    public function destroy(FuelType $fuelType): RedirectResponse
    {
        $this->authorize('delete', $fuelType);

        $fuelType->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Fuel type deleted.')]);

        return to_route('admin.fuel-types.index');
    }
}
