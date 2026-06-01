/**
 * CF Media Manager — admin UI
 *
 * Handles four interaction modes:
 *   - Foreground AJAX runner ("Convert All" / "Convert Selected")
 *   - Background queue runner ("Convert in Background")
 *   - Settings + quality save
 *   - Live page verifier + cache management
 *
 * The queue runner polls cf_media_manager_queue_status while a run is active. The
 * tab can be closed safely — the WP-Cron / Action Scheduler backend keeps
 * processing on the server.
 *
 * i18n: every user-facing string flows through wp.i18n with the 'cf-media-manager'
 * text domain. Translations are loaded by wp_set_script_translations() on
 * the PHP side; see AdminPage::admin_scripts().
 */
jQuery( function ( $ ) {

	// wp-i18n is enqueued as a dependency in AdminPage::admin_scripts(),
	// so wp.i18n is guaranteed to exist by the time this runs.
	var __      = wp.i18n.__;
	var _n      = wp.i18n._n;
	var sprintf = wp.i18n.sprintf;

	// -----------------------------------------------------------------------
	// Tab navigation
	// -----------------------------------------------------------------------
	var TABS_STORAGE_KEY = 'cf_media_manager_active_tab';
	var $tabBtns         = $( '.cf-media-manager-tab' );
	var $tabPanels       = $( '.cf-media-manager-tab-panel' );

	function activateTab( name ) {
		$tabBtns.removeClass( 'is-active' ).attr( 'aria-selected', 'false' );
		$tabPanels.removeClass( 'is-active' );
		var $btn   = $tabBtns.filter( '[data-tab="' + name + '"]' );
		var $panel = $( '#cf-media-manager-panel-' + name );
		if ( $btn.length && $panel.length ) {
			$btn.addClass( 'is-active' ).attr( 'aria-selected', 'true' );
			$panel.addClass( 'is-active' );
			try { localStorage.setItem( TABS_STORAGE_KEY, name ); } catch ( e ) {}
			// Lazy-load tab-scoped data the first time the tab opens, so admins
			// who never visit a tab don't pay for its initial AJAX.
			if ( 'accessibility' === name ) {
				ensureAltListLoaded();
			}
		}
	}

	$tabBtns.on( 'click', function () {
		activateTab( $( this ).data( 'tab' ) );
	} );

	// Restore persisted tab on load (default to 'convert' if none saved).
	( function () {
		var saved = '';
		try { saved = localStorage.getItem( TABS_STORAGE_KEY ) || ''; } catch ( e ) {}
		var validTabs = $tabBtns.map( function () { return $( this ).data( 'tab' ); } ).get();
		if ( saved && validTabs.indexOf( saved ) !== -1 ) {
			activateTab( saved );
		}
	}() );

	// -----------------------------------------------------------------------
	// DOM refs
	// -----------------------------------------------------------------------
	var $status              = $( '#cf-media-manager-status' );
	var $bar                 = $( '#cf-media-manager-bar' );
	var $runBtn              = $( '#cf-media-manager-run' );
	var $runBgBtn            = $( '#cf-media-manager-run-bg' );
	var $stopBtn             = $( '#cf-media-manager-stop' );
	var $deleteBtn           = $( '#cf-media-manager-delete' );
	var $backfillBtn         = $( '#cf-media-manager-backfill' );
	var $doneMsg             = $( '#cf-media-manager-done-msg' );
	var $log                 = $( '#cf-media-manager-log' );
	var $qInput              = $( '#cf-media-manager-quality' );
	var $qSave               = $( '#cf-media-manager-save-quality' );
	var $qApply              = $( '#cf-media-manager-apply-quality' );
	var $qSaved              = $( '#cf-media-manager-quality-saved' );
	var $forceChk            = $( '#cf-media-manager-force' );
	var $selectBtn           = $( '#cf-media-manager-select' );
	var $selection           = $( '#cf-media-manager-selection' );
	var $selSummary          = $( '#cf-media-manager-sel-summary' );
	var $selList             = $( '#cf-media-manager-sel-list' );
	var $convertSelectedBtn  = $( '#cf-media-manager-convert-selected' );
	var $recheckBtn          = $( '#cf-media-manager-recheck' );
	var $queueBox            = $( '#cf-media-manager-queue' );
	var $scopeRadios         = $( 'input[name="cf_media_manager_bulk_scope"]' );
	var $scopeCountInUse     = $( '.cf-media-manager-scope-count[data-scope="in_use"]' );
	var $scopeDetail         = $( '#cf-media-manager-scope-detail' );
	var $rescanBtn           = $( '#cf-media-manager-rescan' );

	// -----------------------------------------------------------------------
	// State
	// -----------------------------------------------------------------------
	var totalCount = 0;
	var doneCount  = 0;
	var pendingIds = [];
	var allIds     = [];

	// In-use scan results (lazy-loaded; null until first fetch returns).
	var inUseIds         = null; // full set of front-end-referenced IDs
	var inUsePendingIds  = null; // subset still needing conversion
	var inUseSources     = {};
	var inUseBuilders    = {};
	var inUseScanRunning = false;

	var running     = false;
	var stopping    = false;
	var workingIds  = [];
	var runTotal    = 0;
	var runMode     = 'all'; // 'all' | 'selected' | 'in_use'
	var bytesSaved  = 0;
	var gdFallbacks = 0;
	var avifWritten = 0;

	var queuePollTimer = null;

	var selectedIds         = [];
	var selectedAttachments = {};
	var mediaFrame          = null;

	// -----------------------------------------------------------------------
	// Utilities
	// -----------------------------------------------------------------------
	function formatBytes( n ) {
		if ( n < 1024 ) {
			return sprintf( __( '%d B', 'cf-media-manager' ), n );
		}
		if ( n < 1024 * 1024 ) {
			return sprintf( __( '%s KB', 'cf-media-manager' ), ( n / 1024 ).toFixed( 1 ) );
		}
		if ( n < 1024 * 1024 * 1024 ) {
			return sprintf( __( '%s MB', 'cf-media-manager' ), ( n / 1024 / 1024 ).toFixed( 2 ) );
		}
		return sprintf( __( '%s GB', 'cf-media-manager' ), ( n / 1024 / 1024 / 1024 ).toFixed( 2 ) );
	}

	function escapeHtml( s ) {
		return String( s ).replace( /[&<>"']/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
		} );
	}

	// Centralized AJAX wrapper. Every endpoint in this file used to
	// spell out the same `$.post( ajaxUrl, { action: 'cf_media_manager_<slug>',
	// nonce: cfMediaManager.nonce, ... } )` boilerplate — 20+ call sites.
	// The downside wasn't only verbosity: it was possible to omit the
	// nonce on a new endpoint and not notice until the server rejected
	// the call in production. cfPost prepends the AJAX prefix, attaches
	// the nonce, and merges in any per-call payload. The action argument
	// is the bare slug (e.g. 'queue_start'), not the prefixed action name.
	//
	// $.extend ordering: data first, then action+nonce — so a future
	// caller that accidentally puts `action` or `nonce` into the data
	// object cannot override the wrapper's spec'd values. Defense in
	// depth against the failure mode we're trying to centralize out of
	// existence.
	function cfPost( action, data ) {
		return $.post( cfMediaManager.ajaxUrl, $.extend( {}, data || {}, {
			action: 'cf_media_manager_' + action,
			nonce:  cfMediaManager.nonce
		} ) );
	}

	// Format the per-attachment reason list returned by convert_batch into a
	// trailing " — reason1; reason2." string. Returns "." when there are no
	// reasons so the caller can append it unconditionally.
	function formatReasonSuffix( reasons ) {
		if ( ! reasons || ! reasons.length ) {
			return '.';
		}
		return ' — ' + reasons.join( '; ' ) + '.';
	}

	function showError( msg ) {
		$status.html( '<span style="color:#a00">&#9888; ' + escapeHtml( msg ) + '</span>' );
		appendLog( '⚠ ' + msg );
		console.error( '[cf-media-manager]', msg );
	}

	function appendLog( msg ) {
		// Use createTextNode so any server-provided string (filenames, error
		// messages, etc.) is rendered as literal text, never as HTML. Even
		// though our server side sanitizes these, this is the right place to
		// enforce the boundary — the log sink doesn't need to interpret HTML
		// and shouldn't be the layer that decides what's safe.
		if ( ! $log.length ) { return; }
		$log.show();
		$log[ 0 ].appendChild( document.createTextNode( String( msg ) + '\n' ) );
		$log.scrollTop( $log[ 0 ].scrollHeight );
	}

	// Allow-list of queue states we'll attach as a CSS class. Defends against
	// a server response that returns an unexpected status string (filter
	// override, future server change) showing up as a raw class fragment.
	var QUEUE_STATE_CLASSES = { running: 1, complete: 1, cancelled: 1, idle: 1 };
	function safeQueueStatusClass( status ) {
		return QUEUE_STATE_CLASSES[ status ] ? status : 'unknown';
	}

	function updateBar( done, total ) {
		var pct = total > 0 ? Math.round( ( done / total ) * 100 ) : 0;
		$bar.css( 'width', pct + '%' );
	}

	// -----------------------------------------------------------------------
	// Library status (counts)
	// -----------------------------------------------------------------------
	function loadStatus() {
		if ( running ) { return; }
		$recheckBtn.prop( 'disabled', true ).addClass( 'is-busy' );
		cfPost( 'status' )
			.done( function ( res ) {
				$recheckBtn.prop( 'disabled', false ).removeClass( 'is-busy' );
				if ( ! res || ! res.success ) {
					showError( __( 'Could not load status — check the browser console.', 'cf-media-manager' ) );
					return;
				}
				totalCount = res.data.total;
				doneCount  = res.data.done;
				pendingIds = res.data.pending || [];
				allIds     = res.data.all     || [];
				updateScopeCounts();
				renderScopeDetail();
				updateStatusText();
				updateBar( doneCount, totalCount );
				refreshRunButton();
				if ( pendingIds.length === 0 && totalCount > 0 ) {
					$doneMsg.show();
				} else {
					$doneMsg.hide();
				}
			} )
			.fail( function ( xhr ) {
				$recheckBtn.prop( 'disabled', false ).removeClass( 'is-busy' );
				showError( sprintf(
					/* translators: %d: HTTP status code from the failed status request. */
					__( 'Status request failed (HTTP %d).', 'cf-media-manager' ),
					xhr.status
				) );
			} );
	}

	$recheckBtn.on( 'click', function ( e ) {
		e.preventDefault();
		loadStatus();
	} );

	$( document ).on( 'visibilitychange', function () {
		if ( document.visibilityState === 'visible' && ! running ) {
			loadStatus();
			pollQueue(); // surface queue progress when the tab regains focus
		}
	} );

	function currentScope() {
		return $scopeRadios.filter( ':checked' ).val() || 'all';
	}

	/**
	 * Resolve the working ID list for the current scope + force state.
	 * Returns null while the in-use scan is still loading so callers can
	 * defer (and the buttons stay disabled).
	 */
	function workingIdsForCurrentScope() {
		var force = $forceChk.is( ':checked' );
		if ( currentScope() === 'in_use' ) {
			if ( inUseIds === null ) { return null; }
			return force ? inUseIds.slice() : ( inUsePendingIds || [] ).slice();
		}
		return ( force ? allIds : pendingIds ).slice();
	}

	function updateStatusText() {
		if ( totalCount === 0 ) {
			$status.text( __( 'No JPEG or PNG attachments found in the media library.', 'cf-media-manager' ) );
			return;
		}
		if ( running ) {
			var processed = runTotal - workingIds.length;
			$status.html(
				'<span class="spinner is-active" style="float:none;margin:0 6px 0 0;vertical-align:middle"></span>' +
				sprintf(
					/* translators: 1: <strong>Converting…</strong>, 2: processed count, 3: total count, 4: remaining count. */
					__( '%1$s %2$d of %3$d processed — %4$d remaining.', 'cf-media-manager' ),
					'<strong>' + __( 'Converting…', 'cf-media-manager' ) + '</strong>',
					processed,
					runTotal,
					workingIds.length
				)
			);
			updateBar( processed, runTotal );
		} else {
			// Status header narrates the CURRENT scope. When in_use is
			// selected the "X of Y converted" prefix used to read the global
			// totals while the trailing "Z to convert" read the in-use
			// subset — a contradiction on sites where the global library
			// was fully converted but the in-use scan picked up additional
			// IDs (e.g. attachments whose post_mime_type row is blank but
			// whose filename is .jpg/.png).
			var remaining, done, total, inUseSubset;
			if ( currentScope() === 'in_use' ) {
				if ( inUseIds === null ) {
					$status.text( __( 'Scanning the site for in-use images…', 'cf-media-manager' ) );
					return;
				}
				total       = inUseIds.length;
				inUseSubset = inUsePendingIds ? inUsePendingIds.length : 0;
				remaining   = $forceChk.is( ':checked' ) ? total : inUseSubset;
				done        = Math.max( 0, total - inUseSubset );
			} else {
				total     = totalCount;
				remaining = $forceChk.is( ':checked' ) ? allIds.length : pendingIds.length;
				done      = doneCount;
			}
			var remainingText = remaining > 0
				? sprintf(
					/* translators: %d: number of attachments still pending conversion. */
					__( '%d to convert.', 'cf-media-manager' ),
					remaining
				)
				: __( 'Nothing to do — everything in this scope is already converted.', 'cf-media-manager' );
			$status.html(
				sprintf(
					/* translators: 1: done count in <strong>, 2: total count in <strong>, 3: remaining-text trailing clause. */
					__( '%1$s of %2$s attachments converted. %3$s', 'cf-media-manager' ),
					'<strong>' + done + '</strong>',
					'<strong>' + total + '</strong>',
					remainingText
				)
			);
			updateBar( done, total );
		}
	}

	function refreshRunButton() {
		var ids = workingIdsForCurrentScope();
		var hasWork = ids !== null && ids.length > 0;
		$runBtn.prop( 'disabled', ! hasWork );
		$runBgBtn.prop( 'disabled', ! hasWork );
	}

	// -----------------------------------------------------------------------
	// In-use scan
	// -----------------------------------------------------------------------
	function updateScopeCounts() {
		// The "All images" option intentionally omits a pending count — the
		// global done/total is already conveyed by the status header above.
		// Only the In-use option carries a count badge.
		if ( inUseIds === null ) {
			$scopeCountInUse.text( inUseScanRunning ? __( 'scanning…', 'cf-media-manager' ) : '—' );
		} else {
			var count = $forceChk.is( ':checked' ) ? inUseIds.length : ( inUsePendingIds || [] ).length;
			$scopeCountInUse.text( '(' + count + ')' );
		}
	}

	function renderScopeDetail() {
		// Skip rendering until both data sources have arrived — otherwise
		// we'd flash "X of 0" between the two AJAX returns.
		if ( inUseIds === null || totalCount === 0 ) {
			$scopeDetail.empty();
			return;
		}
		var detected = [];
		if ( inUseBuilders.divi )        { detected.push( 'Divi' ); }
		if ( inUseBuilders.elementor )   { detected.push( 'Elementor' ); }
		if ( inUseBuilders.beaver )      { detected.push( 'Beaver Builder' ); }
		if ( inUseBuilders.bricks )      { detected.push( 'Bricks' ); }
		if ( inUseBuilders.wpbakery )    { detected.push( 'WPBakery' ); }
		if ( inUseBuilders.acf )         { detected.push( 'ACF' ); }
		if ( inUseBuilders.woocommerce ) { detected.push( 'WooCommerce' ); }

		var parts = [];
		parts.push( sprintf(
			/* translators: 1: in-use count in <strong>, 2: total media-library count in <strong>. */
			__( '%1$s of %2$s media-library images are referenced on the front end.', 'cf-media-manager' ),
			'<strong>' + inUseIds.length + '</strong>',
			'<strong>' + totalCount + '</strong>'
		) );
		if ( detected.length > 0 ) {
			parts.push( sprintf(
				/* translators: %s: comma-separated list of detected plugins/builders (e.g. "Divi, ACF"). */
				__( 'Detected: %s.', 'cf-media-manager' ),
				detected.join( ', ' )
			) );
		}
		$scopeDetail.html( parts.join( ' ' ) );
	}

	function loadInUseScan( forceRefresh ) {
		if ( inUseScanRunning ) { return; }
		inUseScanRunning = true;
		$rescanBtn.prop( 'disabled', true ).addClass( 'is-busy' );
		updateScopeCounts();
		cfPost( 'in_use_scan', {
			refresh : forceRefresh ? 1 : 0,
			force   : $forceChk.is( ':checked' ) ? 1 : 0
		} )
			.done( function ( res ) {
				inUseScanRunning = false;
				$rescanBtn.prop( 'disabled', false ).removeClass( 'is-busy' );
				if ( ! res || ! res.success ) {
					inUseIds = [];
					inUsePendingIds = [];
					updateScopeCounts();
					showError( __( 'In-use scan failed.', 'cf-media-manager' ) );
					return;
				}
				inUseIds        = res.data.ids         || [];
				inUsePendingIds = res.data.pending_ids || [];
				inUseSources    = res.data.sources     || {};
				inUseBuilders   = res.data.builders    || {};
				updateScopeCounts();
				renderScopeDetail();
				if ( currentScope() === 'in_use' ) {
					updateStatusText();
					refreshRunButton();
				}
			} )
			.fail( function () {
				inUseScanRunning = false;
				$rescanBtn.prop( 'disabled', false ).removeClass( 'is-busy' );
				inUseIds = [];
				inUsePendingIds = [];
				updateScopeCounts();
				showError( __( 'In-use scan request failed.', 'cf-media-manager' ) );
			} );
	}

	$scopeRadios.on( 'change', function () {
		updateStatusText();
		refreshRunButton();
	} );

	$rescanBtn.on( 'click', function ( e ) {
		e.preventDefault();
		loadInUseScan( true );
	} );

	// -----------------------------------------------------------------------
	// Foreground (AJAX) batch loop
	// -----------------------------------------------------------------------
	function runNextBatch() {
		if ( stopping || workingIds.length === 0 ) {
			finishRun();
			return;
		}

		var batch = workingIds.splice( 0, cfMediaManager.batchSize );
		var force = $forceChk.is( ':checked' ) ? 1 : 0;

		cfPost( 'convert_batch', {
			ids    : batch,
			force  : force
		} )
		.done( function ( res ) {
			if ( ! res || ! res.success ) {
				showError( __( 'Server returned an error — conversion stopped.', 'cf-media-manager' ) );
				console.error( '[cf-media-manager] batch response:', res );
				finishRun();
				return;
			}
			try {
				bytesSaved  += parseInt( res.data.bytes_saved,  10 ) || 0;
				gdFallbacks += parseInt( res.data.gd_fallbacks, 10 ) || 0;
				avifWritten += parseInt( res.data.avif_written, 10 ) || 0;
				var results = res.data.results || {};
				$.each( results, function ( id, r ) {
					if ( ! r ) {
						return;
					}
					if ( r.status === 'partial' ) {
						var head = sprintf(
							/* translators: 1: filename or "ID <n>", 2: number of variants that failed. */
							_n(
								'%1$s — %2$d variant failed',
								'%1$s — %2$d variants failed',
								r.failed,
								'cf-media-manager'
							),
							r.file || sprintf( __( 'ID %d', 'cf-media-manager' ), id ),
							r.failed
						);
						appendLog( '⚠ ' + head + formatReasonSuffix( r.reasons ) );
					} else if ( r.status === 'skip' ) {
						var label = r.file
							? r.file + ' (' + sprintf( __( 'ID %d', 'cf-media-manager' ), id ) + ')'
							: sprintf( __( 'ID %d', 'cf-media-manager' ), id );
						appendLog( '⚠ ' + sprintf(
							/* translators: %s: filename and/or "ID <n>" label. */
							__( '%s skipped', 'cf-media-manager' ),
							label
						) + formatReasonSuffix( r.reasons ) );
					}
				} );
			} catch ( e ) {
				console.error( '[cf-media-manager] error processing batch result:', e, res );
			}
			updateStatusText();
			runNextBatch();
		} )
		.fail( function ( xhr ) {
			showError( sprintf(
				/* translators: %d: HTTP status code. */
				__( 'AJAX request failed (HTTP %d) — conversion stopped.', 'cf-media-manager' ),
				xhr.status
			) );
			finishRun();
		} );
	}

	function finishRun() {
		running  = false;
		stopping = false;
		$stopBtn.prop( 'disabled', false ).text( __( 'Stop', 'cf-media-manager' ) ).hide();

		if ( runMode === 'selected' ) {
			$convertSelectedBtn.prop( 'disabled', false ).show();
			loadSelectionStatus();
		} else {
			$runBtn.show();
			$runBgBtn.show();
			if ( workingIds.length === 0 ) { $doneMsg.show(); }
		}

		if ( bytesSaved > 0 ) {
			appendLog( '✓ ' + sprintf(
				/* translators: %s: human-readable bytes (e.g. "1.4 MB"). */
				__( 'Saved %s total across all converted images.', 'cf-media-manager' ),
				formatBytes( bytesSaved )
			) );
		}
		if ( avifWritten > 0 ) {
			appendLog( '✓ ' + sprintf(
				/* translators: %d: number of attachments AVIF was generated for. */
				_n(
					'AVIF written for %d file.',
					'AVIF written for %d files.',
					avifWritten,
					'cf-media-manager'
				),
				avifWritten
			) );
		}
		if ( gdFallbacks > 0 ) {
			appendLog( '⚠ ' + sprintf(
				/* translators: %d: number of files that used the GD fallback after Imagick failed. */
				_n(
					'%d file used GD fallback — Imagick failed for it. Check error_log.',
					'%d files used GD fallback — Imagick failed for these. Check error_log.',
					gdFallbacks,
					'cf-media-manager'
				),
				gdFallbacks
			) );
		}

		loadStatus();
	}

	function startRunAll( force ) {
		if ( force ) { $forceChk.prop( 'checked', true ); }
		var ids = workingIdsForCurrentScope();
		if ( ids === null || ids.length === 0 ) { return; }
		workingIds = ids;
		runMode    = currentScope() === 'in_use' ? 'in_use' : 'all';
		runTotal   = workingIds.length;
		stopping   = false;
		bytesSaved  = 0;
		gdFallbacks = 0;
		avifWritten = 0;

		$doneMsg.hide();
		$log.empty().hide();
		$runBtn.hide();
		$runBgBtn.hide();
		$stopBtn.show();

		running = true;
		updateStatusText();
		runNextBatch();
	}

	$runBtn.on( 'click', function () { startRunAll( false ); } );

	$stopBtn.on( 'click', function () {
		stopping = true;
		$stopBtn.prop( 'disabled', true ).text( __( 'Stopping…', 'cf-media-manager' ) );
	} );

	$forceChk.on( 'change', function () {
		updateScopeCounts();
		refreshRunButton();
		updateStatusText();
	} );

	// -----------------------------------------------------------------------
	// Background queue
	// -----------------------------------------------------------------------
	$runBgBtn.on( 'click', function () {
		if ( ! confirm( __( 'Queue this run in the background? You can close this tab — conversion will continue on the server.', 'cf-media-manager' ) ) ) {
			return;
		}
		var force = $forceChk.is( ':checked' ) ? 1 : 0;
		var mode  = currentScope() === 'in_use' ? 'in_use' : 'pending';
		$runBgBtn.prop( 'disabled', true ).text( __( 'Queuing…', 'cf-media-manager' ) );
		cfPost( 'queue_start', {
			mode   : mode,
			force  : force
		} )
		.done( function ( res ) {
			$runBgBtn.text( __( 'Convert in Background', 'cf-media-manager' ) );
			if ( ! res || ! res.success ) {
				$runBgBtn.prop( 'disabled', false );
				showError( __( 'Could not start queue.', 'cf-media-manager' ) );
				return;
			}
			renderQueueState( res.data.state );
			pollQueue();
		} )
		.fail( function () {
			$runBgBtn.prop( 'disabled', false ).text( __( 'Convert in Background', 'cf-media-manager' ) );
			showError( __( 'Queue request failed.', 'cf-media-manager' ) );
		} );
	} );

	function renderQueueState( state ) {
		if ( ! state || state.status === 'idle' ) {
			$queueBox.hide().empty();
			$runBgBtn.prop( 'disabled', false );
			return;
		}

		var pct = state.total > 0 ? Math.round( ( state.processed / state.total ) * 100 ) : 0;
		var statusLabel = ( {
			running   : __( 'Running', 'cf-media-manager' ),
			complete  : __( 'Complete', 'cf-media-manager' ),
			cancelled : __( 'Cancelled', 'cf-media-manager' )
		} )[ state.status ] || state.status;

		var html = '<div class="cf-queue-card cf-queue-' + safeQueueStatusClass( state.status ) + '">';
		html += '<div class="cf-queue-row">';
		html += '<strong>' + __( 'Background queue:', 'cf-media-manager' ) + '</strong> ' + escapeHtml( statusLabel );
		html += ' <span class="cf-queue-backend">(' + escapeHtml( state.backend ) + ')</span>';
		html += '</div>';
		html += '<div class="cf-queue-bar"><div class="cf-queue-bar-fill" style="width:' + pct + '%"></div></div>';
		html += '<div class="cf-queue-row cf-queue-stats">';
		html += '<span>' + sprintf(
			/* translators: 1: processed count, 2: total count. */
			__( '%1$d / %2$d processed', 'cf-media-manager' ),
			state.processed,
			state.total
		) + '</span>';
		html += ' · <span>' + sprintf(
			/* translators: %d: count of successfully converted attachments. */
			__( '%d converted', 'cf-media-manager' ),
			state.converted
		) + '</span>';
		html += ' · <span>' + sprintf(
			/* translators: %d: count of attachments that failed conversion. */
			__( '%d failed', 'cf-media-manager' ),
			state.failed
		) + '</span>';
		if ( state.avif_written > 0 ) {
			html += ' · <span>' + sprintf(
				/* translators: %d: count of AVIF files written. */
				__( '%d AVIF', 'cf-media-manager' ),
				state.avif_written
			) + '</span>';
		}
		html += ' · <span>' + sprintf(
			/* translators: %s: human-readable bytes (e.g. "1.4 MB"). */
			__( '%s saved', 'cf-media-manager' ),
			formatBytes( state.bytes_saved )
		) + '</span>';
		html += '</div>';

		html += '<div class="cf-queue-row">';
		if ( state.status === 'running' ) {
			html += '<button class="button cf-queue-cancel-btn">' + __( 'Cancel queue', 'cf-media-manager' ) + '</button>';
		} else {
			html += '<button class="button cf-queue-clear-btn">' + __( 'Dismiss', 'cf-media-manager' ) + '</button>';
		}
		html += '</div>';
		html += '</div>';

		$queueBox.html( html ).show();

		if ( state.status === 'running' ) {
			$runBgBtn.prop( 'disabled', true );
			$runBtn.prop( 'disabled', true );
		} else {
			$runBgBtn.prop( 'disabled', false );
			refreshRunButton();
			loadStatus();
		}
	}

	$queueBox.on( 'click', '.cf-queue-cancel-btn', function () {
		if ( ! confirm( __( 'Cancel the background queue? Already-converted files will stay; remaining files won’t be processed.', 'cf-media-manager' ) ) ) {
			return;
		}
		cfPost( 'queue_cancel' )
			.done( function ( res ) {
				if ( res && res.success ) { renderQueueState( res.data.state ); }
			} );
	} );

	$queueBox.on( 'click', '.cf-queue-clear-btn', function () {
		cfPost( 'queue_clear' )
			.done( function ( res ) {
				if ( res && res.success ) {
					$queueBox.hide().empty();
					loadStatus();
				}
			} );
	} );

	function pollQueue() {
		if ( queuePollTimer ) { clearTimeout( queuePollTimer ); queuePollTimer = null; }
		cfPost( 'queue_status' )
			.done( function ( res ) {
				if ( ! res || ! res.success ) { return; }
				var state = res.data.state || {};
				renderQueueState( state );
				if ( state.status === 'running' ) {
					queuePollTimer = setTimeout( pollQueue, 2500 );
				}
			} );
	}

	// -----------------------------------------------------------------------
	// Delete all variants (WebP + AVIF)
	//
	// Both the count and delete endpoints are incremental: the client sends
	// cursor='' to start, then loops with the next_cursor returned by the
	// server until next_cursor is null. Each tick processes one top-level
	// subtree of uploads/ (typically a year folder). Prevents OOM on sites
	// with 100K+ attachments where the legacy single-call walk would
	// stat-storm the filesystem.
	// -----------------------------------------------------------------------

	function pollIncremental( action, onTick, onDone, onError, extraParams ) {
		var cursor = '';
		function tick() {
			var payload = $.extend( {}, extraParams || {}, { cursor: cursor } );
			cfPost( action, payload )
				.done( function ( res ) {
					if ( ! res || ! res.success ) {
						onError && onError();
						return;
					}
					onTick && onTick( res.data );
					if ( res.data.next_cursor ) {
						cursor = res.data.next_cursor;
						tick();
					} else {
						onDone && onDone();
					}
				} )
				.fail( function ( xhr ) {
					onError && onError( xhr );
				} );
		}
		tick();
	}

	function performDelete() {
		$deleteBtn.prop( 'disabled', true ).text( __( 'Deleting…', 'cf-media-manager' ) );
		var totalDeleted = 0;
		var totalErrors  = 0;
		var totalSkipped = 0;
		pollIncremental(
			'delete_all',
			function onTick( data ) {
				totalDeleted += data.partial.deleted;
				totalErrors  += data.partial.errors;
				totalSkipped += ( data.partial.skipped_foreign || 0 );
				if ( data.progress && data.progress.total > 1 ) {
					$deleteBtn.text( sprintf(
						/* translators: 1: chunks done, 2: total chunks. */
						__( 'Deleting… (%1$d/%2$d)', 'cf-media-manager' ),
						data.progress.index,
						data.progress.total
					) );
				}
			},
			function onDone() {
				$deleteBtn.prop( 'disabled', false ).text( __( 'Delete All Variants', 'cf-media-manager' ) );
				if ( totalErrors > 0 ) {
					appendLog( '⚠ ' + sprintf(
						/* translators: %d: number of files that could not be deleted. */
						_n(
							'%d file could not be deleted.',
							'%d files could not be deleted.',
							totalErrors,
							'cf-media-manager'
						),
						totalErrors
					) );
				}
				if ( totalSkipped > 0 ) {
					appendLog( 'ℹ ' + sprintf(
						/* translators: %d: number of variant-shaped files left in place because the plugin did not generate them. */
						_n(
							'%d untracked file left in place (not generated by this plugin).',
							'%d untracked files left in place (not generated by this plugin).',
							totalSkipped,
							'cf-media-manager'
						),
						totalSkipped
					) );
				}
				appendLog( sprintf(
					/* translators: %d: number of deleted files. */
					_n( 'Deleted %d file.', 'Deleted %d files.', totalDeleted, 'cf-media-manager' ),
					totalDeleted
				) );
				$doneMsg.hide();
				loadStatus();
			},
			function onError( xhr ) {
				$deleteBtn.prop( 'disabled', false ).text( __( 'Delete All Variants', 'cf-media-manager' ) );
				showError( sprintf(
					/* translators: %d: HTTP status code. */
					__( 'Delete AJAX request failed (HTTP %d).', 'cf-media-manager' ),
					xhr ? xhr.status : 0
				) );
			}
		);
	}

	$deleteBtn.on( 'click', function () {
		$deleteBtn.prop( 'disabled', true ).text( __( 'Counting…', 'cf-media-manager' ) );
		var totals = { count_webp: 0, count_avif: 0, count: 0, bytes: 0 };
		pollIncremental(
			'count_variants',
			function onTick( data ) {
				totals.count_webp += data.partial.count_webp;
				totals.count_avif += data.partial.count_avif;
				totals.count      += data.partial.count;
				totals.bytes      += data.partial.bytes;
				if ( data.progress && data.progress.total > 1 ) {
					$deleteBtn.text( sprintf(
						/* translators: 1: chunks done, 2: total chunks. */
						__( 'Counting… (%1$d/%2$d)', 'cf-media-manager' ),
						data.progress.index,
						data.progress.total
					) );
				}
			},
			function onDone() {
				$deleteBtn.prop( 'disabled', false ).text( __( 'Delete All Variants', 'cf-media-manager' ) );
				if ( totals.count === 0 ) {
					alert( __( 'No WebP or AVIF files to delete.', 'cf-media-manager' ) );
					return;
				}
				var detail = sprintf(
					/* translators: 1: WebP file count, 2: AVIF file count. */
					__( '%1$d WebP, %2$d AVIF', 'cf-media-manager' ),
					totals.count_webp,
					totals.count_avif
				);
				var prompt = sprintf(
					/* translators: 1: total files (e.g. "12 files"), 2: detail like "10 WebP, 2 AVIF", 3: human-readable bytes. */
					__( 'Delete %1$s (%2$s, %3$s)?\n\nOriginals are never touched.', 'cf-media-manager' ),
					sprintf( _n( '%d file', '%d files', totals.count, 'cf-media-manager' ), totals.count ),
					detail,
					formatBytes( totals.bytes )
				);
				if ( confirm( prompt ) ) {
					performDelete();
				}
			},
			function onError( xhr ) {
				$deleteBtn.prop( 'disabled', false ).text( __( 'Delete All Variants', 'cf-media-manager' ) );
				showError( sprintf(
					/* translators: %d: HTTP status code. */
					__( 'Count AJAX request failed (HTTP %d).', 'cf-media-manager' ),
					xhr ? xhr.status : 0
				) );
			}
		);
	} );

	// -----------------------------------------------------------------------
	// Backfill manifest — one-time adoption flow for pre-1.2.2 installs.
	//
	// Single-pass: pre-built attachment lookup maps make resolution cheap
	// enough that a separate dry-run pass isn't worth the round-trip. The
	// adopt-guard (variant-is-itself-an-attachment check) protects against
	// claiming user-uploaded .webp files regardless. We still confirm before
	// running because the operation does write postmeta rows.
	// -----------------------------------------------------------------------
	function runBackfill( onDone ) {
		var totalClaimed   = 0;
		var totalProcessed = 0;
		pollIncremental(
			'backfill_manifest',
			function onTick( data ) {
				totalClaimed   += ( data.partial && data.partial.claimed )   || 0;
				totalProcessed += ( data.partial && data.partial.processed ) || 0;
				// Show both the outer subtree progress and a running file
				// counter — multiple inner chunks per subtree mean the
				// (N/M) advances slowly while the file count moves quickly.
				if ( data.progress && data.progress.total > 0 ) {
					$backfillBtn.text( sprintf(
						/* translators: 1: chunks done, 2: total chunks, 3: files processed so far. */
						__( 'Adopting… (subtree %1$d/%2$d, %3$d files)', 'cf-media-manager' ),
						data.progress.index,
						data.progress.total,
						totalProcessed
					) );
				}
			},
			function onDoneTick() {
				onDone( totalClaimed );
			},
			function onError( xhr ) {
				$backfillBtn.prop( 'disabled', false ).text( __( 'Adopt legacy variants…', 'cf-media-manager' ) );
				showError( sprintf(
					/* translators: %d: HTTP status code. */
					__( 'Backfill AJAX request failed (HTTP %d).', 'cf-media-manager' ),
					xhr ? xhr.status : 0
				) );
			},
			{ dry_run: 0 }
		);
	}

	$backfillBtn.on( 'click', function () {
		var prompt = __( 'Adopt all untracked WebP/AVIF files into the plugin manifest?\n\nFiles registered as Media Library attachments are skipped automatically. Manually placed files cannot be distinguished from legacy plugin variants and will be adopted.\n\nThis runs through every uploads-folder subtree and may take several minutes on a large site.', 'cf-media-manager' );
		if ( ! confirm( prompt ) ) {
			return;
		}
		$backfillBtn.prop( 'disabled', true ).text( __( 'Adopting…', 'cf-media-manager' ) );
		runBackfill( function ( adopted ) {
			$backfillBtn.prop( 'disabled', true ).text( __( 'Done', 'cf-media-manager' ) );
			appendLog( sprintf(
				/* translators: %d: number of adopted files. */
				_n( 'Adopted %d file into the manifest.', 'Adopted %d files into the manifest.', adopted, 'cf-media-manager' ),
				adopted
			) );
			loadStatus();
		} );
	} );

	// -----------------------------------------------------------------------
	// Quality save
	// -----------------------------------------------------------------------
	function saveQuality( done ) {
		var q = parseInt( $qInput.val(), 10 );
		if ( isNaN( q ) || q < 1 || q > 100 ) {
			alert( __( 'Quality must be between 1 and 100.', 'cf-media-manager' ) );
			return;
		}
		cfPost( 'save_quality', { quality: q } )
			.done( function ( res ) {
				if ( res && res.success ) {
					$qSaved.show().delay( 2000 ).fadeOut();
					if ( typeof done === 'function' ) { done(); }
				}
			} );
	}

	$qSave.on( 'click', function () { saveQuality(); } );

	$qApply.on( 'click', function () {
		if ( ! confirm( __( 'Save this quality and re-encode every existing variant at the new setting? This may take a while.', 'cf-media-manager' ) ) ) {
			return;
		}
		saveQuality( function () { startRunAll( true ); } );
	} );

	// -----------------------------------------------------------------------
	// Media picker — convert specific images
	// -----------------------------------------------------------------------
	$selectBtn.on( 'click', function () {
		if ( ! mediaFrame ) {
			mediaFrame = wp.media( {
				title    : __( 'Select Images to Convert', 'cf-media-manager' ),
				multiple : true,
				library  : { type: 'image' },
				button   : { text: __( 'Select for Conversion', 'cf-media-manager' ) }
			} );

			mediaFrame.on( 'open', function () {
				var sel = mediaFrame.state().get( 'selection' );
				selectedIds.forEach( function ( id ) {
					var a = wp.media.attachment( id );
					a.fetch();
					sel.add( a ? [ a ] : [] );
				} );
			} );

			mediaFrame.on( 'select', function () {
				selectedIds         = [];
				selectedAttachments = {};
				mediaFrame.state().get( 'selection' ).each( function ( attachment ) {
					var a = attachment.toJSON();
					selectedIds.push( a.id );
					selectedAttachments[ a.id ] = {
						label: a.filename || a.title || sprintf( __( 'ID %d', 'cf-media-manager' ), a.id )
					};
				} );
				loadSelectionStatus();
			} );
		}
		mediaFrame.open();
	} );

	function loadSelectionStatus() {
		if ( selectedIds.length === 0 ) {
			$selection.hide();
			return;
		}
		$selSummary.text( __( 'Loading status…', 'cf-media-manager' ) );
		$selList.empty();
		$selection.show();
		$convertSelectedBtn.prop( 'disabled', true );

		cfPost( 'attachment_status', { ids: selectedIds } )
			.done( function ( res ) {
				if ( ! res || ! res.success ) {
					$selSummary.text( __( 'Could not load attachment status.', 'cf-media-manager' ) );
					return;
				}
				var statuses  = res.data.statuses || {};
				var doneN     = 0;
				var pendingN  = 0;

				selectedIds.forEach( function ( id ) {
					var s     = statuses[ id ] || {};
					var label = s.label
						|| ( selectedAttachments[ id ] && selectedAttachments[ id ].label )
						|| sprintf( __( 'ID %d', 'cf-media-manager' ), id );
					var doneFlag = !! s.converted;
					if ( doneFlag ) { doneN++; } else { pendingN++; }
					var badge = doneFlag
						? __( 'Converted', 'cf-media-manager' )
						: __( 'Pending', 'cf-media-manager' );
					$selList.append(
						'<div class="cf-sel-item ' + ( doneFlag ? 'is-done' : 'is-pending' ) + '">' +
						'<span class="cf-sel-name">' + escapeHtml( label ) + '</span>' +
						'<span class="cf-sel-badge">' + escapeHtml( badge ) + '</span>' +
						'</div>'
					);
				} );

				var parts = [];
				if ( doneN  > 0 ) {
					parts.push( sprintf(
						/* translators: %s: bolded count of already-converted attachments. */
						__( '%s already converted', 'cf-media-manager' ),
						'<strong>' + doneN + '</strong>'
					) );
				}
				if ( pendingN > 0 ) {
					parts.push( sprintf(
						/* translators: %s: bolded count of pending attachments. */
						__( '%s pending', 'cf-media-manager' ),
						'<strong>' + pendingN + '</strong>'
					) );
				}
				$selSummary.html( sprintf(
					/* translators: 1: count + word "image(s) selected", 2: comma-joined detail like "5 already converted, 3 pending". */
					__( '%1$s — %2$s.', 'cf-media-manager' ),
					sprintf( _n( '%d image selected', '%d images selected', selectedIds.length, 'cf-media-manager' ), selectedIds.length ),
					parts.join( ', ' )
				) );
				$convertSelectedBtn.prop( 'disabled', pendingN === 0 && ! $forceChk.is( ':checked' ) );
			} )
			.fail( function () {
				$selSummary.text( __( 'Failed to load attachment status.', 'cf-media-manager' ) );
			} );
	}

	$convertSelectedBtn.on( 'click', function () {
		workingIds = selectedIds.slice();
		if ( workingIds.length === 0 ) { return; }
		runMode    = 'selected';
		runTotal   = workingIds.length;
		stopping   = false;
		bytesSaved  = 0;
		gdFallbacks = 0;
		avifWritten = 0;

		$log.empty().hide();
		$convertSelectedBtn.hide();
		$stopBtn.show();

		running = true;
		updateStatusText();
		runNextBatch();
	} );

	// -----------------------------------------------------------------------
	// Settings — HTML Rewriting
	// -----------------------------------------------------------------------
	var $rewriteEnabled  = $( '#cf-media-manager-rewrite-enabled' );
	var $rewriteSettings = $( '.cf-media-manager-rewrite-settings' );
	var $filterMode      = $( '#cf-media-manager-filter-mode' );
	var $patternsRow     = $( '#cf-media-manager-patterns-row' );
	var $filterPatterns  = $( '#cf-media-manager-filter-patterns' );
	var $batchSize       = $( '#cf-media-manager-batch-size' );
	var $enableAvif      = $( '#cf-media-manager-enable-avif' );
	var $rewriteFavicons = $( '#cf-media-manager-rewrite-favicons' );
	var $maxSourceMb     = $( '#cf-media-manager-max-source-mb' );
	var $saveSettingsBtn = $( '#cf-media-manager-save-settings' );
	var $settingsSaved   = $( '#cf-media-manager-settings-saved' );

	function toggleRewriteSettings() {
		$rewriteSettings.toggle( $rewriteEnabled.is( ':checked' ) );
	}

	function togglePatternsRow() {
		$patternsRow.toggle( $filterMode.val() !== 'none' );
	}

	$rewriteEnabled.on( 'change', toggleRewriteSettings );
	$filterMode.on( 'change', togglePatternsRow );

	$saveSettingsBtn.on( 'click', function () {
		$saveSettingsBtn.prop( 'disabled', true );
		cfPost( 'save_settings', {
			rewrite         : $rewriteEnabled.is( ':checked' ) ? 1 : 0,
			enable_avif     : $enableAvif.is( ':checked' ) ? 1 : 0,
			rewrite_favicons: $rewriteFavicons.is( ':checked' ) ? 1 : 0,
			max_source_mb   : parseInt( $maxSourceMb.val(), 10 ) || 50,
			scope           : $( 'input[name="cf_media_manager_scope"]:checked' ).val() || 'all',
			filter_mode     : $filterMode.val(),
			filter_patterns : $filterPatterns.val(),
			batch_size      : parseInt( $batchSize.val(), 10 ) || 1,
			delete_on_uninstall : $( '#cf-media-manager-delete-on-uninstall' ).is( ':checked' ) ? 1 : 0
		} )
		.done( function ( res ) {
			$saveSettingsBtn.prop( 'disabled', false );
			if ( res && res.success ) {
				$settingsSaved.show().delay( 2500 ).fadeOut();
				if ( res.data && res.data.batch_size ) {
					cfMediaManager.batchSize = res.data.batch_size;
				}
			}
		} )
		.fail( function () {
			$saveSettingsBtn.prop( 'disabled', false );
			showError( __( 'Failed to save settings.', 'cf-media-manager' ) );
		} );
	} );

	toggleRewriteSettings();

	// -----------------------------------------------------------------------
	// Live page verifier
	// -----------------------------------------------------------------------
	var $verifyUrl    = $( '#cf-media-manager-verify-url' );
	var $verifyBtn    = $( '#cf-media-manager-verify-btn' );
	var $verifyResult = $( '#cf-media-manager-verify-result' );

	$verifyBtn.on( 'click', function () {
		var url = $.trim( $verifyUrl.val() );
		if ( ! url ) { return; }

		$verifyBtn.prop( 'disabled', true ).text( __( 'Fetching…', 'cf-media-manager' ) );
		$verifyResult.hide().empty();

		cfPost( 'verify_url', { url: url } )
			.done( function ( res ) {
				$verifyBtn.prop( 'disabled', false ).text( __( 'Verify', 'cf-media-manager' ) );
				if ( ! res || ! res.success ) {
					var msg = ( res && res.data ) ? res.data : __( 'Verification failed.', 'cf-media-manager' );
					$verifyResult.show().html(
						'<div class="notice notice-error inline"><p><strong>' +
						__( 'Error:', 'cf-media-manager' ) +
						'</strong> ' + escapeHtml( msg ) + '</p></div>'
					);
					return;
				}

				var d = res.data;
				var modern = ( d.webp_count || 0 ) + ( d.avif_count || 0 );
				var noticeClass = d.legacy_count === 0 && modern > 0 ? 'notice-success'
					: ( modern === 0 ? 'notice-error' : 'notice-warning' );

				var html = '<div class="notice ' + noticeClass + ' inline"><p>';
				html += sprintf(
					/* translators: 1: HTTP status code, 2: percent of modern-format images, 3: AVIF count, 4: WebP count, 5: legacy JPEG/PNG count. */
					__( '<strong>HTTP %1$d</strong> · %2$d%% modern formats — <strong>%3$d</strong> AVIF, <strong>%4$d</strong> WebP, <strong>%5$d</strong> JPEG/PNG outside &lt;picture&gt;.', 'cf-media-manager' ),
					d.http_code,
					d.percent_modern,
					d.avif_count,
					d.webp_count,
					d.legacy_count
				);
				if ( d.favicon_count > 0 ) {
					html += ' ' + sprintf(
						/* translators: %d: number of PNG/ICO favicons kept in their original format by design. */
						_n(
							'<strong>%d</strong> favicon PNG kept as PNG by design (excluded from the legacy count).',
							'<strong>%d</strong> favicon PNGs kept as PNG by design (excluded from the legacy count).',
							d.favicon_count,
							'cf-media-manager'
						),
						d.favicon_count
					);
				}
				html += '</p>';

				if ( d.og_image ) {
					var ogVerdict = d.og_is_modern
						? '<span class="cf-verify-ok">' + __( '✓ modern', 'cf-media-manager' ) + '</span>'
						: '<span class="cf-verify-bad">' + __( '⚠ legacy format', 'cf-media-manager' ) + '</span>';
					html += '<p><strong>' + __( 'og:image:', 'cf-media-manager' ) + '</strong> '
						+ ogVerdict
						+ ' <code class="cf-verify-url">' + escapeHtml( d.og_image ) + '</code></p>';
				}

				if ( d.samples_legacy && d.samples_legacy.length ) {
					html += '<p><strong>' + __( 'Sample non-modern URLs (outside <picture>):', 'cf-media-manager' ) + '</strong></p><ul class="cf-verify-samples">';
					$.each( d.samples_legacy, function ( i, u ) {
						html += '<li><code class="cf-verify-url">' + escapeHtml( u ) + '</code></li>';
					} );
					html += '</ul>';
				}

				if ( d.samples_favicon && d.samples_favicon.length ) {
					html += '<p><strong>' + __( 'Favicon PNGs (kept by design):', 'cf-media-manager' ) + '</strong></p><ul class="cf-verify-samples">';
					$.each( d.samples_favicon, function ( i, u ) {
						html += '<li><code class="cf-verify-url">' + escapeHtml( u ) + '</code></li>';
					} );
					html += '</ul>';
				}

				html += '</div>';
				$verifyResult.show().html( html );
			} )
			.fail( function ( xhr ) {
				$verifyBtn.prop( 'disabled', false ).text( __( 'Verify', 'cf-media-manager' ) );
				$verifyResult.show().html(
					'<div class="notice notice-error inline"><p>' +
					sprintf(
						/* translators: %d: HTTP status code. */
						__( 'Request failed (HTTP %d).', 'cf-media-manager' ),
						xhr.status
					) +
					'</p></div>'
				);
			} );
	} );

	// -----------------------------------------------------------------------
	// Cache-purge notice — persist dismissal server-side
	// -----------------------------------------------------------------------
	$( document ).on( 'click', '.cf-media-manager-purge-notice .notice-dismiss', function () {
		cfPost( 'dismiss_purge' );
	} );

	// -----------------------------------------------------------------------
	// Cache Management — detect layers + purge
	// -----------------------------------------------------------------------
	var $cacheLayers   = $( '#cf-media-manager-cache-layers' );
	var $purgeBtn      = $( '#cf-media-manager-purge-now' );
	var $purgeStatus   = $( '#cf-media-manager-purge-status' );
	var $purgeResults  = $( '#cf-media-manager-purge-results' );

	function loadCacheLayers() {
		cfPost( 'detect_caches' )
			.done( function ( res ) {
				if ( ! res || ! res.success ) {
					$cacheLayers.html( '<span class="cf-cache-empty">' + __( 'Could not detect caches.', 'cf-media-manager' ) + '</span>' );
					return;
				}
				var layers = res.data.layers || [];
				if ( layers.length === 0 ) {
					$cacheLayers.html( '<span class="cf-cache-empty">' + __( 'No supported cache layers detected. WordPress object cache will still be flushed.', 'cf-media-manager' ) + '</span>' );
					return;
				}
				var html = '<span class="cf-cache-label">' + __( 'Detected:', 'cf-media-manager' ) + '</span> ';
				html += layers.map( function ( l ) { return '<span class="cf-cache-badge">' + escapeHtml( l.label ) + '</span>'; } ).join( ' ' );
				$cacheLayers.html( html );
			} )
			.fail( function () {
				$cacheLayers.html( '<span class="cf-cache-empty">' + __( 'Detection failed.', 'cf-media-manager' ) + '</span>' );
			} );
	}

	$purgeBtn.on( 'click', function () {
		$purgeBtn.prop( 'disabled', true );
		$purgeStatus.text( ' ' + __( 'Purging…', 'cf-media-manager' ) );
		$purgeResults.hide().empty();

		cfPost( 'purge_caches' )
			.done( function ( res ) {
				$purgeBtn.prop( 'disabled', false );
				if ( ! res || ! res.success ) {
					$purgeStatus.text( ' ' + __( 'Purge failed.', 'cf-media-manager' ) );
					return;
				}
				var results = res.data.results || [];
				var okCount     = 0;
				var failedCount = 0;
				var rowsHtml    = '';
				results.forEach( function ( r ) {
					if ( r.status === 'ok' ) {
						okCount++;
						rowsHtml += '<li class="cf-purge-ok">✓ ' + escapeHtml( r.label ) + '</li>';
					} else {
						failedCount++;
						rowsHtml += '<li class="cf-purge-fail">✗ ' + escapeHtml( r.label ) +
							' <span class="cf-purge-err">' + escapeHtml( r.error || __( 'failed', 'cf-media-manager' ) ) + '</span></li>';
					}
				} );
				if ( results.length === 0 ) {
					$purgeStatus.text( ' ' + __( 'Object cache flushed.', 'cf-media-manager' ) );
				} else {
					var summary = failedCount > 0
						? sprintf(
							/* translators: 1: number purged successfully, 2: number that failed. */
							__( '✓ Done. %1$d purged, %2$d failed.', 'cf-media-manager' ),
							okCount,
							failedCount
						)
						: sprintf(
							/* translators: %d: number of cache layers purged successfully. */
							__( '✓ Done. %d purged.', 'cf-media-manager' ),
							okCount
						);
					$purgeStatus.text( ' ' + summary );
				}
				if ( rowsHtml ) {
					$purgeResults.html( '<ul class="cf-purge-list">' + rowsHtml + '</ul>' ).show();
				}
			} )
			.fail( function ( xhr ) {
				$purgeBtn.prop( 'disabled', false );
				$purgeStatus.text( ' ' + sprintf(
					/* translators: %d: HTTP status code. */
					__( 'Request failed (HTTP %d).', 'cf-media-manager' ),
					xhr.status
				) );
			} );
	} );

	loadCacheLayers();

	// -----------------------------------------------------------------------
	// Diagnose Attachment — debug helper for "unowned WebP" errors. Sends
	// an attachment ID to the diagnose_variant endpoint, renders the report,
	// and offers a one-click "Claim this WebP" button when the report says
	// the file exists on disk but the manifest row is missing.
	// -----------------------------------------------------------------------
	var $diagId     = $( '#cf-diag-id' );
	var $diagRun    = $( '#cf-diag-run' );
	var $diagResult = $( '#cf-diag-result' );

	$diagRun.on( 'click', function () {
		var id = parseInt( $diagId.val(), 10 );
		if ( ! id || id <= 0 ) {
			alert( __( 'Enter a valid attachment ID.', 'cf-media-manager' ) );
			return;
		}
		$diagRun.prop( 'disabled', true ).text( __( 'Checking…', 'cf-media-manager' ) );
		$diagResult.hide().empty();

		cfPost( 'diagnose_variant', { id: id } )
			.done( function ( res ) {
				$diagRun.prop( 'disabled', false ).text( __( 'Diagnose', 'cf-media-manager' ) );
				if ( ! res || ! res.success ) {
					var msg = ( res && res.data ) ? res.data : __( 'Diagnostic failed.', 'cf-media-manager' );
					$diagResult.show().html(
						'<div class="notice notice-error inline"><p>' + escapeHtml( msg ) + '</p></div>'
					);
					return;
				}
				renderDiagResult( res.data );
			} )
			.fail( function ( xhr ) {
				$diagRun.prop( 'disabled', false ).text( __( 'Diagnose', 'cf-media-manager' ) );
				$diagResult.show().html(
					'<div class="notice notice-error inline"><p>' + sprintf(
						/* translators: %d: HTTP status code. */
						__( 'Request failed (HTTP %d).', 'cf-media-manager' ),
						xhr.status
					) + '</p></div>'
				);
			} );
	} );

	function renderDiagResult( d ) {
		var html = '<div class="notice notice-info inline cf-diag-report">';
		html += '<p><strong>' + escapeHtml( __( 'Verdict:', 'cf-media-manager' ) ) + '</strong> ' + escapeHtml( d.verdict || '' ) + '</p>';
		html += '<table class="widefat cf-diag-table"><tbody>';
		html += diagRow( __( 'Attachment ID', 'cf-media-manager' ), String( d.id ) );
		html += diagRow( __( 'MIME type', 'cf-media-manager' ), d.mime );
		html += diagRow( __( 'Source path', 'cf-media-manager' ), d.source.abs_path );
		html += diagRow( __( 'Source exists on disk', 'cf-media-manager' ), d.source.exists ? '✓' : '✗' );
		html += diagRow( __( 'Source rel → ID (lookup map)', 'cf-media-manager' ), d.source.mapped_id || __( 'not found', 'cf-media-manager' ) );
		html += diagRow( __( 'Source rel → parent ID (size map)', 'cf-media-manager' ), d.source.in_size_map || __( 'not found', 'cf-media-manager' ) );
		html += diagRow( __( 'WebP path', 'cf-media-manager' ), d.webp.abs_path );
		html += diagRow( __( 'WebP exists on disk', 'cf-media-manager' ), d.webp.exists ? '✓' : '✗' );
		html += diagRow( __( 'WebP owned by this attachment', 'cf-media-manager' ), d.webp.owned_by_this_id ? '✓' : '✗' );
		html += diagRow( __( 'WebP is a separate Media Library attachment', 'cf-media-manager' ), d.webp.is_attachment ? ( 'ID ' + d.webp.is_attachment ) : '—' );
		if ( d.avif.exists ) {
			html += diagRow( __( 'AVIF path', 'cf-media-manager' ), d.avif.abs_path );
			html += diagRow( __( 'AVIF owned by this attachment', 'cf-media-manager' ), d.avif.owned_by_this_id ? '✓' : '✗' );
			html += diagRow( __( 'AVIF is a separate Media Library attachment', 'cf-media-manager' ), d.avif.is_attachment ? ( 'ID ' + d.avif.is_attachment ) : '—' );
		}
		html += diagRow( __( 'path_to_id map size', 'cf-media-manager' ), String( d.path_to_id_size ) );
		html += diagRow( __( 'size_to_parent map size', 'cf-media-manager' ), String( d.size_to_parent_size ) );
		html += '</tbody></table>';

		// Offer the one-click claim when the diagnosis says the WebP is on
		// disk but unowned (and isn't itself an attachment).
		var canClaim = d.webp.exists && ! d.webp.owned_by_this_id && ! d.webp.is_attachment;
		if ( canClaim ) {
			html += '<p><button type="button" class="button button-primary cf-diag-claim" data-id="' + d.id + '">' + escapeHtml( __( 'Claim this WebP for this attachment', 'cf-media-manager' ) ) + '</button> <span class="cf-diag-claim-status"></span></p>';
		}
		html += '</div>';
		$diagResult.show().html( html );
	}

	function diagRow( label, value ) {
		return '<tr><th>' + escapeHtml( label ) + '</th><td><code>' + escapeHtml( String( value ) ) + '</code></td></tr>';
	}

	$diagResult.on( 'click', '.cf-diag-claim', function () {
		var $btn    = $( this );
		var $status = $btn.siblings( '.cf-diag-claim-status' );
		var id      = parseInt( $btn.data( 'id' ), 10 );
		$btn.prop( 'disabled', true ).text( __( 'Claiming…', 'cf-media-manager' ) );
		cfPost( 'claim_variant', { id: id } )
			.done( function ( res ) {
				if ( ! res || ! res.success ) {
					$btn.prop( 'disabled', false ).text( __( 'Claim this WebP for this attachment', 'cf-media-manager' ) );
					$status.html( '<span style="color:#b32d2e">' + escapeHtml( ( res && res.data ) || __( 'Claim failed.', 'cf-media-manager' ) ) + '</span>' );
					return;
				}
				var claimed = res.data.claimed || [];
				$btn.text( __( 'Claimed', 'cf-media-manager' ) );
				$status.html( '<span style="color:#00a32a">' + escapeHtml( sprintf(
					/* translators: %s: comma-joined list of formats claimed (e.g. "webp, avif"). */
					__( 'Done — claimed: %s. Re-run Diagnose to verify, or try Convert again.', 'cf-media-manager' ),
					claimed.length ? claimed.join( ', ' ) : __( '(nothing — see Skipped list)', 'cf-media-manager' )
				) ) + '</span>' );
			} )
			.fail( function ( xhr ) {
				$btn.prop( 'disabled', false ).text( __( 'Claim this WebP for this attachment', 'cf-media-manager' ) );
				$status.html( '<span style="color:#b32d2e">' + sprintf(
					/* translators: %d: HTTP status code. */
					__( 'Claim request failed (HTTP %d).', 'cf-media-manager' ),
					xhr.status
				) + '</span>' );
			} );
	} );

	// -----------------------------------------------------------------------
	// Permanently-dismissable explainer
	// -----------------------------------------------------------------------
	// Delegated handler: the explainer block is only rendered by PHP when
	// the current user hasn't already dismissed it, so the button may not
	// exist on every page load.
	$( document ).on( 'click', '#cf-media-manager-explainer-dismiss', function ( e ) {
		e.preventDefault();
		var $card = $( '#cf-media-manager-explainer' );
		// Hide immediately for a snappy feel; rollback on server failure
		// so the user can try again rather than losing the affordance.
		$card.stop( true, true ).slideUp( 150 );
		cfPost( 'dismiss_explainer' ).fail( function () {
			$card.slideDown( 150 );
		} );
	} );

	// -----------------------------------------------------------------------
	// Init
	// -----------------------------------------------------------------------
	loadStatus();
	loadInUseScan( false );
	pollQueue();

	// -----------------------------------------------------------------------
	// Alt Text Manager (Accessibility tab)
	//
	// Lazy: first AJAX request fires the first time the Accessibility tab is
	// activated. Subsequent filter / pagination changes refresh in place.
	// -----------------------------------------------------------------------
	var $altFilter     = $( '#cf-alt-filter' );
	var $altRefresh    = $( '#cf-alt-refresh' );
	var $altSummary    = $( '#cf-alt-summary' );
	var $altLoading    = $( '#cf-alt-loading' );
	var $altError      = $( '#cf-alt-error' );
	var $altEmpty      = $( '#cf-alt-empty' );
	var $altTable      = $( '#cf-alt-table' );
	var $altTbody      = $( '#cf-alt-tbody' );
	var $altPagination = $( '#cf-alt-pagination' );
	var $altPrev       = $( '#cf-alt-prev' );
	var $altNext       = $( '#cf-alt-next' );
	var $altPageInfo   = $( '#cf-alt-page-info' );

	var altState = {
		loaded     : false,
		loading    : false,
		page       : 1,
		perPage    : 25,
		totalPages : 0,
		total      : 0,
		filter     : 'in_use_missing'
	};

	function ensureAltListLoaded() {
		if ( altState.loaded || altState.loading ) { return; }
		loadAltList();
	}

	function loadAltList() {
		if ( altState.loading ) { return; }
		altState.loading = true;
		$altError.hide().find( 'p' ).empty();
		$altEmpty.hide();
		$altLoading.show();
		$altTable.hide();
		$altPagination.hide();
		$altSummary.empty();

		cfPost( 'alt_list', {
			filter   : altState.filter,
			page     : altState.page,
			per_page : altState.perPage
		} )
			.done( function ( res ) {
				altState.loading = false;
				altState.loaded  = true;
				$altLoading.hide();
				if ( ! res || ! res.success ) {
					altShowError( __( 'Could not load alt text list.', 'cf-media-manager' ) );
					return;
				}
				altState.page       = res.data.page;
				altState.perPage    = res.data.per_page;
				altState.total      = res.data.total;
				altState.totalPages = res.data.total_pages;
				renderAltList( res.data.items || [] );
				renderAltPagination();
				renderAltSummary();
			} )
			.fail( function ( xhr ) {
				altState.loading = false;
				$altLoading.hide();
				altShowError( sprintf(
					/* translators: %d: HTTP status code. */
					__( 'Alt text list request failed (HTTP %d).', 'cf-media-manager' ),
					xhr.status
				) );
			} );
	}

	function altShowError( msg ) {
		$altError.find( 'p' ).text( msg );
		$altError.show();
	}

	function renderAltList( items ) {
		$altTbody.empty();
		if ( ! items.length ) {
			$altEmpty.show();
			$altTable.hide();
			return;
		}
		$altEmpty.hide();
		items.forEach( function ( item ) {
			$altTbody.append( renderAltRow( item ) );
		} );
		$altTable.show();
	}

	function renderAltRow( item ) {
		var missingBadge = item.missing
			? '<span class="cf-alt-badge cf-alt-badge--missing">' + escapeHtml( __( 'Missing', 'cf-media-manager' ) ) + '</span>'
			: '';
		var decorativeBadge = item.decorative
			? '<span class="cf-alt-badge cf-alt-badge--decorative">' + escapeHtml( __( 'Decorative', 'cf-media-manager' ) ) + '</span>'
			: '';
		var thumb = item.thumb
			? '<img src="' + escapeHtml( item.thumb ) + '" alt="" loading="lazy">'
			: '<span class="cf-alt-thumb-fallback">—</span>';
		var fileLink = item.edit_url
			? '<a href="' + escapeHtml( item.edit_url ) + '" target="_blank" rel="noopener">' + escapeHtml( item.filename ) + '</a>'
			: escapeHtml( item.filename );

		var $tr = $(
			'<tr class="cf-alt-row" data-id="' + item.id + '">' +
				'<td class="cf-alt-col-thumb"><div class="cf-alt-thumb">' + thumb + '</div></td>' +
				'<td class="cf-alt-col-meta">' +
					'<div class="cf-alt-filename">' + fileLink + '</div>' +
					'<div class="cf-alt-badges">' + missingBadge + decorativeBadge + '</div>' +
				'</td>' +
				'<td class="cf-alt-col-input">' +
					'<input type="text" class="cf-alt-input regular-text" value="" maxlength="500">' +
				'</td>' +
				'<td class="cf-alt-col-decorative">' +
					'<label class="cf-alt-decorative-label">' +
						'<input type="checkbox" class="cf-alt-decorative">' +
						'<span class="screen-reader-text">' + escapeHtml( __( 'Mark as decorative', 'cf-media-manager' ) ) + '</span>' +
					'</label>' +
				'</td>' +
				'<td class="cf-alt-col-actions">' +
					'<button type="button" class="button cf-alt-save">' + escapeHtml( __( 'Save', 'cf-media-manager' ) ) + '</button>' +
					'<span class="cf-alt-saved-indicator" style="display:none">&#10003;</span>' +
				'</td>' +
			'</tr>'
		);

		// Set the value via .val() rather than building it into the HTML so any
		// quotes / angle brackets in the alt text don't have to be HTML-escaped
		// twice (once for the input value attr, once for the surrounding HTML).
		$tr.find( '.cf-alt-input' ).val( item.alt );
		$tr.find( '.cf-alt-decorative' ).prop( 'checked', !! item.decorative );
		return $tr;
	}

	function renderAltSummary() {
		var filterLabel = $altFilter.find( 'option:selected' ).text();
		if ( altState.total === 0 ) {
			$altSummary.text( sprintf(
				/* translators: %s: human-readable filter label like "Missing alt text". */
				__( '0 images match "%s".', 'cf-media-manager' ),
				filterLabel
			) );
			return;
		}
		var start = ( ( altState.page - 1 ) * altState.perPage ) + 1;
		var end   = Math.min( altState.page * altState.perPage, altState.total );
		$altSummary.text( sprintf(
			/* translators: 1: start row, 2: end row, 3: total matches, 4: filter label. */
			__( 'Showing %1$d–%2$d of %3$d matching "%4$s".', 'cf-media-manager' ),
			start, end, altState.total, filterLabel
		) );
	}

	function renderAltPagination() {
		if ( altState.totalPages <= 1 ) {
			$altPagination.hide();
			return;
		}
		$altPageInfo.text( sprintf(
			/* translators: 1: current page, 2: total pages. */
			__( 'Page %1$d of %2$d', 'cf-media-manager' ),
			altState.page,
			altState.totalPages
		) );
		$altPrev.prop( 'disabled', altState.page <= 1 );
		$altNext.prop( 'disabled', altState.page >= altState.totalPages );
		$altPagination.show();
	}

	$altFilter.on( 'change', function () {
		altState.filter = $( this ).val();
		altState.page   = 1;
		loadAltList();
	} );

	$altRefresh.on( 'click', function ( e ) {
		e.preventDefault();
		loadAltList();
	} );

	$altPrev.on( 'click', function () {
		if ( altState.page <= 1 ) { return; }
		altState.page -= 1;
		loadAltList();
	} );

	$altNext.on( 'click', function () {
		if ( altState.page >= altState.totalPages ) { return; }
		altState.page += 1;
		loadAltList();
	} );

	// Save handler is delegated because rows are rendered dynamically.
	$altTbody.on( 'click', '.cf-alt-save', function () {
		var $btn       = $( this );
		var $row       = $btn.closest( '.cf-alt-row' );
		var id         = parseInt( $row.data( 'id' ), 10 );
		var alt        = $row.find( '.cf-alt-input' ).val();
		var decorative = $row.find( '.cf-alt-decorative' ).is( ':checked' ) ? 1 : 0;
		var $indicator = $row.find( '.cf-alt-saved-indicator' );

		$btn.prop( 'disabled', true ).text( __( 'Saving…', 'cf-media-manager' ) );
		$indicator.hide();

		cfPost( 'alt_save', {
			id         : id,
			alt        : alt,
			decorative : decorative
		} )
			.done( function ( res ) {
				$btn.prop( 'disabled', false ).text( __( 'Save', 'cf-media-manager' ) );
				if ( ! res || ! res.success ) {
					altShowError( __( 'Save failed.', 'cf-media-manager' ) );
					return;
				}
				// Refresh row badges from the server-computed state.
				var item = res.data;
				var $badges = $row.find( '.cf-alt-badges' );
				$badges.empty();
				if ( item.missing ) {
					$badges.append( '<span class="cf-alt-badge cf-alt-badge--missing">' + escapeHtml( __( 'Missing', 'cf-media-manager' ) ) + '</span>' );
				}
				if ( item.decorative ) {
					$badges.append( '<span class="cf-alt-badge cf-alt-badge--decorative">' + escapeHtml( __( 'Decorative', 'cf-media-manager' ) ) + '</span>' );
				}
				$indicator.show().delay( 1800 ).fadeOut( 200 );
			} )
			.fail( function ( xhr ) {
				$btn.prop( 'disabled', false ).text( __( 'Save', 'cf-media-manager' ) );
				altShowError( sprintf(
					/* translators: %d: HTTP status code. */
					__( 'Save request failed (HTTP %d).', 'cf-media-manager' ),
					xhr.status
				) );
			} );
	} );

	// If the user starts on the Accessibility tab (restored from localStorage),
	// the activateTab() call above will have already triggered the load.
} );
