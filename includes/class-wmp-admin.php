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
            'strings'  => array(
                'confirm_import'  => "This will overwrite this site's database and all wp-content files.\n\nCreate a backup first if you haven't already.\n\nContinue?",
                'confirm_restore' => "This will restore this site's database and files to the selected backup.\n\nThe current database and files will be overwritten.\n\nContinue?",
                'confirm_delete'  => 'Permanently delete this backup?',
            ),
        ) );
    }

    public static function render() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Access denied.' ); }
        $backups      = self::list_backups();
        $requirements = self::get_requirements();
        $all_ok       = true;
        foreach ( $requirements as $r ) {
            if ( ! $r['ok'] ) { $all_ok = false; break; }
        }
        include WMP_PLUGIN_DIR . 'templates/admin-page.php';
    }

    private static function get_requirements() {
        $checks = array();

        // PHP version
        $checks[] = array(
            'label' => 'PHP ' . PHP_VERSION,
            'ok'    => version_compare( PHP_VERSION, '7.4', '>=' ),
            'note'  => 'PHP 7.4 or higher required.',
        );

        // ZIP library
        $has_zip    = WMP_Compat::has_zip_archive();
        $has_pclzip = WMP_Compat::has_pclzip();
        $checks[] = array(
            'label' => 'ZIP: ' . ( $has_zip ? 'ZipArchive' : ( $has_pclzip ? 'PclZip' : 'Not available' ) ),
            'ok'    => $has_zip || $has_pclzip,
            'note'  => ( ! $has_zip && ! $has_pclzip ) ? 'Enable the PHP zip extension.' : '',
        );

        // Backup directory writable
        $dir_ok = ( file_exists( WMP_BACKUP_DIR ) || wp_mkdir_p( WMP_BACKUP_DIR ) ) && is_writable( WMP_BACKUP_DIR );
        $checks[] = array(
            'label' => 'Backup directory ' . ( $dir_ok ? 'writable' : 'not writable' ),
            'ok'    => $dir_ok,
            'note'  => $dir_ok ? WMP_BACKUP_DIR : WMP_BACKUP_DIR . ' — check permissions.',
        );

        // Memory limit
        $mem    = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );
        $mem_ok = $mem === -1 || $mem >= 128 * 1024 * 1024;
        $checks[] = array(
            'label' => 'Memory limit: ' . ini_get( 'memory_limit' ),
            'ok'    => $mem_ok,
            'note'  => $mem_ok ? '' : '128 MB minimum recommended.',
        );

        // Upload size
        $ul     = wp_convert_hr_to_bytes( ini_get( 'upload_max_filesize' ) );
        $ul_ok  = $ul >= 64 * 1024 * 1024;
        $checks[] = array(
            'label' => 'Upload limit: ' . ini_get( 'upload_max_filesize' ),
            'ok'    => $ul_ok,
            'note'  => $ul_ok ? '' : 'Use Remote URL import to bypass this limit.',
        );

        // Execution time
        $max_exec = (int) ini_get( 'max_execution_time' );
        $exec_ok  = $max_exec === 0 || $max_exec >= 120;
        $checks[] = array(
            'label' => 'Max execution time: ' . ( $max_exec === 0 ? 'unlimited' : $max_exec . 's' ),
            'ok'    => $exec_ok,
            'note'  => $exec_ok ? '' : 'Values under 120s may cause timeouts on large sites.',
        );

        return $checks;
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
