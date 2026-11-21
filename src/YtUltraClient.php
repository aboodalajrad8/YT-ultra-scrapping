<?php

declare(strict_types=1);

namespace YtUltra;

use RuntimeException;
use Throwable;

/**
 * Client for the unofficial ytultra.com YouTube resolver.
 *
 * The upstream API is protected by a request signature built from an
 * obfuscated key that ships inside the website's JavaScript bundle. This class
 * reproduces that scheme (see README) and exposes a small, typed surface:
 *
 *   $client = new YtUltraClient();
 *   $data   = $client->fetch('https://www.youtube.com/watch?v=...');
 *   $url    = $client->getBestUrl('https://www.youtube.com/watch?v=...');
 *
 * Some videos are not ready for download on the first call: upstream answers
 * with code 5023 and expects the client to create a resolve task, poll it and
 * then ask for the download again. That handshake is handled transparently.
 */
final class YtUltraClient
{
    private const HOST = 'https://api.ytultra.com';
    private const DOWNLOAD_PATH = '/ikool/youtube/download';
    private const RESOLVE_PATH = '/ikool/youtube/download/resolve';
    private const DESCRIPTION_PATH = '/ikool/youtube/descriptionExtractor';
    private const GENERATE_PATH = '/ikool/youtube/generate';

    /**
     * Longest text accepted by the AI description/tag generator.
     *
     * The upstream endpoint rejects anything longer than 200 characters with
     * an empty HTTP 400, so the limit is enforced here to return a clear error.
     */
    public const MAX_INPUT_LENGTH = 200;

    private const ORIGIN = 'https://www.ytultra.com';
    private const REFERER = 'https://www.ytultra.com/';
    private const LANG = 'en';
    private const APP_ID = '2';

    /** Successful upstream response code. */
    private const CODE_OK = '0000';

    /** Upstream asks the client to create a resolve task and poll it. */
    private const CODE_PROCESSING_REQUIRED = '5023';

    /** Delay between resolve polls, matching the website (about 2.5 seconds). */
    private const POLL_INTERVAL_US = 2_500_000;

    /** Seconds kept free at the end of the budget for the final download retry. */
    private const RETRY_RESERVE = 5.0;

    public const DEFAULT_USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
        . 'AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36';

    /**
     * Obfuscated key bytes extracted from the site bundle.
     *
     * @var int[]
     */
    private const KEY_PARTS = [
        81, 126, 91, 189, 251, 6, 125, 91, 232, 23, 172, 71, 137,
        207, 234, 102, 104, 193, 132, 209, 111, 228, 140,
    ];

    /**
     * XOR mask applied cyclically to the key bytes.
     *
     * @var int[]
     */
    private const KEY_MASK = [55, 66, 156];

    /** Expected checksum of the decoded key, used as a sanity check. */
    private const KEY_CHECKSUM = 54;

    /** Hosts accepted as YouTube links. */
    private const YOUTUBE_HOSTS = [
        'youtube.com',
        'www.youtube.com',
        'm.youtube.com',
        'music.youtube.com',
        'youtu.be',
        'www.youtu.be',
    ];

    private readonly string $derivedKey;

    /**
     * @param int $timeout       Total budget in seconds for a whole fetch(), including
     *                           the resolve handshake and its polls.
     * @param int $streamTimeout Total budget in seconds for one AI generation stream.
     */
    public function __construct(
        private readonly int $timeout = 30,
        private readonly int $connectTimeout = 10,
        private readonly string $userAgent = self::DEFAULT_USER_AGENT,
        private readonly int $streamTimeout = 60,
    ) {
        $this->derivedKey = self::deriveKey();
    }

