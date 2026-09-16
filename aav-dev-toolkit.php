<?php
/**
 * Plugin Name: AAV Dev Toolkit
 * Description: Outils d'administration pour le site AAV : export du theme (zip),
 *              limites PHP (via .user.ini ou .htaccess selon le serveur), et
 *              installation d'un mu-plugin en un clic. Reserve aux administrateurs.
 * Version:     1.5.0
 * Author:      Biolay Group
 * Update URI:  https://github.com/biolay-group/aav-dev-toolkit
 *
 * AVERTISSEMENT SECURITE
 * Cet outil peut exporter le theme, modifier la configuration PHP et deposer
 * du code executable (mu-plugin). Toutes les actions exigent le droit
 * "manage_options" et un jeton (nonce). Sur un site en production partage,
 * installe-le le temps de ton intervention, puis desactive-le ou retire-le.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'AAV_DT_CAP', 'manage_options' );   // seuls les admins
define( 'AAV_DT_SLUG', 'aav-devtools' );

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

/* -------------------------------------------------------------------------
 *  Helpers
 * ---------------------------------------------------------------------- */
function aav_dt_redirect( $status ) {
    wp_safe_redirect( add_query_arg(
        array( 'page' => AAV_DT_SLUG, 'aav_status' => rawurlencode( $status ) ),
        admin_url( 'tools.php' )
    ) );
    exit;
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

/** Contenu par defaut du mu-plugin : correctif de recalcul du smooth scroll. */
function aav_dt_default_mu() {
    return <<<'PHP'
<?php
/**
 * Plugin Name: AAV - Correctif smooth scroll (hauteur + reset navigation)
 * Description: 1) recalcule la hauteur du smooth scroll apres injection de contenu
 *              (footer coupe). 2) remet le scroll a zero apres chaque transition
 *              Barba via l'instance Locomotive exposee (window.__aavLoco),
 *              en ecrivant directement la position (setScroll), methode robuste.
 *              3) cache-bust du app.min.js patche.
 * Version:     1.2.0
 * Author:      Jean-Baptiste Biolay
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

add_action( 'wp_footer', function () {
    ?>
<script>
(function () {
  'use strict';

  function loco() {
    var i = window.__aavLoco || window.locoScroll || null;
    return (i && typeof i.update === 'function') ? i : null;
  }

  /* Remise a zero robuste : ecrit la position directement (setScroll),
     puis scrollTo en secours, puis update pour resynchroniser. */
  function toTop() {
    var l = loco();
    if (!l) { window.scrollTo(0, 0); return; }
    try { if (l.setScroll) l.setScroll(0, 0); } catch (e) {}
    try { l.scrollTo(0, { duration: 0, disableLerp: true }); } catch (e) {}
    try { l.update(); } catch (e) {}
  }

  /* Recalcul de hauteur (footer coupe apres injection de contenu). */
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
  window.addEventListener('load', observe);

  /* Changement de page Barba : le conteneur [data-barba="container"] est
     remplace. On remet le scroll en haut (double tir pour couvrir la fin
     de la transition du theme) et on recale la hauteur. */
  var wrapper = document.querySelector('[data-barba="wrapper"]') || document.body;
  if ('MutationObserver' in window) {
    new MutationObserver(function (muts) {
      for (var i = 0; i < muts.length; i++) {
        for (var j = 0; j < muts[i].addedNodes.length; j++) {
          var n = muts[i].addedNodes[j];
          if (n.nodeType === 1 &&
              ((n.matches && n.matches('[data-barba="container"]')) ||
               (n.querySelector && n.querySelector('[data-barba="container"]')))) {
            setTimeout(toTop, 80);
            setTimeout(function () { toTop(); observe(); }, 600);
            return;
          }
        }
      }
    }).observe(wrapper, { childList: true, subtree: true });
  }

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

/* Cache-bust : force le rechargement du app.min.js patche par tous les caches */
add_filter( 'script_loader_src', function ( $src ) {
    if ( strpos( $src, 'themes/aav/assets/app.min.js' ) !== false ) {
        $src = add_query_arg( 'aavp', '2', $src );
    }
    return $src;
}, 10, 1 );
PHP;
}

/* -------------------------------------------------------------------------
 *  1) Export du theme (zip stream)
 * ---------------------------------------------------------------------- */
add_action( 'admin_post_aav_dt_download_theme', function () {
    if ( ! current_user_can( AAV_DT_CAP ) ) {
        wp_die( 'Acces refuse.' );
    }
    check_admin_referer( 'aav_dt_download_theme' );

    if ( ! class_exists( 'ZipArchive' ) ) {
        aav_dt_redirect( 'nozip' );
    }

    @set_time_limit( 300 );
    $dir  = get_template_directory();          // theme parent (le theme MOTIO)
    $name = basename( $dir );
    $tmp  = wp_tempnam( $name . '.zip' );

    $zip = new ZipArchive();
    if ( $zip->open( $tmp, ZipArchive::OVERWRITE ) !== true ) {
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

    nocache_headers();
    header( 'Content-Type: application/zip' );
    header( 'Content-Disposition: attachment; filename="' . $name . '-' . gmdate( 'Ymd-His' ) . '.zip"' );
    header( 'Content-Length: ' . filesize( $tmp ) );
    readfile( $tmp );
    @unlink( $tmp );
    exit;
} );

/* -------------------------------------------------------------------------
 *  1b) Export d'une extension installee (zip stream)
 * ---------------------------------------------------------------------- */
add_action( 'admin_post_aav_dt_download_plugin', function () {
    if ( ! current_user_can( AAV_DT_CAP ) ) {
        wp_die( 'Acces refuse.' );
    }
    check_admin_referer( 'aav_dt_download_plugin' );

    if ( ! class_exists( 'ZipArchive' ) ) {
        aav_dt_redirect( 'nozip' );
    }
    if ( ! function_exists( 'get_plugins' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $pf  = isset( $_POST['plugin_file'] ) ? wp_unslash( $_POST['plugin_file'] ) : '';
    $all = get_plugins();
    if ( ! isset( $all[ $pf ] ) ) {
        aav_dt_redirect( 'plg_notfound' );
    }

    @set_time_limit( 300 );
    $dirname = dirname( $pf );
    $single  = ( '.' === $dirname );
    $name    = $single ? preg_replace( '/\.php$/', '', basename( $pf ) ) : $dirname;
    $tmp     = wp_tempnam( $name . '.zip' );

    $zip = new ZipArchive();
    if ( $zip->open( $tmp, ZipArchive::OVERWRITE ) !== true ) {
        aav_dt_redirect( 'zip_err' );
    }

    if ( $single ) {
        $zip->addFile( WP_PLUGIN_DIR . '/' . $pf, basename( $pf ) );
    } else {
        $dir     = WP_PLUGIN_DIR . '/' . $dirname;
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
    }
    $zip->close();

    nocache_headers();
    header( 'Content-Type: application/zip' );
    header( 'Content-Disposition: attachment; filename="' . $name . '-' . gmdate( 'Ymd-His' ) . '.zip"' );
    header( 'Content-Length: ' . filesize( $tmp ) );
    readfile( $tmp );
    @unlink( $tmp );
    exit;
} );

/* -------------------------------------------------------------------------
 *  2) Limites PHP (via .user.ini et/ou .htaccess)
 * ---------------------------------------------------------------------- */
add_action( 'admin_post_aav_dt_save_ini', function () {
    if ( ! current_user_can( AAV_DT_CAP ) ) {
        wp_die( 'Acces refuse.' );
    }
    check_admin_referer( 'aav_dt_save_ini' );

    $keys  = array( 'upload_max_filesize', 'post_max_size', 'memory_limit', 'max_execution_time' );
    $lines = array();
    foreach ( $keys as $k ) {
        $v = isset( $_POST[ $k ] ) ? trim( sanitize_text_field( wp_unslash( $_POST[ $k ] ) ) ) : '';
        // valeurs simples uniquement : chiffres + suffixe M/G/K, ou nombre seul
        if ( $v !== '' && preg_match( '/^\d+[KMG]?$/i', $v ) ) {
            $lines[ $k ] = $v;
        }
    }
    if ( empty( $lines ) ) {
        aav_dt_redirect( 'ini_empty' );
    }

    // .user.ini (PHP-FPM / CGI)
    $block1 = "; BEGIN AAV\n";
    foreach ( $lines as $k => $v ) {
        $block1 .= "$k = $v\n";
    }
    $block1 .= "; END AAV\n";
    $ok1 = aav_dt_put_block( ABSPATH . '.user.ini', $block1, '; BEGIN AAV', '; END AAV' );

    // .htaccess (Apache mod_php) uniquement si pertinent
    $ok2 = true;
    if ( stripos( php_sapi_name(), 'apache' ) !== false ) {
        $block2 = "# BEGIN AAV\n<IfModule mod_php.c>\n";
        foreach ( $lines as $k => $v ) {
            $block2 .= "php_value $k $v\n";
        }
        $block2 .= "</IfModule>\n# END AAV\n";
        $ok2 = aav_dt_put_block( ABSPATH . '.htaccess', $block2, '# BEGIN AAV', '# END AAV' );
    }

    aav_dt_redirect( ( $ok1 && $ok2 ) ? 'ini_ok' : 'ini_err' );
} );

/* -------------------------------------------------------------------------
 *  3) Installation d'un mu-plugin
 * ---------------------------------------------------------------------- */
add_action( 'admin_post_aav_dt_install_mu', function () {
    if ( ! current_user_can( AAV_DT_CAP ) ) {
        wp_die( 'Acces refuse.' );
    }
    check_admin_referer( 'aav_dt_install_mu' );

    $filename = sanitize_file_name( wp_unslash( $_POST['mu_filename'] ?? '' ) );
    if ( ! $filename || ! preg_match( '/\.php$/', $filename ) ) {
        $filename = 'aav-scroll-refresh.php';
    }

    $content = isset( $_POST['mu_content'] ) ? wp_unslash( $_POST['mu_content'] ) : '';
    if ( trim( $content ) === '' || strpos( $content, '<?php' ) !== 0 ) {
        aav_dt_redirect( 'mu_invalid' );
    }

    $dir = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
    if ( ! file_exists( $dir ) ) {
        wp_mkdir_p( $dir );
    }

    $path = trailingslashit( $dir ) . $filename;
    $ok   = ( @file_put_contents( $path, $content ) !== false );

    // invalide l'OPcache pour que PHP execute la nouvelle version immediatement
    if ( $ok && function_exists( 'opcache_invalidate' ) ) {
        @opcache_invalidate( $path, true );
    }

    // relecture de controle : le contenu sur disque doit etre celui envoye
    if ( $ok ) {
        $written = (string) @file_get_contents( $path );
        if ( md5( $written ) !== md5( $content ) ) {
            aav_dt_redirect( 'mu_mismatch' );
        }
    }
    aav_dt_redirect( $ok ? 'mu_ok' : 'mu_err' );
} );

/* -------------------------------------------------------------------------
 *  4) Remplacer un fichier du theme (avec sauvegarde .bak automatique)
 * ---------------------------------------------------------------------- */
add_action( 'admin_post_aav_dt_replace_file', function () {
    if ( ! current_user_can( AAV_DT_CAP ) ) {
        wp_die( 'Acces refuse.' );
    }
    check_admin_referer( 'aav_dt_replace_file' );

    $rel = isset( $_POST['target_path'] ) ? trim( wp_unslash( $_POST['target_path'] ) ) : '';
    // chemin relatif au theme, sans remontee de repertoire
    $rel = ltrim( str_replace( '\\', '/', $rel ), '/' );
    if ( $rel === '' || strpos( $rel, '..' ) !== false ) {
        aav_dt_redirect( 'file_badpath' );
    }

    if ( empty( $_FILES['replacement']['tmp_name'] ) || ! is_uploaded_file( $_FILES['replacement']['tmp_name'] ) ) {
        aav_dt_redirect( 'file_noupload' );
    }

    $base_dir = WP_CONTENT_DIR;
    $target   = $base_dir . '/' . $rel;

    // le fichier cible doit exister et etre bien dans wp-content
    $real_base   = realpath( $base_dir );
    $real_target = realpath( $target );
    if ( ! $real_target || strpos( $real_target, $real_base ) !== 0 || ! is_file( $real_target ) ) {
        aav_dt_redirect( 'file_notfound' );
    }

    // sauvegarde .bak horodatee (ne l ecrase jamais)
    $bak = $real_target . '.bak-' . gmdate( 'Ymd-His' );
    if ( ! @copy( $real_target, $bak ) ) {
        aav_dt_redirect( 'file_bak_err' );
    }

    $ok = @move_uploaded_file( $_FILES['replacement']['tmp_name'], $real_target );
    aav_dt_redirect( $ok ? 'file_ok' : 'file_err' );
} );

/* -------------------------------------------------------------------------
 *  5) Supprimer un fichier dans wp-content (residus de migration, .bak...)
 * ---------------------------------------------------------------------- */
add_action( 'admin_post_aav_dt_delete_file', function () {
    if ( ! current_user_can( AAV_DT_CAP ) ) {
        wp_die( 'Acces refuse.' );
    }
    check_admin_referer( 'aav_dt_delete_file' );

    $rel = isset( $_POST['delete_path'] ) ? trim( wp_unslash( $_POST['delete_path'] ) ) : '';
    $rel = ltrim( str_replace( '\\', '/', $rel ), '/' );
    if ( $rel === '' || strpos( $rel, '..' ) !== false ) {
        aav_dt_redirect( 'del_badpath' );
    }

    $real_base   = realpath( WP_CONTENT_DIR );
    $real_target = realpath( WP_CONTENT_DIR . '/' . $rel );
    if ( ! $real_target || strpos( $real_target, $real_base ) !== 0 ) {
        aav_dt_redirect( 'del_notfound' );
    }
    if ( ! is_file( $real_target ) ) {
        aav_dt_redirect( 'del_notafile' );
    }

    $ok = @unlink( $real_target );
    if ( ! $ok ) {
        // seconde chance : assouplir les droits puis reessayer
        @chmod( $real_target, 0644 );
        $ok = @unlink( $real_target );
    }
    aav_dt_redirect( $ok ? 'del_ok' : 'del_err' );
} );

/* -------------------------------------------------------------------------
 *  6) Normaliser les blocs AAV en mode edition (attribut stocke en base)
 * ---------------------------------------------------------------------- */
add_action( 'admin_post_aav_dt_normalize_mode', function () {
    if ( ! current_user_can( AAV_DT_CAP ) ) {
        wp_die( 'Acces refuse.' );
    }
    check_admin_referer( 'aav_dt_normalize_mode' );

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

/* -------------------------------------------------------------------------
 *  Page d'administration
 * ---------------------------------------------------------------------- */
function aav_dt_render_page() {
    if ( ! current_user_can( AAV_DT_CAP ) ) {
        wp_die( 'Acces refuse.' );
    }

    $notices = array(
        'nozip'     => array( 'error', "L'extension PHP ZipArchive n'est pas disponible sur ce serveur." ),
        'zip_err'   => array( 'error', "Impossible de creer l'archive du theme." ),
        'ini_ok'    => array( 'success', 'Limites PHP enregistrees. Compte quelques minutes pour la prise en compte.' ),
        'ini_err'   => array( 'error', "Echec d'ecriture du fichier de limites (droits en ecriture ?)." ),
        'ini_empty' => array( 'error', 'Aucune valeur valide fournie.' ),
        'mu_ok'     => array( 'success', 'Mu-plugin installe dans wp-content/mu-plugins/.' ),
        'mu_err'    => array( 'error', "Echec d'ecriture du mu-plugin (droits en ecriture ?)." ),
        'mu_invalid'=> array( 'error', 'Le contenu du mu-plugin doit commencer par <?php.' ),
        'mu_mismatch'  => array( 'error', "Ecriture incomplete : le contenu relu sur le disque ne correspond pas a celui envoye (quota disque ? securite hebergeur ?)." ),
        'file_ok'      => array( 'success', 'Fichier remplace. Une sauvegarde .bak horodatee a ete creee a cote.' ),
        'file_err'     => array( 'error', "Echec d'ecriture du fichier (droits ?). La sauvegarde .bak a ete creee." ),
        'file_bak_err' => array( 'error', 'Impossible de creer la sauvegarde .bak, remplacement annule par prudence.' ),
        'file_badpath' => array( 'error', 'Chemin invalide (relatif au theme, sans "..").' ),
        'file_notfound'=> array( 'error', "Le fichier cible n'existe pas dans le theme." ),
        'file_noupload'=> array( 'error', 'Aucun fichier televerse.' ),
        'del_ok'       => array( 'success', 'Fichier supprime.' ),
        'del_err'      => array( 'error', "Suppression impossible : le fichier appartient a un autre utilisateur systeme. Il faudra passer par le gestionnaire de fichiers de l'hebergeur ou son support." ),
        'del_badpath'  => array( 'error', 'Chemin invalide (relatif a wp-content, sans "..").' ),
        'del_notfound' => array( 'error', "Le fichier n'existe pas dans wp-content." ),
        'del_notafile' => array( 'error', 'La cible est un dossier : seuls les fichiers peuvent etre supprimes ici.' ),
        'plg_notfound' => array( 'error', "Extension inconnue." ),
    );
    $status = isset( $_GET['aav_status'] ) ? sanitize_key( $_GET['aav_status'] ) : '';
    if ( 0 === strpos( $status, 'norm_' ) ) {
        $n = (int) substr( $status, 5 );
        $notices[ $status ] = array( 'success', $n
            ? sprintf( '%d page(s) normalisee(s) : les blocs AAV s\'ouvriront en mode edition.', $n )
            : 'Aucune page a modifier : tous les blocs AAV sont deja en mode edition.' );
    }
    if ( $status && isset( $notices[ $status ] ) ) {
        printf(
            '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
            esc_attr( $notices[ $status ][0] ),
            esc_html( $notices[ $status ][1] )
        );
    }

    $action     = esc_url( admin_url( 'admin-post.php' ) );
    $ini_keys   = array(
        'upload_max_filesize' => 'Taille max d\'un fichier uploade',
        'post_max_size'       => 'Taille max d\'une requete POST',
        'memory_limit'        => 'Limite memoire',
        'max_execution_time'  => 'Temps d\'execution max (secondes)',
    );
    $sapi       = php_sapi_name();
    $loaded_ini = php_ini_loaded_file() ?: '(aucun)';
    ?>
    <div class="wrap">
        <h1>AAV Dev Toolkit</h1>
        <p style="max-width:820px;background:#fff3cd;border:1px solid #ffe69c;padding:12px 16px;border-radius:4px;">
            <strong>Outil d'administration puissant.</strong> Il exporte le theme, modifie la
            configuration PHP et depose du code executable. Reserve aux administrateurs.
            Sur un site en production, retire-le une fois ton intervention terminee.
        </p>

        <!-- 1) EXPORT DU THEME -->
        <h2>1. Exporter le theme ou une extension</h2>
        <p>Genere un zip du theme actif (<code><?php echo esc_html( basename( get_template_directory() ) ); ?></code>),
           sans <code>node_modules</code> ni <code>.git</code>.</p>
        <form method="post" action="<?php echo $action; ?>">
            <?php wp_nonce_field( 'aav_dt_download_theme' ); ?>
            <input type="hidden" name="action" value="aav_dt_download_theme">
            <?php submit_button( 'Telecharger le theme (.zip)', 'primary', 'submit', false ); ?>
        </form>

        <p style="margin-top:18px;"><strong>Exporter une extension installee</strong> (zip complet, sans <code>node_modules</code> ni <code>.git</code>) :</p>
        <?php
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $aav_dt_plugins = get_plugins();
        $aav_dt_active  = (array) get_option( 'active_plugins', array() );
        ?>
        <form method="post" action="<?php echo $action; ?>">
            <?php wp_nonce_field( 'aav_dt_download_plugin' ); ?>
            <input type="hidden" name="action" value="aav_dt_download_plugin">
            <select name="plugin_file" style="min-width:420px;">
                <?php foreach ( $aav_dt_plugins as $pfile => $pdata ) :
                    $state = in_array( $pfile, $aav_dt_active, true ) ? '' : ' — inactive'; ?>
                    <option value="<?php echo esc_attr( $pfile ); ?>">
                        <?php echo esc_html( $pdata['Name'] . ' (' . $pdata['Version'] . ')' . $state ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php submit_button( "Telecharger l'extension (.zip)", 'secondary', 'submit', false ); ?>
        </form>

        <hr>

        <!-- 2) LIMITES PHP -->
        <h2>2. Limites PHP</h2>
        <p>
            SAPI detecte : <code><?php echo esc_html( $sapi ); ?></code>.
            Fichier php.ini charge : <code><?php echo esc_html( $loaded_ini ); ?></code>.<br>
            On n'edite pas php.ini directement (souvent impossible). On ecrit un
            <code>.user.ini</code> (PHP-FPM / CGI)<?php echo ( stripos( $sapi, 'apache' ) !== false ) ? ' et un bloc <code>.htaccess</code> (Apache mod_php)' : ''; ?>.
            La prise en compte d'un <code>.user.ini</code> peut prendre quelques minutes.
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
                                   type="text" class="regular-text"
                                   placeholder="<?php echo esc_attr( ini_get( $key ) ); ?>">
                            <p class="description">Actuel : <code><?php echo esc_html( ini_get( $key ) ); ?></code>. Ex : 64M, 128M, 300. Laisse vide pour ne pas toucher.</p>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
            <?php submit_button( 'Appliquer les limites', 'primary', 'submit', false ); ?>
        </form>

        <hr>

        <!-- 3) MU-PLUGIN -->
        <h2>3. Installer un mu-plugin</h2>
        <p>Ecrit un fichier dans <code>wp-content/mu-plugins/</code> (cree le dossier si besoin).
           Pre-rempli avec le correctif de smooth scroll. Le contenu doit commencer par <code>&lt;?php</code>.</p>
        <?php
        $mu_dir = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
        $dir_writable = is_dir( $mu_dir ) ? is_writable( $mu_dir ) : is_writable( dirname( $mu_dir ) );
        printf(
            '<p>Dossier <code>%s</code> : %s en ecriture. OPcache : %s.</p>',
            esc_html( $mu_dir ),
            $dir_writable ? '<strong style="color:#00a32a">accessible</strong>' : '<strong style="color:#d63638">NON accessible</strong>',
            function_exists( 'opcache_get_status' ) && @opcache_get_status( false ) ? 'actif' : 'inactif ou indetectable'
        );
        $existing = ( is_dir( $mu_dir ) ) ? array_values( array_diff( scandir( $mu_dir ), array( '.', '..' ) ) ) : array();
        if ( $existing ) {
            echo '<table class="widefat striped" style="max-width:820px;margin:8px 0 16px;">';
            echo '<thead><tr><th>Fichier</th><th>Version lue sur le disque</th><th>Modifie le (UTC)</th><th>Empreinte</th><th>Ecriture</th></tr></thead><tbody>';
            foreach ( $existing as $f ) {
                $p = trailingslashit( $mu_dir ) . $f;
                if ( ! is_file( $p ) ) { continue; }
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
        <form method="post" action="<?php echo $action; ?>">
            <?php wp_nonce_field( 'aav_dt_install_mu' ); ?>
            <input type="hidden" name="action" value="aav_dt_install_mu">
            <p>
                <label for="mu_filename"><strong>Nom du fichier</strong></label><br>
                <input name="mu_filename" id="mu_filename" type="text" class="regular-text" value="aav-scroll-refresh.php">
            </p>
            <p>
                <label for="mu_content"><strong>Contenu</strong></label><br>
                <textarea name="mu_content" id="mu_content" rows="18" class="large-text code" spellcheck="false"><?php echo esc_textarea( aav_dt_default_mu() ); ?></textarea>
            </p>
            <?php submit_button( 'Installer le mu-plugin', 'primary', 'submit', false ); ?>
        </form>
<hr>

        <!-- 4) REMPLACER UN FICHIER DU THEME -->
        <h2>4. Remplacer un fichier (theme ou extension)</h2>
        <p>Televerse un fichier et remplace celui au chemin indique, relatif a
           <code>wp-content</code> (ex : <code>themes/aav/assets/app.min.js</code> ou
           <code>plugins/aav-landing-blocks/aav-landing-blocks.php</code>).
           Une sauvegarde <code>.bak</code> horodatee est creee avant remplacement.</p>
        <form method="post" action="<?php echo $action; ?>" enctype="multipart/form-data">
            <?php wp_nonce_field( 'aav_dt_replace_file' ); ?>
            <input type="hidden" name="action" value="aav_dt_replace_file">
            <p>
                <label for="target_path"><strong>Chemin cible (relatif a wp-content)</strong></label><br>
                <input name="target_path" id="target_path" type="text" class="regular-text"
                       value="plugins/aav-landing-blocks/aav-landing-blocks.php" placeholder="themes/aav/assets/app.min.js">
            </p>
            <p>
                <label for="replacement"><strong>Nouveau fichier</strong></label><br>
                <input name="replacement" id="replacement" type="file" required>
            </p>
            <?php submit_button( 'Remplacer le fichier', 'primary', 'submit', false ); ?>
        </form>

        <hr>

        <!-- 5) SUPPRIMER UN FICHIER -->
        <h2>5. Supprimer un fichier</h2>
        <p>Supprime un fichier dans <code>wp-content</code> (residus de migration,
           sauvegardes <code>.bak</code> qui bloquent les mises a jour d'extensions...).
           Fichiers uniquement, jamais de dossiers.</p>
        <form method="post" action="<?php echo $action; ?>"
              onsubmit="return confirm('Supprimer definitivement ce fichier ?');">
            <?php wp_nonce_field( 'aav_dt_delete_file' ); ?>
            <input type="hidden" name="action" value="aav_dt_delete_file">
            <p>
                <label for="delete_path"><strong>Chemin du fichier (relatif a wp-content)</strong></label><br>
                <input name="delete_path" id="delete_path" type="text" class="large-text"
                       value="plugins/aav-landing-blocks/aav-landing-blocks.php.bak-2026-09-02-120703">
            </p>
            <?php submit_button( 'Supprimer le fichier', 'delete', 'submit', false ); ?>
        </form>

        <hr>

        <!-- 6) MODE EDITION DES BLOCS AAV -->
        <h2>6. Blocs AAV : forcer le mode edition</h2>
        <p>Le mode aperçu/edition d'un bloc est memorise dans la page au moment de son
           insertion. Ce bouton reecrit l'attribut de tous les blocs AAV existants en
           <code>mode edition</code> : les champs s'affichent en pleine largeur dans
           l'editeur, sur toutes les landings, pour tous les utilisateurs.</p>
        <form method="post" action="<?php echo $action; ?>">
            <?php wp_nonce_field( 'aav_dt_normalize_mode' ); ?>
            <input type="hidden" name="action" value="aav_dt_normalize_mode">
                    <?php submit_button( 'Normaliser tous les blocs AAV en mode edition', 'primary', 'submit', false ); ?>
        </form>
    </div>
    <?php
}

/* ================================================================== *
 * MISES À JOUR DEPUIS GITHUB (dépôt public)
 * ================================================================== */
define( 'AAV_DT_REPO', 'biolay-group/aav-dev-toolkit' );
define( 'AAV_DT_FILE', plugin_basename( __FILE__ ) );

add_filter( 'update_plugins_github.com', function ( $update, $plugin_data, $plugin_file ) {
	if ( AAV_DT_FILE !== $plugin_file ) {
		return $update;
	}
	$cache_key = 'aav_upd_' . md5( AAV_DT_REPO );
	$release   = get_transient( $cache_key );

	if ( false === $release ) {
		$res = wp_remote_get(
			'https://api.github.com/repos/' . AAV_DT_REPO . '/releases/latest',
			array( 'timeout' => 10, 'headers' => array( 'Accept' => 'application/vnd.github+json' ) )
		);
		if ( is_wp_error( $res ) || 200 !== wp_remote_retrieve_response_code( $res ) ) {
			return $update;
		}
		$release = json_decode( wp_remote_retrieve_body( $res ), true );
		set_transient( $cache_key, $release, 6 * HOUR_IN_SECONDS );
	}
	if ( empty( $release['tag_name'] ) ) {
		return $update;
	}

	$remote_version = ltrim( $release['tag_name'], 'v' );
	if ( version_compare( $remote_version, $plugin_data['Version'], '<=' ) ) {
		return $update;
	}

	$package = '';
	if ( ! empty( $release['assets'][0]['browser_download_url'] ) ) {
		$package = $release['assets'][0]['browser_download_url'];
	} elseif ( ! empty( $release['zipball_url'] ) ) {
		$package = $release['zipball_url'];
	}

	return array(
		'slug'    => dirname( AAV_DT_FILE ),
		'version' => $remote_version,
		'url'     => 'https://github.com/' . AAV_DT_REPO,
		'package' => $package,
	);
}, 10, 3 );
