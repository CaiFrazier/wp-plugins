/**
 * Standalone importer app — Tools → Import Large File.
 *
 * Drop zone + active/completed lists. Uploads go straight to the server
 * filesystem (destination='import'), never the media library. Native WP
 * admin look: .button classes, WP notice markup, muted text — no colored
 * cards (see ROADMAP design philosophy).
 */
import { createRoot, useState, useRef, useEffect, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Uploader } from './Uploader';
import { boot, guardUnload, formatBytes, formatDuration } from './lib';

// ---------------------------------------------------------------------------
// FEAT-1: localStorage resume helpers
// ---------------------------------------------------------------------------

const LS_PREFIX = 'cf-cu-resumable-';

function loadResumable() {
	const saved = [];
	try {
		for ( let i = 0; i < localStorage.length; i++ ) {
			const key = localStorage.key( i );
			if ( key && key.startsWith( LS_PREFIX ) ) {
				const raw = localStorage.getItem( key );
				if ( raw ) {
					const entry = JSON.parse( raw );
					// Discard entries older than 48 h (well beyond the server
					// cleanup window default of 2 h).
					if ( entry && Date.now() - ( entry.savedAt || 0 ) < 48 * 3600 * 1000 ) {
						saved.push( entry );
					} else {
						localStorage.removeItem( key );
					}
				}
			}
		}
	} catch ( _ ) {}
	return saved;
}

function discardResumable( uploadId ) {
	try {
		// We don't have the file anymore so we can't recompute the fingerprint.
		// Scan all cf-cu-resumable-* keys and remove the one with this uploadId.
		for ( let i = localStorage.length - 1; i >= 0; i-- ) {
			const key = localStorage.key( i );
			if ( key && key.startsWith( LS_PREFIX ) ) {
				const raw = localStorage.getItem( key );
				if ( raw ) {
					const entry = JSON.parse( raw );
					if ( entry && entry.uploadId === uploadId ) {
						localStorage.removeItem( key );
						break;
					}
				}
			}
		}
	} catch ( _ ) {}
}

// ---------------------------------------------------------------------------
// Core hook
// ---------------------------------------------------------------------------

