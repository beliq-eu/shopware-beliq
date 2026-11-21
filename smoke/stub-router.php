<?php declare(strict_types=1);

// Router for `php -S`: a stand-in beliq API for `./run.sh stub`. It answers
// generate with a fixed document of the requested media type and validate with
// a green result, so the harness's Shopware steps can be exercised without a key
// or quota. A stub run therefore proves the store side only: the documents it
// stores are placeholders that no validator has read.

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

$json = static function (int $status, array $body): bool {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($body);

    return true;
};

if ($method === 'GET' && $path === '/health/live') {
    return $json(200, ['status' => 'ok']);
}

if (($_SERVER['HTTP_X_API_KEY'] ?? '') === '') {
    return $json(401, ['success' => false, 'error' => ['code' => 'UNAUTHORIZED', 'message' => 'X-API-Key missing']]);
}

if ($method === 'POST' && $path === '/v1/generate') {
    $body = json_decode((string) file_get_contents('php://input'), true);
    if (($body['output'] ?? 'xml') === 'pdf') {
        header('Content-Type: application/pdf');
        echo "%PDF-1.7\n% beliq smoke stub\n%%EOF\n";
    } else {
        header('Content-Type: application/xml');
        echo '<?xml version="1.0" encoding="UTF-8"?><stub standard="' . htmlspecialchars((string) ($body['standard'] ?? '')) . '"/>';
    }

    return true;
}

if ($method === 'POST' && $path === '/v1/validate') {
    return $json(200, ['success' => true, 'data' => [
        'valid' => true,
        'errors' => [],
        'warnings' => [],
        'format' => 'stub',
        'profileDetected' => 'stub',
    ]]);
}

return $json(404, ['success' => false, 'error' => ['code' => 'NOT_FOUND', 'message' => $method . ' ' . $path]]);
