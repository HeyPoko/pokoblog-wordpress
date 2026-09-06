<?php
/**
 * A WordPress-shaped thing, small enough to read in one sitting.
 *
 * ## Why this exists instead of the WordPress test scaffold
 *
 * The conventional answer is `phpunit` plus `wp-develop`'s scaffold: it gives
 * you a real WordPress against a real MySQL, and it needs both of those
 * installed, an SVN checkout of core, and a database the test run is allowed to
 * drop. This repository has no `composer` and no `phpunit`, the plugin is not
 * part of its pnpm workspace and must not be dragged into one, and standing all
 * of that up would be a larger change than the plugin. A `php` binary is enough
 * to run what is here, and `tests/run.php` needs nothing else.
 *
 * So: in-memory fakes for the twenty-odd WordPress functions this plugin
 * actually calls, and a runner in `tests/run.php`. What that buys is that the
 * REST handlers are exercised end to end -- a request goes in, a post comes out
 * of the fake database, and the assertions are about that post -- rather than
 * only the pure helpers underneath them.
 *
 * ## What this therefore does and does not prove
 *
 * It proves what *this plugin* does: which functions it calls, in what order,
 * with what arguments, and what it does with the answers. Every behaviour the
 * brief asks for is a decision made in the plugin's own code, and that is what
 * is under test here.
 *
 * It does not prove what WordPress does. In particular `wp_kses_post()` below
 * is a stand-in, not a copy: the sanitising test shows that article HTML is
 * routed through the sanitizer rather than written raw, which is the thing this
 * plugin can get wrong. Whether kses itself is correct is WordPress's test
 * suite's business. The same caveat applies to `wp_insert_post`'s slug
 * behaviour -- the rule it implements below (drafts keep their requested slug,
 * published posts get uniquified) is copied from core's `wp_insert_post`, and
 * the tests that depend on it are testing our reaction to that rule, not the
 * rule.
 *
 * Also untested here, and said out loud rather than left to be discovered: the
 * admin screen's markup, the featured-image download against a real HTTP
 * server, the IndexNow virtual handler's interaction with a web server, and the
 * three SEO plugins themselves. The SEO bridge is tested against fakes of
 * Yoast, Rank Math and AIOSEO built from their published source; the key names
 * were read out of that source and are quoted in `class-pokoblog-seo.php`.
 */

/* -------------------------------------------------------------------------- */
/* State                                                                      */
/* -------------------------------------------------------------------------- */

/**
 * Everything the fake site knows, in one global.
 *
 * A global rather than a class because that is what WordPress is, and because
 * the plugin reaches for these as free functions -- a fake with a nicer shape
 * would be a fake of something else.
 */
function pokoblog_test_state() {
	if ( ! isset( $GLOBALS['pokoblog_test'] ) ) {
		pokoblog_test_reset();
	}

	return $GLOBALS['pokoblog_test'];
}

/**
 * Empty the site.
 *
 * Called by the runner before every test. Every one of these suites writes to
 * the same global, so a suite that did not start from a known state would pass
 * or fail depending on which file ran before it.
 */
function pokoblog_test_reset() {
	$GLOBALS['pokoblog_test'] = [
		'options'   => [],
		'posts'     => [],
		'postmeta'  => [],
		'next_id'   => 1,
		'hooks'     => [],
		'downloads' => [],
		'thumbnails' => [],
		'categories' => [],
		'styles'    => [],
		'singular'  => null,
	];

	/* The plugins the fake site has installed. See `tests/stubs/seo.php`. */
	$GLOBALS['pokoblog_test_seo'] = [];
}

function pokoblog_test_set( $key, $value ) {
	$GLOBALS['pokoblog_test'][ $key ] = $value;
}

/* -------------------------------------------------------------------------- */
/* The page being viewed, and the styles put on it                            */
/* -------------------------------------------------------------------------- */

/**
 * Which single post, if any, the fake site is showing.
 *
 * Null for everything that is not a single post: an index, an archive, a page.
 * `PokoBlog_Styles` is the only caller and it asks both questions, so both
 * answers come out of the one value.
 */
