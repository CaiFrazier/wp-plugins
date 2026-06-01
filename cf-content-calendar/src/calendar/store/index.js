import { create } from 'zustand';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import {
	getMonthRangeForQuery,
	getWeekRangeForQuery,
	getListRangeForQuery,
} from '../utils/dates';

const cfCalData = window.cfCalData || {};
const API_BASE = cfCalData.restUrl || '';

if ( cfCalData.nonce ) {
	apiFetch.use( apiFetch.createNonceMiddleware( cfCalData.nonce ) );
}

const now = new Date();

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
		const t = new Date();
		set( { year: t.getFullYear(), month: t.getMonth(), day: t.getDate() } );
	},

	setFilters( patch ) {
		set( { filters: { ...get().filters, ...patch } } );
	},

	async fetchPosts() {
		const { view, year, month, day, filters } = get();
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
					__( 'Failed to load posts.', 'cf-content-calendar' ),
			} );
		}
	},

	async reschedulePost( id, newDate ) {
		const { posts } = get();
		const prev = posts.find( ( p ) => p.id === id );
		if ( ! prev ) {
			return;
		}

		// Optimistic update.
		set( {
			posts: posts.map( ( p ) =>
				p.id === id ? { ...p, date: newDate } : p
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
