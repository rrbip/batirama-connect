<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Ollama Configuration
    |--------------------------------------------------------------------------
    */

    'ollama' => [
        'host' => env('OLLAMA_HOST', 'ollama'),
        'port' => env('OLLAMA_PORT', 11434),
        'timeout' => env('OLLAMA_TIMEOUT', 120),

        // Modèle par défaut pour la génération
        'default_model' => env('OLLAMA_DEFAULT_MODEL', 'mistral:7b'),

        // Modèle pour les embeddings
        'embedding_model' => env('OLLAMA_EMBEDDING_MODEL', 'nomic-embed-text'),

        // Temps de rétention du modèle en mémoire
        'keep_alive' => env('OLLAMA_KEEP_ALIVE', '5m'),

        // Nombre de requêtes parallèles
        'num_parallel' => env('OLLAMA_NUM_PARALLEL', 2),
    ],

    /*
    |--------------------------------------------------------------------------
    | Qdrant Configuration
    |--------------------------------------------------------------------------
    */

    'qdrant' => [
        'host' => env('QDRANT_HOST', 'qdrant'),
        'port' => env('QDRANT_PORT', 6333),
        'api_key' => env('QDRANT_API_KEY', null),
        'timeout' => env('QDRANT_TIMEOUT', 30),

        // Dimension des vecteurs (dépend du modèle d'embedding)
        'vector_size' => env('QDRANT_VECTOR_SIZE', 768),

        // Distance par défaut
        'distance' => env('QDRANT_DISTANCE', 'Cosine'),
    ],

    /*
    |--------------------------------------------------------------------------
    | RAG Configuration
    |--------------------------------------------------------------------------
    */

    'rag' => [
        // Nombre de résultats maximum à récupérer (documents)
        'max_results' => env('RAG_MAX_RESULTS', 5),

        // Score minimum pour inclure un résultat documentaire (0.5 = 50% similaire)
        'min_score' => env('RAG_MIN_SCORE', 0.5),

        // Taille du contexte (en tokens approximatifs)
        'context_size' => env('RAG_CONTEXT_SIZE', 4000),

        // Réponses apprises (learned responses)
        'max_learned_responses' => env('RAG_MAX_LEARNED_RESPONSES', 3),
        'learned_min_score' => env('RAG_LEARNED_MIN_SCORE', 0.75),
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Agent Settings
    |--------------------------------------------------------------------------
    */

    'agent_defaults' => [
        'context_window_size' => 10,
        'max_tokens' => 2048,
        'temperature' => 0.7,
        'retrieval_mode' => 'TEXT_ONLY',
    ],

    /*
    |--------------------------------------------------------------------------
    | Retrieval Modes
    |--------------------------------------------------------------------------
    */

    'retrieval_modes' => [
        'TEXT_ONLY' => 'Texte seul (pas d\'hydratation SQL)',
        'SQL_HYDRATION' => 'Hydratation SQL (enrichissement depuis la base)',
    ],

];
