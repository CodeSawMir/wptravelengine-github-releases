# WPTE DevZone – GitHub

A sub-plugin for the **WP Travel Engine Dev Zone** that adds a **GitHub** tab for managing releases, issues, and plugin installation directly from the WordPress admin.

---

## Requirements

- **_wptravelengine-devzone-plugin** must be active (provides `AbstractTool`, the shared admin shell, and nav bar injection)
- WordPress admin access with `manage_options` capability
- A GitHub Personal Access Token (PAT) with at minimum: `repo`, `read:org`

---

## Features

### Issues Tab
- Search issues by **URL**, **GitHub Project board URL**, or **title keyword**
- Supports standard issue URLs: `https://github.com/org/repo/issues/123`
- Supports project board URLs: `https://github.com/orgs/Org/projects/N/...?issue=Org|repo|123`
- Keyword search is scoped to the PAT user's organisations (`org:` qualifiers added automatically)
- Each issue card auto-expands to show linked PRs (via GraphQL `timelineItems`)
- Each PR row auto-expands to show releases whose tags were created from that PR's commits

### Repos Tab
- Lists all personal and organisation repositories, grouped by owner
- Collapsible sections; section state persisted in `localStorage`
- Mark repos as favourites (also persisted in `localStorage`)
- Refresh to bust the 24-hour transient cache

### Releases
- Lists all non-draft releases per repository
- Filters to only releases whose tag commit belongs to the expanded PR
- Shows installed plugin version badge and active/inactive state
- One-click install from release zip (supports private repos via GitHub redirect resolution)
- One-click activate for installed plugins

---

## File Structure

```
_wpte_devzone_github/
├── wpte-devzone-github.php      # Plugin bootstrap, autoloader, tab registration
├── includes/
│   ├── class-github-api.php     # All GitHub REST + GraphQL calls
│   ├── class-github-tool.php    # AbstractTool implementation; AJAX handlers
│   └── class-github-installer.php  # Download + WP_Upgrader-based plugin install
├── assets/
│   ├── github.js                # ES module — full frontend app (no build step)
│   └── github.css               # Styles (dark-mode aware via .wte-dbg-dark)
└── templates/
    └── tab-github.php           # Root mount point rendered by DevZone layout
```

---

## How It Works

### Bootstrap (`wpte-devzone-github.php`)
- Hooks into `plugins_loaded`; bails if `AbstractTool` is not available or if not in admin
- Registers a PSR-0-style autoloader mapping `WPTEDZGithub\ClassName` → `includes/class-classname.php`
- Adds a `github` entry to the `wpte_devzone_tabs` filter with `priority: 4`
- Appends a `GithubTool` instance via the `wpte_devzone_tools` filter

### Backend (`class-github-api.php`)
All GitHub communication goes through `GithubApi`:

| Method | Description |
|---|---|
| `validate_token()` | `GET /user` — confirms PAT validity, returns login/name/avatar |
| `get_all_repos()` | Paginates personal + all org repos, deduplicates, sorts by `updated_at` |
| `get_releases()` | Paginates `/repos/{full_name}/releases`, excludes drafts |
| `get_user_orgs()` | `GET /user/orgs`, cached 1 hour per token hash |
| `search_issues()` | `GET /search/issues` with `org:` scoping + `in:title,body type:issue` |
| `parse_issue_url()` | Regex-based: handles standard issue URL and project board `?issue=` param |
| `get_issue()` | `GET /repos/{full_name}/issues/{number}`, rejects pull requests |
| `get_issue_prs()` | GraphQL `timelineItems` (CrossReferenced + Connected events) |
| `get_tags_for_pr()` | GraphQL PR commits (up to 250) intersected against REST tags |
| `download_zip()` | Resolves GitHub redirect before download (private repo support) |

