<?php
/**
 * Plugin Name: CoPAI Meetup Block
 * Description: Gutenberg-Block "Meetup-Liste" + Default-Archiv für den Meetup-CPT.
 * Version:     0.2.0
 * Requires PHP: 8.0
 *
 * CoPAI Platform
 * https://copai.community
 *
 * Developed by Murbit GmbH as part of the Erasmus+ project:
 *
 * Community of Practice AI
 * Project No.: KA210-VET-4603C73C
 *
 * Funded by the European Union. Views and opinions expressed are however
 * those of the author(s) only and do not necessarily reflect those of the
 * European Union or the European Education and Culture Executive Agency (EACEA).
 * Neither the European Union nor EACEA can be held responsible for them.
 *
 * Copyright (c) 2025 Murbit GmbH
 *
 * Licensed under the MIT License.
 * See LICENSE file for details.
 */

defined('ABSPATH') || exit;

/**
 * Register the block from block.json.
 */
add_action('init', function () {
    register_block_type(__DIR__);
});

/**
 * Filter the meetup archive's main query: only upcoming, sorted by event date ASC.
 * Use the `copai_meetup_archive_upcoming_only` filter to disable.
 */
add_action('pre_get_posts', function (WP_Query $query) {
    if (is_admin() || !$query->is_main_query()) {
        return;
    }
    if (!$query->is_post_type_archive('meetup')) {
        return;
    }
    if (!apply_filters('copai_meetup_archive_upcoming_only', true)) {
        return;
    }
    $query->set('meta_key', 'cmr_event_date');
    $query->set('orderby', 'meta_value');
    $query->set('order', 'ASC');
    $query->set('meta_query', [
        [
            'key'     => 'cmr_event_date',
            'value'   => current_time('Y-m-d'),
            'compare' => '>=',
            'type'    => 'DATE',
        ],
    ]);
});

/**
 * Block-theme template: render the meetup archive using our grid block.
 * Hooked to `init` (after `register_block_template` is available).
 */
add_action('init', function () {
    if (!function_exists('register_block_template')) {
        return;
    }
    register_block_template('copai//archive-meetup', [
        'title'       => __('Meetups (kommende)', 'copai-meetup-block'),
        'description' => __('Zeigt kommende Meetups als Grid.', 'copai-meetup-block'),
        'post_types'  => ['meetup'],
        'content'     => '<!-- wp:template-part {"slug":"header","tagName":"header"} /-->'
            . '<!-- wp:group {"tagName":"main","layout":{"type":"constrained"}} -->'
            . '<main class="wp-block-group">'
            . '<!-- wp:heading {"level":1,"className":"copai-meetup-archive__title"} -->'
            . '<h1 class="wp-block-heading copai-meetup-archive__title">' . esc_html__('Meetups', 'copai-meetup-block') . '</h1>'
            . '<!-- /wp:heading -->'
            . '<!-- wp:copai/meetup-list {"count":12,"scope":"upcoming"} /-->'
            . '</main>'
            . '<!-- /wp:group -->'
            . '<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->',
    ]);
});

/**
 * Classic-theme fallback: serve our PHP archive template.
 * No effect on block themes (they use the block template registered above).
 */
add_filter('template_include', function ($template) {
    if (function_exists('wp_is_block_theme') && wp_is_block_theme()) {
        return $template;
    }
    if (is_post_type_archive('meetup')) {
        $custom = __DIR__ . '/archive-meetup.php';
        if (file_exists($custom)) {
            return $custom;
        }
    }
    return $template;
});
