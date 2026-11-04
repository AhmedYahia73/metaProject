<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ApiDiscoveryService
{
    protected const LOG_PREFIX = '[api-discovery]';

    protected const HTTP_TIMEOUT = 15;

    protected const JS_DOWNLOAD_TIMEOUT = 30;

    /**
     * Discover the API from a SPA frontend and fetch products.
     *
     * Algorithm:
     *  1. Download HTML from login page → extract JS bundle URLs
     *  2. Download JS bundles → extract backend base URL, login path, product path
     *  3. Login via discovered API → extract auth token
     *  4. Fetch products with Bearer token → normalize response
     *
     * @return array{products: array<int, mixed>, meta: array<string, mixed>}|null
     */
    public function discoverAndFetch(
        string $loginLink,
        string $userName,
        string $password,
        string $productsLink,
    ): ?array {
        $context = [
            'login_link' => $loginLink,
            'products_link' => $productsLink,
        ];

        try {
            // Step 1: Download HTML and extract JS bundle URLs
            Log::info(self::LOG_PREFIX.' Step 1: Downloading login page HTML.', $context);
            $jsUrls = $this->extractJsBundleUrls($loginLink);

            if (empty($jsUrls)) {
                Log::warning(self::LOG_PREFIX.' No JS bundles found in HTML.', $context);

                return null;
            }

            Log::info(self::LOG_PREFIX.' Found JS bundles.', ['count' => count($jsUrls)]);

            // Step 2: Parse JS bundles to discover API config
            Log::info(self::LOG_PREFIX.' Step 2: Parsing JS bundles for API config.');
            $apiConfig = $this->discoverApiConfig($jsUrls, $loginLink, $productsLink);

            if (! $apiConfig['base_url']) {
                Log::warning(self::LOG_PREFIX.' Could not discover backend base URL from JS bundles.', $context);

                return null;
            }

            Log::info(self::LOG_PREFIX.' Discovered API config.', [
                'base_url' => $apiConfig['base_url'],
                'login_paths' => $apiConfig['login_paths'],
                'product_paths' => $apiConfig['product_paths'],
            ]);

            // Step 3: Try to login and get auth token
            Log::info(self::LOG_PREFIX.' Step 3: Attempting login.');
            $token = $this->attemptLogin($apiConfig, $userName, $password);

            if (! $token) {
                Log::warning(self::LOG_PREFIX.' All login attempts failed.', $context);

                return null;
            }

            Log::info(self::LOG_PREFIX.' Login successful, token obtained.');

            // Step 4: Fetch products with token
            Log::info(self::LOG_PREFIX.' Step 4: Fetching products.');
            $products = $this->fetchProducts($apiConfig, $token);

            if ($products === null) {
                Log::warning(self::LOG_PREFIX.' All product fetch attempts failed.', $context);

                return null;
            }

            Log::info(self::LOG_PREFIX.' Products fetched successfully.', [
                'count' => count($products),
                'sample' => $products[0] ?? null,
            ]);

            return [
                'products' => $products,
                'meta' => [
                    'source' => 'api_discovery',
                    'base_url' => $apiConfig['base_url'],
                    'products_count' => count($products),
                    'scraped_at' => now()->toIso8601String(),
                ],
            ];
        } catch (\Throwable $e) {
            Log::error(self::LOG_PREFIX.' Uncaught exception during discovery.', [
                'error' => $e->getMessage(),
                'trace' => substr($e->getTraceAsString(), 0, 500),
                ...$context,
            ]);

            return null;
        }
    }

    /**
     * Download HTML from the login page and extract all JS bundle URLs.
     *
     * @return array<int, string>
     */
    protected function extractJsBundleUrls(string $loginLink): array
    {
        $response = Http::timeout(self::HTTP_TIMEOUT)
            ->withoutVerifying()
            ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            ])
            ->get($loginLink);

        if (! $response->successful()) {
            Log::warning(self::LOG_PREFIX.' Failed to download login page.', [
                'url' => $loginLink,
                'status' => $response->status(),
            ]);

            return [];
        }

        $html = $response->body();
        $parsed = parse_url($loginLink);
        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'] ?? '';
        $baseOrigin = $scheme.'://'.$host;
        $pathDir = dirname($parsed['path'] ?? '/');
        $pathDir = ($pathDir === '\\' || $pathDir === '/') ? '' : $pathDir;

        // Match script src and link preload/modulepreload href tags
        $rawSrcs = [];

        // 1. <script ... src="...">
        if (preg_match_all('/<script\b[^>]*?\bsrc=["\']([^"\']+\.js(?:\?[^"\']*)?)["\']/i', $html, $scriptMatches)) {
            $rawSrcs = array_merge($rawSrcs, $scriptMatches[1]);
        }

        // 2. <link rel="modulepreload|preload" ... href="...">
        if (preg_match_all('/<link\b[^>]*?\bhref=["\']([^"\']+\.js(?:\?[^"\']*)?)["\'][^>]*?>/i', $html, $linkMatches)) {
            $rawSrcs = array_merge($rawSrcs, $linkMatches[1]);
        }

        $urls = [];
        foreach ($rawSrcs as $src) {
            $cleanSrc = explode('?', $src)[0];

            if (str_starts_with($src, '//')) {
                $abs = $scheme.':'.$src;
            } elseif (str_starts_with($src, 'http://') || str_starts_with($src, 'https://')) {
                $abs = $src;
            } elseif (str_starts_with($src, '/')) {
                $abs = $baseOrigin.$src;
            } elseif (str_starts_with($src, './')) {
                $abs = $baseOrigin.$pathDir.'/'.substr($src, 2);
            } else {
                $abs = $baseOrigin.$pathDir.'/'.ltrim($src, '/');
            }

            $urls[] = $abs;
        }

        $urls = array_values(array_unique($urls));

        // Prioritize main/app bundles (likely contain API config) over vendor/chunk files
        usort($urls, function (string $a, string $b) {
            $scoreA = $this->jsBundlePriority($a);
            $scoreB = $this->jsBundlePriority($b);

            return $scoreB <=> $scoreA;
        });

        return $urls;
    }

    /**
     * Score a JS bundle URL for priority (higher = more likely to contain API config).
     */
    protected function jsBundlePriority(string $url): int
    {
        $lower = strtolower($url);
        $score = 0;

        if (str_contains($lower, 'index') || str_contains($lower, 'app') || str_contains($lower, 'main')) {
            $score += 10;
        }

        if (str_contains($lower, 'vendor') || str_contains($lower, 'chunk-vendors') || str_contains($lower, 'node_modules')) {
            $score -= 5;
        }

        if (str_contains($lower, '/assets/') || str_contains($lower, '/js/')) {
            $score += 2;
        }

        return $score;
    }

    /**
     * Parse JS bundles to discover API base URL, login paths, and product paths.
     *
     * @param  array<int, string>  $jsUrls
     * @return array{base_url: string|null, login_paths: array<int, string>, product_paths: array<int, string>}
     */
    protected function discoverApiConfig(array $jsUrls, string $loginLink, string $productsLink): array
    {
        $baseUrls = [];
        $loginPaths = [];
        $productPaths = [];

        // Limit to first 5 bundles to avoid excessive downloads
        $jsUrls = array_slice($jsUrls, 0, 5);

        foreach ($jsUrls as $jsUrl) {
            try {
                $response = Http::timeout(self::JS_DOWNLOAD_TIMEOUT)
                    ->withoutVerifying()
                    ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])
                    ->withHeaders([
                        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
                    ])
                    ->get($jsUrl);

                if (! $response->successful()) {
                    continue;
                }

                $js = $response->body();

                // Extract API base URLs from JS
                $foundUrls = $this->extractBaseUrlsFromJs($js);
                $baseUrls = array_merge($baseUrls, $foundUrls);

                // Extract login paths from JS
                $foundLoginPaths = $this->extractPathsFromJs($js, 'login');
                $loginPaths = array_merge($loginPaths, $foundLoginPaths);

                // Extract product paths from JS
                $foundProductPaths = $this->extractPathsFromJs($js, 'product');
                $productPaths = array_merge($productPaths, $foundProductPaths);
            } catch (\Throwable $e) {
                Log::debug(self::LOG_PREFIX." Failed to download JS bundle: {$jsUrl}", [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $baseUrls = array_unique($baseUrls);

        // Standard common REST API endpoints to supplement discovered paths
        $standardLoginPaths = [
            '/api/admin/auth/login',
            '/api/restaurant/auth/login',
            '/api/auth/login',
            '/auth/login',
            '/api/admin/login',
            '/api/login',
            '/admin/login',
            '/login',
        ];

        $standardProductPaths = [
            '/admin/product',
            '/api/admin/product',
            '/api/restaurant/food',
            '/api/restaurant/foods',
            '/api/products',
            '/api/v1/products',
            '/products',
            '/api/foods',
            '/foods',
            '/api/items',
            '/items',
            '/api/public/products',
        ];

        $loginPaths = array_values(array_unique(array_merge($loginPaths, $standardLoginPaths)));
        $productPaths = array_values(array_unique(array_merge($productPaths, $standardProductPaths)));

        // Filter and sort login paths: must contain login/auth/token, prioritize /api/
        $loginPaths = array_filter($loginPaths, function ($path) {
            $lower = strtolower($path);

            return str_contains($lower, 'login') || str_contains($lower, 'auth') || str_contains($lower, 'token');
        });
        usort($loginPaths, function ($a, $b) {
            $scoreA = (str_starts_with($a, '/api/') ? 10 : 0) + (str_contains($a, 'auth') ? 5 : 0);
            $scoreB = (str_starts_with($b, '/api/') ? 10 : 0) + (str_contains($b, 'auth') ? 5 : 0);

            return $scoreB <=> $scoreA;
        });
        $loginPaths = array_values($loginPaths);

        // Filter and sort product paths: must contain product/food/item/menu/categor
        $productPaths = array_filter($productPaths, function ($path) {
            $lower = strtolower($path);

            return str_contains($lower, 'product') || str_contains($lower, 'food') || str_contains($lower, 'item') || str_contains($lower, 'menu');
        });
        usort($productPaths, function ($a, $b) {
            $scoreA = (str_starts_with($a, '/api/') ? 10 : 0) + (str_starts_with($a, '/admin/') ? 8 : 0);
            $scoreB = (str_starts_with($b, '/api/') ? 10 : 0) + (str_starts_with($b, '/admin/') ? 8 : 0);

            return $scoreB <=> $scoreA;
        });
        $productPaths = array_values($productPaths);

        // Pick the best base URL
        $bestBaseUrl = $this->selectBestBaseUrl($baseUrls, $loginLink);

        // Fallback: If no backend URL found in JS, use the frontend origin itself
        if (! $bestBaseUrl) {
            $parsed = parse_url($loginLink);
            $bestBaseUrl = ($parsed['scheme'] ?? 'https').'://'.($parsed['host'] ?? '');
        }

        // If we couldn't find paths in JS, derive them from the user-provided links
        if (empty($loginPaths)) {
            $loginPaths = $this->derivePathsFromUrl($loginLink, 'login');
        }

        if (empty($productPaths)) {
            $productPaths = $this->derivePathsFromUrl($productsLink, 'product');
        }

        return [
            'base_url' => $bestBaseUrl,
            'login_paths' => $loginPaths,
            'product_paths' => $productPaths,
        ];
    }

    /**
     * Extract backend API base URLs from JavaScript bundle content.
     *
     * @return array<int, string>
     */
    protected function extractBaseUrlsFromJs(string $js): array
    {
        $urls = [];

        // Pattern 1: baseURL / apiUrl / API_URL assignment
        $configPatterns = [
            '/(?:baseURL|apiUrl|API_URL|apiBase|api_url|BACKEND_URL|BASE_URL|serverUrl|SERVER_URL)\s*[:=]\s*["\']+(https?:\/\/[^"\'`\s,;]+)["\'`]/i',
        ];

        foreach ($configPatterns as $pattern) {
            if (preg_match_all($pattern, $js, $matches)) {
                foreach ($matches[1] as $url) {
                    $urls[] = rtrim($url, '/');
                }
            }
        }

        // Pattern 2: URLs with common backend subdomains
        $subdomainPattern = '/https?:\/\/(?:bcknd|backend|restbcknd|api|admin-api|app-api|server)\.[a-z0-9][a-z0-9.-]+/i';
        if (preg_match_all($subdomainPattern, $js, $matches)) {
            foreach ($matches[0] as $url) {
                $urls[] = rtrim($url, '/');
            }
        }

        // Pattern 3: axios.create with baseURL
        if (preg_match_all('/axios\.create\s*\(\s*\{[^}]*baseURL\s*:\s*["\'`](https?:\/\/[^"\'`]+)["\'`]/is', $js, $matches)) {
            foreach ($matches[1] as $url) {
                $urls[] = rtrim($url, '/');
            }
        }

        // Pattern 4: VITE/VUE/REACT env vars
        if (preg_match_all('/(?:VITE_|VUE_APP_|REACT_APP_|NEXT_PUBLIC_)[A-Z_]*(?:API|URL|BASE|BACKEND)[A-Z_]*\s*[:=]\s*["\'`](https?:\/\/[^"\'`]+)["\'`]/i', $js, $matches)) {
            foreach ($matches[1] as $url) {
                $urls[] = rtrim($url, '/');
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * Extract API paths from JS bundle for a given type (login or product).
     *
     * @return array<int, string>
     */
    protected function extractPathsFromJs(string $js, string $type): array
    {
        $paths = [];

        if ($type === 'login') {
            $loginPatterns = [
                '/["\'`](\/(?:api\/)?(?:[a-z_-]+\/)*(?:auth\/)?login)\b["\'`]/i',
                '/["\'`](\/(?:api\/)?(?:[a-z_-]+\/)*signin)\b["\'`]/i',
                '/["\'`](\/(?:api\/)?(?:[a-z_-]+\/)*authenticate)\b["\'`]/i',
            ];

            foreach ($loginPatterns as $pattern) {
                if (preg_match_all($pattern, $js, $matches)) {
                    $paths = array_merge($paths, $matches[1]);
                }
            }
        } elseif ($type === 'product') {
            $productPatterns = [
                '/["\'`](\/(?:api\/)?(?:[a-z_-]+\/)*(?:products?|foods?|items?|menu|categories|categor(?:y|ies)))\s*["\'`]/i',
            ];

            foreach ($productPatterns as $pattern) {
                if (preg_match_all($pattern, $js, $matches)) {
                    $paths = array_merge($paths, $matches[1]);
                }
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * Derive API paths from a user-provided URL when JS parsing fails.
     *
     * @return array<int, string>
     */
    protected function derivePathsFromUrl(string $url, string $type): array
    {
        $parsed = parse_url($url);
        $path = $parsed['path'] ?? '/';
        $paths = [];

        // Add the original path itself
        $paths[] = $path;

        // Try with /api/ prefix if not already present
        if (! str_starts_with($path, '/api/')) {
            $paths[] = '/api'.($path === '/' ? '' : $path);
        }

        if ($type === 'login') {
            // Generate common login variations
            $baseParts = array_filter(explode('/', trim($path, '/')));
            // Remove 'login' from parts to get the prefix
            $prefix = [];
            foreach ($baseParts as $part) {
                if (stripos($part, 'login') !== false) {
                    break;
                }
                $prefix[] = $part;
            }

            $prefixPath = empty($prefix) ? '' : '/'.implode('/', $prefix);
            $paths[] = $prefixPath.'/auth/login';
            $paths[] = '/api'.$prefixPath.'/auth/login';
            $paths[] = '/api'.$prefixPath.'/login';
        } elseif ($type === 'product') {
            // Generate common product path variations
            $paths[] = '/api/restaurant/food';
            $paths[] = '/admin/product';
            $paths[] = '/api/admin/product';
        }

        return array_values(array_unique($paths));
    }

    /**
     * Select the most likely backend base URL from discovered candidates.
     *
     * @param  array<int, string>  $urls
     */
    protected function selectBestBaseUrl(array $urls, string $loginLink): ?string
    {
        if (empty($urls)) {
            return null;
        }

        if (count($urls) === 1) {
            return $urls[0];
        }

        $loginHost = parse_url($loginLink, PHP_URL_HOST) ?? '';
        $loginDomain = $this->extractDomain($loginHost);

        // Score each URL: prefer one that shares domain with login link
        $scored = [];
        foreach ($urls as $url) {
            $urlHost = parse_url($url, PHP_URL_HOST) ?? '';
            $urlDomain = $this->extractDomain($urlHost);
            $score = 0;

            if ($urlDomain === $loginDomain) {
                $score += 10;
            }

            // Prefer backend-style subdomains
            if (preg_match('/^(bcknd|backend|restbcknd|api|server)/i', $urlHost)) {
                $score += 5;
            }

            // Avoid frontend domains
            if (str_starts_with($urlHost, 'www.') || $urlHost === $loginHost) {
                $score -= 3;
            }

            $scored[] = ['url' => $url, 'score' => $score];
        }

        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

        return $scored[0]['url'];
    }

    /**
     * Extract the registrable domain from a hostname (e.g., "bcknd.food2go.online" → "food2go.online").
     */
    protected function extractDomain(string $host): string
    {
        $parts = explode('.', $host);
        $count = count($parts);

        if ($count <= 2) {
            return $host;
        }

        // Return the last two or three parts depending on TLD
        return implode('.', array_slice($parts, -2));
    }

    /**
     * Attempt to login using discovered API config. Tries multiple paths and content types.
     *
     * @param  array{base_url: string|null, login_paths: array<int, string>, product_paths: array<int, string>}  $apiConfig
     */
    protected function attemptLogin(array $apiConfig, string $userName, string $password): ?string
    {
        $baseUrl = $apiConfig['base_url'];

        // Determine most likely credential keys based on input format
        $credentialVariants = [];
        if (str_contains($userName, '@')) {
            $credentialVariants[] = ['email' => $userName, 'password' => $password];
            $credentialVariants[] = ['username' => $userName, 'password' => $password];
        } elseif (preg_match('/^[0-9+]+$/', $userName)) {
            $credentialVariants[] = ['phone' => $userName, 'password' => $password];
            $credentialVariants[] = ['email' => $userName, 'password' => $password];
            $credentialVariants[] = ['username' => $userName, 'password' => $password];
        } else {
            $credentialVariants[] = ['email' => $userName, 'password' => $password];
            $credentialVariants[] = ['username' => $userName, 'password' => $password];
            $credentialVariants[] = ['user_name' => $userName, 'password' => $password];
        }

        // Content types to try (JSON first, then form urlencoded)
        $contentTypes = ['json', 'form'];

        // Limit login paths to top 10 to keep discovery fast
        $candidatePaths = array_slice($apiConfig['login_paths'], 0, 10);

        foreach ($candidatePaths as $loginPath) {
            $loginUrl = rtrim($baseUrl, '/').'/'.ltrim($loginPath, '/');

            foreach ($credentialVariants as $credentials) {
                foreach ($contentTypes as $contentType) {
                    $token = $this->tryLogin($loginUrl, $credentials, $contentType);

                    if ($token) {
                        Log::info(self::LOG_PREFIX.' Login succeeded.', [
                            'url' => $loginUrl,
                            'content_type' => $contentType,
                            'credential_field' => array_key_first($credentials),
                        ]);

                        return $token;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Try a single login request and extract token from response.
     *
     * @param  array<string, string>  $credentials
     */
    protected function tryLogin(string $url, array $credentials, string $contentType): ?string
    {
        try {
            $request = Http::timeout(self::HTTP_TIMEOUT)
                ->withoutVerifying()
                ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
                ])
                ->acceptJson();

            if ($contentType === 'json') {
                $response = $request->post($url, $credentials);
            } else {
                $response = $request->asForm()->post($url, $credentials);
            }

            if (! $response->successful()) {
                Log::debug(self::LOG_PREFIX." Login attempt failed: {$url}", [
                    'status' => $response->status(),
                    'content_type' => $contentType,
                ]);

                return null;
            }

            $data = $response->json();

            return $this->extractToken($data);
        } catch (\Throwable $e) {
            Log::debug(self::LOG_PREFIX." Login exception: {$url}", [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Extract auth token from a login response, trying multiple common locations.
     *
     * @param  mixed  $data
     */
    protected function extractToken($data): ?string
    {
        if (! is_array($data)) {
            return null;
        }

        // Try common token locations in priority order
        $paths = [
            ['data', 'token'],
            ['token'],
            ['data', 'access_token'],
            ['access_token'],
            ['data', 'data', 'token'],
            ['data', 'auth', 'token'],
            ['data', 'bearer'],
            ['bearer'],
            ['data', 'data', 'access_token'],
        ];

        foreach ($paths as $path) {
            $value = $data;
            foreach ($path as $key) {
                if (! is_array($value) || ! array_key_exists($key, $value)) {
                    $value = null;
                    break;
                }
                $value = $value[$key];
            }

            if (is_string($value) && strlen($value) > 10) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Fetch products from discovered API with auth token.
     *
     * @param  array{base_url: string|null, login_paths: array<int, string>, product_paths: array<int, string>}  $apiConfig
     * @return array<int, mixed>|null
     */
    protected function fetchProducts(array $apiConfig, string $token): ?array
    {
        $baseUrl = $apiConfig['base_url'];

        foreach ($apiConfig['product_paths'] as $productPath) {
            $productUrl = rtrim($baseUrl, '/').'/'.ltrim($productPath, '/');

            try {
                $response = Http::timeout(self::HTTP_TIMEOUT)
                    ->withoutVerifying()
                    ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])
                    ->withHeaders([
                        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
                    ])
                    ->acceptJson()
                    ->withToken($token)
                    ->get($productUrl);

                if (! $response->successful()) {
                    Log::debug(self::LOG_PREFIX." Product fetch failed: {$productUrl}", [
                        'status' => $response->status(),
                    ]);

                    continue;
                }

                $data = $response->json();
                $products = $this->extractProductsList($data);

                if ($products !== null && count($products) > 0) {
                    Log::info(self::LOG_PREFIX." Products found at: {$productUrl}", [
                        'count' => count($products),
                    ]);

                    return $products;
                }
            } catch (\Throwable $e) {
                Log::debug(self::LOG_PREFIX." Product fetch exception: {$productUrl}", [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return null;
    }

    /**
     * Extract the products list from an API response, trying multiple nesting patterns.
     *
     * @param  mixed  $data
     * @return array<int, mixed>|null
     */
    protected function extractProductsList($data): ?array
    {
        if (! is_array($data)) {
            return null;
        }

        // If it's already a list of items
        if (array_is_list($data) && ! empty($data)) {
            return $data;
        }

        // Try common nesting patterns
        $nestingPaths = [
            ['data', 'data', 'data'],
            ['data', 'data'],
            ['data'],
            ['products'],
            ['foods'],
            ['items'],
            ['menu'],
            ['data', 'products'],
            ['data', 'foods'],
            ['data', 'items'],
            ['data', 'menu'],
        ];

        foreach ($nestingPaths as $path) {
            $value = $data;
            foreach ($path as $key) {
                if (! is_array($value) || ! array_key_exists($key, $value)) {
                    $value = null;
                    break;
                }
                $value = $value[$key];
            }

            if (is_array($value) && ! empty($value) && array_is_list($value)) {
                return $value;
            }
        }

        return null;
    }
}
