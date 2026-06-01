/**
 * Framework-agnostic chunked upload engine.
 *
 * Pure browser APIs only — File.slice, fetch, plus a no-dependency SHA-256
 * (./sha256). Works identically in the standalone importer and the
 * media-modal hook, and on plain HTTP (no crypto.subtle requirement).
 *
 * Integrity model:
 *   - Per-chunk SHA-256 sent with each chunk; the server re-hashes and
 *     rejects a corrupted chunk with 422.
 *   - Whole-file SHA-256 computed with a streaming hasher (O(1) memory) in a
 *     pass that runs CONCURRENTLY with the network send loop — uploads are
 *     network-bound (~MB/s) and finish long after hashing, so the digest is
 *     ready by the time we call /finalize. The digest is supplied in the
 *     /finalize body (not per-chunk), which is the natural place: the server
 *     verifies the assembled file against it.
 */

import { Sha256, sha256Hex } from './sha256';

function uuidv4() {
	if ( typeof crypto !== 'undefined' && crypto.randomUUID ) {
		return crypto.randomUUID();
	}
	// Math.random() is not cryptographically secure, but this id is only
	// used to name a server-side temp directory. It is not a secret and
	// cannot be guessed to access another user's session (the server binds
	// sessions to the authenticated user). crypto.randomUUID is always
	// preferred when available.
	return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace( /[xy]/g, ( c ) => {
		const r = ( Math.random() * 16 ) | 0;
		const v = c === 'x' ? r : ( r & 0x3 ) | 0x8;
		return v.toString( 16 );
	} );
}

const tick = () => new Promise( ( r ) => setTimeout( r, 0 ) );

export class Uploader {
	/**
	 * @param {File}   file
	 * @param {Object} opts
	 * @param {string} opts.restRoot    Base REST URL (…/cf-chunked-upload/v1).
	 * @param {string} opts.nonce       wp_rest nonce.
	 * @param {string} opts.destination 'media' | 'import'.
	 * @param {number} opts.chunkBytes  Chunk size in bytes.
	 * @param {number} opts.concurrency Max chunks in flight.
	 * @param {number} opts.maxRetries  Per-chunk retry budget.
	 * @param {string} [opts.uploadId]  Override upload ID (used by resume()).
	 * @param {Set}    [opts.received]  Indices already on the server (resume).
	 */
	constructor( file, opts ) {
		this.file = file;
		this.restRoot = opts.restRoot.replace( /\/$/, '' );
		this.nonce = opts.nonce;
		this.destination = opts.destination;
		this.chunkBytes = Math.max( 1, opts.chunkBytes | 0 );
		this.concurrency = Math.max( 1, Math.min( 6, ( opts.concurrency | 0 ) || 3 ) );
		this.maxRetries = Math.max( 1, Math.min( 10, ( opts.maxRetries | 0 ) || 3 ) );

		this.uploadId = opts.uploadId || uuidv4();
		this.totalChunks = Math.max( 1, Math.ceil( file.size / this.chunkBytes ) );
		this.cancelled = false;
		this._paused = false;
		this._resumeResolve = null;
		this._received = opts.received instanceof Set ? opts.received : new Set();

		this._listeners = { progress: [], complete: [], error: [] };
		this._done = this._received.size; // pre-count already-received chunks
		this._times = [];
		this._startedAt = 0;
		this._hashPromise = null;
		// OPT-5: when totalChunks === 1 and the chunk is fresh (not already
		// received), the per-chunk SHA-256 equals the whole-file SHA-256 and the
		// second read-and-hash pass can be skipped. Set by start(), resolved by
		// _sendChunk() once the chunk hash is computed.
		this._singleChunkHashResolve = null;
	}

	/**
	 * Cheap file fingerprint for localStorage keying. NOT a content hash — uses
	 * name, size, and lastModified which are fast to read without reading bytes.
	 *
	 * @param {File} file
	 * @returns {string}
	 */
	static fingerprint( file ) {
		return btoa( encodeURIComponent( `${ file.name }:${ file.size }:${ file.lastModified }` ) )
			.replace( /[+/=]/g, '' ).slice( 0, 48 );
	}

