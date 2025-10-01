<?php
/** 
 * Plugin Name: اشتراک ویژه
 * Description: مدیریت پلن‌های ویژه با امکان مدت زمان (مثلاً سالانه)، اتصال به WooCommerce، محدودیت دسترسی به نوشته‌ها، برگه‌ها و محصولات بر اساس سطح پلن. شورتکات: [zc_plans], [zc_my_plans]
 * Version: 1.5
 * Author: Zentrixcode Team
 */   

if ( ! defined( 'ABSPATH' ) ) exit;

/* -----------------------
   تنظیمات و ثابت‌ها
   ----------------------- */
define('ZCSS_OPTION_PLANS', 'zcss_plans_array');
define('ZCSS_OPTION_SETTINGS', 'zcss_settings');
define('ZCSS_USER_META_PLANS', 'zcss_user_plans'); // ساختار: [ slug => ['started'=>'Y-m-d H:i:s','expires'=>null|'Y-m-d H:i:s'] ]
define('ZCSS_META_REQUIRED_LEVEL', 'zcss_required_level');

/* -----------------------
   Activation defaults
   ----------------------- */
register_activation_hook(__FILE__, function(){
    if ( get_option(ZCSS_OPTION_PLANS) === false ) {
        $default = array(
            array(
                'slug' => 'basic-free',
                'title' => 'پلن پایه (رایگان)',
                'description' => 'دسترسی پایه.',
                'price' => '0',
                'wc_product_id' => 0,
                'is_paid' => 0,
                'duration_days' => 0, // 0 => نامحدود
                'level' => 1, // سطح پایه
            )
        );
        update_option(ZCSS_OPTION_PLANS, $default);
    }
    if ( get_option(ZCSS_OPTION_SETTINGS) === false ) {
        $settings = array(
            'auto_assign_free_on_login' => 1,
            'free_plan_slug' => 'basic-free',
            'auto_create_wc_product' => 1,
            'upgrade_plan_page_id' => 0, // New: ID of the page with [zc_plans] shortcode
        );
        update_option(ZCSS_OPTION_SETTINGS, $settings);
    }

    // schedule daily expiration check
    if ( ! wp_next_scheduled('zcss_daily_expire_event') ) {
        wp_schedule_event( time(), 'daily', 'zcss_daily_expire_event' );
    }
});

/* -----------------------
   Deactivation cleanup
   ----------------------- */
register_deactivation_hook(__FILE__, function(){
    // clear scheduled event
    $ts = wp_next_scheduled('zcss_daily_expire_event');
    if ($ts) wp_unschedule_event($ts, 'zcss_daily_expire_event');
});

/* -----------------------
   Helpers: plans management
   ----------------------- */
function zcss_get_plans(){
    $p = get_option(ZCSS_OPTION_PLANS, array());
    return is_array($p) ? $p : array();
}
function zcss_save_plans($plans){
    update_option(ZCSS_OPTION_PLANS, $plans);
}
function zcss_get_plan_by_slug($slug){
    $plans = zcss_get_plans();
    foreach($plans as $pl) if(isset($pl['slug']) && $pl['slug']===$slug) return $pl;
    return null;
}
function zcss_get_plan_by_wc_product($product_id){
    $plans = zcss_get_plans();
    foreach($plans as $pl) if(!empty($pl['wc_product_id']) && intval($pl['wc_product_id'])===intval($product_id)) return $pl;
    return null;
}

/* -----------------------
   New Helper: Get upgrade plan URL (auto-detect page with [zc_plans] or use setting)
   ----------------------- */
function zcss_get_upgrade_plan_url() {
    $settings = get_option(ZCSS_OPTION_SETTINGS, array());
    $page_id = isset($settings['upgrade_plan_page_id']) ? intval($settings['upgrade_plan_page_id']) : 0;

    if ($page_id > 0) {
        $url = get_permalink($page_id);
        if ($url) return $url;
    }

    // Auto-detect: Find the first page that contains [zc_plans]
    $pages = get_posts(array(
        'post_type' => 'page',
        'posts_per_page' => 1,
        's' => '[zc_plans]',
        'post_status' => 'publish',
    ));

    if (!empty($pages)) {
        return get_permalink($pages[0]->ID);
    }

    // Fallback to a default URL if no page found
    return home_url('/plans'); // Or customize as needed
}

/* -----------------------
   Assign plan to user (central)
   stores into user meta ZCSS_USER_META_PLANS
   structure: [ slug => ['started'=>'Y-m-d H:i:s','expires'=>null|'Y-m-d H:i:s'] ]
   If assigning a paid plan, remove free plan if present.
   ----------------------- */
function zcss_assign_plan_to_user($user_id, $slug){
    $plan = zcss_get_plan_by_slug($slug);
    if (!$plan) return false;

    $now_ts = current_time('timestamp');
    $started = date('Y-m-d H:i:s', $now_ts);
    $duration_days = isset($plan['duration_days']) ? intval($plan['duration_days']) : 0;
    $expires = null;
    if ($duration_days > 0) {
        $expires = date('Y-m-d H:i:s', $now_ts + ($duration_days * 86400));
    }

    $user_plans = get_user_meta($user_id, ZCSS_USER_META_PLANS, true);
    if (!is_array($user_plans)) $user_plans = array();

    // assign or update
    $user_plans[$slug] = array('started' => $started, 'expires' => $expires);
    update_user_meta($user_id, ZCSS_USER_META_PLANS, $user_plans);

    // If this is a paid plan, remove any free plan assignment (so free won't coexist)
    if ( isset($plan['is_paid']) && intval($plan['is_paid']) === 1 ) {
        // Remove the configured free plan slug from user's plans if exists
        $settings = get_option(ZCSS_OPTION_SETTINGS, array());
        $free_slug = isset($settings['free_plan_slug']) ? $settings['free_plan_slug'] : '';
        if ( $free_slug ) {
            $user_plans_after = get_user_meta($user_id, ZCSS_USER_META_PLANS, true);
            if ( is_array($user_plans_after) && isset($user_plans_after[$free_slug]) ) {
                unset($user_plans_after[$free_slug]);
                update_user_meta($user_id, ZCSS_USER_META_PLANS, $user_plans_after);
            }
        }
    }

    return true;
}

/* -----------------------
   Remove/expire plan for user
   ----------------------- */
function zcss_unassign_plan_from_user($user_id, $slug){
    $user_plans = get_user_meta($user_id, ZCSS_USER_META_PLANS, true);
    if (!is_array($user_plans)) return false;
    if ( isset($user_plans[$slug]) ) {
        unset($user_plans[$slug]);
        update_user_meta($user_id, ZCSS_USER_META_PLANS, $user_plans);
        return true;
    }
    return false;
}

/* -----------------------
   Check if user's plan active (RCP or WC/internal)
   ----------------------- */
function zcss_user_has_active_subscription($user_id=null){
    if(!$user_id) $user_id = get_current_user_id();
    if(!$user_id) return false;

    // 1) RCP active => true
    if ( function_exists('rcp_user_has_active_subscription') && rcp_user_has_active_subscription($user_id) ) return true;

    // 2) internal assigned plans with expiry check
    $user_plans = get_user_meta($user_id, ZCSS_USER_META_PLANS, true);
    if ( is_array($user_plans) ) {
        foreach($user_plans as $slug => $info){
            if (!is_array($info)) continue;
            if ( empty($info['expires']) ) {
                // never expires => active
                return true;
            } else {
                $exp_ts = strtotime($info['expires']);
                if ($exp_ts === false) continue;
                if ($exp_ts >= current_time('timestamp')) return true;
            }
        }
    }

    return false;
}

