import { TextControl, TextareaControl, Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export default function FaqRepeater( { items, onChange } ) {
	const add = () => onChange( [ ...items, { question: '', answer: '' } ] );

	const remove = ( idx ) => onChange( items.filter( ( _, i ) => i !== idx ) );

	const update = ( idx, key, value ) => {
		onChange( items.map( ( item, i ) => i === idx ? { ...item, [ key ]: value } : item ) );
	};

	return (
		<div className="som-faq-repeater">
			<label className="components-base-control__label">
				{ __( 'FAQ Items', 'schema-override-manager' ) }
			</label>

			{ items.map( ( item, idx ) => (
				<div key={ idx } className="som-faq-repeater__item">
					<TextControl
						label={ `${ __( 'Question', 'schema-override-manager' ) } ${ idx + 1 }` }
						value={ item.question }
						onChange={ v => update( idx, 'question', v ) }
					/>
					<TextareaControl
						label={ __( 'Answer', 'schema-override-manager' ) }
						value={ item.answer }
						onChange={ v => update( idx, 'answer', v ) }
						rows={ 3 }
					/>
					<Button isDestructive isSmall onClick={ () => remove( idx ) }>
						{ __( 'Remove', 'schema-override-manager' ) }
					</Button>
				</div>
			) ) }

			<Button variant="secondary" onClick={ add }>
				{ __( '+ Add Question', 'schema-override-manager' ) }
			</Button>
		</div>
	);
}
