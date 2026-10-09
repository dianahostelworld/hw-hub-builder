<?php
/**
 * Plugin Name:       Hostelworld Hub Builder
 * Description:       Creates hub pages in bulk from one spreadsheet: template, parent page, address, hub settings, Yoast SEO title and meta description, and the hero photo with its credit and alt text. Pages are created as drafts. Needs the Hostelworld Blog Hubs plugin; can be deactivated once the pages exist, and the pages stay exactly as they are.
 * Version:           1.2.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Hostelworld
 * Text Domain:       hostelworld
 *
 * @package Hostelworld_Hub_Builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'HW_BUILDER_VERSION', '1.2.0' );
define( 'HW_BUILDER_URL', plugin_dir_url( __FILE__ ) );
define( 'HW_BUILDER_PATH', plugin_dir_path( __FILE__ ) );

/**
 * Whether Blog Hubs is active, which the builder relies on for the templates,
 * the field storage and the spreadsheet reader.
 *
 * @return bool
 */
function hw_builder_ready() {
	return function_exists( 'hw_hub_meta_key' ) && function_exists( 'hw_hub_xlsx_read' ) && defined( 'HW_HUB_CITY_TEMPLATE_KEY' );
}

add_action(
	'admin_notices',
	function () {
		if ( ! hw_builder_ready() && current_user_can( 'activate_plugins' ) ) {
			echo '<div class="notice notice-error"><p><strong>Hub Builder</strong> needs the Hostelworld Blog Hubs plugin to be active.</p></div>';
		}
	}
);

/* ---------- The columns ---------------------------------------------------- */

/**
 * The spreadsheet's columns: key => header as it appears in the template.
 * Headers are matched loosely (case, spaces and punctuation ignored).
 *
 * @return array
 */
function hw_builder_columns() {
	return array(
		'template'     => 'Template',
		'title'        => 'Page title',
		'slug'         => 'Page address',
		'parent'       => 'Parent page',
		'tag'          => 'Tag',
		'hw_city'      => 'Hostelworld city',
		'hw_country'   => 'Hostelworld country',
		'currency'     => 'Currency',
		'seo_title'    => 'SEO title',
		'meta'         => 'Meta description',
		'photo'        => 'Photo link',
		'photographer' => 'Photographer',
		'photo_alt'    => 'Alt text',
	);
}

/**
 * Template names people may type, to the Blog Hubs template.
 *
 * @return array typed => array( page template key, internal name, label )
 */
function hw_builder_templates() {
	$city    = array( HW_HUB_CITY_TEMPLATE_KEY, 'city', 'City hub' );
	$country = array( HW_HUB_COUNTRY_TEMPLATE_KEY, 'country', 'Country hub' );
	$states  = array( HW_HUB_COUNTRY_STATES_TEMPLATE_KEY, 'country_states', 'Country hub (states)' );
	$state   = array( HW_HUB_STATE_TEMPLATE_KEY, 'state', 'State hub' );
	$cont    = array( HW_HUB_TEMPLATE_KEY, 'continent', 'Continent hub' );

	return array(
		'city'                 => $city,
		'city hub'             => $city,
		'country'              => $country,
		'country hub'          => $country,
		'country states'       => $states,
		'country with states'  => $states,
		'country hub states'   => $states,
		'state'                => $state,
		'state hub'            => $state,
		'continent'            => $cont,
		'continent hub'        => $cont,
	);
}

/**
 * Normalises a header or a template name for matching.
 *
 * @param string $text Text.
 * @return string
 */
function hw_builder_norm( $text ) {
	return trim( preg_replace( '/[^a-z0-9]+/', ' ', strtolower( remove_accents( (string) $text ) ) ) );
}

/* ---------- Reading the file ----------------------------------------------- */

/**
 * The rows of an uploaded .xlsx or .csv, as column key => value.
 *
 * @param string $path File.
 * @param string $name Original name.
 * @return array|WP_Error
 */
