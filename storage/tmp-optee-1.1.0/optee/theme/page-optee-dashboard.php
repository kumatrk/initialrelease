<?php
/**
 * Template Name: Optee Dashboard
 *
 * Custom page template for the dashboard page.
 * Simply renders the page content (which contains the [optee_dashboard] shortcode).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

get_header();

if ( have_posts() ) {
    while ( have_posts() ) {
        the_post();
        the_content();
    }
}

get_footer();
