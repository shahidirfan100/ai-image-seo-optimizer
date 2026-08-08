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
        add_menu_page( 'AI Image SEO', 'AI Image SEO', 'upload_files', 'aiiso', array( __CLASS__, 'dashboard' ), 'dashicons-format-image', 58 );
        add_submenu_page( 'aiiso', 'Dashboard', 'Dashboard', 'upload_files', 'aiiso', array( __CLASS__, 'dashboard' ) );
        add_submenu_page( 'aiiso', 'Image Library SEO', 'Library SEO', 'upload_files', 'aiiso-library', array( __CLASS__, 'library_page' ) );
        add_submenu_page( 'aiiso', 'Settings', 'Settings', 'manage_options', 'aiiso-settings', array( __CLASS__, 'settings_page' ) );
        add_submenu_page( 'aiiso', 'Logs', 'Logs', 'manage_options', 'aiiso-logs', array( __CLASS__, 'logs_page' ) );
    }

    public static function assets( string $hook ): void {
        if ( false === strpos( $hook, 'aiiso' ) ) { return; }
        wp_enqueue_style( 'aiiso-admin', AIISO_URL . 'assets/admin.css', array(), AIISO_VERSION );
        wp_enqueue_script( 'aiiso-admin', AIISO_URL . 'assets/admin.js', array( 'jquery' ), AIISO_VERSION, true );
        wp_localize_script( 'aiiso-admin', 'AIISO', array( 'ajax' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'aiiso_ajax' ) ) );
    }

    private static function stats(): array {
        global $wpdb;
        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='attachment' AND post_mime_type LIKE 'image/%'" );
        $missing = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON (p.ID=m.post_id AND m.meta_key='_wp_attachment_image_alt') WHERE p.post_type='attachment' AND p.post_mime_type LIKE 'image/%' AND (m.meta_value IS NULL OR TRIM(m.meta_value)='')" );
        $processed = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key='_aiiso_status' AND meta_value='done'" );
        $errors = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key='_aiiso_status' AND meta_value='error'" );
        $queued = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key='_aiiso_status' AND meta_value IN ('queued','processing')" );
        return compact( 'total','missing','processed','errors','queued' );
    }

    public static function dashboard(): void {
        self::notice(); $st = self::stats();
        echo '<div class="wrap aiiso-wrap"><h1>AI Image SEO Optimizer</h1><p class="description">AI-powered WordPress image metadata with BYOK OpenRouter/NVIDIA vision models.</p>';
        echo '<div class="aiiso-cards">';
        foreach ( array( 'total'=>'Images','missing'=>'Missing ALT','processed'=>'AI Optimized','queued'=>'Queued / Processing','errors'=>'Errors' ) as $k=>$label ) { echo '<div class="aiiso-card"><strong>' . esc_html( number_format_i18n( $st[$k] ) ) . '</strong><span>' . esc_html( $label ) . '</span></div>'; }
        echo '</div>';
        echo '<div class="aiiso-grid"><section class="aiiso-panel"><h2>Bulk optimize existing Media Library</h2><p>Jobs are queued in the background. Existing manual fields are preserved unless overwrite is enabled or Force overwrite is selected.</p>';
        echo '<form method="post" action="' . esc_url( admin_url('admin-post.php') ) . '"><input type="hidden" name="action" value="aiiso_bulk">'; wp_nonce_field( 'aiiso_bulk' );
        echo '<label>Scope <select name="mode"><option value="missing">Images missing ALT</option><option value="all">All images</option><option value="errors">Retry errors</option></select></label> ';
        echo '<label><input type="checkbox" name="force" value="1"> Force overwrite generated fields</label> ';
        submit_button( 'Queue optimization', 'primary', 'submit', false ); echo '</form></section>';
        echo '<section class="aiiso-panel"><h2>Recommended workflow</h2><ol><li>Add your own API key(s) under Settings.</li><li>Keep automatic new-upload SEO enabled.</li><li>Run “Missing ALT” first for old media.</li><li>Review Library SEO and regenerate only weak results.</li><li>Enable upload-time filename renaming only if you accept slower uploads.</li></ol></section></div>';
        echo '<div class="aiiso-panel aiiso-warning"><h2>Filename safety</h2><p>AI filename renaming is intentionally limited to the upload prefilter, before WordPress creates attachment URLs and thumbnails. Existing files receive a filename suggestion but are not physically renamed, preventing broken URLs, caches, page-builder references and image-index churn.</p></div>';
        echo '</div>';
    }

    public static function library_page(): void {
        self::notice();
        $paged = max( 1, absint( $_GET['paged'] ?? 1 ) ); $filter = sanitize_key( $_GET['filter'] ?? 'all' ); $search = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
        $args = array( 'post_type'=>'attachment','post_status'=>'inherit','post_mime_type'=>'image','posts_per_page'=>24,'paged'=>$paged,'orderby'=>'ID','order'=>'DESC','s'=>$search );
        if ( 'missing' === $filter ) { $args['meta_query'] = array( 'relation'=>'OR', array( 'key'=>'_wp_attachment_image_alt','compare'=>'NOT EXISTS' ), array( 'key'=>'_wp_attachment_image_alt','value'=>'','compare'=>'=' ) ); }
        elseif ( in_array( $filter, array('done','error','queued'), true ) ) { $args['meta_key'] = '_aiiso_status'; $args['meta_value'] = $filter; }
        $q = new WP_Query( $args );
        echo '<div class="wrap aiiso-wrap"><h1>Image Library SEO</h1><form method="get" class="aiiso-filters"><input type="hidden" name="page" value="aiiso-library"><select name="filter"><option value="all">All images</option><option value="missing"'.selected($filter,'missing',false).'>Missing ALT</option><option value="done"'.selected($filter,'done',false).'>AI optimized</option><option value="error"'.selected($filter,'error',false).'>Errors</option><option value="queued"'.selected($filter,'queued',false).'>Queued</option></select><input type="search" name="s" value="'.esc_attr($search).'" placeholder="Search media title"><button class="button">Filter</button></form>';
        echo '<div class="aiiso-media-grid">';
        foreach ( $q->posts as $p ) {
            $id = $p->ID; $alt = (string) get_post_meta($id,'_wp_attachment_image_alt',true); $status = (string) get_post_meta($id,'_aiiso_status',true); $model=(string)get_post_meta($id,'_aiiso_model',true); $suggest=(string)get_post_meta($id,'_aiiso_filename_suggestion',true);
            $url = wp_get_attachment_image_url( $id, 'medium' );
            $process = wp_nonce_url( admin_url('admin-post.php?action=aiiso_process_one&attachment_id='.$id.'&force=1'), 'aiiso_process_'.$id );
            $restore = wp_nonce_url( admin_url('admin-post.php?action=aiiso_restore&attachment_id='.$id), 'aiiso_restore_'.$id );
            echo '<article class="aiiso-media"><div class="aiiso-thumb"><img src="'.esc_url($url).'" alt=""></div><div class="aiiso-media-body"><div class="aiiso-id">#'.esc_html($id).' · '.esc_html($status ?: 'not processed').'</div><h3>'.esc_html($p->post_title).'</h3><p><b>ALT:</b> '.esc_html($alt ?: '— missing —').'</p><p><b>Filename suggestion:</b> '.esc_html($suggest ?: '—').'</p><p class="aiiso-model">'.esc_html($model).'</p><div><a class="button button-primary" href="'.esc_url($process).'">Generate / Regenerate</a> ';
            if ( get_post_meta($id,'_aiiso_backup_v1',true) ) echo '<a class="button" href="'.esc_url($restore).'">Restore previous</a>';
            echo '</div></div></article>';
        }
        echo '</div>';
        echo '<div class="tablenav"><div class="tablenav-pages">'.paginate_links(array('base'=>add_query_arg('paged','%#%'),'format'=>'','current'=>$paged,'total'=>max(1,$q->max_num_pages))).'</div></div></div>';
        wp_reset_postdata();
    }

    public static function settings_page(): void {
        self::notice(); $s=AIISO_Settings::get_all();
        echo '<div class="wrap aiiso-wrap"><h1>AI Image SEO Settings</h1><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="aiiso_save_settings">'; wp_nonce_field('aiiso_save_settings');
        echo '<div class="aiiso-grid"><section class="aiiso-panel"><h2>AI providers</h2>';
        self::select('provider','Provider',$s['provider'],array('openrouter'=>'OpenRouter','nvidia'=>'NVIDIA','both'=>'Both / failover'));
        self::select('provider_strategy','When Both is selected',$s['provider_strategy'],array('primary_failover'=>'OpenRouter first, NVIDIA fallback','round_robin'=>'Alternate primary provider'));
        self::password_area('openrouter_keys','OpenRouter API key(s)','One key per line. Leave blank to keep saved keys.');
        echo '<button type="button" class="button aiiso-test" data-provider="openrouter">Test OpenRouter</button><span class="aiiso-test-result"></span>';
        self::textarea('openrouter_models','OpenRouter vision models',$s['openrouter_models'],'One model ID per line; rotated on retry.');
        self::password_area('nvidia_keys','NVIDIA API key(s)','One key per line. Leave blank to keep saved keys.');
        echo '<button type="button" class="button aiiso-test" data-provider="nvidia">Test NVIDIA</button><span class="aiiso-test-result"></span>';
        self::textarea('nvidia_models','NVIDIA vision models',$s['nvidia_models'],'One model ID per line; rotated on retry.');
        echo '<p class="description">Images are sent to the provider you select. API keys are stored encrypted with your WordPress authentication salt when OpenSSL is available.</p></section>';
        echo '<section class="aiiso-panel"><h2>Automation</h2>';
        self::check('auto_new_uploads','Automatically optimize new image uploads',$s);
        self::check('safe_rename_new_uploads','AI-rename filenames before upload is finalized',$s,'Runs the AI synchronously during upload so the filename can be changed safely before WordPress creates thumbnails/URLs. This makes uploads slower.');
        self::check('sync_frontend_alt','Sync saved ALT text into rendered WordPress images',$s,'Covers responsive attachment images and core/image blocks without destructive post-content rewrites.');
        self::check('low_429_mode','Conservative pacing after rate limits',$s);
        self::number('max_retries','Retries per provider',$s['max_retries'],1,8);
        self::number('request_timeout','API timeout (seconds)',$s['request_timeout'],20,180);
        self::number('max_image_side','Max AI analysis dimension (px)',$s['max_image_side'],256,2048);
        echo '</section></div>';
        echo '<div class="aiiso-grid"><section class="aiiso-panel"><h2>Fields & overwrite policy</h2>';
        self::check('generate_caption','Generate Media Library caption',$s); self::check('generate_description','Generate Media Library description',$s); self::check('generate_keywords','Store AI keyword phrases as attachment meta',$s,'Keywords are internal metadata; they are not treated as an ALT-text stuffing field.');
        self::check('overwrite_alt','Overwrite existing ALT text',$s); self::check('overwrite_title','Overwrite existing Media Library title',$s); self::check('overwrite_caption','Overwrite existing caption',$s); self::check('overwrite_description','Overwrite existing description',$s);
        self::check('store_backup','Back up original fields before first AI update',$s);
        self::text('alt_target','ALT text target',$s['alt_target'],'Example: 80-140. This is a writing target, not a hard SEO rule.');
        self::text('language','Output language / locale',$s['language'],'Use “site” for the WordPress site locale, or e.g. en, en_US, ur, de.');
        echo '</section><section class="aiiso-panel"><h2>Context intelligence</h2>';
        self::check('use_page_context','Use parent post/page context',$s); self::check('use_woo_context','Use WooCommerce product name, SKU, categories and short description',$s); self::check('use_seo_keywords','Use focus keywords from Yoast, Rank Math, SEOPress and AIOSEO when available',$s);
        self::number('min_width','Skip images narrower than (px)',$s['min_width'],0,1000); self::number('min_height','Skip images shorter than (px)',$s['min_height'],0,1000);
        self::textarea('custom_prompt','Additional site instructions',$s['custom_prompt'],'Optional. Example: “For product images, mention product type and visible flavor/packaging but never invent claims.”');
        echo '</section></div>';
        submit_button('Save settings'); echo '</form></div>';
    }

    public static function logs_page(): void {
        self::notice(); $logs=AIISO_Logger::recent(100); echo '<div class="wrap aiiso-wrap"><h1>AI Image SEO Logs</h1><div class="aiiso-panel"><table class="widefat striped"><thead><tr><th>Time</th><th>Image</th><th>Status</th><th>Provider / Model</th><th>Message</th></tr></thead><tbody>';
        foreach($logs as $r){$edit=$r['attachment_id']?get_edit_post_link((int)$r['attachment_id']):'';echo '<tr><td>'.esc_html($r['created_at']).'</td><td>'.($edit?'<a href="'.esc_url($edit).'">#'.esc_html($r['attachment_id']).'</a>':'—').'</td><td>'.esc_html($r['status']).'</td><td>'.esc_html(trim($r['provider'].' / '.$r['model'],' /')).'</td><td>'.esc_html($r['message']).'</td></tr>';}
        if(!$logs) echo '<tr><td colspan="5">No log entries yet.</td></tr>'; echo '</tbody></table></div></div>';
    }

    public static function save_settings(): void { if(!current_user_can('manage_options'))wp_die('Forbidden'); check_admin_referer('aiiso_save_settings'); AIISO_Settings::save($_POST); self::redirect('aiiso-settings','Settings saved.'); }
    public static function process_one(): void { $id=absint($_GET['attachment_id']??0); if(!current_user_can('upload_files'))wp_die('Forbidden'); check_admin_referer('aiiso_process_'.$id); AIISO_Queue::enqueue($id,true); self::redirect('aiiso-library','Image queued for AI regeneration.'); }
    public static function restore(): void { $id=absint($_GET['attachment_id']??0); if(!current_user_can('upload_files'))wp_die('Forbidden'); check_admin_referer('aiiso_restore_'.$id); AIISO_Processor::restore($id); self::redirect('aiiso-library','Previous metadata restored.'); }
    public static function bulk(): void { if(!current_user_can('upload_files'))wp_die('Forbidden'); check_admin_referer('aiiso_bulk'); $mode=sanitize_key($_POST['mode']??'missing'); $force=!empty($_POST['force']); $n=AIISO_Queue::bulk_enqueue($mode,$force); self::redirect('aiiso','Queued '.number_format_i18n($n).' image(s).'); }

    public static function ajax_test_provider(): void { check_ajax_referer('aiiso_ajax','nonce'); if(!current_user_can('manage_options'))wp_send_json_error('Forbidden'); $p=sanitize_key($_POST['provider']??''); $r=AIISO_Provider::test($p); is_wp_error($r)?wp_send_json_error($r->get_error_message()):wp_send_json_success('Connection successful.'); }

    public static function media_columns(array $c): array { $c['aiiso_status']='AI Image SEO'; return $c; }
    public static function media_column(string $col,int $id): void { if('aiiso_status'!==$col)return; $s=get_post_meta($id,'_aiiso_status',true); $alt=get_post_meta($id,'_wp_attachment_image_alt',true); echo esc_html($s?:($alt?'ALT exists':'Missing ALT')); }
    public static function media_bulk_actions(array $a): array { $a['aiiso_generate']='Generate AI Image SEO'; return $a; }
    public static function handle_media_bulk(string $redirect,string $action,array $ids): string { if('aiiso_generate'!==$action)return $redirect; $n=0; foreach($ids as $id){if(AIISO_Queue::enqueue((int)$id,false))$n++;} return add_query_arg('aiiso_queued',$n,$redirect); }

    public static function attachment_fields(array $fields, WP_Post $post): array {
        if(!str_starts_with((string)$post->post_mime_type,'image/'))return $fields;
        $checked=get_post_meta($post->ID,'_aiiso_decorative',true)?' checked':'';
        $fields['aiiso_decorative']=array('label'=>'Decorative image','input'=>'html','html'=>'<label><input type="checkbox" name="attachments['.$post->ID.'][aiiso_decorative]" value="1"'.$checked.'> Keep ALT empty and skip AI generation</label>','helps'=>'Use only when the image is purely decorative and conveys no information.');
        return $fields;
    }
    public static function save_attachment_fields(array $post,array $attachment): array { if(isset($attachment['aiiso_decorative'])){update_post_meta($post['ID'],'_aiiso_decorative',1);update_post_meta($post['ID'],'_wp_attachment_image_alt','');}else{delete_post_meta($post['ID'],'_aiiso_decorative');} return $post; }

    private static function redirect(string $page,string $msg): void { wp_safe_redirect(add_query_arg(array('page'=>$page,'aiiso_msg'=>rawurlencode($msg)),admin_url('admin.php'))); exit; }
    private static function notice(): void { if(!empty($_GET['aiiso_msg']))echo '<div class="notice notice-success is-dismissible"><p>'.esc_html(wp_unslash($_GET['aiiso_msg'])).'</p></div>'; if(isset($_GET['aiiso_queued']))echo '<div class="notice notice-success is-dismissible"><p>Queued '.esc_html(absint($_GET['aiiso_queued'])).' image(s).</p></div>'; }
    private static function select($name,$label,$value,$opts){echo '<div class="aiiso-field"><label>'.esc_html($label).'</label><select name="'.esc_attr($name).'">';foreach($opts as $v=>$t)echo '<option value="'.esc_attr($v).'"'.selected($value,$v,false).'>'.esc_html($t).'</option>';echo '</select></div>';}
    private static function check($name,$label,$s,$help=''){echo '<label class="aiiso-check"><input type="checkbox" name="'.esc_attr($name).'" value="1"'.checked(!empty($s[$name]),true,false).'> <span>'.esc_html($label).'</span></label>';if($help)echo '<p class="description aiiso-help">'.esc_html($help).'</p>';}
    private static function textarea($name,$label,$value,$help=''){echo '<div class="aiiso-field"><label>'.esc_html($label).'</label><textarea name="'.esc_attr($name).'" rows="5">'.esc_textarea($value).'</textarea>';if($help)echo '<p class="description">'.esc_html($help).'</p>';echo '</div>';}
    private static function password_area($name,$label,$help=''){echo '<div class="aiiso-field"><label>'.esc_html($label).'</label><textarea name="'.esc_attr($name).'" rows="3" autocomplete="new-password" placeholder="•••••••• (saved keys are never displayed)"></textarea><label class="aiiso-inline"><input type="checkbox" name="clear_'.esc_attr($name).'" value="1"> Clear saved key(s)</label><p class="description">'.esc_html($help).'</p></div>';}
    private static function text($name,$label,$value,$help=''){echo '<div class="aiiso-field"><label>'.esc_html($label).'</label><input type="text" name="'.esc_attr($name).'" value="'.esc_attr($value).'">';if($help)echo '<p class="description">'.esc_html($help).'</p>';echo '</div>';}
    private static function number($name,$label,$value,$min,$max){echo '<div class="aiiso-field"><label>'.esc_html($label).'</label><input type="number" min="'.esc_attr($min).'" max="'.esc_attr($max).'" name="'.esc_attr($name).'" value="'.esc_attr($value).'"></div>';}
}
