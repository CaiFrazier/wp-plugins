import { useState } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { useStore } from '../store';
import bmeLog from '../../shared/logger';
import ColumnSelector, { getAvailableColumns } from './ColumnSelector';

const { apiNamespace, restRoot, nonce } = window.bmeEditorData ?? {};

export default function Toolbar() {
	const {
		postTypes,
		activePostType,
		setActivePostType,
		saveAll,
		saving,
		loadedRows,
		columnVisibility,
		dirtyRows,
		addNotice,
	} = useStore();

	const [ exporting, setExporting ] = useState( false );
	const dirtyCount = Object.keys( dirtyRows ).length;

	// POST the export so post IDs travel in the request body (out of URLs,
	// access logs, browser history, referrer headers).
	const handleExport = async () => {
		const ids = loadedRows.map( ( r ) => r.id );
		if ( ! ids.length ) return;

		const visibleCols = getAvailableColumns()
			.filter( ( c ) => c.key === 'post_title' || ( columnVisibility[ c.key ] ?? true ) )
			.map( ( c ) => c.key );

		setExporting( true );
		bmeLog.info( 'export', 'csv export request', {
			ids: ids.length,
			columns: visibleCols.length,
			post_type: activePostType,
		} );

		try {
			const base = restRoot.replace( /\/$/, '' );
			const response = await fetch( `${ base }/${ apiNamespace }/export`, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce':   nonce,
				},
				body: JSON.stringify( {
					ids,
					columns: visibleCols,
					post_type: activePostType,
				} ),
			} );

			if ( ! response.ok ) {
				throw new Error( `Export failed (HTTP ${ response.status })` );
			}

			const blob = await response.blob();
			// Pull filename from Content-Disposition if present, else build one.
			const cd = response.headers.get( 'content-disposition' ) || '';
			const m  = cd.match( /filename="?([^"]+)"?/i );
			const filename = m ? m[ 1 ] : `bulk-meta-export-${ activePostType }-${ new Date().toISOString().slice( 0, 10 ) }.csv`;

			const url = URL.createObjectURL( blob );
			const a   = document.createElement( 'a' );
			a.href     = url;
			a.download = filename;
			document.body.appendChild( a );
			a.click();
			document.body.removeChild( a );
			setTimeout( () => URL.revokeObjectURL( url ), 1000 );

			bmeLog.info( 'export', 'csv export complete', { bytes: blob.size, filename } );
		} catch ( e ) {
			addNotice( 'error', e.message || 'Export failed.' );
			bmeLog.error( 'export', 'csv export failed', { message: e.message } );
		} finally {
			setExporting( false );
		}
	};

	return (
		<div className="bme-toolbar">
			<div className="bme-post-type-tabs">
				{ postTypes.map( ( t ) => (
					<button
						key={ t.slug }
						type="button"
						className={ `bme-tab ${ activePostType === t.slug ? 'is-active' : '' }` }
						onClick={ () => setActivePostType( t.slug ) }
					>
						{ t.label }
					</button>
				) ) }
			</div>

			<div className="bme-toolbar-actions">
				<ColumnSelector />
				<Button
					variant="secondary"
					onClick={ handleExport }
					disabled={ ! loadedRows.length || exporting }
					isBusy={ exporting }
				>
					{ exporting ? __( 'Exporting…', 'cf-bulk-meta-editor' ) : __( 'Export CSV', 'cf-bulk-meta-editor' ) }
				</Button>
				<Button
					variant="primary"
					onClick={ saveAll }
					isBusy={ saving }
					disabled={ saving || dirtyCount === 0 }
				>
					{ dirtyCount > 0
						? `${ __( 'Save All Changes', 'cf-bulk-meta-editor' ) } (${ dirtyCount })`
						: __( 'Save All Changes', 'cf-bulk-meta-editor' ) }
				</Button>
			</div>
		</div>
	);
}
