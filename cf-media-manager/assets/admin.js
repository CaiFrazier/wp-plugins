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
	// Utilities (the alt-text editor uses escapeHtml + cfPost)
	// -----------------------------------------------------------------------
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

	// -----------------------------------------------------------------------
	// Init — the Accessibility tab is the default panel; lazily load its data
	// if it's active on first paint. (activateTab() covers a restored tab.)
	// -----------------------------------------------------------------------
	if ( $( '#cf-media-manager-panel-accessibility' ).hasClass( 'is-active' ) ) {
		ensureAltListLoaded();
	}

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
	var $altSaveAll    = $( '#cf-alt-save-all' );
	var $altLightbox   = $( '#cf-alt-lightbox' );

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
			refreshDirtyState();
			return;
		}
		$altEmpty.hide();
		items.forEach( function ( item ) {
			$altTbody.append( renderAltRow( item ) );
		} );
		$altTable.show();
		refreshDirtyState();
	}

	function renderAltRow( item ) {
		var missingBadge = item.missing
			? '<span class="cf-alt-badge cf-alt-badge--missing">' + escapeHtml( __( 'Missing', 'cf-media-manager' ) ) + '</span>'
			: '';
		var decorativeBadge = item.decorative
			? '<span class="cf-alt-badge cf-alt-badge--decorative">' + escapeHtml( __( 'Decorative', 'cf-media-manager' ) ) + '</span>'
			: '';
		// The full-size source drives the click-to-enlarge popup. Fall back to
		// the thumb when no distinct full source exists so the popup still
		// shows *something* rather than breaking.
		var fullSrc = item.full || item.thumb || '';
		var thumb;
		if ( item.thumb ) {
			thumb = '<button type="button" class="cf-alt-thumb-btn" ' +
				'data-full="' + escapeHtml( fullSrc ) + '" ' +
				'aria-label="' + escapeHtml( __( 'View full image', 'cf-media-manager' ) ) + '">' +
				'<img src="' + escapeHtml( item.thumb ) + '" alt="" loading="lazy">' +
				'</button>';
		} else {
			thumb = '<span class="cf-alt-thumb-fallback">—</span>';
		}
		var fileLink = item.edit_url
			? '<a href="' + escapeHtml( item.edit_url ) + '" target="_blank" rel="noopener">' + escapeHtml( item.filename ) + '</a>'
			: escapeHtml( item.filename );

		var $tr = $(
			'<tr class="cf-alt-row" data-id="' + item.id + '" data-filename="' + escapeHtml( item.filename ) + '">' +
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
		seedRowBaseline( $tr, item );
		return $tr;
	}

	// Record the server's current alt/decorative state on the row so we can
	// detect whether the user has edited it ("dirty"). "Save all changes"
	// only submits dirty rows.
	function seedRowBaseline( $row, item ) {
		$row.data( 'origAlt', item.alt || '' );
		$row.data( 'origDecorative', !! item.decorative );
		$row.removeClass( 'cf-alt-row--dirty' );
	}

	function isRowDirty( $row ) {
		var alt        = $row.find( '.cf-alt-input' ).val();
		var decorative = $row.find( '.cf-alt-decorative' ).is( ':checked' );
		return alt !== $row.data( 'origAlt' ) || decorative !== $row.data( 'origDecorative' );
	}

	// Recompute per-row dirty flags and toggle the "Save all changes" button.
	function refreshDirtyState() {
		var dirty = 0;
		$altTbody.find( '.cf-alt-row' ).each( function () {
			var $row = $( this );
			if ( isRowDirty( $row ) ) {
				$row.addClass( 'cf-alt-row--dirty' );
				dirty++;
			} else {
				$row.removeClass( 'cf-alt-row--dirty' );
			}
		} );

		$altSaveAll.prop( 'disabled', dirty === 0 );
		if ( dirty === 0 ) {
			$altSaveAll.text( __( 'Save all changes', 'cf-media-manager' ) );
		} else {
			$altSaveAll.text( sprintf(
				/* translators: %d: number of rows with unsaved edits. */
				__( 'Save all changes (%d)', 'cf-media-manager' ),
				dirty
			) );
		}
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
				// Refresh row badges + baseline from the server-computed state.
				var item = res.data;
				updateRowBadges( $row, item );
				seedRowBaseline( $row, item );
				refreshDirtyState();
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

	// Replace a row's badge cluster from a server-computed item payload.
	// Shared by the single-row and bulk save paths.
	function updateRowBadges( $row, item ) {
		var $badges = $row.find( '.cf-alt-badges' );
		$badges.empty();
		if ( item.missing ) {
			$badges.append( '<span class="cf-alt-badge cf-alt-badge--missing">' + escapeHtml( __( 'Missing', 'cf-media-manager' ) ) + '</span>' );
		}
		if ( item.decorative ) {
			$badges.append( '<span class="cf-alt-badge cf-alt-badge--decorative">' + escapeHtml( __( 'Decorative', 'cf-media-manager' ) ) + '</span>' );
		}
	}

	// Recompute dirty state whenever the user edits an alt input or toggles a
	// decorative checkbox. Delegated because rows are rendered dynamically.
	$altTbody.on( 'input', '.cf-alt-input', refreshDirtyState );
	$altTbody.on( 'change', '.cf-alt-decorative', refreshDirtyState );

	// "Save all changes": collect every dirty row on the current page and
	// write them in one bulk request.
	$altSaveAll.on( 'click', function () {
		var $dirtyRows = $altTbody.find( '.cf-alt-row' ).filter( function () {
			return isRowDirty( $( this ) );
		} );
		if ( ! $dirtyRows.length ) { return; }

		var ids        = [];
		var alt        = {};
		var decorative = [];
		$dirtyRows.each( function () {
			var $row = $( this );
			var id   = parseInt( $row.data( 'id' ), 10 );
			ids.push( id );
			alt[ id ] = $row.find( '.cf-alt-input' ).val();
			if ( $row.find( '.cf-alt-decorative' ).is( ':checked' ) ) {
				decorative.push( id );
			}
		} );

		$altSaveAll.prop( 'disabled', true ).text( __( 'Saving…', 'cf-media-manager' ) );
		$altError.hide().find( 'p' ).empty();

		cfPost( 'alt_save_bulk', {
			ids        : ids,
			alt        : alt,
			decorative : decorative
		} )
			.done( function ( res ) {
				if ( ! res || ! res.success ) {
					altShowError( __( 'Bulk save failed.', 'cf-media-manager' ) );
					refreshDirtyState();
					return;
				}
				( res.data.items || [] ).forEach( function ( item ) {
					var $row = $altTbody.find( '.cf-alt-row[data-id="' + item.id + '"]' );
					if ( ! $row.length ) { return; }
					$row.find( '.cf-alt-input' ).val( item.alt );
					$row.find( '.cf-alt-decorative' ).prop( 'checked', !! item.decorative );
					updateRowBadges( $row, item );
					seedRowBaseline( $row, item );
					$row.find( '.cf-alt-saved-indicator' ).show().delay( 1800 ).fadeOut( 200 );
				} );
				refreshDirtyState();
			} )
			.fail( function ( xhr ) {
				refreshDirtyState();
				altShowError( sprintf(
					/* translators: %d: HTTP status code. */
					__( 'Bulk save request failed (HTTP %d).', 'cf-media-manager' ),
					xhr.status
				) );
			} );
	} );

	// -----------------------------------------------------------------------
	// Full-image popup. The table thumb is a cropped 60×60 square — useless
	// for actually reading an image while writing its alt text. Clicking a
	// thumb opens the full-size source in an overlay.
	// -----------------------------------------------------------------------
	function openAltLightbox( src, caption ) {
		if ( ! src ) { return; }
		$altLightbox.find( '.cf-alt-lightbox-img' ).attr( 'src', src );
		$altLightbox.find( '.cf-alt-lightbox-caption' ).text( caption || '' );
		$altLightbox.prop( 'hidden', false );
	}

	function closeAltLightbox() {
		$altLightbox.prop( 'hidden', true );
		// Drop the src so a large image isn't held in memory while closed.
		$altLightbox.find( '.cf-alt-lightbox-img' ).attr( 'src', '' );
	}

	$altTbody.on( 'click', '.cf-alt-thumb-btn', function () {
		var $btn = $( this );
		openAltLightbox( $btn.data( 'full' ), $btn.closest( '.cf-alt-row' ).data( 'filename' ) );
	} );

	$altLightbox.on( 'click', '.cf-alt-lightbox-backdrop, .cf-alt-lightbox-close', closeAltLightbox );

	$( document ).on( 'keydown', function ( e ) {
		if ( 'Escape' === e.key && ! $altLightbox.prop( 'hidden' ) ) {
			closeAltLightbox();
		}
	} );

	// If the user starts on the Accessibility tab (restored from localStorage),
	// the activateTab() call above will have already triggered the load.
} );
