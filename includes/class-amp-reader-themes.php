<?php
/**
 * Fetches and formats data for AMP reader themes.
 *
 * @package AMP
 * @since 1.6.0
 */

/**
 * Class AMP_Reader_Themes.
 *
 * @since 1.6.0
 */
final class AMP_Reader_Themes {
	/**
	 * Formatted theme data.
	 *
	 * @since 1.6.0
	 *
	 * @var array
	 */
	private $themes;

	/**
	 * The name of the currently active theme.
	 *
	 * @since 1.6.0
	 *
	 * @var string
	 */
	private $current_theme_name;

	/**
	 * Whether themes can be installed in the current WordPress installation.
	 *
	 * @since 1.6.0
	 *
	 * @var bool
	 */
	private $can_install_themes;

	/**
	 * Status indicating a reader theme is active on the site.
	 *
	 * @since 1.6.0
	 *
	 * @var string
	 */
	const ACTIVE_STATUS = 'active';

	/**
	 * Status indicating a reader theme is installed but not active.
	 *
	 * @since 1.6.0
	 *
	 * @var string
	 */
	const INSTALLED_STATUS = 'installed';

	/**
	 * Status indicating a reader theme is not installed but is installable.
	 *
	 * @since 1.6.0
	 *
	 * @var string
	 */
	const INSTALLABLE_STATUS = 'installable';

	/**
	 * Status indicating a reader theme is not installed and can't be installed.
	 *
	 * @since 1.6.0
	 *
	 * @var string
	 */
	const NON_INSTALLABLE_STATUS = 'non-installable';

	/**
	 * Retrieves all AMP plugin options specified in the endpoint schema.
	 *
	 * @since 1.6.0
	 *
	 * @return array Formatted theme data.
	 */
	public function get_themes() {
		if ( ! is_null( $this->themes ) ) {
			return $this->themes;
		}

		$themes   = $this->get_default_supported_reader_themes();
		$themes   = array_map( [ $this, 'prepare_theme' ], $themes );
		$themes[] = $this->get_classic_mode();

		/**
		 * Filters supported reader themes.
		 *
		 * @param array $themes [
		 *     Reader theme data.
		 *     {
		 *         @type string         $name           Theme name.
		 *         @type string         $slug           Theme slug.
		 *         @type string         $slug           URL of theme preview.
		 *         @type string         $screenshot_url The URL of a mobile screenshot. Note: if this is empty, the theme may not display.
		 *         @type string         $homepage        A link to a page with more information about the theme.
		 *         @type string         $description     A description of the theme.
		 *         @type string|boolean $requires        Minimum version of WordPress required by the theme. False if all versions are supported.
		 *         @type string|boolean $requires_php    Minimum version of PHP required by the theme. False if all versions are supported.
		 *         @type string         $download_link   A link to the theme's zip file. If empty, the plugin will attempt to download the theme from wordpress.org.
		 *     }
		 * ]
		 */
		$themes = apply_filters( 'amp_reader_themes', $themes );

		foreach ( $themes as &$theme ) {
			$theme['availability'] = $this->get_theme_availability( $theme );
		}

		$this->themes = $themes;

		return $this->themes;
	}

	/**
	 * Gets a reader theme by slug.
	 *
	 * @since 1.6.0
	 *
	 * @param string $slug Theme slug.
	 * @return array Theme data.
	 */
	public function get_reader_theme( $slug ) {
		return current(
			array_filter(
				$this->get_themes(),
				static function( $theme ) use ( $slug ) {
					return $theme['slug'] === $slug;
				}
			)
		);
	}

