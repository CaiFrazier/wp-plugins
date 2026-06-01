import { useState, useEffect, useCallback } from '@wordpress/element';
import { Button, Notice, Spinner, TextControl, SelectControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import apiFetch from '../../shared/api';
import bmeLog from '../../shared/logger';

const { apiNamespace } = window.bmeDiagnosticsData ?? {};

const LEVEL_COLOR = {
	debug: '#777',
	info:  '#2271b1',
	warn:  '#c44b00',
	error: '#cc1818',
};

export default function App() {
	const [ env, setEnv ] = useState( null );
	const [ envError, setEnvError ] = useState( null );

	const [ logEntries, setLogEntries ] = useState( [] );
	const [ logLoading, setLogLoading ] = useState( false );
	const [ logLevelFilter, setLogLevelFilter ] = useState( '' );
	const [ logSearch, setLogSearch ] = useState( '' );

	const [ selfTestPostId, setSelfTestPostId ] = useState( '' );
	const [ selfTestResult, setSelfTestResult ] = useState( null );
	const [ selfTestRunning, setSelfTestRunning ] = useState( false );

	const loadEnv = useCallback( async () => {
		try {
			const data = await apiFetch( { path: `/${ apiNamespace }/diagnostics/environment` } );
			setEnv( data );
			setEnvError( null );
		} catch ( e ) {
			setEnvError( e.message || 'Failed to load environment' );
			bmeLog.error( 'diagnostics', 'env load failed', { message: e.message } );
		}
	}, [] );

	const loadLog = useCallback( async () => {
		setLogLoading( true );
		try {
			const params = new URLSearchParams();
			params.set( 'lines', '500' );
			if ( logLevelFilter ) params.set( 'level', logLevelFilter );
			const data = await apiFetch( {
				path: `/${ apiNamespace }/diagnostics/log?${ params.toString() }`,
			} );
			setLogEntries( Array.isArray( data ) ? data : [] );
		} catch ( e ) {
			bmeLog.error( 'diagnostics', 'log load failed', { message: e.message } );
		} finally {
			setLogLoading( false );
		}
	}, [ logLevelFilter ] );

	useEffect( () => {
		loadEnv();
		loadLog();
	}, [ loadEnv, loadLog ] );

	const clearLog = async () => {
		if ( ! window.confirm( __( 'Clear all log entries?', 'cf-bulk-meta-editor' ) ) ) return;
		try {
			await apiFetch( {
				path: `/${ apiNamespace }/diagnostics/log`,
				method: 'DELETE',
			} );
			setLogEntries( [] );
		} catch ( e ) {
			bmeLog.error( 'diagnostics', 'log clear failed', { message: e.message } );
		}
	};

	const runSelfTest = async () => {
		const id = parseInt( selfTestPostId, 10 );
		if ( ! id ) return;
		setSelfTestRunning( true );
		setSelfTestResult( null );
		try {
			const result = await apiFetch( {
				path: `/${ apiNamespace }/diagnostics/self-test`,
				method: 'POST',
				data: { post_id: id },
			} );
			setSelfTestResult( result );
		} catch ( e ) {
			setSelfTestResult( { ok: false, stage: 'error', error: e.message } );
		} finally {
			setSelfTestRunning( false );
		}
	};

	const downloadLog = () => {
		const blob = new Blob( [ logEntries.map( ( e ) => JSON.stringify( e ) ).join( '\n' ) ], {
			type: 'application/x-ndjson',
		} );
		const url = URL.createObjectURL( blob );
		const a = document.createElement( 'a' );
		a.href = url;
		a.download = `bme-log-${ new Date().toISOString().slice( 0, 10 ) }.jsonl`;
		a.click();
		setTimeout( () => URL.revokeObjectURL( url ), 1000 );
	};

	const filteredEntries = logEntries.filter( ( e ) => {
		if ( ! logSearch ) return true;
		const hay = `${ e.channel } ${ e.message } ${ JSON.stringify( e.context ) }`.toLowerCase();
		return hay.includes( logSearch.toLowerCase() );
	} );

	return (
		<div className="bme-diagnostics">
			<h1>{ __( 'Bulk Meta Editor — Diagnostics', 'cf-bulk-meta-editor' ) }</h1>

			<EnvSection env={ env } error={ envError } onRefresh={ loadEnv } />

			<SelfTestSection
				postId={ selfTestPostId }
				onPostId={ setSelfTestPostId }
				running={ selfTestRunning }
				result={ selfTestResult }
				onRun={ runSelfTest }
			/>

			<div className="bme-card">
				<div className="bme-card-header">
					<h2>{ __( 'Server log', 'cf-bulk-meta-editor' ) }</h2>
					<div className="bme-card-actions">
						<Button variant="secondary" onClick={ loadLog }>{ __( 'Refresh', 'cf-bulk-meta-editor' ) }</Button>
						<Button variant="secondary" onClick={ downloadLog } disabled={ ! logEntries.length }>
							{ __( 'Download .jsonl', 'cf-bulk-meta-editor' ) }
						</Button>
						<Button isDestructive onClick={ clearLog }>{ __( 'Clear log', 'cf-bulk-meta-editor' ) }</Button>
					</div>
				</div>

				<div className="bme-log-filters">
					<SelectControl
						label={ __( 'Level', 'cf-bulk-meta-editor' ) }
						hideLabelFromVision
						value={ logLevelFilter }
						options={ [
							{ label: __( 'All levels', 'cf-bulk-meta-editor' ), value: '' },
							{ label: 'debug', value: 'debug' },
							{ label: 'info',  value: 'info' },
							{ label: 'warn',  value: 'warn' },
							{ label: 'error', value: 'error' },
						] }
						onChange={ setLogLevelFilter }
					/>
					<TextControl
						label={ __( 'Search', 'cf-bulk-meta-editor' ) }
						hideLabelFromVision
						placeholder={ __( 'Filter (channel, message, context)…', 'cf-bulk-meta-editor' ) }
						value={ logSearch }
						onChange={ setLogSearch }
					/>
				</div>

				{ logLoading && <Spinner /> }
				{ ! logLoading && filteredEntries.length === 0 && (
					<p className="bme-empty">{ __( 'No log entries match.', 'cf-bulk-meta-editor' ) }</p>
				) }
				<div className="bme-log-list">
					{ filteredEntries.map( ( e, i ) => (
						<div key={ i } className="bme-log-row">
							<span className="bme-log-time">{ e.ts }</span>
							<span className="bme-log-level" style={ { color: LEVEL_COLOR[ e.level ] } }>
								{ ( e.level || '' ).toUpperCase() }
							</span>
							<span className="bme-log-channel">{ e.channel }</span>
							<span className="bme-log-message">{ e.message }</span>
							{ e.context && Object.keys( e.context ).length > 0 && (
								<details className="bme-log-context">
									<summary>ctx</summary>
									<pre>{ JSON.stringify( e.context, null, 2 ) }</pre>
								</details>
							) }
						</div>
					) ) }
				</div>
			</div>
		</div>
	);
}

function EnvSection( { env, error, onRefresh } ) {
	if ( error ) return <Notice status="error">{ error }</Notice>;
	if ( ! env ) return <Spinner />;

	const rows = [
		[ 'Plugin version', env.plugin_version ],
		[ 'WordPress', env.wp_version ],
		[ 'PHP', env.php_version ],
		[ 'Memory limit', env.memory_limit ],
		[ 'Max execution time', env.max_execution_time ],
		[ 'Post max size', env.post_max_size ],
		[ 'BME_DEBUG constant', env.bme_debug_const === null ? '— not defined —' : String( env.bme_debug_const ) ],
		[ 'Log file path', env.log_path ?? '— uploads dir not writable —' ],
		[ 'Log dir writable', env.log_writable ? 'yes' : 'no' ],
	];

	return (
		<div className="bme-card">
			<div className="bme-card-header">
				<h2>{ __( 'Environment', 'cf-bulk-meta-editor' ) }</h2>
				<Button variant="secondary" onClick={ onRefresh }>{ __( 'Refresh', 'cf-bulk-meta-editor' ) }</Button>
			</div>

			<table className="bme-env-table">
				<tbody>
					{ rows.map( ( [ k, v ] ) => (
						<tr key={ k }>
							<th>{ k }</th>
							<td><code>{ String( v ?? '—' ) }</code></td>
						</tr>
					) ) }
				</tbody>
			</table>

			<h3>{ __( 'Detected SEO plugins', 'cf-bulk-meta-editor' ) }</h3>
			{ env.seo_plugins.length === 0 ? (
				<p>{ __( 'No known SEO plugin detected.', 'cf-bulk-meta-editor' ) }</p>
			) : (
				<ul className="bme-seo-list">
					{ env.seo_plugins.map( ( p ) => (
						<li key={ p.path }>
							<strong>{ p.slug }</strong> — <code>{ p.path }</code>
						</li>
					) ) }
				</ul>
			) }

			<h3>{ __( 'Active configuration', 'cf-bulk-meta-editor' ) }</h3>
			<table className="bme-env-table">
				<tbody>
					<tr><th>Meta title key</th><td><code>{ env.settings.meta_title_key || '—' }</code></td></tr>
					<tr><th>Meta description key</th><td><code>{ env.settings.meta_desc_key || '—' }</code></td></tr>
					<tr><th>Custom columns</th><td>
						{ env.settings.custom_columns.length === 0
							? '—'
							: env.settings.custom_columns.map( ( c ) => `${ c.label } (${ c.key })` ).join( ', ' ) }
					</td></tr>
					<tr><th>Enabled post types</th><td><code>{ env.enabled_post_types.join( ', ' ) }</code></td></tr>
					<tr><th>Allowed meta keys</th><td><code>{ env.allowed_keys.join( ', ' ) || '—' }</code></td></tr>
					<tr><th>Debug mode</th><td>{ env.settings.debug_mode ? 'on' : 'off' }</td></tr>
					<tr><th>Log level</th><td>{ env.settings.log_level }</td></tr>
				</tbody>
			</table>
		</div>
	);
}

function SelfTestSection( { postId, onPostId, running, result, onRun } ) {
	return (
		<div className="bme-card">
			<h2>{ __( 'Round-trip self-test', 'cf-bulk-meta-editor' ) }</h2>
			<p className="bme-card-help">
				{ __(
					'Writes a unique marker to the configured Meta Title key on the chosen post, reads it back, then restores the original value. Detects when another plugin (Yoast filters, ACF, custom save_post hooks) is silently mutating writes.',
					'cf-bulk-meta-editor'
				) }
			</p>

			<div className="bme-self-test-form">
				<TextControl
					label={ __( 'Post ID', 'cf-bulk-meta-editor' ) }
					value={ postId }
					onChange={ onPostId }
					type="number"
				/>
				<Button variant="primary" onClick={ onRun } disabled={ running || ! postId }>
					{ running ? __( 'Running…', 'cf-bulk-meta-editor' ) : __( 'Run self-test', 'cf-bulk-meta-editor' ) }
				</Button>
			</div>

			{ result && (
				<Notice status={ result.ok ? 'success' : 'error' } isDismissible={ false }>
					<strong>{ result.ok ? '✓ Round-trip OK' : '✗ Round-trip failed' }</strong>
					<div>{ __( 'Stage:', 'cf-bulk-meta-editor' ) } <code>{ result.stage }</code></div>
					{ result.note && <p>{ result.note }</p> }
					{ result.error && <p><code>{ result.error }</code></p> }
					{ ( 'wrote' in result ) && (
						<details>
							<summary>{ __( 'Details', 'cf-bulk-meta-editor' ) }</summary>
							<pre>{ JSON.stringify( result, null, 2 ) }</pre>
						</details>
					) }
				</Notice>
			) }
		</div>
	);
}
