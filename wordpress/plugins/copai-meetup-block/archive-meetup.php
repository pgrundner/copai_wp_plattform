<?php
/**
 * Classic-theme archive template for the meetup CPT.
 * For block themes the registered block template is used instead.
 */

defined('ABSPATH') || exit;

get_header();
?>
<main id="primary" class="site-main copai-meetup-archive">
    <header class="page-header">
        <h1 class="page-title copai-meetup-archive__title"><?php post_type_archive_title(); ?></h1>
    </header>
    <?php
    echo render_block([
        'blockName'    => 'copai/meetup-list',
        'attrs'        => ['count' => 12, 'scope' => 'upcoming'],
        'innerBlocks'  => [],
        'innerHTML'    => '',
        'innerContent' => [],
    ]);
    ?>
</main>
<?php
get_footer();
