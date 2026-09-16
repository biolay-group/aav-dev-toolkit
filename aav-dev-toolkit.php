<?php
/**
 * Plugin Name: AAV Dev Toolkit
 * Description: Outils d'administration pour le site AAV, sans acces FTP : export du
 *              theme et des extensions (zip), limites PHP (.user.ini / .htaccess),
 *              installation de mu-plugins, remplacement et suppression de fichiers
 *              dans wp-content, normalisation des blocs AAV. Reserve aux administrateurs.
 * Version:     1.6.0
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

define( 'AAV_DT_VERSION', '1.6.0' );
define( 'AAV_DT_CAP', 'manage_options' );   // seuls les admins
define( 'AAV_DT_SLUG', 'aav-devtools' );    // slug de la page d'admin
define( 'AAV_DT_FILE', plugin_basename( __FILE__ ) );
define( 'AAV_DT_REPO', 'biolay-group/aav-dev-toolkit' );

/* -------------------------------------------------------------------------
 *  Menu (sous "Outils")
 * ---------------------------------------------------------------------- */
add_action( 'admin_menu', function () {
	add_management_page(
		'AAV Dev Toolkit',
		'AAV Dev Toolkit',
		AAV_DT_CAP,
		AAV_DT_SLUG,
		'aav_dt_render_page'
	);
} );

/* =========================================================================
 *  HELPERS
 * ====================================================================== */

