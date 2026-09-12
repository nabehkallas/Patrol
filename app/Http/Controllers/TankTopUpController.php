<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTankTopUpRequest;
use App\Http\Requests\UpdateTankTopUpRequest;
use App\Models\Tank;
use App\Models\TankTopUp;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class TankTopUpController extends Controller
{
    public function store(StoreTankTopUpRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $data['recorded_by_id'] = $request->user()->id;

        TankTopUp::create($data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Liters added to tank.')]);

        return to_route('inventory.index');
    }

    public function edit(TankTopUp $topUp): Response
    {
        return Inertia::render('inventory/topup-edit', [
            'topUp' => [
                'id' => $topUp->id,
                'tank_id' => $topUp->tank_id,
                'date' => $topUp->date->toDateString(),
                'liters' => (string) $topUp->liters,
                'notes' => $topUp->notes,
            ],
            'tanks' => Tank::with('fuelType')
                ->orderBy('fuel_type_id')
                ->orderBy('name')
                ->get()
                ->map(fn (Tank $tank) => [
                    'id' => $tank->id,
                    'name' => $tank->name,
                    'fuel_type_id' => $tank->fuel_type_id,
                    'fuel_type_name' => $tank->fuelType->name,
                ]),
        ]);
    }

    public function update(UpdateTankTopUpRequest $request, TankTopUp $topUp): RedirectResponse
    {
        $topUp->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Entry updated.')]);

        return to_route('inventory.index');
    }

    public function destroy(TankTopUp $topUp): RedirectResponse
    {
        $topUp->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Entry deleted.')]);

        return to_route('inventory.index');
    }
}
