<?php
/**
 * Plugin Name: AAV Dev Toolkit
 * Description: Boite a outils d'administration pour le site AAV, sans acces FTP :
 *              diagnostic du site, export theme/extensions, limites PHP, mu-plugins,
 *              remplacement, lecture et suppression de fichiers dans wp-content,
 *              purge des caches, normalisation des blocs AAV. Reserve aux administrateurs.
 * Version:     1.7.0
 * Author:      Biolay Group
 * Update URI:  https://github.com/biolay-group/aav-dev-toolkit
 * License:     GPL-2.0-or-later
 *
 * AVERTISSEMENT SECURITE
 * Cet outil depose du code executable et modifie des fichiers. Toutes les actions
 * exigent le droit "manage_options" et un jeton (nonce). Sur un site en production,
 * installe-le le temps de ton intervention, puis desactive-le ou retire-le.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AAV_DT_VERSION', '1.7.0' );
define( 'AAV_DT_CAP', 'manage_options' );
define( 'AAV_DT_SLUG', 'aav-devtools' );
define( 'AAV_DT_FILE', plugin_basename( __FILE__ ) );
define( 'AAV_DT_REPO', 'biolay-group/aav-dev-toolkit' );

/* Onglets de la page (cle => libelle). */
function aav_dt_tabs() {
	return array(
		'diag'   => 'Diagnostic',
		'export' => 'Export',
		'files'  => 'Fichiers',
		'mu'     => 'Mu-plugins',
		'php'    => 'Limites PHP',
		'tools'  => 'Maintenance',
	);
}

add_action( 'admin_menu', function () {
	add_management_page( 'AAV Dev Toolkit', 'AAV Dev Toolkit', AAV_DT_CAP, AAV_DT_SLUG, 'aav_dt_render_page' );
} );

/* =========================================================================
 *  HELPERS
 * ====================================================================== */

function aav_dt_guard( $nonce_action ) {
	if ( ! current_user_can( AAV_DT_CAP ) ) {
		wp_die( 'Acces refuse.', '', array( 'response' => 403 ) );
	}
	check_admin_referer( $nonce_action );
}

/** Retour a la page, en conservant l'onglet actif. */
function aav_dt_redirect( $status, $tab = '' ) {
	$args = array( 'page' => AAV_DT_SLUG, 'aav_status' => rawurlencode( $status ) );
	if ( $tab ) {
		$args['tab'] = $tab;
	}
	wp_safe_redirect( add_query_arg( $args, admin_url( 'tools.php' ) ) );
	exit;
}

function aav_dt_url( $tab = '', $args = array() ) {
	$base = array( 'page' => AAV_DT_SLUG );
	if ( $tab ) {
		$base['tab'] = $tab;
	}
	return add_query_arg( array_merge( $base, $args ), admin_url( 'tools.php' ) );
}

function aav_dt_clean_rel( $raw ) {
	$rel = ltrim( str_replace( '\\', '/', trim( (string) $raw ) ), '/' );
	if ( '' === $rel || false !== strpos( $rel, '..' ) ) {
		return '';
	}
	return $rel;
}

function aav_dt_resolve_in_content( $rel ) {
	$base   = realpath( WP_CONTENT_DIR );
	$target = realpath( WP_CONTENT_DIR . '/' . $rel );
	if ( ! $base || ! $target || 0 !== strpos( $target, $base ) ) {
		return '';
	}
	return $target;
}

function aav_dt_put_block( $file, $block, $begin, $end ) {
	$existing = file_exists( $file ) ? (string) @file_get_contents( $file ) : '';
	$pattern  = '/' . preg_quote( $begin, '/' ) . '.*?' . preg_quote( $end, '/' ) . '\R?/s';
	if ( preg_match( $pattern, $existing ) ) {
		$new = preg_replace( $pattern, rtrim( $block ) . "\n", $existing );
	} else {
		$new = ltrim( rtrim( $existing ) . "\n\n" . $block );
	}
	return (bool) @file_put_contents( $file, $new );
}

function aav_dt_stream_file( $path, $filename ) {
	nocache_headers();
	header( 'Content-Type: application/zip' );
	header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
	header( 'Content-Length: ' . filesize( $path ) );
	readfile( $path );
	@unlink( $path );
	exit;
}

function aav_dt_stream_zip_dir( $dir, $name ) {
	if ( ! class_exists( 'ZipArchive' ) ) {
		aav_dt_redirect( 'nozip', 'export' );
	}
	@set_time_limit( 300 );

	$tmp = wp_tempnam( $name . '.zip' );
	$zip = new ZipArchive();
	if ( true !== $zip->open( $tmp, ZipArchive::OVERWRITE ) ) {
		aav_dt_redirect( 'zip_err', 'export' );
	}

	$exclude = array( 'node_modules', '.git', '.svn', '.DS_Store', '.cache', '.idea' );
	$filter  = new RecursiveCallbackFilterIterator(
		new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
		function ( $current ) use ( $exclude ) {
			return ! in_array( $current->getFilename(), $exclude, true );
		}
	);
	$items = new RecursiveIteratorIterator( $filter, RecursiveIteratorIterator::SELF_FIRST );

	foreach ( $items as $item ) {
		$path  = $item->getPathname();
		$local = $name . '/' . ltrim( substr( $path, strlen( $dir ) ), '/\\' );
		if ( $item->isDir() ) {
			$zip->addEmptyDir( $local );
		} else {
			$zip->addFile( $path, $local );
		}
	}
	$zip->close();

	aav_dt_stream_file( $tmp, $name . '-' . gmdate( 'Ymd-His' ) . '.zip' );
}

