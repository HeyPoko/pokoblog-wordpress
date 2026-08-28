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
				<div class="notice notice-warning">
					<p><?php esc_html_e( 'A new key was generated. Paste it into PokoBlog — the old one no longer works.', 'pokoblog' ); ?></p>
				</div>
			<?php elseif ( $notice === 'defaults' ) : ?>
				<div class="notice notice-success">
					<p><?php esc_html_e( 'Saved.', 'pokoblog' ); ?></p>
				</div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Your API key', 'pokoblog' ); ?></h2>
			<p><?php esc_html_e( 'Copy this into PokoBlog, on the Connections screen for this website.', 'pokoblog' ); ?></p>
			<p>
				<input
					type="text"
					readonly
					onfocus="this.select()"
					class="large-text code"
					value="<?php echo esc_attr( $key ); ?>"
				/>
			</p>
			<p class="description">
				<?php esc_html_e( 'PokoBlog will publish to:', 'pokoblog' ); ?>
				<code><?php echo esc_html( rest_url( POKOBLOG_REST_NAMESPACE . '/publish' ) ); ?></code>
			</p>

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

			<h2><?php esc_html_e( 'How articles arrive', 'pokoblog' ); ?></h2>
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

			<h2><?php esc_html_e( 'What PokoBlog can see', 'pokoblog' ); ?></h2>
			<table class="widefat striped" style="max-width:40em">
				<tbody>
					<tr>
						<td><?php esc_html_e( 'Yoast SEO', 'pokoblog' ); ?></td>
						<td><?php echo esc_html( self::yes_no( $seo['yoast'] ) ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Rank Math', 'pokoblog' ); ?></td>
						<td><?php echo esc_html( self::yes_no( $seo['rank_math'] ) ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'All in One SEO', 'pokoblog' ); ?></td>
						<td><?php echo esc_html( self::yes_no( $seo['aioseo'] ) ); ?></td>
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
		<?php
	}

	private static function yes_no( $value ) {
		return $value ? __( 'Installed', 'pokoblog' ) : __( 'Not installed', 'pokoblog' );
	}
}
