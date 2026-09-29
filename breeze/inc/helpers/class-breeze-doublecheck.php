<?php

use MatthiasMullie\Minify;
use Symfony\Component\CssSelector\CssSelectorConverter;

class Breeze_Doublecheck {

	var string $loader_class = 'breeze-dc-elem';
	var string $minify_css   = '0';
	var string $minify_js    = '0';
	private static $css_selector_converter = null;
	private const DC_CACHE_GROUP   = 'breeze_dc';
	private const DC_CACHE_TTL     = DAY_IN_SECONDS;
	private const DC_SCRIPT_SCHEMA = '6';


	public function init( $buffer ) {
		// Get the breeze config values
		//breeze_config   = $GLOBALS['breeze_config']['cache_options'];
		$elements        = Breeze_Options_Reader::get_option_value( 'breeze-doublecheck-elements' ) ?? array();
		if ( ! is_array( $elements ) ) {
			$elements = array();
		}
		$elements        = array_values(
			array_filter(
				$elements,
				static function ( $selector ) {
					return is_string( $selector ) && ! breeze_is_doublecheck_unsupported_root_selector( $selector );
				}
			)
		);
		if ( empty( $elements ) ) {
			return $buffer;
		}
		$load_type       = Breeze_Options_Reader::get_option_value( 'breeze-doublecheck-load' ) ?? 'async';
		$overlay_enabled = Breeze_Options_Reader::get_option_value( 'breeze-html-doublecheck-loader' ) ?? false;
		$overlay_color   = Breeze_Options_Reader::get_option_value( 'breeze-html-doublecheck-loader-overlay' ) ?? '#3a12fa';

		$this->minify_css = Breeze_Options_Reader::get_option_value( 'breeze-minify-css' );
		$this->minify_js  = Breeze_Options_Reader::get_option_value( 'breeze-minify-js' );

		// Edit the markup to add classes
		$edited_markup = $this->edit_markup( $buffer, $elements );
		// Continue only when at least one selector matched in the response markup.
		if ( $edited_markup['has_elements'] ) {
			$buffer = $edited_markup['buffer'];
			// Add overlay CSS only when the loader overlay option is enabled.
			if ( $overlay_enabled ) {
				// Inject the styles into the buffer
				$buffer = $this->load_styles( $buffer, $overlay_color );
			}

			// Inject the script into the buffer
			$buffer = $this->load_scripts( $buffer, $load_type, $elements );
		}

		return $buffer;
	}

	public function edit_markup( $buffer, $elements ): array {
		if ( ! class_exists( 'DOMDocument' ) || ! class_exists( 'DOMXPath' ) ) {
			// ext-dom is optional. Constructing these classes would fatal the request.
			return array(
				'buffer'       => $buffer,
				'has_elements' => false,
			);
		}

		$dom = new DOMDocument();
		@$dom->loadHTML( $buffer );

		$xpath           = new DOMXPath( $dom );
		$classToAdd      = $this->loader_class;
		$has_elements    = false;
		$output          = array();
		$search_offsets  = array();
		$raw_text_ranges = $this->collect_raw_text_ranges( $buffer );

		foreach ( $elements as $selector ) {
			if ( ! is_string( $selector ) || breeze_is_doublecheck_unsupported_root_selector( $selector ) ) {
				continue;
			}

			$query = $this->cssToXPath( $selector );

			// Skip invalid or unsupported selectors that cannot be converted to XPath.
			if ( '' === $query ) {
				continue;
			}

			$nodes = $xpath->query( $query );

			// Skip the selector when XPath execution fails unexpectedly.
			if ( false === $nodes ) {
				continue;
			}

			// Patch tags only when current selector found at least one DOM node.
			if ( $nodes->length > 0 ) {
				$has_elements = true;
				foreach ( $nodes as $node ) {
					// Guard against non-element nodes; class attributes only apply to elements.
					if ( ! ( $node instanceof DOMElement ) ) {
						continue;
					}

					$buffer = $this->patch_node_opening_tag_class_in_buffer( $buffer, $node, $classToAdd, $search_offsets, $raw_text_ranges );
				}
			}
		}

		// Return original buffer with only matched opening tags updated.
		$output['buffer']       = $buffer;
		$output['has_elements'] = $has_elements;

		return $output;
	}

	/**
	 * Byte ranges whose contents are not elements.
	 *
	 * Each script or style range is the interior only, so the opening tag can
	 * still be patched. A comment range covers the whole comment. Comment
	 * markers inside a script or style block are ignored.
	 *
	 * @param string $buffer Original HTML buffer.
	 * @return array<int, array{start: int, end: int}>
	 */
	private function collect_raw_text_ranges( string $buffer ): array {
		$ranges   = array();
		$patterns = array(
			'/<script\b[^>]*>(.*?)<\/script\s*>/is',
			'/<style\b[^>]*>(.*?)<\/style\s*>/is',
		);

		foreach ( $patterns as $pattern ) {
			$matches = array();
			if ( false === preg_match_all( $pattern, $buffer, $matches, PREG_OFFSET_CAPTURE ) ) {
				continue;
			}

			foreach ( $matches[1] as $inner ) {
				$start  = (int) $inner[1];
				$length = strlen( $inner[0] );
				if ( $length < 1 ) {
					continue;
				}

				$ranges[] = array(
					'start' => $start,
					'end'   => $start + $length,
				);
			}
		}

		// Blank script and style interiors so a <!-- inside them cannot pair
		// with a later -->. Length is unchanged, so offsets still match $buffer.
		$masked = $buffer;
		foreach ( $ranges as $range ) {
			$length = $range['end'] - $range['start'];
			$masked = substr_replace( $masked, str_repeat( ' ', $length ), $range['start'], $length );
		}

		$comments = array();
		if ( false !== preg_match_all( '/<!--.*?-->/s', $masked, $comments, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $comments[0] as $comment ) {
				$start    = (int) $comment[1];
				$ranges[] = array(
					'start' => $start,
					'end'   => $start + strlen( $comment[0] ),
				);
			}
		}

		return $ranges;
	}

	/**
	 * End offset when a match starts inside a raw-text range.
	 *
	 * @param array<int, array{start: int, end: int}> $ranges Raw-text ranges.
	 * @param int                                      $offset Match start.
	 * @return int Range end, or 0 when the match is a real tag.
	 */
	private function raw_text_range_end_after( array $ranges, int $offset ): int {
		$end = 0;

		foreach ( $ranges as $range ) {
			if ( $offset >= $range['start'] && $offset < $range['end'] && $range['end'] > $end ) {
				$end = $range['end'];
			}
		}

		return $end;
	}

