/**
 * Media-modal integration.
 *
 * WordPress core does not reassemble Plupload chunks, and Plupload's own
 * chunking still POSTs each chunk through admin-ajax under the host's
 * upload_max_filesize. So instead of configuring Plupload, we intercept large
 * files the moment they are queued: pull them out of Plupload's queue and run
 * them through our REST chunker (destination='media'), which sideloads the
 * assembled file into the library server-side.
 *
 * Defensive by construction: everything is feature-detected. If wp.Uploader
 * is absent or the API shifts, small files keep flowing through the native
 * path untouched — we only ever *remove* oversized files from Plupload, never
 * alter its behavior for files it can already handle.
 */
import { __ } from '@wordpress/i18n';
import { Uploader } from './Uploader';
import { boot, guardUnload } from './lib';

( function () {
	const cfg = boot();
	const threshold = cfg.thresholdBytes || 10 * 1048576;

	// One bag of in-flight diverted uploads, keyed by uploadId. While any is
	// running, warn before the tab closes — a closed tab silently kills the
	// upload and orphans chunks (cron eventually sweeps them, but the user
	// loses all progress).
	const inFlight = new Set();
	const statuses = new Map();
	guardUnload( () => inFlight.size > 0 );

	/**
	 * Render every active status line into a single native-looking notice
	 * near the media frame. One concurrent uploader per uploadId; the bar
	 * shows all of them at once instead of jumping between filenames.
	 *
	 * Built with textContent / createElement — never innerHTML. file.name and
	 * server error messages are attacker-influenceable strings (a file named
	 * `<img src=x onerror=…>.zip` would otherwise execute in wp-admin); DOM
	 * construction makes injection structurally impossible.
	 *
	 * @param {HTMLElement} [action] Optional trusted element appended to the
	 *                               last line (e.g. the "Reload to see it"
	 *                               button after a completion).
	 */
	function renderStatuses( action ) {
		const lines = Array.from( statuses.values() );
		let bar = document.getElementById( 'cf-cu-media-status' );

		if ( ! lines.length ) {
			if ( bar ) {
				bar.remove();
			}
			return;
		}

		if ( ! bar ) {
			bar = document.createElement( 'div' );
			bar.id = 'cf-cu-media-status';
			bar.className = 'notice notice-info inline';
			bar.style.margin = '10px 0';
			const host =
				document.querySelector( '.media-frame-content' ) ||
				document.querySelector( '#wpbody-content' );
			host?.prepend( bar );
		}

		const nodes = lines.map( ( msg ) => {
			const p = document.createElement( 'p' );
			p.textContent = msg;
			return p;
		} );
		if ( action ) {
			nodes[ nodes.length - 1 ].appendChild( document.createTextNode( ' ' ) );
			nodes[ nodes.length - 1 ].appendChild( action );
		}
		bar.replaceChildren( ...nodes );
	}

	function divert( file ) {
		const u = new Uploader( file, {
			restRoot: cfg.restRoot,
			nonce: cfg.nonce,
			destination: 'media',
			chunkBytes: cfg.chunkBytes,
			concurrency: cfg.concurrency,
			maxRetries: cfg.maxRetries,
		} );
		inFlight.add( u.uploadId );

		u.on( 'progress', ( p ) => {
			const message = p.phase === 'assembling'
				? `Assembling “${ file.name }”…`
				: `Uploading “${ file.name }” — ${ p.percent }%`;
			statuses.set( u.uploadId, message );
			renderStatuses();
		} );

		u.on( 'complete', () => {
			inFlight.delete( u.uploadId );
			statuses.delete( u.uploadId );

			// Try to refresh the open media frame in place so the new
			// attachment appears without a full page reload. Setting an
			// "ignore" prop with a fresh value busts the attachment query
			// cache, then .more() forces a re-fetch. If the frame is closed
			// (or the API has shifted), library is undefined and we fall back
			// to the reload-button path.
			const frame = window?.wp?.media?.frame;
			const library = frame?.content?.get?.()?.collection;
			if ( library ) {
				library.props.set( { ignore: Date.now() } );
				library.more();
				statuses.set( u.uploadId, `“${ file.name }” added to the Media Library.` );
				renderStatuses();
				setTimeout( () => {
					statuses.delete( u.uploadId );
					renderStatuses();
				}, 4000 );
				return;
			}

			const reload = document.createElement( 'button' );
			reload.type = 'button';
			reload.className = 'button button-small';
			reload.textContent = __( 'Reload to see it', 'cf-chunked-upload' );
			reload.addEventListener( 'click', () => location.reload() );
			statuses.set( u.uploadId, `“${ file.name }” added.` );
			renderStatuses( reload );
		} );

		u.on( 'error', ( e ) => {
			inFlight.delete( u.uploadId );
			statuses.set( u.uploadId, `Upload of “${ file.name }” failed: ${ e.message }` );
			renderStatuses();
		} );
		u.start();
	}

	function attach( uploaderInstance ) {
		const up = uploaderInstance?.uploader; // the underlying plupload.Uploader
		if ( ! up || ! up.bind ) {
			return;
		}
		up.bind( 'FilesAdded', ( pl, files ) => {
			for ( let i = files.length - 1; i >= 0; i-- ) {
				const pf = files[ i ];
				const native = pf.getNative ? pf.getNative() : pf.getSource?.();
				if ( native && native.size >= threshold ) {
					pl.removeFile( pf ); // keep it out of the host-limited path
					divert( native );
				}
			}
		} );
	}

	function install() {
		if ( ! window.wp || ! window.wp.Uploader ) {
			return false;
		}
		const proto = window.wp.Uploader.prototype;
		if ( proto.__cfCuWrapped ) {
			return true;
		}
		const originalInit = proto.init;
		proto.init = function () {
			if ( typeof originalInit === 'function' ) {
				originalInit.apply( this, arguments );
			}
			try {
				attach( this );
			} catch ( e ) {
				/* Never break the native uploader. */
			}
		};
		proto.__cfCuWrapped = true;
		return true;
	}

	// wp.Uploader may load after this script; poll briefly then give up.
	// The native uploader keeps working regardless — we only ever *add*
	// diversion, never replace anything.
	if ( ! install() ) {
		let tries = 0;
		const t = setInterval( () => {
			if ( install() || ++tries > 40 ) {
				clearInterval( t );
			}
		}, 250 );
	}
} )();
