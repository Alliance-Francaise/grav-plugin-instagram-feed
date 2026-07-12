<?php

namespace Grav\Plugin\InstagramFeed;

use Grav\Common\Grav;

/**
 * Fetches the Instagram media feed and self-hosts a resized WebP copy of every
 * post's still image (PHP-GD). Shared by the plugin's webhook handler, its
 * scheduler job, and the `bin/plugin instagram-feed fetch` CLI command, so the
 * one implementation runs the same way everywhere.
 *
 * See user/plugins/instagram-feed/README.md.
 */
class InstagramFeed
{
    private const GRAPH_FIELDS = 'id,caption,media_type,media_product_type,media_url,permalink,thumbnail_url,timestamp';

    /** @var Grav */
    private $grav;
    /** @var array */
    private $config;

    public function __construct(Grav $grav, array $config)
    {
        $this->grav = $grav;
        $this->config = $config;
    }

    /**
     * Fetch the latest media, cache resized images, write feed.json.
     *
     * @return array{ok:bool,count?:int,reels?:int,images_cached?:int,images_failed?:int,pages?:int,error?:string}
     */
    public function run(): array
    {
        $maxItems  = max(1, (int) ($this->config['max_items'] ?? 120));
        $width     = max(64, (int) ($this->config['image_width'] ?? 250));
        $quality   = min(100, max(1, (int) ($this->config['webp_quality'] ?? 80)));
        $pageLimit = 50;   // IG caps the media edge per page
        $maxPages  = 20;   // safety cap so a paging bug can't loop forever

        $base = (string) $this->grav['locator']->findResource('user-data://', true, true);
        $dir  = $base . '/instagram';
        $cacheDir  = $dir . '/cache';
        $tokenFile = $dir . '/token.json';
        $feedFile  = $dir . '/feed.json';

        if (!is_dir($cacheDir) && !@mkdir($cacheDir, 0755, true) && !is_dir($cacheDir)) {
            return $this->fail('Instagram feed: cannot create cache dir');
        }

        if (!is_file($tokenFile)) {
            return $this->fail('Instagram feed: no token.json — seed it first (see bin/README-instagram.md)');
        }
        $token = (string) (json_decode((string) file_get_contents($tokenFile), true)['access_token'] ?? '');
        if ($token === '') {
            return $this->fail('Instagram feed: no usable token in token.json');
        }

        // Page through /me/media, accumulating into $all until we hit maxItems.
        $all = [];
        $next = null;
        $pages = 0;
        while (true) {
            $url = $next ?: 'https://graph.instagram.com/me/media?' . http_build_query([
                'fields'       => self::GRAPH_FIELDS,
                'limit'        => $pageLimit,
                'access_token' => $token,
            ]);
            [$body, $code, $curlErr] = $this->httpGet($url);
            $json = json_decode((string) $body, true);

            if (!is_array($json) || !isset($json['data'])) {
                $msg = $curlErr ?: ($json['error']['message'] ?? ('HTTP ' . $code . ' ' . substr((string) $body, 0, 200)));
                if (count($all) > 0) {
                    $this->log('warning', 'Instagram feed: page ' . ($pages + 1) . ' fetch failed, keeping cached data — ' . $msg);
                    break;
                }
                return $this->fail('Instagram feed: fetch failed — ' . $msg);
            }

            $all = array_merge($all, $json['data']);
            $pages++;
            $next = $json['paging']['next'] ?? null;
            if (count($all) >= $maxItems || !$next || $pages >= $maxPages) {
                break;
            }
        }
        $all = array_slice($all, 0, $maxItems);

        // Normalise + cache each still image.
        $items = [];
        $keep = [];
        $cached = 0;
        $failed = 0;
        foreach ($all as $m) {
            $id = (string) ($m['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $type    = (string) ($m['media_type'] ?? '');
            $product = (string) ($m['media_product_type'] ?? '');
            $isVideo = ($type === 'VIDEO');
            $srcImage = $isVideo
                ? (string) ($m['thumbnail_url'] ?? $m['media_url'] ?? '')
                : (string) ($m['media_url'] ?? '');
            $video = $isVideo ? (string) ($m['media_url'] ?? '') : '';

            $imageLocal = '';
            if ($srcImage !== '') {
                $keep[$id] = true;
                $file    = $cacheDir . '/' . $id . '.webp';
                $webPath = '/user/data/instagram/cache/' . $id . '.webp';
                if (is_file($file) && filesize($file) > 0) {
                    $imageLocal = $webPath;                       // already cached (by id)
                } elseif ($this->cacheImage($srcImage, $file, $width, $quality)) {
                    $imageLocal = $webPath;
                    $cached++;
                } else {
                    $failed++;                                    // fall back to CDN url below
                }
            }

            $items[] = [
                'id'           => $id,
                'type'         => $type,
                'product_type' => $product,
                'reel'         => ($product === 'REELS'),
                'permalink'    => (string) ($m['permalink'] ?? ''),
                'caption'      => (string) ($m['caption'] ?? ''),
                'image'        => $srcImage,     // original CDN still (fallback)
                'image_local'  => $imageLocal,   // self-hosted resized WebP
                'video'        => $video,        // mp4 CDN url (streamed on demand)
                'timestamp'    => (string) ($m['timestamp'] ?? ''),
            ];
        }

        // Drop cache files for posts no longer in the feed (keeps the dir bounded).
        foreach (glob($cacheDir . '/*.webp') ?: [] as $f) {
            if (!isset($keep[basename($f, '.webp')])) {
                @unlink($f);
            }
        }

        $out = [
            'fetched_at'  => time(),
            'count'       => count($items),
            'image_width' => $width,
            'items'       => $items,
        ];
        $tmp = $feedFile . '.tmp';
        if (file_put_contents($tmp, json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) === false) {
            @unlink($tmp);
            return $this->fail('Instagram feed: cannot write feed.json');
        }
        @rename($tmp, $feedFile);

        $reels = count(array_filter($items, static fn ($i) => $i['reel']));
        $this->log('info', sprintf(
            'Instagram feed: cached %d media item(s) across %d page(s) — %d reel(s), %d image(s) resized, %d failed',
            count($items), $pages, $reels, $cached, $failed
        ));

        return [
            'ok'            => true,
            'count'         => count($items),
            'reels'         => $reels,
            'images_cached' => $cached,
            'images_failed' => $failed,
            'pages'         => $pages,
        ];
    }

    /**
     * Refresh the long-lived Instagram access token (extends it ~60 days). Ports
     * bin/instagram-refresh-token.sh. Never overwrites the stored token unless the
     * API returns a new one, so a failed/expired refresh keeps the old file intact.
     *
     * @return array{ok:bool,expires_in?:int,expires_at?:int,valid_days?:int,error?:string}
     */
    public function refreshToken(): array
    {
        $base = (string) $this->grav['locator']->findResource('user-data://', true, true);
        $tokenFile = $base . '/instagram/token.json';

        if (!is_file($tokenFile)) {
            return $this->fail('Instagram token: no token.json — seed it first (see the plugin README)');
        }
        $current = (string) (json_decode((string) file_get_contents($tokenFile), true)['access_token'] ?? '');
        if ($current === '') {
            return $this->fail('Instagram token: token.json has no access_token');
        }

        $url = 'https://graph.instagram.com/refresh_access_token?' . http_build_query([
            'grant_type'   => 'ig_refresh_token',
            'access_token' => $current,
        ]);
        [$body, $code, $curlErr] = $this->httpGet($url);
        $json = json_decode((string) $body, true);
        $new = is_array($json) ? (string) ($json['access_token'] ?? '') : '';
        if ($new === '') {
            $msg = $curlErr ?: (is_array($json) ? ($json['error']['message'] ?? '') : '') ?: ('HTTP ' . $code . ' ' . substr((string) $body, 0, 200));
            return $this->fail('Instagram token: refresh failed — ' . $msg);
        }

        $expiresIn = (int) ($json['expires_in'] ?? 5184000);   // default ~60 days
        $now = time();
        $expiresAt = $now + $expiresIn;

        // Atomic write next to the target (same fs → atomic rename); keep it tight.
        $payload = json_encode([
            'access_token' => $new,
            'last_refresh' => $now,
            'expires_at'   => $expiresAt,
        ], JSON_UNESCAPED_SLASHES);
        $tmp = $tokenFile . '.tmp';
        if (file_put_contents($tmp, $payload) === false) {
            @unlink($tmp);
            return $this->fail('Instagram token: cannot write token.json');
        }
        @chmod($tmp, 0600);
        @rename($tmp, $tokenFile);
        @chmod($tokenFile, 0600);

        $days = intdiv($expiresIn, 86400);
        $this->log('info', sprintf('Instagram token: refreshed — valid ~%d days (until %s)', $days, date('Y-m-d', $expiresAt)));

        return ['ok' => true, 'expires_in' => $expiresIn, 'expires_at' => $expiresAt, 'valid_days' => $days];
    }

    /**
     * Download an image and write an aspect-preserving WebP no wider than $width.
     * Never upscales. Returns false (caller falls back to the CDN url) on any error.
     */
    private function cacheImage(string $url, string $dest, int $width, int $quality): bool
    {
        [$body, $code, ] = $this->httpGet($url);
        if ($body === '' || $code >= 400) {
            return false;
        }
        $src = @imagecreatefromstring($body);
        if (!$src) {
            return false;
        }
        $ow = imagesx($src);
        $oh = imagesy($src);
        if ($ow < 1 || $oh < 1) {
            imagedestroy($src);
            return false;
        }
        $tw = min($width, $ow);                        // don't upscale
        $th = max(1, (int) round($tw * $oh / $ow));
        $dst = imagecreatetruecolor($tw, $th);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, $ow, $oh);

        $tmp = $dest . '.tmp';
        $ok = imagewebp($dst, $tmp, $quality);
        imagedestroy($src);
        imagedestroy($dst);
        if ($ok && is_file($tmp) && filesize($tmp) > 0) {
            @rename($tmp, $dest);
            return true;
        }
        @unlink($tmp);
        return false;
    }

    /**
     * @return array{0:string,1:int,2:string} [body, http_code, curl_error]
     */
    private function httpGet(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_USERAGENT      => 'afsendai-instagram-feed/1.0',
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        return [$body === false ? '' : (string) $body, $code, $err];
    }

    private function fail(string $message): array
    {
        $this->log('error', $message);
        return ['ok' => false, 'error' => $message];
    }

    /**
     * Append one line to logs/grav.log in Grav's Monolog line format, minus the
     * trailing empty {context}/[extra] fields — i.e. `[ISO8601] grav.LEVEL: message`,
     * matching bin/instagram-refresh-token.sh so all Instagram logging is uniform
     * and still parses in Admin → Tools → Logs.
     */
    private function log(string $level, string $message): void
    {
        try {
            $log = (string) $this->grav['locator']->findResource('log://grav.log', true, true);
            if ($log === '') {
                $log = rtrim(GRAV_ROOT, '/') . '/logs/grav.log';
            }
            // DateTimeImmutable carries real microseconds + Grav's timezone, so the
            // timestamp is byte-for-byte the shape Grav's own Monolog lines use.
            $ts = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.uP');
            $line = sprintf("[%s] grav.%s: %s\n", $ts, strtoupper($level), $message);
            @file_put_contents($log, $line, FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {
            // Logging must never take the request down.
        }
    }
}
