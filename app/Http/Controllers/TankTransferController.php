<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTankTransferRequest;
use App\Models\TankTransfer;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

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
}
