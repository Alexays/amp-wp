<?php
/**
 * Class Amp\AmpWP\Dom\Document.
 *
 * @package AMP
 */

namespace Amp\AmpWP\Dom;

use AMP_DOM_Utils;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Class Amp\AmpWP\Dom\Document.
 *
 * @since 1.5
 *
 * @property DOMXPath   $xpath XPath query object for this document.
 * @property DOMElement $head  The document's <head> element.
 * @property DOMElement $body  The document's <body> element.
 *
 * Abstract away some of the difficulties of working with PHP's DOMDocument.
 */
final class Document extends DOMDocument {

	/**
	 * AMP requires the HTML markup to be encoded in UTF-8.
	 *
	 * @var string
	 */
	const AMP_ENCODING = 'utf-8';

	/**
	 * Encoding identifier to use for an unknown encoding.
	 *
	 * "auto" is recognized by mb_convert_encoding() as a special value.
	 *
	 * @var string
	 */
	const UNKNOWN_ENCODING = 'auto';

	/**
	 * Encoding detection order in case we have to guess.
	 *
	 * This list of encoding detection order is just a wild guess and might need fine-tuning over time.
	 * If the charset was not provided explicitly, we can really only guess, as the detection can
	 * never be 100% accurate and reliable.
	 *
	 * @var string
	 */
	const ENCODING_DETECTION_ORDER = 'UTF-8, EUC-JP, eucJP-win, JIS, ISO-2022-JP, ISO-8859-15, ISO-8859-1, ASCII';

	/**
	 * Attribute prefix for AMP-bind data attributes.
	 *
	 * @var string
	 */
	const AMP_BIND_DATA_ATTR_PREFIX = 'data-amp-bind-';

	/**
	 * Regular expression pattern to match the http-equiv meta tag.
	 *
	 * @var string
	 */
	const HTTP_EQUIV_META_TAG_PATTERN = '/<meta [^>]*?\s*http-equiv=[^>]*?>[^<]*(?:<\/meta>)?/i';

	/**
	 * Regular expression pattern to match the charset meta tag.
	 *
	 * @var string
	 */
	const CHARSET_META_TAG_PATTERN = '/<meta [^>]*?\s*charset=[^>]*?>[^<]*(?:<\/meta>)?/i';

	/**
	 * Regular expression pattern to match the main HTML structural tags.
	 *
	 * @var string
	 */
	const HTML_STRUCTURE_PATTERN = '/(?:.*?(?<doctype><!doctype(?:\s+[^>]*)?>))?(?:(?<pre_html>.*?)(?<html_start><html(?:\s+[^>]*)?>))?(?:.*?(?<head><head(?:\s+[^>]*)?>.*?<\/head\s*>))?(?:.*?(?<body><body(?:\s+[^>]*)?>.*?<\/body\s*>))?.*?(?:(?:.*(?<html_end><\/html\s*>)|.*)(?<post_html>.*))/is';

	/**
	 * Xpath query to fetch the attributes that are being URL-encoded by saveHTML().
	 *
	 * @var string
	 */
	const XPATH_URL_ENCODED_ATTRIBUTES_QUERY = './/*/@src|.//*/@href|.//*/@action';

	/**
	 * Error message to use when the __get() is triggered for an unknown property.
	 *
	 * @var string
	 */
	const PROPERTY_GETTER_ERROR_MESSAGE = 'Undefined property: Amp\\AmpWP\\Dom\\Document::';

	// Regex patterns and values used for adding and removing http-equiv charsets for compatibility.
	const HTML_GET_HEAD_OPENING_TAG_PATTERN     = '/<head(?:\s+[^>]*)?>/i';
	const HTML_GET_HEAD_OPENING_TAG_REPLACEMENT = '$1<meta http-equiv="content-type" content="text/html; charset=utf-8">';
	const HTML_GET_HTTP_EQUIV_TAG_PATTERN       = '#<meta http-equiv=([\'"])content-type\1 content=([\'"])text/html; charset=utf-8\2>#i';
	const HTML_HTTP_EQUIV_VALUE                 = 'content-type';
	const HTML_HTTP_EQUIV_CONTENT_VALUE         = 'text/html; charset=utf-8';

