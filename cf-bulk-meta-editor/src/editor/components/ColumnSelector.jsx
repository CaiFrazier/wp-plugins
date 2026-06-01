import { useState } from '@wordpress/element';
import { Button, CheckboxControl, Popover } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { useStore } from '../store';

const { settings = {} } = window.bmeEditorData ?? {};

export function getAvailableColumns() {
	const cols = [
		{ key: 'post_title', label: __( 'Post Title', 'cf-bulk-meta-editor' ), readOnly: true },
		{ key: 'post_name', label: __( 'Slug / URL', 'cf-bulk-meta-editor' ), readOnly: true },
		{ key: 'post_status', label: __( 'Status', 'cf-bulk-meta-editor' ), readOnly: true },
		{ key: 'post_modified', label: __( 'Last Modified', 'cf-bulk-meta-editor' ), readOnly: true },
		{ key: 'post_author', label: __( 'Author', 'cf-bulk-meta-editor' ), readOnly: true },
		{ key: 'excerpt', label: __( 'Content Excerpt', 'cf-bulk-meta-editor' ), readOnly: true },
	];

	if ( settings.meta_title_key ) {
		cols.push( { key: 'meta_title', label: __( 'Meta Title', 'cf-bulk-meta-editor' ), readOnly: false, charLimit: 60 } );
	}
	if ( settings.meta_desc_key ) {
		cols.push( { key: 'meta_desc', label: __( 'Meta Description', 'cf-bulk-meta-editor' ), readOnly: false, charLimit: 160 } );
	}
	( settings.custom_columns ?? [] ).forEach( ( col ) => {
		cols.push( { key: `custom__${ col.key }`, label: col.label, readOnly: false } );
	} );

	return cols;
}

export default function ColumnSelector() {
	const [ open, setOpen ] = useState( false );
	const { columnVisibility, setColumnVisibility } = useStore();

	const columns = getAvailableColumns();

	const toggleCol = ( key ) => {
		if ( key === 'post_title' ) return; // always visible
		setColumnVisibility( { ...columnVisibility, [ key ]: ! ( columnVisibility[ key ] ?? true ) } );
	};

	return (
		<div className="bme-column-selector">
			<Button variant="secondary" onClick={ () => setOpen( ! open ) }>
				{ __( 'Columns', 'cf-bulk-meta-editor' ) } ▾
			</Button>
			{ open && (
				<Popover onClose={ () => setOpen( false ) } placement="bottom-start">
					<div className="bme-column-popover">
						{ columns.map( ( col ) => (
							<CheckboxControl
								key={ col.key }
								label={ col.label }
								checked={ col.key === 'post_title' ? true : ( columnVisibility[ col.key ] ?? true ) }
								disabled={ col.key === 'post_title' }
								onChange={ () => toggleCol( col.key ) }
							/>
						) ) }
					</div>
				</Popover>
			) }
		</div>
	);
}
