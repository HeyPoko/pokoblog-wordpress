# PokoBlog for WordPress

The WordPress half of the PokoBlog connector. A customer installs it, copies one
key out of **Settings → PokoBlog**, and pastes it into PokoBlog's Connections
screen. From then on every article PokoBlog publishes becomes a real post in
their database.

Not part of the pnpm workspace, and it must not be added to one. It is PHP, it
ships to customers as a zip, and it has no build step.

## Why it exists

Every other way an article reaches a customer's site is client-side. The embed
widget writes the blog into their page with JavaScript, and most AI crawlers do
not run JavaScript — GPTBot, ClaudeBot and PerplexityBot fetch HTML and read
what comes back. To those agents an embedded blog is an empty div, and no
amount of better JavaScript changes that.

Here the article is a row in `wp_posts`, rendered by the customer's theme, at
their permalink, in HTML. Anything that can read a web page can read it.

## Installing

1. Copy `plugins/wordpress/` into `wp-content/plugins/pokoblog/` (or zip it and
   upload it through **Plugins → Add New → Upload**).
2. Activate. A key is minted on activation.
3. **Settings → PokoBlog** → copy the key.
4. In PokoBlog: Connections → WordPress → paste the site address and the key.

PokoBlog calls the site's `/verify` route before it stores anything, so a wrong
key or a missing plugin is a message at that moment rather than a month of
articles going nowhere.

## The REST routes

All four live under `pokoblog/v1` and all four require the API key, in
`X-PokoBlog-Key` or as `Authorization: Bearer`.

| Route              | Method | What it does                             |
| ------------------ | ------ | ---------------------------------------- |
| `/publish`         | POST   | Create or update one post                |
| `/verify`          | GET    | Is this key right, and what is this site |
| `/setup-indexnow`  | POST   | Mint an IndexNow key and publish it      |
| `/indexnow-status` | GET    | Is that key still readable               |

`/publish` takes `article_id`, `title` and `content` (all required) plus `slug`,
`excerpt`, `status`, `meta_title`, `meta_description`, `focus_keyword`,
`featured_image_url`, `featured_image_alt` and `category`.

`article_id` is required rather than optional and it is PokoBlog's own row id.
It is stored as post meta and looked up before every write, which is what makes
sending the same article twice update one post instead of making two.

## The decisions worth knowing before you change anything

**The key comparison is constant-time over digests, not over the keys.**
`hash_equals` alone still returns early when the two strings are different
lengths, which leaks the key's length. Hashing both sides first means the
comparison always runs over 64 fixed characters. See `class-pokoblog-key.php`.

**A slug conflict makes the article a draft.** If the address PokoBlog wants is
already held by a post or page the customer wrote, the article is written in
full as a draft at that address and the response says so. It is not published at
`slug-2`: PokoBlog has already shown the customer a link to `/slug`, and
publishing somewhere else while showing that link is the product stating
something untrue about their own site. Their page is never overwritten.

**The plugin never un-publishes.** The only status transition it makes on an
existing post is draft → publish. A customer who switches PokoBlog to draft mode
is talking about the articles they have not seen yet, not asking for a live page
to be taken down.

**A trashed post stays trashed.** If the customer binned an article's post, a
republish reports that back rather than restoring it or making a second copy.

**Nothing is fetched except the one image URL in the request.** There is no
endpoint here that takes a URL and returns its contents. The featured image is
fetched with `download_url()`, which goes through `wp_safe_remote_get()` — that
is the `reject_unsafe_urls` path, so loopback and private ranges, odd ports and
non-http schemes are refused by WordPress itself.

**It never calls PokoBlog.** Nothing polls or phones home. A site whose owner
blocks all outbound traffic to us keeps working, and a PokoBlog outage is
invisible from inside WordPress.

## The SEO bridge

The meta description and focus keyword are written into whichever of these is
installed, and into none of the others:

| Plugin         | Where it actually stores things                                                 |
| -------------- | ------------------------------------------------------------------------------- |
| Yoast SEO      | `_yoast_wpseo_title`, `_yoast_wpseo_metadesc`, `_yoast_wpseo_focuskw` post meta |
| Rank Math      | `rank_math_title`, `rank_math_description`, `rank_math_focus_keyword` post meta |
| All in One SEO | Its own `{prefix}aioseo_posts` table — **not** post meta                        |

AIOSEO is the one that catches people out. `_aioseo_description` exists in
`wp_postmeta`, but AIOSEO _writes_ it for multilingual plugins to translate and
never reads it back, so a connector that sets it appears to work and changes
nothing on the site. There is no `_aioseo_focus_keyword` key at all. The bridge
goes through `AIOSEO\Plugin\Common\Models\Post::savePost()` instead.

## IndexNow

The plugin hosts the key; PokoBlog submits the URLs. That split is the protocol:
only the site can publish `https://example.com/<key>.txt`, and only PokoBlog
knows when an article went live.

If the site root is not writable — a git deploy, a read-only image, most managed
hosts — the key is served by WordPress itself instead, from the same address.
That works wherever the web server hands unknown paths to WordPress, which is
what pretty permalinks do. `/indexnow-status` reports which of the two is in
use.

## Uninstalling

Deleting the plugin removes every `pokoblog_` option, the IndexNow key file, and
every `_pokoblog_` post meta key **except one**. The posts stay: they are the
customer's articles, with readers and links pointing at them.

The exception is `_pokoblog_article_id`, and it is deliberate. It is the only
thing tying a post to the article it came from, so deleting it means that
uninstalling and reinstalling — the first thing anyone does when a connection
misbehaves — republishes every article the customer has ever had, next to the
copy that is already there. A few kilobytes of invisible metadata is the better
end of that trade. To remove it anyway:

```sql
DELETE FROM wp_postmeta WHERE meta_key = '_pokoblog_article_id';
```

## Tests

```sh
plugins/wordpress/bin/test                                   # everything
plugins/wordpress/bin/test --only "the same article sent twice"  # one test
```

`bin/test` uses `php` if there is one on `PATH` and otherwise runs the suite in a
PHP container, so it works on a machine set up only for the TypeScript half.
Override the image with `POKOBLOG_PHP_IMAGE=php:8.3-cli`. Do not run it as root:
one test makes a directory read-only to check the IndexNow fallback, and root
ignores permission bits.

There is no PHPUnit and no WordPress test scaffold. `tests/stubs/wordpress.php`
fakes the twenty-odd core functions this plugin calls, `tests/run.php` starts one
PHP process per test file — three of the things the plugin branches on are
constants that cannot be undefined — and the REST handlers are exercised end to
end against the fake database.

**What that proves and what it does not.** It proves what this plugin does: which
functions it calls, with what, and what it does with the answers. It does not
prove what WordPress does — `wp_kses_post` in the stubs is a stand-in, so the
sanitising test shows that article HTML goes through the sanitizer rather than
being written raw, not that kses is correct. Not covered: the admin screen's
markup, the image download against a real HTTP server, the IndexNow virtual
handler against a real web server, and the three SEO plugins themselves (their
key names were read out of their published source and are quoted in
`includes/class-pokoblog-seo.php`).