/** Garde commune a toutes les actions : droits + nonce. */
function aav_dt_guard( $nonce_action ) {
	if ( ! current_user_can( AAV_DT_CAP ) ) {
		wp_die( esc_html__( 'Acces refuse.', 'default' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( $nonce_action );
}

/** Retour a la page du toolkit avec un code de statut. */
function aav_dt_redirect( $status ) {
	wp_safe_redirect( add_query_arg(
		array( 'page' => AAV_DT_SLUG, 'aav_status' => rawurlencode( $status ) ),
		admin_url( 'tools.php' )
	) );
	exit;
}

/** Normalise un chemin relatif fourni par l'utilisateur (refuse les remontees). */
function aav_dt_clean_rel( $raw ) {
	$rel = ltrim( str_replace( '\\', '/', trim( (string) $raw ) ), '/' );
	if ( '' === $rel || false !== strpos( $rel, '..' ) ) {
		return '';
	}
	return $rel;
}

/** Resout un chemin dans wp-content et verifie qu'il n'en sort pas. */
function aav_dt_resolve_in_content( $rel ) {
	$base   = realpath( WP_CONTENT_DIR );
	$target = realpath( WP_CONTENT_DIR . '/' . $rel );
	if ( ! $base || ! $target || 0 !== strpos( $target, $base ) ) {
		return '';
	}
	return $target;
}

/** Ecrit un bloc delimite par des marqueurs dans un fichier (remplace s'il existe). */
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

/** Zippe un dossier et l'envoie au navigateur (puis termine la requete). */
function aav_dt_stream_zip_dir( $dir, $name ) {
	if ( ! class_exists( 'ZipArchive' ) ) {
		aav_dt_redirect( 'nozip' );
	}
	@set_time_limit( 300 );

	$tmp = wp_tempnam( $name . '.zip' );
	$zip = new ZipArchive();
	if ( true !== $zip->open( $tmp, ZipArchive::OVERWRITE ) ) {
		aav_dt_redirect( 'zip_err' );
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

/** Envoie un fichier en telechargement puis termine la requete. */
function aav_dt_stream_file( $path, $filename ) {
	nocache_headers();
	header( 'Content-Type: application/zip' );
	header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
	header( 'Content-Length: ' . filesize( $path ) );
	readfile( $path );
	@unlink( $path );
	exit;
}

/** Liste les fichiers .bak deposes par le toolkit (ils bloquent les mises a jour). */
function aav_dt_find_baks() {
	$found = array();
	$roots = array( WP_PLUGIN_DIR, get_theme_root() );
	foreach ( $roots as $root ) {
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
					$rel = ltrim( str_replace( array( WP_CONTENT_DIR, '\\' ), array( '', '/' ), $f->getPathname() ), '/' );
					$found[] = array( 'rel' => $rel, 'size' => $f->getSize(), 'time' => $f->getMTime() );
				}
			}
		} catch ( Exception $e ) {
			continue;
		}
	}
	return $found;
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

/* 1) Export du theme actif */
add_action( 'admin_post_aav_dt_download_theme', function () {
	aav_dt_guard( 'aav_dt_download_theme' );
	$dir = get_template_directory();
	aav_dt_stream_zip_dir( $dir, basename( $dir ) );
} );

/* 1b) Export d'une extension installee */
add_action( 'admin_post_aav_dt_download_plugin', function () {
	aav_dt_guard( 'aav_dt_download_plugin' );

	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$pf  = isset( $_POST['plugin_file'] ) ? wp_unslash( $_POST['plugin_file'] ) : '';
	$all = get_plugins();
	if ( ! isset( $all[ $pf ] ) ) {
		aav_dt_redirect( 'plg_notfound' );
	}

	$dirname = dirname( $pf );
	if ( '.' === $dirname ) {
		// extension d'un seul fichier : zip a la volee
		if ( ! class_exists( 'ZipArchive' ) ) {
			aav_dt_redirect( 'nozip' );
		}
		$name = preg_replace( '/\.php$/', '', basename( $pf ) );
		$tmp  = wp_tempnam( $name . '.zip' );
		$zip  = new ZipArchive();
		if ( true !== $zip->open( $tmp, ZipArchive::OVERWRITE ) ) {
			aav_dt_redirect( 'zip_err' );
		}
		$zip->addFile( WP_PLUGIN_DIR . '/' . $pf, basename( $pf ) );
		$zip->close();
		aav_dt_stream_file( $tmp, $name . '-' . gmdate( 'Ymd-His' ) . '.zip' );
	}

	aav_dt_stream_zip_dir( WP_PLUGIN_DIR . '/' . $dirname, $dirname );
} );

/* 2) Limites PHP (.user.ini et/ou .htaccess) */
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
		aav_dt_redirect( 'ini_empty' );
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

	aav_dt_redirect( ( $ok1 && $ok2 ) ? 'ini_ok' : 'ini_err' );
} );

/* 3) Installation d'un mu-plugin */
add_action( 'admin_post_aav_dt_install_mu', function () {
	aav_dt_guard( 'aav_dt_install_mu' );

	$filename = sanitize_file_name( wp_unslash( $_POST['mu_filename'] ?? '' ) );
	if ( ! $filename || ! preg_match( '/\.php$/', $filename ) ) {
		$filename = 'aav-scroll-refresh.php';
	}

	$content = isset( $_POST['mu_content'] ) ? wp_unslash( $_POST['mu_content'] ) : '';
	if ( '' === trim( $content ) || 0 !== strpos( $content, '<?php' ) ) {
		aav_dt_redirect( 'mu_invalid' );
	}

	$dir = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
	if ( ! file_exists( $dir ) ) {
		wp_mkdir_p( $dir );
	}

	$path = trailingslashit( $dir ) . $filename;
	$ok   = ( false !== @file_put_contents( $path, $content ) );

	if ( $ok && function_exists( 'opcache_invalidate' ) ) {
		@opcache_invalidate( $path, true );
	}
	if ( $ok ) {
		$written = (string) @file_get_contents( $path );
		if ( md5( $written ) !== md5( $content ) ) {
			aav_dt_redirect( 'mu_mismatch' );
		}
	}
	aav_dt_redirect( $ok ? 'mu_ok' : 'mu_err' );
} );

/* 4) Remplacer un fichier dans wp-content (sauvegarde .bak automatique) */
add_action( 'admin_post_aav_dt_replace_file', function () {
	aav_dt_guard( 'aav_dt_replace_file' );

	$rel = aav_dt_clean_rel( $_POST['target_path'] ?? '' );
	if ( '' === $rel ) {
		aav_dt_redirect( 'file_badpath' );
	}
	if ( empty( $_FILES['replacement']['tmp_name'] ) || ! is_uploaded_file( $_FILES['replacement']['tmp_name'] ) ) {
		aav_dt_redirect( 'file_noupload' );
	}

	$target = aav_dt_resolve_in_content( $rel );
	if ( '' === $target || ! is_file( $target ) ) {
		aav_dt_redirect( 'file_notfound' );
	}

	$bak = $target . '.bak-' . gmdate( 'Ymd-His' );
	if ( ! @copy( $target, $bak ) ) {
		aav_dt_redirect( 'file_bak_err' );
	}

	$ok = @move_uploaded_file( $_FILES['replacement']['tmp_name'], $target );
	if ( $ok && function_exists( 'opcache_invalidate' ) && preg_match( '/\.php$/i', $target ) ) {
		@opcache_invalidate( $target, true );
	}
	aav_dt_redirect( $ok ? 'file_ok' : 'file_err' );
} );

/* 5) Supprimer un fichier dans wp-content */
add_action( 'admin_post_aav_dt_delete_file', function () {
	aav_dt_guard( 'aav_dt_delete_file' );

	$rel = aav_dt_clean_rel( $_POST['delete_path'] ?? '' );
	if ( '' === $rel ) {
		aav_dt_redirect( 'del_badpath' );
	}
	$target = aav_dt_resolve_in_content( $rel );
	if ( '' === $target ) {
		aav_dt_redirect( 'del_notfound' );
	}
	if ( ! is_file( $target ) ) {
		aav_dt_redirect( 'del_notafile' );
	}

	$ok = @unlink( $target );
	if ( ! $ok ) {
		@chmod( $target, 0644 );
		$ok = @unlink( $target );
	}
	aav_dt_redirect( $ok ? 'del_ok' : 'del_err' );
} );

/* 5b) Supprimer tous les .bak reperes */
add_action( 'admin_post_aav_dt_purge_baks', function () {
	aav_dt_guard( 'aav_dt_purge_baks' );

	$done = 0;
	foreach ( aav_dt_find_baks() as $b ) {
		$target = aav_dt_resolve_in_content( $b['rel'] );
		if ( '' !== $target && is_file( $target ) && @unlink( $target ) ) {
			$done++;
		}
	}
	aav_dt_redirect( 'baks_' . $done );
} );

/* 6) Normaliser les blocs AAV en mode edition */
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
	aav_dt_redirect( 'norm_' . $changed );
} );

