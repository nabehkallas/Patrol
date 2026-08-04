<?php

namespace App\Policies;

use App\Models\FuelType;
use App\Models\User;

class FuelTypePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, FuelType $fuelType): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, FuelType $fuelType): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, FuelType $fuelType): bool
    {
        return $user->isAdmin();
    }
}