    /**
     * Resolve a YouTube URL and return normalised metadata plus every media
     * variant reported by the upstream API.
     *
     * @return array{
     *     id: string|null,
     *     source_url: string,
     *     title: string|null,
     *     thumbnail: string|null,
     *     duration: int|float|null,
     *     medias: array<int, array<string, mixed>>
     * }
     *
     * @throws YtUltraException
     */
    public function fetch(string $videoUrl): array
    {
        $normalized = $this->normalizeUrl($videoUrl);
        $deadline = $this->deadline();
        $body = $this->encodeBody($normalized);

        $decoded = $this->decodeResponse($this->sendDownload($body, $deadline));

        if (($decoded['code'] ?? null) === self::CODE_PROCESSING_REQUIRED) {
            $resolved = $this->resolveAndWait($body, $deadline);

            $decoded = $resolved ?? $this->decodeResponse($this->sendDownload($body, $deadline));
        }

        if ((string) ($decoded['code'] ?? '') !== self::CODE_OK) {
            $code = (string) ($decoded['code'] ?? '');
            $message = (string) ($decoded['msg'] ?? 'Unknown upstream error.');

            throw new YtUltraException(self::upstreamMessage($code, $message), 502, null, $code);
        }

        $data = $decoded['data'] ?? null;
        if (!is_array($data)) {
            throw new YtUltraException('Upstream response did not contain media data.', 502);
        }

        return $this->normalizeData($data, $normalized);
    }

    /**
     * Return the direct URL of the best matching media variant, or null when
     * the requested quality/type is unavailable.
     *
     * @throws YtUltraException
     */
    public function getBestUrl(string $videoUrl, ?string $quality = null, string $type = 'video'): ?string
    {
        $data = $this->fetch($videoUrl);
        $media = $this->pickMedia($data['medias'], $quality, $type);

        return $media['url'] ?? null;
    }

    /**
     * Fetch the title, description and tags of a YouTube video as one payload.
     *
     * @return array{
     *     id: string|null,
     *     type: string|null,
     *     title: string|null,
     *     description: string|null,
     *     tags: array<int, string>,
     *     published_at: string|null
     * }
     *
     * @throws YtUltraException
     */
    public function extractDescription(string $videoUrl): array
    {
        $normalized = $this->normalizeUrl($videoUrl);
        $body = $this->encodeBody($normalized);
        $decoded = $this->decodeResponse(
            $this->send('POST', self::DESCRIPTION_PATH, $body, $this->deadline())
        );

        if ((string) ($decoded['code'] ?? '') !== self::CODE_OK) {
            $code = (string) ($decoded['code'] ?? '');
            $message = (string) ($decoded['msg'] ?? 'Unknown upstream error.');

            throw new YtUltraException(self::upstreamMessage($code, $message), 502, null, $code);
        }

        $data = $decoded['data'] ?? null;
        if (!is_array($data)) {
            throw new YtUltraException('Upstream did not return description data.', 502);
        }

        $tags = [];
        foreach (($data['tags'] ?? []) as $tag) {
            if (is_string($tag) && trim($tag) !== '') {
                $tags[] = trim($tag);
            }
        }

        return [
            'id' => isset($data['id']) ? (string) $data['id'] : $this->extractVideoId($normalized),
            'type' => isset($data['type']) ? (string) $data['type'] : null,
            'title' => isset($data['title']) ? (string) $data['title'] : null,
            'description' => isset($data['description']) ? (string) $data['description'] : null,
            'tags' => $tags,
            'published_at' => isset($data['publishedAt']) ? (string) $data['publishedAt'] : null,
        ];
    }

