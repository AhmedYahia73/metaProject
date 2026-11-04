<?php

use App\Services\ApiDiscoveryService;
use Illuminate\Support\Facades\Http;

test('api discovery extracts js bundle urls from html including scripts and preloads', function () {
    $html = <<<'HTML'
<!DOCTYPE html>
<html>
<head>
    <link rel="modulepreload" crossorigin href="/assets/vendor-C93kLmPq.js">
    <link rel="stylesheet" href="/assets/index-style.css">
    <script type="module" crossorigin src="/assets/index-DfhW3k1L.js"></script>
    <script src="https://cdn.example.com/analytics.js"></script>
</head>
<body><div id="app"></div></body>
</html>
HTML;

    Http::fake([
        'https://shop.test/login' => Http::response($html, 200),
    ]);

    $service = new ApiDiscoveryService;
    $reflection = new ReflectionClass($service);
    $method = $reflection->getMethod('extractJsBundleUrls');
    $method->setAccessible(true);

    $urls = $method->invoke($service, 'https://shop.test/login');

    expect($urls)->toBeArray();
    expect($urls)->toContain('https://shop.test/assets/index-DfhW3k1L.js');
    expect($urls)->toContain('https://shop.test/assets/vendor-C93kLmPq.js');
    expect($urls)->toContain('https://cdn.example.com/analytics.js');
    // Index bundle should be prioritized before vendor bundle
    expect($urls[0])->toBe('https://shop.test/assets/index-DfhW3k1L.js');
});

test('api discovery extracts base urls from minified javascript', function () {
    $js = <<<'JS'
var e=axios.create({baseURL:"https://bcknd.myshop.com",timeout:1e4});
const api="https://app.bosta.co/api/v2";
const config={API_URL:"https://api.myshop.com"};
JS;

    $service = new ApiDiscoveryService;
    $reflection = new ReflectionClass($service);
    $method = $reflection->getMethod('extractBaseUrlsFromJs');
    $method->setAccessible(true);

    $urls = $method->invoke($service, $js);

    expect($urls)->toContain('https://bcknd.myshop.com');
    expect($urls)->toContain('https://api.myshop.com');
});

test('api discovery selects best base url matching target domain', function () {
    $service = new ApiDiscoveryService;
    $reflection = new ReflectionClass($service);
    $method = $reflection->getMethod('selectBestBaseUrl');
    $method->setAccessible(true);

    $candidates = [
        'https://app.bosta.co/api/v2',
        'https://bcknd.myshop.com',
        'https://cdn.jsdelivr.net',
    ];

    $best = $method->invoke($service, $candidates, 'https://myshop.com/login');

    expect($best)->toBe('https://bcknd.myshop.com');
});

test('api discovery extracts token from various json response formats', function () {
    $service = new ApiDiscoveryService;
    $reflection = new ReflectionClass($service);
    $method = $reflection->getMethod('extractToken');
    $method->setAccessible(true);

    // Format 1: data.token
    expect($method->invoke($service, ['data' => ['token' => 'jwt_token_sample_1234567890']]))
        ->toBe('jwt_token_sample_1234567890');

    // Format 2: direct token
    expect($method->invoke($service, ['token' => 'direct_jwt_token_1234567890']))
        ->toBe('direct_jwt_token_1234567890');

    // Format 3: data.access_token
    expect($method->invoke($service, ['data' => ['access_token' => 'sanctum_token_1234567890']]))
        ->toBe('sanctum_token_1234567890');

    // Format 4: data.data.token (nested)
    expect($method->invoke($service, ['data' => ['data' => ['token' => 'deep_nested_token_1234567890']]]))
        ->toBe('deep_nested_token_1234567890');

    // Format 5: invalid or null
    expect($method->invoke($service, ['status' => 'failed']))
        ->toBeNull();
});

test('api discovery extracts products list from nested response formats', function () {
    $service = new ApiDiscoveryService;
    $reflection = new ReflectionClass($service);
    $method = $reflection->getMethod('extractProductsList');
    $method->setAccessible(true);

    $sampleItems = [
        ['id' => 1, 'name' => 'وجبة شاورما'],
        ['id' => 2, 'name' => 'برجر دجاج'],
    ];

    // Format 1: Flat list
    expect($method->invoke($service, $sampleItems))->toHaveCount(2);

    // Format 2: data wrapper
    expect($method->invoke($service, ['data' => $sampleItems]))->toHaveCount(2);

    // Format 3: data.data (paginated)
    expect($method->invoke($service, ['data' => ['data' => $sampleItems, 'total' => 2]]))->toHaveCount(2);

    // Format 4: products key
    expect($method->invoke($service, ['products' => $sampleItems]))->toHaveCount(2);

    // Format 5: foods key
    expect($method->invoke($service, ['foods' => $sampleItems]))->toHaveCount(2);

    // Format 6: data.data.data (triple nested)
    expect($method->invoke($service, ['data' => ['data' => ['data' => $sampleItems]]]))->toHaveCount(2);
});

