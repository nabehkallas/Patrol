<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FuelPump;
use App\Models\FuelType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FuelPumpController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/fuel-pumps/index', [
            'pumps' => FuelPump::with('fuelType')->orderBy('name')->get(['id', 'name', 'fuel_type_id']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/fuel-pumps/create', [
            'fuelTypes' => $this->fuelTypeOptions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'fuel_type_id' => 'nullable|exists:fuel_types,id',
        ]);

        FuelPump::create($data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Pump created.')]);

        return to_route('admin.fuel-pumps.index');
    }

    public function edit(FuelPump $fuelPump): Response
    {
        return Inertia::render('admin/fuel-pumps/edit', [
            'pump' => $fuelPump->only(['id', 'name', 'fuel_type_id']),
            'fuelTypes' => $this->fuelTypeOptions(),
        ]);
    }

    public function update(Request $request, FuelPump $fuelPump): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'fuel_type_id' => 'nullable|exists:fuel_types,id',
        ]);

        $fuelPump->update($data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Pump updated.')]);

        return to_route('admin.fuel-pumps.index');
    }

    public function destroy(FuelPump $fuelPump): RedirectResponse
    {
        $fuelPump->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Pump deleted.')]);

        return to_route('admin.fuel-pumps.index');
    }

    private function fuelTypeOptions()
    {
        return FuelType::orderBy('name')->get(['id', 'name']);
    }
}