	/**
	 * Build a resume-capable Uploader from a saved localStorage state.
	 * Calls GET /status/{uploadId} to learn which chunks already landed.
	 *
	 * @param {File}   file      The same file the user wants to resume.
	 * @param {Object} saved     Stored state: { uploadId, destination, totalChunks }.
	 * @param {Object} opts      Same opts as the constructor (restRoot, nonce, …).
	 * @returns {Promise<Uploader>}
	 */
	static async resume( file, saved, opts ) {
		let received = new Set();
		try {
			const res = await fetch(
				opts.restRoot.replace( /\/$/, '' ) + `/status/${ saved.uploadId }`,
				{ headers: { 'X-WP-Nonce': opts.nonce }, credentials: 'same-origin' }
			);
			if ( res.ok ) {
				const data = await res.json();
				( data.received || [] ).forEach( ( i ) => received.add( i ) );
			}
		} catch ( _ ) {
			// Server unreachable or session expired — start fresh.
			received = new Set();
		}
		return new Uploader( file, {
			...opts,
			uploadId: received.size > 0 ? saved.uploadId : undefined,
			received,
		} );
	}

	on( event, fn ) {
		if ( this._listeners[ event ] ) {
			this._listeners[ event ].push( fn );
		}
		return this;
	}

	_emit( event, payload ) {
		( this._listeners[ event ] || [] ).forEach( ( fn ) => fn( payload ) );
	}

	cancel() {
		this.cancelled = true;
		this._resumeResolve?.();
		this._fetch( `/upload/${ this.uploadId }`, { method: 'DELETE' } ).catch(
			() => {}
		);
		this._clearResumable();
	}

	/** FEAT-6: pause sending new chunks. In-flight chunks complete normally. */
	pause() {
		this._paused = true;
	}

	/** FEAT-6: resume sending after a pause(). */
	resume() {
		if ( ! this._paused ) {
			return;
		}
		this._paused = false;
		if ( this._resumeResolve ) {
			const resolve = this._resumeResolve;
			this._resumeResolve = null;
			resolve();
		}
	}

	async _waitIfPaused() {
		while ( this._paused && ! this.cancelled ) {
			await new Promise( ( resolve ) => {
				this._resumeResolve = resolve;
			} );
		}
	}

	/** FEAT-1: persist resumable state to localStorage. */
	_saveResumable() {
		try {
			const key = `cf-cu-resumable-${ Uploader.fingerprint( this.file ) }`;
			localStorage.setItem(
				key,
				JSON.stringify( {
					uploadId: this.uploadId,
					destination: this.destination,
					fileName: this.file.name,
					size: this.file.size,
					totalChunks: this.totalChunks,
					savedAt: Date.now(),
				} )
			);
		} catch ( _ ) {}
	}

	/** FEAT-1: clear resumable entry on completion, error, or cancel. */
	_clearResumable() {
		try {
			localStorage.removeItem( `cf-cu-resumable-${ Uploader.fingerprint( this.file ) }` );
		} catch ( _ ) {}
	}

	async _fetch( path, init = {} ) {
		const doFetch = () =>
			fetch( this.restRoot + path, {
				...init,
				headers: {
					'X-WP-Nonce': this.nonce,
					...( init.headers || {} ),
				},
				credentials: 'same-origin',
			} );

		let res = await doFetch();
		// WP returns the currently-valid nonce on every authenticated REST
		// response. Harvest it so a nonce that ticks over mid-transfer (long
		// uploads can outlive the 12–24h nonce window) is picked up with zero
		// extra requests — the same mechanism @wordpress/api-fetch uses. No
		// capability problem, unlike pinging an admin-only route.
		const fresh = res.headers.get( 'X-WP-Nonce' );
		if ( fresh ) {
			this.nonce = fresh;
		}
		// A hard 403 after a harvest means the nonce genuinely rotated past
		// the grace tick: retry exactly once with the freshened nonce. A
		// second 403 is a real auth failure and surfaces to the caller.
		if ( res.status === 403 && fresh ) {
			res = await doFetch();
		}
		return res;
	}

	_slice( index ) {
		const start = index * this.chunkBytes;
		return this.file.slice(
			start,
			Math.min( start + this.chunkBytes, this.file.size )
		);
	}

	async _digestWholeFile() {
		// Streaming, ordered, O(1) memory: one chunk resident at a time, with
		// a yield between blocks so a multi-GB hash never freezes the UI.
		const h = new Sha256();
		for ( let i = 0; i < this.totalChunks; i++ ) {
			if ( this.cancelled ) {
				return '';
			}
			const buf = await this._slice( i ).arrayBuffer();
			h.update( new Uint8Array( buf ) );
			await tick();
		}
		return h.hex();
	}

