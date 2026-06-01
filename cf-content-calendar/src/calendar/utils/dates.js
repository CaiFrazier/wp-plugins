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

export function isToday( date ) {
	return isSameDay( date, new Date() );
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
