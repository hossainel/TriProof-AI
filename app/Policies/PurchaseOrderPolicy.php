<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\PurchaseOrder;
use App\Models\User;

class PurchaseOrderPolicy
{
    public function view(User $user, PurchaseOrder $order): bool
    {
        return match ($user->role) {
            UserRole::Admin => true,
            UserRole::GlobalBuyer => $order->buyer_id === $user->id,
            UserRole::FactoryQa => $user->factory_id !== null && $order->factory_id === $user->factory_id,
        };
    }

    public function generate(User $user, PurchaseOrder $order): bool
    {
        return $user->isAdmin() || ($user->isBuyer() && $order->buyer_id === $user->id);
    }

    public function export(User $user, PurchaseOrder $order): bool
    {
        return $this->view($user, $order);
    }

    public function qrStickers(User $user, PurchaseOrder $order): bool
    {
        // Buyers, administrators and factory QA (scoped to their factory) may print labels for
        // orders they can already export.
        return $this->export($user, $order);
    }

    public function pack(User $user, PurchaseOrder $order): bool
    {
        return $user->isAdmin()
            || ($user->isFactory() && $user->factory_id !== null && $order->factory_id === $user->factory_id);
    }
}
