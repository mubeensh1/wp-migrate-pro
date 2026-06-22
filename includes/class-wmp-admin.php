<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class WMP_Admin {

    public static function init() {
        add_action( 'admin_menu',            array( __CLASS__, 'add_menu' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
    }

    public static function activate() {
        if ( ! file_exists( WMP_BACKUP_DIR ) ) { wp_mkdir_p( WMP_BACKUP_DIR ); }
        $ht = WMP_BACKUP_DIR . '.htaccess';
        if ( ! file_exists( $ht ) ) {
            file_put_contents( $ht, "Options -Indexes\n<FilesMatch \".*\">\n  Order Allow,Deny\n  Deny from all\n</FilesMatch>\n" );
        }
        if ( ! file_exists( WMP_BACKUP_DIR . 'index.php' ) ) {
            file_put_contents( WMP_BACKUP_DIR . 'index.php', '<?php // Silence is golden.' );
        }
        WMP_Runner::reset_detection();
    }

    public static function deactivate() {
        wp_clear_scheduled_hook( WMP_Runner::CRON_HOOK );
    }

    public static function add_menu() {
        add_menu_page(
            __( 'WP Migrate Pro', 'wp-migrate-pro' ),
            __( 'Migrate Pro', 'wp-migrate-pro' ),
            'manage_options',
            'wp-migrate-pro',
            array( __CLASS__, 'render' ),
            'dashicons-migrate',
            80
        );
    }

    public static function enqueue( $hook ) {
        if ( $hook !== 'toplevel_page_wp-migrate-pro' ) { return; }
        wp_enqueue_style( 'wmp-admin', WMP_PLUGIN_URL . 'assets/css/admin.css', array(), WMP_VERSION );
        wp_enqueue_script( 'wmp-admin', WMP_PLUGIN_URL . 'assets/js/admin.js', array( 'jquery' ), WMP_VERSION, true );
        wp_localize_script( 'wmp-admin', 'WMP', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'wmp_nonce' ),
            'site_url' => get_site_url(),
            'method'   => WMP_Runner::detect_method(),
            'strings'  => array(
                'confirm_import'  => "WARNING: This will OVERWRITE this site's entire database and replace all wp-content files.\n\nMake sure you have a backup first.\n\nAre you absolutely sure?",
                'confirm_restore' => "WARNING: This will RESTORE this site's database and files to the state of the selected backup.\n\nThe current database and files will be overwritten.\n\nAre you absolutely sure?",
                'confirm_delete'  => 'Permanently delete this backup? This cannot be undone.',
            ),
        ) );
    }

    public static function render() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Access denied.' ); }
        $backups       = self::list_backups();
        $requirements  = WMP_Compat::check_requirements();
        $runner_method = WMP_Runner::detect_method();
        $requirements[] = array(
            'label' => 'Background runner: ' . WMP_Runner::method_label( $runner_method ),
            'ok'    => true,
            'note'  => $runner_method === WMP_Runner::METHOD_INLINE ? 'Loopback and Cron unavailable — using inline execution.' : '',
        );
        $all_ok = true;
        foreach ( $requirements as $r ) { if ( ! $r['ok'] ) { $all_ok = false; break; } }
        include WMP_PLUGIN_DIR . 'templates/admin-page.php';
    }

    public static function list_backups() {
        $files = glob( WMP_BACKUP_DIR . 'backup_*.zip' );
        if ( ! $files ) { return array(); }
        $out = array();
        foreach ( $files as $f ) {
            $name  = basename( $f );
            $out[] = array(
                'name'   => $name,
                'size'   => size_format( filesize( $f ) ),
                'date'   => gmdate( 'Y-m-d H:i:s', filemtime( $f ) ),
                'dl_url' => admin_url(
                    'admin-ajax.php?action=wmp_download_backup'
                    . '&file=' . rawurlencode( $name )
                    . '&_wpnonce=' . wp_create_nonce( 'wmp_download_' . $name )
                ),
            );
        }
        usort( $out, array( __CLASS__, 'sort_backups' ) );
        return $out;
    }

    public static function sort_backups( $a, $b ) {
        return strcmp( $b['date'], $a['date'] );
    }
}
