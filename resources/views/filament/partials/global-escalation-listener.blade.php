{{-- Global WebSocket Listener for Support Escalations --}}
@php
    // Configuration identique à view-ai-session pour la compatibilité production
    $soketiConfig = [
        'key' => config('broadcasting.connections.pusher.key'),
        'frontendHost' => config('broadcasting.connections.pusher.frontend.host'),
        'frontendPort' => config('broadcasting.connections.pusher.frontend.port'),
        'frontendScheme' => config('broadcasting.connections.pusher.frontend.scheme'),
        'cluster' => config('broadcasting.connections.pusher.options.cluster', 'mt1'),
    ];

    // Récupérer les IDs des agents pour lesquels l'utilisateur peut gérer le support
    $supportAgentIds = [];
    $user = auth()->user();
    if ($user) {
        if ($user->hasRole('super-admin') || $user->hasRole('admin')) {
            // Super-admin et admin peuvent gérer tous les agents
            $supportAgentIds = \App\Models\Agent::where('human_support_enabled', true)->pluck('id')->toArray();
        } elseif ($user->hasRole('support-agent')) {
            // Agent de support: uniquement les agents assignés
            $supportAgentIds = $user->supportAgents()->where('human_support_enabled', true)->pluck('agents.id')->toArray();
        }
    }
@endphp

<style>
    .escalation-toast {
        position: fixed;
        top: 80px;
        right: 20px;
        max-width: 400px;
        background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
        color: white;
        border-radius: 12px;
        box-shadow: 0 10px 40px rgba(239, 68, 68, 0.4);
        z-index: 99999;
        transform: translateX(120%);
        transition: transform 0.3s cubic-bezier(0.68, -0.55, 0.265, 1.55);
        overflow: hidden;
    }
    .escalation-toast.show {
        transform: translateX(0);
    }
    .escalation-toast-content {
        padding: 16px 20px;
    }
    .escalation-toast-header {
        display: flex;
        align-items: center;
        gap: 10px;
        margin-bottom: 8px;
    }
    .escalation-toast-icon {
        width: 28px;
        height: 28px;
        background: rgba(255,255,255,0.2);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 14px;
    }
    .escalation-toast-title {
        font-weight: 600;
        font-size: 15px;
    }
    .escalation-toast-body {
        font-size: 14px;
        opacity: 0.95;
        line-height: 1.4;
    }
    .escalation-toast-session {
        margin-top: 10px;
        padding-top: 10px;
        border-top: 1px solid rgba(255,255,255,0.2);
        font-size: 13px;
    }
    .escalation-toast-action {
        display: inline-block;
        margin-top: 10px;
        padding: 8px 16px;
        background: rgba(255,255,255,0.2);
        border-radius: 6px;
        text-decoration: none;
        color: white;
        font-weight: 500;
        font-size: 13px;
        transition: background 0.2s;
    }
    .escalation-toast-action:hover {
        background: rgba(255,255,255,0.3);
        color: white;
    }
    .escalation-toast-close {
        position: absolute;
        top: 8px;
        right: 8px;
        width: 24px;
        height: 24px;
        border: none;
        background: rgba(255,255,255,0.1);
        border-radius: 50%;
        color: white;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 14px;
        transition: background 0.2s;
    }
    .escalation-toast-close:hover {
        background: rgba(255,255,255,0.2);
    }
    .escalation-toast-progress {
        height: 3px;
        background: rgba(255,255,255,0.3);
    }
    .escalation-toast-progress-bar {
        height: 100%;
        background: white;
        width: 100%;
        animation: progress 10s linear forwards;
    }
    @keyframes progress {
        from { width: 100%; }
        to { width: 0%; }
    }
</style>

<div id="escalation-toast-container"></div>

