<?php

namespace App\Rules;

use App\Models\User;
use Carbon\Carbon;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Throwable;

/**
 * The date a record is entered for: never in the future (station time), and for anyone but an
 * admin at most BACKDATE_DAYS back -- older corrections are an admin's. Pair it with 'date',
 * which reports a value that isn't a date at all.
 */
class EntryDate implements ValidationRule
{
    public const BACKDATE_DAYS = 7;

    public function __construct(private readonly ?User $user) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        try {
            $date = Carbon::parse($value)->startOfDay();
        } catch (Throwable) {
            return;
        }

        if ($date->gt(today())) {
            $fail(__('The date cannot be in the future.'));

            return;
        }

        if (! $this->user?->isAdmin() && $date->lt(today()->subDays(self::BACKDATE_DAYS))) {
            $fail(__('Only an admin can enter a date more than :days days back.', ['days' => self::BACKDATE_DAYS]));
        }
    }
}
