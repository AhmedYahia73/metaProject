<!doctype html>
<html lang="en" data-theme="{{ $config->renderer()->get('theme', 'light') }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="color-scheme" content="{{ $config->renderer()->get('theme', 'light') }}">
    <title>{{ $config->get('ui.title') ?? config('app.name') . ' - API Docs' }}</title>

    <script src="https://unpkg.com/@stoplight/elements@8.4.2/web-components.min.js"></script>
    <link rel="stylesheet" href="https://unpkg.com/@stoplight/elements@8.4.2/styles.min.css">

    @include('scramble::dev-tools', ['renderer' => 'elements'])

    <script>
        const originalFetch = window.fetch;

        // intercept TryIt requests and add the XSRF-TOKEN header,
        // which is necessary for Sanctum cookie-based authentication to work correctly
        window.fetch = (url, options) => {
            const CSRF_TOKEN_COOKIE_KEY = "XSRF-TOKEN";
            const CSRF_TOKEN_HEADER_KEY = "X-XSRF-TOKEN";
            const getCookieValue = (key) => {
                const cookie = document.cookie.split(';').find((cookie) => cookie.trim().startsWith(key));
                return cookie?.split("=")[1];
            };

            const updateFetchHeaders = (
                headers,
                headerKey,
                headerValue,
            ) => {
                if (headers instanceof Headers) {
                    headers.set(headerKey, headerValue);
                } else if (Array.isArray(headers)) {
                    headers.push([headerKey, headerValue]);
                } else if (headers) {
                    headers[headerKey] = headerValue;
                }
            };
            const csrfToken = getCookieValue(CSRF_TOKEN_COOKIE_KEY);
            if (csrfToken) {
                const { headers = new Headers() } = options || {};
                updateFetchHeaders(headers, CSRF_TOKEN_HEADER_KEY, decodeURIComponent(csrfToken));
                return originalFetch(url, {
                    ...options,
                    headers,
                });
            }

            return originalFetch(url, options);
        };
    </script>

    <style>
        html, body { margin:0; height:100%; }
        body { background-color: var(--color-canvas); }
        /* issues about the dark theme of stoplight/mosaic-code-viewer using web component:
         * https://github.com/stoplightio/elements/issues/2188#issuecomment-1485461965
         */
        [data-theme="dark"] .token.property {
            color: rgb(128, 203, 196) !important;
        }
        [data-theme="dark"] .token.operator {
            color: rgb(255, 123, 114) !important;
        }
        [data-theme="dark"] .token.number {
            color: rgb(247, 140, 108) !important;
        }
        [data-theme="dark"] .token.string {
            color: rgb(165, 214, 255) !important;
        }
        [data-theme="dark"] .token.boolean {
            color: rgb(121, 192, 255) !important;
        }
        [data-theme="dark"] .token.punctuation {
            color: #dbdbdb !important;
        }
        .scramble-docs-navbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 20px;
            height: 48px;
            background-color: #0f172a;
            color: #f8fafc;
            border-bottom: 1px solid #1e293b;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            font-size: 13px;
            z-index: 9999;
            flex-shrink: 0;
        }
        .scramble-docs-brand {
            font-weight: 700;
            color: #ffffff;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
        }
        .scramble-docs-tabs {
            display: flex;
            gap: 6px;
            align-items: center;
        }
        .scramble-docs-tab {
            padding: 6px 14px;
            border-radius: 6px;
            color: #94a3b8;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.15s ease;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .scramble-docs-tab:hover {
            color: #ffffff;
            background-color: rgba(255, 255, 255, 0.08);
        }
        .scramble-docs-tab.active {
            color: #ffffff;
            background-color: #2563eb;
            font-weight: 600;
        }
    </style>
</head>
<body style="height: 100vh; overflow-y: hidden; margin: 0; display: flex; flex-direction: column;">
<header class="scramble-docs-navbar">
    <a href="{{ url('docs/api') }}" class="scramble-docs-brand">
        ⚡ {{ config('app.name', 'Meta Restaurant') }} API Docs
    </a>
    <nav class="scramble-docs-tabs">
        <a href="{{ url('docs/api') }}" class="scramble-docs-tab {{ request()->path() === 'docs/api' ? 'active' : '' }}">
            🌐 All APIs
        </a>
        <a href="{{ url('docs/api/admin') }}" class="scramble-docs-tab {{ request()->is('docs/api/admin*') || request()->is('docs/admin*') ? 'active' : '' }}">
            🛡️ Admin Folder
        </a>
        <a href="{{ url('docs/api/user') }}" class="scramble-docs-tab {{ request()->is('docs/api/user*') || request()->is('docs/user*') ? 'active' : '' }}">
            👤 User Folder
        </a>
        <a href="{{ url('docs/api/auth') }}" class="scramble-docs-tab {{ request()->is('docs/api/auth*') || request()->is('docs/auth*') ? 'active' : '' }}">
            🔐 Auth Folder
        </a>
    </nav>
</header>
<div style="flex: 1; height: calc(100vh - 48px); overflow: hidden;">
<elements-api
    id="docs"
    @foreach($config->renderer()->all(except: ['theme']) as $key => $value)
        @continue(! $value)
        {{ $key }}="{{ $value === true ? 'true' : ($value === false ? 'false' : $value) }}"
    @endforeach
/>
</div>
<script>
    (async () => {
        const docs = document.getElementById('docs');
        docs.apiDescriptionDocument = @json($spec);
    })();
</script>

@if($config->renderer()->get('theme', 'light') === 'system')
    <script>
        var mediaQuery = window.matchMedia('(prefers-color-scheme: dark)');

        function updateTheme(e) {
            if (e.matches) {
                window.document.documentElement.setAttribute('data-theme', 'dark');
                window.document.getElementsByName('color-scheme')[0].setAttribute('content', 'dark');
            } else {
                window.document.documentElement.setAttribute('data-theme', 'light');
                window.document.getElementsByName('color-scheme')[0].setAttribute('content', 'light');
            }
        }

        mediaQuery.addEventListener('change', updateTheme);
        updateTheme(mediaQuery);
    </script>
@endif
</body>
</html>
