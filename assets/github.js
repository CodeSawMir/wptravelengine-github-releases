/* global WPTEDZGithub, wpteDbg */

/**
 * XSS Protection: All user-supplied and API-sourced strings are passed through
 * esc() before any DOM insertion. esc() uses div.textContent to HTML-encode the
 * string, making it safe for innerHTML. No raw user input is ever inserted
 * without escaping. Static markup (SVG icons, structural HTML) is hardcoded.
 */

// ---- State ----
const state = {
	hasToken        : WPTEDZGithub.has_token,
	user            : WPTEDZGithub.user || {},
	repos           : [],
	favs            : loadFavs(),
	collapsed       : loadCollapsed(),
	tab             : 'issues',  // all | favs | issues
	search          : '',
	installedPlugins: {},
	issues          : [],        // current issue search results
	issueSearch     : '',        // last issue query
};

var searchTimer;

const releaseCache    = {};
const issuePrCache    = {};   // "owner/repo#number" → prs[]
const branchTagCache  = {};   // "owner/repo#branch"  → string[]

// ---- HTML escape helper (XSS protection) ----
// All untrusted strings MUST pass through esc() before DOM insertion.
function esc( str ) {
	const d = document.createElement( 'div' );
	d.textContent = String( str || '' );
	return d.innerHTML;
}

// ---- Favourites (localStorage) ----
function loadFavs() {
	try { return JSON.parse( localStorage.getItem( 'wpte_dz_github_favs' ) || '[]' ); }
	catch (e) { return []; }
}
function saveFavs() {
	try { localStorage.setItem( 'wpte_dz_github_favs', JSON.stringify( state.favs ) ); }
	catch (e) {}
}
function isFav( full_name ) { return state.favs.indexOf( full_name ) !== -1; }
function toggleFav( full_name ) {
	const idx = state.favs.indexOf( full_name );
	if ( idx === -1 ) state.favs.push( full_name );
	else state.favs.splice( idx, 1 );
	saveFavs();
}

// ---- Collapsed sections (localStorage) ----
function loadCollapsed() {
	try { return JSON.parse( localStorage.getItem( 'wpte_dz_github_collapsed' ) || '[]' ); }
	catch (e) { return []; }
}
function saveCollapsed() {
	try { localStorage.setItem( 'wpte_dz_github_collapsed', JSON.stringify( state.collapsed ) ); }
	catch (e) {}
}
function isSectionCollapsed( owner ) {
	return state.collapsed.indexOf( owner.toLowerCase() ) !== -1;
}
function toggleSectionCollapsed( owner ) {
	var key = owner.toLowerCase();
	var idx = state.collapsed.indexOf( key );
	if ( idx === -1 ) state.collapsed.push( key );
	else state.collapsed.splice( idx, 1 );
	saveCollapsed();
}

// ---- AJAX helper ----
function post( action, data ) {
	const params = Object.assign( { action, _ajax_nonce: wpteDbg.nonce }, data || {} );
	const body   = new URLSearchParams( params );
	return fetch( wpteDbg.ajaxurl, { method: 'POST', body } ).then( function( r ) { return r.json(); } );
}

// ---- Status notice helper ----
// Wraps window.wteDbgSetStatus (devzone shared API — targets #wte-dbg-wp-debug-notice).
// info  — persists (in-flight); success/error/cancelled — auto-clear after 4 s.
function setStatus( msg, type ) {
	if ( typeof window.wteDbgSetStatus !== 'function' ) return;
	var secs = ( type === 'success' || type === 'error' || type === 'cancelled' ) ? 4 : null;
	window.wteDbgSetStatus( msg, type || 'info', secs );
}
function clearStatus() {
	if ( typeof window.wteDbgClearStatus === 'function' ) window.wteDbgClearStatus();
}

// ---- Boot ----
function boot() {
	if ( state.hasToken ) {
		// Token exists — keep the nav bar visible during validation so the
		// toolbar inject doesn't flash invisible while the AJAX call is in flight.
		// Validate the stored PAT before rendering the app — the token may
		// have been revoked since the last visit.
		post( 'wpte_dz_gh_validate' ).then( function( res ) {
			if ( res.success ) {
				state.user = res.data.user || state.user;
				loadInstalledVersions();
				renderApp();
				loadRepos();
			} else {
				// Token invalid; clear local state and show connect screen.
				state.hasToken = false;
				state.user     = {};
				renderConnect( res.data && res.data.message ? res.data.message : '' );
			}
		} ).catch( function() {
			// Network/server error — assume token is still valid and proceed.
			loadInstalledVersions();
			renderApp();
			loadRepos();
		} );
	} else {
		// No token — hide the nav bar and show the connect screen.
		setSubtabBar( false );
		renderConnect();
	}
}

// ---- Connect screen ----
// NOTE: innerHTML is used with hardcoded structural markup only.
// User-visible text that could contain untrusted data is inserted with textContent.
function setSubtabBar( visible ) {
	var tabs = document.querySelector( '.wte-dbg-tabs' );
	if ( ! tabs ) return;
	tabs.classList.toggle( 'gh-nav-hidden', ! visible );
	// DevZone PHP may render is-hidden when there are no real subtabs; remove it too.
	if ( visible ) tabs.classList.remove( 'is-hidden' );
}

function renderConnect( errorMsg ) {
	var root   = document.getElementById( 'wpte-dz-github-root' );
	var inject = document.getElementById( 'gh-toolbar-inject' );
	if ( inject ) inject.innerHTML = '';
	setSubtabBar( false );

	// Build using DOM methods for the user-facing areas, template for structure.
	var initError = errorMsg ? '<div class="wte-dbg-error-notice" style="margin-bottom:12px;text-align:left;">' + esc( errorMsg ) + '</div>' : '';
	root.innerHTML = [
		'<div class="gh-body">',
		'<div class="gh-connect">',
		'<div class="gh-connect-card">',
		'<div class="gh-connect__icon">' + githubIcon( 30 ) + '</div>',
		'<div class="gh-connect__header">',
		'<h2>Connect GitHub Account</h2>',
		'<p>Generate a <a href="https://github.com/settings/tokens/new?scopes=repo,read:org" target="_blank" rel="noopener">Personal Access Token</a>',
		' with <code>repo</code> and <code>read:org</code> scopes, then paste it below.</p>',
		'</div>',
		initError,
		'<div class="gh-token-form">',
		'<label for="gh-token-input">Personal Access Token</label>',
		'<div class="gh-input-wrap">',
		'<svg class="gh-input-prefix-icon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>',
		'<input type="password" id="gh-token-input" class="gh-token-input" placeholder="ghp_xxxxxxxxxxxxxxxxxxxx" autocomplete="new-password" spellcheck="false">',
		'</div>',
		'<div class="wte-dbg-error-notice" id="gh-token-error"></div>',
		'<button class="gh-btn--connect" id="gh-connect-btn">' + githubIcon( 16 ) + ' Connect to GitHub</button>',
		'<p class="gh-connect__hint">Your token is stored in the WordPress database and never shared.</p>',
		'</div></div></div></div>',
	].join( '' );

	document.getElementById( 'gh-connect-btn' ).addEventListener( 'click', doConnect );
	document.getElementById( 'gh-token-input' ).addEventListener( 'keydown', function( e ) {
		if ( e.key === 'Enter' ) doConnect();
	} );
}