	/**
	 * Retrieves theme data.
	 *
	 * @since 1.6.0
	 *
	 * @param boolean $from_api Whether to return theme data from the wordpress.org API. Default false.
	 * @return array Theme ecosystem posts copied the amp-wp.org website.
	 */
	public function get_default_supported_reader_themes( $from_api = false ) {
		// Note: This can be used to manually refresh the hardcoded raw theme data.
		if ( $from_api ) {
			if ( ! function_exists( 'themes_api' ) ) {
				require_once ABSPATH . 'wp-admin/includes/theme.php';
			}

			$response = themes_api(
				'query_themes',
				[
					'author'   => 'wordpressdotorg',
					'per_page' => 24, // There are only 12 as of 05/2020.
				]
			);

			if ( ! $response || is_wp_error( $response ) ) {
				return $response;
			}

			$supported_themes = array_diff(
				AMP_Core_Theme_Sanitizer::get_supported_themes(),
				[ 'twentyten' ] // Excluded because not responsive.
			);

			$supported_themes_from_response = array_filter(
				$response->themes,
				static function( $theme ) use ( $supported_themes ) {
					return in_array( $theme->slug, $supported_themes, true );
				}
			);

			return $supported_themes_from_response;
		}

		$themes = self::get_default_raw_reader_themes();

		return $themes;
	}

	/**
	 * Prepares a single theme.
	 *
	 * @since 1.6.0
	 *
	 * @param array $theme Theme data from the wordpress.org themes API.
	 * @return array Prepared theme array.
	 */
	public function prepare_theme( $theme ) {
		$prepared_theme = [];
		$theme_array    = (array) $theme;

		$keys = [
			'name',
			'slug',
			'preview_url',
			'screenshot_url',
			'homepage',
			'description',
			'requires',
			'requires_php',
			'download_link',
		];

		$prepared_theme = array_filter(
			$theme_array,
			function( $key ) use ( $keys ) {
				return in_array( $key, $keys, true );
			},
			ARRAY_FILTER_USE_KEY
		);

		foreach ( $keys as $key ) {
			if ( ! array_key_exists( $key, $prepared_theme ) ) {
				$prepared_theme[ $key ] = '';
			}
		}

		return $prepared_theme;
	}

	/**
	 * Provides the current theme name.
	 *
	 * @return string|bool The theme name, or false if the theme has errors.
	 */
	private function get_current_theme_name() {
		if ( is_null( $this->current_theme_name ) ) {
			$current_theme = wp_get_theme();

			$this->current_theme_name = $current_theme->exists() ? $current_theme->get( 'Name' ) : false;
		}

		return $this->current_theme_name;
	}

