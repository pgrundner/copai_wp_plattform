<?php
/**
 * Plugin Name: CoPAI Meetup Block
 * Description: Gutenberg-Block, der kommende oder vergangene Meetups als Liste anzeigt.
 * Version:     0.1.0
 * Requires PHP: 8.0
 */

defined('ABSPATH') || exit;

add_action('init', function () {
    register_block_type(__DIR__);
});
