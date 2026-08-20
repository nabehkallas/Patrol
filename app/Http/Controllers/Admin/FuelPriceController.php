<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Currency;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreFuelPriceRequest;
use App\Http\Requests\Admin\UpdateFuelTypeProfitMarginRequest;
use App\Models\ExchangeRate;
use App\Models\FuelPrice;
use App\Models\FuelType;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class FuelPriceController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', FuelPrice::class);

        $sypRate = ExchangeRate::currentRateFor(Currency::SYP);

        return Inertia::render('admin/fuel-prices/index', [
            'fuelTypes' => FuelType::orderBy('name')->get(['id', 'name', 'slug', 'profit_margin_percent'])
                ->map(function (FuelType $fuelType) use ($sypRate) {
                    $currentPrice = $fuelType->currentPrice();

                    return [
                        'id' => $fuelType->id,
                        'name' => $fuelType->name,
                        'slug' => $fuelType->slug,
                        'profit_margin_percent' => $fuelType->profit_margin_percent,
                        'current_price_syp' => $currentPrice ? round($currentPrice->amountInSyp($sypRate), 3) : null,
                    ];
                }),
            'prices' => FuelPrice::with(['fuelType', 'setBy'])
                ->latest('effective_at')
                ->paginate(25),
        ]);
    }

    public function updateProfitMargin(UpdateFuelTypeProfitMarginRequest $request, FuelType $fuelType): RedirectResponse
    {
        $this->authorize('update', $fuelType);

        $fuelType->update(['profit_margin_percent' => $request->validated('profit_margin_percent')]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profit margin updated.')]);

        return to_route('admin.fuel-prices.index');
    }

    public function store(StoreFuelPriceRequest $request): RedirectResponse
    {
        $this->authorize('create', FuelPrice::class);

        $data = $request->validated();
        $data['set_by_id'] = $request->user()->id;
        $data['effective_at'] ??= now();

        FuelPrice::create($data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Fuel price updated.')]);

        return to_route('admin.fuel-prices.index');
    }

    public function destroy(FuelPrice $fuelPrice): RedirectResponse
    {
        $this->authorize('delete', $fuelPrice);

        $fuelPrice->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Fuel price deleted.')]);

        return to_route('admin.fuel-prices.index');
    }
}