/* -----------------------
   Helper: does user have any active paid plan?
   ----------------------- */
function zcss_user_has_active_paid_plan($user_id=null){
    if(!$user_id) $user_id = get_current_user_id();
    if(!$user_id) return false;

    // Check internal plans
    $user_plans = get_user_meta($user_id, ZCSS_USER_META_PLANS, true);
    if ( is_array($user_plans) ) {
        foreach($user_plans as $slug => $info){
            $plan = zcss_get_plan_by_slug($slug);
            if ( $plan && isset($plan['is_paid']) && intval($plan['is_paid']) === 1 ) {
                // check not expired
                if ( empty($info['expires']) || strtotime($info['expires']) >= current_time('timestamp') ) {
                    return true;
                }
            }
        }
    }

    // RCP may be considered "paid"
    if ( function_exists('rcp_user_has_active_subscription') && rcp_user_has_active_subscription($user_id) ) {
        return true;
    }

    return false;
}

/* -----------------------
   Helper: Get user's max active level
   ----------------------- */
function zcss_get_user_max_level($user_id = null){
    if(!$user_id) $user_id = get_current_user_id();
    if(!$user_id) return 0;

    $max_level = 0;
    $user_plans = get_user_meta($user_id, ZCSS_USER_META_PLANS, true);
    if ( is_array($user_plans) ) {
        foreach($user_plans as $slug => $info){
            $plan = zcss_get_plan_by_slug($slug);
            if ( $plan && isset($plan['level']) ) {
                // check not expired
                if ( empty($info['expires']) || strtotime($info['expires']) >= current_time('timestamp') ) {
                    $level = intval($plan['level']);
                    if ($level > $max_level) $max_level = $level;
                }
            }
        }
    }

    // If RCP active, assume a high level (e.g., 10)
    if ( function_exists('rcp_user_has_active_subscription') && rcp_user_has_active_subscription($user_id) ) {
        return 10; // or configure as needed
    }

    return $max_level;
}

/* -----------------------
   Restrict content based on required level
   ----------------------- */
add_filter('the_content', function($content){
    if (!is_singular(array('post', 'page', 'product'))) return $content;

    $required_level = get_post_meta(get_the_ID(), ZCSS_META_REQUIRED_LEVEL, true);
    if (!$required_level) return $content;

    $user_level = zcss_get_user_max_level();
    if ($user_level >= intval($required_level)) return $content;

    $upgrade_url = zcss_get_upgrade_plan_url();
    return '<div class="zcss-restricted-content"><p>برای دسترسی به این محتوا نیاز به پلن سطح ' . esc_html($required_level) . ' یا بالاتر دارید. لطفاً پلن خود را ارتقا دهید.</p><a href="' . esc_url($upgrade_url) . '" class="button">ارتقای پلن</a></div>';
}, 10, 1);

/* -----------------------
   Admin UI: Plans manager with tabs and modern styles
   ----------------------- */
add_action('admin_menu', function(){
    add_menu_page('اشتراک ویژه', 'اشتراک ویژه', 'manage_options', 'zcss_plans', 'zcss_admin_page', 'dashicons-groups', 58);
});

