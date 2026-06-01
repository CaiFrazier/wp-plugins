/**
 * Client-side structured logger.
 *
 * - Keeps the last 500 entries in an in-memory ring buffer.
 * - Mirrors all entries to console.* so they're inspectable in DevTools live.
 * - Batches and ships entries to /diagnostics/client-log so PHP-side log has
 *   the full request lifecycle including UI events.
 *
 * Exposed at window.bmeLog for ad-hoc inspection from the console.
 */

import apiFetch from '@wordpress/api-fetch';

const RING_SIZE     = 500;
const FLUSH_AFTER_N = 25;
const FLUSH_AFTER_MS = 2000;

const buffer  = [];
const pending = [];
let flushTimer = null;
let flushing   = false;

function getNamespace() {
	return (
		window.bmeEditorData?.apiNamespace ||
		window.bmeSettingsData?.apiNamespace ||
		window.bmeDiagnosticsData?.apiNamespace ||
		'bulk-meta-editor/v1'
	);
}

function nowIso() {
	try {
		return new Date().toISOString();
	} catch ( e ) {
		return String( Date.now() );
	}
}

function pushRing( entry ) {
	buffer.push( entry );
	if ( buffer.length > RING_SIZE ) buffer.shift();
}

function consoleMirror( entry ) {
	const tag = `[BME ${ entry.channel }]`;
	const fn  =
		entry.level === 'error' ? console.error :
		entry.level === 'warn'  ? console.warn  :
		entry.level === 'debug' ? console.debug :
		console.info;
	if ( entry.context && Object.keys( entry.context ).length ) {
		fn( tag, entry.message, entry.context );
	} else {
		fn( tag, entry.message );
	}
}

function canMirror() {
	return !! (
		window.bmeEditorData?.canMirrorLogs ||
		window.bmeSettingsData?.canMirrorLogs ||
		window.bmeDiagnosticsData?.canMirrorLogs
	);
}

async function flush() {
	if ( flushing ) return;
	if ( ! pending.length ) return;
	if ( ! canMirror() ) {
		// Non-admin user: keep entries in the local ring buffer + console only.
		// The server endpoint requires manage_options to prevent log flooding.
		pending.length = 0;
		return;
	}
	flushing = true;
	const batch = pending.splice( 0, pending.length );
	try {
		await apiFetch( {
			path: `/${ getNamespace() }/diagnostics/client-log`,
			method: 'POST',
			data: batch,
		} );
	} catch ( e ) {
		// Swallow. We still have the entries in the local ring buffer.
		// Re-queueing on failure could create a tight loop if the endpoint is broken.
		// eslint-disable-next-line no-console
		console.warn( '[BME logger] flush failed, entries retained in ring only', e );
	} finally {
		flushing = false;
	}
}

function scheduleFlush() {
	if ( pending.length >= FLUSH_AFTER_N ) {
		if ( flushTimer ) {
			clearTimeout( flushTimer );
			flushTimer = null;
		}
		flush();
		return;
	}
	if ( flushTimer ) return;
	flushTimer = setTimeout( () => {
		flushTimer = null;
		flush();
	}, FLUSH_AFTER_MS );
}

function log( level, channel, message, context = {} ) {
	const entry = {
		ts: nowIso(),
		level,
		channel,
		message,
		context,
	};
	pushRing( entry );
	consoleMirror( entry );
	pending.push( entry );
	scheduleFlush();
}

const api = {
	debug: ( channel, message, context ) => log( 'debug', channel, message, context ),
	info:  ( channel, message, context ) => log( 'info', channel, message, context ),
	warn:  ( channel, message, context ) => log( 'warn', channel, message, context ),
	error: ( channel, message, context ) => log( 'error', channel, message, context ),
	dump:  () => buffer.slice(),
	flush,
	clear: () => {
		buffer.length = 0;
		pending.length = 0;
	},
};

// Make available for ad-hoc inspection.
if ( typeof window !== 'undefined' ) {
	window.bmeLog = api;

	// Best-effort flush on page unload via Beacon, which survives navigation.
	window.addEventListener( 'pagehide', () => {
		if ( ! pending.length ) return;
		if ( ! canMirror() ) return;
		try {
			const url   = `${ window.bmeEditorData?.restRoot || window.bmeSettingsData?.restRoot || window.bmeDiagnosticsData?.restRoot || '/wp-json/' }${ getNamespace() }/diagnostics/client-log?_wpnonce=${ window.bmeEditorData?.nonce || window.bmeSettingsData?.nonce || window.bmeDiagnosticsData?.nonce || '' }`;
			const blob  = new Blob( [ JSON.stringify( pending ) ], { type: 'application/json' } );
			navigator.sendBeacon( url, blob );
		} catch ( e ) {
			// Nothing to do; we're leaving anyway.
		}
	} );

	// Capture global errors and unhandled promise rejections so we don't
	// rely on every component remembering to log.
	window.addEventListener( 'error', ( event ) => {
		api.error( 'window', event.message || 'window error', {
			filename: event.filename,
			lineno:   event.lineno,
			colno:    event.colno,
			stack:    event.error?.stack,
		} );
	} );
	window.addEventListener( 'unhandledrejection', ( event ) => {
		const reason = event.reason;
		api.error( 'promise', 'unhandled rejection', {
			message: reason?.message ?? String( reason ),
			stack:   reason?.stack,
		} );
	} );
}

export default api;
