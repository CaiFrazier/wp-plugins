import { create } from 'zustand';
import apiFetch from '../../shared/api';
import bmeLog from '../../shared/logger';

// Default to {} so an asset enqueued in the wrong context (eg. a third-party
// caller importing the bundle) doesn't crash at module-init time. The store
// will simply boot with no post types and an empty grid until the editor
// page provides the real localized data.
const { apiNamespace, settings = {}, columnVisibility = {} } = window.bmeEditorData ?? {};

// Debounce helper for column-visibility persistence.
function debounce( fn, wait = 400 ) {
	let t;
	return ( ...args ) => {
		clearTimeout( t );
		t = setTimeout( () => fn( ...args ), wait );
	};
}

// Debounced picker fetch so a search keystroke doesn't fire one REST call per character.
let debouncedPickerFetchHandle = null;
function scheduleDebouncedPickerFetch( storeApi, wait = 300 ) {
	if ( debouncedPickerFetchHandle ) clearTimeout( debouncedPickerFetchHandle );
	debouncedPickerFetchHandle = setTimeout( () => {
		debouncedPickerFetchHandle = null;
		storeApi().fetchPickerPosts();
	}, wait );
}

// Monotonically increasing notice id so React keys are stable and unique.
let noticeIdCounter = 1;

// Human-readable size for the value_too_large error_context — keeps the user
// notice meaningful ("68.4 KB > 64 KB limit") instead of a bare error code.
function formatBytes( n ) {
	if ( typeof n !== 'number' || ! isFinite( n ) || n < 0 ) return '?';
	if ( n < 1024 ) return `${ n } B`;
	if ( n < 1024 * 1024 ) return `${ ( n / 1024 ).toFixed( 1 ) } KB`;
	return `${ ( n / 1024 / 1024 ).toFixed( 2 ) } MB`;
}

// Turn a save_batch result row's `error` code (plus optional `error_context`)
// into a short user-facing string. Keep this in one place so both saveRow and
// saveAll surface the same wording.
function formatSaveError( r ) {
	const code = r.error || 'unknown';
	if ( code === 'value_too_large' && r.error_context ) {
		const { actual, max } = r.error_context;
		return `value too large (${ formatBytes( actual ) } > ${ formatBytes( max ) } limit)`;
	}
	return code;
}

const persistColumnVisibility = debounce( async ( vis ) => {
	try {
		await apiFetch( {
			path: `/${ apiNamespace }/column-visibility`,
			method: 'POST',
			data: vis,
		} );
	} catch ( e ) {
		// Non-fatal: visibility persistence is best-effort.
		// eslint-disable-next-line no-console
		console.warn( '[BME] Failed to persist column visibility', e );
	}
} );

