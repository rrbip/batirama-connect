<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PipelineToolsSetting extends Model
{
    protected $fillable = [
        'pdf_tools',
        'image_tools',
        'html_tools',
        'markdown_tools',
    ];

    protected $casts = [
        'pdf_tools' => 'array',
        'image_tools' => 'array',
        'html_tools' => 'array',
        'markdown_tools' => 'array',
    ];

    /**
     * Récupère l'instance singleton des settings
     */
    public static function getInstance(): self
    {
        $settings = static::first();

        if (!$settings) {
            $settings = static::create(static::getDefaults());
        }

        return $settings;
    }

    /**
     * Retourne les outils par défaut
     */
    public static function getDefaults(): array
    {
        return [
            'pdf_tools' => [
                ['name' => 'pdf_to_images', 'tool' => 'pdftoppm', 'enabled' => true],
                ['name' => 'images_to_markdown', 'tool' => 'vision_llm', 'enabled' => true],
                ['name' => 'markdown_to_qr', 'tool' => 'qr_atomique', 'enabled' => true],
            ],
            'image_tools' => [
                ['name' => 'image_to_markdown', 'tool' => 'vision_llm', 'enabled' => true],
                ['name' => 'markdown_to_qr', 'tool' => 'qr_atomique', 'enabled' => true],
            ],
            'html_tools' => [
                ['name' => 'html_to_markdown', 'tool' => 'turndown', 'enabled' => true],
                ['name' => 'markdown_to_qr', 'tool' => 'qr_atomique', 'enabled' => true],
            ],
            'markdown_tools' => [
                ['name' => 'markdown_to_qr', 'tool' => 'qr_atomique', 'enabled' => true],
            ],
        ];
    }

    /**
     * Retourne les outils disponibles par type
     */
    public static function getAvailableTools(): array
    {
        return [
            'pdf_to_images' => [
                'pdftoppm' => 'pdftoppm (ImageMagick)',
            ],
            'images_to_markdown' => [
                'vision_llm' => 'Vision LLM (Llama 3.2 Vision)',
            ],
            'image_to_markdown' => [
                'vision_llm' => 'Vision LLM (Llama 3.2 Vision)',
            ],
            'html_to_markdown' => [
                'turndown' => 'Turndown (JS library)',
            ],
            'markdown_to_qr' => [
                'qr_atomique' => 'Q/R Atomique (LLM)',
            ],
        ];
    }

    /**
     * Retourne les outils pour un type de document
     */
    public function getToolsForType(string $documentType): array
    {
        return match ($documentType) {
            'pdf' => $this->pdf_tools ?? static::getDefaults()['pdf_tools'],
            'image', 'png', 'jpg', 'jpeg', 'gif', 'webp' => $this->image_tools ?? static::getDefaults()['image_tools'],
            'html', 'htm' => $this->html_tools ?? static::getDefaults()['html_tools'],
            'markdown', 'md' => $this->markdown_tools ?? static::getDefaults()['markdown_tools'],
            default => [],
        };
    }
}