function useImporter() {
	const [ active, setActive ] = useState( [] );
	const [ pending, setPending ] = useState( [] ); // FEAT-7: queued files
	const [ done, setDone ] = useState( [] );
	const [ resumable, setResumable ] = useState( () => loadResumable() ); // FEAT-1
	const uploaders = useRef( new Map() );
	const cfg = boot();
	const maxConcurrent = cfg.maxConcurrentImports || 5;

	useEffect(
		() => guardUnload( () => uploaders.current.size > 0 ),
		[]
	);

	const _startUpload = useCallback(
		( file, uploaderOverride ) => {
			const u =
				uploaderOverride ||
				new Uploader( file, {
					restRoot: cfg.restRoot,
					nonce: cfg.nonce,
					destination: 'import',
					chunkBytes: cfg.chunkBytes,
					concurrency: cfg.concurrency,
					maxRetries: cfg.maxRetries,
				} );

			uploaders.current.set( u.uploadId, u );

			const patch = ( id, next ) =>
				setActive( ( rows ) =>
					rows.map( ( r ) => ( r.id === id ? { ...r, ...next } : r ) )
				);

			setActive( ( rows ) => [
				...rows,
				{
					id: u.uploadId,
					name: file ? file.name : u.file?.name || '—',
					size: file ? file.size : u.file?.size || 0,
					percent: 0,
					eta: null,
					speed: 0,
					phase: 'uploading',
					paused: false,
				},
			] );

			u.on( 'progress', ( p ) =>
				patch( u.uploadId, {
					percent: p.percent,
					eta: p.etaSeconds,
					speed: p.speedMbps,
					phase: p.phase || 'uploading',
				} )
			);
			u.on( 'complete', ( result ) => {
				uploaders.current.delete( u.uploadId );
				setActive( ( rows ) => rows.filter( ( r ) => r.id !== u.uploadId ) );
				setDone( ( rows ) => [
					{
						id: u.uploadId,
						name: file ? file.name : u.file?.name || '—',
						size: file ? file.size : u.file?.size || 0,
						result,
						// FEAT-4: include integrity info from finalize result
						sha256: result?.sha256 || null,
						integrityVerified: result?.integrityVerified ?? false,
					},
					...rows,
				] );
				// FEAT-7: dequeue next pending file when a slot opens.
				setPending( ( q ) => {
					if ( q.length === 0 ) return q;
					const [ next, ...rest ] = q;
					next?._uploader ? _startUpload( next._file, next._uploader ) : _startUpload( next );
					return rest;
				} );
			} );
			u.on( 'error', ( err ) => {
				uploaders.current.delete( u.uploadId );
				patch( u.uploadId, { error: err.message, phase: 'error' } );
				// Dequeue next on error too.
				setPending( ( q ) => {
					if ( q.length === 0 ) return q;
					const [ next, ...rest ] = q;
					next?._uploader ? _startUpload( next._file, next._uploader ) : _startUpload( next );
					return rest;
				} );
			} );

			u.start();
		},
		// eslint-disable-next-line react-hooks/exhaustive-deps
		[ cfg.restRoot, cfg.nonce, cfg.chunkBytes, cfg.concurrency, cfg.maxRetries ]
	);

	// FEAT-7: start immediately if a slot is free, else queue.
	const begin = useCallback(
		( file ) => {
			if ( uploaders.current.size < maxConcurrent ) {
				_startUpload( file );
			} else {
				setPending( ( q ) => [ ...q, file ] );
			}
		},
		[ _startUpload, maxConcurrent ]
	);

	const cancel = useCallback( ( id ) => {
		const u = uploaders.current.get( id );
		if ( u ) {
			u.cancel();
			uploaders.current.delete( id );
		}
		setActive( ( rows ) => rows.filter( ( r ) => r.id !== id ) );
		// Dequeue next if a slot opened.
		setPending( ( q ) => {
			if ( q.length === 0 ) return q;
			const [ next, ...rest ] = q;
			next?._uploader ? _startUpload( next._file, next._uploader ) : _startUpload( next );
			return rest;
		} );
	}, [ _startUpload ] );

	// FEAT-6: toggle pause/resume.
	const togglePause = useCallback( ( id ) => {
		const u = uploaders.current.get( id );
		if ( ! u ) return;
		if ( u._paused ) {
			u.resume();
			setActive( ( rows ) =>
				rows.map( ( r ) => ( r.id === id ? { ...r, paused: false } : r ) )
			);
		} else {
			u.pause();
			setActive( ( rows ) =>
				rows.map( ( r ) => ( r.id === id ? { ...r, paused: true } : r ) )
			);
		}
	}, [] );

	// FEAT-7: remove from pending queue.
	const dequeue = useCallback( ( index ) => {
		setPending( ( q ) => q.filter( ( _, i ) => i !== index ) );
	}, [] );

	// FEAT-1: resume a saved upload.
	const resumeUpload = useCallback(
		async ( saved, file ) => {
			// Remove from the resumable list immediately to prevent double-start.
			setResumable( ( list ) => list.filter( ( s ) => s.uploadId !== saved.uploadId ) );
			const uploader = await Uploader.resume( file, saved, {
				restRoot: cfg.restRoot,
				nonce: cfg.nonce,
				destination: saved.destination,
				chunkBytes: cfg.chunkBytes,
				concurrency: cfg.concurrency,
				maxRetries: cfg.maxRetries,
			} );
			if ( uploaders.current.size < maxConcurrent ) {
				_startUpload( file, uploader );
			} else {
				// Re-add to the pending queue; wrap with the pre-built uploader.
				setPending( ( q ) => [ ...q, { _file: file, _uploader: uploader } ] );
			}
		},
		[ _startUpload, cfg, maxConcurrent ]
	);

	const discardResume = useCallback( ( uploadId ) => {
		discardResumable( uploadId );
		setResumable( ( list ) => list.filter( ( s ) => s.uploadId !== uploadId ) );
	}, [] );

	return { active, pending, done, resumable, begin, cancel, togglePause, dequeue, resumeUpload, discardResume };
}

// ---------------------------------------------------------------------------
// Components
// ---------------------------------------------------------------------------

