<?php declare(strict_types=1);

// Router for `php -S`: a stand-in beliq API that answers from a script of
// responses. BELIQ_STUB_DIR holds responses.json, a list of
// {"status": int, "headers": {name: value}, "body": string}; each request takes
// the next entry and the last one repeats. Every request is appended to
// requests.log as one JSON line, so a test can count the calls and their times.

$dir = getenv('BELIQ_STUB_DIR');
if ($dir === false || $dir === '') {
    http_response_code(500);
    echo 'BELIQ_STUB_DIR is not set';

    return true;
}

$lock = fopen($dir . '/lock', 'c');
flock($lock, LOCK_EX);

$responses = json_decode((string) file_get_contents($dir . '/responses.json'), true);
$counter = is_file($dir . '/counter') ? (int) file_get_contents($dir . '/counter') : 0;
file_put_contents($dir . '/counter', (string) ($counter + 1));
file_put_contents($dir . '/requests.log', json_encode([
    'n' => $counter,
    'at' => microtime(true),
    'method' => $_SERVER['REQUEST_METHOD'],
    'path' => $_SERVER['REQUEST_URI'],
    'body' => file_get_contents('php://input'),
]) . "\n", FILE_APPEND);

flock($lock, LOCK_UN);

$response = $responses[min($counter, count($responses) - 1)];
if (isset($response['sleep'])) {
    sleep((int) $response['sleep']);
}
http_response_code((int) $response['status']);
foreach ($response['headers'] ?? [] as $name => $value) {
    header($name . ': ' . $value);
}
echo $response['body'] ?? '';

return true;
