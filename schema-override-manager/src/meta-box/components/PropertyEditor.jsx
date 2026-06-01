import { TextControl, TextareaControl, SelectControl, Notice } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import FaqRepeater from './FaqRepeater';
import BreadcrumbsRepeater from './BreadcrumbsRepeater';
import { getTypeDef } from '../schemaTypes';

function FieldControl( { field, value, onChange } ) {
	const { key, label, type = 'text', placeholder, options } = field;

	switch ( type ) {
		case 'textarea':
			return (
				<TextareaControl
					label={ __( label, 'schema-override-manager' ) }
					value={ value ?? '' }
					onChange={ onChange }
					placeholder={ placeholder }
				/>
			);
		case 'select':
			return (
				<SelectControl
					label={ __( label, 'schema-override-manager' ) }
					value={ value ?? '' }
					options={ options ?? [] }
					onChange={ onChange }
				/>
			);
		case 'date':
		case 'url':
		case 'text':
		default:
			return (
				<TextControl
					label={ __( label, 'schema-override-manager' ) }
					value={ value ?? '' }
					onChange={ onChange }
					type={ type === 'date' ? 'date' : ( type === 'url' ? 'url' : 'text' ) }
					placeholder={ placeholder }
				/>
			);
	}
}

export default function PropertyEditor( { type, data, onChange } ) {
	if ( ! type ) return null;

	const def = getTypeDef( type );
	const update = ( key, value ) => onChange( { ...data, [ key ]: value } );

	if ( ! def ) {
		return (
			<Notice status="warning" isDismissible={ false }>
				{ sprintf(
					/* translators: %s: schema.org @type name */
					__( 'No editor registered for type "%s". Use Extend mode and supply properties through the JSON elsewhere, or open an issue to add it to the registry.', 'schema-override-manager' ),
					type
				) }
			</Notice>
		);
	}

	let body;
	if ( def.editor === 'faq' ) {
		body = (
			<>
				<TextControl
					label={ __( 'Page Name', 'schema-override-manager' ) }
					value={ data.name ?? '' }
					onChange={ v => update( 'name', v ) }
				/>
				<FaqRepeater
					items={ data.mainEntity ?? [] }
					onChange={ v => update( 'mainEntity', v ) }
				/>
			</>
		);
	} else if ( def.editor === 'breadcrumbs' ) {
		body = (
			<BreadcrumbsRepeater
				items={ data.itemListElement ?? [] }
				onChange={ v => update( 'itemListElement', v ) }
			/>
		);
	} else {
		body = (
			<>
				{ def.fields.map( field => (
					<FieldControl
						key={ field.key }
						field={ field }
						value={ data[ field.key ] }
						onChange={ v => update( field.key, v ) }
					/>
				) ) }
			</>
		);
	}

	return (
		<div className="som-property-editor">
			{ body }

			<div className="som-mode-toggle">
				<SelectControl
					label={ __( 'Mode', 'schema-override-manager' ) }
					value={ data._som_mode ?? 'extend' }
					options={ [
						{ label: __( 'Extend (merge with existing)', 'schema-override-manager' ), value: 'extend' },
						{ label: __( 'Replace (suppress same type from other sources)', 'schema-override-manager' ), value: 'replace' },
					] }
					onChange={ v => update( '_som_mode', v ) }
				/>
				{ data._som_mode === 'replace' && (
					<Notice status="warning" isDismissible={ false }>
						{ sprintf(
							/* translators: %s is a schema.org type name like "Article". */
							__( 'Replace mode is on. Schema of type "%s" emitted by Yoast and Rank Math will be automatically suppressed on this page.', 'schema-override-manager' ),
							type
						) }
					</Notice>
				) }
			</div>
		</div>
	);
}
