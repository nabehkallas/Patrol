<?php

namespace App\Policies;

use App\Models\Tank;
use App\Models\User;

class TankPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, Tank $tank): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Tank $tank): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, Tank $tank): bool
    {
        return $user->isAdmin();
    }
}
