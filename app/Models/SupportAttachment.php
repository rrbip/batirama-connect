<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class SupportAttachment extends Model
{
    use HasFactory;

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (SupportAttachment $attachment) {
            if (empty($attachment->uuid)) {
                $attachment->uuid = (string) Str::uuid();
            }
        });

        // Supprimer le fichier quand l'enregistrement est supprimé
        static::deleting(function (SupportAttachment $attachment) {
            $attachment->deleteFile();
        });
    }

    protected $fillable = [
        'uuid',
        'message_id',
        'session_id',
        'original_name',
        'stored_name',
        'mime_type',
        'size_bytes',
        'disk',
        'scan_status',
        'scanned_at',
        'scan_result',
        'source',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'scanned_at' => 'datetime',
    ];

    /**
     * Extensions de fichiers autorisées.
     */
    public const ALLOWED_EXTENSIONS = [
        'pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp',
        'doc', 'docx', 'xls', 'xlsx', 'txt', 'csv',
    ];

    /**
     * Taille maximale en octets (10 Mo).
     */
    public const MAX_FILE_SIZE = 10 * 1024 * 1024;

    /**
     * Dossier de stockage.
     */
    public const STORAGE_PATH = 'support-attachments';

    // ─────────────────────────────────────────────────────────────────
    // RELATIONS
    // ─────────────────────────────────────────────────────────────────

    /**
     * Message associé.
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(SupportMessage::class, 'message_id');
    }

    /**
     * Session associée.
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(AiSession::class, 'session_id');
    }

    // ─────────────────────────────────────────────────────────────────
    // MÉTHODES
    // ─────────────────────────────────────────────────────────────────

    /**
     * Retourne le chemin complet du fichier.
     */
    public function getFilePath(): string
    {
        return self::STORAGE_PATH . '/' . $this->stored_name;
    }

    /**
     * Vérifie si le fichier existe.
     */
    public function fileExists(): bool
    {
        return Storage::disk($this->disk)->exists($this->getFilePath());
    }

    /**
     * Supprime le fichier physique.
     */
    public function deleteFile(): bool
    {
        if ($this->fileExists()) {
            return Storage::disk($this->disk)->delete($this->getFilePath());
        }

        return true;
    }

    /**
     * Génère une URL signée temporaire pour télécharger le fichier.
     */
    public function getSignedUrl(int $expiresInMinutes = 60): ?string
    {
        if (!$this->fileExists()) {
            return null;
        }

        // Pour le disque local, on utilise une route signée
        if ($this->disk === 'local') {
            return URL::signedRoute('support.attachment.download', [
                'attachment' => $this->uuid,
            ], now()->addMinutes($expiresInMinutes));
        }

        // Pour S3, on utilise l'URL temporaire native
        return Storage::disk($this->disk)->temporaryUrl(
            $this->getFilePath(),
            now()->addMinutes($expiresInMinutes)
        );
    }

    /**
     * Vérifie si le fichier a été scanné et est propre.
     */
    public function isClean(): bool
    {
        return $this->scan_status === 'clean';
    }

    /**
     * Vérifie si le fichier est infecté.
     */
    public function isInfected(): bool
    {
        return $this->scan_status === 'infected';
    }

    /**
     * Vérifie si le scan est en attente.
     */
    public function isPendingScan(): bool
    {
        return $this->scan_status === 'pending';
    }

    /**
     * Vérifie si le scan a été ignoré (ClamAV indisponible).
     */
    public function wasScanSkipped(): bool
    {
        return $this->scan_status === 'skipped';
    }

    /**
     * Retourne la taille formatée.
     */
    public function getFormattedSize(): string
    {
        $bytes = $this->size_bytes;

        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' Mo';
        }

        if ($bytes >= 1024) {
            return round($bytes / 1024, 1) . ' Ko';
        }

        return $bytes . ' octets';
    }

    /**
     * Vérifie si c'est une image.
     */
    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }

    /**
     * Vérifie si c'est un PDF.
     */
    public function isPdf(): bool
    {
        return $this->mime_type === 'application/pdf';
    }

    /**
     * Retourne l'icône appropriée pour le type de fichier.
     */
    public function getIcon(): string
    {
        if ($this->isImage()) {
            return 'heroicon-o-photo';
        }

        if ($this->isPdf()) {
            return 'heroicon-o-document-text';
        }

        return match (true) {
            str_contains($this->mime_type, 'spreadsheet'),
            str_contains($this->mime_type, 'excel') => 'heroicon-o-table-cells',
            str_contains($this->mime_type, 'word'),
            str_contains($this->mime_type, 'document') => 'heroicon-o-document',
            default => 'heroicon-o-paper-clip',
        };
    }
}
