<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Document extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'uuid',
        'tenant_id',
        'agent_id',
        'original_name',
        'storage_path',
        'mime_type',
        'file_size',
        'file_hash',
        'document_type',
        'category',
        'extraction_status',
        'extracted_text',
        'extraction_metadata',
        'extraction_error',
        'extracted_at',
        'chunk_count',
        'chunk_strategy',
        'is_indexed',
        'indexed_at',
        'title',
        'description',
        'source_url',
        'uploaded_by',
    ];

    protected $casts = [
        'extraction_metadata' => 'array',
        'is_indexed' => 'boolean',
        'extracted_at' => 'datetime',
        'indexed_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(DocumentChunk::class);
    }

    public function isPending(): bool
    {
        return $this->extraction_status === 'pending';
    }

    public function isProcessed(): bool
    {
        return $this->extraction_status === 'completed';
    }

    public function isFailed(): bool
    {
        return $this->extraction_status === 'failed';
    }

    public function getFileSizeForHumans(): string
    {
        $bytes = $this->file_size;
        $units = ['B', 'KB', 'MB', 'GB'];

        for ($i = 0; $bytes >= 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }
}
