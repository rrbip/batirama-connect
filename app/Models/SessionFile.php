<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SessionFile extends Model
{
    use HasFactory;

    public const TYPE_IMAGE = 'image';
    public const TYPE_VIDEO = 'video';
    public const TYPE_AUDIO = 'audio';
    public const TYPE_PDF = 'pdf';
    public const TYPE_DOCUMENT = 'document';

    public const STATUS_UPLOADED = 'uploaded';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_READY = 'ready';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'uuid',
        'session_id',
        'original_name',
        'storage_path',
        'storage_disk',
        'mime_type',
        'size_bytes',
        'thumbnail_path',
        'file_type',
        'metadata',
        'status',
    ];

    protected $casts = [
        'metadata' => 'array',
        'size_bytes' => 'integer',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (SessionFile $file) {
            if (empty($file->uuid)) {
                $file->uuid = (string) Str::uuid();
            }
        });

        static::deleting(function (SessionFile $file) {
            // Delete file from storage
            $disk = Storage::disk($file->storage_disk);

            if ($file->storage_path && $disk->exists($file->storage_path)) {
                $disk->delete($file->storage_path);
            }

            if ($file->thumbnail_path && $disk->exists($file->thumbnail_path)) {
                $disk->delete($file->thumbnail_path);
            }
        });
    }

    // ─────────────────────────────────────────────────────────────────
    // RELATIONS
    // ─────────────────────────────────────────────────────────────────

    public function session(): BelongsTo
    {
        return $this->belongsTo(AiSession::class, 'session_id');
    }

    // ─────────────────────────────────────────────────────────────────
    // ACCESSORS
    // ─────────────────────────────────────────────────────────────────

    /**
     * Get the file URL.
     */
    public function getUrlAttribute(): ?string
    {
        if (!$this->storage_path) {
            return null;
        }

        $disk = Storage::disk($this->storage_disk);

        // For S3 or other cloud storage, generate a temporary signed URL
        if (method_exists($disk, 'temporaryUrl')) {
            try {
                return $disk->temporaryUrl($this->storage_path, now()->addHours(1));
            } catch (\Exception) {
                // Fall back to regular URL
            }
        }

        return $disk->url($this->storage_path);
    }

    /**
     * Get the thumbnail URL.
     */
    public function getThumbnailUrlAttribute(): ?string
    {
        if (!$this->thumbnail_path) {
            return null;
        }

        $disk = Storage::disk($this->storage_disk);

        if (method_exists($disk, 'temporaryUrl')) {
            try {
                return $disk->temporaryUrl($this->thumbnail_path, now()->addHours(1));
            } catch (\Exception) {
                // Fall back to regular URL
            }
        }

        return $disk->url($this->thumbnail_path);
    }

    /**
     * Get human-readable file size.
     */
    public function getHumanSizeAttribute(): string
    {
        $bytes = $this->size_bytes;

        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        }
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        }

        return $bytes . ' bytes';
    }

    // ─────────────────────────────────────────────────────────────────
    // METHODS
    // ─────────────────────────────────────────────────────────────────

    /**
     * Determine file type from mime type.
     */
    public static function determineFileType(string $mimeType): string
    {
        return match (true) {
            str_starts_with($mimeType, 'image/') => self::TYPE_IMAGE,
            str_starts_with($mimeType, 'video/') => self::TYPE_VIDEO,
            str_starts_with($mimeType, 'audio/') => self::TYPE_AUDIO,
            $mimeType === 'application/pdf' => self::TYPE_PDF,
            default => self::TYPE_DOCUMENT,
        };
    }

    /**
     * Check if file is an image.
     */
    public function isImage(): bool
    {
        return $this->file_type === self::TYPE_IMAGE;
    }

    /**
     * Check if file needs a thumbnail.
     */
    public function needsThumbnail(): bool
    {
        return $this->isImage() && !$this->thumbnail_path;
    }

    /**
     * Mark file as ready.
     */
    public function markAsReady(): void
    {
        $this->update(['status' => self::STATUS_READY]);
    }

    /**
     * Mark file as failed.
     */
    public function markAsFailed(string $reason = null): void
    {
        $metadata = $this->metadata ?? [];
        $metadata['error'] = $reason;

        $this->update([
            'status' => self::STATUS_FAILED,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Get file for API response.
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->original_name,
            'type' => $this->file_type,
            'mime_type' => $this->mime_type,
            'size' => $this->size_bytes,
            'size_human' => $this->human_size,
            'url' => $this->url,
            'thumbnail_url' => $this->thumbnail_url,
            'status' => $this->status,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
