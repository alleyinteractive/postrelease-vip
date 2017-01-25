<?php

/*
 Plugin Name: Nativo
 Plugin URI: https://github.com/alleyinteractive/postrelease-vip
 Description: A plugin to handle navtive ads through Nativo
 Version: 1.3
 Author: PostRelease
 Author URI: https://github.com/alleyinteractive/postrelease-vip
 */
if ( ! class_exists( 'Nativo' ) ) {

	/**
	 * Nativo - A plugin to handle navtive ads through Nativo
	 */
	class Nativo {

		/**
		 * The one instance of Nativo
		 *
		 * @var Nativo
		 */
		private static $instance;

		/**
		 * Post type
		 *
		 * @var string
		 */
		public $post_type = 'pr_sponsored_post';

		/**
		 * Plugin version
		 *
		 * @var string
		 */
		public $plugin_version = '1.3';

		/**
		 * DB Version
		 *
		 * @var string
		 */
		public $db_version = 1;

		/**
		 * In development mode?
		 *
		 * @var string
		 */
		public $is_dev = true;

		/**
		 * URL of postrelease server
		 *
		 * @var string
		 */
		public $server = 'http://www.postrelease.com';

		/**
		 * URL of postrelease js file
		 *
		 * @var string
		 */
		public $js_url = 'http://a.postrelease.com/serve/load.js?async=true';

		/**
		 * Initialize the class.
		 */
		public function __construct() {}

		/**
		 * Return an instance of this class.
		 *
		 * @return object
		 */
		public static function get_instance() {
			if ( is_null( self::$instance ) ) {
				self::$instance = new self();
				self::$instance->setup();
			}
			return self::$instance;
		}

		/**
		 * Setup actions and filters, etc.
		 *
		 * @return void
		 */
		public function setup() {
			$this->server = trailingslashit( $this->server );

			add_action( 'init', [ $this, 'init' ] );
			add_action( 'init', [ $this, 'handle_enable_request' ] );
			add_action( 'init', [ $this, 'handle_signup_requests' ] );
			add_action( 'admin_init', [ $this, 'admin_init' ] );
			add_action( 'wp_enqueue_scripts', [ $this, 'wp_enqueue_scripts' ] );
			add_action( 'admin_menu', [ $this, 'create_menu' ] );
			add_action( 'template_redirect', [ $this, 'template_redirect' ] );

			add_filter( 'the_author', [ $this, 'the_author' ] );
			add_filter( 'query_vars', [ $this, 'add_query_vars' ] );
			add_filter( 'author_link', [ $this, 'author_link' ], 100, 2 );
			add_filter( 'get_the_date', [ $this, 'get_the_date_time' ], 10, 2 );
			add_filter( 'get_the_time', [ $this, 'get_the_date_time' ], 10, 2 );

			if ( ! function_exists( 'wpcom_is_vip' ) ) {
				register_activation_hook( __FILE__, [ $this, 'notify_activate' ] );
				register_deactivation_hook( __FILE__, [ $this, 'notify_deactivate' ] );
			}

		}

		/**
		 * Appends the PostRelease javascript to header
		 *
		 * @return void
		 */
		public function wp_enqueue_scripts() {
			wp_enqueue_script( 'postrelease', $this->js_url, [], null, false );
		}

		/**
		 * Register postrelease post type
		 *
		 * @return void
		 */
		public function init() {
			$args = [
				'public'              => $this->is_dev,
				'publicly_queryable'  => true,
				'label'               => 'Sponsored Post',
				'exclude_from_search' => true,
				'can_export'          => false,
				'supports' => [
					'title',
					'editor',
					'author',
					'thumbnail',
					'excerpt',
				],
			];
			register_post_type( $this->post_type, $args );
		}

		/**
		 * Function when plugin is activated.
		 * In WP VIP, the activate hook is not supported.
		 * The plugin is activated when the user goes to the dashboard and
		 * adds his publication to the PostRelease network.
		 *
		 * @return void
		 */
		public function activate() {
			if ( ! function_exists( 'wpcom_is_vip' ) ) {
				flush_rewrite_rules();
			} else {
				do_action( 'postrelease_activate' );
			}
		}

		/**
		 * Reset changes caused by the plugin upon deactivation
		 *
		 * @return void
		 */
		public function deactivate() {
			/* delete all sponsored posts */
			$sponsored_posts_array = get_posts( [ 'post_type' => $this->post_type ] );
			if ( ! empty( $sponsored_posts_array ) ) {
				foreach ( $sponsored_posts_array as $sponsored_post ) {
					wp_delete_post( $sponsored_post->ID, true );
				}
			}
			delete_option( 'prx_template_post_id' );
			delete_option( 'prx_plugin_activated' );
			delete_option( 'prx_plugin_key' );
			delete_option( 'prx_database_version' );
		}

		/**
		 * Check if ad full page is created and OK
		 * If not, recreate it
		 *
		 * Returns false if the page had to be created
		 * That means that either:
		 * - the plugin was just installed
		 * - the template page was corrupted or deleted somehow and we need to create a new one
		 *
		 * @return boolean
		 */
		public function check_full_page() {
			$page_id = get_option( 'prx_template_post_id', false );
			if ( false === $page_id ) {
				$this->create_page();
				return false;
			}

			$page = get_page( intval( $page_id ) );

			if (
				is_null( $page )
				|| 0 !== strcmp( $page->post_status,'publish' )
				|| false === strstr( $page->post_name, 'postrelease' )
				|| 0 !== strcmp( $page->post_type, $this->post_type )
				|| 0 !== strcmp( $page->post_title,'<span class="prx_title"></span>' )
				|| 0 !== strcmp( $page->post_content,'<span class="prx_body"></span>' )
			) {
				$this->create_page();
				return false;
			}
			return true;
		}

		/**
		 * Allow additional parameters in the URL
		 *
		 * @param array $vars array of query vars.
		 * @return array modified vars
		 */
		public function add_query_vars( $vars ) {
			$vars[] = 'prx_t';
			$vars[] = 'prx_rk';
			$vars[] = 'prx_ro';
			$vars[] = 'prx';
			return $vars;
		}

		/**
		 * Create the ad template post
		 *
		 * @return void
		 */
		public function create_page() {
			$current_user = wp_get_current_user();

			$args = [
				'post_title'     => '<span class="prx_title"></span>',
				'post_content'   => '<span class="prx_body"></span>',
				'post_type'      => $this->post_type,
				'post_status'    => 'publish',
				'post_author'    => $current_user->ID,
				'post_name'      => 'postrelease',
				'post_date'      => '1960-01-01 00:0:00',
				'post_date_gmt'  => '1960-01-01 00:00:00',
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
			];

			$post_id = wp_insert_post( $args );

			// save in wordpress db so we can remove that when deactivating plugin.
			update_option( 'prx_template_post_id', $post_id );
		}

		/**
		 * Template redirect
		 *
		 * @return void
		 */
		public function template_redirect() {
			global $wp_query;

			// functions that don't require security check.
			if ( isset( $wp_query->query_vars['prx'] ) ) {
				$function = sanitize_text_field( $wp_query->query_vars['prx'] );
				// redirect to full ad page.
				if ( ( 'ad' === $function ) && ( 1 === intval( get_option( 'prx_plugin_activated', 0 ) ) ) ) {
					// redirect to template page.
					$template_post_id = intval( get_option( 'prx_template_post_id', 1 ) );

					if ( ! $template_post_id ) {
						return;
					}
					$template_post_url = get_permalink( $template_post_id );
					if ( false !== $template_post_url ) {
						$query_struct = [];
						$query_string = isset( $_SERVER['QUERY_STRING'] ) ? esc_url_raw( wp_unslash( $_SERVER['QUERY_STRING'] ) ) : '';
						parse_str( $query_string, $query_struct );
						$query_struct = rawurlencode( $query_struct );
						// keeping URL parameters when we redirect (prx_t and prx_rk need to stay in the URL).
						$template_post_url = add_query_arg( $query_struct, $template_post_url );
						// prx=page so that we do not get redirected anymore.
						$template_post_url = add_query_arg( 'prx', 'page', $template_post_url );
						wp_safe_redirect( esc_url( $template_post_url ) );
						exit;
					}
				}
			}
		}

		/**
		 * Security check - validate key
		 *
		 * @param string $key security MD5 encoded
		 * @return boolean
		 */
		public function is_key_valid( $key ) {
			if ( false !== get_option( 'prx_plugin_key', false ) ) {
				$md5_key = md5( get_option( 'prx_plugin_key' ) );
				return ( 0 === strcmp( $md5_key, $key ) ) ? true : false;
			}
			return false;
		}

		/**
		 * Authenticate if IP is coming from localhost or from a PostRelease server
		 *
		 * @return boolean
		 */
		public function authenticate_ip() {
			$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
			if ( 0 === strlen( $ip ) ) {
				$ip = sanitize_text_field( getenv( 'REMOTE_ADDR' ) );
			} else {
				$ip = trim( $ip );
			}
			$args = [
				'method'     => 'GET',
				'timeout'    => 2,
				'user-agent' => 'Wordpress_plugin',
				'sslverify'  => true,
			];
			$response = wp_safe_remote_get( trailingslashit( $this->server ) . 'plugins/Api/AuthenticateIP?ip=' . $ip, $args );
			if ( is_wp_error( $response ) ) {
				return false;
			}
			$body = wp_remote_retrieve_body( $response );
			$obj = json_decode( $body );
			return isset( $obj->result ) && 1 == $obj->result;
		}

		/**
		 * Creates an entry in the admin menu for
		 * Post Release settings
		 */
		public function create_menu() {
			// Add PostRelease menu item under the "Settings" top-level menu
			add_submenu_page( 'options-general.php', __( 'Nativo Dashboard', 'nativo' ), __( 'Nativo', 'nativo' ), 'manage_options', 'postrelease', [ $this, 'settings_page' ] );
		}

		/**
		 * Opens iframe to http://www.postrelease.com
		 * User can check his PostRelease publication dashboard
		 * and Edit the template of his publication.
		 * All of this is done inside the iframe.
		 *
		 * @return void, outputs html
		 */
		public function settings_page() {
			$dashboard_path = $this->server . 'wpplugin/Index/?PublicationUrl=' . urlencode( home_url( '/' , 'http' ) ) . '&vip=1';
			echo '<iframe src="' . esc_url( $dashboard_path ) . '" style="margin: 0 auto;" width="700px" height="900px" frameborder="0" scrolling="no"></iframe>';
		}

		/**
		 * Check if needs to run upgrade routine
		 *
		 * @return void
		 */
		public function admin_init() {
			$current_db_version = get_option( 'prx_database_version', 0 );
			if ( $current_db_version < $this->db_version ) {
				$this->upgrade( $this->db_version );
				update_option( 'prx_database_version', $this->db_version );
			}
		}

		/**
		 * Upgrade routine
		 *
		 * @return void
		 */
		public function upgrade( $current_db_version ) {
			// create template post
			if ( 1 >= $current_db_version ) {
				$this->check_full_page();
			}
		}

		/**
		 * Handle signup requests
		 *
		 * @return void
		 */
		public function handle_signup_requests() {
			if ( isset( $_REQUEST['prx'] ) ) {
				$function = strtolower( sanitize_text_field( wp_unslash( $_REQUEST['prx'] ) ) );

				if ( 'prx_generate_key' === $function ) {
					$this->generate_security_key();
					exit;
				}

				// security check, block any requests that do not have the security key
				$security_check_ok = $this->is_dev;

				if ( isset( $_REQUEST['id'] ) ) { //there is a key saved here
					$key_url = sanitize_text_field( wp_unslash( $_REQUEST['id'] ) );
					$security_check_ok = $this->is_key_valid( $key_url );
				}

				// these functions require security check to be called
				if ( $security_check_ok && $this->authenticate_ip() ) {
					// enable plugin during sign up process
					if ( 'enable' === $function ) {
						if ( isset( $_REQUEST['status'] ) ) {
							$this->enable_plugin( sanitize_text_field( wp_unslash( $_REQUEST['status'] ) ) );
						} else if ( 'getposts' === $function ) {
							$this->get_posts_xml_for_indexing();
						} else if ( 'status' === $function ) {
							$this->get_status();
						} else if ( 'check' === $function ) {
							$this->get_check();
						}
						exit;
					}
				}
			}
		}

		/**
		 * Handle enable request/send success response
		 *
		 * @return void
		 */
		public function handle_enable_request() {
			if (
				isset( $_GET['do'] )
				&& 'update' == $_GET['do']
				&& isset( $_GET['postrelease_enable'] )
				&& '1' == $_GET['postrelease_enable']
			) {
				$this->send_success_response();
				exit;
			}
		}

		/**
		 * This code returns XML that follows the schema
		 * specified in PostRelease API documentation.
		 * This XML will be used to hydrate our indexing system that is necessary to
		 * suggest what are the best publications for an ad.
		 *
		 * @return void, outputs XML
		 */
		public function get_posts_xml_for_indexing() {
			// by default return 200 posts
			$default_number_posts = 200;

			// sanitizing num URL parameter
			$number_posts = isset( $_REQUEST['num'] ) ? intval( $_REQUEST['num'] ) : 0;

			if ( 1 > $number_posts || 200 < $number_posts ) {
				$number_posts = $default_number_posts;
			}

			$args = [
				'posts_per_page' => $number_posts,
				'ignore_sticky_posts' => true,
				'no_found_rows' => true,
			];
			$posts_for_xml = new WP_Query( $args );

			if ( $posts_for_xml->have_posts() ) :
			?>
				<?php echo '<?xml version="1.0" encoding="UTF-8" ?>'; // phpcs flags w/o echoing. ?>
				<articles>;
				<?php while ( $posts_for_xml->have_posts() ) : $posts_for_xml->the_post(); ?>
					<article>
						<id><?php echo intval( get_the_ID() ); ?></id>
						<link><?php echo '<![CDATA[' . esc_url( get_permalink() ) . ']]>'; ?></link>
						<title><?php echo '<![CDATA[' . esc_html( get_the_title() ) . ']]>'; ?></title>
						<content><?php echo '<![CDATA[' . wp_kses_post( get_the_content() ) . ']]>'; ?></content>
					</article>
				<?php endwhile; ?>
				</articles>
			<?php
			endif;
		}

		/**
		 * Does nothing and just returns a result=1 json
		 * This is called by sign up process (always returns 1)
		 * The plugin will be enabled with the prx=enable call (method enable_plugin)
		 * We need to maintain this call because it's part of server sign up process
		 *
		 * @return void, outputs html
		 */
		public function send_success_response() {
			if ( isset( $_REQUEST['callback'] ) && preg_match( '/\W/', sanitize_text_field( wp_unslash( $_REQUEST['callback'] ) ) ) ) {
				header( 'HTTP/1.1 400 Bad Request' );
				exit;
			}
			header( 'Cache-Control: no-cache, must-revalidate' );
			header( 'Content-type: application/javascript; charset=utf-8' );
			$data = [ 'result' => 1 ];
			$callback = sanitize_text_field( wp_unslash( $_REQUEST['callback'] ) );
			echo esc_html( sprintf( '%s(%s);', $callback, wp_json_encode( $data ) ) );
		}

		/**
		 * Generates a random security key that will be saved both
		 * on the blog side as well as on the PostRelease side
		 * This key will be necessary to make calls to the plugin during the sign up
		 * process and later for indexing purposes
		 *
		 * @return void
		 */
		public function generate_security_key() {
			// if key already exists
			if ( false !== get_option( 'prx_plugin_key', false ) ) {
				echo 'FAIL: key already exists';
				return;
			}

			if ( $this->authenticate_ip() ) {
				$key = wp_generate_password( 10, false, false );
				update_option( 'prx_plugin_key', $key );
				$data['Key'] = $key;
				echo wp_json_encode( $data );
			} else {
				esc_html_e( 'FAIL: IP authentication error', 'nativo' );
			}
		}

		/**
		 * Returns information about the blog
		 * This is called by the PostRelease server during
		 * sign up
		 *
		 * @return void
		 */
		public function get_status() {
			$response                     = [];
			$response['PublicationTitle'] = $this->get_blog_name();
			$response['Enabled']          = get_option( 'prx_plugin_activated' );
			$response['PlatformVersion']  = get_bloginfo( 'version' );
			$response['PluginVersion']    = $this->plugin_version . '.vip';
			echo wp_json_encode( $response );
		}

		/**
		 * Returns detailed information about the blog
		 *
		 * @return void, outputs JSON
		 */
		public function get_check() {
			$response                       = [];
			$response['plugin_activated']   = get_option( 'prx_plugin_activated' );
			$response['site_url']           = site_url();
			$response['plugin_version']     = $this->plugin_version . '.vip';
			$response['postrelease_server'] = $this->server;
			$response['javascript_url']     = $this->js_url;
			$response['db_version']         = $this->db_version;
			$response['php_version']        = phpversion();
			$response['wordpress_version']  = get_bloginfo( 'version' );
			$response['blog_title']         = $this->get_blog_name();
			$response['template_post_id']   = get_option( 'prx_template_post_id', '-1' );
			$response['prx_dev']            = $this->is_dev;
			echo wp_json_encode( $response );
		}

		/**
		 * Enable or Disable the plugin
		 * This basically updates the WP option prx_plugin_activated
		 * This function is called by the PostRelease server during
		 * sign up
		 *
		 * @return void, outputs JSON
		 */
		public function enable_plugin( $enable ) {
			$enable = intval( $enable );
			$data['result'] = 0;

			if ( 1 === $enable || 0 === $enable ) {
				// plugin activated changed
				if ( intval( get_option( 'prx_plugin_activated' ) ) !== $enable ) {
					if ( 0 === $enable ) {
						$this->deactivate();
					} else if ( 1 === $enable ) {
						$this->activate();
					}
				}
				update_option( 'prx_plugin_activated', (int) $enable );
				$data['result'] = 1;
			}
			echo wp_json_encode( $data );
		}

		/**
		 * Return the blog name.
		 * If it's not set, return the blog URL.
		 */
		public function get_blog_name() {
			if ( 0 === strlen( trim( get_bloginfo( 'name' ) ) ) ) {
				return site_url();
			} else {
				return get_bloginfo( 'name' );
			}
		}

		/**
		 * Change author display name to "Promoted" in secondary impression
		 */
		public function the_author( $author ) {
			global $post;
			$template_post_id = get_option( 'prx_template_post_id' );
			if ( ! empty( $post->ID ) && ( intval( get_option( 'prx_template_post_id', -1 ) ) === $post->ID ) ) {
				$author = 'Promoted';
			}
			return $author;
		}

		/**
		 * Change author link to blog homepage URL in secondary impression
		 */
		public function author_link( $link, $author_id ) {
			global $post;
			if ( ! empty( $post->ID ) && ( intval( get_option( 'prx_template_post_id', -1 ) ) === $post->ID ) ) {
				$link = esc_url( get_home_url( $post->ID ) );
			}
			return $link;
		}

		/**
		 * Change date to today's date in secondary impression
		 * We do not want to display that fake date of 1/1/1960
		 *
		 * @param  string $date_time the date/time
		 * @param  string $format    the date format
		 * @return string            formatted date
		 */
		public function get_the_date_time( $date_time, $format ) {
			global $post;
			if ( intval( get_option( 'prx_template_post_id', -1 ) ) === $post->ID ) {
				$date_time = date( $d, time() );
			}
			return $date_time;
		}

		/**
		 * Notify the PostRelease server that the plugin in this publication was activated
		 *
		 * @return boolean
		 */
		public function notify_activate() {
			// notify PostRelease team that plugin was activated
			$args = [
				'method'     => 'POST',
				'timeout'    => 2,
				'user-agent' => 'Wordpress_plugin',
				'sslverify'  => true,
			];
			$url = rawurlencode( $this->server . 'plugins/Api/PluginActivated?url=' . site_url() );
			return is_wp_error( wp_safe_remote_post( $url, $args ) );
		}

		/**
		 * Notify the PostRelease server that the plugin in this publication was deactivated
		 *
		 * @return boolean
		 */
		public function notify_deactivate() {
			// notify PostRelease team that plugin was deactivated
			$args = [
				'method'     => 'POST',
				'timeout'    => 2,
				'user-agent' => 'Wordpress_plugin',
				'sslverify'  => true,
			];
			$url = rawurlencode( $this->server . 'plugins/Api/PluginDeactivated?url=' . site_url() );
			return is_wp_error( wp_safe_remote_post( $url, $args ) );
		}

	}

	Nativo::get_instance();

} // End if().
