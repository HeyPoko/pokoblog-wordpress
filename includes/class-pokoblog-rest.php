<?php
/**
 * The four routes, and the one credential in front of all of them.
 *
 * `pokoblog/v1/publish`         POST   create or update a post
 * `pokoblog/v1/verify`          GET    is the key right, and what site is this
 * `pokoblog/v1/setup-indexnow`  POST   mint and publish an IndexNow key
 * `pokoblog/v1/indexnow-status` GET    is that key still readable
 *
 * All four go through the same `permission_callback`. There is deliberately no
 * route here that answers without it, including `verify` -- an endpoint that
 * tells a stranger which WordPress version and which SEO plugins a site runs is
 * a reconnaissance endpoint, and "it only returns public information" stops
 * being true the moment somebody adds a field.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PokoBlog_Rest {

	public static function register() {
		add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
		add_filter( 'rest_authentication_errors', [ __CLASS__, 'allow_our_namespace' ], 999 );
	}

	public static function routes() {
		$permission = [ __CLASS__, 'authorize' ];

		register_rest_route(
			POKOBLOG_REST_NAMESPACE,
			'/publish',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'publish' ],
				'permission_callback' => $permission,
			]
		);

		register_rest_route(
			POKOBLOG_REST_NAMESPACE,
			'/verify',
			[
				'methods'             => 'GET',
				'callback'            => [ __CLASS__, 'verify' ],
				'permission_callback' => $permission,
			]
		);

		register_rest_route(
			POKOBLOG_REST_NAMESPACE,
			'/setup-indexnow',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'setup_indexnow' ],
				'permission_callback' => $permission,
			]
		);

		register_rest_route(
			POKOBLOG_REST_NAMESPACE,
			'/indexnow-status',
			[
				'methods'             => 'GET',
				'callback'            => [ __CLASS__, 'indexnow_status' ],
				'permission_callback' => $permission,
			]
		);
	}

	/**
	 * The only gate.
	 *
	 * A `WP_Error` rather than a bare `false`, so the answer is 401 with a code
	 * PokoBlog can act on -- "your key is wrong, ask the customer to paste it
	 * again" is a different conversation from "your request was malformed", and
	 * WordPress's default refusal does not distinguish them.
	 *
	 * The comparison itself is in `PokoBlog_Key::matches`, which is where the
	 * argument for how it is written lives.
	 */
	public static function authorize( $request ) {
		if ( PokoBlog_Key::matches( PokoBlog_Key::stored(), PokoBlog_Key::presented( $request ) ) ) {
			return true;
		}

		return new WP_Error(
			'pokoblog_bad_key',
			__( 'The API key is missing or does not match this site.', 'pokoblog' ),
			[ 'status' => 401 ]
		);
	}

	/**
	 * Let our namespace through a site-wide REST lockdown.
	 *
	 * A number of security plugins and a couple of managed hosts add a
	 * `rest_authentication_errors` filter that refuses every REST request from
	 * anybody who is not logged in. That filter runs before any route's
	 * `permission_callback`, so on those sites this plugin's own authentication
	 * never gets to run and the customer sees a connector that will not connect
	 * with no indication why.
	 *
	 * This clears the block for `pokoblog/v1` and for nothing else. It is safe
	 * because it does not authorize anything: every route above still requires
	 * the API key, and `authorize()` is what actually decides. What it removes
	 * is a blanket refusal that was aimed at WordPress's own user and content
	 * endpoints.
	 *
	 * The route is read from the request WordPress has already parsed rather
	 * than from `$_SERVER['REQUEST_URI']`, which is the detail that matters. The
	 * raw URI is attacker-controlled and full of ways to look like one path and
	 * resolve to another -- encoded slashes, `..`, a query string carrying the
	 * namespace as a value -- and a substring test against it is a filter that
	 * unlocks the whole REST API for anybody who can put nine characters in a
	 * query string.
	 */
	public static function allow_our_namespace( $result ) {
		if ( ! is_wp_error( $result ) ) {
			return $result;
		}

		$route = self::current_route();

		if ( $route !== '' && strpos( $route, '/' . POKOBLOG_REST_NAMESPACE . '/' ) === 0 ) {
			return true;
		}

		return $result;
	}

	/**
	 * The REST route being served, as WordPress resolved it.
	 *
	 * `$GLOBALS['wp']->query_vars['rest_route']` is set by the REST server
	 * itself after it has decoded and normalized the path, so it is the one
	 * spelling of the route that cannot be dressed up to look like another.
	 */
	private static function current_route() {
		if ( ! isset( $GLOBALS['wp'] ) || ! isset( $GLOBALS['wp']->query_vars['rest_route'] ) ) {
			return '';
		}

		$route = $GLOBALS['wp']->query_vars['rest_route'];

		return is_string( $route ) ? $route : '';
	}

	/**
	 * Everything PokoBlog needs to decide whether this connection is usable.
	 *
	 * The SEO plugins are reported here as well as on publish, so the
	 * Connections screen can say "we will write your meta description into
	 * Yoast" at the moment the customer connects rather than after the first
	 * article.
	 */
	public static function verify( $request ) {
		return new WP_REST_Response(
			[
				'ok'        => true,
				'site_name' => get_bloginfo( 'name' ),
				'site_url'  => get_site_url(),
				'version'   => POKOBLOG_VERSION,
				'seo'       => PokoBlog_SEO::detected(),
			],
			200
		);
	}

	public static function publish( $request ) {
		$body = $request->get_json_params();
		$form = $request->get_body_params();

		$params = array_merge(
			is_array( $form ) ? $form : [],
			is_array( $body ) ? $body : []
		);

		$normalized = PokoBlog_Payload::normalize( $params );
		$missing    = PokoBlog_Payload::missing( $normalized );

		if ( ! empty( $missing ) ) {
			return new WP_REST_Response(
				[
					'ok'      => false,
					'code'    => 'missing_fields',
					'missing' => $missing,
				],
				400
			);
		}

		$result = PokoBlog_Publisher::publish( $normalized );

		if ( ! $result['ok'] ) {
			return new WP_REST_Response( $result, self::status_for( $result['code'] ) );
		}

		$result['post_url'] = get_permalink( $result['post_id'] );
		$result['edit_url'] = get_edit_post_link( $result['post_id'], 'raw' );

		/*
		 * 201 for a post that did not exist, 200 for one that did. The
		 * distinction is not pedantry -- it is how PokoBlog can tell a first
		 * delivery from a retry without a field of its own, and how a customer
		 * support conversation about a duplicate can be settled by looking at a
		 * log.
		 */
		return new WP_REST_Response( $result, $result['created'] ? 201 : 200 );
	}

	private static function status_for( $code ) {
		if ( $code === 'locked' || $code === 'trashed' ) {
			/*
			 * 409, not 500. Both are states where the request was well-formed
			 * and correct and the site is not in a position to carry it out --
			 * one because it is already carrying it out, one because the
			 * customer deleted the post. A 500 would tell PokoBlog to retry
			 * forever, and neither of these resolves by retrying.
			 */
			return 409;
		}

		return 500;
	}

	public static function setup_indexnow( $request ) {
		$result       = PokoBlog_IndexNow::setup();
		$result['ok'] = true;

		return new WP_REST_Response( $result, 200 );
	}

	public static function indexnow_status( $request ) {
		$result       = PokoBlog_IndexNow::status();
		$result['ok'] = true;

		return new WP_REST_Response( $result, 200 );
	}
}