function pokoblog_test_view( $post_id ) {
	$GLOBALS['pokoblog_test']['singular'] = $post_id;
}

function is_singular( $type = '' ) {
	return null !== $GLOBALS['pokoblog_test']['singular'];
}

function get_the_ID() {
	return $GLOBALS['pokoblog_test']['singular'] ?? false;
}

function wp_register_style( $handle, $src, $deps = [], $version = false ) {
	$GLOBALS['pokoblog_test']['styles'][ $handle ] = [ 'inline' => '' ];

	return true;
}

function wp_enqueue_style( $handle ) {
	$GLOBALS['pokoblog_test']['styles'][ $handle ]['enqueued'] = true;
}

function wp_add_inline_style( $handle, $css ) {
	$GLOBALS['pokoblog_test']['styles'][ $handle ]['inline'] .= $css;

	return true;
}

/* -------------------------------------------------------------------------- */
/* Hooks                                                                      */
/* -------------------------------------------------------------------------- */

function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
	$GLOBALS['pokoblog_test']['hooks'][ $hook ][] = $callback;

	return true;
}

function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {
	return add_action( $hook, $callback, $priority, $args );
}

function do_action( $hook ) {
	return null;
}

function apply_filters( $hook, $value ) {
	return $value;
}

function register_activation_hook( $file, $callback ) {
	return true;
}

function register_rest_route( $namespace, $route, $args ) {
	$GLOBALS['pokoblog_test']['hooks']['rest_routes'][] = $namespace . $route;

	return true;
}

function plugin_basename( $file ) {
	return basename( dirname( $file ) ) . '/' . basename( $file );
}

function plugin_dir_path( $file ) {
	return dirname( $file ) . '/';
}

function plugin_dir_url( $file ) {
	return 'https://example.test/wp-content/plugins/pokoblog/';
}

/* -------------------------------------------------------------------------- */
/* Errors and responses                                                       */
/* -------------------------------------------------------------------------- */

class WP_Error {
	public $code;
	public $message;
	public $data;

	public function __construct( $code = '', $message = '', $data = [] ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_message() {
		return $this->message;
	}
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

class WP_REST_Response {
	public $data;
	public $status;

	public function __construct( $data = null, $status = 200 ) {
		$this->data   = $data;
		$this->status = $status;
	}

	public function get_data() {
		return $this->data;
	}

	public function get_status() {
		return $this->status;
	}
}

/**
 * Just enough of `WP_REST_Request` for the handlers: headers and a JSON body.
 *
 * Header lookup is case-insensitive and dash-insensitive, like the real one --
 * which matters, because the plugin asks for `x-pokoblog-key` and PokoBlog
 * sends `X-PokoBlog-Key`.
 */
class WP_REST_Request {
	private $headers = [];
	private $json    = [];
	private $body    = [];

	public function __construct( $json = [], $headers = [] ) {
		$this->json = $json;

		foreach ( $headers as $name => $value ) {
			$this->headers[ self::normalize( $name ) ] = $value;
		}
	}

	private static function normalize( $name ) {
		return strtolower( str_replace( '_', '-', $name ) );
	}

	public function get_header( $name ) {
		$key = self::normalize( $name );

		return isset( $this->headers[ $key ] ) ? $this->headers[ $key ] : null;
	}

	public function get_json_params() {
		return $this->json;
	}

	public function get_body_params() {
		return $this->body;
	}
}

/* -------------------------------------------------------------------------- */
/* Options                                                                    */
/* -------------------------------------------------------------------------- */

function get_option( $name, $default = false ) {
	$options = $GLOBALS['pokoblog_test']['options'];

	return array_key_exists( $name, $options ) ? $options[ $name ] : $default;
}

function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['pokoblog_test']['options'][ $name ] = $value;

	return true;
}

/**
 * The atomic half of the publish lock.
 *
 * Returns false when the option already exists, which is what core does and
 * what makes `add_option` usable as a lock at all. Getting this wrong in the
 * fake would make the lock test pass against a fake that cannot fail.
 */
function add_option( $name, $value, $deprecated = '', $autoload = null ) {
	if ( array_key_exists( $name, $GLOBALS['pokoblog_test']['options'] ) ) {
		return false;
	}

	$GLOBALS['pokoblog_test']['options'][ $name ] = $value;

	return true;
}

function delete_option( $name ) {
	unset( $GLOBALS['pokoblog_test']['options'][ $name ] );

	return true;
}

/* -------------------------------------------------------------------------- */
/* Sanitizers                                                                 */
/* -------------------------------------------------------------------------- */

function sanitize_text_field( $value ) {
	if ( ! is_scalar( $value ) ) {
		return '';
	}

	$value = wp_strip_all_tags( (string) $value );
	$value = preg_replace( '/[\r\n\t ]+/', ' ', $value );

	return trim( $value );
}

function sanitize_textarea_field( $value ) {
	if ( ! is_scalar( $value ) ) {
		return '';
	}

	return trim( wp_strip_all_tags( (string) $value ) );
}

function wp_strip_all_tags( $value ) {
	$value = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $value );

