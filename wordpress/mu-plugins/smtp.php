<?php
/**
 * Plugin Name: CoPAI SMTP (mu)
 * Description: Forces SMTP delivery using settings from environment variables.
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