	async _sendChunk( index ) {
		const blob = this._slice( index );
		const buf = await blob.arrayBuffer();
		const chunkSha = await sha256Hex( buf );

		// OPT-5: single-chunk upload — resolve the whole-file hash promise with
		// this chunk's SHA-256 (they are identical). _singleChunkHashResolve is
		// nulled immediately so a retry after a failed fetch doesn't double-resolve.
		if ( this._singleChunkHashResolve ) {
			this._singleChunkHashResolve( chunkSha );
			this._singleChunkHashResolve = null;
		}

		const form = new FormData();
		form.append( 'uploadId', this.uploadId );
		form.append( 'chunkIndex', String( index ) );
		form.append( 'totalChunks', String( this.totalChunks ) );
		form.append( 'fileName', this.file.name );
		form.append( 'mimeType', this.file.type || 'application/octet-stream' );
		form.append( 'destination', this.destination );
		form.append( 'chunkSha256', chunkSha );
		form.append( 'fileSize', String( this.file.size ) );
		form.append( 'chunk', blob, `${ this.file.name }.part${ index }` );

		const res = await this._fetch( '/chunk', { method: 'POST', body: form } );
		if ( ! res.ok ) {
			const body = await res.json().catch( () => ( {} ) );
			const err = new Error(
				body?.message || `Chunk ${ index } failed (HTTP ${ res.status })`
			);
			err.status = res.status;
			throw err;
		}
		return res.json();
	}

	async _sendWithRetry( index ) {
		let attempt = 0;
		// eslint-disable-next-line no-constant-condition
		while ( true ) {
			if ( this.cancelled ) {
				throw new Error( 'cancelled' );
			}
			try {
				return await this._sendChunk( index );
			} catch ( e ) {
				// Terminal client errors won't change on retry — a
				// disallowed type, malformed id, or session conflict is
				// permanent. Only 408 (timeout) and 429 (rate limit) among
				// 4xx are worth retrying; everything else 4xx fails fast.
				const s = e.status | 0;
				const terminal =
					s >= 400 && s < 500 && s !== 408 && s !== 429;
				attempt++;
				if ( terminal || attempt >= this.maxRetries ) {
					throw e;
				}
				await new Promise( ( r ) =>
					setTimeout( r, Math.min( 5000, 250 * 2 ** attempt ) )
				);
			}
		}
	}

	_recordTiming( ms ) {
		this._times.push( ms );
		if ( this._times.length > 5 ) {
			this._times.shift();
		}
	}

	_progressSnapshot() {
		const avg =
			this._times.reduce( ( a, b ) => a + b, 0 ) /
			( this._times.length || 1 );
		const remaining = this.totalChunks - this._done;
		// Divide by the number of workers ACTUALLY in flight, not the
		// configured ceiling. At the tail (remaining < concurrency) the
		// configured value under-counts the wall-clock and the ETA reads low.
		const lanes = Math.max( 1, Math.min( this.concurrency, remaining ) );
		const etaMs = avg * Math.ceil( remaining / lanes );
		const elapsed = ( performance.now() - this._startedAt ) / 1000;
		const bytesDone = Math.min(
			this.file.size,
			this._done * this.chunkBytes
		);
		return {
			uploadId: this.uploadId,
			done: this._done,
			total: this.totalChunks,
			percent: Math.round( ( this._done / this.totalChunks ) * 100 ),
			etaSeconds: isFinite( etaMs ) ? Math.round( etaMs / 1000 ) : null,
			speedMbps: elapsed > 0 ? bytesDone / 1048576 / elapsed : 0,
		};
	}