	return trim( strip_tags( $value ) );
}

function sanitize_title( $value ) {
	$value = strtolower( (string) $value );
	$value = preg_replace( '/[^a-z0-9\s-]/', '', $value );
	$value = preg_replace( '/[\s-]+/', '-', $value );

	return trim( $value, '-' );
}

function sanitize_file_name( $value ) {
	$value = preg_replace( '/[^A-Za-z0-9._-]/', '-', (string) $value );

	return trim( preg_replace( '/-+/', '-', $value ), '-' );
}

function absint( $value ) {
	return abs( (int) $value );
}

/**
 * A stand-in for `wp_kses_post`, and only a stand-in.
 *
 * WordPress's is nine hundred lines and depends on a dozen other core
 * functions; copying it here would be copying the thing under test's dependency
 * into the test. What this does is the recognisable shape of it -- a tag
 * allowlist, event handlers removed, script-bearing URL schemes refused -- so
 * that a test can assert an injected payload does not survive the trip.
 *
 * Read the sanitising test as: "the plugin puts article HTML through the
 * sanitizer instead of writing it raw". It is not, and cannot be, a test of
 * kses.
 */
function wp_kses_post( $html ) {
	$html = (string) $html;

	/* Script and style elements go entirely, contents included. */
	$html = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $html );
	$html = preg_replace( '@</?(script|style)[^>]*>@i', '', $html );

	/*
	 * The subset of WordPress's own `$allowedposttags` that PokoBlog can emit,
	 * taken from `wp-includes/kses.php` rather than from what would be
	 * convenient. `div`, `section`, `sup` and `del` are in it, which is what
	 * lets a table wrapper, a footnote and a strikethrough survive a delivery.
	 *
	 * `input` is deliberately absent, and it is absent from WordPress too. That
	 * is the reason `workflow/html.ts` writes a task list as a character rather
	 * than as a checkbox: a checkbox is stripped here, and the reader is left
	 * with a label and no way to tell done from not done.
	 */
	$allowed = [ 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'blockquote', 'a', 'strong', 'em', 'code', 'pre', 'br', 'img', 'figure', 'figcaption', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'hr', 'div', 'section', 'sup', 'sub', 'del', 's', 'span' ];

	$html = preg_replace_callback(
		'@<(/?)([a-zA-Z0-9]+)([^>]*)>@',
		static function ( $match ) use ( $allowed ) {
			$tag = strtolower( $match[2] );

			if ( ! in_array( $tag, $allowed, true ) ) {
				return '';
			}

			$attributes = $match[3];

			/* Event handlers, in any spelling. */
			$attributes = preg_replace( '/\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $attributes );

			/* URL attributes carrying an executable scheme. */
			$attributes = preg_replace(
				'/\s(href|src)\s*=\s*("|\')?\s*(javascript|data|vbscript):[^"\'>]*("|\')?/i',
				'',
				$attributes
			);

			return '<' . $match[1] . $tag . $attributes . '>';
		},
		$html
	);

	return $html;
}

function esc_html( $value ) {
	return htmlspecialchars( (string) $value, ENT_QUOTES );
}