/** Sauvegardes .bak laissees dans les extensions et les themes. */
function aav_dt_find_baks() {
	$found = array();
	foreach ( array( WP_PLUGIN_DIR, get_theme_root() ) as $root ) {
		if ( ! is_dir( $root ) ) {
			continue;
		}
		try {
			$it = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::SELF_FIRST
			);
			foreach ( $it as $f ) {
				if ( $f->isFile() && preg_match( '/\.bak(-[0-9\-]+)?$/i', $f->getFilename() ) ) {
					$rel     = ltrim( str_replace( array( WP_CONTENT_DIR, '\\' ), array( '', '/' ), $f->getPathname() ), '/' );
					$found[] = array( 'rel' => $rel, 'size' => $f->getSize(), 'time' => $f->getMTime() );
				}
			}
		} catch ( Exception $e ) {
			continue;
		}
	}
	return $found;
}

/** Extensions de cache connues, actives sur le site. */
function aav_dt_cache_plugins() {
	$known = array(
		'litespeed-cache/litespeed-cache.php'   => 'LiteSpeed Cache',
		'wp-rocket/wp-rocket.php'               => 'WP Rocket',
		'w3-total-cache/w3-total-cache.php'     => 'W3 Total Cache',
		'wp-super-cache/wp-cache.php'           => 'WP Super Cache',
		'wp-fastest-cache/wpFastestCache.php'   => 'WP Fastest Cache',
		'autoptimize/autoptimize.php'           => 'Autoptimize',
		'sg-cachepress/sg-cachepress.php'       => 'SiteGround Optimizer',
		'breeze/breeze.php'                     => 'Breeze',
	);
	$active = (array) get_option( 'active_plugins', array() );
	$found  = array();
	foreach ( $known as $file => $label ) {
		if ( in_array( $file, $active, true ) ) {
			$found[ $file ] = $label;
		}
	}
	return $found;
}

/** Etat de sante du site, en une passe. */
function aav_dt_diagnostics() {
	global $wp_version, $wpdb;

	$theme   = wp_get_theme();
	$mu_dir  = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
	$mu      = is_dir( $mu_dir ) ? array_values( array_diff( scandir( $mu_dir ), array( '.', '..' ) ) ) : array();
	$actives = (array) get_option( 'active_plugins', array() );
	$locale  = get_option( 'WPLANG' );

	return array(
		'WordPress'          => $wp_version,
		'PHP'                => PHP_VERSION . ' (' . php_sapi_name() . ')',
		'MySQL'              => $wpdb->db_version(),
		'Theme actif'        => $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ),
		'Extensions actives' => count( $actives ),
		'Mu-plugins'         => $mu ? implode( ', ', $mu ) : 'aucun',
		'Langue du site'     => $locale ? $locale : 'en_US (defaut)',
		'Fuseau horaire'     => get_option( 'timezone_string' ) ?: get_option( 'gmt_offset' ) . 'h',
		'Limite memoire'     => ini_get( 'memory_limit' ) . ' (WP_MEMORY_LIMIT : ' . ( defined( 'WP_MEMORY_LIMIT' ) ? WP_MEMORY_LIMIT : 'n/d' ) . ')',
		'Upload max'         => ini_get( 'upload_max_filesize' ),
		'Temps d execution'  => ini_get( 'max_execution_time' ) . ' s',
		'OPcache'            => ( function_exists( 'opcache_get_status' ) && @opcache_get_status( false ) ) ? 'actif' : 'inactif',
		'WP_DEBUG'           => ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ? 'ACTIF' : 'inactif',
		'Cache objet'        => file_exists( WP_CONTENT_DIR . '/object-cache.php' ) ? 'present' : 'absent',
		'Cache de page'      => file_exists( WP_CONTENT_DIR . '/advanced-cache.php' ) ? 'present (advanced-cache.php)' : 'absent',
		'Extensions de cache'=> ( $c = aav_dt_cache_plugins() ) ? implode( ', ', $c ) : 'aucune detectee',
		'HTTPS'              => is_ssl() ? 'oui' : 'non',
	);
}

/** Contenu par defaut du mu-plugin : correctifs navigation Barba + smooth scroll. */
function aav_dt_default_mu() {
	return <<<'PHP'
<?php
/**
 * Plugin Name: AAV - Correctifs navigation Barba (scripts + smooth scroll)
 * Description: Re-execute les scripts du conteneur apres chaque transition Barba
 *              (detecteur d'insertion + verification continue auto-reparante),
 *              remet le scroll a zero, recalcule la hauteur apres injection de
 *              contenu, cache-bust du app.min.js patche.
 * Version:     1.4.0
 * Author:      Jean-Baptiste Biolay
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

add_action( 'wp_footer', function () {
	?>
<script>/*aav-mu-1.4*/
(function () {
  'use strict';

  function loco() {
    var i = window.__aavLoco || window.locoScroll || null;
    return (i && typeof i.update === 'function') ? i : null;
  }

  function toTop() {
    var l = loco();
    if (!l) { window.scrollTo(0, 0); return; }
    try { if (l.setScroll) l.setScroll(0, 0); } catch (e) {}
    try { l.update(); } catch (e) {}
  }

  /* Les <script> inseres par Barba sont inertes : on les rejoue une fois. */
  function execScripts(container) {
    if (!container || container.dataset.aavExec) return false;
    container.dataset.aavExec = '1';
    var scripts = container.querySelectorAll('script');
    for (var i = 0; i < scripts.length; i++) {
      var old = scripts[i];
      var type = (old.getAttribute('type') || '').toLowerCase();
      if (type && type !== 'text/javascript' && type !== 'module' && type !== 'application/javascript') continue;
      var s = document.createElement('script');
      for (var a = 0; a < old.attributes.length; a++) {
        s.setAttribute(old.attributes[a].name, old.attributes[a].value);
      }
      s.text = old.text;
      old.parentNode.replaceChild(s, old);
    }
    return true;
  }

  var raf, lastH = 0;
  function refresh() {
    var t = document.querySelector('[data-scroll-container]') || document.body;
    var h = t.scrollHeight;
    if (Math.abs(h - lastH) < 2) return;
    lastH = h;
    if (raf) cancelAnimationFrame(raf);
    raf = requestAnimationFrame(function () {
      var l = loco();
      if (l) l.update(); else window.dispatchEvent(new Event('resize'));
    });
  }
  var ro = ('ResizeObserver' in window) ? new ResizeObserver(refresh) : null;
  function observe() {
    if (!ro) return;
    ro.disconnect();
    ro.observe(document.querySelector('[data-scroll-container]') || document.body);
    lastH = 0; refresh();
  }

  function onNewContainer(c) {
    if (!execScripts(c)) return;
    setTimeout(function () { toTop(); observe(); }, 80);
    setTimeout(function () { toTop(); refresh(); }, 700);
    setTimeout(refresh, 1500);
  }

  window.addEventListener('load', function () {
    observe();
    var first = document.querySelector('[data-barba="container"]');
    if (first) first.dataset.aavExec = '1';
  });

  var wrapper = document.querySelector('[data-barba="wrapper"]') || document.body;
  if ('MutationObserver' in window) {
    new MutationObserver(function (muts) {
      for (var i = 0; i < muts.length; i++) {
        for (var j = 0; j < muts[i].addedNodes.length; j++) {
          var n = muts[i].addedNodes[j];
          if (n.nodeType !== 1) continue;
          var c = (n.matches && n.matches('[data-barba="container"]')) ? n
                : (n.querySelector ? n.querySelector('[data-barba="container"]') : null);
          if (c) { onNewContainer(c); return; }
        }
      }
    }).observe(wrapper, { childList: true, subtree: true });
  }

  /* Filet auto-reparant : rattrape tout conteneur non traite. */
  setInterval(function () {
    var c = document.querySelector('[data-barba="container"]:not([data-aav-exec])');
    if (c) onNewContainer(c);
  }, 500);

  if (!ro) {
    window.addEventListener('load', function () {
      var n = 0, id = setInterval(function () {
        window.dispatchEvent(new Event('resize'));
        if (++n >= 4) clearInterval(id);
      }, 800);
    });
  }
})();
</script>
	<?php
}, 99 );

