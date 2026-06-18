<?php
defined( 'ABSPATH' ) || exit;

$log = array_reverse( get_option( WPTE_DZ_GITHUB_OPTION_DOWNLOAD_LOG, [] ) );
?>
<div class="gh-log-wrap">

	<div class="gh-log-header">
		<div class="gh-log-header__left">
			<svg class="gh-log-header__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
				<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
				<polyline points="7 10 12 15 17 10"/>
				<line x1="12" y1="15" x2="12" y2="3"/>
			</svg>
			<span class="gh-log-header__title"><?php esc_html_e( 'GitHub Downloads', 'wpte-devzone-github' ); ?></span>
			<?php if ( ! empty( $log ) ) : ?>
				<span class="gh-log-header__count"><?php echo count( $log ); ?></span>
			<?php endif; ?>
		</div>
		<?php if ( ! empty( $log ) ) : ?>
			<button class="wte-dbg-cron-run-btn gh-log-clear-btn" id="gh-clear-log-btn"><?php esc_html_e( 'Clear log', 'wpte-devzone-github' ); ?></button>
		<?php endif; ?>
		<p class="gh-log-header__desc"><?php esc_html_e( 'Webhook-triggered plugin installs from GitHub releases.', 'wpte-devzone-github' ); ?></p>
	</div>

<?php if ( empty( $log ) ) : ?>

	<div class="gh-log-empty">
		<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round">
			<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
			<polyline points="7 10 12 15 17 10"/>
			<line x1="12" y1="15" x2="12" y2="3"/>
		</svg>
		<p><?php esc_html_e( 'No webhook-triggered downloads yet.', 'wpte-devzone-github' ); ?></p>
		<span><?php esc_html_e( 'Downloads appear here when a GitHub Projects issue is moved to Testing or Push Zips.', 'wpte-devzone-github' ); ?></span>
	</div>

<?php else : ?>

	<div class="gh-log-table-wrap">
		<table class="gh-log-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'When', 'wpte-devzone-github' ); ?></th>
					<th><?php esc_html_e( 'Issue', 'wpte-devzone-github' ); ?></th>
					<th><?php esc_html_e( 'Plugin', 'wpte-devzone-github' ); ?></th>
					<th><?php esc_html_e( 'PR', 'wpte-devzone-github' ); ?></th>
					<th><?php esc_html_e( 'Tag', 'wpte-devzone-github' ); ?></th>
					<th><?php esc_html_e( 'Action', 'wpte-devzone-github' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $log as $entry ) :
				$entry_status = $entry['status'] ?? 'installed';
				$issue     = $entry['issue'] ?? [];
				$ts        = ! empty( $entry['timestamp'] ) ? human_time_diff( (int) $entry['timestamp'] ) . ' ago' : '—';
				$full_name = (string) ( $issue['full_name'] ?? '' );
				$issue_num = (int) ( $issue['number'] ?? 0 );
				$pr_repo   = (string) ( $entry['pr_repo'] ?? '' );
				$pr_num    = (int) ( $entry['pr'] ?? 0 );
				if ( $entry_status === 'failed' ) {
					$action = 'failed';
				} elseif ( ( $entry['action'] ?? 'installed' ) === 'replaced' ) {
					$action = 'replaced';
				} else {
					$action = 'installed';
				}

				// Construct hrefs from safe path segments only — never from raw stored URLs.
				$encode_path = fn( string $s ) => implode( '/', array_map( 'rawurlencode', explode( '/', $s ) ) );
				$issue_href  = $issue_num ? 'https://github.com/' . $encode_path( $full_name ) . '/issues/' . $issue_num : '';
				$pr_href     = $pr_num    ? 'https://github.com/' . $encode_path( $pr_repo )   . '/pull/'   . $pr_num    : '';
			?>
				<tr class="gh-log__row" data-action="<?php echo esc_attr( $action ); ?>">
					<td class="gh-log__time">
						<span class="gh-log__time-text"><?php echo esc_html( $ts ); ?></span>
					</td>
					<td class="gh-log__issue">
						<?php
						$repo_short = $full_name ? explode( '/', $full_name )[1] ?? $full_name : '';
						if ( $issue_href ) : ?>
							<a class="gh-link gh-log__issue-ref" href="<?php echo esc_url( $issue_href ); ?>" target="_blank" rel="noopener noreferrer" title="<?php echo esc_attr( $repo_short . ( $issue_num ? ' #' . $issue_num : '' ) ); ?>">
								<?php echo esc_html( ! empty( $issue['title'] ) ? $issue['title'] : $repo_short . ( $issue_num ? ' #' . $issue_num : '' ) ); ?>
							</a>
						<?php else : ?>
							<span class="gh-log__issue-ref"<?php if ( ! empty( $issue['title'] ) ) : ?> title="<?php echo esc_attr( $issue['title'] ); ?>"<?php endif; ?>><?php echo esc_html( $repo_short ); ?></span>
						<?php endif; ?>
						<?php if ( $repo_short && $issue_num ) : ?>
							<span class="gh-log__issue-title"><?php echo esc_html( $repo_short . ' #' . $issue_num ); ?></span>
						<?php endif; ?>
					</td>
					<td class="gh-log__plugin">
						<?php if ( $action === 'failed' ) : ?>
							<span class="gh-log__error-msg"><?php echo esc_html( $entry['message'] ?? '—' ); ?></span>
						<?php else : ?>
							<span class="gh-log__plugin-name"><?php echo esc_html( $entry['plugin_name'] ?? $entry['slug'] ?? '—' ); ?></span>
						<?php endif; ?>
					</td>
					<td class="gh-log__pr">
						<?php
						$pr_repo_short = $pr_repo ? explode( '/', $pr_repo )[1] ?? $pr_repo : '';
						if ( $pr_href ) : ?>
							<a class="gh-link gh-log__pr-link" href="<?php echo esc_url( $pr_href ); ?>" target="_blank" rel="noopener noreferrer">
								<?php echo esc_html( $pr_repo_short . ( $pr_num ? ' #' . $pr_num : '' ) ); ?>
							</a>
						<?php elseif ( $pr_repo_short ) : ?>
							<span><?php echo esc_html( $pr_repo_short ); ?></span>
						<?php else : ?>
							<span class="gh-log__none">—</span>
						<?php endif; ?>
					</td>
					<td class="gh-log__tag">
						<code class="gh-log__tag-code"><?php echo esc_html( $entry['tag'] ?? '—' ); ?></code>
					</td>
					<td class="gh-log__action">
						<span class="gh-log__action-badge gh-log__action-badge--<?php echo esc_attr( $action ); ?>">
							<?php
							if ( $action === 'replaced' ) {
								esc_html_e( 'Replaced', 'wpte-devzone-github' );
							} elseif ( $action === 'failed' ) {
								esc_html_e( 'Failed', 'wpte-devzone-github' );
							} else {
								esc_html_e( 'Added', 'wpte-devzone-github' );
							}
							?>
						</span>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>

<?php endif; ?>
</div>
