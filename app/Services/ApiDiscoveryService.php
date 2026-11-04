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
     * Discover the API from a SPA frontend and fetch products/items/courses.
     *
     * Algorithm (Universal & Entity-Agnostic):
     *  1. Download HTML from login page → extract JS bundle URLs (scripts & preloads)
     *  2. Extract entity keywords from products_link (e.g. courses, categories, items, foods)
     *  3. Parse JS bundles → extract backend base URL & matching API endpoints
     *  4. Login via discovered API → extract auth token
     *  5. Fetch data with Bearer token → recursive JSON unnesting & normalization
     *  6. If API discovery yields no data, attempt Browserless Headless Browser fallback
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
                Log::warning(self::LOG_PREFIX.' No JS bundles found in HTML, trying Browserless.', $context);

                return $this->scrapeViaBrowserless($loginLink, $userName, $password, $productsLink);
            }

            Log::info(self::LOG_PREFIX.' Found JS bundles.', ['count' => count($jsUrls)]);

            // Step 2: Parse JS bundles and products_link for API config
            Log::info(self::LOG_PREFIX.' Step 2: Parsing JS bundles for API config.');
            $apiConfig = $this->discoverApiConfig($jsUrls, $loginLink, $productsLink);

            if (! $apiConfig['base_url']) {
                Log::warning(self::LOG_PREFIX.' Could not discover backend base URL from JS bundles.', $context);

                return $this->scrapeViaBrowserless($loginLink, $userName, $password, $productsLink);
            }

            Log::info(self::LOG_PREFIX.' Discovered API config.', [
                'base_url' => $apiConfig['base_url'],
                'login_paths' => array_slice($apiConfig['login_paths'], 0, 8),
                'product_paths' => array_slice($apiConfig['product_paths'], 0, 10),
            ]);

            // Step 3: Try to login and get auth token
            Log::info(self::LOG_PREFIX.' Step 3: Attempting login.');
            $token = $this->attemptLogin($apiConfig, $userName, $password);

            if (! $token) {
                Log::warning(self::LOG_PREFIX.' All login attempts failed.', $context);

                return $this->scrapeViaBrowserless($loginLink, $userName, $password, $productsLink);
            }

            Log::info(self::LOG_PREFIX.' Login successful, token obtained.');

            // Step 4: Fetch products/items with token
            Log::info(self::LOG_PREFIX.' Step 4: Fetching products/items.');
            $keywords = $this->extractEntityKeywords($productsLink);
            $products = $this->fetchProducts($apiConfig, $token, $keywords);

            if ($products === null || empty($products)) {
                Log::warning(self::LOG_PREFIX.' Direct API fetch failed, trying Browserless fallback.', $context);

                return $this->scrapeViaBrowserless($loginLink, $userName, $password, $productsLink);
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

            return $this->scrapeViaBrowserless($loginLink, $userName, $password, $productsLink);
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
        $productPathsFromJs = [];
        $harvestedApis = [];

        // Extract entity keywords from user's products_link (e.g. courses, categories, foods, etc.)
        $keywords = $this->extractEntityKeywords($productsLink);

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

                // Extract entity paths from JS matching user's keywords or common items
                $foundProductPaths = $this->extractEntityPathsFromJs($js, $keywords);
                $productPathsFromJs = array_merge($productPathsFromJs, $foundProductPaths);

                // Harvest ALL read API endpoints from the JS bundle (starts with /api/ or https://)
                $foundHarvested = $this->harvestAllReadApisFromJs($js);
                $harvestedApis = array_merge($harvestedApis, $foundHarvested);
            } catch (\Throwable $e) {
                Log::debug(self::LOG_PREFIX." Failed to download JS bundle: {$jsUrl}", [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $baseUrls = array_unique($baseUrls);
        $harvestedApis = array_values(array_unique($harvestedApis));
        $loginPathsFromJs = array_values(array_unique($loginPaths));

        // Standard login paths
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

        // Endpoints extracted from JS come FIRST, then standard fallbacks
        $loginPaths = array_values(array_unique(array_merge($loginPathsFromJs, $standardLoginPaths)));
        $loginPaths = array_filter($loginPaths, function ($path) {
            $lower = strtolower($path);

            return str_contains($lower, 'login') || str_contains($lower, 'auth') || str_contains($lower, 'token');
        });

        usort($loginPaths, function ($a, $b) use ($loginPathsFromJs) {
            $scoreA = (in_array($a, $loginPathsFromJs) ? 30 : 0)
                + (str_contains($a, '/api/') ? 12 : 0)
                + (str_contains($a, 'login') ? 8 : 0);
            $scoreB = (in_array($b, $loginPathsFromJs) ? 30 : 0)
                + (str_contains($b, '/api/') ? 12 : 0)
                + (str_contains($b, 'login') ? 8 : 0);

            return $scoreB <=> $scoreA;
        });
        $loginPaths = array_values($loginPaths);

        // ── GENERATE PRODUCT / ENTITY PATHS (Hierarchical Priority) ──
        // Priority 1: Paths derived directly from the user's products_link (e.g. /admin/courses/categories)
        $derivedProductPaths = $this->deriveEntityPathsFromUrl($productsLink, $keywords);

        // Priority 2: Standard common paths as fallbacks
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

        // Combine all candidates: Derived FIRST, then JS-discovered, then Standard fallbacks, then ALL harvested APIs
        $allProductPaths = array_values(array_unique(array_merge(
            $derivedProductPaths,
            $productPathsFromJs,
            $standardProductPaths,
            $harvestedApis
        )));

        // Prioritize paths with /api/, shorter depth, and matching primary entity
        $primaryKeyword = ! empty($keywords) ? end($keywords) : '';

        usort($allProductPaths, function ($a, $b) use ($keywords, $primaryKeyword) {
            $depthA = count(explode('/', trim($a, '/')));
            $depthB = count(explode('/', trim($b, '/')));

            $scoreA = (str_starts_with($a, '/api/') ? 12 : 0) + (str_contains($a, '/admin') ? 6 : 0) - ($depthA * 3);
            $scoreB = (str_starts_with($b, '/api/') ? 12 : 0) + (str_contains($b, '/admin') ? 6 : 0) - ($depthB * 3);

            if (! empty($primaryKeyword)) {
                if (str_ends_with($a, '/'.$primaryKeyword) || str_contains($a, '/'.$primaryKeyword.'/')) {
                    $scoreA += 25;
                }
                if (str_ends_with($b, '/'.$primaryKeyword) || str_contains($b, '/'.$primaryKeyword.'/')) {
                    $scoreB += 25;
                }
            }

            foreach ($keywords as $kw) {
                if (stripos($a, $kw) !== false) {
                    $scoreA += 10;
                }
                if (stripos($b, $kw) !== false) {
                    $scoreB += 10;
                }
            }

            return $scoreB <=> $scoreA;
        });
        $finalProductPaths = array_values($allProductPaths);

        // Pick the best base URL
        $bestBaseUrl = $this->selectBestBaseUrl($baseUrls, $loginLink);

        // Fallback: If no backend URL found in JS, use the frontend origin itself
        if (! $bestBaseUrl) {
            $parsed = parse_url($loginLink);
            $bestBaseUrl = ($parsed['scheme'] ?? 'https').'://'.($parsed['host'] ?? '');
        }

        return [
            'base_url' => $bestBaseUrl,
            'login_paths' => $loginPaths,
            'product_paths' => $finalProductPaths,
        ];
    }

    /**
     * Extract entity keywords from the products URL (e.g. "courses", "categories", "foods").
     *
     * @return array<int, string>
     */
    protected function extractEntityKeywords(string $url): array
    {
        $parsed = parse_url($url);
        $path = $parsed['path'] ?? '/';
        $noise = [
            '', 'admin', 'dashboard', 'setup', 'panel', 'view', 'list', 'index',
            'manage', 'app', 'portal', 'home', 'main', 'api', 'v1', 'v2', 'v3',
        ];

        $segments = array_filter(explode('/', $path), function ($seg) use ($noise) {
            $lower = strtolower(trim($seg));

            return ! in_array($lower, $noise) && ! is_numeric($seg) && strlen($seg) > 2;
        });

        return array_values(array_unique(array_map('strtolower', $segments)));
    }

    /**
     * Derive candidate API paths directly from the user-provided products link.
     *
     * @param  array<int, string>  $keywords
     * @return array<int, string>
     */
    protected function deriveEntityPathsFromUrl(string $url, array $keywords): array
    {
        $parsed = parse_url($url);
        $path = '/'.ltrim($parsed['path'] ?? '/', '/');
        $paths = [];

        // 1. The exact path as provided by user
        $paths[] = $path;
        $paths[] = '/api'.$path;

        // 2. Prefix variations
        if (! str_starts_with($path, '/admin/') && ! str_starts_with($path, '/api/admin/')) {
            $paths[] = '/api/admin'.$path;
            $paths[] = '/admin'.$path;
        }

        // 3. For each individual keyword: generate clean direct paths (e.g. /admin/product, /api/admin/category)
        foreach ($keywords as $kw) {
            $paths[] = '/api/admin/'.$kw;
            $paths[] = '/admin/'.$kw;
            $paths[] = '/api/'.$kw;
            $paths[] = '/'.$kw;

            // Singular form (e.g. categories -> category, courses -> course, foods -> food)
            $singular = null;
            if (str_ends_with($kw, 'ies')) {
                $singular = substr($kw, 0, -3).'y';
            } elseif (str_ends_with($kw, 's')) {
                $singular = substr($kw, 0, -1);
            }

            if ($singular) {
                $paths[] = '/api/admin/'.$singular;
                $paths[] = '/admin/'.$singular;
                $paths[] = '/api/'.$singular;
                $paths[] = '/'.$singular;
            }
        }

        // 4. Combined progressive segments
        $accum = '';
        foreach ($keywords as $seg) {
            $accum .= '/'.$seg;
            $paths[] = '/api'.$accum;
            $paths[] = '/api/admin'.$accum;
            $paths[] = '/admin'.$accum;
            $paths[] = $accum;
        }

        return array_values(array_unique($paths));
    }

    /**
     * Extract API paths matching entity keywords from JS bundle.
     *
     * @param  array<int, string>  $keywords
     * @return array<int, string>
     */
    protected function extractEntityPathsFromJs(string $js, array $keywords): array
    {
        $paths = [];
        $allKeywords = array_values(array_unique(array_merge(
            $keywords,
            ['product', 'food', 'item', 'menu', 'category', 'catalog', 'course', 'unit', 'village', 'owner']
        )));

        // 1. Full https:// URLs matching keywords
        if (preg_match_all('/https?:\/\/[a-zA-Z0-9.-]+(?::[0-9]+)?(?:\/[a-zA-Z0-9._\/-]*)?/i', $js, $fullMatches)) {
            foreach ($fullMatches[0] as $u) {
                $lower = strtolower($u);
                if (! str_contains($lower, 'pusher')
                    && ! str_contains($lower, 'github')
                    && ! str_contains($lower, 'w3.org')
                    && ! str_contains($lower, 'google')
                    && ! str_contains($lower, 'delete')
                    && ! str_contains($lower, 'add')
                    && ! str_contains($lower, '.png')
                    && ! str_contains($lower, '.jpg')
                    && ! str_contains($lower, '.js')
                    && ! str_contains($lower, '.css')) {
                    foreach ($allKeywords as $kw) {
                        if (str_contains($lower, $kw)) {
                            $paths[] = $u;
                            break;
                        }
                    }
                }
            }
        }

        // 2. Relative paths
        $keywordPattern = implode('|', array_map('preg_quote', $allKeywords));
        $pattern = '/["\'`](\/(?:api\/)?(?:[a-z0-9_-]+\/)*(?:'.$keywordPattern.')[a-z0-9_\/-]*)["\'`]/i';

        if (preg_match_all($pattern, $js, $matches)) {
            foreach ($matches[1] as $p) {
                if (! str_contains($p, '.') && ! str_contains($p, 'delete') && ! str_contains($p, 'import') && strlen($p) < 60) {
                    $paths[] = $p;
                }
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * Harvest all readable API endpoints from JS bundles (starts with /api/ or https://).
     *
     * @return array<int, string>
     */
    protected function harvestAllReadApisFromJs(string $js): array
    {
        $apis = [];

        // 1. Matches relative /api/... endpoints
        if (preg_match_all('/["\'](\/api\/[a-zA-Z0-9_\/-]+)["\']/i', $js, $matches)) {
            $apis = array_merge($apis, $matches[1]);
        }

        // 2. Matches full https://.../api/... endpoints
        if (preg_match_all('/https?:\/\/[a-z0-9.-]+(?::[0-9]+)?(\/[a-zA-Z0-9._\/-]*)/i', $js, $matches)) {
            foreach ($matches[0] as $u) {
                if (! str_contains($u, 'pusher') && ! str_contains($u, 'github') && ! str_contains($u, 'w3.org') && ! str_contains($u, 'google')) {
                    $apis[] = $u;
                }
            }
        }

        // Mutation / noise keywords to filter out
        $mutationKeywords = [
            'login', 'logout', 'auth', 'delete', 'destroy', 'remove', 'store',
            'create', 'update', 'edit', 'patch', 'upload', 'import', 'export',
            'download', 'reset', 'register', 'verify', 'password', 'token',
            'search', 'swap-order', 'attendance', 'manual', 'ocr', 'live',
        ];

        $filtered = array_filter(array_unique($apis), function ($api) use ($mutationKeywords) {
            if (str_contains($api, '.') || strlen($api) > 65) {
                return false;
            }

            $lower = strtolower($api);
            foreach ($mutationKeywords as $bad) {
                if (str_contains($lower, $bad)) {
                    return false;
                }
            }

            return true;
        });

        return array_values($filtered);
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
            // 1. Full https:// URLs containing login/auth/signin
            if (preg_match_all('/https?:\/\/[a-zA-Z0-9.-]+(?::[0-9]+)?(?:\/[a-zA-Z0-9._\/-]*)?/i', $js, $fullMatches)) {
                foreach ($fullMatches[0] as $u) {
                    $lower = strtolower($u);
                    if ((str_contains($lower, 'login') || str_contains($lower, 'signin') || str_contains($lower, 'auth'))
                        && ! str_contains($lower, 'logout')
                        && ! str_contains($lower, 'pusher')
                        && ! str_contains($lower, 'github')
                        && ! str_contains($lower, 'w3.org')
                        && ! str_contains($lower, 'google')
                        && ! str_contains($lower, 'facebook')
                        && ! str_contains($lower, 'apple')
                        && ! str_contains($lower, '.js')
                        && ! str_contains($lower, '.png')) {
                        $paths[] = $u;
                    }
                }
            }

            // 2. Relative paths
            $loginPatterns = [
                '/["\'`](\/(?:api\/)?[a-zA-Z0-9_-]*(?:auth\/)?login)\b["\'`]/i',
                '/["\'`](\/(?:api\/)?[a-zA-Z0-9_-]*signin)\b["\'`]/i',
                '/["\'`](\/(?:api\/)?[a-zA-Z0-9_-]*authenticate)\b["\'`]/i',
            ];

            foreach ($loginPatterns as $pattern) {
                if (preg_match_all($pattern, $js, $matches)) {
                    $paths = array_merge($paths, $matches[1]);
                }
            }
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

        // Limit login paths to top 20 to keep discovery fast
        $candidatePaths = array_slice($apiConfig['login_paths'], 0, 20);

        foreach ($candidatePaths as $loginPath) {
            $loginUrl = (str_starts_with($loginPath, 'http://') || str_starts_with($loginPath, 'https://'))
                ? $loginPath
                : rtrim($baseUrl, '/').'/'.ltrim($loginPath, '/');

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
                    'response' => substr((string) $response->body(), 0, 150),
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
     * @param  array<int, string>  $keywords
     * @return array<int, mixed>|null
     */
    protected function fetchProducts(array $apiConfig, string $token, array $keywords = []): ?array
    {
        $baseUrl = $apiConfig['base_url'];
        $primaryKeyword = ! empty($keywords) ? end($keywords) : '';
        $fallbackCandidate = null;

        $candidatePaths = array_slice($apiConfig['product_paths'], 0, 35);

        foreach ($candidatePaths as $productPath) {
            $productUrl = (str_starts_with($productPath, 'http://') || str_starts_with($productPath, 'https://'))
                ? $productPath
                : rtrim($baseUrl, '/').'/'.ltrim($productPath, '/');

            try {
                $response = Http::timeout(8)
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
                $products = $this->extractProductsList($data, $keywords);

                if ($products !== null && count($products) > 0) {
                    Log::info(self::LOG_PREFIX." Products found at: {$productUrl}", [
                        'count' => count($products),
                        'matched_path' => $productPath,
                    ]);

                    // If this endpoint matches the primary requested keyword, return immediately
                    if (! empty($primaryKeyword) && (stripos($productPath, $primaryKeyword) !== false)) {
                        return $products;
                    }

                    // Otherwise keep as high quality fallback
                    if ($fallbackCandidate === null || count($products) > count($fallbackCandidate)) {
                        $fallbackCandidate = $products;
                    }
                }
            } catch (\Throwable $e) {
                Log::debug(self::LOG_PREFIX." Product fetch exception: {$productUrl}", [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $fallbackCandidate;
    }

    /**
     * Extract the products list from an API response, using dynamic recursive unnesting.
     *
     * @param  array<int, string>  $keywords
     * @return array<int, mixed>|null
     */
    protected function extractProductsList(mixed $data, array $keywords = []): ?array
    {
        if (! is_array($data) || empty($data)) {
            return null;
        }

        // 1. Direct flat list of associative items
        if (array_is_list($data) && count($data) > 0 && is_array($data[0])) {
            return $this->normalizeProductItems($data);
        }

        // 2. Check prioritized keys (matching keywords first, then standard keys)
        $priorityKeys = array_values(array_unique(array_merge($keywords, [
            'data', 'courses', 'categories', 'products', 'foods', 'items', 'menu',
            'results', 'payload', 'list', 'records', 'rows',
        ])));

        foreach ($priorityKeys as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                $extracted = $this->extractProductsList($data[$key], $keywords);
                if ($extracted !== null && count($extracted) > 0) {
                    return $extracted;
                }
            }
        }

        // 3. Fallback: inspect every nested array value in the associative container
        foreach ($data as $val) {
            if (is_array($val) && ! empty($val)) {
                $extracted = $this->extractProductsList($val, $keywords);
                if ($extracted !== null && count($extracted) > 0) {
                    return $extracted;
                }
            }
        }

        return null;
    }

    /**
     * Normalize raw items into a unified structure compatible with FormatsChatLinks.
     *
     * @param  array<int, mixed>  $items
     * @return array<int, array<string, mixed>>
     */
    protected function normalizeProductItems(array $items): array
    {
        $normalized = [];

        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            $id = $item['id'] ?? $item['_id'] ?? $item['course_id'] ?? $item['category_id'] ?? ($index + 1);
            $name = $item['name']
                ?? $item['ar_name']
                ?? $item['name_ar']
                ?? $item['title']
                ?? $item['ar_title']
                ?? $item['course_name']
                ?? $item['category_name']
                ?? $item['label']
                ?? null;

            if (empty($name)) {
                continue;
            }

            $price = null;
            if (isset($item['price']) && $item['price'] !== null && $item['price'] !== '') {
                $rawPrice = $item['price'];
                $price = is_numeric($rawPrice)
                    ? (floor((float) $rawPrice) == (float) $rawPrice ? (int) $rawPrice : (float) $rawPrice)
                    : (string) $rawPrice;
            } elseif (isset($item['cost']) && $item['cost'] !== null && $item['cost'] !== '') {
                $rawPrice = $item['cost'];
                $price = is_numeric($rawPrice)
                    ? (floor((float) $rawPrice) == (float) $rawPrice ? (int) $rawPrice : (float) $rawPrice)
                    : (string) $rawPrice;
            } elseif (isset($item['fee']) && $item['fee'] !== null && $item['fee'] !== '') {
                $rawPrice = $item['fee'];
                $price = is_numeric($rawPrice)
                    ? (floor((float) $rawPrice) == (float) $rawPrice ? (int) $rawPrice : (float) $rawPrice)
                    : (string) $rawPrice;
            }
            $description = $item['description'] ?? $item['ar_description'] ?? $item['desc'] ?? $item['details'] ?? null;
            $image = $item['image'] ?? $item['image_url'] ?? $item['thumbnail'] ?? $item['cover'] ?? null;

            $normalized[] = [
                'id' => $id,
                'name' => (string) $name,
                'price' => $price,
                'description' => $description ? (string) $description : null,
                'image' => $image ? (string) $image : null,
                'options' => $item['options'] ?? [],
            ];
        }

        return $normalized;
    }

    /**
     * Tier 2 Fallback: Scrape via Browserless Chromium Headless Browser if running.
     *
     * @return array{products: array<int, mixed>, meta: array<string, mixed>}|null
     */
    protected function scrapeViaBrowserless(
        string $loginLink,
        string $userName,
        string $password,
        string $productsLink,
    ): ?array {
        $browserlessUrl = config('services.browserless.url', env('BROWSERLESS_URL', 'http://127.0.0.1:3000'));
        $endpoint = rtrim($browserlessUrl, '/').'/function';

        $jsFunction = <<<'JS'
module.exports = async ({ page }) => {
    const loginUrl = '%LOGIN_URL%';
    const user = '%USER%';
    const pass = '%PASS%';
    const targetUrl = '%TARGET_URL%';

    let interceptedData = [];

    // Intercept JSON responses
    page.on('response', async (res) => {
        try {
            const ct = res.headers()['content-type'] || '';
            if (ct.includes('application/json')) {
                const json = await res.json();
                if (json && (Array.isArray(json) || json.data || json.courses || json.products || json.items)) {
                    interceptedData.push(json);
                }
            }
        } catch (e) {}
    });

    // 1. Navigate to login
    await page.goto(loginUrl, { waitUntil: 'networkidle2', timeout: 30000 });

    // 2. Fill login inputs
    const emailSelector = 'input[type="email"], input[name="email"], input[name="username"], input[type="text"]';
    const passSelector = 'input[type="password"], input[name="password"]';

    if (await page.$(emailSelector) && await page.$(passSelector)) {
        await page.type(emailSelector, user);
        await page.type(passSelector, pass);
        await Promise.all([
            page.keyboard.press('Enter'),
            page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 15000 }).catch(() => {})
        ]);
    }

    // 3. Navigate to target products/items page
    await page.goto(targetUrl, { waitUntil: 'networkidle2', timeout: 30000 });
    await new Promise(r => setTimeout(r, 2000));

    return { data: interceptedData };
};
JS;

        $code = str_replace(
            ['%LOGIN_URL%', '%USER%', '%PASS%', '%TARGET_URL%'],
            [addslashes($loginLink), addslashes($userName), addslashes($password), addslashes($productsLink)],
            $jsFunction
        );

        try {
            Log::info(self::LOG_PREFIX.' Attempting Browserless scrape fallback.', ['endpoint' => $endpoint]);
            $response = Http::timeout(60)
                ->withHeaders(['Content-Type' => 'application/javascript'])
                ->withBody($code, 'application/javascript')
                ->post($endpoint);

            if (! $response->successful()) {
                return null;
            }

            $result = $response->json();
            $candidates = $result['data'] ?? [];

            foreach ($candidates as $candidate) {
                $items = $this->extractProductsList($candidate);
                if (! empty($items)) {
                    return [
                        'products' => $items,
                        'meta' => [
                            'source' => 'browserless_headless',
                            'products_count' => count($items),
                            'scraped_at' => now()->toIso8601String(),
                        ],
                    ];
                }
            }
        } catch (\Throwable $e) {
            Log::debug(self::LOG_PREFIX.' Browserless unreachable or failed: '.$e->getMessage());
        }

        return null;
    }
}
