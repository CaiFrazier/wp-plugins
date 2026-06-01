import { Button, SelectControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export default function PaginationControls( { page, pages, perPage, total, onPage, onPerPage, perPageOptions = [ 25, 50, 100 ] } ) {
	return (
		<div className="bme-pagination">
			<Button
				variant="tertiary"
				disabled={ page <= 1 }
				onClick={ () => onPage( page - 1 ) }
			>
				‹
			</Button>
			<span className="bme-pagination-label">
				{ page } / { pages || 1 }
			</span>
			<Button
				variant="tertiary"
				disabled={ page >= pages }
				onClick={ () => onPage( page + 1 ) }
			>
				›
			</Button>
			<SelectControl
				label={ __( 'Per page', 'cf-bulk-meta-editor' ) }
				hideLabelFromVision
				value={ String( perPage ) }
				options={ perPageOptions.map( ( n ) => ( { label: String( n ), value: String( n ) } ) ) }
				onChange={ ( v ) => onPerPage( Number( v ) ) }
			/>
		</div>
	);
}
