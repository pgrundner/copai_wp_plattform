<?php
/**
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
    $issuer = api::create_issuer($record);
    echo "Issuer created with id=" . $issuer->get('id') . "\n";
}

$issuer_id = $issuer->get('id');

// Ensure endpoints exist (defensive — fills any missing ones on every run).
$desired_endpoints = [
    'authorization_endpoint' => "$base_url/wp-json/copai-oauth/v1/authorize",
    'token_endpoint'         => "$base_url/wp-json/copai-oauth/v1/token",
    'userinfo_endpoint'      => "$base_url/wp-json/copai-oauth/v1/userinfo",
    'discovery_endpoint'     => "$base_url/wp-json/copai-oauth/v1/.well-known/openid-configuration",
];
$existing_endpoints = [];
foreach (api::get_endpoints($issuer) as $e) {
    $existing_endpoints[$e->get('name')] = $e;
}
foreach ($desired_endpoints as $name => $url) {
    if (isset($existing_endpoints[$name])) {
        continue;
    }
    api::create_endpoint((object) [
        'issuerid' => $issuer_id,
        'name'     => $name,
        'url'      => $url,
    ]);
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
