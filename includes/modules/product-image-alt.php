<?php
/**
 * 商品圖片 alt 統一由規則產生（SEO／無障礙）。
 *
 * 媒體庫裡的 alt 常是下載或製作時自動產生的檔名或無關文字，不能信任，所以開啟後一律
 * 依格式覆蓋輸出，不看原本的 alt；媒體庫裡儲存的值不會被改動，關閉開關即完全恢復。
 *
 * 圖片的 title（滑鼠提示）原本是檔名，若輸出有 title 也一併換成同一個文字。
 * 格式可用 {name}（商品名稱）、{n}（第幾張，主圖為 1）、{attributes}（規格值）。
 * 只有「確認是該商品自己的圖片」才會改：主圖、圖庫、規格圖。其他圖片（Logo、文章圖）不動。
 * 不綁任何模組開關（純顯示偏好，比照商品頁頁籤名稱、運送／付款方式改名）。
 */

/** 產生單張商品圖的 alt；$kind：main／gallery／variation。 */
function twshop_build_product_image_alt( $product, $kind, $n = 1 ) {
    $parent = $product->is_type( 'variation' ) ? wc_get_product( $product->get_parent_id() ) : $product;
    if ( ! $parent ) return '';

    $attributes = '';
    if ( 'variation' === $kind && $product->is_type( 'variation' ) ) {
        $attributes = str_replace( ', ', ' ', wc_get_formatted_variation( $product, true, false, false ) );
    }

    $templates = array(
        'main'      => twshop_option( 'wc_product_image_alt_main' ),
        'gallery'   => twshop_option( 'wc_product_image_alt_gallery' ),
        'variation' => twshop_option( 'wc_product_image_alt_variation' ),
    );
    $alt = str_replace(
        array( '{name}', '{n}', '{attributes}' ),
        array( $parent->get_name(), (string) $n, $attributes ),
        $templates[ $kind ]
    );
    return trim( wp_strip_all_tags( preg_replace( '/\s+/', ' ', $alt ) ) );
}

/**
 * 判斷某張附件在這個商品裡是什麼角色，回傳 alt；不是這個商品的圖片時回傳 null。
 */
function twshop_product_image_alt_for_attachment( $product, $attachment_id ) {
    $attachment_id = (int) $attachment_id;
    if ( ! $product instanceof WC_Product || $attachment_id <= 0 ) return null;

    if ( $product->is_type( 'variation' ) && (int) $product->get_image_id() === $attachment_id ) {
        return twshop_build_product_image_alt( $product, 'variation' );
    }
    if ( (int) $product->get_image_id() === $attachment_id ) {
        return twshop_build_product_image_alt( $product, 'main', 1 );
    }
    $parent = $product->is_type( 'variation' ) ? wc_get_product( $product->get_parent_id() ) : $product;
    if ( $parent ) {
        $gallery = array_map( 'intval', $parent->get_gallery_image_ids() );
        $idx = array_search( $attachment_id, $gallery, true );
        if ( false !== $idx ) {
            return twshop_build_product_image_alt( $parent, 'gallery', $idx + 2 );
        }
    }
    return null;
}

/**
 * 涵蓋商品頁圖庫、列表頁等有「目前商品」的情境：附件必須確實是目前商品的圖片才覆蓋。
 * 全域 $product 過期或不相關時比對不到，結果就是不動——不會把別的圖片改錯。
 */
function twshop_filter_attachment_alt_by_product( $attr, $attachment ) {
    global $product;
    if ( ! $product instanceof WC_Product || ! $attachment ) return $attr;
    $alt = twshop_product_image_alt_for_attachment( $product, $attachment->ID );
    if ( null !== $alt && '' !== $alt ) {
        $attr['alt'] = $alt;
        // 滑鼠停在圖片上顯示的提示（title）原本是圖片檔名，一併換成同一個文字。
        if ( isset( $attr['title'] ) ) $attr['title'] = $alt;
    }
    return $attr;
}

/** $product->get_image() 輸出（購物車縮圖、結帳、信件、相關商品）：直接改 <img> 的 alt。 */
function twshop_filter_product_get_image_alt( $image, $product ) {
    if ( ! $product instanceof WC_Product || false === strpos( $image, '<img' ) ) return $image;
    $alt = twshop_product_image_alt_for_attachment( $product, $product->get_image_id() );
    if ( null === $alt || '' === $alt ) return $image;

    $tags = new WP_HTML_Tag_Processor( $image );
    if ( $tags->next_tag( 'img' ) ) {
        $tags->set_attribute( 'alt', $alt );
        if ( null !== $tags->get_attribute( 'title' ) ) $tags->set_attribute( 'title', $alt );
        return $tags->get_updated_html();
    }
    return $image;
}

/** 可變商品切換規格時，前端用的規格資料裡的圖片 alt。 */
function twshop_filter_variation_image_alt( $data, $product, $variation ) {
    if ( empty( $data['image'] ) || ! $variation instanceof WC_Product_Variation ) return $data;
    if ( (int) $variation->get_image_id() > 0 ) {
        $data['image']['alt'] = twshop_build_product_image_alt( $variation, 'variation' );
    }
    return $data;
}