function doConnect() {
	var input = document.getElementById( 'gh-token-input' );
	var btn   = document.getElementById( 'gh-connect-btn' );
	var err   = document.getElementById( 'gh-token-error' );
	var token = input.value.trim();

	err.style.display = 'none';

	if ( ! token ) {
		err.textContent   = 'Please enter a token.';
		err.style.display = 'block';
		return;
	}

	btn.disabled  = true;
	btn.innerHTML = '<span class="gh-spinner"></span> Connecting\u2026';

	post( 'wpte_dz_gh_save_token', { token: token } ).then( function( res ) {
		if ( res.success ) {
			window.location.reload();
		} else {
			err.textContent   = ( res.data && res.data.message ) ? res.data.message : 'Connection failed.';
			err.style.display = 'block';
			btn.disabled      = false;
			btn.innerHTML     = githubIcon( 15 ) + ' Connect to GitHub';
		}
	} ).catch( function() {
		err.textContent   = 'Request failed.';
		err.style.display = 'block';
		btn.disabled      = false;
		btn.innerHTML     = githubIcon( 15 ) + ' Connect to GitHub';
	} );
}

// ---- App shell ----
function renderApp() {
	// Fill toolbar into the nav bar inject container.
	var inject = document.getElementById( 'gh-toolbar-inject' );
	if ( inject ) {
		inject.innerHTML = [
			'<div class="gh-toolbar">',
			'<div class="wte-dbg-search-wrap">',
			'<input type="text" id="gh-search" class="wte-dbg-search-input" placeholder="Search repositories\u2026">',
			'<span class="wte-dbg-search-count" id="gh-search-count"></span>',
			'</div>',
			'<button class="wte-dbg-refresh-btn" id="gh-refresh" title="Refresh repositories"></button>',
			'<span class="gh-toolbar-sep"></span>',
			'<div class="gh-tabs" id="gh-tabs">',
			'<button class="gh-tab' + ( state.tab === 'issues' ? ' is-active' : '' ) + '" data-tab="issues">Issues <span class="wte-dbg-count-badge" id="tab-count-issues"></span></button>',
			'<button class="gh-tab' + ( state.tab === 'all'    ? ' is-active' : '' ) + '" data-tab="all">Repos <span class="wte-dbg-count-badge" id="tab-count-all">\u2026</span></button>',
			'<button class="gh-tab gh-tab--fav' + ( state.tab === 'favs' ? ' is-active' : '' ) + '" data-tab="favs" style="display:' + ( state.favs.length > 0 ? '' : 'none' ) + '">\u2605 Favs <span class="wte-dbg-count-badge" id="tab-count-favs">0</span></button>',
			'</div>',
			'<span id="gh-user-info" class="gh-user-info"></span>',
			'</div>',
		].join( '' );

		// User identity — set via DOM (not innerHTML) for XSS safety.
		var userInfo = inject.querySelector( '.gh-user-info' );
		if ( userInfo ) {
			if ( state.user.avatar_url ) {
				var img = document.createElement( 'img' );
				img.src            = state.user.avatar_url;
				img.alt            = '';
				img.className      = 'gh-user-avatar';
				img.referrerPolicy = 'no-referrer';
				userInfo.appendChild( img );
			}
			var nameEl = document.createElement( 'span' );
			nameEl.className   = 'gh-user-name';
			nameEl.textContent = state.user.name || state.user.login || '';
			userInfo.appendChild( nameEl );

			var disc = document.createElement( 'button' );
			disc.className = 'gh-disconnect-btn';
			disc.title     = 'Disconnect GitHub account';
			disc.setAttribute( 'aria-label', 'Disconnect' );
			// Static SVG — not user input, safe for innerHTML.
			disc.innerHTML = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>';
			disc.addEventListener( 'click', doDisconnect );
			userInfo.appendChild( disc );
		}

		// Wire toolbar events.
		document.getElementById( 'gh-search' ).addEventListener( 'input', function( e ) {
			clearTimeout( searchTimer );
			var val = e.target.value.trim();
			if ( state.tab === 'issues' ) {
				state.issueSearch = val;
				if ( val.length >= 2 ) {
					var wrap = document.getElementById( 'gh-grid-wrap' );
					if ( wrap ) wrap.innerHTML = issuesSkeletonHtml();
				}
				searchTimer = setTimeout( function() {
					val.length >= 2 ? searchIssues( val ) : renderIssuesGrid();
				}, 2000 );
			} else {
				searchTimer = setTimeout( function() { state.search = val; sectionPages = {}; renderGrid(); }, 200 );
			}
		} );

		document.getElementById( 'gh-search' ).addEventListener( 'keydown', function( e ) {
			if ( e.key !== 'Enter' || state.tab !== 'issues' ) return;
			clearTimeout( searchTimer );
			var val = e.target.value.trim();
			state.issueSearch = val;
			val.length >= 2 ? searchIssues( val ) : renderIssuesGrid();
		} );

		document.getElementById( 'gh-tabs' ).addEventListener( 'click', function( e ) {
			var btn = e.target.closest( '[data-tab]' );
			if ( ! btn ) return;
			var newTab = btn.dataset.tab;
			if ( newTab === state.tab ) return;
			state.tab = newTab;
			document.querySelectorAll( '.gh-tab' ).forEach( function( b ) {
				b.classList.toggle( 'is-active', b.dataset.tab === state.tab );
			} );
			syncToolbarForTab();
			state.tab === 'issues' ? renderIssuesGrid() : renderGrid();
		} );

		document.getElementById( 'gh-refresh' ).addEventListener( 'click', function() { loadRepos( true ); } );

		syncToolbarForTab();
	}

	setSubtabBar( true );

	// Content area: only the grid (toolbar is in the nav bar).
	var root = document.getElementById( 'wpte-dz-github-root' );
	root.innerHTML = '<div id="gh-grid-wrap" class="gh-grid-wrap"></div>';

	// Render the correct initial content for the active tab.
	if ( state.tab === 'issues' ) {
		renderIssuesGrid();
	} else {
		var wrap = document.getElementById( 'gh-grid-wrap' );
		if ( wrap ) {
			wrap.innerHTML = '<div class="gh-idle-state">'
				+ githubIcon( 28 )
				+ '<p>Click <strong>Load repositories</strong> to fetch your GitHub repos.</p>'
				+ '<button class="gh-btn--load-repos" id="gh-load-repos-btn">Load repositories</button>'
				+ '</div>';
			document.getElementById( 'gh-load-repos-btn' ).addEventListener( 'click', function() {
				loadRepos( true );
			} );
		}
	}
}

function doDisconnect() {
	if ( ! confirm( 'Disconnect GitHub? Your token will be removed.' ) ) return;
	setStatus( 'Disconnecting\u2026', 'info' );
	post( 'wpte_dz_gh_disconnect' ).then( function() {
		state.hasToken  = false;
		state.user      = {};
		state.repos     = [];
		setStatus( 'Disconnected from GitHub.', 'success' );
		setTimeout( clearStatus, 2000 );
		renderConnect();
	} ).catch( function() {
		setStatus( 'Disconnect failed: network error.', 'error' );
	} );
}

// ---- Load repos ----
function loadRepos( force ) {
	var onReposTab = state.tab !== 'issues';

	if ( ! force && state.repos.length ) {
		if ( onReposTab ) renderGrid();
		return;
	}

	if ( onReposTab ) {
		var wrap = document.getElementById( 'gh-grid-wrap' );
		if ( wrap ) wrap.innerHTML = loadingStateHtml( 'Fetching repositories\u2026' );
		setStatus( ( force ? 'Refreshing' : 'Loading' ) + ' repositories\u2026', 'info' );
	}

	post( 'wpte_dz_gh_fetch_repos', force ? { force: 1 } : {} ).then( function( res ) {
		if ( res.success ) {
			state.repos = res.data.repos;
			if ( state.tab !== 'issues' ) {
				setStatus( res.data.repos.length + ' repositories loaded.', 'success' );
				renderGrid();
			}
		} else {
			if ( state.tab !== 'issues' ) {
				var msg = ( res.data && res.data.message ) ? res.data.message : 'Failed to load repos.';
				var w = document.getElementById( 'gh-grid-wrap' );
				if ( w ) w.innerHTML = errorStateHtml( msg );
				setStatus( 'Failed to load repos: ' + msg, 'error' );
			}
		}
	} ).catch( function() {
		if ( state.tab !== 'issues' ) {
			var w = document.getElementById( 'gh-grid-wrap' );
			if ( w ) w.innerHTML = errorStateHtml( 'Request failed.' );
			setStatus( 'Failed to load repos: network error.', 'error' );
		}
	} );
}

