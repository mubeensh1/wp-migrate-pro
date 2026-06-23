<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
// Vars from WMP_Admin::render(): $backups, $requirements, $all_ok
$zip_eng = WMP_Compat::has_zip_archive() ? 'ZipArchive' : ( WMP_Compat::has_pclzip() ? 'PclZip' : 'None' );
?>
<div class="wrap wmp-wrap">

    <div class="wmp-header">
        <h1 class="wmp-title">
            <span class="wmp-logo" aria-hidden="true">⟳</span>
            WP Migrate Pro
            <span class="wmp-ver">v<?php echo esc_html( WMP_VERSION ); ?></span>
        </h1>
        <p class="wmp-sub">Full-site backup, restore &amp; migration — database, files, and automatic URL replacement.</p>
    </div>

    <?php if ( ! $all_ok ) : ?>
    <div class="wmp-notice wmp-notice-warn">
        ⚠ Some system requirements need attention — see the <button class="wmp-link-btn" data-goto-tab="reqs">Requirements tab</button>.
    </div>
    <?php endif; ?>

    <!-- TABS -->
    <nav class="wmp-tabs" role="tablist">
        <button class="wmp-tab active" data-tab="backup"  role="tab">🗄 Backup</button>
        <button class="wmp-tab"        data-tab="import"  role="tab">📥 Import</button>
        <button class="wmp-tab"        data-tab="backups" role="tab">
            📁 My Backups<?php if ( ! empty( $backups ) ) : ?> <span class="wmp-count"><?php echo count( $backups ); ?></span><?php endif; ?>
        </button>
        <button class="wmp-tab"        data-tab="reqs"    role="tab">
            🔧 Requirements<?php if ( ! $all_ok ) : ?><span class="wmp-badge-warn">!</span><?php endif; ?>
        </button>
    </nav>

    <!-- ════ BACKUP ════ -->
    <div id="tab-backup" class="wmp-panel">
        <div class="wmp-card">
            <h2>Create Full Backup</h2>
            <p>Exports the complete database and compresses your entire wp-content directory into a single .zip file.</p>

            <div class="wmp-info-grid">
                <div><span class="wmp-info-lbl">Site URL</span><code><?php echo esc_html( get_site_url() ); ?></code></div>
                <div><span class="wmp-info-lbl">WordPress</span><code>v<?php echo esc_html( get_bloginfo( 'version' ) ); ?></code></div>
                <div><span class="wmp-info-lbl">Backup directory</span><code><?php echo esc_html( WMP_BACKUP_DIR ); ?></code></div>
                <div><span class="wmp-info-lbl">ZIP engine</span><code><?php echo esc_html( $zip_eng ); ?></code></div>
            </div>

            <button id="wmp-btn-backup" class="wmp-btn wmp-btn-primary wmp-btn-lg">
                <span class="dashicons dashicons-backup"></span> Create Backup Now
            </button>

            <div id="wmp-backup-progress" class="wmp-progress-wrap" style="display:none" aria-live="polite"></div>
            <div id="wmp-backup-result"   class="wmp-result"         style="display:none" aria-live="polite"></div>
        </div>
    </div>

    <!-- ════ IMPORT ════ -->
    <div id="tab-import" class="wmp-panel" style="display:none">
        <div class="wmp-card">
            <h2>Import Backup</h2>
            <div class="wmp-alert-danger">
                ⚠ <strong>Warning:</strong> Importing will overwrite this site's database and all wp-content files. Create a backup first.
            </div>

            <div class="wmp-field">
                <label for="wmp-new-url">Destination URL</label>
                <div class="wmp-row-gap">
                    <input type="url" id="wmp-new-url" class="wmp-input"
                           value="<?php echo esc_attr( get_site_url() ); ?>"
                           placeholder="https://newsite.com" />
                    <button type="button" class="wmp-btn wmp-btn-ghost wmp-btn-sm" id="wmp-use-current">
                        Use current URL
                    </button>
                </div>
                <small>All URLs from the source site in the database will be replaced with this value.</small>
            </div>

            <div class="wmp-pills" role="tablist">
                <button class="wmp-pill active" data-import="upload">⬆ Upload ZIP</button>
                <button class="wmp-pill"        data-import="remote">🔗 Remote URL</button>
                <button class="wmp-pill"        data-import="local" >🖥 Server Backup</button>
            </div>

            <!-- Upload -->
            <div id="wmp-import-upload" class="wmp-import-panel">
                <div class="wmp-dropzone" id="wmp-dropzone" tabindex="0" role="button" aria-label="Drop or browse for a .zip backup">
                    <span class="wmp-drop-icon">📦</span>
                    <p class="wmp-drop-main">Drop your backup .zip here</p>
                    <p class="wmp-drop-sub">or click to browse</p>
                    <input type="file" id="wmp-file-input" accept=".zip" tabindex="-1" aria-hidden="true" />
                </div>
                <div id="wmp-file-chip" style="display:none" class="wmp-chip">
                    <span class="wmp-chip-icon">✔</span>
                    <span id="wmp-chip-name"></span>
                    <span id="wmp-chip-size" class="wmp-muted"></span>
                    <button type="button" id="wmp-chip-clear" aria-label="Remove file">✕</button>
                </div>
                <button id="wmp-btn-upload" class="wmp-btn wmp-btn-danger wmp-btn-lg" disabled>
                    <span class="dashicons dashicons-upload"></span> Upload &amp; Import
                </button>
            </div>

            <!-- Remote URL -->
            <div id="wmp-import-remote" class="wmp-import-panel" style="display:none">
                <div class="wmp-field">
                    <label for="wmp-remote-url">Backup ZIP URL</label>
                    <input type="url" id="wmp-remote-url" class="wmp-input"
                           placeholder="https://sourcesite.com/wp-content/wmp-backups/backup_2024-01-01.zip" />
                    <small>The server will download the file directly from this URL.</small>
                </div>
                <button id="wmp-btn-remote" class="wmp-btn wmp-btn-danger wmp-btn-lg">
                    <span class="dashicons dashicons-download"></span> Download &amp; Import
                </button>
            </div>

            <!-- Local server backup -->
            <div id="wmp-import-local" class="wmp-import-panel" style="display:none">
                <?php if ( ! empty( $backups ) ) : ?>
                <div class="wmp-field">
                    <label for="wmp-local-select">Select a backup</label>
                    <select id="wmp-local-select" class="wmp-input">
                        <option value="">— Choose a backup —</option>
                        <?php foreach ( $backups as $b ) : ?>
                        <option value="<?php echo esc_attr( $b['name'] ); ?>">
                            <?php echo esc_html( $b['date'] . '  ·  ' . $b['size'] . '  ·  ' . $b['name'] ); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button id="wmp-btn-local" class="wmp-btn wmp-btn-danger wmp-btn-lg">
                    <span class="dashicons dashicons-migrate"></span> Import Selected
                </button>
                <?php else : ?>
                <div class="wmp-empty">
                    <span>📭</span>
                    <p>No backups on this server yet.</p>
                    <button class="wmp-btn wmp-btn-ghost wmp-btn-sm" data-goto-tab="backup">Create a backup first</button>
                </div>
                <?php endif; ?>
            </div>

            <div id="wmp-import-progress" class="wmp-progress-wrap" style="display:none" aria-live="polite"></div>
            <div id="wmp-import-result"   class="wmp-result"         style="display:none" aria-live="polite"></div>
        </div>
    </div>

    <!-- ════ MY BACKUPS ════ -->
    <div id="tab-backups" class="wmp-panel" style="display:none">
        <div class="wmp-card">
            <div class="wmp-card-hdr">
                <h2>Stored Backups</h2>
                <button class="wmp-btn wmp-btn-primary wmp-btn-sm" data-goto-tab="backup">
                    <span class="dashicons dashicons-backup"></span> New Backup
                </button>
            </div>

            <?php if ( empty( $backups ) ) : ?>
            <div class="wmp-empty"><span>🗄</span><p>No backups yet.</p></div>
            <?php else : ?>

            <!-- Restore -->
            <div class="wmp-restore-box">
                <div class="wmp-restore-hdr">
                    <span class="wmp-restore-icon">🔄</span>
                    <div>
                        <strong>Restore a Backup</strong>
                        <p>Revert this site's database and files to a selected backup point. The site URL is not changed.</p>
                    </div>
                </div>
                <div class="wmp-alert-danger" style="margin-bottom:14px">
                    ⚠ <strong>Warning:</strong> This will overwrite the current database and files with the selected backup.
                </div>
                <div class="wmp-row-gap" style="margin-bottom:14px">
                    <select id="wmp-restore-select" class="wmp-input">
                        <option value="">— Select a backup to restore —</option>
                        <?php foreach ( $backups as $b ) : ?>
                        <option value="<?php echo esc_attr( $b['name'] ); ?>">
                            <?php echo esc_html( $b['date'] . '  ·  ' . $b['size'] . '  ·  ' . $b['name'] ); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <button id="wmp-btn-restore" class="wmp-btn wmp-btn-warning wmp-btn-lg" disabled>
                        <span class="dashicons dashicons-image-rotate"></span> Restore Selected
                    </button>
                </div>
                <div id="wmp-restore-progress" class="wmp-progress-wrap" style="display:none" aria-live="polite"></div>
                <div id="wmp-restore-result"   class="wmp-result"         style="display:none" aria-live="polite"></div>
            </div>

            <!-- Backups table -->
            <div class="wmp-table-wrap">
                <table class="wmp-table widefat">
                    <thead>
                        <tr>
                            <th>File</th>
                            <th class="wmp-col-size">Size</th>
                            <th class="wmp-col-date">Created (UTC)</th>
                            <th class="wmp-col-act">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $backups as $b ) : ?>
                    <tr data-zip="<?php echo esc_attr( $b['name'] ); ?>">
                        <td>
                            <span class="wmp-file-badge">ZIP</span>
                            <code class="wmp-fname"><?php echo esc_html( $b['name'] ); ?></code>
                        </td>
                        <td class="wmp-col-size"><?php echo esc_html( $b['size'] ); ?></td>
                        <td class="wmp-col-date"><?php echo esc_html( $b['date'] ); ?></td>
                        <td class="wmp-col-act">
                            <div class="wmp-actions">
                                <a href="<?php echo esc_url( $b['dl_url'] ); ?>" class="wmp-btn wmp-btn-sm" download>
                                    <span class="dashicons dashicons-download"></span> Download
                                </a>
                                <button class="wmp-btn wmp-btn-sm wmp-btn-danger wmp-btn-delete"
                                        aria-label="Delete <?php echo esc_attr( $b['name'] ); ?>">
                                    <span class="dashicons dashicons-trash"></span> Delete
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="wmp-muted">
                <?php printf( '%d backup(s) in <code>%s</code>', count( $backups ), esc_html( WMP_BACKUP_DIR ) ); ?>
            </p>
            <?php endif; ?>
        </div>
    </div>

    <!-- ════ REQUIREMENTS ════ -->
    <div id="tab-reqs" class="wmp-panel" style="display:none">
        <div class="wmp-card">
            <h2>System Requirements</h2>
            <p>All items should show ✔. Items marked ✖ may affect functionality.</p>
            <div class="wmp-reqs">
                <?php foreach ( $requirements as $r ) : ?>
                <div class="wmp-req-row <?php echo $r['ok'] ? 'req-ok' : 'req-fail'; ?>">
                    <span class="wmp-req-icon"><?php echo $r['ok'] ? '✔' : '✖'; ?></span>
                    <span class="wmp-req-label"><?php echo esc_html( $r['label'] ); ?></span>
                    <?php if ( $r['note'] ) : ?>
                    <span class="wmp-req-note"><?php echo esc_html( $r['note'] ); ?></span>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

</div><!-- .wmp-wrap -->
