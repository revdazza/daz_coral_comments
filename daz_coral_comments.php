<?php

// This is a Textpattern plugin. Do not run directly.
if (!defined('txpinterface')) @die('txp plugin');

/**
 * daz_coral_comments
 *
 * Integrates the Coral Project commenting system with Textpattern,
 * including SSO, recent comments panel, and comment counts.
 *
 * Tags provided:
 *   <txp:daz_coral_embed />   — SSO-aware comment embed
 *   <txp:daz_coral_recent />  — styled recent comments panel
 *   <txp:daz_coral_count />   — comment count for a URL
 *
 * @author  daz
 * @version 0.1
 */

// ============================================================
// BOOTSTRAP
// ============================================================

if (@txpinterface === 'admin') {
    add_privs('plugin_prefs.daz_coral_comments', '1,2');
    register_callback('daz_coral_prefs_page', 'plugin_prefs.daz_coral_comments');
}

// ============================================================
// ADMIN — PREFERENCES PAGE (handles POST and renders form)
// ============================================================

function daz_coral_prefs_page()
{
    // Handle form submissions first
    $action = ps('daz_coral_action');

    if ($action === 'save_prefs') {
        daz_coral_save_prefs();
    } elseif ($action === 'generate_token') {
        daz_coral_generate_token();
    } elseif ($action === 'revoke_token') {
        set_pref('daz_coral_api_token',    '',        'daz_coral_comments', 1, 'text_input', 5);
        set_pref('daz_coral_token_status', 'revoked', 'daz_coral_comments', 1, 'text_input', 80);
    }

    // Then render the form
    daz_coral_options();
}

function daz_coral_handle_post()
{
    $action = ps('daz_coral_action');

    if ($action === 'save_prefs') {
        daz_coral_save_prefs();
    } elseif ($action === 'generate_token') {
        daz_coral_generate_token();
    } elseif ($action === 'revoke_token') {
        set_pref('daz_coral_api_token',    '',           'daz_coral_comments', 1, 'text_input', 5);
        set_pref('daz_coral_token_status', 'revoked',    'daz_coral_comments', 1, 'text_input', 80);
    }
}

function daz_coral_save_prefs()
{
    $fields = [
        'daz_coral_domain'        => 10,
        'daz_coral_sso_secret'    => 20,
        'daz_coral_photo_path'    => 30,
        'daz_coral_photo_url'     => 40,
        'daz_coral_default_photo' => 50,
        'daz_coral_recent_limit'  => 60,
        'daz_coral_bg_color'      => 70,
    ];

    foreach ($fields as $key => $position) {
        set_pref($key, ps($key), 'daz_coral_comments', 1, 'text_input', $position);
    }
}

function daz_coral_generate_token()
{
    $domain   = rtrim(ps('daz_coral_domain') ?: get_pref('daz_coral_domain', ''), '/');
    $email    = ps('daz_coral_admin_email');
    $password = ps('daz_coral_admin_password');

    if (!$domain || !$email || !$password) {
        set_pref('daz_coral_token_status', 'missing_fields', 'daz_coral_comments', 1, 'text_input', 80);
        return;
    }

    // Step 1 — authenticate via REST (not GraphQL)
    $ch = curl_init($domain . '/api/auth/local');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode(['email' => $email, 'password' => $password]),
        CURLOPT_TIMEOUT        => 10,
    ]);
    $auth = json_decode(curl_exec($ch), true);
    curl_close($ch);

    if (empty($auth['token'])) {
        set_pref('daz_coral_token_status', 'auth_failed', 'daz_coral_comments', 1, 'text_input', 80);
        return;
    }

    // Step 2 — create permanent token via GraphQL (admins bypass persisted query requirement)
    $mutation = 'mutation CreateTokenMutation($name: String!) {
        createToken(input: { clientMutationId: "", name: $name }) {
            token { id name createdAt }
            signedToken
        }
    }';

    $ch = curl_init($domain . '/api/graphql');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $auth['token'],
        ],
        CURLOPT_POSTFIELDS     => json_encode([
            'query'     => $mutation,
            'variables' => ['name' => 'daz_coral_comments-' . date('Y-m-d')],
        ]),
        CURLOPT_TIMEOUT        => 10,
    ]);
    $result = json_decode(curl_exec($ch), true);
    curl_close($ch);

    $signed = $result['data']['createToken']['signedToken'] ?? null;

    if ($signed) {
        set_pref('daz_coral_api_token',    $signed,     'daz_coral_comments', 1, 'text_input', 5);
        set_pref('daz_coral_token_status', 'connected', 'daz_coral_comments', 1, 'text_input', 80);
    } else {
        set_pref('daz_coral_token_status', 'token_failed', 'daz_coral_comments', 1, 'text_input', 80);
    }
}

