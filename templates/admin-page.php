<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
// Vars from WMP_Admin::render(): $backups, $requirements, $all_ok, $runner_method
$zip_eng = WMP_Compat::has_zip_archive() ? 'ZipArchive' : ( WMP_Compat::has_pclzip() ? 'PclZip (WP core)' : 'None' );
?>
<div class="wrap wmp-wrap">

    <div class="wmp-header">
        <h1 class="wmp-title">
            <span class="wmp-logo" aria-hidden="true">⟳</span>
            WP Migrate Pro
            <span class="wmp-ver">v<?php echo esc_html( WMP_VERSION ); ?></span>
        </h1>
        <p class="wmp-sub">Full-site backup, restore &amp; migration — database, files, automatic URL replacement. 524-timeout safe.</p>
    </div>

    <?php if ( WMP_Compat::is_wpengine() ) : ?>
    <div class="wmp-host-bar wmp-bar-wpe">⚙ <strong>WP Engine</strong> detected — PclZip fallback active, restricted paths skipped, WPE cache flushed after operations.</div>
    <?php elseif ( WMP_Compat::is_rocket_net() ) : ?>
    <div class="wmp-host-bar wmp-bar-rocket">⚙ <strong>Rocket.net</strong> detected — WP Rocket cache flushed after operations.</div>
    <?php endif; ?>

    <?php if ( ! $all_ok ) : ?>
    <div class="wmp-notice wmp-notice-warn">⚠ Some system requirements are not met — check the <button class="wmp-link-btn" data-goto-tab="reqs">Requirements tab</button>.</div>
    <?php endif; ?>

    <div class="wmp-notice wmp-notice-info">
        🔄 <strong>Background runner:</strong> <?php echo esc_html( WMP_Runner::method_label( $runner_method ) ); ?>
        <?php if ( $runner_method === WMP_Runner::METHOD_INLINE ) : ?>
        — <em>Loopback &amp; cron unavailable; using inline execution after response.</em>
        <?php elseif ( $runner_method === WMP_Runner::METHOD_CRON ) : ?>
        — <em>HTTP loopback unavailable; job starts via WP-Cron within ~5 seconds.</em>
        <?php endif; ?>
    </div>

    <!-- TABS -->
    <nav class="wmp-tabs" role="tablist">
        <button class="wmp-tab active" data-tab="backup"  role="tab">🗄 Backup</button>
        <button class="wmp-tab"        data-tab="import"  role="tab">📥 Import</button>
        <button class="wmp-tab"        data-tab="backups" role="tab">
            📁 My Backups <?php if ( ! empty( $backups ) ) : ?><span class="wmp-count"><?php echo count( $backups ); ?></span><?php endif; ?>
        </button>
        <button class="wmp-tab"        data-tab="reqs"    role="tab">
            🔧 Requirements<?php if ( ! $all_ok ) : ?><span class="wmp-badge-warn">!</span><?php endif; ?>
        </button>
    </nav>

    <!-- ════ BACKUP ════ -->
    <div id="tab-backup" class="wmp-panel">
        <div class="wmp-card">
            <h2>Create Full Backup</h2>
            <p>Exports all database tables and compresses your entire wp-content directory into a single portable .zip file. Backup files and directories are automatically excluded.</p>

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
                ⚠ <strong>Destructive:</strong> importing will overwrite this site's entire database and replace all wp-content files. Always create a backup first.
            </div>

            <div class="wmp-field">
                <label for="wmp-new-url">Destination Site URL</label>
                <div class="wmp-row-gap">
                    <input type="url" id="wmp-new-url" class="wmp-input" value="<?php echo esc_attr( get_site_url() ); ?>" placeholder="https://newsite.com" />
                    <button type="button" class="wmp-btn wmp-btn-ghost wmp-btn-sm" id="wmp-use-current">Use this site's URL</button>
                </div>
                <small>All source URLs in the database will be replaced with this value (serialisation-safe).</small>
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
                    <input type="url" id="wmp-remote-url" class="wmp-input" placeholder="https://sourcesite.com/.../backup_2024-01-01.zip" />
                    <small>The server downloads the file directly — bypasses <code>upload_max_filesize</code> entirely.</small>
                </div>
                <button id="wmp-btn-remote" class="wmp-btn wmp-btn-danger wmp-btn-lg">
                    <span class="dashicons dashicons-download"></span> Download &amp; Import
                </button>
            </div>

            <!-- Local server backup -->
            <div id="wmp-import-local" class="wmp-import-panel" style="display:none">
                <?php if ( ! empty( $backups ) ) : ?>
                <div class="wmp-field">
                    <label for="wmp-local-select">Select a backup on this server</label>
                    <select id="wmp-local-select" class="wmp-input">
                        <option value="">— Choose a backup —</option>
                        <?php foreach ( $backups as $b ) : ?>
                        <option value="<?php echo esc_attr( $b['name'] ); ?>">
                            <?php echo esc_html( $b['date'] . ' UTC  ·  ' . $b['size'] . '  ·  ' . $b['name'] ); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button id="wmp-btn-local" class="wmp-btn wmp-btn-danger wmp-btn-lg">
                    <span class="dashicons dashicons-migrate"></span> Import Selected
                </button>
                <?php else : ?>
                <div class="wmp-empty"><span>📭</span><p>No backups on this server yet.</p>
                <button class="wmp-btn wmp-btn-ghost wmp-btn-sm" data-goto-tab="backup">Create a backup first</button></div>
                <?php endif; ?>
            </div>

            <div id="wmp-import-progress" class="wmp-progress-wrap" style="display:none" aria-live="polite"></div>
            <div id="wmp-import-result"   class="wmp-result"         style="display:none" aria-live="polite"></div>
        </div>
    </div>

    <!-- ════ MY BACKUPS (with Restore) ════ -->
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

            <!-- Restore section -->
            <div class="wmp-restore-box">
                <div class="wmp-restore-hdr">
                    <span class="wmp-restore-icon">🔄</span>
                    <div>
                        <strong>Restore a Backup</strong>
                        <p>Restore this site's database and files to a previous backup. No URL replacement — the site URL stays the same.</p>
                    </div>
                </div>
                <div class="wmp-alert-danger" style="margin-bottom:14px">
                    ⚠ <strong>Destructive:</strong> restoring will overwrite the current database and files with those from the selected backup.
                </div>
                <div class="wmp-row-gap" style="margin-bottom:14px">
                    <select id="wmp-restore-select" class="wmp-input">
                        <option value="">— Select a backup to restore —</option>
                        <?php foreach ( $backups as $b ) : ?>
                        <option value="<?php echo esc_attr( $b['name'] ); ?>">
                            <?php echo esc_html( $b['date'] . ' UTC  ·  ' . $b['size'] . '  ·  ' . $b['name'] ); ?>
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
                <thead><tr>
                    <th>File</th>
                    <th class="wmp-col-size">Size</th>
                    <th class="wmp-col-date">Created (UTC)</th>
                    <th class="wmp-col-act">Actions</th>
                </tr></thead>
                <tbody>
                <?php foreach ( $backups as $b ) : ?>
                <tr data-zip="<?php echo esc_attr( $b['name'] ); ?>">
                    <td><span class="wmp-file-badge">ZIP</span><code class="wmp-fname"><?php echo esc_html( $b['name'] ); ?></code></td>
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
            <p class="wmp-muted"><?php printf( '%d backup(s) stored in <code>%s</code>', count( $backups ), esc_html( WMP_BACKUP_DIR ) ); ?></p>
            <?php endif; ?>
        </div>
    </div>

    <!-- ════ REQUIREMENTS ════ -->
    <div id="tab-reqs" class="wmp-panel" style="display:none">
        <div class="wmp-card">
            <h2>System Requirements</h2>
            <p>All rows should show ✔. Warnings may not block operation but should be resolved for best results.</p>
            <div class="wmp-reqs">
                <?php foreach ( $requirements as $r ) : ?>
                <div class="wmp-req-row <?php echo $r['ok'] ? 'req-ok' : 'req-fail'; ?>">
                    <span class="wmp-req-icon"><?php echo $r['ok'] ? '✔' : '✖'; ?></span>
                    <span class="wmp-req-label"><?php echo esc_html( $r['label'] ); ?></span>
                    <?php if ( $r['note'] ) : ?><span class="wmp-req-note"><?php echo esc_html( $r['note'] ); ?></span><?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="wmp-tips">
                <h3>Host tips</h3>
                <ul>
                    <li><strong>WP Engine / Rocket.net:</strong> define <code>WMP_BACKUP_PATH</code> in wp-config.php to store backups outside webroot.</li>
                    <li><strong>Stuck at 0%:</strong> check the runner banner above. If inline, disable caching plugins temporarily.</li>
                    <li><strong>Large sites:</strong> use Remote URL import to bypass <code>upload_max_filesize</code>.</li>
                    <li><strong>524 errors:</strong> solved by design — each HTTP call completes in under 2 seconds.</li>
                    <li><strong>No ZipArchive:</strong> falls back to PclZip (bundled with WordPress).</li>
                    <li><strong>Backup exclusions:</strong> any file/directory containing <code>backup</code>, <code>backups</code>, or <code>bkp</code> is automatically excluded from backups.</li>
                </ul>
            </div>
        </div>
    </div>

</div><!-- .wmp-wrap -->