// ---- Load installed plugin versions ----
function loadInstalledVersions() {
	post( 'wpte_dz_gh_installed_versions' ).then( function( res ) {
		if ( res.success ) {
			state.installedPlugins = res.data.plugins || {};
		}
	} ).catch( function() {} );
}

// ---- Sync toolbar state for the active tab ----
function syncToolbarForTab() {
	var si = document.getElementById( 'gh-search' );
	var rb = document.getElementById( 'gh-refresh' );
	if ( state.tab === 'issues' ) {
		if ( si ) { si.placeholder = 'Paste a GitHub issue URL or keyword\u2026'; si.value = state.issueSearch; }
		if ( rb ) rb.style.display = 'none';
	} else {
		if ( si ) { si.placeholder = 'Search repositories\u2026'; si.value = state.search; }
		if ( rb ) rb.style.display = '';
	}
}

// ---- Render grid ----
function renderGrid() {
	if ( state.tab === 'issues' ) return;

	var repos = filteredRepos();
	var wrap  = document.getElementById( 'gh-grid-wrap' );
	if ( ! wrap ) return;

	var allCount = state.repos.length;
	var favCount = state.repos.filter( function( r ) { return isFav( r.full_name ); } ).length;

	var countEl     = document.getElementById( 'tab-count-all' );
	var favEl       = document.getElementById( 'tab-count-favs' );
	var favTabBtn   = document.querySelector( '.gh-tab--fav' );
	var searchCount = document.getElementById( 'gh-search-count' );
	if ( countEl     ) countEl.textContent     = allCount;
	if ( favEl       ) favEl.textContent       = favCount;
	if ( searchCount ) searchCount.textContent = repos.length === 1 ? '1 repo' : repos.length + ' repos';

	// Show favs tab only when there is at least one favourite.
	if ( favTabBtn ) favTabBtn.style.display = favCount > 0 ? '' : 'none';

	// If currently on favs tab but no favs remain, switch back to all.
	if ( state.tab === 'favs' && favCount === 0 ) {
		state.tab = 'all';
		document.querySelectorAll( '.gh-tab' ).forEach( function( b ) {
			b.classList.toggle( 'is-active', b.dataset.tab === 'all' );
		} );
	}

	if ( repos.length === 0 ) {
		wrap.innerHTML = emptyStateHtml( state.search ? 'No repos match your search.' : 'No repositories found.' );
		return;
	}

	// Build sectioned grid — repos grouped by owner, priority orgs pinned first.
	// All untrusted strings (owner names) are passed through esc() per XSS policy.
	var groups     = groupReposByOwner( repos );
	var multiGroup = groups.length > 1;
	var cardIdx    = 0;

	var sectionsHtml = groups.map( function( g ) {
		var safeOwner  = esc( g.owner );
		var collapsed  = multiGroup && ! state.search && isSectionCollapsed( g.owner );
		var total      = g.repos.length;
		var totalPages = Math.max( 1, Math.ceil( total / REPO_PAGE_SIZE ) );
		var page       = Math.min( sectionPages[ g.owner ] || 1, totalPages );
		var sliced     = g.repos.slice( ( page - 1 ) * REPO_PAGE_SIZE, page * REPO_PAGE_SIZE );
		var gridCards  = sliced.map( function( r ) { return cardHtml( r, cardIdx++ ); } ).join( '' );

		var initials = esc( nameInitials( g.owner ) );
		var count    = total + ' repo' + ( total === 1 ? '' : 's' );
		var pag      = '';
		if ( totalPages > 1 ) {
			pag = '<div class="gh-section__pagination wte-dbg-pagination">'
				+ '<span class="wte-dbg-page-btn sec-page-prev"' + ( page === 1 ? ' aria-disabled="true"' : '' ) + '>\u2039</span>'
				+ '<span class="gh-page-indicator">' + page + ' / ' + totalPages + '</span>'
				+ '<span class="wte-dbg-page-btn sec-page-next"' + ( page === totalPages ? ' aria-disabled="true"' : '' ) + '>\u203a</span>'
				+ '</div>';
		}
		var header = '<div class="gh-section__header" data-owner="' + safeOwner + '">'
			+ '<button class="gh-section__toggle" aria-expanded="' + ( collapsed ? 'false' : 'true' ) + '">'
			+ '<span class="gh-section__avatar" aria-hidden="true">' + initials + '</span>'
			+ '<span class="gh-section__owner">' + safeOwner + '</span>'
			+ '<span class="gh-section__count">' + count + '</span>'
			+ pag
			+ ( multiGroup ? '<svg class="gh-section__chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>' : '' )
			+ '</button>'
			+ '</div>';
		return '<div class="gh-section' + ( collapsed ? ' is-collapsed' : '' ) + '" data-owner="' + safeOwner + '">'
			+ header
			+ '<div class="gh-grid">' + gridCards + '</div>'
			+ '</div>';
	} ).join( '' );

	wrap.innerHTML = '<div class="gh-sections" id="gh-grid">' + sectionsHtml + '</div>';

	// Section collapse toggle via event delegation.
	document.getElementById( 'gh-grid' ).addEventListener( 'click', function( e ) {
		if ( e.target.closest( '.gh-section__pagination' ) ) return; // pagination spans inside toggle
		var hdr = e.target.closest( '.gh-section__header' );
		if ( ! hdr ) return;
		var section        = hdr.closest( '.gh-section' );
		var owner          = hdr.dataset.owner;
		var isNowCollapsed = section.classList.toggle( 'is-collapsed' );
		var toggle         = hdr.querySelector( '.gh-section__toggle' );
		if ( toggle ) toggle.setAttribute( 'aria-expanded', isNowCollapsed ? 'false' : 'true' );
		toggleSectionCollapsed( owner );
	} );

	// Section pagination via event delegation.
	document.getElementById( 'gh-grid' ).addEventListener( 'click', function( e ) {
		var btn = e.target.closest( '.gh-section__pagination .wte-dbg-page-btn' );
		if ( ! btn || btn.getAttribute( 'aria-disabled' ) === 'true' ) return;
		var hdr   = btn.closest( '.gh-section__header' );
		var owner = hdr.dataset.owner;
		var cur   = sectionPages[ owner ] || 1;
		sectionPages[ owner ] = btn.classList.contains( 'sec-page-prev' ) ? cur - 1 : cur + 1;
		renderGrid();
	} );

	// Card open/close via event delegation.
	document.getElementById( 'gh-grid' ).addEventListener( 'click', function( e ) {
		if ( e.target.closest( '.gh-section__header' ) ) return;
		if ( e.target.closest( '.gh-fav-btn' ) ) return;
		var card = e.target.closest( '.gh-card' );
		if ( ! card || ! e.target.closest( '.gh-card__head, .gh-card__expand-btn' ) ) return;

		var fullName = card.dataset.repo;
		var wasOpen  = card.classList.contains( 'is-open' );
		document.querySelectorAll( '.gh-card.is-open' ).forEach( function( c ) { c.classList.remove( 'is-open' ); } );
		if ( ! wasOpen ) {
			card.classList.add( 'is-open' );
			loadReleases( card, fullName );
		}
	} );

	// Fav toggle via event delegation.
	document.getElementById( 'gh-grid' ).addEventListener( 'click', function( e ) {
		var btn = e.target.closest( '.gh-fav-btn' );
		if ( ! btn ) return;
		e.stopPropagation();
		var fullName = btn.dataset.repo;
		toggleFav( fullName );
		btn.classList.toggle( 'is-fav', isFav( fullName ) );
		var fc      = state.repos.filter( function( r ) { return isFav( r.full_name ); } ).length;
		var fe      = document.getElementById( 'tab-count-favs' );
		var favTab  = document.querySelector( '.gh-tab--fav' );
		if ( fe     ) fe.textContent       = fc;
		if ( favTab ) favTab.style.display = fc > 0 ? '' : 'none';
		var wasOnFavs = state.tab === 'favs';
		if ( fc === 0 && wasOnFavs ) {
			state.tab = 'all';
			document.querySelectorAll( '.gh-tab' ).forEach( function( b ) {
				b.classList.toggle( 'is-active', b.dataset.tab === 'all' );
			} );
		}
		if ( wasOnFavs ) renderGrid();
	} );
}

