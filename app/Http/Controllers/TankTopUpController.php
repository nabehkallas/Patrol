<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTankTopUpRequest;
use App\Models\TankTopUp;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

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
}
