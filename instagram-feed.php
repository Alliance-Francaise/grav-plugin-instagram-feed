<?php

namespace Grav\Plugin;

use Grav\Common\Grav;
use Grav\Common\Plugin;
use Grav\Plugin\InstagramFeed\InstagramFeed;
use RocketTheme\Toolbox\Event\Event;
use RuntimeException;
use Throwable;

require_once __DIR__ . '/classes/InstagramFeed.php';

/**
 * Instagram Feed plugin for the Alliance Française de Sendai theme.
 *
 * Replaces the old bin/instagram-fetch-feed.sh shell job. It pages through the
 * Instagram Graph media edge, resizes every post's still image with PHP-GD to a
 * small self-hosted WebP (default 250px wide) under user/data/instagram/cache/,
 * and writes user/data/instagram/feed.json (the cache the theme renders) with an
 * `image_local` web path per item. The heavy lifting lives in
 * classes/InstagramFeed.php; this class just wires it up. Two actions —
 * `fetch` (feed + image cache) and `refresh-token` (extend the long-lived token) —
 * are each exposed three ways:
 *
 *   - Secret-key webhook:  GET /_instagram/fetch?key=<webhook_key>
 *                          GET /_instagram/refresh-token?key=<webhook_key>
 *     (the key may also be sent as an X-Webhook-Key header). Returns JSON.
 *   - Grav scheduler jobs `instagram-fetch-feed` (every 30 min) and
 *     `instagram-refresh-token` (daily), registered here.
 *   - CLI: `bin/plugin instagram-feed fetch` / `... refresh-token`
 *     (see cli/FetchCommand.php, cli/RefreshTokenCommand.php).
 *
 * Video files are NOT self-hosted (they stream fine from the IG CDN on demand);
 * only their poster still is cached. The token refresh never overwrites the stored
 * token unless the API returns a new one, so a failed/expired refresh is harmless.
 */
class InstagramFeedPlugin extends Plugin
{
    public static function getSubscribedEvents(): array
    {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 0],
        ];
    }

    public function onPluginsInitialized(): void
    {
        // The scheduler job must register in CLI (php bin/grav scheduler) too.
        $this->enable([
            'onSchedulerInitialized' => ['onSchedulerInitialized', 0],
        ]);

        if ($this->isAdmin()) {
            return;
        }

        // Intercept the webhook route before Grav tries to resolve it as a page.
        $route = trim((string) $this->config->get('plugins.instagram-feed.route', '/_instagram'));
        if ($route === '' || $route[0] !== '/') {
            return;
        }
        $path = rtrim((string) $this->grav['uri']->path(), '/');
        if ($path === '') {
            $path = '/';
        }
        if ($path === $route || strpos($path, $route . '/') === 0) {
            $this->handleWebhook($route, $path);
        }
    }

    /**
     * Register both actions as native Grav scheduler jobs. They run in-process via
     * string static callables — the same shape core uses for `Cache::purgeJob` and
     * `Backups::backup`, so they still render in `bin/grav scheduler -j` and the
     * Admin scheduler. Deliberately NOT `addCommand('php', ['bin/plugin', ...])`:
     * that spawns a child PHP resolved from the cron PATH, which on hosts with
     * several PHP versions installed is not necessarily the one Grav requires
     * (vendor/composer/platform_check.php then aborts every single run).
     */
    public function onSchedulerInitialized(Event $event): void
    {
        $scheduler = $event['scheduler'];
        $fetchAt   = (string) ($this->config->get('plugins.instagram-feed.fetch_at') ?: '*/30 * * * *');
        $refreshAt = (string) ($this->config->get('plugins.instagram-feed.refresh_at') ?: '17 4 * * *');

        $fetch = $scheduler->addFunction(self::class . '::schedulerFetch', [], 'instagram-fetch-feed');
        $fetch->at($fetchAt);
        $fetch->output('logs/instagram-scheduler.out');

        $refresh = $scheduler->addFunction(self::class . '::schedulerRefreshToken', [], 'instagram-refresh-token');
        $refresh->at($refreshAt);
        $refresh->output('logs/instagram-scheduler.out');
    }

    /**
     * Scheduler entry point for the periodic feed + image cache.
     */
    public static function schedulerFetch(): string
    {
        return self::runScheduled('fetch');
    }

    /**
     * Scheduler entry point for the daily long-lived token refresh.
     */
    public static function schedulerRefreshToken(): string
    {
        return self::runScheduled('refresh-token');
    }

    /**
     * Run an action inside the scheduler process and report it the way Job::exec()
     * expects: the returned string becomes the job output, and a RuntimeException
     * (the only exception it rescues) marks the job failed. Anything else escaping
     * from here would abort the whole scheduler run, so everything is contained.
     */
    private static function runScheduled(string $action): string
    {
        $grav = Grav::instance();

        try {
            $feed = new InstagramFeed($grav, (array) $grav['config']->get('plugins.instagram-feed'));
            $result = $action === 'refresh-token' ? $feed->refreshToken() : $feed->run();
        } catch (Throwable $e) {
            throw new RuntimeException($action . ' failed: ' . $e->getMessage(), 0, $e);
        }

        $json = (string) json_encode($result, JSON_UNESCAPED_SLASHES);
        if (empty($result['ok'])) {
            throw new RuntimeException($json);
        }

        return $json;
    }

    /**
     * Validate the secret key, run the fetch, emit JSON and exit.
     */
    private function handleWebhook(string $route, string $path): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        $expected = (string) $this->config->get('plugins.instagram-feed.webhook_key', '');
        $given = (string) ($this->grav['uri']->query('key') ?? '');
        if ($given === '' && isset($_SERVER['HTTP_X_WEBHOOK_KEY'])) {
            $given = (string) $_SERVER['HTTP_X_WEBHOOK_KEY'];
        }

        if ($expected === '' || !hash_equals($expected, $given)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'forbidden']);
            exit;
        }

        $action = trim(substr($path, strlen($route)), '/');
        $feed = new InstagramFeed($this->grav, (array) $this->config->get('plugins.instagram-feed'));
        if ($action === '' || $action === 'fetch') {
            $result = $feed->run();
        } elseif ($action === 'refresh-token') {
            $result = $feed->refreshToken();
        } else {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'unknown action: ' . $action]);
            exit;
        }

        http_response_code(!empty($result['ok']) ? 200 : 500);
        echo json_encode($result);
        exit;
    }
}
