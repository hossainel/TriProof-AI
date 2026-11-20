<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocSection extends Model
{
    protected $fillable = [
        'slug',
        'category',
        'section_key',
        'title_en',
        'title_bn',
        'subtitle_en',
        'subtitle_bn',
        'content_markdown',
        'draft_markdown',
        'diagram_mermaid',
        'sort_order',
        'is_visible',
        'status',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_visible' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(DocRevision::class);
    }

    public function displayTitle(string $locale = 'en'): string
    {
        return $locale === 'bn' && $this->title_bn ? $this->title_bn : $this->title_en;
    }

    public function displaySubtitle(string $locale = 'en'): ?string
    {
        return $locale === 'bn' ? ($this->subtitle_bn ?: $this->subtitle_en) : $this->subtitle_en;
    }

    public function liveMarkdown(): string
    {
        return $this->content_markdown ?: '';
    }
}