function hw_builder_read( $path, $name ) {
	$ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );

	if ( 'csv' === $ext ) {
		$grid   = array();
		$handle = fopen( $path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( ! $handle ) {
			return new WP_Error( 'hw_builder_read', 'The file could not be read.' );
		}

		while ( false !== ( $row = fgetcsv( $handle ) ) ) {
			$grid[] = $row;
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		// Excel's UTF-8 CSVs start with a byte-order mark.
		if ( isset( $grid[0][0] ) ) {
			$grid[0][0] = preg_replace( '/^\xEF\xBB\xBF/', '', $grid[0][0] );
		}
	} elseif ( 'xlsx' === $ext ) {
		$sheets = hw_hub_xlsx_read( $path );

		if ( is_wp_error( $sheets ) ) {
			return $sheets;
		}

		// The sheet called Pages, or failing that the first one.
		$grid = isset( $sheets['Pages'] ) ? $sheets['Pages'] : reset( $sheets );
	} else {
		return new WP_Error( 'hw_builder_type', 'Upload the spreadsheet as .xlsx, or as .csv.' );
	}

	// The header row is the first row that names "Page title".
	$wanted = array();
	foreach ( hw_builder_columns() as $key => $label ) {
		$wanted[ hw_builder_norm( $label ) ] = $key;
	}

	$header = null;
	$map    = array();

	foreach ( (array) $grid as $i => $row ) {
		$found = array();
		foreach ( (array) $row as $col => $cell ) {
			$norm = hw_builder_norm( $cell );
			if ( isset( $wanted[ $norm ] ) ) {
				$found[ $col ] = $wanted[ $norm ];
			}
		}
		if ( in_array( 'title', $found, true ) ) {
			$header = $i;
			$map    = $found;
			break;
		}
	}

	if ( null === $header ) {
		return new WP_Error( 'hw_builder_header', 'No header row with a "Page title" column was found. Start from the template spreadsheet so the columns are named the way the builder expects.' );
	}

	$rows = array();

	foreach ( array_slice( (array) $grid, $header + 1, null, true ) as $i => $row ) {
		$item = array_fill_keys( array_keys( hw_builder_columns() ), '' );

		foreach ( $map as $col => $key ) {
			$item[ $key ] = isset( $row[ $col ] ) ? trim( (string) $row[ $col ] ) : '';
		}

		if ( '' === implode( '', $item ) ) {
			continue; // Blank line.
		}

		$item['line'] = (int) $i + 1; // The row number as Excel shows it.
		$rows[]       = $item;
	}

	if ( ! $rows ) {
		return new WP_Error( 'hw_builder_empty', 'The spreadsheet has the headers but no rows under them.' );
	}

	if ( count( $rows ) > 300 ) {
		return new WP_Error( 'hw_builder_many', 'That is ' . count( $rows ) . ' rows. Split it into files of 300 or fewer.' );
	}

	return $rows;
}

/* ---------- Checking each row ---------------------------------------------- */

/**
 * A page by its path ("europe/italy"), in any state but the bin.
 *
 * @param string $path Path or full URL.
 * @return WP_Post|null
 */
function hw_builder_page_by_path( $path ) {
	$path = hw_builder_clean_path( $path );

	if ( '' === $path ) {
		return null;
	}

	$parent = 0;
	$page   = null;

	foreach ( explode( '/', $path ) as $part ) {
		$found = get_posts(
			array(
				'post_type'        => 'page',
				'name'             => $part,
				'post_parent'      => $parent,
				'post_status'      => array( 'publish', 'draft', 'private', 'pending', 'future' ),
				'posts_per_page'   => 1,
				'suppress_filters' => false,
				'lang'             => '',
			)
		);

		if ( ! $found ) {
			return null;
		}

		$page   = $found[0];
		$parent = $page->ID;
	}

	return $page;
}

/**
 * A path from whatever was typed: "europe/italy", "/europe/italy/" or the
 * full address of the page.
 *
 * @param string $path Typed.
 * @return string
 */
function hw_builder_clean_path( $path ) {
	$path = trim( (string) $path );

	if ( preg_match( '#^https?://#i', $path ) ) {
		$home = untrailingslashit( home_url() );
		$path = 0 === stripos( $path, $home ) ? substr( $path, strlen( $home ) ) : (string) wp_parse_url( $path, PHP_URL_PATH );
	}

	$parts = array_filter( array_map( 'sanitize_title', explode( '/', $path ) ), 'strlen' );

	return implode( '/', $parts );
}

/**
 * The Pexels photo number in a link to the photo page or to the image itself.
 *
 * @param string $link Link.
 * @return int
 */
function hw_builder_pexels_id( $link ) {
	if ( preg_match( '#pexels\.com/(?:[a-z-]+/)?photo/(?:[^/?]*-)?(\d+)/?#i', $link, $m ) ) {
		return (int) $m[1];
	}

	if ( preg_match( '#images\.pexels\.com/photos/(\d+)/#i', $link, $m ) ) {
		return (int) $m[1];
	}

	return 0;
}

/**
 * The image address to download, at a given width.
 *
 * @param string $link  Photo link from the sheet.
 * @param int    $width Width.
 * @return string
 */
function hw_builder_photo_url( $link, $width = 2000 ) {
	$id = hw_builder_pexels_id( $link );

	if ( $id ) {
		return sprintf( 'https://images.pexels.com/photos/%1$d/pexels-photo-%1$d.jpeg?auto=compress&cs=tinysrgb&w=%2$d', $id, $width );
	}

	return preg_match( '#^https?://.+\.(jpe?g|png|webp)(\?.*)?$#i', $link ) ? $link : '';
}

/**
 * The tag a hub should read from: the tag with that slug, or the tag whose
 * name matches (venice finds venice-italy).
 *
 * @param string $typed Typed tag.
 * @return WP_Term|null
 */
function hw_builder_tag( $typed ) {
	$slug  = sanitize_title( $typed );
	$exact = get_term_by( 'slug', $slug, 'post_tag' );
	$exact = $exact && ! is_wp_error( $exact ) ? $exact : null;
	$named = null;

	// The tag whose name reads the same, with the most articles.
	$found = get_terms( array( 'taxonomy' => 'post_tag', 'hide_empty' => false, 'search' => str_replace( '-', ' ', $slug ) ) );
	foreach ( is_wp_error( $found ) ? array() : $found as $tag ) {
		if ( sanitize_title( $tag->name ) === $slug && ( ! $named || $tag->count > $named->count ) ) {
			$named = $tag;
		}
	}

	// An unused tag with the exact slug loses to a used one with that name:
	// "lisbon" (0 articles) vs "lisbon-portugal" (14) means the latter.
	if ( $exact && $named && $named->term_id !== $exact->term_id && 0 === (int) $exact->count && $named->count > 0 ) {
		return $named;
	}

	return $exact ? $exact : $named;
}

/**
 * Every key a row needs, so rows from a file, the edit form or an older
 * preview all look the same.
 *
 * @param array $row Row.
 * @return array
 */
function hw_builder_row_defaults( $row ) {
	foreach ( array_keys( hw_builder_columns() ) as $key ) {
		$row[ $key ] = isset( $row[ $key ] ) ? (string) $row[ $key ] : '';
	}

	foreach ( array( 'line' => 0, 'result' => null, 'content_key' => '', 'content_name' => '' ) as $key => $default ) {
		if ( ! isset( $row[ $key ] ) ) {
			$row[ $key ] = $default;
		}
	}

	return $row;
}

/**
 * Whether a row gets created: no problems, or only problems that "create
 * anyway" is allowed to skip past (never an address that's already taken).
 *
 * @param array $row   Row.
 * @param array $state Batch.
 * @return bool
 */
function hw_builder_can_go( $row, $state ) {
	if ( ! empty( $row['result']['id'] ) ) {
		return false;
	}

	return ! $row['errors'] || ( ! empty( $state['force'] ) && empty( $row['hard'] ) );
}

/**
 * A row's content spreadsheet, as read when it was attached.
 *
 * @param array $row Row.
 * @return array
 */
function hw_builder_content_sheets( $row ) {
	if ( empty( $row['content_key'] ) ) {
		return array();
	}

	$sheets = get_transient( $row['content_key'] );

	return is_array( $sheets ) ? $sheets : array();
}

/**
 * The full path of an existing page whose address ends like the one typed:
 * "north-america" finds "continents/north-america".
 *
 * @param string $path Typed path.
 * @return string
 */
function hw_builder_guess_parent( $path ) {
	$parts = explode( '/', $path );
	$found = get_posts(
		array(
			'post_type'        => 'page',
			'name'             => end( $parts ),
			'post_status'      => array( 'publish', 'draft', 'private' ),
			'posts_per_page'   => 1,
			'suppress_filters' => false,
			'lang'             => '',
		)
	);

	return $found ? get_page_uri( $found[0] ) : '';
}

/**
 * Checks every row and works out what will be created. Problems that stop a
 * row are errors; the rest are warnings, and the row is still created.
 *
 * @param array $rows Rows.
 * @return array Rows with plan, errors and warnings.
 */
function hw_builder_check( $rows ) {
	$templates = hw_builder_templates();
	$in_batch  = array(); // Paths already claimed by a row above, to catch duplicates.

	// Every page this batch would create, wherever its row sits: a row can go
	// under a page made by any other row, and pages are created parents first.
	$planned = array();
	foreach ( $rows as $r ) {
		$r = hw_builder_row_defaults( $r );
		if ( '' !== $r['title'] && isset( $templates[ hw_builder_norm( $r['template'] ) ] ) ) {
			$planned[ ltrim( hw_builder_clean_path( $r['parent'] ) . '/' . sanitize_title( '' !== $r['slug'] ? $r['slug'] : $r['title'] ), '/' ) ] = true;
		}
	}

	foreach ( $rows as &$row ) {
		$row      = hw_builder_row_defaults( $row );
		$errors   = array();
		$warnings = array();
		$hard     = false; // Problems "create anyway" can't get past.
		$typed    = hw_builder_norm( $row['template'] );
		$template = isset( $templates[ $typed ] ) ? $templates[ $typed ] : null;

		if ( '' === $row['title'] ) {
			$errors[] = 'No page title.';
		}

		if ( ! $template ) {
			$errors[] = '' === $row['template'] ? 'No template. Use City, Country, Country with states, State or Continent.' : 'Template "' . $row['template'] . '" isn\'t one the builder knows. Use City, Country, Country with states, State or Continent.';
		}

		$slug        = sanitize_title( '' !== $row['slug'] ? $row['slug'] : ( '' !== $row['title'] ? $row['title'] : 'untitled-hub' ) );
		$parent_path = hw_builder_clean_path( $row['parent'] );
		$path        = ltrim( $parent_path . '/' . $slug, '/' );
		$parent      = null;

		if ( '' !== $parent_path ) {
			$parent = hw_builder_page_by_path( $parent_path );

			if ( ! $parent && ! isset( $planned[ $parent_path ] ) ) {
				$guess    = hw_builder_guess_parent( $parent_path );
				$errors[] = 'Parent page "' . $parent_path . '" doesn\'t exist, and no row in this batch creates it.' . ( $guess ? ' Did you mean ' . $guess . '?' : '' );
			} elseif ( $parent && 'publish' !== $parent->post_status ) {
				$warnings[] = 'Parent page is ' . $parent->post_status . ', not published.';
			}
		} elseif ( $template && 'continent' !== $template[1] ) {
			$warnings[] = 'No parent page, so this will sit at the top level of the blog.';
		}

		if ( $slug && hw_builder_page_by_path( $path ) ) {
			$errors[] = 'A page already exists at ' . $path . '/. It is left as it is: change the page address to create a new one.';
			$hard     = true;
		} elseif ( isset( $in_batch[ $path ] ) ) {
			$errors[] = 'Row ' . $in_batch[ $path ] . ' already creates ' . $path . '/. Change one of the page addresses.';
			$hard     = true;
		}

		// The tag, as the hub will read it.
		$tag_slug = '';
		if ( '' !== $row['tag'] ) {
			$tag = hw_builder_tag( $row['tag'] );
			if ( $tag ) {
				$tag_slug = $tag->slug;
				if ( sanitize_title( $row['tag'] ) !== $tag->slug ) {
					$warnings[] = 'Tag "' . $row['tag'] . '" is ' . $tag->slug . ' on the blog, so that\'s what will be saved.';
				}
			} else {
				$tag_slug   = sanitize_title( $row['tag'] );
				$warnings[] = 'No tag "' . $tag_slug . '" on the blog yet: the carousels stay empty until articles carry it.';
			}
		} else {
			$warnings[] = 'No tag, so the hub has no articles to show and the destination box can\'t point here.';
		}

		// Hostelworld knows the city?
		$hw_found = '';
		if ( $template && 'city' === $template[1] ) {
			if ( '' === $row['hw_city'] || '' === $row['hw_country'] ) {
				$warnings[] = 'No Hostelworld city or country, so no live hostels, prices or events.';
			} elseif ( function_exists( 'hw_hub_api_city' ) ) {
				$record = hw_hub_api_city( $row['hw_city'], $row['hw_country'] );
				if ( empty( $record['id'] ) ) {
					$warnings[] = 'Hostelworld has no "' . $row['hw_city'] . '" in "' . $row['hw_country'] . '": check the spelling in a Hostelworld web address. No live data until it\'s fixed.';
				} else {
					$hw_found = $record['name'] . ', ' . number_format_i18n( $record['properties'] ) . ' places to stay';
				}
			}
		}

		$symbols = array( '$' => 'USD', '€' => 'EUR', '£' => 'GBP' );
		if ( isset( $symbols[ trim( $row['currency'] ) ] ) ) {
			$warnings[]      = 'Currency "' . trim( $row['currency'] ) . '" read as ' . $symbols[ trim( $row['currency'] ) ] . '.';
			$row['currency'] = $symbols[ trim( $row['currency'] ) ];
		}

		if ( '' !== $row['currency'] && ! preg_match( '/^[A-Za-z]{3}$/', $row['currency'] ) ) {
			$warnings[] = 'Currency "' . $row['currency'] . '" should be three letters, like EUR. Left empty.';
			$row['currency'] = '';
		}

		// SEO copy.
		$title_len = mb_strlen( $row['seo_title'] );
		$meta_len  = mb_strlen( $row['meta'] );

		if ( ! $title_len ) {
			$warnings[] = 'No SEO title: Yoast uses its default.';
		} elseif ( $title_len > 60 ) {
			$warnings[] = 'SEO title is ' . $title_len . ' characters; Google usually cuts it after about 60.';
		}

		if ( ! $meta_len ) {
			$warnings[] = 'No meta description.';
		} elseif ( $meta_len > 155 ) {
			$warnings[] = 'Meta description is ' . $meta_len . ' characters; Google usually cuts it after about 155.';
		} elseif ( $meta_len < 70 ) {
			$warnings[] = 'Meta description is only ' . $meta_len . ' characters.';
		}

		// Photo.
		$photo = '';
		if ( '' !== $row['photo'] ) {
			$photo = hw_builder_photo_url( $row['photo'] );
			if ( ! $photo ) {
				$warnings[] = 'The photo link isn\'t a Pexels photo or an image address, so there\'s no hero photo.';
			} else {
				if ( '' === $row['photographer'] ) {
					$warnings[] = 'No photographer, so the photo has no credit.';
				}
				if ( '' === $row['photo_alt'] ) {
					$warnings[] = 'No alt text for the photo.';
				}
			}
		} else {
			$warnings[] = 'No photo.';
		}

		// The hub's content spreadsheet, if one is attached.
		$content = array();
		$sheets = hw_builder_content_sheets( $row );
		if ( $row['content_key'] && ! $sheets ) {
			$errors[] = 'The content spreadsheet ' . $row['content_name'] . ' has expired from the preview (it is kept for a day). Attach it again.';
		}
		if ( $sheets && $template && function_exists( 'hw_hub_import_parse' ) ) {
			$parsed  = hw_hub_import_parse( $sheets, $template[1] );
			$content = array(
				'file'    => $row['content_name'],
				'fields'  => count( $parsed['values'] ),
				'skipped' => $parsed['skipped'],
			);
			if ( ! $parsed['values'] ) {
				$errors[] = 'Nothing in the content spreadsheet ' . $row['content_name'] . ' matches a ' . strtolower( $template[2] ) . '. Is it the right hub type?';
			} elseif ( $parsed['skipped'] ) {
				$warnings[] = 'Content spreadsheet: ' . count( $parsed['skipped'] ) . ' field' . ( 1 === count( $parsed['skipped'] ) ? '' : 's' ) . ' this template doesn\'t have will be ignored (' . implode( ', ', array_slice( $parsed['skipped'], 0, 4 ) ) . ( count( $parsed['skipped'] ) > 4 ? '…' : '' ) . ').';
			}
		}

		// Created rows are done: the page now existing is not a problem.
		if ( ! empty( $row['result']['id'] ) ) {
			$errors   = array();
			$warnings = array();
		}

		if ( ! $hard && $slug ) {
			$in_batch[ $path ] = $row['line'];
		}

		$row['plan'] = array(
			'template'     => $template ? $template[0] : '',
			'name'         => $template ? $template[1] : '',
			'label'        => $template ? $template[2] : '',
			'slug'         => $slug,
			'parent_path'  => $parent_path,
			'path'         => $path,
			'tag'          => $tag_slug,
			'photo'        => $photo,
			'photo_thumb'  => $photo ? hw_builder_photo_url( $row['photo'], 360 ) : '',
			'hw_found'     => $hw_found,
			'content'      => $content,
		);
		$row['errors']   = $errors;
		$row['warnings'] = $warnings;
		$row['hard']     = $hard;
	}
	unset( $row );

	return $rows;
}

/* ---------- Creating a page ------------------------------------------------ */

/**
 * Creates one row's page. Never touches an existing page.
 *
 * @param array  $row   Checked row.
 * @param string $batch Batch id.
 * @return array|WP_Error { id, edit, view, notes }
 */
function hw_builder_create( $row, $batch ) {
	$plan  = $row['plan'];
	$notes = array();

	if ( hw_builder_page_by_path( $plan['path'] ) ) {
		return new WP_Error( 'hw_builder_exists', 'A page already exists at ' . $plan['path'] . '/.' );
	}

	$parent_id = 0;
	if ( '' !== $plan['parent_path'] ) {
		$parent = hw_builder_page_by_path( $plan['parent_path'] );
		if ( $parent ) {
			$parent_id = $parent->ID;
		} else {
			$notes[] = 'No parent page "' . $plan['parent_path'] . '", so it sits at the top level: set the parent in Page attributes.';
		}
	}

	if ( '' === trim( $row['title'] ) ) {
		$row['title'] = 'Untitled hub';
		$notes[]      = 'No title, so it\'s called "Untitled hub".';
	}

	$post_id = wp_insert_post(
		array(
			'post_type'   => 'page',
			'post_status' => 'draft',
			'post_title'  => $row['title'],
			'post_name'   => $plan['slug'],
			'post_parent' => $parent_id,
		),
		true
	);

	if ( is_wp_error( $post_id ) ) {
		return $post_id;
	}

	// Same language as the parent (or the default) when Polylang runs.
	if ( function_exists( 'pll_set_post_language' ) ) {
		$lang = $parent_id && function_exists( 'pll_get_post_language' ) ? pll_get_post_language( $parent_id ) : '';
		$lang = $lang ? $lang : ( function_exists( 'pll_default_language' ) ? pll_default_language() : '' );
		if ( $lang ) {
			pll_set_post_language( $post_id, $lang );
		}
	}

	update_post_meta( $post_id, '_hw_builder_batch', $batch );

	// Without a template it's a plain page: only the title, address, parent,
	// SEO and photo apply, since hub fields need to know their template.
	if ( ! $plan['template'] ) {
		$notes[] = 'No hub template, so it\'s a plain page: choose the template in Page attributes, then fill in the hub panel.';
		hw_builder_finish_plain( $post_id, $row, $plan, $notes );
		return array(
			'id'    => $post_id,
			'edit'  => get_edit_post_link( $post_id, 'raw' ),
			'view'  => get_preview_post_link( $post_id ),
			'notes' => $notes,
		);
	}

	// The template first: Blog Hubs keys every field by it.
	update_post_meta( $post_id, '_wp_page_template', $plan['template'] );

	// The hub's written content, from its own spreadsheet. The row's settings,
	// SEO and photo below go on top, so the builder sheet has the last word.
	$sheets = hw_builder_content_sheets( $row );
	if ( $sheets && function_exists( 'hw_hub_import_apply' ) ) {
		$parsed  = hw_hub_import_parse( $sheets, $plan['name'] );
		$written = $parsed['values'] ? hw_hub_import_apply( $post_id, $parsed['values'] ) : 0;
		$notes[] = $written ? $written . ' content field' . ( 1 === $written ? '' : 's' ) . ' imported from ' . $row['content_name'] . '.' : 'Nothing imported from ' . $row['content_name'] . ': it doesn\'t match this hub type.';
	}

	$fields = function_exists( 'hw_hub_schema_fields' ) ? hw_hub_schema_fields( $plan['name'] ) : array();
	$values = array(
		'hub_term'         => $plan['tag'],
		'city_slug'        => sanitize_title( $row['hw_city'] ),
		'country_slug'     => sanitize_title( $row['hw_country'] ),
		'events_country'   => 'city' === $plan['name'] ? '' : sanitize_title( $row['hw_country'] ),
		'parent_hub'       => $plan['parent_path'],
		'currency'         => strtoupper( $row['currency'] ),
		'hostels_currency' => strtoupper( $row['currency'] ),
	);

	foreach ( $values as $key => $value ) {
		if ( '' !== $value && isset( $fields[ $key ] ) ) {
			update_post_meta( $post_id, hw_hub_meta_key( $key, $post_id ), $value );
		}
	}

	// Yoast.
	if ( '' !== $row['seo_title'] ) {
		update_post_meta( $post_id, '_yoast_wpseo_title', sanitize_text_field( $row['seo_title'] ) );
	}
	if ( '' !== $row['meta'] ) {
		update_post_meta( $post_id, '_yoast_wpseo_metadesc', sanitize_text_field( $row['meta'] ) );
	}

	// Hero photo.
	if ( $plan['photo'] ) {
		$attachment = hw_builder_sideload( $plan['photo'], $post_id, $row );
		if ( is_wp_error( $attachment ) ) {
			$notes[] = 'Page created, but the photo failed: ' . $attachment->get_error_message();
		} else {
			if ( isset( $fields['hero_image'] ) ) {
				update_post_meta( $post_id, hw_hub_meta_key( 'hero_image', $post_id ), (string) $attachment );
			}
			set_post_thumbnail( $post_id, $attachment );
		}
	}

	if ( function_exists( 'hw_destbox_flush' ) ) {
		hw_destbox_flush( $post_id );
	}

	return array(
		'id'    => $post_id,
		'edit'  => get_edit_post_link( $post_id, 'raw' ),
		'view'  => get_preview_post_link( $post_id ),
		'notes' => $notes,
	);
}

/**
 * SEO and photo for a page created without a hub template.
 *
 * @param int   $post_id Page.
 * @param array $row     Row.
 * @param array $plan    Plan.
 * @param array $notes   Notes, added to.
 */
function hw_builder_finish_plain( $post_id, $row, $plan, &$notes ) {
	if ( '' !== $row['seo_title'] ) {
		update_post_meta( $post_id, '_yoast_wpseo_title', sanitize_text_field( $row['seo_title'] ) );
	}
	if ( '' !== $row['meta'] ) {
		update_post_meta( $post_id, '_yoast_wpseo_metadesc', sanitize_text_field( $row['meta'] ) );
	}
	if ( $plan['photo'] ) {
		$attachment = hw_builder_sideload( $plan['photo'], $post_id, $row );
		if ( is_wp_error( $attachment ) ) {
			$notes[] = 'The photo failed: ' . $attachment->get_error_message();
		} else {
			set_post_thumbnail( $post_id, $attachment );
		}
	}
}

/**
 * Downloads a photo into the Media Library with its credit and alt text.
 *
 * @param string $url     Image address.
 * @param int    $post_id Page it belongs to.
 * @param array  $row     Row.
 * @return int|WP_Error Attachment id.
 */
function hw_builder_sideload( $url, $post_id, $row ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$tmp = download_url( $url, 60 );

	if ( is_wp_error( $tmp ) ) {
		return $tmp;
	}

	$id   = hw_builder_pexels_id( $row['photo'] );
	$name = sanitize_title( $row['title'] ) . ( $id ? '-pexels-' . $id : '' ) . '.jpg';

	$attachment = media_handle_sideload(
		array(
			'name'     => $name,
			'tmp_name' => $tmp,
		),
		$post_id,
		$row['photo_alt'] ? $row['photo_alt'] : $row['title']
	);

	if ( is_wp_error( $attachment ) ) {
		wp_delete_file( $tmp );
		return $attachment;
	}

	if ( '' !== $row['photo_alt'] ) {
		update_post_meta( $attachment, '_wp_attachment_image_alt', sanitize_text_field( $row['photo_alt'] ) );
	}

	if ( '' !== $row['photographer'] ) {
		$source = $id ? 'Pexels' : (string) wp_parse_url( $row['photo'], PHP_URL_HOST );
		$link   = $id ? 'https://www.pexels.com/photo/' . $id . '/' : $row['photo'];

		wp_update_post(
			array(
				'ID'           => $attachment,
				'post_excerpt' => sprintf( 'Photo by %s on <a href="%s" rel="noopener">%s</a>', esc_html( $row['photographer'] ), esc_url( $link ), esc_html( $source ) ),
			)
		);
		update_post_meta( $attachment, '_hw_photo_credit', sanitize_text_field( $row['photographer'] ) );
		update_post_meta( $attachment, '_hw_photo_source', esc_url_raw( $link ) );
	}

	return $attachment;
}

/* ---------- Screens -------------------------------------------------------- */

add_action(
	'admin_menu',
	function () {
		add_menu_page( 'Hub builder', 'Hub builder', 'edit_pages', 'hw-hub-builder', 'hw_builder_screen', 'dashicons-admin-multisite', 27 );
	}
);

/**
 * The batch being worked on, per user.
 *
 * @return string
 */
function hw_builder_key() {
	return 'hw_builder_' . get_current_user_id();
}

/**
 * Upload: read, check, keep for the preview.
 */
function hw_builder_handle_upload() {
	if ( ! current_user_can( 'edit_pages' ) ) {
		wp_die( 'You do not have permission to do this.' );
	}

	check_admin_referer( 'hw_builder_upload' );

	$back = admin_url( 'admin.php?page=hw-hub-builder' );

	if ( empty( $_FILES['sheet']['tmp_name'] ) || ! is_uploaded_file( $_FILES['sheet']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		set_transient( hw_builder_key() . '_error', 'Choose a spreadsheet first.', 300 );
		wp_safe_redirect( $back );
		exit;
	}

	$rows = hw_builder_read( $_FILES['sheet']['tmp_name'], sanitize_file_name( wp_unslash( $_FILES['sheet']['name'] ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

	if ( is_wp_error( $rows ) ) {
		set_transient( hw_builder_key() . '_error', $rows->get_error_message(), 300 );
		wp_safe_redirect( $back );
		exit;
	}

	set_transient(
		hw_builder_key(),
		array(
			'file'  => sanitize_file_name( wp_unslash( $_FILES['sheet']['name'] ) ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			'batch' => gmdate( 'Ymd-His' ),
			'rows'  => hw_builder_check( $rows ),
		),
		DAY_IN_SECONDS
	);

	wp_safe_redirect( $back );
	exit;
}
add_action( 'admin_post_hw_builder_upload', 'hw_builder_handle_upload' );

/**
 * Starting over clears the preview.
 */
add_action(
	'admin_post_hw_builder_reset',
	function () {
		check_admin_referer( 'hw_builder_reset' );
		$state = get_transient( hw_builder_key() );
		foreach ( $state ? $state['rows'] : array() as $row ) {
			if ( ! empty( $row['content_key'] ) ) {
				delete_transient( $row['content_key'] );
			}
		}
		delete_transient( hw_builder_key() );
		wp_safe_redirect( admin_url( 'admin.php?page=hw-hub-builder' ) );
		exit;
	}
);

/**
 * Creates one row, called by the screen row by row so a big batch never runs
 * into the server's time limit and progress shows as it goes.
 */
function hw_builder_ajax_create() {
	check_ajax_referer( 'hw_builder_create', 'nonce' );

	if ( ! current_user_can( 'edit_pages' ) ) {
		wp_send_json_error( array( 'message' => 'You do not have permission to do this.' ), 403 );
	}

	$state = get_transient( hw_builder_key() );
	$index = isset( $_POST['index'] ) ? absint( $_POST['index'] ) : -1;

	if ( ! $state || ! isset( $state['rows'][ $index ] ) ) {
		wp_send_json_error( array( 'message' => 'This preview has expired. Upload the spreadsheet again.' ) );
	}

	$row = $state['rows'][ $index ];

	if ( ! hw_builder_can_go( $row, $state ) && empty( $row['result']['id'] ) ) {
		wp_send_json_error( array( 'message' => 'Skipped: ' . implode( ' ', $row['errors'] ) ) );
	}

	if ( ! empty( $row['result']['id'] ) ) {
		wp_send_json_success( $row['result'] ); // Already done.
	}

	$result = hw_builder_create( $row, $state['batch'] );

	if ( is_wp_error( $result ) ) {
		$state['rows'][ $index ]['result'] = array( 'error' => $result->get_error_message() );
		set_transient( hw_builder_key(), $state, DAY_IN_SECONDS );
		wp_send_json_error( array( 'message' => $result->get_error_message() ) );
	}

	$state['rows'][ $index ]['result'] = $result;
	set_transient( hw_builder_key(), $state, DAY_IN_SECONDS );

	wp_send_json_success( $result );
}
add_action( 'wp_ajax_hw_builder_create', 'hw_builder_ajax_create' );

/**
 * Undo: moves a batch's pages to the bin (recoverable from Pages → Bin).
 * Only pages still in draft are moved, so nothing published is touched.
 */
function hw_builder_handle_undo() {
	check_admin_referer( 'hw_builder_undo' );

	if ( ! current_user_can( 'delete_pages' ) ) {
		wp_die( 'You do not have permission to do this.' );
	}

	$batch = isset( $_POST['batch'] ) ? sanitize_text_field( wp_unslash( $_POST['batch'] ) ) : '';
	$moved = 0;
	$kept  = 0;

	$ids = get_posts(
		array(
			'post_type'      => 'page',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_key'       => '_hw_builder_batch', // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value'     => $batch, // phpcs:ignore WordPress.DB.SlowDBQuery
			'lang'           => '',
		)
	);

	// Children first, so no page is binned while a page still sits under it.
	usort(
		$ids,
		function ( $a, $b ) {
			return count( get_post_ancestors( $b ) ) - count( get_post_ancestors( $a ) );
		}
	);

	foreach ( $ids as $id ) {
		if ( 'draft' === get_post_status( $id ) ) {
			wp_trash_post( $id );
			++$moved;
		} else {
			++$kept;
		}
	}

	set_transient( hw_builder_key() . '_notice', $moved . ' draft page' . ( 1 === $moved ? '' : 's' ) . ' moved to the bin (Pages → Bin to restore).' . ( $kept ? ' ' . $kept . ' already published or private, so left alone.' : '' ), 300 );
	wp_safe_redirect( admin_url( 'admin.php?page=hw-hub-builder' ) );
	exit;
}
add_action( 'admin_post_hw_builder_undo', 'hw_builder_handle_undo' );

add_action(
	'admin_enqueue_scripts',
	function ( $hook ) {
		if ( 'toplevel_page_hw-hub-builder' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'hw-builder', HW_BUILDER_URL . 'assets/builder.css', array(), HW_BUILDER_VERSION );
		wp_enqueue_script( 'hw-builder', HW_BUILDER_URL . 'assets/builder.js', array( 'jquery' ), HW_BUILDER_VERSION, true );
		wp_localize_script(
			'hw-builder',
			'hwBuilder',
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'hw_builder_create' ),
			)
		);
	}
);

/**
 * The screen: upload, then preview and create.
 */
function hw_builder_screen() {
	echo '<div class="wrap hw-builder">';
	echo '<h1>Hub builder</h1>';

	if ( ! hw_builder_ready() ) {
		echo '<div class="notice notice-error"><p>Activate the Hostelworld Blog Hubs plugin first.</p></div></div>';
		return;
	}

	foreach ( array( '_error' => 'notice-error', '_notice' => 'notice-success' ) as $suffix => $class ) {
		$msg = get_transient( hw_builder_key() . $suffix );
		if ( $msg ) {
			delete_transient( hw_builder_key() . $suffix );
			printf( '<div class="notice %s"><p>%s</p></div>', esc_attr( $class ), esc_html( $msg ) );
		}
	}

	$state = get_transient( hw_builder_key() );

	if ( ! $state ) {
		hw_builder_screen_upload();
	} else {
		hw_builder_screen_preview( $state );
	}

	hw_builder_screen_batches();

	echo '</div>';
}

/**
 * Step 1.
 */
function hw_builder_screen_upload() {
	?>
	<div class="hw-builder__card">
		<h2>1. Upload the spreadsheet</h2>
		<p>One row per page. Every page is created as a <strong>draft</strong>, with its template, parent page, address, hub settings, Yoast SEO title and meta description, hero photo (with credit and alt text) and, if you attach it, its content spreadsheet. Nothing that already exists is changed.</p>
		<p>Next you get a preview where you can fix any row, add or remove rows and attach each hub's content spreadsheet, before anything is created.</p>
		<p><a class="button" href="<?php echo esc_url( HW_BUILDER_URL . 'assets/hub-builder-template.xlsx' ); ?>">Download the template spreadsheet</a></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
			<?php wp_nonce_field( 'hw_builder_upload' ); ?>
			<input type="hidden" name="action" value="hw_builder_upload">
			<p><input type="file" name="sheet" accept=".xlsx,.csv" required></p>
			<p><button class="button button-primary">Check the spreadsheet</button></p>
		</form>
	</div>
	<?php
}

/**
 * Step 2: the editable preview. The table itself is drawn by
 * hw_builder_table(), which the edit, attach and remove actions call again
 * to redraw it after every change.
 *
 * @param array $state Batch.
 */
function hw_builder_screen_preview( $state ) {
	?>
	<div class="hw-builder__card">
		<h2>2. Check, fix, then create</h2>
		<p>Rows with red problems are skipped unless you tick <strong>Create anyway</strong>: then they're created as drafts with the gaps left for you to fill in on the page (no parent puts the page at the top level, no template makes a plain page). A page address that's already taken is the one thing it never gets past.</p>
		<p>Fix anything right here: <strong>Edit</strong> opens the row's fields, and every change is checked again straight away. Attach each hub's <strong>content spreadsheet</strong> in the same place, and it is imported when the page is created.</p>
		<div class="hw-builder__summary"><?php echo hw_builder_summary( $state ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
		<p class="hw-builder__actions">
			<button type="button" class="button button-primary hw-builder-go">Create the draft pages</button>
			<button type="button" class="button hw-builder-add">Add a row</button>
			<label class="hw-builder__force"><input type="checkbox" class="hw-builder-force" <?php checked( ! empty( $state['force'] ) ); ?>> Create anyway, with whatever is missing</label>
			<span class="hw-builder__progress" aria-live="polite"></span>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'hw_builder_reset' ); ?>
			<input type="hidden" name="action" value="hw_builder_reset">
			<button class="button-link">Start again with another file</button>
		</form>
	</div>

	<table class="widefat hw-builder__table">
		<thead>
			<tr><th>Row</th><th>Photo</th><th>Page</th><th>SEO</th><th>Checks</th><th>Status</th></tr>
		</thead>
		<tbody class="hw-builder__rows"><?php echo hw_builder_table( $state ); // phpcs:ignore WordPress.Security.EscapeOutput ?></tbody>
	</table>
	<?php
}

/**
 * The counts above the table.
 *
 * @param array $state Batch.
 * @return string
 */
function hw_builder_summary( $state ) {
	$rows   = $state['rows'];
	$ready  = count( array_filter( $rows, function ( $r ) use ( $state ) { return hw_builder_can_go( $r, $state ); } ) );
	$done   = count( array_filter( $rows, function ( $r ) { return ! empty( $r['result']['id'] ); } ) );
	$errors = count( array_filter( $rows, function ( $r ) use ( $state ) { return $r['errors'] && ! hw_builder_can_go( $r, $state ) && empty( $r['result']['id'] ); } ) );
	$gaps   = count( array_filter( $rows, function ( $r ) use ( $state ) { return $r['errors'] && hw_builder_can_go( $r, $state ); } ) );
	$warns  = count( array_filter( $rows, function ( $r ) { return ! $r['errors'] && $r['warnings'] && empty( $r['result']['id'] ); } ) );

	$out  = '<strong>' . esc_html( $state['file'] ) . '</strong> · ' . count( $rows ) . ' row' . ( 1 === count( $rows ) ? '' : 's' );
	$out .= ' · <span class="hw-ok" data-ready="' . (int) $ready . '">' . $ready . ' ready to create</span>';
	if ( $warns ) {
		$out .= ' (<span class="hw-warn">' . $warns . ' with warnings worth a look</span>)';
	}
	if ( $gaps ) {
		$out .= ' · <span class="hw-warn">' . $gaps . ' of them with gaps</span>';
	}
	if ( $errors ) {
		$out .= ' · <span class="hw-err">' . $errors . ' to fix, or ' . ( 1 === $errors ? 'it is' : 'they are' ) . ' skipped</span>';
	}
	if ( $done ) {
		$out .= ' · ' . $done . ' created';
	}

	return $out;
}

/**
 * The table rows: a summary row and, under it, its edit form.
 *
 * @param array $state Batch.
 * @return string
 */
function hw_builder_table( $state ) {
	ob_start();

	foreach ( $state['rows'] as $i => $row ) {
		$plan    = $row['plan'];
		$done    = ! empty( $row['result']['id'] );
		$go      = hw_builder_can_go( $row, $state );
		$status  = $done ? 'done' : ( $row['errors'] ? ( $go ? 'gap' : 'err' ) : ( $row['warnings'] ? 'warn' : 'ok' ) );
		$content = $plan['content'];
		?>
		<tr class="hw-row hw-row--<?php echo esc_attr( $status ); ?>" data-index="<?php echo esc_attr( $i ); ?>" data-depth="<?php echo esc_attr( substr_count( $plan['path'], '/' ) ); ?>" data-go="<?php echo esc_attr( $go ? 1 : 0 ); ?>">
			<td><?php echo esc_html( $row['line'] ? $row['line'] : '+' ); ?></td>
			<td class="hw-photo">
				<?php if ( $plan['photo_thumb'] ) : ?>
					<a href="<?php echo esc_url( $row['photo'] ); ?>" target="_blank" rel="noopener"><img src="<?php echo esc_url( $plan['photo_thumb'] ); ?>" alt="" loading="lazy"></a>
					<small><?php echo esc_html( $row['photographer'] ); ?></small>
				<?php else : ?>
					<span class="hw-nophoto">No photo</span>
				<?php endif; ?>
			</td>
			<td>
				<strong><?php echo esc_html( '' !== $row['title'] ? $row['title'] : '(no title)' ); ?></strong>
				<div class="hw-muted"><?php echo esc_html( $plan['label'] ? $plan['label'] : 'No template' ); ?> · /<?php echo esc_html( $plan['path'] ); ?>/</div>
				<?php if ( $plan['tag'] ) : ?><div class="hw-muted">Tag: <?php echo esc_html( $plan['tag'] ); ?></div><?php endif; ?>
				<?php if ( $plan['hw_found'] ) : ?><div class="hw-muted">Hostelworld: <?php echo esc_html( $plan['hw_found'] ); ?></div><?php endif; ?>
				<div class="hw-muted hw-content">
					<?php if ( $content ) : ?>
						Content: <?php echo esc_html( $content['file'] ); ?> · <?php echo esc_html( $content['fields'] ); ?> fields
					<?php elseif ( ! $done ) : ?>
						No content spreadsheet yet
					<?php endif; ?>
				</div>
			</td>
			<td class="hw-seo">
				<?php if ( $row['seo_title'] ) : ?><div class="hw-seo__title"><?php echo esc_html( $row['seo_title'] ); ?></div><?php endif; ?>
				<?php if ( $row['meta'] ) : ?><div class="hw-seo__meta"><?php echo esc_html( $row['meta'] ); ?></div><?php endif; ?>
			</td>
			<td class="hw-checks">
				<?php foreach ( $row['errors'] as $msg ) : ?><div class="hw-err">✕ <?php echo esc_html( $msg ); ?></div><?php endforeach; ?>
				<?php foreach ( $row['warnings'] as $msg ) : ?><div class="hw-warn">! <?php echo esc_html( $msg ); ?></div><?php endforeach; ?>
				<?php if ( ! $row['errors'] && ! $row['warnings'] && ! $done ) : ?><div class="hw-ok">✓ All good</div><?php endif; ?>
			</td>
			<td class="hw-status">
				<?php if ( $done ) : ?>
					<span class="hw-ok">Created</span><br><a href="<?php echo esc_url( $row['result']['edit'] ); ?>" target="_blank">Edit page</a>
					<?php foreach ( (array) $row['result']['notes'] as $note ) : ?><div class="hw-muted"><?php echo esc_html( $note ); ?></div><?php endforeach; ?>
				<?php else : ?>
					<?php if ( ! empty( $row['result']['error'] ) ) : ?><div class="hw-err"><?php echo esc_html( $row['result']['error'] ); ?></div><?php endif; ?>
					<?php if ( $row['errors'] && $go ) : ?>
						<span class="hw-warn">Will be created with gaps</span><br>
					<?php else : ?>
						<span class="<?php echo $row['errors'] ? 'hw-err' : 'hw-muted'; ?>"><?php echo $row['errors'] ? ( empty( $row['hard'] ) ? 'Needs fixing (or tick Create anyway)' : 'Needs fixing' ) : 'Ready'; ?></span><br>
					<?php endif; ?>
					<button type="button" class="button button-small hw-builder-edit">Edit</button>
					<button type="button" class="button-link hw-builder-remove">Remove</button>
				<?php endif; ?>
			</td>
		</tr>
		<?php if ( ! $done ) : ?>
			<tr class="hw-edit" data-index="<?php echo esc_attr( $i ); ?>" hidden>
				<td colspan="6"><?php echo hw_builder_edit_form( $row ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
			</tr>
		<?php endif; ?>
		<?php
	}

	return (string) ob_get_clean();
}

/**
 * The edit form for one row (also used, empty, for "Add a row").
 *
 * @param array $row Row.
 * @return string
 */
function hw_builder_edit_form( $row ) {
	$row    = hw_builder_row_defaults( $row );
	$labels = hw_builder_columns();
	$help   = array(
		'slug'       => 'Optional. Made from the title when empty.',
		'parent'     => 'Path of the page it sits under, e.g. europe/italy.',
		'tag'        => 'The tag articles about this place carry.',
		'hw_city'    => 'As in a Hostelworld web address.',
		'hw_country' => 'As in a Hostelworld web address.',
		'currency'   => 'EUR, GBP, USD…',
		'photo'      => 'Pexels photo page or image address.',
	);
	$types  = array(
		'City'                => 'City',
		'Country'             => 'Country',
		'Country with states' => 'Country with states',
		'State'               => 'State',
		'Continent'           => 'Continent',
	);
	$typed  = hw_builder_templates();
	$now    = isset( $typed[ hw_builder_norm( $row['template'] ) ] ) ? $typed[ hw_builder_norm( $row['template'] ) ][1] : '';
	$names  = array( 'city' => 'City', 'country' => 'Country', 'country_states' => 'Country with states', 'state' => 'State', 'continent' => 'Continent' );

	ob_start();
	?>
	<div class="hw-form">
		<div class="hw-form__grid">
			<label>Template
				<select data-field="template">
					<option value="">Choose…</option>
					<?php foreach ( $types as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $now && $names[ $now ] === $value ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<?php foreach ( array( 'title', 'slug', 'parent', 'tag', 'hw_city', 'hw_country', 'currency' ) as $key ) : ?>
				<label><?php echo esc_html( $labels[ $key ] ); ?>
					<input type="text" data-field="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $row[ $key ] ); ?>">
					<?php if ( isset( $help[ $key ] ) ) : ?><small><?php echo esc_html( $help[ $key ] ); ?></small><?php endif; ?>
				</label>
			<?php endforeach; ?>
		</div>
		<div class="hw-form__grid hw-form__grid--wide">
			<label>SEO title <span class="hw-count" data-max="60"></span>
				<input type="text" data-field="seo_title" value="<?php echo esc_attr( $row['seo_title'] ); ?>">
			</label>
			<label>Meta description <span class="hw-count" data-max="155"></span>
				<textarea rows="2" data-field="meta"><?php echo esc_textarea( $row['meta'] ); ?></textarea>
			</label>
		</div>
		<div class="hw-form__grid">
			<?php foreach ( array( 'photo', 'photographer', 'photo_alt' ) as $key ) : ?>
				<label><?php echo esc_html( $labels[ $key ] ); ?>
					<input type="text" data-field="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $row[ $key ] ); ?>">
					<?php if ( isset( $help[ $key ] ) ) : ?><small><?php echo esc_html( $help[ $key ] ); ?></small><?php endif; ?>
				</label>
			<?php endforeach; ?>
			<label>Content spreadsheet
				<input type="file" accept=".xlsx" data-field="content">
				<small>
					<?php if ( $row['content_name'] ) : ?>
						Attached: <?php echo esc_html( $row['content_name'] ); ?>. Choose another file to replace it. <button type="button" class="button-link hw-builder-detach">Remove it</button>
					<?php else : ?>
						The hub's content file, the one you'd use with Import spreadsheet on the page.
					<?php endif; ?>
				</small>
			</label>
		</div>
		<p>
			<button type="button" class="button button-primary hw-builder-save">Save and check again</button>
			<button type="button" class="button-link hw-builder-cancel">Cancel</button>
			<span class="hw-form__msg"></span>
		</p>
	</div>
	<?php
	return (string) ob_get_clean();
}

/**
 * Edits, adds, removes or attaches to a row, checks the whole batch again
 * (a fixed parent can unblock the rows under it) and sends back the table.
 */
function hw_builder_ajax_row() {
	check_ajax_referer( 'hw_builder_create', 'nonce' );

	if ( ! current_user_can( 'edit_pages' ) ) {
		wp_send_json_error( array( 'message' => 'You do not have permission to do this.' ), 403 );
	}

	$state = get_transient( hw_builder_key() );

	if ( ! $state ) {
		wp_send_json_error( array( 'message' => 'This preview has expired. Upload the spreadsheet again.' ) );
	}

	$op    = isset( $_POST['op'] ) ? sanitize_key( $_POST['op'] ) : '';
	$index = isset( $_POST['index'] ) && 'new' !== $_POST['index'] ? absint( $_POST['index'] ) : -1;

	if ( $index >= 0 && ( ! isset( $state['rows'][ $index ] ) || ! empty( $state['rows'][ $index ]['result']['id'] ) ) ) {
		wp_send_json_error( array( 'message' => 'That row can\'t be changed (it may already be created).' ) );
	}

	if ( 'force' === $op ) {
		$state['force'] = ! empty( $_POST['on'] );
	} elseif ( 'remove' === $op && $index >= 0 ) {
		array_splice( $state['rows'], $index, 1 );
	} elseif ( 'save' === $op ) {
		$row    = $index >= 0 ? $state['rows'][ $index ] : hw_builder_row_defaults( array() );
		$fields = isset( $_POST['fields'] ) && is_array( $_POST['fields'] ) ? wp_unslash( $_POST['fields'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		foreach ( array_keys( hw_builder_columns() ) as $key ) {
			if ( isset( $fields[ $key ] ) ) {
				$row[ $key ] = 'meta' === $key ? sanitize_textarea_field( $fields[ $key ] ) : sanitize_text_field( $fields[ $key ] );
			}
		}

		if ( ! empty( $_POST['detach'] ) ) {
			delete_transient( $row['content_key'] );
			$row['content_key']  = '';
			$row['content_name'] = '';
		}

		// The content spreadsheet, read now and kept with the row.
		if ( ! empty( $_FILES['content']['tmp_name'] ) && UPLOAD_ERR_OK === (int) $_FILES['content']['error'] ) {
			$name = sanitize_file_name( wp_unslash( $_FILES['content']['name'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

			if ( 'xlsx' !== strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) ) {
				wp_send_json_error( array( 'message' => 'The content spreadsheet has to be an .xlsx file.' ) );
			}

			$sheets = hw_hub_xlsx_read( $_FILES['content']['tmp_name'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

			if ( is_wp_error( $sheets ) ) {
				wp_send_json_error( array( 'message' => $sheets->get_error_message() ) );
			}

			// Kept on its own, so a batch of big content files never makes
			// the preview one huge database entry.
			if ( $row['content_key'] ) {
				delete_transient( $row['content_key'] );
			}
			$row['content_key']  = 'hw_builder_c_' . get_current_user_id() . '_' . wp_generate_password( 10, false );
			$row['content_name'] = $name;
			set_transient( $row['content_key'], $sheets, DAY_IN_SECONDS );
		}

		if ( $index >= 0 ) {
			$state['rows'][ $index ] = $row;
		} else {
			$state['rows'][] = $row;
		}
	}

	$state['rows'] = hw_builder_check( array_values( $state['rows'] ) );
	set_transient( hw_builder_key(), $state, DAY_IN_SECONDS );

	wp_send_json_success(
		array(
			'rows'    => hw_builder_table( $state ),
			'summary' => hw_builder_summary( $state ),
		)
	);
}
add_action( 'wp_ajax_hw_builder_row', 'hw_builder_ajax_row' );

/**
 * The empty form for "Add a row".
 */
add_action(
	'wp_ajax_hw_builder_blank',
	function () {
		check_ajax_referer( 'hw_builder_create', 'nonce' );
		wp_send_json_success( array( 'form' => hw_builder_edit_form( array() ) ) );
	}
);

/**
 * Past batches, each with a one-click undo.
 */
function hw_builder_screen_batches() {
	global $wpdb;

	$batches = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		"SELECT m.meta_value AS batch, COUNT(*) AS pages, SUM(p.post_status = 'draft') AS drafts
		 FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id
		 WHERE m.meta_key = '_hw_builder_batch' AND p.post_status <> 'trash'
		 GROUP BY m.meta_value ORDER BY m.meta_value DESC LIMIT 10"
	);

	if ( ! $batches ) {
		return;
	}
	?>
	<div class="hw-builder__card">
		<h2>Batches created</h2>
		<table class="widefat striped">
			<thead><tr><th>Created</th><th>Pages</th><th>Still drafts</th><th></th></tr></thead>
			<tbody>
			<?php foreach ( $batches as $b ) : ?>
				<?php $when = DateTime::createFromFormat( 'Ymd-His', $b->batch, new DateTimeZone( 'UTC' ) ); ?>
				<tr>
					<td><?php echo esc_html( $when ? wp_date( 'j M Y, H:i', $when->getTimestamp() ) : $b->batch ); ?></td>
					<td><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=page&hw_builder_batch=' . rawurlencode( $b->batch ) ) ); ?>"><?php echo esc_html( $b->pages ); ?></a></td>
					<td><?php echo esc_html( (int) $b->drafts ); ?></td>
					<td>
						<?php if ( (int) $b->drafts ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Move this batch\'s draft pages to the bin? You can restore them from Pages → Bin.');">
							<?php wp_nonce_field( 'hw_builder_undo' ); ?>
							<input type="hidden" name="action" value="hw_builder_undo">
							<input type="hidden" name="batch" value="<?php echo esc_attr( $b->batch ); ?>">
							<button class="button-link hw-err">Undo: move its drafts to the bin</button>
						</form>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php
}

/**
 * Pages → filtered to one batch, from the link in the batches table.
 */
add_action(
	'pre_get_posts',
	function ( $query ) {
		if ( is_admin() && $query->is_main_query() && ! empty( $_GET['hw_builder_batch'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$query->set( 'meta_key', '_hw_builder_batch' );
			$query->set( 'meta_value', sanitize_text_field( wp_unslash( $_GET['hw_builder_batch'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		}
	}
);
