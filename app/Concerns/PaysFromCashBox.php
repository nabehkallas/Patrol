<?php

namespace App\Concerns;

use App\Enums\TransactionType;
use App\Models\Transaction;
use App\Services\CashBox;
use Illuminate\Http\Request;

/**
 * For forms that take money out of the cash box (see CashBox): the fields they send -- where a
 * payment in another currency comes from (`pay_from`: its own box, or the primary box at
 * `cash_rate`), and the confirmation that the primary box may go negative.
 */
trait PaysFromCashBox
{
    /**
     * @return array<string, array<int, string>>
     */
    protected function cashSourceRules(): array
    {
        return [
            'pay_from' => ['nullable', 'in:'.CashBox::FROM_OWN.','.CashBox::FROM_PRIMARY],
            'cash_rate' => ['nullable', 'numeric', 'gt:0'],
            CashBox::CONFIRM_FIELD => ['nullable', 'boolean'],
        ];
    }

    /**
     * What a record paying $amount of $currency out of the box is stored as (see
     * CashBox::moneyOut()), after checking the box can pay it.
     *
     * @param  array<string, float>  $giveBack
     * @return array{amount: float, currency: string, original_amount: float|null, original_currency: string|null, original_rate: float|null}
     */
    protected function cashOut(Request $request, float $amount, string $currency, array $giveBack = [], string $field = 'amount'): array
    {
        return app(CashBox::class)->moneyOut(
            $amount,
            $currency,
            $request->input('pay_from'),
            $request->input('cash_rate'),
            $request->boolean(CashBox::CONFIRM_FIELD),
            $giveBack,
            $field,
            anyRate: (bool) $request->user()?->isAdmin(),
        );
    }

    /**
     * Checks the boxes can pay these amounts, per currency, with no conversion offered.
     *
     * @param  array<string, float>  $amounts
     * @param  array<string, float>  $giveBack
     */
    protected function assertCashFor(Request $request, array $amounts, array $giveBack = [], string $field = 'amount'): void
    {
        app(CashBox::class)->assertCanPay($amounts, $request->boolean(CashBox::CONFIRM_FIELD), $giveBack, $field);
    }

    /**
     * What a saved transaction took out of the boxes, per currency -- put back when it's being
     * edited, so the check is against the box without it.
     *
     * @return array<string, float>
     */
    protected function takenOutBy(Transaction $transaction): array
    {
        if ($transaction->debt()->exists()) {
            return [];
        }

        return match ($transaction->type) {
            TransactionType::Expense, TransactionType::Purchase => [$transaction->currency => (float) $transaction->amount],
            TransactionType::CurrencyExchange => [
                $transaction->currency => (float) $transaction->amount,
                (string) $transaction->to_currency => -(float) $transaction->to_amount,
            ],
            default => [],
        };
    }
}
