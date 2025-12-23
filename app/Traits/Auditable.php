<?php

declare(strict_types=1);

namespace App\Traits;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

trait Auditable
{
    /**
     * Boot the auditable trait.
     */
    public static function bootAuditable(): void
    {
        static::created(function (Model $model) {
            if (self::shouldAudit()) {
                AuditLog::log(
                    action: 'create',
                    model: $model,
                    newValues: $model->getAuditableAttributes()
                );
            }
        });

        static::updated(function (Model $model) {
            if (self::shouldAudit() && $model->wasChanged()) {
                $oldValues = [];
                $newValues = [];

                foreach ($model->getChanges() as $key => $value) {
                    if (! in_array($key, $model->getHiddenAuditAttributes())) {
                        $oldValues[$key] = $model->getOriginal($key);
                        $newValues[$key] = $value;
                    }
                }

                if (! empty($newValues)) {
                    AuditLog::log(
                        action: 'update',
                        model: $model,
                        oldValues: $oldValues,
                        newValues: $newValues
                    );
                }
            }
        });

        static::deleted(function (Model $model) {
            if (self::shouldAudit()) {
                AuditLog::log(
                    action: 'delete',
                    model: $model,
                    oldValues: $model->getAuditableAttributes()
                );
            }
        });

        // Handle soft delete restoration
        if (method_exists(static::class, 'restored')) {
            static::restored(function (Model $model) {
                if (self::shouldAudit()) {
                    AuditLog::log(
                        action: 'restore',
                        model: $model
                    );
                }
            });
        }
    }

    /**
     * Determine if audit should be performed.
     */
    protected static function shouldAudit(): bool
    {
        // Ne pas auditer pendant les seeds ou tests
        if (app()->runningInConsole() && ! app()->environment('production')) {
            return false;
        }

        return true;
    }

    /**
     * Get attributes that should be logged.
     */
    public function getAuditableAttributes(): array
    {
        $attributes = $this->attributesToArray();
        $hidden = $this->getHiddenAuditAttributes();

        return array_diff_key($attributes, array_flip($hidden));
    }

    /**
     * Get attributes that should be hidden from audit log.
     */
    public function getHiddenAuditAttributes(): array
    {
        return array_merge(
            ['password', 'remember_token', 'updated_at', 'created_at'],
            $this->hiddenFromAudit ?? []
        );
    }
}
