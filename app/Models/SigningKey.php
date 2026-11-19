<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SigningKey extends Model
{
    use HasFactory;

    protected $fillable = [
        'key_version', 'buyer_id', 'public_key_b64', 'private_key_encrypted',
        'status', 'activated_at', 'retired_at',
    ];

    protected function casts(): array
    {
        return ['activated_at' => 'datetime', 'retired_at' => 'datetime'];
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeUsable($query)
    {
        return $query->whereIn('status', ['active', 'verify_only']);
    }
}
