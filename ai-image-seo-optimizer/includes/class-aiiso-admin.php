<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class AIISO_Admin {
    public static function init(): void {
        add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
        add_action( 'admin_post_aiiso_save_settings', array( __CLASS__, 'save_settings' ) );
        add_action( 'admin_post_aiiso_process_one', array( __CLASS__, 'process_one' ) );
        add_action( 'admin_post_aiiso_bulk', array( __CLASS__, 'bulk' ) );
        add_action( 'admin_post_aiiso_restore', array( __CLASS__, 'restore' ) );
        add_action( 'wp_ajax_aiiso_test_provider', array( __CLASS__, 'ajax_test_provider' ) );
        add_filter( 'manage_media_columns', array( __CLASS__, 'media_columns' ) );
        add_action( 'manage_media_custom_column', array( __CLASS__, 'media_column' ), 10, 2 );
        add_filter( 'bulk_actions-upload', array( __CLASS__, 'media_bulk_actions' ) );
        add_filter( 'handle_bulk_actions-upload', array( __CLASS__, 'handle_media_bulk' ), 10, 3 );
        add_filter( 'attachment_fields_to_edit', array( __CLASS__, 'attachment_fields' ), 10, 2 );
        add_filter( 'attachment_fields_to_save', array( __CLASS__, 'save_attachment_fields' ), 10, 2 );
    }

    public static function menu(): void {
        add_menu_page( 'AI Image SEO', 'AI Image SEO', 'upload_files', 'aiiso', array( __CLASS__, 'dashboard' ), AIISO_URL . 'assets/images/logo-menu.svg', 58 );
        add_submenu_page( 'aiiso', 'Dashboard', 'Dashboard', 'upload_files', 'aiiso', array( __CLASS__, 'dashboard' ) );
        add_submenu_page( 'aiiso', 'Image Library SEO', 'Library SEO', 'upload_files', 'aiiso-library', array( __CLASS__, 'library_page' ) );
        add_submenu_page( 'aiiso', 'Settings', 'Settings', 'manage_options', 'aiiso-settings', array( __CLASS__, 'settings_page' ) );
        add_submenu_page( 'aiiso', 'Logs', 'Logs', 'manage_options', 'aiiso-logs', array( __CLASS__, 'logs_page' ) );
    }

    private static function is_plugin_page(): bool {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen detection; no state is changed.
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
        return in_array( $page, array( 'aiiso', 'aiiso-library', 'aiiso-settings', 'aiiso-logs' ), true );
    }

    public static function assets( string $hook ): void {
        if ( ! self::is_plugin_page() && false === strpos( (string) $hook, 'aiiso' ) ) { return; }

        wp_enqueue_style( 'dashicons' );
        wp_enqueue_style( 'aiiso-admin', AIISO_URL . 'assets/admin.css', array(), AIISO_VERSION );
        wp_enqueue_script( 'aiiso-admin', AIISO_URL . 'assets/admin.js', array( 'jquery' ), AIISO_VERSION, true );
        wp_localize_script( 'aiiso-admin', 'AIISO', array(
            'ajax'    => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'aiiso_ajax' ),
            'version' => AIISO_VERSION,
        ) );
    }

    private static function stats(): array {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only aggregate count for the plugin dashboard.
        $total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE post_type=%s AND post_mime_type LIKE %s', $wpdb->posts, 'attachment', 'image/%' ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only aggregate count for the plugin dashboard.
        $missing = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i p LEFT JOIN %i m ON (p.ID=m.post_id AND m.meta_key=%s) WHERE p.post_type=%s AND p.post_mime_type LIKE %s AND (m.meta_value IS NULL OR TRIM(m.meta_value)='')", $wpdb->posts, $wpdb->postmeta, '_wp_attachment_image_alt', 'attachment', 'image/%' ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only aggregate count for the plugin dashboard.
        $processed = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(DISTINCT post_id) FROM %i WHERE meta_key=%s AND meta_value=%s', $wpdb->postmeta, '_aiiso_status', 'done' ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only aggregate count for the plugin dashboard.
        $errors = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(DISTINCT post_id) FROM %i WHERE meta_key=%s AND meta_value=%s', $wpdb->postmeta, '_aiiso_status', 'error' ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only aggregate count for the plugin dashboard.
        $queued = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(DISTINCT post_id) FROM %i WHERE meta_key=%s AND meta_value IN (%s,%s)', $wpdb->postmeta, '_aiiso_status', 'queued', 'processing' ) );
        return compact( 'total', 'missing', 'processed', 'errors', 'queued' );
    }

    private static function app_header( string $current, string $title, string $subtitle, string $action_html = '' ): void {
        $nav = array(
            'dashboard' => array( 'Dashboard', 'aiiso', 'dashicons-dashboard' ),
            'library'   => array( 'Library SEO', 'aiiso-library', 'dashicons-images-alt2' ),
            'settings'  => array( 'Settings', 'aiiso-settings', 'dashicons-admin-generic' ),
            'logs'      => array( 'Logs', 'aiiso-logs', 'dashicons-list-view' ),
        );
        ?>
        <div class="aiiso-app-head">
            <div class="aiiso-brand-row">
                <div class="aiiso-brand-mark" aria-hidden="true"><img src="<?php echo esc_url( AIISO_URL . 'assets/images/logo-admin.png' ); ?>" alt=""></div>
                <div class="aiiso-title-block">
                    <div class="aiiso-eyebrow">AI IMAGE SEO OPTIMIZER</div>
                    <h1><?php echo esc_html( $title ); ?></h1>
                    <p><?php echo esc_html( $subtitle ); ?></p>
                </div>
                <?php if ( $action_html ) : ?><div class="aiiso-head-actions"><?php echo wp_kses_post( $action_html ); ?></div><?php endif; ?>
            </div>
            <nav class="aiiso-app-nav" aria-label="AI Image SEO navigation">
                <?php foreach ( $nav as $key => $item ) : ?>
                    <a class="<?php echo $current === $key ? 'is-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . $item[1] ) ); ?>">
                        <span class="dashicons <?php echo esc_attr( $item[2] ); ?>" aria-hidden="true"></span>
                        <?php echo esc_html( $item[0] ); ?>
                    </a>
                <?php endforeach; ?>
            </nav>
        </div>
        <?php
    }

    private static function provider_configured( string $provider ): bool {
        return ! empty( AIISO_Settings::keys( $provider ) ) && ! empty( AIISO_Settings::models( $provider ) );
    }

    public static function dashboard(): void {
        self::notice();
        $st = self::stats();
        $s  = AIISO_Settings::get_all();
        $coverage = $st['total'] > 0 ? (int) round( ( ( $st['total'] - $st['missing'] ) / $st['total'] ) * 100 ) : 0;
        $provider_ready = self::provider_configured( 'openrouter' ) || self::provider_configured( 'nvidia' );
        $setup_items = array(
            array( $provider_ready, 'Connect an AI provider', 'Add an OpenRouter or NVIDIA API key and at least one vision model.', 'aiiso-settings#providers' ),
            array( ! empty( $s['auto_new_uploads'] ), 'Automatic new uploads', 'Optimize newly uploaded images without manual work.', 'aiiso-settings#general' ),
            array( ! empty( $s['sync_frontend_alt'] ), 'Front-end ALT sync', 'Keep saved Media Library ALT text reflected in rendered images.', 'aiiso-settings#general' ),
            array( ! empty( $s['store_backup'] ), 'Metadata recovery', 'Back up original fields before the first AI update.', 'aiiso-settings#metadata' ),
        );
        $done = 0; foreach ( $setup_items as $item ) { if ( $item[0] ) { $done++; } }
        $setup_pct = (int) round( ( $done / count( $setup_items ) ) * 100 );
        $recent = AIISO_Logger::recent( 6 );
        ?>
        <div class="wrap aiiso-wrap">
            <?php self::app_header( 'dashboard', 'Dashboard', 'See image SEO coverage, finish setup, and optimize your Media Library.' ); ?>

            <?php if ( ! $provider_ready ) : ?>
                <div class="aiiso-callout aiiso-callout-info">
                    <span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
                    <div><strong>Connect an AI provider to start optimizing.</strong><p>Add your own OpenRouter or NVIDIA key. Nothing is sent until you configure a provider.</p></div>
                    <a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=aiiso-settings#providers' ) ); ?>">Connect provider</a>
                </div>
            <?php endif; ?>

            <section class="aiiso-metrics" aria-label="Image SEO summary">
                <?php
                $cards = array(
                    array( 'total', 'Total images', 'dashicons-images-alt2', 'neutral' ),
                    array( 'missing', 'Missing ALT', 'dashicons-warning', $st['missing'] ? 'warning' : 'success' ),
                    array( 'processed', 'AI optimized', 'dashicons-yes-alt', 'success' ),
                    array( 'queued', 'In queue', 'dashicons-update', 'info' ),
                    array( 'errors', 'Needs attention', 'dashicons-dismiss', $st['errors'] ? 'danger' : 'neutral' ),
                );
                foreach ( $cards as $card ) : ?>
                    <article class="aiiso-metric aiiso-metric-<?php echo esc_attr( $card[3] ); ?>">
                        <div class="aiiso-metric-icon"><span class="dashicons <?php echo esc_attr( $card[2] ); ?>" aria-hidden="true"></span></div>
                        <div><span class="aiiso-metric-label"><?php echo esc_html( $card[1] ); ?></span><strong><?php echo esc_html( number_format_i18n( $st[ $card[0] ] ) ); ?></strong></div>
                    </article>
                <?php endforeach; ?>
            </section>

            <div class="aiiso-dashboard-grid">
                <section class="aiiso-card aiiso-card-primary">
                    <div class="aiiso-card-head">
                        <div><span class="aiiso-section-kicker">QUICK ACTION</span><h2>Optimize existing images</h2><p>Start with missing ALT text, then review results in Library SEO.</p></div>
                        <div class="aiiso-coverage" style="--aiiso-progress: <?php echo esc_attr( $coverage ); ?>%;"><span><?php echo esc_html( $coverage ); ?>%</span><small>ALT coverage</small></div>
                    </div>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="aiiso-bulk-form">
                        <input type="hidden" name="action" value="aiiso_bulk">
                        <?php wp_nonce_field( 'aiiso_bulk' ); ?>
                        <div class="aiiso-form-row aiiso-form-row-compact">
                            <div class="aiiso-control-group">
                                <label for="aiiso-bulk-mode">What should be optimized?</label>
                                <select id="aiiso-bulk-mode" name="mode">
                                    <option value="missing">Images missing ALT</option>
                                    <option value="all">All images</option>
                                    <option value="errors">Retry failed images</option>
                                </select>
                            </div>
                            <label class="aiiso-mini-check"><input type="checkbox" name="force" value="1"><span>Overwrite generated fields</span></label>
                            <button class="button button-primary button-hero aiiso-cta" type="submit"><span class="dashicons dashicons-controls-play" aria-hidden="true"></span> Start optimization</button>
                        </div>
                    </form>
                    <div class="aiiso-inline-note"><span class="dashicons dashicons-clock" aria-hidden="true"></span> Bulk jobs run in the background, so you can leave this page.</div>
                </section>

                <aside class="aiiso-card aiiso-setup-card">
                    <div class="aiiso-card-head aiiso-card-head-simple">
                        <div><span class="aiiso-section-kicker">SETUP</span><h2><?php echo 100 === $setup_pct ? 'Ready to optimize' : 'Finish setup'; ?></h2></div>
                        <span class="aiiso-score-pill"><?php echo esc_html( $done . '/' . count( $setup_items ) ); ?> complete</span>
                    </div>
                    <div class="aiiso-progress-track" aria-label="Setup completion"><span style="width:<?php echo esc_attr( $setup_pct ); ?>%"></span></div>
                    <div class="aiiso-checklist">
                        <?php foreach ( $setup_items as $item ) : ?>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $item[3] ) ); ?>" class="aiiso-check-item <?php echo $item[0] ? 'is-done' : ''; ?>">
                                <span class="aiiso-check-icon dashicons <?php echo $item[0] ? 'dashicons-yes-alt' : 'dashicons-marker'; ?>" aria-hidden="true"></span>
                                <span><strong><?php echo esc_html( $item[1] ); ?></strong><small><?php echo esc_html( $item[2] ); ?></small></span>
                                <span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </aside>
            </div>

            <div class="aiiso-two-col">
                <section class="aiiso-card">
                    <div class="aiiso-card-head aiiso-card-head-simple"><div><span class="aiiso-section-kicker">ACTIVITY</span><h2>Recent optimization activity</h2></div><a href="<?php echo esc_url( admin_url( 'admin.php?page=aiiso-logs' ) ); ?>">View all logs</a></div>
                    <?php if ( $recent ) : ?>
                        <div class="aiiso-activity-list">
                            <?php foreach ( $recent as $row ) :
                                $status = sanitize_key( $row['status'] );
                                $label = in_array( $status, array( 'success', 'done' ), true ) ? 'Success' : ( 'error' === $status ? 'Error' : ucfirst( $status ?: 'Info' ) );
                                ?>
                                <div class="aiiso-activity-row">
                                    <span class="aiiso-status aiiso-status-<?php echo esc_attr( $status ); ?>"><?php echo esc_html( $label ); ?></span>
                                    <div><strong><?php echo $row['attachment_id'] ? esc_html( 'Image #' . $row['attachment_id'] ) : 'System'; ?></strong><small><?php echo esc_html( $row['message'] ); ?></small></div>
                                    <time><?php echo esc_html( mysql2date( get_option( 'date_format' ), $row['created_at'] ) ); ?></time>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else : ?>
                        <div class="aiiso-empty-state"><span class="dashicons dashicons-chart-line" aria-hidden="true"></span><h3>No activity yet</h3><p>Your recent optimization runs will appear here.</p></div>
                    <?php endif; ?>
                </section>

                <section class="aiiso-card aiiso-safety-card">
                    <div class="aiiso-feature-icon"><span class="dashicons dashicons-shield-alt" aria-hidden="true"></span></div>
                    <div><span class="aiiso-section-kicker">SAFE BY DEFAULT</span><h2>Existing image URLs stay untouched</h2><p>Physical AI renaming is limited to new uploads before WordPress creates attachment URLs and thumbnails. Existing media only receives a filename suggestion, avoiding broken references and cache churn.</p><a href="<?php echo esc_url( admin_url( 'admin.php?page=aiiso-settings#general' ) ); ?>">Review upload settings <span aria-hidden="true">→</span></a></div>
                </section>
            </div>
        </div>
        <?php
    }

    public static function library_page(): void {
        self::notice();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only library filters; no state is changed.
        $request = wp_unslash( $_GET );
        $paged = max( 1, absint( $request['paged'] ?? 1 ) );
        $filter = sanitize_key( $request['filter'] ?? 'all' );
        $search = sanitize_text_field( $request['s'] ?? '' );
        $args = array( 'post_type'=>'attachment', 'post_status'=>'inherit', 'post_mime_type'=>'image', 'posts_per_page'=>24, 'paged'=>$paged, 'orderby'=>'ID', 'order'=>'DESC', 's'=>$search );
        if ( 'missing' === $filter ) {
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- User-selected Media Library filter, paginated to 24 items.
            $args['meta_query'] = array( 'relation'=>'OR', array( 'key'=>'_wp_attachment_image_alt','compare'=>'NOT EXISTS' ), array( 'key'=>'_wp_attachment_image_alt','value'=>'','compare'=>'=' ) );
        } elseif ( in_array( $filter, array( 'done','error','queued' ), true ) ) {
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- User-selected Media Library status filter, paginated to 24 items.
            $args['meta_key'] = '_aiiso_status'; $args['meta_value'] = $filter;
        }
        $q = new WP_Query( $args );
        $st = self::stats();
        ?>
        <div class="wrap aiiso-wrap">
            <?php self::app_header( 'library', 'Library SEO', 'Review, filter, regenerate, or restore image metadata from one place.' ); ?>
            <div class="aiiso-library-summary">
                <div><strong><?php echo esc_html( number_format_i18n( $st['total'] ) ); ?></strong><span>Total images</span></div>
                <div><strong><?php echo esc_html( number_format_i18n( $st['missing'] ) ); ?></strong><span>Missing ALT</span></div>
                <div><strong><?php echo esc_html( number_format_i18n( $st['processed'] ) ); ?></strong><span>AI optimized</span></div>
            </div>

            <section class="aiiso-card aiiso-toolbar-card">
                <form method="get" class="aiiso-library-toolbar">
                    <input type="hidden" name="page" value="aiiso-library">
                    <div class="aiiso-filter-tabs" role="group" aria-label="Library filters">
                        <?php foreach ( array( 'all'=>'All', 'missing'=>'Missing ALT', 'done'=>'Optimized', 'error'=>'Errors', 'queued'=>'Queued' ) as $value=>$label ) : ?>
                            <a class="<?php echo $filter === $value ? 'is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'page'=>'aiiso-library', 'filter'=>$value ), admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html( $label ); ?></a>
                        <?php endforeach; ?>
                    </div>
                    <div class="aiiso-search-wrap"><span class="dashicons dashicons-search" aria-hidden="true"></span><input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Search media title"><input type="hidden" name="filter" value="<?php echo esc_attr( $filter ); ?>"><button class="button">Search</button></div>
                </form>
            </section>

            <?php if ( $q->have_posts() ) : ?>
                <div class="aiiso-media-grid">
                    <?php foreach ( $q->posts as $p ) :
                        $id = $p->ID;
                        $alt = trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) );
                        $status = sanitize_key( (string) get_post_meta( $id, '_aiiso_status', true ) );
                        $model = (string) get_post_meta( $id, '_aiiso_model', true );
                        $suggest = (string) get_post_meta( $id, '_aiiso_filename_suggestion', true );
                        $url = wp_get_attachment_image_url( $id, 'medium' );
                        $process = wp_nonce_url( admin_url( 'admin-post.php?action=aiiso_process_one&attachment_id=' . $id . '&force=1' ), 'aiiso_process_' . $id );
                        $restore = wp_nonce_url( admin_url( 'admin-post.php?action=aiiso_restore&attachment_id=' . $id ), 'aiiso_restore_' . $id );
                        $status_label = $status ? ucfirst( $status ) : ( $alt ? 'Manual ALT' : 'Missing ALT' );
                        $status_class = $status ?: ( $alt ? 'manual' : 'missing' );
                        ?>
                        <article class="aiiso-media-card">
                            <div class="aiiso-media-thumb">
                                <?php if ( $url ) : ?><img src="<?php echo esc_url( $url ); ?>" alt=""><?php else : ?><span class="dashicons dashicons-format-image"></span><?php endif; ?>
                                <span class="aiiso-status aiiso-status-<?php echo esc_attr( $status_class ); ?>"><?php echo esc_html( $status_label ); ?></span>
                            </div>
                            <div class="aiiso-media-content">
                                <div class="aiiso-media-meta">#<?php echo esc_html( $id ); ?></div>
                                <h3 title="<?php echo esc_attr( $p->post_title ); ?>"><?php echo esc_html( $p->post_title ?: 'Untitled image' ); ?></h3>
                                <div class="aiiso-meta-block"><span>ALT text</span><p class="<?php echo $alt ? '' : 'is-missing'; ?>"><?php echo esc_html( $alt ?: 'No ALT text yet' ); ?></p></div>
                                <?php if ( $suggest ) : ?><div class="aiiso-meta-block aiiso-filename"><span>Filename suggestion</span><code><?php echo esc_html( $suggest ); ?></code></div><?php endif; ?>
                                <?php if ( $model ) : ?><div class="aiiso-model-line"><span class="dashicons dashicons-superhero-alt" aria-hidden="true"></span><?php echo esc_html( $model ); ?></div><?php endif; ?>
                                <div class="aiiso-card-actions">
                                    <a class="button button-primary" href="<?php echo esc_url( $process ); ?>">Generate / Regenerate</a>
                                    <?php if ( get_post_meta( $id, '_aiiso_backup_v1', true ) ) : ?><a class="button" href="<?php echo esc_url( $restore ); ?>">Restore</a><?php endif; ?>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
                <div class="aiiso-pagination"><?php echo wp_kses_post( paginate_links( array( 'base'=>add_query_arg( 'paged', '%#%' ), 'format'=>'', 'current'=>$paged, 'total'=>max( 1, $q->max_num_pages ), 'prev_text'=>'‹', 'next_text'=>'›' ) ) ); ?></div>
            <?php else : ?>
                <div class="aiiso-card aiiso-empty-state aiiso-empty-large"><span class="dashicons dashicons-images-alt2" aria-hidden="true"></span><h2>No images match this view</h2><p>Try another filter or search term.</p></div>
            <?php endif; ?>
        </div>
        <?php
        wp_reset_postdata();
    }

    public static function settings_page(): void {
        self::notice();
        $s = AIISO_Settings::get_all();
        $or_ready = self::provider_configured( 'openrouter' );
        $nv_ready = self::provider_configured( 'nvidia' );
        $provider_label = 'openrouter' === $s['provider'] ? 'OpenRouter' : ( 'nvidia' === $s['provider'] ? 'NVIDIA' : 'Both / failover' );
        ?>
        <div class="wrap aiiso-wrap aiiso-settings-page">
            <?php self::app_header( 'settings', 'Settings', 'Connect AI providers and define exactly how image metadata should be generated.' ); ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="aiiso-settings-form" id="aiiso-settings-form">
                <input type="hidden" name="action" value="aiiso_save_settings">
                <input type="hidden" name="preserve_manual_data" value="1">
                <?php wp_nonce_field( 'aiiso_save_settings' ); ?>

                <div class="aiiso-settings-layout">
                    <aside class="aiiso-settings-nav" aria-label="Settings sections">
                        <button type="button" class="is-active" data-aiiso-tab="general"><span class="dashicons dashicons-admin-tools"></span><span>General<small>Automation & workflow</small></span></button>
                        <button type="button" data-aiiso-tab="providers"><span class="dashicons dashicons-cloud"></span><span>AI Providers<small>Keys, models & failover</small></span></button>
                        <button type="button" data-aiiso-tab="metadata"><span class="dashicons dashicons-edit-page"></span><span>Metadata<small>Fields & overwrite rules</small></span></button>
                        <button type="button" data-aiiso-tab="context"><span class="dashicons dashicons-networking"></span><span>Context<small>Products, pages & SEO data</small></span></button>
                        <button type="button" data-aiiso-tab="advanced"><span class="dashicons dashicons-admin-settings"></span><span>Advanced<small>Limits, retries & cleanup</small></span></button>
                        <div class="aiiso-settings-summary">
                            <span>Active provider</span><strong><?php echo esc_html( $provider_label ); ?></strong>
                            <span>OpenRouter</span><em class="<?php echo $or_ready ? 'ok' : ''; ?>"><?php echo $or_ready ? 'Configured' : 'Needs setup'; ?></em>
                            <span>NVIDIA</span><em class="<?php echo $nv_ready ? 'ok' : ''; ?>"><?php echo $nv_ready ? 'Configured' : 'Needs setup'; ?></em>
                        </div>
                    </aside>

                    <main class="aiiso-settings-content">
                        <section class="aiiso-settings-panel is-active" data-aiiso-tab-panel="general" id="general">
                            <div class="aiiso-panel-title"><span class="aiiso-section-kicker">WORKFLOW</span><h2>General</h2><p>Choose what happens automatically when images enter your site.</p></div>
                            <div class="aiiso-card">
                                <div class="aiiso-settings-section-head"><div><h3>Automation</h3><p>Turn core workflow features on or off.</p></div></div>
                                <div class="aiiso-switch-list">
                                    <?php self::switch_field( 'auto_new_uploads', 'Automatically optimize new uploads', 'Queue new images for AI metadata generation as soon as WordPress adds them.', $s, 'dashicons-upload' ); ?>
                                    <?php self::switch_field( 'sync_frontend_alt', 'Keep front-end ALT text in sync', 'Reflect saved attachment ALT text in responsive images and core/image blocks without rewriting post content.', $s, 'dashicons-update-alt' ); ?>
                                    <?php self::switch_field( 'safe_rename_new_uploads', 'SEO-friendly filenames for new uploads', 'Runs AI before WordPress finalizes the upload. This is safe for URLs, but it can make uploads slower.', $s, 'dashicons-edit' ); ?>
                                    <?php self::switch_field( 'low_429_mode', 'Rate-limit friendly mode', 'Use more conservative pacing after provider rate limits.', $s, 'dashicons-clock' ); ?>
                                </div>
                            </div>
                            <div class="aiiso-card">
                                <div class="aiiso-settings-section-head"><div><h3>Writing preferences</h3><p>Set language and a practical ALT-text writing target.</p></div></div>
                                <div class="aiiso-form-grid">
                                    <?php self::text_field( 'language', 'Output language / locale', $s['language'], 'Use “site” for the WordPress locale, or a locale such as en_US, ur, de.', 'site' ); ?>
                                    <?php self::text_field( 'alt_target', 'ALT text target', $s['alt_target'], 'A writing target, not a hard SEO rule. Example: 80-140.', '80-140' ); ?>
                                </div>
                            </div>
                        </section>

                        <section class="aiiso-settings-panel" data-aiiso-tab-panel="providers" id="providers">
                            <div class="aiiso-panel-title"><span class="aiiso-section-kicker">BYOK CONNECTIONS</span><h2>AI Providers</h2><p>Use your own API keys. Keys are never displayed again after saving.</p></div>
                            <div class="aiiso-card">
                                <div class="aiiso-settings-section-head"><div><h3>Provider routing</h3><p>Choose the provider used for generation and how failover behaves.</p></div></div>
                                <?php self::provider_choice( $s['provider'] ); ?>
                                <div class="aiiso-provider-strategy <?php echo 'both' === $s['provider'] ? '' : 'is-hidden'; ?>" data-aiiso-both-settings>
                                    <?php self::select_field( 'provider_strategy', 'Failover strategy', $s['provider_strategy'], array( 'primary_failover'=>'OpenRouter first, NVIDIA fallback', 'round_robin'=>'Alternate the primary provider' ), 'Only used when “Both / failover” is selected.' ); ?>
                                </div>
                            </div>

                            <div class="aiiso-provider-grid">
                                <?php self::provider_card( 'openrouter', 'OpenRouter', 'Flexible access to a broad catalog of vision models.', $or_ready, $s['openrouter_models'] ); ?>
                                <?php self::provider_card( 'nvidia', 'NVIDIA', 'Direct access to NVIDIA-hosted vision models.', $nv_ready, $s['nvidia_models'] ); ?>
                            </div>
                            <div class="aiiso-callout aiiso-callout-muted"><span class="dashicons dashicons-lock" aria-hidden="true"></span><div><strong>Privacy & key storage</strong><p>Images are sent only to the provider you choose. API keys are encrypted with your WordPress authentication salt when OpenSSL is available.</p></div></div>
                        </section>

                        <section class="aiiso-settings-panel" data-aiiso-tab-panel="metadata" id="metadata">
                            <div class="aiiso-panel-title"><span class="aiiso-section-kicker">OUTPUT</span><h2>Metadata</h2><p>Control which fields AI writes and when existing content may be replaced.</p></div>
                            <div class="aiiso-card">
                                <div class="aiiso-settings-section-head"><div><h3>Generated fields</h3><p>ALT text and Media Library title are core outputs. Choose optional metadata below.</p></div></div>
                                <div class="aiiso-switch-list">
                                    <?php self::switch_field( 'generate_caption', 'Generate caption', 'Create a useful visible caption when the image benefits from one.', $s, 'dashicons-format-quote' ); ?>
                                    <?php self::switch_field( 'generate_description', 'Generate description', 'Write a concise Media Library description with relevant visual/context details.', $s, 'dashicons-text-page' ); ?>
                                    <?php self::switch_field( 'generate_keywords', 'Store internal keyword phrases', 'Keep relevant phrases as attachment metadata for internal use; they are not stuffed into ALT text.', $s, 'dashicons-tag' ); ?>
                                </div>
                            </div>
                            <div class="aiiso-card">
                                <div class="aiiso-settings-section-head"><div><h3>Overwrite policy</h3><p>Existing human-written metadata is preserved by default.</p></div><span class="aiiso-badge aiiso-badge-safe">Recommended: preserve</span></div>
                                <div class="aiiso-callout aiiso-callout-warning"><span class="dashicons dashicons-shield" aria-hidden="true"></span><div><strong>Enable overwrite only where you want AI to replace existing content.</strong><p>Bulk “Force overwrite” can still override generated fields for a specific run.</p></div></div>
                                <div class="aiiso-switch-list aiiso-switch-list-compact">
                                    <?php self::switch_field( 'overwrite_alt', 'Overwrite existing ALT text', '', $s, 'dashicons-format-image' ); ?>
                                    <?php self::switch_field( 'overwrite_title', 'Overwrite Media Library title', '', $s, 'dashicons-heading' ); ?>
                                    <?php self::switch_field( 'overwrite_caption', 'Overwrite existing caption', '', $s, 'dashicons-format-quote' ); ?>
                                    <?php self::switch_field( 'overwrite_description', 'Overwrite existing description', '', $s, 'dashicons-text-page' ); ?>
                                </div>
                                <div class="aiiso-divider"></div>
                                <?php self::switch_field( 'store_backup', 'Back up original fields before the first AI update', 'Allows one-click restoration from Library SEO.', $s, 'dashicons-backup' ); ?>
                            </div>
                        </section>

                        <section class="aiiso-settings-panel" data-aiiso-tab-panel="context" id="context">
                            <div class="aiiso-panel-title"><span class="aiiso-section-kicker">INTELLIGENCE</span><h2>Context</h2><p>Give the vision model useful site information without allowing it to invent unsupported details.</p></div>
                            <div class="aiiso-card">
                                <div class="aiiso-settings-section-head"><div><h3>Context sources</h3><p>Use relevant WordPress data to make metadata more specific.</p></div></div>
                                <div class="aiiso-switch-list">
                                    <?php self::switch_field( 'use_page_context', 'Parent post or page context', 'Use the parent page/post title and nearby context when available.', $s, 'dashicons-admin-page' ); ?>
                                    <?php self::switch_field( 'use_woo_context', 'WooCommerce product context', 'Use product name, SKU, categories and short description when available.', $s, 'dashicons-cart' ); ?>
                                    <?php self::switch_field( 'use_seo_keywords', 'SEO focus keywords', 'Read focus keywords from Yoast, Rank Math, SEOPress and AIOSEO when available.', $s, 'dashicons-search' ); ?>
                                </div>
                            </div>
                            <div class="aiiso-card">
                                <div class="aiiso-settings-section-head"><div><h3>Image eligibility & site instructions</h3><p>Skip tiny assets and add brand-specific guidance.</p></div></div>
                                <div class="aiiso-form-grid">
                                    <?php self::number_field( 'min_width', 'Minimum width', $s['min_width'], 0, 1000, 'px', 'Skip images narrower than this.' ); ?>
                                    <?php self::number_field( 'min_height', 'Minimum height', $s['min_height'], 0, 1000, 'px', 'Skip images shorter than this.' ); ?>
                                </div>
                                <?php self::textarea_field( 'custom_prompt', 'Additional site instructions', $s['custom_prompt'], 'Example: “For product images, mention visible packaging details, but never invent product claims.”', 5 ); ?>
                            </div>
                        </section>

                        <section class="aiiso-settings-panel" data-aiiso-tab-panel="advanced" id="advanced">
                            <div class="aiiso-panel-title"><span class="aiiso-section-kicker">TECHNICAL</span><h2>Advanced</h2><p>Tune request size, retries and plugin cleanup behavior.</p></div>
                            <div class="aiiso-card">
                                <div class="aiiso-settings-section-head"><div><h3>API limits</h3><p>Defaults are conservative and work well for most sites.</p></div></div>
                                <div class="aiiso-form-grid aiiso-form-grid-3">
                                    <?php self::number_field( 'max_retries', 'Retries per provider', $s['max_retries'], 1, 8, '', 'Rotate models/keys across retries.' ); ?>
                                    <?php self::number_field( 'request_timeout', 'Request timeout', $s['request_timeout'], 20, 180, 'sec', 'Maximum time for one provider request.' ); ?>
                                    <?php self::number_field( 'max_image_side', 'Analysis image size', $s['max_image_side'], 256, 2048, 'px', 'Largest side sent to the AI model.' ); ?>
                                </div>
                            </div>
                            <div class="aiiso-card aiiso-danger-zone">
                                <div class="aiiso-settings-section-head"><div><h3>Data cleanup</h3><p>Choose what happens if the plugin is removed.</p></div><span class="aiiso-badge aiiso-badge-danger">Advanced</span></div>
                                <?php self::switch_field( 'delete_data_uninstall', 'Delete plugin settings and logs on uninstall', 'Off by default. Generated WordPress attachment metadata remains part of your Media Library.', $s, 'dashicons-trash' ); ?>
                            </div>
                        </section>
                    </main>
                </div>

                <div class="aiiso-save-bar">
                    <div><span class="dashicons dashicons-saved" aria-hidden="true"></span><span><strong>Ready to save?</strong><small>Changes apply to future and newly queued image jobs.</small></span></div>
                    <button type="submit" class="button button-primary button-hero">Save settings</button>
                </div>
            </form>
        </div>
        <?php
    }

    public static function logs_page(): void {
        self::notice();
        $logs = AIISO_Logger::recent( 100 );
        ?>
        <div class="wrap aiiso-wrap">
            <?php self::app_header( 'logs', 'Logs', 'Inspect provider activity, failed requests, and recent optimization history.' ); ?>
            <section class="aiiso-card aiiso-log-card">
                <div class="aiiso-card-head aiiso-card-head-simple"><div><span class="aiiso-section-kicker">RECENT ACTIVITY</span><h2>Last 100 events</h2></div><span class="aiiso-badge"><?php echo esc_html( count( $logs ) ); ?> entries</span></div>
                <?php if ( $logs ) : ?>
                    <div class="aiiso-table-wrap">
                        <table class="aiiso-table">
                            <thead><tr><th>Time</th><th>Image</th><th>Status</th><th>Provider / Model</th><th>Message</th></tr></thead>
                            <tbody>
                            <?php foreach ( $logs as $r ) :
                                $edit = $r['attachment_id'] ? get_edit_post_link( (int) $r['attachment_id'] ) : '';
                                $status = sanitize_key( $r['status'] );
                                ?>
                                <tr>
                                    <td class="aiiso-nowrap"><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $r['created_at'] ) ); ?></td>
                                    <td><?php echo $edit ? '<a href="' . esc_url( $edit ) . '">#' . esc_html( $r['attachment_id'] ) . '</a>' : '—'; ?></td>
                                    <td><span class="aiiso-status aiiso-status-<?php echo esc_attr( $status ); ?>"><?php echo esc_html( ucfirst( $status ?: 'info' ) ); ?></span></td>
                                    <td><div class="aiiso-provider-model"><strong><?php echo esc_html( $r['provider'] ?: '—' ); ?></strong><small><?php echo esc_html( $r['model'] ?: '' ); ?></small></div></td>
                                    <td><?php echo esc_html( $r['message'] ); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else : ?>
                    <div class="aiiso-empty-state aiiso-empty-large"><span class="dashicons dashicons-list-view" aria-hidden="true"></span><h2>No logs yet</h2><p>Provider tests and image optimization activity will appear here.</p></div>
                <?php endif; ?>
            </section>
        </div>
        <?php
    }

    public static function save_settings(): void {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Forbidden', 'ai-image-seo-optimizer' ) ); }
        check_admin_referer( 'aiiso_save_settings' );

        $request = wp_unslash( $_POST );
        $saved   = AIISO_Settings::save( $request );
        if ( is_wp_error( $saved ) ) {
            self::set_flash( 'error', 'Settings were not saved: ' . $saved->get_error_message() );
            self::redirect( 'aiiso-settings', '', '#providers' );
        }

        $test_provider = sanitize_key( $request['aiiso_test_after_save'] ?? '' );
        if ( in_array( $test_provider, array( 'openrouter', 'nvidia' ), true ) ) {
            $result = AIISO_Provider::test( $test_provider );
            if ( is_wp_error( $result ) ) {
                AIISO_Logger::add( 0, 'error', strtoupper( $test_provider ) . ' connection test failed: ' . $result->get_error_message(), $test_provider, '' );
                self::set_flash( 'error', ucfirst( $test_provider ) . ' key/model were saved, but the live connection test failed: ' . $result->get_error_message() );
            } else {
                AIISO_Logger::add( 0, 'success', strtoupper( $test_provider ) . ' live connection test succeeded.', $test_provider, $result['model'] ?? '' );
                self::set_flash(
                    'success',
                    ucfirst( $test_provider ) . ' saved and connected successfully using ' . ( $result['model'] ?? 'the selected model' ) . ' (HTTP ' . (int) ( $result['http_code'] ?? 200 ) . ', ' . (int) ( $result['latency_ms'] ?? 0 ) . ' ms).'
                );
            }
            self::redirect( 'aiiso-settings', '', '#providers' );
        }

        self::set_flash( 'success', 'Settings saved successfully.' );
        self::redirect( 'aiiso-settings' );
    }

    public static function process_one(): void {
        $id = absint( wp_unslash( $_GET['attachment_id'] ?? 0 ) );
        if ( ! current_user_can( 'upload_files' ) ) { wp_die( esc_html__( 'Forbidden', 'ai-image-seo-optimizer' ) ); }
        check_admin_referer( 'aiiso_process_' . $id );
        AIISO_Queue::enqueue( $id, true );
        self::redirect( 'aiiso-library', 'Image queued for AI regeneration.' );
    }

    public static function restore(): void {
        $id = absint( wp_unslash( $_GET['attachment_id'] ?? 0 ) );
        if ( ! current_user_can( 'upload_files' ) ) { wp_die( esc_html__( 'Forbidden', 'ai-image-seo-optimizer' ) ); }
        check_admin_referer( 'aiiso_restore_' . $id );
        AIISO_Processor::restore( $id );
        self::redirect( 'aiiso-library', 'Previous metadata restored.' );
    }

    public static function bulk(): void {
        if ( ! current_user_can( 'upload_files' ) ) { wp_die( esc_html__( 'Forbidden', 'ai-image-seo-optimizer' ) ); }
        check_admin_referer( 'aiiso_bulk' );
        $mode = sanitize_key( wp_unslash( $_POST['mode'] ?? 'missing' ) );
        $force = ! empty( wp_unslash( $_POST['force'] ?? '' ) );
        $n = AIISO_Queue::bulk_enqueue( $mode, $force );
        self::redirect( 'aiiso', 'Queued ' . number_format_i18n( $n ) . ' image(s).' );
    }

    public static function ajax_test_provider(): void {
        check_ajax_referer( 'aiiso_ajax', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( __( 'Forbidden', 'ai-image-seo-optimizer' ) ); }
        $p = sanitize_key( wp_unslash( $_POST['provider'] ?? '' ) );
        $key = sanitize_text_field( wp_unslash( $_POST['key'] ?? '' ) );
        $model = sanitize_text_field( wp_unslash( $_POST['model'] ?? '' ) );
        $r = AIISO_Provider::test( $p, $key, $model );
        if ( is_wp_error( $r ) ) {
            AIISO_Logger::add( 0, 'error', strtoupper( $p ) . ' AJAX connection test failed: ' . $r->get_error_message(), $p, $model );
            wp_send_json_error( $r->get_error_message() );
        }
        AIISO_Logger::add( 0, 'success', strtoupper( $p ) . ' AJAX live connection test succeeded.', $p, $r['model'] ?? $model );
        wp_send_json_success( 'Live request succeeded using ' . ( $r['model'] ?? $model ) . ' (HTTP ' . (int) ( $r['http_code'] ?? 200 ) . ', ' . (int) ( $r['latency_ms'] ?? 0 ) . ' ms).' );
    }

    public static function media_columns( array $c ): array { $c['aiiso_status'] = 'AI Image SEO'; return $c; }
    public static function media_column( string $col, int $id ): void {
        if ( 'aiiso_status' !== $col ) { return; }
        $s = get_post_meta( $id, '_aiiso_status', true );
        $alt = get_post_meta( $id, '_wp_attachment_image_alt', true );
        echo esc_html( $s ?: ( $alt ? 'ALT exists' : 'Missing ALT' ) );
    }
    public static function media_bulk_actions( array $a ): array { $a['aiiso_generate'] = 'Generate AI Image SEO'; return $a; }
    public static function handle_media_bulk( string $redirect, string $action, array $ids ): string {
        if ( 'aiiso_generate' !== $action ) { return $redirect; }
        if ( ! current_user_can( 'upload_files' ) ) { return $redirect; }
        $n = 0; foreach ( $ids as $id ) { if ( AIISO_Queue::enqueue( (int) $id, false ) ) { $n++; } }
        return add_query_arg( 'aiiso_queued', $n, $redirect );
    }

    public static function attachment_fields( array $fields, WP_Post $post ): array {
        if ( ! str_starts_with( (string) $post->post_mime_type, 'image/' ) ) { return $fields; }
        $checked = get_post_meta( $post->ID, '_aiiso_decorative', true ) ? ' checked' : '';
        $fields['aiiso_decorative'] = array(
            'label' => 'Decorative image',
            'input' => 'html',
            'html'  => '<label><input type="checkbox" name="attachments[' . $post->ID . '][aiiso_decorative]" value="1"' . $checked . '> Keep ALT empty and skip AI generation</label>',
            'helps' => 'Use only when the image is purely decorative and conveys no information.',
        );
        return $fields;
    }

    public static function save_attachment_fields( array $post, array $attachment ): array {
        $attachment_id = absint( $post['ID'] ?? 0 );
        if ( ! $attachment_id || ! current_user_can( 'edit_post', $attachment_id ) ) { return $post; }
        if ( isset( $attachment['aiiso_decorative'] ) ) {
            update_post_meta( $attachment_id, '_aiiso_decorative', 1 );
            update_post_meta( $attachment_id, '_wp_attachment_image_alt', '' );
        } else {
            delete_post_meta( $attachment_id, '_aiiso_decorative' );
        }
        return $post;
    }

    private static function redirect( string $page, string $msg = '', string $anchor = '' ): void {
        if ( '' !== $msg ) { self::set_flash( 'success', $msg ); }
        $url = add_query_arg( array( 'page' => $page ), admin_url( 'admin.php' ) );
        if ( $anchor ) { $url .= $anchor; }
        wp_safe_redirect( $url );
        exit;
    }

    private static function set_flash( string $type, string $message ): void {
        $type = in_array( $type, array( 'success', 'error', 'warning', 'info' ), true ) ? $type : 'info';
        set_transient( 'aiiso_flash_' . get_current_user_id(), array( 'type' => $type, 'message' => sanitize_text_field( $message ) ), 2 * MINUTE_IN_SECONDS );
    }

    private static function notice(): void {
        $flash_key = 'aiiso_flash_' . get_current_user_id();
        $flash = get_transient( $flash_key );
        if ( is_array( $flash ) && ! empty( $flash['message'] ) ) {
            delete_transient( $flash_key );
            $type = in_array( $flash['type'] ?? '', array( 'success', 'error', 'warning', 'info' ), true ) ? $flash['type'] : 'info';
            echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $flash['message'] ) . '</p></div>';
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Informational count added by WordPress bulk-action redirect; no state is changed.
        $queued_count = isset( $_GET['aiiso_queued'] ) ? absint( wp_unslash( $_GET['aiiso_queued'] ) ) : null;
        if ( null !== $queued_count ) {
            echo '<div class="notice notice-success is-dismissible"><p>Queued ' . esc_html( $queued_count ) . ' image(s).</p></div>';
        }
    }

    private static function switch_field( string $name, string $label, string $help, array $s, string $icon = 'dashicons-yes-alt' ): void {
        ?>
        <label class="aiiso-switch-row">
            <span class="aiiso-switch-icon"><span class="dashicons <?php echo esc_attr( $icon ); ?>" aria-hidden="true"></span></span>
            <span class="aiiso-switch-copy"><strong><?php echo esc_html( $label ); ?></strong><?php if ( $help ) : ?><small><?php echo esc_html( $help ); ?></small><?php endif; ?></span>
            <span class="aiiso-switch-control">
                <input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="0">
                <input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( ! empty( $s[ $name ] ) ); ?>>
                <span class="aiiso-switch-ui" aria-hidden="true"></span>
            </span>
        </label>
        <?php
    }

    private static function provider_choice( string $value ): void {
        $opts = array(
            'openrouter' => array( 'OpenRouter', 'Use OpenRouter only', 'dashicons-cloud' ),
            'nvidia'     => array( 'NVIDIA', 'Use NVIDIA only', 'dashicons-superhero-alt' ),
            'both'       => array( 'Both / failover', 'Automatic provider fallback', 'dashicons-randomize' ),
        );
        echo '<div class="aiiso-choice-grid">';
        foreach ( $opts as $v => $o ) {
            echo '<label class="aiiso-choice-card"><input type="radio" name="provider" value="' . esc_attr( $v ) . '" ' . checked( $value, $v, false ) . '><span class="aiiso-choice-body"><span class="dashicons ' . esc_attr( $o[2] ) . '"></span><span><strong>' . esc_html( $o[0] ) . '</strong><small>' . esc_html( $o[1] ) . '</small></span><i class="dashicons dashicons-yes-alt"></i></span></label>';
        }
        echo '</div>';
    }

    private static function provider_card( string $provider, string $title, string $description, bool $ready, string $models ): void {
        $key_name   = $provider . '_keys';
        $model_name = $provider . '_models';
        $summary    = AIISO_Settings::key_summary( $provider );
        $health     = AIISO_Provider::health( $provider );
        ?>
        <article class="aiiso-provider-card" data-provider-card="<?php echo esc_attr( $provider ); ?>">
            <div class="aiiso-provider-head">
                <div class="aiiso-provider-logo <?php echo esc_attr( $provider ); ?>"><span class="dashicons <?php echo 'openrouter' === $provider ? 'dashicons-cloud' : 'dashicons-superhero-alt'; ?>"></span></div>
                <div><h3><?php echo esc_html( $title ); ?></h3><p><?php echo esc_html( $description ); ?></p></div>
                <span class="aiiso-config-status <?php echo $ready ? 'is-ready' : ''; ?>"><span></span><?php echo $ready ? 'Configured' : 'Needs setup'; ?></span>
            </div>
            <div class="aiiso-provider-body">
                <div class="aiiso-field">
                    <div class="aiiso-field-label"><label for="<?php echo esc_attr( 'aiiso-' . $key_name ); ?>">API key<?php echo 's'; ?></label><?php if ( $ready ) : ?><span class="aiiso-saved-key"><span class="dashicons dashicons-lock"></span> Saved <?php echo esc_html( $summary['masked'] ); ?><?php if ( $summary['count'] > 1 ) : ?> · <?php echo esc_html( $summary['count'] ); ?> keys<?php endif; ?></span><?php endif; ?></div>
                    <textarea id="<?php echo esc_attr( 'aiiso-' . $key_name ); ?>" class="aiiso-secret-input" name="<?php echo esc_attr( $key_name ); ?>" rows="2" autocomplete="new-password" placeholder="Paste API key here. One key per line."></textarea>
                    <div class="aiiso-field-footer"><small>Leave blank to keep saved key(s).</small><label class="aiiso-clear-key"><input type="checkbox" name="clear_<?php echo esc_attr( $key_name ); ?>" value="1"> Clear saved key(s)</label></div>
                </div>
                <div class="aiiso-field">
                    <label for="<?php echo esc_attr( 'aiiso-' . $model_name ); ?>">Vision models</label>
                    <textarea id="<?php echo esc_attr( 'aiiso-' . $model_name ); ?>" class="aiiso-model-input" name="<?php echo esc_attr( $model_name ); ?>" rows="4"><?php echo esc_textarea( $models ); ?></textarea>
                    <small>One model ID per line. Models rotate on retry.</small>
                </div>
                <div class="aiiso-provider-actions">
                    <button type="submit" name="aiiso_test_after_save" value="<?php echo esc_attr( $provider ); ?>" class="button button-primary aiiso-save-test"><span class="dashicons dashicons-admin-site-alt3" aria-hidden="true"></span> Save &amp; test connection</button>
                    <span class="aiiso-test-result" role="status" aria-live="polite">This saves the key and model list first, then sends a real vision request.</span>
                </div>
                <?php if ( ! empty( $health['tested_at'] ) ) : ?>
                    <div class="aiiso-provider-health <?php echo ! empty( $health['ok'] ) ? 'is-ok' : 'is-bad'; ?>">
                        <strong><?php echo ! empty( $health['ok'] ) ? 'Last test passed' : 'Last test failed'; ?></strong>
                        <span><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $health['tested_at'] ) ); ?> · <?php echo esc_html( $health['model'] ?? '' ); ?><?php if ( ! empty( $health['http_code'] ) ) : ?> · HTTP <?php echo esc_html( (int) $health['http_code'] ); ?><?php endif; ?><?php if ( ! empty( $health['latency_ms'] ) ) : ?> · <?php echo esc_html( (int) $health['latency_ms'] ); ?> ms<?php endif; ?></span>
                        <?php if ( empty( $health['ok'] ) && ! empty( $health['message'] ) ) : ?><small><?php echo esc_html( $health['message'] ); ?></small><?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </article>
        <?php
    }

    private static function select_field( string $name, string $label, string $value, array $opts, string $help = '' ): void {
        ?>
        <div class="aiiso-field">
            <label for="<?php echo esc_attr( 'aiiso-' . $name ); ?>"><?php echo esc_html( $label ); ?></label>
            <select id="<?php echo esc_attr( 'aiiso-' . $name ); ?>" name="<?php echo esc_attr( $name ); ?>">
                <?php foreach ( $opts as $v=>$t ) : ?><option value="<?php echo esc_attr( $v ); ?>" <?php selected( $value, $v ); ?>><?php echo esc_html( $t ); ?></option><?php endforeach; ?>
            </select>
            <?php if ( $help ) : ?><small><?php echo esc_html( $help ); ?></small><?php endif; ?>
        </div>
        <?php
    }

    private static function text_field( string $name, string $label, string $value, string $help = '', string $placeholder = '' ): void {
        ?>
        <div class="aiiso-field">
            <label for="<?php echo esc_attr( 'aiiso-' . $name ); ?>"><?php echo esc_html( $label ); ?></label>
            <input id="<?php echo esc_attr( 'aiiso-' . $name ); ?>" type="text" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" placeholder="<?php echo esc_attr( $placeholder ); ?>">
            <?php if ( $help ) : ?><small><?php echo esc_html( $help ); ?></small><?php endif; ?>
        </div>
        <?php
    }

    private static function number_field( string $name, string $label, $value, int $min, int $max, string $suffix = '', string $help = '' ): void {
        ?>
        <div class="aiiso-field">
            <label for="<?php echo esc_attr( 'aiiso-' . $name ); ?>"><?php echo esc_html( $label ); ?></label>
            <div class="aiiso-number-wrap"><input id="<?php echo esc_attr( 'aiiso-' . $name ); ?>" type="number" min="<?php echo esc_attr( $min ); ?>" max="<?php echo esc_attr( $max ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>"><?php if ( $suffix ) : ?><span><?php echo esc_html( $suffix ); ?></span><?php endif; ?></div>
            <?php if ( $help ) : ?><small><?php echo esc_html( $help ); ?></small><?php endif; ?>
        </div>
        <?php
    }

    private static function textarea_field( string $name, string $label, string $value, string $help = '', int $rows = 5 ): void {
        ?>
        <div class="aiiso-field aiiso-field-full">
            <label for="<?php echo esc_attr( 'aiiso-' . $name ); ?>"><?php echo esc_html( $label ); ?></label>
            <textarea id="<?php echo esc_attr( 'aiiso-' . $name ); ?>" name="<?php echo esc_attr( $name ); ?>" rows="<?php echo esc_attr( $rows ); ?>"><?php echo esc_textarea( $value ); ?></textarea>
            <?php if ( $help ) : ?><small><?php echo esc_html( $help ); ?></small><?php endif; ?>
        </div>
        <?php
    }
}