/* =========================================================================
 *  PAGE D'ADMINISTRATION
 * ====================================================================== */
function aav_dt_notice_for( $status ) {
	$map = array(
		'nozip'         => array( 'error',   "L'extension PHP ZipArchive n'est pas disponible sur ce serveur." ),
		'zip_err'       => array( 'error',   "Impossible de creer l'archive." ),
		'plg_notfound'  => array( 'error',   'Extension inconnue.' ),
		'ini_ok'        => array( 'success', 'Limites PHP enregistrees. Compte quelques minutes pour la prise en compte.' ),
		'ini_err'       => array( 'error',   "Echec d'ecriture du fichier de limites (droits en ecriture ?)." ),
		'ini_empty'     => array( 'error',   'Aucune valeur valide fournie.' ),
		'mu_ok'         => array( 'success', 'Mu-plugin installe dans wp-content/mu-plugins/.' ),
		'mu_err'        => array( 'error',   "Echec d'ecriture du mu-plugin (droits en ecriture ?)." ),
		'mu_invalid'    => array( 'error',   'Le contenu du mu-plugin doit commencer par <?php.' ),
		'mu_mismatch'   => array( 'error',   'Ecriture incomplete : le contenu relu sur le disque differe de celui envoye (quota ? securite hebergeur ?).' ),
		'file_ok'       => array( 'success', 'Fichier remplace. Une sauvegarde .bak horodatee a ete creee a cote (pense a la supprimer, section 5).' ),
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
	);

	if ( isset( $map[ $status ] ) ) {
		return $map[ $status ];
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

function aav_dt_render_page() {
	if ( ! current_user_can( AAV_DT_CAP ) ) {
		wp_die( esc_html( 'Acces refuse.' ), '', array( 'response' => 403 ) );
	}

	$status = isset( $_GET['aav_status'] ) ? sanitize_key( wp_unslash( $_GET['aav_status'] ) ) : '';
	$notice = $status ? aav_dt_notice_for( $status ) : null;

	$action = esc_url( admin_url( 'admin-post.php' ) );
	$sapi   = php_sapi_name();
	$ini_f  = php_ini_loaded_file() ?: '(aucun)';
	$mu_dir = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
	$ini_keys = array(
		'upload_max_filesize' => "Taille max d'un fichier uploade",
		'post_max_size'       => "Taille max d'une requete POST",
		'memory_limit'        => 'Limite memoire',
		'max_execution_time'  => "Temps d'execution max (secondes)",
	);

	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$plugins = get_plugins();
	$actives = (array) get_option( 'active_plugins', array() );
	$baks    = aav_dt_find_baks();
	?>
	<div class="wrap">
		<h1>AAV Dev Toolkit <span style="font-size:13px;color:#666;font-weight:400;">v<?php echo esc_html( AAV_DT_VERSION ); ?></span></h1>

		<?php if ( $notice ) : ?>
			<div class="notice notice-<?php echo esc_attr( $notice[0] ); ?> is-dismissible"><p><?php echo esc_html( $notice[1] ); ?></p></div>
		<?php endif; ?>

		<p style="max-width:840px;background:#fff3cd;border:1px solid #ffe69c;padding:12px 16px;border-radius:4px;">
			<strong>Outil d'administration puissant.</strong> Il exporte le theme et les extensions,
			modifie la configuration PHP, depose du code executable et supprime des fichiers.
			Reserve aux administrateurs. Sur un site en production, retire-le une fois l'intervention terminee.
		</p>

		<hr>

		<h2>1. Exporter le theme ou une extension</h2>
		<p>Zip complet, sans <code>node_modules</code> ni <code>.git</code>. Utile pour archiver
		   l'etat reel du site ou alimenter un depot Git.</p>

		<form method="post" action="<?php echo $action; ?>" style="margin-bottom:14px;">
			<?php wp_nonce_field( 'aav_dt_download_theme' ); ?>
			<input type="hidden" name="action" value="aav_dt_download_theme">
			<?php submit_button( 'Telecharger le theme actif (' . basename( get_template_directory() ) . ')', 'primary', 'submit', false ); ?>
		</form>

		<form method="post" action="<?php echo $action; ?>">
			<?php wp_nonce_field( 'aav_dt_download_plugin' ); ?>
			<input type="hidden" name="action" value="aav_dt_download_plugin">
			<select name="plugin_file" style="min-width:420px;">
				<?php foreach ( $plugins as $pfile => $pdata ) :
					$state = in_array( $pfile, $actives, true ) ? '' : ' (inactive)'; ?>
					<option value="<?php echo esc_attr( $pfile ); ?>">
						<?php echo esc_html( $pdata['Name'] . ' ' . $pdata['Version'] . $state ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<?php submit_button( "Telecharger l'extension", 'secondary', 'submit', false ); ?>
		</form>

		<hr>

		<h2>2. Limites PHP</h2>
		<p>
			SAPI : <code><?php echo esc_html( $sapi ); ?></code> &middot;
			php.ini charge : <code><?php echo esc_html( $ini_f ); ?></code><br>
			php.ini n'est pas editable depuis PHP : on ecrit un <code>.user.ini</code> (PHP-FPM / CGI)<?php
			echo ( false !== stripos( $sapi, 'apache' ) ) ? ' et un bloc <code>.htaccess</code> (Apache mod_php)' : ''; ?>.
			Un <code>.user.ini</code> peut mettre quelques minutes a etre pris en compte.
		</p>
		<form method="post" action="<?php echo $action; ?>">
			<?php wp_nonce_field( 'aav_dt_save_ini' ); ?>
			<input type="hidden" name="action" value="aav_dt_save_ini">
			<table class="form-table" role="presentation">
				<?php foreach ( $ini_keys as $key => $label ) : ?>
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
						<td>
							<input name="<?php echo esc_attr( $key ); ?>" id="<?php echo esc_attr( $key ); ?>"
							       type="text" class="regular-text" placeholder="<?php echo esc_attr( ini_get( $key ) ); ?>">
							<p class="description">Actuel : <code><?php echo esc_html( ini_get( $key ) ); ?></code>. Ex : 64M, 128M, 300. Vide = inchange.</p>
						</td>
					</tr>
				<?php endforeach; ?>
			</table>
			<?php submit_button( 'Appliquer les limites', 'primary', 'submit', false ); ?>
		</form>

		<hr>

		<h2>3. Installer un mu-plugin</h2>
		<?php
		$writable = is_dir( $mu_dir ) ? is_writable( $mu_dir ) : is_writable( dirname( $mu_dir ) );
		printf(
			'<p><code>%s</code> : %s en ecriture &middot; OPcache : %s</p>',
			esc_html( $mu_dir ),
			$writable ? '<strong style="color:#00a32a">accessible</strong>' : '<strong style="color:#d63638">NON accessible</strong>',
			( function_exists( 'opcache_get_status' ) && @opcache_get_status( false ) ) ? 'actif' : 'inactif ou indetectable'
		);

		$existing = is_dir( $mu_dir ) ? array_values( array_diff( scandir( $mu_dir ), array( '.', '..' ) ) ) : array();
		if ( $existing ) {
			echo '<table class="widefat striped" style="max-width:860px;margin:8px 0 16px;">';
			echo '<thead><tr><th>Fichier</th><th>Version sur le disque</th><th>Modifie le (UTC)</th><th>Empreinte</th><th>Ecriture</th></tr></thead><tbody>';
			foreach ( $existing as $f ) {
				$p = trailingslashit( $mu_dir ) . $f;
				if ( ! is_file( $p ) ) {
					continue;
				}
				$head = (string) @file_get_contents( $p, false, null, 0, 2048 );
				$ver  = preg_match( '/^\s*\*\s*Version:\s*(.+)$/mi', $head, $m ) ? trim( $m[1] ) : 'n/a';
				printf(
					'<tr><td><code>%s</code></td><td><strong>%s</strong></td><td>%s</td><td><code>%s</code></td><td>%s</td></tr>',
					esc_html( $f ),
					esc_html( $ver ),
					esc_html( gmdate( 'Y-m-d H:i:s', (int) @filemtime( $p ) ) ),
					esc_html( substr( md5( (string) @file_get_contents( $p ) ), 0, 8 ) ),
					is_writable( $p ) ? 'oui' : '<strong style="color:#d63638">non</strong>'
				);
			}
			echo '</tbody></table>';
		}
		?>
		<p class="description" style="max-width:860px;">
			Le champ ci-dessous est pre-rempli avec la derniere version connue du correctif
			(v1.4). <strong>Verifie le tableau ci-dessus avant d'installer</strong> : si le disque
			porte une version plus recente, colle-la ici plutot que d'ecraser avec le gabarit.
		</p>
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
			<?php submit_button( 'Installer le mu-plugin', 'primary', 'submit', false ); ?>
		</form>

		<hr>

		<h2>4. Remplacer un fichier (theme ou extension)</h2>
		<p>Chemin relatif a <code>wp-content</code>, par exemple
		   <code>themes/aav/assets/app.min.js</code> ou
		   <code>plugins/aav-landing-blocks/aav-landing-blocks.php</code>.
		   Une sauvegarde <code>.bak</code> horodatee est creee avant remplacement.</p>
		<form method="post" action="<?php echo $action; ?>" enctype="multipart/form-data">
			<?php wp_nonce_field( 'aav_dt_replace_file' ); ?>
			<input type="hidden" name="action" value="aav_dt_replace_file">
			<p>
				<label for="target_path"><strong>Chemin cible</strong></label><br>
				<input name="target_path" id="target_path" type="text" class="large-text"
				       placeholder="plugins/aav-landing-blocks/aav-landing-blocks.php" required>
			</p>
			<p>
				<label for="replacement"><strong>Nouveau fichier</strong></label><br>
				<input name="replacement" id="replacement" type="file" required>
			</p>
			<?php submit_button( 'Remplacer le fichier', 'primary', 'submit', false ); ?>
		</form>

		<hr>

		<h2>5. Supprimer un fichier</h2>
		<p>Fichiers uniquement, jamais de dossiers. Utile pour les residus de migration et les
		   sauvegardes <code>.bak</code>, qui <strong>bloquent les mises a jour d'extensions</strong>.</p>

		<?php if ( $baks ) : ?>
			<div class="notice notice-warning" style="max-width:860px;">
				<p><strong><?php echo count( $baks ); ?> sauvegarde(s) .bak detectee(s)</strong> dans les extensions ou les themes :</p>
				<ul style="margin-left:18px;list-style:disc;">
					<?php foreach ( array_slice( $baks, 0, 10 ) as $b ) : ?>
						<li><code><?php echo esc_html( $b['rel'] ); ?></code>
							<span style="color:#666;">(<?php echo esc_html( size_format( $b['size'] ) ); ?>, <?php echo esc_html( gmdate( 'Y-m-d', $b['time'] ) ); ?>)</span></li>
					<?php endforeach; ?>
					<?php if ( count( $baks ) > 10 ) : ?><li>…</li><?php endif; ?>
				</ul>
				<form method="post" action="<?php echo $action; ?>"
				      onsubmit="return confirm('Supprimer toutes les sauvegardes .bak detectees ?');" style="margin:8px 0 12px;">
					<?php wp_nonce_field( 'aav_dt_purge_baks' ); ?>
					<input type="hidden" name="action" value="aav_dt_purge_baks">
					<?php submit_button( 'Supprimer toutes les sauvegardes .bak', 'delete', 'submit', false ); ?>
				</form>
			</div>
		<?php endif; ?>

		<form method="post" action="<?php echo $action; ?>"
		      onsubmit="return confirm('Supprimer definitivement ce fichier ?');">
			<?php wp_nonce_field( 'aav_dt_delete_file' ); ?>
			<input type="hidden" name="action" value="aav_dt_delete_file">
			<p>
				<label for="delete_path"><strong>Chemin du fichier (relatif a wp-content)</strong></label><br>
				<input name="delete_path" id="delete_path" type="text" class="large-text"
				       placeholder="plugins/mon-extension/fichier.php.bak-20260902-120703" required>
			</p>
			<?php submit_button( 'Supprimer le fichier', 'delete', 'submit', false ); ?>
		</form>

		<hr>

		<h2>6. Blocs AAV : forcer le mode edition</h2>
		<p>Le mode apercu/edition d'un bloc est memorise dans la page au moment de son insertion.
		   Ce bouton reecrit l'attribut de tous les blocs AAV existants en <code>mode edition</code>.
		   <strong>Ferme les editeurs de pages ouverts avant de lancer l'operation.</strong></p>
		<form method="post" action="<?php echo $action; ?>">
			<?php wp_nonce_field( 'aav_dt_normalize_mode' ); ?>
			<input type="hidden" name="action" value="aav_dt_normalize_mode">
			<?php submit_button( 'Normaliser tous les blocs AAV en mode edition', 'primary', 'submit', false ); ?>
		</form>
	</div>
	<?php
}

/* =========================================================================
 *  MISES A JOUR DEPUIS GITHUB (releases, sans extension tierce)
 *  Cycle : incrementer la Version en en-tete, pousser, publier une release
 *  taguee vX.Y.Z avec le zip du plugin en piece jointe.
 *  Depot prive : l'appel echoue silencieusement, mise a jour manuelle.
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
			set_transient( $cache_key, array(), HOUR_IN_SECONDS ); // evite de marteler l'API
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

	// zip attache a la release (dossier racine propre), sinon archive du tag
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

/* Vider le cache de release a la demande : Extensions > Verifier a nouveau. */
add_action( 'upgrader_process_complete', function () {
	delete_transient( 'aav_dt_gh_release' );
} );