function esc_attr( $value ) {
	return esc_html( $value );
}

function esc_url( $value ) {
	return (string) $value;
}

function esc_url_raw( $value ) {
	return preg_replace( '/[^A-Za-z0-9\-._~:\/?#\[\]@!$&\'()*+,;=%]/', '', (string) $value );
}

function wp_unslash( $value ) {
	if ( is_array( $value ) ) {
		return array_map( 'wp_unslash', $value );
	}

	return is_string( $value ) ? stripslashes( $value ) : $value;
}

function wp_slash( $value ) {
	if ( is_array( $value ) ) {
		return array_map( 'wp_slash', $value );
	}

	return is_string( $value ) ? addslashes( $value ) : $value;
}

function wp_json_encode( $value ) {
	return json_encode( $value );
}

function __( $text, $domain = '' ) {
	return $text;
}

function esc_html__( $text, $domain = '' ) {
	return $text;
}

/* -------------------------------------------------------------------------- */
/* Posts                                                                      */
/* -------------------------------------------------------------------------- */

/**
 * Insert a post, following core's slug rule.
 *
 * The rule, from core's `wp_insert_post`: `wp_unique_post_slug` is called for
 * every status *except* `draft`, `pending` and `auto-draft`. So a published
 * post whose slug is taken becomes `slug-2`, and a draft keeps exactly the name
 * it asked for. The plugin's slug-conflict decision depends on that second half,
 * so the fake has to have it.
 */
/**
 * Unslashed on the way in, because core does.
 *
 * `wp_insert_post()` runs its fields through `wp_unslash()`, which is why every
 * caller in core slashes first -- the REST posts controller included. Without
 * that step modelled here a plugin that forgets to slash passes the suite and
 * strips one level of backslashes on a real site, which is what happened: a
 * shell command was published with `/^\[/` written as `/^[/`.
 */
function pokoblog_test_unslash_fields( $fields ) {
	foreach ( [ 'post_title', 'post_content', 'post_excerpt', 'post_name' ] as $key ) {
		if ( isset( $fields[ $key ] ) ) {
			$fields[ $key ] = wp_unslash( $fields[ $key ] );
		}
	}

	if ( isset( $fields['meta_input'] ) && is_array( $fields['meta_input'] ) ) {
		$fields['meta_input'] = wp_unslash( $fields['meta_input'] );
	}

	return $fields;
}

function wp_insert_post( $fields, $wp_error = false ) {
	$state = &$GLOBALS['pokoblog_test'];

	if ( isset( $fields['ID'] ) && $fields['ID'] ) {
		return wp_update_post( $fields, $wp_error );
	}

	$fields = pokoblog_test_unslash_fields( $fields );

	$id = $state['next_id'];
	$state['next_id']++;

	$post = [
		'ID'           => $id,
		'post_type'    => isset( $fields['post_type'] ) ? $fields['post_type'] : 'post',
		'post_title'   => isset( $fields['post_title'] ) ? $fields['post_title'] : '',
		'post_content' => isset( $fields['post_content'] ) ? $fields['post_content'] : '',
		'post_excerpt' => isset( $fields['post_excerpt'] ) ? $fields['post_excerpt'] : '',
		'post_status'  => isset( $fields['post_status'] ) ? $fields['post_status'] : 'draft',
		'post_author'  => isset( $fields['post_author'] ) ? (int) $fields['post_author'] : 0,
		'post_name'    => '',
		'post_category' => isset( $fields['post_category'] ) ? $fields['post_category'] : [],
	];

	$requested         = isset( $fields['post_name'] ) ? $fields['post_name'] : '';
	$post['post_name'] = pokoblog_test_slug( $requested, $post['post_status'], $id );

	$state['posts'][ $id ] = $post;

	pokoblog_test_apply_meta( $id, $fields );

	return $id;
}