	/**
	 * Move raw-text ranges that follow an insertion.
	 *
	 * @param array<int, array{start: int, end: int}> $ranges Raw-text ranges.
	 * @param int                                      $position Insertion point.
	 * @param int                                      $inserted Number of bytes inserted.
	 * @return void
	 */
	private function shift_raw_text_ranges( array &$ranges, int $position, int $inserted ): void {
		if ( 0 === $inserted ) {
			return;
		}

		foreach ( $ranges as $index => $range ) {
			if ( $range['start'] >= $position ) {
				$ranges[ $index ]['start'] += $inserted;
				$ranges[ $index ]['end']   += $inserted;
			}
		}
	}

	/**
	 * Patch one matched node opening tag in the original buffer.
	 *
	 * @param string                                   $buffer Original HTML buffer.
	 * @param DOMElement                               $node Matched node from DOM query.
	 * @param string                                   $class_to_add Class that should be added.
	 * @param array                                    $search_offsets Search offsets per matcher key.
	 * @param array<int, array{start: int, end: int}>  $raw_text_ranges Script, style, and comment ranges.
	 * @return string
	 */
	private function patch_node_opening_tag_class_in_buffer( string $buffer, DOMElement $node, string $class_to_add, array &$search_offsets, array &$raw_text_ranges ): string {
		$matcher_data = $this->build_node_opening_tag_matcher( $node );
		// Abort when no safe matcher could be built for the target node.
		if ( empty( $matcher_data['pattern'] ) ) {
			return $buffer;
		}

		$pattern = $matcher_data['pattern'];
		$key     = $matcher_data['key'];
		$offset  = isset( $search_offsets[ $key ] ) ? (int) $search_offsets[ $key ] : 0;

		$matches = array();
		// Skip copies of the tag that live in a script, style block, or comment.
		while ( $offset <= strlen( $buffer ) && 1 === preg_match( $pattern, $buffer, $matches, PREG_OFFSET_CAPTURE, $offset ) ) {
			$opening_tag_pos = (int) $matches[0][1];
			$skip_to         = $this->raw_text_range_end_after( $raw_text_ranges, $opening_tag_pos );

			if ( $skip_to > $opening_tag_pos ) {
				$offset = $skip_to;
				continue;
			}

			$opening_tag = $matches[0][0];
			$updated_tag = $this->add_class_to_opening_tag( $opening_tag, $class_to_add );

			$search_offsets[ $key ] = $opening_tag_pos + strlen( $updated_tag );

			// If class already exists, avoid rewriting and leave buffer untouched.
			if ( $updated_tag === $opening_tag ) {
				return $buffer;
			}

			$this->shift_raw_text_ranges( $raw_text_ranges, $opening_tag_pos, strlen( $updated_tag ) - strlen( $opening_tag ) );

			return substr( $buffer, 0, $opening_tag_pos ) . $updated_tag . substr( $buffer, $opening_tag_pos + strlen( $opening_tag ) );
		}

		return $buffer;
	}

	/**
	 * Build a safe regex matcher for one node opening tag.
	 *
	 * @param DOMElement $node Matched node from DOM query.
	 * @return array{pattern: string, key: string}
	 */
	private function build_node_opening_tag_matcher( DOMElement $node ): array {
		$tag_name = strtolower( $node->tagName );
		// A node without tag name cannot be matched back to an HTML opening tag.
		if ( '' === $tag_name ) {
			return array(
				'pattern' => '',
				'key'     => '',
			);
		}

		$id_value = trim( $node->getAttribute( 'id' ) );
		// Prefer id-based matching because ids are expected to be unique in the document.
		if ( '' !== $id_value ) {
			// Escape user/content-provided id to keep the regex literal and safe.
			$escaped_id = preg_quote( $id_value, '/' );
			return array(
				// Match opening tag by tag name and exact id attribute value.
				'pattern' => '/<' . $tag_name . '\b(?=[^>]*\bid\s*=\s*(["\'])' . $escaped_id . '\1)[^>]*>/i',
				'key'     => 'id:' . $tag_name . ':' . $id_value,
			);
		}

		$class_attr = trim( $node->getAttribute( 'class' ) );
		// Fallback to class-based matching when no id is present.
		if ( '' !== $class_attr ) {
			// Split classes by whitespace to build per-class lookaheads.
			$class_tokens = preg_split( '/\s+/', $class_attr );
			// Keep processing only when split result is a non-empty array.
			if ( is_array( $class_tokens ) && ! empty( $class_tokens ) ) {
				// Normalize class tokens: remove empty entries and duplicates.
				$class_tokens = array_values( array_unique( array_filter( $class_tokens ) ) );
				// If nothing remains after normalization, fall back to tag-only matcher.
				if ( empty( $class_tokens ) ) {
					return array(
						'pattern' => '/<' . $tag_name . '\b[^>]*>/i',
						'key'     => 'tag:' . $tag_name,
					);
				}

				$class_lookaheads = '';
				foreach ( $class_tokens as $class_token ) {
					// Escape class name to prevent regex meta characters from altering the pattern.
					$escaped_token     = preg_quote( $class_token, '/' );
					// Ensure each expected class token exists inside the class attribute value.
					$class_lookaheads .= '(?=[^>]*\bclass\s*=\s*["\'][^"\']*\b' . $escaped_token . '\b[^"\']*["\'])';
				}

				return array(
					// Match opening tag by tag name and all required class-token lookaheads.
					'pattern' => '/<' . $tag_name . '\b' . $class_lookaheads . '[^>]*>/i',
					'key'     => 'class:' . $tag_name . ':' . implode( '.', $class_tokens ),
				);
			}
		}

		// Final fallback: first opening tag for this tag name after current offset.
		return array(
			'pattern' => '/<' . $tag_name . '\b[^>]*>/i',
			'key'     => 'tag:' . $tag_name,
		);
	}

	/**
	 * Add CSS class to an opening HTML tag.
	 *
	 * @param string $opening_tag Opening tag to modify.
	 * @param string $class_to_add CSS class to add.
	 * @return string
	 */
	private function add_class_to_opening_tag( string $opening_tag, string $class_to_add ): string {
		$class_to_add = trim( $class_to_add );
		// Empty class input means there is nothing to patch.
		if ( '' === $class_to_add ) {
			return $opening_tag;
		}

		$matches = array();
		// Detect existing class attribute and capture quote style + current class list.
		if ( 1 === preg_match( '/\bclass\s*=\s*(["\'])([^"\']*)\1/i', $opening_tag, $matches ) ) {
			$current_classes = isset( $matches[2] ) ? trim( $matches[2] ) : '';
			// Skip updates when class already exists as a standalone token.
			if ( preg_match( '/(?:^|\s)' . preg_quote( $class_to_add, '/' ) . '(?:\s|$)/', $current_classes ) ) {
				return $opening_tag;
			}

			$updated_classes = trim( $current_classes . ' ' . $class_to_add );
			// Replace class attribute value while preserving original quote style.
			return (string) preg_replace_callback(
				'/\bclass\s*=\s*(["\'])[^"\']*\1/i',
				static function ( $class_matches ) use ( $updated_classes ) {
					$quote = isset( $class_matches[1] ) ? $class_matches[1] : '"';
					return 'class=' . $quote . $updated_classes . $quote;
				},
				$opening_tag,
				1
			);
		}

		// No class attribute exists: inject one before the closing ">" or "/>".
		return (string) preg_replace( '/\s*(\/?)>$/', ' class="' . $class_to_add . '"$1>', $opening_tag, 1 );
	}

