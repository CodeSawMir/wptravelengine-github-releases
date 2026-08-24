# WPTE DevZone – GitHub

Sub-plugin for **WP Travel Engine Dev Zone**. Adds a GitHub tab for release management, issue-driven installs, and a webhook auto-install pipeline.

---

> ### Requirements
> - **_wptravelengine-devzone-plugin** active
> - GitHub PAT with `repo`, `read:org` scopes

---

> ### Features
> - **Issues tab** — search by URL, project board URL, or keyword; auto-loads linked PRs and release tags
> - **Repos tab** — personal + org repos grouped by owner, with favourites
> - **Releases** — one-click install/activate from a release zip, with installed-version and last-installed badges
> - **Webhook auto-install** — GitHub Projects v2 webhook installs a plugin when its issue moves to *Testing* or *Push Zips*
> - **GitHub Downloads log** — last 100 webhook-triggered installs

---

> ### Webhook Setup
>
> 1. `define( 'WPTE_DZ_GITHUB_WEBHOOK_SECRET', 'your-secret' );` in `wp-config.php`
> 2. Add a GitHub webhook: Payload URL from the Issues tab, content type `application/json`, same secret, event **Projects v2 item**
> 3. Move an issue to *Testing* or *Push Zips* — linked PR releases install automatically
>
> Endpoint only registers when **Auto-install on webhook** is enabled (Issues tab toggle).

---

> ### Security
> - AJAX handlers verify nonce + `manage_options`; webhook verifies HMAC-SHA256 with replay protection
> - Only repos owned by the stored user or their orgs can be installed

