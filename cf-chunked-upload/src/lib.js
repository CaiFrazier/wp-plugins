/**
 * Shared helpers for the admin entry points: the bootstrap object, a
 * navigate-away guard, and byte/duration formatting. (Nonce freshness is
 * handled inside Uploader by harvesting the X-WP-Nonce response header — no
 * separate refresher endpoint needed.)
 */

export function boot() {
	return window.CFChunkedUpload || {};
}

/**
 * Warn before unload while an upload is active. Browsers ignore custom
 * strings and show their own generic prompt — that is the only available
 * behavior. Returns a disposer.
 */
export function guardUnload( isActive ) {
	const handler = ( e ) => {
		if ( isActive() ) {
			e.preventDefault();
			e.returnValue = '';
			return '';
		}
		return undefined;
	};
	window.addEventListener( 'beforeunload', handler );
	return () => window.removeEventListener( 'beforeunload', handler );
}

export function formatBytes( bytes ) {
	if ( ! bytes || bytes < 0 ) {
		return '0 B';
	}
	const u = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
	let i = 0;
	let n = bytes;
	while ( n >= 1024 && i < u.length - 1 ) {
		n /= 1024;
		i++;
	}
	return `${ n.toFixed( i ? 1 : 0 ) } ${ u[ i ] }`;
}

export function formatDuration( seconds ) {
	if ( seconds == null || ! isFinite( seconds ) ) {
		return '—';
	}
	const s = Math.max( 0, Math.round( seconds ) );
	if ( s < 60 ) {
		return `${ s }s`;
	}
	const m = Math.floor( s / 60 );
	const r = s % 60;
	if ( m < 60 ) {
		return `${ m }m ${ r }s`;
	}
	const h = Math.floor( m / 60 );
	return `${ h }h ${ m % 60 }m`;
}