export const useStore = create( ( set, get ) => ( {
	// Post type
	activePostType: '',
	postTypes: [],

	// Picker state
	pickerPosts: [],
	pickerTotal: 0,
	pickerPages: 0,
	pickerPage: 1,
	pickerPerPage: settings.default_per_page ?? 50,
	pickerStatus: 'any',
	pickerSearch: '',
	selectedIds: new Set(),
	pickerLoading: false,

	// Grid state
	loadedRows: [],
	dirtyRows: {},       // { [id]: { [colKey]: newValue } }
	gridPage: 1,
	gridPerPage: settings.default_per_page ?? 50,
	gridLoading: false,
	saving: false,

	// Column visibility — initialised from server-rendered user meta.
	columnVisibility: { ...( columnVisibility ?? {} ) },

	// Notices
	notices: [],

	// ─── Actions ───────────────────────────────────────────────────────────────

	initPostTypes: async () => {
		try {
			const types = await apiFetch( { path: `/${ apiNamespace }/post-types` } );
			set( { postTypes: types, activePostType: types[0]?.slug ?? '' } );
			if ( types.length ) {
				get().fetchPickerPosts();
			}
		} catch ( e ) {
			get().addNotice( 'error', e.message || 'Failed to load post types.' );
		}
	},

	setActivePostType: ( slug ) => {
		set( {
			activePostType: slug,
			pickerPage: 1,
			pickerSearch: '',
			pickerStatus: 'any',
			selectedIds: new Set(),
		} );
		get().fetchPickerPosts();
	},

	fetchPickerPosts: async () => {
		const { activePostType, pickerPage, pickerPerPage, pickerStatus, pickerSearch } = get();
		if ( ! activePostType ) return;
		set( { pickerLoading: true } );
		try {
			const result = await apiFetch( {
				path: `/${ apiNamespace }/posts`,
				method: 'GET',
				data: {
					post_type: activePostType,
					page: pickerPage,
					per_page: pickerPerPage,
					status: pickerStatus,
					search: pickerSearch,
				},
			} );
			set( {
				pickerPosts: result.posts,
				pickerTotal: result.total,
				pickerPages: result.pages,
				pickerLoading: false,
			} );
		} catch ( e ) {
			set( { pickerLoading: false } );
			get().addNotice( 'error', e.message || 'Failed to load posts.' );
		}
	},

	setPickerPage: ( page ) => {
		set( { pickerPage: page } );
		get().fetchPickerPosts();
	},

	setPickerPerPage: ( n ) => {
		set( { pickerPerPage: n, pickerPage: 1 } );
		get().fetchPickerPosts();
	},

	setPickerStatus: ( status ) => {
		set( { pickerStatus: status, pickerPage: 1 } );
		get().fetchPickerPosts();
	},

	setPickerSearch: ( search ) => {
		set( { pickerSearch: search, pickerPage: 1 } );
		// Debounce: avoid one REST call per keystroke while user is typing.
		scheduleDebouncedPickerFetch( get );
	},

	toggleSelectId: ( id ) => {
		const next = new Set( get().selectedIds );
		if ( next.has( id ) ) {
			next.delete( id );
		} else {
			next.add( id );
		}
		set( { selectedIds: next } );
	},

	selectAllOnPage: () => {
		const next = new Set( get().selectedIds );
		get().pickerPosts.forEach( ( p ) => next.add( p.id ) );
		set( { selectedIds: next } );
	},

	clearPageSelection: () => {
		const next = new Set( get().selectedIds );
		get().pickerPosts.forEach( ( p ) => next.delete( p.id ) );
		set( { selectedIds: next } );
	},

	clearSelection: () => set( { selectedIds: new Set() } ),

	loadSelected: async () => {
		const ids = [ ...get().selectedIds ];
		if ( ! ids.length ) return;
		set( { gridLoading: true } );
		// Chunked so very large selections don't blow past the Apache/Nginx
		// URL-length limit (~8 KB). With `ids[]=` encoding each id costs
		// ~12 chars, so 200/chunk keeps URLs well under 3 KB.
		const CHUNK_SIZE = 200;
		const chunks = [];
		for ( let i = 0; i < ids.length; i += CHUNK_SIZE ) {
			chunks.push( ids.slice( i, i + CHUNK_SIZE ) );
		}
		try {
			const results = await Promise.all(
				chunks.map( ( chunkIds ) =>
					apiFetch( {
						path: `/${ apiNamespace }/posts/batch`,
						method: 'GET',
						data: { ids: chunkIds },
					} )
				)
			);
			const rows = results.flat();
			set( { loadedRows: rows, dirtyRows: {}, gridPage: 1, gridLoading: false } );
		} catch ( e ) {
			set( { gridLoading: false } );
			get().addNotice( 'error', e.message || 'Failed to load rows.' );
		}
	},

	setCellValue: ( id, colKey, value ) => {
		bmeLog.debug( 'store', 'cell edit', { id, colKey, length: ( value ?? '' ).length } );
		set( ( state ) => ( {
			dirtyRows: {
				...state.dirtyRows,
				[ id ]: { ...( state.dirtyRows[ id ] ?? {} ), [ colKey ]: value },
			},
		} ) );
	},

	revertRow: ( id ) => {
		bmeLog.info( 'store', 'revert row', { id } );
		set( ( state ) => {
			const next = { ...state.dirtyRows };
			delete next[ id ];
			return { dirtyRows: next };
		} );
	},

	saveAll: async () => {
		const { dirtyRows } = get();
		const edits = [];
		for ( const [ id, cols ] of Object.entries( dirtyRows ) ) {
			for ( const [ key, value ] of Object.entries( cols ) ) {
				edits.push( { id: Number( id ), key, value } );
			}
		}
		if ( ! edits.length ) return;
		set( { saving: true } );
		try {
			const results = await apiFetch( {
				path: `/${ apiNamespace }/posts/batch`,
				method: 'POST',
				data: edits,
			} );
			const failed = results.filter( ( r ) => ! r.success );
			if ( failed.length ) {
				get().addNotice(
					'warning',
					`${ failed.length } edit(s) could not be saved (${ failed.map( formatSaveError ).join( ', ' ) }).`
				);
			} else {
				get().addNotice( 'success', 'All changes saved.' );
			}

			// Merge successfully-saved values into loadedRows; drop saved entries from dirty.
			set( ( state ) => {
				const successByRow = {};
				results.forEach( ( r ) => {
					if ( ! r.success ) return;
					successByRow[ r.id ] = successByRow[ r.id ] ?? new Set();
					successByRow[ r.id ].add( r.key );
				} );

				const newLoaded = state.loadedRows.map( ( row ) => {
					const dirty = state.dirtyRows[ row.id ];
					if ( ! dirty ) return row;
					const saved = successByRow[ row.id ];
					if ( ! saved ) return row;
					const updated = { ...row };
					for ( const k of saved ) {
						if ( k in dirty ) updated[ k ] = dirty[ k ];
					}
					return updated;
				} );

				const newDirty = {};
				for ( const [ id, cols ] of Object.entries( state.dirtyRows ) ) {
					const saved = successByRow[ Number( id ) ];
					const remaining = {};
					for ( const [ k, v ] of Object.entries( cols ) ) {
						if ( ! saved || ! saved.has( k ) ) remaining[ k ] = v;
					}
					if ( Object.keys( remaining ).length ) newDirty[ id ] = remaining;
				}

				return { loadedRows: newLoaded, dirtyRows: newDirty };
			} );
		} catch ( e ) {
			get().addNotice( 'error', e.message || 'Save failed.' );
		} finally {
			set( { saving: false } );
		}
	},

	saveRow: async ( id ) => {
		const dirty = get().dirtyRows[ id ];
		if ( ! dirty ) return;
		const edits = Object.entries( dirty ).map( ( [ key, value ] ) => ( { id: Number( id ), key, value } ) );
		try {
			const results = await apiFetch( {
				path: `/${ apiNamespace }/posts/batch`,
				method: 'POST',
				data: edits,
			} );

			const savedKeys  = new Set();
			const failedKeys = [];
			results.forEach( ( r ) => {
				if ( r.success ) {
					savedKeys.add( r.key );
				} else {
					failedKeys.push( { key: r.key, message: formatSaveError( r ) } );
				}
			} );

			set( ( state ) => {
				// Merge successful cells into loadedRows.
				const newLoaded = state.loadedRows.map( ( row ) => {
					if ( row.id !== id ) return row;
					const updated = { ...row };
					savedKeys.forEach( ( k ) => {
						if ( k in dirty ) updated[ k ] = dirty[ k ];
					} );
					return updated;
				} );

				// Drop saved cells from dirty; keep failed ones so user sees them and can retry.
				const remaining = {};
				Object.entries( dirty ).forEach( ( [ k, v ] ) => {
					if ( ! savedKeys.has( k ) ) remaining[ k ] = v;
				} );
				const nextDirty = { ...state.dirtyRows };
				if ( Object.keys( remaining ).length === 0 ) {
					delete nextDirty[ id ];
				} else {
					nextDirty[ id ] = remaining;
				}

				return { loadedRows: newLoaded, dirtyRows: nextDirty };
			} );

			if ( failedKeys.length === 0 ) {
				get().addNotice( 'success', `Row ${ id } saved.` );
			} else {
				get().addNotice(
					'warning',
					`Row ${ id }: ${ savedKeys.size } saved, ${ failedKeys.length } failed (${ failedKeys.map( ( f ) => `${ f.key }: ${ f.message }` ).join( '; ' ) }).`
				);
			}
		} catch ( e ) {
			get().addNotice( 'error', e.message || `Failed to save row ${ id }.` );
		}
	},

	setColumnVisibility: ( vis ) => {
		bmeLog.debug( 'store', 'column visibility change', { columns: Object.keys( vis ).length } );
		set( { columnVisibility: vis } );
		persistColumnVisibility( vis );
	},

	setGridPage: ( p ) => set( { gridPage: p } ),
	setGridPerPage: ( n ) => set( { gridPage: 1, gridPerPage: n } ),

	addNotice: ( type, message ) => {
		const id = noticeIdCounter++;
		const level = type === 'error' ? 'error' : type === 'warning' ? 'warn' : 'info';
		bmeLog[ level ]( 'notice', message, { type } );
		set( ( state ) => ( { notices: [ ...state.notices, { id, type, message } ] } ) );
		const ttl = type === 'error' ? 10000 : 5000;
		setTimeout( () => get().dismissNotice( id ), ttl );
	},

	dismissNotice: ( id ) =>
		set( ( state ) => ( { notices: state.notices.filter( ( n ) => n.id !== id ) } ) ),
} ) );
