<?php
/**
 * Plugin Name: AAV Dev Toolkit
 * Description: Boîte à outils d'administration pour le site AAV, sans accès FTP :
 *              diagnostic, export thème/extensions, limites PHP, mu-plugins,
 *              remplacement et suppression de fichiers, purge des caches,
 *              normalisation des blocs AAV. Réservé aux administrateurs.
 * Version:     1.8.0
 * Author:      Biolay Group
 * Update URI:  https://github.com/biolay-group/aav-dev-toolkit
 * License:     GPL-2.0-or-later
 *
 * AVERTISSEMENT SÉCURITÉ
 * Cet outil dépose du code exécutable et modifie des fichiers. Toutes les actions
 * exigent le droit "manage_options" et un jeton (nonce). Sur un site en production,
 * installez-le le temps de l'intervention, puis désactivez-le ou retirez-le.
 *
 * Verrou optionnel : définir AAV_DT_LOCK à true dans wp-config.php désactive
 * toutes les opérations d'écriture (lecture et diagnostic restent disponibles).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AAV_DT_VERSION', '1.8.0' );
define( 'AAV_DT_CAP', 'manage_options' );
define( 'AAV_DT_SLUG', 'aav-devtools' );
define( 'AAV_DT_FILE', plugin_basename( __FILE__ ) );
define( 'AAV_DT_REPO', 'biolay-group/aav-dev-toolkit' );
define( 'AAV_DT_BACKUP_DIR', WP_CONTENT_DIR . '/aav-backups' );

/* =========================================================================
 *  LANGUE : français / anglais selon la langue de l'administration
 * ====================================================================== */

/** L'utilisateur connecté lit-il l'admin en français ? */
function aav_dt_is_fr() {
	static $fr = null;
	if ( null === $fr ) {
		$locale = function_exists( 'get_user_locale' ) ? get_user_locale() : get_locale();
		$fr     = ( 0 === strpos( $locale, 'fr' ) );
	}
	return $fr;
}

/** Retourne la chaîne française ou anglaise selon la langue de l'admin. */
function aav_dt__( $fr, $en ) {
	return aav_dt_is_fr() ? $fr : $en;
}

/** Idem, échappé pour affichage HTML. */
function aav_dt_e( $fr, $en ) {
	echo esc_html( aav_dt__( $fr, $en ) );
}

function aav_dt_tabs() {
	return array(
		'diag'   => aav_dt__( 'Diagnostic', 'Diagnostics' ),
		'export' => aav_dt__( 'Export', 'Export' ),
		'files'  => aav_dt__( 'Fichiers', 'Files' ),
		'mu'     => aav_dt__( 'Mu-plugins', 'Mu-plugins' ),
		'php'    => aav_dt__( 'Limites PHP', 'PHP limits' ),
		'tools'  => aav_dt__( 'Maintenance', 'Maintenance' ),
	);
}

add_action( 'admin_menu', function () {
	add_management_page( 'AAV Dev Toolkit', 'AAV Dev Toolkit', AAV_DT_CAP, AAV_DT_SLUG, 'aav_dt_render_page' );
} );

/* =========================================================================
 *  SÉCURITÉ ET HELPERS
 * ====================================================================== */

/** Le verrou d'écriture est-il actif ? (constante dans wp-config.php) */
function aav_dt_locked() {
	return defined( 'AAV_DT_LOCK' ) && AAV_DT_LOCK;
}

/**
 * Garde commune : droits, jeton, et verrou pour les opérations d'écriture.
 * $writes = true pour toute action qui modifie le site.
 */
