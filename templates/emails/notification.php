<?php
/**
 * 外掛通知信內文。可由主題的 ultimate-ecommerce/emails/notification.php 覆寫。
 * 使用 email-safe table 與 inline CSS；文字一律跳脫，配色沿用 WooCommerce 設定。
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$base_color = sanitize_hex_color( get_option( 'woocommerce_email_base_color', '#720eec' ) ) ?: '#720eec';
$text_color = sanitize_hex_color( get_option( 'woocommerce_email_text_color', '#3c3c3c' ) ) ?: '#3c3c3c';
$button_text = wc_light_or_dark( $base_color, '#202020', '#ffffff' );
$details = $context['details'] ?? array();
$gift_lines = $context['gift_lines'] ?? array();
$gift_text = implode( "\n", $gift_lines );
// 只將本封信已發放的完整贈禮文字替換為表格，其他自訂文案保持原順序與內容。
$body_parts = '' !== $gift_text ? explode( $gift_text, $body ) : array( $body );

do_action( 'woocommerce_email_header', $email_heading, $email ?? null );
?>
<div class="twshop-email-notification" style="color:<?php echo esc_attr( $text_color ); ?>; font-size:16px; line-height:1.7;">
    <?php if ( ! empty( $context['title'] ) ) : ?>
        <h2 style="margin:0 0 20px; padding:0 0 12px; border-bottom:2px solid <?php echo esc_attr( $base_color ); ?>; font-size:20px; line-height:1.4;"><?php echo esc_html( $context['title'] ); ?></h2>
    <?php endif; ?>

    <?php foreach ( $body_parts as $i => $part ) : ?>
        <?php if ( $i > 0 ) : ?>
            <table class="td twshop-email-gifts" cellspacing="0" cellpadding="0" width="100%" style="width:100%; margin:0 0 24px; border-collapse:collapse; border:1px solid #e5e5e5;">
                <thead><tr><th scope="col" style="padding:12px 16px; text-align:left; background-color:#f7f7f7; font-size:14px;">本次專屬好禮</th></tr></thead>
                <tbody><?php foreach ( $gift_lines as $gift ) : ?>
                    <tr><td class="td" style="padding:14px 16px; border-top:1px solid #e5e5e5; font-size:16px; font-weight:600; overflow-wrap:anywhere;"><?php echo esc_html( $gift ); ?></td></tr>
                <?php endforeach; ?></tbody>
            </table>
        <?php endif; ?>
        <?php echo wp_kses_post( wpautop( make_clickable( esc_html( trim( $part ) ) ) ) ); ?>
    <?php endforeach; ?>

    <?php if ( $details ) : ?>
        <table class="td twshop-email-details" cellspacing="0" cellpadding="0" width="100%" aria-label="通知明細" style="width:100%; margin:24px 0; border-collapse:collapse; border:1px solid #e5e5e5;">
            <tbody><?php foreach ( $details as $label => $value ) : ?>
                <tr>
                    <th class="td" scope="row" style="padding:12px 16px; width:40%; text-align:left; vertical-align:top; background-color:#f7f7f7; border:1px solid #e5e5e5; font-size:14px; font-weight:400;"><?php echo esc_html( $label ); ?></th>
                    <td class="td" style="padding:12px 16px; text-align:left; vertical-align:top; border:1px solid #e5e5e5; font-size:16px; font-weight:600; overflow-wrap:anywhere;"><?php echo esc_html( $value ); ?></td>
                </tr>
            <?php endforeach; ?></tbody>
        </table>
    <?php endif; ?>

    <?php if ( ! empty( $context['action_url'] ) && ! empty( $context['action_label'] ) ) : ?>
        <table role="presentation" cellspacing="0" cellpadding="0" style="margin:24px 0 12px; border:0;">
            <tr><td style="background-color:<?php echo esc_attr( $base_color ); ?>; border-radius:4px; text-align:center;">
                <a class="twshop-email-action" href="<?php echo esc_url( $context['action_url'] ); ?>" style="display:inline-block; padding:14px 24px; background-color:<?php echo esc_attr( $base_color ); ?>; color:<?php echo esc_attr( $button_text ); ?>; border-radius:4px; text-decoration:none; font-size:16px; line-height:1.5; font-weight:600;"><?php echo esc_html( $context['action_label'] ); ?></a>
            </td></tr>
        </table>
    <?php endif; ?>
</div>
<?php do_action( 'woocommerce_email_footer', $email ?? null ); ?>
