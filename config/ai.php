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

        // URL pour synchroniser la liste des modèles disponibles (optionnel)
        // Format attendu: JSON { "model-key": { "name": "...", "size": "...", "type": "chat|embedding|code", "description": "..." } }
        'models_list_url' => env('OLLAMA_MODELS_LIST_URL', null),

        // Liste des modèles disponibles pour installation
        // Ces modèles sont vérifiés compatibles et recommandés
        'available_models' => [
            // Modèles de chat/génération
            'llama3.2:1b' => ['name' => 'Llama 3.2 1B', 'size' => '~1.3 GB', 'type' => 'chat', 'description' => 'Très léger, rapide'],
            'llama3.2:3b' => ['name' => 'Llama 3.2 3B', 'size' => '~2 GB', 'type' => 'chat', 'description' => 'Bon équilibre taille/qualité'],
            'llama3.1:8b' => ['name' => 'Llama 3.1 8B', 'size' => '~4.7 GB', 'type' => 'chat', 'description' => 'Recommandé pour production'],
            'mistral:7b' => ['name' => 'Mistral 7B', 'size' => '~4.1 GB', 'type' => 'chat', 'description' => 'Excellent pour le français'],
            'mixtral:8x7b' => ['name' => 'Mixtral 8x7B', 'size' => '~26 GB', 'type' => 'chat', 'description' => 'Très performant, gourmand'],
            'gemma2:2b' => ['name' => 'Gemma 2 2B', 'size' => '~1.6 GB', 'type' => 'chat', 'description' => 'Google, léger'],
            'gemma2:9b' => ['name' => 'Gemma 2 9B', 'size' => '~5.5 GB', 'type' => 'chat', 'description' => 'Google, performant'],
            'phi3:mini' => ['name' => 'Phi-3 Mini', 'size' => '~2.3 GB', 'type' => 'chat', 'description' => 'Microsoft, compact'],
            'qwen2.5:7b' => ['name' => 'Qwen 2.5 7B', 'size' => '~4.7 GB', 'type' => 'chat', 'description' => 'Alibaba, multilingue'],
            'deepseek-r1:7b' => ['name' => 'DeepSeek R1 7B', 'size' => '~4.7 GB', 'type' => 'chat', 'description' => 'Raisonnement avancé'],

            // Modèles d'embedding
            'nomic-embed-text' => ['name' => 'Nomic Embed', 'size' => '~274 MB', 'type' => 'embedding', 'description' => 'Embeddings, recommandé'],
            'mxbai-embed-large' => ['name' => 'MXBai Embed Large', 'size' => '~670 MB', 'type' => 'embedding', 'description' => 'Embeddings haute qualité'],
            'all-minilm' => ['name' => 'All-MiniLM', 'size' => '~45 MB', 'type' => 'embedding', 'description' => 'Embeddings très léger'],

            // Modèles de code
            'codellama:7b' => ['name' => 'Code Llama 7B', 'size' => '~3.8 GB', 'type' => 'code', 'description' => 'Spécialisé code'],
            'deepseek-coder:6.7b' => ['name' => 'DeepSeek Coder', 'size' => '~3.8 GB', 'type' => 'code', 'description' => 'Excellent pour le code'],
        ],
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
