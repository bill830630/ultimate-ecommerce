<?php
/**
 * 團購模式結帳頁：與團購專屬頁相同的獨立外框（無主題頁首選單、頁尾、側欄），
 * 內容仍是結帳頁本身（WooCommerce 結帳短代碼／付款完成頁），金流與超商選店照常運作。
 * 唯一的站內出口是「返回團購頁」。
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$twshop_gb_leader = twshop_group_buy_checkout_leader();
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<main class="twshop-gb twshop-gb--checkout">
    <header class="twshop-gb__header">
        <p class="twshop-gb__back"><a href="<?php echo esc_url( twshop_group_buy_page_url( $twshop_gb_leader ) ); ?>">← <?php echo esc_html( twshop_group_buy_t( '返回團購頁' ) ); ?></a></p>
        <h1 class="twshop-gb__checkout-title"><?php echo esc_html( twshop_group_buy_checkout_heading() ); ?></h1>
    </header>
    <div class="twshop-gb__checkout">
        <?php
        while ( have_posts() ) {
            the_post();
            the_content();
        }
        ?>
    </div>
</main>
<?php wp_footer(); ?>
</body>
</html>
