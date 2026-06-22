<?php
/**
 * WMP_Progress — writes a JSON progress file polled by the browser.
 *
 * FACTORY PATTERN — never call `new WMP_Progress()` directly outside this class.
 *
 *  WMP_Progress::create($id)           — AJAX start handlers only. Creates file on disk.
 *  WMP_Progress::load($id)             — poll handler. Reads existing file, never writes.
 *  WMP_Progress::load_or_create($id)   — job runners. Loads if exists, else creates.
 *
 * The 95→0 bug was caused by `new WMP_Progress($id)` in handle_job() always
 * calling write() in the constructor, overwriting the progress file that the
 * AJAX start handler had just written with the initial "Starting…" state.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class WMP_Progress {

    private string $job_id;
    private string $file;
    private array  $state;
    private int    $last_write = 0;

    public const STAGES = array(
        'backup_init'    => array( 'label' => 'Initialising',        'start' =>  0, 'end' =>  2  ),
        'backup_db'      => array( 'label' => 'Exporting database',  'start' =>  2, 'end' => 38  ),
        'backup_zip'     => array( 'label' => 'Compressing files',   'start' => 38, 'end' => 95  ),
        'backup_done'    => array( 'label' => 'Finalising',          'start' => 95, 'end' => 100 ),
        'import_init'    => array( 'label' => 'Initialising',        'start' =>  0, 'end' =>  3  ),
        'import_extract' => array( 'label' => 'Extracting archive',  'start' =>  3, 'end' => 15  ),
        'import_files'   => array( 'label' => 'Restoring files',     'start' => 15, 'end' => 45  ),
        'import_db'      => array( 'label' => 'Importing database',  'start' => 45, 'end' => 78  ),
        'import_urls'    => array( 'label' => 'Replacing URLs',      'start' => 78, 'end' => 92  ),
        'import_cache'   => array( 'label' => 'Flushing caches',     'start' => 92, 'end' => 98  ),
        'import_done'    => array( 'label' => 'Complete',            'start' => 98, 'end' => 100 ),
        'restore_init'   => array( 'label' => 'Initialising restore','start' =>  0, 'end' =>  3  ),
        'restore_extract'=> array( 'label' => 'Extracting archive',  'start' =>  3, 'end' => 15  ),
        'restore_files'  => array( 'label' => 'Restoring files',     'start' => 15, 'end' => 55  ),
        'restore_db'     => array( 'label' => 'Restoring database',  'start' => 55, 'end' => 90  ),
        'restore_cache'  => array( 'label' => 'Flushing caches',     'start' => 90, 'end' => 98  ),
        'restore_done'   => array( 'label' => 'Complete',            'start' => 98, 'end' => 100 ),
    );

    // ── Internal constructor — use factory methods ──────────────────────────

    private function __construct( string $job_id ) {
        if ( ! preg_match( '/^[a-z0-9_]{4,80}$/i', $job_id ) ) {
            throw new InvalidArgumentException( "Invalid job ID: {$job_id}" );
        }
        $this->job_id = $job_id;
        $this->file   = WMP_BACKUP_DIR . '.progress_' . $job_id . '.json';
        $this->state  = array(
            'job_id'  => $job_id,
            'status'  => 'running',
            'pct'     => 0,
            'stage'   => '',
            'label'   => 'Starting\u2026',
            'detail'  => '',
            'log'     => array(),
            'result'  => null,
            'updated' => time(),
        );
    }

    // ── Factories ──────────────────────────────────────────────────────────

    /** Call from AJAX start handlers only. Writes initial state to disk. */
    public static function create( string $job_id ): self {
        $obj = new self( $job_id );
        $obj->write();
        return $obj;
    }

    /** Call from poll handler only. Loads file, never writes. Returns null if missing. */
    public static function load( string $job_id ): ?self {
        if ( ! preg_match( '/^[a-z0-9_]{4,80}$/i', $job_id ) ) { return null; }
        $file = WMP_BACKUP_DIR . '.progress_' . $job_id . '.json';
        if ( ! file_exists( $file ) ) { return null; }
        $raw  = @file_get_contents( $file );
        if ( ! $raw ) { return null; }
        $data = json_decode( $raw, true );
        if ( ! is_array( $data ) ) { return null; }
        $obj             = new self( $job_id );
        $obj->state      = $data;
        $obj->last_write = time();  // prevent immediate throttle-write
        return $obj;
    }

    /** Call from job runners. Loads existing file (preserving state) or creates fresh. */
    public static function load_or_create( string $job_id ): self {
        $obj = self::load( $job_id );
        if ( $obj !== null ) {
            return $obj;
        }
        return self::create( $job_id );
    }

    public static function generate_id(): string {
        return 'wmp_' . bin2hex( random_bytes( 12 ) );
    }

    // ── State updates ──────────────────────────────────────────────────────

    public function stage( string $stage, string $detail = '', float $sub = 0.0 ): void {
        $stages = self::STAGES;
        $def    = isset( $stages[ $stage ] ) ? $stages[ $stage ] : array( 'label' => $stage, 'start' => 0, 'end' => 100 );
        $range  = $def['end'] - $def['start'];
        $pct    = (int) round( $def['start'] + $range * max( 0.0, min( 1.0, $sub ) ) );

        $this->state['stage']  = $stage;
        $this->state['label']  = $def['label'];
        $this->state['detail'] = $detail;
        $this->state['pct']    = $pct;
        $this->state['status'] = 'running';
        $this->write();
    }

    public function log( string $line, string $detail = '', ?int $pct = null ): void {
        $this->state['log'][] = $line;
        if ( $detail !== '' ) { $this->state['detail'] = $detail; }
        if ( $pct !== null )  { $this->state['pct']    = $pct; }
        if ( ( time() - $this->last_write ) >= 1 ) { $this->write(); }
    }

    public function done( array $result ): void {
        $this->state['status'] = 'done';
        $this->state['pct']    = 100;
        $this->state['label']  = 'Complete';
        $this->state['detail'] = '';
        $this->state['result'] = $result;
        $this->write();
    }

    public function error( string $message ): void {
        $this->state['status'] = 'error';
        $this->state['label']  = 'Error';
        $this->state['detail'] = $message;
        $this->state['log'][]  = '✖ ' . $message;
        $this->write();
    }

    // ── Accessors ──────────────────────────────────────────────────────────

    public function get_state(): array   { return $this->state; }
    public function get_job_id(): string { return $this->job_id; }

    public function is_stale( int $seconds = 300 ): bool {
        return ( time() - ( isset( $this->state['updated'] ) ? $this->state['updated'] : 0 ) ) > $seconds;
    }

    public function delete(): void {
        if ( file_exists( $this->file ) ) { @unlink( $this->file ); }
    }

    public static function purge_old( int $max_age = 3600 ): void {
        $files = glob( WMP_BACKUP_DIR . '.progress_*.json' );
        if ( ! $files ) { return; }
        foreach ( $files as $f ) {
            if ( ( time() - filemtime( $f ) ) > $max_age ) { @unlink( $f ); }
        }
    }

    // ── Internal ───────────────────────────────────────────────────────────

    private function write(): void {
        $this->state['updated'] = time();
        $this->last_write       = time();
        @file_put_contents( $this->file, wp_json_encode( $this->state ), LOCK_EX );
    }
}