	public function cssToXPath( $selector ) {
		$selector = trim( (string) $selector );

		// Reject empty selector lines early.
		if ( '' === $selector ) {
			return '';
		}

		// Prefer a full CSS-to-XPath converter when available.
		$converter = $this->get_css_selector_converter();
		if ( $converter instanceof CssSelectorConverter ) {
			try {
				$xpath = $converter->toXPath( $selector );
				if ( is_string( $xpath ) && '' !== trim( $xpath ) ) {
					return $xpath;
				}
			} catch ( \Exception $e ) {
				// Fall back to the lightweight converter below.
			}
		}

		// This lightweight converter intentionally supports only simple selectors.
		// Reject pseudo selectors, sibling combinators and attribute selectors not handled by converter.
		if ( preg_match( '/[:,+~\[\]\(\)]/', $selector ) ) {
			return '';
		}

		// Split selector by whitespace descendant combinator and direct-child ">" combinator.
		$parts = preg_split(
			'/(\s*>\s*|\s+)/',
			$selector,
			-1,
			PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY
		);

		// Abort when split failed or there is no usable selector segment.
		if ( false === $parts || empty( $parts ) ) {
			return '';
		}

		$xpath           = '//';
		$first_segment   = true;
		$expect_segment  = true;
		$next_combinator = '//';

		foreach ( $parts as $part ) {
			$part = trim( $part );

			// Ignore empty tokens produced by spacing normalization.
			if ( '' === $part ) {
				continue;
			}

			// Handle direct-child combinator token.
			if ( '>' === $part ) {
				// A combinator without a left-hand segment is invalid CSS.
				if ( true === $expect_segment ) {
					return '';
				}

				$next_combinator = '/';
				$expect_segment  = true;
				continue;
			}

			$segment = $this->build_xpath_segment( $part );
			// Stop when any selector token cannot be converted safely.
			if ( '' === $segment ) {
				return '';
			}

			// Glue converted segments with the selected combinator.
			if ( false === $first_segment ) {
				$xpath .= $next_combinator;
			}

			$xpath .= $segment;

			$first_segment   = false;
			$expect_segment  = false;
			$next_combinator = '//';
		}

		// Expression cannot end with a dangling combinator.
		if ( true === $expect_segment ) {
			return '';
		}

		return $xpath;
	}

	/**
	 * Get shared Symfony CSS selector converter when dependency is available.
	 *
	 * @return CssSelectorConverter|null
	 */
	private function get_css_selector_converter() {
		if ( false === self::$css_selector_converter ) {
			return null;
		}

		if ( null !== self::$css_selector_converter ) {
			return self::$css_selector_converter;
		}

		$autoload_path = dirname( dirname( __DIR__ ) ) . '/vendor/autoload.php';
		if ( file_exists( $autoload_path ) ) {
			require_once $autoload_path;
		}

		if ( class_exists( '\Symfony\Component\CssSelector\CssSelectorConverter' ) ) {
			self::$css_selector_converter = new CssSelectorConverter();
			return self::$css_selector_converter;
		}

		// Sentinel value to avoid repeated class_exists checks when dependency is unavailable.
		self::$css_selector_converter = false;
		return null;
	}

	/**
	 * Convert a simple CSS token to an XPath segment.
	 *
	 * Supported forms: tag, #id, .class, tag#id, tag.class1.class2.
	 *
	 * @param string $simple_selector CSS token.
	 * @return string
	 */
	protected function build_xpath_segment( $simple_selector ) {
		$simple_selector = trim( $simple_selector );

		// Empty token cannot form a valid XPath segment.
		if ( '' === $simple_selector ) {
			return '';
		}

		$matches = array();
		// Parse allowed token forms: optional tag + optional id/classes.
		$result  = preg_match( '/^([a-zA-Z][a-zA-Z0-9_-]*|\*)?((?:[#.][a-zA-Z0-9_-]+)*)$/', $simple_selector, $matches );
		// Reject token when it contains unsupported syntax.
		if ( 1 !== $result ) {
			return '';
		}

		$tag       = ! empty( $matches[1] ) ? $matches[1] : '*';
		$qualifier = isset( $matches[2] ) ? $matches[2] : '';

		$id_matches = array();
		// Extract id fragments from the qualifier (e.g. "#my-id").
		preg_match_all( '/#([a-zA-Z0-9_-]+)/', $qualifier, $id_matches );
		// Invalid token: more than one id in same simple selector.
		if ( ! empty( $id_matches[1] ) && count( $id_matches[1] ) > 1 ) {
			return '';
		}

		$class_matches = array();
		// Extract class fragments from the qualifier (e.g. ".card.primary").
		preg_match_all( '/\.([a-zA-Z0-9_-]+)/', $qualifier, $class_matches );

		$predicates = array();

		// Add id predicate when token contains an id.
		if ( ! empty( $id_matches[1] ) ) {
			$predicates[] = sprintf( '@id="%s"', $id_matches[1][0] );
		}

		// Add one contains(...) predicate per class token.
		if ( ! empty( $class_matches[1] ) ) {
			foreach ( $class_matches[1] as $class_name ) {
				$predicates[] = sprintf(
					'contains(concat(" ", normalize-space(@class), " "), " %s ")',
					$class_name
				);
			}
		}

		// Without id/class predicates, plain tag token is enough.
		if ( empty( $predicates ) ) {
			return $tag;
		}

		return sprintf( '%s[%s]', $tag, implode( ' and ', $predicates ) );
	}



	public function load_styles( $buffer, $overlay_color ) {
		$style = $this->get_cached_style( (string) $overlay_color );

		// Inject the styles into the <head> section
		// Callback treats generated CSS as literal text and avoids replacement back references.
		$updated_buffer = preg_replace_callback(
			'/<\/head>/i',
			static function ( $matches ) use ( $style ) {
				return $style . $matches[0];
			},
			$buffer,
			1
		);

		return is_string( $updated_buffer ) ? $updated_buffer : $buffer;
	}

