<?php

declare(strict_types=1);

require_once __DIR__ . '/src/YtUltraException.php';
require_once __DIR__ . '/src/YtUltraClient.php';

/**
 * Backwards-compatible helper kept from the original single-file version.
 *
 * Returns the best unlocked .mp4 URL for a YouTube link, or null on any
 * failure. New code should use {@see \YtUltra\YtUltraClient} directly.
 */
function getVideoUrl(string $reelUrl): ?string
{
    try {
        return (new YtUltra\YtUltraClient())->getBestUrl($reelUrl);
    } catch (Throwable) {
        return null;
    }
}