**Caching**
- Repos: `wpte_dz_gh_repos_{hash}` transient — 24 hours
- User validation: `wpte_dz_gh_user_{hash}` transient — 30 minutes
- Orgs: `wpte_dz_gh_orgs_{hash}` transient — 1 hour
- Token hash: `substr(md5($token), 0, 12)` — used as a per-token namespace for all transients

### Frontend (`assets/github.js`)
A self-contained ES module (no build step required). Loaded as `type="module"`.

**State object**
```js
const state = {
    hasToken, user, repos, favs, collapsed,
    tab,          // 'issues' | 'all' | 'favs'
    search,       // repo search filter
    issues,       // current issue search results
    issueSearch,  // last issue query string
};
```

**Tab rendering**
- `renderApp()` — builds the toolbar (tabs, search, user identity) into `#gh-toolbar-inject`
- `renderIssuesGrid()` — shows welcome screen, skeleton loader, or issue cards
- `renderGrid()` — shows repo sections grouped by org owner

**Auto-expansion flow**
1. Issue result arrives → `renderIssuesGrid()` → renders cards → immediately calls `loadIssuePRs()` per card
2. PRs arrive → `renderIssuePRs()` → renders PR rows → immediately calls `loadPrReleases()` per row
3. Releases arrive → filtered by PR commit SHAs → `renderReleases()`

**Race condition guard**
- `renderGrid()` returns early if `state.tab === 'issues'`
- `renderIssuesGrid()` returns early if `state.tab !== 'issues'`
- This prevents stale AJAX responses from polluting the wrong tab's content area

**Caches** (in-memory, reset on page load)
- `releaseCache` — keyed by `full_name`
- `issuePrCache` — keyed by `owner/repo#number`
- `branchTagCache` — keyed by `owner/repo#prNumber`

### AJAX Actions

All actions require a valid nonce (`wpteDbg.nonce`) and `manage_options` capability.

| Action | Handler | Description |
|---|---|---|
| `wpte_dz_gh_validate` | `ajax_validate` | Validate stored token; returns user object |
| `wpte_dz_gh_save_token` | `ajax_save_token` | Save + validate a new PAT |
| `wpte_dz_gh_disconnect` | `ajax_disconnect` | Delete token + all related transients |
| `wpte_dz_gh_fetch_repos` | `ajax_fetch_repos` | Fetch repos (force param busts cache) |
| `wpte_dz_gh_get_releases` | `ajax_get_releases` | Releases for a repo |
| `wpte_dz_gh_get_issue_prs` | `ajax_get_issue_prs` | PRs linked to an issue |
| `wpte_dz_gh_get_branch_tags` | `ajax_get_branch_tags` | Tags for a PR (via commit SHA intersection) |
| `wpte_dz_gh_search_issues` | `ajax_search_issues` | Keyword issue search |
| `wpte_dz_gh_get_issue` | `ajax_get_issue` | Single issue by repo + number |
| `wpte_dz_gh_get_issue_by_url` | `ajax_get_issue_by_url` | Issue from a GitHub or project board URL |
| `wpte_dz_gh_install` | `ajax_install` | Download + install plugin from zip URL |
| `wpte_dz_gh_activate` | `ajax_activate` | Activate an installed plugin |
| `wpte_dz_gh_installed_versions` | `ajax_installed_versions` | Map of all installed plugin names → version/active/file |

---

## WordPress Options Used

| Option | Contents |
|---|---|
| `wpte_dz_github_token` | The stored PAT (plain text) |
| `wpte_dz_github_user` | Cached user object (login, name, avatar_url) |

---

## Security Notes

- All AJAX handlers call `Admin::verify_request()` which checks the nonce and `manage_options` capability
- PAT is stored in `wp_options` (plain text); access is gated behind `manage_options`
- All user/API-sourced strings are passed through `esc()` (via `div.textContent`) before DOM insertion
- GitHub URLs are validated with `preg_match('#^https?://github\.com/#i', ...)` before processing
- Plugin file paths passed to `ajax_activate` are validated against a strict regex before use
