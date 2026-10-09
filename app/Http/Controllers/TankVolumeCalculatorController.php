<?php

namespace App\Http\Controllers;

use App\Models\Tank;
use Inertia\Inertia;
use Inertia\Response;

class TankVolumeCalculatorController extends Controller
{
    /** The station's active tanks, so a tank can be picked to fill in its capacity. */
    public function index(): Response
    {
        return Inertia::render('tools/tank-volume', [
            'tanks' => Tank::with('fuelType')->where('is_active', true)->orderBy('name')->get()
                ->map(fn (Tank $tank) => [
                    'id' => $tank->id,
                    'name' => $tank->name,
                    'fuel_type_id' => $tank->fuel_type_id,
                    'fuel_type_name' => $tank->fuelType->name,
                    'capacity_liters' => (float) $tank->capacity_liters,
                ])
                ->values(),
        ]);
    }
}
