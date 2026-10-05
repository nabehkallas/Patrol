<?php

namespace App\Policies;

use App\Models\Debt;
use App\Models\User;

/**
 * Attendants record debts and collect payments on them (in full or in part, into the cash box);
 * changing or deleting a recorded debt is for admins only. The routes enforce the same split.
 */
class DebtPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Debt $debt): bool
    {
        return $user->isAdmin() || $user->id === $debt->recorded_by_id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Debt $debt): bool
    {
        return $user->isAdmin();
    }

    public function settle(User $user, Debt $debt): bool
    {
        return true;
    }

    public function delete(User $user, Debt $debt): bool
    {
        return $user->isAdmin();
    }
}