// ============================================================
// ADMIN — PREFERENCES PAGE
// ============================================================

function daz_coral_options()
{
    $domain        = get_pref('daz_coral_domain',        '');
    $sso_secret    = get_pref('daz_coral_sso_secret',    '');
    $photo_path    = get_pref('daz_coral_photo_path',    '');
    $photo_url     = get_pref('daz_coral_photo_url',     '/membership/photos/');
    $default_photo = get_pref('daz_coral_default_photo', 'user.jpg');
    $limit         = get_pref('daz_coral_recent_limit',  '10');
    $bg_color      = get_pref('daz_coral_bg_color',      '#D9E0DC');
    $token_status  = get_pref('daz_coral_token_status',  '');
    $api_token     = get_pref('daz_coral_api_token',     '');

    $status_messages = [
        'connected'     => '<span class="dcc-ok">&#10003; Connected</span>',
        'auth_failed'   => '<span class="dcc-err">&#10007; Authentication failed — check email and password</span>',
        'token_failed'  => '<span class="dcc-err">&#10007; Token creation failed — is this account a Coral admin?</span>',
        'missing_fields'=> '<span class="dcc-err">&#10007; Domain, email and password are all required</span>',
        'revoked'       => '<span class="dcc-warn">Token revoked</span>',
    ];

    $status_html   = $status_messages[$token_status] ?? '<span class="dcc-muted">Not connected</span>';
    $token_preview = $api_token ? substr($api_token, 0, 24) . '&hellip;' : 'None';

    $photo_path_placeholder = ($_SERVER['DOCUMENT_ROOT'] ?? '/var/www/html') . '/membership/photos/';

    echo <<<HTML
<style>
  .dcc-admin { max-width:680px; font-family:sans-serif; font-size:.9rem; }
  .dcc-admin h3 { font-size:.82rem; text-transform:uppercase; letter-spacing:.06em; color:#666;
                  border-bottom:1px solid #ddd; padding-bottom:5px; margin:28px 0 14px; }
  .dcc-admin h3:first-child { margin-top:0; }
  .dcc-admin label { display:block; font-weight:600; margin-bottom:3px; }
  .dcc-admin input[type=text],
  .dcc-admin input[type=password] { width:100%; padding:6px 8px; border:1px solid #ccc;
                                     border-radius:4px; box-sizing:border-box; margin-bottom:12px; font-size:.88rem; }
  .dcc-admin .hint { font-size:.78rem; color:#999; margin:-8px 0 12px; }
  .dcc-admin .row { display:flex; gap:16px; }
  .dcc-admin .row > div { flex:1; min-width:0; }
  .dcc-admin .btn { padding:7px 18px; border:none; border-radius:4px; cursor:pointer; font-size:.88rem; }
  .dcc-admin .btn-save { background:#4a7c6f; color:#fff; }
  .dcc-admin .btn-connect { background:#2c5282; color:#fff; }
  .dcc-admin .btn-revoke { background:#c00; color:#fff; margin-left:8px; }
  .dcc-admin .token-row { display:flex; align-items:center; gap:16px; margin-bottom:14px; flex-wrap:wrap; }
  .dcc-admin .token-status { font-size:.88rem; }
  .dcc-admin .token-preview { font-family:monospace; font-size:.78rem; color:#888; }
  .dcc-ok   { color:#2a7a2a; font-weight:600; }
  .dcc-err  { color:#c00;    font-weight:600; }
  .dcc-warn { color:#a06000; font-weight:600; }
  .dcc-muted{ color:#999; }
</style>

<div class="dcc-admin">

  <!-- ── Settings ───────────────────────────────────────── -->
  <form method="post">
    <input type="hidden" name="daz_coral_action" value="save_prefs">

    <h3>Connection</h3>

    <label>Coral domain</label>
    <input type="text" name="daz_coral_domain" value="{$domain}" placeholder="https://comments.example.com">

    <label>SSO secret key</label>
    <input type="text" name="daz_coral_sso_secret" value="{$sso_secret}" placeholder="ssosec_… or the hex string after the prefix">
    <p class="hint">Copy the full key from Coral admin &rarr; Configure &rarr; Auth &rarr; Single Sign-On. The <code>ssosec_</code> prefix is stripped automatically.</p>

    <h3>Recent Comments Display</h3>

    <div class="row">
      <div>
        <label>Number of comments to show</label>
        <input type="text" name="daz_coral_recent_limit" value="{$limit}">
      </div>
      <div>
        <label>Panel background colour</label>
        <input type="text" name="daz_coral_bg_color" value="{$bg_color}" placeholder="#D9E0DC">
      </div>
    </div>

    <h3>User Avatars</h3>

    <label>Server path to photo directory</label>
    <input type="text" name="daz_coral_photo_path" value="{$photo_path}" placeholder="{$photo_path_placeholder}">
    <p class="hint">Absolute server path used to check whether a photo file exists.</p>

    <label>Web URL to photo directory</label>
    <input type="text" name="daz_coral_photo_url" value="{$photo_url}" placeholder="/membership/photos/">

    <label>Default photo filename</label>
    <input type="text" name="daz_coral_default_photo" value="{$default_photo}" placeholder="user.jpg">
    <p class="hint">Used when no photo exists for a user.</p>

    <button type="submit" class="btn btn-save">Save settings</button>
  </form>

  <!-- ── API Token ───────────────────────────────────────── -->
  <form method="post">
    <input type="hidden" name="daz_coral_action" value="generate_token">
    <input type="hidden" name="daz_coral_domain" value="{$domain}">

    <h3>API Token</h3>

    <div class="token-row">
      <div class="token-status">{$status_html}</div>
      <div class="token-preview">Token: {$token_preview}</div>
    </div>

    <div class="row">
      <div>
        <label>Coral admin email</label>
        <input type="text" name="daz_coral_admin_email" value="" autocomplete="off">
      </div>
      <div>
        <label>Coral admin password</label>
        <input type="password" name="daz_coral_admin_password" value="" autocomplete="off">
      </div>
    </div>
    <p class="hint">Credentials are used once to generate the token and are never stored.</p>

    <button type="submit" class="btn btn-connect">Generate API token</button>

    <!-- Revoke button (separate form so it doesn't need credentials) -->
    <span style="display:inline-block;">
HTML;

    echo '</span></form>';

    if ($api_token) {
        echo '<form method="post" style="display:inline;">
            <input type="hidden" name="daz_coral_action" value="revoke_token">
            <button type="submit" class="btn btn-revoke" onclick="return confirm(\'Revoke the stored token?\')">Revoke token</button>
          </form>';
    }

    echo '</div>'; // .dcc-admin
}

// ============================================================
// HELPERS
// ============================================================

function daz_coral_get_secret()
{
    $raw = get_pref('daz_coral_sso_secret', '');
    // Strip ssosec_ prefix if present
    if (strpos($raw, 'ssosec_') === 0) {
        $raw = substr($raw, 7);
    }
    return $raw;
}

function daz_coral_uuid()
{
    $data    = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function daz_coral_build_jwt($user_id, $email, $username)
{
    $secret = daz_coral_get_secret();
    if (!$secret) return null;

    $b64 = static function ($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    };

    $header  = $b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $payload = $b64(json_encode([
        'jti'  => daz_coral_uuid(),
        'iat'  => time(),
        'exp'  => time() + 3600,
        'user' => [
            'id'       => (string) $user_id,
            'email'    => $email,
            'username' => $username,
        ],
    ]));
    $sig = $b64(hash_hmac('sha256', "$header.$payload", $secret, true));

    return "$header.$payload.$sig";
}

function daz_coral_avatar($user_id)
{
    $path    = rtrim(get_pref('daz_coral_photo_path', ''), '/') . '/';
    $web     = rtrim(get_pref('daz_coral_photo_url',  '/membership/photos/'), '/') . '/';
    $default = get_pref('daz_coral_default_photo', 'user.jpg');

    if ($path && $user_id) {
        foreach (['jpg', 'png'] as $ext) {
            if (file_exists($path . $user_id . '.' . $ext)) {
                return $web . $user_id . '.' . $ext;
            }
        }
    }

    return $web . $default;
}

function daz_coral_api($query, $variables = [])
{
    $domain = rtrim(get_pref('daz_coral_domain', ''), '/');
    $token  = get_pref('daz_coral_api_token', '');

    if (!$domain || !$token) return null;

    $ch = curl_init($domain . '/api/graphql');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ],
        CURLOPT_POSTFIELDS     => json_encode([
            'query'     => $query,
            'variables' => $variables,
        ]),
    ]);
    $result = json_decode(curl_exec($ch), true);
    curl_close($ch);

    return $result;
}

// ============================================================
// PUBLIC TAGS
// ============================================================

/**
 * <txp:daz_coral_embed />
 *
 * Attributes:
 *   session_user      — $_SESSION key holding the user ID    (default: 'user')
 *   session_email     — $_SESSION key holding the email      (default: 'email')
 *   session_username  — $_SESSION key holding the username   (default: 'username')
 */
function daz_coral_embed($atts)
{
    extract(lAtts([
        'session_user'     => 'user',
        'session_email'    => 'email',
        'session_username' => 'username',
    ], $atts));

    $domain = rtrim(get_pref('daz_coral_domain', ''), '/');
    if (!$domain) return '';

    $token           = null;
    $access_token_js = '';

    if (!empty($_SESSION[$session_user])) {
        $token = daz_coral_build_jwt(
            $_SESSION[$session_user],
            $_SESSION[$session_email]    ?? '',
            $_SESSION[$session_username] ?? ''
        );
    }

    if ($token) {
        $access_token_js = "accessToken: '" . $token . "',";
    }

    return <<<HTML
<div id="coral_thread"></div>
<script>
(function () {
    var s = document.createElement('script');
    s.src = '{$domain}/assets/js/embed.js';
    s.async = true;
    s.defer = true;
    s.onload = function () {
        Coral.createStreamEmbed({
            id: 'coral_thread',
            autoRender: true,
            rootURL: '{$domain}',
            {$access_token_js}
        });
    };
    document.body.appendChild(s);
}());
</script>
HTML;
}

/**
 * <txp:daz_coral_count />
 *
 * Attributes:
 *   url    — story URL (defaults to current page canonical URL)
 *   notext — set to "1" to show number only, no "Comments" label
 */
function daz_coral_count($atts)
{
    extract(lAtts([
        'url'    => '',
        'notext' => '0',
    ], $atts));

    $domain = rtrim(get_pref('daz_coral_domain', ''), '/');
    if (!$domain) return '';

    $data_url    = $url    ? ' data-coral-url="'  . txpspecialchars($url) . '"' : '';
    $data_notext = ($notext === '1') ? ' data-coral-notext="true"' : '';

    return <<<HTML
<script class="coral-script" src="{$domain}/assets/js/count.js" defer></script>
<span class="coral-count"{$data_url}{$data_notext}></span>
HTML;
}

/**
 * <txp:daz_coral_recent />
 *
 * Attributes:
 *   limit    — number of comments (overrides admin setting)
 *   bg_color — panel background colour (overrides admin setting)
 */
function daz_coral_recent($atts)
{
    extract(lAtts([
        'limit'    => get_pref('daz_coral_recent_limit', 10),
        'bg_color' => get_pref('daz_coral_bg_color', '#D9E0DC'),
    ], $atts));

    $limit = (int) $limit;

    $query = "{
        comments(first: {$limit}, orderBy: CREATED_AT_DESC) {
            nodes {
                id
                body
                createdAt
                author { id username }
                story { url metadata { title } }
            }
        }
    }";

    $result   = daz_coral_api($query);
    $comments = $result['data']['comments']['nodes'] ?? [];

    if (!$comments) return '';

    $default_avatar = txpspecialchars(
        rtrim(get_pref('daz_coral_photo_url', '/membership/photos/'), '/') . '/' .
        get_pref('daz_coral_default_photo', 'user.jpg')
    );

    $out = <<<CSS
<style>
.dcc-panel{background:{$bg_color};border-radius:12px;padding:20px 24px}
.dcc-panel h5{color:#2c3e35;font-weight:700;letter-spacing:.05em;text-transform:uppercase;font-size:.92rem;margin-bottom:18px;padding-bottom:10px;border-bottom:2px solid #b5c2bc}
.dcc-item{padding:12px 0;border-bottom:1px solid #c4cec9;transition:opacity .2s}
.dcc-item:last-child{border-bottom:none;padding-bottom:0}
.dcc-item:hover{opacity:.85}
.dcc-item-top{display:flex;gap:12px}
.dcc-avatar{width:42px;height:42px;border-radius:50%;object-fit:cover;flex-shrink:0;border:2px solid #b5c2bc;box-shadow:0 1px 3px rgba(0,0,0,.12)}
.dcc-username{font-weight:700;font-size:1.05rem;color:#2c3e35}
.dcc-date{font-size:.88rem;color:#7a9089;margin-left:6px}
.dcc-body{font-size:1rem;color:#3a4f47;margin:4px 0 0;line-height:1.45}
.dcc-link{font-size:.88rem;color:#5a8078;text-decoration:none;font-style:italic;margin-top:6px;display:block}
.dcc-link:hover{color:#2c3e35;text-decoration:underline}
</style>
CSS;

    $out .= '<div class="dcc-panel">';
    $out .= '<h5><i class="fas fa-comments me-2"></i>Recent Comments</h5>';

    foreach ($comments as $comment) {
        $username = $comment['author']['username'] ?? null;
        if (!$username) continue;

        $user_id = $comment['author']['id'] ?? null;
        $avatar  = txpspecialchars(daz_coral_avatar($user_id));
        $body    = txpspecialchars(trim(strip_tags($comment['body'])));
        $title   = $comment['story']['metadata']['title'] ?? null;
        $url     = txpspecialchars($comment['story']['url']);
        $date    = date('j M Y', strtotime($comment['createdAt']));
        $name    = txpspecialchars($username);

        $out .= '<div class="dcc-item">';
        $out .= '<div class="dcc-item-top">';
        $out .= "<img src=\"{$avatar}\" alt=\"{$name}\" class=\"dcc-avatar\" onerror=\"this.src='{$default_avatar}'\">";
        $out .= '<div style="min-width:0;flex:1">';
        $out .= "<span class=\"dcc-username\">{$name}</span>";
        $out .= "<span class=\"dcc-date\">{$date}</span>";
        $out .= "<div class=\"dcc-body\">{$body}</div>";
        $out .= '</div>';
        $out .= '</div>'; // .dcc-item-top

        if ($title) {
            $t    = txpspecialchars($title);
            $out .= "<a href=\"{$url}\" class=\"dcc-link\"><i class=\"fas fa-arrow-right me-1\"></i>{$t}</a>";
        }

        $out .= '</div>'; // .dcc-item
    }

    $out .= '</div>'; // .dcc-panel

    return $out;
}
