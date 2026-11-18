<?php

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use HasFactory;

    protected $fillable = [
        'name',
        'email',
        'password_hash',
        'role',
        'language_pref',
        'factory_id',
    ];

    protected $hidden = [
        'password_hash',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password_hash' => 'hashed',
            'role' => UserRole::class,
            'language_pref' => 'string',
        ];
    }

    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }

    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class, 'buyer_id');
    }

    public function factoryOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class, 'factory_id', 'factory_id');
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function isBuyer(): bool
    {
        return $this->role === UserRole::GlobalBuyer;
    }

    public function isFactory(): bool
    {
        return $this->role === UserRole::FactoryQa;
    }

    public function hasFactoryAccess(string $factoryId): bool
    {
        return $this->isAdmin()
            || ($this->isFactory() && $this->factory_id !== null && hash_equals($this->factory_id, $factoryId));
    }
}