function wp_update_post( $fields, $wp_error = false ) {
	$state = &$GLOBALS['pokoblog_test'];
	$id    = isset( $fields['ID'] ) ? (int) $fields['ID'] : 0;

	if ( ! isset( $state['posts'][ $id ] ) ) {
		return $wp_error ? new WP_Error( 'invalid_post', 'no such post' ) : 0;
	}

	$fields = pokoblog_test_unslash_fields( $fields );

	foreach ( [ 'post_title', 'post_content', 'post_excerpt', 'post_status', 'post_type', 'post_category' ] as $field ) {
		if ( array_key_exists( $field, $fields ) ) {
			$state['posts'][ $id ][ $field ] = $fields[ $field ];
		}
	}

	if ( array_key_exists( 'post_name', $fields ) ) {
		$state['posts'][ $id ]['post_name'] = pokoblog_test_slug(
			$fields['post_name'],
			$state['posts'][ $id ]['post_status'],
			$id
		);
	}

	pokoblog_test_apply_meta( $id, $fields );

	return $id;
}

/** Core's rule, spelled out. See `wp_insert_post` above for the citation. */
function pokoblog_test_slug( $requested, $status, $id ) {
	if ( $requested === '' ) {
		return '';
	}

	if ( in_array( $status, [ 'draft', 'pending', 'auto-draft' ], true ) ) {
		return $requested;
	}

	$slug   = $requested;
	$suffix = 2;

	while ( pokoblog_test_slug_taken( $slug, $id ) ) {
		$slug = $requested . '-' . $suffix;
		$suffix++;
	}

	return $slug;
}

function pokoblog_test_slug_taken( $slug, $ignore ) {
	foreach ( $GLOBALS['pokoblog_test']['posts'] as $post ) {
		if ( (int) $post['ID'] !== (int) $ignore && $post['post_name'] === $slug ) {
			return true;
		}
	}

	return false;
}

function pokoblog_test_apply_meta( $id, $fields ) {
	if ( empty( $fields['meta_input'] ) || ! is_array( $fields['meta_input'] ) ) {
		return;
	}

	foreach ( $fields['meta_input'] as $key => $value ) {
		update_post_meta( $id, $key, $value );
	}
}

function update_post_meta( $post_id, $key, $value ) {
	$GLOBALS['pokoblog_test']['postmeta'][ (int) $post_id ][ $key ] = $value;

	return true;
}

function get_post_meta( $post_id, $key = '', $single = false ) {
	$meta = isset( $GLOBALS['pokoblog_test']['postmeta'][ (int) $post_id ] )
		? $GLOBALS['pokoblog_test']['postmeta'][ (int) $post_id ]
		: [];

	if ( $key === '' ) {
		return $meta;
	}

	if ( ! array_key_exists( $key, $meta ) ) {
		return $single ? '' : [];
	}

	return $single ? $meta[ $key ] : [ $meta[ $key ] ];
}

function get_post_status( $post_id ) {
	$posts = $GLOBALS['pokoblog_test']['posts'];

	return isset( $posts[ (int) $post_id ] ) ? $posts[ (int) $post_id ]['post_status'] : false;
}

function get_post_field( $field, $post_id ) {
	$posts = $GLOBALS['pokoblog_test']['posts'];

	return isset( $posts[ (int) $post_id ][ $field ] ) ? $posts[ (int) $post_id ][ $field ] : '';
}

/**
 * The two query shapes the plugin uses, and no others.
 *
 * A fake `get_posts` that quietly ignored an argument would make a test pass
 * against a query the real WordPress would answer differently, so anything
 * unrecognised is loud rather than ignored.
 */
