<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTankTransferRequest;
use App\Http\Requests\UpdateTankTransferRequest;
use App\Models\TankTransfer;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class TankTransferController extends Controller
{
    public function store(StoreTankTransferRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $data['recorded_by_id'] = $request->user()->id;

        TankTransfer::create($data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Fuel transferred between tanks.')]);

        return to_route('inventory.index');
    }

    public function edit(TankTransfer $transfer): Response
    {
        $transfer->loadMissing(['fromTank.fuelType', 'toTank.fuelType']);

        return Inertia::render('inventory/transfer-edit', [
            'transfer' => [
                'id' => $transfer->id,
                'liters' => (string) $transfer->liters,
                'notes' => $transfer->notes,
                'date' => $transfer->date->toDateString(),
                'from_tank_name' => $transfer->fromTank
                    ? $transfer->fromTank->fuelType->name.' — '.$transfer->fromTank->name
                    : null,
                'to_tank_name' => $transfer->toTank
                    ? $transfer->toTank->fuelType->name.' — '.$transfer->toTank->name
                    : null,
            ],
        ]);
    }

    // Editing only ever touches liters/notes (never which tanks are involved) -- both tanks'
    // balances are derived live from tank_transfers, transactions, and top-ups, never stored,
    // so changing this row's liters is itself the "recalculation": the next time either tank's
    // expectedLiters() is read, it already reflects the new value.
    public function update(UpdateTankTransferRequest $request, TankTransfer $transfer): RedirectResponse
    {
        $transfer->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Transfer updated.')]);

        return to_route('inventory.index');
    }

    // Same reasoning as update(): deleting the row is the rollback. Both tanks' balances stop
    // counting this transfer the instant it's gone, since expectedLiters() sums live from
    // tank_transfers rather than reading a stored balance.
    public function destroy(TankTransfer $transfer): RedirectResponse
    {
        $transfer->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Transfer deleted.')]);

        return to_route('inventory.index');
    }
}
