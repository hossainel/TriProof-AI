<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocRevision extends Model
{
    protected $fillable = [
        'doc_section_id',
        'version_label',
        'snapshot_json',
        'change_note',
        'created_by',
    ];

    public function section(): BelongsTo
    {
        return $this->belongsTo(DocSection::class, 'doc_section_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $decoded = json_decode($this->snapshot_json, true);

        return is_array($decoded) ? $decoded : [];
    }
}
