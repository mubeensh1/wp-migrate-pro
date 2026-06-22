<?php
/**
 * WMP_Compat — environment detection and abstraction layer.
 * Handles WP Engine, Rocket.net, standard hosts, and local/Docker environments.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class WMP_Compat {

    private static ?bool $is_wpengine = null;
    private static ?bool $is_rocket   = null;
    private static ?bool $has_zip     = null;
    private static ?bool $has_pclzip  = null;

    // ── Detection ──────────────────────────────────────────────────────────

    public static function is_wpengine(): bool {
        if ( self::$is_wpengine === null ) {
            self::$is_wpengine = (
                defined( 'WPE_APIKEY' ) || defined( 'WPE_PLUGIN_BASE' )
                || class_exists( 'WpePlugin', false )
                || isset( $_SERVER['HTTP_X_WP_ENGINE'] )
                || ( isset( $_SERVER['SERVER_SOFTWARE'] ) && stripos( (string) $_SERVER['SERVER_SOFTWARE'], 'wpengine' ) !== false )
            );
        }
        return self::$is_wpengine;
    }

    public static function is_rocket_net(): bool {
        if ( self::$is_rocket === null ) {
            self::$is_rocket = (
                isset( $_SERVER['HTTP_X_ROCKETNET'] )
                || ( function_exists( 'getenv' ) && getenv( 'ROCKETNET_ENV' ) !== false )
            );
        }
        return self::$is_rocket;
    }

    public static function has_zip_archive(): bool {
        if ( self::$has_zip === null ) {
            self::$has_zip = class_exists( 'ZipArchive' );
        }
        return self::$has_zip;
    }

    public static function has_pclzip(): bool {
        if ( self::$has_pclzip === null ) {
            if ( ! class_exists( 'PclZip', false ) ) {
                $path = ABSPATH . 'wp-admin/includes/class-pclzip.php';
                if ( file_exists( $path ) ) {
                    require_once $path;
                }
            }
            self::$has_pclzip = class_exists( 'PclZip', false );
        }
        return self::$has_pclzip;
    }

    // ── Temp directory ─────────────────────────────────────────────────────

    public static function get_tmp_dir(): string {
        $candidates = [
            WMP_BACKUP_DIR . 'tmp/',
            WP_CONTENT_DIR . '/wmp-tmp/',
            sys_get_temp_dir() . '/',
            ABSPATH . 'tmp/',
        ];
        foreach ( $candidates as $dir ) {
            $dir = trailingslashit( $dir );
            if ( ! file_exists( $dir ) ) {
                @wp_mkdir_p( $dir );
            }
            if ( is_writable( $dir ) ) {
                return $dir;
            }
        }
        return WMP_BACKUP_DIR;
    }

    // ── Limits ─────────────────────────────────────────────────────────────

    public static function raise_limits(): void {
        if ( ! self::is_wpengine() ) {
            @ini_set( 'memory_limit', '512M' ); // phpcs:ignore
        }
        wp_raise_memory_limit( 'admin' );
        if ( function_exists( 'set_time_limit' ) && ! self::is_wpengine() ) {
            @set_time_limit( 0 );
        }
    }

    // ── ZIP abstraction ────────────────────────────────────────────────────

    /**
     * @throws RuntimeException
     */
    public static function open_zip_for_write( string $path ): object {
        if ( self::has_zip_archive() ) {
            $zip = new ZipArchive();
            $r   = $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
            if ( $r !== true ) {
                throw new RuntimeException( "ZipArchive::open failed (code {$r}): {$path}" );
            }
            return $zip;
        }
        if ( self::has_pclzip() ) {
            return new WMP_PclZip_Writer( $path );
        }
        throw new RuntimeException( 'No ZIP library available. Enable the PHP ZipArchive extension.' );
    }

    /**
     * @throws RuntimeException
     */
    public static function extract_zip( string $path, string $dest ): void {
        if ( ! file_exists( $path ) ) {
            throw new RuntimeException( "ZIP not found: {$path}" );
        }
        if ( self::has_zip_archive() ) {
            $zip = new ZipArchive();
            if ( $zip->open( $path ) !== true ) {
                throw new RuntimeException( "Cannot open ZIP: {$path}" );
            }
            // Zip-slip guard
            for ( $i = 0; $i < $zip->numFiles; $i++ ) {
                $entry = $zip->getNameIndex( $i );
                if ( self::is_unsafe_zip_path( $entry ) ) {
                    $zip->close();
                    throw new RuntimeException( "Unsafe path in ZIP: {$entry}" );
                }
            }
            $zip->extractTo( $dest );
            $zip->close();
            return;
        }
        if ( self::has_pclzip() ) {
            $pclzip = new PclZip( $path );
            $result = $pclzip->extract( PCLZIP_OPT_PATH, $dest );
            if ( $result === 0 ) {
                throw new RuntimeException( 'PclZip extract failed: ' . $pclzip->errorInfo( true ) );
            }
            return;
        }
        throw new RuntimeException( 'No ZIP library available to extract archive.' );
    }

    public static function is_unsafe_zip_path( string $entry ): bool {
        return (
            strpos( $entry, '..' ) !== false
            || strpos( $entry, "\x00" ) !== false
            || preg_match( '#^(/|[A-Za-z]:[\\\\/])#', $entry )
        );
    }

    // ── Managed-host helpers ───────────────────────────────────────────────

    public static function flush_wpengine_cache(): void {
        if ( ! self::is_wpengine() ) { return; }
        if ( class_exists( 'WpePlugin', false ) && method_exists( 'WpePlugin', 'purge_varnish_cache' ) ) {
            WpePlugin::purge_varnish_cache( null );
        }
        if ( function_exists( 'wpe_param_flush_cache' ) ) {
            wpe_param_flush_cache();
        }
    }

    public static function is_restricted_path( string $path ): bool {
        if ( ! self::is_wpengine() ) { return false; }
        $restricted = [ ABSPATH . 'wp-config.php', ABSPATH . '.htaccess' ];
        foreach ( $restricted as $r ) {
            if ( realpath( $path ) === realpath( $r ) ) { return true; }
        }
        return false;
    }

    // ── Safe recursive delete ──────────────────────────────────────────────

    public static function rrmdir( string $dir ): void {
        if ( ! is_dir( $dir ) ) { return; }
        $dir      = trailingslashit( realpath( $dir ) ?: $dir );
        $safe     = [ realpath( WP_CONTENT_DIR ), realpath( WMP_BACKUP_DIR ) ];
        $is_safe  = false;
        foreach ( $safe as $root ) {
            if ( $root && strpos( $dir, trailingslashit( $root ) ) === 0 ) {
                $is_safe = true; break;
            }
        }
        if ( ! $is_safe ) { return; }

        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ( $it as $item ) {
            if ( $item->isLink() || $item->isFile() ) { @unlink( $item->getRealPath() ); }
            elseif ( $item->isDir() ) { @rmdir( $item->getRealPath() ); }
        }
        @rmdir( $dir );
    }

    // ── Requirements check ─────────────────────────────────────────────────

    public static function check_requirements(): array {
        $checks = [];

        $checks[] = [
            'label' => 'PHP ' . PHP_VERSION,
            'ok'    => version_compare( PHP_VERSION, '7.4', '>=' ),
            'note'  => 'PHP 7.4+ required.',
        ];

        $zip_ok   = self::has_zip_archive() || self::has_pclzip();
        $checks[] = [
            'label' => 'ZIP library: ' . ( self::has_zip_archive() ? 'ZipArchive' : ( self::has_pclzip() ? 'PclZip (WP core)' : 'None' ) ),
            'ok'    => $zip_ok,
            'note'  => $zip_ok ? '' : 'Enable the PHP zip extension.',
        ];

        $dir_ok   = ( file_exists( WMP_BACKUP_DIR ) || wp_mkdir_p( WMP_BACKUP_DIR ) ) && is_writable( WMP_BACKUP_DIR );
        $checks[] = [
            'label' => 'Backup directory writable',
            'ok'    => $dir_ok,
            'note'  => WMP_BACKUP_DIR . ( $dir_ok ? ' ✔' : ' — not writable!' ),
        ];

        $max_exec = (int) ini_get( 'max_execution_time' );
        $checks[] = [
            'label' => 'max_execution_time: ' . ( $max_exec === 0 ? 'unlimited' : $max_exec . 's' ),
            'ok'    => $max_exec === 0 || $max_exec >= 120,
            'note'  => 'Values < 120s may timeout on large sites. The plugin works around this with background execution.',
        ];

        $mem    = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );
        $mem_ok = $mem === -1 || $mem >= 128 * 1024 * 1024;
        $checks[] = [
            'label' => 'memory_limit: ' . ini_get( 'memory_limit' ),
            'ok'    => $mem_ok,
            'note'  => '128 MB minimum recommended.',
        ];

        $ul     = wp_convert_hr_to_bytes( ini_get( 'upload_max_filesize' ) );
        $checks[] = [
            'label' => 'upload_max_filesize: ' . ini_get( 'upload_max_filesize' ),
            'ok'    => $ul >= 64 * 1024 * 1024,
            'note'  => 'Only affects browser uploads. Use Remote URL import to bypass this limit.',
        ];

        $env = 'Standard';
        if ( self::is_wpengine() )   { $env = 'WP Engine'; }
        if ( self::is_rocket_net() ) { $env = 'Rocket.net'; }
        $checks[] = [
            'label' => 'Host: ' . $env,
            'ok'    => true,
            'note'  => '',
        ];

        return $checks;
    }
}

