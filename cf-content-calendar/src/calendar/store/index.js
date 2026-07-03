import { create } from 'zustand';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import {
	getMonthRangeForQuery,
	getWeekRangeForQuery,
	getListRangeForQuery,
	siteToday,
} from '../utils/dates';

const cfCalData = window.cfCalData || {};
const API_BASE = cfCalData.restUrl || '';

if ( cfCalData.nonce ) {
	apiFetch.use( apiFetch.createNonceMiddleware( cfCalData.nonce ) );
}

const now = siteToday();

// Extract "HH:MM:SS" from a "YYYY-MM-DD HH:MM:SS" string, defaulting to
// midnight. Used to keep a post's time-of-day when only its day changes.
function timeOfDay( dateStr ) {
	const match = /\d{2}:\d{2}:\d{2}/.exec( dateStr || '' );
	return match ? match[ 0 ] : '00:00:00';
}

// Per-user client-side preference: skip the "unpublish & reschedule" confirm.
const SKIP_CONFIRM_KEY = 'cfCalSkipRescheduleConfirm';
function skipRescheduleConfirm() {
	try {
		return window.localStorage.getItem( SKIP_CONFIRM_KEY ) === '1';
	} catch {
		return false;
	}
}
function rememberSkipRescheduleConfirm() {
	try {
		window.localStorage.setItem( SKIP_CONFIRM_KEY, '1' );
	} catch {
		// Private mode / storage disabled — just don't persist.
	}
}

const useCalendarStore = create( ( set, get ) => ( {
	// Date is stored as three integers so React effects can depend on them
	// without reference-equality issues that a Date object would cause.
	year: now.getFullYear(),
	month: now.getMonth(),
	day: now.getDate(),

	view: 'month', // 'month' | 'week' | 'list'
	posts: [],
	loading: false,
	error: null,

	// Set to { id, newDate, whenLabel } when a published post is dragged into
	// the future and the confirmation dialog needs to be shown.
	pendingReschedule: null,

	filters: {
		postTypes: Object.keys( cfCalData.postTypes || {} ),
		statuses: [ 'publish', 'future', 'draft' ],
		authorId: 0, // 0 = all authors
	},

	setView( view ) {
		set( { view } );
	},

	navigate( direction ) {
		const { view, year, month, day } = get();
		if ( 'month' === view ) {
			const d = new Date( year, month + direction, 1 );
			set( { year: d.getFullYear(), month: d.getMonth(), day: 1 } );
		} else {
			const d = new Date( year, month, day + direction * 7 );
			set( {
				year: d.getFullYear(),
				month: d.getMonth(),
				day: d.getDate(),
			} );
		}
	},

	goToToday() {
		const t = siteToday();
		set( { year: t.getFullYear(), month: t.getMonth(), day: t.getDate() } );
	},

	setFilters( patch ) {
		set( { filters: { ...get().filters, ...patch } } );
	},

	async fetchPosts() {
		const { view, year, month, day, filters } = get();

		// An empty type or status filter means "show nothing" — but sending no
		// param makes the REST endpoint fall back to all types/statuses, the
		// opposite of intent. Short-circuit to an empty grid without a request.
		if ( filters.postTypes.length === 0 || filters.statuses.length === 0 ) {
			set( { posts: [], loading: false, error: null } );
			return;
		}

		const date = new Date( year, month, day );

		let range;
		if ( 'month' === view ) {
			range = getMonthRangeForQuery( year, month );
		} else if ( 'week' === view ) {
			range = getWeekRangeForQuery( date );
		} else {
			range = getListRangeForQuery( date );
		}

		set( { loading: true, error: null } );

		const params = new URLSearchParams( {
			start: range.start,
			end: range.end,
		} );
		filters.postTypes.forEach( ( t ) =>
			params.append( 'post_types[]', t )
		);
		filters.statuses.forEach( ( s ) =>
			params.append( 'post_status[]', s )
		);
		if ( filters.authorId > 0 ) {
			params.append( 'author', String( filters.authorId ) );
		}

		try {
			const posts = await apiFetch( {
				url: `${ API_BASE }/posts?${ params }`,
				method: 'GET',
			} );
			set( { posts, loading: false } );
		} catch ( err ) {
			set( {
				loading: false,
				error:
					err.message ||
					__(
						'Failed to load posts. The REST API may be blocked — check that no security plugin is disabling it.',
						'cf-content-calendar'
					),
			} );
		}
	},

	// Entry point for a drag-drop reschedule. A published post moved into the
	// future is unpublished and re-scheduled — a destructive-enough change that
	// it's confirmed first (unless the user opted out). Everything else goes
	// straight through.
	requestReschedule( id, newDate ) {
		const post = get().posts.find( ( p ) => p.id === id );
		if ( ! post ) {
			return;
		}

		const target = new Date( `${ newDate }T${ timeOfDay( post.date ) }` );
		const isFuture = target.getTime() > Date.now();

		if (
			post.status === 'publish' &&
			isFuture &&
			! skipRescheduleConfirm()
		) {
			set( {
				pendingReschedule: {
					id,
					newDate,
					whenLabel: target.toLocaleString( undefined, {
						dateStyle: 'medium',
						timeStyle: 'short',
					} ),
				},
			} );
			return;
		}

		get().reschedulePost( id, newDate );
	},

	confirmReschedule( dontAskAgain ) {
		const pending = get().pendingReschedule;
		if ( ! pending ) {
			return;
		}
		if ( dontAskAgain ) {
			rememberSkipRescheduleConfirm();
		}
		set( { pendingReschedule: null } );
		get().reschedulePost( pending.id, pending.newDate );
	},

	cancelReschedule() {
		set( { pendingReschedule: null } );
	},

	async reschedulePost( id, newDate ) {
		const { posts } = get();
		const prev = posts.find( ( p ) => p.id === id );
		if ( ! prev ) {
			return;
		}

		// Optimistic update — keep the post's existing time-of-day so the chip
		// doesn't flash to midnight before the server echoes back the real
		// datetime (the server preserves the time on a date-only reschedule).
		const optimisticDate = `${ newDate } ${ timeOfDay( prev.date ) }`;
		set( {
			posts: posts.map( ( p ) =>
				p.id === id ? { ...p, date: optimisticDate } : p
			),
		} );

		try {
			const updated = await apiFetch( {
				url: `${ API_BASE }/posts/${ id }/reschedule`,
				method: 'PATCH',
				data: { post_date: newDate },
			} );
			set( {
				posts: get().posts.map( ( p ) =>
					p.id === id ? updated : p
				),
			} );
		} catch ( err ) {
			// Roll back and surface the error.
			set( {
				posts: get().posts.map( ( p ) => ( p.id === id ? prev : p ) ),
				error:
					err.message ||
					__( 'Reschedule failed.', 'cf-content-calendar' ),
			} );
		}
	},

	async createPost( data ) {
		try {
			const created = await apiFetch( {
				url: `${ API_BASE }/posts`,
				method: 'POST',
				data,
			} );
			set( { posts: [ ...get().posts, created ] } );
			return created;
		} catch ( err ) {
			set( {
				error:
					err.message ||
					__( 'Failed to create post.', 'cf-content-calendar' ),
			} );
			throw err;
		}
	},

	clearError() {
		set( { error: null } );
	},
} ) );

export default useCalendarStore;
