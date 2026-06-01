import { TextControl, SelectControl, Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Sanitizer strategies — keep in lockstep with Settings::SANITIZERS in PHP.
 * Labels are short on purpose so the dropdown stays a tight column.
 */
const SANITIZER_OPTIONS = [
	{ value: 'textarea', label: __( 'Text (multi-line)', 'cf-bulk-meta-editor' ) },
	{ value: 'text',     label: __( 'Text (single-line)', 'cf-bulk-meta-editor' ) },
	{ value: 'html',     label: __( 'Safe HTML', 'cf-bulk-meta-editor' ) },
	{ value: 'url',      label: __( 'URL', 'cf-bulk-meta-editor' ) },
	{ value: 'number',   label: __( 'Number', 'cf-bulk-meta-editor' ) },
	{ value: 'raw',      label: __( 'Raw (no sanitization)', 'cf-bulk-meta-editor' ) },
];

export default function CustomColumnRepeater( { columns, onChange } ) {
	const add = () => onChange( [ ...columns, { key: '', label: '', sanitizer: 'textarea' } ] );

	const remove = ( idx ) => onChange( columns.filter( ( _, i ) => i !== idx ) );

	const updateField = ( idx, field, value ) => {
		const next = columns.map( ( col, i ) =>
			i === idx ? { ...col, [ field ]: value } : col
		);
		onChange( next );
	};

	return (
		<div className="bme-card">
			<h2>{ __( 'Custom Columns', 'cf-bulk-meta-editor' ) }</h2>
			<p>{ __( 'Add arbitrary postmeta fields to expose in the editor grid.', 'cf-bulk-meta-editor' ) }</p>

			{ columns.map( ( col, idx ) => (
				<div key={ idx } className="bme-custom-col-row">
					<TextControl
						label={ __( 'Meta key', 'cf-bulk-meta-editor' ) }
						placeholder="_product_sku"
						value={ col.key }
						onChange={ ( v ) => updateField( idx, 'key', v ) }
					/>
					<TextControl
						label={ __( 'Display label', 'cf-bulk-meta-editor' ) }
						placeholder="SKU"
						value={ col.label }
						onChange={ ( v ) => updateField( idx, 'label', v ) }
					/>
					<SelectControl
						label={ __( 'Sanitizer', 'cf-bulk-meta-editor' ) }
						value={ col.sanitizer || 'textarea' }
						options={ SANITIZER_OPTIONS }
						onChange={ ( v ) => updateField( idx, 'sanitizer', v ) }
						help={ col.sanitizer === 'raw'
							? __( 'No sanitization. Cell value is stored verbatim (still capped at 64 KB). Use for JSON or other structured payloads.', 'cf-bulk-meta-editor' )
							: undefined
						}
					/>
					<Button isDestructive isSmall onClick={ () => remove( idx ) }>
						{ __( 'Remove', 'cf-bulk-meta-editor' ) }
					</Button>
				</div>
			) ) }

			<Button variant="secondary" onClick={ add }>
				{ __( '+ Add Column', 'cf-bulk-meta-editor' ) }
			</Button>
		</div>
	);
}
