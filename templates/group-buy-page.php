<?php
/**
 * 團購主專屬頁：獨立形象頁，不輸出主題的頁首選單、頁尾與側欄，商品卡不連到商品頁，
 * 顧客在本頁選規格、加入購物車，再從底部購物車列直接前往結帳。
 * 仍呼叫 wp_head()／wp_footer()，沿用主題字體、配色與按鈕樣式及 WooCommerce 腳本。
 * 內容：有指定 Elementor 範本就輸出範本（團購名稱／簡介／商品由團購小工具帶入），否則用內建版型。
 * GET 回應對所有訪客相同（可被 CDN 快取）；個人的購物車件數由購物車片段在瀏覽器端補上。
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$twshop_gb_leader      = twshop_group_buy_page_leader();
$twshop_gb_template_id = twshop_group_buy_template_for( $twshop_gb_leader );
$twshop_gb_is_post     = 'POST' === strtoupper( $_SERVER['REQUEST_METHOD'] ?? 'GET' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated
$twshop_gb_period      = twshop_group_buy_render_period_notice( $twshop_gb_leader );
$twshop_gb_open        = twshop_group_buy_is_open( $twshop_gb_leader );
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<?php if ( $twshop_gb_template_id ) : ?>
<main class="twshop-gb twshop-gb--elementor">
    <?php echo $twshop_gb_period; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 函式內已跳脫。 ?>
    <?php echo twshop_group_buy_render_notices( $twshop_gb_is_post ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WooCommerce 原生通知。 ?>
    <?php echo twshop_group_buy_render_elementor_template( $twshop_gb_template_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Elementor 輸出。 ?>
</main>
<?php else : ?>
<main class="twshop-gb">
    <header class="twshop-gb__header">
        <h1 class="twshop-gb__title"><?php echo esc_html( twshop_group_buy_leader_title( $twshop_gb_leader ) ); ?></h1>
        <?php if ( '' !== trim( (string) $twshop_gb_leader['intro'] ) ) : ?>
            <div class="twshop-gb__intro"><?php echo wp_kses_post( wpautop( esc_html( $twshop_gb_leader['intro'] ) ) ); ?></div>
        <?php endif; ?>
    </header>
    <?php echo $twshop_gb_period; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 函式內已跳脫。 ?>
    <?php echo twshop_group_buy_render_notices( $twshop_gb_is_post ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WooCommerce 原生通知。 ?>
    <?php echo twshop_group_buy_render_products( $twshop_gb_leader ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 函式內已跳脫。 ?>
</main>
<?php endif; ?>

<?php if ( $twshop_gb_open ) : ?>
<div class="twshop-gb-cartpanel" id="twshop-gb-cartpanel" role="dialog" aria-label="購物車內容" hidden>
    <div class="twshop-gb-cartpanel__head"><strong>購物車</strong><button type="button" class="twshop-gb-cartpanel__close" aria-label="關閉購物車">×</button></div>
    <div class="twshop-gb-cartpanel__error" role="alert" hidden></div>
    <?php echo twshop_group_buy_cart_panel_items( $twshop_gb_is_post ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 函式內已跳脫。 ?>
</div>
<div class="twshop-gb-cartbar" role="region" aria-label="購物車">
    <div class="twshop-gb-cartbar__toggle" role="button" tabindex="0" aria-controls="twshop-gb-cartpanel" aria-expanded="false">
        <?php echo twshop_group_buy_cartbar_summary( $twshop_gb_is_post ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 函式內已跳脫。 ?>
        <span class="twshop-gb-cartbar__caret" aria-hidden="true">▲</span>
    </div>
    <a class="button alt twshop-gb-cartbar__checkout" href="<?php echo esc_url( twshop_group_buy_checkout_url( $twshop_gb_leader['code'] ) ); ?>">前往結帳</a>
</div>
<?php endif; ?>
<?php wp_footer(); ?>
</body>
</html>