	async start() {
		this._startedAt = performance.now();
		this._saveResumable();

		// OPT-5: for a fresh single-chunk upload the per-chunk SHA-256 IS the
		// whole-file SHA-256. Skip the second read-and-hash pass and resolve
		// _hashPromise from _sendChunk() once the chunk hash is computed.
		// Fall back to _digestWholeFile() when the chunk was already received
		// (resume path) or when there are multiple chunks.
		if ( this.totalChunks === 1 && ! this._received.has( 0 ) ) {
			this._hashPromise = new Promise( ( resolve ) => {
				this._singleChunkHashResolve = resolve;
			} );
		} else {
			// Kick the whole-file digest off in parallel with the send loop.
			// Both pass over the file; uploads are far slower than hashing, so
			// the digest is ready well before /finalize.
			this._hashPromise = this._digestWholeFile();
		}

		// FEAT-1: skip chunks already confirmed by the server (resume path).
		const queue = Array.from( { length: this.totalChunks }, ( _, i ) => i )
			.filter( ( i ) => ! this._received.has( i ) );

		const worker = async () => {
			while ( queue.length && ! this.cancelled ) {
				// FEAT-6: wait if paused before pulling from the queue.
				await this._waitIfPaused();
				if ( this.cancelled ) {
					break;
				}
				const index = queue.shift();
				if ( index === undefined ) {
					break;
				}
				const t0 = performance.now();
				await this._sendWithRetry( index );
				this._recordTiming( performance.now() - t0 );
				this._done++;
				this._emit( 'progress', this._progressSnapshot() );
			}
		};

		try {
			await Promise.all(
				Array.from( { length: this.concurrency }, () => worker() )
			);
			if ( this.cancelled ) {
				return;
			}
			const result = await this._finalize();
			this._clearResumable();
			this._emit( 'complete', result );
		} catch ( e ) {
			if ( e.message !== 'cancelled' ) {
				this._clearResumable();
				this._emit( 'error', e );
			}
		}
	}

	async _finalize() {
		const digest = await this._hashPromise;
		const res = await this._fetch( '/finalize', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( {
				uploadId: this.uploadId,
				fileName: this.file.name,
				mimeType: this.file.type || 'application/octet-stream',
				destination: this.destination,
				totalSha256: digest,
				fileSize: this.file.size,
			} ),
		} );
		if ( ! res.ok ) {
			const body = await res.json().catch( () => ( {} ) );
			throw new Error(
				body?.message || `Finalize failed (HTTP ${ res.status })`
			);
		}
		const { jobId } = await res.json();
		return this._pollFinalize( jobId );
	}

	async _pollFinalize( jobId ) {
		// Poll every 2s. Two guards against an indefinite spin:
		//   - PENDING_STALL: the job never leaves 'pending' → the background
		//     runner (WP-Cron / Action Scheduler) isn't firing. Surface a
		//     specific, actionable message instead of hanging silently.
		//   - HARD_CAP: an absolute ceiling so a wedged 'running' job (e.g.
		//     the worker died after the last progress write) can't poll
		//     forever. The cap is generous — multi-GB assembly is slow.
		const PENDING_STALL_MS = 90000;
		const HARD_CAP_MS = 8 * 3600 * 1000;
		const startedAt = performance.now();
		let leftPending = false;

		// eslint-disable-next-line no-constant-condition
		while ( true ) {
			if ( this.cancelled ) {
				throw new Error( 'cancelled' );
			}
			const elapsed = performance.now() - startedAt;
			if ( ! leftPending && elapsed > PENDING_STALL_MS ) {
				const boot = window.CFChunkedUpload || {};
				const msg = boot.hostInfo?.wpCronDisabled
					? 'Assembly has not started. WP-Cron is disabled on this site — ensure a system cron is calling wp-cron.php on a schedule.'
					: 'Assembly has not started after 90 seconds. On low-traffic sites this can happen if no page load has fired WP-Cron yet. You can reload the page to check progress, or wait a little longer.';
				throw new Error( msg );
			}
			if ( elapsed > HARD_CAP_MS ) {
				throw new Error(
					'Assembly timed out. The file may be too large for this ' +
						'host, or the background worker stopped. Check the ' +
						'server before retrying.'
				);
			}

			await new Promise( ( r ) => setTimeout( r, 2000 ) );
			const res = await this._fetch( `/finalize-status/${ jobId }` );
			if ( ! res.ok ) {
				continue;
			}
			const rec = await res.json();
			if ( rec.status !== 'pending' ) {
				leftPending = true;
			}
			if ( rec.status === 'complete' ) {
				return rec.result;
			}
			if ( rec.status === 'error' ) {
				throw new Error( rec.error?.message || 'Assembly failed.' );
			}
			this._emit( 'progress', {
				...this._progressSnapshot(),
				phase: 'assembling',
			} );
		}
	}
}

export { uuidv4 };