    /**
     * Generate a YouTube description or tag list from a short topic/input.
     *
     * Upstream answers with a Server-Sent Events stream; the token deltas are
     * concatenated into one string, so callers can either return a single
     * answer or forward each delta as it arrives through $onDelta.
     *
     * @param string              $type    "description" or "tag".
     * @param string              $input   Topic or video title (1..200 chars).
     * @param (callable(string): void)|null $onDelta Called for every token delta.
     *
     * @throws YtUltraException
     */
    public function generate(string $type, string $input, ?callable $onDelta = null): string
    {
        $type = strtolower(trim($type));
        if (!in_array($type, ['description', 'tag'], true)) {
            throw new YtUltraException('The "type" parameter must be "description" or "tag".', 400);
        }

        $input = trim($input);
        if ($input === '' || self::textLength($input) > self::MAX_INPUT_LENGTH) {
            throw new YtUltraException(
                'The "input" parameter must be between 1 and ' . self::MAX_INPUT_LENGTH . ' characters.',
                400
            );
        }

        $body = json_encode(
            ['type' => $type, 'input' => $input],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        if ($body === false) {
            throw new YtUltraException('Unable to encode the request body.', 500);
        }

        $deadline = microtime(true) + max(1, $this->streamTimeout);

        return $this->sendStream(
            self::GENERATE_PATH,
            $body,
            $deadline,
            $onDelta ?? static function (string $delta): void {
            }
        );
    }

    /**
     * Pick a media entry from a normalised media list.
     *
     * @param array<int, array<string, mixed>> $medias
     * @return array<string, mixed>|null
     */
    public function pickMedia(array $medias, ?string $quality = null, string $type = 'video'): ?array
    {
        $type = $type === 'audio' ? 'audio' : 'video';
        $extensions = $type === 'audio'
            ? ['m4a', 'weba', 'mp3', 'opus', 'ogg']
            : ['mp4', 'webm'];

        $candidates = [];
        foreach ($medias as $media) {
            if (!is_array($media)) {
                continue;
            }
            if (!empty($media['locked'])) {
                continue;
            }
            if (($media['type'] ?? '') !== $type) {
                continue;
            }
            if (!in_array((string) ($media['extension'] ?? ''), $extensions, true)) {
                continue;
            }
            if (!is_string($media['url'] ?? null) || $media['url'] === '') {
                continue;
            }
            $candidates[] = $media;
        }

        if ($candidates === []) {
            return null;
        }

        if ($quality !== null && $quality !== '') {
            foreach ($candidates as $candidate) {
                if (strcasecmp((string) ($candidate['quality'] ?? ''), $quality) === 0) {
                    return $candidate;
                }
            }

            return null;
        }

        $extensionRank = static function (string $extension) use ($type): int {
            if ($type === 'audio') {
                return match ($extension) {
                    'm4a' => 0,
                    'mp3' => 1,
                    'weba' => 2,
                    'opus' => 3,
                    'ogg' => 4,
                    default => 5,
                };
            }

            return $extension === 'mp4' ? 0 : 1;
        };

        usort($candidates, static function (array $a, array $b) use ($extensionRank): int {
            $byExtension = $extensionRank((string) ($a['extension'] ?? ''))
                <=> $extensionRank((string) ($b['extension'] ?? ''));

            if ($byExtension !== 0) {
                return $byExtension;
            }

            return self::qualityValue($b['quality'] ?? '') <=> self::qualityValue($a['quality'] ?? '');
        });

        return $candidates[0];
    }

    /**
     * Create an upstream resolve task and wait until it stops processing.
     *
     * @return array<string, mixed>|null The resolved data payload when the task
     *                                   already contains media, otherwise null
     *                                   and the caller retries the download.
     *
     * @throws YtUltraException
     */
    private function resolveAndWait(string $body, float $deadline): ?array
    {
        $payload = $this->decodeResponse(
            $this->send('POST', self::RESOLVE_PATH, $body, $deadline)
        );

        if ((string) ($payload['code'] ?? '') !== self::CODE_OK) {
            $code = (string) ($payload['code'] ?? '');
            $message = (string) ($payload['msg'] ?? 'Unknown upstream error.');

            throw new YtUltraException(self::upstreamMessage($code, $message), 502, null, $code);
        }

        $data = $payload['data'] ?? null;
        if (!is_array($data)) {
            throw new YtUltraException('Upstream resolve task response was malformed.', 502);
        }

        $taskId = $data['taskId'] ?? null;
        if (!is_string($taskId) || $taskId === '') {
            throw new YtUltraException('Upstream did not return a resolve task id.', 502);
        }

        $status = (string) ($data['status'] ?? 'processing');
        $result = $data['result'] ?? null;
        $path = self::RESOLVE_PATH . '/' . rawurlencode($taskId);

        while ($status === 'processing') {
            $remaining = $deadline - self::RETRY_RESERVE - microtime(true);
            if ($remaining <= 0.5) {
                throw new YtUltraException(
                    'The video is still being processed upstream. Retry in a few seconds.',
                    504
                );
            }

            usleep((int) min(self::POLL_INTERVAL_US, $remaining * 1_000_000));

            $poll = $this->decodeResponse($this->send('GET', $path, null, $deadline));

            if ((string) ($poll['code'] ?? '') !== self::CODE_OK) {
                $code = (string) ($poll['code'] ?? '');
                $message = (string) ($poll['msg'] ?? 'Unknown upstream error.');

                throw new YtUltraException(self::upstreamMessage($code, $message), 502, null, $code);
            }

            $pollData = $poll['data'] ?? null;
            if (!is_array($pollData)) {
                throw new YtUltraException('Upstream resolve status response was malformed.', 502);
            }

            $status = (string) ($pollData['status'] ?? 'processing');
            $result = $pollData['result'] ?? null;
        }

        if ($status === 'failed') {
            $code = is_array($result) ? (string) ($result['code'] ?? '') : '';
            $message = is_array($result) ? (string) ($result['msg'] ?? '') : '';

            throw new YtUltraException(self::upstreamMessage($code, $message), 502, null, $code);
        }

        if (is_array($result) && is_array($result['medias'] ?? null)) {
            return [
                'code' => self::CODE_OK,
                'msg' => 'ok',
                'data' => $result,
            ];
        }

        return null;
    }

    /**
     * Send the signed download POST and return the raw response body.
     *
     * @throws YtUltraException
     */
    private function sendDownload(string $body, float $deadline): string
    {
        return $this->send('POST', self::DOWNLOAD_PATH, $body, $deadline);
    }

    /**
     * Perform a signed request against the upstream API.
     *
     * @throws YtUltraException
     */
    private function send(string $method, string $path, ?string $body, float $deadline): string
    {
        $method = strtoupper($method);
        $remaining = $deadline - microtime(true);
        if ($remaining < 1) {
            throw new YtUltraException(
                'Timed out while waiting for upstream. Retry in a few seconds.',
                504
            );
        }

        $requestTimeout = max(1, min($this->timeout, (int) floor($remaining)));
        $timestamp = (int) round(microtime(true) * 1000);

        try {
            $nonce = bin2hex(random_bytes(16));
        } catch (Throwable $e) {
            throw new YtUltraException('Unable to generate a secure nonce.', 500, $e);
        }

        $headers = $this->signedHeaders($method, $path, $body, $timestamp, $nonce);

        $ch = curl_init(self::HOST . $path);
        if ($ch === false) {
            throw new YtUltraException('Unable to initialise cURL.', 500);
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $requestTimeout,
            CURLOPT_CONNECTTIMEOUT => min($this->connectTimeout, $requestTimeout),
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];

        if ($method === 'GET') {
            $options[CURLOPT_HTTPGET] = true;
        } else {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $body ?? '';
        }

        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $errno !== 0) {
            throw new YtUltraException('Network error while contacting upstream: ' . $error, 502);
        }

        if ($status === 429) {
            throw new YtUltraException('Upstream rate limit reached. Retry in a few seconds.', 429);
        }

        if ($status !== 200) {
            throw new YtUltraException('Upstream responded with HTTP ' . $status . '.', 502);
        }

        if (!is_string($response)) {
            throw new YtUltraException('Upstream returned an empty response.', 502);
        }

        return $response;
    }

