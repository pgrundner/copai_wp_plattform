<?php
/**
 * Server render for copai/meetup-list block.
 *
 * @var array $attributes  Block attributes (count, scope).
 * @var string $content    Inner block content (unused).
 * @var WP_Block $block    Block instance.
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
    printf('<div %s><p>%s</p></div>', $wrapper, esc_html($empty_msg));
    return;
}

echo '<ul ' . $wrapper . '>';
while ($query->have_posts()) {
    $query->the_post();
    $id       = get_the_ID();
    $date     = get_post_meta($id, 'cmr_event_date', true);
    $time     = get_post_meta($id, 'cmr_event_time', true);
    $location = get_post_meta($id, 'cmr_event_location', true);

    $when = '';
    if ($date) {
        $ts = strtotime(trim($date . ($time ? ' ' . $time : '')));
        if ($ts) {
            $when = $time
                ? date_i18n('d.m.Y, H:i', $ts) . ' ' . __('Uhr', 'copai-meetup-block')
                : date_i18n('d.m.Y', $ts);
        }
    }

    echo '<li class="copai-meetup-list__item">';
    echo '<a class="copai-meetup-list__title" href="' . esc_url(get_permalink()) . '">' . esc_html(get_the_title()) . '</a>';
    if ($when) {
        echo ' — <time class="copai-meetup-list__when" datetime="' . esc_attr($date) . '">' . esc_html($when) . '</time>';
    }
    if ($location) {
        echo ' · <span class="copai-meetup-list__location">' . esc_html($location) . '</span>';
    }
    echo '</li>';
}
wp_reset_postdata();
echo '</ul>';