	// Regex patterns used for finding tags or extracting attribute values in an HTML string.
	const HTML_FIND_TAG_WITHOUT_ATTRIBUTE_PATTERN = '/<%1$s[^>]*?>[^<]*(?:<\/%1$s>)?/i';
	const HTML_FIND_TAG_WITH_ATTRIBUTE_PATTERN    = '/<%1$s [^>]*?\s*%2$s=[^>]*?>[^<]*(?:<\/%1$s>)?/i';
	const HTML_EXTRACT_ATTRIBUTE_VALUE_PATTERN    = '/%s=(?:([\'"])(?<full>.*)?\1|(?<partial>[^ \'";]+))/';


	// Tags constants used throughout.
	const TAG_HEAD     = 'head';
	const TAG_BODY     = 'body';
	const TAG_TEMPLATE = 'template';

	/**
	 * The original encoding of how the Amp\AmpWP\Dom\Document was created.
	 *
	 * This is stored to do an automatic conversion to UTF-8, which is
	 * a requirement for AMP.
	 *
	 * @var string
	 */
	private $original_encoding;

	/**
	 * Associative array of encoding mappings.
	 *
	 * Translates HTML charsets into encodings PHP can understand.
	 *
	 * @todo Turn into const array once PHP minimum is bumped to 5.6+.
	 *
	 * @var string[]
	 */
	private static $encoding_map = [
		// Assume ISO-8859-1 for some charsets.
		'latin-1' => 'ISO-8859-1',
	];

	/**
	 * HTML elements that are self-closing.
	 *
	 * Not all are valid AMP, but we include them for completeness.
	 *
	 * @link https://www.w3.org/TR/html5/syntax.html#serializing-html-fragments
	 *
	 * @todo Turn into const array once PHP minimum is bumped to 5.6+.
	 *
	 * @var string[]
	 */
	private static $self_closing_tags = [
		'area',
		'base',
		'basefont',
		'bgsound',
		'br',
		'col',
		'embed',
		'frame',
		'hr',
		'img',
		'input',
		'keygen',
		'link',
		'meta',
		'param',
		'source',
		'track',
		'wbr',
	];

	/**
	 * Store the placeholder comments that were generated to replace <noscript> elements.
	 *
	 * @see maybe_replace_noscript_elements()
	 *
	 * @var string[]
	 */
	private $noscript_placeholder_comments = [];

	/**
	 * Store whether mustache template tags were replaced and need to be restored.
	 *
	 * @see replace_mustache_template_tokens()
	 *
	 * @var bool
	 */
	private $mustache_tags_replaced = false;

	/**
	 * Creates a new Amp\AmpWP\Dom\Document object
	 *
	 * @link  https://php.net/manual/domdocument.construct.php
	 *
	 * @param string $version  Optional. The version number of the document as part of the XML declaration.
	 * @param string $encoding Optional. The encoding of the document as part of the XML declaration.
	 */
	public function __construct( $version = '', $encoding = null ) {
		$this->original_encoding = (string) $encoding ?: self::UNKNOWN_ENCODING;
		parent::__construct( $version ?: '1.0', self::AMP_ENCODING );
	}

	/**
	 * Load HTML from a string.
	 *
	 * @link  https://php.net/manual/domdocument.loadhtml.php
	 *
	 * @param string     $source  The HTML string.
	 * @param int|string $options Optional. Specify additional Libxml parameters.
	 * @return bool true on success or false on failure.
	 */
	public function loadHTML( $source, $options = 0 ) {
		$source = $this->convert_amp_bind_attributes( $source );
		$source = $this->replace_self_closing_tags( $source );
		$source = $this->normalize_document_structure( $source );
		$source = $this->maybe_replace_noscript_elements( $source );

		$this->original_encoding = $this->detect_and_strip_encoding( $source );

		if ( self::AMP_ENCODING !== strtolower( $this->original_encoding ) ) {
			$source = $this->adapt_encoding( $source );
		}

		// Force-add http-equiv charset to make DOMDocument behave as it should.
		// See: http://php.net/manual/en/domdocument.loadhtml.php#78243.
		$source = preg_replace( self::HTML_GET_HEAD_OPENING_TAG_PATTERN, self::HTML_GET_HEAD_OPENING_TAG_REPLACEMENT, $source, 1 );

		$libxml_previous_state = libxml_use_internal_errors( true );

		$success = parent::loadHTML( $source, $options );

		libxml_clear_errors();
		libxml_use_internal_errors( $libxml_previous_state );

		if ( $success ) {
			// Remove http-equiv charset again.
			$head = $this->getElementsByTagName( self::TAG_HEAD )->item( 0 );
			$meta = $head->firstChild;
			if (
				'meta' === $meta->tagName
				&& self::HTML_HTTP_EQUIV_VALUE === $meta->getAttribute( 'http-equiv' )
				&& ( self::HTML_HTTP_EQUIV_CONTENT_VALUE ) === $meta->getAttribute( 'content' )
			) {
				$head->removeChild( $meta );
			}
		}

		return $success;
	}

