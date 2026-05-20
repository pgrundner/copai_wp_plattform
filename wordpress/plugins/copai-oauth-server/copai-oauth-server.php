<?php
/**
 * Plugin Name: CoPAI OAuth2 Server
 * Description: Minimal OAuth2 Authorization-Code provider so external services (Moodle) can SSO against WordPress users.
 * Version:     0.1.0
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
 * Configuration read from environment (set in compose.yml).
 *  OAUTH_CLIENT_ID, OAUTH_CLIENT_SECRET   — credentials for the single registered client (Moodle).
 *  MOODLE_HOST                            — used to compute the allowed redirect URI.
 */
function copai_oauth_config(): array {
    return [
        'client_id'     => (string) getenv('OAUTH_CLIENT_ID') ?: 'moodle',
        'client_secret' => (string) getenv('OAUTH_CLIENT_SECRET'),
        'redirect_uri'  => 'https://' . ((string) getenv('MOODLE_HOST')) . '/admin/oauth2callback.php',
    ];
}

const COPAI_OAUTH_NS         = 'copai-oauth/v1';
const COPAI_OAUTH_CODE_TTL   = 60;    // 1 minute
const COPAI_OAUTH_TOKEN_TTL  = 3600;  // 1 hour

add_action('rest_api_init', function () {
    $routes = [
        'authorize'                       => ['GET',  'copai_oauth_authorize'],
        'token'                           => ['POST', 'copai_oauth_token'],
        'userinfo'                        => ['GET',  'copai_oauth_userinfo'],
        '.well-known/openid-configuration'=> ['GET',  'copai_oauth_discovery'],
    ];
    foreach ($routes as $path => [$method, $cb]) {
        register_rest_route(COPAI_OAUTH_NS, '/' . $path, [
            'methods'             => $method,
            'callback'            => $cb,
            'permission_callback' => '__return_true',
        ]);
    }
});

/**
 * GET /authorize
 * Standard OAuth2 authorization endpoint.
 */
function copai_oauth_authorize(WP_REST_Request $req) {
    $cfg = copai_oauth_config();

    // WP REST's default cookie auth requires an X-WP-Nonce header; the user
    // here arrives via a top-level browser redirect from wp-login.php, so
    // there's no nonce, and is_user_logged_in() returns false even though
    // the logged_in cookie validates fine. Validate the cookie directly and
    // adopt the user for the rest of this request.
    if (!is_user_logged_in() && !empty($_COOKIE[LOGGED_IN_COOKIE])) {
        $uid = wp_validate_auth_cookie($_COOKIE[LOGGED_IN_COOKIE], 'logged_in');
        if ($uid) {
            wp_set_current_user($uid);
        }
    }

    $client_id     = (string) $req->get_param('client_id');
    $redirect_uri  = (string) $req->get_param('redirect_uri');
    $response_type = (string) $req->get_param('response_type');
    $state         = (string) $req->get_param('state');
    $scope         = (string) ($req->get_param('scope') ?: 'openid email profile');

    if ($response_type !== 'code') {
        return new WP_REST_Response(['error' => 'unsupported_response_type'], 400);
    }
    if (!hash_equals($cfg['client_id'], $client_id)) {
        return new WP_REST_Response(['error' => 'invalid_client'], 400);
    }
    if ($cfg['redirect_uri'] && !hash_equals($cfg['redirect_uri'], $redirect_uri)) {
        return new WP_REST_Response(['error' => 'invalid_redirect_uri'], 400);
    }

    if (!is_user_logged_in()) {
        // Bounce through wp-login then come back to this exact URL.
        $current = rest_url(COPAI_OAUTH_NS . '/authorize');
        if (!empty($_SERVER['QUERY_STRING'])) {
            $current .= '?' . $_SERVER['QUERY_STRING'];
        }
        wp_safe_redirect(wp_login_url($current));
        exit;
    }

    // Issue a one-shot code.
    $code = bin2hex(random_bytes(16));
    set_transient('copai_oauth_code_' . $code, [
        'user_id'      => get_current_user_id(),
        'client_id'    => $client_id,
        'scope'        => $scope,
        'redirect_uri' => $redirect_uri,
    ], COPAI_OAUTH_CODE_TTL);

    $params = ['code' => $code];
    if ($state !== '') {
        $params['state'] = $state;
    }
    wp_redirect($redirect_uri . '?' . http_build_query($params));
    exit;
}

