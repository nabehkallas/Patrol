<?php

namespace App\Policies;

use App\Models\Transaction;
use App\Models\User;

/**
 * Everyone records transactions; correcting or deleting a recorded one is for admins only (the
 * same split as debts), so the cash box can't be quietly changed after the fact.
 */
class TransactionPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Transaction $transaction): bool
    {
        return $user->isAdmin() || $user->id === $transaction->user_id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Transaction $transaction): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, Transaction $transaction): bool
    {
        return $user->isAdmin();
    }
}
