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
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            font-size: 14px;
            line-height: 1.5;
            color: var(--text-color);
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .chat-wrapper {
            width: 100%;
            max-width: 480px;
            height: calc(100vh - 40px);
            max-height: 700px;
            background: var(--bg-color);
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
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

        <!-- Input Area -->
        <div class="input-container">
            <div class="input-wrapper">
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

    <script>
        (function() {
            'use strict';

            // Config from server
            var CONFIG = {
                token: @json($token),
                apiBase: @json($config['api_base']),
                deploymentKey: @json($config['deployment_key'] ?? null),
                tokenMode: @json($isLegacy ?? false),
                maxMessageLength: @json($config['max_message_length'] ?? 2000),
                attachmentsEnabled: @json($config['attachments_enabled'] ?? false),
                branding: @json($branding)
            };

            // State
            var state = {
                session: null,
                messages: [],
                isLoading: true,
                isSending: false
            };

            // DOM Elements
            var elements = {
                loadingOverlay: document.getElementById('loadingOverlay'),
                messagesContainer: document.getElementById('messagesContainer'),
                welcomeSection: document.getElementById('welcomeSection'),
                typingIndicator: document.getElementById('typingIndicator'),
                inputField: document.getElementById('inputField'),
                sendButton: document.getElementById('sendButton')
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

            // Add message to UI
            function addMessage(message) {
                state.messages.push(message);
                elements.welcomeSection.style.display = 'none';

                var messageEl = document.createElement('div');
                messageEl.className = 'message ' + message.role;

                var logoUrl = CONFIG.branding.logo_url || '';
                var avatarContent = message.role === 'user'
                    ? '<svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>'
                    : (logoUrl ? '<img src="' + escapeHtml(logoUrl) + '" alt="">' : '<svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>');

                messageEl.innerHTML = '<div class="message-avatar">' + avatarContent + '</div>' +
                    '<div class="message-content">' +
                    '<div class="message-bubble">' + escapeHtml(message.content) + '</div>' +
                    '<div class="message-time">' + formatTime(message.created_at || new Date()) + '</div>' +
                    '</div>';

                elements.messagesContainer.insertBefore(messageEl, elements.typingIndicator);
                scrollToBottom();
            }

            // Show/hide typing indicator
            function showTyping() {
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
                    throw new Error(json.message || json.error || 'Erreur de requête');
                }

                return json;
            }

            // Initialize session
            async function initSession() {
                try {
                    if (CONFIG.tokenMode) {
                        // Legacy public token mode
                        var startResponse = await apiRequest('POST', '/c/' + CONFIG.token + '/start');
                        state.session = { session_id: startResponse.data.session_id };

                        // Load history
                        var historyResponse = await apiRequest('GET', '/c/' + CONFIG.token + '/history');
                        if (historyResponse.data.messages) {
                            historyResponse.data.messages.forEach(function(msg) {
                                addMessage(msg);
                            });
                        }
                    } else {
                        // Whitelabel mode - create session via whitelabel API
                        var sessionResponse = await apiRequest('POST', '/whitelabel/sessions', {
                            whitelabel_token: CONFIG.token
                        });
                        state.session = sessionResponse;

                        if (sessionResponse.messages) {
                            sessionResponse.messages.forEach(function(msg) {
                                addMessage(msg);
                            });
                        }
                    }

                    elements.loadingOverlay.style.display = 'none';
                    state.isLoading = false;

                } catch (error) {
                    console.error('Init error:', error);
                    elements.loadingOverlay.querySelector('.loading-text').textContent = 'Erreur de connexion';
                    showError(error.message);
                }
            }

            // Send message
            async function sendMessage(content) {
                if (!content.trim() || state.isSending || !state.session) {
                    return;
                }

                state.isSending = true;
                elements.sendButton.disabled = true;
                elements.inputField.value = '';
                autoResize();

                addMessage({
                    role: 'user',
                    content: content,
                    created_at: new Date().toISOString()
                });

                showTyping();

                try {
                    var response;

                    if (CONFIG.tokenMode) {
                        // Legacy mode - use /c/{token}/message
                        response = await apiRequest('POST', '/c/' + CONFIG.token + '/message', {
                            message: content,
                            async: false
                        });

                        addMessage({
                            role: 'assistant',
                            content: response.data.response,
                            created_at: new Date().toISOString()
                        });
                    } else {
                        // Whitelabel mode
                        response = await apiRequest('POST', '/whitelabel/sessions/' + state.session.session_id + '/messages', {
                            message: content
                        });

                        addMessage({
                            role: 'assistant',
                            content: response.content,
                            sources: response.sources,
                            created_at: response.created_at
                        });
                    }

                } catch (error) {
                    console.error('Send error:', error);
                    showError(error.message || 'Erreur lors de l\'envoi');
                } finally {
                    hideTyping();
                    state.isSending = false;
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
                elements.sendButton.disabled = !elements.inputField.value.trim() || state.isSending;
            }

            // Event listeners
            elements.inputField.addEventListener('input', function() {
                autoResize();
                updateSendButton();
            });

            elements.inputField.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    sendMessage(elements.inputField.value);
                }
            });

            elements.sendButton.addEventListener('click', function() {
                sendMessage(elements.inputField.value);
            });

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
