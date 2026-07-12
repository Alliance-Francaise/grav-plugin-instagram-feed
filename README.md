# Instagram Feed — Grav Plugin

A [Grav](https://getgrav.org) plugin that pulls your Instagram feed **server-side**
and, crucially, **downloads and resizes every post image into a small self-hosted
WebP** (≈13 KB each instead of the full-resolution Instagram CDN files). Your theme
then renders tiny local thumbnails — fast, private, and immune to Instagram's
expiring CDN links.

It also keeps your long-lived access token alive, so once set up it just runs.

> Built for the [Alliance Française de Sendai](https://afsendai.com) website, but
> written to be reusable on any Grav site.

---

## Why

The usual ways to show Instagram on a site are bad for visitors:

- **Official embeds** (`embed.js`) load Meta tracking scripts and cookies.
- **Hotlinking the CDN** ships full-resolution images (hundreds of KB each) and
  the signed URLs expire, so images silently break after a while.
- **Auto-playing reels** can push tens of MB of video onto someone who just opened
  your homepage.

This plugin fixes all three: no third-party JavaScript, images are resized and
served from your own domain (and never expire), and video is left to the theme to
load *on demand* only.

## Features

- 📥 Caches the latest *N* posts to a single JSON file your theme reads.
- 🖼️ **Resizes & self-hosts every post still** as WebP via PHP-GD (width
  configurable, aspect-ratio preserved, never upscaled).
- ♻️ Images are cached by post id — re-runs only fetch *new* posts, and stale
  files are pruned automatically.
- 🔑 **Refreshes the long-lived token** automatically (never clobbers it on
  failure).
- ⏱️ Runs three ways: **Grav scheduler**, **CLI**, or a **secret-key webhook**.
- 🔒 No third-party scripts or cookies. Only reel *videos* stream from Instagram,
  and only when your theme chooses to load them.

## Requirements

- Grav 1.7+
- PHP 8.0+ with the **GD** extension compiled with **WebP** support
  (`php -r 'var_dump(function_exists("imagewebp"));'` → `true`)
- cURL
- An Instagram **Business** or **Creator** account and a **long-lived access
  token** (Instagram API with Instagram Login / Basic Display)

## Installation

Clone into your Grav `user/plugins` folder as **`instagram-feed`** (the folder must
match the slug):

```bash
cd /path/to/grav/user/plugins
git clone https://github.com/Eymeric65/grav-plugin-instagram-feed.git instagram-feed
```

Then clear the cache: `bin/grav clearcache`.

## Setup

### 1. Seed your token (once)

The plugin *refreshes* a token but can't create the first one. Get a long-lived
token, then on the server:

```bash
mkdir -p user/data/instagram
echo '{"access_token":"YOUR_LONG_LIVED_TOKEN"}' > user/data/instagram/token.json
chmod 600 user/data/instagram/token.json
```

### 2. Configure

In `user/config/plugins/instagram-feed.yaml` (or **Admin → Plugins → Instagram
Feed**), set a webhook key if you want the webhook, and adjust the image size:

```yaml
enabled: true
route: /_instagram
webhook_key: 'paste-a-secret-here'   # php -r 'echo bin2hex(random_bytes(24));'
image_width: 250       # cached thumbnail width in px
webp_quality: 80
max_items: 120         # how many latest posts to cache
fetch_at: '*/30 * * * *'   # scheduler cadence: cache feed + images
refresh_at: '17 4 * * *'   # scheduler cadence: refresh token (daily)
```

### 3. Prime the cache

```bash
bin/plugin instagram-feed refresh-token   # normalises + extends the token
bin/plugin instagram-feed fetch           # writes feed.json + the image cache
```

You should now have `user/data/instagram/feed.json` and a set of
`user/data/instagram/cache/*.webp` files.

---

## Usage

The plugin exposes **two actions** — `fetch` and `refresh-token` — each runnable
**three ways**.

### Scheduler (automatic, recommended)

The plugin registers two native Grav scheduler jobs itself — you don't add
anything to `scheduler.yaml`:

| Job | Default cadence | Config key |
|---|---|---|
| `instagram-fetch-feed` | every 30 min | `fetch_at` |
| `instagram-refresh-token` | daily 04:17 | `refresh_at` |

Confirm Grav sees them:

```bash
bin/grav scheduler -j
```

(For the scheduler to fire, Grav's one crontab line must be installed —
`* * * * * cd /path/to/grav && php bin/grav scheduler 1>> /dev/null 2>&1`.)

### CLI

```bash
bin/plugin instagram-feed fetch
bin/plugin instagram-feed refresh-token
```

Each prints a JSON summary, e.g.
`{"ok":true,"count":44,"reels":6,"images_cached":3,"images_failed":0,"pages":1}`.

### Webhook

Handy for triggering a refresh from an uptime monitor, a deploy hook, or a phone
shortcut. Requires `webhook_key` to be set. Pass the key as `?key=…` or an
`X-Webhook-Key` header:

```bash
curl "https://your-site.com/_instagram/fetch?key=YOUR_KEY"
curl "https://your-site.com/_instagram/refresh-token?key=YOUR_KEY"
```

A missing or wrong key returns `403 {"ok":false,"error":"forbidden"}`.

---

## Rendering the feed in your theme

The plugin only *produces* the data; your theme decides how to show it. It writes
`user/data/instagram/feed.json`:

```json
{
  "fetched_at": 1783791002,
  "count": 44,
  "image_width": 250,
  "items": [
    {
      "id": "1812…",
      "type": "IMAGE",              // IMAGE | VIDEO | CAROUSEL_ALBUM
      "product_type": "FEED",       // FEED | REELS | …
      "reel": false,                // true when product_type == REELS
      "permalink": "https://www.instagram.com/p/…/",
      "caption": "…",
      "image": "https://…cdninstagram.com/…",              // original CDN still (fallback)
      "image_local": "/user/data/instagram/cache/1812….webp", // self-hosted, resized
      "video": "",                  // mp4 CDN url for VIDEO posts (empty otherwise)
      "timestamp": "2026-07-10T04:00:46+0000"
    }
  ]
}
```

Add a small Twig function to your theme's PHP to expose it (register it in
`onTwigInitialized` / `onTwigExtensions`):

```php
use Grav\Common\Grav;
use Twig\TwigFunction;

$twig->addFunction(new TwigFunction('instagram_feed', static function (int $limit = 0) {
    $file = Grav::instance()['locator']->findResource('user-data://instagram/feed.json', true, true);
    if (!$file || !is_file($file)) {
        return [];
    }
    $items = json_decode(file_get_contents($file), true)['items'] ?? [];
    return $limit > 0 ? array_slice($items, 0, $limit) : $items;
}));
```

Then in a template:

```twig
{% for post in instagram_feed(12) %}
  <a href="{{ post.permalink }}" target="_blank" rel="noopener">
    <img src="{{ post.image_local ?: post.image }}" loading="lazy"
         alt="{{ post.caption|striptags|slice(0, 80) }}">
  </a>
{% endfor %}
```

> **Tip (bandwidth):** default your grid to **image posts** and let visitors opt
> into reels, loading a reel's `video` mp4 only on click / when scrolled into view.
> That way a first visit downloads only the tiny local thumbnails and **zero video
> bytes**. The AF Sendai theme this was built for does exactly that.

---

## How it works

- **`fetch`** pages through `graph.instagram.com/me/media`, and for each post
  downloads the still (a photo, or a video's poster), resizes it with GD to a WebP
  no wider than `image_width`, and stores it as `cache/<post-id>.webp`. It writes
  `feed.json` with the local path, prunes cache files for posts that dropped out of
  the feed, and logs a summary. Videos themselves are **not** downloaded.
- **`refresh-token`** exchanges the current long-lived token for a fresh one
  (~60-day window) and writes it back atomically (`chmod 600`). It **never
  overwrites the stored token unless the API returns a new one**, so a failed or
  too-early refresh (Instagram only re-issues once a token is >24 h old) leaves the
  old token untouched.

Everything logs to `logs/grav.log` in Grav's line format, so runs appear (and
filter by level) in **Admin → Tools → Logs**.

### Where things live

```
user/data/instagram/token.json       the secret token (chmod 600, keep private)
user/data/instagram/feed.json         the cache your theme reads
user/data/instagram/cache/*.webp      self-hosted, resized post stills
```

`user/data/` is user-writable and **should not** be committed or deployed — seed
the token on the server once, then let the plugin regenerate the feed + images
there. The `cache/` folder is served directly (WebP is web-accessible under
`user/data/` per Grav's default `.htaccess` / nginx rules).

## Privacy / GDPR

No third-party JavaScript and no image hotlinking: post stills are served from your
own domain. Only reel **videos** stream from Instagram's CDN, and only when your
theme loads them. Nothing about a visitor is sent to Meta on page load.

## Configuration reference

| Key | Default | Meaning |
|---|---|---|
| `enabled` | `true` | Plugin on/off |
| `route` | `/_instagram` | Webhook base path |
| `webhook_key` | `''` | Secret for the webhook; blank disables it (CLI/scheduler still work) |
| `image_width` | `250` | Cached thumbnail width in px (aspect kept, never upscaled) |
| `webp_quality` | `80` | WebP quality (1–100) |
| `max_items` | `120` | How many latest posts to cache |
| `fetch_at` | `*/30 * * * *` | Fetch scheduler cadence |
| `refresh_at` | `17 4 * * *` | Token-refresh scheduler cadence |

## License

[MIT](LICENSE) © Alliance Française de Sendai
