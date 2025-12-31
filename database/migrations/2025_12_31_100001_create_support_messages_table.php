<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_messages', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // Lien avec la session
            $table->foreignId('session_id')->constrained('ai_sessions')->cascadeOnDelete();

            // Expéditeur
            $table->string('sender_type', 20);
            // 'user'   : Message utilisateur (via chat ou email)
            // 'agent'  : Réponse agent de support
            // 'system' : Message système (escalade, assignation, etc.)

            $table->foreignId('agent_id')->nullable()->constrained('users')->nullOnDelete();

            // Canal de communication
            $table->string('channel', 20)->default('chat');
            // 'chat'  : Message via widget/interface web
            // 'email' : Message reçu/envoyé par email

            // Contenu
            $table->text('content');

            // Contenu original (avant amélioration IA)
            $table->text('original_content')->nullable();
            $table->boolean('was_ai_improved')->default(false);

            // Métadonnées email
            $table->jsonb('email_metadata')->nullable();
            // {
            //   "message_id": "<xxx@mail.com>",
            //   "in_reply_to": "<yyy@mail.com>",
            //   "from": "user@example.com",
            //   "subject": "Re: Support #123"
            // }

            // Statut de lecture
            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();

            $table->timestamps();

            // Index
            $table->index('session_id');
            $table->index('sender_type');
            $table->index('channel');
            $table->index(['session_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_messages');
    }
};
