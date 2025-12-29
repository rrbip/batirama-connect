<?php

declare(strict_types=1);

namespace App\Models;

use App\Observers\DocumentObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

#[ObservedBy([DocumentObserver::class])]
class Document extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Boot the model - Auto-generate UUID on creation.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Document $document) {
            if (empty($document->uuid)) {
                $document->uuid = (string) Str::uuid();
            }
        });
    }

    protected $fillable = [
        'uuid',
        'source_type',
        'tenant_id',
        'agent_id',
        'original_name',
        'storage_path',
        'mime_type',
        'file_size',
        'file_hash',
        'document_type',
        'extraction_status',
        'extracted_text',
        'extraction_metadata',
        'pipeline_steps',
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
        'web_crawl_id',
        'crawl_url_id',
    ];

    protected $casts = [
        'extraction_metadata' => 'array',
        'pipeline_steps' => 'array',
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

    /**
     * Le crawl web source (si provient d'un crawl)
     */
    public function webCrawl(): BelongsTo
    {
        return $this->belongsTo(WebCrawl::class);
    }

    /**
     * L'URL crawlée source (pour le partage de contenu)
     */
    public function crawlUrl(): BelongsTo
    {
        return $this->belongsTo(WebCrawlUrl::class, 'crawl_url_id');
    }

    /**
     * Vérifie si le document provient d'un crawl web
     */
    public function isFromCrawl(): bool
    {
        return $this->web_crawl_id !== null;
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
