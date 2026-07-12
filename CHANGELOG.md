# v1.0.0
## 07/12/2026

1. [](#new)
    * Initial release.
    * `fetch` action: caches the latest Instagram posts to `feed.json` and
      self-hosts a resized WebP copy of every post still (PHP-GD).
    * `refresh-token` action: refreshes the long-lived access token.
    * Each action runs via the Grav scheduler, the `bin/plugin` CLI, or a
      secret-key webhook (`/_instagram/fetch`, `/_instagram/refresh-token`).
