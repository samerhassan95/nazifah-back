<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$routesJsonPath = $projectRoot . '/storage/app/routes.json';

$routesJson = shell_exec('php ' . escapeshellarg($projectRoot . '/artisan') . ' route:list --json');
if (! is_string($routesJson) || trim($routesJson) === '') {
    if (file_exists($routesJsonPath)) {
        $routesJson = file_get_contents($routesJsonPath);
    } else {
        fwrite(STDERR, "Unable to export routes.\n");
        exit(1);
    }
}

$routesJson = preg_replace('/^\xEF\xBB\xBF/', '', $routesJson) ?? $routesJson;
$routesJson = preg_replace('/^\xFF\xFE/', '', $routesJson) ?? $routesJson;
$routes = json_decode($routesJson, true, 512, JSON_THROW_ON_ERROR);

$collection = [
    'info' => [
        '_postman_id' => 'nazefah-api-collection',
        'name' => 'Nazefah API',
        'description' => 'Auto-generated Postman collection for all Nazefah / Nathefah Laravel API endpoints.',
        'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
    ],
    'auth' => [
        'type' => 'noauth',
    ],
    'variable' => [
        ['key' => 'base_url', 'value' => 'http://127.0.0.1:8000'],
        ['key' => 'admin_token', 'value' => ''],
        ['key' => 'vendor_token', 'value' => ''],
        ['key' => 'driver_token', 'value' => ''],
        ['key' => 'user_token', 'value' => ''],
        ['key' => 'Accept-Language', 'value' => 'en'],
    ],
    'item' => [],
];

$folders = [];

foreach ($routes as $route) {
    $uri = $route['uri'];

    if (! str_starts_with($uri, 'api/')) {
        continue;
    }

    $methods = explode('|', $route['method']);
    $methods = array_values(array_filter($methods, fn (string $m) => $m !== 'HEAD'));
    if ($methods === []) {
        continue;
    }

    $folderPath = resolveFolderPath($uri);
    $folderKey = implode('/', $folderPath);
    $folders[$folderKey] ??= [
        'name' => end($folderPath) ?: 'API',
        'path' => $folderPath,
        'items' => [],
    ];

    foreach ($methods as $method) {
        $requestName = buildRequestName($method, $uri, $route['name'], $route['action']);
        $folders[$folderKey]['items'][] = buildRequestItem(
            $requestName,
            $method,
            $uri,
            $route['middleware'] ?? [],
            $route['action'] ?? ''
        );
    }
}

uksort($folders, fn (string $a, string $b) => strcmp($a, $b));

$collection['item'] = buildFolderTree(array_values($folders));