	/**
	 * Dumps the internal document into a string using HTML formatting.
	 *
	 * @link  https://php.net/manual/domdocument.savehtml.php
	 *
	 * @param DOMNode $node Optional. Parameter to output a subset of the document.
	 * @return string The HTML, or false if an error occurred.
	 */
	public function saveHTML( DOMNode $node = null ) {
		$this->replace_mustache_template_tokens();

		// Force-add http-equiv charset to make DOMDocument behave as it should.
		// See: http://php.net/manual/en/domdocument.loadhtml.php#78243.
		$charset = AMP_DOM_Utils::create_node(
			$this,
			'meta',
			[
				'http-equiv' => self::HTML_HTTP_EQUIV_VALUE,
				'content'    => self::HTML_HTTP_EQUIV_CONTENT_VALUE,
			]
		);

		$this->head->insertBefore( $charset, $this->head->firstChild );

		if ( null === $node || PHP_VERSION_ID >= 70300 ) {
			$html = parent::saveHTML( $node );
		} else {
			$html = $this->extract_node_via_fragment_boundaries( $node );
		}

		// Remove http-equiv charset again.
		$html = preg_replace( self::HTML_GET_HTTP_EQUIV_TAG_PATTERN, '', $html, 1 );

		$html = $this->restore_mustache_template_tokens( $html );
		$html = $this->maybe_restore_noscript_elements( $html );
		$html = $this->restore_self_closing_tags( $html );
		$html = $this->restore_amp_bind_attributes( $html );

		return $html;
	}

	/**
	 * Extract a node's HTML via fragment boundaries.
	 *
	 * Temporarily adds fragment boundary comments in order to locate the desired node to extract from
	 * the given HTML document. This is required because libxml seems to only preserve whitespace when
	 * serializing when calling DOMDocument::saveHTML() on the entire document. If you pass the element
	 * to DOMDocument::saveHTML() then formatting whitespace gets added unexpectedly. This is seen to
	 * be fixed in PHP 7.3, but for older versions of PHP the following workaround is needed.
	 *
	 * @param DOMNode $node Node to extract the HTML for.
	 * @return string Extracted HTML string.
	 */
	private function extract_node_via_fragment_boundaries( DOMNode $node ) {
		$boundary       = 'fragment_boundary:' . wp_rand();
		$start_boundary = $boundary . ':start';
		$end_boundary   = $boundary . ':end';
		$comment_start  = $this->createComment( $start_boundary );
		$comment_end    = $this->createComment( $end_boundary );

		$node->parentNode->insertBefore( $comment_start, $node );
		$node->parentNode->insertBefore( $comment_end, $node->nextSibling );

		$html = preg_replace(
			'/^.*?' . preg_quote( "<!--{$start_boundary}-->", '/' ) . '(.*)' . preg_quote( "<!--{$end_boundary}-->", '/' ) . '.*?\s*$/s',
			'$1',
			parent::saveHTML()
		);

		$node->parentNode->removeChild( $comment_start );
		$node->parentNode->removeChild( $comment_end );

		return $html;
	}

