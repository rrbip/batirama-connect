<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Partner extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'logo_url',
        'api_key',
        'api_key_prefix',
        'webhook_url',
        'default_agent',
        'data_access',
        'data_fields',
        'commission_rate',
        'notify_on_session_complete',
        'notify_on_conversion',
        'sessions_count',
        'conversions_count',
        'total_commission',
        'status',
        'contact_email',
        'contact_name',
    ];

    protected $casts = [
        'data_fields' => 'array',
        'commission_rate' => 'decimal:2',
        'total_commission' => 'decimal:2',
        'notify_on_session_complete' => 'boolean',
        'notify_on_conversion' => 'boolean',
    ];

    protected $hidden = [
        'api_key',
    ];

    public function sessions(): HasMany
    {
        return $this->hasMany(AiSession::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function hasFullAccess(): bool
    {
        return $this->data_access === 'full';
    }

    public function hasSummaryAccess(): bool
    {
        return $this->data_access === 'summary';
    }

    public function getVisibleFields(): array
    {
        if ($this->data_access === 'full') {
            return ['*'];
        }

        if ($this->data_access === 'custom' && $this->data_fields) {
            return $this->data_fields;
        }

        // Default summary fields
        return ['summary', 'quote', 'attachments', 'client_info'];
    }

    public static function generateApiKey(string $prefix): string
    {
        return $prefix . bin2hex(random_bytes(28));
    }
}