	public function get_style( $overlay_color ) {
		$style = "
            .{$this->loader_class} {
                position: relative;
            }

            .{$this->loader_class}::before {
                content: '';
                position: absolute;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background-color: {$overlay_color};
                opacity: 0.6;
                z-index: 1;
            }

            .{$this->loader_class}::after {
                content: '';
                position: absolute;
                top: 50%;
                left: 50%;
                width: 40px;
                height: 40px;
                margin-top: -20px;
                margin-left: -20px;
                border: 4px solid #fff;
                border-top-color: transparent;
                border-radius: 50%;
                animation: spin 1s linear infinite;
                z-index: 2;
            }

            @keyframes spin {
                0% {
                    transform: rotate(0deg);
                }
                100% {
                    transform: rotate(360deg);
                }
            }
        ";

		// Minify generated loader CSS only when CSS minification is enabled in settings.
		if ( true === filter_var( $this->minify_css, FILTER_VALIDATE_BOOLEAN ) ) {
			$style = $this->minify_css( $style );
		}

		$style = '<style>' . $style . '</style>';

		return $style;
	}

	protected function minify_css( $style ) {

		// Use minifier only when dependency is available in current runtime.
		if ( class_exists( 'MatthiasMullie\Minify\CSS' ) ) {
			$minifier = new MatthiasMullie\Minify\CSS();
			$minifier->add( $style );

			$style = $minifier->minify();
		}

		return $style;
	}

	public function load_scripts( $buffer, $load_type, $elements ) {
		$script = $this->get_cached_script( (string) $load_type, is_array( $elements ) ? $elements : array() );

		// Inject the script at the beginning of the <body> tag.
		// Preload therefore runs before later body nodes exist; JS must wait to swap.
		// Callback preserves the opening tag and treats generated JavaScript as literal text.
		$updated_buffer = preg_replace_callback(
			'/<body[^>]*>/i',
			static function ( $matches ) use ( $script ) {
				return $matches[0] . $script;
			},
			$buffer,
			1
		);

		return is_string( $updated_buffer ) ? $updated_buffer : $buffer;
	}

	/**
	 * Get cached loader style block keyed by current DC style settings.
	 *
	 * @param string $overlay_color Overlay color value.
	 * @return string
	 */
	private function get_cached_style( string $overlay_color ): string {
		$cache_key = $this->build_dc_cache_key(
			'style',
			array(
				'overlay_color' => $overlay_color,
				'loader_class'  => $this->loader_class,
				'minify_css'    => (string) $this->minify_css,
			)
		);

		return $this->get_cached_dc_block(
			$cache_key,
			function () use ( $overlay_color ): string {
				return $this->get_style( $overlay_color );
			}
		);
	}

	/**
	 * Get cached Double-check script block keyed by current DC script settings.
	 *
	 * @param string $load_type Script load type.
	 * @param array  $elements  Configured selectors.
	 * @return string
	 */
	private function get_cached_script( string $load_type, array $elements ): string {
		$normalized_elements   = $this->normalize_elements_for_script_cache( $elements );
		$cached_query_patterns = $this->get_doublecheck_cached_query_patterns();
		$cache_key             = $this->build_dc_cache_key(
			'script',
			array(
				'load_type'             => $load_type,
				'elements'              => $normalized_elements,
				'loader_class'          => $this->loader_class,
				'minify_js'             => (string) $this->minify_js,
				'cached_query_patterns' => $cached_query_patterns,
				'script_schema'         => self::DC_SCRIPT_SCHEMA,
			)
		);

		return $this->get_cached_dc_block(
			$cache_key,
			function () use ( $load_type, $normalized_elements, $cached_query_patterns ): string {
				return $this->get_script( $load_type, $normalized_elements, $cached_query_patterns );
			}
		);
	}

	/**
	 * Normalize selectors for deterministic script cache keys.
	 *
	 * @param array $elements Raw selectors from settings.
	 * @return array
	 */
	private function normalize_elements_for_script_cache( array $elements ): array {
		$normalized = array();
		$seen       = array();

		foreach ( $elements as $element ) {
			if ( ! is_string( $element ) ) {
				continue;
			}

			$selector = trim( $element );
			if ( '' === $selector || isset( $seen[ $selector ] ) ) {
				continue;
			}

			$seen[ $selector ] = true;
			$normalized[]      = $selector;
		}

		return $normalized;
	}

	/**
	 * Return a cached DC block (object cache + transient fallback).
	 *
	 * @param string   $cache_key Cache key.
	 * @param callable $builder   Builder callback when cache miss occurs.
	 * @return string
	 */
	private function get_cached_dc_block( string $cache_key, callable $builder ): string {
		$cached_block = wp_cache_get( $cache_key, self::DC_CACHE_GROUP );
		if ( is_string( $cached_block ) && '' !== $cached_block ) {
			return $cached_block;
		}

		$transient_block = get_transient( $cache_key );
		if ( is_string( $transient_block ) && '' !== $transient_block ) {
			wp_cache_set( $cache_key, $transient_block, self::DC_CACHE_GROUP, self::DC_CACHE_TTL );
			return $transient_block;
		}

		$generated_block = (string) call_user_func( $builder );
		if ( '' === $generated_block ) {
			return '';
		}

		wp_cache_set( $cache_key, $generated_block, self::DC_CACHE_GROUP, self::DC_CACHE_TTL );
		set_transient( $cache_key, $generated_block, self::DC_CACHE_TTL );

		return $generated_block;
	}

	/**
	 * Build deterministic cache key for DC generated blocks.
	 *
	 * @param string $type  Block type.
	 * @param array  $parts Key parts.
	 * @return string
	 */
	private function build_dc_cache_key( string $type, array $parts ): string {
		$version = defined( 'BREEZE_VERSION' ) ? (string) BREEZE_VERSION : '0';
		$payload = wp_json_encode(
			array(
				'version' => $version,
				'type'    => $type,
				'parts'   => $parts,
			)
		);

		return 'breeze_dc_' . $type . '_' . $this->build_dc_cache_fingerprint( (string) $payload );
	}

	/**
	 * Build cache fingerprint without MD5.
	 *
	 * Prefers xxh128 when available and falls back to sha256.
	 *
	 * @param string $payload Fingerprint source payload.
	 * @return string
	 */
	private function build_dc_cache_fingerprint( string $payload ): string {
		$algorithm = in_array( 'xxh128', hash_algos(), true ) ? 'xxh128' : 'sha256';
		$hash      = hash( $algorithm, $payload );

		// Compact key segment to keep transient/cache keys short and portable.
		if ( false === $hash || '' === $hash ) {
			$hash = hash( 'sha256', $payload );
		}

		return substr( (string) $hash, 0, 32 );
	}