	/**
	 * Normalize the document structure.
	 *
	 * This makes sure the document adheres to the general structure that AMP requires:
	 *   ```
	 *   <!doctype html>
	 *   <html>
	 *     <head>
	 *       <meta charset="utf-8">
	 *     </head>
	 *     <body>
	 *     </body>
	 *   </html>
	 *   ```
	 *
	 * @param string $content Content to normalize the structure of.
	 * @return string Normalized content.
	 */
	private function normalize_document_structure( $content ) {
		$matches = [];

		// Unable to parse, so skip normalization and hope for the best.
		if ( false === preg_match( self::HTML_STRUCTURE_PATTERN, $content, $matches ) ) {
			return $content;
		}

		// Strip doctype for now.
		if ( ! empty( $matches['doctype'] ) ) {
			$content = preg_replace(
				sprintf(
					'/^.*?%s/s',
					str_replace( "\n", '\R', preg_quote( $matches['doctype'], '/' ) )
				),
				'',
				$content,
				1
			);
		}

		if ( empty( $matches[ self::TAG_HEAD ] ) && empty( $matches[ self::TAG_BODY ] ) ) {
			// Neither body, nor head, so wrap content in both.
			$pattern = sprintf(
				'/%s(.*)%s/is',
				( empty( $matches['html_start'] ) ? '^\s*' : preg_quote( $matches['html_start'], '/' ) ),
				( empty( $matches['html_end'] ) ? '$\s*' : preg_quote( $matches['html_end'], '/' ) )
			);
			$content = preg_replace(
				$pattern,
				( empty( $matches['html_start'] ) ? '' : $matches['html_start'] )
				. '<head></head><body>$1</body>'
				. ( empty( $matches['html_end'] ) ? '' : $matches['html_end'] ),
				$content,
				1
			);
		} elseif ( empty( $matches[ self::TAG_BODY ] ) && ! empty( $matches[ self::TAG_HEAD ] ) ) {
			// Head without body, so wrap content in body.
			$pattern = sprintf(
				'/%s(.*)%s/is',
				preg_quote( $matches[ self::TAG_HEAD ], '/' ),
				( empty( $matches['html_end'] ) ? '' : preg_quote( $matches['html_end'], '/' ) )
			);
			$content = preg_replace(
				$pattern,
				$matches[ self::TAG_HEAD ]
				. '<body>$1</body>'
				. ( empty( $matches['html_end'] ) ? '' : $matches['html_end'] ),
				$content,
				1
			);
		} elseif ( empty( $matches[ self::TAG_HEAD ] ) && ! empty( $matches[ self::TAG_BODY ] ) ) {
			// Body without head, so add empty head before body.
			$content = str_replace( $matches[ self::TAG_BODY ], '<head></head>' . $matches[ self::TAG_BODY ], $content );
		}

		if ( empty( $matches['html_start'] ) ) {
			// No surround html tag, so wrap the content in html.
			$content = "<html>{$content}</html>";
		}

		// Reinsert a standard doctype.
		$content = "<!DOCTYPE html>{$content}";

		return $content;
	}

	/**
	 * Normalize the structure of the document if it was already provided as a DOM.
	 */
	public function normalize_dom_structure() {
		$head = $this->getElementsByTagName( self::TAG_HEAD )->item( 0 );
		if ( ! $head ) {
			$head = $this->createElement( self::TAG_HEAD );
			$this->documentElement->insertBefore( $head, $this->documentElement->firstChild );
		}

		$body = $this->getElementsByTagName( self::TAG_BODY )->item( 0 );
		if ( ! $body ) {
			$body = $this->createElement( self::TAG_BODY );
			$this->documentElement->appendChild( $body );
		}

		// Walking backwards makes it easier to move elements in the expected order.
		$node = $this->head->lastChild;
		while ( $node ) {
			$next_sibling = $node->previousSibling;
			if ( ! AMP_DOM_Utils::is_valid_head_node( $node ) ) {
				$this->body->insertBefore( $this->head->removeChild( $node ), $this->body->firstChild );
			}
			$node = $next_sibling;
		}
	}

	/**
	 * Force all self-closing tags to have closing tags.
	 *
	 * This is needed because DOMDocument isn't fully aware of these.
	 *
	 * @see restore_self_closing_tags() Reciprocal function.
	 *
	 * @param string $html HTML string to adapt.
	 * @return string Adapted HTML string.
	 */
	private function replace_self_closing_tags( $html ) {
		static $regex_pattern = null;

		if ( null === $regex_pattern ) {
			$regex_pattern = '#<(' . implode( '|', self::$self_closing_tags ) . ')[^>]*>(?!</\1>)#';
		}

		return preg_replace( $regex_pattern, '$0</$1>', $html );
	}

