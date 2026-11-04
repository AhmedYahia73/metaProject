<?php

use App\Services\ApiDiscoveryService;
use App\trait\FormatsChatLinks;
use Illuminate\Http\Client\Request;
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
const productUrl = "/admin/products";
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
        'https://bcknd.foodtest.online/admin/products' => Http::response($productsData, 200),
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

test('discoverAndFetch calls logout endpoint gracefully after fetching products', function () {
    $loginHtml = '<script src="/assets/app.js"></script>';
    $jsBundle = <<<'JS'
const baseURL = "https://bcknd.resort.test";
const loginUrl = "/api/auth/login";
const logoutUrl = "https://bcknd.resort.test/api/logout";
const ownersUrl = "/api/owners";
JS;

    $ownersData = [
        'owners' => [
            'data' => [
                ['id' => 10, 'name' => 'Owner Alpha', 'image_link' => 'https://img.test/alpha.png'],
            ],
        ],
    ];

    $logoutCalled = false;

    Http::fake([
        'https://resort.test/login' => Http::response($loginHtml, 200),
        'https://resort.test/assets/app.js' => Http::response($jsBundle, 200),
        'https://bcknd.resort.test/api/auth/login' => Http::response([
            'token' => 'jwt_resort_token_1234567890',
        ], 200),
        'https://bcknd.resort.test/api/owners' => Http::response($ownersData, 200),
        'https://bcknd.resort.test/api/logout' => function () use (&$logoutCalled) {
            $logoutCalled = true;

            return Http::response(['message' => 'Logged out'], 200);
        },
    ]);

    $service = new ApiDiscoveryService;
    $result = $service->discoverAndFetch(
        'https://resort.test/login',
        'admin@resort.test',
        'secret',
        'https://resort.test/owners'
    );

    expect($result)->not->toBeNull();
    expect($result['products'])->toHaveCount(1);
    expect($result['products'][0]['name'])->toBe('Owner Alpha');
    expect($result['products'][0]['image'])->toBe('https://img.test/alpha.png');
    expect($logoutCalled)->toBeTrue();
});

