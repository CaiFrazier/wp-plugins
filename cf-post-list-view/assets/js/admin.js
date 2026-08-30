/* CF Post List View — admin.js
 * Vanilla JS, no build step. References window.cfPlvData (wp_localize_script).
 */
( function () {
	'use strict';

	const cfg  = window.cfPlvData;
	const WIN  = 2; // pagination window radius

	// -------------------------------------------------------------------------
	// State
	// -------------------------------------------------------------------------

	const state = {
		page:       1,
		perPage:    50,
		search:     '',
		postType:   'post',
		postStatus: '',
		orderby:    'date_published',
		order:      'DESC',
		columns:    [],   // active column keys for current post type
		total:      0,
		pages:      0,
		// Post type registry fetched from /post-types endpoint.
		// { 'post': { label, singular, taxonomies: [{key,label,hierarchical}] }, … }
		postTypes:  {},
	};

	// -------------------------------------------------------------------------
	// LocalStorage — per-post-type column preferences
	// -------------------------------------------------------------------------

	function storageKey( postType ) {
		return 'cfPlv_columns_' + postType + '_v1';
	}

	function loadColumns( postType ) {
		try {
			const raw = localStorage.getItem( storageKey( postType ) );
			if ( raw ) {
				const parsed = JSON.parse( raw );
				if ( Array.isArray( parsed ) && parsed.length > 0 ) {
					return parsed;
				}
			}
		} catch ( e ) { /* ignore */ }
		return cfg.defaults.slice();
	}

	function saveColumns( postType, columns ) {
		try {
			localStorage.setItem( storageKey( postType ), JSON.stringify( columns ) );
		} catch ( e ) { /* ignore */ }
	}

	// -------------------------------------------------------------------------
	// Column config helpers
	// -------------------------------------------------------------------------

	/**
	 * Build the full column config for the given post type:
	 * static groups from cfPlvData.columns + dynamic taxonomy columns.
	 *
	 * Returns a groups object in the same shape as cfg.columns, but with the
	 * 'author' group augmented by taxonomy entries for the active post type.
	 */
	function buildColumnConfig( postType ) {
		// Deep-copy static groups.
		const groups = JSON.parse( JSON.stringify( cfg.columns ) );

		const typeInfo = state.postTypes[ postType ];
		if ( typeInfo && typeInfo.taxonomies && typeInfo.taxonomies.length > 0 ) {
			// Inject taxonomy columns into the 'author' group.
			if ( groups.author ) {
				for ( const tax of typeInfo.taxonomies ) {
					const key = 'tax_' + tax.key;
					groups.author.columns[ key ] = {
						label:    tax.label,
						desc:     ( tax.hierarchical
							? 'Hierarchical taxonomy: '
							: 'Taxonomy: ' ) + tax.label,
						default:  false,
						width:    140,
						sortable: false,
						dynamic:  true,
					};
				}
			}
		}

		return groups;
	}

	/**
	 * Return the flat key→entry map for the given column config.
	 */
	function flatConfig( groups ) {
		const flat = {};
		for ( const group of Object.values( groups ) ) {
			for ( const [ key, entry ] of Object.entries( group.columns ) ) {
				flat[ key ] = entry;
			}
		}
		return flat;
	}

	// -------------------------------------------------------------------------
	// Fetch: post types
	// -------------------------------------------------------------------------

	function fetchPostTypes() {
		return fetch( cfg.postTypesUrl, {
			headers: { 'X-WP-Nonce': cfg.nonce },
		} )
			.then( function ( r ) { return r.json(); } )
			.then( function ( data ) {
				if ( ! Array.isArray( data ) ) return;

				state.postTypes = {};
				for ( const t of data ) {
					state.postTypes[ t.key ] = {
						label:      t.label,
						singular:   t.singular,
						taxonomies: t.taxonomies || [],
					};
				}

				// Populate post type dropdown.
				const sel = document.getElementById( 'cf-plv-post-type' );
				if ( ! sel ) return;

				sel.innerHTML = '';
				for ( const [ key, info ] of Object.entries( state.postTypes ) ) {
					const opt = document.createElement( 'option' );
					opt.value       = key;
					opt.textContent = info.label;
					if ( key === state.postType ) opt.selected = true;
					sel.appendChild( opt );
				}

				// If saved postType isn't in the list, default to first.
				if ( ! state.postTypes[ state.postType ] ) {
					state.postType = Object.keys( state.postTypes )[ 0 ] || 'post';
					const first = sel.querySelector( 'option' );
					if ( first ) first.selected = true;
				}

				// Load columns for initial post type.
				state.columns = loadColumns( state.postType );
			} )
			.catch( function () {
				// On error keep defaults; post type dropdown shows fallback.
			} );
	}

	// -------------------------------------------------------------------------
	// Fetch: posts
	// -------------------------------------------------------------------------

	let currentController = null;

	function fetchPosts() {
		if ( currentController ) {
			currentController.abort();
		}
		currentController = new AbortController();

		const params = new URLSearchParams( {
			page:        state.page,
			per_page:    state.perPage,
			post_type:   state.postType,
			post_status: state.postStatus,
			orderby:     state.orderby,
			order:       state.order,
		} );

		if ( state.search ) {
			params.set( 'search', state.search );
		}

		for ( const col of state.columns ) {
			params.append( 'columns[]', col );
		}

		renderLoading();

		// rest_url() may already carry a query string (plain permalinks use
		// index.php?rest_route=...), so join with the right separator.
		fetch( cfg.restUrl + ( cfg.restUrl.indexOf( '?' ) === -1 ? '?' : '&' ) + params.toString(), {
			signal:  currentController.signal,
			headers: { 'X-WP-Nonce': cfg.nonce },
		} )
			.then( function ( r ) {
				if ( ! r.ok ) throw new Error( 'HTTP ' + r.status );
				return r.json();
			} )
			.then( function ( data ) {
				state.total = data.total || 0;
				state.pages = data.pages || 1;
				renderSummary();
				renderTable( data.items || [] );
				renderPagination();
			} )
			.catch( function ( err ) {
				if ( err.name === 'AbortError' ) return;
				renderError( 'Failed to load data. Check that the REST API is accessible.' );
			} );
	}

	// -------------------------------------------------------------------------
	// Render: loading / error states
	// -------------------------------------------------------------------------

	function renderLoading() {
		const tbody = document.getElementById( 'cf-plv-tbody' );
		if ( tbody ) {
			tbody.innerHTML = '<tr><td colspan="99" class="cf-plv-loading">Loading…</td></tr>';
		}
		const sumEl = document.getElementById( 'cf-plv-summary' );
		if ( sumEl ) sumEl.textContent = '';
	}

	function renderError( msg ) {
		const tbody = document.getElementById( 'cf-plv-tbody' );
		if ( tbody ) {
			tbody.innerHTML = '<tr><td colspan="99" class="cf-plv-error">' +
				escHtml( msg ) + '</td></tr>';
		}
	}

	// -------------------------------------------------------------------------
	// Render: summary
	// -------------------------------------------------------------------------

	function renderSummary() {
		const el = document.getElementById( 'cf-plv-summary' );
		if ( ! el ) return;

		const typeInfo = state.postTypes[ state.postType ];
		const typeLabel = typeInfo ? typeInfo.label : state.postType;

		if ( state.total === 0 ) {
			el.textContent = 'No ' + typeLabel.toLowerCase() + ' found.';
			return;
		}

		const from  = ( state.page - 1 ) * state.perPage + 1;
		const to    = Math.min( state.page * state.perPage, state.total );
		el.textContent = from + '–' + to + ' of ' + state.total +
			' ' + typeLabel.toLowerCase() +
			( state.pages > 1 ? ' — page ' + state.page + ' of ' + state.pages : '' );
	}

	// -------------------------------------------------------------------------
	// Render: table
	// -------------------------------------------------------------------------

	const BOOL_COLS = new Set( [
		'has_featured_image', 'is_sticky', 'is_password_protected',
	] );

	const CODE_COLS = new Set( [
		'slug', 'guid', 'relative_path', 'page_template', 'post_type',
		'canonical_url', 'custom_field_keys',
	] );

	const SORTABLE_KEYS = new Set( [
		'id', 'title', 'slug', 'date_published', 'date_modified',
		'menu_order', 'comment_count', 'post_parent_id',
	] );

	function renderTable( items ) {
		const colConfig = buildColumnConfig( state.postType );
		const flat      = flatConfig( colConfig );
		const cols      = state.columns;

		// Header.
		const thead = document.getElementById( 'cf-plv-thead' );
		if ( thead ) {
			let th = '';
			for ( const key of cols ) {
				const col = flat[ key ] || { label: key, sortable: false };
				const isSortable = SORTABLE_KEYS.has( key );
				const isSorted   = state.orderby === key;
				const w          = col.width ? ' style="width:' + col.width + 'px"' : '';
				let cls = 'cf-plv-th';
				if ( isSortable ) cls += ' is-sortable';
				if ( isSorted )   cls += ' is-sorted';

				let arrow = '';
				if ( isSortable ) {
					if ( isSorted ) {
						arrow = state.order === 'ASC' ? ' &#8593;' : ' &#8595;';
					} else {
						arrow = ' <span class="cf-plv-sort-idle">&#8597;</span>';
					}
				}

				th += '<th class="' + cls + '" data-col="' + escHtml( key ) + '"' + w + '>' +
					escHtml( col.label ) + arrow + '</th>';
			}
			thead.querySelector( 'tr' ).innerHTML = th;
		}

		// Body.
		const tbody = document.getElementById( 'cf-plv-tbody' );
		if ( ! tbody ) return;

		if ( items.length === 0 ) {
			tbody.innerHTML = '<tr><td colspan="' + cols.length + '" class="cf-plv-empty">' +
				'No items found.' + '</td></tr>';
			return;
		}

		let html = '';
		for ( const row of items ) {
			html += '<tr>';
			for ( const key of cols ) {
				const val = row[ key ] !== undefined ? row[ key ] : '';
				let cell  = '';

				if ( key === 'id' ) {
					cell = '<strong>' + escHtml( val ) + '</strong>';
				} else if ( key === 'title' ) {
					cell = escHtml( val );
				} else if ( key === 'full_url' && val ) {
					cell = '<a href="' + escHtml( val ) + '" target="_blank" rel="noopener">' +
						escHtml( val ) + '</a>';
				} else if ( key === 'post_status' ) {
					cell = '<span class="cf-plv-status cf-plv-status--' +
						escHtml( val ) + '">' + escHtml( val ) + '</span>';
				} else if ( BOOL_COLS.has( key ) ) {
					const isYes = val === '✓';
					cell = '<span class="cf-plv-bool ' + ( isYes ? 'yes' : 'no' ) + '">' +
						escHtml( val ) + '</span>';
				} else if ( CODE_COLS.has( key ) ) {
					cell = val ? '<code class="cf-plv-code">' + escHtml( val ) + '</code>' : '';
				} else {
					cell = escHtml( val );
				}

				html += '<td class="cf-plv-td" data-col="' + escHtml( key ) + '">' + cell + '</td>';
			}
			html += '</tr>';
		}

		tbody.innerHTML = html;
	}

	// -------------------------------------------------------------------------
	// Render: pagination
	// -------------------------------------------------------------------------

	function renderPagination() {
		const el = document.getElementById( 'cf-plv-pagination' );
		if ( ! el ) return;

		if ( state.pages <= 1 ) {
			el.innerHTML = '';
			return;
		}

		const p = state.page;
		const n = state.pages;

		let html = '<span class="displaying-num">' + state.total + ' items</span>';
		html += '<span class="pagination-links">';

		html += pageBtn( 1, '«', p <= 1, 'First page' );
		html += pageBtn( p - 1, '‹', p <= 1, 'Previous page' );

		const lo = Math.max( 1, p - WIN );
		const hi = Math.min( n, p + WIN );

		if ( lo > 1 ) html += '<span class="cf-plv-page-gap">…</span>';

		for ( let i = lo; i <= hi; i++ ) {
			if ( i === p ) {
				html += '<span class="cf-plv-page-current" aria-current="page">' + i + '</span>';
			} else {
				html += '<button class="button cf-plv-page-btn" data-page="' + i + '">' + i + '</button>';
			}
		}

		if ( hi < n ) html += '<span class="cf-plv-page-gap">…</span>';

		html += pageBtn( p + 1, '›', p >= n, 'Next page' );
		html += pageBtn( n, '»', p >= n, 'Last page' );

		html += '</span>';
		el.innerHTML = html;
	}

	function pageBtn( page, label, disabled, title ) {
		if ( disabled ) {
			return '<span class="button disabled" aria-disabled="true">' + label + '</span>';
		}
		return '<button class="button cf-plv-page-btn" data-page="' + page +
			'" title="' + escHtml( title ) + '">' + label + '</button>';
	}

	// -------------------------------------------------------------------------
	// Column modal
	// -------------------------------------------------------------------------

	function buildModal() {
		const body = document.getElementById( 'cf-plv-modal-body' );
		if ( ! body ) return;

		const colConfig = buildColumnConfig( state.postType );
		const active    = new Set( state.columns );
		let   html      = '';

		for ( const [ groupKey, group ] of Object.entries( colConfig ) ) {
			const groupCols = Object.entries( group.columns );
			if ( groupCols.length === 0 ) continue;

			html += '<div class="cf-plv-group">';
			html += '<button type="button" class="cf-plv-group-toggle" data-group="' +
				escHtml( groupKey ) + '">' + escHtml( group.label ) + '</button>';
			html += '<div class="cf-plv-group-rows" id="cf-plv-group-' + escHtml( groupKey ) + '">';

			for ( const [ key, col ] of groupCols ) {
				const checked  = active.has( key ) ? ' checked' : '';
				const isDefault = col.default ? ' <span class="cf-plv-default-badge">default</span>' : '';
				const isDynamic = col.dynamic ? ' <span class="cf-plv-dynamic-badge">taxonomy</span>' : '';
				html += '<label class="cf-plv-col-row">' +
					'<input type="checkbox" name="col" value="' + escHtml( key ) + '"' + checked + '>' +
					'<span class="cf-plv-col-name">' + escHtml( col.label ) + isDefault + isDynamic + '</span>' +
					'<span class="cf-plv-col-desc">' + escHtml( col.desc || '' ) + '</span>' +
					'</label>';
			}

			html += '</div></div>';
		}

		body.innerHTML = html;

		// Group collapse/expand.
		body.querySelectorAll( '.cf-plv-group-toggle' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				const groupKey = btn.dataset.group;
				const rows     = document.getElementById( 'cf-plv-group-' + groupKey );
				if ( ! rows ) return;
				const isHidden = rows.hidden;
				rows.hidden    = ! isHidden;
				btn.classList.toggle( 'is-collapsed', ! isHidden );
			} );
		} );
	}

	function openModal() {
		buildModal();
		const modal = document.getElementById( 'cf-plv-modal' );
		if ( modal ) {
			modal.hidden = false;
			const first = modal.querySelector( 'input, button' );
			if ( first ) first.focus();
		}
	}

	function closeModal() {
		const modal = document.getElementById( 'cf-plv-modal' );
		if ( modal ) modal.hidden = true;
		const btn = document.getElementById( 'cf-plv-columns-btn' );
		if ( btn ) btn.focus();
	}

	function applyModal() {
		const checks = document.querySelectorAll( '#cf-plv-modal-body input[name="col"]:checked' );
		const cols   = Array.from( checks ).map( function ( c ) { return c.value; } );
		state.columns = cols.length > 0 ? cols : cfg.defaults.slice();
		saveColumns( state.postType, state.columns );
		closeModal();
		state.page = 1;
		fetchPosts();
	}

	function resetModal() {
		state.columns = cfg.defaults.slice();
		buildModal();
	}

	// -------------------------------------------------------------------------
	// Post type change
	// -------------------------------------------------------------------------

	function switchPostType( postType ) {
		state.postType = postType;
		state.columns  = loadColumns( postType );
		state.page     = 1;
		state.orderby  = 'date_published';
		state.order    = 'DESC';
		fetchPosts();
	}

	// -------------------------------------------------------------------------
	// Utility
	// -------------------------------------------------------------------------

	function escHtml( s ) {
		return String( s )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#039;' );
	}

	function debounce( fn, ms ) {
		let timer;
		return function () {
			clearTimeout( timer );
			timer = setTimeout( fn, ms );
		};
	}

	// -------------------------------------------------------------------------
	// Event wiring
	// -------------------------------------------------------------------------

	document.addEventListener( 'DOMContentLoaded', function () {

		// Sortable column headers.
		document.getElementById( 'cf-plv-table' ).addEventListener( 'click', function ( e ) {
			const th = e.target.closest( '.cf-plv-th.is-sortable' );
			if ( ! th ) return;
			const col = th.dataset.col;
			if ( state.orderby === col ) {
				state.order = state.order === 'ASC' ? 'DESC' : 'ASC';
			} else {
				state.orderby = col;
				state.order   = 'DESC';
			}
			state.page = 1;
			fetchPosts();
		} );

		// Search.
		const searchEl = document.getElementById( 'cf-plv-search' );
		if ( searchEl ) {
			searchEl.addEventListener( 'input', debounce( function () {
				state.search = searchEl.value.trim();
				state.page   = 1;
				fetchPosts();
			}, 350 ) );
		}

		// Post type selector.
		const ptSel = document.getElementById( 'cf-plv-post-type' );
		if ( ptSel ) {
			ptSel.addEventListener( 'change', function () {
				switchPostType( ptSel.value );
			} );
		}

		// Status filter.
		const statusSel = document.getElementById( 'cf-plv-status' );
		if ( statusSel ) {
			statusSel.addEventListener( 'change', function () {
				state.postStatus = statusSel.value;
				state.page       = 1;
				fetchPosts();
			} );
		}

		// Per-page.
		const perPageSel = document.getElementById( 'cf-plv-per-page' );
		if ( perPageSel ) {
			perPageSel.addEventListener( 'change', function () {
				state.perPage = parseInt( perPageSel.value, 10 );
				state.page    = 1;
				fetchPosts();
			} );
		}

		// Columns button.
		const colBtn = document.getElementById( 'cf-plv-columns-btn' );
		if ( colBtn ) {
			colBtn.addEventListener( 'click', openModal );
		}

		// Modal close / cancel.
		document.getElementById( 'cf-plv-modal' ).addEventListener( 'click', function ( e ) {
			if ( e.target.classList.contains( 'cf-plv-modal-backdrop' ) ||
				 e.target.classList.contains( 'cf-plv-modal-close' ) ||
				 e.target.id === 'cf-plv-modal-cancel' ) {
				closeModal();
			}
			if ( e.target.id === 'cf-plv-modal-apply' ) {
				applyModal();
			}
			if ( e.target.id === 'cf-plv-modal-reset' ) {
				resetModal();
			}
		} );

		// Escape closes modal.
		document.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Escape' ) {
				const modal = document.getElementById( 'cf-plv-modal' );
				if ( modal && ! modal.hidden ) closeModal();
			}
		} );

		// Pagination clicks.
		document.getElementById( 'cf-plv-pagination' ).addEventListener( 'click', function ( e ) {
			const btn = e.target.closest( '.cf-plv-page-btn' );
			if ( ! btn || btn.disabled ) return;
			state.page = parseInt( btn.dataset.page, 10 );
			fetchPosts();
		} );

		// CSV export.
		const exportBtn = document.getElementById( 'cf-plv-export-btn' );
		if ( exportBtn ) {
			exportBtn.addEventListener( 'click', function () {
				const params = new URLSearchParams( {
					cols:        state.columns.join( ',' ),
					post_type:   state.postType,
					post_status: state.postStatus,
					_wpnonce:    cfg.exportNonce,
				} );
				if ( state.search ) params.set( 's', state.search );
				window.location.href = cfg.exportUrl + '&' + params.toString();
			} );
		}

		// Boot: fetch post types, then fetch posts.
		fetchPostTypes().then( function () {
			fetchPosts();
		} );
	} );
} )();