	/**
	 * Restore all self-closing tags again.
	 *
	 * @see replace_self_closing_tags Reciprocal function.
	 *
	 * @param string $html HTML string to adapt.
	 * @return string Adapted HTML string.
	 */
	private function restore_self_closing_tags( $html ) {
		static $regex_pattern = null;

		if ( null === $regex_pattern ) {
			$regex_pattern = '#</(' . implode( '|', self::$self_closing_tags ) . ')>#i';
		}

		return preg_replace( $regex_pattern, '', $html );
	}

	/**
	 * Maybe replace noscript elements with placeholders.
	 *
	 * This is done because libxml<2.8 might parse them incorrectly.
	 * When appearing in the head element, a noscript can cause the head to close prematurely
	 * and the noscript gets moved to the body and anything after it which was in the head.
	 * See <https://stackoverflow.com/questions/39013102/why-does-noscript-move-into-body-tag-instead-of-head-tag>.
	 * This is limited to only running in the head element because this is where the problem lies,
	 * and it is important for the AMP_Script_Sanitizer to be able to access the noscript elements
	 * in the body otherwise.
	 *
	 * @see maybe_restore_noscript_elements() Reciprocal function.
	 *
	 * @param string $html HTML string to adapt.
	 * @return string Adapted HTML string.
	 */
	private function maybe_replace_noscript_elements( $html ) {
		if ( ! version_compare( LIBXML_DOTTED_VERSION, '2.8', '<' ) ) {
			return $html;
		}

		return preg_replace_callback(
			'#^.+?(?=<body)#is',
			function ( $head_matches ) {
				return preg_replace_callback(
					'#<noscript[^>]*>.*?</noscript>#si',
					function ( $noscript_matches ) {
						$placeholder = sprintf( '<!--noscript:%s-->', (string) wp_rand() );

						$this->noscript_placeholder_comments[ $placeholder ] = $noscript_matches[0];
						return $placeholder;
					},
					$head_matches[0]
				);
			},
			$html
		);
	}

	/**
	 * Maybe replace noscript elements with placeholders.
	 *
	 * This is done because libxml<2.8 might parse them incorrectly.
	 * When appearing in the head element, a noscript can cause the head to close prematurely
	 * and the noscript gets moved to the body and anything after it which was in the head.
	 * See <https://stackoverflow.com/questions/39013102/why-does-noscript-move-into-body-tag-instead-of-head-tag>.
	 * This is limited to only running in the head element because this is where the problem lies,
	 * and it is important for the AMP_Script_Sanitizer to be able to access the noscript elements
	 * in the body otherwise.
	 *
	 * @see maybe_replace_noscript_elements() Reciprocal function.
	 *
	 * @param string $html HTML string to adapt.
	 * @return string Adapted HTML string.
	 */
	private function maybe_restore_noscript_elements( $html ) {
		if ( ! version_compare( LIBXML_DOTTED_VERSION, '2.8', '<' ) ) {
			return $html;
		}

		return str_replace(
			array_keys( $this->noscript_placeholder_comments ),
			$this->noscript_placeholder_comments,
			$html
		);
	}

