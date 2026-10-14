<?php
/**
 * 團購頁 Elementor 小工具。只在 elementor/widgets/register 時載入，Elementor 未安裝時不會讀到本檔。
 * 內容依團購頁網址帶入團購主資料；在編輯器中以第一位啟用中的團購主示範。
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;
use Elementor\Widget_Base;

abstract class TWShop_GB_Widget_Base extends Widget_Base {
    public function get_categories() {
        return array( 'twshop-group-buy' );
    }

    public function get_keywords() {
        return array( '團購', 'group', 'buy', 'twshop' );
    }

    /** 編輯時沒有任何啟用中的團購主可示範：顯示提示；前台不輸出。 */
    protected function missing_leader_notice() {
        if ( twshop_group_buy_is_elementor_editing() ) {
            echo '<p class="twshop-gb__empty">尚無啟用中的團購主，請先在「終極電商 → 團購」新增團購主再預覽。</p>';
        }
    }

    protected function register_text_style_controls( $selector ) {
        $this->start_controls_section( 'section_style', array( 'label' => '樣式', 'tab' => Controls_Manager::TAB_STYLE ) );
        $this->add_responsive_control( 'align', array(
            'label'     => '對齊',
            'type'      => Controls_Manager::CHOOSE,
            'options'   => array(
                'left'   => array( 'title' => '靠左', 'icon' => 'eicon-text-align-left' ),
                'center' => array( 'title' => '置中', 'icon' => 'eicon-text-align-center' ),
                'right'  => array( 'title' => '靠右', 'icon' => 'eicon-text-align-right' ),
            ),
            'selectors' => array( '{{WRAPPER}} ' . $selector => 'text-align: {{VALUE}};' ),
        ) );
        $this->add_control( 'color', array(
            'label'     => '文字顏色',
            'type'      => Controls_Manager::COLOR,
            'selectors' => array( '{{WRAPPER}} ' . $selector => 'color: {{VALUE}};' ),
        ) );
        $this->add_group_control( Group_Control_Typography::get_type(), array( 'name' => 'typography', 'selector' => '{{WRAPPER}} ' . $selector ) );
        $this->end_controls_section();
    }
}

class TWShop_GB_Widget_Title extends TWShop_GB_Widget_Base {
    public function get_name() { return 'twshop-gb-title'; }
    public function get_title() { return '團購名稱'; }
    public function get_icon() { return 'eicon-t-letter'; }

    protected function register_controls() {
        $this->start_controls_section( 'section_content', array( 'label' => '團購名稱' ) );
        $this->add_control( 'tag', array(
            'label'   => 'HTML 標籤',
            'type'    => Controls_Manager::SELECT,
            'default' => 'h1',
            'options' => array( 'h1' => 'H1', 'h2' => 'H2', 'h3' => 'H3', 'h4' => 'H4', 'p' => 'p', 'div' => 'div' ),
        ) );
        $this->end_controls_section();
        $this->register_text_style_controls( '.twshop-gb__title' );
    }

    protected function render() {
        $leader = twshop_group_buy_widget_leader();
        if ( ! $leader ) { $this->missing_leader_notice(); return; }
        $tag = in_array( $this->get_settings_for_display( 'tag' ), array( 'h1', 'h2', 'h3', 'h4', 'p', 'div' ), true ) ? $this->get_settings_for_display( 'tag' ) : 'h1';
        printf( '<%1$s class="twshop-gb__title">%2$s</%1$s>', tag_escape( $tag ), esc_html( twshop_group_buy_leader_title( $leader ) ) );
    }
}

class TWShop_GB_Widget_Intro extends TWShop_GB_Widget_Base {
    public function get_name() { return 'twshop-gb-intro'; }
    public function get_title() { return '團購簡介'; }
    public function get_icon() { return 'eicon-text'; }

    protected function register_controls() {
        $this->start_controls_section( 'section_content', array( 'label' => '團購簡介' ) );
        $this->add_control( 'note', array(
            'type' => Controls_Manager::RAW_HTML,
            'raw'  => '內容取自後台團購主設定的「簡介」，未填時不輸出。',
        ) );
        $this->end_controls_section();
        $this->register_text_style_controls( '.twshop-gb__intro' );
    }