function filteredRepos() {
	return state.repos.filter( function( r ) {
		if ( state.tab === 'favs' && ! isFav( r.full_name ) ) return false;
		if ( state.search ) {
			var q = state.search.toLowerCase();
			return r.name.toLowerCase().indexOf( q ) !== -1
				|| ( r.description || '' ).toLowerCase().indexOf( q ) !== -1
				|| r.owner.toLowerCase().indexOf( q ) !== -1;
		}
		return true;
	} );
}

// Returns initials from a hyphen/underscore/space-separated name.
// Multi-word: first letter of each word (max 3). Single-word: first 2 chars.
function nameInitials( name ) {
	var parts = ( name || '' ).split( /[-_\s]+/ ).filter( Boolean );
	return ( parts.length > 1
		? parts.map( function( p ) { return p[ 0 ]; } ).join( '' ).slice( 0, 3 )
		: ( name || '' ).slice( 0, 2 )
	).toUpperCase();
}

// Group repos by owner, with priority orgs pinned first, then alphabetical.
// Within Codewing-Solutions, wp-travel-engine is pinned first.
var PRIORITY_OWNERS = [ 'wptravelengine', 'codewing-solutions' ];
var REPO_PAGE_SIZE  = 10;
var sectionPages    = {}; // current page per owner key
var PRIORITY_REPOS  = { 'codewing-solutions': [ 'wptravelengine' ] };

function groupReposByOwner( repos ) {
	var map = {};
	repos.forEach( function( r ) {
		if ( ! map[ r.owner ] ) map[ r.owner ] = [];
		map[ r.owner ].push( r );
	} );

	function ownerRank( name ) {
		var idx = PRIORITY_OWNERS.indexOf( name.toLowerCase() );
		return idx !== -1 ? idx : PRIORITY_OWNERS.length;
	}

	function repoRank( owner, name ) {
		var pinned = PRIORITY_REPOS[ owner.toLowerCase() ] || [];
		var n      = name.toLowerCase();
		var idx    = pinned.indexOf( n );
		if ( idx !== -1 ) return idx;                          // exact pinned match
		var hasPrefix = pinned.some( function( p ) {
			return n !== p && n.indexOf( p ) === 0;            // starts with a pinned name
		} );
		return hasPrefix ? pinned.length : pinned.length + 1; // prefix group before the rest
	}

	return Object.keys( map ).sort( function( a, b ) {
		return ownerRank( a ) - ownerRank( b ) || a.toLowerCase().localeCompare( b.toLowerCase() );
	} ).map( function( owner ) {
		var sorted = map[ owner ].slice().sort( function( a, b ) {
			return repoRank( owner, a.name ) - repoRank( owner, b.name );
		} );
		return { owner: owner, repos: sorted };
	} );
}

// Language colour map (GitHub's official palette subset).
var LANG_COLORS = {
	JavaScript: '#f1e05a', TypeScript: '#3178c6', PHP: '#4f5d95', Python: '#3572a5',
	CSS: '#563d7c', HTML: '#e34c26', Vue: '#41b883', Shell: '#89e051',
	Go: '#00add8', Ruby: '#701516', Rust: '#dea584', Java: '#b07219',
	'C#': '#178600', 'C++': '#f34b7d', C: '#555555', Swift: '#f05138',
};

// All repo data (name, owner, description, full_name) is escaped via esc() below.
function cardHtml( r, i ) {
	var fav      = isFav( r.full_name );
	var initials = esc( nameInitials( r.owner ) + '/' + nameInitials( r.name ) );

	// Meta row: language · stars · forks · updated
	var metaParts = [];
	if ( r.language ) {
		var langColor = LANG_COLORS[ r.language ] || '#8b949e';
		metaParts.push(
			'<span class="gh-card__lang">' +
			'<span class="gh-card__lang-dot" style="background:' + langColor + '"></span>' +
			esc( r.language ) + '</span>'
		);
	}
	if ( r.stars ) {
		metaParts.push(
			'<span class="gh-card__meta-item">' +
			'<svg width="11" height="11" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" fill="none"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>' +
			r.stars + '</span>'
		);
	}
	if ( r.forks ) {
		metaParts.push(
			'<span class="gh-card__meta-item">' +
			'<svg width="11" height="11" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" fill="none"><line x1="6" y1="3" x2="6" y2="15"/><circle cx="18" cy="6" r="3"/><circle cx="6" cy="18" r="3"/><circle cx="6" cy="3" r="3"/><path d="M18 9a9 9 0 0 1-9 9"/></svg>' +
			r.forks + '</span>'
		);
	}
	if ( r.updated_at ) {
		metaParts.push( '<span class="gh-card__meta-item gh-card__updated">' + humanDate( r.updated_at ) + '</span>' );
	}

	return [
		'<div class="gh-card' + ( r.private ? ' gh-card--private' : '' ) + '" data-repo="' + esc( r.full_name ) + '" data-name="' + esc( r.name ) + '" style="animation-delay:' + Math.min( i * 12, 200 ) + 'ms">',
		'<div class="gh-card__head">',

		// Avatar
		'<div class="gh-card__avatar" aria-hidden="true">' + initials + '</div>',

		// Main info
		'<div class="gh-card__info">',
		'<div class="gh-card__title">',
		'<span class="gh-card__name">' + esc( r.name ) + '</span>',
		r.private ? '<span class="gh-card__private-badge">Private</span>' : '',
		'</div>',
		metaParts.length ? '<div class="gh-card__meta">' + metaParts.join( '' ) + '</div>' : '',
		'</div>',

		// Actions
		'<div class="gh-card__actions">',
		'<button class="gh-fav-btn' + ( fav ? ' is-fav' : '' ) + '" data-repo="' + esc( r.full_name ) + '" title="' + ( fav ? 'Remove from favourites' : 'Add to favourites' ) + '" aria-label="Favourite">',
		'<svg width="14" height="14" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>',
		'</button>',
		'<button class="gh-card__expand-btn" aria-label="Toggle releases">',
		'<svg class="gh-card__chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>',
		'</button>',
		'</div>',

		'</div>',
		'<div class="gh-releases wte-dbg-section-body">',
		loadingStateHtml( 'Loading releases\u2026' ),
		'</div>',
		'</div>',
	].join( '' );
}

