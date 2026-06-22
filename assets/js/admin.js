/* global jQuery, WMP */
( function( $ ) {
'use strict';

// ── Tab switching ──────────────────────────────────────────────────────────
function switchTab( name ) {
    $( '.wmp-tab' ).removeClass( 'active' ).attr( 'aria-selected', 'false' );
    $( '.wmp-panel' ).hide();
    $( '.wmp-tab[data-tab="' + name + '"]' ).addClass( 'active' ).attr( 'aria-selected', 'true' );
    $( '#tab-' + name ).show();
}
$( '.wmp-tab' ).on( 'click', function() { switchTab( $( this ).data( 'tab' ) ); } );
$( document ).on( 'click', '[data-goto-tab]', function(e) {
    e.preventDefault();
    switchTab( $( this ).data( 'goto-tab' ) );
} );
$( '#wmp-use-current' ).on( 'click', function() {
    $( '#wmp-new-url' ).val( WMP.site_url );
} );

// ── Import method pills ────────────────────────────────────────────────────
$( '.wmp-pill' ).on( 'click', function() {
    $( '.wmp-pill' ).removeClass( 'active' );
    $( this ).addClass( 'active' );
    $( '.wmp-import-panel' ).hide();
    $( '#wmp-import-' + $( this ).data( 'import' ) ).show();
} );

// ── File picker / drag-drop ────────────────────────────────────────────────
var $dz    = $( '#wmp-dropzone' );
var $fi    = $( '#wmp-file-input' );
var $btnUp = $( '#wmp-btn-upload' );
var $chip  = $( '#wmp-file-chip' );
var chosenFile = null;

$dz.on( 'dragover dragenter', function(e) {
    e.preventDefault();
    $( this ).addClass( 'dragover' );
} ).on( 'dragleave', function() {
    $( this ).removeClass( 'dragover' );
} ).on( 'drop', function(e) {
    e.preventDefault();
    $( this ).removeClass( 'dragover' );
    var dt = e.originalEvent.dataTransfer;
    if ( dt && dt.files.length ) { pickFile( dt.files[0] ); }
} ).on( 'click', function() {
    $fi.trigger( 'click' );
} ).on( 'keydown', function(e) {
    if ( e.key === 'Enter' || e.key === ' ' ) { $fi.trigger( 'click' ); }
} );

$fi.on( 'change', function() {
    if ( this.files && this.files[0] ) { pickFile( this.files[0] ); }
} );

$( '#wmp-chip-clear' ).on( 'click', function(e) {
    e.stopPropagation();
    clearFile();
} );

function pickFile( file ) {
    if ( ! file ) { return; }
    if ( ! file.name.toLowerCase().endsWith( '.zip' ) ) {
        alert( 'Please select a .zip file.' );
        return;
    }
    chosenFile = file;
    $( '#wmp-chip-name' ).text( file.name );
    $( '#wmp-chip-size' ).text( '(' + fmtBytes( file.size ) + ')' );
    $chip.show();
    $dz.hide();
    $btnUp.prop( 'disabled', false );
}

function clearFile() {
    chosenFile = null;
    $chip.hide();
    $dz.show();
    $fi.val( '' );
    $btnUp.prop( 'disabled', true );
}

function fmtBytes( b ) {
    if ( b < 1024 )       { return b + ' B'; }
    if ( b < 1048576 )    { return ( b / 1024 ).toFixed(1) + ' KB'; }
    if ( b < 1073741824 ) { return ( b / 1048576 ).toFixed(1) + ' MB'; }
    return ( b / 1073741824 ).toFixed(2) + ' GB';
}

// ── Restore select enable/disable button ───────────────────────────────────
$( '#wmp-restore-select' ).on( 'change', function() {
    $( '#wmp-btn-restore' ).prop( 'disabled', ! $( this ).val() );
} );

// ══════════════════════════════════════════════════════════════════════════════
//  PROGRESS POLLER
// ══════════════════════════════════════════════════════════════════════════════
function startProgress( jobId, $wrap, $result, onDone, onError, colorClass ) {
    colorClass = colorClass || '';

    $wrap.html(
        '<div class="wmp-bar-track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">' +
            '<div class="wmp-bar-fill ' + colorClass + '" id="wmpb-' + jobId + '"></div>' +
        '</div>' +
        '<div class="wmp-progress-meta">' +
            '<span class="wmp-progress-pct ' + colorClass + '" id="wmpp-' + jobId + '">0%</span>' +
            '<span class="wmp-progress-label" id="wmpl-' + jobId + '">Starting\u2026</span>' +
        '</div>' +
        '<div class="wmp-progress-detail" id="wmpd-' + jobId + '"></div>' +
        '<div class="wmp-log-wrap"><ul class="wmp-log-list" id="wmplg-' + jobId + '"></ul></div>'
    ).show();

    var $bar   = $( '#wmpb-'  + jobId );
    var $pct   = $( '#wmpp-'  + jobId );
    var $lbl   = $( '#wmpl-'  + jobId );
    var $dtl   = $( '#wmpd-'  + jobId );
    var $log   = $( '#wmplg-' + jobId );
    var $track = $bar.parent();

    var logFrom  = 0;
    var acked    = false;
    var timer    = null;
    var dispPct  = 0;

    function animPct( target ) {
        if ( target <= dispPct ) {
            $pct.text( target + '%' );
            dispPct = target;
            return;
        }
        ( function step() {
            if ( dispPct < target ) {
                dispPct = Math.min( target, dispPct + 1 );
                $pct.text( dispPct + '%' );
                if ( dispPct < target ) { requestAnimationFrame( step ); }
            }
        } )();
    }

    function ack() {
        acked = true;
        $.post( WMP.ajax_url, {
            action: 'wmp_poll_progress', nonce: WMP.nonce,
            job_id: jobId, ack: '1', log_from: logFrom
        } );
    }

    function poll() {
        $.post( WMP.ajax_url, {
            action: 'wmp_poll_progress', nonce: WMP.nonce,
            job_id: jobId, log_from: logFrom, ack: acked ? '1' : ''
        } )
        .done( function( res ) {
            if ( ! res || ! res.success ) {
                timer = setTimeout( poll, 3000 );
                return;
            }
            var s   = res.data;
            var pct = Math.max( 0, Math.min( 100, parseInt( s.pct, 10 ) || 0 ) );

            $bar.css( 'width', pct + '%' );
            $track.attr( 'aria-valuenow', pct );
            animPct( pct );
            if ( s.label  ) { $lbl.text( s.label ); }
            if ( s.detail ) { $dtl.text( s.detail ); }

            if ( s.log && s.log.length ) {
                $.each( s.log, function( i, line ) {
                    $log.append( $( '<li>' ).text( line ) );
                } );
                if ( typeof s.log_from !== 'undefined' ) { logFrom = s.log_from; }
                var pane = $log[0] && $log[0].parentElement;
                if ( pane ) { pane.scrollTop = pane.scrollHeight; }
            }

            if ( s.status === 'done' ) {
                clearTimeout( timer );
                $bar.addClass( 'is-done' ).css( 'width', '100%' );
                $pct.removeClass( colorClass ).addClass( 'is-done' );
                animPct( 100 );
                $lbl.text( 'Complete \u2714' );
                $dtl.text( '' );
                ack();
                onDone( s );
                return;
            }
            if ( s.status === 'error' ) {
                clearTimeout( timer );
                $bar.addClass( 'is-err' );
                $pct.removeClass( colorClass ).addClass( 'is-err' );
                $lbl.text( 'Error' );
                ack();
                onError( s );
                return;
            }

            timer = setTimeout( poll, 1200 );
        } )
        .fail( function() {
            timer = setTimeout( poll, 3000 );
        } );
    }

    // Start polling after a brief delay — gives the background job time to
    // write its first progress tick before we start reading.
    timer = setTimeout( poll, 2000 );
}

// ══════════════════════════════════════════════════════════════════════════════
//  BACKUP
// ══════════════════════════════════════════════════════════════════════════════
$( '#wmp-btn-backup' ).on( 'click', function() {
    if ( ! confirm( 'Start a full backup now?\n\nThis may take several minutes on large sites.' ) ) { return; }

    var $btn = $( this );
    var $pw  = $( '#wmp-backup-progress' );
    var $res = $( '#wmp-backup-result' );

    $btn.prop( 'disabled', true );
    $pw.empty();
    $res.hide().removeClass( 'success error' ).html( '' );

    $.post( WMP.ajax_url, { action: 'wmp_start_backup', nonce: WMP.nonce } )
    .done( function( res ) {
        if ( ! res || ! res.success ) {
            $btn.prop( 'disabled', false );
            showResult( $res, false, ( res && res.data ) ? res.data : 'Failed to start backup.' );
            return;
        }
        startProgress( res.data.job_id, $pw, $res,
            function( s ) { // done
                $btn.prop( 'disabled', false );
                var r = s.result || {};
                showResult( $res, true,
                    '<strong>\u2714 Backup created!</strong>  <code>' + esc( r.zip_name || '' ) + '</code>' +
                    '  <span class="wmp-muted">(' + esc( r.size || '' ) + ')</span>' +
                    '<br><span class="wmp-muted">Page reloading in 3s to show the new backup\u2026</span>',
                    true
                );
                setTimeout( function() { location.reload(); }, 3000 );
            },
            function( s ) { // error
                $btn.prop( 'disabled', false );
                showResult( $res, false, s.detail || 'Backup failed.' );
            }
        );
    } )
    .fail( function( xhr ) {
        $btn.prop( 'disabled', false );
        showResult( $res, false, 'AJAX error ' + xhr.status );
    } );
} );

// ══════════════════════════════════════════════════════════════════════════════
//  IMPORT (shared runner)
// ══════════════════════════════════════════════════════════════════════════════
function runImport( fd ) {
    if ( ! confirm( WMP.strings.confirm_import ) ) { return; }

    var $pw  = $( '#wmp-import-progress' );
    var $res = $( '#wmp-import-result' );

    $pw.empty();
    $res.hide().removeClass( 'success error' ).html( '' );
    lockImport( true );

    $.ajax( {
        url: WMP.ajax_url, type: 'POST',
        data: fd, processData: false, contentType: false,
        timeout: 120000
    } )
    .done( function( res ) {
        if ( ! res || ! res.success ) {
            lockImport( false );
            showResult( $res, false, ( res && res.data ) ? res.data : 'Failed to start import.' );
            return;
        }
        startProgress( res.data.job_id, $pw, $res,
            function( s ) {
                lockImport( false );
                var r    = s.result || {};
                var html = '<strong>\u2714 Import complete!</strong>';
                if ( r.source_url && r.new_url && r.source_url !== r.new_url ) {
                    html += '<br>URLs replaced: <code>' + esc( r.source_url ) + '</code> \u2192 <code>' + esc( r.new_url ) + '</code>';
                }
                showResult( $res, true, html, true );
            },
            function( s ) {
                lockImport( false );
                showResult( $res, false, s.detail || 'Import failed.' );
            }
        );
    } )
    .fail( function( xhr ) {
        lockImport( false );
        showResult( $res, false,
            xhr.status === 0
                ? 'Connection reset. For large files use the Remote URL method instead.'
                : 'AJAX error ' + xhr.status + ': ' + xhr.statusText
        );
    } );
}

function lockImport( lock ) {
    $( '#wmp-btn-upload,#wmp-btn-remote,#wmp-btn-local' ).prop( 'disabled', lock );
}

// Upload ZIP
$btnUp.on( 'click', function() {
    if ( ! chosenFile ) { return; }
    var fd = new FormData();
    fd.append( 'action',      'wmp_start_import' );
    fd.append( 'nonce',       WMP.nonce );
    fd.append( 'import_type', 'upload' );
    fd.append( 'new_url',     $( '#wmp-new-url' ).val().trim() );
    fd.append( 'backup_file', chosenFile, chosenFile.name );
    runImport( fd );
} );

// Remote URL
$( '#wmp-btn-remote' ).on( 'click', function() {
    var url = $( '#wmp-remote-url' ).val().trim();
    if ( ! url ) { alert( 'Please enter a URL.' ); return; }
    var fd = new FormData();
    fd.append( 'action',      'wmp_start_import' );
    fd.append( 'nonce',       WMP.nonce );
    fd.append( 'import_type', 'remote' );
    fd.append( 'new_url',     $( '#wmp-new-url' ).val().trim() );
    fd.append( 'remote_url',  url );
    runImport( fd );
} );

// Server backup → import
$( '#wmp-btn-local' ).on( 'click', function() {
    var name = $( '#wmp-local-select' ).val();
    if ( ! name ) { alert( 'Please select a backup.' ); return; }
    var fd = new FormData();
    fd.append( 'action',      'wmp_start_import' );
    fd.append( 'nonce',       WMP.nonce );
    fd.append( 'import_type', 'local' );
    fd.append( 'new_url',     $( '#wmp-new-url' ).val().trim() );
    fd.append( 'zip_name',    name );
    runImport( fd );
} );

// ══════════════════════════════════════════════════════════════════════════════
//  RESTORE
// ══════════════════════════════════════════════════════════════════════════════
$( '#wmp-btn-restore' ).on( 'click', function() {
    var name = $( '#wmp-restore-select' ).val();
    if ( ! name ) { alert( 'Please select a backup to restore.' ); return; }
    if ( ! confirm( WMP.strings.confirm_restore ) ) { return; }

    var $btn = $( this );
    var $pw  = $( '#wmp-restore-progress' );
    var $res = $( '#wmp-restore-result' );

    $btn.prop( 'disabled', true );
    $pw.empty();
    $res.hide().removeClass( 'success error' ).html( '' );

    $.post( WMP.ajax_url, {
        action:   'wmp_start_restore',
        nonce:    WMP.nonce,
        zip_name: name
    } )
    .done( function( res ) {
        if ( ! res || ! res.success ) {
            $btn.prop( 'disabled', false );
            showResult( $res, false, ( res && res.data ) ? res.data : 'Failed to start restore.' );
            return;
        }

        // Orange progress bar for restore
        startProgress( res.data.job_id, $pw, $res,
            function( s ) { // done
                $btn.prop( 'disabled', false );
                showResult( $res, true,
                    '<strong>\u2714 Restore complete!</strong> The site has been restored to <code>' + esc( name ) + '</code>.' +
                    '<br><span class="wmp-muted">Reloading in 3s\u2026</span>',
                    true
                );
                setTimeout( function() { location.reload(); }, 3000 );
            },
            function( s ) { // error
                $btn.prop( 'disabled', false );
                showResult( $res, false, s.detail || 'Restore failed.' );
            },
            'is-restore'  // orange colour class
        );
    } )
    .fail( function( xhr ) {
        $btn.prop( 'disabled', false );
        showResult( $res, false, 'AJAX error ' + xhr.status );
    } );
} );

// ══════════════════════════════════════════════════════════════════════════════
//  DELETE BACKUP
// ══════════════════════════════════════════════════════════════════════════════
$( document ).on( 'click', '.wmp-btn-delete', function() {
    var $row = $( this ).closest( 'tr' );
    var name = $row.data( 'zip' );
    if ( ! confirm( WMP.strings.confirm_delete + '\n\n' + name ) ) { return; }
    $( this ).prop( 'disabled', true );
    $.post( WMP.ajax_url, { action: 'wmp_delete_backup', nonce: WMP.nonce, zip_name: name } )
    .done( function( res ) {
        if ( res && res.success ) {
            $row.fadeOut( 250, function() { $( this ).remove(); } );
            // Also remove from restore/import dropdowns
            $( '#wmp-restore-select option[value="' + name + '"]' ).remove();
            $( '#wmp-local-select option[value="' + name + '"]' ).remove();
        } else {
            alert( 'Delete failed: ' + ( res && res.data ? res.data : '?' ) );
        }
    } )
    .fail( function() { alert( 'Network error. Please try again.' ); } );
} );

// ══════════════════════════════════════════════════════════════════════════════
//  HELPERS
// ══════════════════════════════════════════════════════════════════════════════
function showResult( $el, ok, html, raw ) {
    $el.removeClass( 'success error' )
       .addClass( ok ? 'success' : 'error' )
       .html( raw ? html : ( ok ? html : '<strong>\u2716 Error:</strong> ' + esc( html ) ) )
       .show();
}

function esc( s ) {
    return String( s )
        .replace( /&/g, '&amp;' ).replace( /</g, '&lt;' )
        .replace( />/g, '&gt;' ).replace( /"/g, '&quot;' )
        .replace( /'/g, '&#039;' );
}

} )( jQuery );
