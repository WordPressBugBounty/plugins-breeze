<?php
/*
 *  Based on some work of autoptimize plugin
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

class Breeze_MinificationHtml extends Breeze_MinificationBase {
	private $keepcomments          = false;
	private $exclude               = array( '<!-- ngg_resource_manager_marker -->' );
	private $original_content      = '';
	private $show_original_content = 0;
	private $do_process            = false;
	private $forcexhtml            = false;
	private $internal_exclude_tag  = 'breeze-wp-comments-exclude';

	public function read( $options ) {
		$this_path_url = $this->get_cache_file_url( '' );
		if ( false === breeze_is_process_locked( $this_path_url ) ) {
			$this->do_process = breeze_lock_cache_process( $this_path_url );
		} else {
			$this->original_content = $this->content;

			return true;
		}

		// Remove the HTML comments?
		$this->keepcomments = (bool) $options['keepcomments'];

		// filter to force xhtml
		$this->forcexhtml = (bool) apply_filters( 'breeze_filter_html_forcexhtml', false );

		// filter to add strings to be excluded from HTML minification
		$excludeHTML = apply_filters( 'breeze_filter_html_exclude', '' );
		if ( $excludeHTML !== '' ) {
			$exclHTMLArr   = array_filter( array_map( 'trim', explode( ',', $excludeHTML ) ) );
			$this->exclude = array_merge( $exclHTMLArr, $this->exclude );
		}

		// Nothing else for HTML
		return true;
	}

	//Joins and optimizes CSS
	public function minify() {
		if ( false === $this->do_process ) {
			return true;
		}

		$noptimizeHTML = apply_filters( 'breeze_filter_html_noptimize', false, $this->content );
		if ( $noptimizeHTML ) {
			return false;
		}

		if ( class_exists( 'Minify_HTML' ) ) {
			// wrap the to-be-excluded strings in noptimize tags
			foreach ( $this->exclude as $exclString ) {
				$this->content = $this->wrap_html_exclusion_in_noptimize( $this->content, $exclString );
			}

			// noptimize me
			$this->content = $this->hide_noptimize( $this->content );

			// Minify html
			$options = array( 'keepComments' => $this->keepcomments );
			if ( $this->forcexhtml ) {
				$options['xhtml'] = true;
			}

			if ( method_exists( 'Minify_HTML', 'minify' ) ) {
				$tmp_content = Minify_HTML::minify( $this->content, $options );
				if ( ! empty( $tmp_content ) ) {
					$this->content = $tmp_content;
					unset( $tmp_content );
				}
			}

			// restore noptimize
			$this->content = $this->restore_noptimize( $this->content );
			$this->content = $this->cleanup_internal_html_exclusion_wrappers( $this->content );

			// remove the noptimize-wrapper from around the excluded strings
			foreach ( $this->exclude as $exclString ) {
				$replString = '<!--noptimize-->' . $exclString . '<!--/noptimize-->';
				if ( strpos( $this->content, $replString ) !== false ) {
					$this->content = str_replace( $replString, $exclString, $this->content );
				}
			}

			return true;
		}

		// Didn't minify :(
		return false;
	}

	/**
	 * Wrap excluded content in noptimize comments.
	 *
	 * For default WP comments markers, we wrap the complete block to keep that
	 * section unchanged while minifying the rest of the document.
	 *
	 * @param string $content The HTML content.
	 * @param string $exclude_string Exclusion marker.
	 * @return string
	 */
	private function wrap_html_exclusion_in_noptimize( $content, $exclude_string ) {
		$marker_pattern = $this->build_exclusion_marker_pattern( $exclude_string );
		if ( ! preg_match( '#' . $marker_pattern . '#i', $content ) ) {
			return $content;
		}

		$already_wrapped_pattern = '#<!--\s?' . preg_quote( $this->internal_exclude_tag, '#' ) . '\s?-->[\s\S]*?'
			. $marker_pattern
			. '[\s\S]*?<!--\s?/\s?' . preg_quote( $this->internal_exclude_tag, '#' ) . '\s?-->#i';

		if ( preg_match( $already_wrapped_pattern, $content ) ) {
			return $content;
		}

		$block_patterns = array(
			'#<form\b[^>]*' . $marker_pattern . '[^>]*>[\s\S]*?</form>#i',
			'#<(?:div|section|article|aside)\b[^>]*' . $marker_pattern . '[^>]*>[\s\S]*?</(?:div|section|article|aside)>#i',
			'#<(?:ol|ul)\b[^>]*' . $marker_pattern . '[^>]*>[\s\S]*?</(?:ol|ul)>#i',
		);

		foreach ( $block_patterns as $pattern ) {
			$updated_content = preg_replace_callback(
				$pattern,
				function ( $matches ) {
					$matched_block = $matches[0];
					if ( false !== strpos( $matched_block, '<!--noptimize-->' ) ) {
						return $matched_block;
					}

					return '<!--noptimize--><!--' . $this->internal_exclude_tag . '-->'
						. $matched_block
						. '<!--/' . $this->internal_exclude_tag . '--><!--/noptimize-->';
				},
				$content
			);

			if ( null !== $updated_content && $updated_content !== $content ) {
				return $updated_content;
			}
		}

		return $content;
	}

	/**
	 * Build a regex-safe exclusion marker pattern.
	 *
	 * Standalone marker words should not match inside larger words, e.g.
	 * "comments" should not match "comments-list".
	 *
	 * @param string $exclude_string Exclusion marker.
	 * @return string
	 */
	private function build_exclusion_marker_pattern( $exclude_string ) {
		if ( strpos( $exclude_string, '=' ) !== false || strpos( $exclude_string, '"' ) !== false || strpos( $exclude_string, "'" ) !== false ) {
			return preg_quote( $exclude_string, '#' );
		}

		return '(?<![A-Za-z0-9_-])' . preg_quote( $exclude_string, '#' ) . '(?![A-Za-z0-9_-])';
	}

	/**
	 * Remove internal wrappers used for HTML exclusion.
	 *
	 * @param string $content HTML content after noptimize restore.
	 * @return string
	 */
	private function cleanup_internal_html_exclusion_wrappers( $content ) {
		$pattern = '#<!--\s?noptimize\s?-->\s*<!--\s?'
			. preg_quote( $this->internal_exclude_tag, '#' )
			. '\s?-->([\s\S]*?)<!--\s?/\s?'
			. preg_quote( $this->internal_exclude_tag, '#' )
			. '\s?-->\s*<!--\s?/\s?noptimize\s?-->#i';

		$updated_content = preg_replace( $pattern, '$1', $content );
		if ( null !== $updated_content ) {
			return $updated_content;
		}

		return $content;
	}

	// Does nothing
	public function cache() {
		//No cache for HTML
		return true;
	}

	//Returns the content
	public function getcontent() {
		if ( ! empty( $this->show_original_content ) ) {
			return $this->original_content;
		}

		if ( true === $this->do_process ) {
			$this_path_url = $this->get_cache_file_url( '' );
			breeze_unlock_process( $this_path_url );
			return $this->content;
		} else {
			return $this->original_content;
		}

		//return $this->content;
	}
}
