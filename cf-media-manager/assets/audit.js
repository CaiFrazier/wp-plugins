/**
 * Media Manager — Audit tab.
 *
 * Vanilla JS. No build step. Hydrates the server-rendered shell in
 * AuditPage::render_section() by calling the AuditAjax endpoints.
 *
 * Flow:
 *   - On Audit tab activation, fetch the dashboard summary.
 *   - Render cards. For any card in "scanning" phase, start a poll loop
 *     against audit_scan_chunk until is_complete or error.
 *   - URL hash routes the view:
 *       #audit                     → dashboard
 *       #audit/<report_id>         → detail view, page 1
 *       #audit/<report_id>?page=N  → detail view, page N
 *   - Detail view loads paginated items via audit_detail, supports
 *     receipt expansion, and routes bulk actions through audit_bulk.
 */
/* global cfMediaManager, jQuery */

( function ( $ ) {
	'use strict';

	if ( ! window.cfMediaManager ) {
		return;
	}

	const cfg          = window.cfMediaManager;
	const POLL_MS      = 1200;
	const POLL_MAX     = 90;            // ~1.5 min ceiling before user must rescan manually
	const HASH_PREFIX  = '#audit';

	const $root        = $( '[data-cf-audit-root]' );
	if ( ! $root.length ) {
		return;
	}

	const $cards       = $root.find( '[data-cf-audit-cards]' );
	const $dashboard   = $root.find( '[data-cf-audit-view="dashboard"]' );
	const $detail      = $root.find( '[data-cf-audit-view="detail"]' );
	const $detailTitle = $root.find( '[data-cf-audit-detail-title]' );
	const $detailMeta  = $root.find( '[data-cf-audit-detail-meta]' );
	const $detailBody  = $root.find( '[data-cf-audit-tbody]' );
	const $pagination  = $root.find( '[data-cf-audit-pagination]' );
	const $actionbar   = $root.find( '[data-cf-audit-actionbar]' );
	const $bulkSelect  = $root.find( '[data-cf-audit-bulk-action]' );
	const $bulkApply   = $root.find( '[data-cf-audit-bulk-apply]' );
	const $bulkStatus  = $root.find( '[data-cf-audit-bulk-status]' );
	const $selectAll   = $root.find( '[data-cf-audit-select-all]' );
	const $lastAllScan = $root.find( '[data-cf-audit-last-all-scan]' );

	const pollState = {};   // report_id → { attempts, timer }

	const __ = ( window.wp && wp.i18n && wp.i18n.__ )
		? wp.i18n.__
		: function ( s ) { return s; };

	// =========================================================================
	// AJAX helper
	// =========================================================================

	function ajax( action, data, opts ) {
		const payload = Object.assign(
			{ action: 'cf_media_manager_' + action, _wpnonce: cfg.nonce },
			data || {}
		);
		return $.ajax( Object.assign(
			{
				url:     cfg.ajaxUrl,
				method:  'POST',
				data:    payload,
				traditional: true,   // for ids[] array encoding
				dataType: 'json',
			},
			opts || {}
		) );
	}

	// =========================================================================
	// Hash router — single source of truth for view state
	// =========================================================================

	function parseHash() {
		const raw = ( window.location.hash || '' ).trim();
		if ( ! raw.startsWith( HASH_PREFIX ) ) {
			return null;   // not our hash; ignore
		}
		const rest = raw.slice( HASH_PREFIX.length ).replace( /^[\/?]/, '' );
		if ( '' === rest ) {
			return { view: 'dashboard' };
		}
		const qIdx = rest.indexOf( '?' );
		const path = qIdx === -1 ? rest : rest.slice( 0, qIdx );
		const qs   = qIdx === -1 ? ''   : rest.slice( qIdx + 1 );
		const params = new URLSearchParams( qs );
		return {
			view:     'detail',
			report:   path.replace( /\/$/, '' ),
			page:     Math.max( 1, parseInt( params.get( 'page' ), 10 ) || 1 ),
			per_page: Math.max( 1, parseInt( params.get( 'per_page' ), 10 ) || 50 ),
		};
	}

	function setHash( view, report, page ) {
		if ( view === 'detail' ) {
			const q = page && page > 1 ? '?page=' + page : '';
			window.location.hash = HASH_PREFIX + '/' + report + q;
		} else {
			window.location.hash = HASH_PREFIX;
		}
	}

	function applyRoute() {
		const route = parseHash();
		if ( ! route ) {
			return;
		}
		if ( route.view === 'detail' ) {
			showDetail( route.report, route.page, route.per_page );
		} else {
			showDashboard();
		}
	}

	$( window ).on( 'hashchange', applyRoute );

	// =========================================================================
	// Dashboard view
	// =========================================================================

	function showDashboard() {
		Object.values( pollState ).forEach( clearPoll );
		$detail.attr( 'hidden', 'hidden' );
		$dashboard.removeAttr( 'hidden' );
		fetchDashboard();
	}

	function fetchDashboard() {
		ajax( 'audit_dashboard' ).done( function ( resp ) {
			if ( ! resp || ! resp.success ) {
				renderDashboardError( ( resp && resp.data && resp.data.message ) || __( 'Could not load audit dashboard.', 'cf-media-manager' ) );
				return;
			}
			renderDashboard( resp.data.reports || [], resp.data.links || [] );
		} ).fail( function () {
			renderDashboardError( __( 'Could not load audit dashboard.', 'cf-media-manager' ) );
		} );
	}

	function renderDashboard( reports, links ) {
		links = links || [];
		if ( ! reports.length && ! links.length ) {
			$cards.html( '<div class="cf-audit-empty">' + esc( __( 'No audit reports registered.', 'cf-media-manager' ) ) + '</div>' );
			return;
		}
		let mostRecent = 0;
		const reportHtml = reports.map( function ( r ) {
			if ( r.scanned_at && r.scanned_at > mostRecent ) {
				mostRecent = r.scanned_at;
			}
			return renderCard( r );
		} ).join( '' );
		const linkHtml = links.map( renderLinkCard ).join( '' );

		$cards.html( reportHtml + linkHtml );

		if ( mostRecent > 0 ) {
			$lastAllScan.text( __( 'Last scan:', 'cf-media-manager' ) + ' ' + relativeTime( mostRecent ) );
		} else {
			$lastAllScan.text( '' );
		}

		// Restart polling for any card that's currently scanning. The
		// dashboard remembers across page reloads via the runner's state
		// transient.
		reports.forEach( function ( r ) {
			if ( r.phase === 'scanning' ) {
				startPoll( r.id );
			}
		} );
	}

	/**
	 * Link cards are "health signal" indicators that aren't real audit
	 * reports — they just show a count and CTA somewhere else in the
	 * plugin. The CTA target is a Manager tab slug; clicking switches the
	 * outer tab system via its data-tab attribute.
	 */
	function renderLinkCard( link ) {
		const count   = parseInt( link.count, 10 ) || 0;
		const ctaText = link.cta_label || __( 'Open', 'cf-media-manager' );

		return (
			'<article class="cf-audit-card cf-audit-card-link" data-cf-audit-link="' + esc( link.cta_target ) + '">' +
				'<header class="cf-audit-card-header">' +
					'<h3>' + esc( link.label ) + '</h3>' +
				'</header>' +
				'<p class="cf-audit-card-desc">' + esc( link.description ) + '</p>' +
				'<div class="cf-audit-card-body">' +
					( count > 0
						? '<p class="cf-audit-card-count">' +
							'<span class="cf-audit-big-num">' + count + '</span> ' +
							'<span class="cf-audit-count-label">' + esc( count === 1 ? __( 'image', 'cf-media-manager' ) : __( 'images', 'cf-media-manager' ) ) + '</span>' +
						  '</p>'
						: '<p class="cf-audit-card-empty">' + esc( __( 'All set — no signal to report.', 'cf-media-manager' ) ) + '</p>'
					) +
				'</div>' +
				'<footer class="cf-audit-card-footer">' +
					'<button type="button" class="button" data-cf-audit-link-cta>' + esc( ctaText ) + ' &rarr;</button>' +
				'</footer>' +
			'</article>'
		);
	}

	// Link-card CTA: switch the outer Manager tab to the target slug.
	$cards.on( 'click', '[data-cf-audit-link-cta]', function ( e ) {
		e.preventDefault();
		const target = $( e.currentTarget ).closest( '[data-cf-audit-link]' ).attr( 'data-cf-audit-link' );
		if ( ! target ) {
			return;
		}
		// The Manager tab system reads data-tab attributes on the button
		// row; triggering a click on the matching button switches the panel.
		const $tab = $( '.cf-media-manager-tab[data-tab="' + target + '"]' );
		if ( $tab.length ) {
			$tab.trigger( 'click' );
		}
	} );

	function renderDashboardError( msg ) {
		$cards.html( '<div class="cf-audit-empty cf-audit-error">' + esc( msg ) + '</div>' );
	}

	function renderCard( r ) {
		const flagged  = ( r.totals && r.totals.flagged ) ? parseInt( r.totals.flagged, 10 ) : 0;
		const savings  = ( r.totals && r.totals.savings_bytes ) ? humanBytes( r.totals.savings_bytes ) : '';
		const scanned  = r.scanned_at ? relativeTime( r.scanned_at ) : '';
		const stale    = r.is_stale ? ' is-stale' : '';
		const phase    = r.phase || 'idle';

		let bodyHtml;
		switch ( phase ) {
			case 'scanning':
				bodyHtml = renderScanningCardBody( r );
				break;
			case 'failed':
				bodyHtml = renderFailedCardBody( r );
				break;
			case 'complete':
				bodyHtml = renderCompleteCardBody( r, flagged, savings, scanned );
				break;
			case 'idle':
			default:
				bodyHtml = renderIdleCardBody();
				break;
		}

		return (
			'<article class="cf-audit-card phase-' + esc( phase ) + stale + '" data-cf-audit-card="' + esc( r.id ) + '">' +
				'<header class="cf-audit-card-header">' +
					'<h3>' + esc( r.label ) + '</h3>' +
					( r.is_stale ? '<span class="cf-audit-stale-pill" title="' + esc( __( 'Library changed since last scan — results may be outdated.', 'cf-media-manager' ) ) + '">' + esc( __( 'Stale', 'cf-media-manager' ) ) + '</span>' : '' ) +
				'</header>' +
				'<p class="cf-audit-card-desc">' + esc( r.description ) + '</p>' +
				bodyHtml +
			'</article>'
		);
	}

	function renderIdleCardBody() {
		return (
			'<div class="cf-audit-card-body">' +
				'<p class="cf-audit-card-empty">' + esc( __( 'Not yet scanned.', 'cf-media-manager' ) ) + '</p>' +
			'</div>' +
			'<footer class="cf-audit-card-footer">' +
				'<button type="button" class="button button-primary" data-cf-audit-action="scan">' +
					esc( __( 'Run scan', 'cf-media-manager' ) ) +
				'</button>' +
			'</footer>'
		);
	}

	function renderScanningCardBody( r ) {
		const scanned = ( r.running_totals && r.running_totals.scanned ) ? parseInt( r.running_totals.scanned, 10 ) : 0;
		const flagged = ( r.running_totals && r.running_totals.flagged ) ? parseInt( r.running_totals.flagged, 10 ) : 0;
		return (
			'<div class="cf-audit-card-body">' +
				'<p class="cf-audit-card-scanning">' +
					'<span class="cf-audit-spinner" aria-hidden="true"></span> ' +
					esc( __( 'Scanning…', 'cf-media-manager' ) ) +
				'</p>' +
				'<p class="cf-audit-card-progress">' +
					esc( __( 'Examined:', 'cf-media-manager' ) ) + ' <strong>' + scanned + '</strong> · ' +
					esc( __( 'Flagged:', 'cf-media-manager' ) ) + ' <strong>' + flagged + '</strong>' +
				'</p>' +
			'</div>' +
			'<footer class="cf-audit-card-footer">' +
				'<button type="button" class="button" data-cf-audit-action="cancel">' +
					esc( __( 'Cancel', 'cf-media-manager' ) ) +
				'</button>' +
			'</footer>'
		);
	}

	function renderCompleteCardBody( r, flagged, savings, scanned ) {
		return (
			'<div class="cf-audit-card-body">' +
				'<p class="cf-audit-card-count">' +
					'<span class="cf-audit-big-num">' + flagged + '</span> ' +
					'<span class="cf-audit-count-label">' + esc( flagged === 1 ? __( 'item flagged', 'cf-media-manager' ) : __( 'items flagged', 'cf-media-manager' ) ) + '</span>' +
				'</p>' +
				( savings ? '<p class="cf-audit-card-savings">' + esc( '~' + savings + ' ' + __( 'potentially recoverable', 'cf-media-manager' ) ) + '</p>' : '' ) +
				( scanned ? '<p class="cf-audit-card-scanned">' + esc( __( 'Last scan:', 'cf-media-manager' ) + ' ' + scanned ) + '</p>' : '' ) +
			'</div>' +
			'<footer class="cf-audit-card-footer">' +
				( flagged > 0 ? '<button type="button" class="button button-primary" data-cf-audit-action="review">' + esc( __( 'Review', 'cf-media-manager' ) ) + ' &rarr;</button>' : '' ) +
				'<button type="button" class="button" data-cf-audit-action="scan" title="' + esc( __( 'Rescan', 'cf-media-manager' ) ) + '">&#8635; ' + esc( __( 'Rescan', 'cf-media-manager' ) ) + '</button>' +
			'</footer>'
		);
	}

	function renderFailedCardBody( r ) {
		const msg = r.error === 'cancelled'
			? __( 'Scan cancelled.', 'cf-media-manager' )
			: ( __( 'Scan failed:', 'cf-media-manager' ) + ' ' + ( r.error || __( 'unknown error', 'cf-media-manager' ) ) );
		return (
			'<div class="cf-audit-card-body">' +
				'<p class="cf-audit-card-error">' + esc( msg ) + '</p>' +
			'</div>' +
			'<footer class="cf-audit-card-footer">' +
				'<button type="button" class="button button-primary" data-cf-audit-action="scan">' + esc( __( 'Try again', 'cf-media-manager' ) ) + '</button>' +
			'</footer>'
		);
	}

	// Card-level button delegation.
	$cards.on( 'click', '[data-cf-audit-action]', function ( e ) {
		e.preventDefault();
		const $btn       = $( e.currentTarget );
		const $card      = $btn.closest( '[data-cf-audit-card]' );
		const reportId   = $card.attr( 'data-cf-audit-card' );
		const action     = $btn.attr( 'data-cf-audit-action' );

		if ( ! reportId ) {
			return;
		}
		if ( action === 'review' ) {
			setHash( 'detail', reportId, 1 );
			return;
		}
		if ( action === 'scan' ) {
			runScan( reportId );
			return;
		}
		if ( action === 'cancel' ) {
			cancelScan( reportId );
			return;
		}
	} );

	$root.find( '[data-cf-audit-rescan-all]' ).on( 'click', function () {
		$cards.find( '[data-cf-audit-card]' ).each( function () {
			runScan( $( this ).attr( 'data-cf-audit-card' ) );
		} );
	} );

	// =========================================================================
	// Scan lifecycle: kick + poll
	// =========================================================================

	function runScan( reportId ) {
		ajax( 'audit_scan_chunk', { report: reportId, force: 1 } ).done( function ( resp ) {
			if ( ! resp || ! resp.success ) {
				return fetchDashboard();
			}
			updateCardFromState( reportId, resp.data );
			if ( resp.data.phase === 'scanning' ) {
				startPoll( reportId );
			} else {
				fetchDashboard();
			}
		} );
	}

	function cancelScan( reportId ) {
		clearPoll( reportId );
		ajax( 'audit_scan_cancel', { report: reportId } ).always( fetchDashboard );
	}

	function startPoll( reportId ) {
		clearPoll( reportId );
		pollState[ reportId ] = { attempts: 0, timer: 0 };
		pollState[ reportId ].timer = window.setTimeout( function tick() {
			if ( ! pollState[ reportId ] ) {
				return;
			}
			pollState[ reportId ].attempts++;
			if ( pollState[ reportId ].attempts > POLL_MAX ) {
				clearPoll( reportId );
				return;
			}
			ajax( 'audit_scan_chunk', { report: reportId } ).done( function ( resp ) {
				if ( ! resp || ! resp.success ) {
					clearPoll( reportId );
					fetchDashboard();
					return;
				}
				updateCardFromState( reportId, resp.data );
				if ( resp.data.phase !== 'scanning' ) {
					clearPoll( reportId );
					fetchDashboard();
					return;
				}
				pollState[ reportId ].timer = window.setTimeout( tick, POLL_MS );
			} ).fail( function () {
				clearPoll( reportId );
				fetchDashboard();
			} );
		}, POLL_MS );
	}

	function clearPoll( reportId ) {
		const handle = typeof reportId === 'string' ? pollState[ reportId ] : reportId;
		if ( handle ) {
			window.clearTimeout( handle.timer );
		}
		if ( typeof reportId === 'string' ) {
			delete pollState[ reportId ];
		}
	}

	function updateCardFromState( reportId, state ) {
		// Re-render just the in-progress card from the partial state
		// returned by scan_chunk. Simpler than diffing — and the cards
		// are small.
		const $card = $cards.find( '[data-cf-audit-card="' + reportId + '"]' );
		if ( ! $card.length ) {
			return;
		}
		// Synthesize a dashboard-row shape from the state so renderCard
		// works without a full dashboard refetch.
		const placeholder = {
			id:             reportId,
			label:          $card.find( 'h3' ).text(),
			description:    $card.find( '.cf-audit-card-desc' ).text(),
			phase:          state.phase,
			totals:         state.running_totals || {},
			running_totals: state.running_totals || {},
			scanned_at:     state.completed_at || null,
			is_stale:       false,
			error:          state.error || null,
		};
		const $next = $( renderCard( placeholder ) );
		$card.replaceWith( $next );
	}

	// =========================================================================
	// Detail view
	// =========================================================================

	let currentDetail = { report: null, page: 1, per_page: 50, supports_bulk: [], items: [] };

	function showDetail( reportId, page, perPage ) {
		Object.values( pollState ).forEach( clearPoll );
		$dashboard.attr( 'hidden', 'hidden' );
		$detail.removeAttr( 'hidden' );
		currentDetail.report   = reportId;
		currentDetail.page     = page || 1;
		currentDetail.per_page = perPage || 50;
		$detailBody.html( '<tr><td colspan="4" class="cf-audit-table-loading">' + esc( __( 'Loading…', 'cf-media-manager' ) ) + '</td></tr>' );
		fetchDetail();
	}

	function fetchDetail() {
		ajax( 'audit_detail', {
			report:   currentDetail.report,
			page:     currentDetail.page,
			per_page: currentDetail.per_page,
		} ).done( function ( resp ) {
			if ( ! resp || ! resp.success ) {
				$detailBody.html( '<tr><td colspan="4" class="cf-audit-error">' + esc( __( 'Could not load results.', 'cf-media-manager' ) ) + '</td></tr>' );
				return;
			}
			renderDetail( resp.data );
		} );
	}

	function renderDetail( data ) {
		const supports = data.supports_bulk || [];
		currentDetail.supports_bulk = supports;
		currentDetail.items         = data.items || [];

		// Header
		const titleNode = $cards.find( '[data-cf-audit-card="' + currentDetail.report + '"] h3' ).text() || currentDetail.report;
		$detailTitle.text( titleNode );

		const total = data.total || 0;
		$detailMeta.html(
			'<span>' + esc( total + ' ' + ( total === 1 ? __( 'item', 'cf-media-manager' ) : __( 'items', 'cf-media-manager' ) ) ) + '</span>' +
			( data.scanned_at ? ' <span class="cf-audit-sep">·</span> <span>' + esc( __( 'Scanned:', 'cf-media-manager' ) + ' ' + relativeTime( data.scanned_at ) ) + '</span>' : '' ) +
			( data.is_stale ? ' <span class="cf-audit-stale-pill">' + esc( __( 'Stale', 'cf-media-manager' ) ) + '</span>' : '' )
		);

		// Bulk action bar
		if ( supports.length ) {
			$bulkSelect.html( supports.map( function ( a ) {
				return '<option value="' + esc( a ) + '">' + esc( bulkActionLabel( a ) ) + '</option>';
			} ).join( '' ) );
			$actionbar.removeAttr( 'hidden' );
		} else {
			$actionbar.attr( 'hidden', 'hidden' );
		}
		$selectAll.prop( 'checked', false );
		$bulkApply.prop( 'disabled', true );
		$bulkStatus.text( '' );

		// CSV export button — present only when the report opts into
		// AuditReportCsvExportable. We render into the meta block since
		// the action bar already carries the bulk-action controls.
		renderCsvButton( !! data.csv_exportable );

		// Empty state
		if ( ! currentDetail.items.length ) {
			if ( data.no_scan ) {
				$detailBody.html( '<tr><td colspan="4" class="cf-audit-empty">' + esc( __( 'No scan has been run for this report yet. Return to the dashboard and click Run scan.', 'cf-media-manager' ) ) + '</td></tr>' );
			} else {
				$detailBody.html( '<tr><td colspan="4" class="cf-audit-empty">' + esc( __( 'No items.', 'cf-media-manager' ) ) + '</td></tr>' );
			}
			$pagination.empty();
			return;
		}

		// Rows
		$detailBody.html( currentDetail.items.map( renderRow ).join( '' ) );

		// Pagination
		renderPagination( data.page, data.pages );
	}

	function renderCsvButton( exportable ) {
		// Remove any existing button so re-renders don't duplicate.
		$detailMeta.find( '.cf-audit-csv-btn' ).remove();
		if ( ! exportable ) {
			return;
		}
		const url = new URL( cfg.ajaxUrl, window.location.href );
		url.searchParams.set( 'action',   'cf_media_manager_audit_export_csv' );
		url.searchParams.set( 'report',   currentDetail.report );
		url.searchParams.set( '_wpnonce', cfg.nonce );
		const $btn = $(
			'<a class="button cf-audit-csv-btn" href="' + esc( url.toString() ) + '">' +
				'&#8659; ' + esc( __( 'Export CSV', 'cf-media-manager' ) ) +
			'</a>'
		);
		$detailMeta.append( ' ', $btn );
	}

	function renderRow( item, idx ) {
		const key = ( typeof item.id !== 'undefined' && item.id !== null )
			? String( item.id )
			: ( item.path || '' );
		const headline = headlineForItem( item );
		const details  = detailsForItem( item );
		const why      = item.why ? renderWhy( item.why ) : '';

		return (
			'<tr data-cf-audit-row="' + esc( key ) + '">' +
				'<td class="cf-audit-td-check"><input type="checkbox" data-cf-audit-row-check value="' + esc( key ) + '"></td>' +
				'<td class="cf-audit-td-headline">' + headline + '</td>' +
				'<td class="cf-audit-td-details">' + details + '</td>' +
				'<td class="cf-audit-td-why">' +
					( why ? '<details><summary>' + esc( __( 'Why?', 'cf-media-manager' ) ) + '</summary>' + why + '</details>' : '' ) +
				'</td>' +
			'</tr>'
		);
	}

	function headlineForItem( item ) {
		if ( item.path ) {
			return '<code class="cf-audit-path">' + esc( item.path ) + '</code>';
		}
		if ( item.title ) {
			return '<strong>' + esc( item.title ) + '</strong>' +
				( item.id ? ' <span class="cf-audit-id">#' + esc( item.id ) + '</span>' : '' );
		}
		if ( item.id ) {
			return '<strong>#' + esc( item.id ) + '</strong>';
		}
		return '<em>' + esc( __( 'Unknown item', 'cf-media-manager' ) ) + '</em>';
	}

	function detailsForItem( item ) {
		const bits = [];
		if ( item.size_human ) {
			bits.push( esc( item.size_human ) );
		} else if ( item.size_bytes ) {
			bits.push( esc( humanBytes( item.size_bytes ) ) );
		}
		if ( item.mime ) {
			bits.push( esc( item.mime ) );
		}
		if ( item.mtime_human ) {
			bits.push( esc( item.mtime_human ) );
		}
		if ( item.date_uploaded ) {
			bits.push( esc( item.date_uploaded ) );
		}
		if ( item.expected_path ) {
			bits.push( '<span class="cf-audit-meta-path"><code>' + esc( item.expected_path ) + '</code></span>' );
		}
		return bits.length ? bits.join( ' <span class="cf-audit-sep">·</span> ' ) : '&mdash;';
	}

	function renderWhy( why ) {
		const rows = [];
		Object.keys( why ).forEach( function ( k ) {
			const v = why[ k ];
			let formatted;
			if ( Array.isArray( v ) ) {
				formatted = v.length ? v.map( esc ).join( ', ' ) : '<em>' + esc( __( 'none', 'cf-media-manager' ) ) + '</em>';
			} else if ( typeof v === 'object' && v !== null ) {
				formatted = '<code>' + esc( JSON.stringify( v ) ) + '</code>';
			} else if ( typeof v === 'boolean' ) {
				formatted = v ? '✓' : '✗';
			} else {
				formatted = esc( v );
			}
			rows.push( '<dt>' + esc( k ) + '</dt><dd>' + formatted + '</dd>' );
		} );
		return '<dl class="cf-audit-why">' + rows.join( '' ) + '</dl>';
	}

	function bulkActionLabel( action ) {
		switch ( action ) {
			case 'trash':    return __( 'Move to Trash', 'cf-media-manager' );
			case 'delete':   return __( 'Delete from disk', 'cf-media-manager' );
			case 'ignore':   return __( 'Ignore', 'cf-media-manager' );
			case 'unignore': return __( 'Un-ignore', 'cf-media-manager' );
			default:         return action;
		}
	}

	function renderPagination( current, pages ) {
		if ( pages <= 1 ) {
			$pagination.empty();
			return;
		}
		const btns = [];
		if ( current > 1 ) {
			btns.push( '<button class="button cf-audit-page-btn" data-page="' + ( current - 1 ) + '">&lsaquo; ' + esc( __( 'Prev', 'cf-media-manager' ) ) + '</button>' );
		}
		btns.push( '<span class="cf-audit-page-current">' + esc( __( 'Page', 'cf-media-manager' ) + ' ' + current + ' / ' + pages ) + '</span>' );
		if ( current < pages ) {
			btns.push( '<button class="button cf-audit-page-btn" data-page="' + ( current + 1 ) + '">' + esc( __( 'Next', 'cf-media-manager' ) ) + ' &rsaquo;</button>' );
		}
		$pagination.html( btns.join( '' ) );
	}

	$pagination.on( 'click', '.cf-audit-page-btn', function ( e ) {
		e.preventDefault();
		const p = parseInt( $( e.currentTarget ).attr( 'data-page' ), 10 );
		if ( p && p !== currentDetail.page ) {
			setHash( 'detail', currentDetail.report, p );
		}
	} );

	$root.find( '[data-cf-audit-back]' ).on( 'click', function ( e ) {
		e.preventDefault();
		setHash( 'dashboard' );
	} );

	// Row checkbox + bulk apply
	$detailBody.on( 'change', '[data-cf-audit-row-check]', function () {
		const checked = $detailBody.find( '[data-cf-audit-row-check]:checked' ).length;
		$bulkApply.prop( 'disabled', checked === 0 );
	} );
	$selectAll.on( 'change', function () {
		const c = $selectAll.is( ':checked' );
		$detailBody.find( '[data-cf-audit-row-check]' ).prop( 'checked', c ).trigger( 'change' );
	} );

	$bulkApply.on( 'click', function () {
		const action = $bulkSelect.val();
		const ids    = $detailBody.find( '[data-cf-audit-row-check]:checked' )
			.map( function () { return $( this ).val(); } )
			.get();
		if ( ! action || ! ids.length ) {
			return;
		}
		const confirmDestructive = ( action === 'trash' || action === 'delete' );
		if ( confirmDestructive ) {
			const msg = action === 'delete'
				? __( 'Permanently delete the selected files from disk? This cannot be undone.', 'cf-media-manager' )
				: __( 'Move the selected items to Trash?', 'cf-media-manager' );
			// eslint-disable-next-line no-alert
			if ( ! window.confirm( msg ) ) {
				return;
			}
		}
		$bulkApply.prop( 'disabled', true );
		$bulkStatus.text( __( 'Working…', 'cf-media-manager' ) );

		ajax( 'audit_bulk', {
			report:      currentDetail.report,
			action_verb: action,
			ids:         ids,
		} ).done( function ( resp ) {
			if ( ! resp || ! resp.success ) {
				$bulkStatus.text( ( resp && resp.data && resp.data.message ) || __( 'Action failed.', 'cf-media-manager' ) );
				$bulkApply.prop( 'disabled', false );
				return;
			}
			const r = resp.data;
			$bulkStatus.text(
				/* translators: 1: processed count 2: skipped count 3: error count */
				( __( 'Processed', 'cf-media-manager' ) + ': ' + r.processed + ' · ' +
				  __( 'Skipped', 'cf-media-manager' )   + ': ' + r.skipped   + ' · ' +
				  __( 'Errors', 'cf-media-manager' )    + ': ' + Object.keys( r.errors || {} ).length )
			);
			// Refresh the page to reflect the change.
			fetchDetail();
		} );
	} );

	// =========================================================================
	// Tab activation — only hydrate when the Audit tab actually opens, so
	// hopping straight to Convert doesn't pay the dashboard AJAX cost.
	// =========================================================================

	let hydrated = false;

	function hydrateOnce() {
		if ( hydrated ) {
			return;
		}
		hydrated = true;
		const route = parseHash();
		if ( route && route.view === 'detail' ) {
			showDetail( route.report, route.page, route.per_page );
		} else {
			showDashboard();
		}
	}

	// The Manager tab system uses data-tab attributes. Hook the existing
	// tab-button click to know when Audit becomes active.
	$( document ).on( 'click', '.cf-media-manager-tab[data-tab="library"]', hydrateOnce );

	// Auto-hydrate if the page loaded with the Audit tab already active
	// (localStorage-restored) or if the URL hash points at audit.
	$( function () {
		const activeTab = ( function () {
			try {
				return window.localStorage.getItem( 'cf_media_manager_active_tab' ) || '';
			} catch ( _e ) {
				return '';
			}
		} )();
		const hashRoute = parseHash();
		if ( hashRoute || activeTab === 'library' ) {
			hydrateOnce();
		}
	} );

	// =========================================================================
	// Helpers
	// =========================================================================

	function esc( s ) {
		return String( s == null ? '' : s )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#039;' );
	}

	function humanBytes( bytes ) {
		bytes = parseInt( bytes, 10 ) || 0;
		if ( bytes < 1024 ) return bytes + ' B';
		const units = [ 'KB', 'MB', 'GB', 'TB' ];
		let v = bytes / 1024;
		let i = 0;
		while ( v >= 1024 && i < units.length - 1 ) { v /= 1024; i++; }
		return v.toFixed( v < 10 ? 1 : 0 ) + ' ' + units[ i ];
	}

	function relativeTime( unixTs ) {
		const now  = Math.floor( Date.now() / 1000 );
		const diff = Math.max( 0, now - parseInt( unixTs, 10 ) );
		if ( diff < 60 )       return __( 'just now', 'cf-media-manager' );
		if ( diff < 3600 )     return Math.floor( diff / 60 )   + ' ' + __( 'min ago', 'cf-media-manager' );
		if ( diff < 86400 )    return Math.floor( diff / 3600 ) + ' ' + __( 'h ago', 'cf-media-manager' );
		if ( diff < 86400 * 7 ) return Math.floor( diff / 86400 ) + ' ' + __( 'd ago', 'cf-media-manager' );
		return new Date( unixTs * 1000 ).toISOString().slice( 0, 10 );
	}

} )( jQuery );
