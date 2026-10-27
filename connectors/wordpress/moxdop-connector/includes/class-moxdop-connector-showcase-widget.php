<?php

use Elementor\Controls_Manager;
use Elementor\Core\Kits\Documents\Tabs\Global_Colors;
use Elementor\Core\Kits\Documents\Tabs\Global_Typography;
use Elementor\Group_Control_Typography;
use Elementor\Plugin;
use Elementor\Widget_Base;

defined('ABSPATH') || exit;

/**
 * 1.28.0: Elementor widget "MoxDOP Sizden gelenler" (Sorular ve yorumlar). One widget, three views: the questions of
 * the page it is on, the Google reviews, or only the Google rating badge. Colours and fonts start from the site's
 * global settings and can be changed on the widget's Style tab.
 */
final class MoxDOP_Connector_Showcase_Widget extends Widget_Base
{
    public function get_name()
    {
        return 'moxdop-showcase';
    }

    public function get_title()
    {
        return 'MoxDOP Sizden gelenler';
    }

    public function get_icon()
    {
        return 'eicon-testimonial';
    }

    public function get_categories()
    {
        return ['general'];
    }

    public function get_keywords()
    {
        return ['soru', 'cevap', 'yorum', 'google', 'moxdop', 'sss'];
    }

    protected function register_controls()
    {
        $this->start_controls_section('content', ['label' => 'İçerik']);
        $this->add_control('view', ['label' => 'Gösterilecek', 'type' => Controls_Manager::SELECT, 'default' => 'questions',
            'options' => ['questions' => 'Sorular (bu sayfanın)', 'reviews' => 'Google yorumları', 'badge' => 'Google puanı rozeti']]);
        $this->add_control('count', ['label' => 'Gösterilen sayı', 'type' => Controls_Manager::NUMBER, 'min' => 1, 'max' => 50, 'default' => 10,
            'condition' => ['view!' => 'badge']]);
        $this->end_controls_section();

        $this->start_controls_section('style', ['label' => 'Görünüm', 'tab' => Controls_Manager::TAB_STYLE]);
        $this->add_control('accent', ['label' => 'Vurgu rengi', 'type' => Controls_Manager::COLOR,
            'global' => ['default' => Global_Colors::COLOR_PRIMARY],
            'selectors' => ['{{WRAPPER}} .mxs' => '--mxs-accent: {{VALUE}};']]);
        $this->add_control('text', ['label' => 'Yazı rengi', 'type' => Controls_Manager::COLOR,
            'global' => ['default' => Global_Colors::COLOR_TEXT],
            'selectors' => ['{{WRAPPER}} .mxs' => '--mxs-text: {{VALUE}};']]);
        $this->add_group_control(Group_Control_Typography::get_type(), ['name' => 'title_typography', 'label' => 'Başlık yazı tipi',
            'global' => ['default' => Global_Typography::TYPOGRAPHY_PRIMARY], 'selector' => '{{WRAPPER}} .mxs-title, {{WRAPPER}} .mxs-q']);
        $this->add_group_control(Group_Control_Typography::get_type(), ['name' => 'body_typography', 'label' => 'Metin yazı tipi',
            'global' => ['default' => Global_Typography::TYPOGRAPHY_TEXT], 'selector' => '{{WRAPPER}} .mxs']);
        $this->end_controls_section();
    }

    protected function render()
    {
        $settings = $this->get_settings_for_display();
        $count = max(1, min(50, (int) ($settings['count'] ?? 10)));
        $view = (string) ($settings['view'] ?? 'questions');
        if ($view === 'questions') {
            $html = (new MoxDOP_Connector_Showcase)->shortcode_questions(['sayi' => $count]);
        } else {
            $html = MoxDOP_Connector_Showcase::render_reviews($count, $view === 'badge' ? 'badge' : 'list');
        }
        if ($html === '' && Plugin::$instance->editor->is_edit_mode()) {
            $html = '<p style="padding:16px;border:1px dashed #ccc;border-radius:8px">MoxDOP Sizden gelenler: '
                .($view === 'questions' ? 'bu sayfa Ayarlar › Sorular ve yorumlar ekranında seçili değil ya da hizmet MoxDOP\'ta kapalı.' : 'MoxDOP henüz yorum göndermedi (markada "Sitede yorum ve soru göster" kapalı olabilir).').'</p>';
        }
        echo $html; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
    }
}
