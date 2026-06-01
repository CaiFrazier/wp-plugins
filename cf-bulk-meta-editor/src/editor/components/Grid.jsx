import { useMemo, useCallback, useRef } from '@wordpress/element';
import { Button, Spinner } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { AgGridReact } from 'ag-grid-react';
import 'ag-grid-community/styles/ag-grid.css';
import 'ag-grid-community/styles/ag-theme-alpine.css';
import { useStore } from '../store';
import { getAvailableColumns } from './ColumnSelector';
import { CharCountCellRenderer, CharCountCellEditor } from './CharCountCell';
import PaginationControls from './PaginationControls';

const { adminUrl } = window.bmeEditorData ?? {};

function TitleCellRenderer( params ) {
	const { value, data } = params;
	return (
		<a href={ `${ adminUrl }post.php?post=${ data.id }&action=edit` } target="_blank" rel="noreferrer">
			{ value }
		</a>
	);
}

function ActionsCellRenderer( params ) {
	const { data } = params;
	// Read actions imperatively — we only care about the latest functions, not subscribing.
	const revertRow = useStore( ( s ) => s.revertRow );
	const saveRow   = useStore( ( s ) => s.saveRow );

	if ( ! data?._isDirty ) return null;

	return (
		<div className="bme-row-actions">
			<Button isDestructive isSmall onClick={ () => revertRow( data.id ) }>
				{ __( 'Revert', 'cf-bulk-meta-editor' ) }
			</Button>
			<Button variant="primary" isSmall onClick={ () => saveRow( data.id ) }>
				{ __( 'Save', 'cf-bulk-meta-editor' ) }
			</Button>
		</div>
	);
}

export default function Grid() {
	const {
		loadedRows,
		dirtyRows,
		gridLoading,
		gridPage,
		gridPerPage,
		columnVisibility,
		setCellValue,
		setGridPage,
		setGridPerPage,
	} = useStore();

	// getAvailableColumns derives from window.bmeEditorData.settings, which is
	// page-load-fixed, so the result is stable for the lifetime of the app.
	const availableCols = useMemo( () => getAvailableColumns(), [] );

	// Paged slice of loaded rows.
	const pagedRows = useMemo( () => {
		const start = ( gridPage - 1 ) * gridPerPage;
		return loadedRows.slice( start, start + gridPerPage );
	}, [ loadedRows, gridPage, gridPerPage ] );

	// Merge dirty values so the grid always shows in-progress edits.
	const displayRows = useMemo( () =>
		pagedRows.map( ( row ) => ( {
			...row,
			...( dirtyRows[ row.id ] ?? {} ),
			_isDirty: !! dirtyRows[ row.id ],
		} ) ),
	[ pagedRows, dirtyRows ] );

	const columnDefs = useMemo( () => {
		const cols = [
			{
				field: 'post_title',
				headerName: __( 'Post Title', 'cf-bulk-meta-editor' ),
				pinned: 'left',
				width: 220,
				cellRenderer: TitleCellRenderer,
				editable: false,
			},
		];

		availableCols.forEach( ( col ) => {
			if ( col.key === 'post_title' ) return;
			const visible = columnVisibility[ col.key ] ?? true;
			if ( ! visible ) return;

			const def = {
				field: col.key,
				headerName: col.label,
				editable: ! col.readOnly,
				width: col.charLimit ? 280 : 180,
				hide: false,
			};

			if ( col.charLimit ) {
				def.cellRenderer    = CharCountCellRenderer;
				def.cellEditor      = CharCountCellEditor;
				def.cellRendererParams = { charLimit: col.charLimit };
				def.cellEditorParams   = { charLimit: col.charLimit };
				def.cellEditorPopup    = true;
			}

			cols.push( def );
		} );

		cols.push( {
			headerName: '',
			field: '_actions',
			pinned: 'right',
			width: 130,
			editable: false,
			cellRenderer: ActionsCellRenderer,
			sortable: false,
			filter: false,
		} );

		return cols;
	}, [ columnVisibility, availableCols ] );

	const getRowStyle = useCallback(
		( params ) => ( params.data._isDirty ? { background: '#fffbe6' } : null ),
		[]
	);

	const onCellValueChanged = useCallback( ( params ) => {
		setCellValue( params.data.id, params.colDef.field, params.newValue );
	}, [ setCellValue ] );

	const pages = Math.max( 1, Math.ceil( loadedRows.length / gridPerPage ) );

	if ( gridLoading ) return <div className="bme-grid-loading"><Spinner /></div>;

	if ( ! loadedRows.length ) {
		return (
			<div className="bme-grid-empty">
				{ __( 'Select posts in the picker and click "Load Selected".', 'cf-bulk-meta-editor' ) }
			</div>
		);
	}

	return (
		<div className="bme-grid-wrap">
			<div className="bme-grid-info">
				{ __( 'Showing', 'cf-bulk-meta-editor' ) }{ ' ' }
				{ ( gridPage - 1 ) * gridPerPage + 1 }–{ Math.min( gridPage * gridPerPage, loadedRows.length ) }{ ' ' }
				{ __( 'of', 'cf-bulk-meta-editor' ) } { loadedRows.length }{ ' ' }
				{ __( 'loaded posts', 'cf-bulk-meta-editor' ) }
			</div>
			<div
				className="ag-theme-alpine bme-grid"
				style={ { height: 600, width: '100%' } }
			>
				<AgGridReact
					rowData={ displayRows }
					columnDefs={ columnDefs }
					getRowStyle={ getRowStyle }
					onCellValueChanged={ onCellValueChanged }
					suppressRowClickSelection
					rowHeight={ 44 }
					headerHeight={ 36 }
					enableCellTextSelection
					stopEditingWhenCellsLoseFocus
				/>
			</div>
			<PaginationControls
				page={ gridPage }
				pages={ pages }
				perPage={ gridPerPage }
				total={ loadedRows.length }
				onPage={ setGridPage }
				onPerPage={ setGridPerPage }
				perPageOptions={ [ 25, 50, 100, 200 ] }
			/>
		</div>
	);
}