function aav_dt_guard( $nonce_action, $writes = true ) {
	if ( ! current_user_can( AAV_DT_CAP ) ) {
		wp_die( esc_html( aav_dt__( 'Accès refusé.', 'Access denied.' ) ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( $nonce_action );
	if ( $writes && aav_dt_locked() ) {
		wp_die(
			esc_html( aav_dt__(
				'Les opérations d\'écriture sont désactivées (AAV_DT_LOCK est défini dans wp-config.php).',
				'Write operations are disabled (AAV_DT_LOCK is set in wp-config.php).'
			) ),
			'',
			array( 'response' => 403 )
		);
	}
}

/** Journal des opérations sensibles : qui, quoi, quand (30 dernières). */
function aav_dt_log( $what, $detail = '' ) {
	$log   = get_option( 'aav_dt_log', array() );
	if ( ! is_array( $log ) ) {
		$log = array();
	}
	$user  = wp_get_current_user();
	array_unshift( $log, array(
		'time'   => time(),
		'user'   => $user ? $user->user_login : '?',
		'what'   => $what,
		'detail' => $detail,
		'ip'     => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
	) );
	update_option( 'aav_dt_log', array_slice( $log, 0, 30 ), false );
}

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

/** Nettoie un chemin relatif fourni par l'utilisateur (refuse les remontées). */
function aav_dt_clean_rel( $raw ) {
	$rel = ltrim( str_replace( '\\', '/', trim( (string) $raw ) ), '/' );
	if ( '' === $rel || false !== strpos( $rel, '..' ) || false !== strpos( $rel, "\0" ) ) {
		return '';
	}
	return $rel;
}

/** Résout un chemin dans un dossier de base et vérifie qu'il n'en sort pas. */
function aav_dt_resolve_in( $base_dir, $rel ) {
	$base   = realpath( $base_dir );
	$target = realpath( rtrim( $base_dir, '/' ) . '/' . $rel );
	if ( ! $base || ! $target ) {
		return '';
	}
	// Le séparateur final évite qu'un dossier voisin au nom proche passe le test.
	if ( 0 !== strpos( $target . DIRECTORY_SEPARATOR, rtrim( $base, '/\\' ) . DIRECTORY_SEPARATOR ) ) {
		return '';
	}
	return $target;
}

function aav_dt_resolve_in_content( $rel ) {
	return aav_dt_resolve_in( WP_CONTENT_DIR, $rel );
}

function aav_dt_mu_dir() {
	return defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
}

/** Dossier de sauvegardes du toolkit, protégé des regards. */
function aav_dt_backup_dir() {
	if ( ! is_dir( AAV_DT_BACKUP_DIR ) ) {
		wp_mkdir_p( AAV_DT_BACKUP_DIR );
		@file_put_contents( AAV_DT_BACKUP_DIR . '/index.php', "<?php\n// Silence." );
		@file_put_contents( AAV_DT_BACKUP_DIR . '/.htaccess', "Deny from all\n" );
	}
	return AAV_DT_BACKUP_DIR;
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

/** Sauvegardes .bak dans les extensions et thèmes (résultat mis en cache 5 min). */
function aav_dt_find_baks( $force = false ) {
	$cached = get_transient( 'aav_dt_baks' );
	if ( ! $force && is_array( $cached ) ) {
		return $cached;
	}

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
	set_transient( 'aav_dt_baks', $found, 5 * MINUTE_IN_SECONDS );
	return $found;
}

function aav_dt_cache_plugins() {
	$known = array(
		'litespeed-cache/litespeed-cache.php' => 'LiteSpeed Cache',
		'wp-rocket/wp-rocket.php'             => 'WP Rocket',
		'w3-total-cache/w3-total-cache.php'   => 'W3 Total Cache',
		'wp-super-cache/wp-cache.php'         => 'WP Super Cache',
		'wp-fastest-cache/wpFastestCache.php' => 'WP Fastest Cache',
		'autoptimize/autoptimize.php'         => 'Autoptimize',
		'sg-cachepress/sg-cachepress.php'     => 'SiteGround Optimizer',
		'breeze/breeze.php'                   => 'Breeze',
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

function aav_dt_diagnostics() {
	global $wp_version, $wpdb;

	$theme   = wp_get_theme();
	$mu_dir  = aav_dt_mu_dir();
	$mu      = is_dir( $mu_dir ) ? array_values( array_diff( scandir( $mu_dir ), array( '.', '..' ) ) ) : array();
	$actives = (array) get_option( 'active_plugins', array() );
	$locale  = get_option( 'WPLANG' );
	$caches  = aav_dt_cache_plugins();

	return array(
		aav_dt__( 'WordPress', 'WordPress' )                       => $wp_version,
		aav_dt__( 'PHP', 'PHP' )                                   => PHP_VERSION . ' (' . php_sapi_name() . ')',
		aav_dt__( 'MySQL', 'MySQL' )                               => $wpdb->db_version(),
		aav_dt__( 'Thème actif', 'Active theme' )                  => $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ),
		aav_dt__( 'Extensions actives', 'Active plugins' )         => count( $actives ),
		aav_dt__( 'Mu-plugins', 'Mu-plugins' )                     => $mu ? implode( ', ', $mu ) : aav_dt__( 'aucun', 'none' ),
		aav_dt__( 'Langue du site', 'Site language' )              => $locale ? $locale : 'en_US ' . aav_dt__( '(défaut)', '(default)' ),
		aav_dt__( 'Langue de cette admin', 'This admin language' ) => function_exists( 'get_user_locale' ) ? get_user_locale() : get_locale(),
		aav_dt__( 'Fuseau horaire', 'Timezone' )                   => get_option( 'timezone_string' ) ?: get_option( 'gmt_offset' ) . 'h',
		aav_dt__( 'Limite mémoire', 'Memory limit' )               => ini_get( 'memory_limit' ) . ' (WP_MEMORY_LIMIT : ' . ( defined( 'WP_MEMORY_LIMIT' ) ? WP_MEMORY_LIMIT : 'n/d' ) . ')',
		aav_dt__( 'Upload maximum', 'Max upload' )                 => ini_get( 'upload_max_filesize' ),
		aav_dt__( 'Temps d\'exécution', 'Execution time' )         => ini_get( 'max_execution_time' ) . ' s',
		aav_dt__( 'OPcache', 'OPcache' )                           => ( function_exists( 'opcache_get_status' ) && @opcache_get_status( false ) ) ? aav_dt__( 'actif', 'enabled' ) : aav_dt__( 'inactif', 'disabled' ),
		aav_dt__( 'WP_DEBUG', 'WP_DEBUG' )                         => ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ? aav_dt__( 'ACTIF', 'ENABLED' ) : aav_dt__( 'inactif', 'disabled' ),
		aav_dt__( 'Cache objet', 'Object cache' )                  => file_exists( WP_CONTENT_DIR . '/object-cache.php' ) ? aav_dt__( 'présent', 'present' ) : aav_dt__( 'absent', 'absent' ),
		aav_dt__( 'Cache de page', 'Page cache' )                  => file_exists( WP_CONTENT_DIR . '/advanced-cache.php' ) ? 'advanced-cache.php' : aav_dt__( 'absent', 'absent' ),
		aav_dt__( 'Extensions de cache', 'Caching plugins' )       => $caches ? implode( ', ', $caches ) : aav_dt__( 'aucune détectée', 'none detected' ),
		aav_dt__( 'HTTPS', 'HTTPS' )                               => is_ssl() ? aav_dt__( 'oui', 'yes' ) : aav_dt__( 'non', 'no' ),
		aav_dt__( 'Verrou d\'écriture', 'Write lock' )             => aav_dt_locked() ? aav_dt__( 'ACTIF (AAV_DT_LOCK)', 'ENABLED (AAV_DT_LOCK)' ) : aav_dt__( 'inactif', 'disabled' ),
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

/* Export : thème (lecture seule) */
add_action( 'admin_post_aav_dt_download_theme', function () {
	aav_dt_guard( 'aav_dt_download_theme', false );
	$dir = get_template_directory();
	aav_dt_log( 'export_theme', basename( $dir ) );
	aav_dt_stream_zip_dir( $dir, basename( $dir ) );
} );

/* Export : extension (lecture seule) */
add_action( 'admin_post_aav_dt_download_plugin', function () {
	aav_dt_guard( 'aav_dt_download_plugin', false );

	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$pf  = isset( $_POST['plugin_file'] ) ? wp_unslash( $_POST['plugin_file'] ) : '';
	$all = get_plugins();
	if ( ! isset( $all[ $pf ] ) ) {
		aav_dt_redirect( 'plg_notfound', 'export' );
	}
	aav_dt_log( 'export_plugin', $pf );

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

	aav_dt_log( 'php_limits', implode( ', ', array_map( function ( $k, $v ) { return "$k=$v"; }, array_keys( $lines ), $lines ) ) );
	aav_dt_redirect( ( $ok1 && $ok2 ) ? 'ini_ok' : 'ini_err', 'php' );
} );

/* Mu-plugin : installation */
add_action( 'admin_post_aav_dt_install_mu', function () {
	aav_dt_guard( 'aav_dt_install_mu' );

	$filename = sanitize_file_name( wp_unslash( $_POST['mu_filename'] ?? '' ) );
	if ( ! $filename || ! preg_match( '/^[A-Za-z0-9._-]+\.php$/', $filename ) ) {
		$filename = 'aav-scroll-refresh.php';
	}

	$content = isset( $_POST['mu_content'] ) ? wp_unslash( $_POST['mu_content'] ) : '';
	if ( '' === trim( $content ) || 0 !== strpos( $content, '<?php' ) ) {
		aav_dt_redirect( 'mu_invalid', 'mu' );
	}

	$dir = aav_dt_mu_dir();
	if ( ! file_exists( $dir ) ) {
		wp_mkdir_p( $dir );
	}
	$path = trailingslashit( $dir ) . $filename;

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

	/* Sauvegarde hors du dossier mu-plugins (WordPress les charge tous). */
	if ( is_file( $path ) ) {
		@copy( $path, trailingslashit( aav_dt_backup_dir() ) . $filename . '.bak-' . gmdate( 'Ymd-His' ) );
	}

	$ok = ( false !== @file_put_contents( $path, $content ) );
	if ( $ok && function_exists( 'opcache_invalidate' ) ) {
		@opcache_invalidate( $path, true );
	}
	if ( $ok && md5( (string) @file_get_contents( $path ) ) !== md5( $content ) ) {
		aav_dt_redirect( 'mu_mismatch', 'mu' );
	}
	aav_dt_log( 'mu_install', $filename . ' (' . $ver_of( $content ) . ')' );
	aav_dt_redirect( $ok ? 'mu_ok' : 'mu_err', 'mu' );
} );

/* Mu-plugin : suppression (avec sauvegarde) */
add_action( 'admin_post_aav_dt_delete_mu', function () {
	aav_dt_guard( 'aav_dt_delete_mu' );

	$name = sanitize_file_name( wp_unslash( $_POST['mu_file'] ?? '' ) );
	if ( ! $name || ! preg_match( '/^[A-Za-z0-9._-]+\.php$/', $name ) ) {
		aav_dt_redirect( 'mu_del_bad', 'mu' );
	}

	$target = aav_dt_resolve_in( aav_dt_mu_dir(), $name );
	if ( '' === $target || ! is_file( $target ) ) {
		aav_dt_redirect( 'mu_del_notfound', 'mu' );
	}

	/* Copie de sécurité : une suppression de mu-plugin n'est pas annulable autrement. */
	$backup = trailingslashit( aav_dt_backup_dir() ) . $name . '.deleted-' . gmdate( 'Ymd-His' );
	if ( ! @copy( $target, $backup ) ) {
		aav_dt_redirect( 'mu_del_bakerr', 'mu' );
	}

	$ok = @unlink( $target );
	if ( ! $ok ) {
		@chmod( $target, 0644 );
		$ok = @unlink( $target );
	}
	if ( $ok && function_exists( 'opcache_invalidate' ) ) {
		@opcache_invalidate( $target, true );
	}
	aav_dt_log( 'mu_delete', $name );
	aav_dt_redirect( $ok ? 'mu_del_ok' : 'mu_del_err', 'mu' );
} );

/* Mu-plugin : restauration depuis une sauvegarde */
add_action( 'admin_post_aav_dt_restore_mu', function () {
	aav_dt_guard( 'aav_dt_restore_mu' );

	$name   = sanitize_file_name( wp_unslash( $_POST['backup_file'] ?? '' ) );
	$source = $name ? aav_dt_resolve_in( aav_dt_backup_dir(), $name ) : '';
	if ( '' === $source || ! is_file( $source ) ) {
		aav_dt_redirect( 'mu_res_notfound', 'mu' );
	}

	/* Nom d'origine : on retire le suffixe .bak-... ou .deleted-... */
	$orig = preg_replace( '/\.(bak|deleted)-[0-9\-]+$/', '', $name );
	if ( ! preg_match( '/^[A-Za-z0-9._-]+\.php$/', $orig ) ) {
		aav_dt_redirect( 'mu_res_bad', 'mu' );
	}

	$dest = trailingslashit( aav_dt_mu_dir() ) . $orig;
	$ok   = @copy( $source, $dest );
	if ( $ok && function_exists( 'opcache_invalidate' ) ) {
		@opcache_invalidate( $dest, true );
	}
	aav_dt_log( 'mu_restore', $orig );
	aav_dt_redirect( $ok ? 'mu_res_ok' : 'mu_res_err', 'mu' );
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

	/* L'extension du fichier envoyé doit correspondre à celle de la cible :
	   évite de déposer un .php à la place d'un .css par mégarde. */
	$ext_target = strtolower( pathinfo( $target, PATHINFO_EXTENSION ) );
	$ext_upload = strtolower( pathinfo( sanitize_file_name( $_FILES['replacement']['name'] ), PATHINFO_EXTENSION ) );
	if ( $ext_target !== $ext_upload && empty( $_POST['ext_force'] ) ) {
		aav_dt_redirect( 'file_ext', 'files' );
	}

	if ( ! @copy( $target, $target . '.bak-' . gmdate( 'Ymd-His' ) ) ) {
		aav_dt_redirect( 'file_bak_err', 'files' );
	}
	$ok = @move_uploaded_file( $_FILES['replacement']['tmp_name'], $target );
	if ( $ok && function_exists( 'opcache_invalidate' ) && 'php' === $ext_target ) {
		@opcache_invalidate( $target, true );
	}
	delete_transient( 'aav_dt_baks' );
	aav_dt_log( 'replace_file', $rel );
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
	delete_transient( 'aav_dt_baks' );
	aav_dt_log( 'delete_file', $rel );
	aav_dt_redirect( $ok ? 'del_ok' : 'del_err', 'files' );
} );

/* Fichiers : purge des .bak */
add_action( 'admin_post_aav_dt_purge_baks', function () {
	aav_dt_guard( 'aav_dt_purge_baks' );
	$done = 0;
	foreach ( aav_dt_find_baks( true ) as $b ) {
		$target = aav_dt_resolve_in_content( $b['rel'] );
		if ( '' !== $target && is_file( $target ) && @unlink( $target ) ) {
			$done++;
		}
	}
	delete_transient( 'aav_dt_baks' );
	aav_dt_log( 'purge_baks', $done . ' fichier(s)' );
	aav_dt_redirect( 'baks_' . $done, 'files' );
} );

/* Maintenance : purge des caches */
add_action( 'admin_post_aav_dt_purge_cache', function () {
	aav_dt_guard( 'aav_dt_purge_cache' );

	$done = array();
	if ( function_exists( 'opcache_reset' ) && @opcache_reset() ) {
		$done[] = 'OPcache';
	}
	if ( function_exists( 'wp_cache_flush' ) && wp_cache_flush() ) {
		$done[] = aav_dt__( 'cache objet', 'object cache' );
	}
	if ( has_action( 'litespeed_purge_all' ) ) { do_action( 'litespeed_purge_all' ); $done[] = 'LiteSpeed'; }
	if ( function_exists( 'rocket_clean_domain' ) ) { rocket_clean_domain(); $done[] = 'WP Rocket'; }
	if ( function_exists( 'w3tc_flush_all' ) ) { w3tc_flush_all(); $done[] = 'W3 Total Cache'; }
	if ( function_exists( 'wp_cache_clear_cache' ) ) { wp_cache_clear_cache(); $done[] = 'WP Super Cache'; }
	if ( function_exists( 'wpfc_clear_all_cache' ) ) { wpfc_clear_all_cache( true ); $done[] = 'WP Fastest Cache'; }
	if ( class_exists( 'Breeze_PurgeCache' ) ) { do_action( 'breeze_clear_all_cache' ); $done[] = 'Breeze'; }
	if ( class_exists( 'SiteGround_Optimizer\Supercacher\Supercacher' ) ) { do_action( 'siteground_optimizer_flush_cache' ); $done[] = 'SiteGround'; }

	delete_transient( 'aav_dt_gh_release' );
	delete_transient( 'aav_dt_baks' );
	$done[] = aav_dt__( 'cache des mises à jour', 'update cache' );

	set_transient( 'aav_dt_purge_report', $done, 60 );
	aav_dt_log( 'purge_cache', implode( ', ', $done ) );
	aav_dt_redirect( 'cache_ok', 'tools' );
} );

/* Maintenance : vérifier les mises à jour */
add_action( 'admin_post_aav_dt_check_update', function () {
	aav_dt_guard( 'aav_dt_check_update', false );
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
	aav_dt_log( 'normalize_blocks', $changed . ' page(s)' );
	aav_dt_redirect( 'norm_' . $changed, 'tools' );
} );

/* =========================================================================
 *  INTERFACE
 * ====================================================================== */

function aav_dt_notice_for( $status ) {
	$map = array(
		'nozip'           => array( 'error',   aav_dt__( "L'extension PHP ZipArchive n'est pas disponible sur ce serveur.", 'The PHP ZipArchive extension is not available on this server.' ) ),
		'zip_err'         => array( 'error',   aav_dt__( "Impossible de créer l'archive.", 'Could not create the archive.' ) ),
		'plg_notfound'    => array( 'error',   aav_dt__( 'Extension inconnue.', 'Unknown plugin.' ) ),
		'ini_ok'          => array( 'success', aav_dt__( 'Limites PHP enregistrées. Comptez quelques minutes pour la prise en compte.', 'PHP limits saved. Allow a few minutes for them to take effect.' ) ),
		'ini_err'         => array( 'error',   aav_dt__( "Échec d'écriture du fichier de limites (droits en écriture ?).", 'Could not write the limits file (write permissions?).' ) ),
		'ini_empty'       => array( 'error',   aav_dt__( 'Aucune valeur valide fournie.', 'No valid value provided.' ) ),
		'mu_ok'           => array( 'success', aav_dt__( 'Mu-plugin installé. Une copie de la version précédente a été placée dans aav-backups.', 'Mu-plugin installed. A copy of the previous version was saved to aav-backups.' ) ),
		'mu_err'          => array( 'error',   aav_dt__( "Échec d'écriture du mu-plugin (droits en écriture ?).", 'Could not write the mu-plugin (write permissions?).' ) ),
		'mu_invalid'      => array( 'error',   aav_dt__( 'Le contenu du mu-plugin doit commencer par <?php.', 'Mu-plugin content must start with <?php.' ) ),
		'mu_mismatch'     => array( 'error',   aav_dt__( 'Écriture incomplète : le contenu relu diffère de celui envoyé (quota ? sécurité de l\'hébergeur ?).', 'Incomplete write: the content read back differs from what was sent (quota? host security?).' ) ),
		'mu_older'        => array( 'warning', aav_dt__( 'Installation annulée : la version envoyée est plus ancienne que celle en place. Cochez « forcer » pour rétrograder volontairement.', 'Install cancelled: the submitted version is older than the one in place. Tick "force" to downgrade on purpose.' ) ),
		'mu_del_ok'       => array( 'success', aav_dt__( 'Mu-plugin supprimé. Une copie a été conservée dans aav-backups : vous pouvez le restaurer ci-dessous.', 'Mu-plugin deleted. A copy was kept in aav-backups: you can restore it below.' ) ),
		'mu_del_err'      => array( 'error',   aav_dt__( 'Suppression impossible (droits du fichier).', 'Could not delete the file (permissions).' ) ),
		'mu_del_bad'      => array( 'error',   aav_dt__( 'Nom de fichier invalide.', 'Invalid file name.' ) ),
		'mu_del_notfound' => array( 'error',   aav_dt__( 'Ce mu-plugin n\'existe pas.', 'This mu-plugin does not exist.' ) ),
		'mu_del_bakerr'   => array( 'error',   aav_dt__( 'Impossible de créer la copie de sécurité : suppression annulée.', 'Could not create the safety copy: deletion cancelled.' ) ),
		'mu_res_ok'       => array( 'success', aav_dt__( 'Mu-plugin restauré.', 'Mu-plugin restored.' ) ),
		'mu_res_err'      => array( 'error',   aav_dt__( 'Restauration impossible.', 'Restore failed.' ) ),
		'mu_res_bad'      => array( 'error',   aav_dt__( "Nom d'origine introuvable dans le nom de la sauvegarde.", 'Could not determine the original file name from the backup.' ) ),
		'mu_res_notfound' => array( 'error',   aav_dt__( 'Sauvegarde introuvable.', 'Backup not found.' ) ),
		'file_ok'         => array( 'success', aav_dt__( 'Fichier remplacé. Une sauvegarde .bak horodatée a été créée à côté.', 'File replaced. A timestamped .bak backup was created next to it.' ) ),
		'file_err'        => array( 'error',   aav_dt__( "Échec d'écriture du fichier (droits ?). La sauvegarde .bak a été créée.", 'Could not write the file (permissions?). The .bak backup was created.' ) ),
		'file_bak_err'    => array( 'error',   aav_dt__( 'Impossible de créer la sauvegarde .bak : remplacement annulé par prudence.', 'Could not create the .bak backup: replacement cancelled as a precaution.' ) ),
		'file_badpath'    => array( 'error',   aav_dt__( 'Chemin invalide (relatif à wp-content, sans « .. »).', 'Invalid path (relative to wp-content, no "..").' ) ),
		'file_notfound'   => array( 'error',   aav_dt__( "Le fichier cible n'existe pas dans wp-content.", 'The target file does not exist in wp-content.' ) ),
		'file_noupload'   => array( 'error',   aav_dt__( 'Aucun fichier téléversé.', 'No file uploaded.' ) ),
		'file_ext'        => array( 'warning', aav_dt__( "L'extension du fichier envoyé ne correspond pas à celle du fichier cible. Cochez la case pour forcer.", 'The uploaded file extension does not match the target file. Tick the box to force it.' ) ),
		'del_ok'          => array( 'success', aav_dt__( 'Fichier supprimé.', 'File deleted.' ) ),
		'del_err'         => array( 'error',   aav_dt__( "Suppression impossible : le fichier appartient à un autre utilisateur système. Passez par le gestionnaire de fichiers de l'hébergeur.", 'Could not delete: the file belongs to another system user. Use your host file manager.' ) ),
		'del_badpath'     => array( 'error',   aav_dt__( 'Chemin invalide (relatif à wp-content, sans « .. »).', 'Invalid path (relative to wp-content, no "..").' ) ),
		'del_notfound'    => array( 'error',   aav_dt__( "Le fichier n'existe pas dans wp-content.", 'The file does not exist in wp-content.' ) ),
		'del_notafile'    => array( 'error',   aav_dt__( 'La cible est un dossier : seuls les fichiers peuvent être supprimés ici.', 'The target is a directory: only files can be deleted here.' ) ),
		'upd_checked'     => array( 'success', aav_dt__( 'Vérification des mises à jour relancée. Rendez-vous dans Extensions pour voir le résultat.', 'Update check triggered. Go to Plugins to see the result.' ) ),
	);

	if ( isset( $map[ $status ] ) ) {
		return $map[ $status ];
	}
	if ( 'cache_ok' === $status ) {
		$rep = get_transient( 'aav_dt_purge_report' );
		return array( 'success', aav_dt__( 'Caches vidés : ', 'Caches cleared: ' ) . ( $rep ? implode( ', ', $rep ) : aav_dt__( 'aucun cache détecté', 'no cache detected' ) ) . '.' );
	}
	if ( 0 === strpos( $status, 'norm_' ) ) {
		$n = (int) substr( $status, 5 );
		return array( 'success', $n
			? sprintf( aav_dt__( '%d page(s) normalisée(s) : les blocs AAV s\'ouvriront en mode édition.', '%d page(s) normalised: AAV blocks will open in edit mode.' ), $n )
			: aav_dt__( 'Aucune page à modifier : tous les blocs AAV sont déjà en mode édition.', 'Nothing to change: all AAV blocks already open in edit mode.' ) );
	}
	if ( 0 === strpos( $status, 'baks_' ) ) {
		$n = (int) substr( $status, 5 );
		return array( $n ? 'success' : 'warning', sprintf( aav_dt__( '%d sauvegarde(s) .bak supprimée(s).', '%d .bak backup(s) deleted.' ), $n ) );
	}
	return null;
}

function aav_dt_card_open( $title, $desc = '' ) {
	echo '<div class="card" style="max-width:920px;padding:4px 20px 16px;margin:0 0 18px;">';
	echo '<h2 style="margin-top:14px;">' . esc_html( $title ) . '</h2>';
	if ( $desc ) {
		echo '<p class="description" style="margin-bottom:14px;">' . wp_kses_post( $desc ) . '</p>';
	}
}
function aav_dt_card_close() {
	echo '</div>';
}

/** Sauvegardes du toolkit (mu-plugins remplacés ou supprimés). */
function aav_dt_list_backups() {
	$dir = AAV_DT_BACKUP_DIR;
	if ( ! is_dir( $dir ) ) {
		return array();
	}
	$out = array();
	foreach ( array_diff( scandir( $dir ), array( '.', '..', 'index.php', '.htaccess' ) ) as $f ) {
		$p = trailingslashit( $dir ) . $f;
		if ( is_file( $p ) ) {
			$out[] = array( 'name' => $f, 'size' => filesize( $p ), 'time' => filemtime( $p ) );
		}
	}
	usort( $out, function ( $a, $b ) { return $b['time'] - $a['time']; } );
	return $out;
}

function aav_dt_render_page() {
	if ( ! current_user_can( AAV_DT_CAP ) ) {
		wp_die( esc_html( aav_dt__( 'Accès refusé.', 'Access denied.' ) ), '', array( 'response' => 403 ) );
	}

	$tabs = aav_dt_tabs();
	$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'diag';
	if ( ! isset( $tabs[ $tab ] ) ) {
		$tab = 'diag';
	}
	$status = isset( $_GET['aav_status'] ) ? sanitize_key( wp_unslash( $_GET['aav_status'] ) ) : '';
	$notice = $status ? aav_dt_notice_for( $status ) : null;
	$action = esc_url( admin_url( 'admin-post.php' ) );
	$baks   = aav_dt_find_baks();
	$locked = aav_dt_locked();
	?>
	<div class="wrap">
		<h1 style="display:flex;align-items:baseline;gap:10px;">
			AAV Dev Toolkit
			<span style="font-size:13px;color:#666;font-weight:400;">v<?php echo esc_html( AAV_DT_VERSION ); ?></span>
		</h1>

		<?php if ( $notice ) : ?>
			<div class="notice notice-<?php echo esc_attr( $notice[0] ); ?> is-dismissible"><p><?php echo esc_html( $notice[1] ); ?></p></div>
		<?php endif; ?>

		<?php if ( $locked ) : ?>
			<div class="notice notice-info">
				<p><strong><?php aav_dt_e( 'Mode lecture seule.', 'Read-only mode.' ); ?></strong>
				<?php aav_dt_e(
					'La constante AAV_DT_LOCK est définie dans wp-config.php : les opérations d\'écriture sont désactivées.',
					'The AAV_DT_LOCK constant is set in wp-config.php: write operations are disabled.'
				); ?></p>
			</div>
		<?php endif; ?>

		<?php if ( $baks && 'files' !== $tab ) : ?>
			<div class="notice notice-warning">
				<p><?php printf(
					esc_html( aav_dt__( '%d sauvegarde(s) .bak traînent dans wp-content : elles bloquent les mises à jour d\'extensions.', '%d .bak backup(s) left in wp-content: they block plugin updates.' ) ),
					count( $baks )
				); ?>
				<a href="<?php echo esc_url( aav_dt_url( 'files' ) ); ?>"><?php aav_dt_e( 'Voir l\'onglet Fichiers', 'Go to the Files tab' ); ?></a></p>
			</div>
		<?php endif; ?>

		<h2 class="nav-tab-wrapper" style="margin-bottom:18px;">
			<?php foreach ( $tabs as $k => $label ) :
				$cls = 'nav-tab' . ( $k === $tab ? ' nav-tab-active' : '' ); ?>
				<a class="<?php echo esc_attr( $cls ); ?>" href="<?php echo esc_url( aav_dt_url( $k ) ); ?>"><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</h2>

		<?php
		/* ------------------------------------------------------- DIAGNOSTIC */
		if ( 'diag' === $tab ) {
			aav_dt_card_open(
				aav_dt__( 'État du site', 'Site status' ),
				aav_dt__( "Photographie de l'environnement. Utile avant d'intervenir, et à copier-coller dans un ticket de support.", 'A snapshot of the environment. Useful before making changes, and to paste into a support ticket.' )
			);
			$diag = aav_dt_diagnostics();
			echo '<table class="widefat striped"><tbody>';
			foreach ( $diag as $k => $v ) {
				$flag = ( in_array( $v, array( 'ACTIF', 'ENABLED' ), true ) ) ? ' style="color:#b26b00;font-weight:600"' : '';
				printf( '<tr><td style="width:240px;"><strong>%s</strong></td><td%s>%s</td></tr>', esc_html( $k ), $flag, esc_html( $v ) );
			}
			echo '</tbody></table>';
			$txt = '';
			foreach ( $diag as $k => $v ) {
				$txt .= $k . ' : ' . $v . "\n";
			}
			echo '<p style="margin-top:14px;"><strong>' . esc_html( aav_dt__( 'Version texte', 'Plain text' ) ) . '</strong></p>';
			echo '<textarea rows="6" class="large-text code" readonly onclick="this.select()">' . esc_textarea( $txt ) . '</textarea>';
			aav_dt_card_close();

			$log = get_option( 'aav_dt_log', array() );
			if ( is_array( $log ) && $log ) {
				aav_dt_card_open(
					aav_dt__( 'Journal des opérations', 'Operations log' ),
					aav_dt__( 'Les 30 dernières opérations sensibles réalisées avec cet outil.', 'The last 30 sensitive operations performed with this tool.' )
				);
				echo '<table class="widefat striped"><thead><tr>'
					. '<th>' . esc_html( aav_dt__( 'Date (UTC)', 'Date (UTC)' ) ) . '</th>'
					. '<th>' . esc_html( aav_dt__( 'Utilisateur', 'User' ) ) . '</th>'
					. '<th>' . esc_html( aav_dt__( 'Opération', 'Operation' ) ) . '</th>'
					. '<th>' . esc_html( aav_dt__( 'Détail', 'Detail' ) ) . '</th></tr></thead><tbody>';
				foreach ( $log as $l ) {
					printf(
						'<tr><td>%s</td><td>%s</td><td><code>%s</code></td><td>%s</td></tr>',
						esc_html( gmdate( 'Y-m-d H:i', (int) $l['time'] ) ),
						esc_html( $l['user'] ),
						esc_html( $l['what'] ),
						esc_html( $l['detail'] )
					);
				}
				echo '</tbody></table>';
				aav_dt_card_close();
			}

		/* ----------------------------------------------------------- EXPORT */
		} elseif ( 'export' === $tab ) {
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$plugins = get_plugins();
			$actives = (array) get_option( 'active_plugins', array() );

			aav_dt_card_open(
				aav_dt__( 'Exporter le thème', 'Export the theme' ),
				aav_dt__( 'Zip complet du thème actif, sans <code>node_modules</code> ni <code>.git</code>.', 'Full zip of the active theme, without <code>node_modules</code> or <code>.git</code>.' )
			);
			?>
			<form method="post" action="<?php echo $action; ?>">
				<?php wp_nonce_field( 'aav_dt_download_theme' ); ?>
				<input type="hidden" name="action" value="aav_dt_download_theme">
				<?php submit_button( aav_dt__( 'Télécharger ', 'Download ' ) . basename( get_template_directory() ) . ' (.zip)', 'primary', 'submit', false ); ?>
			</form>
			<?php
			aav_dt_card_close();

			aav_dt_card_open(
				aav_dt__( 'Exporter une extension', 'Export a plugin' ),
				aav_dt__( "Pour archiver l'état réel du site ou alimenter un dépôt Git. Attention : l'archive peut contenir des identifiants présents dans le code.", 'To archive the real state of the site or feed a Git repository. Note: the archive may contain credentials present in the code.' )
			);
			?>
			<form method="post" action="<?php echo $action; ?>">
				<?php wp_nonce_field( 'aav_dt_download_plugin' ); ?>
				<input type="hidden" name="action" value="aav_dt_download_plugin">
				<select name="plugin_file" style="min-width:460px;">
					<?php foreach ( $plugins as $pfile => $pdata ) :
						$state = in_array( $pfile, $actives, true ) ? '' : aav_dt__( ' (inactive)', ' (inactive)' ); ?>
						<option value="<?php echo esc_attr( $pfile ); ?>"><?php echo esc_html( $pdata['Name'] . ' ' . $pdata['Version'] . $state ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php submit_button( aav_dt__( 'Télécharger', 'Download' ), 'secondary', 'submit', false ); ?>
			</form>
			<?php
			aav_dt_card_close();

		/* ---------------------------------------------------------- FICHIERS */
		} elseif ( 'files' === $tab ) {

			if ( $baks ) {
				aav_dt_card_open(
					aav_dt__( 'Sauvegardes .bak détectées', '.bak backups found' ),
					aav_dt__( "Ces fichiers <strong>bloquent les mises à jour d'extensions</strong> lorsqu'ils se trouvent dans leur dossier.", 'These files <strong>block plugin updates</strong> when they sit inside a plugin folder.' )
				);
				echo '<table class="widefat striped"><thead><tr><th>' . esc_html( aav_dt__( 'Fichier', 'File' ) ) . '</th><th>'
					. esc_html( aav_dt__( 'Taille', 'Size' ) ) . '</th><th>' . esc_html( aav_dt__( 'Date', 'Date' ) ) . '</th></tr></thead><tbody>';
				foreach ( $baks as $b ) {
					printf(
						'<tr><td><code>%s</code></td><td>%s</td><td>%s</td></tr>',
						esc_html( $b['rel'] ), esc_html( size_format( $b['size'] ) ), esc_html( gmdate( 'Y-m-d H:i', $b['time'] ) )
					);
				}
				echo '</tbody></table>';
				if ( ! $locked ) : ?>
					<form method="post" action="<?php echo $action; ?>" style="margin-top:12px;"
					      onsubmit="return confirm('<?php echo esc_js( aav_dt__( 'Supprimer toutes les sauvegardes .bak ?', 'Delete all .bak backups?' ) ); ?>');">
						<?php wp_nonce_field( 'aav_dt_purge_baks' ); ?>
						<input type="hidden" name="action" value="aav_dt_purge_baks">
						<?php submit_button( aav_dt__( 'Tout supprimer', 'Delete all' ), 'delete', 'submit', false ); ?>
					</form>
				<?php endif;
				aav_dt_card_close();
			}

			aav_dt_card_open(
				aav_dt__( 'Remplacer un fichier', 'Replace a file' ),
				aav_dt__( 'Chemin relatif à <code>wp-content</code>, par exemple <code>themes/aav/assets/app.min.js</code>. Une sauvegarde <code>.bak</code> horodatée est créée avant remplacement.', 'Path relative to <code>wp-content</code>, e.g. <code>themes/aav/assets/app.min.js</code>. A timestamped <code>.bak</code> backup is created before replacing.' )
			);
			if ( $locked ) {
				echo '<p><em>' . esc_html( aav_dt__( 'Désactivé en mode lecture seule.', 'Disabled in read-only mode.' ) ) . '</em></p>';
			} else { ?>
				<form method="post" action="<?php echo $action; ?>" enctype="multipart/form-data">
					<?php wp_nonce_field( 'aav_dt_replace_file' ); ?>
					<input type="hidden" name="action" value="aav_dt_replace_file">
					<p><input name="target_path" type="text" class="large-text" placeholder="plugins/aav-landing-blocks/aav-landing-blocks.php" required></p>
					<p><input name="replacement" type="file" required></p>
					<p><label><input type="checkbox" name="ext_force" value="1"> <?php aav_dt_e( 'Autoriser une extension de fichier différente de la cible', 'Allow a file extension different from the target' ); ?></label></p>
					<?php submit_button( aav_dt__( 'Remplacer', 'Replace' ), 'primary', 'submit', false ); ?>
				</form>
			<?php }
			aav_dt_card_close();

			aav_dt_card_open(
				aav_dt__( 'Supprimer un fichier', 'Delete a file' ),
				aav_dt__( 'Fichiers uniquement, jamais de dossiers. Résidus de migration, sauvegardes, fichiers obsolètes.', 'Files only, never directories. Migration leftovers, backups, obsolete files.' )
			);
			if ( $locked ) {
				echo '<p><em>' . esc_html( aav_dt__( 'Désactivé en mode lecture seule.', 'Disabled in read-only mode.' ) ) . '</em></p>';
			} else { ?>
				<form method="post" action="<?php echo $action; ?>" onsubmit="return confirm('<?php echo esc_js( aav_dt__( 'Supprimer définitivement ce fichier ?', 'Permanently delete this file?' ) ); ?>');">
					<?php wp_nonce_field( 'aav_dt_delete_file' ); ?>
					<input type="hidden" name="action" value="aav_dt_delete_file">
					<p><input name="delete_path" type="text" class="large-text" placeholder="plugins/mon-extension/fichier.php.bak-20260902-120703" required></p>
					<?php submit_button( aav_dt__( 'Supprimer', 'Delete' ), 'delete', 'submit', false ); ?>
				</form>
			<?php }
			aav_dt_card_close();

		/* -------------------------------------------------------- MU-PLUGINS */
		} elseif ( 'mu' === $tab ) {
			$mu_dir   = aav_dt_mu_dir();
			$writable = is_dir( $mu_dir ) ? is_writable( $mu_dir ) : is_writable( dirname( $mu_dir ) );
			$existing = is_dir( $mu_dir ) ? array_values( array_diff( scandir( $mu_dir ), array( '.', '..' ) ) ) : array();

			aav_dt_card_open(
				aav_dt__( 'Mu-plugins installés', 'Installed mu-plugins' ),
				aav_dt__( "Les mu-plugins sont activés automatiquement et n'apparaissent pas dans la liste des extensions.", 'Mu-plugins are always active and do not appear in the plugins list.' )
			);
			printf(
				'<p><code>%s</code> : %s</p>',
				esc_html( $mu_dir ),
				$writable
					? '<strong style="color:#00a32a">' . esc_html( aav_dt__( 'accessible en écriture', 'writable' ) ) . '</strong>'
					: '<strong style="color:#d63638">' . esc_html( aav_dt__( 'NON accessible en écriture', 'NOT writable' ) ) . '</strong>'
			);
			if ( $existing ) {
				echo '<table class="widefat striped"><thead><tr>'
					. '<th>' . esc_html( aav_dt__( 'Fichier', 'File' ) ) . '</th>'
					. '<th>' . esc_html( aav_dt__( 'Version sur le disque', 'Version on disk' ) ) . '</th>'
					. '<th>' . esc_html( aav_dt__( 'Modifié le (UTC)', 'Modified (UTC)' ) ) . '</th>'
					. '<th>' . esc_html( aav_dt__( 'Empreinte', 'Checksum' ) ) . '</th>'
					. '<th>' . esc_html( aav_dt__( 'Action', 'Action' ) ) . '</th></tr></thead><tbody>';
				foreach ( $existing as $f ) {
					$p = trailingslashit( $mu_dir ) . $f;
					if ( ! is_file( $p ) ) {
						continue;
					}
					$head = (string) @file_get_contents( $p, false, null, 0, 2048 );
					$ver  = preg_match( '/^\s*\*\s*Version:\s*(.+)$/mi', $head, $m ) ? trim( $m[1] ) : 'n/a';
					echo '<tr><td><code>' . esc_html( $f ) . '</code></td>';
					echo '<td><strong>' . esc_html( $ver ) . '</strong></td>';
					echo '<td>' . esc_html( gmdate( 'Y-m-d H:i:s', (int) @filemtime( $p ) ) ) . '</td>';
					echo '<td><code>' . esc_html( substr( md5( (string) @file_get_contents( $p ) ), 0, 8 ) ) . '</code></td>';
					echo '<td>';
					if ( ! $locked && preg_match( '/\.php$/i', $f ) ) {
						?>
						<form method="post" action="<?php echo $action; ?>" style="margin:0;"
						      onsubmit="return confirm('<?php echo esc_js( aav_dt__( 'Supprimer ce mu-plugin ? Une copie sera conservée dans aav-backups.', 'Delete this mu-plugin? A copy will be kept in aav-backups.' ) ); ?>');">
							<?php wp_nonce_field( 'aav_dt_delete_mu' ); ?>
							<input type="hidden" name="action" value="aav_dt_delete_mu">
							<input type="hidden" name="mu_file" value="<?php echo esc_attr( $f ); ?>">
							<button type="submit" class="button button-link-delete"><?php aav_dt_e( 'Supprimer', 'Delete' ); ?></button>
						</form>
						<?php
					} else {
						echo '&mdash;';
					}
					echo '</td></tr>';
				}
				echo '</tbody></table>';
			} else {
				echo '<p><em>' . esc_html( aav_dt__( 'Aucun mu-plugin installé.', 'No mu-plugin installed.' ) ) . '</em></p>';
			}
			aav_dt_card_close();

			$backups = aav_dt_list_backups();
			if ( $backups ) {
				aav_dt_card_open(
					aav_dt__( 'Sauvegardes de mu-plugins', 'Mu-plugin backups' ),
					aav_dt__( 'Copies conservées avant chaque remplacement ou suppression, dans <code>wp-content/aav-backups</code>.', 'Copies kept before every replacement or deletion, in <code>wp-content/aav-backups</code>.' )
				);
				echo '<table class="widefat striped"><thead><tr>'
					. '<th>' . esc_html( aav_dt__( 'Sauvegarde', 'Backup' ) ) . '</th>'
					. '<th>' . esc_html( aav_dt__( 'Taille', 'Size' ) ) . '</th>'
					. '<th>' . esc_html( aav_dt__( 'Date (UTC)', 'Date (UTC)' ) ) . '</th>'
					. '<th>' . esc_html( aav_dt__( 'Action', 'Action' ) ) . '</th></tr></thead><tbody>';
				foreach ( $backups as $b ) {
					echo '<tr><td><code>' . esc_html( $b['name'] ) . '</code></td>';
					echo '<td>' . esc_html( size_format( $b['size'] ) ) . '</td>';
					echo '<td>' . esc_html( gmdate( 'Y-m-d H:i', $b['time'] ) ) . '</td>';
					echo '<td>';
					if ( ! $locked ) {
						?>
						<form method="post" action="<?php echo $action; ?>" style="margin:0;"
						      onsubmit="return confirm('<?php echo esc_js( aav_dt__( 'Restaurer cette sauvegarde dans mu-plugins ?', 'Restore this backup into mu-plugins?' ) ); ?>');">
							<?php wp_nonce_field( 'aav_dt_restore_mu' ); ?>
							<input type="hidden" name="action" value="aav_dt_restore_mu">
							<input type="hidden" name="backup_file" value="<?php echo esc_attr( $b['name'] ); ?>">
							<button type="submit" class="button"><?php aav_dt_e( 'Restaurer', 'Restore' ); ?></button>
						</form>
						<?php
					} else {
						echo '&mdash;';
					}
					echo '</td></tr>';
				}
				echo '</tbody></table>';
				aav_dt_card_close();
			}

			aav_dt_card_open(
				aav_dt__( 'Installer ou mettre à jour', 'Install or update' ),
				aav_dt__( "Le champ est pré-rempli avec la dernière version connue du correctif de navigation (v1.4). Installer une version <strong>antérieure</strong> à celle du disque est refusé, sauf si vous cochez la case de forçage.", 'The field is pre-filled with the latest known version of the navigation fix (v1.4). Installing a version <strong>older</strong> than the one on disk is refused unless you tick the force box.' )
			);
			if ( $locked ) {
				echo '<p><em>' . esc_html( aav_dt__( 'Désactivé en mode lecture seule.', 'Disabled in read-only mode.' ) ) . '</em></p>';
			} else { ?>
				<form method="post" action="<?php echo $action; ?>">
					<?php wp_nonce_field( 'aav_dt_install_mu' ); ?>
					<input type="hidden" name="action" value="aav_dt_install_mu">
					<p>
						<label for="mu_filename"><strong><?php aav_dt_e( 'Nom du fichier', 'File name' ); ?></strong></label><br>
						<input name="mu_filename" id="mu_filename" type="text" class="regular-text" value="aav-scroll-refresh.php">
					</p>
					<p>
						<label for="mu_content"><strong><?php aav_dt_e( 'Contenu', 'Content' ); ?></strong></label><br>
						<textarea name="mu_content" id="mu_content" rows="16" class="large-text code" spellcheck="false"><?php echo esc_textarea( aav_dt_default_mu() ); ?></textarea>
					</p>
					<p><label><input type="checkbox" name="mu_force" value="1"> <?php aav_dt_e( 'Forcer même si la version envoyée est plus ancienne', 'Force even if the submitted version is older' ); ?></label></p>
					<?php submit_button( aav_dt__( 'Installer le mu-plugin', 'Install mu-plugin' ), 'primary', 'submit', false ); ?>
				</form>
			<?php }
			aav_dt_card_close();

		/* -------------------------------------------------------- LIMITES PHP */
		} elseif ( 'php' === $tab ) {
			$sapi     = php_sapi_name();
			$ini_keys = array(
				'upload_max_filesize' => aav_dt__( "Taille maximale d'un fichier téléversé", 'Maximum upload file size' ),
				'post_max_size'       => aav_dt__( "Taille maximale d'une requête POST", 'Maximum POST size' ),
				'memory_limit'        => aav_dt__( 'Limite mémoire', 'Memory limit' ),
				'max_execution_time'  => aav_dt__( "Temps d'exécution maximum (secondes)", 'Maximum execution time (seconds)' ),
			);
			aav_dt_card_open(
				aav_dt__( 'Limites PHP', 'PHP limits' ),
				sprintf(
					aav_dt__(
						'SAPI : <code>%1$s</code> &middot; php.ini chargé : <code>%2$s</code>. php.ini n\'est pas modifiable depuis PHP : on écrit un <code>.user.ini</code>%3$s. Comptez quelques minutes pour la prise en compte.',
						'SAPI: <code>%1$s</code> &middot; loaded php.ini: <code>%2$s</code>. php.ini cannot be edited from PHP, so a <code>.user.ini</code> is written%3$s. Allow a few minutes for it to take effect.'
					),
					esc_html( $sapi ),
					esc_html( php_ini_loaded_file() ?: '(n/a)' ),
					( false !== stripos( $sapi, 'apache' ) ) ? aav_dt__( ' et un bloc <code>.htaccess</code>', ' plus an <code>.htaccess</code> block' ) : ''
				)
			);
			if ( $locked ) {
				echo '<p><em>' . esc_html( aav_dt__( 'Désactivé en mode lecture seule.', 'Disabled in read-only mode.' ) ) . '</em></p>';
			} else { ?>
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
									<p class="description">
										<?php aav_dt_e( 'Actuel :', 'Current:' ); ?> <code><?php echo esc_html( ini_get( $key ) ); ?></code>.
										<?php aav_dt_e( 'Ex : 64M, 128M, 300. Vide = inchangé.', 'E.g. 64M, 128M, 300. Empty = unchanged.' ); ?>
									</p>
								</td>
							</tr>
						<?php endforeach; ?>
					</table>
					<?php submit_button( aav_dt__( 'Appliquer les limites', 'Apply limits' ), 'primary', 'submit', false ); ?>
				</form>
			<?php }
			aav_dt_card_close();

		/* -------------------------------------------------------- MAINTENANCE */
		} elseif ( 'tools' === $tab ) {
			$caches = aav_dt_cache_plugins();

			aav_dt_card_open(
				aav_dt__( 'Vider les caches', 'Clear caches' ),
				aav_dt__( 'OPcache, cache objet, extensions de cache détectées et cache des mises à jour. À lancer après tout remplacement de fichier. ', 'OPcache, object cache, detected caching plugins and the update cache. Run it after every file replacement. ' )
				. ( $caches
					? aav_dt__( 'Détecté ici : <strong>', 'Detected here: <strong>' ) . esc_html( implode( ', ', $caches ) ) . '</strong>.'
					: aav_dt__( 'Aucune extension de cache détectée.', 'No caching plugin detected.' ) )
			);
			if ( $locked ) {
				echo '<p><em>' . esc_html( aav_dt__( 'Désactivé en mode lecture seule.', 'Disabled in read-only mode.' ) ) . '</em></p>';
			} else { ?>
				<form method="post" action="<?php echo $action; ?>">
					<?php wp_nonce_field( 'aav_dt_purge_cache' ); ?>
					<input type="hidden" name="action" value="aav_dt_purge_cache">
					<?php submit_button( aav_dt__( 'Vider tous les caches', 'Clear all caches' ), 'primary', 'submit', false ); ?>
				</form>
			<?php }
			aav_dt_card_close();

			aav_dt_card_open(
				aav_dt__( 'Mises à jour des extensions', 'Plugin updates' ),
				aav_dt__( "Force WordPress à réinterroger les dépôts, dont GitHub pour les extensions AAV. Utile juste après avoir publié une release.", 'Forces WordPress to re-check repositories, including GitHub for the AAV plugins. Useful right after publishing a release.' )
			);
			?>
			<form method="post" action="<?php echo $action; ?>">
				<?php wp_nonce_field( 'aav_dt_check_update' ); ?>
				<input type="hidden" name="action" value="aav_dt_check_update">
				<?php submit_button( aav_dt__( 'Vérifier les mises à jour maintenant', 'Check for updates now' ), 'secondary', 'submit', false ); ?>
			</form>
			<?php
			aav_dt_card_close();

			aav_dt_card_open(
				aav_dt__( 'Blocs AAV : forcer le mode édition', 'AAV blocks: force edit mode' ),
				aav_dt__( "Le mode aperçu/édition est mémorisé dans la page à l'insertion du bloc. <strong>Fermez les éditeurs de pages ouverts avant de lancer l'opération.</strong>", 'The preview/edit mode is stored in the page when the block is inserted. <strong>Close any open page editors before running this.</strong>' )
			);
			if ( $locked ) {
				echo '<p><em>' . esc_html( aav_dt__( 'Désactivé en mode lecture seule.', 'Disabled in read-only mode.' ) ) . '</em></p>';
			} else { ?>
				<form method="post" action="<?php echo $action; ?>">
					<?php wp_nonce_field( 'aav_dt_normalize_mode' ); ?>
					<input type="hidden" name="action" value="aav_dt_normalize_mode">
					<?php submit_button( aav_dt__( 'Normaliser tous les blocs AAV', 'Normalise all AAV blocks' ), 'secondary', 'submit', false ); ?>
				</form>
			<?php }
			aav_dt_card_close();

		}
		?>

		<p style="max-width:920px;margin-top:24px;background:#fff3cd;border:1px solid #ffe69c;padding:10px 14px;border-radius:4px;font-size:13px;">
			<strong><?php aav_dt_e( 'Rappel.', 'Reminder.' ); ?></strong>
			<?php aav_dt_e(
				"Cet outil dépose du code exécutable et modifie des fichiers. Sur un site en production, retirez-le une fois l'intervention terminée, ou définissez AAV_DT_LOCK à true dans wp-config.php pour le passer en lecture seule.",
				'This tool writes executable code and modifies files. On a live site, remove it once your work is done, or set AAV_DT_LOCK to true in wp-config.php to make it read-only.'
			); ?>
		</p>
	</div>
	<?php
}

/* =========================================================================
 *  MISES À JOUR DEPUIS GITHUB
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
			set_transient( $cache_key, array(), 15 * MINUTE_IN_SECONDS );
			return $update;
		}
		$release = json_decode( wp_remote_retrieve_body( $res ), true );
		set_transient( $cache_key, $release, 6 * HOUR_IN_SECONDS );
	}

	if ( empty( $release['tag_name'] ) ) {
		return $update;
	}

	$remote = ltrim( $release['tag_name'], 'vV' );
	if ( ! preg_match( '/^\d+(\.\d+)*$/', $remote ) ) {
		return $update; // tag non conforme : on ignore
	}
	if ( version_compare( $remote, $plugin_data['Version'], '<=' ) ) {
		return $update;
	}

	$package = '';
	if ( ! empty( $release['assets'] ) && is_array( $release['assets'] ) ) {
		foreach ( $release['assets'] as $asset ) {
			$url = isset( $asset['browser_download_url'] ) ? $asset['browser_download_url'] : '';
			// L'archive doit venir de github.com et être un zip.
			if ( $url && preg_match( '#^https://github\.com/#', $url ) && preg_match( '/\.zip$/i', $url ) ) {
				$package = $url;
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
	delete_transient( 'aav_dt_baks' );
} );