// ---- Load releases ----
function loadReleases( card, fullName ) {
	var panel = card.querySelector( '.gh-releases' );
	if ( releaseCache[ fullName ] ) {
		renderReleases( panel, releaseCache[ fullName ], card.dataset.name );
		return;
	}

	setStatus( 'Loading releases for ' + fullName + '\u2026', 'info' );
	post( 'wpte_dz_gh_get_releases', { full_name: fullName } ).then( function( res ) {
		if ( res.success ) {
			releaseCache[ fullName ] = res.data.releases;
			setStatus( res.data.releases.length + ' release' + ( res.data.releases.length === 1 ? '' : 's' ) + ' loaded.', 'success' );
			renderReleases( panel, res.data.releases, card.dataset.name );
		} else {
			var msg = ( res.data && res.data.message ) ? res.data.message : 'Failed to load releases.';
			panel.innerHTML = errorStateHtml( msg );
			setStatus( 'Failed to load releases: ' + msg, 'error' );
		}
	} ).catch( function() {
		panel.innerHTML = errorStateHtml( 'Request failed.' );
		setStatus( 'Failed to load releases: network error.', 'error' );
	} );
}

// ---- Issues tab ----

function parseIssueUrl( query ) {
	// Standard issue URL: https://github.com/org/repo/issues/123
	var m = query.match( /^https?:\/\/github\.com\/([^/]+\/[^/]+)\/issues\/(\d+)/ );
	if ( m ) {
		return { full_name: m[1], issue_number: parseInt( m[2], 10 ) };
	}

	// GitHub Projects URL: ...?...&issue=Org%7Crepo%7C123 (pipe-separated, URL-encoded)
	try {
		var url    = new URL( query );
		var raw    = url.searchParams.get( 'issue' );
		if ( raw ) {
			var parts = raw.split( '|' );
			if ( parts.length === 3 && parts[2] && /^\d+$/.test( parts[2] ) ) {
				return { full_name: parts[0] + '/' + parts[1], issue_number: parseInt( parts[2], 10 ) };
			}
		}
	} catch ( e ) {}

	return null;
}

function isGithubUrl( query ) {
	return /^https?:\/\/github\.com\//i.test( query );
}

function searchIssues( query ) {
	var wrap = document.getElementById( 'gh-grid-wrap' );
	if ( wrap ) wrap.innerHTML = issuesSkeletonHtml();

	// Delegate URL parsing (both issue URL and project URL) to PHP.
	if ( isGithubUrl( query ) ) {
		setStatus( 'Looking up issue from URL\u2026', 'info' );
		post( 'wpte_dz_gh_get_issue_by_url', { url: query } ).then( function( res ) {
			if ( res.success ) {
				state.issues = [ res.data.issue ];
				setStatus( 'Issue loaded.', 'success' );
				renderIssuesGrid();
			} else {
				var msg = ( res.data && res.data.message ) ? res.data.message : 'Issue not found.';
				var w = document.getElementById( 'gh-grid-wrap' );
				if ( w ) w.innerHTML = errorStateHtml( msg );
				setStatus( 'Issue not found: ' + msg, 'error' );
			}
		} ).catch( function() {
			var w = document.getElementById( 'gh-grid-wrap' );
			if ( w ) w.innerHTML = errorStateHtml( 'Request failed.' );
			setStatus( 'Failed to load issue: network error.', 'error' );
		} );
		return;
	}

	setStatus( 'Searching issues\u2026', 'info' );
	post( 'wpte_dz_gh_search_issues', { query: query } ).then( function( res ) {
		if ( res.success ) {
			state.issues = res.data.issues || [];
			setStatus( state.issues.length ? state.issues.length + ' issue' + ( state.issues.length === 1 ? '' : 's' ) + ' found.' : 'No issues found.', state.issues.length ? 'success' : 'info' );
			renderIssuesGrid();
		} else {
			var msg = ( res.data && res.data.message ) ? res.data.message : 'Search failed.';
			var w = document.getElementById( 'gh-grid-wrap' );
			if ( w ) w.innerHTML = errorStateHtml( msg );
			setStatus( 'Issue search failed: ' + msg, 'error' );
		}
	} ).catch( function() {
		var w = document.getElementById( 'gh-grid-wrap' );
		if ( w ) w.innerHTML = errorStateHtml( 'Request failed.' );
		setStatus( 'Issue search failed: network error.', 'error' );
	} );
}

function renderIssuesGrid() {
	if ( state.tab !== 'issues' ) return;
	var wrap        = document.getElementById( 'gh-grid-wrap' );
	var countEl     = document.getElementById( 'tab-count-issues' );
	var searchCount = document.getElementById( 'gh-search-count' );
	if ( ! wrap ) return;

	if ( searchCount ) searchCount.textContent = '';

	if ( ! state.issueSearch ) {
		if ( countEl ) countEl.textContent = '';
		wrap.innerHTML = issuesWelcomeHtml();
		return;
	}
	if ( state.issues.length === 0 ) {
		if ( countEl ) countEl.textContent = '';
		wrap.innerHTML = emptyStateHtml( 'No issues found.' );
		return;
	}

	if ( countEl ) countEl.textContent = state.issues.length;

	// Group issues by organisation (first segment of full_name).
	var groups = {};
	state.issues.forEach( function( issue, i ) {
		var org = issue.full_name.split( '/' )[ 0 ] || 'Unknown';
		if ( ! groups[ org ] ) groups[ org ] = [];
		groups[ org ].push( { issue: issue, i: i } );
	} );

	var multiClass = state.issues.length > 1 ? ' gh-issues-list--multi' : '';
	var html = '';
	Object.keys( groups ).forEach( function( org ) {
		var cards = groups[ org ].map( function( e ) { return issueCardHtml( e.issue, e.i ); } ).join( '' );
		html += '<div class="gh-issues-org">'
			+ '<div class="gh-issues-org__header">'
			+ '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>'
			+ '<span>' + esc( org ) + '</span>'
			+ '<span class="gh-issues-org__count">' + groups[ org ].length + '</span>'
			+ '</div>'
			+ '<div class="gh-issues-list' + multiClass + '">' + cards + '</div>'
			+ '</div>';
	} );
	wrap.innerHTML = '<div id="gh-issues-list">' + html + '</div>';

	document.querySelectorAll( '#gh-issues-list .gh-issue-card' ).forEach( function( card ) {
		card.classList.add( 'is-open' );
		loadIssuePRs( card );
	} );
}

function issueCardHtml( issue, i ) {
	var stateClass = issue.state === 'closed' ? 'gh-state--closed' : 'gh-state--open';
	var stateLabel = issue.state === 'closed' ? 'Closed' : 'Open';
	var excerpt    = issue.body_excerpt
		? '<p class="gh-issue__excerpt">' + esc( issue.body_excerpt ) + '</p>'
		: '';

	return [
		'<div class="gh-issue-card" data-repo="' + esc( issue.full_name ) + '" data-issue="' + issue.number + '" style="animation-delay:' + Math.min( i * 12, 200 ) + 'ms">',
		'<div class="gh-issue-card__inner">',
		'<div class="gh-issue__head">',
		'<div class="gh-issue__meta-top">',
		'<a class="gh-issue__repo gh-link" href="https://github.com/' + esc( issue.full_name ) + '" target="_blank" rel="noopener">' + esc( issue.full_name ) + '</a>',
		'<a class="gh-issue__number gh-link" href="' + esc( issue.html_url ) + '" target="_blank" rel="noopener">#' + issue.number + '</a>',
		'<span class="gh-state-badge ' + stateClass + '">' + stateLabel + '</span>',
		'</div>',
		'<p class="gh-issue__title"><a class="gh-link" href="' + esc( issue.html_url ) + '" target="_blank" rel="noopener">' + esc( issue.title ) + '</a></p>',
		excerpt,
		'<div class="gh-issue__footer">',
		'<span class="gh-issue__comments">\ud83d\udcac ' + issue.comments + '</span>',
		'<span class="gh-issue__updated">' + humanDate( issue.updated_at ) + '</span>',
		'</div>',
		'</div>',
		'<div class="gh-issue__prs">' + loadingStateHtml( 'Loading linked PRs\u2026' ) + '</div>',
		'</div>',
		'</div>',
	].join( '' );
}

