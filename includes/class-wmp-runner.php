<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class WMP_Runner {

    const METHOD_LOOPBACK = 'loopback';
    const METHOD_CRON     = 'cron';
    const METHOD_INLINE   = 'inline';
    const CRON_HOOK       = 'wmp_run_cron_job';

    private static $inline_queue      = array();
    private static $shutdown_registered = false;

    public static function init() {
        add_action( self::CRON_HOOK, array( __CLASS__, 'handle_cron_job' ), 10, 2 );
        if ( isset( $_GET['wmp_cron'] ) && $_GET['wmp_cron'] === '1' ) {
            add_action( 'init', array( __CLASS__, 'handle_cron_kick' ), 1 );
        }
    }

    public static function handle_cron_kick() {
        if ( ! defined( 'DOING_CRON' ) ) { define( 'DOING_CRON', true ); }
        do_action( 'wp_cron' );
        exit;
    }

    public static function detect_method() {
        $cached = get_transient( 'wmp_runner_method' );
        if ( $cached && in_array( $cached, array( self::METHOD_LOOPBACK, self::METHOD_CRON, self::METHOD_INLINE ), true ) ) {
            return $cached;
        }
        if ( self::test_loopback() ) {
            $method = self::METHOD_LOOPBACK;
        } elseif ( self::cron_available() ) {
            $method = self::METHOD_CRON;
        } else {
            $method = self::METHOD_INLINE;
        }
        set_transient( 'wmp_runner_method', $method, HOUR_IN_SECONDS );
        return $method;
    }

    public static function reset_detection() { delete_transient( 'wmp_runner_method' ); }

    public static function method_label( $method ) {
        $labels = array(
            self::METHOD_LOOPBACK => 'Background (loopback)',
            self::METHOD_CRON     => 'Background (cron)',
            self::METHOD_INLINE   => 'Background (inline)',
        );
        return isset( $labels[ $method ] ) ? $labels[ $method ] : $method;
    }

    public static function dispatch( $job_id, array $params, $force = '' ) {
        $method = $force ? $force : self::detect_method();
        switch ( $method ) {
            case self::METHOD_LOOPBACK: self::dispatch_loopback( $job_id, $params ); break;
            case self::METHOD_CRON:     self::dispatch_cron( $job_id, $params );     break;
            default:                    self::dispatch_inline( $job_id, $params );   break;
        }
        return $method;
    }

    private static function dispatch_loopback( $job_id, array $params ) {
        $token = bin2hex( random_bytes( 24 ) );
        set_transient( 'wmp_job_'   . $job_id, $params, HOUR_IN_SECONDS );
        set_transient( 'wmp_token_' . $job_id, $token,  HOUR_IN_SECONDS );
        wp_remote_post( admin_url( 'admin-ajax.php' ), array(
            'timeout'    => 1,
            'blocking'   => false,
            'sslverify'  => apply_filters( 'https_local_ssl_verify', false ),
            'user-agent' => 'WP-Migrate-Pro/' . WMP_VERSION,
            'body'       => array( 'action' => 'wmp_run_job', 'job_id' => $job_id, 'token' => $token ),
        ) );
    }

    private static function dispatch_cron( $job_id, array $params ) {
        set_transient( 'wmp_job_' . $job_id, $params, HOUR_IN_SECONDS );
        wp_schedule_single_event( time() + 3, self::CRON_HOOK, array( $job_id, array() ) );
        $cron_url = add_query_arg( 'wmp_cron', '1', site_url( '/' ) );
        wp_remote_get( $cron_url, array( 'timeout' => 1, 'blocking' => false, 'sslverify' => apply_filters( 'https_local_ssl_verify', false ) ) );
        wp_remote_post( site_url( '/wp-cron.php?doing_wp_cron' ), array( 'timeout' => 1, 'blocking' => false, 'sslverify' => apply_filters( 'https_local_ssl_verify', false ) ) );
    }

    public static function handle_cron_job( $job_id, $unused ) {
        WMP_Compat::raise_limits();
        @ignore_user_abort( true );
        $params = get_transient( 'wmp_job_' . $job_id );
        if ( ! is_array( $params ) ) {
            WMP_Progress::load_or_create( $job_id )->error( 'Cron: job params not found.' );
            return;
        }
        delete_transient( 'wmp_job_' . $job_id );
        self::handle_job( $job_id, $params );
    }

    private static function dispatch_inline( $job_id, array $params ) {
        self::$inline_queue[ $job_id ] = $params;
        if ( ! self::$shutdown_registered ) {
            self::$shutdown_registered = true;
            register_shutdown_function( array( 'WMP_Runner', 'shutdown_handler' ) );
        }
    }

    public static function shutdown_handler() {
        if ( empty( self::$inline_queue ) ) { return; }
        self::close_connection();
        WMP_Compat::raise_limits();
        @ignore_user_abort( true );
        $queue = self::$inline_queue;
        self::$inline_queue = array();
        foreach ( $queue as $job_id => $params ) {
            self::handle_job( $job_id, $params );
        }
    }

    private static function close_connection() {
        if ( function_exists( 'fastcgi_finish_request' ) ) { fastcgi_finish_request(); return; }
        if ( ! headers_sent() ) { header( 'Connection: close' ); header( 'Content-Encoding: none' ); }
        $level = ob_get_level();
        while ( $level-- > 0 ) { @ob_end_flush(); }
        flush();
    }

    // ── Core executor — uses load_or_create to NEVER overwrite existing progress ──

    public static function handle_job( $job_id, array $params ) {
        // load_or_create: if the AJAX handler already wrote the progress file,
        // we load it (preserving the initial state). We do NOT call create()
        // which would reset pct to 0 and overwrite "Starting…" state.
        $progress = WMP_Progress::load_or_create( $job_id );

        try {
            $type = isset( $params['type'] ) ? $params['type'] : '';

            if ( $type === 'backup' ) {
                $backup = new WMP_Backup( $progress );
                $backup->run();

            } elseif ( $type === 'import' ) {
                if ( ( isset( $params['import_type'] ) ? $params['import_type'] : '' ) === 'remote' ) {
                    $tmp_zip = WMP_BACKUP_DIR . 'remote_' . bin2hex( random_bytes( 8 ) ) . '.zip';
                    $progress->stage( 'import_init', 'Downloading remote backup…', 0.3 );
                    $response = wp_remote_get( $params['remote_url'], array(
                        'timeout' => 600, 'stream' => true, 'filename' => $tmp_zip, 'sslverify' => true,
                        'user-agent' => 'WP-Migrate-Pro/' . WMP_VERSION,
                    ) );
                    if ( is_wp_error( $response ) ) { throw new RuntimeException( 'Download failed: ' . $response->get_error_message() ); }
                    if ( (int) wp_remote_retrieve_response_code( $response ) !== 200 ) { throw new RuntimeException( 'Remote returned HTTP ' . wp_remote_retrieve_response_code( $response ) ); }
                    $params['zip_path'] = $tmp_zip;
                }
                $importer = new WMP_Import( $params['zip_path'], $progress );
                $importer->run( isset( $params['new_url'] ) ? $params['new_url'] : '' );
                if ( isset( $tmp_zip ) && file_exists( $tmp_zip ) ) { @unlink( $tmp_zip ); }

            } elseif ( $type === 'restore' ) {
                $importer = new WMP_Import( $params['zip_path'], $progress );
                $importer->restore();
            }

        } catch ( Throwable $e ) {
            $progress->error( $e->getMessage() );
        }
    }

    public static function test_loopback() {
        if ( class_exists( 'WP_Site_Health' ) ) {
            $health = WP_Site_Health::get_instance();
            if ( method_exists( $health, 'can_perform_loopback' ) ) {
                $r = $health->can_perform_loopback();
                return isset( $r->status ) && $r->status === 'good';
            }
        }
        $response = wp_remote_post( admin_url( 'admin-ajax.php' ), array(
            'timeout' => 8, 'blocking' => true,
            'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
            'user-agent' => 'WP-Migrate-Pro-Test/' . WMP_VERSION,
            'body' => array( 'action' => 'wmp_loopback_test' ),
        ) );
        if ( is_wp_error( $response ) ) { return false; }
        return in_array( (int) wp_remote_retrieve_response_code( $response ), array( 200, 400 ), true );
    }

    public static function cron_available() {
        return ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON );
    }
}
