<?php

namespace App\Policies;

use App\Models\ExchangeRate;
use App\Models\User;

class ExchangeRatePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ExchangeRate $exchangeRate): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, ExchangeRate $exchangeRate): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, ExchangeRate $exchangeRate): bool
    {
        return $user->isAdmin();
    }
}
