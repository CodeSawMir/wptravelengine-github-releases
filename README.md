# WPTE DevZone – GitHub

Sub-plugin for **WP Travel Engine Dev Zone**. Adds a GitHub tab for release management, issue-driven installs, and a webhook auto-install pipeline.

---

## Requirements

- **_wptravelengine-devzone-plugin** active (provides `AbstractTool` and shared admin shell)
- WordPress `manage_options` capability
- GitHub PAT with `repo`, `read:org` scopes

---

## Features

- **Issues tab** — search by URL, project board URL, or keyword; auto-loads linked PRs and release tags
- **Repos tab** — all personal + org repos grouped by owner, with favourites and collapsible sections
- **Releases** — one-click install/activate from release zip; scrollable release list with a per-repo tag refetch; shows installed-version and last-installed badges plus branch name
- **Webhook auto-install** — GitHub Projects v2 webhook installs plugins when an issue is moved to *Testing* or *Push Zips*; togglable per-admin
- **GitHub Downloads log** — last 100 webhook-triggered installs (success + failure) in the Logs tab

---

## File Structure

```
_wptravelengine-github-releases/
├── wpte-devzone-github.php
├── includes/
│   ├── constants.php
│   ├── plugin.php                      # Plugin::boot() — all hook registration
│   ├── class-github-api.php
│   ├── class-github-tool.php           # AbstractTool + AJAX handlers
│   ├── class-github-installer.php      # Download, extract, slug-map, install
│   ├── class-github-rest-controller.php # Webhook endpoint
│   └── class-github-downloads-tool.php
├── assets/
│   ├── github.js
│   └── github.css
└── templates/
    ├── tab-github.php
    └── tab-logs-github-downloads.php
```

---

## Webhook Setup

1. Set a secret in `wp-config.php`:
   ```php
   define( 'WPTE_DZ_GITHUB_WEBHOOK_SECRET', 'your-secret' );
   ```
   Default (testing only): `wpte-devzone-github-testing`
2. Add a webhook in GitHub → Settings → Webhooks:
   - **Payload URL**: shown in the Issues tab
   - **Content type**: `application/json`
   - **Secret**: same value as above
   - **Events**: Projects v2 item
3. Move an issue to *Testing* or *Push Zips* — linked PR releases are installed automatically

The endpoint is only registered when **Auto-install on webhook** is enabled (toggle in Issues tab).

---

## WordPress Options

| Option | Contents |
|---|---|
| `wpte_dz_github_token` | Stored PAT |
| `wpte_dz_github_user` | Cached user object |
| `wpte_dz_github_auto_install` | `yes` / `no` — controls endpoint registration |
| `wpte_dz_github_download_log` | Last 100 webhook install records |
| `wpte_dz_github_last_download_ts` | Timestamp of most recent download |
| `wpte_dz_github_favorites` | Favourited repo `full_name`s |
| `wpte_dz_github_last_installed` | Per-repo map of most recently installed tag + timestamp |

---

## AJAX Actions

All require a valid nonce and `manage_options`.

| Action | Description |
|---|---|
| `wpte_dz_gh_validate` | Validate stored token |
| `wpte_dz_gh_save_token` | Save + validate new PAT |
| `wpte_dz_gh_disconnect` | Delete token + transients |
| `wpte_dz_gh_fetch_repos` | Fetch repos (force param busts cache) |
| `wpte_dz_gh_get_releases` | Releases for a repo |
| `wpte_dz_gh_get_issue_prs` | PRs linked to an issue |
| `wpte_dz_gh_get_branch_tags` | Tags for a PR |
| `wpte_dz_gh_search_issues` | Keyword issue search |
| `wpte_dz_gh_get_issue` | Single issue by repo + number |
| `wpte_dz_gh_get_issue_by_url` | Issue from a GitHub or project board URL |
| `wpte_dz_gh_install` | Download + install plugin from zip |
| `wpte_dz_gh_activate` | Activate an installed plugin |
| `wpte_dz_gh_installed_versions` | All installed plugins → version/active/file |
| `wpte_dz_gh_get_download_log` | Retrieve download log |
| `wpte_dz_gh_set_auto_install` | Enable / disable webhook endpoint |
| `wpte_dz_gh_get_favorites` | Retrieve favourited repos |
| `wpte_dz_gh_save_favorites` | Persist favourited repos |

---

## Security

- All AJAX handlers verify nonce + `manage_options` via `Admin::verify_request()`
- Webhook endpoint verifies HMAC-SHA256 (`X-Hub-Signature-256`) and replays via `X-GitHub-Delivery` transient
- Only repos whose owner matches the stored user or their orgs are installed
- All user/API strings pass through `esc()` before DOM insertion
- Plugin paths validated with strict regex before `activate_plugin()`

---

## Changelog

### 1.1.0
- Repos/Favs/Issues sub-tab order fixed, Repos is now the default tab on load
- Repos/Favs tab count badges no longer get stuck on load — repos now fetch automatically instead of waiting for a manual "Load repositories" click
- Favourites now persist server-side in a single option instead of `localStorage`
- Releases list is scrollable instead of paginated; the page indicator auto-updates on scroll and still supports prev/next
- Per-repo "Refetch tags" control refreshes just that repo's releases, bypassing the cache
- "Last installed" pill on the release row matching a repo's most recently installed tag, tracked in a new option

### 1.0.1
- Webhook auto-install via GitHub Projects v2 (HMAC-SHA256, replay protection, column gate, trusted-owner allowlist)
- GitHub Downloads log tab — last 100 installs with success/failure rows

### 1.0.0
- Initial release
