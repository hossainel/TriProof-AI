<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Exceptions\BusinessRuleException;
use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PurchaseOrderService
{
    public function create(User $actor, array $data): PurchaseOrder
    {
        if (! $actor->isAdmin() && ! $actor->isBuyer()) {
            throw new BusinessRuleException('forbidden', 'Only buyers and administrators can create purchase orders.', 403);
        }

        $buyerId = $actor->isAdmin()
            ? (int) ($data['buyer_id'] ?? 0)
            : $actor->id;

        if ($buyerId < 1) {
            throw new BusinessRuleException('buyer_required', 'Select a global buyer for this purchase order.');
        }

        $buyer = User::query()->find($buyerId);
        if (! $buyer || $buyer->role !== UserRole::GlobalBuyer) {
            throw new BusinessRuleException('invalid_buyer', 'The selected account is not a global buyer.');
        }

        $factoryId = strtoupper(trim((string) ($data['factory_id'] ?? '')));
        $factoryExists = User::query()
            ->where('role', UserRole::FactoryQa)
            ->where('factory_id', $factoryId)
            ->exists();

        if (! $factoryExists) {
            throw new BusinessRuleException('invalid_factory', 'The selected factory is not registered in TriProof AI.');
        }

        $quota = (int) ($data['authorized_quota'] ?? 0);
        if ($quota < 1 || $quota > 1_000_000) {
            throw new BusinessRuleException('invalid_quota', 'Authorized quota must be between 1 and 1,000,000 units.');
        }

        $factoryDays = (int) ($data['factory_expiry_days'] ?? config('triproof.factory.default_expiry_days'));
        $retailDays = (int) ($data['retail_expiry_days'] ?? config('triproof.factory.default_retail_expiry_days'));

        if ($factoryDays < 1 || $factoryDays > 365) {
            throw new BusinessRuleException('invalid_factory_expiry', 'Factory packing window must be 1-365 days.');
        }

        if ($retailDays < 1 || $retailDays > 3650) {
            throw new BusinessRuleException('invalid_retail_expiry', 'Retail expiry must be 1-3650 days.');
        }

        return DB::transaction(function () use ($data, $buyerId, $factoryId, $quota, $factoryDays, $retailDays) {
            $poNumber = trim((string) ($data['po_number'] ?? ''));

            if ($poNumber === '') {
                do {
                    $poNumber = 'PO-'.now()->format('Ymd').'-'.strtoupper(Str::random(6));
                } while (PurchaseOrder::query()->where('po_number', $poNumber)->exists());
            }

            if (PurchaseOrder::query()->where('po_number', $poNumber)->exists()) {
                throw new BusinessRuleException('po_exists', 'That purchase order number already exists.', 409);
            }

            return PurchaseOrder::create([
                'po_number' => $poNumber,
                'buyer_id' => $buyerId,
                'factory_id' => $factoryId,
                'authorized_quota' => $quota,
                'generated_count' => 0,
                'factory_expiry_days' => $factoryDays,
                'retail_expiry_days' => $retailDays,
            ]);
        });
    }
}