function loadIssuePRs( card ) {
	var fullName    = card.dataset.repo;
	var issueNumber = card.dataset.issue;
	var panel       = card.querySelector( '.gh-issue__prs' );
	var cacheKey    = fullName + '#' + issueNumber;

	if ( issuePrCache[ cacheKey ] ) {
		renderIssuePRs( panel, issuePrCache[ cacheKey ] );
		return;
	}

	post( 'wpte_dz_gh_get_issue_prs', { full_name: fullName, issue_number: issueNumber } )
		.then( function( res ) {
			if ( res.success ) {
				issuePrCache[ cacheKey ] = res.data.prs || [];
				renderIssuePRs( panel, issuePrCache[ cacheKey ] );
			} else {
				panel.innerHTML = errorStateHtml( ( res.data && res.data.message ) ? res.data.message : 'Failed to load PRs.' );
			}
		} ).catch( function() {
			panel.innerHTML = errorStateHtml( 'Request failed.' );
		} );
}

function renderIssuePRs( panel, prs ) {
	if ( ! prs || prs.length === 0 ) {
		panel.innerHTML = emptyStateHtml( 'No linked pull requests found.' );
		return;
	}

	panel.innerHTML = prs.map( function( pr ) { return prRowHtml( pr ); } ).join( '' );

	panel.querySelectorAll( '.gh-pr-row' ).forEach( function( row ) {
		row.classList.add( 'is-open' );
		loadPrReleases( row );
	} );

	bindReleaseRowEvents( panel );
}

function prRowHtml( pr ) {
	var stateClass = pr.merged ? 'gh-pr-state--merged'
	               : pr.state === 'closed' ? 'gh-pr-state--closed'
	               : 'gh-pr-state--open';
	var stateLabel = pr.merged ? 'Merged' : pr.state === 'closed' ? 'Closed' : 'Open';

	return [
		'<div class="gh-pr-row" data-repo="' + esc( pr.head_repo ) + '" data-pr="' + pr.number + '" data-branch="' + esc( pr.head_ref ) + '" data-base-branch="' + esc( pr.base_ref || '' ) + '">',
		'<div class="gh-pr__head">',
		'<a class="gh-pr__number gh-link" href="' + esc( pr.html_url ) + '" target="_blank" rel="noopener">#' + pr.number + '</a>',
		'<a class="gh-pr__branch gh-link" href="https://github.com/' + esc( pr.head_repo ) + '/tree/' + esc( pr.head_ref ) + '" target="_blank" rel="noopener"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="6" y1="3" x2="6" y2="15"/><circle cx="18" cy="6" r="3"/><circle cx="6" cy="18" r="3"/><circle cx="6" cy="3" r="3"/><path d="M18 9a9 9 0 0 1-9 9"/></svg>' + esc( pr.head_ref ) + '</a>',
		'<span class="gh-pr-state-badge ' + stateClass + '">' + stateLabel + '</span>',
		'</div>',
		'<div class="gh-pr__releases">' + loadingStateHtml( 'Loading releases\u2026' ) + '</div>',
		'</div>',
	].join( '' );
}

function loadPrReleases( row ) {
	var fullName  = row.dataset.repo;
	var prNumber  = row.dataset.pr;
	var branchLabel = row.dataset.branch || ( '#' + prNumber );
	var panel     = row.querySelector( '.gh-pr__releases' );
	var repoName  = fullName.split( '/' )[ 1 ] || fullName;
	var cacheKey  = fullName + '#' + prNumber;

	var releasesPromise = releaseCache[ fullName ]
		? Promise.resolve( releaseCache[ fullName ] )
		: post( 'wpte_dz_gh_get_releases', { full_name: fullName } ).then( function( res ) {
			if ( ! res.success ) throw new Error( ( res.data && res.data.message ) ? res.data.message : 'Failed to load releases.' );
			releaseCache[ fullName ] = res.data.releases;
			return res.data.releases;
		} );

	var prTagsPromise = branchTagCache[ cacheKey ] !== undefined
		? Promise.resolve( branchTagCache[ cacheKey ] )
		: post( 'wpte_dz_gh_get_branch_tags', { full_name: fullName, pr_number: prNumber } ).then( function( res ) {
			var tags = res.success ? ( res.data.tags || [] ) : [];
			branchTagCache[ cacheKey ] = tags;
			return tags;
		} ).catch( function() {
			branchTagCache[ cacheKey ] = [];
			return [];
		} );

	setStatus( 'Loading releases for ' + branchLabel + '\u2026', 'info' );

	Promise.all( [ releasesPromise, prTagsPromise ] ).then( function( results ) {
		var releases = results[ 0 ];
		var prTags   = results[ 1 ];
		var tagSet   = {};
		prTags.forEach( function( t ) { tagSet[ t ] = true; } );
		var filtered = releases.filter( function( r ) { return tagSet[ r.tag ]; } );

		if ( filtered.length === 0 ) {
			var msg = prTags.length === 0
				? 'No tags were created from the commits in this PR.'
				: 'No releases found for the tags associated with this PR.';
			panel.innerHTML = emptyStateHtml( msg );
			setStatus( 'No releases found for this PR.', 'info' );
			return;
		}

		setStatus( filtered.length + ' release' + ( filtered.length === 1 ? '' : 's' ) + ' on ' + branchLabel + '.', 'success' );
		renderReleases( panel, filtered, repoName );
	} ).catch( function( err ) {
		panel.innerHTML = errorStateHtml( err && err.message ? err.message : 'Request failed.' );
		setStatus( 'Failed to load releases: ' + ( err && err.message ? err.message : 'network error.' ), 'error' );
	} );
}

// Shared click delegation for release rows (install / activate).
function bindReleaseRowEvents( container ) {
	container.addEventListener( 'click', function( e ) {
		var installBtn  = e.target.closest( '.gh-install-btn' );
		var activateBtn = e.target.closest( '.gh-activate-btn' );

		if ( installBtn ) {
			doInstall( installBtn, installBtn.closest( '.gh-release' ) );
		} else if ( activateBtn ) {
			doActivate( activateBtn, activateBtn.closest( '.gh-release' ), activateBtn.dataset.pluginFile );
		}
	} );
}

