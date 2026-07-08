<?php

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$body = file_get_contents('php://input') ?: '';
$store = getenv('ELVEN_FAKE_COLLECTOR_STORE') ?: sys_get_temp_dir() . '/elven-fake-collector.jsonl';

if (!in_array($path, ['/v1/traces', '/v1/metrics', '/v1/logs'], true)) {
    http_response_code(404);
    echo "not found\n";
    return;
}

file_put_contents($store, json_encode([
    'path' => $path,
    'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
    'content_type' => $_SERVER['CONTENT_TYPE'] ?? '',
    'length' => strlen($body),
    'body_base64' => base64_encode($body),
], JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND);

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (str_contains($contentType, 'application/x-protobuf')) {
    http_response_code(200);
    return;
}

header('content-type: application/json');
echo '{}';
