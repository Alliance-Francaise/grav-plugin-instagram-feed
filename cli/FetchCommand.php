<?php

namespace Grav\Plugin\Console;

use Grav\Common\Grav;
use Grav\Console\ConsoleCommand;
use Grav\Plugin\InstagramFeed\InstagramFeed;

require_once __DIR__ . '/../classes/InstagramFeed.php';

/**
 * `bin/plugin instagram-feed fetch`
 *
 * Fetches the Instagram feed and caches resized post images. Used for manual
 * runs and as the command behind the plugin's Grav scheduler job.
 */
class FetchCommand extends ConsoleCommand
{
    protected function configure()
    {
        $this
            ->setName('fetch')
            ->setAliases(['fetch-feed'])
            ->setDescription('Fetch the Instagram feed and cache resized post images (WebP).')
            ->setHelp('Pages through /me/media, resizes each still with PHP-GD, and writes user/data/instagram/feed.json.');
    }

    protected function serve()
    {
        $grav = Grav::instance();
        $config = (array) $grav['config']->get('plugins.instagram-feed');

        $result = (new InstagramFeed($grav, $config))->run();

        $this->output->writeln(json_encode($result, JSON_UNESCAPED_SLASHES));
        if (empty($result['ok'])) {
            return 1;
        }
        return 0;
    }
}
