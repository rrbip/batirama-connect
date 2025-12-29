/**
 * Batirama Connect - Whitelabel Widget Loader
 *
 * Usage:
 * <script src="https://batirama.fr/whitelabel/loader.js"
 *         data-deployment-key="your-key"
 *         data-container="#chat-container"></script>
 *
 * Or with global config:
 * <script>
 *   window.BatiramaWidgetConfig = {
 *     deploymentKey: 'your-key',
 *     container: '#chat-container',
 *     externalId: 'DUR-001',
 *     particulierEmail: 'martin@email.com',
 *     particulierName: 'M. Martin',
 *     context: { project_type: 'renovation' },
 *     onReady: function(widget) { console.log('Widget ready'); },
 *     onMessage: function(message) { console.log('Message:', message); },
 *     onError: function(error) { console.error('Error:', error); }
 *   };
 * </script>
 * <script src="https://batirama.fr/whitelabel/loader.js"></script>
 */
(function() {
    'use strict';

    // Prevent multiple initializations
    if (window.BatiramaWidget) {
        console.warn('BatiramaWidget already initialized');
        return;
    }

    var VERSION = '1.0.0';
    var WIDGET_BASE_URL = (function() {
        var scripts = document.getElementsByTagName('script');
        for (var i = scripts.length - 1; i >= 0; i--) {
            var src = scripts[i].src;
            if (src && src.indexOf('loader.js') !== -1) {
                return src.replace(/\/loader\.js.*$/, '');
            }
        }
        return '';
    })();

    /**
     * Main Widget Class
     */
    function BatiramaWidget(config) {
        this.config = this._mergeConfig(config);
        this.iframe = null;
        this.session = null;
        this.ready = false;
        this.messageQueue = [];
        this._boundMessageHandler = this._handleMessage.bind(this);

        this._init();
    }

    BatiramaWidget.prototype._mergeConfig = function(config) {
        var defaults = {
            deploymentKey: null,
            container: null,
            externalId: null,
            particulierEmail: null,
            particulierName: null,
            context: {},
            position: 'bottom-right', // bottom-right, bottom-left, inline
            width: '380px',
            height: '600px',
            zIndex: 9999,
            autoOpen: false,
            onReady: null,
            onMessage: null,
            onError: null,
            onSessionStart: null,
            onSessionEnd: null
        };

        // Merge with global config if exists
        var globalConfig = window.BatiramaWidgetConfig || {};

        // Get attributes from script tag
        var scriptConfig = this._getScriptConfig();

        return Object.assign({}, defaults, globalConfig, scriptConfig, config || {});
    };

    BatiramaWidget.prototype._getScriptConfig = function() {
        var scripts = document.getElementsByTagName('script');
        var config = {};

        for (var i = scripts.length - 1; i >= 0; i--) {
            var script = scripts[i];
            if (script.src && script.src.indexOf('loader.js') !== -1) {
                if (script.dataset.deploymentKey) {
                    config.deploymentKey = script.dataset.deploymentKey;
                }
                if (script.dataset.container) {
                    config.container = script.dataset.container;
                }
                if (script.dataset.externalId) {
                    config.externalId = script.dataset.externalId;
                }
                if (script.dataset.position) {
                    config.position = script.dataset.position;
                }
                if (script.dataset.autoOpen) {
                    config.autoOpen = script.dataset.autoOpen === 'true';
                }
                break;
            }
        }

        return config;
    };

    BatiramaWidget.prototype._init = function() {
        var self = this;

        if (!this.config.deploymentKey) {
            this._error('Missing deployment key');
            return;
        }

        // Listen for messages from iframe
        window.addEventListener('message', this._boundMessageHandler);

        // Create container if inline mode
        if (this.config.container) {
            this._createInlineWidget();
        } else {
            this._createFloatingWidget();
        }
    };

    BatiramaWidget.prototype._createFloatingWidget = function() {
        var self = this;

        // Create toggle button
        this.toggleBtn = document.createElement('div');
        this.toggleBtn.className = 'batirama-widget-toggle';
        this.toggleBtn.innerHTML = '<svg viewBox="0 0 24 24" width="28" height="28"><path fill="currentColor" d="M12 3c5.5 0 10 3.58 10 8s-4.5 8-10 8c-1.24 0-2.43-.18-3.53-.5L5 20.5l1.12-3.35A7.94 7.94 0 012 11c0-4.42 4.5-8 10-8zm0 2c-4.42 0-8 2.69-8 6 0 1.66.75 3.18 2 4.35l.26.24-.84 2.52 3.04-1.01.36.12C9.87 17.73 10.91 18 12 18c4.42 0 8-2.69 8-6s-3.58-6-8-6z"/></svg>';
        this.toggleBtn.style.cssText = 'position:fixed;' + (this.config.position.indexOf('right') !== -1 ? 'right:20px;' : 'left:20px;') + 'bottom:20px;width:60px;height:60px;border-radius:50%;background:var(--batirama-primary,#1E88E5);color:white;cursor:pointer;box-shadow:0 4px 12px rgba(0,0,0,0.15);display:flex;align-items:center;justify-content:center;z-index:' + this.config.zIndex + ';transition:transform 0.2s,background 0.2s;';
        this.toggleBtn.onmouseenter = function() { this.style.transform = 'scale(1.1)'; };
        this.toggleBtn.onmouseleave = function() { this.style.transform = 'scale(1)'; };
        this.toggleBtn.onclick = function() { self.toggle(); };

        // Create widget container
        this.container = document.createElement('div');
        this.container.className = 'batirama-widget-container';
        this.container.style.cssText = 'position:fixed;' + (this.config.position.indexOf('right') !== -1 ? 'right:20px;' : 'left:20px;') + 'bottom:90px;width:' + this.config.width + ';height:' + this.config.height + ';max-height:calc(100vh - 120px);border-radius:16px;overflow:hidden;box-shadow:0 8px 32px rgba(0,0,0,0.15);z-index:' + this.config.zIndex + ';display:none;background:#fff;';

        // Create iframe
        this._createIframe();
        this.container.appendChild(this.iframe);

        document.body.appendChild(this.toggleBtn);
        document.body.appendChild(this.container);

        if (this.config.autoOpen) {
            this.open();
        }
    };

    BatiramaWidget.prototype._createInlineWidget = function() {
        var containerEl = document.querySelector(this.config.container);
        if (!containerEl) {
            this._error('Container not found: ' + this.config.container);
            return;
        }

        this.container = containerEl;
        this.container.style.cssText = 'position:relative;width:100%;height:100%;min-height:400px;overflow:hidden;';

        this._createIframe();
        this.iframe.style.cssText = 'width:100%;height:100%;border:none;';
        this.container.appendChild(this.iframe);
    };

    BatiramaWidget.prototype._createIframe = function() {
        this.iframe = document.createElement('iframe');
        this.iframe.className = 'batirama-widget-iframe';
        this.iframe.style.cssText = 'width:100%;height:100%;border:none;';
        this.iframe.setAttribute('allow', 'clipboard-write');

        var params = new URLSearchParams({
            key: this.config.deploymentKey,
            origin: window.location.origin
        });

        if (this.config.externalId) {
            params.append('external_id', this.config.externalId);
        }
        if (this.config.particulierEmail) {
            params.append('particulier_email', this.config.particulierEmail);
        }
        if (this.config.particulierName) {
            params.append('particulier_name', this.config.particulierName);
        }
        if (Object.keys(this.config.context).length > 0) {
            params.append('context', JSON.stringify(this.config.context));
        }

        this.iframe.src = WIDGET_BASE_URL + '/widget.html?' + params.toString();
    };

    BatiramaWidget.prototype._handleMessage = function(event) {
        // Verify origin (allow same origin or widget origin)
        var data = event.data;
        if (!data || !data.type || data.type.indexOf('batirama:') !== 0) {
            return;
        }

        var type = data.type.replace('batirama:', '');

        switch (type) {
            case 'ready':
                this.ready = true;
                this._processMessageQueue();
                if (this.config.onReady) {
                    this.config.onReady(this);
                }
                break;

            case 'session_started':
                this.session = data.session;
                if (this.config.onSessionStart) {
                    this.config.onSessionStart(data.session);
                }
                break;

            case 'session_ended':
                this.session = null;
                if (this.config.onSessionEnd) {
                    this.config.onSessionEnd();
                }
                break;

            case 'message':
                if (this.config.onMessage) {
                    this.config.onMessage(data.message);
                }
                break;

            case 'error':
                this._error(data.error);
                break;

            case 'resize':
                if (data.height && this.container) {
                    this.container.style.height = Math.min(data.height, parseInt(this.config.height)) + 'px';
                }
                break;

            case 'close':
                this.close();
                break;
        }
    };

    BatiramaWidget.prototype._postMessage = function(type, data) {
        if (!this.iframe || !this.iframe.contentWindow) {
            this.messageQueue.push({ type: type, data: data });
            return;
        }

        this.iframe.contentWindow.postMessage({
            type: 'batirama:' + type,
            ...data
        }, '*');
    };

    BatiramaWidget.prototype._processMessageQueue = function() {
        while (this.messageQueue.length > 0) {
            var msg = this.messageQueue.shift();
            this._postMessage(msg.type, msg.data);
        }
    };

    BatiramaWidget.prototype._error = function(message) {
        console.error('[BatiramaWidget]', message);
        if (this.config.onError) {
            this.config.onError(message);
        }
    };

    // Public API
    BatiramaWidget.prototype.open = function() {
        if (this.container && !this.config.container) {
            this.container.style.display = 'block';
            if (this.toggleBtn) {
                this.toggleBtn.innerHTML = '<svg viewBox="0 0 24 24" width="28" height="28"><path fill="currentColor" d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>';
            }
        }
        return this;
    };

    BatiramaWidget.prototype.close = function() {
        if (this.container && !this.config.container) {
            this.container.style.display = 'none';
            if (this.toggleBtn) {
                this.toggleBtn.innerHTML = '<svg viewBox="0 0 24 24" width="28" height="28"><path fill="currentColor" d="M12 3c5.5 0 10 3.58 10 8s-4.5 8-10 8c-1.24 0-2.43-.18-3.53-.5L5 20.5l1.12-3.35A7.94 7.94 0 012 11c0-4.42 4.5-8 10-8zm0 2c-4.42 0-8 2.69-8 6 0 1.66.75 3.18 2 4.35l.26.24-.84 2.52 3.04-1.01.36.12C9.87 17.73 10.91 18 12 18c4.42 0 8-2.69 8-6s-3.58-6-8-6z"/></svg>';
            }
        }
        return this;
    };

    BatiramaWidget.prototype.toggle = function() {
        if (this.container && this.container.style.display === 'none') {
            this.open();
        } else {
            this.close();
        }
        return this;
    };

    BatiramaWidget.prototype.sendMessage = function(message) {
        this._postMessage('send_message', { message: message });
        return this;
    };

    BatiramaWidget.prototype.setContext = function(context) {
        this._postMessage('set_context', { context: context });
        return this;
    };

    BatiramaWidget.prototype.destroy = function() {
        window.removeEventListener('message', this._boundMessageHandler);
        if (this.container && this.container.parentNode) {
            this.container.parentNode.removeChild(this.container);
        }
        if (this.toggleBtn && this.toggleBtn.parentNode) {
            this.toggleBtn.parentNode.removeChild(this.toggleBtn);
        }
        this.iframe = null;
        this.container = null;
        this.toggleBtn = null;
        window.BatiramaWidget = null;
    };

    // Factory function
    BatiramaWidget.init = function(config) {
        return new BatiramaWidget(config);
    };

    BatiramaWidget.VERSION = VERSION;

    // Auto-initialize if deployment key found in script tag or global config
    var autoConfig = window.BatiramaWidgetConfig || {};
    var scripts = document.getElementsByTagName('script');
    for (var i = scripts.length - 1; i >= 0; i--) {
        if (scripts[i].src && scripts[i].src.indexOf('loader.js') !== -1) {
            if (scripts[i].dataset.deploymentKey) {
                autoConfig.deploymentKey = scripts[i].dataset.deploymentKey;
            }
            break;
        }
    }

    if (autoConfig.deploymentKey) {
        window.BatiramaWidget = new BatiramaWidget(autoConfig);
    } else {
        window.BatiramaWidget = BatiramaWidget;
    }

})();
