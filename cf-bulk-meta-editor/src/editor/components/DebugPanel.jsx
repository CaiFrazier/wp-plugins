import { useState, useEffect, useRef } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import bmeLog from '../../shared/logger';

const LEVEL_COLOR = {
	debug: '#777',
	info:  '#2271b1',
	warn:  '#c44b00',
	error: '#cc1818',
};

export default function DebugPanel() {
	const [ open, setOpen ] = useState( false );
	const [ entries, setEntries ] = useState( [] );
	const [ filter, setFilter ] = useState( '' );
	const [ levelFilter, setLevelFilter ] = useState( '' );
	const intervalRef = useRef( null );

	useEffect( () => {
		if ( ! open ) {
			if ( intervalRef.current ) {
				clearInterval( intervalRef.current );
				intervalRef.current = null;
			}
			return undefined;
		}
		const refresh = () => setEntries( bmeLog.dump().slice().reverse() );
		refresh();
		intervalRef.current = setInterval( refresh, 1000 );
		return () => {
			if ( intervalRef.current ) clearInterval( intervalRef.current );
		};
	}, [ open ] );

	const filtered = entries.filter( ( e ) => {
		if ( levelFilter && e.level !== levelFilter ) return false;
		if ( filter ) {
			const hay = `${ e.channel } ${ e.message } ${ JSON.stringify( e.context ) }`.toLowerCase();
			if ( ! hay.includes( filter.toLowerCase() ) ) return false;
		}
		return true;
	} );

	const copyAll = () => {
		const text = bmeLog.dump().map( ( e ) => JSON.stringify( e ) ).join( '\n' );
		navigator.clipboard.writeText( text ).then(
			() => bmeLog.info( 'debug-panel', 'log copied to clipboard', { entries: entries.length } ),
			( err ) => bmeLog.warn( 'debug-panel', 'clipboard copy failed', { error: err?.message } )
		);
	};

	const flushNow = () => bmeLog.flush();

	const clearLocal = () => {
		bmeLog.clear();
		setEntries( [] );
	};

	return (
		<>
			<button
				type="button"
				className={ `bme-debug-toggle ${ open ? 'is-open' : '' }` }
				onClick={ () => setOpen( ! open ) }
				title={ __( 'Toggle BME debug panel', 'cf-bulk-meta-editor' ) }
			>
				{ open ? '×' : '⚡' } BME
			</button>

			{ open && (
				<div className="bme-debug-panel" role="region" aria-label={ __( 'BME diagnostic log', 'cf-bulk-meta-editor' ) }>
					<div className="bme-debug-header">
						<strong>{ __( 'BME live log', 'cf-bulk-meta-editor' ) }</strong>
						<span className="bme-debug-count">{ filtered.length } / { entries.length }</span>
						<div className="bme-debug-actions">
							<Button variant="secondary" isSmall onClick={ copyAll }>
								{ __( 'Copy', 'cf-bulk-meta-editor' ) }
							</Button>
							<Button variant="secondary" isSmall onClick={ flushNow }>
								{ __( 'Flush', 'cf-bulk-meta-editor' ) }
							</Button>
							<Button isDestructive isSmall onClick={ clearLocal }>
								{ __( 'Clear', 'cf-bulk-meta-editor' ) }
							</Button>
						</div>
					</div>

					<div className="bme-debug-filters">
						<input
							type="text"
							placeholder={ __( 'Filter…', 'cf-bulk-meta-editor' ) }
							value={ filter }
							onChange={ ( e ) => setFilter( e.target.value ) }
						/>
						<select value={ levelFilter } onChange={ ( e ) => setLevelFilter( e.target.value ) }>
							<option value="">{ __( 'All levels', 'cf-bulk-meta-editor' ) }</option>
							<option value="debug">debug</option>
							<option value="info">info</option>
							<option value="warn">warn</option>
							<option value="error">error</option>
						</select>
					</div>

					<div className="bme-debug-list">
						{ filtered.length === 0 && (
							<div className="bme-debug-empty">
								{ __( 'No entries (yet). Try interacting with the editor.', 'cf-bulk-meta-editor' ) }
							</div>
						) }
						{ filtered.map( ( e, i ) => (
							<div key={ i } className="bme-debug-row">
								<span className="bme-debug-time">{ e.ts.split( 'T' )[ 1 ]?.slice( 0, 12 ) ?? e.ts }</span>
								<span className="bme-debug-level" style={ { color: LEVEL_COLOR[ e.level ] } }>
									{ e.level.toUpperCase() }
								</span>
								<span className="bme-debug-channel">{ e.channel }</span>
								<span className="bme-debug-message">{ e.message }</span>
								{ e.context && Object.keys( e.context ).length > 0 && (
									<details className="bme-debug-context">
										<summary>ctx</summary>
										<pre>{ JSON.stringify( e.context, null, 2 ) }</pre>
									</details>
								) }
							</div>
						) ) }
					</div>
				</div>
			) }
		</>
	);
}