	/**
	 * Replace AMP binding attributes with something that libxml can parse (as HTML5 data-* attributes).
	 *
	 * This is necessary because attributes in square brackets are not understood in PHP and
	 * get dropped with an error raised:
	 * > Warning: DOMDocument::loadHTML(): error parsing attribute name
	 * This is a reciprocal function of Document::restore_amp_bind_attributes().
	 *
	 * @link https://www.ampproject.org/docs/reference/components/amp-bind
	 *
	 * @see restore_amp_bind_attributes() Reciprocal function.
	 *
	 * @param string $html HTML containing amp-bind attributes.
	 * @return string HTML with AMP binding attributes replaced with HTML5 data-* attributes.
	 */
	private function convert_amp_bind_attributes( $html ) {

		// Pattern for HTML attribute accounting for binding attr name, boolean attribute, single/double-quoted attribute value, and unquoted attribute values.
		$attr_regex = '#^\s+(?P<name>\[?[a-zA-Z0-9_\-]+\]?)(?P<value>=(?:"[^"]*+"|\'[^\']*+\'|[^\'"\s]+))?#';

		/**
		 * Replace callback.
		 *
		 * @param array $tag_matches Tag matches.
		 * @return string Replacement.
		 */
		$replace_callback = static function( $tag_matches ) use ( $attr_regex ) {

			// Strip the self-closing slash as long as it is not an attribute value, like for the href attribute (<a href=/>).
			$old_attrs = rtrim( preg_replace( '#(?<!=)/$#', '', $tag_matches['attrs'] ) );

			$new_attrs = '';
			$offset    = 0;
			while ( preg_match( $attr_regex, substr( $old_attrs, $offset ), $attr_matches ) ) {
				$offset += strlen( $attr_matches[0] );

				if ( '[' === $attr_matches['name'][0] ) {
					$new_attrs .= ' ' . self::AMP_BIND_DATA_ATTR_PREFIX . trim( $attr_matches['name'], '[]' );
					if ( isset( $attr_matches['value'] ) ) {
						$new_attrs .= $attr_matches['value'];
					}
				} else {
					$new_attrs .= $attr_matches[0];
				}
			}

			// Bail on parse error which occurs when the regex isn't able to consume the entire $new_attrs string.
			if ( strlen( $old_attrs ) !== $offset ) {
				return $tag_matches[0];
			}

			return '<' . $tag_matches['name'] . $new_attrs . '>';
		};

		// Match all start tags that contain a binding attribute.
		$pattern   = implode(
			'',
			[
				'#<',
				'(?P<name>[a-zA-Z0-9_\-]+)',               // Tag name.
				'(?P<attrs>\s',                            // Attributes.
				'(?:[^>"\'\[\]]+|"[^"]*+"|\'[^\']*+\')*+', // Non-binding attributes tokens.
				'\[[a-zA-Z0-9_\-]+\]',                     // One binding attribute key.
				'(?:[^>"\']+|"[^"]*+"|\'[^\']*+\')*+',     // Any attribute tokens, including binding ones.
				')>#s',
			]
		);
		$converted = preg_replace_callback(
			$pattern,
			$replace_callback,
			$html
		);

		/**
		 * If the regex engine incurred an error during processing, for example exceeding the backtrack
		 * limit, $converted will be null. In this case we return the originally passed document to allow
		 * DOMDocument to attempt to load it.  If the AMP HTML doesn't make use of amp-bind or similar
		 * attributes, then everything should still work.
		 *
		 * See https://github.com/ampproject/amp-wp/issues/993 for additional context on this issue.
		 * See http://php.net/manual/en/pcre.constants.php for additional info on PCRE errors.
		 */
		return ( null !== $converted ) ? $converted : $html;
	}


	/**
	 * Convert AMP bind-attributes back to their original syntax.
	 *
	 * @see convert_amp_bind_attributes() Reciprocal function.
	 *
	 * @param string $html HTML with amp-bind attributes converted.
	 * @return string HTML with amp-bind attributes restored.
	 */
	private function restore_amp_bind_attributes( $html ) {
		return preg_replace(
			'#\s' . self::AMP_BIND_DATA_ATTR_PREFIX . '([a-zA-Z0-9_\-]+)#',
			' [$1]',
			$html
		);
	}

	/**
	 * Adapt the encoding of the content.
	 *
	 * @param string $source Source content to adapt the encoding of.
	 * @return string Adapted content.
	 */
	private function adapt_encoding( $source ) {
		// No encoding was provided, so we need to guess.
		if ( self::UNKNOWN_ENCODING === $this->original_encoding && function_exists( 'mb_detect_encoding' ) ) {
			$this->original_encoding = mb_detect_encoding( $source, self::ENCODING_DETECTION_ORDER, true );
		}

		// Guessing the encoding seems to have failed, so we assume UTF-8 instead.
		if ( empty( $this->original_encoding ) ) {
			$this->original_encoding = self::AMP_ENCODING;
		}

		$this->original_encoding = $this->sanitize_encoding( $this->original_encoding );

		$target = false;
		if ( self::AMP_ENCODING !== strtolower( $this->original_encoding ) ) {
			$target = function_exists( 'mb_convert_encoding' )
				? mb_convert_encoding( $source, self::AMP_ENCODING, $this->original_encoding )
				: false;
		}

		return false !== $target ? $target : $source;
	}

