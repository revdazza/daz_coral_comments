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
 * @version 0.2
 */

// ============================================================
// BOOTSTRAP
// ============================================================

// Register public tags with TXP tag registry
if (class_exists('\Textpattern\Tag\Registry')) {
    Txp::get('\Textpattern\Tag\Registry')
        ->register('daz_coral_embed')
        ->register('daz_coral_recent')
        ->register('daz_coral_count');
}

if (@txpinterface === 'admin') {
    add_privs('plugin_prefs.daz_coral_comments', '1,2');
    register_callback('daz_coral_prefs_page',   'plugin_prefs.daz_coral_comments');
    register_callback('daz_coral_ensure_help',  'admin_side', 'head');
}

function daz_coral_ensure_help()
{
    $row = safe_row('help', 'txp_plugin', "name = 'daz_coral_comments'");
    if ($row && empty($row['help'])) {
        safe_update(
            'txp_plugin',
            "help = '" . doSlash('<p>See the <strong>Help</strong> tab within the plugin Options page for full documentation of all tags, attributes, and settings.</p>') . "'",
            "name = 'daz_coral_comments'"
        );
    }
}

// ============================================================
// ADMIN — PREFERENCES PAGE (handles POST and renders form)
// ============================================================

function daz_coral_prefs_page()
{
    $action = ps('daz_coral_action');
    $notice = null;

    if ($action === 'save_prefs') {
        daz_coral_save_prefs();
    } elseif ($action === 'generate_token') {
        daz_coral_generate_token();
    } elseif ($action === 'revoke_token') {
        set_pref('daz_coral_api_token',    '',        'daz_coral_comments', 1, 'text_input', 5);
        set_pref('daz_coral_token_status', 'revoked', 'daz_coral_comments', 1, 'text_input', 80);
    } elseif ($action === 'approve_comment') {
        $notice = daz_coral_do_moderate('approve');
    } elseif ($action === 'reject_comment') {
        $notice = daz_coral_do_moderate('reject');
    }

    $tab = gps('daz_tab') ?: 'settings';
    if (in_array($action, ['approve_comment', 'reject_comment'])) {
        $tab = 'moderation';
    }

    if ($tab === 'help') {
        daz_coral_help_page();
    } elseif ($tab === 'moderation') {
        daz_coral_moderation_page($notice);
    } else {
        daz_coral_options();
    }
}

function daz_coral_save_prefs()
{
    $fields = [
        'daz_coral_domain'           => 10,
        'daz_coral_sso_secret'       => 20,
        'daz_coral_session_user'     => 25,
        'daz_coral_session_email'    => 26,
        'daz_coral_session_username' => 27,
        'daz_coral_photo_path'       => 30,
        'daz_coral_photo_url'        => 40,
        'daz_coral_default_photo'    => 50,
        'daz_coral_recent_limit'     => 60,
        'daz_coral_bg_color'         => 70,
        'daz_coral_text_color'       => 71,
        'daz_coral_show_photos'      => 72,
    ];

    foreach ($fields as $key => $position) {
        set_pref($key, ps($key), 'daz_coral_comments', 1, 'text_input', $position);
    }

    // Checkbox — only present in POST when checked, so handle explicitly
    set_pref('daz_coral_show_photos', ps('daz_coral_show_photos') ? '1' : '0', 'daz_coral_comments', 1, 'text_input', 72);
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

    // Step 1 — authenticate via REST
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
        $detail = isset($auth['error']['message']) ? $auth['error']['message'] : 'no token in response';
        set_pref('daz_coral_token_status', 'auth_failed: ' . substr($detail, 0, 200), 'daz_coral_comments', 1, 'text_input', 80);
        return;
    }

    // Step 2 — create permanent token via GraphQL
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
// ADMIN — SHARED CHROME
// ============================================================