    protected function render() {
        $leader = twshop_group_buy_widget_leader();
        if ( ! $leader ) { $this->missing_leader_notice(); return; }
        $intro = trim( (string) $leader['intro'] );
        if ( '' === $intro ) {
            if ( twshop_group_buy_is_elementor_editing() ) echo '<div class="twshop-gb__intro"><p>（這位團購主尚未填寫簡介）</p></div>';
            return;
        }
        echo '<div class="twshop-gb__intro">' . wp_kses_post( wpautop( esc_html( $intro ) ) ) . '</div>';
    }
}

class TWShop_GB_Widget_Products extends TWShop_GB_Widget_Base {
    public function get_name() { return 'twshop-gb-products'; }
    public function get_title() { return '團購商品'; }
    public function get_icon() { return 'eicon-products'; }

    public function get_script_depends() {
        return array( 'wc-add-to-cart-variation' );
    }

    protected function register_controls() {
        $this->start_controls_section( 'section_content', array( 'label' => '團購商品' ) );
        $this->add_responsive_control( 'columns', array(
            'label'          => '每排商品數',
            'type'           => Controls_Manager::SELECT,
            'default'        => '4',
            'tablet_default' => '2',
            'mobile_default' => '1',
            'options'        => array( '1' => '1', '2' => '2', '3' => '3', '4' => '4', '5' => '5', '6' => '6' ),
            'selectors'      => array( '{{WRAPPER}} .twshop-gb__grid' => 'grid-template-columns: repeat({{VALUE}}, minmax(0, 1fr));' ),
        ) );
        $this->add_responsive_control( 'gap', array(
            'label'      => '間距',
            'type'       => Controls_Manager::SLIDER,
            'size_units' => array( 'px' ),
            'range'      => array( 'px' => array( 'min' => 0, 'max' => 80 ) ),
            'selectors'  => array( '{{WRAPPER}} .twshop-gb__grid' => 'gap: {{SIZE}}{{UNIT}};' ),
        ) );
        $this->add_control( 'show_desc', array(
            'label'        => '顯示簡短描述',
            'type'         => Controls_Manager::SWITCHER,
            'default'      => 'yes',
            'return_value' => 'yes',
        ) );
        $this->add_control( 'empty_text', array(
            'label'   => '沒有商品時顯示',
            'type'    => Controls_Manager::TEXT,
            'default' => twshop_group_buy_t( '目前沒有團購商品。' ),
        ) );
        $this->end_controls_section();

        $this->start_controls_section( 'section_style', array( 'label' => '商品卡', 'tab' => Controls_Manager::TAB_STYLE ) );
        $this->add_control( 'card_bg', array(
            'label'     => '背景色',
            'type'      => Controls_Manager::COLOR,
            'selectors' => array( '{{WRAPPER}} .twshop-gb__card' => 'background: {{VALUE}};' ),
        ) );
        $this->add_control( 'card_border', array(
            'label'     => '邊框顏色',
            'type'      => Controls_Manager::COLOR,
            'selectors' => array( '{{WRAPPER}} .twshop-gb__card' => 'border-color: {{VALUE}};' ),
        ) );
        $this->add_control( 'card_radius', array(
            'label'      => '圓角',
            'type'       => Controls_Manager::SLIDER,
            'size_units' => array( 'px' ),
            'range'      => array( 'px' => array( 'min' => 0, 'max' => 40 ) ),
            'selectors'  => array( '{{WRAPPER}} .twshop-gb__card' => 'border-radius: {{SIZE}}{{UNIT}};' ),
        ) );
        $this->add_group_control( Group_Control_Typography::get_type(), array( 'name' => 'name_typography', 'label' => '商品名稱字型', 'selector' => '{{WRAPPER}} .twshop-gb__name' ) );
        $this->end_controls_section();
    }

    protected function render() {
        $leader = twshop_group_buy_widget_leader();
        if ( ! $leader ) { $this->missing_leader_notice(); return; }
        $settings = $this->get_settings_for_display();
        // 沒改過的預設文字（含改名前存下的舊預設）跟著目前的自訂稱呼
        $empty = trim( (string) ( $settings['empty_text'] ?? '' ) );
        $empty = in_array( $empty, array( '', '目前沒有團購商品。', twshop_group_buy_t( '目前沒有團購商品。' ) ), true ) ? null : $empty;
        $html = twshop_group_buy_render_products( $leader, $empty );
        if ( 'yes' !== ( $settings['show_desc'] ?? 'yes' ) ) {
            $html = '<div class="twshop-gb--no-desc">' . $html . '</div>';
        }
        echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- twshop_group_buy_render_products() 內已跳脫。
    }
}