function DropZone( { onFiles } ) {
	const [ over, setOver ] = useState( false );
	const inputRef = useRef( null );
	return (
		<div
			className={ `cf-cu-drop ${ over ? 'is-over' : '' }` }
			onDragOver={ ( e ) => {
				e.preventDefault();
				setOver( true );
			} }
			onDragLeave={ () => setOver( false ) }
			onDrop={ ( e ) => {
				e.preventDefault();
				setOver( false );
				onFiles( Array.from( e.dataTransfer.files ) );
			} }
			onClick={ () => inputRef.current?.click() }
		>
			<p>{ __( 'Drop files here', 'cf-chunked-upload' ) }</p>
			<p className="description">
				{ __( 'or', 'cf-chunked-upload' ) }{ ' ' }
				<button type="button" className="button">
					{ __( 'click to browse', 'cf-chunked-upload' ) }
				</button>
			</p>
			<input
				ref={ inputRef }
				type="file"
				multiple
				style={ { display: 'none' } }
				onChange={ ( e ) => onFiles( Array.from( e.target.files ) ) }
			/>
		</div>
	);
}

function App() {
	const { active, pending, done, resumable, begin, cancel, togglePause, dequeue, resumeUpload, discardResume } =
		useImporter();
	const [ copiedId, setCopiedId ] = useState( null );
	// FEAT-1: file picker ref for resume flow.
	const resumePickerRef = useRef( null );
	const resumeTargetRef = useRef( null ); // which saved entry is being resumed

	const copyPath = async ( id, value ) => {
		if ( navigator.clipboard ) {
			await navigator.clipboard.writeText( value );
		} else {
			const el = document.createElement( 'textarea' );
			el.value = value;
			el.style.position = 'fixed';
			el.style.opacity = '0';
			document.body.appendChild( el );
			el.select();
			document.execCommand( 'copy' );
			document.body.removeChild( el );
		}
		setCopiedId( id );
		setTimeout( () => setCopiedId( null ), 2000 );
	};

	const isNginx = boot().hostInfo?.isNginx;

	return (
		<div className="cf-cu-importer">
			{ isNginx && (
				<div className="notice notice-warning inline">
					<p>
						{ __(
							'Nginx detected. The temp chunk directory is not protected by .htaccess on Nginx — add this server block so it is never web-served:',
							'cf-chunked-upload'
						) }
					</p>
					<code>
						location ~ /wp-content/cf-chunks/ {'{'} deny all; return
						403; {'}'}
					</code>
				</div>
			) }

			{ /* FEAT-1: Resumable uploads section */ }
			{ resumable.length > 0 && (
				<>
					<h2>{ __( 'Resume previous uploads', 'cf-chunked-upload' ) }</h2>
					<table className="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th>{ __( 'File', 'cf-chunked-upload' ) }</th>
								<th>{ __( 'Size', 'cf-chunked-upload' ) }</th>
								<th />
							</tr>
						</thead>
						<tbody>
							{ resumable.map( ( saved ) => (
								<tr key={ saved.uploadId }>
									<td>{ saved.fileName }</td>
									<td>{ formatBytes( saved.size ) }</td>
									<td>
										{ /* Hidden file picker — fires when user clicks Resume */ }
										<input
											ref={ resumePickerRef }
											type="file"
											style={ { display: 'none' } }
											onChange={ ( e ) => {
												const file = e.target.files?.[ 0 ];
												if ( file && resumeTargetRef.current ) {
													resumeUpload( resumeTargetRef.current, file );
													resumeTargetRef.current = null;
												}
												e.target.value = '';
											} }
										/>
										<button
											type="button"
											className="button button-primary"
											onClick={ () => {
												resumeTargetRef.current = saved;
												resumePickerRef.current?.click();
											} }
										>
											{ __( 'Resume', 'cf-chunked-upload' ) }
										</button>{ ' ' }
										<button
											type="button"
											className="button button-secondary"
											onClick={ () => discardResume( saved.uploadId ) }
										>
											{ __( 'Discard', 'cf-chunked-upload' ) }
										</button>
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
				</>
			) }

			<DropZone onFiles={ ( files ) => files.forEach( begin ) } />

			{ /* FEAT-7: Queued (pending) files */ }
			{ pending.length > 0 && (
				<>
					<h2>{ __( 'Queued', 'cf-chunked-upload' ) }</h2>
					<table className="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th>{ __( 'File', 'cf-chunked-upload' ) }</th>
								<th>{ __( 'Size', 'cf-chunked-upload' ) }</th>
								<th />
							</tr>
						</thead>
						<tbody>
							{ pending.map( ( item, i ) => {
								const name = item._file?.name || item.name || '—';
								const size = item._file?.size || item.size || 0;
								return (
									<tr key={ i }>
										<td>{ name }</td>
										<td>{ formatBytes( size ) }</td>
										<td>
											<button
												type="button"
												className="button button-secondary"
												onClick={ () => dequeue( i ) }
											>
												{ __( 'Remove', 'cf-chunked-upload' ) }
											</button>
										</td>
									</tr>
								);
							} ) }
						</tbody>
					</table>
				</>
			) }

			{ active.length > 0 && (
				<table className="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th>{ __( 'File', 'cf-chunked-upload' ) }</th>
							<th>{ __( 'Size', 'cf-chunked-upload' ) }</th>
							<th>{ __( 'Progress', 'cf-chunked-upload' ) }</th>
							<th>{ __( 'ETA', 'cf-chunked-upload' ) }</th>
							<th>{ __( 'Speed', 'cf-chunked-upload' ) }</th>
							<th />
						</tr>
					</thead>
					<tbody>
						{ active.map( ( r ) => (
							<tr key={ r.id }>
								<td>{ r.name }</td>
								<td>{ formatBytes( r.size ) }</td>
								<td>
									<progress value={ r.percent } max="100" aria-label={ r.name } />{ ' ' }
									{ r.phase === 'assembling'
										? __( 'Assembling…', 'cf-chunked-upload' )
										: `${ r.percent }%` }
									{ r.error && (
										<span className="notice notice-error inline">
											{ r.error }
										</span>
									) }
								</td>
								<td>{ r.paused ? __( 'Paused', 'cf-chunked-upload' ) : formatDuration( r.eta ) }</td>
								<td>{ r.paused ? '—' : ( r.speed ? `${ r.speed.toFixed( 1 ) } MB/s` : '—' ) }</td>
								<td>
									{ /* FEAT-6: pause/resume button */ }
									{ r.phase !== 'assembling' && ! r.error && (
										<button
											type="button"
											className="button button-secondary"
											onClick={ () => togglePause( r.id ) }
										>
											{ r.paused
												? __( 'Resume', 'cf-chunked-upload' )
												: __( 'Pause', 'cf-chunked-upload' ) }
										</button>
									) }{ ' ' }
									<button
										type="button"
										className="button button-secondary"
										onClick={ () => cancel( r.id ) }
									>
										{ __( 'Cancel', 'cf-chunked-upload' ) }
									</button>
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }

			{ done.length > 0 && (
				<>
					<h2>{ __( 'Completed', 'cf-chunked-upload' ) }</h2>
					<table className="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th>{ __( 'File', 'cf-chunked-upload' ) }</th>
								<th>{ __( 'Size', 'cf-chunked-upload' ) }</th>
								<th>{ __( 'Saved to', 'cf-chunked-upload' ) }</th>
								{ /* FEAT-4: integrity column */ }
								<th>{ __( 'Integrity', 'cf-chunked-upload' ) }</th>
							</tr>
						</thead>
						<tbody>
							{ done.map( ( r ) => (
								<tr key={ r.id }>
									<td>{ r.name }</td>
									<td>{ formatBytes( r.size ) }</td>
									<td>
										<code>{ r.result?.path }</code>{ ' ' }
										<button
											type="button"
											className="button-link"
											onClick={ () =>
												copyPath( r.id, r.result?.path || '' )
											}
										>
											{ copiedId === r.id
												? __( 'Copied!', 'cf-chunked-upload' )
												: __( 'Copy path', 'cf-chunked-upload' ) }
										</button>
									</td>
									{ /* FEAT-4: SHA-256 verification badge */ }
									<td>
										{ r.integrityVerified ? (
											<span
												title={ r.sha256 }
												style={ { cursor: 'pointer', color: 'green' } }
												onClick={ () =>
													r.sha256 && copyPath( r.id + '-sha', r.sha256 )
												}
											>
												{ copiedId === r.id + '-sha'
													? __( 'Copied!', 'cf-chunked-upload' )
													: '✓ SHA-256' }
											</span>
										) : (
											<span className="description">{ __( '—', 'cf-chunked-upload' ) }</span>
										) }
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
				</>
			) }

			<p className="description">
				{ __(
					'Files uploaded here are saved directly to the server filesystem and do not appear in the Media Library.',
					'cf-chunked-upload'
				) }
			</p>
		</div>
	);
}

const mount = document.getElementById( 'cf-chunked-upload-importer' );
if ( mount ) {
	createRoot( mount ).render( <App /> );
}
