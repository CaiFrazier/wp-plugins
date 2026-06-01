import { useState } from '@wordpress/element';
import { Button, Notice, Spinner, PanelBody } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import SchemaTypeSelector from './SchemaTypeSelector';
import PropertyEditor from './PropertyEditor';

/**
 * Normalize a block from form-shape to JSON-LD-shape on the way out:
 *   - FAQPage: {question,answer}[] → {@type:Question, name, acceptedAnswer:{@type:Answer, text}}[]
 *   - BreadcrumbList items already carry @type/position from BreadcrumbsRepeater.
 *
 * Strips empty fields so we don't store noise in postmeta.
 */
/**
 * Inverse of normalizeBlockForSave. Converts saved JSON-LD shapes back into
 * the form shapes the editors expect (notably FAQPage Q&A items).
 */
export function denormalizeBlockForLoad( block ) {
	if ( ! block || ! block['@type'] ) return block;
	if ( block['@type'] === 'FAQPage' && Array.isArray( block.mainEntity ) ) {
		return {
			...block,
			mainEntity: block.mainEntity.map( q => ( {
				question: q.name ?? q.question ?? '',
				answer:   q.acceptedAnswer?.text ?? q.answer ?? '',
			} ) ),
		};
	}
	return block;
}

function normalizeBlockForSave( block ) {
	if ( ! block || ! block['@type'] ) return block;
	const out = {};
	for ( const [ k, v ] of Object.entries( block ) ) {
		if ( v === undefined || v === null || v === '' ) continue;
		if ( Array.isArray( v ) && v.length === 0 ) continue;
		out[ k ] = v;
	}

	if ( out['@type'] === 'FAQPage' && Array.isArray( out.mainEntity ) ) {
		out.mainEntity = out.mainEntity
			.filter( q => q.question || q.answer )
			.map( q => ( {
				'@type': 'Question',
				name:    q.question ?? '',
				acceptedAnswer: { '@type': 'Answer', text: q.answer ?? '' },
			} ) );
	}

	return out;
}

function SchemaBlock( { block, index, onChange, onRemove } ) {
	return (
		<PanelBody
			title={ block['@type'] || __( 'New Schema Block', 'schema-override-manager' ) }
			initialOpen={ index === 0 }
		>
			<SchemaTypeSelector
				value={ block['@type'] ?? '' }
				onChange={ v => onChange( { ...block, '@type': v } ) }
			/>
			{ block['@type'] && (
				<PropertyEditor
					type={ block['@type'] }
					data={ block }
					onChange={ onChange }
				/>
			) }
			<Button isDestructive isSmall onClick={ onRemove }>
				{ __( 'Remove this block', 'schema-override-manager' ) }
			</Button>
		</PanelBody>
	);
}

export default function SchemaBuilder( { postId, schema, onChange } ) {
	const [ saving, setSaving ] = useState( false );
	const [ notice, setNotice ] = useState( null );

	const { restUrl } = window.somMetaBox ?? {};

	const addBlock = () => onChange( [ ...schema, { '@type': '' } ] );

	const updateBlock = ( idx, updated ) => onChange( schema.map( ( b, i ) => i === idx ? updated : b ) );

	const removeBlock = ( idx ) => onChange( schema.filter( ( _, i ) => i !== idx ) );

	const save = async () => {
		setSaving( true );
		setNotice( null );
		try {
			const payload = schema.map( normalizeBlockForSave );
			await apiFetch( {
				url: `${ restUrl }/page/${ postId }/schema`,
				method: 'POST',
				data: payload,
			} );
			setNotice( { type: 'success', message: __( 'Schema saved.', 'schema-override-manager' ) } );
		} catch ( e ) {
			setNotice( { type: 'error', message: e?.message ?? __( 'Save failed.', 'schema-override-manager' ) } );
		} finally {
			setSaving( false );
		}
	};

	return (
		<div className="som-schema-builder">
			{ notice && (
				<Notice status={ notice.type } isDismissible onRemove={ () => setNotice( null ) }>
					{ notice.message }
				</Notice>
			) }

			{ schema.map( ( block, idx ) => (
				<SchemaBlock
					key={ idx }
					block={ block }
					index={ idx }
					onChange={ updated => updateBlock( idx, updated ) }
					onRemove={ () => removeBlock( idx ) }
				/>
			) ) }

			<div className="som-builder-actions">
				<Button variant="secondary" onClick={ addBlock }>
					{ __( '+ Add Schema Block', 'schema-override-manager' ) }
				</Button>
				{ schema.length > 0 && (
					<Button variant="primary" onClick={ save } isBusy={ saving } disabled={ saving }>
						{ saving ? <Spinner /> : __( 'Save Schema', 'schema-override-manager' ) }
					</Button>
				) }
			</div>
		</div>
	);
}