test('discoverAndFetch successfully performs end to end SPA discovery and scraping', function () {
    $loginHtml = <<<'HTML'
<!DOCTYPE html>
<html>
<head><script type="module" src="/assets/index-test.js"></script></head>
<body><div id="root"></div></body>
</html>
HTML;

    $jsBundle = <<<'JS'
const api = axios.create({ baseURL: "https://bcknd.foodtest.online" });
const loginUrl = "/api/admin/auth/login";
const productUrl = "/admin/product";
JS;

    $productsData = [
        'data' => [
            ['id' => 101, 'name' => 'بيتزا مشكل جبن', 'price' => 150],
            ['id' => 102, 'name' => 'كريب دجاج كرانشي', 'price' => 90],
        ],
    ];

    Http::fake([
        // Step 1: HTML page
        'https://foodtest.online/login' => Http::response($loginHtml, 200),
        // Step 2: JS bundle
        'https://foodtest.online/assets/index-test.js' => Http::response($jsBundle, 200),
        // Step 3: Login endpoint
        'https://bcknd.foodtest.online/api/admin/auth/login' => Http::response([
            'data' => ['token' => 'sample_auth_token_9876543210'],
        ], 200),
        // Step 4: Products endpoint
        'https://bcknd.foodtest.online/admin/product' => Http::response($productsData, 200),
    ]);

    $service = new ApiDiscoveryService;
    $result = $service->discoverAndFetch(
        'https://foodtest.online/login',
        'admin@foodtest.online',
        'password123',
        'https://foodtest.online/products'
    );

    expect($result)->not->toBeNull();
    expect($result['products'])->toHaveCount(2);
    expect($result['products'][0]['name'])->toBe('بيتزا مشكل جبن');
    expect($result['products'][1]['price'])->toBe(90);
    expect($result['meta']['source'])->toBe('api_discovery');
    expect($result['meta']['base_url'])->toBe('https://bcknd.foodtest.online');
});

test('discoverAndFetch discovers educational platforms with courses and categories', function () {
    $loginHtml = <<<'HTML'
<!DOCTYPE html>
<html>
<head><script type="module" src="/assets/index-maths.js"></script></head>
<body><div id="root"></div></body>
</html>
HTML;

    $jsBundle = <<<'JS'
const api = axios.create({ baseURL: "https://bcknd.mathstest.net" });
const loginUrl = "/api/admin/auth/login";
const coursesUrl = "/api/admin/courses";
const categoryUrl = "/api/admin/category";
JS;

    $coursesData = [
        'status' => true,
        'courses' => [
            ['course_id' => 1, 'course_name' => 'رياضيات الصف الثالث الثانوي', 'fee' => 500],
            ['course_id' => 2, 'course_name' => 'فيزياء لغات', 'fee' => 450],
        ],
    ];

    Http::fake([
        'https://admin.mathstest.net' => Http::response($loginHtml, 200),
        'https://admin.mathstest.net/assets/index-maths.js' => Http::response($jsBundle, 200),
        'https://bcknd.mathstest.net/api/admin/auth/login' => Http::response([
            'data' => ['token' => 'jwt_maths_token_1234567890'],
        ], 200),
        'https://bcknd.mathstest.net/api/admin/courses/categories' => Http::response([], 404),
        'https://bcknd.mathstest.net/api/courses/categories' => Http::response([], 404),
        'https://bcknd.mathstest.net/api/admin/categories' => Http::response([], 404),
        'https://bcknd.mathstest.net/admin/courses/categories' => Http::response([], 404),
        'https://bcknd.mathstest.net/api/categories' => Http::response([], 404),
        'https://bcknd.mathstest.net/courses/categories' => Http::response([], 404),
        'https://bcknd.mathstest.net/admin/courses/categories/add' => Http::response([], 404),
        'https://bcknd.mathstest.net/admin/categories' => Http::response([], 404),
        'https://bcknd.mathstest.net/categories' => Http::response([], 404),
        'https://bcknd.mathstest.net/api/admin/courses' => Http::response($coursesData, 200),
    ]);

    $service = new ApiDiscoveryService;
    $result = $service->discoverAndFetch(
        'https://admin.mathstest.net',
        'admin@mathstest.net',
        'secret123',
        'https://admin.mathstest.net/admin/courses/categories'
    );

    expect($result)->not->toBeNull();
    expect($result['products'])->toHaveCount(2);
    expect($result['products'][0]['name'])->toBe('رياضيات الصف الثالث الثانوي');
    expect($result['products'][0]['price'])->toBe(500);
    expect($result['products'][1]['name'])->toBe('فيزياء لغات');
    expect($result['products'][1]['price'])->toBe(450);
});

test('discoverAndFetch returns null safely when login page fails or is unreachable', function () {
    Http::fake([
        'https://offline.test/login' => Http::response('Server Error', 500),
    ]);

    $service = new ApiDiscoveryService;
    $result = $service->discoverAndFetch(
        'https://offline.test/login',
        'user',
        'pass',
        'https://offline.test/products'
    );

    expect($result)->toBeNull();
});
