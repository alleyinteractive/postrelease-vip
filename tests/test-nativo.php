<?php
/**
 * Class NativoTests
 *
 */
class NativoTests extends WP_UnitTestCase {

	protected $post_type = 'pr_sponsored_post';

	protected $current_user, $post_id, $posts, $password;

	public function setUp() {
		parent::setUp();

		// Current user
		$this->current_user = wp_set_current_user( 1 );

		$single_post_args = [
			'post_title'     => '<span class="prx_title"></span>',
			'post_content'   => '<span class="prx_body"></span>',
			'post_type'      => $this->post_type,
			'post_status'    => 'publish',
			'post_author'    => $this->current_user->ID,
			'post_name'      => 'postrelease',
			'post_date'      => '1960-01-01 00:0:00',
			'post_date_gmt'  => '1960-01-01 00:00:00',
			'comment_status' => 'closed',
			'ping_status'    => 'closed',
		];

		// Generated password
		$this->password = wp_generate_password( 10, false, false );

		// Create a test post.
		$this->post_id = $this->factory->post->create( $single_post_args );

		// Create 5 test posts
		$this->posts = $this->factory->post->create_many( 5, $single_post_args );

		// Create
	}

	public function tearDown() {
		parent::tearDown();
	}

	public function test_pr_sponsored_post_custom_post_type_creation() {
		global $wp_post_types;
		$this->assertArrayHasKey( $this->post_type, $wp_post_types );
	}

	public function test_create_page() {
		// Does the test post created exist.
		$post = get_post( $this->post_id );
		$this->assertEquals( $this->post_id, $post->ID );

		// Test the option update
		$this->assertTrue( update_option( 'prx_template_post_id', $this->post_id ) );
	}

	/**
	 * Test removal of sponsored posts
	 */
	public function test_sponsored_posts_removal() {
		foreach ( $this->posts as $id ) {
			$this->assertNotFalse( wp_delete_post( $id, true ) );
		}
	}

	public function test_options_removal() {
		update_option( 'prx_template_post_id', 1 );
		$this->assertTrue( delete_option( 'prx_template_post_id' ) );

		update_option( 'prx_plugin_activated', true );
		$this->assertTrue( delete_option( 'prx_plugin_activated' ) );

		update_option( 'prx_plugin_key', $this->password );
		$this->assertTrue( delete_option( 'prx_plugin_key' ) );

		update_option( 'prx_database_version', 1 );
		$this->assertTrue( delete_option( 'prx_database_version' ) );
	}

	public function test_is_key_valid() {
		update_option( 'prx_plugin_key', $this->password );
		$md5_key = md5( get_option( 'prx_plugin_key' ) );
		$this->assertEquals( $md5_key, md5( $this->password ) );
	}

	// public function test_ () {

	// }

	// public function test_ () {

	// }

	// public function test_ () {

	// }

	// public function test_ () {

	// }
}
