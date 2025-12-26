<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class DocumentCategory extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'description',
        'color',
        'is_ai_generated',
        'usage_count',
    ];

    protected $casts = [
        'is_ai_generated' => 'boolean',
        'usage_count' => 'integer',
    ];

    /**
     * Boot du modèle
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $category) {
            if (empty($category->slug)) {
                $category->slug = Str::slug($category->name);
            }
        });
    }

    /**
     * Chunks utilisant cette catégorie
     */
    public function chunks(): HasMany
    {
        return $this->hasMany(DocumentChunk::class, 'category_id');
    }

    /**
     * Incrémente le compteur d'utilisation
     */
    public function incrementUsage(): void
    {
        $this->increment('usage_count');
    }

    /**
     * Décrémente le compteur d'utilisation
     */
    public function decrementUsage(): void
    {
        if ($this->usage_count > 0) {
            $this->decrement('usage_count');
        }
    }

    /**
     * Recalcule le compteur d'utilisation
     */
    public function recalculateUsage(): void
    {
        $this->update([
            'usage_count' => $this->chunks()->count(),
        ]);
    }

    /**
     * Trouve ou crée une catégorie par son nom
     */
    public static function findOrCreateByName(string $name, ?string $description = null, bool $isAiGenerated = false): self
    {
        $slug = Str::slug($name);

        return static::firstOrCreate(
            ['slug' => $slug],
            [
                'name' => $name,
                'description' => $description,
                'is_ai_generated' => $isAiGenerated,
            ]
        );
    }

    /**
     * Liste des catégories pour le prompt LLM
     */
    public static function getListForPrompt(): string
    {
        $categories = static::orderBy('name')->get();

        if ($categories->isEmpty()) {
            return '(Aucune catégorie prédéfinie - tu peux en créer de nouvelles)';
        }

        return $categories->map(function (self $cat) {
            $desc = $cat->description ? " - {$cat->description}" : '';
            return "- {$cat->name}{$desc}";
        })->implode("\n");
    }

    /**
     * Couleurs prédéfinies pour les nouvelles catégories
     */
    public static function getRandomColor(): string
    {
        $colors = [
            '#EF4444', // red
            '#F97316', // orange
            '#F59E0B', // amber
            '#84CC16', // lime
            '#22C55E', // green
            '#14B8A6', // teal
            '#06B6D4', // cyan
            '#3B82F6', // blue
            '#6366F1', // indigo
            '#8B5CF6', // violet
            '#A855F7', // purple
            '#EC4899', // pink
        ];

        return $colors[array_rand($colors)];
    }
}
