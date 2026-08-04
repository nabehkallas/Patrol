<?php

namespace App\Policies;

use App\Models\FuelPrice;
use App\Models\User;

class FuelPricePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, FuelPrice $fuelPrice): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, FuelPrice $fuelPrice): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, FuelPrice $fuelPrice): bool
    {
        return $user->isAdmin();
    }
}