	/**
	 * Collect query-string names that Breeze treats as distinct cache variants.
	 *
	 * Includes user-defined "Cache Query Strings" and built-in always-cache vars
	 * (for example lang, page, paged). Only names/patterns are returned, never values.
	 *
	 * @return array
	 */
	private function get_doublecheck_cached_query_patterns(): array {
		$patterns   = array();
		$seen       = array();
		$candidates = array();

		$user_defined = Breeze_Options_Reader::get_option_value( 'cached-query-strings' );
		if ( is_array( $user_defined ) ) {
			$candidates = array_merge( $candidates, $user_defined );
		}

		if ( class_exists( 'Breeze_Query_Strings_Rules' ) ) {
			$query_rules = Breeze_Query_Strings_Rules::get_instance();
			if ( $query_rules instanceof Breeze_Query_Strings_Rules ) {
				$always_cache = $query_rules->fetch_always_cache_list();
				if ( is_array( $always_cache ) ) {
					$candidates = array_merge( $candidates, $always_cache );
				}
			}
		}

		foreach ( $candidates as $candidate ) {
			if ( ! is_string( $candidate ) ) {
				continue;
			}

			$pattern = trim( $candidate );
			if ( '' === $pattern || isset( $seen[ $pattern ] ) ) {
				continue;
			}

			if ( ! $this->is_safe_doublecheck_query_pattern( $pattern ) ) {
				continue;
			}

			$seen[ $pattern ] = true;
			$patterns[]       = $pattern;
		}

		return $patterns;
	}

	/**
	 * Validate a cache-query pattern before injecting it into the DC script.
	 *
	 * @param string $pattern Query-string name or wildcard pattern.
	 * @return bool
	 */
	private function is_safe_doublecheck_query_pattern( string $pattern ): bool {
		if ( 0 === strcasecmp( $pattern, 'nocache' ) ) {
			return false;
		}

		// A catch-all wildcard would forward every query argument on the refresh request.
		if ( '(.*)' === $pattern ) {
			return false;
		}

		return 1 === preg_match( '/^[A-Za-z0-9_.\-\[\]]+(\(\.\*\))?$/', $pattern );
	}

