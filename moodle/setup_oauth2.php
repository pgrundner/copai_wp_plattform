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
 * Idempotent setup of the WordPress OAuth2 issuer in Moodle.
 *
 * Reads OAUTH_CLIENT_ID, OAUTH_CLIENT_SECRET, WP_HOST from the environment.
 * Safe to run on every container start — checks for existing issuer by name.
 */

define('CLI_SCRIPT', true);
require_once(__DIR__ . '/config.php');
require_once($CFG->libdir . '/clilib.php');

use core\oauth2\api;
use core\oauth2\issuer;

// CLI scripts run unauthenticated; the OAuth2 API checks capabilities, so
// adopt the site admin's session before making API calls.
\core\session\manager::set_user(get_admin());

// Moodle's curl_security_helper blocks 127.0.0.0/8 + RFC1918 by default. In
// our self-hosted stack the OAuth2 host (e.g. community.localtest.me in dev)
// often resolves there, which breaks Moodle's endpoint-discovery probe. Clear
// the blocklist — the stack admin owns both endpoints, so this is safe.
set_config('curlsecurityblockedhosts', '');

$client_id     = (string) getenv('OAUTH_CLIENT_ID');
$client_secret = (string) getenv('OAUTH_CLIENT_SECRET');
$wp_host       = (string) getenv('WP_HOST');

if ($client_id === '' || $client_secret === '' || $wp_host === '') {
    fwrite(STDERR, "OAuth2 setup skipped — OAUTH_CLIENT_ID / OAUTH_CLIENT_SECRET / WP_HOST not set.\n");
    exit(0);
}

$issuer_name = 'CoPAI Community';
$base_url    = "https://$wp_host";

$issuer = null;
foreach (api::get_all_issuers() as $existing) {
    if ($existing->get('name') === $issuer_name) {
        $issuer = $existing;
        echo "OAuth2 issuer '$issuer_name' exists (id=" . $issuer->get('id') . ").\n";
        break;
    }
}

if (!$issuer) {
    echo "Creating OAuth2 issuer '$issuer_name'...\n";
    $record = (object) [
        'name'               => $issuer_name,
        'image'              => '',
        'baseurl'            => $base_url,
        'clientid'           => $client_id,
        'clientsecret'       => $client_secret,
        'loginscopes'        => 'openid email profile',
        'loginscopesoffline' => 'openid email profile',
        'showonloginpage'    => issuer::EVERYWHERE,
        'servicetype'        => '',
        'logoutsupport'      => 0,
    ];
    // Bypass api::create_issuer() — it always triggers OIDC discovery against
    // baseurl, which fails in dev (LE staging cert doesn't match the hostname).
    // We set all endpoints explicitly below, so discovery is redundant anyway.
    $issuer = new issuer(0, $record);
    $issuer->create();
    echo "Issuer created with id=" . $issuer->get('id') . "\n";
}

$issuer_id = $issuer->get('id');

// Skip Moodle's own email confirmation for OAuth2 sign-ups: the user's identity
// is already verified by the OAuth2 provider (WordPress). Otherwise Moodle sends
// a confirmation link pointing at /login/confirm.php (an auth_email-only
// endpoint) which then errors with "user confirmation is not enabled".
if ($issuer->get('requireconfirmation')) {
    $issuer->set('requireconfirmation', 0);
    $issuer->save();
    echo "Disabled requireconfirmation on issuer.\n";
}

// Confirm any existing OAuth2 users still sitting on confirmed=0 from the
// pre-fix flow so they're not locked out.
global $DB;
if ($DB->count_records_select('user', 'auth = ? AND confirmed = 0', ['oauth2']) > 0) {
    $DB->set_field_select('user', 'confirmed', 1, 'auth = ? AND confirmed = 0', ['oauth2']);
    echo "Auto-confirmed pre-existing unconfirmed OAuth2 users.\n";
}

// Ensure endpoints exist (defensive — fills any missing ones on every run).
//
// authorize is browser-facing → must use the public HTTPS host so the user's
// browser can follow the redirect.
// token + userinfo are server-to-server → go through the internal docker
// network using the wp-nginx service name over HTTP. That sidesteps the
// self-signed/default Traefik cert that's served in dev/firewall setups
// where LE staging can't validate localtest.me.
$bc_url = (string) (getenv('OAUTH_BACKCHANNEL_URL') ?: 'http://wp-nginx');
$desired_endpoints = [
    'authorization_endpoint' => "$base_url/wp-json/copai-oauth/v1/authorize",
    'token_endpoint'         => "$bc_url/wp-json/copai-oauth/v1/token",
    'userinfo_endpoint'      => "$bc_url/wp-json/copai-oauth/v1/userinfo",
    'discovery_endpoint'     => "$base_url/wp-json/copai-oauth/v1/.well-known/openid-configuration",
];
$existing_endpoints = [];
foreach (api::get_endpoints($issuer) as $e) {
    $existing_endpoints[$e->get('name')] = $e;
}
$admin_id = get_admin()->id;
$now      = time();
foreach ($desired_endpoints as $name => $url) {
    if (isset($existing_endpoints[$name])) {
        continue;
    }
    if (strpos($url, 'http://') === 0) {
        // Moodle's endpoint::validate_url rejects non-HTTPS URLs. For internal
        // back-channel URLs (wp-nginx service name in the docker network) we
        // insert directly to bypass that check — the URL is reachable only
        // from inside the compose stack, no MITM surface.
        $DB->insert_record('oauth2_endpoint', (object) [
            'issuerid'     => $issuer_id,
            'name'         => $name,
            'url'          => $url,
            'timecreated'  => $now,
            'timemodified' => $now,
            'usermodified' => $admin_id,
        ]);
    } else {
        api::create_endpoint((object) [
            'issuerid' => $issuer_id,
            'name'     => $name,
            'url'      => $url,
        ]);
    }
    echo "  + endpoint $name -> $url\n";
}

// Ensure user field mappings exist (defensive).
$desired_mappings = [
    'email'              => 'email',
    'given_name'         => 'firstname',
    'family_name'        => 'lastname',
    'preferred_username' => 'username',
];
$existing_mappings = [];
foreach (api::get_user_field_mappings($issuer) as $m) {
    $existing_mappings[$m->get('externalfield')] = $m;
}
foreach ($desired_mappings as $external => $internal) {
    if (isset($existing_mappings[$external])) {
        continue;
    }
    api::create_user_field_mapping((object) [
        'issuerid'      => $issuer_id,
        'externalfield' => $external,
        'internalfield' => $internal,
    ]);
    echo "  + mapping $external -> $internal\n";
}

// Enable the oauth2 auth plugin (alongside whatever else is enabled).
$auths = array_filter(explode(',', (string) get_config('core', 'auth')));
if (!in_array('oauth2', $auths, true)) {
    $auths[] = 'oauth2';
    set_config('auth', implode(',', $auths));
    echo "Enabled 'oauth2' authentication plugin.\n";
} else {
    echo "'oauth2' authentication plugin already enabled.\n";
}

// Purge caches so the new auth + issuer are picked up immediately.
purge_all_caches();
echo "OAuth2 setup done.\n";
