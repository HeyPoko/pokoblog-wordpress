<?php
/**
 * The few rules a theme cannot already have, on the posts that need them.
 *
 * PokoBlog articles can carry a comparison table, a callout and a task list.
 * A theme styles `table`, `pre` and `blockquote` already, so those are left
 * alone -- but `poko-table-wrap`, `poko-callout` and `poko-task` are class
 * names it has never seen, and without them a table wider than a phone pushes
 * the whole page sideways and a warning looks like an ordinary quote.
 *
 * ## Where the real copy lives
 *
 * `packages/api/src/modules/connector/article-css.ts`, in the `PORTABLE` half.
 * This is a transcription of three of its rules and it can drift from it, which
 * is a cost taken deliberately: the alternative is fetching the stylesheet, and
 * this plugin makes no outbound requests at all. That property is worth more
 * than three rules being in one place. Keep the two in step by hand, and prefer
 * changing neither.
 *
 * ## Why it is not simply enqueued everywhere
 *
 * A plugin that adds a stylesheet to every page of a site it was installed on
 * to receive articles is a plugin taking liberties. This loads on a single post
 * that PokoBlog itself delivered, identified by the same post meta the
 * publisher uses for idempotency, and nowhere else. A site with no PokoBlog
 * articles serves not one extra byte.
 *
 * @package PokoBlog
 */

defined( 'ABSPATH' ) || exit;

/**
 * Front-end styles for a delivered article.
 */
final class PokoBlog_Styles {

	const HANDLE = 'pokoblog-article';

	/** Added to `<body>` on a PokoBlog article, and nowhere else. */
	const BODY_CLASS = 'poko-article';

	/**
	 * Structure only, and every colour is the reader's own text colour thinned.
	 *
	 * `currentColor` rather than a value of ours, so this follows the theme's
	 * palette and its dark mode with nothing configured. There is no colour in
	 * here for the site owner to disagree with.
	 *
	 * Note what is absent: no `max-width` on the wrapper. A block theme
	 * constrains its content area with `:where(...)`, which has zero
	 * specificity, so a plain class rule here outranks it and the table draws
	 * itself across the whole window. Measured on Twenty Twenty-Five: 1340px
	 * against a 645px column.
	 */
	const CSS = '.poko-table-wrap{overflow-x:auto}'
		. '.poko-table-wrap>table{border-collapse:collapse;width:100%}'
		. '.poko-callout{border-left:.25rem solid currentColor;padding:.75rem 1rem;margin:1.5rem 0;background:color-mix(in srgb,currentColor 5%,transparent)}'
		. '.poko-callout>*:first-child{margin-top:0}'
		. '.poko-callout>*:last-child{margin-bottom:0}'
		. '.poko-task{list-style:none}'
		/*
		 * Defaults for the two elements a theme is *supposed* to style and
		 * often does not. Measured on Twenty Twenty-Five: `pre` gets no
		 * background and no padding, so a shell command is a paragraph in a
		 * monospace font, and a table cell gets no rule, so a comparison grid
		 * is a block of text.
		 *
		 * `:where()` is what makes this safe to send. It has zero specificity,
		 * so a theme with any opinion at all about `pre` or `td` wins without
		 * having to raise its own selectors. This is a floor, not a look.
		 */
		. ':where(.' . self::BODY_CLASS . ') pre{overflow-x:auto;padding:1rem;background:color-mix(in srgb,currentColor 7%,transparent);color:inherit}'
		. ':where(.' . self::BODY_CLASS . ') pre code{background:none;color:inherit}'
		. ':where(.' . self::BODY_CLASS . ') th,:where(.' . self::BODY_CLASS . ') td{padding:.4rem .75rem;border-bottom:1px solid color-mix(in srgb,currentColor 15%,transparent)}';

	/**
	 * Register the hook.
	 */
	public static function register() {
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
		add_filter( 'body_class', [ __CLASS__, 'body_class' ] );
	}

	/**
	 * Mark the page, so the two element defaults above have something to hang
	 * off that is not "every page on this site".
	 *
	 * @param array $classes Classes WordPress has collected.
	 * @return array
	 */
	public static function body_class( $classes ) {
		if ( self::is_article() ) {
			$classes[] = self::BODY_CLASS;
		}

		return $classes;
	}

	/**
	 * Whether the page being shown is a single article PokoBlog delivered.
	 *
	 * The same post meta the publisher uses for idempotency, so there is one
	 * answer to "is this ours" rather than two that can disagree.
	 */
	private static function is_article() {
		if ( ! is_singular( 'post' ) ) {
			return false;
		}

		$post_id = get_the_ID();

		if ( ! $post_id ) {
			return false;
		}

		return '' !== (string) get_post_meta( $post_id, PokoBlog_Publisher::ARTICLE_ID_META, true );
	}

	/**
	 * Add the stylesheet, on a single PokoBlog post and nowhere else.
	 */
	public static function enqueue() {
		if ( ! self::is_article() ) {
			return;
		}

		/*
		 * Registered with no source and given the CSS inline. Six rules is far
		 * less than the request that would fetch them costs, and a file would
		 * be one more thing for a caching plugin to serve a stale copy of.
		 */
		wp_register_style( self::HANDLE, false, [], POKOBLOG_VERSION );
		wp_enqueue_style( self::HANDLE );
		wp_add_inline_style( self::HANDLE, self::CSS );
	}
}
