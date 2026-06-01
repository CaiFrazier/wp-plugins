/**
 * Settings app — Media → Chunked Upload.
 *
 * Live host-limits readout (so the admin sees exactly what the chunker is
 * working around) plus the chunk/importer settings. Server clamps chunk size
 * to the host ceiling on save and returns a notice; we surface it inline
 * using WP notice markup. No custom color — WP admin palette only.
 */
import { createRoot, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { boot, formatBytes } from './lib';

async function saveSettings( restRoot, payload ) {
	const cfg = boot();
	const res = await fetch( restRoot.replace( /\/$/, '' ) + '/settings', {
		method: 'POST',
		credentials: 'same-origin',
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': cfg.nonce,
		},
		body: JSON.stringify( payload ),
	} );
	// Harvest the rolled nonce like Uploader does, so a second save in a
	// long-lived settings tab doesn't 403 on a ticked-over nonce.
	const fresh = res.headers.get( 'X-WP-Nonce' );
	if ( fresh && window.CFChunkedUpload ) {
		window.CFChunkedUpload.nonce = fresh;
	}
	if ( ! res.ok ) {
		throw new Error( `Save failed (HTTP ${ res.status })` );
	}
	return res.json();
}

function HostCard( { host } ) {
	const execLabel = __( 'Max execution time', 'cf-chunked-upload' );
	const rows = [
		[ __( 'Max upload filesize', 'cf-chunked-upload' ), host.upload_max_filesize, 'bytes' ],
		[ __( 'Max POST size', 'cf-chunked-upload' ), host.post_max_size, 'bytes' ],
		[ __( 'Memory limit', 'cf-chunked-upload' ), host.memory_limit, 'bytes' ],
		[ execLabel, host.max_execution_time, 'seconds' ],
		[ __( 'Free disk space', 'cf-chunked-upload' ), host.free_disk_space, 'bytes' ],
	];
	const fmt = ( val, kind ) => {
		if ( val == null ) {
			return '—';
		}
		if ( kind === 'seconds' ) {
			return val === 0
				? __( 'Unlimited', 'cf-chunked-upload' )
				: `${ val }s`;
		}
		return formatBytes( val );
	};
	return (
		<div className="cf-cu-hostcard">
			<table className="widefat striped">
				<tbody>
					{ rows.map( ( [ label, val, kind ] ) => (
						<tr key={ label }>
							<th scope="row">{ label }</th>
							<td>{ fmt( val, kind ) }</td>
						</tr>
					) ) }
				</tbody>
			</table>

			{ host.wpCronDisabled && (
				<div className="notice notice-warning inline">
					<p>
						{ __(
							'WP-Cron is disabled (DISABLE_WP_CRON). Assembly jobs run on the task runner — ensure a system cron is calling wp-cron.php, or large-file finalization will never complete.',
							'cf-chunked-upload'
						) }
					</p>
				</div>
			) }
			<p className="description">
				{ __(
					'Chunked upload is active. Files larger than the threshold are split automatically — your effective upload limit is now your available disk space.',
					'cf-chunked-upload'
				) }
			</p>
			{ host.isNginx && (
				<div className="notice notice-warning inline">
					<p>
						{ __(
							'Nginx detected. Add this server block so the temp chunk directory is never web-served:',
							'cf-chunked-upload'
						) }
					</p>
					<code>
						location ~ /wp-content/cf-chunks/ {'{'} deny all; return
						403; {'}'}
					</code>
				</div>
			) }
		</div>
	);
}

