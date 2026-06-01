import { TextControl, Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export default function BreadcrumbsRepeater( { items, onChange } ) {
	const add = () => onChange( [
		...items,
		{ '@type': 'ListItem', position: items.length + 1, name: '', item: '' },
	] );

	const remove = ( idx ) => onChange(
		items.filter( ( _, i ) => i !== idx )
			.map( ( item, i ) => ( { ...item, position: i + 1 } ) )
	);

	const update = ( idx, key, value ) => {
		onChange( items.map( ( item, i ) => i === idx ? { ...item, [ key ]: value } : item ) );
	};

	return (
		<div className="som-breadcrumbs-repeater">
			<label className="components-base-control__label">
				{ __( 'Breadcrumb Items', 'schema-override-manager' ) }
			</label>

			{ items.map( ( item, idx ) => (
				<div key={ idx } className="som-breadcrumbs-repeater__item">
					<TextControl
						label={ `${ idx + 1 } — ${ __( 'Label', 'schema-override-manager' ) }` }
						value={ item.name ?? '' }
						onChange={ v => update( idx, 'name', v ) }
					/>
					<TextControl
						label={ __( 'URL', 'schema-override-manager' ) }
						value={ item.item ?? '' }
						onChange={ v => update( idx, 'item', v ) }
						type="url"
					/>
					<Button isDestructive isSmall onClick={ () => remove( idx ) }>
						{ __( 'Remove', 'schema-override-manager' ) }
					</Button>
				</div>
			) ) }

			<Button variant="secondary" onClick={ add }>
				{ __( '+ Add Breadcrumb', 'schema-override-manager' ) }
			</Button>
		</div>
	);
}