$outputPath = $projectRoot . '/Nazefah-API.postman_collection.json';
file_put_contents(
    $outputPath,
    json_encode($collection, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL
);

$environment = [
    'id' => 'nazefah-local-env',
    'name' => 'Nazefah Local',
    'values' => [
        ['key' => 'base_url', 'value' => 'http://127.0.0.1:8000', 'type' => 'default', 'enabled' => true],
        ['key' => 'admin_token', 'value' => '', 'type' => 'secret', 'enabled' => true],
        ['key' => 'vendor_token', 'value' => '', 'type' => 'secret', 'enabled' => true],
        ['key' => 'driver_token', 'value' => '', 'type' => 'secret', 'enabled' => true],
        ['key' => 'user_token', 'value' => '', 'type' => 'secret', 'enabled' => true],
        ['key' => 'Accept-Language', 'value' => 'en', 'type' => 'default', 'enabled' => true],
    ],
    '_postman_variable_scope' => 'environment',
    '_postman_exported_at' => gmdate('Y-m-d\TH:i:s\Z'),
    '_postman_exported_using' => 'generate_postman_collection.php',
];

$envPath = $projectRoot . '/Nazefah-Local.postman_environment.json';
file_put_contents(
    $envPath,
    json_encode($environment, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL
);

$requestCount = array_sum(array_map(fn (array $folder) => count($folder['items']), $folders));

echo "Generated {$requestCount} requests in " . count($folders) . " folders.\n";
echo "Collection: {$outputPath}\n";
echo "Environment: {$envPath}\n";

/**
 * @return list<string>
 */
function resolveFolderPath(string $uri): array
{
    $parts = explode('/', $uri);

    if (($parts[0] ?? '') === 'api' && ($parts[1] ?? '') === 'v1') {
        $surface = $parts[2] ?? 'general';
        $group = $parts[3] ?? 'root';

        if ($group === 'root') {
            return ['API v1', ucfirst($surface), 'General'];
        }

        return ['API v1', ucfirst($surface), humanize($group)];
    }

    if (($parts[0] ?? '') === 'api') {
        return ['API', humanize($parts[1] ?? 'general')];
    }

    return ['API', 'Other'];
}

function humanize(string $value): string
{
    $value = str_replace(['-', '_'], ' ', $value);

    return ucwords($value);
}

function buildRequestName(string $method, string $uri, ?string $name, string $action): string
{
    if ($name) {
        $short = str_replace(['api.', 'api.api.', 'user.', 'admin.', 'vendor.', 'driver.'], '', $name);
        $short = trim(str_replace('.', ' / ', $short));

        if ($short !== '') {
            return strtoupper($method) . ' ' . $short;
        }
    }

    $pos = strpos($action, '@');
    $actionShort = basename(str_replace('\\', '/', $pos === false ? $action : substr($action, 0, $pos)));
    $actionMethod = $pos === false ? '' : substr($action, $pos + 1);

    return strtoupper($method) . ' ' . $uri . ($actionMethod ? " ({$actionShort}@{$actionMethod})" : '');
}

/**
 * @param list<string> $middleware
 * @return array<string, mixed>
 */
function buildRequestItem(string $name, string $method, string $uri, array $middleware, string $action): array
{
    $headers = [
        ['key' => 'Accept', 'value' => 'application/json'],
        ['key' => 'Accept-Language', 'value' => '{{Accept-Language}}'],
    ];

    $auth = detectAuth($middleware, $uri);
    if ($auth !== null) {
        $headers[] = ['key' => 'Authorization', 'value' => 'Bearer {{' . $auth . '_token}}'];
    }

    $body = buildSampleBody($method, $uri, $action);

    $item = [
        'name' => $name,
        'request' => [
            'method' => $method,
            'header' => $headers,
            'url' => buildUrl($uri),
            'description' => trim("Route: /{$uri}\nAction: {$action}\nMiddleware: " . implode(', ', $middleware)),
        ],
        'response' => [],
    ];

    if ($body !== null) {
        $item['request']['header'][] = ['key' => 'Content-Type', 'value' => 'application/json'];
        $item['request']['body'] = $body;
    }

    $testScript = buildAuthTestScript($uri);
    if ($testScript !== null) {
        $item['event'] = [[
            'listen' => 'test',
            'script' => [
                'type' => 'text/javascript',
                'exec' => $testScript,
            ],
        ]];
    }

    return $item;
}

/**
 * @return array<string, mixed>
 */
function buildUrl(string $uri): array
{
    $pathParts = explode('/', $uri);
    $postmanPath = [];

    foreach ($pathParts as $part) {
        if (preg_match('/^\{(.+)\}$/', $part, $matches)) {
            $param = $matches[1];
            $postmanPath[] = ':' . $param;
        } else {
            $postmanPath[] = $part;
        }
    }

    $rawPath = implode('/', $postmanPath);

    return [
        'raw' => '{{base_url}}/' . $rawPath,
        'host' => ['{{base_url}}'],
        'path' => $postmanPath,
    ];
}

/**
 * @param list<string> $middleware
 */
function detectAuth(array $middleware, string $uri): ?string
{
    foreach ($middleware as $entry) {
        if (preg_match('/auth:([a-z]+)/', $entry, $matches)) {
            return mapAuthGuardToToken($matches[1]);
        }
    }

    if (str_contains($uri, '/admin/') && ! str_contains($uri, '/auth/login')) {
        foreach ($middleware as $entry) {
            if (str_contains($entry, 'Authenticate') && str_contains($uri, '/admin/')) {
                return 'admin';
            }
        }
    }

    if (preg_match('#api/v1/(user|vendor|driver)/#', $uri, $matches)) {
        foreach ($middleware as $entry) {
            if (str_contains($entry, 'Authenticate') && ! isPublicRoute($uri)) {
                return $matches[1] === 'user' ? 'user' : $matches[1];
            }
        }
    }

    return null;
}

function mapAuthGuardToToken(string $guard): string
{
    return match ($guard) {
        'client' => 'user',
        default => $guard,
    };
}

function isPublicRoute(string $uri): bool
{
    $publicPatterns = [
        '/auth/login',
        '/auth/register',
        '/auth/send-otp',
        '/auth/verify-otp',
        '/auth/resend-otp',
        '/auth/fingerprint',
        '/auth/reset-password',
        '/home/',
        '/zones',
        '/payment-methods',
        '/locations/validate-zone',
        '/branches/all',
        '/departments',
        '/ads',
        '/slider',
        '/best-laundries',
    ];

    foreach ($publicPatterns as $pattern) {
        if (str_contains($uri, trim($pattern, '/'))) {
            return true;
        }
    }

    return false;
}

/**
 * @return list<string>|null
 */
function buildAuthTestScript(string $uri): ?array
{
    $tokenVar = match (true) {
        str_contains($uri, 'admin/auth/login') => 'admin_token',
        str_contains($uri, 'vendor/auth/login') => 'vendor_token',
        str_contains($uri, 'driver/auth/login') => 'driver_token',
        str_contains($uri, 'user/auth/verify-otp') => 'user_token',
        default => null,
    };

    if ($tokenVar === null) {
        return null;
    }

    return [
        'const json = pm.response.json();',
        'const token = json?.data?.token || json?.token || json?.data?.access_token;',
        'if (token) {',
        "    pm.collectionVariables.set('{$tokenVar}', token);",
        "    pm.environment.set('{$tokenVar}', token);",
        '}',
    ];
}

/**
 * @return array<string, mixed>|null
 */
function buildSampleBody(string $method, string $uri, string $action): ?array
{
    if (! in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
        return null;
    }

    $payload = match (true) {
        str_contains($uri, 'admin/auth/login') => [
            'email' => 'admin@example.com',
            'password' => 'password123',
        ],
        str_contains($uri, 'vendor/auth/login') => [
            'phone' => '966500000001',
            'password' => 'password123',
        ],
        str_contains($uri, 'driver/auth/login') => [
            'phone' => '966500000001',
            'password' => 'password123',
        ],
        str_contains($uri, 'user/auth/send-otp') => [
            'phone' => '966500000001',
        ],
        str_contains($uri, 'user/auth/verify-otp') => [
            'phone' => '966500000001',
            'otp' => '123456',
        ],
        str_contains($uri, 'user/auth/resend-otp') => [
            'phone' => '966500000001',
        ],
        str_contains($uri, 'auth/send-otp') => [
            'phone' => '966500000001',
        ],
        str_contains($uri, 'auth/verify-otp') => [
            'phone' => '966500000001',
            'otp' => '123456',
        ],
        str_contains($uri, 'locations/validate-zone') => [
            'latitude' => 24.7136,
            'longitude' => 46.6753,
        ],
        default => [
            'example_key' => 'example_value',
        ],
    };

    return [
        'mode' => 'raw',
        'raw' => json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'options' => [
            'raw' => [
                'language' => 'json',
            ],
        ],
    ];
}

/**
 * @param list<array{name:string,path:list<string>,items:list<array<string,mixed>>}> $folders
 * @return list<array<string,mixed>>
 */
function buildFolderTree(array $folders): array
{
    $tree = [];

    foreach ($folders as $folder) {
        $current = &$tree;

        foreach ($folder['path'] as $index => $segment) {
            $found = false;

            foreach ($current as &$node) {
                if (($node['name'] ?? null) === $segment && isset($node['item']) && ! isset($node['request'])) {
                    $current = &$node['item'];
                    $found = true;
                    break;
                }
            }
            unset($node);

            if (! $found) {
                $newNode = [
                    'name' => $segment,
                    'item' => [],
                ];
                $current[] = $newNode;
                $current = &$current[array_key_last($current)]['item'];
            }
        }

        foreach ($folder['items'] as $item) {
            $current[] = $item;
        }
    }

    return $tree;
}