    /**
     * Perform a signed POST request and consume the Server-Sent Events body.
     *
     * Upstream frames look like:
     *
     *   :connected
     *
     *   data:{"choices":[{"delta":{"content":"Have"}}]}
     *
     *   data:[DONE]
     *
     * @param callable(string): void $onDelta
     *
     * @throws YtUltraException
     */
    private function sendStream(string $path, string $body, float $deadline, callable $onDelta): string
    {
        $remaining = $deadline - microtime(true);
        if ($remaining < 1) {
            throw new YtUltraException(
                'Timed out while waiting for upstream. Retry in a few seconds.',
                504
            );
        }

        $requestTimeout = max(1, min($this->streamTimeout, (int) floor($remaining)));
        $timestamp = (int) round(microtime(true) * 1000);

        try {
            $nonce = bin2hex(random_bytes(16));
        } catch (Throwable $e) {
            throw new YtUltraException('Unable to generate a secure nonce.', 500, $e);
        }

        $headers = $this->signedHeaders('POST', $path, $body, $timestamp, $nonce, 'text/event-stream');

        $ch = curl_init(self::HOST . $path);
        if ($ch === false) {
            throw new YtUltraException('Unable to initialise cURL.', 500);
        }

        $buffer = '';
        $text = '';
        $finished = false;

        $consume = static function (string $event) use (&$text, &$finished, $onDelta): void {
            foreach (explode("\n", str_replace("\r", '', $event)) as $line) {
                if ($line === '' || str_starts_with($line, ':') || !str_starts_with($line, 'data:')) {
                    continue;
                }

                $payload = trim(substr($line, 5));
                if ($payload === '') {
                    continue;
                }

                if ($payload === '[DONE]') {
                    $finished = true;

                    return;
                }

                $decoded = json_decode($payload, true);
                $delta = is_array($decoded)
                    ? ($decoded['choices'][0]['delta']['content'] ?? null)
                    : null;

                if (is_string($delta) && $delta !== '') {
                    $text .= $delta;
                    $onDelta($delta);
                }
            }
        };

        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_ENCODING => 'identity',
            CURLOPT_TIMEOUT => $requestTimeout,
            CURLOPT_CONNECTTIMEOUT => min($this->connectTimeout, $requestTimeout),
            CURLOPT_LOW_SPEED_LIMIT => 1,
            CURLOPT_LOW_SPEED_TIME => 20,
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (
                &$buffer,
                &$finished,
                $consume
            ): int {
                $length = strlen($chunk);
                $buffer .= str_replace("\r\n", "\n", $chunk);

                while (($position = strpos($buffer, "\n\n")) !== false) {
                    $event = substr($buffer, 0, $position);
                    $buffer = substr($buffer, $position + 2);
                    $consume($event);

                    if ($finished) {
                        return 0;
                    }
                }

                return $length;
            },
        ]);

        curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status === 429) {
            throw new YtUltraException('Upstream rate limit reached. Retry in a few seconds.', 429);
        }

        if ($status !== 200) {
            throw new YtUltraException('Upstream responded with HTTP ' . $status . '.', 502);
        }

        if ($errno !== 0 && $errno !== CURLE_WRITE_ERROR) {
            throw new YtUltraException('Network error while contacting upstream: ' . $error, 502);
        }

        if ($text === '') {
            throw new YtUltraException('Upstream returned an empty generation stream.', 502);
        }

        return $text;
    }

    /**
     * Build the signed upstream headers shared by every request type.
     *
     * @return array<int, string>
     */
    private function signedHeaders(
        string $method,
        string $path,
        ?string $body,
        int $timestamp,
        string $nonce,
        string $accept = '*/*',
    ): array {
        return [
            'Content-Type: application/json',
            'Accept: ' . $accept,
            'Accept-Encoding: identity',
            'Origin: ' . self::ORIGIN,
            'Referer: ' . self::REFERER,
            'User-Agent: ' . $this->userAgent,
            'Lang: ' . self::LANG,
            'Timestamp: ' . $timestamp,
            'Nonce: ' . $nonce,
            'Sign: ' . $this->sign($method, $path, $body, $timestamp, $nonce),
            'X-App-Id: ' . self::APP_ID,
        ];
    }

    /**
     * Build the request signature.
     *
     * Canonical string:
     *   METHOD \n PATH \n SORTED_QUERY \n BODY_FIELD \n TIMESTAMP \n NONCE \n LANG
     *
     * BODY_FIELD is sha256(body) for requests with a JSON body (POST) and an
     * empty string for body-less requests (the resolve status GET).
     *
     * K1  = HMAC-SHA256(K0, "v2|" . floor(timestamp / 10000))   (raw bytes)
     * SIG = HMAC-SHA256(K1, canonical)                           (hex)
     */
    private function sign(string $method, string $path, ?string $body, int $timestamp, string $nonce): string
    {
        $canonical = implode("\n", [
            $method,
            $path,
            '',
            $body === null || $body === '' ? '' : hash('sha256', $body),
            (string) $timestamp,
            $nonce,
            self::LANG,
        ]);

        $k1 = hash_hmac('sha256', 'v2|' . intdiv($timestamp, 10000), $this->derivedKey, true);

        return hash_hmac('sha256', $canonical, $k1);
    }

    /**
     * Current monotonic-ish deadline for a whole fetch.
     */
    private function deadline(): float
    {
        return microtime(true) + max(1, $this->timeout);
    }

    /**
     * @throws YtUltraException
     */
    private function encodeBody(string $normalized): string
    {
        $body = json_encode(
            ['url' => $normalized],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        if ($body === false) {
            throw new YtUltraException('Unable to encode the request body.', 500);
        }

        return $body;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws YtUltraException
     */
    private function decodeResponse(string $raw): array
    {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new YtUltraException('Upstream returned a malformed JSON response.', 502);
        }

        return $decoded;
    }

    /**
     * Turn an upstream error code into a readable message.
     */
    private static function upstreamMessage(string $code, string $message): string
    {
        return match ($code) {
            '5023' => 'Upstream still requires processing for this video. Retry in a few seconds.',
            '5016' => 'This video could not be processed; it may be restricted or unavailable.',
            default => 'Upstream error: ' . ($message !== '' ? $message : 'Unknown upstream error.'),
        };
    }

    /**
     * Decode the shipping key and verify its checksum.
     */
    private static function deriveKey(): string
    {
        $maskCount = count(self::KEY_MASK);
        $key = '';
        $checksum = 0;

        foreach (self::KEY_PARTS as $i => $part) {
            $byte = ($part ^ self::KEY_MASK[$i % $maskCount] ^ ((31 * $i + 85) & 255)) & 255;
            $key .= chr($byte);
            $checksum = ($checksum + $byte) % 251;
        }

        if ($checksum !== self::KEY_CHECKSUM) {
            throw new RuntimeException('Signature key checksum mismatch; the upstream key may have changed.');
        }

        return $key;
    }

    /**
     * Validate and normalise a YouTube URL (or a bare 11-character video ID).
     *
     * @throws YtUltraException
     */
    private function normalizeUrl(string $input): string
    {
        $input = trim($input);
        if ($input === '') {
            throw new YtUltraException('The "url" parameter is required.', 400);
        }

        if (preg_match('/^[A-Za-z0-9_-]{11}$/', $input) === 1) {
            return 'https://www.youtube.com/watch?v=' . $input;
        }

        $parts = parse_url($input);
        if ($parts === false || empty($parts['host'])) {
            throw new YtUltraException('The supplied URL is not valid.', 400);
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new YtUltraException('Only http and https URLs are supported.', 400);
        }

        if (!in_array(strtolower((string) $parts['host']), self::YOUTUBE_HOSTS, true)) {
            throw new YtUltraException('Only YouTube URLs are supported.', 400);
        }

        return $input;
    }

    /**
     * Normalise the upstream `data` object.
     *
     * @param array<string, mixed> $data
     * @return array{
     *     id: string|null,
     *     source_url: string,
     *     title: string|null,
     *     thumbnail: string|null,
     *     duration: int|float|null,
     *     medias: array<int, array<string, mixed>>
     * }
     */
    private function normalizeData(array $data, string $sourceUrl): array
    {
        $medias = [];

        foreach (($data['medias'] ?? []) as $media) {
            if (!is_array($media)) {
                continue;
            }

            $format = (string) ($media['format'] ?? '');
            $extension = null;
            if (preg_match('/\[\.([a-z0-9]+)\]/i', $format, $matches) === 1) {
                $extension = strtolower($matches[1]);
            }

            $type = in_array($extension, ['m4a', 'weba', 'mp3', 'opus', 'ogg'], true)
                ? 'audio'
                : 'video';

            $url = $media['url'] ?? null;

            $medias[] = [
                'quality' => (string) ($media['quality'] ?? ''),
                'format' => $format,
                'extension' => $extension,
                'type' => $type,
                'size_bytes' => isset($media['fileSize']) ? (int) $media['fileSize'] : null,
                'size' => isset($media['sizeStr']) ? (string) $media['sizeStr'] : null,
                'locked' => (bool) ($media['locked'] ?? false),
                'requires_merge' => (bool) ($media['requiresMerge'] ?? false),
                'url' => is_string($url) && $url !== '' ? $url : null,
            ];
        }

        return [
            'id' => $this->extractVideoId($sourceUrl),
            'source_url' => $sourceUrl,
            'title' => isset($data['title']) ? (string) $data['title'] : null,
            'thumbnail' => isset($data['imageUrl']) ? (string) $data['imageUrl'] : null,
            'duration' => $data['duration'] ?? null,
            'medias' => $medias,
        ];
    }

    private function extractVideoId(string $url): ?string
    {
        if (preg_match('/(?:v=|\/shorts\/|\/embed\/|youtu\.be\/)([A-Za-z0-9_-]{11})/', $url, $matches) === 1) {
            return $matches[1];
        }

        if (preg_match('/^[A-Za-z0-9_-]{11}$/', $url) === 1) {
            return $url;
        }

        return null;
    }

    private static function qualityValue(mixed $quality): int
    {
        return (int) preg_replace('/\D+/', '', (string) $quality);
    }

    /**
     * Character count that works with or without mbstring.
     */
    private static function textLength(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }
}
