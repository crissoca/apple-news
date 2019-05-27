<?php
/**
 * Publish to Apple News: Admin_Apple_Bulk_Sync_Page class
 *
 * @package Apple_News
 */

// Include dependencies.
require_once plugin_dir_path( __FILE__ ) . 'apple-actions/index/class-push.php';

/**
 * Bulk sync page. Display progress on multiple articles sync process.
 *
 * @since 0.6.0
 */
class Admin_Apple_Bulk_Sync_Page extends Apple_News {

	/**
	 * Action used for nonces
	 *
	 * @var array
	 * @access private
	 */
	const ACTION = 'apple_news_sync_post';

	/**
	 * Current plugin settings.
	 *
	 * @var array
	 * @access private
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param \Apple_Exporter\Settings $settings Settings in use during this run.
	 */
	public function __construct( $settings ) {
		$this->settings = $settings;

		add_action( 'admin_menu', array( $this, 'register_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'register_assets' ) );
		add_action( 'wp_ajax_sync_post', array( $this, 'ajax_sync_post' ) );
		add_filter( 'admin_title', array( $this, 'set_title' ) );
	}

	/**
	 * Registers the plugin submenu page.
	 *
	 * @access public
	 */
	public function register_page() {
		add_submenu_page(
			null,                                // Parent, if null, it won't appear in any menu.
			__( 'Bulk Sync', 'apple-news' ),   // Page title.
			__( 'Bulk Sync', 'apple-news' ),   // Menu title.
			apply_filters( 'apple_news_bulk_sync_capability', 'manage_options' ), // Capability.
			$this->plugin_slug . '_bulk_sync', // Menu Slug.
			array( $this, 'build_page' )         // Function.
		);
	}

	/**
	 * Fix the title since WordPress doesn't set one.
	 *
	 * @param string $admin_title The title to be filtered.
	 * @access public
	 * @return string The title for the screen.
	 */
	public function set_title( $admin_title ) {
		$screen = get_current_screen();
		if ( 'admin_page_apple_news_bulk_sync' === $screen->base ) {
			$admin_title = __( 'Bulk Sync', 'apple-news' ) . $admin_title;
		}

		return $admin_title;
	}

	/**
	 * Builds the plugin submenu page.
	 *
	 * @access public
	 */
	public function build_page() {
		$ids = isset( $_GET['ids'] ) ? sanitize_text_field( wp_unslash( $_GET['ids'] ) ) : null; // phpcs:ignore WordPress.VIP.SuperGlobalInputUsage.AccessDetected
		if ( ! $ids ) {
			wp_safe_redirect( esc_url_raw( menu_page_url( $this->plugin_slug . '_index', false ) ) );
			if ( ! defined( 'APPLE_NEWS_UNIT_TESTS' ) || ! APPLE_NEWS_UNIT_TESTS ) {
				exit;
			}
		}

		// Populate $articles array with a set of valid posts.
		$articles = array();
		foreach ( explode( '.', $ids ) as $id ) {
			$post = get_post( absint( $id ) );
			if ( ! empty( $post ) ) {
				$articles[] = $post;
			}
		}

		require_once plugin_dir_path( __FILE__ ) . 'partials/page-bulk-sync.php';
	}

	/**
	 * Handles the ajax action to push a post to Apple News.
	 *
	 * @access public
	 */
	public function ajax_sync_post() {
		// Check the nonce.
		check_ajax_referer( self::ACTION );

		// Sanitize input data.
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.VIP.SuperGlobalInputUsage.AccessDetected

		// Ensure the post exists and that it's published.
		$post = get_post( $id );
		if ( empty( $post ) ) {
			echo wp_json_encode(
				array(
					'success' => false,
					'error'   => __( 'This post no longer exists.', 'apple-news' ),
				)
			);
			wp_die();
		}

		// Check capabilities.
		if ( ! current_user_can( apply_filters( 'apple_news_publish_capability', self::get_capability_for_post_type( 'publish_posts', $post->post_type ) ) ) ) {
			echo wp_json_encode(
				array(
					'success' => false,
					'error'   => __( 'You do not have permission to publish to Apple News', 'apple-news' ),
				)
			);
			wp_die();
		}

		if ( 'publish' !== $post->post_status ) {
			echo wp_json_encode(
				array(
					'success' => false,
					'error'   => sprintf(
						// translators: token is a post ID.
						__( 'Article %s is not published and cannot be pushed to Apple News.', 'apple-news' ),
						$id
					),
				)
			);
			wp_die();
		}

		// If the post has been marked as deleted from the API, ignore this update.
		$deleted = get_post_meta( $id, 'apple_news_api_deleted', true );
		if ( $deleted ) {
			echo wp_json_encode(
				array(
					'success' => false,
					'error'   => sprintf(
					// translators: token is a post ID.
						__( 'This post no longer exists.', 'apple-news' ),
						$id
					),
				)
			);
			wp_die();
		}

		// Proceed based on the current settings for auto publish and update.
		$updated = get_post_meta( $id, 'apple_news_api_id', true );
		if ( $updated && 'yes' !== $this->settings->api_autosync_update
		     || ! $updated && 'yes' !== $this->settings->api_autosync ) {
			echo wp_json_encode(
				array(
					'success' => false,
					'error'   => sprintf(
					// translators: token is a post ID.
						__( 'Sync is disabled in the settings.', 'apple-news' ),
						$id
					),
				)
			);
			wp_die();
		}

		/**
		 * Ability to override the autopublishing of posts on a per-post level.
		 *
		 * @param bool    $should_autopublish Flag if the post should autopublish.
		 * @param int     $post_id Post ID.
		 * @param WP_Post $post Post object.
		 */
		$should_autopublish = (bool) apply_filters( 'apple_news_should_post_autopublish', true, $id, $post );

		// Bail if the filter returns false.
		if ( ! $should_autopublish ) {
			echo wp_json_encode(
				array(
					'success' => false,
					'error'   => __( 'You do not have permission to publish to Apple News', 'apple-news' ),
				)
			);
			wp_die();
		}

		// Proceed with the push.
		$action = new Apple_Actions\Index\Push( $this->settings, $id );
		try {
			$errors = $action->perform();
		} catch ( \Apple_Actions\Action_Exception $e ) {
			$errors = $e->getMessage();
		}

		if ( $errors ) {
			echo wp_json_encode(
				array(
					'success' => false,
					'error'   => $errors,
				)
			);
		} else {
			echo wp_json_encode(
				array(
					'success' => true,
				)
			);
		}

		// This is required to terminate immediately and return a valid response.
		wp_die();
	}

	/**
	 * Registers assets used by the bulk sync process.
	 *
	 * @param string $hook The context under which this function is called.
	 * @access public
	 */
	public function register_assets( $hook ) {
		if ( 'admin_page_apple_news_bulk_sync' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			$this->plugin_slug . '_bulk_sync_css',
			plugin_dir_url( __FILE__ ) . '../assets/css/bulk-sync.css',
			array(),
			self::$version
		);
		wp_enqueue_script(
			$this->plugin_slug . '_bulk_sync_js',
			plugin_dir_url( __FILE__ ) . '../assets/js/bulk-sync.js',
			array( 'jquery' ),
			self::$version,
			true
		);
	}
}
