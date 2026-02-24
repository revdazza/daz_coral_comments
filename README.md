# daz_coral_comments

A [Textpattern CMS](https://textpattern.com) plugin that integrates the [Coral Project](https://coralproject.net) (Vox Media) commenting system, with Single Sign-On, a recent comments panel, and per-URL comment counts.

Tested on Textpattern 4.9 / PHP 7.4.

---

## Features

- **SSO** — logged-in Textpattern users are automatically signed into Coral via JWT, no separate Coral account required
- **Recent comments panel** — styled Bootstrap 5 panel showing your latest comments across all articles
- **Comment counts** — embed Coral's `count.js` to display per-article comment counts
- **Admin token generator** — generate a permanent Coral API token directly from the TXP admin panel, no server CLI access needed
- **Profile photo support** — optionally display user avatars alongside recent comments

---

## Requirements

- Textpattern 4.8+ (developed and tested on 4.9)
- PHP 7.4+
- A self-hosted Coral instance with SSO enabled
- Bootstrap 5 and Font Awesome 5 loaded on your front-end (for `daz_coral_recent`)

---

## Installation

1. Download `daz_coral_comments.php`
2. In the Textpattern admin panel go to **Admin → Plugins**
3. Upload the file and activate the plugin
4. Set the plugin type to **Front and back**
5. Click **Options** to open the settings page

---

## Configuration

Open **Admin → Plugins → daz_coral_comments → Options**.

| Setting | Description |
|---|---|
| Coral domain | Full URL of your Coral instance, e.g. `https://comments.example.com` |
| SSO secret | The secret from your Coral **Configure → Authentication → SSO** page |
| Session key — user ID | PHP `$_SESSION` key that holds the logged-in user's ID |
| Session key — email | PHP `$_SESSION` key that holds the user's email address |
| Session key — username | PHP `$_SESSION` key that holds the display name |
| Photo path | Server filesystem path to user photo directory, e.g. `/var/www/membership/photos` |
| Photo URL | Public URL prefix for photos, e.g. `https://example.com/membership/photos` |
| Default photo URL | Fallback avatar URL when no user photo exists |
| Default recent limit | How many recent comments to show (overridable per tag) |
| Background colour | Hex colour for the recent comments panel, e.g. `#1a1a2e` |
| Text colour | Hex colour for text in the panel, e.g. `#e0e0e0` |
| Show profile photos | Whether to display user avatars in the recent comments panel |

### Generating an API token

In the **Settings** tab, enter your Coral admin email and password and click **Generate Token**. The plugin authenticates against `/api/auth/local` and then calls the GraphQL `createToken` mutation to produce a permanent token, which is stored in the plugin prefs.

---

## Tags

### `<txp:daz_coral_embed />`

Embeds the Coral comment stream. For logged-in users it passes a signed SSO JWT automatically.

```html
<txp:daz_coral_embed />
```

No attributes — reads all configuration from plugin prefs.

---

### `<txp:daz_coral_recent />`

Displays a styled panel of recent comments across your site.

```html
<txp:daz_coral_recent limit="10" />
```

| Attribute | Default | Description |
|---|---|---|
| `limit` | plugin pref | Number of comments to show |
| `text` | `Recent Comments` | Panel heading |
| `bg_color` | plugin pref | Panel background colour |
| `text_color` | plugin pref | Panel text colour |
| `photos` | plugin pref | `1` to show avatars, `0` to hide |
| `debug` | `0` | `1` to output domain and token info |

---

### `<txp:daz_coral_count />`

Embeds Coral's `count.js` script to render comment counts.

```html
<txp:daz_coral_count />
```

| Attribute | Default | Description |
|---|---|---|
| `url` | current page URL | Override the URL to count comments for |
| `notext` | `0` | `1` to suppress the "Comments" label |

Add a `data-coral-count` attribute to any element where you want the count to appear:

```html
<a href="#coral_thread" data-coral-count data-coral-count-href="https://example.com/article">Comments</a>
<txp:daz_coral_count />
```

---

## SSO Notes

Coral SSO uses HS256 JWT tokens. The plugin generates tokens with:
- `user.id` — value of your session user ID key
- `user.email` — value of your session email key
- `user.username` — value of your session username key
- 1-hour expiry
- UUID `jti` claim

The SSO secret is taken from the **SSO secret** pref. If your secret starts with `ssosec_` (the Coral admin panel prefix), that prefix is stripped automatically before signing.

---

## Styling

`daz_coral_recent` uses Bootstrap 5 classes. The panel also exposes these CSS classes for custom styling:

| Class | Element |
|---|---|
| `.daz-coral-panel` | Outer panel wrapper |
| `.daz-coral-header` | Panel heading bar |
| `.daz-coral-item` | Individual comment row |
| `.daz-coral-avatar` | User photo |
| `.daz-coral-username` | Comment author name |
| `.daz-coral-body` | Comment text |
| `.daz-coral-meta` | Article title / timestamp row |
| `.daz-coral-title` | Article title link |
| `.daz-coral-time` | Relative timestamp |

---

## License

MIT