<script src="https://cdn.jsdelivr.net/npm/pusher-js@8.3.0/dist/web/pusher.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/laravel-echo@1.15.3/dist/echo.iife.js"></script>
<script>
(function() {
    // Avoid duplicate initialization
    if (window.__globalEscalationListenerInitialized) {
        console.log('🔔 Global Escalation Listener: Already initialized, skipping');
        return;
    }
    window.__globalEscalationListenerInitialized = true;

    var soketiConfig = @json($soketiConfig);
    var supportAgentIds = @json($supportAgentIds);

    // Fallback to window.location if frontend config not set
    soketiConfig.frontendHost = soketiConfig.frontendHost || window.location.hostname;
    soketiConfig.frontendPort = soketiConfig.frontendPort || (window.location.protocol === 'https:' ? 443 : 80);
    soketiConfig.frontendScheme = soketiConfig.frontendScheme || window.location.protocol.replace(':', '');

    console.group('🔔 GLOBAL ESCALATION LISTENER');
    console.log('📋 Config:', JSON.stringify(soketiConfig, null, 2));
    console.log('🔑 Key:', soketiConfig.key || '(empty)');
    console.log('🏠 Host:', soketiConfig.frontendHost);
    console.log('🚪 Port:', soketiConfig.frontendPort);
    console.log('🔒 Scheme:', soketiConfig.frontendScheme);
    console.groupEnd();

    // Wait for page to be ready
    document.addEventListener('DOMContentLoaded', function() {
        initGlobalEscalationListener();
    });

    // Also init immediately if DOM already loaded
    if (document.readyState === 'complete' || document.readyState === 'interactive') {
        setTimeout(initGlobalEscalationListener, 100);
    }

    function initGlobalEscalationListener() {
        if (!soketiConfig.key) {
            console.warn('🔔 Global Escalation Listener: No Pusher key configured, skipping');
            return;
        }

        // Check if Echo is already initialized by another component
        if (window.Echo && window.Echo.connector && window.Echo.connector.pusher) {
            console.log('🔔 Global Escalation Listener: Reusing existing Echo instance');
            subscribeToEscalations();
            subscribeToPresenceChannels();
            return;
        }

        // Create new Echo instance (same config as view-ai-session)
        try {
            var useTLS = soketiConfig.frontendScheme === 'https';

            // Get CSRF token for auth endpoint
            var csrfToken = document.querySelector('meta[name="csrf-token"]');
            var authHeaders = {};
            if (csrfToken) {
                authHeaders['X-CSRF-TOKEN'] = csrfToken.getAttribute('content');
            }

            var echoConfig = {
                broadcaster: 'pusher',
                key: soketiConfig.key,
                wsHost: soketiConfig.frontendHost,
                wsPort: useTLS ? 443 : soketiConfig.frontendPort,
                wssPort: useTLS ? 443 : soketiConfig.frontendPort,
                forceTLS: useTLS,
                encrypted: useTLS,
                disableStats: true,
                enabledTransports: ['ws', 'wss'],
                cluster: soketiConfig.cluster,
                // Required for presence channels authentication
                authEndpoint: '/broadcasting/auth',
                auth: {
                    headers: authHeaders
                }
            };

            console.log('🔧 Global Echo Config:', JSON.stringify(echoConfig, null, 2));

            window.Echo = new Echo(echoConfig);

            window.Echo.connector.pusher.connection.bind('connecting', function() {
                console.log('🔄 Global Escalation: CONNECTING...');
            });

            window.Echo.connector.pusher.connection.bind('connected', function() {
                console.log('✅ Global Escalation: CONNECTED to Soketi!');
                subscribeToEscalations();
                subscribeToPresenceChannels();
            });

            window.Echo.connector.pusher.connection.bind('error', function(err) {
                console.error('❌ Global Escalation: Connection error', err);
            });

            window.Echo.connector.pusher.connection.bind('unavailable', function() {
                console.warn('⚠️ Global Escalation: WebSocket unavailable');
            });

        } catch (e) {
            console.error('❌ Global Escalation: Failed to initialize Echo', e);
        }
    }

    function subscribeToEscalations() {
        console.log('📡 Global Escalation Listener: Subscribing to admin.escalations channel');

        window.Echo.channel('admin.escalations')
            .listen('.session.escalated', function(data) {
                console.log('🚨 Global Escalation Listener: New escalation received!', data);
                showEscalationToast(data);
                showBrowserNotification(data);
            });
    }

    // ═══════════════════════════════════════════════════════════════
    // PRESENCE CHANNELS - Track agent availability
    // ═══════════════════════════════════════════════════════════════
    // When user connects: joins presence channels for all agents they can handle
    // When user disconnects (logout, browser close): Soketi auto-removes from all channels
    var presenceChannels = [];

    function subscribeToPresenceChannels() {
        if (!supportAgentIds || supportAgentIds.length === 0) {
            console.log('👥 No agents to track presence for');
            return;
        }

        if (!window.Echo.join) {
            console.warn('👥 Echo.join not available (presence channels require authentication)');
            return;
        }

        console.log('👥 Joining presence channels for ' + supportAgentIds.length + ' agent(s)');

        supportAgentIds.forEach(function(agentId) {
            var channelName = 'presence-agent.' + agentId + '.support';

            try {
                var channel = window.Echo.join(channelName)
                    .here(function(members) {
                        console.log('👥 [Agent ' + agentId + '] Connected agents:', members.length, members.map(function(m) { return m.name; }));
                    })
                    .joining(function(member) {
                        console.log('👥 [Agent ' + agentId + '] Agent joined:', member.name);
                    })
                    .leaving(function(member) {
                        console.log('👥 [Agent ' + agentId + '] Agent left:', member.name);
                    })
                    .error(function(error) {
                        console.warn('👥 [Agent ' + agentId + '] Presence error:', error);
                    });

                presenceChannels.push({ agentId: agentId, channel: channel });
            } catch (e) {
                console.error('👥 Failed to join presence channel for agent ' + agentId + ':', e);
            }
        });

        console.log('✅ Presence channels joined for agents:', supportAgentIds);
    }

    // Cleanup on page unload (for extra safety, though Soketi handles this)
    window.addEventListener('beforeunload', function() {
        presenceChannels.forEach(function(pc) {
            try {
                window.Echo.leave('presence-agent.' + pc.agentId + '.support');
            } catch (e) {
                // Ignore errors during cleanup
            }
        });
    });

    function showEscalationToast(data) {
        var container = document.getElementById('escalation-toast-container');
        if (!container) return;

        var sessionId = data.session_id;
        var sessionUuid = data.session_uuid;
        var userName = data.user_email || data.user_name || 'Utilisateur anonyme';
        var reason = data.escalation_reason || 'manual_request';
        var agentName = data.agent_name || 'Agent IA';

        var reasonLabel = {
            'ai_handoff_request': "L'IA a demandé un transfert",
            'user_explicit_request': "L'utilisateur demande un humain",
            'manual_request': "Demande de support humain"
        }[reason] || reason;

        var viewUrl = '/admin/ai-sessions/' + sessionId;

        var toast = document.createElement('div');
        toast.className = 'escalation-toast';
        toast.innerHTML =
            '<button class="escalation-toast-close" onclick="this.parentElement.remove()">×</button>' +
            '<div class="escalation-toast-content">' +
                '<div class="escalation-toast-header">' +
                    '<div class="escalation-toast-icon">🚨</div>' +
                    '<div class="escalation-toast-title">Nouvelle escalade support</div>' +
                '</div>' +
                '<div class="escalation-toast-body">' + reasonLabel + '</div>' +
                '<div class="escalation-toast-session">' +
                    '<strong>' + agentName + '</strong><br>' +
                    'De : ' + userName +
                '</div>' +
                '<a href="' + viewUrl + '" class="escalation-toast-action">Voir la session →</a>' +
            '</div>' +
            '<div class="escalation-toast-progress"><div class="escalation-toast-progress-bar"></div></div>';

        container.appendChild(toast);

        // Trigger animation
        setTimeout(function() {
            toast.classList.add('show');
        }, 10);

        // Play notification sound
        playNotificationSound();

        // Auto-remove after 10 seconds
        setTimeout(function() {
            toast.classList.remove('show');
            setTimeout(function() {
                if (toast.parentElement) {
                    toast.remove();
                }
            }, 300);
        }, 10000);
    }

    function showBrowserNotification(data) {
        if (!('Notification' in window)) {
            return;
        }

        if (Notification.permission === 'granted') {
            createNotification(data);
        } else if (Notification.permission !== 'denied') {
            Notification.requestPermission().then(function(permission) {
                if (permission === 'granted') {
                    createNotification(data);
                }
            });
        }
    }

    function createNotification(data) {
        var userName = data.user_email || 'Utilisateur anonyme';
        var agentName = data.agent_name || 'Agent IA';

        var notification = new Notification('🚨 Nouvelle escalade support', {
            body: agentName + '\nDe : ' + userName,
            icon: '/favicon.ico',
            tag: 'escalation-' + data.session_id,
            requireInteraction: true
        });

        notification.onclick = function() {
            window.focus();
            window.location.href = '/admin/ai-sessions/' + data.session_id;
            notification.close();
        };
    }

    function playNotificationSound() {
        try {
            // Create a simple notification beep using Web Audio API
            var audioContext = new (window.AudioContext || window.webkitAudioContext)();
            var oscillator = audioContext.createOscillator();
            var gainNode = audioContext.createGain();

            oscillator.connect(gainNode);
            gainNode.connect(audioContext.destination);

            oscillator.frequency.value = 800;
            oscillator.type = 'sine';

            gainNode.gain.setValueAtTime(0.3, audioContext.currentTime);
            gainNode.gain.exponentialRampToValueAtTime(0.01, audioContext.currentTime + 0.3);

            oscillator.start(audioContext.currentTime);
            oscillator.stop(audioContext.currentTime + 0.3);

            // Second beep
            setTimeout(function() {
                var osc2 = audioContext.createOscillator();
                var gain2 = audioContext.createGain();
                osc2.connect(gain2);
                gain2.connect(audioContext.destination);
                osc2.frequency.value = 1000;
                osc2.type = 'sine';
                gain2.gain.setValueAtTime(0.3, audioContext.currentTime);
                gain2.gain.exponentialRampToValueAtTime(0.01, audioContext.currentTime + 0.3);
                osc2.start(audioContext.currentTime);
                osc2.stop(audioContext.currentTime + 0.3);
            }, 200);
        } catch (e) {
            console.log('Could not play notification sound:', e);
        }
    }
})();
</script>
