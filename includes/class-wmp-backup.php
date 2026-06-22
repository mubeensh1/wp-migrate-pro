<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class WMP_Backup {

    private string        $label;
    private string        $zip_path;
    public  array         $log = [];
    private ?WMP_Progress $progress;

    private const SKIP = [
        // Cache & performance dirs
        '#[/\\\\](cache|page_cache|object-cache|wpo-cache|wp-rocket|breeze|litespeed|w3tc)[/\\\\]#i',
        // Temp & upgrade dirs
        '#[/\\\\](tmp|temp)[/\\\\]#i',
        '#[/\\\\]upgrade[/\\\\]#i',
        // Our own backup dir
        '#[/\\\\]wmp-backups[/\\\\]#i',
        '#[/\\\\]wmp-tmp[/\\\\]#i',
        // Log files
        '#\.log$#i',
        // Any directory segment containing backup, backups, or bkp
        // Covers: /backups/, /my-site-backup/, /updraftplus/backups/, /bkp/, /site-bkp/ etc.
        '#[/\\\\][^/\\\\]*(backup|backups|bkp)[^/\\\\]*[/\\\\]#i',
        // Any FILE whose name contains backup, backups, or bkp
        // Covers: backup-2024-01-01.zip, full-site-backup.tar.gz, site_bkp.sql
        '#[/\\\\][^/\\\\]*(backup|backups|bkp)[^/\\\\]*\\.[^/\\\\]+$#i',
        // Known backup plugin dirs (belt-and-suspenders)
        '#[/\\\\](updraft|updraftplus|backupbuddy|wpvivid|duplicator|backwpup|ai1wm-backups|boldgrid-backup|backuply|xcloner)[/\\\\]#i',
    ];

    public function __construct( ?WMP_Progress $progress = null ) {
        $this->label    = gmdate( 'Y-m-d_H-i-s' );
        $this->zip_path = WMP_BACKUP_DIR . 'backup_' . $this->label . '.zip';
        $this->progress = $progress;
    }

    // ── Public ─────────────────────────────────────────────────────────────

    public function run(): array {
        WMP_Compat::raise_limits();
        $this->ensure_dir();
        $this->emit( 'backup_init', 'Backup directory ready', 1.0 );

        $sql = $this->export_db();

        try {
            $this->build_zip( $sql );
        } finally {
            if ( file_exists( $sql ) ) { @unlink( $sql ); }
        }

        if ( ! file_exists( $this->zip_path ) ) {
            throw new RuntimeException( 'ZIP not created — check directory permissions: ' . WMP_BACKUP_DIR );
        }

        $result = [
            'success'  => true,
            'zip_name' => basename( $this->zip_path ),
            'zip_path' => $this->zip_path,
            'size'     => size_format( filesize( $this->zip_path ) ),
            'log'      => $this->log,
        ];

        if ( $this->progress ) { $this->progress->done( $result ); }
        return $result;
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function emit( string $stage, string $detail = '', float $sub = 0.0 ): void {
        if ( $this->progress ) { $this->progress->stage( $stage, $detail, $sub ); }
    }

    private function plog( string $line, string $detail = '', ?int $pct = null ): void {
        $this->log[] = $line;
        if ( $this->progress ) { $this->progress->log( $line, $detail, $pct ); }
    }

    private function ensure_dir(): void {
        if ( ! file_exists( WMP_BACKUP_DIR ) ) { wp_mkdir_p( WMP_BACKUP_DIR ); }
        if ( ! is_writable( WMP_BACKUP_DIR ) ) {
            throw new RuntimeException( 'Backup directory not writable: ' . WMP_BACKUP_DIR );
        }
        $ht = WMP_BACKUP_DIR . '.htaccess';
        if ( ! file_exists( $ht ) ) {
            file_put_contents( $ht, "Options -Indexes\n<FilesMatch \".*\">\n  Order Allow,Deny\n  Deny from all\n</FilesMatch>\n" );
        }
        if ( ! file_exists( WMP_BACKUP_DIR . 'index.php' ) ) {
            file_put_contents( WMP_BACKUP_DIR . 'index.php', '<?php // Silence is golden.' );
        }
    }

    // ── Database ───────────────────────────────────────────────────────────

    private function export_db(): string {
        global $wpdb;

        $sql_path = WMP_BACKUP_DIR . 'db_' . $this->label . '.sql';
        $fh = fopen( $sql_path, 'w' );
        if ( ! $fh ) { throw new RuntimeException( 'Cannot write SQL file: ' . $sql_path ); }

        fwrite( $fh, "-- WP Migrate Pro " . WMP_VERSION . " | " . gmdate( 'c' ) . "\n" );
        fwrite( $fh, "-- Source: " . get_site_url() . "\n\n" );
        fwrite( $fh, "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n" );
        fwrite( $fh, "SET time_zone='+00:00';\n" );
        fwrite( $fh, "SET FOREIGN_KEY_CHECKS=0;\n\n" );

        $tables = $wpdb->get_col( 'SHOW TABLES' ); // phpcs:ignore
        $total  = count( $tables );
        $this->emit( 'backup_db', "Found {$total} tables", 0.0 );
        $this->plog( "Exporting {$total} tables…" );

        foreach ( $tables as $i => $table ) {
            $pct    = $total > 0 ? $i / $total : 0;
            $detail = 'Table ' . ( $i + 1 ) . "/{$total}: {$table}";
            $this->emit( 'backup_db', $detail, $pct );
            $this->plog( "  → {$table}", $detail );
            $this->dump_table( $fh, $table );
        }

        fwrite( $fh, "\nSET FOREIGN_KEY_CHECKS=1;\n" );
        fclose( $fh );

        $this->plog( 'DB export done: ' . size_format( filesize( $sql_path ) ) );
        $this->emit( 'backup_db', 'Database export complete', 1.0 );
        return $sql_path;
    }

    private function dump_table( $fh, string $table ): void {
        global $wpdb;

        $create = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N ); // phpcs:ignore
        if ( ! $create ) { $this->plog( "  ⚠ Skipped {$table} — no CREATE statement" ); return; }

        fwrite( $fh, "\n-- Table: `{$table}`\n" );
        fwrite( $fh, "DROP TABLE IF EXISTS `{$table}`;\n" );
        fwrite( $fh, $create[1] . ";\n\n" );

        $offset = 0;
        $chunk  = 200;
        do {
            $rows = $wpdb->get_results( // phpcs:ignore
                $wpdb->prepare( "SELECT * FROM `{$table}` LIMIT %d OFFSET %d", $chunk, $offset ),
                ARRAY_A
            );
            if ( empty( $rows ) ) { break; }
            fwrite( $fh, "INSERT INTO `{$table}` VALUES\n" );
            $last = count( $rows ) - 1;
            foreach ( $rows as $idx => $row ) {
                $vals = array_map( [ $this, 'sql_val' ], array_values( $row ) );
                fwrite( $fh, '(' . implode( ',', $vals ) . ')' . ( $idx < $last ? ',' : ';' ) . "\n" );
            }
            fwrite( $fh, "\n" );
            $offset += $chunk;
        } while ( count( $rows ) === $chunk );
    }

    private function sql_val( $v ): string {
        if ( $v === null ) { return 'NULL'; }
        return "'" . str_replace(
            [ '\\',   "'",   "\n",   "\r",   "\x00", "\x1a" ],
            [ '\\\\', "\\'", '\\n',  '\\r',  '\\0',  '\\Z'  ],
            $v
        ) . "'";
    }

    // ── ZIP ────────────────────────────────────────────────────────────────

    private function build_zip( string $sql_file ): void {
        $this->emit( 'backup_zip', 'Opening ZIP…', 0.0 );
        $zip = WMP_Compat::open_zip_for_write( $this->zip_path );

        // Manifest
        $zip->addFromString( 'wmp_manifest.json', wp_json_encode( [
            'created_at'  => gmdate( 'c' ),
            'wmp_version' => WMP_VERSION,
            'wp_version'  => get_bloginfo( 'version' ),
            'site_url'    => get_site_url(),
            'home_url'    => get_home_url(),
            'db_prefix'   => $GLOBALS['wpdb']->prefix,
            'sql_file'    => 'wmp_database.sql',
            'php_version' => PHP_VERSION,
        ], JSON_PRETTY_PRINT ) );

        $zip->addFile( $sql_file, 'wmp_database.sql' );

        // Collect files first for accurate progress
        $this->emit( 'backup_zip', 'Scanning files…', 0.02 );
        $this->plog( 'Scanning wp-content…' );
        $files      = $this->scan_files( WP_CONTENT_DIR );
        $total_size = array_sum( array_column( $files, 'size' ) );
        $n          = count( $files );
        $this->plog( "Found {$n} files (" . size_format( $total_size ) . ')' );

        $done_bytes = 0;
        foreach ( $files as $idx => $f ) {
            $sub    = $total_size > 0 ? ( $done_bytes / $total_size ) : ( $idx / max( 1, $n ) );
            $detail = ( $idx + 1 ) . "/{$n}: " . basename( $f['real'] );
            $this->emit( 'backup_zip', $detail, min( 0.98, 0.04 + $sub * 0.94 ) );

            if ( $f['is_dir'] ) {
                $zip->addEmptyDir( $f['local'] );
            } else {
                $zip->addFile( $f['real'], $f['local'] );
                $done_bytes += $f['size'];
            }

            if ( ( $idx + 1 ) % 500 === 0 ) {
                $this->plog( "Compressed " . ( $idx + 1 ) . "/{$n} files…", $detail );
            }
        }

        $this->emit( 'backup_done', 'Finalising ZIP…', 0.0 );
        $zip->close();
        $this->plog( 'ZIP done: ' . basename( $this->zip_path ) . ' (' . size_format( filesize( $this->zip_path ) ) . ')' );
        $this->emit( 'backup_done', 'Complete', 1.0 );
    }

    private function scan_files( string $dir ): array {
        $dir      = rtrim( $dir, '/\\' );
        $bak_real = realpath( WMP_BACKUP_DIR ) ?: WMP_BACKUP_DIR;
        $out      = [];

        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS ),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ( $it as $f ) {
            $real = $f->getRealPath();
            if ( ! $real ) { continue; }
            if ( strpos( $real, $bak_real ) === 0 ) { continue; }

            $unix = str_replace( '\\', '/', $real );
            $skip = false;
            foreach ( self::SKIP as $p ) { if ( preg_match( $p, $unix ) ) { $skip = true; break; } }
            if ( $skip ) { continue; }

            $local = 'wp-content/' . ltrim( str_replace( '\\', '/', str_replace( $dir, '', $real ) ), '/' );

            if ( $f->isDir() ) {
                $out[] = [ 'real' => $real, 'local' => $local, 'size' => 0, 'is_dir' => true ];
            } elseif ( $f->isFile() ) {
                $sz = $f->getSize();
                if ( $sz > 500 * 1024 * 1024 ) { $this->plog( "Skipped >500MB: {$local}" ); continue; }
                $out[] = [ 'real' => $real, 'local' => $local, 'size' => $sz, 'is_dir' => false ];
            }
        }
        return $out;
    }
}
