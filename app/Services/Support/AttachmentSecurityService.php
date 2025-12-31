<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Models\AiSession;
use App\Models\SupportAttachment;
use App\Models\SupportMessage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Socket;

class AttachmentSecurityService
{
    /**
     * Extensions de fichiers autorisées.
     */
    protected const ALLOWED_EXTENSIONS = [
        'pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp',
        'doc', 'docx', 'xls', 'xlsx', 'txt', 'csv',
    ];

    /**
     * Types MIME autorisés.
     */
    protected const ALLOWED_MIME_TYPES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'text/plain',
        'text/csv',
    ];

    /**
     * Taille maximale en octets (10 Mo).
     */
    protected const MAX_FILE_SIZE = 10 * 1024 * 1024;

    /**
     * Stocke et valide un fichier uploadé.
     */
    public function storeAttachment(
        UploadedFile $file,
        AiSession $session,
        ?SupportMessage $message = null,
        string $source = 'chat'
    ): SupportAttachment|array {
        // 1. Valider l'extension
        $extension = strtolower($file->getClientOriginalExtension());
        if (!in_array($extension, self::ALLOWED_EXTENSIONS)) {
            return ['error' => "Extension de fichier non autorisée: .{$extension}"];
        }

        // 2. Valider le type MIME
        $mimeType = $file->getMimeType();
        if (!in_array($mimeType, self::ALLOWED_MIME_TYPES)) {
            return ['error' => "Type de fichier non autorisé: {$mimeType}"];
        }

        // 3. Valider la taille
        if ($file->getSize() > self::MAX_FILE_SIZE) {
            $maxMb = self::MAX_FILE_SIZE / 1024 / 1024;
            return ['error' => "Fichier trop volumineux. Maximum: {$maxMb} Mo"];
        }

        // 4. Générer un nom unique
        $storedName = Str::uuid() . '.' . $extension;
        $storagePath = SupportAttachment::STORAGE_PATH;

        // 5. Stocker le fichier
        $path = $file->storeAs($storagePath, $storedName, 'local');

        if (!$path) {
            return ['error' => 'Erreur lors du stockage du fichier'];
        }

        // 6. Créer l'enregistrement
        $attachment = SupportAttachment::create([
            'session_id' => $session->id,
            'message_id' => $message?->id,
            'original_name' => $file->getClientOriginalName(),
            'stored_name' => $storedName,
            'mime_type' => $mimeType,
            'size_bytes' => $file->getSize(),
            'disk' => 'local',
            'source' => $source,
            'scan_status' => 'pending',
        ]);

        // 7. Scanner avec ClamAV (asynchrone si disponible)
        $this->scanAttachment($attachment);

        return $attachment;
    }

    /**
     * Scanne un fichier avec ClamAV.
     */
    public function scanAttachment(SupportAttachment $attachment): string
    {
        $filePath = storage_path('app/' . $attachment->getFilePath());

        if (!file_exists($filePath)) {
            $attachment->update([
                'scan_status' => 'error',
                'scan_result' => 'File not found',
                'scanned_at' => now(),
            ]);
            return 'error';
        }

        try {
            $result = $this->scanWithClamAV($filePath);

            $attachment->update([
                'scan_status' => $result['status'],
                'scan_result' => $result['message'],
                'scanned_at' => now(),
            ]);

            // Si infecté, supprimer le fichier
            if ($result['status'] === 'infected') {
                $attachment->deleteFile();
                Log::warning('Infected file detected and deleted', [
                    'attachment_id' => $attachment->id,
                    'original_name' => $attachment->original_name,
                    'scan_result' => $result['message'],
                ]);
            }

            return $result['status'];

        } catch (\Throwable $e) {
            // ClamAV non disponible - fallback avec avertissement
            Log::warning('ClamAV not available, skipping scan', [
                'attachment_id' => $attachment->id,
                'error' => $e->getMessage(),
            ]);

            $attachment->update([
                'scan_status' => 'skipped',
                'scan_result' => 'ClamAV unavailable: ' . $e->getMessage(),
                'scanned_at' => now(),
            ]);

            return 'skipped';
        }
    }

    /**
     * Effectue le scan ClamAV via socket.
     */
    protected function scanWithClamAV(string $filePath): array
    {
        $socket = $this->getClamAVSocket();

        if (!$socket) {
            throw new \RuntimeException('Cannot connect to ClamAV');
        }

        try {
            // Envoyer la commande SCAN
            $command = "SCAN {$filePath}\n";
            socket_write($socket, $command, strlen($command));

            // Lire la réponse
            $response = '';
            while ($buffer = socket_read($socket, 1024)) {
                $response .= $buffer;
                if (str_contains($response, "\n")) {
                    break;
                }
            }

            socket_close($socket);

            // Parser la réponse
            // Format: /path/to/file: OK ou /path/to/file: Virus_Name FOUND
            $response = trim($response);

            if (str_ends_with($response, 'OK')) {
                return [
                    'status' => 'clean',
                    'message' => 'No threats detected',
                ];
            }

            if (str_contains($response, 'FOUND')) {
                // Extraire le nom du virus
                preg_match('/: (.+) FOUND$/', $response, $matches);
                return [
                    'status' => 'infected',
                    'message' => $matches[1] ?? 'Unknown threat',
                ];
            }

            return [
                'status' => 'error',
                'message' => 'Unexpected response: ' . $response,
            ];

        } catch (\Throwable $e) {
            if (isset($socket)) {
                socket_close($socket);
            }
            throw $e;
        }
    }

    /**
     * Crée une connexion socket vers ClamAV.
     */
    protected function getClamAVSocket(): ?Socket
    {
        $host = config('services.clamav.host', '127.0.0.1');
        $port = config('services.clamav.port', 3310);

        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        if (!$socket) {
            return null;
        }

        // Timeout de 5 secondes
        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 5, 'usec' => 0]);
        socket_set_option($socket, SOL_SOCKET, SO_SNDTIMEO, ['sec' => 5, 'usec' => 0]);

        if (!@socket_connect($socket, $host, $port)) {
            socket_close($socket);
            return null;
        }

        return $socket;
    }

    /**
     * Vérifie si ClamAV est disponible.
     */
    public function isClamAVAvailable(): bool
    {
        $socket = $this->getClamAVSocket();

        if (!$socket) {
            return false;
        }

        try {
            // Envoyer PING
            socket_write($socket, "PING\n", 5);
            $response = socket_read($socket, 1024);
            socket_close($socket);

            return str_contains($response, 'PONG');

        } catch (\Throwable $e) {
            if (isset($socket)) {
                socket_close($socket);
            }
            return false;
        }
    }

    /**
     * Retourne les extensions autorisées.
     */
    public function getAllowedExtensions(): array
    {
        return self::ALLOWED_EXTENSIONS;
    }

    /**
     * Retourne la taille maximale.
     */
    public function getMaxFileSize(): int
    {
        return self::MAX_FILE_SIZE;
    }
}
