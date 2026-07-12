<?php

namespace Grav\Plugin\Console;

use Grav\Common\Grav;
use Grav\Console\ConsoleCommand;
use Grav\Plugin\InstagramFeed\InstagramFeed;

require_once __DIR__ . '/../classes/InstagramFeed.php';

/**
 * `bin/plugin instagram-feed refresh-token`
 *
 * Refreshes the long-lived Instagram access token (extends it ~60 days). Used for
 * manual runs and as the command behind the plugin's daily token scheduler job.
 */
class RefreshTokenCommand extends ConsoleCommand
{
    protected function configure()
    {
        $this
            ->setName('refresh-token')
            ->setDescription('Refresh the long-lived Instagram access token (extends it ~60 days).')
            ->setHelp('Reads user/data/instagram/token.json, asks the Graph API for a fresh token, and writes it back. Keeps the old token on failure.');
    }

    protected function serve()
    {
        $grav = Grav::instance();
        $config = (array) $grav['config']->get('plugins.instagram-feed');

        $result = (new InstagramFeed($grav, $config))->refreshToken();

        $this->output->writeln(json_encode($result, JSON_UNESCAPED_SLASHES));
        if (empty($result['ok'])) {
            return 1;
        }
        return 0;
    }
}
