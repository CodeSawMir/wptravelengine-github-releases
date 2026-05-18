<?php
/**
 * Dev Zone – GitHub tab.
 *
 * All rendering is handled by github.js. This template only provides the
 * mount point; the JS reads WPTEDZGithub (localized) and wpteDbg for config.
 */
defined( 'ABSPATH' ) || exit;
?>
<div id="wpte-dz-github-root"></div>
<script>
// Called by DomHelper.setServerHtml() after AJAX tab injection re-executes inline scripts.
// window.wpteGithubBoot is exported by github.js (ES module loaded once at page load).
if ( typeof window.wpteGithubBoot === 'function' ) {
	window.wpteGithubBoot();
}
</script>
