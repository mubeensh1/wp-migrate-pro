<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class WMP_Ajax {

    public static function init() {
        WMP_Runner::init();
        $actions = array(
            'wmp_start_backup',
            'wmp_start_import',
            'wmp_start_restore',
            'wmp_poll_progress',
            'wmp_delete_backup',
            'wmp_download_backup',
            'wmp_check_requirements',
            'wmp_loopback_test',
        );
        foreach ( $actions as $a ) {
            add_action( 'wp_ajax_' . $a, array( __CLASS__, $a ) );
        }
        add_action( 'wp_ajax_nopriv_wmp_run_job', array( __CLASS__, 'wmp_run_job' ) );
        add_action( 'wp_ajax_wmp_run_job',        array( __CLASS__, 'wmp_run_job' ) );
    }

    public static function wmp_loopback_test() {
        wp_send_json_success( 'ok' );
    }

    // ── Start backup ───────────────────────────────────────────────────────

    public static function wmp_start_backup() {
        self::verify();
        $job_id = WMP_Progress::generate_id();
        $params = array( 'type' => 'backup' );
        // Use create() — writes initial state to disk immediately.
        // Runner uses load_or_create() so it won't overwrite this.
        WMP_Progress::create( $job_id );
        set_transient( 'wmp_job_' . $job_id, $params, HOUR_IN_SECONDS );
        $method = WMP_Runner::dispatch( $job_id, $params );
        wp_send_json_success( array( 'job_id' => $job_id, 'method' => $method ) );
    }

    // ── Start import ───────────────────────────────────────────────────────

    public static function wmp_start_import() {
        self::verify();
        $type    = sanitize_key( wp_unslash( isset( $_POST['import_type'] ) ? $_POST['import_type'] : 'local' ) );
        $new_url = esc_url_raw( wp_unslash( isset( $_POST['new_url'] ) ? $_POST['new_url'] : '' ) );
        $job_id  = WMP_Progress::generate_id();
        $params  = array( 'type' => 'import', 'import_type' => $type, 'new_url' => $new_url );

        switch ( $type ) {
            case 'upload':
                if ( empty( $_FILES['backup_file'] ) || (int) $_FILES['backup_file']['error'] !== UPLOAD_ERR_OK ) {
                    wp_send_json_error( 'Upload failed (code ' . ( isset( $_FILES['backup_file']['error'] ) ? (int) $_FILES['backup_file']['error'] : -1 ) . ').' );
                }
                $file = $_FILES['backup_file'];
                if ( strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) ) !== 'zip' ) {
                    wp_send_json_error( 'Only .zip files accepted.' );
                }
                if ( function_exists( 'finfo_file' ) ) {
                    $fi = finfo_open( FILEINFO_MIME_TYPE );
                    $mime = finfo_file( $fi, $file['tmp_name'] );
                    finfo_close( $fi );
                    if ( ! in_array( $mime, array( 'application/zip', 'application/x-zip-compressed', 'application/octet-stream', 'application/x-zip' ), true ) ) {
                        wp_send_json_error( "Invalid file type ({$mime})." );
                    }
                }
                $dest = WMP_BACKUP_DIR . 'upload_' . bin2hex( random_bytes( 8 ) ) . '.zip';
                if ( ! move_uploaded_file( $file['tmp_name'], $dest ) ) {
                    wp_send_json_error( 'Could not save uploaded file.' );
                }
                $params['zip_path'] = $dest;
                break;

            case 'local':
                $zip_name = sanitize_file_name( wp_unslash( isset( $_POST['zip_name'] ) ? $_POST['zip_name'] : '' ) );
                $real     = realpath( WMP_BACKUP_DIR . $zip_name );
                $real_dir = realpath( WMP_BACKUP_DIR );
                if ( ! $real || ! $real_dir || strpos( $real, $real_dir . DIRECTORY_SEPARATOR ) !== 0 || ! file_exists( $real ) ) {
                    wp_send_json_error( 'Backup file not found.' );
                }
                $params['zip_path'] = $real;
                break;

            case 'remote':
                $remote_url = esc_url_raw( wp_unslash( isset( $_POST['remote_url'] ) ? $_POST['remote_url'] : '' ) );
                $scheme = wp_parse_url( $remote_url, PHP_URL_SCHEME );
                if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
                    wp_send_json_error( 'Only http/https URLs allowed.' );
                }
                $host = (string) wp_parse_url( $remote_url, PHP_URL_HOST );
                if ( $host && self::is_private_host( $host ) ) {
                    wp_send_json_error( 'Private/loopback addresses not allowed.' );
                }
                $params['remote_url'] = $remote_url;
                break;

            default:
                wp_send_json_error( 'Unknown import_type.' );
        }

        WMP_Progress::create( $job_id );
        set_transient( 'wmp_job_' . $job_id, $params, HOUR_IN_SECONDS );
        $method = WMP_Runner::dispatch( $job_id, $params );
        wp_send_json_success( array( 'job_id' => $job_id, 'method' => $method ) );
    }

    // ── Start restore ──────────────────────────────────────────────────────

    public static function wmp_start_restore() {
        self::verify();
        $zip_name = sanitize_file_name( wp_unslash( isset( $_POST['zip_name'] ) ? $_POST['zip_name'] : '' ) );
        if ( ! $zip_name ) {
            wp_send_json_error( 'No backup selected.' );
        }
        $real     = realpath( WMP_BACKUP_DIR . $zip_name );
        $real_dir = realpath( WMP_BACKUP_DIR );
        if ( ! $real || ! $real_dir || strpos( $real, $real_dir . DIRECTORY_SEPARATOR ) !== 0 || ! file_exists( $real ) ) {
            wp_send_json_error( 'Backup file not found.' );
        }
        $job_id = WMP_Progress::generate_id();
        $params = array( 'type' => 'restore', 'zip_path' => $real );
        WMP_Progress::create( $job_id );
        set_transient( 'wmp_job_' . $job_id, $params, HOUR_IN_SECONDS );
        $method = WMP_Runner::dispatch( $job_id, $params );
        wp_send_json_success( array( 'job_id' => $job_id, 'method' => $method ) );
    }

    // ── Poll progress ──────────────────────────────────────────────────────

    public static function wmp_poll_progress() {
        self::verify();
        $job_id = sanitize_key( wp_unslash( isset( $_POST['job_id'] ) ? $_POST['job_id'] : '' ) );
        if ( ! $job_id ) { wp_send_json_error( 'job_id required.' ); }

        $progress = WMP_Progress::load( $job_id );
        if ( ! $progress ) {
            wp_send_json_success( array(
                'status' => 'running', 'pct' => 0,
                'label' => 'Starting\u2026', 'detail' => 'Waiting for job to begin\u2026',
                'log' => array(), 'log_from' => 0, 'log_total' => 0,
            ) );
            return;
        }

        $state    = $progress->get_state();
        $log_from = max( 0, (int) ( isset( $_POST['log_from'] ) ? $_POST['log_from'] : 0 ) );
        $all_log  = isset( $state['log'] ) ? $state['log'] : array();
        $new_log  = array_values( array_slice( $all_log, $log_from ) );

        $state['log']       = $new_log;
        $state['log_from']  = $log_from + count( $new_log );
        $state['log_total'] = count( $all_log );

        if ( $state['status'] === 'running' && $progress->is_stale( 300 ) ) {
            $state['status'] = 'error';
            $state['detail'] = 'Job stalled for 5+ minutes. Check PHP error logs.';
        }

        if ( in_array( $state['status'], array( 'done', 'error' ), true ) && ! empty( $_POST['ack'] ) ) {
            $progress->delete();
            WMP_Progress::purge_old();
        }

        wp_send_json_success( $state );
    }

    // ── Run job (loopback endpoint) ────────────────────────────────────────

    public static function wmp_run_job() {
        $job_id = sanitize_key( wp_unslash( isset( $_POST['job_id'] ) ? $_POST['job_id'] : '' ) );
        $token  = preg_replace( '/[^a-f0-9]/i', '', wp_unslash( isset( $_POST['token'] ) ? $_POST['token'] : '' ) );
        $stored = (string) get_transient( 'wmp_token_' . $job_id );
        if ( ! $job_id || ! $token || ! $stored || ! hash_equals( $stored, $token ) ) {
            wp_die( 'Unauthorized.', '', array( 'response' => 403 ) );
        }
        delete_transient( 'wmp_token_' . $job_id );
        $params = get_transient( 'wmp_job_' . $job_id );
        if ( ! is_array( $params ) ) { wp_die( 'Job params not found.', '', array( 'response' => 404 ) ); }
        delete_transient( 'wmp_job_' . $job_id );
        WMP_Compat::raise_limits();
        @ignore_user_abort( true );
        WMP_Runner::handle_job( $job_id, $params );
        wp_die( '', '', array( 'response' => 200 ) );
    }

    // ── Delete backup ──────────────────────────────────────────────────────

    public static function wmp_delete_backup() {
        self::verify();
        $name     = sanitize_file_name( wp_unslash( isset( $_POST['zip_name'] ) ? $_POST['zip_name'] : '' ) );
        $real     = realpath( WMP_BACKUP_DIR . $name );
        $real_dir = realpath( WMP_BACKUP_DIR );
        if ( ! $real || ! $real_dir || strpos( $real, $real_dir . DIRECTORY_SEPARATOR ) !== 0 ) {
            wp_send_json_error( 'Invalid path.' );
        }
        if ( ! @unlink( $real ) ) { wp_send_json_error( 'Delete failed.' ); }
        wp_send_json_success( 'Deleted.' );
    }

    // ── Download backup ────────────────────────────────────────────────────

    public static function wmp_download_backup() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Unauthorized.', '', array( 'response' => 403 ) ); }
        $name     = sanitize_file_name( wp_unslash( isset( $_GET['file'] ) ? $_GET['file'] : '' ) );
        if ( ! check_ajax_referer( 'wmp_download_' . $name, '_wpnonce', false ) ) { wp_die( 'Bad nonce.', '', array( 'response' => 403 ) ); }
        $real     = realpath( WMP_BACKUP_DIR . $name );
        $real_dir = realpath( WMP_BACKUP_DIR );
        if ( ! $real || ! $real_dir || strpos( $real, $real_dir . DIRECTORY_SEPARATOR ) !== 0 || ! file_exists( $real ) ) {
            wp_die( 'File not found.', '', array( 'response' => 404 ) );
        }
        nocache_headers();
        header( 'Content-Type: application/zip' );
        header( 'Content-Disposition: attachment; filename="' . rawurlencode( $name ) . '"' );
        header( 'Content-Length: ' . filesize( $real ) );
        header( 'X-Content-Type-Options: nosniff' );
        $fp = fopen( $real, 'rb' );
        if ( $fp ) { while ( ! feof( $fp ) ) { echo fread( $fp, 1048576 ); flush(); } fclose( $fp ); } // phpcs:ignore
        exit;
    }

    // ── Requirements ──────────────────────────────────────────────────────

    public static function wmp_check_requirements() {
        self::verify();
        $checks   = WMP_Compat::check_requirements();
        $method   = WMP_Runner::detect_method();
        $checks[] = array(
            'label' => 'Background runner: ' . WMP_Runner::method_label( $method ),
            'ok'    => true,
            'note'  => $method === WMP_Runner::METHOD_INLINE ? 'Uses shutdown execution.' : '',
        );
        wp_send_json_success( $checks );
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private static function verify() {
        check_ajax_referer( 'wmp_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Insufficient permissions.', 403 ); }
    }

    private static function is_private_host( $host ) {
        if ( in_array( strtolower( $host ), array( '127.0.0.1', '::1', 'localhost' ), true ) ) { return true; }
        $ip = gethostbyname( $host );
        if ( $ip === $host ) { return false; }
        return filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) === false;
    }
}