/* Cache-bust : force le rechargement du app.min.js patche par tous les caches. */
add_filter( 'script_loader_src', function ( $src ) {
	if ( false !== strpos( $src, 'themes/aav/assets/app.min.js' ) ) {
		$src = add_query_arg( 'aavp', '2', $src );
	}
	return $src;
}, 10, 1 );
PHP;
}

/* =========================================================================
 *  ACTIONS
 * ====================================================================== */

/* Export : theme */
add_action( 'admin_post_aav_dt_download_theme', function () {
	aav_dt_guard( 'aav_dt_download_theme' );
	$dir = get_template_directory();
	aav_dt_stream_zip_dir( $dir, basename( $dir ) );
} );

/* Export : extension */
add_action( 'admin_post_aav_dt_download_plugin', function () {
	aav_dt_guard( 'aav_dt_download_plugin' );

	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$pf  = isset( $_POST['plugin_file'] ) ? wp_unslash( $_POST['plugin_file'] ) : '';
	$all = get_plugins();
	if ( ! isset( $all[ $pf ] ) ) {
		aav_dt_redirect( 'plg_notfound', 'export' );
	}

	$dirname = dirname( $pf );
	if ( '.' === $dirname ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			aav_dt_redirect( 'nozip', 'export' );
		}
		$name = preg_replace( '/\.php$/', '', basename( $pf ) );
		$tmp  = wp_tempnam( $name . '.zip' );
		$zip  = new ZipArchive();
		if ( true !== $zip->open( $tmp, ZipArchive::OVERWRITE ) ) {
			aav_dt_redirect( 'zip_err', 'export' );
		}
		$zip->addFile( WP_PLUGIN_DIR . '/' . $pf, basename( $pf ) );
		$zip->close();
		aav_dt_stream_file( $tmp, $name . '-' . gmdate( 'Ymd-His' ) . '.zip' );
	}

	aav_dt_stream_zip_dir( WP_PLUGIN_DIR . '/' . $dirname, $dirname );
} );

/* Limites PHP */
add_action( 'admin_post_aav_dt_save_ini', function () {
	aav_dt_guard( 'aav_dt_save_ini' );

	$keys  = array( 'upload_max_filesize', 'post_max_size', 'memory_limit', 'max_execution_time' );
	$lines = array();
	foreach ( $keys as $k ) {
		$v = isset( $_POST[ $k ] ) ? trim( sanitize_text_field( wp_unslash( $_POST[ $k ] ) ) ) : '';
		if ( '' !== $v && preg_match( '/^\d+[KMG]?$/i', $v ) ) {
			$lines[ $k ] = $v;
		}
	}
	if ( empty( $lines ) ) {
		aav_dt_redirect( 'ini_empty', 'php' );
	}

	$block1 = "; BEGIN AAV\n";
	foreach ( $lines as $k => $v ) {
		$block1 .= "$k = $v\n";
	}
	$block1 .= "; END AAV\n";
	$ok1 = aav_dt_put_block( ABSPATH . '.user.ini', $block1, '; BEGIN AAV', '; END AAV' );

	$ok2 = true;
	if ( false !== stripos( php_sapi_name(), 'apache' ) ) {
		$block2 = "# BEGIN AAV\n<IfModule mod_php.c>\n";
		foreach ( $lines as $k => $v ) {
			$block2 .= "php_value $k $v\n";
		}
		$block2 .= "</IfModule>\n# END AAV\n";
		$ok2 = aav_dt_put_block( ABSPATH . '.htaccess', $block2, '# BEGIN AAV', '# END AAV' );
	}

	aav_dt_redirect( ( $ok1 && $ok2 ) ? 'ini_ok' : 'ini_err', 'php' );
} );

