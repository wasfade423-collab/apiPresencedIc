<?php

declare(strict_types=1);

require_once __DIR__ . '/routes.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function applyCorsHeaders(): void
{
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Requested-With');
    header('Access-Control-Max-Age: 86400');
}

function sendJson(int $statusCode, array $payload): never
{
    applyCorsHeaders();
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method === 'OPTIONS') {
    applyCorsHeaders();
    header('Allow: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    http_response_code(204);
    exit;
}

$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($requestUri, PHP_URL_PATH);
if (!is_string($path)) {
    $path = '/';
}

if (str_starts_with($path, '/index.php/')) {
    $path = substr($path, strlen('/index.php'));
}
if ($path === '/index.php') {
    $path = '/';
}
if (str_starts_with($path, '/api/')) {
    $path = substr($path, strlen('/api'));
} elseif ($path === '/api') {
    $path = '/';
}
$path = rtrim($path, '/') ?: '/';

$rawBody = file_get_contents('php://input');
try {
    if ($rawBody === false || $rawBody === '') {
        $body = [];
    } else {
        $decodedBody = json_decode($rawBody, false, 512, JSON_THROW_ON_ERROR);
        if (!$decodedBody instanceof stdClass) {
            throw new ApiException(400, 'Le corps de la requête doit être un objet JSON.');
        }
        $body = (array) $decodedBody;
    }

    $headers = function_exists('getallheaders') ? getallheaders() : [];
    [$statusCode, $response] = dispatchRequest($method, $path, $body, is_array($headers) ? $headers : []);
    sendJson($statusCode, ['code' => $statusCode, 'response' => $response]);
} catch (ApiException $exception) {
    foreach ($exception->headers as $name => $value) {
        header(sprintf('%s: %s', $name, $value));
    }
    sendJson($exception->statusCode, [
        'code' => $exception->statusCode,
        'response' => ['error' => $exception->getMessage()],
    ]);
} catch (JsonException $exception) {
    sendJson(400, [
        'code' => 400,
        'response' => ['error' => 'Le corps de la requête contient un JSON invalide.'],
    ]);
} catch (Throwable $exception) {
    error_log(sprintf(
        'API error (%s): %s in %s:%d',
        $exception::class,
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine()
    ));
    sendJson(500, [
        'code' => 500,
        'response' => ['error' => 'Une erreur interne est survenue.' . $exception->getMessage()],
    ]);
}
