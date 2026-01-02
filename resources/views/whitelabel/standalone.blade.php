<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $branding['chat_title'] ?? 'Assistant' }}</title>
    <style>
        :root {
            --primary-color: {{ $branding['primary_color'] ?? '#1E88E5' }};
            --primary-dark: {{ adjustColor($branding['primary_color'] ?? '#1E88E5', -20) }};
            --text-color: #333;
            --text-light: #666;
            --bg-color: #fff;
            --bg-light: #f5f5f5;
            --border-color: #e0e0e0;
            --shadow: 0 2px 8px rgba(0,0,0,0.1);
            --radius: 12px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html, body {
            height: 100%;
            overflow: hidden;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            font-size: 14px;
            line-height: 1.5;
            color: var(--text-color);
            background: var(--bg-color);
            height: 100vh;
            margin: 0;
            padding: 0;
        }

        .chat-wrapper {
            width: 100%;
            height: 100vh;
            background: var(--bg-color);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        /* Header */
        .chat-header {
            background: var(--primary-color);
            color: white;
            padding: 20px 24px;
            display: flex;
            align-items: center;
            gap: 16px;
            flex-shrink: 0;
        }

        .chat-header-logo {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: rgba(255,255,255,0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            flex-shrink: 0;
        }

        .chat-header-logo img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .chat-header-logo svg {
            width: 28px;
            height: 28px;
        }

        .chat-header-info {
            flex: 1;
        }

        .chat-header-title {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 2px;
        }

        .chat-header-subtitle {
            font-size: 13px;
            opacity: 0.9;
        }

        /* Messages Container */
        .messages-container {
            flex: 1;
            overflow-y: auto;
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .message {
            display: flex;
            gap: 12px;
            max-width: 85%;
            animation: messageIn 0.3s ease;
        }

        @keyframes messageIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .message.user {
            align-self: flex-end;
            flex-direction: row-reverse;
        }

        .message.assistant {
            align-self: flex-start;
        }

        .message-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: var(--bg-light);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            overflow: hidden;
        }

        .message-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .message.user .message-avatar {
            background: var(--primary-color);
            color: white;
        }

        .message-bubble {
            padding: 12px 16px;
            border-radius: var(--radius);
            word-wrap: break-word;
            white-space: pre-wrap;
        }

        .message.user .message-bubble {
            background: var(--primary-color);
            color: white;
            border-bottom-right-radius: 4px;
        }

        .message.assistant .message-bubble {
            background: var(--bg-light);
            border-bottom-left-radius: 4px;
        }

        .message-time {
            font-size: 11px;
            color: var(--text-light);
            margin-top: 4px;
            text-align: right;
        }

        .message.assistant .message-time {
            text-align: left;
        }

        /* Support agent messages - green background */
        .message.support .message-avatar {
            background: #059669;
            color: white;
        }

        .message.support .message-bubble {
            background: #d1fae5;
            border: 1px solid #a7f3d0;
        }

        /* AI messages - same style as support but with blue/purple tint */
        .message.ai .message-avatar {
            background: #059669;
            color: white;
        }

        .message.ai .message-bubble {
            background: #d1fae5;
            border: 1px solid #a7f3d0;
        }

        /* System messages - centered gray bar */
        .system-message {
            display: flex;
            justify-content: center;
            padding: 12px 16px;
        }

        .system-message-content {
            background: #f3f4f6;
            color: #6b7280;
            font-size: 13px;
            padding: 8px 16px;
            border-radius: 16px;
            text-align: center;
            max-width: 80%;
        }

        /* Welcome Message */
        .welcome-section {
            text-align: center;
            padding: 40px 20px;
        }

        .welcome-icon {
            width: 80px;
            height: 80px;
            margin: 0 auto 20px;
            background: var(--bg-light);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }

        .welcome-icon img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .welcome-icon svg {
            width: 40px;
            height: 40px;
            color: var(--primary-color);
        }

        .welcome-title {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .welcome-text {
            color: var(--text-light);
            max-width: 300px;
            margin: 0 auto;
        }

        /* Typing Indicator */
        .typing-indicator {
            display: none;
            align-self: flex-start;
            padding: 12px 16px;
            background: var(--bg-light);
            border-radius: var(--radius);
            border-bottom-left-radius: 4px;
        }

        .typing-indicator.visible {
            display: flex;
            gap: 4px;
        }

        .typing-dot {
            width: 8px;
            height: 8px;
            background: var(--text-light);
            border-radius: 50%;
            animation: typing 1.4s infinite;
        }

        .typing-dot:nth-child(2) { animation-delay: 0.2s; }
        .typing-dot:nth-child(3) { animation-delay: 0.4s; }

        @keyframes typing {
            0%, 60%, 100% { transform: translateY(0); }
            30% { transform: translateY(-4px); }
        }

        /* Input Area */
        .input-container {
            padding: 16px 20px;
            border-top: 1px solid var(--border-color);
            background: var(--bg-color);
            flex-shrink: 0;
        }

        .input-wrapper {
            display: flex;
            gap: 12px;
            align-items: flex-end;
        }

        .input-field {
            flex: 1;
            min-height: 44px;
            max-height: 120px;
            padding: 12px 16px;
            border: 1px solid var(--border-color);
            border-radius: 22px;
            font-size: 14px;
            font-family: inherit;
            resize: none;
            outline: none;
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        .input-field:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(30, 136, 229, 0.1);
        }

        .input-field::placeholder {
            color: #999;
        }

        .send-button {
            width: 44px;
            height: 44px;
            border: none;
            background: var(--primary-color);
            color: white;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.2s, transform 0.2s;
            flex-shrink: 0;
        }

        .send-button:hover:not(:disabled) {
            background: var(--primary-dark);
            transform: scale(1.05);
        }

        .send-button:disabled {
            background: #ccc;
            cursor: not-allowed;
        }

        /* Powered By Footer */
        .powered-by {
            text-align: center;
            padding: 12px;
            font-size: 11px;
            color: var(--text-light);
            background: var(--bg-light);
        }

        .powered-by a {
            color: var(--primary-color);
            text-decoration: none;
        }

        .powered-by a:hover {
            text-decoration: underline;
        }

        /* Loading State */
        .loading-overlay {
            position: absolute;
            inset: 0;
            background: rgba(255,255,255,0.95);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            z-index: 100;
            border-radius: 20px;
        }

        .loading-spinner {
            width: 48px;
            height: 48px;
            border: 4px solid var(--border-color);
            border-top-color: var(--primary-color);
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-bottom: 16px;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .loading-text {
            color: var(--text-light);
            font-size: 14px;
        }

        /* Error Message */
        .error-toast {
            position: fixed;
            bottom: 100px;
            left: 50%;
            transform: translateX(-50%);
            background: #f44336;
            color: white;
            padding: 12px 24px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
            z-index: 1000;
            animation: toastIn 0.3s ease;
        }

        @keyframes toastIn {
            from {
                opacity: 0;
                transform: translateX(-50%) translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateX(-50%) translateY(0);
            }
        }

        /* Upload Button */
        .upload-button {
            width: 44px;
            height: 44px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--bg-light);
            border-radius: 50%;
            cursor: pointer;
            transition: background 0.2s;
            flex-shrink: 0;
        }

        .upload-button:hover {
            background: var(--border-color);
        }

        .upload-button svg {
            color: var(--text-light);
        }

        /* File Preview */
        .file-preview {
            padding: 8px 16px;
            background: var(--bg-light);
            border-bottom: 1px solid var(--border-color);
        }

        .file-preview-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 6px 10px;
            background: white;
            border-radius: 8px;
            font-size: 13px;
        }

        .file-name {
            flex: 1;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            color: var(--text-color);
        }

        .file-remove {
            background: none;
            border: none;
            color: var(--text-light);
            cursor: pointer;
            font-size: 18px;
            padding: 0 4px;
            line-height: 1;
        }

        .file-remove:hover {
            color: #f44336;
        }

        /* Error Banner (persistent) */
        .error-banner {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            background: #f44336;
            color: white;
            padding: 12px 40px 12px 16px;
            font-size: 14px;
            z-index: 1000;
        }

        .error-banner-close {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: white;
            font-size: 20px;
            cursor: pointer;
            padding: 0 4px;
        }

        /* Email Collection Form */
        .email-form-overlay {
            display: none;
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            background: linear-gradient(to top, rgba(255,255,255,0.98) 80%, rgba(255,255,255,0.9));
            padding: 20px;
            border-top: 1px solid var(--border-color);
            z-index: 50;
            animation: slideUp 0.3s ease;
        }

        .email-form-overlay.visible {
            display: block;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .email-form-title {
            font-size: 15px;
            font-weight: 600;
            margin-bottom: 4px;
            color: var(--text-color);
        }

        .email-form-subtitle {
            font-size: 13px;
            color: var(--text-light);
            margin-bottom: 16px;
        }

        .email-form-wrapper {
            display: flex;
            gap: 12px;
            align-items: center;
        }

        .email-form-input {
            flex: 1;
            padding: 12px 16px;
            border: 1px solid var(--border-color);
            border-radius: 22px;
            font-size: 14px;
            font-family: inherit;
            outline: none;
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        .email-form-input:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(30, 136, 229, 0.1);
        }

        .email-form-input::placeholder {
            color: #999;
        }

        .email-form-submit {
            padding: 12px 24px;
            background: var(--primary-color);
            color: white;
            border: none;
            border-radius: 22px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: background 0.2s;
            flex-shrink: 0;
        }

        .email-form-submit:hover:not(:disabled) {
            background: var(--primary-dark);
        }

        .email-form-submit:disabled {
            background: #ccc;
            cursor: not-allowed;
        }

        .email-form-error {
            color: #f44336;
            font-size: 12px;
            margin-top: 8px;
        }

        .email-form-success {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #059669;
            font-size: 14px;
        }

        .email-form-success svg {
            width: 20px;
            height: 20px;
        }

        /* Responsive */
        @media (max-width: 520px) {
            body {
                padding: 0;
            }

            .chat-wrapper {
                max-width: 100%;
                height: 100vh;
                max-height: none;
                border-radius: 0;
            }

            .upload-button {
                width: 40px;
                height: 40px;
            }
        }
    </style>
</head>
<body>
    <div class="chat-wrapper" id="chatWrapper">
        <!-- Loading Overlay -->
        <div class="loading-overlay" id="loadingOverlay">
            <div class="loading-spinner"></div>
            <div class="loading-text">Chargement...</div>
        </div>

        <!-- Header -->
        <div class="chat-header">
            <div class="chat-header-logo" id="headerLogo">
                @if(!empty($branding['logo_url']))
                    <img src="{{ $branding['logo_url'] }}" alt="Logo">
                @else
                    <svg viewBox="0 0 24 24" fill="currentColor">
                        <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/>
                    </svg>
                @endif
            </div>
            <div class="chat-header-info">
                <div class="chat-header-title" id="headerTitle">{{ $branding['chat_title'] ?? $agent->name ?? 'Assistant' }}</div>
                <div class="chat-header-subtitle" id="headerSubtitle">En ligne</div>
            </div>
        </div>

        <!-- Messages -->
        <div class="messages-container" id="messagesContainer">
            <div class="welcome-section" id="welcomeSection">
                <div class="welcome-icon">
                    @if(!empty($branding['logo_url']))
                        <img src="{{ $branding['logo_url'] }}" alt="Logo">
                    @else
                        <svg viewBox="0 0 24 24" fill="currentColor">
                            <path d="M12 3c5.5 0 10 3.58 10 8s-4.5 8-10 8c-1.24 0-2.43-.18-3.53-.5L5 20.5l1.12-3.35A7.94 7.94 0 012 11c0-4.42 4.5-8 10-8zm0 2c-4.42 0-8 2.69-8 6 0 1.66.75 3.18 2 4.35l.26.24-.84 2.52 3.04-1.01.36.12C9.87 17.73 10.91 18 12 18c4.42 0 8-2.69 8-6s-3.58-6-8-6z"/>
                        </svg>
                    @endif
                </div>
                <h2 class="welcome-title">{{ $branding['header_text'] ?? 'Bienvenue !' }}</h2>
                <p class="welcome-text">{{ $branding['welcome_message'] ?? 'Comment puis-je vous aider aujourd\'hui ?' }}</p>
            </div>

            <!-- Typing Indicator -->
            <div class="typing-indicator" id="typingIndicator">
                <span class="typing-dot"></span>
                <span class="typing-dot"></span>
                <span class="typing-dot"></span>
            </div>
        </div>

        <!-- Email Collection Form (shown when escalated without email) -->
        <div class="email-form-overlay" id="emailFormOverlay">
            <div class="email-form-title">📧 Laissez-nous votre email</div>
            <div class="email-form-subtitle">Un conseiller vous répondra dès que possible.</div>
            <form id="emailForm">
                <div class="email-form-wrapper">
                    <input type="email" class="email-form-input" id="emailInput" placeholder="votre@email.com" required>
                    <button type="submit" class="email-form-submit" id="emailSubmit">Envoyer</button>
                </div>
                <div class="email-form-error" id="emailError" style="display: none;"></div>
            </form>
            <div class="email-form-success" id="emailSuccess" style="display: none;">
                <svg viewBox="0 0 24 24" fill="currentColor">
                    <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/>
                </svg>
                <span>Merci ! Nous vous répondrons rapidement.</span>
            </div>
        </div>

        <!-- Input Area -->
        <div class="input-container">
            <!-- File preview area -->
            <div class="file-preview" id="filePreview" style="display: none;">
                <div class="file-preview-item" id="filePreviewItem">
                    <span class="file-name" id="fileName"></span>
                    <button class="file-remove" id="fileRemove" type="button">&times;</button>
                </div>
            </div>
            <div class="input-wrapper">
                @if($config['attachments_enabled'] ?? false)
                <label class="upload-button" for="fileInput" title="Joindre un fichier">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor">
                        <path d="M16.5 6v11.5c0 2.21-1.79 4-4 4s-4-1.79-4-4V5c0-1.38 1.12-2.5 2.5-2.5s2.5 1.12 2.5 2.5v10.5c0 .55-.45 1-1 1s-1-.45-1-1V6H10v9.5c0 1.38 1.12 2.5 2.5 2.5s2.5-1.12 2.5-2.5V5c0-2.21-1.79-4-4-4S7 2.79 7 5v12.5c0 3.04 2.46 5.5 5.5 5.5s5.5-2.46 5.5-5.5V6h-1.5z"/>
                    </svg>
                    <input type="file" id="fileInput" accept=".pdf,.jpg,.jpeg,.png,.gif,.doc,.docx,.xls,.xlsx,.txt,.csv" style="display: none;">
                </label>
                @endif
                <textarea
                    class="input-field"
                    id="inputField"
                    placeholder="{{ $branding['placeholder_text'] ?? 'Tapez votre message...' }}"
                    rows="1"
                    maxlength="{{ $config['max_message_length'] ?? 2000 }}"
                ></textarea>
                <button class="send-button" id="sendButton" disabled>
                    <svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor">
                        <path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/>
                    </svg>
                </button>
            </div>
        </div>

        <!-- Powered By -->
        @if($branding['powered_by'] ?? true)
            <div class="powered-by">
                {{ $branding['signature'] ?? 'Propulsé par' }} <a href="https://batirama.fr" target="_blank" rel="noopener">Batirama</a>
            </div>
        @endif
    </div>

    <!-- Soketi/Echo for WebSocket -->
    <script src="https://cdn.jsdelivr.net/npm/pusher-js@8.3.0/dist/web/pusher.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/laravel-echo@1.15.3/dist/echo.iife.js"></script>

    <script>
        (function() {
            'use strict';

            // Config from server
            var CONFIG = {
                token: @json($token),
                apiBase: @json($config['api_base']),
                deploymentKey: @json($config['deployment_key'] ?? null),
                tokenMode: @json($isLegacy ?? false),
                isWidget: @json($isWidget ?? false),
                widgetParams: @json($widgetParams ?? []),
                maxMessageLength: @json($config['max_message_length'] ?? 2000),
                attachmentsEnabled: @json($config['attachments_enabled'] ?? false),
                branding: @json($branding),
                // Soketi WebSocket config
                soketi: {
                    key: @json(config('broadcasting.connections.pusher.key')),
                    // Frontend config - use same domain via Caddy reverse proxy
                    frontendHost: @json(config('broadcasting.connections.pusher.frontend.host')) || window.location.hostname,
                    frontendPort: @json(config('broadcasting.connections.pusher.frontend.port')) || (window.location.protocol === 'https:' ? 443 : 80),
                    frontendScheme: @json(config('broadcasting.connections.pusher.frontend.scheme')) || window.location.protocol.replace(':', ''),
                    cluster: @json(config('broadcasting.connections.pusher.options.cluster', 'mt1'))
                }
            };

            // WebSocket connection state
            var wsConnected = false;
            var wsConnectionFailed = false;
            var wsConnectionState = 'initializing';

            // ═══════════════════════════════════════════════════════════════
            // SOKETI DEBUG - Configuration détaillée
            // ═══════════════════════════════════════════════════════════════
            console.group('🔌 SOKETI WEBSOCKET DEBUG');
            console.log('📋 Configuration complète:', JSON.stringify(CONFIG.soketi, null, 2));
            console.log('🌐 Page actuelle:', {
                hostname: window.location.hostname,
                port: window.location.port,
                protocol: window.location.protocol,
                href: window.location.href
            });
            console.log('📦 Librairies disponibles:', {
                Echo: typeof Echo !== 'undefined' ? '✅ Chargé' : '❌ Non chargé',
                Pusher: typeof Pusher !== 'undefined' ? '✅ Chargé' : '❌ Non chargé'
            });
            console.log('🔑 Clé Soketi:', CONFIG.soketi.key || '(vide)');
            console.log('🏠 Host frontend:', CONFIG.soketi.frontendHost);
            console.log('🚪 Port frontend:', CONFIG.soketi.frontendPort);
            console.log('🔒 Scheme:', CONFIG.soketi.frontendScheme);
            console.log('🌍 Cluster:', CONFIG.soketi.cluster);

            // Calculer l'URL WebSocket attendue
            var expectedWsUrl = (CONFIG.soketi.frontendScheme === 'https' ? 'wss://' : 'ws://') +
                CONFIG.soketi.frontendHost +
                (CONFIG.soketi.frontendPort && CONFIG.soketi.frontendPort != 80 && CONFIG.soketi.frontendPort != 443 ? ':' + CONFIG.soketi.frontendPort : '') +
                '/app/' + CONFIG.soketi.key;
            console.log('🔗 URL WebSocket attendue:', expectedWsUrl);
            console.groupEnd();

            // Vérifications de configuration
            var configErrors = [];
            if (!CONFIG.soketi.key) configErrors.push('❌ Clé Soketi manquante (PUSHER_APP_KEY)');
            if (CONFIG.soketi.key === 'app-key') configErrors.push('⚠️ Clé Soketi par défaut "app-key" - non configurée');
            if (!CONFIG.soketi.frontendHost) configErrors.push('❌ Host frontend manquant');

            if (configErrors.length > 0) {
                console.group('⚠️ PROBLÈMES DE CONFIGURATION SOKETI');
                configErrors.forEach(function(err) { console.warn(err); });
                console.groupEnd();
            }

            if (typeof Echo !== 'undefined' && typeof Pusher !== 'undefined' && CONFIG.soketi.key && CONFIG.soketi.key !== 'app-key') {
                // Enable Pusher logging for debugging
                Pusher.logToConsole = true;

                var useTLS = CONFIG.soketi.frontendScheme === 'https';
                var echoConfig = {
                    broadcaster: 'pusher',
                    key: CONFIG.soketi.key,
                    wsHost: CONFIG.soketi.frontendHost,
                    wsPort: useTLS ? 443 : CONFIG.soketi.frontendPort,
                    wssPort: useTLS ? 443 : CONFIG.soketi.frontendPort,
                    forceTLS: useTLS,
                    encrypted: useTLS,
                    disableStats: true,
                    enabledTransports: ['ws', 'wss'],
                    cluster: CONFIG.soketi.cluster
                };

                console.group('🔧 CONFIGURATION ECHO/PUSHER');
                console.log('Configuration Echo:', JSON.stringify(echoConfig, null, 2));
                console.groupEnd();

                window.Echo = new Echo(echoConfig);

                // Configurer l'auth guest pour les canaux de présence
                setupGuestAuth();

                // Log tous les états de connexion
                window.Echo.connector.pusher.connection.bind('initialized', function() {
                    wsConnectionState = 'initialized';
                    console.log('🔄 Soketi: INITIALIZED - Connexion initialisée');
                });

                window.Echo.connector.pusher.connection.bind('connecting', function() {
                    wsConnectionState = 'connecting';
                    console.log('🔄 Soketi: CONNECTING - Tentative de connexion...');
                });

                window.Echo.connector.pusher.connection.bind('connected', function() {
                    wsConnectionState = 'connected';
                    wsConnected = true;
                    wsConnectionFailed = false;
                    console.log('✅ Soketi: CONNECTED - WebSocket connecté !');
                    console.log('   Socket ID:', window.Echo.socketId());
                });

                window.Echo.connector.pusher.connection.bind('disconnected', function() {
                    wsConnectionState = 'disconnected';
                    wsConnected = false;
                    console.log('❌ Soketi: DISCONNECTED - WebSocket déconnecté');
                });

                window.Echo.connector.pusher.connection.bind('error', function(err) {
                    wsConnectionState = 'error';
                    wsConnectionFailed = true;
                    wsConnected = false;
                    console.group('❌ Soketi: ERROR');
                    console.error('Erreur:', err);
                    if (err && err.error && err.error.data) {
                        console.error('Code:', err.error.data.code);
                        console.error('Message:', err.error.data.message);
                    }
                    console.log('💡 Causes possibles:');
                    console.log('   - Soketi n\'est pas démarré');
                    console.log('   - Mauvaise configuration host/port');
                    console.log('   - Reverse proxy (Apache/Nginx) ne forward pas les WebSockets');
                    console.log('   - Pare-feu bloque le port');
                    console.groupEnd();
                });

                window.Echo.connector.pusher.connection.bind('unavailable', function() {
                    wsConnectionState = 'unavailable';
                    wsConnectionFailed = true;
                    wsConnected = false;
                    console.warn('⚠️ Soketi: UNAVAILABLE - WebSocket indisponible, fallback polling actif');
                });

                window.Echo.connector.pusher.connection.bind('failed', function() {
                    wsConnectionState = 'failed';
                    wsConnectionFailed = true;
                    wsConnected = false;
                    console.error('💀 Soketi: FAILED - Échec total de connexion');
                });

                window.Echo.connector.pusher.connection.bind('state_change', function(states) {
                    console.log('🔀 Soketi: État changé:', states.previous, '→', states.current);
                });

                console.log('🔌 Soketi WebSocket: Initialisation terminée, en attente de connexion...');
            } else {
                console.group('⚠️ SOKETI NON CONFIGURÉ - MODE POLLING');
                if (typeof Echo === 'undefined') console.warn('   Echo.js non chargé');
                if (typeof Pusher === 'undefined') console.warn('   Pusher.js non chargé');
                if (!CONFIG.soketi.key) console.warn('   Clé Soketi vide');
                if (CONFIG.soketi.key === 'app-key') console.warn('   Clé Soketi par défaut (non configurée)');
                console.log('   → Le chat utilisera le polling HTTP (1 req/sec)');
                console.groupEnd();

                wsConnectionFailed = true;
                wsConnectionState = 'disabled';
                // Create a mock Echo to prevent errors
                window.Echo = {
                    channel: function() { return { listen: function() { return this; } }; },
                    private: function() { return { listen: function() { return this; } }; },
                    join: function() { return { listen: function() { return this; } }; },
                    leave: function() {},
                    socketId: function() { return null; }
                };
            }

            // Helper function to check if WebSocket is usable
            function isWebSocketAvailable() {
                var available = wsConnected && !wsConnectionFailed && window.Echo && window.Echo.connector;
                return available;
            }

            // Fonction pour afficher l'état actuel dans la console
            window.soketiStatus = function() {
                console.group('📊 SOKETI STATUS');
                console.log('État connexion:', wsConnectionState);
                console.log('Connecté:', wsConnected);
                console.log('Échec:', wsConnectionFailed);
                console.log('WebSocket disponible:', isWebSocketAvailable());
                if (window.Echo && window.Echo.connector && window.Echo.connector.pusher) {
                    console.log('Socket ID:', window.Echo.socketId());
                    console.log('État Pusher:', window.Echo.connector.pusher.connection.state);
                }
                console.groupEnd();
            };
            console.log('💡 Tapez soketiStatus() dans la console pour voir l\'état actuel');

            // State
            var state = {
                session: null,
                messages: [],
                isLoading: true,
                isSending: false,
                currentFile: null,
                uploadedAttachment: null,
                isHumanSupportActive: false,  // True when escalated/assigned - hide AI typing
                userEmail: null,  // User email (if provided)
                asyncMode: false  // True when outside support hours or no agents connected - show email form
            };

            // DOM Elements
            var elements = {
                loadingOverlay: document.getElementById('loadingOverlay'),
                messagesContainer: document.getElementById('messagesContainer'),
                welcomeSection: document.getElementById('welcomeSection'),
                typingIndicator: document.getElementById('typingIndicator'),
                inputField: document.getElementById('inputField'),
                sendButton: document.getElementById('sendButton'),
                fileInput: document.getElementById('fileInput'),
                filePreview: document.getElementById('filePreview'),
                fileName: document.getElementById('fileName'),
                fileRemove: document.getElementById('fileRemove'),
                // Email form elements
                emailFormOverlay: document.getElementById('emailFormOverlay'),
                emailForm: document.getElementById('emailForm'),
                emailInput: document.getElementById('emailInput'),
                emailSubmit: document.getElementById('emailSubmit'),
                emailError: document.getElementById('emailError'),
                emailSuccess: document.getElementById('emailSuccess')
            };

            // Helper functions
            function escapeHtml(text) {
                var div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML;
            }

            function formatTime(date) {
                return new Date(date).toLocaleTimeString('fr-FR', {
                    hour: '2-digit',
                    minute: '2-digit'
                });
            }

            function showError(message) {
                var toast = document.createElement('div');
                toast.className = 'error-toast';
                toast.textContent = message;
                document.body.appendChild(toast);
                setTimeout(function() {
                    toast.remove();
                }, 5000);
            }

            function scrollToBottom() {
                elements.messagesContainer.scrollTop = elements.messagesContainer.scrollHeight;
            }

            // ═══════════════════════════════════════════════════════════════
            // EMAIL COLLECTION FORM
            // ═══════════════════════════════════════════════════════════════

            // Show email collection form (only if in async mode and no email set)
            function showEmailForm() {
                if (state.userEmail) {
                    console.log('📧 Email already set:', state.userEmail);
                    return;
                }
                if (!state.asyncMode) {
                    console.log('📧 Not in async mode (support agents available) - no email form needed');
                    return;
                }
                console.log('📧 Showing email collection form (async mode)');
                elements.emailFormOverlay.classList.add('visible');
                elements.emailInput.focus();
            }

            // Hide email collection form
            function hideEmailForm() {
                elements.emailFormOverlay.classList.remove('visible');
            }

            // Submit email
            async function submitEmail(email) {
                if (!email || !email.trim()) return;

                elements.emailSubmit.disabled = true;
                elements.emailError.style.display = 'none';

                try {
                    var response = await apiRequest('POST', '/c/' + CONFIG.token + '/email', {
                        email: email.trim()
                    });

                    if (response.success) {
                        state.userEmail = email.trim();
                        console.log('📧 Email saved:', state.userEmail);

                        // Show success message
                        elements.emailForm.style.display = 'none';
                        elements.emailSuccess.style.display = 'flex';

                        // Hide the form after 3 seconds
                        setTimeout(function() {
                            hideEmailForm();
                            // Reset form for potential future use
                            elements.emailForm.style.display = 'block';
                            elements.emailSuccess.style.display = 'none';
                            elements.emailInput.value = '';
                        }, 3000);
                    }
                } catch (error) {
                    console.error('Email submission error:', error);
                    elements.emailError.textContent = error.message || 'Erreur lors de l\'envoi';
                    elements.emailError.style.display = 'block';
                    elements.emailSubmit.disabled = false;
                }
            }

            // File handling functions
            function handleFileSelect(file) {
                if (!file) return;

                // Check file size (10MB max)
                if (file.size > 10 * 1024 * 1024) {
                    showError('Fichier trop volumineux (max 10 Mo)');
                    return;
                }

                state.currentFile = file;
                elements.fileName.textContent = file.name;
                elements.filePreview.style.display = 'block';
                updateSendButton();
            }

            function clearFile() {
                state.currentFile = null;
                state.uploadedAttachment = null;
                if (elements.fileInput) elements.fileInput.value = '';
                if (elements.filePreview) elements.filePreview.style.display = 'none';
                updateSendButton();
            }

            async function uploadFile(file) {
                var formData = new FormData();
                formData.append('file', file);

                var response = await fetch(CONFIG.apiBase + '/c/' + CONFIG.token + '/upload', {
                    method: 'POST',
                    headers: CONFIG.deploymentKey ? { 'X-Deployment-Key': CONFIG.deploymentKey } : {},
                    body: formData
                });

                var json = await response.json();
                if (!response.ok) {
                    throw new Error(json.message || 'Erreur upload');
                }

                return json.data;
            }

            // Add message to UI
            function addMessage(message) {
                state.messages.push(message);
                elements.welcomeSection.style.display = 'none';

                var messageEl = document.createElement('div');
                // Map roles to CSS classes
                var roleClass;
                if (message.role === 'support') {
                    roleClass = 'assistant support';
                } else if (message.role === 'assistant') {
                    roleClass = 'assistant ai';
                } else {
                    roleClass = message.role;
                }
                messageEl.className = 'message ' + roleClass;

                var avatarContent;

                if (message.role === 'user') {
                    avatarContent = '<svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>';
                } else if (message.role === 'support') {
                    // Support agent icon (person)
                    avatarContent = '<svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 3c1.66 0 3 1.34 3 3s-1.34 3-3 3-3-1.34-3-3 1.34-3 3-3zm0 14.2c-2.5 0-4.71-1.28-6-3.22.03-1.99 4-3.08 6-3.08 1.99 0 5.97 1.09 6 3.08-1.29 1.94-3.5 3.22-6 3.22z"/></svg>';
                } else {
                    // AI icon (same as support for consistent look)
                    avatarContent = '<svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 3c1.66 0 3 1.34 3 3s-1.34 3-3 3-3-1.34-3-3 1.34-3 3-3zm0 14.2c-2.5 0-4.71-1.28-6-3.22.03-1.99 4-3.08 6-3.08 1.99 0 5.97 1.09 6 3.08-1.29 1.94-3.5 3.22-6 3.22z"/></svg>';
                }

                // Build time and sender info
                var timeInfo = formatTime(message.created_at || new Date());
                if (message.role === 'support' && message.sender_name) {
                    timeInfo = message.sender_name + ' · ' + timeInfo;
                } else if (message.role === 'assistant') {
                    timeInfo = 'IA · ' + timeInfo;
                }

                messageEl.innerHTML = '<div class="message-avatar">' + avatarContent + '</div>' +
                    '<div class="message-content">' +
                    '<div class="message-bubble">' + escapeHtml(message.content) + '</div>' +
                    '<div class="message-time">' + timeInfo + '</div>' +
                    '</div>';

                elements.messagesContainer.insertBefore(messageEl, elements.typingIndicator);
                scrollToBottom();
            }

            // Show/hide typing indicator
            // Ne pas afficher quand le support humain est actif (l'utilisateur ne doit pas voir l'IA réfléchir)
            function showTyping() {
                if (state.isHumanSupportActive) {
                    console.log('🤖 AI typing hidden (human support mode)');
                    return; // Ne pas montrer le typing en mode support humain
                }
                elements.typingIndicator.classList.add('visible');
                scrollToBottom();
            }

            function hideTyping() {
                elements.typingIndicator.classList.remove('visible');
            }

            // API request helper
            async function apiRequest(method, endpoint, data) {
                var headers = {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                };

                if (CONFIG.deploymentKey) {
                    headers['X-Deployment-Key'] = CONFIG.deploymentKey;
                }

                var options = {
                    method: method,
                    headers: headers
                };

                if (data && method !== 'GET') {
                    options.body = JSON.stringify(data);
                }

                var response = await fetch(CONFIG.apiBase + endpoint, options);
                var json = await response.json();

                if (!response.ok) {
                    var errorMsg = json.message || json.error || 'Erreur de requête';
                    // Ajouter le debug info si disponible (APP_DEBUG=true)
                    if (json.debug) {
                        console.error('API Debug:', json.debug);
                        errorMsg += ' (' + json.debug + ')';
                    }
                    throw new Error(errorMsg);
                }

                return json;
            }

            // Initialize session
            async function initSession() {
                try {
                    if (CONFIG.isWidget) {
                        // Widget mode - create session on first message, not now
                        // Just hide loading and wait for user input
                        elements.loadingOverlay.style.display = 'none';
                        state.isLoading = false;
                        return;
                    }

                    if (CONFIG.tokenMode) {
                        // Legacy public token mode
                        var startResponse = await apiRequest('POST', '/c/' + CONFIG.token + '/start');
                        state.session = { session_id: startResponse.data.session_id };

                        // Load history
                        var historyResponse = await apiRequest('GET', '/c/' + CONFIG.token + '/history');
                        if (historyResponse.data.messages) {
                            historyResponse.data.messages.forEach(function(msg) {
                                loadMessageByRole(msg);
                            });
                        }
                        // Vérifier si le support humain est actif
                        if (historyResponse.data.support_status && ['escalated', 'assigned'].includes(historyResponse.data.support_status)) {
                            state.isHumanSupportActive = true;
                            console.log('🔄 Restored human support mode from session:', historyResponse.data.support_status);
                        }
                        // Capturer l'email utilisateur si présent
                        if (historyResponse.data.user_email) {
                            state.userEmail = historyResponse.data.user_email;
                            console.log('📧 User email from session:', state.userEmail);
                        }
                        // Capturer le mode async (hors horaires ou pas d'agents connectés)
                        if (historyResponse.data.async_mode !== undefined) {
                            state.asyncMode = historyResponse.data.async_mode;
                            console.log('📧 Async mode:', state.asyncMode, '(within hours:', historyResponse.data.within_support_hours, ')');
                        }
                        // Note: Le message système d'escalade est maintenant stocké en BDD
                        // et inclus dans l'historique des messages, donc on ne l'ajoute plus ici
                        // Si escaladé en mode async sans email, montrer le formulaire
                        if (historyResponse.data.support_status === 'escalated' && historyResponse.data.async_mode && !historyResponse.data.user_email) {
                            setTimeout(showEmailForm, 500);
                        }
                    } else {
                        // Whitelabel mode - create session via whitelabel API
                        var sessionResponse = await apiRequest('POST', '/whitelabel/sessions', {
                            whitelabel_token: CONFIG.token
                        });
                        state.session = sessionResponse;

                        if (sessionResponse.messages) {
                            sessionResponse.messages.forEach(function(msg) {
                                loadMessageByRole(msg);
                            });
                        }
                        // Vérifier si le support humain est actif
                        if (sessionResponse.support_status && ['escalated', 'assigned'].includes(sessionResponse.support_status)) {
                            state.isHumanSupportActive = true;
                            console.log('🔄 Restored human support mode from session:', sessionResponse.support_status);
                            // Note: Le message système d'escalade est maintenant stocké en BDD
                        }
                    }

                    elements.loadingOverlay.style.display = 'none';
                    state.isLoading = false;

                    // Scroll to bottom after loading history
                    setTimeout(scrollToBottom, 100);

                    // Subscribe to session channel for support events
                    subscribeToSessionChannel();

                    // Démarrer le ping de présence (toutes les 30 secondes)
                    startPresencePing();

                } catch (error) {
                    console.error('Init error:', error);
                    elements.loadingOverlay.querySelector('.loading-text').textContent = 'Erreur de connexion';
                    showError(error.message);
                }
            }

            // Ping de présence pour signaler que l'utilisateur est connecté (fallback HTTP)
            var pingInterval = null;
            function startPresencePing() {
                if (!CONFIG.tokenMode || !CONFIG.token) {
                    return; // Pas de ping en mode whitelabel pour l'instant
                }

                // Envoyer un ping immédiatement
                sendPresencePing();

                // Puis toutes les 30 secondes
                pingInterval = setInterval(sendPresencePing, 30000);

                // Arrêter le ping quand la page se ferme
                window.addEventListener('beforeunload', function() {
                    if (pingInterval) {
                        clearInterval(pingInterval);
                    }
                });
            }

            function sendPresencePing() {
                if (!CONFIG.token) return;

                fetch(CONFIG.baseUrl + '/c/' + CONFIG.token + '/ping', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    }
                }).catch(function(error) {
                    // Ignorer les erreurs de ping silencieusement
                    console.debug('Ping failed:', error);
                });
            }

            // Rejoindre le canal de présence pour signaler qu'on est connecté
            var presenceChannel = null;
            function joinPresenceChannel(sessionUuid) {
                if (!isWebSocketAvailable() || !CONFIG.tokenMode) {
                    return;
                }

                var presenceChannelName = 'chat.session.' + sessionUuid;

                console.log('👤 Joining presence channel:', presenceChannelName);

                // Configurer l'auth personnalisée pour les guests
                // On utilise un authorizer custom pour ce canal de présence
                var pusher = window.Echo.connector.pusher;

                // Créer un canal de présence avec auth guest
                presenceChannel = pusher.subscribe('presence-' + presenceChannelName);

                presenceChannel.bind('pusher:subscription_succeeded', function(members) {
                    console.log('✅ Presence channel joined, members:', members.count);
                });

                presenceChannel.bind('pusher:subscription_error', function(error) {
                    console.warn('❌ Presence channel subscription error:', error);
                    // Fallback sur le ping HTTP si le canal de présence ne fonctionne pas
                    startPresencePing();
                });

                presenceChannel.bind('pusher:member_added', function(member) {
                    console.log('👤 Member joined:', member);
                });

                presenceChannel.bind('pusher:member_removed', function(member) {
                    console.log('👤 Member left:', member);
                });
            }

            // Authentification guest pour les canaux de présence
            function setupGuestAuth() {
                if (!window.Echo || !window.Echo.connector || !window.Echo.connector.pusher) {
                    return;
                }

                var pusher = window.Echo.connector.pusher;

                // Override l'authorizer pour les canaux presence-chat.session.*
                var originalAuthorize = pusher.config.authorizer;

                pusher.config.authorizer = function(channel, options) {
                    // Si c'est un canal de présence pour une session de chat, utiliser l'auth guest
                    if (channel.name.startsWith('presence-chat.session.')) {
                        return {
                            authorize: function(socketId, callback) {
                                var sessionUuid = state.session?.uuid || state.session?.session_id;

                                fetch(CONFIG.baseUrl + '/broadcasting/auth/guest', {
                                    method: 'POST',
                                    headers: {
                                        'Content-Type': 'application/json',
                                        'Accept': 'application/json'
                                    },
                                    body: JSON.stringify({
                                        socket_id: socketId,
                                        channel_name: channel.name,
                                        session_uuid: sessionUuid
                                    })
                                })
                                .then(function(response) {
                                    if (!response.ok) {
                                        throw new Error('Auth failed: ' + response.status);
                                    }
                                    return response.json();
                                })
                                .then(function(data) {
                                    console.log('✅ Guest auth success for presence channel');
                                    callback(null, data);
                                })
                                .catch(function(error) {
                                    console.error('❌ Guest auth failed:', error);
                                    callback(error, null);
                                });
                            }
                        };
                    }

                    // Pour les autres canaux, utiliser l'authorizer original
                    if (originalAuthorize) {
                        return originalAuthorize(channel, options);
                    }

                    return {
                        authorize: function(socketId, callback) {
                            callback(new Error('No authorizer configured'), null);
                        }
                    };
                };

                console.log('✅ Guest auth configured for presence channels');
            }

            // Subscribe to session WebSocket channel for support events
            function subscribeToSessionChannel() {
                if (!state.session || !state.session.session_id) {
                    console.warn('Cannot subscribe to session channel: no session');
                    return;
                }

                if (!isWebSocketAvailable()) {
                    console.warn('WebSocket not available, support messages will use polling');
                    return;
                }

                var sessionUuid = state.session.uuid || state.session.session_id;
                var channelName = 'chat.session.' + sessionUuid;

                console.log('📡 Subscribing to session channel:', channelName);

                // Rejoindre le canal de présence pour signaler qu'on est connecté
                joinPresenceChannel(sessionUuid);

                window.Echo.channel(channelName)
                    // Listen for support agent messages
                    .listen('.message.new', function(data) {
                        console.log('📨 Support message received:', data);
                        if (data.message && data.message.sender_type === 'agent') {
                            addMessage({
                                role: 'support',
                                content: data.message.content,
                                sender_name: data.message.sender_name || 'Agent Support',
                                created_at: data.message.created_at
                            });
                            scrollToBottom();
                        } else if (data.message && data.message.sender_type === 'system') {
                            addSystemMessage(data.message.content);
                            scrollToBottom();
                        }
                    })
                    // Listen for session assignment (admin took over)
                    // Note: Le message système est déjà envoyé via .message.new, pas besoin de l'ajouter ici
                    .listen('.session.assigned', function(data) {
                        console.log('👤 Session assigned to agent:', data);
                        state.isHumanSupportActive = true;
                        hideTyping(); // Cacher immédiatement le typing indicator
                        // Le message "X a pris en charge votre demande" arrive via NewSupportMessage
                        scrollToBottom();
                    })
                    // Listen for escalation (confirmation)
                    .listen('.session.escalated', function(data) {
                        console.log('🚨 Session escalated:', data);
                        state.isHumanSupportActive = true;
                        hideTyping(); // Cacher immédiatement le typing indicator

                        // Mettre à jour le mode async depuis l'événement
                        if (data.async_mode !== undefined) {
                            state.asyncMode = data.async_mode;
                            console.log('📧 Async mode from event:', state.asyncMode, '(within hours:', data.within_support_hours, ')');
                        }

                        // Note: Le message système arrive via .message.new, pas besoin de l'ajouter ici
                        scrollToBottom();

                        // Afficher le formulaire de collecte d'email si mode async et pas d'email
                        if (state.asyncMode && !state.userEmail) {
                            setTimeout(showEmailForm, 1000); // Délai pour laisser le message s'afficher
                        }
                    })
                    // Listen for validated AI messages (after admin approval in human support mode)
                    .listen('.message.validated', function(data) {
                        console.log('✅ AI message validated:', data);
                        // Afficher la réponse IA validée (ou corrigée)
                        addMessage({
                            role: 'assistant',
                            content: data.content,
                            created_at: data.created_at
                        });
                        scrollToBottom();
                    });

                console.log('✅ Session channel subscribed');
            }

            // Add system message to chat
            function addSystemMessage(content) {
                var container = elements.messagesContainer;
                var messageDiv = document.createElement('div');
                messageDiv.className = 'system-message';
                messageDiv.innerHTML = '<div class="system-message-content">' + escapeHtml(content) + '</div>';
                // Insérer avant le typing indicator pour maintenir l'ordre chronologique
                container.insertBefore(messageDiv, elements.typingIndicator);
                scrollToBottom();
            }

            // Load message by role (used when loading history)
            function loadMessageByRole(msg) {
                if (msg.role === 'system') {
                    addSystemMessage(msg.content);
                } else {
                    addMessage(msg);
                }
            }

            // Create session for widget mode (called on first message)
            async function createWidgetSession() {
                var params = CONFIG.widgetParams || {};
                var sessionData = {};

                if (params.external_id) sessionData.external_id = params.external_id;
                if (params.particulier_email) sessionData.particulier_email = params.particulier_email;
                if (params.particulier_name) sessionData.particulier_name = params.particulier_name;
                if (params.context) sessionData.context = params.context;

                var sessionResponse = await apiRequest('POST', '/whitelabel/sessions', sessionData);
                state.session = sessionResponse;
                return sessionResponse;
            }

            // Send message
            async function sendMessage(content) {
                var hasContent = content.trim().length > 0;
                var hasFile = state.currentFile !== null;

                if ((!hasContent && !hasFile) || state.isSending) {
                    return;
                }

                // Widget mode: create session on first message
                if (CONFIG.isWidget && !state.session) {
                    try {
                        await createWidgetSession();
                    } catch (error) {
                        console.error('Session creation error:', error);
                        showError('Erreur de connexion: ' + error.message);
                        return;
                    }
                }

                if (!state.session) {
                    showError('Session non initialisée');
                    return;
                }

                state.isSending = true;
                elements.sendButton.disabled = true;
                elements.inputField.value = '';
                autoResize();

                // Build display message
                var displayContent = content;
                if (hasFile) {
                    displayContent = content + (content ? '\n' : '') + '📎 ' + state.currentFile.name;
                }

                addMessage({
                    role: 'user',
                    content: displayContent,
                    created_at: new Date().toISOString()
                });

                showTyping();

                try {
                    // Upload file if present
                    var attachments = [];
                    if (hasFile) {
                        try {
                            var uploadedFile = await uploadFile(state.currentFile);
                            attachments.push(uploadedFile);
                        } catch (uploadError) {
                            console.error('Upload error:', uploadError);
                            showError('Erreur upload: ' + uploadError.message);
                        }
                        clearFile();
                    }

                    var response;

                    if (CONFIG.tokenMode) {
                        // Legacy mode - use /c/{token}/message with async + hybrid WebSocket/polling
                        response = await apiRequest('POST', '/c/' + CONFIG.token + '/message', {
                            message: content || 'Fichier joint',
                            attachments: attachments,
                            async: true
                        });

                        // En mode support humain, ne pas attendre la réponse IA
                        // L'utilisateur peut continuer à envoyer des messages
                        if (state.isHumanSupportActive) {
                            console.log('📤 Message sent (human support mode) - not waiting for AI');
                            hideTyping();
                            state.isSending = false;
                            updateSendButton();
                            return;
                        }

                        var messageId = response.data.message_id;
                        var timeoutMs = 300000; // 5 minutes (same as job timeout)

                        // Hybrid approach: try WebSocket first, fallback to polling
                        await new Promise(function(resolve, reject) {
                            var resolved = false;
                            var wsTimeout = null;
                            var pollInterval = null;
                            var startTime = Date.now();

                            // Cleanup function
                            function cleanup() {
                                if (wsTimeout) clearTimeout(wsTimeout);
                                if (pollInterval) clearInterval(pollInterval);
                                if (window.Echo && window.Echo.leave) {
                                    window.Echo.leave('chat.message.' + messageId);
                                }
                            }

                            // Handle success
                            function onSuccess(data) {
                                if (resolved) return;
                                resolved = true;
                                cleanup();
                                // En mode support humain, ne pas afficher la réponse IA
                                // Elle sera affichée après validation via .message.validated
                                if (state.isHumanSupportActive) {
                                    console.log('🤖 AI response hidden (human support mode) - waiting for validation');
                                    resolve();
                                    return;
                                }
                                addMessage({
                                    role: 'assistant',
                                    content: data.content,
                                    created_at: new Date().toISOString()
                                });
                                resolve();
                            }

                            // Handle failure
                            function onError(error) {
                                if (resolved) return;
                                resolved = true;
                                cleanup();
                                reject(new Error(error || 'Erreur lors du traitement'));
                            }

                            // Global timeout
                            wsTimeout = setTimeout(function() {
                                if (!resolved) {
                                    onError('Délai d\'attente dépassé');
                                }
                            }, timeoutMs);

                            // Try WebSocket if available
                            if (isWebSocketAvailable()) {
                                console.log('🔌 Using WebSocket for message:', messageId);
                                window.Echo.channel('chat.message.' + messageId)
                                    .listen('.completed', function(data) {
                                        console.log('📨 WebSocket received:', data);
                                        onSuccess(data);
                                    })
                                    .listen('.failed', function(data) {
                                        console.log('❌ WebSocket failed:', data);
                                        onError(data.error);
                                    });
                            }

                            // Always start polling as backup (or primary if WebSocket unavailable)
                            var pollUrl = '/messages/' + messageId + '/status';
                            var pollDelay = isWebSocketAvailable() ? 3000 : 1000; // Poll less often if WebSocket active

                            console.log('📊 Starting polling (interval: ' + pollDelay + 'ms)');

                            pollInterval = setInterval(async function() {
                                if (resolved) return;

                                try {
                                    var statusResponse = await apiRequest('GET', pollUrl);
                                    var status = statusResponse.data.status;

                                    if (status === 'completed') {
                                        console.log('📊 Polling found completed message');
                                        onSuccess({
                                            content: statusResponse.data.content
                                        });
                                    } else if (status === 'failed') {
                                        console.log('📊 Polling found failed message');
                                        onError(statusResponse.data.error);
                                    }
                                    // else: still processing, continue polling
                                } catch (pollError) {
                                    console.warn('📊 Polling error (will retry):', pollError.message);
                                    // Don't fail on single poll error, continue trying
                                }
                            }, pollDelay);
                        });
                    } else {
                        // Whitelabel mode
                        response = await apiRequest('POST', '/whitelabel/sessions/' + state.session.session_id + '/messages', {
                            message: content || 'Fichier joint',
                            attachments: attachments,
                            async: state.isHumanSupportActive // Mode async si support humain actif
                        });

                        // En mode async (support humain), ne pas attendre la réponse IA
                        if (response.async) {
                            console.log('📤 Message sent (whitelabel async mode) - not waiting for AI');
                            // Update support status if returned
                            if (response.support_status && ['escalated', 'assigned'].includes(response.support_status)) {
                                state.isHumanSupportActive = true;
                            }
                            return;
                        }

                        // Mode sync: afficher la réponse immédiatement
                        if (response.response) {
                            addMessage({
                                role: 'assistant',
                                content: response.response.content,
                                sources: response.response.sources,
                                created_at: response.response.created_at
                            });
                        }
                    }

                } catch (error) {
                    console.error('Send error:', error);
                    showError(error.message || 'Erreur lors de l\'envoi');
                } finally {
                    hideTyping();
                    state.isSending = false;
                    clearFile();
                    updateSendButton();
                }
            }

            // Auto-resize textarea
            function autoResize() {
                elements.inputField.style.height = 'auto';
                elements.inputField.style.height = Math.min(elements.inputField.scrollHeight, 120) + 'px';
            }

            // Update send button state
            function updateSendButton() {
                var hasContent = elements.inputField.value.trim().length > 0;
                var hasFile = state.currentFile !== null;
                elements.sendButton.disabled = (!hasContent && !hasFile) || state.isSending;
            }

            // Event listeners
            elements.inputField.addEventListener('input', function() {
                autoResize();
                updateSendButton();
            });

            // File input event listeners
            if (elements.fileInput) {
                elements.fileInput.addEventListener('change', function(e) {
                    var file = e.target.files[0];
                    if (file) {
                        handleFileSelect(file);
                    }
                });
            }

            if (elements.fileRemove) {
                elements.fileRemove.addEventListener('click', function() {
                    clearFile();
                });
            }

            elements.inputField.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    sendMessage(elements.inputField.value);
                }
            });

            elements.sendButton.addEventListener('click', function() {
                sendMessage(elements.inputField.value);
            });

            // Email form event listeners
            if (elements.emailForm) {
                elements.emailForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    submitEmail(elements.emailInput.value);
                });
            }

            // Initialize
            initSession();

        })();
    </script>
</body>
</html>

@php
function adjustColor($hex, $percent) {
    $hex = ltrim($hex, '#');
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));

    $r = max(0, min(255, $r + $percent));
    $g = max(0, min(255, $g + $percent));
    $b = max(0, min(255, $b + $percent));

    return sprintf('#%02x%02x%02x', $r, $g, $b);
}
@endphp
