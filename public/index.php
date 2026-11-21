<?php

declare(strict_types=1);

use YtUltra\YtUltraClient;
use YtUltra\YtUltraException;

ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once __DIR__ . '/../src/YtUltraException.php';
require_once __DIR__ . '/../src/YtUltraClient.php';

// ---------------------------------------------------------------------------
// CORS / baseline headers
// ---------------------------------------------------------------------------

$allowedOrigin = getenv('YTULTRA_CORS_ORIGIN');
header('Access-Control-Allow-Origin: ' . ($allowedOrigin !== false && $allowedOrigin !== '' ? $allowedOrigin : '*'));
header('Access-Control-Allow-Methods: GET, HEAD, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Range');
header('Access-Control-Expose-Headers: Content-Length, Content-Range, Content-Disposition');
header('X-Content-Type-Options: nosniff');

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Emit a JSON response and stop execution.
 *
 * @param array<string, mixed> $payload
 */
function respond(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
    );
    exit;
}

/**
 * Emit a structured error response and stop execution.
 */
function fail(
    string $message,
    int $status = 400,
    string $code = 'invalid_request',
    ?string $upstreamCode = null,
): never {
    $error = [
        'code' => $code,
        'message' => $message,
    ];

    if ($upstreamCode !== null && $upstreamCode !== '') {
        $error['upstream_code'] = $upstreamCode;
    }

    respond([
        'success' => false,
        'error' => $error,
    ], $status);
}

/**
 * Run an endpoint handler, mapping exceptions to JSON errors.
 */
function api(callable $handler): void
{
    try {
        $handler();
    } catch (YtUltraException $e) {
        $status = $e->getStatus();
        $code = $status >= 500 ? 'upstream_error' : 'invalid_request';

        if ($status === 504) {
            header('Retry-After: 5');
        } elseif ($status === 429) {
            header('Retry-After: 30');
            $code = 'rate_limited';
        }

        fail($e->getMessage(), $status, $code, $e->getUpstreamCode());
    } catch (Throwable $e) {
        error_log('[ytultra] ' . $e::class . ': ' . $e->getMessage());
        fail('Internal server error.', 500, 'internal_error');
    }
}

/**
 * Stream a remote media URL to the client, forwarding range requests.
 *
 * The direct googlevideo URLs returned by the upstream API are bound to the IP
 * address that requested them. Proxying through this endpoint makes the media
 * downloadable from any client, even though the file is fetched server-side.
 */
function streamRemote(string $url, string $filename): void
{
    $filename = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?: 'video.mp4';

    set_time_limit(0);
    ignore_user_abort(true);

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $status = 200;
    $headers = [];
    $started = false;
    $errorBody = '';

    $ch = curl_init($url);
    if ($ch === false) {
        fail('Unable to initialise the download stream.', 500, 'stream_error');
    }

    $requestHeaders = ['Accept: */*'];
    if (!empty($_SERVER['HTTP_RANGE']) && preg_match('/^bytes=\d*-\d*$/', $_SERVER['HTTP_RANGE']) === 1) {
        $requestHeaders[] = 'Range: ' . $_SERVER['HTTP_RANGE'];
    }

    curl_setopt_array($ch, [
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_ENCODING => 'identity',
        CURLOPT_BUFFERSIZE => 1048576,
        CURLOPT_HTTPHEADER => $requestHeaders,
        CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$status, &$headers): int {
            $length = strlen($line);
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $matches) === 1) {
                $status = (int) $matches[1];
                $headers = [];
            } elseif (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($name))] = trim($value);
            }

            return $length;
        },
        CURLOPT_WRITEFUNCTION => static function ($handle, string $data) use (
            &$status,
            &$headers,
            &$started,
            &$errorBody,
            $filename
        ): int {
            $length = strlen($data);

            if (!$started) {
                if ($status >= 400) {
                    $errorBody .= $data;

                    return $length;
                }

                http_response_code($status);

                foreach (['content-type', 'content-length', 'content-range', 'accept-ranges'] as $name) {
                    if (isset($headers[$name])) {
                        header(ucwords($name, '-') . ': ' . $headers[$name]);
                    }
                }

                header('Content-Disposition: attachment; filename="' . $filename . '"');
                header('Cache-Control: no-store');
                header('X-Accel-Buffering: no');
                $started = true;
            }

            echo $data;
            flush();

            return connection_aborted() ? 0 : $length;
        },
    ]);

    curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if (!$started && $status >= 400) {
        fail('The upstream media server returned HTTP ' . $status . '.', 502, 'upstream_error');
    }

    if (!$started && $errno !== 0) {
        fail('The download stream failed: ' . $error, 502, 'stream_error');
    }
}