function zcss_admin_page(){
    if(!current_user_can('manage_options')) return;

    // Suppress notices/warnings in admin page
    error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);

    // Save actions (common for all tabs)
    if(isset($_POST['zcss_action']) && check_admin_referer('zcss_admin_save','zcss_admin_nonce')){
        $action = sanitize_text_field($_POST['zcss_action']);
        $plans = zcss_get_plans();
        if($action==='save_plan'){
            $slug = sanitize_text_field($_POST['slug']);
            $title = sanitize_text_field($_POST['title']);
            $desc = sanitize_textarea_field($_POST['description']);
            $price = sanitize_text_field($_POST['price']);
            $wc_product_id = intval($_POST['wc_product_id']);
            $is_paid = isset($_POST['is_paid']) ? 1 : 0;
            $duration_days = intval($_POST['duration_days']); // 0 => نامحدود
            $level = intval($_POST['level']); // سطح پلن

            // auto create wc product if needed
            $settings = get_option(ZCSS_OPTION_SETTINGS, array());
            $auto_create = isset($settings['auto_create_wc_product']) ? intval($settings['auto_create_wc_product']) : 1;
            if( $is_paid && $wc_product_id<=0 && class_exists('WC_Product') && $auto_create ){
                $product = new WC_Product_Simple();
                $product->set_name( $title );
                $product->set_status('publish');
                $product->set_catalog_visibility('hidden');
                $product->set_price( $price );
                $product->set_regular_price( $price );
                $product_id = $product->save();
                if($product_id) $wc_product_id = $product_id;
            }

            $found=false;
            foreach($plans as &$p){
                if($p['slug']===$slug){
                    $p['title']=$title; $p['description']=$desc; $p['price']=$price; $p['wc_product_id']=$wc_product_id; $p['is_paid']=$is_paid; $p['duration_days']=$duration_days; $p['level']=$level;
                    $found=true; break;
                }
            }
            if(!$found){
                $plans[] = array('slug'=>$slug,'title'=>$title,'description'=>$desc,'price'=>$price,'wc_product_id'=>$wc_product_id,'is_paid'=>$is_paid,'duration_days'=>$duration_days,'level'=>$level);
            }
            zcss_save_plans($plans);
            echo '<div class="notice notice-success is-dismissible"><p>پلن ذخیره شد.</p></div>';
        }
        if($action==='delete_plan' && isset($_POST['del_slug'])){
            $del = sanitize_text_field($_POST['del_slug']);
            $new = array();
            foreach($plans as $p) if($p['slug'] !== $del) $new[]=$p;
            zcss_save_plans($new);
            echo '<div class="notice notice-success is-dismissible"><p>پلن حذف شد.</p></div>';
        }
        if($action==='save_settings'){
            $settings = get_option(ZCSS_OPTION_SETTINGS, array());
            $settings['auto_assign_free_on_login'] = isset($_POST['auto_assign_free_on_login']) ? 1 : 0;
            $settings['free_plan_slug'] = sanitize_text_field($_POST['free_plan_slug']);
            $settings['auto_create_wc_product'] = isset($_POST['auto_create_wc_product']) ? 1 : 0;
            $settings['upgrade_plan_page_id'] = isset($_POST['upgrade_plan_page_id']) ? intval($_POST['upgrade_plan_page_id']) : 0;
            update_option(ZCSS_OPTION_SETTINGS, $settings);
            echo '<div class="notice notice-success is-dismissible"><p>تنظیمات ذخیره شد.</p></div>';
        }
        if($action==='assign_plan_to_user' && isset($_POST['user_id']) && isset($_POST['plan_slug'])){
            $user_id = intval($_POST['user_id']);
            $plan_slug = sanitize_text_field($_POST['plan_slug']);
            if($user_id > 0 && $plan_slug){
                $result = zcss_assign_plan_to_user($user_id, $plan_slug);
                if($result){
                    echo '<div class="notice notice-success is-dismissible"><p>پلن با موفقیت به کاربر اختصاص یافت.</p></div>';
                } else {
                    echo '<div class="notice notice-error is-dismissible"><p>خطا در اختصاص پلن به کاربر.</p></div>';
                }
            } else {
                echo '<div class="notice notice-error is-dismissible"><p>کاربر یا پلن معتبر نیست.</p></div>';
            }
        }
        if($action==='remove_user_plans' && isset($_POST['manage_user_id'])){
            $user_id = intval($_POST['manage_user_id']);
            $plans_to_remove = isset($_POST['remove_plans']) && is_array($_POST['remove_plans']) ? array_map('sanitize_text_field', $_POST['remove_plans']) : array();
            $removed_count = 0;
            foreach($plans_to_remove as $slug){
                if(zcss_unassign_plan_from_user($user_id, $slug)) $removed_count++;
            }
            if($removed_count > 0){
                echo '<div class="notice notice-success is-dismissible"><p>اشتراک‌های انتخاب‌شده حذف شدند.</p></div>';
            } else {
                echo '<div class="notice notice-error is-dismissible"><p>هیچ اشتراکی حذف نشد.</p></div>';
            }
        }
        if($action === 'set_restriction' && isset($_POST['post_id']) && isset($_POST['required_level'])){
            $post_id = intval($_POST['post_id']);
            $required_level = intval($_POST['required_level']);
            $original_post_id = isset($_POST['original_post_id']) ? intval($_POST['original_post_id']) : 0;
            if($post_id > 0){
                if($original_post_id > 0 && $original_post_id != $post_id){
                    delete_post_meta($original_post_id, ZCSS_META_REQUIRED_LEVEL);
                }
                if($required_level > 0){
                    update_post_meta($post_id, ZCSS_META_REQUIRED_LEVEL, $required_level);
                } else {
                    delete_post_meta($post_id, ZCSS_META_REQUIRED_LEVEL);
                }
                echo '<div class="notice notice-success is-dismissible"><p>محدودیت ذخیره شد.</p></div>';
            } else {
                echo '<div class="notice notice-error is-dismissible"><p>آیتم معتبر نیست.</p></div>';
            }
        }
        // New action for deleting restriction
        if($action === 'delete_restriction' && isset($_POST['del_post_id'])){
            $del_post_id = intval($_POST['del_post_id']);
            if($del_post_id > 0){
                delete_post_meta($del_post_id, ZCSS_META_REQUIRED_LEVEL);
                echo '<div class="notice notice-success is-dismissible"><p>محدودیت حذف شد.</p></div>';
            } else {
                echo '<div class="notice notice-error is-dismissible"><p>آیتم معتبر نیست.</p></div>';
            }
        }
    }

    $plans = zcss_get_plans();
    $settings = get_option(ZCSS_OPTION_SETTINGS, array('auto_assign_free_on_login'=>1,'free_plan_slug'=>'basic-free','auto_create_wc_product'=>1,'upgrade_plan_page_id'=>0));
    // Fetch users with plans
    $users_with_plans = get_users(array(
        'meta_key' => ZCSS_USER_META_PLANS,
        'number' => -1, // Get all users
    ));
    // Fetch all users for the assign plan dropdown
    $all_users = get_users(array('number' => -1));

    // Check if editing a plan
    $edit_slug = isset($_GET['edit_slug']) ? sanitize_text_field($_GET['edit_slug']) : '';
    $edit_plan = $edit_slug ? zcss_get_plan_by_slug($edit_slug) : null;

    // For manage user plans section
    $selected_user_id = isset($_POST['manage_user_id']) ? intval($_POST['manage_user_id']) : (isset($_GET['manage_user_id']) ? intval($_GET['manage_user_id']) : 0);

    // For restrictions list
    $post_types = array('post', 'page');
    if(class_exists('WooCommerce')) $post_types[] = 'product';
    $restrictions = array();
    foreach($post_types as $pt){
        $posts = get_posts(array(
            'post_type' => $pt,
            'posts_per_page' => -1,
            'post_status' => 'any',
            'meta_key' => ZCSS_META_REQUIRED_LEVEL,
        ));
        foreach($posts as $post){
            $level = get_post_meta($post->ID, ZCSS_META_REQUIRED_LEVEL, true);
            $restrictions[] = array(
                'post_id' => $post->ID,
                'post_title' => $post->post_title,
                'post_type' => $pt,
                'required_level' => $level,
            );
        }
    }

    // For editing restriction
    $edit_restriction_post_id = isset($_GET['edit_restriction']) ? intval($_GET['edit_restriction']) : 0;
    $edit_restriction_level = $edit_restriction_post_id ? get_post_meta($edit_restriction_post_id, ZCSS_META_REQUIRED_LEVEL, true) : 0;

    // If editing, pre-set selected_post_type and selected_post_id
    $selected_post_type = isset($_POST['post_type']) ? sanitize_text_field($_POST['post_type']) : (isset($_GET['post_type']) ? sanitize_text_field($_GET['post_type']) : '');
    $selected_post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : (isset($_GET['post_id']) ? intval($_GET['post_id']) : 0);
    $current_level = $selected_post_id ? get_post_meta($selected_post_id, ZCSS_META_REQUIRED_LEVEL, true) : 0;

    if($edit_restriction_post_id > 0 && empty($selected_post_type)){
        $edit_post = get_post($edit_restriction_post_id);
        if($edit_post && in_array($edit_post->post_type, $post_types)){
            $selected_post_type = $edit_post->post_type;
            $selected_post_id = $edit_restriction_post_id;
            $current_level = $edit_restriction_level;
        }
    }

    // Validate post type
    $allowed_post_types = $post_types;
    if(!in_array($selected_post_type, $allowed_post_types)) $selected_post_type = '';

    // Get posts if post type selected
    $posts = array();
    if($selected_post_type){
        $args = array(
            'post_type' => $selected_post_type,
            'posts_per_page' => -1,
            'post_status' => 'any',
        );
        $posts = get_posts($args);
    }

    // Fetch all pages for upgrade_plan_page_id dropdown
    $all_pages = get_posts(array(
        'post_type' => 'page',
        'posts_per_page' => -1,
        'post_status' => 'publish',
    ));

    // Tabs setup
    $current_tab = isset($_GET['tab']) ? $_GET['tab'] : 'settings';
    $tabs = array(
        'settings' => 'تنظیمات',
        'plans' => 'پلن‌ها',
        'users' => 'کاربران',
        'restrictions' => 'محدودیت‌ها',
        'guide' => 'راهنما',
    );

    ?>
    <style>
        /* Modern and Professional Styles for ZCSS Admin Panel */
        .wrap.zcss-wrap { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; max-width: 1200px; margin: 0 auto; }
        .zcss-wrap h1 { color: #333; border-bottom: 2px solid #0073aa; padding-bottom: 10px; }
        .nav-tab-wrapper { margin-bottom: 20px; }
        .nav-tab { background: #f1f1f1; border-radius: 5px 5px 0 0; padding: 10px 20px; font-weight: bold; }
        .nav-tab-active { background: #fff; border-bottom: none; }
        .zcss-section { background: #fff; border: 1px solid #ddd; border-radius: 8px; padding: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); margin-bottom: 20px; }
        .zcss-section h2 { color: #0073aa; margin-top: 0; }
        .form-table th { width: 200px; color: #555; }
        .form-table input[type="text"], .form-table input[type="number"], .form-table textarea, .form-table select { width: 100%; max-width: 400px; padding: 8px; border-radius: 4px; border: 1px solid #ccc; }
        .button-primary { background: #0073aa; border-color: #006799; border-radius: 4px; padding: 8px 16px; transition: background 0.3s; }
        .button-primary:hover { background: #006799; }
        .widefat { border-radius: 8px; overflow: hidden; }
        .widefat th { background: #f9f9f9; color: #333; padding: 12px; }
        .widefat td { padding: 12px; vertical-align: middle; }
        .widefat tbody tr:nth-child(odd) { background: #f9f9f9; }
        .notice { border-radius: 4px; margin: 10px 0; }
        /* Card styles for plans and users */
        .zcss-plan-card, .zcss-user-card { border: 1px solid #ddd; border-radius: 8px; padding: 15px; margin-bottom: 15px; background: #fff; box-shadow: 0 1px 5px rgba(0,0,0,0.05); }
        .zcss-plan-card h3, .zcss-user-card h3 { margin: 0 0 10px; color: #333; }
    </style>
    <div class="wrap zcss-wrap">
        <h1>پلن های اشتراک ویژه</h1>
        <p>شورت‌کدها: [zc_plans] برای نمایش پلن‌ها، [zc_my_plans] برای نمایش پلن‌های کاربر.</p>
        <nav class="nav-tab-wrapper">
            <?php foreach ($tabs as $tab_id => $tab_name): ?>
                <a href="<?php echo esc_url(add_query_arg('tab', $tab_id)); ?>" class="nav-tab <?php echo ($current_tab == $tab_id) ? 'nav-tab-active' : ''; ?>"><?php echo esc_html($tab_name); ?></a>
            <?php endforeach; ?>
        </nav>

        <div class="zcss-tab-content">
            <?php if ($current_tab == 'settings'): ?>
                <div class="zcss-section">
                    <h2>تنظیمات عمومی</h2>
                    <form method="post">
                        <?php wp_nonce_field('zcss_admin_save','zcss_admin_nonce'); ?>
                        <input type="hidden" name="zcss_action" value="save_settings">
                        <table class="form-table">
                            <tr>
                                <th><label for="auto_assign_free_on_login">فعال‌سازی خودکار پلن رایگان هنگام لاگین</label></th>
                                <td><input type="checkbox" id="auto_assign_free_on_login" name="auto_assign_free_on_login" <?php checked(1, $settings['auto_assign_free_on_login']); ?>></td>
                            </tr>
                            <tr>
                                <th><label for="free_plan_slug">پلن رایگان پایه</label></th>
                                <td>
                                    <select id="free_plan_slug" name="free_plan_slug">
                                        <?php foreach($plans as $p): ?>
                                            <option value="<?php echo esc_attr($p['slug']); ?>" <?php selected($settings['free_plan_slug'], $p['slug']); ?>><?php echo esc_html($p['title']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="auto_create_wc_product">ایجاد خودکار محصول ووکامرس برای پلن‌های پولی</label></th>
                                <td><input type="checkbox" id="auto_create_wc_product" name="auto_create_wc_product" <?php checked(1, $settings['auto_create_wc_product']); ?>></td>
                            </tr>
                            <tr>
                                <th><label for="upgrade_plan_page_id">صفحه ارتقای پلن (با [zc_plans])</label></th>
                                <td>
                                    <select id="upgrade_plan_page_id" name="upgrade_plan_page_id">
                                        <option value="0">-- خودکار (اولین صفحه با [zc_plans]) --</option>
                                        <?php foreach($all_pages as $page): ?>
                                            <option value="<?php echo esc_attr($page->ID); ?>" <?php selected($settings['upgrade_plan_page_id'], $page->ID); ?>><?php echo esc_html($page->post_title); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                        </table>
                        <p><button type="submit" class="button button-primary">ذخیره تنظیمات</button></p>
                    </form>
                </div>
            <?php elseif ($current_tab == 'plans'): ?>
                <div class="zcss-section">
                    <h2>لیست پلن‌ها</h2>
                    <table class="widefat fixed striped">
                        <thead><tr><th>عنوان</th><th>اسلاگ</th><th>قیمت</th><th>مدت (روز)</th><th>سطح</th><th>WC Product ID</th><th>عملیات</th></tr></thead>
                        <tbody>
                        <?php if(empty($plans)): ?>
                            <tr><td colspan="7">پلنی تعریف نشده.</td></tr>
                        <?php else: foreach($plans as $p): ?>
                            <tr>
                                <td><?php echo esc_html($p['title']); ?></td>
                                <td><?php echo esc_html($p['slug']); ?></td>
                                <td><?php echo esc_html($p['price']); ?></td>
                                <td><?php echo esc_html(isset($p['duration_days']) ? $p['duration_days'] : 0); ?></td>
                                <td><?php echo esc_html(isset($p['level']) ? $p['level'] : 0); ?></td>
                                <td><?php echo esc_html($p['wc_product_id']); ?></td>
                                <td>
                                    <a href="<?php echo esc_url(add_query_arg(array('tab' => 'plans', 'edit_slug' => $p['slug']))); ?>" class="button">ویرایش</a>
                                    <form method="post" style="display:inline;">
                                        <?php wp_nonce_field('zcss_admin_save','zcss_admin_nonce'); ?>
                                        <input type="hidden" name="zcss_action" value="delete_plan">
                                        <input type="hidden" name="del_slug" value="<?php echo esc_attr($p['slug']); ?>">
                                        <button type="submit" class="button" onclick="return confirm('حذف پلن؟');">حذف</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="zcss-section">
                    <h2><?php echo $edit_plan ? 'ویرایش پلن' : 'افزودن پلن جدید'; ?></h2>
                    <form method="post">
                        <?php wp_nonce_field('zcss_admin_save','zcss_admin_nonce'); ?>
                        <input type="hidden" name="zcss_action" value="save_plan">
                        <table class="form-table">
                            <tr><th>اسلاگ</th><td><input type="text" name="slug" required placeholder="مثال: pro-1" value="<?php echo esc_attr($edit_plan ? $edit_plan['slug'] : ''); ?>" <?php if($edit_plan) echo 'readonly'; ?>></td></tr>
                            <tr><th>عنوان</th><td><input type="text" name="title" required value="<?php echo esc_attr($edit_plan ? $edit_plan['title'] : ''); ?>"></td></tr>
                            <tr><th>توضیحات</th><td><textarea name="description" rows="4"><?php echo esc_textarea($edit_plan ? $edit_plan['description'] : ''); ?></textarea></td></tr>
                            <tr><th>قیمت</th><td><input type="text" name="price" placeholder="مثال: 100000" value="<?php echo esc_attr($edit_plan ? $edit_plan['price'] : ''); ?>"></td></tr>
                            <tr><th>WC Product ID (اختیاری)</th><td><input type="number" name="wc_product_id" value="<?php echo esc_attr($edit_plan ? $edit_plan['wc_product_id'] : '0'); ?>"></td></tr>
                            <tr><th>پولی است؟</th><td><input type="checkbox" name="is_paid" <?php checked(1, $edit_plan ? $edit_plan['is_paid'] : 0); ?>></td></tr>
                            <tr><th>مدت (روز)</th><td><input type="number" name="duration_days" value="<?php echo esc_attr($edit_plan ? $edit_plan['duration_days'] : '365'); ?>"> (0 = نامحدود)</td></tr>
                            <tr><th>سطح</th><td><input type="number" name="level" value="<?php echo esc_attr($edit_plan ? $edit_plan['level'] : '1'); ?>"></td></tr>
                        </table>
                        <p><button type="submit" class="button button-primary">ذخیره پلن</button></p>
                    </form>
                </div>
            <?php elseif ($current_tab == 'users'): ?>
                <div class="zcss-section">
                    <h2>کاربران دارای پلن</h2>
                    <table class="widefat fixed striped">
                        <thead><tr><th>شناسه</th><th>نام</th><th>ایمیل</th><th>پلن‌ها</th><th>وضعیت</th><th>حداکثر سطح</th></tr></thead>
                        <tbody>
                        <?php if (empty($users_with_plans)): ?>
                            <tr><td colspan="6">هیچ کاربری یافت نشد.</td></tr>
                        <?php else: foreach ($users_with_plans as $user): 
                            $user_plans = get_user_meta($user->ID, ZCSS_USER_META_PLANS, true);
                            $plan_details = [];
                            $is_active = false;
                            $user_max_level = zcss_get_user_max_level($user->ID);
                            if (is_array($user_plans)) {
                                foreach ($user_plans as $slug => $info) {
                                    $plan = zcss_get_plan_by_slug($slug);
                                    $title = $plan ? esc_html($plan['title']) : $slug;
                                    $expires = empty($info['expires']) ? 'نامحدود' : esc_html($info['expires']);
                                    $is_expired = !empty($info['expires']) && strtotime($info['expires']) < current_time('timestamp');
                                    $status = $is_expired ? 'منقضی' : 'فعال';
                                    if (!$is_expired) $is_active = true;
                                    $plan_details[] = "$title (انقضا: $expires، وضعیت: $status)";
                                }
                            }
                            $status_text = $is_active ? 'فعال' : 'منقضی';
                        ?>
                            <tr>
                                <td><?php echo esc_html($user->ID); ?></td>
                                <td><?php echo esc_html($user->display_name); ?></td>
                                <td><?php echo esc_html($user->user_email); ?></td>
                                <td><?php echo implode('<br>', $plan_details); ?></td>
                                <td><?php echo $status_text; ?></td>
                                <td><?php echo esc_html($user_max_level); ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="zcss-section">
                    <h2>اختصاص پلن به کاربر</h2>
                    <form method="post">
                        <?php wp_nonce_field('zcss_admin_save','zcss_admin_nonce'); ?>
                        <input type="hidden" name="zcss_action" value="assign_plan_to_user">
                        <table class="form-table">
                            <tr><th>کاربر</th><td>
                                <select name="user_id" required>
                                    <option value="">-- انتخاب --</option>
                                    <?php foreach($all_users as $user): ?>
                                        <option value="<?php echo esc_attr($user->ID); ?>"><?php echo esc_html($user->display_name . ' (' . $user->user_email . ')'); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td></tr>
                            <tr><th>پلن</th><td>
                                <select name="plan_slug" required>
                                    <option value="">-- انتخاب --</option>
                                    <?php foreach($plans as $p): ?>
                                        <option value="<?php echo esc_attr($p['slug']); ?>"><?php echo esc_html($p['title']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td></tr>
                        </table>
                        <p><button type="submit" class="button button-primary">اختصاص</button></p>
                    </form>
                </div>
                <div class="zcss-section">
                    <h2>مدیریت اشتراک‌های کاربر</h2>
                    <form method="post">
                        <?php wp_nonce_field('zcss_admin_save','zcss_admin_nonce'); ?>
                        <input type="hidden" name="zcss_action" value="select_user_for_manage">
                        <table class="form-table">
                            <tr><th>کاربر</th><td>
                                <select name="manage_user_id" required onchange="this.form.submit()">
                                    <option value="">-- انتخاب --</option>
                                    <?php foreach($all_users as $user): ?>
                                        <option value="<?php echo esc_attr($user->ID); ?>" <?php selected($selected_user_id, $user->ID); ?>><?php echo esc_html($user->display_name . ' (' . $user->user_email . ')'); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td></tr>
                        </table>
                    </form>
                    <?php if ($selected_user_id > 0): 
                        $user = get_user_by('id', $selected_user_id);
                        $user_plans = get_user_meta($selected_user_id, ZCSS_USER_META_PLANS, true);
                        if (is_array($user_plans) && !empty($user_plans)):
                    ?>
                        <h3>اشتراک‌های <?php echo esc_html($user->display_name); ?></h3>
                        <form method="post">
                            <?php wp_nonce_field('zcss_admin_save','zcss_admin_nonce'); ?>
                            <input type="hidden" name="zcss_action" value="remove_user_plans">
                            <input type="hidden" name="manage_user_id" value="<?php echo esc_attr($selected_user_id); ?>">
                            <table class="widefat fixed striped">
                                <thead><tr><th>انتخاب</th><th>عنوان</th><th>اسلاگ</th><th>شروع</th><th>انقضا</th><th>وضعیت</th></tr></thead>
                                <tbody>
                                <?php foreach ($user_plans as $slug => $info):
                                    $plan = zcss_get_plan_by_slug($slug);
                                    $title = $plan ? esc_html($plan['title']) : $slug;
                                    $started = esc_html($info['started']);
                                    $expires = empty($info['expires']) ? 'نامحدود' : esc_html($info['expires']);
                                    $is_expired = !empty($info['expires']) && strtotime($info['expires']) < current_time('timestamp');
                                    $status = $is_expired ? 'منقضی' : 'فعال';
                                ?>
                                    <tr>
                                        <td><input type="checkbox" name="remove_plans[]" value="<?php echo esc_attr($slug); ?>"></td>
                                        <td><?php echo $title; ?></td>
                                        <td><?php echo esc_html($slug); ?></td>
                                        <td><?php echo $started; ?></td>
                                        <td><?php echo $expires; ?></td>
                                        <td><?php echo $status; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                            <p><button type="submit" class="button button-primary">حذف انتخاب‌شده‌ها</button></p>
                        </form>
                    <?php else: ?>
                        <p>این کاربر اشتراکی ندارد.</p>
                    <?php endif; endif; ?>
                </div>
            <?php elseif ($current_tab == 'restrictions'): ?>
                <div class="zcss-section">
                    <h2>لیست محدودیت‌ها</h2>
                    <table class="widefat fixed striped">
                        <thead><tr><th>شناسه</th><th>عنوان</th><th>نوع</th><th>سطح مورد نیاز</th><th>عملیات</th></tr></thead>
                        <tbody>
                        <?php if(empty($restrictions)): ?>
                            <tr><td colspan="5">محدودیتی تعریف نشده.</td></tr>
                        <?php else: foreach($restrictions as $r): ?>
                            <tr>
                                <td><?php echo esc_html($r['post_id']); ?></td>
                                <td><?php echo esc_html($r['post_title']); ?></td>
                                <td><?php echo esc_html($r['post_type']); ?></td>
                                <td><?php echo esc_html($r['required_level']); ?></td>
                                <td>
                                    <a href="<?php echo esc_url(add_query_arg(array('tab' => 'restrictions', 'edit_restriction' => $r['post_id']))); ?>" class="button">ویرایش</a>
                                    <form method="post" style="display:inline;">
                                        <?php wp_nonce_field('zcss_admin_save','zcss_admin_nonce'); ?>
                                        <input type="hidden" name="zcss_action" value="delete_restriction">
                                        <input type="hidden" name="del_post_id" value="<?php echo esc_attr($r['post_id']); ?>">
                                        <button type="submit" class="button" onclick="return confirm('حذف محدودیت؟');">حذف</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="zcss-section">
                    <h2>تنظیم محدودیت جدید</h2>
                    <form method="post">
                        <?php wp_nonce_field('zcss_admin_save','zcss_admin_nonce'); ?>
                        <input type="hidden" name="zcss_action" value="select_post_type">
                        <table class="form-table">
                            <tr><th>نوع محتوا</th><td>
                                <select name="post_type" required onchange="this.form.submit()">
                                    <option value="">-- انتخاب --</option>
                                    <option value="post" <?php selected('post', $selected_post_type); ?>>نوشته‌ها</option>
                                    <option value="page" <?php selected('page', $selected_post_type); ?>>برگه‌ها</option>
                                    <?php if(class_exists('WooCommerce')): ?>
                                        <option value="product" <?php selected('product', $selected_post_type); ?>>محصولات</option>
                                    <?php endif; ?>
                                </select>
                            </td></tr>
                        </table>
                    </form>
                    <?php if($selected_post_type): ?>
                        <form method="post">
                            <?php wp_nonce_field('zcss_admin_save','zcss_admin_nonce'); ?>
                            <input type="hidden" name="zcss_action" value="select_post_id">
                            <input type="hidden" name="post_type" value="<?php echo esc_attr($selected_post_type); ?>">
                            <table class="form-table">
                                <tr><th>آیتم</th><td>
                                    <select name="post_id" required onchange="this.form.submit()">
                                        <option value="">-- انتخاب --</option>
                                        <?php foreach($posts as $post): ?>
                                            <option value="<?php echo esc_attr($post->ID); ?>" <?php selected($selected_post_id, $post->ID); ?>><?php echo esc_html($post->post_title); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td></tr>
                            </table>
                        </form>
                    <?php endif; ?>
                    <?php if($selected_post_id > 0): ?>
                        <form method="post">
                            <?php wp_nonce_field('zcss_admin_save','zcss_admin_nonce'); ?>
                            <input type="hidden" name="zcss_action" value="set_restriction">
                            <input type="hidden" name="post_type" value="<?php echo esc_attr($selected_post_type); ?>">
                            <input type="hidden" name="post_id" value="<?php echo esc_attr($selected_post_id); ?>">
                            <input type="hidden" name="original_post_id" value="<?php echo esc_attr($edit_restriction_post_id); ?>">
                            <table class="form-table">
                                <tr><th>سطح مورد نیاز</th><td>
                                    <input type="number" name="required_level" value="<?php echo esc_attr($current_level); ?>" min="0">
                                    <p class="description">0 = بدون محدودیت</p>
                                </td></tr>
                            </table>
                            <p><button type="submit" class="button button-primary">ذخیره</button></p>
                        </form>
                    <?php endif; ?>
                </div>
            <?php elseif ($current_tab == 'guide'): ?>
                <div class="zcss-section">
                    <h2>راهنمای استفاده از افزونه</h2>
                    <p>این افزونه برای مدیریت پلن‌های ویژه اشتراک طراحی شده است. در ادامه، شورت‌کدها و نحوه استفاده از آن‌ها توضیح داده شده است.</p>
                    
                    <h3>شورت‌کدها</h3>
                    <ul>
                        <li><strong>[zc_plans]</strong>: برای نمایش لیست پلن‌ها و امکان خرید یا فعال‌سازی رایگان.</li>
                        <li><strong>[zc_my_plans]</strong>: برای نمایش پلن‌های فعلی کاربر (شامل جزئیات مانند تاریخ شروع، انقضا و سطح).</li>
                    </ul>
                    
                    <h3>نحوه قرار دادن شورت‌کد در المنتور</h3>
                    <p>در صفحه ویرایش با المنتور:</p>
                    <ol>
                        <li>ویجت "Shortcode" را جستجو و به صفحه اضافه کنید.</li>
                        <li>در فیلد Shortcode، کد مورد نظر مانند [zc_plans] را وارد کنید.</li>
                        <li>صفحه را ذخیره و منتشر کنید.</li>
                    </ol>
                    
                    <h3>نحوه قرار دادن شورت‌کد از طریق کد</h3>
                    <p>در فایل‌های تم (مانند page.php یا single.php) یا با استفاده از توابع:</p>
                    <pre><code>&lt;?php echo do_shortcode('[zc_plans]'); ?&gt;</code></pre>
                    <p>یا در محتوای برگه/نوشته مستقیماً شورت‌کد را قرار دهید.</p>
                    
                    <h3>لینک صفحه خانه افزونه</h3>
                    <p>برای اطلاعات بیشتر، به <a href="https://zentrixcode.com/zc-special-subscription" target="_blank">صفحه خانه افزونه</a> </p>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

/* -----------------------
   Metabox for required level on posts, pages, products (still kept for direct editing)
   ----------------------- */
add_action('add_meta_boxes', function(){
    $post_types = array('post', 'page');
    if (class_exists('WooCommerce')) $post_types[] = 'product';
    add_meta_box('zcss_required_level', 'سطح مورد نیاز برای دسترسی', 'zcss_metabox_callback', $post_types, 'side', 'high');
});

function zcss_metabox_callback($post){
    wp_nonce_field('zcss_meta_save', 'zcss_meta_nonce');
    $required_level = get_post_meta($post->ID, ZCSS_META_REQUIRED_LEVEL, true);
    ?>
    <p>
        <label for="zcss_required_level">حداقل سطح پلن:</label>
        <input type="number" id="zcss_required_level" name="zcss_required_level" value="<?php echo esc_attr($required_level ? $required_level : ''); ?>" min="0" placeholder="0 = بدون محدودیت">
    </p>
    <p class="description">اگر 0 یا خالی باشد، محدودیت ندارد. سطح بالاتر = دسترسی بیشتر.</p>
    <?php
}

add_action('save_post', function($post_id){
    if (!isset($_POST['zcss_meta_nonce']) || !wp_verify_nonce($_POST['zcss_meta_nonce'], 'zcss_meta_save')) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;

    $required_level = isset($_POST['zcss_required_level']) ? intval($_POST['zcss_required_level']) : 0;
    if ($required_level > 0) {
        update_post_meta($post_id, ZCSS_META_REQUIRED_LEVEL, $required_level);
    } else {
        delete_post_meta($post_id, ZCSS_META_REQUIRED_LEVEL);
    }
});

/* -----------------------
   Auto-assign free on login (uses zcss_assign_plan_to_user)
   ----------------------- */
add_action('wp_login', function($user_login, $user){
    $settings = get_option(ZCSS_OPTION_SETTINGS, array('auto_assign_free_on_login'=>1, 'free_plan_slug'=>'basic-free'));
    if ( empty($settings['auto_assign_free_on_login']) ) return;
    $free_slug = isset($settings['free_plan_slug']) ? $settings['free_plan_slug'] : '';
    if ( ! $free_slug ) return;
    $user_id = is_object($user) && isset($user->ID) ? $user->ID : (int)$user;
    if ( ! $user_id ) return;
    // اگر قبلاً اختصاص داده شده یا RCP فعال است نکن
    $user_plans = get_user_meta($user_id, ZCSS_USER_META_PLANS, true);
    if (is_array($user_plans) && isset($user_plans[$free_slug])) return;
    if ( function_exists('rcp_user_has_active_subscription') && rcp_user_has_active_subscription($user_id) ) return;
    zcss_assign_plan_to_user($user_id, $free_slug);
}, 10, 2);

/* -----------------------
   Shortcode: list plans (shows duration and level nicely)
   Usage: [zc_plans]
   ----------------------- */
add_shortcode('zc_plans', function(){
    $plans = zcss_get_plans();
    if(empty($plans)) return '<p>پلنی تعریف نشده.</p>';
    $nonce = wp_create_nonce('zcss_activate_free');
    $cart_url = function_exists('wc_get_cart_url') ? wc_get_cart_url() : site_url('/cart');
    ob_start();
    echo '<div class="zcss-plans">';
    $user_id = is_user_logged_in() ? get_current_user_id() : 0;

    // gather active slugs for user (non-expired)
    $active_slugs = array();
    if ($user_id) {
        $user_plans = get_user_meta($user_id, ZCSS_USER_META_PLANS, true);
        if ( is_array($user_plans) ) {
            foreach($user_plans as $s => $info) {
                if ( empty($info['expires']) ) {
                    $active_slugs[] = $s;
                } else {
                    $exp = strtotime($info['expires']);
                    if ($exp !== false && $exp >= current_time('timestamp')) $active_slugs[] = $s;
                }
            }
        }
    }
    $user_has_paid = $user_id ? zcss_user_has_active_paid_plan($user_id) : false;

    foreach($plans as $p){
        $slug = isset($p['slug']) ? $p['slug'] : '';
        $is_paid = isset($p['is_paid']) && intval($p['is_paid'])===1;
        echo '<div class="zcss-plan" style="border:1px solid #eee;padding:12px;margin:8px 0;">';
        echo '<h3>'.esc_html($p['title']).'</h3>';
        echo '<p>'.nl2br(esc_html($p['description'])).'</p>';
        echo '<p><strong>قیمت:</strong> '.esc_html($p['price']).'</p>';
        $dur = (isset($p['duration_days']) && intval($p['duration_days'])>0) ? intval($p['duration_days']).' روز' : 'نامحدود';
        echo '<p><strong>مدت:</strong> '.esc_html($dur).'</p>';
        echo '<p><strong>سطح:</strong> '.esc_html(isset($p['level']) ? $p['level'] : '0').'</p>';

        // if user already has this plan active
        $user_has_this_plan = in_array($slug, $active_slugs);

        if ( $user_has_this_plan ) {
            echo '<span class="zcss-current-plan" style="font-weight:bold;color:green;">پلن فعلی شما</span>';
        } else {
            // free plan: never show buy; only allow activation if user has NOT any active paid plan and not RCP
            if ( !$is_paid ) {
                if ( is_user_logged_in() ) {
                    if ( $user_has_paid ) {
                        echo '<span>این پلن رایگان برای شما در دسترس نیست (شما پلن پولی فعال دارید).</span>';
                    } else {
                        // show activate free button
                        echo '<button class="button zcss-activate-free" data-slug="'.esc_attr($slug).'" data-nonce="'.esc_attr($nonce).'">فعال‌سازی رایگان</button>';
                        echo '<span class="zcss-activate-result" style="margin-left:8px"></span>';
                    }
                } else {
                    echo '<a href="'. wp_login_url(get_permalink()) .'">برای فعال‌سازی وارد شوید</a>';
                }
            } else {
                // paid plan: show buy button (add-to-cart) that redirects to cart
                if ( ! empty($p['wc_product_id']) && intval($p['wc_product_id'])>0 ) {
                    $addUrl = esc_url( add_query_arg('add-to-cart', intval($p['wc_product_id']), home_url('/') ) );
                    // data-product-id included for potential use
                    echo '<a class="button zcss-buy-link" data-product-id="'.intval($p['wc_product_id']).'" href="'. $addUrl .'">خرید / پرداخت</a>';
                } else if ( ! empty($p['purchase_url']) ) {
                    echo '<a class="button" href="'. esc_url($p['purchase_url']) .'">خرید / پرداخت</a>';
                } else {
                    echo '<span>برای خرید لطفاً با مدیریت تماس بگیرید.</span>';
                }
            }
        }

        echo '</div>';
    }
    echo '</div>';
    ?>
    <script>
    (function(){
        // Activate free
        document.addEventListener('click', function(e){
            if(!e.target.matches || !e.target.matches('.zcss-activate-free')) return;
            e.preventDefault();
            var btn = e.target;
            var slug = btn.getAttribute('data-slug');
            var nonce = btn.getAttribute('data-nonce');
            var result = btn.parentNode.querySelector('.zcss-activate-result');
            result.textContent = '...';
            var data = new FormData();
            data.append('action','zcss_activate_free');
            data.append('slug', slug);
            data.append('_wpnonce', nonce);
            fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                method:'POST',
                credentials:'same-origin',
                body:data
            }).then(function(r){ return r.json(); }).then(function(j){
                if ( j.success ) {
                    result.textContent = j.data.message || 'فعال شد';
                    setTimeout(function(){ location.reload(); }, 900);
                } else {
                    result.textContent = j.data && j.data.message ? j.data.message : 'خطا';
                }
            }).catch(function(){
                result.textContent = 'خطا در ارتباط';
            });
        }, false);

        // Buy behavior: request add-to-cart then redirect to cart
        document.addEventListener('click', function(e){
            if(!e.target.matches || !e.target.matches('.zcss-buy-link')) return;
            e.preventDefault();
            var href = e.target.getAttribute('href');
            var cart = '<?php echo esc_js($cart_url); ?>';
            // Use fetch to trigger add-to-cart then go to cart
            fetch(href, { method: 'GET', credentials: 'same-origin' })
                .then(function(){ window.location.href = cart; })
                .catch(function(){ window.location.href = cart; });
        }, false);
    })();
    </script>
    <?php
    return ob_get_clean();
});

/* -----------------------
   AJAX: activate free (uses zcss_assign_plan_to_user)
   Prevent activation if user already has active paid plan or RCP active
   ----------------------- */
add_action('wp_ajax_zcss_activate_free', function(){
    if(!is_user_logged_in()) wp_send_json_error(array('message'=>'ابتدا وارد شوید.'));
    if(!isset($_POST['_wpnonce']) || ! wp_verify_nonce($_POST['_wpnonce'],'zcss_activate_free')) wp_send_json_error(array('message'=>'درخواست نامعتبر.'));
    $slug = isset($_POST['slug']) ? sanitize_text_field($_POST['slug']) : '';
    if(!$slug) wp_send_json_error(array('message'=>'پلن مشخص نیست.'));
    $plan = zcss_get_plan_by_slug($slug);
    if(!$plan) wp_send_json_error(array('message'=>'پلن یافت نشد.'));
    if(intval($plan['is_paid'])===1) wp_send_json_error(array('message'=>'این پلن پولی است.'));
    $user_id = get_current_user_id();

    // if user has RCP active or internal paid plan, deny
    if(function_exists('rcp_user_has_active_subscription') && rcp_user_has_active_subscription($user_id)) {
        wp_send_json_error(array('message'=>'شما قبلاً اشتراک فعال دارید.'));
    }
    if ( zcss_user_has_active_paid_plan($user_id) ) {
        wp_send_json_error(array('message'=>'شما قبلاً یک پلن پولی فعال دارید.'));
    }

    zcss_assign_plan_to_user($user_id, $slug);
    wp_send_json_success(array('message'=>'پلن رایگان فعال شد.'));
});

// Inline styles moved out of heredoc and properly enqueued to avoid output during admin/editor requests.
if (!function_exists('zcss_enqueue_inline_styles')) {
    function zcss_enqueue_inline_styles() {
        $css = '.zcss-my-plans-wrapper { display:flex; flex-wrap:wrap; gap:16px; margin-top:12px; }
.zcss-my-plan-card { flex:1 1 250px; border:2px solid #4f46e5; border-radius:12px; padding:16px; background:linear-gradient(145deg,#f3f4f6,#e0e7ff); box-shadow:0 4px 10px rgba(0,0,0,0.15); transition: transform 0.3s, box-shadow 0.3s; }
.zcss-my-plan-card:hover { transform:translateY(-5px); box-shadow:0 8px 20px rgba(0,0,0,0.25); }
.zcss-my-plan-card p { margin:6px 0; line-height:1.4; color:#1e293b; }
.zcss-upgrade-btn-wrap { margin-top:16px; text-align:center; width:100%; }
.zcss-upgrade-btn { display:inline-block; padding:10px 20px; background:#4f46e5; color:#fff; border-radius:8px; text-decoration:none; font-weight:bold; transition:background 0.3s, transform 0.3s; }
.zcss-upgrade-btn:hover { background:#3730a3; transform:translateY(-2px); }';
        wp_register_style('zcss-inline', false);
        wp_enqueue_style('zcss-inline');
        wp_add_inline_style('zcss-inline', $css);
    }
    add_action('wp_enqueue_scripts', 'zcss_enqueue_inline_styles');
    add_action('admin_enqueue_scripts', 'zcss_enqueue_inline_styles');
}

/* -----------------------
   Shortcode: my plans (shows expiry and level)
   ----------------------- */
add_shortcode('zc_my_plans', function(){
    if(!is_user_logged_in()) return '<p>برای مشاهده پلن‌ها وارد شوید.</p>';
    
    $user_id = get_current_user_id();
    $out = '';

    $user_plans = get_user_meta($user_id, ZCSS_USER_META_PLANS, true);

    if($user_plans && is_array($user_plans)){
        $out .= '<div class="zcss-my-plans-wrapper">';

        foreach($user_plans as $slug => $info){
            $pl = zcss_get_plan_by_slug($slug);
            $title = $pl ? $pl['title'] : $slug;
            $desc = $pl ? $pl['description'] : '';
            $price = $pl ? $pl['price'] : '';
            $level = $pl ? $pl['level'] : 0;
            $started = isset($info['started']) ? esc_html($info['started']) : '-';
            $expires = isset($info['expires']) && $info['expires'] ? esc_html($info['expires']) : 'نامحدود';

            $out .= '<div class="zcss-my-plan-card">';
            $out .= "<p><strong>عنوان:</strong> {$title}</p>";
            $out .= $desc ? "<p class='zcss-plan-desc'>".nl2br(esc_html($desc))."</p>" : '';

            if($price !== '') $out .= "<p><strong>قیمت:</strong> {$price}</p>";
            $out .= "<p><strong>سطح:</strong> {$level}</p>";
            $out .= "<p><strong>شروع:</strong> {$started}</p>";
            $out .= "<p><strong>انقضا:</strong> {$expires}</p>";
            $out .= '</div>';
        }

        // دکمه ارتقا
        $upgrade_url = zcss_get_upgrade_plan_url();
        $out .= '<div class="zcss-upgrade-btn-wrap">';
        $out .= '<a href="' . esc_url($upgrade_url) . '" class="zcss-upgrade-btn">ارتقای پلن</a>';
        $out .= '</div>';

        $out .= '</div>';
    } else {
        $out .= '<p>هیچ پلنی ندارید.</p>';
        $upgrade_url = zcss_get_upgrade_plan_url();
        $out .= '<div class="zcss-upgrade-btn-wrap">';
        $out .= '<a href="' . esc_url($upgrade_url) . '" class="zcss-upgrade-btn">ارتقای پلن</a>';
        $out .= '</div>';
    }

    return $out;
});


/* -----------------------
   Admin: assign plans to user (profile)
   ----------------------- */
add_action('show_user_profile','zcss_user_profile_meta_box');
add_action('edit_user_profile','zcss_user_profile_meta_box');
function zcss_user_profile_meta_box($user){
    if(!current_user_can('manage_options')) return;
    $plans = zcss_get_plans();
    $user_plans = get_user_meta($user->ID, ZCSS_USER_META_PLANS, true);
    if(!is_array($user_plans)) $user_plans = array();
    ?>
    <h2>پلن‌های ZC Special</h2>
    <table class="form-table"><tr><th>انتساب پلن‌ها</th><td>
    <?php foreach($plans as $p):
        $checked = isset($user_plans[$p['slug']]); ?>
        <label style="display:block;">
            <input type="checkbox" name="zcss_user_plans[]" value="<?php echo esc_attr($p['slug']); ?>" <?php checked($checked); ?>>
            <?php echo esc_html($p['title'].' ('.$p['slug'].' - سطح '.$p['level'].')'); ?>
        </label>
    <?php endforeach; ?>
    <p class="description">تیک‌دار کردن یک پلن آن را به کاربر اختصاص می‌دهد. (اگر پلن پولی اختصاص داده شود، پلن رایگان حذف خواهد شد.)</p>
    </td></tr></table>
    <?php
}
add_action('personal_options_update','zcss_save_user_profile_meta');
add_action('edit_user_profile_update','zcss_save_user_profile_meta');
function zcss_save_user_profile_meta($user_id){
    if(!current_user_can('manage_options')) return;
    $plans = isset($_POST['zcss_user_plans']) && is_array($_POST['zcss_user_plans']) ? array_map('sanitize_text_field', $_POST['zcss_user_plans']) : array();
    // convert to the meta structure with started now and expires from plan duration
    $now = current_time('timestamp');
    $existing = get_user_meta($user_id, ZCSS_USER_META_PLANS, true);
    if(!is_array($existing)) $existing = array();
    foreach($plans as $slug){
        if(!isset($existing[$slug])){
            // assign
            zcss_assign_plan_to_user($user_id, $slug);
        }
    }
    // remove unchecked
    foreach($existing as $slug => $info){
        if(!in_array($slug, $plans)){
            zcss_unassign_plan_from_user($user_id, $slug);
        }
    }
}

/* -----------------------
   WooCommerce order completed: assign plans
   (when assigning paid plan, zcss_assign_plan_to_user will remove free)
   ----------------------- */
add_action('woocommerce_order_status_completed','zcss_woocommerce_order_completed', 10, 1);
function zcss_woocommerce_order_completed($order_id){
    if(!class_exists('WC_Order')) return;
    $order = wc_get_order($order_id);
    if(!$order) return;
    $user_id = $order->get_user_id();
    if(!$user_id) return;
    foreach($order->get_items() as $item){
        $product_id = $item->get_product_id();
        $plan = zcss_get_plan_by_wc_product($product_id);
        if($plan){
            // assign plan with start and expiry based on plan.duration_days
            zcss_assign_plan_to_user($user_id, $plan['slug']);
        }
    }
}

/* -----------------------
   Daily expiration check (cron)
   ----------------------- */
add_action('zcss_daily_expire_event', 'zcss_daily_expire_check');
function zcss_daily_expire_check(){
    // scan users who have ZCSS_USER_META_PLANS
    $users = get_users(array(
        'meta_key' => ZCSS_USER_META_PLANS,
        'number' => 0,
    ));
    $now_ts = current_time('timestamp');
    foreach($users as $user){
        $user_plans = get_user_meta($user->ID, ZCSS_USER_META_PLANS, true);
        if(!is_array($user_plans)) continue;
        $changed = false;
        foreach($user_plans as $slug => $info){
            if(empty($info['expires'])) continue; // never expires
            $exp_ts = strtotime($info['expires']);
            if($exp_ts !== false && $exp_ts < $now_ts){
                // expired -> remove
                unset($user_plans[$slug]);
                $changed = true;
            }
        }
        if($changed){
            update_user_meta($user->ID, ZCSS_USER_META_PLANS, $user_plans);
        }
    }
}
?>