	/**
	 * Returns whether the themes can be installed on the system.
	 *
	 * @since 1.6.0
	 *
	 * @param array $theme Theme data.
	 * @return bool True if themes can be installed.
	 */
	private function can_install_theme( $theme ) {
		if ( is_null( $this->can_install_themes ) ) {
			if ( ! class_exists( 'WP_Upgrader' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
				require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
			}

			$this->can_install_themes = true === ( new WP_Upgrader() )->fs_connect( [ get_theme_root() ] );
		}

		if ( ! $this->can_install_themes ) {
			return false;
		}

		if ( ! empty( $theme['requires'] ) && ! is_wp_version_compatible( $theme['requires'] ) ) {
			return false;
		}

		if ( ! empty( $theme['requires_php'] ) && ! is_php_version_compatible( $theme['requires_php'] ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Returns reader theme availability status.
	 *
	 * @since 1.6.0
	 *
	 * @param array $theme Theme data.
	 * @return array Theme availability status.
	 */
	public function get_theme_availability( $theme ) {
		switch ( true ) {
			case $this->get_current_theme_name() === $theme['name']:
				return self::ACTIVE_STATUS;

			case wp_get_theme( $theme['slug'] )->exists():
				return self::INSTALLED_STATUS;

			case $this->can_install_theme( $theme ):
				return self::INSTALLABLE_STATUS;

			default:
				return self::NON_INSTALLABLE_STATUS;
		}
	}

	/**
	 * Provides details for the classic theme included with the plugin.
	 *
	 * @since 1.6.0
	 *
	 * @return array
	 */
	private function get_classic_mode() {
		return [
			'name'           => 'AMP Classic',
			'slug'           => 'classic',
			'preview_url'    => 'https://amp-wp.org',
			'screenshot_url' => '//via.placeholder.com/218x472',
			'homepage'       => 'https://amp-wp.org',
			'description'    => __(
				// @todo Improved description text.
				'A legacy default template that looks nice and clean, with a good balance between ease and extensibility when it comes to customization.',
				'amp'
			),
			'requires'       => false,
			'requires_php'   => false,
			'download_link'  => '',
			'availability'   => [
				'is_active'         => false,
				'can_install'       => true,
				'is_compatible_wp'  => true,
				'is_compatible_php' => true,
				'is_installed'      => true,
			],
		];
	}

	/**
	 * Provides raw data for the default reader themes. Descriptions are translatable.
	 *
	 * @since 1.6.0
	 *
	 * @return array
	 */
	public static function get_default_raw_reader_themes() {
		// Copied from JSON data returned from Wordpress.org themes endpoint. See ::get_default_supported_reader_themes.
		// Note: Descriptions are made translatable in the AMP_Reader_Theme_REST_Controller.
		return [
			[
				'name'           => 'Twenty Twenty',
				'slug'           => 'twentytwenty',
				'version'        => '1.3',
				'preview_url'    => 'https://wp-themes.com/twentytwenty',
				'author'         =>
				[
					'user_nicename' => 'wordpressdotorg',
					'profile'       => 'https://profiles.wordpress.org/wordpressdotorg',
					'avatar'        => 'https://secure.gravatar.com/avatar/61ee2579b8905e62b4b4045bdc92c11a?s=96&d=monsterid&r=g',
					'display_name'  => 'WordPress.org',
				],
				'screenshot_url' => '//ts.w.org/wp-content/themes/twentytwenty/screenshot.png?ver=1.3',
				'rating'         => 86,
				'num_ratings'    => '37',
				'homepage'       => 'https://wordpress.org/themes/twentytwenty/',
				'description'    => 'Our default theme for 2020 is designed to take full advantage of the flexibility of the block editor. Organizations and businesses have the ability to create dynamic landing pages with endless layouts using the group and column blocks. The centered content column and fine-tuned typography also makes it perfect for traditional blogs. Complete editor styles give you a good idea of what your content will look like, even before you publish. You can give your site a personal touch by changing the background colors and the accent color in the Customizer. The colors of all elements on your site are automatically calculated based on the colors you pick, ensuring a high, accessible color contrast for your visitors.',
				'requires'       => '4.7',
				'requires_php'   => '5.2.4',
			],
			[
				'name'           => 'Twenty Nineteen',
				'slug'           => 'twentynineteen',
				'version'        => '1.5',
				'preview_url'    => 'https://wp-themes.com/twentynineteen',
				'author'         =>
				[
					'user_nicename' => 'wordpressdotorg',
					'profile'       => 'https://profiles.wordpress.org/wordpressdotorg',
					'avatar'        => 'https://secure.gravatar.com/avatar/61ee2579b8905e62b4b4045bdc92c11a?s=96&d=monsterid&r=g',
					'display_name'  => 'WordPress.org',
				],
				'screenshot_url' => '//ts.w.org/wp-content/themes/twentynineteen/screenshot.png?ver=1.5',
				'rating'         => 72,
				'num_ratings'    => '54',
				'homepage'       => 'https://wordpress.org/themes/twentynineteen/',
				'description'    => 'Our 2019 default theme is designed to show off the power of the block editor. It features custom styles for all the default blocks, and is built so that what you see in the editor looks like what you\'ll see on your website. Twenty Nineteen is designed to be adaptable to a wide range of websites, whether you’re running a photo blog, launching a new business, or supporting a non-profit. Featuring ample whitespace and modern sans-serif headlines paired with classic serif body text, it\'s built to be beautiful on all screen sizes.',
				'requires'       => '4.9.6',
				'requires_php'   => '5.2.4',
			],
			[
				'name'           => 'Twenty Seventeen',
				'slug'           => 'twentyseventeen',
				'version'        => '2.3',
				'preview_url'    => 'https://wp-themes.com/twentyseventeen',
				'author'         =>
				[
					'user_nicename' => 'wordpressdotorg',
					'profile'       => 'https://profiles.wordpress.org/wordpressdotorg',
					'avatar'        => 'https://secure.gravatar.com/avatar/61ee2579b8905e62b4b4045bdc92c11a?s=96&d=monsterid&r=g',
					'display_name'  => 'WordPress.org',
				],
				'screenshot_url' => '//ts.w.org/wp-content/themes/twentyseventeen/screenshot.png?ver=2.3',
				'rating'         => 90,
				'num_ratings'    => '110',
				'homepage'       => 'https://wordpress.org/themes/twentyseventeen/',
				'description'    => 'Twenty Seventeen brings your site to life with header video and immersive featured images. With a focus on business sites, it features multiple sections on the front page as well as widgets, navigation and social menus, a logo, and more. Personalize its asymmetrical grid with a custom color scheme and showcase your multimedia content with post formats. Our default theme for 2017 works great in many languages, for any abilities, and on any device.',
				'requires'       => '4.7',
				'requires_php'   => '5.2.4',
			],
			[
				'name'           => 'Twenty Sixteen',
				'slug'           => 'twentysixteen',
				'version'        => '2.1',
				'preview_url'    => 'https://wp-themes.com/twentysixteen',
				'author'         =>
				[
					'user_nicename' => 'wordpressdotorg',
					'profile'       => 'https://profiles.wordpress.org/wordpressdotorg',
					'avatar'        => 'https://secure.gravatar.com/avatar/61ee2579b8905e62b4b4045bdc92c11a?s=96&d=monsterid&r=g',
					'display_name'  => 'WordPress.org',
				],
				'screenshot_url' => '//ts.w.org/wp-content/themes/twentysixteen/screenshot.png?ver=2.1',
				'rating'         => 82,
				'num_ratings'    => '76',
				'homepage'       => 'https://wordpress.org/themes/twentysixteen/',
				'description'    => 'Twenty Sixteen is a modernized take on an ever-popular WordPress layout — the horizontal masthead with an optional right sidebar that works perfectly for blogs and websites. It has custom color options with beautiful default color schemes, a harmonious fluid grid using a mobile-first approach, and impeccable polish in every detail. Twenty Sixteen will make your WordPress look beautiful everywhere.',
				'requires'       => '4.4',
				'requires_php'   => '5.2.4',
			],
			[
				'name'           => 'Twenty Fifteen',
				'slug'           => 'twentyfifteen',
				'version'        => '2.6',
				'preview_url'    => 'https://wp-themes.com/twentyfifteen',
				'author'         =>
				[
					'user_nicename' => 'wordpressdotorg',
					'profile'       => 'https://profiles.wordpress.org/wordpressdotorg',
					'avatar'        => 'https://secure.gravatar.com/avatar/61ee2579b8905e62b4b4045bdc92c11a?s=96&d=monsterid&r=g',
					'display_name'  => 'WordPress.org',
				],
				'screenshot_url' => '//ts.w.org/wp-content/themes/twentyfifteen/screenshot.png?ver=2.6',
				'rating'         => 88,
				'num_ratings'    => '48',
				'homepage'       => 'https://wordpress.org/themes/twentyfifteen/',
				'description'    => 'Our 2015 default theme is clean, blog-focused, and designed for clarity. Twenty Fifteen\'s simple, straightforward typography is readable on a wide variety of screen sizes, and suitable for multiple languages. We designed it using a mobile-first approach, meaning your content takes center-stage, regardless of whether your visitors arrive by smartphone, tablet, laptop, or desktop computer.',
				'requires'       => false,
				'requires_php'   => '5.2.4',
			],
			[
				'name'           => 'Twenty Fourteen',
				'slug'           => 'twentyfourteen',
				'version'        => '2.8',
				'preview_url'    => 'https://wp-themes.com/twentyfourteen',
				'author'         =>
				[
					'user_nicename' => 'wordpressdotorg',
					'profile'       => 'https://profiles.wordpress.org/wordpressdotorg',
					'avatar'        => 'https://secure.gravatar.com/avatar/61ee2579b8905e62b4b4045bdc92c11a?s=96&d=monsterid&r=g',
					'display_name'  => 'WordPress.org',
				],
				'screenshot_url' => '//ts.w.org/wp-content/themes/twentyfourteen/screenshot.png?ver=2.8',
				'rating'         => 88,
				'num_ratings'    => '93',
				'homepage'       => 'https://wordpress.org/themes/twentyfourteen/',
				'description'    => 'In 2014, our default theme lets you create a responsive magazine website with a sleek, modern design. Feature your favorite homepage content in either a grid or a slider. Use the three widget areas to customize your website, and change your content\'s layout with a full-width page template and a contributor page to show off your authors. Creating a magazine website with WordPress has never been easier.',
				'requires'       => false,
				'requires_php'   => '5.2.4',
			],
			[
				'name'           => 'Twenty Thirteen',
				'slug'           => 'twentythirteen',
				'version'        => '3.0',
				'preview_url'    => 'https://wp-themes.com/twentythirteen',
				'author'         =>
				[
					'user_nicename' => 'wordpressdotorg',
					'profile'       => 'https://profiles.wordpress.org/wordpressdotorg',
					'avatar'        => 'https://secure.gravatar.com/avatar/61ee2579b8905e62b4b4045bdc92c11a?s=96&d=monsterid&r=g',
					'display_name'  => 'WordPress.org',
				],
				'screenshot_url' => '//ts.w.org/wp-content/themes/twentythirteen/screenshot.png?ver=3.0',
				'rating'         => 82,
				'num_ratings'    => '62',
				'homepage'       => 'https://wordpress.org/themes/twentythirteen/',
				'description'    => 'The 2013 theme for WordPress takes us back to the blog, featuring a full range of post formats, each displayed beautifully in their own unique way. Design details abound, starting with a vibrant color scheme and matching header images, beautiful typography and icons, and a flexible layout that looks great on any device, big or small.',
				'requires'       => '3.6',
				'requires_php'   => '5.2.4',
			],
			[
				'name'           => 'Twenty Twelve',
				'slug'           => 'twentytwelve',
				'version'        => '3.1',
				'preview_url'    => 'https://wp-themes.com/twentytwelve',
				'author'         =>
				[
					'user_nicename' => 'wordpressdotorg',
					'profile'       => 'https://profiles.wordpress.org/wordpressdotorg',
					'avatar'        => 'https://secure.gravatar.com/avatar/61ee2579b8905e62b4b4045bdc92c11a?s=96&d=monsterid&r=g',
					'display_name'  => 'WordPress.org',
				],
				'screenshot_url' => '//ts.w.org/wp-content/themes/twentytwelve/screenshot.png?ver=3.1',
				'rating'         => 92,
				'num_ratings'    => '155',
				'homepage'       => 'https://wordpress.org/themes/twentytwelve/',
				'description'    => 'The 2012 theme for WordPress is a fully responsive theme that looks great on any device. Features include a front page template with its own widgets, an optional display font, styling for post formats on both index and single views, and an optional no-sidebar page template. Make it yours with a custom menu, header image, and background.',
				'requires'       => '3.5',
				'requires_php'   => '5.2.4',
			],
			[
				'name'           => 'Twenty Eleven',
				'slug'           => 'twentyeleven',
				'version'        => '3.4',
				'preview_url'    => 'https://wp-themes.com/twentyeleven',
				'author'         =>
				[
					'user_nicename' => 'wordpressdotorg',
					'profile'       => 'https://profiles.wordpress.org/wordpressdotorg',
					'avatar'        => 'https://secure.gravatar.com/avatar/61ee2579b8905e62b4b4045bdc92c11a?s=96&d=monsterid&r=g',
					'display_name'  => 'WordPress.org',
				],
				'screenshot_url' => '//ts.w.org/wp-content/themes/twentyeleven/screenshot.png?ver=3.4',
				'rating'         => 94,
				'num_ratings'    => '45',
				'homepage'       => 'https://wordpress.org/themes/twentyeleven/',
				'description'    => 'The 2011 theme for WordPress is sophisticated, lightweight, and adaptable. Make it yours with a custom menu, header image, and background -- then go further with available theme options for light or dark color scheme, custom link colors, and three layout choices. Twenty Eleven comes equipped with a Showcase page template that transforms your front page into a showcase to show off your best content, widget support galore (sidebar, three footer areas, and a Showcase page widget area), and a custom "Ephemera" widget to display your Aside, Link, Quote, or Status posts. Included are styles for print and for the admin editor, support for featured images (as custom header images on posts and pages and as large images on featured "sticky" posts), and special styles for six different post formats.',
				'requires'       => false,
				'requires_php'   => '5.2.4',
			],
		];
	}
}