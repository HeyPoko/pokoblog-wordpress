<?php
/**
 * Settings &rarr; PokoBlog.
 *
 * One screen with one job: hand the customer the API key so they can paste it
 * into PokoBlog. Everything else on it -- the author, the category, what we can
 * see of their SEO plugin -- is secondary and exists so that the first article
 * arrives looking the way they expect rather than as an orphan post by admin in
 * Uncategorized.
 *
 * Deliberately plain. This screen is looked at once, for about forty seconds,
 * with another tab open; a designed page would take longer to read than the
 * task takes to do, and it would drift out of step with the WordPress admin
 * around it at every core release.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PokoBlog_Admin {

	const SLUG = 'pokoblog';

	public static function register() {
		add_action( 'admin_menu', [ __CLASS__, 'menu' ] );
		add_action( 'admin_init', [ __CLASS__, 'handle_post' ] );
		add_filter(
			'plugin_action_links_' . plugin_basename( POKOBLOG_FILE ),
			[ __CLASS__, 'action_links' ]
		);
	}

	public static function menu() {
		add_options_page(
			__( 'PokoBlog', 'pokoblog' ),
			__( 'PokoBlog', 'pokoblog' ),
			'manage_options',
			self::SLUG,
			[ __CLASS__, 'render' ]
		);
	}

	/** A direct link from the plugins list, which is where people look first. */
	public static function action_links( $links ) {
		$url = admin_url( 'options-general.php?page=' . self::SLUG );

		array_unshift(
			$links,
			'<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'pokoblog' ) . '</a>'
		);

		return $links;
	}

	/**
	 * The three things this screen can change.
	 *
	 * One handler rather than three, and every branch behind both a capability
	 * check and a nonce. The capability check is the one that matters: a nonce
	 * proves the request came from a page we rendered, not that the person
	 * sending it is allowed to do this.
	 */
	public static function handle_post() {
		if ( ! isset( $_POST['pokoblog_action'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$action = sanitize_text_field( wp_unslash( $_POST['pokoblog_action'] ) );

		check_admin_referer( 'pokoblog_' . $action );

		if ( $action === 'rotate' ) {
			PokoBlog_Key::rotate();
		}

		if ( $action === 'defaults' ) {
			update_option(
				'pokoblog_post_author',
				isset( $_POST['pokoblog_post_author'] ) ? absint( $_POST['pokoblog_post_author'] ) : 0,
				false
			);
			update_option(
				'pokoblog_post_category',
				isset( $_POST['pokoblog_post_category'] ) ? absint( $_POST['pokoblog_post_category'] ) : 0,
				false
			);
		}

		wp_safe_redirect(
			add_query_arg(
				[
					'page'            => self::SLUG,
					'pokoblog-notice' => $action,
				],
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		/*
		 * Minted on read as well as on activation. A plugin updated in place
		 * from a version that stored its key elsewhere, or activated by a
		 * process that skipped the hook, would otherwise show an empty field
		 * with no way to fill it.
		 */
		$key      = PokoBlog_Key::ensure();
		$seo      = PokoBlog_SEO::detected();
		$indexnow = PokoBlog_IndexNow::status();
		$notice   = isset( $_GET['pokoblog-notice'] ) ? sanitize_text_field( wp_unslash( $_GET['pokoblog-notice'] ) ) : '';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'PokoBlog', 'pokoblog' ); ?></h1>

			<?php if ( $notice === 'rotate' ) : ?>
				<div class="notice notice-warning is-dismissible">
					<p><?php esc_html_e( 'A new key was generated. Paste it into PokoBlog — the old one no longer works.', 'pokoblog' ); ?></p>
				</div>
			<?php elseif ( $notice === 'defaults' ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Saved.', 'pokoblog' ); ?></p>
				</div>
			<?php endif; ?>

			<div class="card" style="max-width:46em">
			<h2 class="title"><?php esc_html_e( 'Your API key', 'pokoblog' ); ?></h2>
			<p><?php esc_html_e( 'Copy this into PokoBlog, on the Connections screen for this website.', 'pokoblog' ); ?></p>
			<p class="pokoblog-key-row">
				<input
					id="pokoblog-key"
					type="text"
					readonly
					onfocus="this.select()"
					class="large-text code"
					value="<?php echo esc_attr( $key ); ?>"
				/>
				<?php
				/*
				 * A copy button, and the field stays selectable behind it. The
				 * script is progressive on purpose: `navigator.clipboard` is
				 * absent on an http admin that is not localhost, and the
				 * select-on-focus this has always had is the fallback rather
				 * than a broken button.
				 */
				?>
				<button type="button" class="button" id="pokoblog-copy" hidden>
					<?php esc_html_e( 'Copy', 'pokoblog' ); ?>
				</button>
			</p>
			<script>
				( function () {
					var button = document.getElementById( 'pokoblog-copy' );
					var field = document.getElementById( 'pokoblog-key' );

					if ( ! button || ! field || ! navigator.clipboard ) {
						return;
					}

					button.hidden = false;
					button.addEventListener( 'click', function () {
						navigator.clipboard.writeText( field.value ).then( function () {
							var was = button.textContent;
							button.textContent = <?php echo wp_json_encode( __( 'Copied', 'pokoblog' ) ); ?>;
							setTimeout( function () { button.textContent = was; }, 2000 );
						} );
					} );
				} )();
			</script>

			<form method="post">
				<?php wp_nonce_field( 'pokoblog_rotate' ); ?>
				<input type="hidden" name="pokoblog_action" value="rotate" />
				<?php
				submit_button(
					__( 'Generate a new key', 'pokoblog' ),
					'secondary',
					'submit',
					true,
					[
						'onclick' => 'return confirm(' . wp_json_encode(
							__( 'PokoBlog will stop publishing until you paste the new key in. Continue?', 'pokoblog' )
						) . ');',
					]
				);
				?>
			</form>
			</div>

			<div class="card" style="max-width:46em">
			<h2 class="title"><?php esc_html_e( 'How articles arrive', 'pokoblog' ); ?></h2>
			<form method="post">
				<?php wp_nonce_field( 'pokoblog_defaults' ); ?>
				<input type="hidden" name="pokoblog_action" value="defaults" />
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="pokoblog_post_author"><?php esc_html_e( 'Author', 'pokoblog' ); ?></label></th>
						<td>
							<?php
							wp_dropdown_users(
								[
									'name'              => 'pokoblog_post_author',
									'id'                => 'pokoblog_post_author',
									'selected'          => (int) get_option( 'pokoblog_post_author', 0 ),
									'show_option_none'  => __( 'Default', 'pokoblog' ),
									'option_none_value' => 0,
									'capability'        => [ 'edit_posts' ],
								]
							);
							?>
							<p class="description"><?php esc_html_e( 'Set on the first publish only. Changing the author of an article afterwards is left to you.', 'pokoblog' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pokoblog_post_category"><?php esc_html_e( 'Category', 'pokoblog' ); ?></label></th>
						<td>
							<?php
							wp_dropdown_categories(
								[
									'name'             => 'pokoblog_post_category',
									'id'               => 'pokoblog_post_category',
									'selected'         => (int) get_option( 'pokoblog_post_category', 0 ),
									'show_option_none' => __( 'Default', 'pokoblog' ),
									'option_none_value' => 0,
									'hide_empty'       => false,
								]
							);
							?>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save', 'pokoblog' ) ); ?>
			</form>
			</div>

			<div class="card" style="max-width:46em">
			<h2 class="title"><?php esc_html_e( 'What PokoBlog can see', 'pokoblog' ); ?></h2>
			<table class="widefat striped">
				<tbody>
					<tr>
						<td><?php esc_html_e( 'Yoast SEO', 'pokoblog' ); ?></td>
						<td><?php self::status_cell( $seo['yoast'] ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Rank Math', 'pokoblog' ); ?></td>
						<td><?php self::status_cell( $seo['rank_math'] ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'All in One SEO', 'pokoblog' ); ?></td>
						<td><?php self::status_cell( $seo['aioseo'] ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'IndexNow', 'pokoblog' ); ?></td>
						<td>
							<?php
							echo esc_html(
								empty( $indexnow['configured'] )
									? __( 'Not set up', 'pokoblog' )
									: sprintf(
										/* translators: %s: the URL the IndexNow key is published at. */
										__( 'Key published at %s', 'pokoblog' ),
										$indexnow['location']
									)
							);
							?>
						</td>
					</tr>
				</tbody>
			</table>
			<p class="description">
				<?php esc_html_e( 'PokoBlog writes your meta description and focus keyword into whichever of these you have installed, and into none of the others.', 'pokoblog' ); ?>
			</p>
			</div>

			<?php self::render_log(); ?>
		</div>
		<?php
	}

	/**
	 * What PokoBlog actually did to this site, most recent first.
	 *
	 * The half of the story that used to be told only on our server. A slug
	 * conflict writes the post as a draft, a picture that will not download is
	 * skipped on purpose, and an article in the bin is refused rather than
	 * restored -- all three are deliberate, all three are invisible from
	 * PokoBlog's own screens, and this is where the person whose site it is
	 * can see them.
	 *
	 * The post id is a link when there is a post, because the next thing
	 * anybody does after reading one of these lines is go and look at it.
	 */
	private static function render_log() {
		$entries = PokoBlog_Log::entries();

		?>
		<div class="card" style="max-width:none">
		<h2 class="title"><?php esc_html_e( 'Recent deliveries', 'pokoblog' ); ?></h2>
		<?php

		if ( empty( $entries ) ) {
			?>
			<p class="description">
				<?php esc_html_e( 'Nothing yet. Every article PokoBlog sends will be listed here, along with what this site did with it.', 'pokoblog' ); ?>
			</p>
			</div>
			<?php
			return;
		}

		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'When', 'pokoblog' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Post', 'pokoblog' ); ?></th>
					<th scope="col"><?php esc_html_e( 'What happened', 'pokoblog' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $entries as $entry ) : ?>
					<tr>
						<td style="white-space:nowrap">
							<?php
							echo esc_html(
								wp_date(
									get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
									$entry['at']
								)
							);
							?>
						</td>
						<td>
							<?php if ( ! empty( $entry['post_id'] ) ) : ?>
								<a href="<?php echo esc_url( (string) get_edit_post_link( $entry['post_id'] ) ); ?>">
									<?php echo esc_html( get_the_title( $entry['post_id'] ) ); ?>
								</a>
							<?php else : ?>
								<span aria-hidden="true">&mdash;</span>
							<?php endif; ?>
						</td>
						<td>
							<?php
							$fine = ! empty( $entry['ok'] ) && empty( $entry['conflict'] )
								&& ( ! isset( $entry['image'] ) || false !== $entry['image'] );

							printf(
								'<span class="dashicons %s" aria-hidden="true" style="vertical-align:text-bottom;color:%s"></span> ',
								$fine ? 'dashicons-yes-alt' : 'dashicons-warning',
								$fine ? '#00a32a' : '#dba617'
							);

							echo esc_html( PokoBlog_Log::describe( $entry ) );
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %d: how many deliveries are kept. */
					__( 'The last %d deliveries. Older ones fall off.', 'pokoblog' ),
					PokoBlog_Log::LIMIT
				)
			);
			?>
		</p>
		<?php self::render_endpoint(); ?>
		</div>
		<?php
	}

	/**
	 * The address PokoBlog posts to, for the one conversation that needs it.
	 *
	 * It used to sit under the API key, which is the wrong place twice over:
	 * the customer never types it anywhere -- PokoBlog builds it from the site
	 * address they already gave it -- and putting a `wp-json` URL next to the
	 * one field they do have to copy makes a two-step job look like a
	 * developer task.
	 *
	 * It is worth keeping for exactly one situation: PokoBlog says it cannot
	 * reach the site, and somebody wants to try the address by hand. That
	 * person is already reading the deliveries table, so it lives here,
	 * underneath it, in the small text.
	 */
	private static function render_endpoint() {
		?>
		<p class="description">
			<?php esc_html_e( 'If PokoBlog says it cannot reach this site, this is the address it posts to:', 'pokoblog' ); ?>
			<code><?php echo esc_html( rest_url( POKOBLOG_REST_NAMESPACE . '/publish' ) ); ?></code>
		</p>
		<?php
	}

	private static function yes_no( $value ) {
		return $value ? __( 'Installed', 'pokoblog' ) : __( 'Not installed', 'pokoblog' );
	}

	/**
	 * The same answer as `yes_no`, with a glyph in front of it.
	 *
	 * The words stay, and that is the point rather than belt-and-braces: a
	 * dashicon is a background image with no text of its own, so a column of
	 * them says nothing to a screen reader and nothing at all if the icon font
	 * fails to load. The icon is `aria-hidden` and decorative; the sentence is
	 * the answer.
	 */
	private static function status_cell( $value ) {
		printf(
			'<span class="dashicons %s" aria-hidden="true" style="vertical-align:text-bottom;color:%s"></span> %s',
			$value ? 'dashicons-yes-alt' : 'dashicons-minus',
			$value ? '#00a32a' : '#8c8f94',
			esc_html( self::yes_no( $value ) )
		);
	}
}