function App() {
	const cfg = boot();
	const [ form, setForm ] = useState( cfg.settings || {} );
	const [ notices, setNotices ] = useState( [] );
	const [ saving, setSaving ] = useState( false );
	const [ saved, setSaved ] = useState( false );

	const NUMERIC = [ 'chunk_size_mb', 'concurrency', 'threshold_mb', 'max_retries', 'cleanup_age_hours', 'chunks_per_minute', 'per_user_quota_gb', 'max_concurrent_uploads_per_user', 'imports_retention_days' ];
	const set = ( k ) => ( e ) => {
		const v = NUMERIC.includes( k )
			? parseInt( e.target.value, 10 ) || 0
			: e.target.value;
		setForm( { ...form, [ k ]: v } );
	};

	const submit = async ( e ) => {
		e.preventDefault();
		setSaving( true );
		setSaved( false );
		try {
			const out = await saveSettings( cfg.restRoot, form );
			setForm( out.settings );
			setNotices( out.notices || [] );
			setSaved( true );
			setTimeout( () => setSaved( false ), 3000 );
		} catch ( err ) {
			setNotices( [ err.message ] );
		} finally {
			setSaving( false );
		}
	};

	return (
		<form onSubmit={ submit }>
			<HostCard host={ cfg.hostInfo || {} } />

			{ notices.map( ( n, i ) => (
				<div key={ i } className="notice notice-warning inline">
					<p>{ n }</p>
				</div>
			) ) }
			{ saved && (
				<div className="notice notice-success inline">
					<p>{ __( 'Settings saved.', 'cf-chunked-upload' ) }</p>
				</div>
			) }

			<table className="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row">
							<label htmlFor="cf-cu-chunk">
								{ __( 'Chunk size (MB)', 'cf-chunked-upload' ) }
							</label>
						</th>
						<td>
							<input
								id="cf-cu-chunk"
								type="number"
								min="1"
								max="1024"
								value={ form.chunk_size_mb || 8 }
								onChange={ set( 'chunk_size_mb' ) }
							/>
							<p className="description">
								{ __(
									'Each chunk is one HTTP request. 8 MB is safe on virtually all hosts; we clamp to the host limit automatically.',
									'cf-chunked-upload'
								) }
							</p>
							{ cfg.hostInfo?.chunk_ceiling_bytes > 0 && (
								<p className="description">
									{ sprintf(
										// translators: %s is a byte size like "7 MB".
										__( 'Your host ceiling: %s per request.', 'cf-chunked-upload' ),
										formatBytes( cfg.hostInfo.chunk_ceiling_bytes )
									) }
								</p>
							) }
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label htmlFor="cf-cu-conc">
								{ __( 'Concurrent uploads', 'cf-chunked-upload' ) }
							</label>
						</th>
						<td>
							<input
								id="cf-cu-conc"
								type="number"
								min="1"
								max="6"
								value={ form.concurrency || 3 }
								onChange={ set( 'concurrency' ) }
							/>
							<p className="description">
								{ __( 'How many chunks to send at the same time. Higher is faster on reliable connections; set to 1 for maximum reliability on unstable connections.', 'cf-chunked-upload' ) }
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label htmlFor="cf-cu-thr">
								{ __( 'Chunking threshold (MB)', 'cf-chunked-upload' ) }
							</label>
						</th>
						<td>
							<input
								id="cf-cu-thr"
								type="number"
								min="1"
								value={ form.threshold_mb || 10 }
								onChange={ set( 'threshold_mb' ) }
							/>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label htmlFor="cf-cu-ret">
								{ __( 'Max retries per chunk', 'cf-chunked-upload' ) }
							</label>
						</th>
						<td>
							<input
								id="cf-cu-ret"
								type="number"
								min="1"
								max="10"
								value={ form.max_retries || 3 }
								onChange={ set( 'max_retries' ) }
							/>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label htmlFor="cf-cu-impdir">
								{ __( 'Imports directory', 'cf-chunked-upload' ) }
							</label>
						</th>
						<td>
							<input
								id="cf-cu-impdir"
								type="text"
								className="regular-text"
								placeholder="wp-content/cf-imports/"
								value={ form.imports_dir || '' }
								onChange={ set( 'imports_dir' ) }
							/>
							<p className="description">
								{ __(
									'Where importer uploads are saved. Must be inside wp-content; created automatically and not web-accessible. Leave blank for the default.',
									'cf-chunked-upload'
								) }
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label htmlFor="cf-cu-exts">
								{ __( 'Allowed file types', 'cf-chunked-upload' ) }
							</label>
						</th>
						<td>
							<input
								id="cf-cu-exts"
								type="text"
								className="regular-text"
								placeholder="zip, tar, gz, sql"
								value={
									Array.isArray( form.allowed_extensions )
										? form.allowed_extensions.join( ', ' )
										: ''
								}
								onChange={ ( e ) =>
									setForm( {
										...form,
										allowed_extensions: e.target.value
											.split( ',' )
											.map( ( s ) => s.trim() )
											.filter( Boolean ),
									} )
								}
							/>
							<p className="description">
								{ __(
									'Comma-separated extensions the importer accepts. Leave blank to allow all non-executable types. Executable/server-interpreted files (.php, .phtml, .phar, .asp, .jsp, .cgi, .htaccess, …) are always rejected. Files are also content-inspected after upload.',
									'cf-chunked-upload'
								) }
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label htmlFor="cf-cu-cap">
								{ __( 'Importer access', 'cf-chunked-upload' ) }
							</label>
						</th>
						<td>
							<select
								id="cf-cu-cap"
								value={ form.importer_capability || 'manage_options' }
								onChange={ set( 'importer_capability' ) }
							>
								<option value="manage_options">
									{ __( 'Administrators only', 'cf-chunked-upload' ) }
								</option>
								<option value="upload_files">
									{ __(
										'Anyone who can upload files',
										'cf-chunked-upload'
									) }
								</option>
							</select>
							<p className="description">
								{ __(
									'Who can use the standalone importer. Media-library chunking always uses the standard upload capability and is unaffected.',
									'cf-chunked-upload'
								) }
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label htmlFor="cf-cu-coll">
								{ __( 'Filename collision policy', 'cf-chunked-upload' ) }
							</label>
						</th>
						<td>
							<select
								id="cf-cu-coll"
								value={ form.collision_policy || 'timestamp' }
								onChange={ set( 'collision_policy' ) }
							>
								<option value="timestamp">
									{ __( 'Rename with timestamp', 'cf-chunked-upload' ) }
								</option>
								<option value="reject">
									{ __( 'Reject', 'cf-chunked-upload' ) }
								</option>
								<option value="overwrite">
									{ __( 'Overwrite (destructive)', 'cf-chunked-upload' ) }
								</option>
							</select>
							<p className="description">
								{ __(
									'What to do when a file with the same name already exists in the imports directory. Overwrite is destructive — use with care in backup workflows.',
									'cf-chunked-upload'
								) }
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label htmlFor="cf-cu-age">
								{ __( 'Stale chunk cleanup (hours)', 'cf-chunked-upload' ) }
							</label>
						</th>
						<td>
							<input
								id="cf-cu-age"
								type="number"
								min="1"
								max="168"
								value={ form.cleanup_age_hours || 2 }
								onChange={ set( 'cleanup_age_hours' ) }
							/>
							<p className="description">
								{ __( 'Incomplete or abandoned uploads leave temporary chunk files on the server. They are automatically deleted after this many hours of inactivity.', 'cf-chunked-upload' ) }
							</p>
						</td>
					</tr>

					{ /* --- Resource Limits --- */ }
					<tr>
						<th colSpan="2" scope="row" style={ { paddingTop: '1.5em', paddingBottom: '0.25em', borderTop: '1px solid #ddd' } }>
							<strong>{ __( 'Resource limits', 'cf-chunked-upload' ) }</strong>
						</th>
					</tr>
					<tr>
						<th scope="row">
							<label htmlFor="cf-cu-cpm">
								{ __( 'Rate limit (chunks / min)', 'cf-chunked-upload' ) }
							</label>
						</th>
						<td>
							<input
								id="cf-cu-cpm"
								type="number"
								min="0"
								max="300"
								value={ form.chunks_per_minute ?? 60 }
								onChange={ set( 'chunks_per_minute' ) }
							/>
							<p className="description">
								{ __(
									'Maximum chunk requests per user per minute. 0 disables the limit (single-user or trusted installs only).',
									'cf-chunked-upload'
								) }
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label htmlFor="cf-cu-quota">
								{ __( 'Per-user storage quota (GB)', 'cf-chunked-upload' ) }
							</label>
						</th>
						<td>
							<input
								id="cf-cu-quota"
								type="number"
								min="0"
								max="10000"
								value={ form.per_user_quota_gb ?? 50 }
								onChange={ set( 'per_user_quota_gb' ) }
							/>
							<p className="description">
								{ __(
									'Maximum in-progress upload storage per user across all active sessions. 0 disables the quota.',
									'cf-chunked-upload'
								) }
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label htmlFor="cf-cu-maxconc">
								{ __( 'Max concurrent uploads per user', 'cf-chunked-upload' ) }
							</label>
						</th>
						<td>
							<input
								id="cf-cu-maxconc"
								type="number"
								min="0"
								max="20"
								value={ form.max_concurrent_uploads_per_user ?? 5 }
								onChange={ set( 'max_concurrent_uploads_per_user' ) }
							/>
							<p className="description">
								{ __(
									'How many simultaneous upload sessions a single user may have active. 0 disables the cap.',
									'cf-chunked-upload'
								) }
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label htmlFor="cf-cu-impret">
								{ __( 'Import file retention (days)', 'cf-chunked-upload' ) }
							</label>
						</th>
						<td>
							<input
								id="cf-cu-impret"
								type="number"
								min="0"
								max="365"
								value={ form.imports_retention_days ?? 0 }
								onChange={ set( 'imports_retention_days' ) }
							/>
							<p className="description">
								{ __(
									'Automatically delete files in the imports directory after this many days. 0 disables auto-deletion.',
									'cf-chunked-upload'
								) }
							</p>
						</td>
					</tr>
				</tbody>
			</table>

			<p className="submit">
				<button
					type="submit"
					className="button button-primary"
					disabled={ saving }
				>
					{ saving
						? __( 'Saving…', 'cf-chunked-upload' )
						: __( 'Save Changes', 'cf-chunked-upload' ) }
				</button>
			</p>
		</form>
	);
}

const mount = document.getElementById( 'cf-chunked-upload-settings' );
if ( mount ) {
	createRoot( mount ).render( <App /> );
}
