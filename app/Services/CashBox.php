<?php

namespace App\Services;

use App\Http\Controllers\CashBoxController;
use App\Models\ExchangeRate;
use App\Support\Currency;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Money leaving the cash box, one box per currency. Paying in a currency other than the
 * primary one comes either from that currency's own box, or -- converted at a rate the user
 * gives -- from the primary-currency box; then the record is in the primary currency (what
 * actually left the box) and keeps the original amount, currency and rate as information.
 *
 * A box other than the primary one can't go below zero: there is no such cash. The primary box
 * may (expenses are often entered before the day's sales), but only once the user confirms.
 */
class CashBox
{
    /** From the box of the record's own currency. */
    public const FROM_OWN = 'own';

    /** From the primary-currency box, converted at a rate. */
    public const FROM_PRIMARY = 'primary';

    /** How far (as a fraction) a non-admin's conversion rate may be from today's saved one. */
    public const RATE_TOLERANCE = 0.10;

    /** The flag a form sends back once the user accepted the primary box going negative. */
    public const CONFIRM_FIELD = 'confirm_negative_cash';

    /**
     * Each box's balance now: everything recorded up to the end of today, the same figure as
     * the Cash Box page's current balance.
     *
     * @return array<string, float>
     */
    public function balances(): array
    {
        return app(CashBoxController::class)->summarize(
            Carbon::create(2000, 1, 1)->startOfDay(),
            now()->endOfDay(),
            true,
            0,
        )['net'];
    }

    /**
     * What a form taking money out needs: each box's balance, and today's saved rates (units
     * per 1 USD, null where none is saved) to pre-fill a conversion rate.
     *
     * @return array{cashBalances: array<string, float>, cashRates: array<string, float|null>}
     */
    public function formProps(): array
    {
        $rates = [];

        foreach (Currency::codes() as $code) {
            $rates[$code] = $this->savedRate($code);
        }

        return ['cashBalances' => $this->balances(), 'cashRates' => $rates];
    }

    /** Today's saved rate for a currency, in units per 1 USD; null when none is saved. */
    private function savedRate(string $code): ?float
    {
        if ($code === Currency::USD) {
            return 1.0;
        }

        $rate = ExchangeRate::query()
            ->where('currency', $code)
            ->where('effective_at', '<=', now())
            ->latest('effective_at')
            ->value('rate_to_usd');

        return $rate !== null ? (float) $rate : null;
    }

    /**
     * A non-admin pays from the primary box at a rate within RATE_TOLERANCE of today's saved one
     * (1 $currency = X primary, as the form pre-fills it); an admin may give any rate. With no
     * saved rate to compare against, only an admin can pay at a rate.
     */
    private function assertRateAllowed(float $rate, string $currency, string $primary, bool $anyRate): void
    {
        if ($anyRate) {
            return;
        }

        $from = $this->savedRate($currency);
        $to = $this->savedRate($primary);

        if ($from === null || $to === null) {
            throw ValidationException::withMessages(['cash_rate' => __('There is no saved exchange rate for today. Only an admin can pay at a rate.')]);
        }

        $saved = $to / $from;

        if (abs($rate - $saved) > $saved * self::RATE_TOLERANCE) {
            throw ValidationException::withMessages(['cash_rate' => __('The rate must be within :percent% of today\'s rate (:rate). Only an admin can enter another rate.', [
                'percent' => (int) round(self::RATE_TOLERANCE * 100),
                'rate' => rtrim(rtrim(number_format($saved, 6, '.', ''), '0'), '.'),
            ])]);
        }
    }

    /**
     * What a record taking $amount of $currency out of the box is stored as, after checking
     * the box can pay it. $giveBack is what the record being edited already took out, per
     * currency (it goes back in before the check).
     *
     * @param  array<string, float>  $giveBack
     * @return array{amount: float, currency: string, original_amount: float|null, original_currency: string|null, original_rate: float|null}
     */
    public function moneyOut(float $amount, string $currency, ?string $payFrom, mixed $rate, bool $confirmed, array $giveBack = [], string $field = 'amount', bool $anyRate = false): array
    {
        $primary = Currency::primary();

        if ($currency === $primary || $payFrom !== self::FROM_PRIMARY) {
            $this->assertCanPay([$currency => $amount], $confirmed, $giveBack, $field, convertible: $currency !== $primary);

            return ['amount' => $amount, 'currency' => $currency, 'original_amount' => null, 'original_currency' => null, 'original_rate' => null];
        }

        if (! is_numeric($rate) || (float) $rate <= 0) {
            throw ValidationException::withMessages(['cash_rate' => __('Enter the exchange rate.')]);
        }

        $this->assertRateAllowed((float) $rate, $currency, $primary, $anyRate);

        $converted = round($amount * (float) $rate, Currency::decimals($primary));
        $this->assertCanPay([$primary => $converted], $confirmed, $giveBack, $field);

        return [
            'amount' => $converted,
            'currency' => $primary,
            'original_amount' => $amount,
            'original_currency' => $currency,
            'original_rate' => (float) $rate,
        ];
    }

    /**
     * Checks the boxes can pay these amounts (per currency): a box other than the primary one
     * must hold enough; the primary one only asks first. Throws a validation error otherwise.
     *
     * @param  array<string, float>  $amounts
     * @param  array<string, float>  $giveBack
     */
    public function assertCanPay(array $amounts, bool $confirmed, array $giveBack = [], string $field = 'amount', bool $convertible = false): void
    {
        $amounts = array_filter($amounts, fn (float $value) => $value > 0);

        if ($amounts === []) {
            return;
        }

        $primary = Currency::primary();
        $balances = $this->balances();

        foreach ($amounts as $currency => $amount) {
            $available = round(($balances[$currency] ?? 0.0) + ($giveBack[$currency] ?? 0.0), Currency::decimals($currency));

            if ($amount <= $available + 0.005) {
                continue;
            }

            $values = ['box' => Currency::label($currency), 'available' => Currency::format($available, $currency)];

            if ($currency !== $primary) {
                throw ValidationException::withMessages([
                    $field => $convertible
                        ? __('Not enough in the :box box (available: :available). You can pay from the :primary box at an exchange rate.', [...$values, 'primary' => Currency::label($primary)])
                        : __('Not enough in the :box box (available: :available).', $values),
                ]);
            }

            if (! $confirmed) {
                throw ValidationException::withMessages([
                    self::CONFIRM_FIELD => __('The :box box will go negative (available: :available). Continue?', $values),
                ]);
            }
        }
    }
}
