<?php
/**
 * Removing PokoBlog from a site, and the one thing that is deliberately left.
 *
 * Run by WordPress when the plugin is deleted, not when it is deactivated. The
 * rule this file is written to: anything the plugin put on the customer's site
 * that is not their content goes, and the posts stay -- those are articles on
 * their blog with readers and links pointing at them, and deleting somebody's
 * published pages because they uninstalled a publishing tool would be an
 * astonishing thing for a publishing tool to do.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

/*
 * The IndexNow key file, which is the only thing this plugin ever writes
 * outside the database. Read the key before the options go, or there is nothing
 * left to name the file with -- and a 32-character text file nobody can explain
 * is exactly the kind of thing that survives in a site root for a decade.
 */
$indexnow_key = get_option( 'pokoblog_indexnow_key' );

if ( is_string( $indexnow_key ) && $indexnow_key !== '' ) {
	$key_file = ABSPATH . $indexnow_key . '.txt';

	if ( file_exists( $key_file ) ) {
		wp_delete_file( $key_file );
	}
}

/*
 * Every option we ever set, by prefix rather than by name.
 *
 * A list of names is a list somebody forgets to add to. The prefix is ours and
 * is used by nothing else, and it catches the publish locks -- which are named
 * after a hash and so could not be listed here anyway.
 *
 * The underscore is escaped: unescaped it is a single-character wildcard in
 * SQL's LIKE, and `pokoblog_%` would also match an option belonging to a
 * hypothetical `pokoblogger` plugin.
 */
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( 'pokoblog_' ) . '%'
	)
);

/*
 * Our bookkeeping on attachments and posts, with one exception.
 *
 * `_pokoblog_article_id` stays, and this is the one place in the plugin where
 * the rule at the top of this file is bent, so here is the argument.
 *
 * That key is the only thing connecting a WordPress post to the PokoBlog
 * article it was written from. It is what makes a second delivery of the same
 * article update the post instead of adding a copy of it. Deleting it does not
 * tidy anything a customer will ever see -- it is one short string per post,
 * hidden from the editor -- and it arms the worst failure this connector has:
 * uninstall the plugin, reinstall it a week later while troubleshooting, and
 * every article PokoBlog has ever written for that site is published a second
 * time, next to the first.
 *
 * Reinstalling is not a rare path. It is the first thing anybody does when a
 * connection stops working. Between a few kilobytes of invisible metadata and a
 * blog with two of everything, the metadata wins.
 *
 * A customer who wants it gone can have it in one statement, and it is in the
 * README so they do not have to work it out:
 *
 *   DELETE FROM wp_postmeta WHERE meta_key = '_pokoblog_article_id';
 */
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s AND meta_key != %s",
		$wpdb->esc_like( '_pokoblog_' ) . '%',
		'_pokoblog_article_id'
	)
);

/*
 * Nothing is done about the meta this plugin wrote into Yoast's and Rank Math's
 * keys, and that is not an omission. A meta description is the customer's
 * content -- it is on their pages, in their search results -- and it belongs to
 * the SEO plugin that reads it, which is still installed. Removing it would
 * take the descriptions off every article PokoBlog ever wrote for them.
 */
