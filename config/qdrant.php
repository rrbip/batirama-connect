<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Collections Qdrant
    |--------------------------------------------------------------------------
    |
    | Configuration des collections vectorielles pour le RAG.
    | Chaque collection correspond à un type de données différent.
    |
    */

    'collections' => [

        /*
         * Collection pour les ouvrages BTP
         * Utilisée par l'agent expert-btp avec hydratation SQL
         */
        'agent_btp_ouvrages' => [
            'vector_size' => (int) env('QDRANT_VECTOR_SIZE', 768),
            'distance' => env('QDRANT_DISTANCE', 'Cosine'),
            'on_disk_payload' => false,
            'payload_indexes' => [
                'db_id' => 'integer',
                'type' => 'keyword',
                'category' => 'keyword',
                'subcategory' => 'keyword',
                'tenant_id' => 'integer',
            ],
        ],

        /*
         * Collection pour les documents support
         * Utilisée par l'agent support-client en mode TEXT_ONLY
         */
        'agent_support_docs' => [
            'vector_size' => (int) env('QDRANT_VECTOR_SIZE', 768),
            'distance' => env('QDRANT_DISTANCE', 'Cosine'),
            'on_disk_payload' => false,
            'payload_indexes' => [
                'category' => 'keyword',
                'source' => 'keyword',
            ],
        ],

        /*
         * Collection pour les réponses apprises
         * Utilisée par le système d'apprentissage continu
         */
        'learned_responses' => [
            'vector_size' => (int) env('QDRANT_VECTOR_SIZE', 768),
            'distance' => env('QDRANT_DISTANCE', 'Cosine'),
            'on_disk_payload' => false,
            'payload_indexes' => [
                'agent_id' => 'integer',
                'agent_slug' => 'keyword',
                'message_id' => 'integer',
            ],
        ],

    ],

];
