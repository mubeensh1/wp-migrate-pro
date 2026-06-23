<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class WMP_Import {

    private string        $zip_path;
    private string        $tmp_dir;
    public  array         $log = [];
    private ?WMP_Progress $progress;

    public function __construct( string $zip_path, ?WMP_Progress $progress = null ) {
        $real = realpath( $zip_path );
        if ( ! $real ) { throw new InvalidArgumentException( "ZIP not found: {$zip_path}" ); }
        $allowed = array_filter( array(
            realpath( WMP_BACKUP_DIR ),
            realpath( sys_get_temp_dir() ),
            realpath( WP_CONTENT_DIR ),
        ) );
        $safe = false;
        foreach ( $allowed as $root ) {
            if ( strpos( $real, trailingslashit( $root ) ) === 0 ) { $safe = true; break; }
        }
        if ( ! $safe ) { throw new InvalidArgumentException( "ZIP outside allowed dirs: {$zip_path}" ); }
        $this->zip_path = $real;
        $this->tmp_dir  = trailingslashit( WMP_Compat::get_tmp_dir() ) . 'wmp_import_' . bin2hex( random_bytes( 6 ) ) . '/';
        $this->progress = $progress;
    }

    // ── Import (with URL replacement) ──────────────────────────────────────

    public function run( string $new_url = '' ): array {
        WMP_Compat::raise_limits();
        try {
            $this->emit( 'import_init', 'Initialising…', 0.0 );
            $this->extract();
            $manifest   = $this->read_manifest();
            $source_url = rtrim( isset( $manifest['site_url'] ) ? $manifest['site_url'] : '', '/' );
            $new_url    = rtrim( $new_url ?: get_site_url(), '/' );
            $this->restore_files( 'import_files' );
            $this->import_db( 'import_db' );
            if ( $source_url && $new_url && $source_url !== $new_url ) {
                $this->replace_urls( $source_url, $new_url );
            } else {
                $this->plog( 'URL replacement skipped (same URL).' );
                $this->emit( 'import_urls', 'Skipped — URLs match', 1.0 );
            }
            $this->flush_caches( 'import_cache' );
            $this->cleanup();
            $result = array( 'success' => true, 'source_url' => $source_url, 'new_url' => $new_url, 'log' => $this->log );
            if ( $this->progress ) { $this->progress->done( $result ); }
            return $result;
        } catch ( Throwable $e ) {
            $this->cleanup();
            if ( $this->progress ) { $this->progress->error( $e->getMessage() ); }
            return array( 'success' => false, 'error' => $e->getMessage(), 'log' => $this->log );
        }
    }

    // ── Restore (same site, no URL replacement) ────────────────────────────

    public function restore(): array {
        WMP_Compat::raise_limits();
        try {
            $this->emit( 'restore_init', 'Initialising restore…', 0.0 );
            $this->extract( 'restore_extract' );
            $this->read_manifest(); // validate it's a WMP backup
            $this->restore_files( 'restore_files' );
            $this->import_db( 'restore_db' );
            $this->flush_caches( 'restore_cache' );
            $this->cleanup();
            $result = array( 'success' => true, 'log' => $this->log );
            if ( $this->progress ) { $this->progress->done( $result ); }
            return $result;
        } catch ( Throwable $e ) {
            $this->cleanup();
            if ( $this->progress ) { $this->progress->error( $e->getMessage() ); }
            return array( 'success' => false, 'error' => $e->getMessage(), 'log' => $this->log );
        }
    }

    // ── Shared steps ───────────────────────────────────────────────────────

    private function emit( string $stage, string $detail = '', float $sub = 0.0 ): void {
        if ( $this->progress ) { $this->progress->stage( $stage, $detail, $sub ); }
    }

    private function plog( string $line, string $detail = '', ?int $pct = null ): void {
        $this->log[] = $line;
        if ( $this->progress ) { $this->progress->log( $line, $detail, $pct ); }
    }

    private function extract( string $stage = 'import_extract' ): void {
        $this->emit( $stage, 'Extracting archive…', 0.0 );
        $this->plog( 'Extracting ' . size_format( filesize( $this->zip_path ) ) );
        wp_mkdir_p( $this->tmp_dir );
        if ( ! is_writable( $this->tmp_dir ) ) { throw new RuntimeException( "Temp dir not writable: {$this->tmp_dir}" ); }
        WMP_Compat::extract_zip( $this->zip_path, $this->tmp_dir );
        $this->plog( 'Extracted.' );
        $this->emit( $stage, 'Extraction complete', 1.0 );
    }

    private function read_manifest(): array {
        $f = $this->tmp_dir . 'wmp_manifest.json';
        if ( ! file_exists( $f ) ) { throw new RuntimeException( 'wmp_manifest.json not found — is this a WP Migrate Pro backup?' ); }
        $data = json_decode( file_get_contents( $f ), true ); // phpcs:ignore
        if ( ! is_array( $data ) ) { throw new RuntimeException( 'Manifest JSON is corrupt.' ); }
        $this->plog( 'Manifest OK. Source: ' . ( isset( $data['site_url'] ) ? $data['site_url'] : '?' ) );
        return $data;
    }

    private function restore_files( string $stage = 'import_files' ): void {
        $src = trailingslashit( $this->tmp_dir . 'wp-content' );
        if ( ! is_dir( $src ) ) { $this->plog( 'No wp-content in backup — skipping files.' ); return; }
        $this->emit( $stage, 'Counting files…', 0.0 );
        $total = $this->count_files( $src );
        $this->plog( "Restoring {$total} files…" );
        $copied = 0;
        $this->rcopy( $src, trailingslashit( WP_CONTENT_DIR ), $total, $copied, $stage );
        $this->plog( "Files restored: {$copied} items." );
        $this->emit( $stage, "Restored {$copied} files", 1.0 );
    }

    private function count_files( string $dir ): int {
        $n = 0;
        $it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
        foreach ( $it as $f ) { if ( $f->isFile() ) { $n++; } }
        return $n;
    }

    private function rcopy( string $src, string $dst, int $total, int &$copied, string $stage ): void {
        $bak = realpath( WMP_BACKUP_DIR );
        if ( $bak && strpos( realpath( $src ) ?: $src, $bak ) === 0 ) { return; }
        @mkdir( $dst, 0755, true );
        foreach ( scandir( $src ) as $item ) {
            if ( $item === '.' || $item === '..' ) { continue; }
            $s = $src . $item; $d = $dst . $item;
            if ( is_link( $s ) ) { continue; }
            if ( WMP_Compat::is_restricted_path( $d ) ) { $this->plog( "Skipped (restricted): {$d}" ); continue; }
            if ( is_dir( $s ) ) {
                $this->rcopy( trailingslashit( $s ), trailingslashit( $d ), $total, $copied, $stage );
            } else {
                if ( ! @copy( $s, $d ) ) { $this->plog( "⚠ Copy failed: {$item}" ); }
                $copied++;
                if ( $copied % 100 === 0 ) {
                    $sub    = $total > 0 ? min( 0.99, $copied / $total ) : 0;
                    $detail = "File {$copied}/{$total}: {$item}";
                    $this->emit( $stage, $detail, $sub );
                    $this->plog( "  {$copied}/{$total} files…", $detail );
                }
            }
        }
    }

    private function import_db( string $stage = 'import_db' ): void {
        global $wpdb;
        $sql_file = $this->tmp_dir . 'wmp_database.sql';
        if ( ! file_exists( $sql_file ) ) { throw new RuntimeException( 'wmp_database.sql not found.' ); }
        $sql_size = filesize( $sql_file );
        $this->emit( $stage, 'Importing database…', 0.0 );
        $this->plog( 'Importing database (' . size_format( $sql_size ) . ')…' );
        $fh = fopen( $sql_file, 'r' );
        if ( ! $fh ) { throw new RuntimeException( 'Cannot open SQL file.' ); }
        $suppress = $wpdb->suppress_errors( true );
        $stmt = ''; $in_comment = false; $stmts = 0; $errs = 0; $bytes = 0;
        while ( ! feof( $fh ) ) {
            $line = fgets( $fh, 1048576 );
            if ( $line === false ) { break; }
            $bytes  += strlen( $line );
            $trimmed = ltrim( $line );
            if ( preg_match( '/^-- Table: `(.+?)`/', $trimmed, $m ) ) {
                $sub = $sql_size > 0 ? min( 0.99, $bytes / $sql_size ) : 0;
                $this->emit( $stage, "Table: {$m[1]}", $sub );
                // table progress via emit only
            }
            if ( $trimmed === '' || strncmp( $trimmed, '--', 2 ) === 0 || strncmp( $trimmed, '#', 1 ) === 0 ) { continue; }
            if ( ! $in_comment && strncmp( $trimmed, '/*', 2 ) === 0 ) {
                if ( strpos( $trimmed, '*/' ) !== false ) { continue; }
                $in_comment = true; continue;
            }
            if ( $in_comment ) { if ( strpos( $trimmed, '*/' ) !== false ) { $in_comment = false; } continue; }
            $stmt .= $line;
            if ( substr( rtrim( $stmt ), -1 ) === ';' ) {
                $wpdb->query( $stmt ); // phpcs:ignore
                if ( $wpdb->last_error && ++$errs <= 30 ) { $this->plog( '  ⚠ SQL: ' . substr( $wpdb->last_error, 0, 180 ) ); }
                $stmt = ''; $stmts++;
                if ( $stmts % 20 === 0 ) { $this->emit( $stage, "{$stmts} statements…", min( 0.99, $bytes / max(1,$sql_size) ) ); }
            }
        }
        fclose( $fh );
        $wpdb->suppress_errors( $suppress );
        $this->plog( "Database import complete." );
        $this->emit( $stage, "Done ({$stmts} statements)", 1.0 );
    }

    private function replace_urls( string $old, string $new ): void {
        global $wpdb;
        $this->emit( 'import_urls', 'Replacing URLs…', 0.0 );
        $this->plog( "Replacing: {$old} → {$new}" );
        $old_proto = preg_replace( '#^https?:#', '', $old );
        $new_proto = preg_replace( '#^https?:#', '', $new );
        $tables = $wpdb->get_col( 'SHOW TABLES' ); // phpcs:ignore
        $total  = count( $tables ); $count = 0;
        foreach ( $tables as $i => $table ) {
            $this->emit( 'import_urls', 'Table ' . ( $i + 1 ) . "/{$total}: {$table}", $i / $total );
            $pks = array();
            foreach ( $wpdb->get_results( "SHOW KEYS FROM `{$table}` WHERE Key_name='PRIMARY'", ARRAY_A ) as $kr ) { $pks[] = $kr['Column_name']; } // phpcs:ignore
            $cols = $wpdb->get_results( "DESCRIBE `{$table}`", ARRAY_A ); // phpcs:ignore
            foreach ( $cols as $col ) {
                if ( ! preg_match( '/text|varchar|longtext|mediumtext|tinytext/i', $col['Type'] ) ) { continue; }
                $cn = $col['Field'];
                $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE `{$cn}` LIKE %s", '%' . $wpdb->esc_like( $old ) . '%' ), ARRAY_A ); // phpcs:ignore
                foreach ( $rows as $row ) {
                    $orig    = $row[ $cn ];
                    $updated = $this->deep_replace( $old, $new, $orig );
                    if ( $old_proto !== $old ) { $updated = $this->deep_replace( $old_proto, $new_proto, $updated ); }
                    if ( $updated === $orig || empty( $pks ) ) { continue; }
                    $where = array();
                    foreach ( $pks as $pk ) { $where[] = $wpdb->prepare( "`{$pk}`=%s", $row[ $pk ] ); } // phpcs:ignore
                    $wpdb->query( $wpdb->prepare( "UPDATE `{$table}` SET `{$cn}`=%s WHERE " . implode( ' AND ', $where ), $updated ) ); // phpcs:ignore
                    $count++;
                }
            }
            if ( ( $i + 1 ) % 5 === 0 ) { $this->plog( "Replacing URLs in tables…" ); }
        }
        update_option( 'siteurl', $new );
        update_option( 'home',    $new );
        $this->plog( "URL replacement complete." );
        $this->emit( 'import_urls', "Done — {$count} replacements", 1.0 );
    }

    private function flush_caches( string $stage = 'import_cache' ): void {
        $this->emit( $stage, 'Flushing caches…', 0.0 );
        wp_cache_flush();
        WMP_Compat::flush_wpengine_cache();
        if ( function_exists( 'rocket_clean_domain' ) )        { rocket_clean_domain(); }
        if ( function_exists( 'w3tc_flush_all' ) )             { w3tc_flush_all(); }
        if ( function_exists( 'wp_super_cache_clear_cache' ) ) { wp_super_cache_clear_cache(); }
        if ( class_exists( 'autoptimizeCache', false ) && method_exists( 'autoptimizeCache', 'clearall' ) ) { autoptimizeCache::clearall(); }
        flush_rewrite_rules( true );
        $this->plog( 'Caches flushed.' );
        $this->emit( $stage, 'Caches flushed', 1.0 );
    }

    private function deep_replace( string $old, string $new, string $data ): string {
        if ( function_exists( 'is_serialized' ) ? is_serialized( $data ) : $this->is_serial( $data ) ) {
            $u = @unserialize( $data ); // phpcs:ignore
            if ( $u !== false || $data === serialize( false ) ) {
                return $this->fix_lengths( serialize( $this->recurse( $old, $new, $u ) ) );
            }
        }
        return str_replace( $old, $new, $data );
    }

    private function recurse( string $old, string $new, $v ) {
        if ( is_string( $v ) ) { return str_replace( $old, $new, $v ); }
        if ( is_array( $v ) ) {
            $r = array();
            foreach ( $v as $k => $val ) { $r[ $this->recurse( $old, $new, $k ) ] = $this->recurse( $old, $new, $val ); }
            return $r;
        }
        if ( is_object( $v ) ) {
            $c = clone $v;
            foreach ( get_object_vars( $c ) as $k => $val ) { $c->$k = $this->recurse( $old, $new, $val ); }
            return $c;
        }
        return $v;
    }

    private function is_serial( string $d ): bool {
        $d = trim( $d );
        if ( $d === 'N;' || $d === 'b:0;' ) { return true; }
        if ( ! in_array( substr( $d, -1 ), array( ';', '}' ), true ) ) { return false; }
        return in_array( $d[0], array( 's', 'a', 'o', 'i', 'd', 'b', 'N', 'C' ), true );
    }

    private function fix_lengths( string $d ): string {
        return preg_replace_callback( '/s:(\d+):"(.*?)";/s', static function( $m ) {
            return 's:' . strlen( $m[2] ) . ':"' . $m[2] . '";';
        }, $d );
    }

    private function cleanup(): void {
        if ( $this->tmp_dir && is_dir( $this->tmp_dir ) ) { WMP_Compat::rrmdir( $this->tmp_dir ); }
    }
}