	/**
	 * Build the inline Double-check runtime script.
	 *
	 * JavaScript helpers are documented with JSDoc inside the generated string.
	 * Do not put dollar signs in those JS comments: this string is double-quoted PHP.
	 * Frontend minify may strip comments; they are for maintainers reading this file.
	 *
	 * @param string     $load_type               Script load type.
	 * @param array      $elements                Configured selectors.
	 * @param array|null $cached_query_patterns   Cacheable query-string names/patterns.
	 * @return string
	 */
	public function get_script( $load_type, $elements, $cached_query_patterns = null ) {
		// Hex-escape so a selector cannot close the inline script tag.
		$elements_json = wp_json_encode(
			array_values( is_array( $elements ) ? $elements : array() ),
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES
		);
		if ( ! is_string( $elements_json ) || '' === $elements_json ) {
			$elements_json = '[]';
		}
		if ( ! is_array( $cached_query_patterns ) ) {
			$cached_query_patterns = $this->get_doublecheck_cached_query_patterns();
		}

		$patterns_json = wp_json_encode(
			array_values( $cached_query_patterns ),
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES
		);
		if ( ! is_string( $patterns_json ) || '' === $patterns_json ) {
			$patterns_json = '[]';
		}

		// Ensure we are fetching from a valid URL by appending an endpoint if necessary
		$script = "
        (function () {
            /**
             * Breeze HTML Double-Check (DC).
             *
             * Fetches an uncached copy of this URL and copies selected subtrees into the
             * live (cached) DOM so fragments such as mini-cart or greetings stay fresh.
             * Dispatches breeze:dc:swapped, breeze:dc:skipped, and breeze:dc:done.
             * Minify may strip these comments on the frontend; they exist for maintainers.
             */
            // Safety guard: prevent duplicate DC bootstrap on the same page load.
            // This does not limit selector processing; it only blocks a second full DC run.
            if (window.__breezeDcHasRun === true) {
                return;
            }
            window.__breezeDcHasRun = true;

            // Normalize selector input defensively so runtime logic always receives
            // a clean array of unique, non-empty CSS selector strings.
            var elementsReload = Array.isArray({$elements_json}) ? {$elements_json} : [];
            elementsReload = elementsReload
                .map(function (selector) {
                    return typeof selector === 'string' ? selector.trim() : '';
                })
                .filter(function (selector) {
                    return selector !== '';
                })
                .filter(function (selector, index, selectors) {
                    return selectors.indexOf(selector) === index;
                });

            // Cache Query Strings + built-in always-cache vars. Values come from the live URL.
            var cachedQueryPatterns = Array.isArray({$patterns_json}) ? {$patterns_json} : [];
            cachedQueryPatterns = cachedQueryPatterns
                .map(function (pattern) {
                    return typeof pattern === 'string' ? pattern.trim() : '';
                })
                .filter(function (pattern) {
                    return pattern !== '' && pattern.toLowerCase() !== 'nocache';
                })
                .filter(function (pattern, index, patterns) {
                    return patterns.indexOf(pattern) === index;
                });

            /**
             * Current epoch milliseconds.
             *
             * @return {number}
             */
            function getTimestamp() {
                if (Date.now) {
                    return Date.now();
                }

                return new Date().getTime();
            }

            /**
             * Random hex token for nocache= and request_id. Prefers Web Crypto.
             *
             * @return {string}
             */
            function generateRequestToken() {
                if (window.crypto && window.crypto.getRandomValues) {
                    var tokenArray = new Uint32Array(2);
                    window.crypto.getRandomValues(tokenArray);
                    return tokenArray[0].toString(16) + tokenArray[1].toString(16);
                }

                return Math.random().toString(16).slice(2) + getTimestamp().toString(16);
            }

            /**
             * Match a query key against an exact name or a Breeze wildcard (city(.*)).
             *
             * @param {string} key     Decoded query parameter name.
             * @param {string} pattern Allowlist entry from Cache Query Strings.
             * @return {boolean}
             */
            function matchesCachedQueryPattern(key, pattern) {
                if (key === pattern) {
                    return true;
                }

                if (pattern.indexOf('(.*)') === -1) {
                    return false;
                }

                // Escape regex metacharacters, then restore the Breeze (.*) wildcard.
                var placeholder = 'REG_EXP_ALL';
                var quoted = pattern.split('(.*)') .join(placeholder);
                var specials = '.*+?^\$()[]{}|\\\\';
                var escaped = '';
                var i;
                for (i = 0; i < quoted.length; i++) {
                    var ch = quoted.charAt(i);
                    if (specials.indexOf(ch) !== -1) {
                        escaped += '\\\\' + ch;
                    } else {
                        escaped += ch;
                    }
                }
                quoted = escaped.split(placeholder).join('(.*)');

                try {
                    return new RegExp('^' + quoted + '$').test(key);
                } catch (error) {
                    return false;
                }
            }

            /**
             * True when this query key is a Breeze cache variant (allowlist / wildcard).
             * nocache is never forwarded; we append our own token later.
             *
             * @param {string} key Decoded query parameter name (foo[] stripped for matching).
             * @return {boolean}
             */
            function isAllowedCachedQueryKey(key) {
                if (typeof key !== 'string' || key === '') {
                    return false;
                }

                if (key.toLowerCase() === 'nocache') {
                    return false;
                }

                var baseKey = key;
                if (baseKey.length > 2 && baseKey.slice(-2) === '[]') {
                    baseKey = baseKey.slice(0, -2);
                }

                var i;
                for (i = 0; i < cachedQueryPatterns.length; i++) {
                    var pattern = cachedQueryPatterns[i];
                    if (typeof pattern !== 'string' || pattern === '') {
                        continue;
                    }

                    if (matchesCachedQueryPattern(key, pattern) || matchesCachedQueryPattern(baseKey, pattern)) {
                        return true;
                    }
                }

                return false;
            }

            /**
             * Build origin+path + allowed current query pairs + nocache.
             * Keeps original pair encoding/order (repeats and foo[] included).
             *
             * @param {string} cacheBusterValue Token written to nocache=.
             * @return {string} Absolute URL for the DC XHR.
             */
            function buildDoublecheckRefreshUrl(cacheBusterValue) {
                var baseUrl = window.location.origin + window.location.pathname;
                var preservedPairs = [];
                var search = window.location.search ? window.location.search.substring(1) : '';

                if (search !== '') {
                    var rawPairs = search.split('&');
                    var p;
                    for (p = 0; p < rawPairs.length; p++) {
                        var rawPair = rawPairs[p];
                        if (rawPair === '') {
                            continue;
                        }

                        var eqPos = rawPair.indexOf('=');
                        // Keep the raw pair for the URL; only decode the key for allowlist matching.
                        var rawKey = -1 === eqPos ? rawPair : rawPair.substring(0, eqPos);
                        var decodedKey = rawKey;
                        try {
                            decodedKey = decodeURIComponent(rawKey.split('+').join(' '));
                        } catch (error) {
                            decodedKey = rawKey;
                        }

                        if (!isAllowedCachedQueryKey(decodedKey)) {
                            continue;
                        }

                        preservedPairs.push(rawPair);
                    }
                }

                preservedPairs.push('nocache=' + encodeURIComponent(cacheBusterValue));
                return baseUrl + '?' + preservedPairs.join('&');
            }

            // Per-run metrics and flags. dcUpdateStarted prevents a second XHR from load+timeout.
            var dcRequestId = 'dc_' + generateRequestToken();
            var dcUrlPath = window.location.pathname || '/';
            var xhrTimeoutMs = 15000;
            var runStartedAt = getTimestamp();
            var swappedCount = 0;
            var skippedCount = 0;
            var doneEmitted = false;
            var dcUpdateStarted = false;
            var selectorMismatchState = {};
            var selectorMismatchSuppressedCount = 0;

            /**
             * Sum mismatch hits across selectors (including suppressed repeats).
             *
             * @return {number}
             */
            function getSelectorMismatchTotal() {
                var total = 0;

                Object.keys(selectorMismatchState).forEach(function (selector) {
                    total += selectorMismatchState[selector].count;
                });

                return total;
            }

            /**
             * First mismatch per selector emits breeze:dc:skipped; later repeats are counted only.
             *
             * @param {string} selector   CSS selector that failed to pair live vs fresh nodes.
             * @param {number} liveCount  Matches in the live document.
             * @param {number} freshCount Matches in the parsed fresh HTML.
             * @return {void}
             */
            function trackSelectorMismatch(selector, liveCount, freshCount) {
                var selectorKey = typeof selector === 'string' ? selector : '';

                if (!selectorMismatchState[selectorKey]) {
                    selectorMismatchState[selectorKey] = {
                        count: 0,
                        notified: false
                    };
                }

                selectorMismatchState[selectorKey].count++;

                if (selectorMismatchState[selectorKey].notified) {
                    selectorMismatchSuppressedCount++;
                    return;
                }

                selectorMismatchState[selectorKey].notified = true;
                emitSkipped('selector-mismatch', {
                    scope: 'global',
                    selector: selector,
                    phase: 'swap',
                    matched_live_count: liveCount,
                    matched_fresh_count: freshCount,
                    mismatch_count: selectorMismatchState[selectorKey].count,
                    suppressed_repeats: false
                });
            }

            /**
             * Terminal event for this DC run. Always strips leftover loader overlay classes.
             *
             * @param {number|null} xhrStatus   XHR status, or null when no request ran.
             * @param {string}      doneReason  Short reason code on breeze:dc:done.
             * @param {Object}      extraDetail Optional extra keys merged into event.detail.
             * @return {void}
             */
            function emitDone(xhrStatus, doneReason, extraDetail) {
                if (doneEmitted) {
                    return;
                }
                doneEmitted = true;

                var leftoverLoaders = document.querySelectorAll('.{$this->loader_class}');
                leftoverLoaders.forEach(function (element) {
                    element.classList.remove('{$this->loader_class}');
                });

                var detail = {
                    request_id: dcRequestId,
                    url_path: dcUrlPath,
                    ts: getTimestamp(),
                    duration_ms: getTimestamp() - runStartedAt,
                    swapped_count: swappedCount,
                    skipped_count: skippedCount,
                    xhr_status: typeof xhrStatus === 'number' ? xhrStatus : null,
                    reason: doneReason,
                    selector_mismatch_total: getSelectorMismatchTotal(),
                    selector_mismatch_suppressed: selectorMismatchSuppressedCount,
                    selector_mismatch_selectors: Object.keys(selectorMismatchState).length
                };

                if (extraDetail && typeof extraDetail === 'object') {
                    Object.keys(extraDetail).forEach(function (key) {
                        detail[key] = extraDetail[key];
                    });
                }

                document.dispatchEvent(new CustomEvent('breeze:dc:done', {
                    bubbles: true,
                    detail: detail
                }));
            }

            /**
             * True when the selector SUBJECT is html, body, or head (body.home).
             * Descendant targets such as body .mini-cart are allowed.
             * Fresh HTML is a real Document (DOMParser), so those tags exist; swapping
             * them as the subject is still unsupported.
             *
             * @param {string} selector CSS selector from settings.
             * @return {boolean}
             */
            function isUnsupportedRootSelector(selector) {
                if (typeof selector !== 'string') {
                    return false;
                }

                var normalized = selector.replace(/\s+/g, ' ').trim();
                if (normalized === '') {
                    return false;
                }

                // Subject is the right-most compound after combinators (descendant, >, +, ~).
                var segments = normalized.split(/\s*(?:>|\+|~)\s*|\s+/);
                if (!segments.length) {
                    return false;
                }

                var subject = segments[segments.length - 1];
                var match = subject.match(/^([A-Za-z][A-Za-z0-9_-]*)/);
                if (!match) {
                    return false;
                }

                var tag = match[1].toLowerCase();
                return tag === 'html' || tag === 'body' || tag === 'head';
            }

            /**
             * Dispatch breeze:dc:skipped with a stable detail payload (reason, request_id, path).
             *
             * @param {string}  reason      Skip reason code.
             * @param {Object}  extraDetail Optional extra keys merged into event.detail.
             * @param {Element} target      Optional event target; defaults to document.
             * @return {void}
             */
            function emitSkipped(reason, extraDetail, target) {
                skippedCount++;

                var detail = {
                    reason: reason,
                    scope: 'global',
                    request_id: dcRequestId,
                    url_path: dcUrlPath,
                    ts: getTimestamp()
                };

                if (extraDetail && typeof extraDetail === 'object') {
                    Object.keys(extraDetail).forEach(function (key) {
                        detail[key] = extraDetail[key];
                    });
                }

                if (!detail.scope) {
                    detail.scope = 'global';
                }

                var eventTarget = target && target.dispatchEvent ? target : document;
                eventTarget.dispatchEvent(new CustomEvent('breeze:dc:skipped', {
                    bubbles: true,
                    detail: detail
                }));
            }

            // Drop html/body/head subjects before any XHR. Other selectors keep running.
            var usableSelectors = [];
            elementsReload.forEach(function (selector) {
                if (isUnsupportedRootSelector(selector)) {
                    emitSkipped('unsupported-root-selector', {
                        selector: selector,
                        phase: 'bootstrap'
                    });
                    return;
                }
                usableSelectors.push(selector);
            });
            elementsReload = usableSelectors;

            if (!elementsReload.length) {
                // Nothing usable to process: emit skip event for observability.
                emitSkipped('invalid-selectors', {
                    selector_count: 0,
                    phase: 'bootstrap'
                });
                emitDone(null, 'invalid-selectors');
                return;
            }

            /**
             * querySelectorAll wrapper. Invalid selectors skip instead of aborting the run.
             *
             * @param {ParentNode} root     Document or parsed container.
             * @param {string}     selector CSS selector.
             * @param {string}     phase    Label stored on skip events for debugging.
             * @return {NodeList|Array}
             */
            function queryElements(root, selector, phase) {
                try {
                    if (!root || typeof root.querySelectorAll !== 'function') {
                        return [];
                    }
                    return root.querySelectorAll(selector);
                } catch (error) {
                    // Invalid selector must not crash the whole refresh flow.
                    // Emit detail so integrations/debug tooling can track bad selectors.
                    emitSkipped('invalid-selector', {
                        selector: selector,
                        phase: phase
                    });
                    return [];
                }
            }

            /**
             * Parse nocache HTML as a Document. Do not assign the full markup to innerHTML.
             *
             * @param {string} markup Fresh HTML from the XHR.
             * @return {Document|null}
             */
            function parseFreshHtmlDocument(markup) {
                if (typeof markup !== 'string' || typeof DOMParser === 'undefined') {
                    return null;
                }

                try {
                    return new DOMParser().parseFromString(markup, 'text/html');
                } catch (error) {
                    return null;
                }
            }

            /**
             * Run fn now, or on DOMContentLoaded if the HTML parser is still going.
             * Preload injects this script right after body, before target nodes exist.
             *
             * @param {Function} fn
             * @return {void}
             */
            function whenDocumentReady(fn) {
                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', fn);
                    return;
                }
                fn();
            }

            /**
             * Add loader class to current matches. Returns false when none exist yet.
             *
             * @return {boolean}
             */
            function markLiveTargets() {
                var hasLiveTargets = false;

                elementsReload.forEach(function (selector) {
                    var elements = queryElements(document, selector, 'precheck');
                    if (elements.length > 0) {
                        hasLiveTargets = true;
                    }
                    elements.forEach(function (element) {
                        element.classList.add('{$this->loader_class}');
                    });
                });

                return hasLiveTargets;
            }

            /**
             * Fetch fresh HTML and swap matched regions.
             * Guarded so the load listener and the 20s fallback cannot start two XHRs.
             *
             * @return {void}
             */
            function updateElements() {
                if (dcUpdateStarted) {
                    return;
                }
                dcUpdateStarted = true;

                var documentStillLoading = document.readyState === 'loading';

                // While the parser is still in loading, matched nodes are not in the DOM yet.
                // PHP already painted the overlay; skip the empty precheck so we do not emitDone
                // before those nodes exist (that left the red overlay stuck on Before load).
                if (!documentStillLoading && !markLiveTargets()) {
                    emitSkipped('no-targets', {
                        selector_count: elementsReload.length,
                        phase: 'precheck'
                    });
                    emitDone(null, 'no-targets');
                    return;
                }

				// Use secure random cache-buster for the refresh request.
				// Fallback keeps compatibility where Web Crypto is unavailable.
				var randomValue = generateRequestToken();
                var urlWithCacheBuster = buildDoublecheckRefreshUrl(randomValue);

                // Request fresh uncached HTML, then copy only selected subtrees.
                var xhr = new XMLHttpRequest();
                // Fail-safe timeout: if the refresh call hangs, release loader state and continue.
                xhr.timeout = xhrTimeoutMs;
                xhr.ontimeout = function () {
                    whenDocumentReady(function () {
                        elementsReload.forEach(function (selector) {
                            var elements = queryElements(document, selector, 'xhr-timeout-cleanup');
                            elements.forEach(function (element) {
                                element.classList.remove('{$this->loader_class}');
                            });
                        });
                        emitSkipped('xhr-timeout', {
                            phase: 'xhr',
                            timeout_ms: xhrTimeoutMs,
                            status: xhr.status
                        });
                        emitDone(xhr.status, 'xhr-timeout', {
                            timeout_ms: xhrTimeoutMs
                        });
                    });
                };
                xhr.onerror = function () {
                    whenDocumentReady(function () {
                        elementsReload.forEach(function (selector) {
                            var elements = queryElements(document, selector, 'xhr-error-cleanup');
                            elements.forEach(function (element) {
                                element.classList.remove('{$this->loader_class}');
                            });
                        });
                        emitSkipped('xhr-error', {
                            phase: 'xhr',
                            status: xhr.status
                        });
                        emitDone(xhr.status, 'xhr-error');
                    });
                };
                xhr.onabort = function () {
                    whenDocumentReady(function () {
                        elementsReload.forEach(function (selector) {
                            var elements = queryElements(document, selector, 'xhr-abort-cleanup');
                            elements.forEach(function (element) {
                                element.classList.remove('{$this->loader_class}');
                            });
                        });
                        emitSkipped('xhr-abort', {
                            phase: 'xhr',
                            status: xhr.status
                        });
                        emitDone(xhr.status, 'xhr-abort');
                    });
                };
                xhr.onreadystatechange = function () {
                    if (xhr.readyState === XMLHttpRequest.DONE) {
                        if (xhr.status === 200) {

                            whenDocumentReady(function () {
                            if (!markLiveTargets()) {
                                emitSkipped('no-targets', {
                                    selector_count: elementsReload.length,
                                    phase: 'precheck'
                                });
                                emitDone(xhr.status, 'no-targets');
                                return;
                            }

                            // Parse as a Document so unused page regions are not assigned via innerHTML.
                            var freshDocument = parseFreshHtmlDocument(xhr.responseText);
                            if (!freshDocument) {
                                emitSkipped('parse-error', {
                                    phase: 'swap'
                                });
                                emitDone(xhr.status, 'parse-error');
                                return;
                            }

                            // For each selector, pair live nodes with fresh nodes by index.
                            elementsReload.forEach(function (selector) {
                                var foundElementsInDOM = queryElements(document, selector, 'swap-live-dom');
                                var foundElementsInVirtualDOM = queryElements(freshDocument, selector, 'swap-fresh-dom');

                                if (foundElementsInDOM.length && foundElementsInVirtualDOM.length) {
                                    foundElementsInDOM.forEach(function (elem, i) {
                                        var freshElem = foundElementsInVirtualDOM[i];
                                        if (!freshElem) {
                                            emitSkipped('missing-fresh-node', {
                                                scope: 'element',
                                                selector: selector,
                                                index: i,
                                                phase: 'swap',
                                                matched_live_count: foundElementsInDOM.length,
                                                matched_fresh_count: foundElementsInVirtualDOM.length
                                            }, elem);
                                            return;
                                        }

                                        // Skip forms and nonce fields so a cached page does not overwrite a live CSRF token.
                                        var nonceFields = Array.prototype.slice.call(elem.querySelectorAll('input[type=\"hidden\"]'));
                                        nonceFields = nonceFields.concat(Array.prototype.slice.call(freshElem.querySelectorAll('input[type=\"hidden\"]')));
                                        var containsNonce = Array.prototype.some.call(nonceFields, function (field) {
                                            var fieldName = (field.getAttribute('name') || '').toLowerCase();
                                            var fieldId = (field.getAttribute('id') || '').toLowerCase();
                                            return fieldName.indexOf('nonce') !== -1 || fieldId.indexOf('nonce') !== -1;
                                        });
                                        var containsForm = elem.matches('form') || null !== elem.querySelector('form') ||
                                            freshElem.matches('form') || null !== freshElem.querySelector('form');

                                        if (containsForm || containsNonce) {
                                            emitSkipped(
                                                containsForm ? 'contains-form' : 'contains-nonce',
                                                {
                                                    scope: 'element',
                                                    selector: selector,
                                                    element: elem,
                                                    index: i,
                                                    phase: 'swap'
                                                },
                                                elem
                                            );
                                            return;
                                        }

                                        // Safe swap: replace only element inner content, not outer node identity.
                                        // Consumers can reinitialize dynamic widgets via the swapped event.
                                        elem.innerHTML = freshElem.innerHTML;
                                        elem.dispatchEvent(new CustomEvent('breeze:dc:swapped', {
                                            bubbles: true,
                                            detail: {
                                                selector: selector,
                                                element: elem,
                                                index: i
                                            }
                                        }));
                                        swappedCount++;
                                    });
                                    // Remove loader state once this selector batch is processed.
                                    foundElementsInDOM.forEach(function (elem) {
                                        elem.classList.remove('{$this->loader_class}');
                                    });
                                } else {
                                    trackSelectorMismatch(selector, foundElementsInDOM.length, foundElementsInVirtualDOM.length);
                                    // Keep cleanup deterministic even when no fresh match exists.
                                    foundElementsInDOM.forEach(function (elem) {
                                        elem.classList.remove('{$this->loader_class}');
                                    });
                                }
                            });
                            emitDone(xhr.status, 'success');
                            });
                        } else {
                            // Network/status failure: remove any temporary loader classes.
                            whenDocumentReady(function () {
                            elementsReload.forEach(function (selector) {
                                var elements = queryElements(document, selector, 'xhr-failure-cleanup');
                                elements.forEach(function (element) {
                                    element.classList.remove('{$this->loader_class}');
                                });
                            });
                            emitSkipped('xhr-status', {
                                phase: 'xhr',
                                status: xhr.status
                            });
                            emitDone(xhr.status, 'xhr-status');
                            });
                        }
                    }
                };
                xhr.open('GET', urlWithCacheBuster);
                // Lets the server skip injecting DC again on this refresh request.
                xhr.setRequestHeader('X-Breeze-DC', '1');
                xhr.send();
            }";
		// Preload starts the XHR immediately (script sits at the start of body).
		// Swap/cleanup wait for DOMContentLoaded so PHP-painted overlays can be removed.
		if ( $load_type === 'preload' ) {
			$script .= "\nupdateElements();";
		// Onload mode defers execution until all page resources are loaded.
		} elseif ( $load_type === 'onload' ) {
			// Cooperative load hook: do not assign window.onload (that overwrites other scripts).
			$script .= "
            // Cooperative load hook: addEventListener, never window.onload assignment.
            if (document.readyState === 'complete') {
                updateElements();
            } else {
                window.addEventListener('load', updateElements);
                // If load never fires, clear leftover loaders. Does not start a second XHR
                // if updateElements already ran (doneEmitted is then true).
                window.setTimeout(function () {
                    if (!doneEmitted) {
                        emitSkipped('load-timeout', {
                            phase: 'load',
                            timeout_ms: 20000
                        });
                        emitDone(null, 'load-timeout');
                    }
                }, 20000);
            }";
		// Async mode runs at DOM readiness (DOMContentLoaded or already-ready states).
		} elseif ( $load_type === 'async' ) {
			$script .= "
            // DOMContentLoaded (or already interactive/complete). Does not wait for images.
            if (document.readyState === 'complete' || document.readyState === 'interactive') {
                updateElements();
            } else {
                document.addEventListener('DOMContentLoaded', updateElements);
            }";
		}
		$script .= "\n})();";

		// The minifier accepts JavaScript only, so process the raw body before adding HTML tags.
		if ( class_exists( 'Minify\JS' ) ) {
			$script = $this->minify_js( $script );
		}

		return "<script id='dc-js' type='application/javascript'>" . $script . '</script>';
	}

	protected function minify_js( $script ) {

		// Respect filter so third-party integrations can disable JS minification.
		if ( apply_filters( 'breeze_js_do_minify', true ) ) {
			$minifier = new Minify\JS();
			$minifier->add( $script );

			return $minifier->minify();
		}

		return $script;
	}
}
