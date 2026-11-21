# YtUltra API

[![PHP](https://img.shields.io/badge/PHP-8.1%2B-777BB4?logo=php&logoColor=white)](#requirements)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)
[![No dependencies](https://img.shields.io/badge/dependencies-none-brightgreen)](#requirements)

A small, dependency-free PHP API that resolves direct YouTube download URLs through the
unofficial [ytultra.com](https://www.ytultra.com) backend.

It exposes the resolved media list as JSON, can redirect to a direct URL, and can stream the
selected file through your own server so that downloads work from any IP address.

> **Unofficial project.** Not affiliated with YouTube, Google or ytultra.com. The upstream
> service may change or disappear at any time. Use it responsibly and respect copyright and
> the terms of service of the platforms you interact with.

---

## Features

- Signed upstream requests, including the resolve handshake for videos that are not ready yet;
  no browser automation, headless Chrome or external libraries.
- Pure PHP 8.1+ with `cURL` only.
- Returns every media variant: quality, format, size, lock state, type and URL.
- Three endpoints: metadata (`/api/info`), direct redirect (`/api/redirect`) and streaming
  download (`/api/download`).
- Streaming proxy with HTTP `Range` support, so large files are never buffered in memory.
- AI helpers: fetch a video's title, description and tags in one call, or generate a description or
  tag list from a topic, either as a single JSON answer or as forwarded SSE token deltas.
- Structured JSON errors and sensible HTTP status codes.
- Configurable through environment variables.
- Linted in CI with GitHub Actions.

## Requirements

- PHP **8.1** or newer
- `ext-curl`
- `ext-json`
- Composer is optional (it is only used for PSR-4 autoloading and the helper scripts)

## Project structure

```
.
├── public/
│   ├── index.php          # Front controller: routing, JSON responses, streaming
│   └── .htaccess          # Apache rewrite to index.php
├── src/
│   ├── YtUltraClient.php  # Upstream client + signature + media normalisation
│   └── YtUltraException.php
├── ytultra_downloader.php # Legacy one-function helper (getVideoUrl)
├── composer.json
├── .htaccess              # Optional root → public rewrite
├── .github/workflows/ci.yml
├── LICENSE
└── README.md
```

## Quick start

```bash
git clone https://github.com/aboodalajrad8/YT-Ultra-video-downloader.git
cd YT-Ultra-video-downloader

# Optional: install the autoloader
composer install

# Run the development server
php -S 127.0.0.1:8080 -t public public/index.php
```

Open <http://127.0.0.1:8080/> to see the endpoint index, or try:

```bash
curl "http://127.0.0.1:8080/api/info?url=https://www.youtube.com/watch?v=MXkG0Nrrq9A"
```

With Composer installed you can also use:

```bash
composer serve
composer lint
```

## Configuration

Configuration is read from the process environment, so it works with Apache, Nginx + PHP-FPM,
Docker and systemd without any extra files.

| Variable                 | Default | Description                                              |
| ------------------------ | ------- | -------------------------------------------------------- |
| `YTULTRA_TIMEOUT`        | `30`    | Total timeout for the upstream API request, in seconds.  |
| `YTULTRA_CONNECT_TIMEOUT`| `10`    | Connection timeout for the upstream API request.         |
| `YTULTRA_STREAM_TIMEOUT` | `60`    | Total timeout for one AI generation stream, in seconds.   |
| `YTULTRA_USER_AGENT`     | Chrome  | `User-Agent` sent to the upstream API.                   |
| `YTULTRA_CORS_ORIGIN`    | `*`     | Value of the `Access-Control-Allow-Origin` response header. |

Example with PHP-FPM:

```ini
; /etc/php/8.2/fpm/pool.d/www.conf
env[YTULTRA_TIMEOUT] = 30
env[YTULTRA_CORS_ORIGIN] = https://example.com
```

## API reference

All endpoints accept `GET` (and `HEAD`) and return JSON unless noted otherwise.

| Method | Path            | Description                                        |
| ------ | --------------- | -------------------------------------------------- |
| GET    | `/`             | Endpoint index and API metadata.                   |
| GET    | `/health`       | Liveness probe.                                    |
| GET    | `/api/info`     | Metadata and every media variant.                  |
| GET    | `/api/redirect` | `302` to the best direct media URL.                |
| GET    | `/api/download` | Stream the selected media file through the server. |
| GET    | `/api/description` | Title, full description and tags of a video.    |
| GET    | `/api/generate` | AI description/tag generation (one-shot or SSE).   |

Common query parameters:

| Parameter | Required | Description                                                       |
| --------- | -------- | ----------------------------------------------------------------- |
| `url`     | yes      | A YouTube URL or a bare 11-character video ID.                    |
| `quality` | no       | e.g. `144p`, `240p`, `360p`, `480p`, `720p`, `1080p`. Best available when omitted. |
| `type`    | no       | `video` (default) or `audio`.                                     |

### `GET /api/info`

Returns normalised metadata and the full media list. Locked variants are included with a
`null` URL so clients can display what exists without offering a broken download.

```bash
curl "http://127.0.0.1:8080/api/info?url=https://www.youtube.com/watch?v=MXkG0Nrrq9A"
```

```json
{
  "success": true,
  "data": {
    "id": "MXkG0Nrrq9A",
    "source_url": "https://www.youtube.com/watch?v=MXkG0Nrrq9A",
    "title": null,
    "thumbnail": "https://i.ytimg.com/vi/MXkG0Nrrq9A/maxresdefault.jpg",
    "duration": null,
    "medias": [
      {
        "quality": "720p",
        "format": "720p (144.52 MB) [.mp4]",
        "extension": "mp4",
        "type": "video",
        "size_bytes": 151541342,
        "size": "144.52 MB",
        "locked": false,
        "requires_merge": false,
        "url": "https://rr1---sn-....googlevideo.com/videoplayback?..."
      },
      {
        "quality": "1080p",
        "format": "1080p (288.72 MB) [.mp4]",
        "extension": "mp4",
        "type": "video",
        "size_bytes": 302742843,
        "size": "288.72 MB",
        "locked": true,
        "requires_merge": false,
        "url": null
      }
    ]
  }
}
```

> `title` and `duration` are passed through from upstream and may be `null` depending on the
> video. `thumbnail` is always available.

> **Audio variants are `.m4a` (`audio/mp4`), not `.mp3`.** Upstream duplicates the same audio
> stream under every video quality label (`1080p`, `720p`, ...) with an identical size, so the
> quality field is not meaningful for `type=audio`; the client keeps the highest label but the
> bytes are the same. Producing a real `.mp3` requires server-side conversion (for example
> `ffmpeg`), which this dependency-free API intentionally does not do.

### `GET /api/redirect`

Redirects (`302`) to the direct media URL. Use this when the client shares the server's public
IP address (for example the same machine or a VPN exit node).

```bash
curl -I "http://127.0.0.1:8080/api/redirect?url=https://www.youtube.com/watch?v=MXkG0Nrrq9A&quality=480p"
```

### `GET /api/download`

Streams the file through the API server with `Content-Disposition: attachment`, forwarding
`Range` requests so resumable downloads work.

```bash
curl -L -o video.mp4 \
  "http://127.0.0.1:8080/api/download?url=https://www.youtube.com/watch?v=MXkG0Nrrq9A&quality=720p"

# Audio only
curl -L -o audio.m4a \
  "http://127.0.0.1:8080/api/download?url=MXkG0Nrrq9A&type=audio"
```

Note that proxying consumes your server's bandwidth for the whole file.

### Why `/api/download` exists

The direct `googlevideo.com` URLs are signed for the **IP address that requested them**. If the
upstream request is made from your server and the link is opened from a different network, Google
returns:

```
HTTP ERROR 403 — Access denied
```

`/api/download` avoids this by fetching the media server-side and streaming it to the client.
Alternatively, run the API on the same host that will consume `/api/redirect` links.

## Video description and AI generation

Two extra upstream endpoints are wrapped here. Both are signed with the same scheme as the
download call.

### `GET /api/description`

Returns the video's own title, full description (chapters and links included) and tags in a single
JSON response. The extractor is a normal JSON endpoint upstream, so there is no streaming here.

```bash
curl "http://127.0.0.1:8080/api/description?url=https://www.youtube.com/watch?v=xsOxoVuv9bs"
```

```json
{
  "success": true,
  "data": {
    "id": "xsOxoVuv9bs",
    "type": "video",
    "title": "I Made AI Make an Operating System",
    "description": "Can AI build a fully-functional Operating System? ...",
    "tags": ["chatgpt", "grok", "artificial intelligence", "..."],
    "published_at": "2026-10-10T12:30:24Z"
  }
}
```

### `GET /api/generate`

Generates a new YouTube description or a comma-separated tag list from a topic or title.

| Parameter | Required | Description                                              |
| --------- | -------- | -------------------------------------------------------- |
| `type`    | yes      | `description` or `tag`.                                  |
| `input`   | yes      | Topic or video title, 1–200 characters.                  |
| `stream`  | no       | `1` to receive Server-Sent Events instead of one JSON.   |

**One answer (default)** — the whole stream is collected and returned once, which is what a chat
bot wants:

```bash
curl "http://127.0.0.1:8080/api/generate?type=tag&input=I%20Made%20AI%20Make%20an%20Operating%20System"
```

```json
{
  "success": true,
  "data": {
    "type": "tag",
    "input": "I Made AI Make an Operating System",
    "text": "AI operating system, AI created OS, artificial intelligence OS, ...",
    "length": 469
  }
}
```

**Live stream** — `stream=1` forwards upstream token deltas as they arrive:

```bash
curl -N "http://127.0.0.1:8080/api/generate?type=description&input=I%20Made%20AI%20Make%20an%20Operating%20System&stream=1"
```

```
: connected

data: {"delta":"Have"}

data: {"delta":" you"}

data: {"delta":" ever"}

data: [DONE]
```

A failure after the stream started is sent as a final `data: {"error":{"message":...}}` frame
before `[DONE]`.

#### How the upstream stream looks

Upstream replies with `Content-Type: text/event-stream`, `Cache-Control: no-cache, no-transform`
and a comment line first, then one JSON object per token and a sentinel:

```
:connected

data:{"choices":[{"delta":{"content":"Have"}}]}

data:[DONE]
```

Notes on the format, all handled by `YtUltraClient`:

- `data:` has **no space** after the colon; both forms are accepted.
- Events are separated by a blank line and may be split across network chunks.
- `:connected` is a comment and is ignored; `[DONE]` ends the stream.
- The answer is the concatenation of `choices[0].delta.content`, so a single JSON response and a
  live stream are two views of the same data.

PHP needs nothing beyond cURL for this: `CURLOPT_WRITEFUNCTION` parses each chunk, `flush()`
(plus `X-Accel-Buffering: no`) pushes deltas to the client, and returning `0` from the callback
stops reading once `[DONE]` arrives or the client disconnects.

#### Upstream limits

The AI endpoints are rate-limited and opinionated. These limits were measured against the live API:

- **200 characters** is the hard maximum for `input`, in both `description` and `tag` mode.
  Longer text is rejected by upstream with an empty `400`, so the API enforces the limit itself and
  returns `400 invalid_request` without spending an upstream call.
- **Rate limiting** appears after a burst of generations and is surfaced as `429 rate_limited` with
  `Retry-After: 30`. Space requests out; retrying immediately extends the block.
- **Non-English input is unreliable.** An Arabic `input` was accepted but produced an English
  description about an unrelated topic. Prefer an English topic or video title for predictable output.

## Error responses

Errors use a consistent envelope:

```json
{
  "success": false,
  "error": {
    "code": "invalid_request",
    "message": "Only YouTube URLs are supported."
  }
}
```

| HTTP | `code`            | Meaning                                            |
| ---- | ----------------- | -------------------------------------------------- |
| 400  | `invalid_request` | Missing/invalid `url` or unsupported host.          |
| 404  | `not_found`       | Unknown endpoint, or requested quality/type absent. |
| 405  | `method_not_allowed` | Method other than `GET`, `HEAD`, `OPTIONS`.     |
| 429  | `rate_limited`    | Upstream rate limit reached; retry after 30s.       |
| 500  | `internal_error`  | Unexpected server-side failure.                    |
| 502  | `upstream_error`  | Upstream API or media server failure/timeout.      |
| 504  | `upstream_error`  | Upstream is still processing the video; retry.     |

When upstream returns a known error code, the payload also carries it:

```json
{
  "success": false,
  "error": {
    "code": "upstream_error",
    "message": "This video could not be processed; it may be restricted or unavailable.",
    "upstream_code": "5016"
  }
}
```

| Upstream code | Meaning                                                            |
| ------------- | ------------------------------------------------------------------ |
| `5023`        | Video not ready: create a resolve task, poll it, then retry        |
| `5016`        | The video is restricted or unavailable and could not be processed  |

## How it works

The website builds one signed request to:

```
POST https://api.ytultra.com/ikool/youtube/download
```

Required headers include `Timestamp` (milliseconds), `Nonce` (16 random bytes, hex), `Lang`,
`X-App-Id: 2` and `Sign`. The signature is derived from a key obfuscated in the site's
JavaScript bundle:

1. Decode the key bytes with a repeating XOR mask and a position-dependent term, then verify a
   checksum.
2. Derive a per-window key:

   ```
   K1 = HMAC-SHA256(K0, "v2|" + floor(timestamp / 10000))   // raw bytes
   ```

3. Build a canonical string and sign it:

   ```
   METHOD \n PATH \n SORTED_QUERY \n BODY_FIELD \n TIMESTAMP \n NONCE \n LANG
   Sign = HMAC-SHA256(K1, canonical)                        // lowercase hex
   ```

   `BODY_FIELD` is `sha256(BODY)` for requests that carry a JSON body (`POST`) and an empty
   string for body-less requests such as the resolve status `GET`.

4. POST `{"url":"<youtube-url>"}`. A successful response has `code: "0000"` and a `data.medias`
   array with `url`, `quality`, `locked`, `format`, `fileSize` and `sizeStr`.

`YtUltraClient` reproduces all of the above and normalises the response.

### Videos that need processing (`5023`)

For some videos the first download call answers without media:

```json
{"code":"5023","msg":"Additional video processing is required. Please wait."}
```

That response means "start a resolve task, wait, then ask again". The client follows the same
handshake the website uses, all within the single `YTULTRA_TIMEOUT` budget:

| Step | Request                                                  | Result                                                   |
| ---- | -------------------------------------------------------- | -------------------------------------------------------- |
| 1    | `POST /ikool/youtube/download`                            | `5023` (no media yet)                                    |
| 2    | `POST /ikool/youtube/download/resolve` (same JSON body)    | `data.taskId`, `data.status: "processing"`                |
| 3    | `GET /ikool/youtube/download/resolve/{taskId}` every ~2.5s | `processing` until `success` or `failed`                 |
| 4    | `POST /ikool/youtube/download` (retry once)               | `0000` with the full `data.medias` list                  |

- On `failed` the task carries a nested `result: {"code":"...","msg":"..."}`; the error code is
  surfaced as `upstream_code` (for example `5016` for a restricted video).
- If the budget runs out while the task is still `processing`, the API answers `504` with
  `Retry-After: 5` and a clear message. Retrying the same request a few seconds later normally
  succeeds, because upstream finished the work in the meantime.

> The key and scheme are reverse-engineered and can change without notice. If upstream starts
> returning signature errors, the key or canonical format needs to be re-derived.

## Deployment

### Apache

Point the virtual host at `public/` and enable `mod_rewrite`:

```apache
<VirtualHost *:80>
    ServerName api.example.com
    DocumentRoot /var/www/ytultra-api/public

    <Directory /var/www/ytultra-api/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

The bundled `public/.htaccess` routes unknown paths to `index.php`. The repository-root
`.htaccess` also works if you cannot change `DocumentRoot`, but pointing directly at `public/`
is preferred.

### Nginx + PHP-FPM

```nginx
server {
    listen 80;
    server_name api.example.com;
    root /var/www/ytultra-api/public;
    index index.php;

    location / {
        try_files $uri /index.php$is_args$args;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    }
}
```

For long downloads, raise `fastcgi_read_timeout` and disable proxy buffering:

```nginx
location = /api/download {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root/index.php;
    fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    fastcgi_read_timeout 3600;
    fastcgi_buffering off;
}
```

### PHP built-in server (development only)

```bash
php -S 127.0.0.1:8080 -t public public/index.php
```

## Using the client directly

```php
<?php

require __DIR__ . '/vendor/autoload.php'; // or require src/*.php manually

use YtUltra\YtUltraClient;

$client = new YtUltraClient();

$data = $client->fetch('https://www.youtube.com/watch?v=MXkG0Nrrq9A');
foreach ($data['medias'] as $media) {
    printf("%-6s %-5s %-8s %s\n", $media['quality'], $media['extension'], $media['locked'] ? 'locked' : 'free', $media['size']);
}

$url = $client->getBestUrl('MXkG0Nrrq9A', '720p');          // video/mp4
$audio = $client->getBestUrl('MXkG0Nrrq9A', null, 'audio'); // audio/m4a or weba
```

The legacy helper remains available:

```php
require __DIR__ . '/ytultra_downloader.php';

$url = getVideoUrl('https://www.youtube.com/watch?v=MXkG0Nrrq9A'); // ?string
```

## Security notes

- Only `youtube.com` / `youtu.be` hosts are accepted, and the media URL is always the one
  returned by the upstream API.
- `url` is never used to build a shell command or a local file path.
- Keep PHP and cURL up to date; the API does not require or store credentials.
- The `/api/download` endpoint is public by default. Add authentication or rate limiting
  (reverse proxy, API gateway or application middleware) before exposing it at scale.
- Consider blocking direct access to `src/` and `ytultra_downloader.php` in your web server;
  only `public/` needs to be reachable.

## Contributing

Issues and pull requests are welcome. Please run `composer lint` before opening a PR. Keep the
project dependency-free and PHP 8.1 compatible.

## License

Released under the [MIT License](LICENSE).
