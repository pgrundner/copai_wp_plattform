<?php
/**
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
 *
 * ---
 *
 * Idempotent setup of Moodle's outgoing-mail (SMTP) configuration.
 *
 * Reads SMTP_HOST / SMTP_PORT / SMTP_USER / SMTP_PASS / SMTP_ENCRYPTION /
 * SMTP_FROM_EMAIL / SMTP_FROM_NAME from the environment and writes them into
 * Moodle's mainstream config keys. Safe to run on every container start —
 * set_config() is an upsert.
 */

define('CLI_SCRIPT', true);
require_once(__DIR__ . '/config.php');

$host = (string) getenv('SMTP_HOST');
if ($host === '') {
    fwrite(STDERR, "SMTP setup skipped — SMTP_HOST not set.\n");
    exit(0);
}

$port       = (string) (getenv('SMTP_PORT') ?: '587');
$user       = (string) getenv('SMTP_USER');
$pass       = (string) getenv('SMTP_PASS');
$encryption = strtolower((string) (getenv('SMTP_ENCRYPTION') ?: 'tls'));
$from_email = (string) getenv('SMTP_FROM_EMAIL');
$from_name  = (string) getenv('SMTP_FROM_NAME');

// Moodle expects: '' (none), 'ssl', 'tls'. STARTTLS = 'tls', implicit = 'ssl'.
$smtpsecure = match ($encryption) {
    'ssl'   => 'ssl',
    'none', '' => '',
    default => 'tls',
};

$values = [
    'smtphosts'      => "$host:$port",
    'smtpsecure'     => $smtpsecure,
    'smtpauthtype'   => 'LOGIN',
    'smtpuser'       => $user,
    'smtppass'       => $pass,
    'smtpmaxbulk'    => '1',
];

if ($from_email !== '') {
    $values['noreplyaddress'] = $from_email;
}
if ($from_name !== '') {
    // Moodle uses 'supportname' as the From-name fallback for site mails.
    $values['supportname'] = $from_name;
}

$changed = 0;
foreach ($values as $name => $value) {
    $current = get_config('moodle', $name);
    if ((string) $current !== (string) $value) {
        set_config($name, $value);
        $changed++;
    }
}

if ($changed > 0) {
    echo "SMTP config updated ($changed values).\n";
    purge_all_caches();
} else {
    echo "SMTP config already up-to-date.\n";
}
