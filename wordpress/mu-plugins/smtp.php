<?php
/**
 * Plugin Name: CoPAI SMTP (mu)
 * Description: Forces SMTP delivery using settings from environment variables.
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

add_action('phpmailer_init', function ($mail) {
    $host = getenv('SMTP_HOST');
    if (!$host) {
        return;
    }

    $mail->isSMTP();
    $mail->Host = $host;
    $mail->Port = (int) (getenv('SMTP_PORT') ?: 587);

    $user = getenv('SMTP_USER');
    $pass = getenv('SMTP_PASS');
    if ($user !== false && $user !== '') {
        $mail->SMTPAuth = true;
        $mail->Username = $user;
        $mail->Password = $pass;
    } else {
        $mail->SMTPAuth = false;
    }

    $enc = strtolower((string) getenv('SMTP_ENCRYPTION'));
    if ($enc === 'tls' || $enc === 'ssl') {
        $mail->SMTPSecure = $enc;
    } else {
        $mail->SMTPSecure = '';
        $mail->SMTPAutoTLS = false;
    }

    $from = getenv('SMTP_FROM_EMAIL');
    $name = getenv('SMTP_FROM_NAME');
    if ($from) {
        $mail->From = $from;
    }
    if ($name) {
        $mail->FromName = $name;
    }
});

add_filter('wp_mail_from', function ($email) {
    $from = getenv('SMTP_FROM_EMAIL');
    return $from ?: $email;
}, 100);

add_filter('wp_mail_from_name', function ($name) {
    $from = getenv('SMTP_FROM_NAME');
    return $from ?: $name;
}, 100);
