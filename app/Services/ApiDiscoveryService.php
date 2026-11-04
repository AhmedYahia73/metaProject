<?php

namespace App\Services;

use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
                Log::info(self::LOG_PREFIX.' No JS bundles found in HTML, trying Web Session Scraper fallback.', $context);

                $webResult = $this->scrapeViaWebSession($loginLink, $userName, $password, $productsLink);
                if ($webResult !== null && ! empty($webResult['products'])) {
                    return $webResult;
                }

                return $this->scrapeViaBrowserless($loginLink, $userName, $password, $productsLink);
            }

            Log::info(self::LOG_PREFIX.' Found JS bundles.', ['count' => count($jsUrls)]);

            // Step 2: Parse JS bundles and products_link for API config
            Log::info(self::LOG_PREFIX.' Step 2: Parsing JS bundles for API config.');
            $apiConfig = $this->discoverApiConfig($jsUrls, $loginLink, $productsLink);

            if (! $apiConfig['base_url']) {
                Log::info(self::LOG_PREFIX.' Could not discover backend base URL from JS bundles. Trying Web Session Scraper...', $context);

                $webResult = $this->scrapeViaWebSession($loginLink, $userName, $password, $productsLink);
                if ($webResult !== null && ! empty($webResult['products'])) {
                    return $webResult;
                }

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
                Log::info(self::LOG_PREFIX.' All API login attempts failed. Trying Web Session Scraper...', $context);

                $webResult = $this->scrapeViaWebSession($loginLink, $userName, $password, $productsLink);
                if ($webResult !== null && ! empty($webResult['products'])) {
                    return $webResult;
                }

                return $this->scrapeViaBrowserless($loginLink, $userName, $password, $productsLink);
            }

            Log::info(self::LOG_PREFIX.' Login successful, token obtained.');

            try {
                // Step 4: Fetch products/items with token
                Log::info(self::LOG_PREFIX.' Step 4: Fetching products/items.');
                $keywords = $this->extractEntityKeywords($productsLink);
                $products = $this->fetchProducts($apiConfig, $token, $keywords);

                if ($products === null || empty($products)) {
                    Log::info(self::LOG_PREFIX.' Direct keyword fetch returned no items. Harvesting data from all APIs discovered across the page...', [
                        'keywords' => $keywords,
                        'total_read_paths' => count($apiConfig['all_read_paths'] ?? []),
                    ]);

                    $harvestedResult = $this->harvestAllEndpointsData($apiConfig, $token, $keywords);

                    if ($harvestedResult !== null && ! empty($harvestedResult['products'])) {
                        return $harvestedResult;
                    }

                    Log::info(self::LOG_PREFIX.' Multi-endpoint harvest returned no items (non-API site). Trying Web Session Scraper...', $context);

                    $webResult = $this->scrapeViaWebSession($loginLink, $userName, $password, $productsLink);
                    if ($webResult !== null && ! empty($webResult['products'])) {
                        return $webResult;
                    }

                    Log::warning(self::LOG_PREFIX.' Both API discovery and Web Session Scraper returned no data, trying Browserless fallback.', $context);

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
            } finally {
                // Step 5: Always gracefully log out session to avoid multi-device / single-session lockout
                $this->attemptLogout($apiConfig, $token);
            }
        } catch (\Throwable $e) {
            Log::error(self::LOG_PREFIX.' Uncaught exception during discovery.', [
                'error' => $e->getMessage(),
                'trace' => substr($e->getTraceAsString(), 0, 500),
                ...$context,
            ]);

            $webResult = $this->scrapeViaWebSession($loginLink, $userName, $password, $productsLink);
            if ($webResult !== null && ! empty($webResult['products'])) {
                return $webResult;
            }

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
     * Parse JS bundles to discover API base URL, login paths, product paths, and logout paths.
     *
     * @param  array<int, string>  $jsUrls
     * @return array{base_url: string|null, login_paths: array<int, string>, product_paths: array<int, string>, logout_paths: array<int, string>}
     */
    protected function discoverApiConfig(array $jsUrls, string $loginLink, string $productsLink): array
    {
        $baseUrls = [];
        $loginPaths = [];
        $logoutPaths = [];
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

                // Extract logout paths from JS
                $foundLogoutPaths = $this->extractPathsFromJs($js, 'logout');
                $logoutPaths = array_merge($logoutPaths, $foundLogoutPaths);

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

        // Standard logout paths
        $standardLogoutPaths = [
            '/api/logout',
            '/logout',
            '/api/admin/logout',
            '/api/auth/logout',
            '/admin/logout',
        ];
        $logoutPaths = array_values(array_unique(array_merge($logoutPaths, $standardLogoutPaths)));

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

        // Extract clean single-segment sub-base prefixes (e.g. /village, /admin, /api)
        $subBasePrefixes = [];
        foreach ($baseUrls as $bu) {
            $pathPart = trim((string) parse_url($bu, PHP_URL_PATH), '/');
            if (! empty($pathPart)) {
                $segments = explode('/', $pathPart);
                $firstSeg = $segments[0] ?? '';
                if (! empty($firstSeg) && ! in_array($firstSeg, ['login', 'auth', 'logout', 'signin'])) {
                    $subBasePrefixes[] = '/'.$firstSeg;
                }
            }
        }
        $subBasePrefixes = array_values(array_unique($subBasePrefixes));

        // Generate prefixed variations for all paths (e.g. /village/owner/owners, /village/owners)
        $prefixedPaths = [];
        $prefixedJsPaths = [];
        foreach ($subBasePrefixes as $prefix) {
            foreach ($allProductPaths as $p) {
                if (! str_starts_with($p, 'http://') && ! str_starts_with($p, 'https://')) {
                    if (! str_starts_with($p, $prefix)) {
                        $prefixed = $prefix.'/'.ltrim($p, '/');
                        $prefixedPaths[] = $prefixed;
                        if (in_array($p, $productPathsFromJs)) {
                            $prefixedJsPaths[] = $prefixed;
                        }
                    }
                }
            }
        }

        $allProductPaths = array_values(array_unique(array_merge($allProductPaths, $prefixedPaths)));

        // Real paths found in the application's compiled JavaScript (or their sub-base prefixed variants)
        $realJsPaths = array_values(array_unique(array_merge($productPathsFromJs, $prefixedJsPaths)));

        // Prioritize paths found directly in JS (+50), matching entity keywords (+25), shorter depth
        usort($allProductPaths, function ($a, $b) use ($keywords, $realJsPaths) {
            $depthA = count(explode('/', trim($a, '/')));
            $depthB = count(explode('/', trim($b, '/')));

            $scoreA = (in_array($a, $realJsPaths) ? 50 : 0)
                + (str_starts_with($a, '/api/') ? 12 : 0)
                + (str_contains($a, '/admin') ? 6 : 0)
                - ($depthA * 2);

            $scoreB = (in_array($b, $realJsPaths) ? 50 : 0)
                + (str_starts_with($b, '/api/') ? 12 : 0)
                + (str_contains($b, '/admin') ? 6 : 0)
                - ($depthB * 2);

            foreach ($keywords as $kw) {
                if (str_ends_with($a, '/'.$kw) || str_contains($a, '/'.$kw.'/') || str_contains($a, '/'.$kw.'_')) {
                    $scoreA += 25;
                }
                if (str_ends_with($b, '/'.$kw) || str_contains($b, '/'.$kw.'/') || str_contains($b, '/'.$kw.'_')) {
                    $scoreB += 25;
                }
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

        // Build exhaustive list of all read paths harvested across the JS
        $allReadPaths = [];
        $rawHarvested = array_values(array_unique(array_merge($harvestedApis, $productPathsFromJs)));
        foreach ($rawHarvested as $hp) {
            $allReadPaths[] = $hp;
            if (! str_starts_with($hp, 'http://') && ! str_starts_with($hp, 'https://')) {
                foreach ($subBasePrefixes as $prefix) {
                    if (! str_starts_with($hp, $prefix)) {
                        $allReadPaths[] = $prefix.'/'.ltrim($hp, '/');
                    }
                }
            }
        }
        $allReadPaths = array_values(array_unique($allReadPaths));

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
            'logout_paths' => $logoutPaths,
            'all_read_paths' => $allReadPaths,
        ];
    }

    /**
     * Extract entity keywords from the products URL (e.g. "courses", "categories", "foods", "posts").
     * Exact segments from URL without arbitrary guessing or mutation.
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

        $rawSegments = array_filter(explode('/', $path), function ($seg) use ($noise) {
            $lower = strtolower(trim($seg));

            return ! in_array($lower, $noise) && ! is_numeric($seg) && strlen($seg) > 2;
        });

        $keywords = [];
        foreach ($rawSegments as $seg) {
            $clean = strtolower(trim($seg));
            $keywords[] = $clean;

            // Split compound segments by '_' or '-' (e.g. "security_man" -> ["security", "man"])
            $parts = preg_split('/[_-]+/', $clean);
            if (count($parts) > 1) {
                foreach ($parts as $p) {
                    if (strlen($p) >= 3 && ! in_array($p, $noise)) {
                        $keywords[] = $p;
                    }
                }
            }
        }

        return array_values(array_unique($keywords));
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

        // 3. For each individual keyword: generate clean direct paths (e.g. /admin/product, /api/admin/category, /api/posts)
        foreach ($keywords as $kw) {
            $paths[] = '/api/admin/'.$kw;
            $paths[] = '/admin/'.$kw;
            $paths[] = '/api/'.$kw;
            $paths[] = '/'.$kw;
        }

        // 4. Combined progressive segments from actual URL path
        $rawSegments = array_filter(explode('/', $parsed['path'] ?? '/'), fn ($s) => strlen(trim($s)) > 2 && ! is_numeric($s));
        $accum = '';
        foreach ($rawSegments as $seg) {
            $accum .= '/'.strtolower(trim($seg));
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

        // 3. Template literal paths like `${e}/owner/owners?page=...` or `${e}/post/new_view?`
        if (preg_match_all('/`\$\{[a-zA-Z0-9_]+\}(\/[a-zA-Z0-9_\/-]+)(?:[\?`\$]|\/\{)/i', $js, $tplMatches)) {
            foreach ($tplMatches[1] as $p) {
                if (! str_contains($p, '.') && ! str_contains($p, 'delete') && ! str_contains($p, 'add') && strlen($p) < 60) {
                    $paths[] = $p;
                }
            }
        }

        // Filter out mutations and noisy paths
        $mutationKeywords = ['update', 'delete', 'destroy', 'edit', 'add', 'status', 'upload', 'import', 'export'];
        $filtered = array_filter(array_unique($paths), function ($p) use ($mutationKeywords) {
            $lower = strtolower($p);
            foreach ($mutationKeywords as $bad) {
                if (str_contains($lower, '/'.$bad) || str_ends_with($lower, $bad)) {
                    return false;
                }
            }

            return true;
        });

        return array_values($filtered);
    }

    /**
     * Harvest all readable API endpoints from JS bundles (template literals, Axios/Fetch calls, relative/absolute paths).
     * Universal, domain-agnostic and entity-agnostic.
     *
     * @return array<int, string>
     */
    protected function harvestAllReadApisFromJs(string $js): array
    {
        $apis = [];

        // 1. Template literal paths like `${var}/endpoint/path`
        if (preg_match_all('/`\$\{[a-zA-Z0-9_]+\}(\/[a-zA-Z0-9_\/-]+)(?:[\?`\$]|\/\{)/i', $js, $tplMatches)) {
            $apis = array_merge($apis, $tplMatches[1]);
        }

        // 2. HTTP client calls: .get('/path'), .post('/path'), fetch('/path')
        if (preg_match_all('/(?:\.(?:get|post)|fetch)\s*\(\s*["\'](\/[a-zA-Z0-9_\/-]+)["\']/i', $js, $clientMatches)) {
            $apis = array_merge($apis, $clientMatches[1]);
        }
        if (preg_match_all('/(?:\.(?:get|post)|fetch)\s*\(\s*`\$\{[^}]+\}(\/[a-zA-Z0-9_\/-]+)/i', $js, $clientTplMatches)) {
            $apis = array_merge($apis, $clientTplMatches[1]);
        }

        // 3. Matches relative /api/ or other common route prefix endpoints in quotes
        if (preg_match_all('/["\'](\/(?:api|admin|v[0-9]+|rest|app|portal|dashboard)\/[a-zA-Z0-9_\/-]+)["\']/i', $js, $matches)) {
            $apis = array_merge($apis, $matches[1]);
        }

        // 4. Matches full https://... endpoints, excluding common 3rd-party CDNs and analytics
        if (preg_match_all('/https?:\/\/[a-z0-9.-]+(?::[0-9]+)?(\/[a-zA-Z0-9._\/-]*)/i', $js, $fullMatches)) {
            foreach ($fullMatches[0] as $u) {
                $lower = strtolower($u);
                if (! str_contains($lower, 'pusher')
                    && ! str_contains($lower, 'github')
                    && ! str_contains($lower, 'w3.org')
                    && ! str_contains($lower, 'google')
                    && ! str_contains($lower, 'sentry')
                    && ! str_contains($lower, 'cloudflare')
                    && ! str_contains($lower, 'facebook')
                    && ! str_contains($lower, 'unpkg')
                    && ! str_contains($lower, 'jsdelivr')) {
                    $apis[] = $u;
                }
            }
        }

        // Mutation / noise / utility keywords to filter out (not products or services)
        $mutationKeywords = [
            'login', 'logout', 'auth', 'delete', 'destroy', 'remove', 'store',
            'create', 'update', 'edit', 'add', 'status', 'upload', 'patch',
            'import', 'export', 'download', 'register', 'verify', 'password',
            'token', 'reset', 'change', 'assign', 'swap', 'toggle', 'attendance',
            'code_request', 'notification', 'notifications', 'online_user',
            'visitor_limit', 'my_profile', 'profile_image', 'setting',
        ];

        // Static asset extensions to filter out
        $assetExtensions = [
            '.png', '.jpg', '.jpeg', '.gif', '.svg', '.ico', '.webp',
            '.css', '.js', '.woff', '.woff2', '.ttf', '.eot', '.map',
        ];

        $filtered = array_filter(array_unique($apis), function ($api) use ($mutationKeywords, $assetExtensions) {
            $api = trim($api);
            if (strlen($api) < 3 || strlen($api) > 65) {
                return false;
            }

            // Exclude static assets
            foreach ($assetExtensions as $ext) {
                if (str_ends_with(strtolower($api), $ext) || str_contains($api, $ext.'?')) {
                    return false;
                }
            }

            // Exclude trailing slashes and parameter placeholders
            if (str_ends_with($api, '/') || str_contains($api, ':') || str_contains($api, '{') || str_contains($api, '$')) {
                return false;
            }

            $lower = strtolower($api);
            foreach ($mutationKeywords as $bad) {
                if (str_contains($lower, '/'.$bad) || str_ends_with($lower, $bad)) {
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

        // Pattern 2: URLs with common backend subdomains (host & paths)
        $subdomainPattern = '/https?:\/\/(?:bcknd|backend|restbcknd|api|admin-api|app-api|server)\.[a-z0-9][a-z0-9.-]+(?:\/[a-zA-Z0-9_-]+)*/i';
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
                '/["\'`](\/[a-zA-Z0-9_\/-]*(?:login|signin|authenticate)[a-zA-Z0-9_\/-]*)["\'`]/i',
            ];

            foreach ($loginPatterns as $pattern) {
                if (preg_match_all($pattern, $js, $matches)) {
                    foreach ($matches[1] as $p) {
                        if (! str_contains($p, '.') && strlen($p) < 60) {
                            $paths[] = $p;
                        }
                    }
                }
            }
        }

        if ($type === 'logout') {
            // 1. Full https:// URLs containing logout
            if (preg_match_all('/https?:\/\/[a-zA-Z0-9.-]+(?::[0-9]+)?(?:\/[a-zA-Z0-9._\/-]*)?/i', $js, $fullMatches)) {
                foreach ($fullMatches[0] as $u) {
                    $lower = strtolower($u);
                    if ((str_contains($lower, 'logout') || str_contains($lower, 'signout'))
                        && ! str_contains($lower, 'pusher')
                        && ! str_contains($lower, 'github')
                        && ! str_contains($lower, 'w3.org')
                        && ! str_contains($lower, 'google')
                        && ! str_contains($lower, '.js')) {
                        $paths[] = $u;
                    }
                }
            }

            // 2. Relative paths
            $logoutPatterns = [
                '/["\'`](\/[a-zA-Z0-9_\/-]*(?:logout|signout)[a-zA-Z0-9_\/-]*)["\'`]/i',
            ];

            foreach ($logoutPatterns as $pattern) {
                if (preg_match_all($pattern, $js, $matches)) {
                    foreach ($matches[1] as $p) {
                        if (! str_contains($p, '.') && strlen($p) < 60) {
                            $paths[] = $p;
                        }
                    }
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

        $best = $scored[0]['url'];
        $parsed = parse_url($best);

        return ($parsed['scheme'] ?? 'https').'://'.($parsed['host'] ?? '');
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
                $body = (string) $response->body();
                Log::debug(self::LOG_PREFIX." Login attempt failed: {$url}", [
                    'status' => $response->status(),
                    'content_type' => $contentType,
                    'response' => substr($body, 0, 150),
                ]);

                // Handle single-device / active session lockouts: "You log from another device"
                if (stripos($body, 'another device') !== false || stripos($body, 'already logged') !== false) {
                    Log::warning(self::LOG_PREFIX.' Remote backend detected active session ("another device"). Attempting forced login override...');

                    $forceVariants = [
                        ['force' => 1],
                        ['force' => true],
                        ['force_login' => 1],
                        ['force_login' => true],
                        ['logout_others' => 1],
                        ['device' => 'scraper_'.uniqid()],
                    ];

                    foreach ($forceVariants as $forceVariant) {
                        try {
                            $retryReq = Http::timeout(self::HTTP_TIMEOUT)
                                ->withoutVerifying()
                                ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])
                                ->withHeaders([
                                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
                                ])
                                ->acceptJson();

                            $forcedCreds = array_merge($credentials, $forceVariant);
                            $retryResp = ($contentType === 'json')
                                ? $retryReq->post($url, $forcedCreds)
                                : $retryReq->asForm()->post($url, $forcedCreds);

                            if ($retryResp->successful()) {
                                $forcedData = $retryResp->json();
                                $forcedToken = $this->extractToken($forcedData);
                                if ($forcedToken) {
                                    Log::info(self::LOG_PREFIX.' Forced login override succeeded!', [
                                        'flag' => array_key_first($forceVariant),
                                    ]);

                                    return $forcedToken;
                                }
                            }
                        } catch (\Throwable) {
                            // continue
                        }
                    }
                }

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
     * Gracefully log out from remote session using Bearer token to prevent multi-device lockout.
     *
     * @param  array{base_url: string|null, logout_paths?: array<int, string>}  $apiConfig
     */
    protected function attemptLogout(array $apiConfig, string $token): void
    {
        $logoutPaths = $apiConfig['logout_paths'] ?? [];
        $baseUrl = $apiConfig['base_url'] ?? '';

        if (empty($logoutPaths)) {
            $logoutPaths = ['/api/logout', '/logout'];
        }

        foreach (array_slice($logoutPaths, 0, 4) as $path) {
            $url = (str_starts_with($path, 'http://') || str_starts_with($path, 'https://'))
                ? $path
                : rtrim($baseUrl, '/').'/'.ltrim($path, '/');

            try {
                // Try GET first (used by React apps like Sea-Go)
                $resp = Http::timeout(4)
                    ->withoutVerifying()
                    ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])
                    ->withHeaders([
                        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
                    ])
                    ->withToken($token)
                    ->acceptJson()
                    ->get($url);

                if ($resp->successful()) {
                    Log::info(self::LOG_PREFIX.' Successfully logged out from remote session.', ['url' => $url]);

                    return;
                }

                // Also try POST
                $postResp = Http::timeout(4)
                    ->withoutVerifying()
                    ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])
                    ->withHeaders([
                        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
                    ])
                    ->withToken($token)
                    ->acceptJson()
                    ->post($url);

                if ($postResp->successful()) {
                    Log::info(self::LOG_PREFIX.' Successfully logged out from remote session (POST).', ['url' => $url]);

                    return;
                }
            } catch (\Throwable) {
                // Ignore logout exceptions
            }
        }
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
                    // If GET returns 405 (Method Not Allowed), try POST
                    if ($response->status() === 405) {
                        $postResponse = Http::timeout(8)
                            ->withoutVerifying()
                            ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])
                            ->withHeaders([
                                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
                            ])
                            ->acceptJson()
                            ->withToken($token)
                            ->post($productUrl, ['page' => 1, 'limit' => 100]);

                        if ($postResponse->successful()) {
                            $response = $postResponse;
                        } else {
                            Log::debug(self::LOG_PREFIX." Product fetch failed: {$productUrl}", [
                                'status' => $response->status(),
                            ]);

                            continue;
                        }
                    } else {
                        Log::debug(self::LOG_PREFIX." Product fetch failed: {$productUrl}", [
                            'status' => $response->status(),
                        ]);

                        continue;
                    }
                }

                $data = $response->json();
                $products = $this->extractProductsList($data, $keywords);

                if ($products !== null && count($products) > 0) {
                    Log::info(self::LOG_PREFIX." Products found at: {$productUrl}", [
                        'count' => count($products),
                        'matched_path' => $productPath,
                    ]);

                    // If no keywords specified, or if this endpoint matches any requested keyword, return it
                    if (empty($keywords)) {
                        return $products;
                    }

                    foreach ($keywords as $kw) {
                        $lastSeg = trim((string) basename(parse_url($productPath, PHP_URL_PATH) ?? $productPath), '/');
                        if (stripos($productPath, $kw) !== false || (strlen($lastSeg) >= 3 && stripos($kw, $lastSeg) !== false)) {
                            return $products;
                        }
                    }
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
     * Universal fallback: harvest data from ALL read API endpoints discovered on the page/bundle.
     * Queries each discovered endpoint with auth token and groups valid lists of items into an associative array:
     * ['endpoint_name' => [items...], ...]
     *
     * @param  array{base_url: string|null, all_read_paths?: array<int, string>, product_paths?: array<int, string>}  $apiConfig
     * @return array{products: array<string, array<int, mixed>>, meta: array<string, mixed>}|null
     */
    protected function harvestAllEndpointsData(array $apiConfig, string $token, array $keywords = []): ?array
    {
        $baseUrl = $apiConfig['base_url'];
        $readPaths = $apiConfig['all_read_paths'] ?? $apiConfig['product_paths'] ?? [];

        if (empty($readPaths) || ! $baseUrl) {
            return null;
        }

        // Deduplicate
        $readPaths = array_values(array_unique($readPaths));

        // Prioritize endpoints matching keywords or related to business entities
        if (! empty($keywords)) {
            usort($readPaths, function ($a, $b) use ($keywords) {
                $scoreA = 0;
                $scoreB = 0;
                foreach ($keywords as $kw) {
                    if (strlen($kw) >= 3) {
                        $lastA = trim((string) basename(parse_url($a, PHP_URL_PATH) ?? $a), '/');
                        $lastB = trim((string) basename(parse_url($b, PHP_URL_PATH) ?? $b), '/');
                        if (stripos($a, $kw) !== false || stripos($kw, $lastA) !== false) {
                            $scoreA += 50;
                        }
                        if (stripos($b, $kw) !== false || stripos($kw, $lastB) !== false) {
                            $scoreB += 50;
                        }
                    }
                }

                return $scoreB <=> $scoreA;
            });
        }

        $candidatePaths = array_slice($readPaths, 0, 30);

        $harvested = [];

        foreach ($candidatePaths as $path) {
            $url = (str_starts_with($path, 'http://') || str_starts_with($path, 'https://'))
                ? $path
                : rtrim($baseUrl, '/').'/'.ltrim($path, '/');

            try {
                $response = Http::timeout(4)
                    ->withoutVerifying()
                    ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])
                    ->withHeaders([
                        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
                    ])
                    ->acceptJson()
                    ->withToken($token)
                    ->get($url);

                if (! $response->successful()) {
                    if ($response->status() === 405) {
                        $postResp = Http::timeout(4)
                            ->withoutVerifying()
                            ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])
                            ->withHeaders([
                                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
                            ])
                            ->acceptJson()
                            ->withToken($token)
                            ->post($url, ['page' => 1, 'limit' => 50]);

                        if ($postResp->successful()) {
                            $response = $postResp;
                        } else {
                            continue;
                        }
                    } else {
                        continue;
                    }
                }

                $data = $response->json();
                $items = $this->extractProductsList($data);

                if ($items !== null && count($items) > 0) {
                    $cleanKey = trim((string) parse_url($path, PHP_URL_PATH), '/');
                    if (empty($cleanKey)) {
                        $cleanKey = trim($path, '/');
                    }

                    $harvested[$cleanKey] = $items;
                    Log::info(self::LOG_PREFIX." Harvested endpoint [{$cleanKey}] with ".count($items).' items.', [
                        'url' => $url,
                        'count' => count($items),
                    ]);
                }
            } catch (\Throwable $e) {
                Log::debug(self::LOG_PREFIX." Harvest request error for {$url}: ".$e->getMessage());
            }
        }

        if (empty($harvested)) {
            return null;
        }

        $totalItems = array_sum(array_map('count', $harvested));
        Log::info(self::LOG_PREFIX.' Universal multi-endpoint harvest completed successfully.', [
            'endpoints_count' => count($harvested),
            'endpoints' => array_keys($harvested),
            'total_items' => $totalItems,
        ]);

        return [
            'products' => $harvested,
            'meta' => [
                'source' => 'api_discovery_harvest',
                'base_url' => $baseUrl,
                'endpoints' => array_keys($harvested),
                'endpoints_count' => count($harvested),
                'total_items' => $totalItems,
                'scraped_at' => now()->toIso8601String(),
            ],
        ];
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
            'owners', 'units', 'posts', 'post', 'security', 'results', 'payload', 'list', 'records', 'rows',
        ])));

        foreach ($priorityKeys as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                $extracted = $this->extractProductsList($data[$key], $keywords);
                if ($extracted !== null && count($extracted) > 0) {
                    return $extracted;
                }
            }
        }

        // 3. Fallback: inspect every nested array value in the associative container (skipping metadata & pagination keys)
        $ignoredKeys = ['links', 'meta', 'pagination', 'headers', 'config', 'request', 'params', 'status', 'permissions'];
        foreach ($data as $k => $val) {
            if (in_array(strtolower((string) $k), $ignoredKeys)) {
                continue;
            }
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

            // Skip pagination link items: {"url": "...", "label": "...", "active": ...}
            if (isset($item['label']) && (isset($item['url']) || isset($item['active']))) {
                continue;
            }
            if (isset($item['label']) && is_string($item['label']) && (stripos($item['label'], 'previous') !== false || stripos($item['label'], 'next') !== false)) {
                continue;
            }

            $id = $item['id'] ?? $item['_id'] ?? $item['course_id'] ?? $item['category_id'] ?? $item['post_id'] ?? ($index + 1);
            $name = $item['name']
                ?? $item['ar_name']
                ?? $item['name_ar']
                ?? $item['title']
                ?? $item['ar_title']
                ?? $item['course_name']
                ?? $item['category_name']
                ?? $item['label']
                ?? $item['admin_name']
                ?? (isset($item['description']) && is_string($item['description']) && trim($item['description']) !== '' ? Str::limit(trim(strip_tags($item['description'])), 60) : null)
                ?? ('Item #'.($id ?? ($index + 1)));

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

            $description = $item['description'] ?? $item['ar_description'] ?? $item['desc'] ?? $item['details'] ?? $item['phone'] ?? $item['email'] ?? null;
            $image = $item['image']
                ?? $item['image_url']
                ?? $item['imageUrl']
                ?? $item['image_link']
                ?? (isset($item['images'][0]['image_link']) ? $item['images'][0]['image_link'] : null)
                ?? (isset($item['images'][0]['url']) ? $item['images'][0]['url'] : null)
                ?? (isset($item['images'][0]) && is_string($item['images'][0]) ? $item['images'][0] : null)
                ?? $item['avatar']
                ?? $item['thumbnail']
                ?? $item['cover']
                ?? null;

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
     * Stage 2: Scrape traditional server-rendered websites (HTML / Blade / PHP / WordPress)
     * via authenticated web session cookies and DOM parsing.
     * Runs ONLY when the site is not an API or API discovery yields no data.
     *
     * @return array{products: array<int, mixed>, meta: array<string, mixed>}|null
     */
    public function scrapeViaWebSession(
        string $loginLink,
        string $userName,
        string $password,
        string $productsLink
    ): ?array {
        try {
            $jar = new CookieJar;
            $parsedLogin = parse_url($loginLink);
            $baseOrigin = ($parsedLogin['scheme'] ?? 'https').'://'.($parsedLogin['host'] ?? '');

            // 1. Download login page HTML to inspect form action and CSRF token
            $loginResponse = Http::timeout(self::HTTP_TIMEOUT)
                ->withoutVerifying()
                ->withOptions(['cookies' => $jar, 'curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                ])
                ->get($loginLink);

            if (! $loginResponse->successful()) {
                return null;
            }

            $loginHtml = $loginResponse->body();
            $formDetails = $this->extractFormDetails($loginHtml, $loginLink);

            if (! $formDetails['action']) {
                return null;
            }

            // Build form POST payload with hidden fields (CSRF) and credentials
            $postData = $formDetails['hidden_inputs'];
            $emailField = $formDetails['email_field'] ?? 'email';
            $passwordField = $formDetails['password_field'] ?? 'password';

            $postData[$emailField] = $userName;
            $postData[$passwordField] = $password;

            Log::info(self::LOG_PREFIX.' [WebSession] Submitting web login form with session cookies.', [
                'action' => $formDetails['action'],
                'user_field' => $emailField,
                'hidden_fields' => array_keys($formDetails['hidden_inputs']),
            ]);

            // 2. Submit login form (allow_redirects=false so we can detect authentication success/failure)
            $loginHeaders = [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8,application/json',
                'Origin' => $baseOrigin,
                'Referer' => $loginLink,
            ];
            if (! empty($formDetails['hidden_inputs']['_token'])) {
                $loginHeaders['X-CSRF-TOKEN'] = $formDetails['hidden_inputs']['_token'];
            }

            $loginResp = Http::timeout(self::HTTP_TIMEOUT)
                ->withoutVerifying()
                ->withOptions([
                    'cookies' => $jar,
                    'allow_redirects' => false,
                    'curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4],
                ])
                ->withHeaders($loginHeaders)
                ->asForm()
                ->post($formDetails['action'], $postData);

            $status = $loginResp->status();
            $location = $loginResp->header('Location');

            // Handle HTTP 3xx Redirects
            if ($status >= 300 && $status < 400 && $location) {
                if (str_starts_with($location, '/')) {
                    $location = $baseOrigin.$location;
                }

                $loginPath = parse_url($loginLink, PHP_URL_PATH) ?? '/login';
                $redirectPath = parse_url($location, PHP_URL_PATH) ?? '';

                // If redirected back to login page, authentication failed
                if (str_contains(strtolower($redirectPath), 'login') || $redirectPath === $loginPath) {
                    $errorMsg = null;
                    try {
                        $errPage = Http::timeout(self::HTTP_TIMEOUT)
                            ->withoutVerifying()
                            ->withOptions(['cookies' => $jar, 'curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])
                            ->get($location);

                        $errBody = $errPage->body();
                        if (preg_match('/<span[^>]*style="[^"]*color:\s*red[^"]*"[^>]*>(.*?)<\/span>/is', $errBody, $m)) {
                            $errorMsg = trim(strip_tags($m[1]));
                        } elseif (preg_match('/<(?:div|span|p)[^>]*class="[^"]*(?:alert|danger|error|invalid-feedback)[^"]*"[^>]*>(.*?)<\/(?:div|span|p)>/is', $errBody, $m)) {
                            $errorMsg = trim(strip_tags($m[1]));
                        }
                    } catch (\Throwable) {
                    }

                    Log::warning(self::LOG_PREFIX.' [WebSession] Login rejected by server: '.($errorMsg ?: 'Server redirected back to login page. Please verify email and password for this account.'), [
                        'redirect' => $location,
                        'server_error' => $errorMsg,
                    ]);

                    return null;
                }

                // Redirected to dashboard/home -> Login successful! Initialize session
                Log::info(self::LOG_PREFIX.' [WebSession] Login successful (redirected to '.$location.'). Initializing session.');
                try {
                    Http::timeout(self::HTTP_TIMEOUT)
                        ->withoutVerifying()
                        ->withOptions(['cookies' => $jar, 'curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])
                        ->get($location);
                } catch (\Throwable) {
                }
            } elseif ($status === 200) {
                $contentType = $loginResp->header('Content-Type') ?? '';
                if (str_contains($contentType, 'application/json')) {
                    $json = $loginResp->json();
                    if (isset($json['status']) && ($json['status'] === false || $json['status'] >= 400 || ! empty($json['errors']))) {
                        Log::warning(self::LOG_PREFIX.' [WebSession] AJAX login rejected by server.', ['response' => $json]);

                        return null;
                    }
                } else {
                    $body = $loginResp->body();
                    if (str_contains($body, 'name="password"') && (str_contains($body, 'alert-danger') || str_contains($body, 'Invalid') || str_contains($body, 'do not match'))) {
                        Log::warning(self::LOG_PREFIX.' [WebSession] Login form redisplayed with credentials error.');

                        return null;
                    }
                }
            } elseif ($status >= 400) {
                Log::warning(self::LOG_PREFIX." [WebSession] Login endpoint returned HTTP {$status}.", [
                    'action' => $formDetails['action'],
                ]);

                return null;
            }

            // 3. Fetch products page with authenticated session cookies
            Log::info(self::LOG_PREFIX.' [WebSession] Fetching products page with session cookies.', [
                'url' => $productsLink,
                'cookies_count' => count($jar),
            ]);

            $productsResp = Http::timeout(self::HTTP_TIMEOUT)
                ->withoutVerifying()
                ->withOptions([
                    'cookies' => $jar,
                    'allow_redirects' => false,
                    'curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4],
                ])
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8,application/json',
                    'Referer' => $loginLink,
                ])
                ->get($productsLink);

            $prodStatus = $productsResp->status();
            if ($prodStatus >= 300 && $prodStatus < 400) {
                $targetLoc = $productsResp->header('Location');
                if ($targetLoc) {
                    if (str_starts_with($targetLoc, '/')) {
                        $targetLoc = $baseOrigin.$targetLoc;
                    }

                    // Guard: If redirected to login, session is unauthenticated
                    if (str_contains(strtolower($targetLoc), 'login') || str_contains(strtolower($targetLoc), 'signin')) {
                        Log::warning(self::LOG_PREFIX.' [WebSession] Access denied: Products page redirected to login (unauthenticated session).', [
                            'redirect' => $targetLoc,
                            'products_link' => $productsLink,
                        ]);

                        return null;
                    }

                    // Follow valid redirect to actual target page
                    $productsResp = Http::timeout(self::HTTP_TIMEOUT)
                        ->withoutVerifying()
                        ->withOptions([
                            'cookies' => $jar,
                            'allow_redirects' => true,
                            'curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4],
                        ])
                        ->withHeaders([
                            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
                            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8,application/json',
                            'Referer' => $productsLink,
                        ])
                        ->get($targetLoc);
                }
            }

            if (! $productsResp->successful()) {
                Log::debug(self::LOG_PREFIX.' [WebSession] Products page returned error.', [
                    'status' => $productsResp->status(),
                ]);

                return null;
            }

            // Guard: verify page is not a login form
            $bodyContent = $productsResp->body();
            if (str_contains($bodyContent, 'name="password"') && (str_contains($bodyContent, 'login_store') || str_contains($bodyContent, 'action=') || str_contains($bodyContent, 'sublogin'))) {
                Log::warning(self::LOG_PREFIX.' [WebSession] Products page rendered a login form instead of content (unauthenticated session).');

                return null;
            }

            // Discover and log available student navigation links for user visibility
            if (preg_match_all('/<a\b[^>]*?\bhref=["\']([^"\']*(?:student|course|package)[^"\']*)["\']/i', $bodyContent, $linkMatches)) {
                $discoveredStudentLinks = array_values(array_unique(array_filter(
                    $linkMatches[1],
                    fn ($l) => ! str_contains($l, '#') && ! str_contains($l, 'logout') && strlen($l) < 80
                )));
                if (! empty($discoveredStudentLinks)) {
                    Log::info(self::LOG_PREFIX.' [WebSession] Available student portal links discovered on site:', [
                        'links' => array_slice($discoveredStudentLinks, 0, 10),
                    ]);
                }
            }

            // 4. Extract items (supports JSON, embedded scripts/Inertia props, and HTML DOM tables/cards)
            $contentType = $productsResp->header('Content-Type') ?? '';
            $rawItems = [];

            if (str_contains($contentType, 'application/json')) {
                $jsonData = $productsResp->json();
                $rawItems = $this->extractProductsList($jsonData) ?? [];
            } else {
                $rawItems = $this->extractItemsFromHtmlDom($bodyContent);
            }

            if (empty($rawItems)) {
                $pageTitle = preg_match('/<title[^>]*>(.*?)<\/title>/is', $bodyContent, $titleMatch) ? trim(strip_tags($titleMatch[1])) : 'Unknown';
                $cleanText = trim(preg_replace('/\s+/', ' ', strip_tags($bodyContent)));
                Log::info(self::LOG_PREFIX.' [WebSession] No course/package items found in page content area (account may have no active items on this page).', [
                    'page_title' => $pageTitle,
                    'url' => $productsLink,
                    'content_preview' => mb_substr($cleanText, 0, 150),
                ]);

                return null;
            }

            $normalized = $this->normalizeProductItems($rawItems);

            if (empty($normalized)) {
                return null;
            }

            Log::info(self::LOG_PREFIX.' [WebSession] Successfully scraped items from traditional web page!', [
                'count' => count($normalized),
                'sample' => $normalized[0] ?? null,
            ]);

            return [
                'products' => $normalized,
                'meta' => [
                    'source' => 'web_session_scrape',
                    'login_url' => $loginLink,
                    'products_url' => $productsLink,
                    'products_count' => count($normalized),
                    'scraped_at' => now()->toIso8601String(),
                ],
            ];
        } catch (\Throwable $e) {
            Log::debug(self::LOG_PREFIX.' [WebSession] Exception: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Extract login form action, hidden inputs (e.g. CSRF tokens), and input field names.
     *
     * @return array{action: string, hidden_inputs: array<string, string>, email_field: string, password_field: string}
     */
    protected function extractFormDetails(string $html, string $loginLink): array
    {
        $parsed = parse_url($loginLink);
        $baseOrigin = ($parsed['scheme'] ?? 'https').'://'.($parsed['host'] ?? '');

        $action = null;
        if (preg_match('/<form\b[^>]*?\baction=["\']([^"\']*)["\']/i', $html, $m)) {
            $rawAction = trim($m[1]);
            if (empty($rawAction) || $rawAction === '#') {
                $action = $loginLink;
            } elseif (str_starts_with($rawAction, '//')) {
                $action = ($parsed['scheme'] ?? 'https').':'.$rawAction;
            } elseif (str_starts_with($rawAction, 'http://') || str_starts_with($rawAction, 'https://')) {
                $action = $rawAction;
            } elseif (str_starts_with($rawAction, '/')) {
                $action = $baseOrigin.$rawAction;
            } else {
                $dir = dirname($parsed['path'] ?? '/');
                $action = $baseOrigin.($dir === '/' || $dir === '\\' ? '' : $dir).'/'.ltrim($rawAction, '/');
            }
        }

        if (! $action) {
            $action = $loginLink;
        }

        // Hidden inputs and dynamic field discovery
        $hidden = [];
        $emailField = 'email';
        $passwordField = 'password';

        if (preg_match_all('/<input\b[^>]*>/i', $html, $allInputs)) {
            foreach ($allInputs[0] as $inputTag) {
                $hasName = preg_match('/\bname=["\']([^"\']+)["\']/i', $inputTag, $nameMatch);
                $hasType = preg_match('/\btype=["\']([^"\']+)["\']/i', $inputTag, $typeMatch);
                $name = $hasName ? $nameMatch[1] : '';
                $type = strtolower($hasType ? $typeMatch[1] : 'text');

                if ($type === 'hidden' && $hasName) {
                    $hasVal = preg_match('/\bvalue=["\']([^"\']*)["\']/i', $inputTag, $valMatch);
                    $hidden[$name] = $hasVal ? $valMatch[1] : '';
                } elseif ($type === 'password' && $hasName) {
                    $passwordField = $name;
                } elseif (($type === 'email' || ($type === 'text' && preg_match('/user|email|login|phone/i', $name))) && $hasName) {
                    $emailField = $name;
                }
            }
        }

        // Check meta CSRF token if hidden token input was absent
        if (empty($hidden['_token']) && empty($hidden['csrf_token']) && empty($hidden['csrf-token'])) {
            if (preg_match('/<meta\b[^>]*?\bname=["\'](?:csrf-token|_token)["\'][^>]*?\bcontent=["\']([^"\']+)["\']/i', $html, $metaCsrf)) {
                $hidden['_token'] = $metaCsrf[1];
            }
        }

        return [
            'action' => $action,
            'hidden_inputs' => $hidden,
            'email_field' => $emailField,
            'password_field' => $passwordField,
        ];
    }

    /**
     * Validate whether a string is a legitimate item / product / course name
     * (rejecting pure numbers, user greetings, navigation keywords, and empty strings).
     */
    protected function isValidItemName(string $name): bool
    {
        $clean = trim(preg_replace('/\s+/', ' ', strip_tags($name)));

        if (mb_strlen($clean) < 3) {
            return false;
        }

        // Must contain at least one letter (Latin or Arabic)
        if (! preg_match('/[\p{L}]/u', $clean)) {
            return false;
        }

        // Reject pure numbers and numeric identifiers (e.g. "4999", "5030", "14727")
        if (preg_match('/^[0-9\s.,#:\/-]+$/', $clean)) {
            return false;
        }

        // Reject user greetings in header
        if (stripos($clean, 'welcome') === 0) {
            return false;
        }

        $lower = strtolower($clean);

        // Filter out layout, navigation, and administrative words
        $ignoredKeywords = [
            'courses', 'my courses', 'packages', 'my packages', 'all packages', 'all courses',
            'home', 'dashboard', 'welcome', 'logout', 'login', 'sign in', 'sign out',
            'profile', 'my profile', 'settings', 'account', 'notifications',
            'student', 'admin', 'teacher', 'instructor', 'menu', 'navigation',
            'previous', 'next', 'back', 'view', 'details', 'action', 'actions',
            'status', 'edit', 'delete', 'filter', 'search', 'sort', 'all',
            'dev', 'user', 'default', 'close', 'open', 'read more', 'click here',
            'view all', 'show more', 'load more', 'select', 'choose',
        ];

        if (in_array($lower, $ignoredKeywords, true)) {
            return false;
        }

        return true;
    }

    /**
     * Extract product / course / item structures from HTML DOM (tables, cards, lists, embedded JSON).
     * Automatically strips layout chrome (header, sidebar, navbar, user profile, footer) first.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function extractItemsFromHtmlDom(string $html): array
    {
        if (empty(trim($html))) {
            return [];
        }

        // Strategy A: Inertia.js data-page attribute
        if (preg_match('/data-page=["\'](\{.*?\})["\']/s', $html, $inertiaMatch)) {
            $decoded = json_decode(html_entity_decode($inertiaMatch[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
            if ($decoded && is_array($decoded)) {
                $extracted = $this->extractProductsList($decoded['props'] ?? $decoded);
                if (! empty($extracted)) {
                    return $extracted;
                }
            }
        }

        // Strategy B: Inline script JSON arrays: var courses = [...]; or items = [...]
        if (preg_match_all('/(?:var|let|const|window\.)\s*(?:courses|packages|products|items|data|lessons|units)\s*=\s*(\[\s*\{.*?\}\s*\])\s*;/is', $html, $scriptMatches)) {
            foreach ($scriptMatches[1] as $jsonBlock) {
                $decoded = json_decode($jsonBlock, true);
                if ($decoded && is_array($decoded)) {
                    $extracted = $this->extractProductsList($decoded);
                    if (! empty($extracted)) {
                        return $extracted;
                    }
                }
            }
        }

        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($dom);

        // Strip layout chrome (header, footer, nav, sidebar, offcanvas, drawers, scripts, styles)
        $chromeQuery = '//header | //footer | //nav | //aside | //script | //style | //noscript | '
            .'//*[contains(@class, "sidebar") or contains(@class, "aside") or contains(@class, "navbar") or contains(@class, "topbar") or contains(@class, "toolbar") or contains(@class, "header") or contains(@class, "footer") or contains(@class, "breadcrumb") or contains(@class, "drawer") or contains(@class, "menu") or contains(@class, "user-menu") or contains(@class, "profile-dropdown") or contains(@class, "notification")] | '
            .'//*[contains(@id, "sidebar") or contains(@id, "aside") or contains(@id, "navbar") or contains(@id, "topbar") or contains(@id, "toolbar") or contains(@id, "header") or contains(@id, "footer") or contains(@id, "drawer")]';

        $chromeNodes = $xpath->query($chromeQuery);
        if ($chromeNodes && $chromeNodes->length > 0) {
            $toRemove = [];
            foreach ($chromeNodes as $node) {
                $toRemove[] = $node;
            }
            foreach ($toRemove as $node) {
                if ($node->parentNode) {
                    $node->parentNode->removeChild($node);
                }
            }
        }

        $items = [];

        // Strategy C: HTML Table Rows in content area (common in courses, packages, schedules)
        $rows = $xpath->query('//table//tbody//tr|//table//tr[td]');
        if ($rows && $rows->length > 0) {
            foreach ($rows as $index => $row) {
                $cells = $xpath->query('.//td', $row);
                if ($cells && $cells->length >= 2) {
                    $name = trim($cells->item(0)->textContent);
                    // If first cell has link or bold text, prefer that
                    $linkInCell = $xpath->query('.//a|.//strong|.//b', $cells->item(0));
                    if ($linkInCell && $linkInCell->length > 0) {
                        $candidate = trim($linkInCell->item(0)->textContent);
                        if (strlen($candidate) > 2) {
                            $name = $candidate;
                        }
                    }

                    if (! $this->isValidItemName($name)) {
                        // Check second cell
                        $name2 = trim($cells->item(1)->textContent);
                        if ($this->isValidItemName($name2)) {
                            $name = $name2;
                        } else {
                            continue;
                        }
                    }

                    $desc = $cells->length >= 3 ? trim($cells->item(1)->textContent) : null;
                    $price = null;
                    for ($i = 1; $i < $cells->length; $i++) {
                        $txt = trim($cells->item($i)->textContent);
                        if (preg_match('/(?:[\$£€]|EGP|LE|SAR|USD|ج\.م|جنيه|ريال)\s*([0-9]+(?:\.[0-9]+)?)/i', $txt, $numMatch)) {
                            $price = $numMatch[1];
                            break;
                        } elseif (preg_match('/^[0-9]+(?:\.[0-9]+)?$/', $txt, $numMatch)) {
                            $price = $numMatch[0];
                        }
                    }

                    $imgNode = $xpath->query('.//img', $row);
                    $img = ($imgNode && $imgNode->length > 0) ? $imgNode->item(0)->getAttribute('src') : null;

                    $items[] = [
                        'id' => $index + 1,
                        'name' => $name,
                        'description' => $desc,
                        'price' => $price,
                        'image' => $img,
                    ];
                }
            }

            if (! empty($items)) {
                return $this->deduplicateItemsByName($items);
            }
        }

        // Strategy D: Cards, Accordions & Content Items in content area
        $cardQuery = '//*[contains(concat(" ", normalize-space(@class), " "), " card ") '
            .'or contains(@class, "course-card") '
            .'or contains(@class, "package-card") '
            .'or contains(@class, "product-card") '
            .'or contains(@class, "item-card") '
            .'or contains(@class, "course-box") '
            .'or contains(@class, "package-box") '
            .'or contains(@class, "accordion-item") '
            .'or contains(@class, "list-group-item") '
            .'or contains(@class, "card-body")]';

        $cardNodes = $xpath->query($cardQuery);
        if ($cardNodes && $cardNodes->length >= 1) {
            foreach ($cardNodes as $idx => $card) {
                // Strict prioritized title discovery
                $name = null;

                // Priority 1: Elements with explicit title, name, course, package classes
                $titleNodes = $xpath->query('.//*[contains(@class, "title") or contains(@class, "name") or contains(@class, "heading") or contains(@class, "course") or contains(@class, "package")]', $card);
                if ($titleNodes && $titleNodes->length > 0) {
                    foreach ($titleNodes as $tn) {
                        $candidate = trim(preg_replace('/\s+/', ' ', strip_tags($tn->textContent)));
                        if ($this->isValidItemName($candidate)) {
                            $name = $candidate;
                            break;
                        }
                    }
                }

                // Priority 2: Headings h1..h6
                if (! $name) {
                    $headingNodes = $xpath->query('.//h1|.//h2|.//h3|.//h4|.//h5|.//h6', $card);
                    if ($headingNodes && $headingNodes->length > 0) {
                        foreach ($headingNodes as $hn) {
                            $candidate = trim(preg_replace('/\s+/', ' ', strip_tags($hn->textContent)));
                            if ($this->isValidItemName($candidate)) {
                                $name = $candidate;
                                break;
                            }
                        }
                    }
                }

                // Priority 3: Bold / Strong text
                if (! $name) {
                    $boldNodes = $xpath->query('.//strong|.//b', $card);
                    if ($boldNodes && $boldNodes->length > 0) {
                        foreach ($boldNodes as $bn) {
                            $candidate = trim(preg_replace('/\s+/', ' ', strip_tags($bn->textContent)));
                            if ($this->isValidItemName($candidate)) {
                                $name = $candidate;
                                break;
                            }
                        }
                    }
                }

                // Priority 4: Anchor tags with meaningful title/text classes
                if (! $name) {
                    $aNodes = $xpath->query('.//a[contains(@class, "text-") or contains(@class, "title") or contains(@class, "name")]', $card);
                    if ($aNodes && $aNodes->length > 0) {
                        foreach ($aNodes as $an) {
                            $candidate = trim(preg_replace('/\s+/', ' ', strip_tags($an->textContent)));
                            if ($this->isValidItemName($candidate)) {
                                $name = $candidate;
                                break;
                            }
                        }
                    }
                }

                if (! $name || ! $this->isValidItemName($name)) {
                    continue;
                }

                // Description
                $descNode = $xpath->query('.//p|.//div[contains(@class, "text")]|.//div[contains(@class, "desc")]|.//div[contains(@class, "detail")]', $card);
                $desc = ($descNode && $descNode->length > 0) ? trim(strip_tags($descNode->item(0)->textContent)) : null;

                // Price
                $priceNode = $xpath->query('.//*[contains(@class, "price") or contains(@class, "fee") or contains(@class, "cost")]', $card);
                $price = null;
                if ($priceNode && $priceNode->length > 0) {
                    $priceTxt = trim($priceNode->item(0)->textContent);
                    if (preg_match('/(?:[\$£€]|EGP|LE|SAR|USD|ج\.م|جنيه|ريال)\s*([0-9]+(?:\.[0-9]+)?)/i', $priceTxt, $m)) {
                        $price = $m[1];
                    } elseif (preg_match('/^[0-9]+(?:\.[0-9]+)?$/', $priceTxt, $m)) {
                        $price = $m[0];
                    }
                }

                // Image
                $imgNode = $xpath->query('.//img[not(contains(@src, "default.png")) and not(contains(@src, "icon")) and not(contains(@src, "arrow"))]', $card);
                $img = ($imgNode && $imgNode->length > 0) ? $imgNode->item(0)->getAttribute('src') : null;

                $items[] = [
                    'id' => $idx + 1,
                    'name' => $name,
                    'description' => $desc,
                    'price' => $price,
                    'image' => $img,
                ];
            }
        }

        return $this->deduplicateItemsByName($items);
    }

    /**
     * Deduplicate items by normalized name.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    protected function deduplicateItemsByName(array $items): array
    {
        $seen = [];
        $unique = [];
        foreach ($items as $item) {
            $key = mb_strtolower(trim($item['name'] ?? ''));
            if ($key !== '' && ! isset($seen[$key])) {
                $seen[$key] = true;
                $unique[] = $item;
            }
        }

        return array_values($unique);
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
