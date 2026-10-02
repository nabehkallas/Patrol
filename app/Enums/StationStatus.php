<?php

namespace App\Enums;

use App\Models\Tenant;

/**
 * Lifecycle of a station (tenant). A station an owner registers publicly starts at
 * PendingVerification, moves to PendingApproval once the owner confirms their email, and becomes
 * Active when a platform admin approves it. Stations created by a platform admin start Active.
 * Stations from before this existed have no status stored and count as Active.
 */
enum StationStatus: string
{
    case PendingVerification = 'pending_verification';
    case PendingApproval = 'pending_approval';
    case Active = 'active';

    public static function of(Tenant $tenant): self
    {
        $value = $tenant->getAttribute('status');

        return is_string($value) ? (self::tryFrom($value) ?? self::Active) : self::Active;
    }
}
