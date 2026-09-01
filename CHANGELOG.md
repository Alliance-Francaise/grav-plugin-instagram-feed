# v1.0.1
## 09/01/2026

1. [](#improved)
    * Pinned the Graph API version to `v26.0`. The calls were unversioned, so
      Meta served whichever version was oldest-still-alive — a breakage waiting
      for a retirement date.
    * `token.json` is now read through one shared `readToken()` helper that
      checks the token *family*. Feeding `graph.instagram.com` a Facebook Login
      token (`EAA…`) instead of an Instagram Login one (`IGAA…`) previously
      surfaced only as Meta's `Cannot parse access token`, which named neither
      cause nor fix; it now reports the mismatch and how to regenerate.
    * README: spelled out that the token must be `IGAA…`, with the
      Business/Creator + `instagram_business_basic` prerequisites and the
      long-lived exchange call.

2. [](#bugfix)
    * Dropped a stale reference to `bin/README-instagram.md`, a file that no
      longer exists, from the missing-token error.

# v1.0.0
## 07/12/2026

1. [](#new)
    * Initial release.
    * `fetch` action: caches the latest Instagram posts to `feed.json` and
      self-hosts a resized WebP copy of every post still (PHP-GD).
    * `refresh-token` action: refreshes the long-lived access token.
    * Each action runs via the Grav scheduler, the `bin/plugin` CLI, or a
      secret-key webhook (`/_instagram/fetch`, `/_instagram/refresh-token`).