// All release data (tag, name, body) is escaped via esc() in releaseRowHtml().
function renderReleases( panel, releases, repoName, notice ) {
	if ( ! releases || releases.length === 0 ) {
		panel.innerHTML = ( notice || '' ) + emptyStateHtml( 'No releases found for this branch.' );
		return;
	}

	var PAGE_SIZE = 5;

	panel.innerHTML = ( notice || '' )
		+ '<div class="gh-releases-toolbar">'
		+ '<div class="gh-releases-pagination wte-dbg-pagination"></div>'
		+ '</div>'
		+ '<div class="gh-releases-list"></div>';

	var toolbar    = panel.querySelector( '.gh-releases-toolbar' );
	var pagination = panel.querySelector( '.gh-releases-pagination' );
	var list       = panel.querySelector( '.gh-releases-list' );
	var input      = null;
	var curPage    = 1;
	var visible    = releases.slice();

	function renderPage( page ) {
		curPage    = page;
		var total      = visible.length;
		var totalPages = Math.max( 1, Math.ceil( total / PAGE_SIZE ) );
		if ( curPage > totalPages ) curPage = totalPages;

		var start = ( curPage - 1 ) * PAGE_SIZE;
		var slice = visible.slice( start, start + PAGE_SIZE );

		list.innerHTML = slice.map( function( r ) { return releaseRowHtml( r, repoName ); } ).join( '' );
		bindReleaseRowEvents( list );

		// Show search + pagination only when there is more than one page.
		if ( totalPages <= 1 ) {
			toolbar.style.display = 'none';
			return;
		}

		// Inject search the first time pagination appears.
		if ( ! input ) {
			var searchHtml = '<div class="gh-releases-search"><div class="wte-dbg-search-wrap">'
				+ '<input type="text" class="wte-dbg-search-input gh-releases-search__input" placeholder="Search tags\u2026" aria-label="Search tags">'
				+ '<span class="wte-dbg-search-count gh-releases-search__count"></span>'
				+ '</div></div>';
			toolbar.insertAdjacentHTML( 'afterbegin', searchHtml );
			input = toolbar.querySelector( '.gh-releases-search__input' );
			input.addEventListener( 'input', function() {
				var q = input.value.trim().toLowerCase();
				visible = ! q ? releases.slice() : releases.filter( function( r ) {
					return ( r.tag || '' ).toLowerCase().indexOf( q ) !== -1
						|| ( r.name || '' ).toLowerCase().indexOf( q ) !== -1;
				} );
				renderPage( 1 );
			} );
		}

		toolbar.style.display = '';
		toolbar.querySelector( '.gh-releases-search__count' ).textContent = total + ' tag' + ( total === 1 ? '' : 's' );

		pagination.innerHTML =
			'<button class="wte-dbg-page-btn gh-page-prev"' + ( curPage === 1 ? ' disabled' : '' ) + '>\u2039</button>'
			+ '<span class="gh-page-indicator">' + curPage + ' / ' + totalPages + '</span>'
			+ '<button class="wte-dbg-page-btn gh-page-next"' + ( curPage === totalPages ? ' disabled' : '' ) + '>\u203a</button>';
	}

	pagination.addEventListener( 'click', function( e ) {
		var btn = e.target.closest( '.wte-dbg-page-btn' );
		if ( ! btn || btn.disabled ) return;
		if ( btn.classList.contains( 'gh-page-prev' ) ) { renderPage( curPage - 1 ); }
		else if ( btn.classList.contains( 'gh-page-next' ) ) { renderPage( curPage + 1 ); }
	} );

	renderPage( 1 );
}

// All data from GitHub API is escaped via esc(). Static SVG/HTML is safe markup.
function releaseRowHtml( r, repoName ) {
	var noZip    = ! r.zip_url;
	var preBadge = r.prerelease ? '<span class="wte-dbg-status wte-dbg-status-pending">Pre</span>' : '';
	var noBadge  = noZip        ? '<span class="wte-dbg-status wte-dbg-status-cancelled">No ZIP</span>' : '';
	var status   = getInstalledStatus( repoName, r.tag );
	var instBadge = installedBadgeHtml( status, r.tag );
	var installBtn = noZip
		? '<span style="font-size:11px;color:var(--dbg-text-muted);">No ZIP asset</span>'
		: '<button class="wte-dbg-cron-run-btn gh-install-btn">Install</button>';

	return [
		'<div class="gh-release" data-zip="' + esc( r.zip_url ) + '" data-repo-name="' + esc( repoName ) + '" data-tag="' + esc( r.tag ) + '">',
		'<span class="gh-release__tag"><a class="gh-release__tag-label gh-link" href="' + esc( r.html_url ) + '" target="_blank" rel="noopener">' + esc( r.tag ) + '</a>' + preBadge + noBadge + '</span>',
		instBadge,
		'<span class="gh-release__date">' + humanDate( r.published ) + '</span>',
		'<div class="gh-release__right">' + installBtn + '</div>',
		'<div class="gh-release__post-install"></div>',
		'</div>',
	].join( '' );
}

// ---- Install ----
function doInstall( btn, row ) {
	var zipUrl   = row.dataset.zip;
	var repoName = row.dataset.repoName;
	var postArea = row.querySelector( '.gh-release__post-install' );

	btn.disabled  = true;
	btn.innerHTML = '<span class="gh-spinner"></span>';
	postArea.classList.remove( 'is-visible' );
	postArea.innerHTML = '';

	setStatus( 'Installing ' + repoName + '\u2026', 'info' );

	post( 'wpte_dz_gh_install', { zip_url: zipUrl, repo_name: repoName } ).then( function( res ) {
		if ( res.success ) {
			var pluginFile = res.data.plugin_file;
			var pluginName = res.data.plugin_name || '';

			btn.innerHTML = '<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg> Re-install';
			btn.disabled  = false;

			// Post-install area uses esc() for pluginFile.
			var codeEl = document.createElement( 'code' );
			codeEl.style.fontSize = '10px';
			codeEl.textContent    = pluginFile;

			var resultSpan = document.createElement( 'span' );
			resultSpan.className = 'gh-release__result ok';
			resultSpan.appendChild( document.createTextNode( '\u2713 Installed \u2014 ' ) );
			resultSpan.appendChild( codeEl );

			var activateBtn = document.createElement( 'button' );
			activateBtn.className          = 'wte-dbg-save gh-activate-btn';
			activateBtn.dataset.pluginFile = pluginFile;
			activateBtn.textContent        = 'Activate';

			postArea.innerHTML = '';
			postArea.appendChild( resultSpan );
			postArea.appendChild( activateBtn );
			postArea.classList.add( 'is-visible' );

			if ( pluginName ) {
				var tag = row.dataset.tag;
				state.installedPlugins[ pluginName ] = {
					version : tag.replace( /^v/, '' ),
					active  : false,
					file    : pluginFile,
				};
			}

			setStatus( repoName + ' installed successfully.', 'success' );
		} else {
			var msg = ( res.data && res.data.message ) ? res.data.message : 'Install failed.';
			btn.innerHTML = 'Install';
			btn.disabled  = false;
			var errSpan = document.createElement( 'span' );
			errSpan.className   = 'gh-release__result err';
			errSpan.textContent = '\u2717 ' + msg;
			postArea.innerHTML  = '';
			postArea.appendChild( errSpan );
			postArea.classList.add( 'is-visible' );
			setStatus( 'Install failed: ' + msg, 'error' );
		}
	} ).catch( function() {
		btn.innerHTML = 'Install';
		btn.disabled  = false;
		var errSpan = document.createElement( 'span' );
		errSpan.className   = 'gh-release__result err';
		errSpan.textContent = '\u2717 Request failed.';
		postArea.innerHTML  = '';
		postArea.appendChild( errSpan );
		postArea.classList.add( 'is-visible' );
		setStatus( 'Install failed: network error.', 'error' );
	} );
}

// ---- Activate ----
function doActivate( btn, row, pluginFile ) {
	btn.disabled  = true;
	btn.innerHTML = '<span class="gh-spinner"></span>';
	setStatus( 'Activating plugin\u2026', 'info' );

	post( 'wpte_dz_gh_activate', { plugin_file: pluginFile } ).then( function( res ) {
		var postArea = row.querySelector( '.gh-release__post-install' );
		if ( res.success ) {
			// Use DOM to safely set plugin_file value.
			var codeEl = document.createElement( 'code' );
			codeEl.style.fontSize = '10px';
			codeEl.textContent    = pluginFile;

			var span = document.createElement( 'span' );
			span.className = 'gh-release__result ok';
			span.appendChild( document.createTextNode( '\u2713 Installed & Active \u2014 ' ) );
			span.appendChild( codeEl );

			postArea.innerHTML = '';
			postArea.appendChild( span );

			Object.keys( state.installedPlugins ).forEach( function( name ) {
				if ( state.installedPlugins[ name ].file === pluginFile ) {
					state.installedPlugins[ name ].active = true;
				}
			} );
			setStatus( 'Plugin activated successfully.', 'success' );
		} else {
			btn.disabled  = false;
			btn.innerHTML = 'Activate';
			var result = postArea.querySelector( '.gh-release__result' );
			if ( result ) {
				result.classList.remove( 'ok' );
				result.classList.add( 'err' );
				result.textContent = '\u2717 Activation failed: ' + ( ( res.data && res.data.message ) ? res.data.message : '' );
			}
			setStatus( 'Activation failed: ' + ( ( res.data && res.data.message ) ? res.data.message : 'unknown error.' ), 'error' );
		}
	} ).catch( function() {
		btn.disabled  = false;
		btn.innerHTML = 'Activate';
		setStatus( 'Activation failed: network error.', 'error' );
	} );
}

