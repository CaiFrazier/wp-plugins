import { useEffect, useRef } from '@wordpress/element';

function getCountClass( len, limit ) {
	if ( ! limit ) return '';
	const ratio = len / limit;
	if ( ratio > 1 ) return 'bme-count--over';
	if ( ratio > 0.85 ) return 'bme-count--warn';
	return 'bme-count--ok';
}

/**
 * Read-only renderer with character count badge.
 */
export function CharCountCellRenderer( params ) {
	const { value, colDef } = params;
	const limit = colDef.cellRendererParams?.charLimit;
	const len   = ( value ?? '' ).length;

	return (
		<div className="bme-char-cell">
			<span className="bme-char-value">{ value }</span>
			{ limit && (
				<span className={ `bme-char-count ${ getCountClass( len, limit ) }` }>
					{ len }/{ limit }
				</span>
			) }
		</div>
	);
}

/**
 * AG Grid reactive editor: controlled values are reported through
 * onValueChange so the grid commits the edited value when editing stops.
 *
 * Reference: https://www.ag-grid.com/react-data-grid/component-cell-editor/
 */
export function CharCountCellEditor( params ) {
	const limit = params.colDef.cellEditorParams?.charLimit;
	const val = params.value ?? '';
	const textRef = useRef( null );

	useEffect( () => {
		// Focus + place cursor at end of existing value.
		const el = textRef.current;
		if ( el ) {
			el.focus();
			el.setSelectionRange( el.value.length, el.value.length );
		}
	}, [] );

	const len = val.length;

	return (
		<div className="bme-char-editor">
			<textarea
				ref={ textRef }
				value={ val }
				rows={ 3 }
				onChange={ ( e ) => params.onValueChange( e.target.value ) }
			/>
			{ limit && (
				<span className={ `bme-char-count ${ getCountClass( len, limit ) }` }>
					{ len }/{ limit }
				</span>
			) }
		</div>
	);
}
