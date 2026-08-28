<?php
/**
 * Plugin Name: PokoBlog
 * Plugin URI: https://pokoblog.com/wordpress
 * Description: Publishes the articles PokoBlog writes for you as real posts on this site.
 * Version: 1.0.0
 * Author: PokoBlog
 * Author URI: https://pokoblog.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: pokoblog
 * Requires at least: 5.6
 * Requires PHP: 7.4
 *
 * ---------------------------------------------------------------------------
 *
 * Why this plugin exists, because it decides everything below it.
 *
 * Every other way an article reaches a customer's site is client-side. The
 * embed widget writes the blog into their page with JavaScript, and most AI
 * crawlers do not run JavaScript -- GPTBot, ClaudeBot and PerplexityBot fetch
 * HTML and read what comes back. To those agents an embedded blog is an empty
 * div, and no amount of better JavaScript changes that.
 *
 * Here the article becomes a row in `wp_posts`. The customer's theme renders
 * it, at the customer's own permalink, in HTML, with no script involved.
 * Anything that can read a web page can read it. That is the entire point, and
 * it is the tie-breaker whenever a trade-off in this plugin is close.
 *
 * ## What this plugin does not do
 *
 * It does not call PokoBlog. Nothing here polls, phones home, or needs our
 * servers to be reachable; a site whose owner blocks all outbound traffic to us
 * keeps working, and a PokoBlog outage is invisible from inside WordPress. The
 * plugin receives, and the only outbound request it ever makes is fetching the
 * one image URL named in a request that already proved it holds the API key --
 * see `class-pokoblog-media.php`, which states the rule and enforces it.
 *
 * ## What it leaves behind
 *
 * Options prefixed `pokoblog_`, post meta prefixed `_pokoblog_`, one optional
 * text file in the site root for IndexNow, and posts. `uninstall.php` removes
 * the first three; the posts stay, because they are the customer's content.
 * The one deliberate exception is argued in `uninstall.php` itself.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'POKOBLOG_VERSION', '1.0.0' );
define( 'POKOBLOG_FILE', __FILE__ );

/**
 * The REST namespace, and the prefix every stored key is built from.
 *
 * One constant rather than the string repeated: the namespace appears in the
 * routes, in the settings screen's instructions and in PokoBlog's own client,
 * and a namespace spelled two ways is a connector that verifies and then cannot
 * publish.
 */
define( 'POKOBLOG_REST_NAMESPACE', 'pokoblog/v1' );

require_once __DIR__ . '/includes/class-pokoblog-key.php';
require_once __DIR__ . '/includes/class-pokoblog-payload.php';
require_once __DIR__ . '/includes/class-pokoblog-seo.php';
require_once __DIR__ . '/includes/class-pokoblog-media.php';
require_once __DIR__ . '/includes/class-pokoblog-publisher.php';
require_once __DIR__ . '/includes/class-pokoblog-indexnow.php';
require_once __DIR__ . '/includes/class-pokoblog-rest.php';
require_once __DIR__ . '/includes/class-pokoblog-admin.php';

/**
 * Wiring, and nothing else.
 *
 * Each class registers its own hooks and holds no state between requests, so
 * the load order above is the only relationship between them. Nothing here
 * touches the database: an option read at file scope runs on every request of
 * every page of the site, including the ones this plugin has no business being
 * part of.
 */
function pokoblog_boot() {
	PokoBlog_Admin::register();
	PokoBlog_Rest::register();
	PokoBlog_IndexNow::register();
}

pokoblog_boot();

register_activation_hook(
	__FILE__,
	/*
	 * Minting the key on activation rather than on first use is what makes the
	 * settings screen able to show it immediately. A customer who has just
	 * installed the plugin is looking at that screen with PokoBlog open in the
	 * other tab; "come back later, it will appear" is not an instruction
	 * anybody follows.
	 */
	[ 'PokoBlog_Key', 'ensure' ]
);
