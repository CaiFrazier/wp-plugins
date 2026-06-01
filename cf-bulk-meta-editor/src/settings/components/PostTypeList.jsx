import { CheckboxControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export default function PostTypeList( { postTypes, enabled, onChange } ) {
	const toggle = ( slug, checked ) => {
		const next = checked
			? [ ...enabled, slug ]
			: enabled.filter( ( s ) => s !== slug );
		onChange( next );
	};

	return (
		<div className="bme-card">
			<h2>{ __( 'Enabled Post Types', 'cf-bulk-meta-editor' ) }</h2>
			<p>{ __( 'Unchecked post types will be hidden from the editor.', 'cf-bulk-meta-editor' ) }</p>
			{ Object.entries( postTypes ).map( ( [ slug, label ] ) => (
				<CheckboxControl
					key={ slug }
					label={ `${ label } (${ slug })` }
					checked={ enabled.includes( slug ) }
					onChange={ ( v ) => toggle( slug, v ) }
				/>
			) ) }
		</div>
	);
}