/* Mu-plugin : installation (avec sauvegarde de la version precedente) */
add_action( 'admin_post_aav_dt_install_mu', function () {
	aav_dt_guard( 'aav_dt_install_mu' );

	$filename = sanitize_file_name( wp_unslash( $_POST['mu_filename'] ?? '' ) );
	if ( ! $filename || ! preg_match( '/\.php$/', $filename ) ) {
		$filename = 'aav-scroll-refresh.php';
	}

	$content = isset( $_POST['mu_content'] ) ? wp_unslash( $_POST['mu_content'] ) : '';
	if ( '' === trim( $content ) || 0 !== strpos( $content, '<?php' ) ) {
		aav_dt_redirect( 'mu_invalid', 'mu' );
	}

	$dir = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
	if ( ! file_exists( $dir ) ) {
		wp_mkdir_p( $dir );
	}
	$path = trailingslashit( $dir ) . $filename;

	/* Garde-fou : refuse d'ecraser une version plus recente que celle envoyee. */
	$ver_of = function ( $src ) {
		return preg_match( '/^\s*\*\s*Version:\s*([0-9.]+)/mi', (string) $src, $m ) ? $m[1] : '';
	};
	if ( is_file( $path ) && empty( $_POST['mu_force'] ) ) {
		$cur = $ver_of( (string) @file_get_contents( $path, false, null, 0, 2048 ) );
		$new = $ver_of( $content );
		if ( $cur && $new && version_compare( $new, $cur, '<' ) ) {
			aav_dt_redirect( 'mu_older', 'mu' );
		}
	}

	/* Copie de securite de la version en place (hors mu-plugins : WP les charge tous). */
	if ( is_file( $path ) ) {
		@copy( $path, WP_CONTENT_DIR . '/' . $filename . '.bak-' . gmdate( 'Ymd-His' ) );
	}

	$ok = ( false !== @file_put_contents( $path, $content ) );
	if ( $ok && function_exists( 'opcache_invalidate' ) ) {
		@opcache_invalidate( $path, true );
	}
	if ( $ok && md5( (string) @file_get_contents( $path ) ) !== md5( $content ) ) {
		aav_dt_redirect( 'mu_mismatch', 'mu' );
	}
	aav_dt_redirect( $ok ? 'mu_ok' : 'mu_err', 'mu' );
} );

/* Fichiers : remplacement */
add_action( 'admin_post_aav_dt_replace_file', function () {
	aav_dt_guard( 'aav_dt_replace_file' );

	$rel = aav_dt_clean_rel( $_POST['target_path'] ?? '' );
	if ( '' === $rel ) {
		aav_dt_redirect( 'file_badpath', 'files' );
	}
	if ( empty( $_FILES['replacement']['tmp_name'] ) || ! is_uploaded_file( $_FILES['replacement']['tmp_name'] ) ) {
		aav_dt_redirect( 'file_noupload', 'files' );
	}
	$target = aav_dt_resolve_in_content( $rel );
	if ( '' === $target || ! is_file( $target ) ) {
		aav_dt_redirect( 'file_notfound', 'files' );
	}
	if ( ! @copy( $target, $target . '.bak-' . gmdate( 'Ymd-His' ) ) ) {
		aav_dt_redirect( 'file_bak_err', 'files' );
	}
	$ok = @move_uploaded_file( $_FILES['replacement']['tmp_name'], $target );
	if ( $ok && function_exists( 'opcache_invalidate' ) && preg_match( '/\.php$/i', $target ) ) {
		@opcache_invalidate( $target, true );
	}
	aav_dt_redirect( $ok ? 'file_ok' : 'file_err', 'files' );
} );

/* Fichiers : suppression */
add_action( 'admin_post_aav_dt_delete_file', function () {
	aav_dt_guard( 'aav_dt_delete_file' );

	$rel = aav_dt_clean_rel( $_POST['delete_path'] ?? '' );
	if ( '' === $rel ) {
		aav_dt_redirect( 'del_badpath', 'files' );
	}
	$target = aav_dt_resolve_in_content( $rel );
	if ( '' === $target ) {
		aav_dt_redirect( 'del_notfound', 'files' );
	}
	if ( ! is_file( $target ) ) {
		aav_dt_redirect( 'del_notafile', 'files' );
	}
	$ok = @unlink( $target );
	if ( ! $ok ) {
		@chmod( $target, 0644 );
		$ok = @unlink( $target );
	}
	aav_dt_redirect( $ok ? 'del_ok' : 'del_err', 'files' );
} );

/* Fichiers : purge des .bak */
add_action( 'admin_post_aav_dt_purge_baks', function () {
	aav_dt_guard( 'aav_dt_purge_baks' );
	$done = 0;
	foreach ( aav_dt_find_baks() as $b ) {
		$target = aav_dt_resolve_in_content( $b['rel'] );
		if ( '' !== $target && is_file( $target ) && @unlink( $target ) ) {
			$done++;
		}
	}
	aav_dt_redirect( 'baks_' . $done, 'files' );
} );

/* Maintenance : purge des caches (extensions connues + OPcache + transients) */
add_action( 'admin_post_aav_dt_purge_cache', function () {
	aav_dt_guard( 'aav_dt_purge_cache' );

	$done = array();

	if ( function_exists( 'opcache_reset' ) && @opcache_reset() ) {
		$done[] = 'OPcache';
	}
	if ( function_exists( 'wp_cache_flush' ) && wp_cache_flush() ) {
		$done[] = 'cache objet';
	}
	// Extensions de cache : chacune expose son propre point d'entree.
	if ( has_action( 'litespeed_purge_all' ) ) { do_action( 'litespeed_purge_all' ); $done[] = 'LiteSpeed'; }
	if ( function_exists( 'rocket_clean_domain' ) ) { rocket_clean_domain(); $done[] = 'WP Rocket'; }
	if ( function_exists( 'w3tc_flush_all' ) ) { w3tc_flush_all(); $done[] = 'W3 Total Cache'; }
	if ( function_exists( 'wp_cache_clear_cache' ) ) { wp_cache_clear_cache(); $done[] = 'WP Super Cache'; }
	if ( function_exists( 'wpfc_clear_all_cache' ) ) { wpfc_clear_all_cache( true ); $done[] = 'WP Fastest Cache'; }
	if ( class_exists( 'Breeze_PurgeCache' ) ) { do_action( 'breeze_clear_all_cache' ); $done[] = 'Breeze'; }
	if ( class_exists( 'SiteGround_Optimizer\Supercacher\Supercacher' ) ) { do_action( 'siteground_optimizer_flush_cache' ); $done[] = 'SiteGround'; }

	delete_transient( 'aav_dt_gh_release' );
	$done[] = 'cache des mises a jour';

	set_transient( 'aav_dt_purge_report', $done, 60 );
	aav_dt_redirect( 'cache_ok', 'tools' );
} );

