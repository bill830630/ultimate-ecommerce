<?php
/**
 * Plugin Name: Ultimate E-commerce
 * Plugin URI: https://nibill-studio.com/
 * Description: 具備會員分級、動態折扣規則、優惠卡券、紅利點數系統、智能贈品與加購引擎的終極電商。
 * Version: 25.8.92
 * Author: NiBill
 * Author URI: https://nibill-studio.com/
 * Text Domain: ultimate-ecommerce
 * Requires Plugins: woocommerce
 * WC tested up to: 11.1
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * 主外掛檔的位置常數。
 *
 * 為什麼需要這三個常數，而不是各處直接寫 __FILE__ / __DIR__：
 * __FILE__ 與 __DIR__ 的值是「當前這個 PHP 檔案」的位置，一旦某段程式碼被搬進
 * includes/ 底下的子檔案，它們就會跟著改變，而且是**靜默**改變：
 *
 *   - register_activation_hook( __FILE__, ... ) 的 hook 名稱是
 *     'activate_' . plugin_basename( $file )，檔案一換，掛勾名稱就變成
 *     activate_ultimate-ecommerce/includes/xxx.php，WordPress 啟用外掛時永遠不會觸發它，
 *     不會有任何錯誤訊息。
 *   - TWSHOP_PLUGIN_URL . 'assets/...' 會變成 .../ultimate-ecommerce/includes/assets/...，
 *     前端 CSS/JS 全部 404，PHP 端毫無異狀。
 *   - FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__ ) 認的是
 *     主外掛檔，換成子檔案等於沒宣告，WooCommerce 會把本外掛列為 HPOS 不相容。
 *
 * 這些全都不會噴 PHP 錯誤、不會改變函式定義，靠語法檢查與函式清單比對都抓不到。
 * 因此在拆檔之前先把所有位置相關的取值統一收斂到這三個常數，之後不論程式碼搬到
 * 哪個子檔案，算出來的路徑都仍然指向主外掛檔與外掛根目錄。
 */
define( 'TWSHOP_PLUGIN_FILE', __FILE__ );
define( 'TWSHOP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'TWSHOP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once TWSHOP_PLUGIN_DIR . 'includes/helpers.php';
require_once TWSHOP_PLUGIN_DIR . 'includes/license.php';
require_once TWSHOP_PLUGIN_DIR . 'includes/init.php';
require_once TWSHOP_PLUGIN_DIR . 'includes/modules/order-checkout.php';
require_once TWSHOP_PLUGIN_DIR . 'includes/modules/order-logistics.php';
require_once TWSHOP_PLUGIN_DIR . 'includes/modules/order-admin.php';
require_once TWSHOP_PLUGIN_DIR . 'includes/modules/cart-injection.php';
require_once TWSHOP_PLUGIN_DIR . 'includes/admin/ui-components.php';
require_once TWSHOP_PLUGIN_DIR . 'includes/admin/menus.php';
require_once TWSHOP_PLUGIN_DIR . 'includes/admin/dashboard.php';
require_once TWSHOP_PLUGIN_DIR . 'includes/admin/pages.php';
require_once TWSHOP_PLUGIN_DIR . 'includes/admin/settings.php';
require_once TWSHOP_PLUGIN_DIR . 'includes/admin/icons.php';
require_once TWSHOP_PLUGIN_DIR . 'includes/admin/page-member-tiers.php';
require_once TWSHOP_PLUGIN_DIR . 'includes/admin/page-points.php';
require_once TWSHOP_PLUGIN_DIR . 'includes/admin/page-general.php';
require_once TWSHOP_PLUGIN_DIR . 'includes/admin/page-discount-rules.php';
require_once TWSHOP_PLUGIN_DIR . 'includes/modules/points-engine.php';
require_once TWSHOP_PLUGIN_DIR . 'includes/modules/discount-engine.php';
require_once TWSHOP_PLUGIN_DIR . 'includes/modules/cart-progress.php';
require_once TWSHOP_PLUGIN_DIR . 'includes/modules/product-slug.php';
require_once TWSHOP_PLUGIN_DIR . 'includes/modules/membership.php';
require_once TWSHOP_PLUGIN_DIR . 'includes/modules/wallet-core.php';
require_once TWSHOP_PLUGIN_DIR . 'includes/modules/wallet-account.php';
require_once TWSHOP_PLUGIN_DIR . 'includes/modules/wallet-checkout.php';
require_once TWSHOP_PLUGIN_DIR . 'includes/modules/wallet-topup.php';
require_once TWSHOP_PLUGIN_DIR . 'includes/admin/page-wallet.php';
require_once TWSHOP_PLUGIN_DIR . 'includes/modules/shopee-api.php';
require_once TWSHOP_PLUGIN_DIR . 'includes/modules/shopee-products.php';
require_once TWSHOP_PLUGIN_DIR . 'includes/modules/shopee-orders.php';
require_once TWSHOP_PLUGIN_DIR . 'includes/admin/page-shopee.php';
require_once TWSHOP_PLUGIN_DIR . 'includes/class-twshop-updater.php';
