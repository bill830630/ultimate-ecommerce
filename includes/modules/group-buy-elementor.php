<?php
/**
 * 團購頁 Elementor 整合：用 Elementor 範本（elementor_library）設計團購頁，
 * 範本放進團購頁的獨立外框輸出；團購名稱／簡介／商品由「團購」分類的小工具依網址帶入。
 * Elementor 未啟用或未選範本時一律回到內建版型，團購功能本身不依賴 Elementor。
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function twshop_group_buy_elementor_active() {
    return did_action( 'elementor/loaded' ) && class_exists( '\Elementor\Plugin' );
}

/** 可當團購頁版型的範本類型：頁面、區段、容器（排除頁首、頁尾、彈窗等 Pro 版型）。 */
function twshop_group_buy_template_types() {
    return array( 'page', 'section', 'container' );
}

/** 已發布的可用範本（ID => 標題）。 */
function twshop_group_buy_template_options() {
    if ( ! twshop_group_buy_elementor_active() ) return array();
    $posts = get_posts( array(
        'post_type'      => 'elementor_library',
        'post_status'    => 'publish',
        'posts_per_page' => 200,
        'orderby'        => 'title',
        'order'          => 'ASC',
        'meta_query'     => array( array( 'key' => '_elementor_template_type', 'value' => twshop_group_buy_template_types(), 'compare' => 'IN' ) ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
    ) );
    $options = array();
    foreach ( $posts as $post ) $options[ $post->ID ] = $post->post_title ?: '（未命名範本 #' . $post->ID . '）';
    return $options;
}

function twshop_group_buy_valid_template( $id ) {
    $id = (int) $id;
    if ( $id <= 0 || ! twshop_group_buy_elementor_active() ) return 0;
    $post = get_post( $id );
    if ( ! $post || 'elementor_library' !== $post->post_type || 'publish' !== $post->post_status ) return 0;
    return in_array( get_post_meta( $id, '_elementor_template_type', true ), twshop_group_buy_template_types(), true ) ? $id : 0;
}

function twshop_group_buy_default_template() {
    return (int) get_option( 'twshop_group_buy_template_id', 0 );
}

/** 團購主實際使用的範本：個別指定（0＝內建版型）優先，未指定（NULL）用全站預設；無效或 Elementor 未啟用回 0。 */
function twshop_group_buy_template_for( $leader ) {
    if ( ! is_array( $leader ) || ! twshop_group_buy_elementor_active() ) return 0;
    $own = $leader['template_id'] ?? null;
    $id = null === $own || '' === $own ? twshop_group_buy_default_template() : (int) $own;
    return twshop_group_buy_valid_template( $id );
}

function twshop_group_buy_render_elementor_template( $template_id ) {
    if ( ! twshop_group_buy_elementor_active() ) return '';
    return \Elementor\Plugin::instance()->frontend->get_builder_content_for_display( (int) $template_id, true );
}

/** 團購頁使用範本時載入 Elementor 前台樣式與該範本的 CSS 檔。 */
function twshop_group_buy_elementor_assets() {
    if ( ! twshop_group_buy_is_group_page() || ! twshop_group_buy_elementor_active() ) return;
    $id = twshop_group_buy_template_for( twshop_group_buy_page_leader() );
    if ( ! $id ) return;
    $elementor = \Elementor\Plugin::instance();
    // V4 原子元素的樣式只為 elementor/post/render 登記過的文件產生（團購頁不是 singular，Elementor 不會自己登記範本）；
    // 必須在 enqueue_styles() 觸發 after_enqueue_post_styles 之前登記，所以掛在優先序 20 之前的 19。
    do_action( 'elementor/post/render', $id );
    $elementor->frontend->enqueue_styles();
    if ( class_exists( '\Elementor\Core\Files\CSS\Post' ) ) \Elementor\Core\Files\CSS\Post::create( $id )->enqueue();
}

/**
 * 在 Elementor 編輯範本時（預覽 iframe 與小工具的 AJAX 重繪）用第一位啟用中的團購主當示範資料，
 * 讓小工具與團購價能直接預覽。只限編輯 elementor_library 範本、且有編輯權限的使用者。
 */
function twshop_group_buy_elementor_preview_leader() {
    static $resolved = false, $leader = null;
    if ( $resolved ) return $leader;
    // phpcs:disable WordPress.Security.NonceVerification.Recommended -- 只決定預覽資料；Elementor 自行驗證編輯請求。
    $post_id = 0;
    if ( isset( $_GET['elementor-preview'] ) ) {
        $post_id = absint( $_GET['elementor-preview'] );
    } elseif ( wp_doing_ajax() && 'elementor_ajax' === ( $_REQUEST['action'] ?? '' ) ) {
        $post_id = absint( $_REQUEST['editor_post_id'] ?? 0 );
    }
    // phpcs:enable WordPress.Security.NonceVerification.Recommended
    if ( ! $post_id ) return null; // 尚未能判斷（太早呼叫）時不快取
    $resolved = true;
    if ( ! twshop_group_buy_enabled() || 'elementor_library' !== get_post_type( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) return null;
    global $wpdb;
    $code = $wpdb->get_var( 'SELECT code FROM ' . twshop_group_buy_tables()['leaders'] . " WHERE status = 'active' ORDER BY created_at ASC, user_id ASC LIMIT 1" );
    $leader = $code ? twshop_group_buy_find_active_leader( $code ) : null;
    return $leader;
}

/** 小工具輸出時使用的團購主：團購頁的團購主，或編輯器預覽的示範團購主。 */
function twshop_group_buy_widget_leader() {
    return twshop_group_buy_context_leader();
}

function twshop_group_buy_is_elementor_editing() {
    return twshop_group_buy_elementor_active()
        && ( \Elementor\Plugin::instance()->editor->is_edit_mode() || \Elementor\Plugin::instance()->preview->is_preview_mode() || ( wp_doing_ajax() && 'elementor_ajax' === ( $_REQUEST['action'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
}

function twshop_group_buy_elementor_category( $elements_manager ) {
    $elements_manager->add_category( 'twshop-group-buy', array( 'title' => '團購', 'icon' => 'eicon-cart' ) );
}

function twshop_group_buy_elementor_widgets( $widgets_manager ) {
    require_once TWSHOP_PLUGIN_DIR . 'includes/modules/elementor/group-buy-widgets.php';
    $widgets_manager->register( new TWShop_GB_Widget_Title() );
    $widgets_manager->register( new TWShop_GB_Widget_Intro() );
    $widgets_manager->register( new TWShop_GB_Widget_Products() );
}

/** 編輯器預覽也要載入團購頁的版面樣式，小工具外觀才與前台一致。 */
function twshop_group_buy_elementor_preview_styles() {
    wp_enqueue_style( 'twshop-group-buy', TWSHOP_PLUGIN_URL . 'assets/css/group-buy.css', array(), filemtime( TWSHOP_PLUGIN_DIR . 'assets/css/group-buy.css' ) );
}

function twshop_group_buy_elementor_init() {
    add_action( 'elementor/elements/categories_registered', 'twshop_group_buy_elementor_category' );
    add_action( 'elementor/widgets/register', 'twshop_group_buy_elementor_widgets' );
    add_action( 'elementor/preview/enqueue_styles', 'twshop_group_buy_elementor_preview_styles' );
    add_action( 'wp_enqueue_scripts', 'twshop_group_buy_elementor_assets', 19 );
}
