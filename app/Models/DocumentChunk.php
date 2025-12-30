<?php

declare(strict_types=1);

namespace App\Models;

use App\Observers\DocumentChunkObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ObservedBy([DocumentChunkObserver::class])]
class DocumentChunk extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'document_id',
        'chunk_index',
        'start_offset',
        'end_offset',
        'page_number',
        'content',
        'original_content',
        'content_hash',
        'token_count',
        'context_before',
        'context_after',
        'metadata',
        'summary',
        'keywords',
        'category_id',
        'useful',
        'knowledge_units',
        'parent_context',
        'qdrant_point_ids',
        'qdrant_points_count',
        'is_indexed',
        'indexed_at',
        'created_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'keywords' => 'array',
        'knowledge_units' => 'array',
        'qdrant_point_ids' => 'array',
        'useful' => 'boolean',
        'is_indexed' => 'boolean',
        'indexed_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(DocumentCategory::class);
    }

    /**
     * Vérifie si ce chunk a été traité par LLM
     */
    public function isLlmProcessed(): bool
    {
        return !empty($this->original_content);
    }

    /**
     * Retourne le contenu original si disponible, sinon le contenu actuel
     */
    public function getOriginalOrContent(): string
    {
        return $this->original_content ?? $this->content;
    }
}