test('discoverAndFetch overrides another device lockout with force login flags', function () {
    $loginHtml = '<script src="/assets/app.js"></script>';
    $jsBundle = <<<'JS'
const baseURL = "https://bcknd.lockout.test";
const loginUrl = "/api/login";
const itemsUrl = "/api/items";
JS;

    $itemsData = [
        'data' => [
            ['id' => 1, 'name' => 'Item After Force Login'],
        ],
    ];

    Http::fake([
        'https://lockout.test/login' => Http::response($loginHtml, 200),
        'https://lockout.test/assets/app.js' => Http::response($jsBundle, 200),
        'https://bcknd.lockout.test/api/login' => function (Request $request) {
            $data = $request->data();
            // Normal login fails with "You log from another device"
            if (! isset($data['force']) && ! isset($data['force_login'])) {
                return Http::response(['errors' => 'You log from another device'], 400);
            }

            // Forced login succeeds
            return Http::response(['token' => 'jwt_force_token_1234567890'], 200);
        },
        'https://bcknd.lockout.test/api/items' => Http::response($itemsData, 200),
        'https://bcknd.lockout.test/api/logout' => Http::response([], 200),
    ]);

    $service = new ApiDiscoveryService;
    $result = $service->discoverAndFetch(
        'https://lockout.test/login',
        'admin@lockout.test',
        'pass',
        'https://lockout.test/items'
    );

    expect($result)->not->toBeNull();
    expect($result['products'])->toHaveCount(1);
    expect($result['products'][0]['name'])->toBe('Item After Force Login');
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

test('discoverAndFetch harvests all read apis into array of arrays when keyword matching yields no items', function () {
    $loginHtml = '<script src="/assets/app.js"></script>';
    // Bundle contains baseUrl, login endpoint, and multiple read endpoints (template literals and paths)
    $jsBundle = <<<'JS'
const base = "https://bcknd.randomspa.test";
const loginUrl = "/api/v1/auth/login";
const logoutUrl = "/api/v1/auth/logout";
function loadPosts() { return axios.get(`${base}/village/post/new_view?page=1`); }
function loadOwners() { return axios.get(`${base}/village/owner/owners`); }
function loadApartments() { return axios.get(`${base}/village/appartment`); }
JS;

    $postsData = [
        'posts' => [
            'data' => [
                ['id' => 101, 'title' => 'Post One', 'description' => 'First announcement'],
                ['id' => 102, 'title' => 'Post Two', 'description' => 'Second announcement'],
            ],
        ],
    ];

    $ownersData = [
        'owners' => [
            ['id' => 201, 'name' => 'Owner Alpha', 'phone' => '01000000001'],
        ],
    ];

    $apartmentsData = [
        'data' => [
            ['id' => 301, 'name' => 'Apartment 101', 'price' => 5000],
        ],
    ];

    $logoutCalled = false;

    Http::fake([
        'https://randomspa.test/login' => Http::response($loginHtml, 200),
        'https://randomspa.test/assets/app.js' => Http::response($jsBundle, 200),
        'https://bcknd.randomspa.test/api/v1/auth/login' => Http::response([
            'token' => 'jwt_token_sample_harvest_12345',
        ], 200),
        // Products link with arbitrary/unmatched keyword returns 404
        'https://bcknd.randomspa.test/arbitrary_test_route' => Http::response([], 404),
        'https://bcknd.randomspa.test/api/arbitrary_test_route' => Http::response([], 404),
        'https://bcknd.randomspa.test/village/arbitrary_test_route' => Http::response([], 404),
        // Discovered read endpoints return valid data
        'https://bcknd.randomspa.test/village/post/new_view' => Http::response($postsData, 200),
        'https://bcknd.randomspa.test/village/owner/owners' => Http::response($ownersData, 200),
        'https://bcknd.randomspa.test/village/appartment' => Http::response($apartmentsData, 200),
        'https://bcknd.randomspa.test/api/v1/auth/logout' => function () use (&$logoutCalled) {
            $logoutCalled = true;

            return Http::response(['message' => 'Logged out'], 200);
        },
    ]);

    $service = new ApiDiscoveryService;
    // User passes arbitrary testing link that does NOT match endpoints directly
    $result = $service->discoverAndFetch(
        'https://randomspa.test/login',
        'tester@randomspa.test',
        'password123',
        'https://randomspa.test/arbitrary_test_route'
    );

    expect($result)->not->toBeNull();
    expect($result['meta']['source'])->toBe('api_discovery_harvest');
    expect($result['products'])->toBeArray();

    // Verify it is an array of arrays keyed by endpoint name
    expect(array_is_list($result['products']))->toBeFalse();
    expect($result['products'])->toHaveKey('village/post/new_view');
    expect($result['products'])->toHaveKey('village/owner/owners');
    expect($result['products'])->toHaveKey('village/appartment');

    expect($result['products']['village/post/new_view'])->toHaveCount(2);
    expect($result['products']['village/post/new_view'][0]['name'])->toBe('Post One');

    expect($result['products']['village/owner/owners'])->toHaveCount(1);
    expect($result['products']['village/owner/owners'][0]['name'])->toBe('Owner Alpha');

    expect($result['products']['village/appartment'])->toHaveCount(1);
    expect($result['products']['village/appartment'][0]['name'])->toBe('Apartment 101');

    expect($logoutCalled)->toBeTrue();
});

test('formatProductsCatalog formats multi-endpoint array of arrays with section headers', function () {
    $formatter = new class
    {
        use FormatsChatLinks;
    };

    $multiData = [
        'village/post/new_view' => [
            ['id' => 1, 'name' => 'First Post', 'description' => 'Post detail'],
        ],
        'village/owner/owners' => [
            ['id' => 2, 'name' => 'Owner John Doe'],
        ],
    ];

    $formatted = $formatter->formatProductsCatalog($multiData);

    expect($formatted)->toContain('### قسم [village/post/new_view]:');
    expect($formatted)->toContain('First Post');
    expect($formatted)->toContain('### قسم [village/owner/owners]:');
    expect($formatted)->toContain('Owner John Doe');
});
