# YT-ultra-scrapping

[![PHP](https://img.shields.io/badge/PHP-8.1%2B-777BB4?logo=php&logoColor=white)](#requirements)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)

A PHP YouTube video scraper and downloader that resolves direct YouTube download URLs through the unofficial [ytultra.com](https://www.ytultra.com) backend.

## Features

- Resolves direct YouTube download URLs
- Supports multiple video qualities and formats
- Pure PHP 8.1+ with cURL only
- No external dependencies required
- Returns media metadata and direct download URLs

## Requirements

- PHP 8.1 or newer
- ext-curl
- ext-json

## Quick start

```bash
# Clone the repository
git clone https://github.com/aboodalajrad8/YT-ultra-scrapping.git
cd YT-ultra-scrapping

# Run the development server
php -S 127.0.0.1:8080 -t public public/index.php
```

## Usage

```php
<?php
require 'src/YtUltraClient.php';

use YtUltra\YtUltraClient;

$client = new YtUltraClient();
$data = $client->fetch('https://www.youtube.com/watch?v=VIDEO_ID');

foreach ($data['medias'] as $media) {
    echo "Quality: {$media['quality']}, Format: {$media['extension']}, Size: {$media['size']}\n";
}
```

## API Endpoints

- `/api/info` - Get metadata and media variants
- `/api/redirect` - Redirect to direct media URL
- `/api/download` - Stream selected media file

## License

Released under the [MIT License](LICENSE).
