<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreExchangeRateRequest;
use App\Models\ExchangeRate;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ExchangeRateController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', ExchangeRate::class);

        return Inertia::render('admin/exchange-rates/index', [
            'rates' => ExchangeRate::with('setBy')
                ->latest('effective_at')
                ->paginate(25),
        ]);
    }

    public function store(StoreExchangeRateRequest $request): RedirectResponse
    {
        $this->authorize('create', ExchangeRate::class);

        $data = $request->validated();
        $data['set_by_id'] = $request->user()->id;
        $data['effective_at'] ??= now();

        ExchangeRate::create($data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Exchange rate updated.')]);

        return to_route('admin.exchange-rates.index');
    }
}
