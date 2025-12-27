<?php

declare(strict_types=1);

namespace App\Services\Upload;

use App\Models\AiSession;
use App\Models\SessionFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;

class FileUploadService
{
    private const MAX_FILE_SIZE = 10 * 1024 * 1024; // 10 MB
    private const MAX_FILES_PER_SESSION = 10;
    private const THUMBNAIL_SIZE = 200;

    private const ALLOWED_MIME_TYPES = [
        // Images
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        // Documents
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        // Text
        'text/plain',
        'text/csv',
    ];

    private string $disk;

    public function __construct()
    {
        $this->disk = config('filesystems.default', 'public');
    }

    /**
     * Upload a file for a session.
     */
    public function upload(UploadedFile $file, AiSession $session): SessionFile
    {
        // Validate file
        $this->validateFile($file, $session);

        // Determine file type
        $mimeType = $file->getMimeType();
        $fileType = SessionFile::determineFileType($mimeType);

        // Generate storage path
        $uuid = (string) Str::uuid();
        $extension = $file->getClientOriginalExtension();
        $storagePath = $this->generateStoragePath($session->uuid, $uuid, $extension);

        // Store file
        $disk = Storage::disk($this->disk);
        $file->storeAs(
            dirname($storagePath),
            basename($storagePath),
            $this->disk
        );

        // Create session file record
        $sessionFile = SessionFile::create([
            'uuid' => $uuid,
            'session_id' => $session->id,
            'original_name' => $file->getClientOriginalName(),
            'storage_path' => $storagePath,
            'storage_disk' => $this->disk,
            'mime_type' => $mimeType,
            'size_bytes' => $file->getSize(),
            'file_type' => $fileType,
            'status' => SessionFile::STATUS_PROCESSING,
            'metadata' => [
                'uploaded_at' => now()->toIso8601String(),
                'ip' => request()->ip(),
            ],
        ]);

        // Generate thumbnail if image
        if ($sessionFile->isImage()) {
            try {
                $thumbnailPath = $this->generateThumbnail($sessionFile);
                $sessionFile->update(['thumbnail_path' => $thumbnailPath]);
            } catch (\Exception $e) {
                Log::warning('Failed to generate thumbnail', [
                    'file_id' => $sessionFile->uuid,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Mark as ready
        $sessionFile->markAsReady();

        Log::info('File uploaded', [
            'file_id' => $sessionFile->uuid,
            'session_id' => $session->uuid,
            'file_name' => $sessionFile->original_name,
            'size' => $sessionFile->human_size,
        ]);

        return $sessionFile;
    }

    /**
     * Validate file before upload.
     */
    private function validateFile(UploadedFile $file, AiSession $session): void
    {
        // Check file size
        if ($file->getSize() > self::MAX_FILE_SIZE) {
            throw new \InvalidArgumentException(
                'Fichier trop volumineux. Taille max: ' . $this->formatBytes(self::MAX_FILE_SIZE)
            );
        }

        // Check mime type
        $mimeType = $file->getMimeType();
        if (!in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            throw new \InvalidArgumentException(
                'Type de fichier non autorisé: ' . $mimeType
            );
        }

        // Check session file count
        $currentCount = SessionFile::where('session_id', $session->id)->count();
        if ($currentCount >= self::MAX_FILES_PER_SESSION) {
            throw new \InvalidArgumentException(
                'Nombre maximum de fichiers atteint pour cette session (' . self::MAX_FILES_PER_SESSION . ')'
            );
        }
    }

    /**
     * Generate storage path for file.
     */
    private function generateStoragePath(string $sessionUuid, string $fileUuid, string $extension): string
    {
        $date = now()->format('Y/m/d');

        return "sessions/{$date}/{$sessionUuid}/{$fileUuid}.{$extension}";
    }

    /**
     * Generate thumbnail for image file.
     */
    private function generateThumbnail(SessionFile $sessionFile): string
    {
        $disk = Storage::disk($sessionFile->storage_disk);
        $originalPath = $sessionFile->storage_path;

        // Generate thumbnail path
        $pathInfo = pathinfo($originalPath);
        $thumbnailPath = $pathInfo['dirname'] . '/' . $pathInfo['filename'] . '_thumb.' . $pathInfo['extension'];

        // Read original image
        $imageContent = $disk->get($originalPath);

        // Use Intervention Image to create thumbnail
        try {
            $manager = new ImageManager(new Driver());
            $image = $manager->read($imageContent);

            // Resize maintaining aspect ratio
            $image->scaleDown(self::THUMBNAIL_SIZE, self::THUMBNAIL_SIZE);

            // Encode and store
            $encoded = $image->toJpeg(80);
            $thumbnailPath = str_replace('.' . $pathInfo['extension'], '_thumb.jpg', $originalPath);
            $disk->put($thumbnailPath, (string) $encoded);

            return $thumbnailPath;
        } catch (\Exception $e) {
            // Fallback: try with GD directly
            return $this->generateThumbnailGd($sessionFile, $originalPath, $thumbnailPath);
        }
    }

    /**
     * Generate thumbnail using GD directly (fallback).
     */
    private function generateThumbnailGd(SessionFile $sessionFile, string $originalPath, string $thumbnailPath): string
    {
        $disk = Storage::disk($sessionFile->storage_disk);
        $imageContent = $disk->get($originalPath);

        // Create image from string
        $source = imagecreatefromstring($imageContent);
        if (!$source) {
            throw new \RuntimeException('Cannot create image from file');
        }

        $width = imagesx($source);
        $height = imagesy($source);

        // Calculate new dimensions
        $ratio = min(self::THUMBNAIL_SIZE / $width, self::THUMBNAIL_SIZE / $height);
        $newWidth = (int) ($width * $ratio);
        $newHeight = (int) ($height * $ratio);

        // Create thumbnail
        $thumb = imagecreatetruecolor($newWidth, $newHeight);
        imagecopyresampled($thumb, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        // Save to buffer
        ob_start();
        imagejpeg($thumb, null, 80);
        $thumbContent = ob_get_clean();

        // Clean up
        imagedestroy($source);
        imagedestroy($thumb);

        // Store thumbnail
        $pathInfo = pathinfo($originalPath);
        $thumbnailPath = $pathInfo['dirname'] . '/' . $pathInfo['filename'] . '_thumb.jpg';
        $disk->put($thumbnailPath, $thumbContent);

        return $thumbnailPath;
    }

    /**
     * Delete all files for a session.
     */
    public function deleteSessionFiles(AiSession $session): int
    {
        $files = SessionFile::where('session_id', $session->id)->get();
        $count = $files->count();

        foreach ($files as $file) {
            $file->delete(); // Model handles actual file deletion
        }

        Log::info('Session files deleted', [
            'session_id' => $session->uuid,
            'files_count' => $count,
        ]);

        return $count;
    }

    /**
     * Get files for a session.
     */
    public function getSessionFiles(AiSession $session): array
    {
        return SessionFile::where('session_id', $session->id)
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(fn(SessionFile $file) => $file->toApiArray())
            ->toArray();
    }

    /**
     * Format bytes to human readable.
     */
    private function formatBytes(int $bytes): string
    {
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
}