// ---------------------------------------------------------------------------
// Client
// ---------------------------------------------------------------------------

$timeout = (int) (getenv('YTULTRA_TIMEOUT') ?: 30);
$connectTimeout = (int) (getenv('YTULTRA_CONNECT_TIMEOUT') ?: 10);
$streamTimeout = (int) (getenv('YTULTRA_STREAM_TIMEOUT') ?: 60);
$userAgent = getenv('YTULTRA_USER_AGENT');

$client = new YtUltraClient(
    $timeout,
    $connectTimeout,
    is_string($userAgent) && $userAgent !== '' ? $userAgent : YtUltraClient::DEFAULT_USER_AGENT,
    $streamTimeout
);

// ---------------------------------------------------------------------------
// Routing
// ---------------------------------------------------------------------------

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = '/' . trim(is_string($path) ? $path : '/', '/');

if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
    header('Allow: GET, HEAD, OPTIONS');
    fail('Method not allowed.', 405, 'method_not_allowed');
}

if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

switch ($path) {
    case '/':
        respond([
            'name' => 'YtUltra API',
            'version' => '1.1.0',
            'description' => 'Resolve direct YouTube download URLs through the unofficial ytultra.com backend.',
            'documentation' => 'https://github.com/aboodalajrad8/YT-Ultra-video-downloader#readme',
            'endpoints' => [
                [
                    'method' => 'GET',
                    'path' => '/health',
                    'description' => 'Liveness probe.',
                ],
                [
                    'method' => 'GET',
                    'path' => '/api/info',
                    'description' => 'Return metadata and every media variant for a YouTube URL.',
                    'parameters' => ['url' => 'required'],
                ],
                [
                    'method' => 'GET',
                    'path' => '/api/redirect',
                    'description' => '302 redirect to the best direct media URL.',
                    'parameters' => [
                        'url' => 'required',
                        'quality' => 'optional (e.g. 720p); best available when omitted',
                        'type' => 'optional: video (default) or audio',
                    ],
                ],
                [
                    'method' => 'GET',
                    'path' => '/api/download',
                    'description' => 'Stream the selected media file through this server.',
                    'parameters' => [
                        'url' => 'required',
                        'quality' => 'optional (e.g. 720p); best available when omitted',
                        'type' => 'optional: video (default) or audio',
                    ],
                ],
                [
                    'method' => 'GET',
                    'path' => '/api/description',
                    'description' => 'Return the title, full description and tags of a YouTube video.',
                    'parameters' => ['url' => 'required'],
                ],
                [
                    'method' => 'GET',
                    'path' => '/api/generate',
                    'description' => 'Generate a YouTube description or tag list from a topic.',
                    'parameters' => [
                        'type' => 'required: description or tag',
                        'input' => 'required: topic or video title (1..200 characters)',
                        'stream' => 'optional: 1 to receive Server-Sent Events token deltas',
                    ],
                ],
            ],
        ]);

    case '/health':
        respond([
            'status' => 'ok',
            'time' => gmdate('c'),
        ]);

    case '/api/info':
        api(static function () use ($client): void {
            $url = (string) ($_GET['url'] ?? '');
            $data = $client->fetch($url);

            respond([
                'success' => true,
                'data' => $data,
            ]);
        });
        break;

    case '/api/redirect':
        api(static function () use ($client): void {
            $url = (string) ($_GET['url'] ?? '');
            $quality = isset($_GET['quality']) ? (string) $_GET['quality'] : null;
            $type = (string) ($_GET['type'] ?? 'video');

            $data = $client->fetch($url);
            $media = $client->pickMedia($data['medias'], $quality, $type);

            if ($media === null || empty($media['url'])) {
                fail('The requested quality or type is not available.', 404, 'not_found');
            }

            header('Cache-Control: no-store');
            header('Location: ' . $media['url'], true, 302);
            exit;
        });
        break;

    case '/api/download':
        api(static function () use ($client): void {
            $url = (string) ($_GET['url'] ?? '');
            $quality = isset($_GET['quality']) ? (string) $_GET['quality'] : null;
            $type = (string) ($_GET['type'] ?? 'video');

            $data = $client->fetch($url);
            $media = $client->pickMedia($data['medias'], $quality, $type);

            if ($media === null || empty($media['url'])) {
                fail('The requested quality or type is not available.', 404, 'not_found');
            }

            $extension = (string) ($media['extension'] ?? '') ?: ($type === 'audio' ? 'm4a' : 'mp4');
            $qualityPart = (string) ($media['quality'] ?? 'best');
            $idPart = (string) ($data['id'] ?? 'video');
            $filename = sprintf('youtube-%s-%s.%s', $idPart, $qualityPart, $extension);

            streamRemote((string) $media['url'], $filename);
            exit;
        });
        break;

    case '/api/description':
        api(static function () use ($client): void {
            $url = (string) ($_GET['url'] ?? '');

            respond([
                'success' => true,
                'data' => $client->extractDescription($url),
            ]);
        });
        break;

    case '/api/generate':
        api(static function () use ($client): void {
            $type = strtolower(trim((string) ($_GET['type'] ?? '')));
            $input = trim((string) ($_GET['input'] ?? ''));
            $inputLength = function_exists('mb_strlen') ? mb_strlen($input, 'UTF-8') : strlen($input);

            if (!in_array($type, ['description', 'tag'], true)) {
                fail('The "type" parameter must be "description" or "tag".', 400, 'invalid_request');
            }

            if ($input === '' || $inputLength > YtUltraClient::MAX_INPUT_LENGTH) {
                fail(
                    'The "input" parameter must be between 1 and '
                    . YtUltraClient::MAX_INPUT_LENGTH . ' characters.',
                    400,
                    'invalid_request'
                );
            }

            $stream = filter_var($_GET['stream'] ?? false, FILTER_VALIDATE_BOOLEAN);

            if (!$stream) {
                $text = $client->generate($type, $input);

                respond([
                    'success' => true,
                    'data' => [
                        'type' => $type,
                        'input' => $input,
                        'text' => $text,
                        'length' => function_exists('mb_strlen')
                            ? mb_strlen($text, 'UTF-8')
                            : strlen($text),
                    ],
                ]);
            }

            set_time_limit(0);
            ignore_user_abort(true);

            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            header('Content-Type: text/event-stream; charset=utf-8');
            header('Cache-Control: no-cache, no-transform');
            header('X-Accel-Buffering: no');
            header('Connection: keep-alive');

            echo ": connected\n\n";
            flush();

            try {
                $client->generate($type, $input, static function (string $delta): void {
                    echo 'data: ' . json_encode(
                        ['delta' => $delta],
                        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                    ) . "\n\n";
                    flush();
                });
            } catch (Throwable $e) {
                $payload = ['error' => ['message' => $e->getMessage()]];
                if ($e instanceof YtUltraException && $e->getUpstreamCode() !== null) {
                    $payload['error']['upstream_code'] = $e->getUpstreamCode();
                }

                echo 'data: ' . json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";
            }

            echo "data: [DONE]\n\n";
            flush();
            exit;
        });
        break;

    default:
        fail('Endpoint not found.', 404, 'not_found');
}