// ---- Installed version detection ----
function getInstalledStatus( repoName, releaseTag ) {
	var normalizedTag  = releaseTag.replace( /^v/, '' ).toLowerCase();
	var normalizedRepo = repoName.toLowerCase().replace( /[\s_]+/g, '-' );

	var names = Object.keys( state.installedPlugins );
	for ( var i = 0; i < names.length; i++ ) {
		var name           = names[ i ];
		var data           = state.installedPlugins[ name ];
		var normalizedName = name.toLowerCase().replace( /[\s_]+/g, '-' );

		if ( normalizedName.indexOf( normalizedRepo ) === -1 && normalizedRepo.indexOf( normalizedName ) === -1 ) {
			continue;
		}

		var installedVer = data.version.toLowerCase().replace( /^v/, '' );

		if ( installedVer === normalizedTag )               return 'installed-same';
		if ( compareVersions( installedVer, normalizedTag ) < 0 ) return 'update-available';
		return 'older-installed';
	}

	return 'not-installed';
}

function installedBadgeHtml( status, tag ) {
	if ( status === 'installed-same' ) {
		return '<span class="wte-dbg-status wte-dbg-status-completed" title="This version is installed">\u2713 v' + esc( tag.replace( /^v/, '' ) ) + '</span>';
	}
	if ( status === 'update-available' ) {
		return '<span class="wte-dbg-status wte-dbg-status-pending" title="Installed version is older">\u2191 Update available</span>';
	}
	if ( status === 'older-installed' ) {
		return '<span class="wte-dbg-status wte-dbg-status-draft" title="A newer version is installed">\u2193 Newer installed</span>';
	}
	return '';
}

function compareVersions( a, b ) {
	var pa  = a.split( '.' ).map( Number );
	var pb  = b.split( '.' ).map( Number );
	var len = Math.max( pa.length, pb.length );
	for ( var i = 0; i < len; i++ ) {
		var na = pa[ i ] || 0;
		var nb = pb[ i ] || 0;
		if ( na < nb ) return -1;
		if ( na > nb ) return  1;
	}
	return 0;
}

// ---- Utilities ----
function humanDate( iso ) {
	if ( ! iso ) return '';
	var d    = new Date( iso );
	var diff = ( Date.now() - d.getTime() ) / 1000;
	if ( diff < 86400 )     return 'Today';
	if ( diff < 86400 * 2 ) return 'Yesterday';
	if ( diff < 86400 * 7 ) return Math.round( diff / 86400 ) + 'd ago';
	return d.toLocaleDateString( 'en-US', { month: 'short', day: 'numeric', year: 'numeric' } );
}

function loadingStateHtml( msg ) {
	return '<div class="wte-dbg-loading">' + msg + '</div>';
}

function issuesSkeletonHtml() {
	function card() {
		return '<div class="gh-skeleton-card">'
			+ '<div class="gh-skeleton-meta">'
			+   '<span class="gh-skel gh-skel--repo"></span>'
			+   '<span class="gh-skel gh-skel--badge"></span>'
			+ '</div>'
			+ '<div class="gh-skel gh-skel--title"></div>'
			+ '<div class="gh-skel gh-skel--line"></div>'
			+ '<div class="gh-skel gh-skel--line gh-skel--short"></div>'
			+ '<div class="gh-skeleton-footer">'
			+   '<span class="gh-skel gh-skel--chip"></span>'
			+   '<span class="gh-skel gh-skel--chip"></span>'
			+ '</div>'
			+ '</div>';
	}
	return '<div class="gh-skeleton-grid">'
		+ card() + card() + card() + card()
		+ '</div>';
}

function issuesWelcomeHtml() {
	return [
		'<div class="gh-issues-welcome">',
		'<div class="gh-issues-welcome__icon">',
		'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">',
		'<circle cx="11" cy="11" r="7"/>',
		'<line x1="16.5" y1="16.5" x2="21" y2="21"/>',
		'<path d="M8 9.5c0-1 .7-2 2-2s2 1 2 2c0 1-1 1.5-2 2v.5"/>',
		'<circle cx="10" cy="14.5" r=".5" fill="currentColor"/>',
		'</svg>',
		'</div>',
		'<h3 class="gh-issues-welcome__title">Find a GitHub Issue</h3>',
		'<p class="gh-issues-welcome__desc">Paste an issue URL or search by keyword to load the issue along with its linked pull requests and release tags.</p>',
		'<div class="gh-issues-welcome__hints">',
		'<div class="gh-issues-welcome__hint">',
		'<span class="gh-issues-welcome__hint-label">Issue URL</span>',
		'<code class="gh-issues-welcome__hint-code">https://github.com/org/repo/issues/123</code>',
		'</div>',
		'<div class="gh-issues-welcome__hint">',
		'<span class="gh-issues-welcome__hint-label">Project URL</span>',
		'<code class="gh-issues-welcome__hint-code">https://github.com/orgs/org/projects/78/views/1?...&issue=org|repo|123</code>',
		'</div>',
		'<div class="gh-issues-welcome__hint">',
		'<span class="gh-issues-welcome__hint-label">Issue Title</span>',
		'<code class="gh-issues-welcome__hint-code">booking form validation</code>',
		'</div>',
		'</div>',
		'<p class="gh-issues-welcome__tip"><kbd>Enter</kbd> to search immediately &middot; waits 2s otherwise</p>',
		'</div>',
	].join( '' );
}

function emptyStateHtml( msg ) {
	// msg is always a hardcoded string, not user input.
	return '<div class="wte-dbg-empty">' + msg + '</div>';
}

function errorStateHtml( msg ) {
	return '<div class="wte-dbg-error-notice">' + esc( msg ) + '</div>';
}

function githubIcon( size ) {
	return '<svg width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 19c-5 1.5-5-2.5-7-3m14 6v-3.87a3.37 3.37 0 0 0-.94-2.61c3.14-.35 6.44-1.54 6.44-7A5.44 5.44 0 0 0 20 4.77 5.07 5.07 0 0 0 19.91 1S18.73.65 16 2.48a13.38 13.38 0 0 0-7 0C6.27.65 5.09 1 5.09 1A5.07 5.07 0 0 0 5 4.77a5.44 5.44 0 0 0-1.5 3.78c0 5.42 3.3 6.61 6.44 7A3.37 3.37 0 0 0 9 18.13V22"/></svg>';
}

// ---- Init ----

/**
 * Expose boot function globally so the inline script in tab-github.php can call
 * it after DomHelper.setServerHtml() injects the tab HTML via AJAX.
 * ES modules run once and are cached — this bridge is the re-entry point.
 */
window.wpteGithubBoot = function () {
	if ( ! document.getElementById( 'wpte-dz-github-root' ) ) return;
	boot();
};

// Handle initial hard page load when PHP pre-renders the tab (?tab=github in URL).
// At this point DOMContentLoaded may or may not have fired.
if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', window.wpteGithubBoot );
} else if ( document.getElementById( 'wpte-dz-github-root' ) ) {
	window.wpteGithubBoot();
}
