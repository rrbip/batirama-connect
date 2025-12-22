<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Ouvrage extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'parent_id',
        'path',
        'depth',
        'code',
        'name',
        'description',
        'type',
        'category',
        'subcategory',
        'unit',
        'unit_price',
        'currency',
        'default_quantity',
        'technical_specs',
        'is_indexed',
        'indexed_at',
        'qdrant_point_id',
        'import_source',
        'import_id',
    ];

    protected $casts = [
        'technical_specs' => 'array',
        'unit_price' => 'decimal:4',
        'default_quantity' => 'decimal:4',
        'is_indexed' => 'boolean',
        'indexed_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Ouvrage::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Ouvrage::class, 'parent_id');
    }

    public function components(): BelongsToMany
    {
        return $this->belongsToMany(Ouvrage::class, 'ouvrage_components', 'parent_id', 'component_id')
            ->withPivot(['quantity', 'unit', 'sort_order']);
    }

    public function usedIn(): BelongsToMany
    {
        return $this->belongsToMany(Ouvrage::class, 'ouvrage_components', 'component_id', 'parent_id')
            ->withPivot(['quantity', 'unit', 'sort_order']);
    }

    public function isCompose(): bool
    {
        return $this->type === 'compose';
    }

    public function isSimple(): bool
    {
        return $this->type === 'simple';
    }

    public function getEmbeddingText(): string
    {
        $parts = [
            $this->name,
            $this->description,
            "Catégorie: {$this->category}",
            $this->subcategory ? "Sous-catégorie: {$this->subcategory}" : null,
            "Unité: {$this->unit}",
            $this->unit_price ? "Prix: {$this->unit_price}€/{$this->unit}" : null,
        ];

        if (!empty($this->technical_specs)) {
            foreach ($this->technical_specs as $key => $value) {
                $parts[] = "{$key}: {$value}";
            }
        }

        return implode('. ', array_filter($parts));
    }
}