function get_posts( $args ) {
	$state = $GLOBALS['pokoblog_test'];

	$understood = [ 'post_type', 'post_status', 'meta_key', 'meta_value', 'name', 'numberposts', 'fields', 'suppress_filters', 'no_found_rows' ];

	foreach ( array_keys( $args ) as $key ) {
		if ( ! in_array( $key, $understood, true ) ) {
			throw new RuntimeException( "get_posts() stub does not understand '{$key}'" );
		}
	}

	$types    = isset( $args['post_type'] ) ? (array) $args['post_type'] : [ 'post' ];
	$statuses = isset( $args['post_status'] ) ? (array) $args['post_status'] : [ 'publish' ];
	$limit    = isset( $args['numberposts'] ) ? (int) $args['numberposts'] : 5;

	$matches = [];

	foreach ( $state['posts'] as $post ) {
		if ( ! in_array( 'any', $types, true ) && ! in_array( $post['post_type'], $types, true ) ) {
			continue;
		}

		if ( ! in_array( $post['post_status'], $statuses, true ) ) {
			continue;
		}

		if ( isset( $args['name'] ) && $post['post_name'] !== $args['name'] ) {
			continue;
		}

		if ( isset( $args['meta_key'] ) ) {
			$meta = get_post_meta( $post['ID'], $args['meta_key'], true );

			if ( $meta !== $args['meta_value'] ) {
				continue;
			}
		}

		$matches[] = ( isset( $args['fields'] ) && $args['fields'] === 'ids' ) ? $post['ID'] : $post;

		if ( count( $matches ) >= $limit ) {
			break;
		}
	}

	return $matches;
}

function get_permalink( $post_id ) {
	$name = get_post_field( 'post_name', $post_id );

	return 'https://example.test/' . $name;
}

function get_edit_post_link( $post_id, $context = 'display' ) {
	return 'https://example.test/wp-admin/post.php?post=' . (int) $post_id . '&action=edit';
}

function set_post_thumbnail( $post_id, $attachment_id ) {
	$GLOBALS['pokoblog_test']['thumbnails'][ (int) $post_id ] = (int) $attachment_id;

	return true;
}

function get_cat_ID( $name ) {
	$categories = $GLOBALS['pokoblog_test']['categories'];

	return isset( $categories[ $name ] ) ? (int) $categories[ $name ] : 0;
}

function current_time( $format ) {
	return gmdate( 'Y-m-d H:i:s' );
}

/* -------------------------------------------------------------------------- */
/* Site                                                                       */
/* -------------------------------------------------------------------------- */

function get_bloginfo( $what = 'name' ) {
	return 'Example Site';
}

function get_site_url() {
	return 'https://example.test';
}

function trailingslashit( $value ) {
	return rtrim( (string) $value, '/\\' ) . '/';
}

function wp_parse_url( $url, $component = -1 ) {
	return $component === -1 ? parse_url( $url ) : parse_url( $url, $component );
}

function rest_url( $path = '' ) {
	return 'https://example.test/wp-json/' . ltrim( $path, '/' );
}

function admin_url( $path = '' ) {
	return 'https://example.test/wp-admin/' . ltrim( $path, '/' );
}

/* -------------------------------------------------------------------------- */
/* Files and media                                                            */
/* -------------------------------------------------------------------------- */

/**
 * Never a real request.
 *
 * The stub records what it was asked for and hands back whatever the test put
 * in `downloads`. A test that forgot to arrange a download gets a `WP_Error`,
 * which is the same thing the plugin sees when a fetch fails -- so the failure
 * mode of a badly written test is a test that fails, not a test that reaches
 * the network.
 */
function download_url( $url, $timeout = 300 ) {
	$state = &$GLOBALS['pokoblog_test'];

	$state['downloads']['requested'][] = $url;

	if ( isset( $state['downloads'][ $url ] ) ) {
		return $state['downloads'][ $url ];
	}

	return new WP_Error( 'http_404', 'not arranged in this test' );
}

function media_handle_sideload( $file, $post_id, $title = null ) {
	$state = &$GLOBALS['pokoblog_test'];

	$id = $state['next_id'];
	$state['next_id']++;

	$state['posts'][ $id ] = [
		'ID'           => $id,
		'post_type'    => 'attachment',
		'post_title'   => $title === null ? $file['name'] : $title,
		'post_content' => '',
		'post_excerpt' => '',
		'post_status'  => 'inherit',
		'post_author'  => 0,
		'post_name'    => sanitize_title( $file['name'] ),
		'post_category' => [],
	];

	if ( file_exists( $file['tmp_name'] ) ) {
		unlink( $file['tmp_name'] );
	}

	return $id;
}

function wp_delete_file( $path ) {
	if ( file_exists( $path ) ) {
		unlink( $path );
	}

	return true;
}
