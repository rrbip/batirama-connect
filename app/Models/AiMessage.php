<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiMessage extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'uuid',
        'session_id',
        'role',
        'content',
        'attachments',
        'rag_context',
        'model_used',
        'tokens_prompt',
        'tokens_completion',
        'generation_time_ms',
        'validation_status',
        'validated_by',
        'validated_at',
        'corrected_content',
        'created_at',
    ];

    protected $casts = [
        'attachments' => 'array',
        'rag_context' => 'array',
        'validated_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(AiSession::class, 'session_id');
    }

    public function validator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    public function feedbacks(): HasMany
    {
        return $this->hasMany(AiFeedback::class, 'message_id');
    }

    public function isFromUser(): bool
    {
        return $this->role === 'user';
    }

    public function isFromAssistant(): bool
    {
        return $this->role === 'assistant';
    }

    public function isPending(): bool
    {
        return $this->validation_status === 'pending';
    }

    public function isValidated(): bool
    {
        return $this->validation_status === 'validated';
    }
}
