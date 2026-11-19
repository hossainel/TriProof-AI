<?php

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class PurchaseOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'po_number',
        'buyer_id',
        'factory_id',
        'authorized_quota',
        'generated_count',
        'factory_expiry_days',
        'retail_expiry_days',
        'merkle_root_hash',
        'dpp_metadata',
        'dpp_digest',
    ];

    protected function casts(): array
    {
        return [
            'authorized_quota' => 'integer',
            'generated_count' => 'integer',
            'factory_expiry_days' => 'integer',
            'retail_expiry_days' => 'integer',
            'dpp_metadata' => 'array',
        ];
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function serials(): HasMany
    {
        return $this->hasMany(SerialRegistry::class, 'po_id');
    }

    public function anomalies(): HasMany
    {
        return $this->hasMany(AiAnomalyReport::class, 'po_id');
    }

    public function recallEvents(): HasMany
    {
        return $this->hasMany(RecallEvent::class, 'po_id');
    }

    public function remainingQuota(): int
    {
        return max(0, $this->authorized_quota - $this->generated_count);
    }

    public function factoryDeadline(): Carbon
    {
        return $this->created_at->copy()->addDays($this->factory_expiry_days);
    }

    public function scopeVisibleTo($query, User $user)
    {
        return match ($user->role) {
            UserRole::Admin => $query,
            UserRole::GlobalBuyer => $query->where('buyer_id', $user->id),
            UserRole::FactoryQa => $query->where('factory_id', $user->factory_id ?? '__none__'),
        };
    }
}
