import { SelectControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { getSchemaTypeOptions } from '../schemaTypes';

export default function SchemaTypeSelector( { value, onChange } ) {
	return (
		<SelectControl
			label={ __( 'Schema Type', 'schema-override-manager' ) }
			value={ value }
			options={ getSchemaTypeOptions() }
			onChange={ onChange }
		/>
	);
}
