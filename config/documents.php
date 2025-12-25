<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Allowed Document Types
    |--------------------------------------------------------------------------
    */

    'allowed_types' => [
        // Texte
        'text/plain' => ['extensions' => ['txt'], 'extractor' => 'text'],
        'text/markdown' => ['extensions' => ['md'], 'extractor' => 'text'],
        'text/html' => ['extensions' => ['html', 'htm'], 'extractor' => 'html'],

        // Documents
        'application/pdf' => ['extensions' => ['pdf'], 'extractor' => 'pdf'],
        'application/msword' => ['extensions' => ['doc'], 'extractor' => 'office'],
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' =>
            ['extensions' => ['docx'], 'extractor' => 'office'],
        'application/vnd.oasis.opendocument.text' =>
            ['extensions' => ['odt'], 'extractor' => 'office'],

        // Tableurs
        'text/csv' => ['extensions' => ['csv'], 'extractor' => 'csv'],
        'application/vnd.ms-excel' => ['extensions' => ['xls'], 'extractor' => 'spreadsheet'],
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' =>
            ['extensions' => ['xlsx'], 'extractor' => 'spreadsheet'],

        // Audio (transcription Whisper)
        'audio/mpeg' => ['extensions' => ['mp3'], 'extractor' => 'whisper'],
        'audio/wav' => ['extensions' => ['wav'], 'extractor' => 'whisper'],
        'audio/ogg' => ['extensions' => ['ogg'], 'extractor' => 'whisper'],
        'audio/webm' => ['extensions' => ['weba'], 'extractor' => 'whisper'],

        // Vidéo (extraction audio + transcription)
        'video/mp4' => ['extensions' => ['mp4'], 'extractor' => 'whisper'],
        'video/webm' => ['extensions' => ['webm'], 'extractor' => 'whisper'],
        'video/x-msvideo' => ['extensions' => ['avi'], 'extractor' => 'whisper'],

        // Images (OCR optionnel)
        'image/png' => ['extensions' => ['png'], 'extractor' => 'ocr'],
        'image/jpeg' => ['extensions' => ['jpg', 'jpeg'], 'extractor' => 'ocr'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Upload Limits
    |--------------------------------------------------------------------------
    */

    'max_file_size' => env('DOCUMENT_MAX_SIZE', 104857600), // 100 MB

    /*
    |--------------------------------------------------------------------------
    | Chunking Settings
    |--------------------------------------------------------------------------
    */

    'chunk_settings' => [
        'default_strategy' => 'paragraph',
        'max_chunk_size' => 300,      // Tokens approximatifs (~200 mots) - plus petit = RAG plus précis
        'chunk_overlap' => 50,        // Chevauchement entre chunks
    ],

];
