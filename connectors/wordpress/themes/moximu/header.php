<?php defined('ABSPATH') || exit; ?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<?php wp_head(); ?>
</head>
<body<?php echo is_admin_bar_showing() ? ' class="admin-bar"' : ''; ?>>
<?php wp_body_open(); ?>
<div id="__next"><div class="sc-dNezTh kePwoI"></div><div class="sc-bczRLJ bHtZdW" id="layout-container">
<?php get_template_part('parts/ust_bar'); ?>
<?php get_template_part('parts/ust_menu'); ?>
<?php get_template_part('parts/ust_mobil'); ?>
<?php get_template_part('parts/overlay'); ?>
<main class="sc-gsnTZi eHcevq">
