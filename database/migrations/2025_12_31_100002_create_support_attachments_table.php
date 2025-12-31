<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_attachments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // Liens
            $table->foreignId('message_id')->nullable()->constrained('support_messages')->cascadeOnDelete();
            $table->foreignId('session_id')->constrained('ai_sessions')->cascadeOnDelete();

            // Fichier
            $table->string('original_name', 255);
            $table->string('stored_name', 255); // UUID.extension
            $table->string('mime_type', 100);
            $table->unsignedInteger('size_bytes');
            $table->string('disk', 50)->default('local'); // local, s3

            // Sécurité - Scan antivirus
            $table->string('scan_status', 20)->default('pending');
            // 'pending'  : En attente de scan
            // 'clean'    : Scanné, aucun virus
            // 'infected' : Virus détecté (fichier supprimé)
            // 'error'    : Erreur de scan
            // 'skipped'  : Scan non disponible (ClamAV absent)

            $table->timestamp('scanned_at')->nullable();
            $table->text('scan_result')->nullable();

            // Source
            $table->string('source', 20)->default('chat');
            // 'chat'  : Upload via widget
            // 'email' : Pièce jointe email

            $table->timestamps();

            // Index
            $table->index('message_id');
            $table->index('session_id');
            $table->index('scan_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_attachments');
    }
};
