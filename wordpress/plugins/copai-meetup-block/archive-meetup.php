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
