/**
 * Date helpers for the calendar.
 * All functions work with plain JS Date objects and YYYY-MM-DD strings.
 * No third-party library required.
 */

export function formatDate( date ) {
	const y = date.getFullYear();
	const m = String( date.getMonth() + 1 ).padStart( 2, '0' );
	const d = String( date.getDate() ).padStart( 2, '0' );
	return `${ y }-${ m }-${ d }`;
}

export function formatDateTime( date ) {
	const h = String( date.getHours() ).padStart( 2, '0' );
	const min = String( date.getMinutes() ).padStart( 2, '0' );
	return `${ formatDate( date ) } ${ h }:${ min }:00`;
}

/**
 * Returns 42 Date objects (6 × 7) filling a month calendar grid,
 * starting from the Sunday before or on the 1st of the given month.
 * @param {number} year  Full year.
 * @param {number} month Zero-based month index.
 * @return {Date[]} 42 dates.
 */
export function getMonthGrid( year, month ) {
	const firstDay = new Date( year, month, 1 );
	const startOffset = firstDay.getDay(); // 0 = Sunday
	const days = [];
	for ( let i = 0; i < 42; i++ ) {
		days.push( new Date( year, month, 1 - startOffset + i ) );
	}
	return days;
}

/**
 * Returns 7 Date objects for the week containing the given date,
 * starting Sunday.
 * @param {Date} date Any date within the target week.
 * @return {Date[]} 7 dates, Sunday first.
 */
export function getWeekDays( date ) {
	const sunday = new Date( date );
	sunday.setDate( date.getDate() - date.getDay() );
	return Array.from( { length: 7 }, ( _, i ) => {
		const d = new Date( sunday );
		d.setDate( sunday.getDate() + i );
		return d;
	} );
}

export function isSameDay( a, b ) {
	return (
		a.getFullYear() === b.getFullYear() &&
		a.getMonth() === b.getMonth() &&
		a.getDate() === b.getDate()
	);
}

const SITE_TZ = ( window.cfCalData && window.cfCalData.timezone ) || '';

/**
 * "Today" in the site's timezone, returned as a browser-local Date at midnight
 * so day-level comparisons line up with grid cells (which are built with
 * new Date(year, month, day) in browser-local time). Falls back to the browser
 * day when the site uses a manual UTC-offset string (which Intl can't consume)
 * or on any error.
 * @return {Date} Site-local "today" as a browser-local midnight Date.
 */
export function siteToday() {
	if ( SITE_TZ && ! /^[+-]\d{2}:\d{2}$/.test( SITE_TZ ) ) {
		try {
			const parts = new Intl.DateTimeFormat( 'en-CA', {
				timeZone: SITE_TZ,
				year: 'numeric',
				month: '2-digit',
				day: '2-digit',
			} ).formatToParts( new Date() );
			const val = ( type ) =>
				parts.find( ( p ) => p.type === type )?.value;
			const y = parseInt( val( 'year' ), 10 );
			const m = parseInt( val( 'month' ), 10 );
			const d = parseInt( val( 'day' ), 10 );
			if ( y && m && d ) {
				return new Date( y, m - 1, d );
			}
		} catch {
			// Fall through to the browser day.
		}
	}
	return new Date();
}

export function isToday( date ) {
	return isSameDay( date, siteToday() );
}

export function isCurrentMonth( date, year, month ) {
	return date.getFullYear() === year && date.getMonth() === month;
}

export function getMonthRangeForQuery( year, month ) {
	const grid = getMonthGrid( year, month );
	return {
		start: formatDate( grid[ 0 ] ),
		end: formatDate( grid[ grid.length - 1 ] ),
	};
}

export function getWeekRangeForQuery( date ) {
	const days = getWeekDays( date );
	return {
		start: formatDate( days[ 0 ] ),
		end: formatDate( days[ 6 ] ),
	};
}

export function getListRangeForQuery( date ) {
	const start = new Date( date );
	start.setDate( start.getDate() - 14 );
	const end = new Date( date );
	end.setDate( end.getDate() + 28 );
	return { start: formatDate( start ), end: formatDate( end ) };
}

export function getMonthLabel( year, month ) {
	return new Date( year, month, 1 ).toLocaleDateString( undefined, {
		month: 'long',
		year: 'numeric',
	} );
}

export function getWeekLabel( date ) {
	const days = getWeekDays( date );
	const opts = { month: 'short', day: 'numeric' };
	return `${ days[ 0 ].toLocaleDateString(
		undefined,
		opts
	) } – ${ days[ 6 ].toLocaleDateString( undefined, opts ) }`;
}

/**
 * Given a YYYY-MM-DD or YYYY-MM-DD HH:MM:SS string, returns a Date in local time.
 * Avoids the UTC-midnight pitfall of new Date('YYYY-MM-DD').
 * @param {string} str Date or datetime string.
 * @return {Date} Parsed date (now() on failure).
 */
export function parseLocalDate( str ) {
	if ( ! str ) {
		return new Date();
	}
	// Replace space with T so the date-only form stays in local time.
	const normalized = str.includes( 'T' ) ? str : str.replace( ' ', 'T' );
	const d = new Date( normalized );
	return isNaN( d.getTime() ) ? new Date() : d;
}

export function postsForDay( posts, date ) {
	return posts.filter( ( p ) => isSameDay( parseLocalDate( p.date ), date ) );
}
