<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocTeamMember extends Model
{
    protected $fillable = [
        'full_name',
        'role_title',
        'email',
        'bio_short',
        'photo_path',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function initials(): string
    {
        $parts = preg_split('/\s+/', trim($this->full_name)) ?: [];

        $initials = '';
        foreach (array_slice($parts, 0, 2) as $part) {
            $initials .= mb_strtoupper(mb_substr($part, 0, 1));
        }

        return $initials !== '' ? $initials : 'TP';
    }

    public function photoUrl(): ?string
    {
        return $this->photo_path ? asset('uploads/team/'.$this->photo_path) : null;
    }
}