/* Maintenance : forcer la verification des mises a jour */
add_action( 'admin_post_aav_dt_check_update', function () {
	aav_dt_guard( 'aav_dt_check_update' );
	delete_transient( 'aav_dt_gh_release' );
	delete_site_transient( 'update_plugins' );
	wp_update_plugins();
	aav_dt_redirect( 'upd_checked', 'tools' );
} );

/* Maintenance : normaliser les blocs AAV */
add_action( 'admin_post_aav_dt_normalize_mode', function () {
	aav_dt_guard( 'aav_dt_normalize_mode' );

	$q = new WP_Query( array(
		'post_type'      => 'any',
		'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
		'posts_per_page' => -1,
		's'              => 'wp:acf/aav-',
		'fields'         => 'ids',
	) );

	$changed = 0;
	foreach ( $q->posts as $pid ) {
		$post = get_post( $pid );
		if ( ! $post || false === strpos( $post->post_content, 'wp:acf/aav-' ) ) {
			continue;
		}
		$new = preg_replace_callback(
			'/<!--\s*wp:acf\/aav-[^\s]+\s+(\{.*?\})\s*\/?-->/s',
			function ( $m ) {
				return str_replace( '"mode":"preview"', '"mode":"edit"', $m[0] );
			},
			$post->post_content
		);
		if ( null !== $new && $new !== $post->post_content ) {
			wp_update_post( array( 'ID' => $pid, 'post_content' => $new ) );
			$changed++;
		}
	}
	aav_dt_redirect( 'norm_' . $changed, 'tools' );
} );

/* =========================================================================
 *  INTERFACE
 * ====================================================================== */

function aav_dt_notice_for( $status ) {
	$map = array(
		'nozip'         => array( 'error',   "L'extension PHP ZipArchive n'est pas disponible sur ce serveur." ),
		'zip_err'       => array( 'error',   "Impossible de creer l'archive." ),
		'plg_notfound'  => array( 'error',   'Extension inconnue.' ),
		'ini_ok'        => array( 'success', 'Limites PHP enregistrees. Compte quelques minutes pour la prise en compte.' ),
		'ini_err'       => array( 'error',   "Echec d'ecriture du fichier de limites (droits en ecriture ?)." ),
		'ini_empty'     => array( 'error',   'Aucune valeur valide fournie.' ),
		'mu_ok'         => array( 'success', 'Mu-plugin installe. Une copie de la version precedente a ete placee dans wp-content/.' ),
		'mu_err'        => array( 'error',   "Echec d'ecriture du mu-plugin (droits en ecriture ?)." ),
		'mu_invalid'    => array( 'error',   'Le contenu du mu-plugin doit commencer par <?php.' ),
		'mu_mismatch'   => array( 'error',   'Ecriture incomplete : le contenu relu differe de celui envoye (quota ? securite hebergeur ?).' ),
		'mu_older'      => array( 'warning', 'Installation annulee : la version envoyee est plus ancienne que celle en place. Coche "forcer" pour retrograder volontairement.' ),
		'file_ok'       => array( 'success', 'Fichier remplace. Une sauvegarde .bak horodatee a ete creee a cote.' ),
		'file_err'      => array( 'error',   "Echec d'ecriture du fichier (droits ?). La sauvegarde .bak a ete creee." ),
		'file_bak_err'  => array( 'error',   'Impossible de creer la sauvegarde .bak : remplacement annule par prudence.' ),
		'file_badpath'  => array( 'error',   'Chemin invalide (relatif a wp-content, sans "..").' ),
		'file_notfound' => array( 'error',   "Le fichier cible n'existe pas dans wp-content." ),
		'file_noupload' => array( 'error',   'Aucun fichier televerse.' ),
		'del_ok'        => array( 'success', 'Fichier supprime.' ),
		'del_err'       => array( 'error',   "Suppression impossible : le fichier appartient a un autre utilisateur systeme. Passe par le gestionnaire de fichiers de l'hebergeur." ),
		'del_badpath'   => array( 'error',   'Chemin invalide (relatif a wp-content, sans "..").' ),
		'del_notfound'  => array( 'error',   "Le fichier n'existe pas dans wp-content." ),
		'del_notafile'  => array( 'error',   'La cible est un dossier : seuls les fichiers peuvent etre supprimes ici.' ),
		'upd_checked'   => array( 'success', 'Verification des mises a jour relancee. Va dans Extensions pour voir le resultat.' ),
	);

	if ( isset( $map[ $status ] ) ) {
		return $map[ $status ];
	}
	if ( 'cache_ok' === $status ) {
		$rep = get_transient( 'aav_dt_purge_report' );
		return array( 'success', 'Caches vides : ' . ( $rep ? implode( ', ', $rep ) : 'aucun cache detecte' ) . '.' );
	}
	if ( 0 === strpos( $status, 'norm_' ) ) {
		$n = (int) substr( $status, 5 );
		return array( 'success', $n
			? sprintf( '%d page(s) normalisee(s) : les blocs AAV s\'ouvriront en mode edition.', $n )
			: 'Aucune page a modifier : tous les blocs AAV sont deja en mode edition.' );
	}
	if ( 0 === strpos( $status, 'baks_' ) ) {
		$n = (int) substr( $status, 5 );
		return array( $n ? 'success' : 'warning', sprintf( '%d sauvegarde(s) .bak supprimee(s).', $n ) );
	}
	return null;
}

