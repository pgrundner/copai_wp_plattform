<?php
/**
 * CoPAI Platform
 * https://copai.community
 *
 * Developed by Murbit GmbH as part of the Erasmus+ project:
 *
 * Community of Practice AI
 * Project No.: 2023-2-AT01-KA210-VET-000169864
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
 *
 * ---
 *
 * Server render for copai/meetup-list block.
 *
 * @var array     $attributes Block attributes (count, scope).
 * @var string    $content    Inner block content (unused).
 * @var WP_Block  $block      Block instance.
 */

defined('ABSPATH') || exit;

$count = isset($attributes['count']) ? (int) $attributes['count'] : 5;
$scope = isset($attributes['scope']) ? (string) $attributes['scope'] : 'upcoming';

if ($count < 1)  { $count = 1; }
if ($count > 50) { $count = 50; }
if (!in_array($scope, ['upcoming', 'past'], true)) {
    $scope = 'upcoming';
}

$today = current_time('Y-m-d');

$query = new WP_Query([
    'post_type'      => 'meetup',
    'post_status'    => 'publish',
    'posts_per_page' => $count,
    'meta_key'       => 'cmr_event_date',
    'orderby'        => 'meta_value',
    'order'          => $scope === 'past' ? 'DESC' : 'ASC',
    'meta_query'     => [
        [
            'key'     => 'cmr_event_date',
            'value'   => $today,
            'compare' => $scope === 'past' ? '<' : '>=',
            'type'    => 'DATE',
        ],
    ],
    'no_found_rows'  => true,
]);

$wrapper = get_block_wrapper_attributes(['class' => 'copai-meetup-list']);

if (!$query->have_posts()) {
    $empty_msg = $scope === 'past'
        ? __('Keine vergangenen Meetups vorhanden.', 'copai-meetup-block')
        : __('Aktuell sind keine Meetups geplant.', 'copai-meetup-block');
    // Render as a single-item-style container so the editor preview still shows wrapper styling.
    printf(
        '<div %s style="display:block;"><p class="copai-meetup-list__empty">%s</p></div>',
        $wrapper,
        esc_html($empty_msg)
    );
    return;
}

// Inline SVG placeholder used when no featured image is set.
$placeholder_svg = '<span class="copai-meetup-list__placeholder" aria-hidden="true">'
    . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" '
    . 'stroke-linecap="round" stroke-linejoin="round">'
    . '<rect x="3" y="4" width="18" height="17" rx="2" />'
    . '<line x1="3" y1="9" x2="21" y2="9" />'
    . '<line x1="8" y1="2" x2="8" y2="6" />'
    . '<line x1="16" y1="2" x2="16" y2="6" />'
    . '</svg>'
    . '</span>';

echo '<ul ' . $wrapper . '>';
while ($query->have_posts()) {
    $query->the_post();
    $id       = get_the_ID();
    $date     = get_post_meta($id, 'cmr_event_date', true);
    $time     = get_post_meta($id, 'cmr_event_time', true);
    $location = get_post_meta($id, 'cmr_event_location', true);
    $permalink = get_permalink();
    $title    = get_the_title();

    $when = '';
    $when_iso = '';
    if ($date) {
        $ts = strtotime(trim($date . ($time ? ' ' . $time : '')));
        if ($ts) {
            $when = $time
                ? date_i18n('d.m.Y, H:i', $ts) . ' ' . __('Uhr', 'copai-meetup-block')
                : date_i18n('d.m.Y', $ts);
            $when_iso = $time ? $date . 'T' . $time : $date;
        }
    }

    $thumb = has_post_thumbnail($id)
        ? get_the_post_thumbnail($id, 'medium_large', ['loading' => 'lazy', 'alt' => esc_attr($title)])
        : $placeholder_svg;

    echo '<li class="copai-meetup-list__item">';

    echo '<a class="copai-meetup-list__media" href="' . esc_url($permalink) . '" aria-label="' . esc_attr($title) . '">';
    echo $thumb; // get_the_post_thumbnail returns escaped HTML; placeholder is our own static SVG
    echo '</a>';

    echo '<div class="copai-meetup-list__body">';
    echo '<a class="copai-meetup-list__title" href="' . esc_url($permalink) . '">' . esc_html($title) . '</a>';
    if ($when) {
        echo '<time class="copai-meetup-list__when" datetime="' . esc_attr($when_iso) . '">' . esc_html($when) . '</time>';
    }
    if ($location) {
        echo '<span class="copai-meetup-list__location">' . esc_html($location) . '</span>';
    }
    echo '</div>';

    echo '</li>';
}
wp_reset_postdata();
echo '</ul>';
