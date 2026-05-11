<?php
/**
 * Keep an indexed list of password-protected URL paths.
 *
 * This index is written to a small PHP file in `wp-content/breeze-config/`
 * and is consumed by early cache execution logic to avoid expensive post scans.
 *
 * @package Breeze
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manage password-protected URL path index files.
 */
class Breeze_Protected_Urls_Index {

	/**
	 * Register content lifecycle hooks.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'ensure_index_file_exists' ), 20 );
		add_action( 'save_post', array( $this, 'handle_save_post' ), 10, 3 );
		add_action( 'deleted_post', array( $this, 'handle_deleted_post' ) );
		add_action( 'trashed_post', array( $this, 'handle_deleted_post' ) );
		add_action( 'untrashed_post', array( $this, 'handle_save_post_by_id' ) );
	}

	/**
	 * Build index once if the file is missing.
	 *
	 * @return void
	 */
	public function ensure_index_file_exists() {
		if ( is_file( self::get_index_file_path() ) ) {
			return;
		}

		self::rebuild_current_blog_index();
	}

	/**
	 * Save/update handler for posts.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @param bool    $update  Whether this is an existing post being updated.
	 * @return void
	 */
	public function handle_save_post( $post_id, $post, $update ) {
		unset( $update );

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( ! ( $post instanceof WP_Post ) ) {
			$post = get_post( $post_id );
			if ( ! ( $post instanceof WP_Post ) ) {
				return;
			}
		}

		if ( ! $this->should_track_post_type( $post->post_type ) ) {
			$this->remove_post_from_index( $post_id );
			return;
		}

		$is_published          = ( 'publish' === $post->post_status );
		$is_password_protected = ! empty( $post->post_password );

		if ( $is_published && $is_password_protected ) {
			$path = $this->get_post_path( $post_id );
			if ( '' !== $path ) {
				$this->upsert_post_path( $post_id, $path );
				return;
			}
		}

		$this->remove_post_from_index( $post_id );
	}

	/**
	 * Update handler when only post ID is available.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function handle_save_post_by_id( $post_id ) {
		$post = get_post( $post_id );
		if ( ! ( $post instanceof WP_Post ) ) {
			return;
		}

		$this->handle_save_post( $post_id, $post, true );
	}

	/**
	 * Remove post from index on deletion-like events.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function handle_deleted_post( $post_id ) {
		$this->remove_post_from_index( $post_id );
	}

	/**
	 * Rebuild index for the current blog from scratch.
	 *
	 * @return void
	 */
	public static function rebuild_current_blog_index() {
		$post_ids = get_posts(
			array(
				'post_type'      => get_post_types( array( 'public' => true ), 'names' ),
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'has_password'   => true,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		$paths_by_post_id = array();
		foreach ( $post_ids as $post_id ) {
			$path = self::normalize_path( self::get_post_path_static( (int) $post_id ) );
			if ( '' !== $path ) {
				$paths_by_post_id[ (int) $post_id ] = $path;
			}
		}

		self::write_index( $paths_by_post_id );
	}

	/**
	 * Delete index files on uninstall.
	 *
	 * @return void
	 */
	public static function delete_index_files_on_uninstall() {
		$index_files = glob( trailingslashit( WP_CONTENT_DIR ) . 'breeze-config/breeze-protected-urls*.php' );
		if ( empty( $index_files ) ) {
			return;
		}

		foreach ( $index_files as $index_file ) {
			if ( is_file( $index_file ) ) {
				@unlink( $index_file );
			}
		}
	}

	/**
	 * Check if a post type should be indexed.
	 *
	 * @param string $post_type Post type.
	 * @return bool
	 */
	private function should_track_post_type( $post_type ) {
		$post_type_object = get_post_type_object( $post_type );
		if ( empty( $post_type_object ) ) {
			return false;
		}

		if ( empty( $post_type_object->public ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Insert or update a post path in index.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $path    Normalized path.
	 * @return void
	 */
	private function upsert_post_path( $post_id, $path ) {
		$paths_by_post_id = self::read_index();
		$post_id          = (int) $post_id;
		$normalized_path  = self::normalize_path( $path );

		if ( isset( $paths_by_post_id[ $post_id ] ) && $paths_by_post_id[ $post_id ] === $normalized_path ) {
			return;
		}

		$paths_by_post_id[ $post_id ] = $normalized_path;
		self::write_index( $paths_by_post_id );
	}

	/**
	 * Remove post from index map.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	private function remove_post_from_index( $post_id ) {
		$paths_by_post_id = self::read_index();
		$post_id          = (int) $post_id;

		if ( ! isset( $paths_by_post_id[ $post_id ] ) ) {
			return;
		}

		unset( $paths_by_post_id[ $post_id ] );
		self::write_index( $paths_by_post_id );
	}

	/**
	 * Read index map from file.
	 *
	 * @return array<int,string>
	 */
	private static function read_index() {
		$index_file = self::get_index_file_path();
		if ( ! is_file( $index_file ) ) {
			return array();
		}

		$index_payload = include $index_file;
		if ( ! is_array( $index_payload ) ) {
			return array();
		}

		if ( isset( $index_payload['paths_by_post_id'] ) && is_array( $index_payload['paths_by_post_id'] ) ) {
			return $index_payload['paths_by_post_id'];
		}

		return array();
	}

	/**
	 * Persist index map atomically.
	 *
	 * @param array<int,string> $paths_by_post_id Map of post ID to normalized path.
	 * @return void
	 */
	private static function write_index( array $paths_by_post_id ) {
		$index_file = self::get_index_file_path();
		$index_dir  = dirname( $index_file );

		if ( ! wp_mkdir_p( $index_dir ) ) {
			return;
		}

		$payload = array(
			'paths_by_post_id' => $paths_by_post_id,
		);

		$file_contents = "<?php\nreturn " . var_export( $payload, true ) . ";\n";
		$temp_file     = $index_file . '.' . getmypid() . '.tmp';

		if ( false === file_put_contents( $temp_file, $file_contents, LOCK_EX ) ) {
			return;
		}

		@rename( $temp_file, $index_file );
	}

	/**
	 * Resolve normalized path for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private function get_post_path( $post_id ) {
		return self::get_post_path_static( $post_id );
	}

	/**
	 * Resolve normalized path for a post (static helper).
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private static function get_post_path_static( $post_id ) {
		$permalink = get_permalink( $post_id );
		if ( empty( $permalink ) ) {
			return '';
		}

		$path = wp_parse_url( $permalink, PHP_URL_PATH );
		if ( ! is_string( $path ) ) {
			return '';
		}

		return self::normalize_path( $path );
	}

	/**
	 * Normalize URL path for stable matching.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	private static function normalize_path( $path ) {
		$decoded_path = rawurldecode( (string) $path );
		$trimmed_path = rtrim( $decoded_path, '/' );
		if ( '' === $trimmed_path ) {
			$trimmed_path = '/';
		}

		if ( function_exists( 'mb_strtolower' ) ) {
			return mb_strtolower( $trimmed_path );
		}

		return strtolower( $trimmed_path );
	}

	/**
	 * Resolve index file path for current blog.
	 *
	 * @return string
	 */
	private static function get_index_file_path() {
		$config_dir = trailingslashit( WP_CONTENT_DIR ) . 'breeze-config';
		$filename   = 'breeze-protected-urls.php';

		if ( is_multisite() ) {
			$blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
			if ( ! empty( $blog_id ) ) {
				$filename = 'breeze-protected-urls-' . $blog_id . '.php';
			}
		}

		return $config_dir . '/' . $filename;
	}
}
