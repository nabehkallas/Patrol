<?php

namespace App\Policies;

use App\Models\InventoryEntry;
use App\Models\User;

class InventoryEntryPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, InventoryEntry $inventoryEntry): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, InventoryEntry $inventoryEntry): bool
    {
        return $user->isAdmin() || $user->id === $inventoryEntry->recorded_by_id;
    }

    public function delete(User $user, InventoryEntry $inventoryEntry): bool
    {
        return $user->isAdmin();
    }
}
