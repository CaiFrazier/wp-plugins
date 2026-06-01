/**
 * CF Media Manager — Media Library list view (library.js)
 *
 * Pure vanilla JS. No build step required.
 * Column preferences are persisted to localStorage.
 */
/* global cfMediaLibrary */
( function () {
	'use strict';

	const cfg         = window.cfMediaLibrary || {};
	const STORAGE_KEY = 'cfMM_library_columns_v1';

	// -------------------------------------------------------------------------
	// Column keys that map to WP_Query orderby values.
	// Keys absent from this map are not sortable server-side.
	// -------------------------------------------------------------------------
	const ORDERBY_MAP = {
		id:                'id',
		title:             'title',
		slug:              'slug',
		date_uploaded:     'date_uploaded',
		date_uploaded_gmt: 'date_uploaded_gmt',
		date_modified:     'date_modified',
		date_modified_gmt: 'date_modified_gmt',
		menu_order:        'menu_order',
	};

	// -------------------------------------------------------------------------
	// State
	// -------------------------------------------------------------------------
	const state = {
		page:    1,
		perPage: 50,
		search:  '',
		mime:    '',
		parent:  '',
		orderby: 'id',
		order:   'DESC',
		columns: loadColumns(),
		total:   0,
		pages:   0,
	};

	// -------------------------------------------------------------------------
	// Column persistence (localStorage)
	// -------------------------------------------------------------------------
	function loadColumns() {
		try {
			const raw = localStorage.getItem( STORAGE_KEY );
			if ( raw ) {
				const parsed = JSON.parse( raw );
				if ( Array.isArray( parsed ) && parsed.length > 0 ) {
					return parsed;
				}
			}
		} catch ( _e ) { /* ignore */ }
		return cfg.defaults || [];
	}

	function saveColumns( cols ) {
		try {
			localStorage.setItem( STORAGE_KEY, JSON.stringify( cols ) );
		} catch ( _e ) { /* ignore */ }
	}

	// -------------------------------------------------------------------------
	// DOM refs
	// -------------------------------------------------------------------------
	const elSearch    = document.getElementById( 'cf-mlv-search' );
	const elMime      = document.getElementById( 'cf-mlv-mime' );
	const elParent    = document.getElementById( 'cf-mlv-parent' );
	const elPerPage   = document.getElementById( 'cf-mlv-per-page' );
	const elColBtn    = document.getElementById( 'cf-mlv-columns-btn' );
	const elExport    = document.getElementById( 'cf-mlv-export-btn' );
	const elSummary   = document.getElementById( 'cf-mlv-summary' );
	const elThead     = document.getElementById( 'cf-mlv-thead' );
	const elTbody     = document.getElementById( 'cf-mlv-tbody' );
	const elPager     = document.getElementById( 'cf-mlv-pagination' );
	const elModal     = document.getElementById( 'cf-mlv-modal' );
	const elModalBody = document.getElementById( 'cf-mlv-modal-body' );

	// -------------------------------------------------------------------------
	// Flat column map (group → column → definition)
	// -------------------------------------------------------------------------
	function flatColumns() {
		const flat = {};
		const cats  = cfg.columns || {};
		for ( const group of Object.values( cats ) ) {
			Object.assign( flat, group.columns || {} );
		}
		return flat;
	}

	// -------------------------------------------------------------------------
	// Fetch
	// -------------------------------------------------------------------------
	let activeController = null;

	async function fetchAttachments() {
		if ( activeController ) {
			activeController.abort();
		}
		activeController = new AbortController();

		elTbody.innerHTML = `<tr><td colspan="99" class="cf-mlv-loading">Loading&#8230;</td></tr>`;

		const params = new URLSearchParams( {
			page:     state.page,
			per_page: state.perPage,
			search:   state.search,
			mime:     state.mime,
			parent:   state.parent,
			orderby:  state.orderby,
			order:    state.order,
		} );
		state.columns.forEach( c => params.append( 'columns[]', c ) );

		try {
			const resp = await fetch( cfg.restUrl + '?' + params.toString(), {
				headers: { 'X-WP-Nonce': cfg.nonce },
				signal:  activeController.signal,
			} );

			if ( ! resp.ok ) {
				throw new Error( 'HTTP ' + resp.status );
			}

			const json = await resp.json();
			state.total = json.total;
			state.pages = json.pages;

			renderSummary();
			renderTable( json.items );
			renderPagination();
		} catch ( err ) {
			if ( err.name === 'AbortError' ) return;
			elTbody.innerHTML = `<tr><td colspan="99" class="cf-mlv-error">Failed to load data. Check your connection and try again.</td></tr>`;
		}
	}

	// -------------------------------------------------------------------------
	// Render helpers
	// -------------------------------------------------------------------------
	function esc( str ) {
		return String( str ?? '' )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' );
	}

	function renderSummary() {
		if ( state.total === 0 ) {
			elSummary.textContent = 'No attachments found.';
			return;
		}
		const start = ( state.page - 1 ) * state.perPage + 1;
		const end   = Math.min( state.page * state.perPage, state.total );
		elSummary.textContent = `Showing ${ start }–${ end } of ${ state.total } attachment${ state.total === 1 ? '' : 's' }`;
	}

	function renderTable( items ) {
		const flat = flatColumns();

		// Header row
		const headerHtml = state.columns.map( key => {
			const col      = flat[ key ] || {};
			const label    = col.label || key;
			const isSorted = state.orderby === key;
			const sortMark = isSorted ? ( state.order === 'ASC' ? ' &#9650;' : ' &#9660;' ) : '';
			const sortable = !! ORDERBY_MAP[ key ];
			const width    = col.width ? ` style="width:${ esc( col.width ) }"` : '';
			const classes  = [ 'cf-mlv-th', isSorted && 'is-sorted', sortable && 'is-sortable' ]
				.filter( Boolean ).join( ' ' );
			return `<th class="${ classes }" data-col="${ esc( key ) }"${ width }>${ esc( label ) }${ sortMark }</th>`;
		} ).join( '' );

		elThead.querySelector( 'tr' ).innerHTML = headerHtml;

		// Body rows
		if ( ! items || items.length === 0 ) {
			elTbody.innerHTML = `<tr><td colspan="${ state.columns.length }" class="cf-mlv-empty">No attachments found.</td></tr>`;
			return;
		}

		elTbody.innerHTML = items.map( row => {
			const cells = state.columns.map( key => {
				const val = row[ key ] ?? '';
				let display;

				switch ( key ) {
					case 'full_url':
						display = val
							? `<a href="${ esc( val ) }" target="_blank" rel="noopener noreferrer" class="cf-mlv-link">${ esc( val ) }</a>`
							: '';
						break;
					case 'relative_path':
					case 'slug':
					case 'attached_file':
					case 'guid':
					case 'file_name':
					case 'sizes_list':
						display = val ? `<code class="cf-mlv-code">${ esc( val ) }</code>` : '';
						break;
					case 'has_alt':
					case 'is_unattached':
						display = val === '✓' || val === 'true'
							? `<span class="cf-mlv-bool yes">${ esc( val ) }</span>`
							: `<span class="cf-mlv-bool no">${ esc( val ) }</span>`;
						break;
					case 'id':
						display = `<strong>${ esc( val ) }</strong>`;
						break;
					default:
						display = esc( val );
				}

				return `<td class="cf-mlv-td" data-col="${ esc( key ) }">${ display }</td>`;
			} ).join( '' );

			return `<tr>${ cells }</tr>`;
		} ).join( '' );
	}

	function renderPagination() {
		if ( state.pages <= 1 ) {
			elPager.innerHTML = '';
			return;
		}

		const btns = [];
		const WIN  = 2;

		if ( state.page > 1 ) {
			btns.push( `<button class="button cf-mlv-page-btn" data-page="${ state.page - 1 }">&#8249; Prev</button>` );
		}

		let lastPrinted = 0;
		for ( let p = 1; p <= state.pages; p++ ) {
			const inWindow = p === 1 || p === state.pages ||
				( p >= state.page - WIN && p <= state.page + WIN );

			if ( inWindow ) {
				if ( lastPrinted && p - lastPrinted > 1 ) {
					btns.push( `<span class="cf-mlv-ellipsis">&#8230;</span>` );
				}
				const active = p === state.page ? ' button-primary' : '';
				btns.push( `<button class="button${ active } cf-mlv-page-btn" data-page="${ p }">${ p }</button>` );
				lastPrinted = p;
			}
		}

		if ( state.page < state.pages ) {
			btns.push( `<button class="button cf-mlv-page-btn" data-page="${ state.page + 1 }">Next &#8250;</button>` );
		}

		elPager.innerHTML = `<div class="tablenav-pages cf-mlv-pages">${ btns.join( '' ) }</div>`;
	}

	// -------------------------------------------------------------------------
	// Column modal
	// -------------------------------------------------------------------------
	function buildModal() {
		const cats = cfg.columns || {};
		let html    = '';

		for ( const [ , group ] of Object.entries( cats ) ) {
			html += `
				<div class="cf-mlv-modal-group">
					<button type="button" class="cf-mlv-group-toggle" aria-expanded="true">
						<span class="cf-mlv-group-label">${ esc( group.label ) }</span>
						<span class="cf-mlv-group-chevron" aria-hidden="true">&#9660;</span>
					</button>
					<div class="cf-mlv-group-body">
			`;

			for ( const [ colKey, col ] of Object.entries( group.columns || {} ) ) {
				const checked     = state.columns.includes( colKey ) ? ' checked' : '';
				const defaultMark = col.default
					? ` <span class="cf-mlv-default-badge">default</span>`
					: '';
				html += `
					<label class="cf-mlv-col-row">
						<input type="checkbox" class="cf-mlv-col-check" value="${ esc( colKey ) }"${ checked }>
						<div class="cf-mlv-col-info">
							<span class="cf-mlv-col-name">${ esc( col.label ) }${ defaultMark }</span>
							<span class="cf-mlv-col-desc">${ esc( col.desc || '' ) }</span>
						</div>
					</label>
				`;
			}

			html += `</div></div>`;
		}

		elModalBody.innerHTML = html;

		// Collapse/expand group toggles.
		elModalBody.querySelectorAll( '.cf-mlv-group-toggle' ).forEach( btn => {
			btn.addEventListener( 'click', () => {
				const expanded = btn.getAttribute( 'aria-expanded' ) === 'true';
				const body     = btn.nextElementSibling;
				btn.setAttribute( 'aria-expanded', String( ! expanded ) );
				body.style.display = expanded ? 'none' : '';
				btn.querySelector( '.cf-mlv-group-chevron' ).innerHTML = expanded ? '&#9654;' : '&#9660;';
			} );
		} );
	}

	function openModal() {
		buildModal();
		elModal.removeAttribute( 'hidden' );
		document.body.classList.add( 'cf-mlv-modal-open' );
		elModal.querySelector( '.cf-mlv-modal-close' ).focus();
	}

	function closeModal() {
		elModal.setAttribute( 'hidden', '' );
		document.body.classList.remove( 'cf-mlv-modal-open' );
		elColBtn.focus();
	}

	function applyModal() {
		const checked = Array.from(
			elModalBody.querySelectorAll( '.cf-mlv-col-check:checked' )
		).map( el => el.value );

		if ( checked.length === 0 ) {
			// eslint-disable-next-line no-alert
			alert( 'Select at least one column.' );
			return;
		}

		state.columns = checked;
		saveColumns( checked );
		closeModal();
		state.page = 1;
		fetchAttachments();
	}

	// -------------------------------------------------------------------------
	// Event wiring
	// -------------------------------------------------------------------------

	// Sort on header click.
	elThead.addEventListener( 'click', e => {
		const th = e.target.closest( '.cf-mlv-th.is-sortable' );
		if ( ! th ) return;
		const col = th.dataset.col;
		if ( state.orderby === col ) {
			state.order = state.order === 'ASC' ? 'DESC' : 'ASC';
		} else {
			state.orderby = col;
			state.order   = 'DESC';
		}
		state.page = 1;
		fetchAttachments();
	} );

	// Search (debounced 350 ms).
	let searchTimer;
	elSearch.addEventListener( 'input', () => {
		clearTimeout( searchTimer );
		searchTimer = setTimeout( () => {
			state.search = elSearch.value.trim();
			state.page   = 1;
			fetchAttachments();
		}, 350 );
	} );

	elMime.addEventListener( 'change', () => {
		state.mime = elMime.value;
		state.page = 1;
		fetchAttachments();
	} );

	elParent.addEventListener( 'change', () => {
		state.parent = elParent.value;
		state.page   = 1;
		fetchAttachments();
	} );

	elPerPage.addEventListener( 'change', () => {
		state.perPage = parseInt( elPerPage.value, 10 );
		state.page    = 1;
		fetchAttachments();
	} );

	// Column modal triggers.
	elColBtn.addEventListener( 'click', openModal );
	elModal.querySelector( '.cf-mlv-modal-close' ).addEventListener( 'click', closeModal );
	elModal.querySelector( '.cf-mlv-modal-backdrop' ).addEventListener( 'click', closeModal );
	document.getElementById( 'cf-mlv-modal-cancel' ).addEventListener( 'click', closeModal );
	document.getElementById( 'cf-mlv-modal-apply' ).addEventListener( 'click', applyModal );

	document.getElementById( 'cf-mlv-modal-reset' ).addEventListener( 'click', () => {
		const defaults = cfg.defaults || [];
		elModalBody.querySelectorAll( '.cf-mlv-col-check' ).forEach( cb => {
			cb.checked = defaults.includes( cb.value );
		} );
	} );

	// Keyboard: close modal on Escape.
	document.addEventListener( 'keydown', e => {
		if ( e.key === 'Escape' && ! elModal.hasAttribute( 'hidden' ) ) {
			closeModal();
		}
	} );

	// Pagination clicks.
	elPager.addEventListener( 'click', e => {
		const btn = e.target.closest( '.cf-mlv-page-btn' );
		if ( ! btn ) return;
		const p = parseInt( btn.dataset.page, 10 );
		if ( p && p !== state.page ) {
			state.page = p;
			fetchAttachments();
			document.querySelector( '.cf-mlv-table-wrap' )
				.scrollIntoView( { behavior: 'smooth', block: 'start' } );
		}
	} );

	// CSV export — passes current filters and active columns.
	elExport.addEventListener( 'click', () => {
		const url = new URL( cfg.exportUrl, window.location.href );
		url.searchParams.set( '_wpnonce', cfg.exportNonce );
		url.searchParams.set( 'cols', state.columns.join( ',' ) );
		if ( state.search ) url.searchParams.set( 's', state.search );
		if ( state.mime   ) url.searchParams.set( 'mime', state.mime );
		if ( state.parent ) url.searchParams.set( 'parent', state.parent );
		window.location.href = url.toString();
	} );

	// -------------------------------------------------------------------------
	// Init
	// -------------------------------------------------------------------------
	fetchAttachments();
} )();