	/**
	 * Detect the encoding of the document.
	 *
	 * @param string $content Content of which to detect the encoding.
	 * @return string|false Detected encoding of the document, or false if none.
	 */
	private function detect_and_strip_encoding( &$content ) {
		$encoding = self::UNKNOWN_ENCODING;

		// Check for HTML 4 http-equiv meta tags.
		$http_equiv_tag = $this->find_tag( $content, 'meta', 'http-equiv' );
		if ( $http_equiv_tag ) {
			$encoding = $this->extract_value( $http_equiv_tag, 'charset' );
		}

		// Check for HTML 5 charset meta tag. This overrides the HTML 4 charset.
		$charset_tag = $this->find_tag( $content, 'meta', 'charset' );
		if ( $charset_tag ) {
			$encoding = $this->extract_value( $charset_tag, 'charset' );
		}

		// Strip charset tags if they don't fit the AMP UTF-8 requirement.
		if ( self::AMP_ENCODING !== strtolower( $encoding ) ) {
			if ( $http_equiv_tag ) {
				$content = str_replace( $http_equiv_tag, '', $content );
			}

			if ( $charset_tag ) {
				$content = str_replace( $charset_tag, '', $content );
			}
		}

		return $encoding;
	}

	/**
	 * Find a given tag with a given attribute.
	 *
	 * If multiple tags match, this method will only return the first one.
	 *
	 * @param string $content   Content in which to find the tag.
	 * @param string $element   Element of the tag.
	 * @param string $attribute Attribute that the tag contains.
	 * @return string|false The requested tag, or false if not found.
	 */
	private function find_tag( $content, $element, $attribute = null ) {
		$matches = [];
		$pattern = empty( $attribute )
			? sprintf(
				self::HTML_FIND_TAG_WITHOUT_ATTRIBUTE_PATTERN,
				preg_quote( $element, '/' )
			)
			: sprintf(
				self::HTML_FIND_TAG_WITH_ATTRIBUTE_PATTERN,
				preg_quote( $element, '/' ),
				preg_quote( $attribute, '/' )
			);

		if ( preg_match( $pattern, $content, $matches ) ) {
			return $matches[0];
		}

		return false;
	}

	/**
	 * Extract an attribute value from an HTML tag.
	 *
	 * @param string $tag       Tag from which to extract the attribute.
	 * @param string $attribute Attribute of which to extract the value.
	 * @return string|false Extracted attribute value, false if not found.
	 */
	private function extract_value( $tag, $attribute ) {
		$matches = [];
		$pattern = sprintf(
			self::HTML_EXTRACT_ATTRIBUTE_VALUE_PATTERN,
			preg_quote( $attribute, '/' )
		);

		if ( preg_match( $pattern, $tag, $matches ) ) {
			return empty( $matches['full'] ) ? $matches['partial'] : $matches['full'];
		}

		return false;
	}

	/**
	 * Sanitize the encoding that was detected.
	 *
	 * If sanitization fails, it will return 'auto', letting the conversion
	 * logic try to figure it out itself.
	 *
	 * @param string $encoding Encoding to sanitize.
	 * @return string Sanitized encoding. Falls back to 'auto' on failure.
	 */
	private function sanitize_encoding( $encoding ) {
		if ( ! function_exists( 'mb_list_encodings' ) ) {
			return $encoding;
		}

		static $known_encodings = null;

		if ( null === $known_encodings ) {
			$known_encodings = array_map( 'strtolower', mb_list_encodings() );
		}

		if ( array_key_exists( strtolower( $encoding ), self::$encoding_map ) ) {
			$encoding = self::$encoding_map[ strtolower( $encoding ) ];
		}

		if ( ! in_array( strtolower( $encoding ), $known_encodings, true ) ) {
			return self::UNKNOWN_ENCODING;
		}

		return $encoding;
	}