function daz_coral_admin_chrome($active_tab)
{
    pagetop('Coral Comments');

    $txp_token = function_exists('form_token') ? form_token() : '';
    $base_url  = '?event=plugin_prefs.daz_coral_comments';

    $tabs = [
        'settings'   => 'Settings',
        'moderation' => 'Moderation',
        'help'       => 'Help',
    ];

    $tab_html = '';
    foreach ($tabs as $key => $label) {
        $active    = ($key === $active_tab) ? ' dcc-tab-active' : '';
        $tab_html .= "<a href=\"{$base_url}&amp;daz_tab={$key}\" class=\"dcc-tab{$active}\">{$label}</a>";
    }

    echo <<<HTML
<style>
  .dcc-admin { max-width:720px; font-family:sans-serif; font-size:1rem; margin-top:16px; }
  .dcc-tabs  { display:flex; gap:4px; margin-bottom:24px; border-bottom:2px solid #ddd; padding-bottom:0; }
  .dcc-tab   { padding:8px 20px; text-decoration:none; color:#555; border-radius:4px 4px 0 0;
               border:1px solid transparent; margin-bottom:-2px; font-size:.95rem; }
  .dcc-tab:hover      { background:#f5f5f5; color:#333; }
  .dcc-tab-active     { background:#fff; border-color:#ddd #ddd #fff; color:#222; font-weight:600; }
  .dcc-admin h3 { font-size:.88rem; text-transform:uppercase; letter-spacing:.06em; color:#666;
                  border-bottom:1px solid #eee; padding-bottom:5px; margin:28px 0 14px; }
  .dcc-admin h3:first-child { margin-top:0; }
  .dcc-admin label { display:block; font-weight:600; margin-bottom:4px; font-size:.95rem; }
  .dcc-admin input[type=text],
  .dcc-admin input[type=password] { width:100%; padding:7px 9px; border:1px solid #ccc;
                                    border-radius:4px; box-sizing:border-box; margin-bottom:14px; font-size:.95rem; }
  .dcc-admin .hint  { font-size:.83rem; color:#999; margin:-10px 0 14px; }
  .dcc-admin .row   { display:flex; gap:16px; }
  .dcc-admin .row > div { flex:1; min-width:0; }
  .dcc-admin .btn   { padding:8px 20px; border:none; border-radius:4px; cursor:pointer; font-size:.95rem; }
  .dcc-admin .btn-save    { background:#4a7c6f; color:#fff; }
  .dcc-admin .btn-connect { background:#2c5282; color:#fff; }
  .dcc-admin .btn-revoke  { background:#c00;    color:#fff; margin-left:8px; }
  .dcc-admin .token-row   { display:flex; align-items:center; gap:16px; margin-bottom:14px; flex-wrap:wrap; }
  .dcc-admin .token-preview { font-family:monospace; font-size:.83rem; color:#888; }
  .dcc-ok   { color:#2a7a2a; font-weight:600; }
  .dcc-err  { color:#c00;    font-weight:600; }
  .dcc-warn { color:#a06000; font-weight:600; }
  .dcc-muted{ color:#999; }
  /* Help page */
  .dcc-help h2 { font-size:1rem; margin:24px 0 6px; color:#333; }
  .dcc-help h2:first-child { margin-top:0; }
  .dcc-help p  { margin:0 0 10px; color:#444; line-height:1.55; }
  .dcc-help code { background:#f4f4f4; padding:1px 5px; border-radius:3px; font-size:.85rem; }
  .dcc-help pre { background:#f4f4f4; padding:12px; border-radius:4px; font-size:.82rem;
                  overflow-x:auto; margin:0 0 14px; line-height:1.5; }
  .dcc-help table { width:100%; border-collapse:collapse; margin-bottom:16px; font-size:.85rem; }
  .dcc-help th { text-align:left; padding:6px 10px; background:#f0f0f0; border-bottom:2px solid #ddd; }
  .dcc-help td { padding:6px 10px; border-bottom:1px solid #eee; vertical-align:top; }
  .dcc-help td:first-child { font-family:monospace; white-space:nowrap; color:#2c5282; }
  .dcc-help .dcc-note { background:#fff8e1; border-left:3px solid #f0a500; padding:10px 14px;
                        margin-bottom:14px; font-size:.85rem; border-radius:0 4px 4px 0; }
  /* Moderation */
  .dcc-mod-subtabs { display:flex; gap:8px; margin-bottom:20px; }
  .dcc-mod-subtab  { padding:5px 16px; text-decoration:none; color:#333; border-radius:4px;
                     font-size:.9rem; border:1px solid #ddd; background:#fff; }
  .dcc-mod-subtab:hover      { background:#f5f5f5; }
  .dcc-mod-subtab-active     { background:#e8f0fe; border-color:#4a7cdc; font-weight:600; color:#2c5282; }
  .dcc-mod-empty   { color:#999; font-style:italic; margin:24px 0; font-size:.95rem; }
  .dcc-mod-card    { background:#fff; border:1px solid #e0e0e0; border-radius:8px;
                     padding:16px 20px; margin-bottom:14px; }
  .dcc-mod-article { font-size:.8rem; text-transform:uppercase; letter-spacing:.04em;
                     color:#888; margin-bottom:2px; }
  .dcc-mod-article a { color:#2c5282; text-decoration:none; }
  .dcc-mod-article a:hover { text-decoration:underline; }
  .dcc-mod-meta    { font-size:.88rem; color:#888; margin-bottom:8px; }
  .dcc-mod-body    { font-size:1rem; color:#222; line-height:1.5; margin-bottom:14px;
                     padding:10px 14px; background:#f9f9f9; border-radius:4px; }
  .dcc-mod-actions { display:flex; gap:8px; }
  .dcc-mod-approve { background:#2a7a2a; color:#fff; border:none; padding:8px 20px;
                     border-radius:4px; cursor:pointer; font-size:.95rem; font-weight:600; }
  .dcc-mod-approve:hover { background:#1e5c1e; }
  .dcc-mod-reject  { background:#c00; color:#fff; border:none; padding:8px 20px;
                     border-radius:4px; cursor:pointer; font-size:.95rem; font-weight:600; }
  .dcc-mod-reject:hover  { background:#900; }
  .dcc-mod-notice-ok  { background:#e6f4ea; border:1px solid #a8d5b5; color:#1e5c1e;
                         padding:10px 16px; border-radius:4px; margin-bottom:16px; font-weight:600; }
  .dcc-mod-notice-err { background:#fdecea; border:1px solid #f5c6c6; color:#900;
                         padding:10px 16px; border-radius:4px; margin-bottom:16px; font-weight:600; }
</style>
<div class="dcc-admin">
  <div class="dcc-tabs">{$tab_html}</div>
HTML;

    return $txp_token;
}

// ============================================================
// ADMIN — SETTINGS PAGE
// ============================================================

function daz_coral_options()
{
    $domain       = get_pref('daz_coral_domain',           '');
    $sso_secret   = get_pref('daz_coral_sso_secret',       '');
    $sess_user    = get_pref('daz_coral_session_user',     'user');
    $sess_email   = get_pref('daz_coral_session_email',    'email');
    $sess_uname   = get_pref('daz_coral_session_username', 'username');
    $photo_path   = get_pref('daz_coral_photo_path',       '');
    $photo_url    = get_pref('daz_coral_photo_url',        '/membership/photos/');
    $def_photo    = get_pref('daz_coral_default_photo',    'user.jpg');
    $limit        = get_pref('daz_coral_recent_limit',     '10');
    $bg_color     = get_pref('daz_coral_bg_color',         '#ffffff');
    $text_color   = get_pref('daz_coral_text_color',       '#000000');
    $show_photos  = get_pref('daz_coral_show_photos',      '1');
    $token_status = get_pref('daz_coral_token_status',     '');
    $api_token    = get_pref('daz_coral_api_token',        '');

    $status_messages = [
        'connected'      => '<span class="dcc-ok">&#10003; Connected</span>',
        'token_failed'   => '<span class="dcc-err">&#10007; Token creation failed — is this account a Coral admin?</span>',
        'missing_fields' => '<span class="dcc-err">&#10007; Domain, email and password are all required</span>',
        'revoked'        => '<span class="dcc-warn">Token revoked</span>',
    ];

    if (strpos($token_status, 'auth_failed') === 0) {
        $status_html = '<span class="dcc-err">&#10007; Authentication failed — ' . txpspecialchars(substr($token_status, 12)) . '</span>';
    } else {
        $status_html = $status_messages[$token_status] ?? '<span class="dcc-muted">Not connected</span>';
    }

    $token_preview          = $api_token ? substr($api_token, 0, 24) . '&hellip;' : 'None';
    $photo_path_placeholder = ($_SERVER['DOCUMENT_ROOT'] ?? '/var/www/html') . '/membership/photos/';
    $photos_checked         = ($show_photos === '1') ? ' checked' : '';
    $txp_token              = daz_coral_admin_chrome('settings');

    echo <<<HTML

  <!-- ── Settings ───────────────────────────────────────── -->
  <form method="post">
    <input type="hidden" name="daz_coral_action" value="save_prefs">
    <input type="hidden" name="_txp_token" value="{$txp_token}">

    <h3>Connection</h3>

    <label>Coral domain</label>
    <input type="text" name="daz_coral_domain" value="{$domain}" placeholder="https://comments.example.com">

    <label>SSO secret key</label>
    <input type="text" name="daz_coral_sso_secret" value="{$sso_secret}" placeholder="ssosec_… or the hex string after the prefix">
    <p class="hint">Copy the full key from Coral admin &rarr; Configure &rarr; Auth &rarr; Single Sign-On. The <code>ssosec_</code> prefix is stripped automatically.</p>

    <h3>Session Keys</h3>
    <p class="hint" style="margin-top:-8px">The PHP <code>\$_SESSION</code> variable names your site sets when a user logs in.</p>

    <div class="row">
      <div>
        <label>User ID key</label>
        <input type="text" name="daz_coral_session_user" value="{$sess_user}" placeholder="user">
      </div>
      <div>
        <label>Email key</label>
        <input type="text" name="daz_coral_session_email" value="{$sess_email}" placeholder="email">
      </div>
      <div>
        <label>Username key</label>
        <input type="text" name="daz_coral_session_username" value="{$sess_uname}" placeholder="username">
      </div>
    </div>

    <h3>Recent Comments Display</h3>

    <div class="row">
      <div>
        <label>Default number of comments</label>
        <input type="text" name="daz_coral_recent_limit" value="{$limit}">
        <p class="hint">Can be overridden per tag with <code>limit=""</code>.</p>
      </div>
      <div>
        <label>Background colour</label>
        <input type="text" name="daz_coral_bg_color" value="{$bg_color}" placeholder="#ffffff">
      </div>
      <div>
        <label>Text colour</label>
        <input type="text" name="daz_coral_text_color" value="{$text_color}" placeholder="#000000">
      </div>
    </div>
    <label>
      <input type="checkbox" name="daz_coral_show_photos" value="1"{$photos_checked}>
      Show user profile photos
    </label>
    <p class="hint">When unchecked, no filesystem checks or image requests are made.</p>

    <h3>User Avatars</h3>

    <label>Server path to photo directory</label>
    <input type="text" name="daz_coral_photo_path" value="{$photo_path}" placeholder="{$photo_path_placeholder}">
    <p class="hint">Absolute server path used to check whether a photo file exists.</p>

    <label>Web URL to photo directory</label>
    <input type="text" name="daz_coral_photo_url" value="{$photo_url}" placeholder="/membership/photos/">

    <label>Default photo filename</label>
    <input type="text" name="daz_coral_default_photo" value="{$def_photo}" placeholder="user.jpg">
    <p class="hint">Shown when no photo exists for a user.</p>

    <button type="submit" class="btn btn-save">Save settings</button>
  </form>

  <!-- ── API Token ───────────────────────────────────────── -->
  <form method="post">
    <input type="hidden" name="daz_coral_action" value="generate_token">
    <input type="hidden" name="daz_coral_domain" value="{$domain}">
    <input type="hidden" name="_txp_token" value="{$txp_token}">

    <h3>API Token</h3>

    <div class="token-row">
      <div>{$status_html}</div>
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
HTML;

    echo '</form>';

    if ($api_token) {
        echo '<form method="post" style="margin-top:8px;">
            <input type="hidden" name="daz_coral_action" value="revoke_token">
            <input type="hidden" name="_txp_token" value="' . (function_exists('form_token') ? form_token() : '') . '">
            <button type="submit" class="btn btn-revoke" onclick="return confirm(\'Revoke the stored token?\')">Revoke token</button>
          </form>';
    }

    echo '</div>'; // .dcc-admin
}

// ============================================================
// ADMIN — HELP PAGE
// ============================================================

function daz_coral_help_page()
{
    daz_coral_admin_chrome('help');

    echo <<<HTML
<div class="dcc-help">

  <h2>Overview</h2>
  <p>daz_coral_comments integrates the <a href="https://coralproject.net" target="_blank">Coral Project</a> commenting system with Textpattern. It provides three tags: a comment embed with automatic Single Sign-On, a recent comments panel, and a comment count display.</p>

  <div class="dcc-note">Before any tag works you must set the <strong>Coral domain</strong> and <strong>SSO secret key</strong> in Settings. To use the recent comments panel you also need to generate an <strong>API token</strong>.</div>

  <h2>&lt;txp:daz_coral_embed /&gt;</h2>
  <p>Renders the Coral comment thread on any page. If the current visitor is logged in, they are signed into Coral automatically using Single Sign-On — no separate Coral account or login required. Session key names are configured in Settings (see Initial Setup below).</p>

  <pre>&lt;txp:daz_coral_embed /&gt;</pre>

  <p>Place this tag once per article page, where you want the comment thread to appear. No attributes are normally needed.</p>

  <h2>&lt;txp:daz_coral_recent /&gt;</h2>
  <p>Renders a styled panel showing the most recent approved comments from across your entire site, with user avatars, usernames, dates, and links back to the originating article.</p>

  <pre>&lt;txp:daz_coral_recent /&gt;
&lt;txp:daz_coral_recent limit="5" /&gt;
&lt;txp:daz_coral_recent text="Latest Discussion" /&gt;
&lt;txp:daz_coral_recent text="" /&gt;</pre>

  <table>
    <tr><th>Attribute</th><th>Default</th><th>Description</th></tr>
    <tr>
      <td>limit</td>
      <td><em>Settings value (10)</em></td>
      <td>Number of comments to show. Overrides the default set in plugin settings.</td>
    </tr>
    <tr>
      <td>text</td>
      <td>Recent Comments</td>
      <td>Heading text for the panel. Set to <code>text=""</code> to suppress the heading entirely. If omitted, shows "Recent Comments" with no icon.</td>
    </tr>
    <tr>
      <td>bg_color</td>
      <td><em>Settings value (#ffffff)</em></td>
      <td>Panel background colour. Accepts any CSS colour value.</td>
    </tr>
    <tr>
      <td>text_color</td>
      <td><em>Settings value (#000000)</em></td>
      <td>Primary text colour for usernames and comment bodies.</td>
    </tr>
    <tr>
      <td>photos</td>
      <td><em>Settings value (1)</em></td>
      <td>Set to <code>photos="0"</code> to hide avatars entirely. No filesystem checks or image requests are made. Overrides the setting.</td>
    </tr>
    <tr>
      <td>debug</td>
      <td>0</td>
      <td>Set to <code>debug="1"</code> to show a diagnostic panel instead of comments. Useful for troubleshooting. Remove before going live.</td>
    </tr>
  </table>

  <div class="dcc-note">Only approved comments appear. Pending, rejected, or spam-flagged comments are not shown. Requires a valid API token.</div>

  <h2>&lt;txp:daz_coral_count /&gt;</h2>
  <p>Displays the number of approved comments for a given URL. Uses Coral's lightweight <code>count.js</code> script — no API token required.</p>

  <pre>&lt;txp:daz_coral_count /&gt;
&lt;txp:daz_coral_count url="https://example.com/article/" /&gt;
&lt;txp:daz_coral_count notext="1" /&gt;</pre>

  <table>
    <tr><th>Attribute</th><th>Default</th><th>Description</th></tr>
    <tr>
      <td>url</td>
      <td><em>Current page URL</em></td>
      <td>The story URL to count comments for. If omitted, Coral infers from the page's canonical URL.</td>
    </tr>
    <tr>
      <td>notext</td>
      <td>0</td>
      <td>Set to <code>notext="1"</code> to show the number only, without the word "Comments".</td>
    </tr>
  </table>

  <h2>Initial Setup</h2>
  <p><strong>1. Coral domain</strong> — The full URL of your Coral installation, e.g. <code>https://comments.example.com</code>. No trailing slash.</p>
  <p><strong>2. SSO secret key</strong> — Found in your Coral admin panel under Configure &rarr; Auth &rarr; Single Sign-On. Copy the full key including the <code>ssosec_</code> prefix — the plugin strips it automatically.</p>

  <p><strong>3. Session keys</strong> — For Single Sign-On to work, the plugin needs to read the currently logged-in user's details from PHP's <code>\$_SESSION</code> array. You must tell the plugin which keys your site uses. Set these three values in Settings to match what your authentication system writes when a user logs in:</p>

  <table>
    <tr><th>Setting</th><th>Default</th><th>What it should contain</th></tr>
    <tr>
      <td>User ID key</td>
      <td>user</td>
      <td>The unique numeric or string ID for the logged-in user — e.g. <code>\$_SESSION['user']</code></td>
    </tr>
    <tr>
      <td>Email key</td>
      <td>email</td>
      <td>The user's email address — e.g. <code>\$_SESSION['email']</code></td>
    </tr>
    <tr>
      <td>Username key</td>
      <td>username</td>
      <td>The display name shown on comments — e.g. <code>\$_SESSION['username']</code></td>
    </tr>
  </table>

  <p>If you are not sure what keys your site uses, add this temporarily to any TXP page and view the HTML source:</p>
  <pre>&lt;txp:php&gt;
echo '&lt;!-- SESSION: ' . print_r(\$_SESSION, true) . ' --&gt;';
&lt;/txp:php&gt;</pre>

  <p><strong>4. API token</strong> — Required for the recent comments panel. Enter your Coral admin email and password and click Generate. The credentials are used once and never stored. The resulting token does not expire.</p>
  <p><strong>5. User avatars</strong> — If your site stores profile photos on the server, set the server path and web URL so the plugin can find them. Set the default photo filename for users without a photo.</p>

  <h2>Styling</h2>
  <p>All elements in the recent comments panel carry CSS classes you can override in your site stylesheet. Background and text colours can also be set per tag or in plugin settings.</p>
  <table>
    <tr><th>Class</th><th>Element</th></tr>
    <tr><td>.dcc-panel</td><td>Outer container</td></tr>
    <tr><td>.dcc-panel h5</td><td>Panel heading</td></tr>
    <tr><td>.dcc-item</td><td>Individual comment row</td></tr>
    <tr><td>.dcc-item-top</td><td>Flex row containing avatar and text</td></tr>
    <tr><td>.dcc-avatar</td><td>Profile photo</td></tr>
    <tr><td>.dcc-username</td><td>Commenter display name</td></tr>
    <tr><td>.dcc-date</td><td>Comment date</td></tr>
    <tr><td>.dcc-body</td><td>Comment text</td></tr>
    <tr><td>.dcc-link</td><td>Link to originating article</td></tr>
  </table>

  <h2>Requirements</h2>
  <ul style="color:#444;line-height:1.8;font-size:.88rem;">
    <li>Textpattern 4.8 or later</li>
    <li>PHP 7.4 or later with the <code>curl</code> extension enabled</li>
    <li>A running Coral installation (tested with Coral 7)</li>
    <li>The plugin set to <strong>Front and back</strong> in the Textpattern plugin list</li>
  </ul>

</div>
</div>
HTML;
}

// ============================================================
// ADMIN — MODERATION PAGE
// ============================================================

function daz_coral_do_moderate($action)
{
    $comment_id  = ps('daz_coral_comment_id');
    $revision_id = ps('daz_coral_revision_id');

    if (!$comment_id || !$revision_id) {
        return ['error' => 'Missing comment or revision ID.'];
    }

    $mutation_name = ($action === 'approve') ? 'approveComment' : 'rejectComment';
    $mutation = 'mutation ModerateComment($commentID: ID!, $commentRevisionID: ID!) {
        ' . $mutation_name . '(input: {
            clientMutationId: ""
            commentID: $commentID
            commentRevisionID: $commentRevisionID
        }) {
            comment { id status }
        }
    }';

    $result = daz_coral_api($mutation, [
        'commentID'         => $comment_id,
        'commentRevisionID' => $revision_id,
    ]);

    if ($result === null) {
        return ['error' => 'API call failed — check domain and token in Settings.'];
    }

    if (!empty($result['errors'])) {
        return ['error' => txpspecialchars($result['errors'][0]['message'] ?? 'Unknown error')];
    }

    return ['success' => true, 'action' => $action];
}

function daz_coral_moderation_page($notice = null)
{
    $txp_token = daz_coral_admin_chrome('moderation');
    $queue     = gps('daz_queue') ?: 'unmoderated';
    $base_url  = '?event=plugin_prefs.daz_coral_comments&amp;daz_tab=moderation';

    $domain = get_pref('daz_coral_domain', '');
    $token  = get_pref('daz_coral_api_token', '');

    if (!$domain || !$token) {
        echo '<p class="dcc-err">No API token configured. '
            . '<a href="?event=plugin_prefs.daz_coral_comments&amp;daz_tab=settings">Go to Settings</a> to generate one.</p>';
        echo '</div>';
        return;
    }

    $query = '{
        moderationQueues {
            unmoderated {
                count
                comments(first: 20) {
                    nodes {
                        id body
                        revision { id }
                        author { id username }
                        story { url metadata { title } }
                        createdAt
                    }
                }
            }
            reported {
                count
                comments(first: 20) {
                    nodes {
                        id body
                        revision { id }
                        author { id username }
                        story { url metadata { title } }
                        createdAt
                    }
                }
            }
        }
    }';

    $result  = daz_coral_api($query);
    $queues  = $result['data']['moderationQueues'] ?? null;

    $pending_count  = (int) ($queues['unmoderated']['count'] ?? 0);
    $reported_count = (int) ($queues['reported']['count']    ?? 0);
    $comments       = $queues[$queue]['comments']['nodes']   ?? [];

    $pending_label  = 'Pending'  . ($pending_count  ? " ({$pending_count})"  : '');
    $reported_label = 'Reported' . ($reported_count ? " ({$reported_count})" : '');
    $p_class        = ($queue === 'unmoderated') ? ' dcc-mod-subtab-active' : '';
    $r_class        = ($queue === 'reported')    ? ' dcc-mod-subtab-active' : '';

    $notice_html = '';
    if ($notice) {
        if (!empty($notice['success'])) {
            $verb = ($notice['action'] === 'approve') ? 'approved' : 'rejected';
            $notice_html = "<div class=\"dcc-mod-notice-ok\">&#10003; Comment {$verb}.</div>";
        } elseif (!empty($notice['error'])) {
            $notice_html = '<div class="dcc-mod-notice-err">&#10007; ' . $notice['error'] . '</div>';
        }
    }

    echo <<<HTML
<div class="dcc-mod-subtabs">
  <a href="{$base_url}&amp;daz_queue=unmoderated" class="dcc-mod-subtab{$p_class}">{$pending_label}</a>
  <a href="{$base_url}&amp;daz_queue=reported"    class="dcc-mod-subtab{$r_class}">{$reported_label}</a>
</div>
{$notice_html}
HTML;

    if (!$comments) {
        $label = ($queue === 'unmoderated') ? 'pending' : 'reported';
        echo "<p class=\"dcc-mod-empty\">No {$label} comments. All clear!</p>";
        echo '</div>';
        return;
    }

    foreach ($comments as $c) {
        $comment_id  = txpspecialchars($c['id']);
        $revision_id = txpspecialchars($c['revision']['id'] ?? '');
        $body        = txpspecialchars(strip_tags($c['body'] ?? ''));
        $username    = txpspecialchars($c['author']['username'] ?? 'Unknown');
        $story_url   = txpspecialchars($c['story']['url'] ?? '#');
        $title       = txpspecialchars($c['story']['metadata']['title'] ?? $c['story']['url'] ?? '');
        $date        = date('j M Y, g:ia', strtotime($c['createdAt']));

        echo <<<HTML
<div class="dcc-mod-card">
  <div class="dcc-mod-article"><a href="{$story_url}" target="_blank">{$title}</a></div>
  <div class="dcc-mod-meta">{$username} &middot; {$date}</div>
  <div class="dcc-mod-body">{$body}</div>
  <div class="dcc-mod-actions">
    <form method="post" style="display:inline">
      <input type="hidden" name="daz_coral_action"     value="approve_comment">
      <input type="hidden" name="daz_coral_comment_id"  value="{$comment_id}">
      <input type="hidden" name="daz_coral_revision_id" value="{$revision_id}">
      <input type="hidden" name="daz_tab"              value="moderation">
      <input type="hidden" name="daz_queue"            value="{$queue}">
      <input type="hidden" name="_txp_token"           value="{$txp_token}">
      <button type="submit" class="dcc-mod-approve">&#10003; Approve</button>
    </form>
    <form method="post" style="display:inline">
      <input type="hidden" name="daz_coral_action"     value="reject_comment">
      <input type="hidden" name="daz_coral_comment_id"  value="{$comment_id}">
      <input type="hidden" name="daz_coral_revision_id" value="{$revision_id}">
      <input type="hidden" name="daz_tab"              value="moderation">
      <input type="hidden" name="daz_queue"            value="{$queue}">
      <input type="hidden" name="_txp_token"           value="{$txp_token}">
      <button type="submit" class="dcc-mod-reject">&#10007; Reject</button>
    </form>
  </div>
</div>
HTML;
    }

    echo '</div>'; // .dcc-admin
}

// ============================================================
// HELPERS
// ============================================================

function daz_coral_get_secret()
{
    $raw = get_pref('daz_coral_sso_secret', '');
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
 *   session_user      — $_SESSION key for user ID   (default from settings)
 *   session_email     — $_SESSION key for email     (default from settings)
 *   session_username  — $_SESSION key for username  (default from settings)
 */
function daz_coral_embed($atts)
{
    extract(lAtts([
        'session_user'     => get_pref('daz_coral_session_user',     'user'),
        'session_email'    => get_pref('daz_coral_session_email',    'email'),
        'session_username' => get_pref('daz_coral_session_username', 'username'),
    ], $atts));

    $domain = rtrim(get_pref('daz_coral_domain', ''), '/');
    if (!$domain) return '';

    $access_token_js = '';

    if (!empty($_SESSION[$session_user])) {
        $token = daz_coral_build_jwt(
            $_SESSION[$session_user],
            $_SESSION[$session_email]    ?? '',
            $_SESSION[$session_username] ?? ''
        );
        if ($token) {
            $access_token_js = "accessToken: '" . $token . "',";
        }
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

    $data_url    = $url ? ' data-coral-url="' . txpspecialchars($url) . '"' : '';
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
 *   limit      — number of comments (overrides settings default)
 *   text       — panel heading; omit for "Recent Comments", set to "" to hide heading
 *   bg_color   — panel background colour (overrides settings default)
 *   text_color — primary text colour (overrides settings default)
 *   debug      — set to "1" to show diagnostic output instead of comments
 */
function daz_coral_recent($atts)
{
    // Use a sentinel to detect whether text was explicitly provided
    extract(lAtts([
        'limit'      => get_pref('daz_coral_recent_limit', 10),
        'text'       => '__default__',
        'bg_color'   => get_pref('daz_coral_bg_color',   '#ffffff'),
        'text_color' => get_pref('daz_coral_text_color', '#000000'),
        'photos'     => get_pref('daz_coral_show_photos', '1'),
        'debug'      => '0',
    ], $atts));

    $limit = (int) $limit;

    if ($debug === '1') {
        $domain = get_pref('daz_coral_domain', '(not set)');
        $token  = get_pref('daz_coral_api_token', '');
        return '<pre style="background:#ffc;padding:10px;font-size:.8rem">'
            . 'daz_coral_recent debug' . "\n"
            . 'Domain: '     . txpspecialchars($domain) . "\n"
            . 'Token: '      . ($token ? substr($token, 0, 20) . '...' : '(not set)') . "\n"
            . 'Limit: '      . $limit . "\n"
            . 'bg_color: '   . txpspecialchars($bg_color) . "\n"
            . 'text_color: ' . txpspecialchars($text_color) . "\n"
            . '</pre>';
    }

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

    // Derive secondary colours from primary text colour at reduced opacity via rgba
    // For simplicity we darken/lighten using hex — just use semi-transparent versions
    $default_avatar = txpspecialchars(
        rtrim(get_pref('daz_coral_photo_url', '/membership/photos/'), '/') . '/' .
        get_pref('daz_coral_default_photo', 'user.jpg')
    );

    $out = <<<CSS
<style>
.dcc-panel{background:{$bg_color};border-radius:12px;padding:20px 24px}
.dcc-panel h5{color:{$text_color};font-weight:700;letter-spacing:.05em;text-transform:uppercase;font-size:.92rem;margin-bottom:18px;padding-bottom:10px;border-bottom:2px solid rgba(0,0,0,.12)}
.dcc-item{padding:12px 0;border-bottom:1px solid rgba(0,0,0,.08);transition:opacity .2s}
.dcc-item:last-child{border-bottom:none;padding-bottom:0}
.dcc-item:hover{opacity:.8}
.dcc-item-top{display:flex;gap:12px}
.dcc-avatar{width:42px;height:42px;border-radius:50%;object-fit:cover;flex-shrink:0;border:2px solid rgba(0,0,0,.12);box-shadow:0 1px 3px rgba(0,0,0,.1)}
.dcc-username{font-weight:700;font-size:1.05rem;color:{$text_color}}
.dcc-date{font-size:.88rem;color:{$text_color};opacity:.5;margin-left:6px}
.dcc-body{font-size:1rem;color:{$text_color};opacity:.85;margin:4px 0 0;line-height:1.45}
.dcc-link{font-size:.88rem;color:{$text_color};opacity:.6;text-decoration:none;font-style:italic;margin-top:6px;display:block}
.dcc-link:hover{opacity:1;text-decoration:underline}
</style>
CSS;

    // Heading
    $out .= '<div class="dcc-panel">';
    if ($text === '__default__') {
        $out .= '<h5>Recent Comments</h5>';
    } elseif ($text !== '') {
        $out .= '<h5>' . txpspecialchars($text) . '</h5>';
    }
    // $text === '' means no heading at all

    foreach ($comments as $comment) {
        $username = $comment['author']['username'] ?? null;
        if (!$username) continue;

        $user_id = $comment['author']['id'] ?? null;
        $body    = txpspecialchars(trim(strip_tags($comment['body'])));
        $title   = $comment['story']['metadata']['title'] ?? null;
        $url     = txpspecialchars($comment['story']['url']);
        $date    = date('j M Y', strtotime($comment['createdAt']));
        $name    = txpspecialchars($username);

        $out .= '<div class="dcc-item">';
        $out .= '<div class="dcc-item-top">';
        if ($photos !== '0') {
            $avatar = txpspecialchars(daz_coral_avatar($user_id));
            $out .= "<img src=\"{$avatar}\" alt=\"{$name}\" class=\"dcc-avatar\" onerror=\"this.src='{$default_avatar}'\">";
        }
        $out .= '<div style="min-width:0;flex:1">';
        $out .= "<span class=\"dcc-username\">{$name}</span>";
        $out .= "<span class=\"dcc-date\">{$date}</span>";
        $out .= "<div class=\"dcc-body\">{$body}</div>";
        $out .= '</div>';
        $out .= '</div>';

        if ($title) {
            $t    = txpspecialchars($title);
            $out .= "<a href=\"{$url}\" class=\"dcc-link\"><i class=\"fas fa-arrow-right me-1\"></i>{$t}</a>";
        }

        $out .= '</div>';
    }

    $out .= '</div>';

    return $out;
}
