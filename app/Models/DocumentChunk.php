<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        'content_hash',
        'token_count',
        'context_before',
        'context_after',
        'metadata',
        'qdrant_point_id',
        'is_indexed',
        'indexed_at',
        'created_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'is_indexed' => 'boolean',
        'indexed_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