/**
 * POST /token
 * Exchanges authorization code for access token.
 */
function copai_oauth_token(WP_REST_Request $req) {
    $cfg = copai_oauth_config();

    $grant_type    = (string) $req->get_param('grant_type');
    $code          = (string) $req->get_param('code');
    $redirect_uri  = (string) $req->get_param('redirect_uri');
    $client_id     = (string) $req->get_param('client_id');
    $client_secret = (string) $req->get_param('client_secret');

    // Support HTTP Basic auth too.
    if ($client_id === '' && !empty($_SERVER['PHP_AUTH_USER'])) {
        $client_id     = (string) $_SERVER['PHP_AUTH_USER'];
        $client_secret = (string) ($_SERVER['PHP_AUTH_PW'] ?? '');
    }

    if ($grant_type !== 'authorization_code') {
        return new WP_REST_Response(['error' => 'unsupported_grant_type'], 400);
    }
    if (!hash_equals($cfg['client_id'], $client_id)
        || !hash_equals($cfg['client_secret'], $client_secret)) {
        return new WP_REST_Response(['error' => 'invalid_client'], 401);
    }

    $data = get_transient('copai_oauth_code_' . $code);
    delete_transient('copai_oauth_code_' . $code); // one-shot regardless of outcome
    if (!is_array($data)
        || $data['client_id'] !== $client_id
        || $data['redirect_uri'] !== $redirect_uri) {
        return new WP_REST_Response(['error' => 'invalid_grant'], 400);
    }

    $access_token = bin2hex(random_bytes(24));
    set_transient('copai_oauth_token_' . $access_token, [
        'user_id' => $data['user_id'],
        'scope'   => $data['scope'],
    ], COPAI_OAUTH_TOKEN_TTL);

    return new WP_REST_Response([
        'access_token' => $access_token,
        'token_type'   => 'Bearer',
        'expires_in'   => COPAI_OAUTH_TOKEN_TTL,
        'scope'        => $data['scope'],
    ], 200);
}

/**
 * GET /userinfo
 * Returns profile data for the authenticated bearer token.
 */
function copai_oauth_userinfo(WP_REST_Request $req) {
    $auth = (string) $req->get_header('Authorization');
    if (!preg_match('/^Bearer\s+(\S+)$/i', $auth, $m)) {
        return new WP_REST_Response(['error' => 'invalid_token'], 401);
    }
    $token = $m[1];

    $data = get_transient('copai_oauth_token_' . $token);
    if (!is_array($data)) {
        return new WP_REST_Response(['error' => 'invalid_token'], 401);
    }

    $user = get_userdata((int) $data['user_id']);
    if (!$user) {
        return new WP_REST_Response(['error' => 'user_not_found'], 404);
    }

    $given  = (string) $user->first_name;
    $family = (string) $user->last_name;
    return [
        'sub'                => (string) $user->ID,
        'preferred_username' => $user->user_login,
        'email'              => $user->user_email,
        'email_verified'     => true,
        'name'               => $user->display_name,
        'given_name'         => $given,
        'family_name'        => $family,
    ];
}

/**
 * GET /.well-known/openid-configuration
 * Discovery document so Moodle can introspect the provider.
 */
function copai_oauth_discovery() {
    $base = rest_url(COPAI_OAUTH_NS);
    return [
        'issuer'                                   => home_url(),
        'authorization_endpoint'                   => $base . '/authorize',
        'token_endpoint'                           => $base . '/token',
        'userinfo_endpoint'                        => $base . '/userinfo',
        'response_types_supported'                 => ['code'],
        'grant_types_supported'                    => ['authorization_code'],
        'scopes_supported'                         => ['openid', 'email', 'profile'],
        'token_endpoint_auth_methods_supported'    => ['client_secret_post', 'client_secret_basic'],
        'subject_types_supported'                  => ['public'],
    ];
}