function aav_dt_card_open( $title, $desc = '' ) {
	echo '<div class="card" style="max-width:900px;padding:4px 20px 16px;margin:0 0 18px;">';
	echo '<h2 style="margin-top:14px;">' . esc_html( $title ) . '</h2>';
	if ( $desc ) {
		echo '<p class="description" style="margin-bottom:14px;">' . wp_kses_post( $desc ) . '</p>';
	}
}
function aav_dt_card_close() {
	echo '</div>';
}

function aav_dt_render_page() {
	if ( ! current_user_can( AAV_DT_CAP ) ) {
		wp_die( 'Acces refuse.', '', array( 'response' => 403 ) );
	}

	$tabs   = aav_dt_tabs();
	$tab    = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'diag';
	if ( ! isset( $tabs[ $tab ] ) ) {
		$tab = 'diag';
	}
	$status = isset( $_GET['aav_status'] ) ? sanitize_key( wp_unslash( $_GET['aav_status'] ) ) : '';
	$notice = $status ? aav_dt_notice_for( $status ) : null;
	$action = esc_url( admin_url( 'admin-post.php' ) );
	$baks   = aav_dt_find_baks();
	?>
	<div class="wrap">
		<h1 style="display:flex;align-items:baseline;gap:10px;">
			AAV Dev Toolkit
			<span style="font-size:13px;color:#666;font-weight:400;">v<?php echo esc_html( AAV_DT_VERSION ); ?></span>
		</h1>

		<?php if ( $notice ) : ?>
			<div class="notice notice-<?php echo esc_attr( $notice[0] ); ?> is-dismissible"><p><?php echo esc_html( $notice[1] ); ?></p></div>
		<?php endif; ?>

		<?php if ( $baks && 'files' !== $tab ) : ?>
			<div class="notice notice-warning">
				<p><?php printf( '%d sauvegarde(s) .bak trainent dans wp-content : elles bloquent les mises a jour d\'extensions.', count( $baks ) ); ?>
					<a href="<?php echo esc_url( aav_dt_url( 'files' ) ); ?>">Voir l'onglet Fichiers</a></p>
			</div>
		<?php endif; ?>

		<h2 class="nav-tab-wrapper" style="margin-bottom:18px;">
			<?php foreach ( $tabs as $k => $label ) :
				$cls = 'nav-tab' . ( $k === $tab ? ' nav-tab-active' : '' ); ?>
				<a class="<?php echo esc_attr( $cls ); ?>" href="<?php echo esc_url( aav_dt_url( $k ) ); ?>"><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</h2>

		<?php
		/* ---------------------------------------------------------- DIAGNOSTIC */
		if ( 'diag' === $tab ) :
			aav_dt_card_open( 'Etat du site', "Photographie de l'environnement. Utile avant d'intervenir, et a copier-coller dans un ticket de support." );
			echo '<table class="widefat striped"><tbody>';
			foreach ( aav_dt_diagnostics() as $k => $v ) {
				$flag = ( 'WP_DEBUG' === $k && 'ACTIF' === $v ) ? ' style="color:#b26b00;font-weight:600"' : '';
				printf( '<tr><td style="width:220px;"><strong>%s</strong></td><td%s>%s</td></tr>', esc_html( $k ), $flag, esc_html( $v ) );
			}
			echo '</tbody></table>';
			$txt = '';
			foreach ( aav_dt_diagnostics() as $k => $v ) {
				$txt .= $k . ' : ' . $v . "\n";
			}
			echo '<p style="margin-top:14px;"><label for="aav_diag_txt"><strong>Version texte</strong></label></p>';
			echo '<textarea id="aav_diag_txt" rows="6" class="large-text code" readonly onclick="this.select()">' . esc_textarea( $txt ) . '</textarea>';
			aav_dt_card_close();

		/* -------------------------------------------------------------- EXPORT */
		elseif ( 'export' === $tab ) :
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$plugins = get_plugins();
			$actives = (array) get_option( 'active_plugins', array() );

			aav_dt_card_open( 'Exporter le theme', 'Zip complet du theme actif, sans <code>node_modules</code> ni <code>.git</code>.' );
			?>
			<form method="post" action="<?php echo $action; ?>">
				<?php wp_nonce_field( 'aav_dt_download_theme' ); ?>
				<input type="hidden" name="action" value="aav_dt_download_theme">
				<?php submit_button( 'Telecharger ' . basename( get_template_directory() ) . ' (.zip)', 'primary', 'submit', false ); ?>
			</form>
			<?php
			aav_dt_card_close();

			aav_dt_card_open( 'Exporter une extension', 'Pour archiver l\'etat reel du site ou alimenter un depot Git.' );
			?>
			<form method="post" action="<?php echo $action; ?>">
				<?php wp_nonce_field( 'aav_dt_download_plugin' ); ?>
				<input type="hidden" name="action" value="aav_dt_download_plugin">
				<select name="plugin_file" style="min-width:460px;">
					<?php foreach ( $plugins as $pfile => $pdata ) :
						$state = in_array( $pfile, $actives, true ) ? '' : ' (inactive)'; ?>
						<option value="<?php echo esc_attr( $pfile ); ?>"><?php echo esc_html( $pdata['Name'] . ' ' . $pdata['Version'] . $state ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php submit_button( 'Telecharger', 'secondary', 'submit', false ); ?>
			</form>
			<?php
			aav_dt_card_close();

		/* ------------------------------------------------------------- FICHIERS */
		elseif ( 'files' === $tab ) :

			if ( $baks ) {
				aav_dt_card_open( 'Sauvegardes .bak detectees', 'Ces fichiers <strong>bloquent les mises a jour d\'extensions</strong> lorsqu\'ils se trouvent dans leur dossier.' );
				echo '<table class="widefat striped"><thead><tr><th>Fichier</th><th>Taille</th><th>Date</th></tr></thead><tbody>';
				foreach ( $baks as $b ) {
					printf(
						'<tr><td><code>%s</code></td><td>%s</td><td>%s</td></tr>',
						esc_html( $b['rel'] ), esc_html( size_format( $b['size'] ) ), esc_html( gmdate( 'Y-m-d H:i', $b['time'] ) )
					);
				}
				echo '</tbody></table>';
				?>
				<form method="post" action="<?php echo $action; ?>" style="margin-top:12px;"
				      onsubmit="return confirm('Supprimer les <?php echo count( $baks ); ?> sauvegardes .bak ?');">
					<?php wp_nonce_field( 'aav_dt_purge_baks' ); ?>
					<input type="hidden" name="action" value="aav_dt_purge_baks">
					<?php submit_button( 'Tout supprimer', 'delete', 'submit', false ); ?>
				</form>
				<?php
				aav_dt_card_close();
			}

			aav_dt_card_open( 'Remplacer un fichier', 'Chemin relatif a <code>wp-content</code>, par exemple <code>themes/aav/assets/app.min.js</code>. Une sauvegarde <code>.bak</code> horodatee est creee avant remplacement.' );
			?>
			<form method="post" action="<?php echo $action; ?>" enctype="multipart/form-data">
				<?php wp_nonce_field( 'aav_dt_replace_file' ); ?>
				<input type="hidden" name="action" value="aav_dt_replace_file">
				<p><input name="target_path" type="text" class="large-text" placeholder="plugins/aav-landing-blocks/aav-landing-blocks.php" required></p>
				<p><input name="replacement" type="file" required></p>
				<?php submit_button( 'Remplacer', 'primary', 'submit', false ); ?>
			</form>
			<?php
			aav_dt_card_close();

			aav_dt_card_open( 'Supprimer un fichier', 'Fichiers uniquement, jamais de dossiers. Residus de migration, sauvegardes, fichiers obsoletes.' );
			?>
			<form method="post" action="<?php echo $action; ?>" onsubmit="return confirm('Supprimer definitivement ce fichier ?');">
				<?php wp_nonce_field( 'aav_dt_delete_file' ); ?>
				<input type="hidden" name="action" value="aav_dt_delete_file">
				<p><input name="delete_path" type="text" class="large-text" placeholder="plugins/mon-extension/fichier.php.bak-20260902-120703" required></p>
				<?php submit_button( 'Supprimer', 'delete', 'submit', false ); ?>
			</form>
			<?php
			aav_dt_card_close();

		/* ------------------------------------------------------------ MU-PLUGINS */
		elseif ( 'mu' === $tab ) :
			$mu_dir   = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
			$writable = is_dir( $mu_dir ) ? is_writable( $mu_dir ) : is_writable( dirname( $mu_dir ) );
			$existing = is_dir( $mu_dir ) ? array_values( array_diff( scandir( $mu_dir ), array( '.', '..' ) ) ) : array();

			aav_dt_card_open( 'Mu-plugins installes', 'Les mu-plugins sont actives automatiquement et n\'apparaissent pas dans la liste des extensions.' );
			printf(
				'<p><code>%s</code> : %s en ecriture</p>',
				esc_html( $mu_dir ),
				$writable ? '<strong style="color:#00a32a">accessible</strong>' : '<strong style="color:#d63638">NON accessible</strong>'
			);
			if ( $existing ) {
				echo '<table class="widefat striped"><thead><tr><th>Fichier</th><th>Version sur le disque</th><th>Modifie le (UTC)</th><th>Empreinte</th></tr></thead><tbody>';
				foreach ( $existing as $f ) {
					$p = trailingslashit( $mu_dir ) . $f;
					if ( ! is_file( $p ) ) {
						continue;
					}
					$head = (string) @file_get_contents( $p, false, null, 0, 2048 );
					$ver  = preg_match( '/^\s*\*\s*Version:\s*(.+)$/mi', $head, $m ) ? trim( $m[1] ) : 'n/a';
					printf(
						'<tr><td><code>%s</code></td><td><strong>%s</strong></td><td>%s</td><td><code>%s</code></td></tr>',
						esc_html( $f ), esc_html( $ver ),
						esc_html( gmdate( 'Y-m-d H:i:s', (int) @filemtime( $p ) ) ),
						esc_html( substr( md5( (string) @file_get_contents( $p ) ), 0, 8 ) )
					);
				}
				echo '</tbody></table>';
			} else {
				echo '<p><em>Aucun mu-plugin installe.</em></p>';
			}
			aav_dt_card_close();

			aav_dt_card_open( 'Installer ou mettre a jour', 'Le champ est pre-rempli avec la derniere version connue du correctif de navigation (v1.4). Une installation d\'une version <strong>anterieure</strong> a celle du disque est refusee, sauf si tu coches la case de forcage.' );
			?>
			<form method="post" action="<?php echo $action; ?>">
				<?php wp_nonce_field( 'aav_dt_install_mu' ); ?>
				<input type="hidden" name="action" value="aav_dt_install_mu">
				<p>
					<label for="mu_filename"><strong>Nom du fichier</strong></label><br>
					<input name="mu_filename" id="mu_filename" type="text" class="regular-text" value="aav-scroll-refresh.php">
				</p>
				<p>
					<label for="mu_content"><strong>Contenu</strong></label><br>
					<textarea name="mu_content" id="mu_content" rows="16" class="large-text code" spellcheck="false"><?php echo esc_textarea( aav_dt_default_mu() ); ?></textarea>
				</p>
				<p><label><input type="checkbox" name="mu_force" value="1"> Forcer meme si la version envoyee est plus ancienne</label></p>
				<?php submit_button( 'Installer le mu-plugin', 'primary', 'submit', false ); ?>
			</form>
			<?php
			aav_dt_card_close();

		/* ----------------------------------------------------------- LIMITES PHP */
		elseif ( 'php' === $tab ) :
			$sapi     = php_sapi_name();
			$ini_keys = array(
				'upload_max_filesize' => "Taille max d'un fichier uploade",
				'post_max_size'       => "Taille max d'une requete POST",
				'memory_limit'        => 'Limite memoire',
				'max_execution_time'  => "Temps d'execution max (secondes)",
			);
			aav_dt_card_open(
				'Limites PHP',
				sprintf(
					'SAPI : <code>%s</code> &middot; php.ini charge : <code>%s</code>. php.ini n\'est pas editable depuis PHP : on ecrit un <code>.user.ini</code>%s. Compte quelques minutes pour la prise en compte.',
					esc_html( $sapi ),
					esc_html( php_ini_loaded_file() ?: '(aucun)' ),
					( false !== stripos( $sapi, 'apache' ) ) ? ' et un bloc <code>.htaccess</code>' : ''
				)
			);
			?>
			<form method="post" action="<?php echo $action; ?>">
				<?php wp_nonce_field( 'aav_dt_save_ini' ); ?>
				<input type="hidden" name="action" value="aav_dt_save_ini">
				<table class="form-table" role="presentation">
					<?php foreach ( $ini_keys as $key => $label ) : ?>
						<tr>
							<th scope="row"><label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
							<td>
								<input name="<?php echo esc_attr( $key ); ?>" id="<?php echo esc_attr( $key ); ?>" type="text"
								       class="regular-text" placeholder="<?php echo esc_attr( ini_get( $key ) ); ?>">
								<p class="description">Actuel : <code><?php echo esc_html( ini_get( $key ) ); ?></code>. Ex : 64M, 128M, 300. Vide = inchange.</p>
							</td>
						</tr>
					<?php endforeach; ?>
				</table>
				<?php submit_button( 'Appliquer les limites', 'primary', 'submit', false ); ?>
			</form>
			<?php
			aav_dt_card_close();

		/* ----------------------------------------------------------- MAINTENANCE */
		elseif ( 'tools' === $tab ) :
			$caches = aav_dt_cache_plugins();

			aav_dt_card_open(
				'Vider les caches',
				'OPcache, cache objet, extensions de cache detectees et cache des mises a jour. A lancer apres tout remplacement de fichier : '
				. ( $caches ? 'detecte ici : <strong>' . esc_html( implode( ', ', $caches ) ) . '</strong>.' : 'aucune extension de cache detectee.' )
			);
			?>
			<form method="post" action="<?php echo $action; ?>">
				<?php wp_nonce_field( 'aav_dt_purge_cache' ); ?>
				<input type="hidden" name="action" value="aav_dt_purge_cache">
				<?php submit_button( 'Vider tous les caches', 'primary', 'submit', false ); ?>
			</form>
			<?php
			aav_dt_card_close();

			aav_dt_card_open( 'Mises a jour des extensions', 'Force WordPress a reinterroger les depots, dont GitHub pour les extensions AAV. Utile juste apres avoir publie une release.' );
			?>
			<form method="post" action="<?php echo $action; ?>">
				<?php wp_nonce_field( 'aav_dt_check_update' ); ?>
				<input type="hidden" name="action" value="aav_dt_check_update">
				<?php submit_button( 'Verifier les mises a jour maintenant', 'secondary', 'submit', false ); ?>
			</form>
			<?php
			aav_dt_card_close();

			aav_dt_card_open( 'Blocs AAV : forcer le mode edition', 'Le mode apercu/edition est memorise dans la page a l\'insertion du bloc. <strong>Ferme les editeurs de pages ouverts avant de lancer l\'operation.</strong>' );
			?>
			<form method="post" action="<?php echo $action; ?>">
				<?php wp_nonce_field( 'aav_dt_normalize_mode' ); ?>
				<input type="hidden" name="action" value="aav_dt_normalize_mode">
				<?php submit_button( 'Normaliser tous les blocs AAV', 'secondary', 'submit', false ); ?>
			</form>
			<?php
			aav_dt_card_close();

		endif;
		?>

		<p style="max-width:900px;margin-top:24px;background:#fff3cd;border:1px solid #ffe69c;padding:10px 14px;border-radius:4px;font-size:13px;">
			<strong>Rappel.</strong> Cet outil depose du code executable et modifie des fichiers.
			Sur un site en production, retire-le une fois l'intervention terminee.
		</p>
	</div>
	<?php
}

/* =========================================================================
 *  MISES A JOUR DEPUIS GITHUB (releases, sans extension tierce)
 * ====================================================================== */
add_filter( 'update_plugins_github.com', function ( $update, $plugin_data, $plugin_file ) {
	if ( AAV_DT_FILE !== $plugin_file ) {
		return $update;
	}

	$cache_key = 'aav_dt_gh_release';
	$release   = get_transient( $cache_key );

	if ( false === $release ) {
		$res = wp_remote_get(
			'https://api.github.com/repos/' . AAV_DT_REPO . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'AAV-Dev-Toolkit/' . AAV_DT_VERSION,
				),
			)
		);
		if ( is_wp_error( $res ) || 200 !== wp_remote_retrieve_response_code( $res ) ) {
			set_transient( $cache_key, array(), HOUR_IN_SECONDS );
			return $update;
		}
		$release = json_decode( wp_remote_retrieve_body( $res ), true );
		set_transient( $cache_key, $release, 6 * HOUR_IN_SECONDS );
	}

	if ( empty( $release['tag_name'] ) ) {
		return $update;
	}

	$remote = ltrim( $release['tag_name'], 'vV' );
	if ( version_compare( $remote, $plugin_data['Version'], '<=' ) ) {
		return $update;
	}

	$package = '';
	if ( ! empty( $release['assets'] ) && is_array( $release['assets'] ) ) {
		foreach ( $release['assets'] as $asset ) {
			if ( ! empty( $asset['browser_download_url'] ) && preg_match( '/\.zip$/i', $asset['browser_download_url'] ) ) {
				$package = $asset['browser_download_url'];
				break;
			}
		}
	}
	if ( '' === $package && ! empty( $release['zipball_url'] ) ) {
		$package = $release['zipball_url'];
	}
	if ( '' === $package ) {
		return $update;
	}

	return array(
		'slug'    => dirname( AAV_DT_FILE ),
		'version' => $remote,
		'url'     => 'https://github.com/' . AAV_DT_REPO,
		'package' => $package,
	);
}, 10, 3 );

add_action( 'upgrader_process_complete', function () {
	delete_transient( 'aav_dt_gh_release' );
} );