	/**
	 * Replace Mustache template tokens to safeguard them from turning into HTML entities.
	 *
	 * Prevents amp-mustache syntax from getting URL-encoded in attributes when saveHTML is done.
	 * While this is applying to the entire document, it only really matters inside of <template>
	 * elements, since URL-encoding of curly braces in href attributes would not normally matter.
	 * But when this is done inside of a <template> then it breaks Mustache. Since Mustache
	 * is logic-less and curly braces are not unsafe for HTML, we can do a global replacement.
	 * The replacement is done on the entire HTML document instead of just inside of the <template>
	 * elements since it is faster and wouldn't change the outcome.
	 *
	 * @see restore_mustache_template_tokens() Reciprocal function.
	 */
	private function replace_mustache_template_tokens() {
		$templates = $this->getElementsByTagName( self::TAG_TEMPLATE );

		if ( ! $templates || 0 === count( $templates ) ) {
			return;
		}

		$mustache_tag_placeholders = $this->get_mustache_tag_placeholders();

		foreach ( $templates as $template ) {
			foreach ( $this->xpath->query( self::XPATH_URL_ENCODED_ATTRIBUTES_QUERY, $template ) as $attribute ) {
				$attribute->nodeValue = str_replace(
					array_keys( $mustache_tag_placeholders ),
					$mustache_tag_placeholders,
					$attribute->nodeValue,
					$count
				);
				if ( $count ) {
					$this->mustache_tags_replaced = true;
				}
			}
		}
	}

	/**
	 * Restore Mustache template tokens that were previously replaced.
	 *
	 * @see replace_mustache_template_tokens() Reciprocal function.
	 *
	 * @param string $html HTML string to adapt.
	 * @return string Adapted HTML string.
	 */
	private function restore_mustache_template_tokens( $html ) {
		if ( ! $this->mustache_tags_replaced ) {
			return $html;
		}

		$mustache_tag_placeholders = $this->get_mustache_tag_placeholders();

		return str_replace(
			$mustache_tag_placeholders,
			array_keys( $mustache_tag_placeholders ),
			$html
		);
	}

	/**
	 * Get amp-mustache tag/placeholder mappings.
	 *
	 * @see \wpdb::placeholder_escape()
	 *
	 * @return string[] Mapping of mustache tag token to its placeholder.
	 */
	private function get_mustache_tag_placeholders() {
		static $placeholders = null;

		if ( null === $placeholders ) {
			$placeholders = [];
			$salt         = wp_rand();

			// Note: The order of these tokens is important, as it determines the order of the order of the replacements.
			$tokens = [
				'{{{',
				'}}}',
				'{{#',
				'{{^',
				'{{/',
				'{{/',
				'{{',
				'}}',
			];

			foreach ( $tokens as $token ) {
				$placeholders[ $token ] = '_amp_mustache_' . md5( $salt . $token );
			}
		}

		return $placeholders;
	}

	/**
	 * Magic getter to implement lazily-created, cached properties for the document.
	 *
	 * @param string $name Name of the property to get.
	 * @return mixed Value of the property, or null if unknown property was requested.
	 */
	public function __get( $name ) {
		switch ( $name ) {
			case 'xpath':
				$this->xpath = new DOMXPath( $this );
				return $this->xpath;
			case self::TAG_HEAD:
				$this->head = $this->getElementsByTagName( self::TAG_HEAD )->item( 0 );
				if ( null === $this->head ) {
					// Document was assembled manually and bypassed normalisation.
					$this->normalize_dom_structure();
					$this->head = $this->getElementsByTagName( self::TAG_HEAD )->item( 0 );
				}
				return $this->head;
			case self::TAG_BODY:
				$this->body = $this->getElementsByTagName( self::TAG_BODY )->item( 0 );
				if ( null === $this->body ) {
					// Document was assembled manually and bypassed normalisation.
					$this->normalize_dom_structure();
					$this->body = $this->getElementsByTagName( self::TAG_BODY )->item( 0 );
				}
				return $this->body;
		}

		// Mimic regular PHP behavior for missing notices.
		trigger_error( self::PROPERTY_GETTER_ERROR_MESSAGE . $name, E_NOTICE ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions,WordPress.Security.EscapeOutput
		return null;
	}
}