// ── PclZip write adapter ───────────────────────────────────────────────────

class WMP_PclZip_Writer {
    private string $path;
    private array  $files = [];
    private string $tmp;

    public function __construct( string $path ) {
        $this->path = $path;
        $this->tmp  = trailingslashit( WMP_Compat::get_tmp_dir() ) . 'pcl_' . bin2hex( random_bytes( 6 ) ) . '/';
        @wp_mkdir_p( $this->tmp );
    }

    public function addFile( string $disk, string $local ): void {
        $this->files[] = [ 'disk' => $disk, 'local' => $local ];
    }

    public function addFromString( string $local, string $content ): void {
        $tmp = $this->tmp . md5( $local );
        file_put_contents( $tmp, $content );
        $this->files[] = [ 'disk' => $tmp, 'local' => $local ];
    }

    public function addEmptyDir( string $local ): void {
        // PclZip creates dirs implicitly — no-op.
    }

    public function close(): void {
        $pclzip = new PclZip( $this->path );
        $disks  = array_column( $this->files, 'disk' );
        $map    = [];
        foreach ( $this->files as $e ) {
            $map[ realpath( $e['disk'] ) ?: $e['disk'] ] = $e['local'];
        }
        // Store map in global so the named callback can access it.
        // Closures cannot be used here — PclZip passes the callback name
        // through call_user_func which internally calls function_exists(),
        // and function_exists() only accepts strings, not Closures.
        $GLOBALS['_wmp_pclzip_map'] = $map;
        $result = $pclzip->add( $disks, PCLZIP_CB_PRE_ADD, 'wmp_pclzip_pre_add_callback' );
        $GLOBALS['_wmp_pclzip_map'] = array();
        WMP_Compat::rrmdir( $this->tmp );
        if ( $result === 0 ) {
            throw new RuntimeException( 'PclZip write failed: ' . $pclzip->errorInfo( true ) );
        }
    }
}

/**
 * Named global callback for PclZip PCLZIP_CB_PRE_ADD.
 * Must be a named function — PclZip passes it through call_user_func()
 * which calls function_exists() internally, rejecting Closures.
 * The filename map is stored in $GLOBALS['_wmp_pclzip_map'] by WMP_PclZip_Writer::close().
 */
function wmp_pclzip_pre_add_callback( $event, &$header ) {
    $map = isset( $GLOBALS['_wmp_pclzip_map'] ) ? $GLOBALS['_wmp_pclzip_map'] : array();
    $r   = $header['filename'];
    if ( isset( $map[ $r ] ) ) {
        $header['stored_filename'] = $map[ $r ];
    }
    return 1;
}
