import { Button, Spinner } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export default function SchemaPreview( { preview, onRefresh } ) {
	if ( ! preview ) {
		return (
			<div className="som-preview">
				<Spinner />
				<p>{ __( 'Computing preview…', 'schema-override-manager' ) }</p>
			</div>
		);
	}

	const blocks = Object.values( preview );
	const isEmpty = blocks.length === 0;

	const json = isEmpty
		? '// No schema output for this page.'
		: blocks.length === 1
			? JSON.stringify( blocks[ 0 ], null, 2 )
			: JSON.stringify(
				{ '@context': 'https://schema.org', '@graph': blocks },
				null,
				2
			);

	return (
		<div className="som-preview">
			<div className="som-preview__actions">
				<p className="description">
					{ __( 'Final JSON-LD that will be output on this page after all layers and suppression rules are applied.', 'schema-override-manager' ) }
				</p>
				<Button variant="secondary" isSmall onClick={ onRefresh }>
					{ __( 'Refresh', 'schema-override-manager' ) }
				</Button>
			</div>

			<pre className="som-preview__json">
				<code>{ json }</code>
			</pre>
		</div>
	);
}
