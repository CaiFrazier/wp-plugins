import { useState } from '@wordpress/element';
import { Button, PanelBody, Spinner, Notice } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

function SchemaBlock( { source, type, data } ) {
	const [ open, setOpen ] = useState( false );
	return (
		<div className="som-schema-block">
			<button
				type="button"
				className="som-schema-block__toggle"
				onClick={ () => setOpen( v => ! v ) }
				aria-expanded={ open }
			>
				<span className="som-schema-block__source">{ source }</span>
				<code>{ type }</code>
				<span className="som-schema-block__arrow">{ open ? '▲' : '▼' }</span>
			</button>
			{ open && (
				<pre className="som-schema-block__json">
					{ JSON.stringify( data, null, 2 ) }
				</pre>
			) }
		</div>
	);
}

export default function ExistingSchemaViewer( { detected } ) {
	const { restUrl, postId, postUrl } = window.somMetaBox ?? {};

	const richResultsUrl = postUrl
		? `https://search.google.com/test/rich-results?url=${ encodeURIComponent( postUrl ) }`
		: null;
	const schemaOrgUrl = postUrl
		? `https://validator.schema.org/?url=${ encodeURIComponent( postUrl ) }`
		: null;

	const [ live, setLive ]               = useState( null );
	const [ liveLoading, setLiveLoading ] = useState( false );
	const [ liveError, setLiveError ]     = useState( null );

	const fetchLive = async () => {
		setLiveLoading( true );
		setLiveError( null );
		try {
			const data = await apiFetch( { url: `${ restUrl }/page/${ postId }/detected-live` } );
			setLive( data );
		} catch ( e ) {
			setLiveError( e?.message ?? __( 'Live fetch failed.', 'schema-override-manager' ) );
		} finally {
			setLiveLoading( false );
		}
	};

	if ( ! detected ) {
		return (
			<div className="som-existing-schema">
				<Spinner />
				<p>{ __( 'Loading schema sources…', 'schema-override-manager' ) }</p>
			</div>
		);
	}

	const yoastBlocks = detected.yoast     ?? [];
	const rmBlocks    = detected.rank_math ?? [];
	const filterEmpty = yoastBlocks.length === 0 && rmBlocks.length === 0;

	return (
		<div className="som-existing-schema">
			<p className="description">
				{ __( 'Schema currently active on this page from other sources.', 'schema-override-manager' ) }
			</p>

			{ richResultsUrl && (
				<p className="som-validate-links">
					<Button variant="link" href={ richResultsUrl } target="_blank" rel="noopener noreferrer">
						{ __( 'Validate in Google Rich Results Test ↗', 'schema-override-manager' ) }
					</Button>
					<span aria-hidden="true"> · </span>
					<Button variant="link" href={ schemaOrgUrl } target="_blank" rel="noopener noreferrer">
						{ __( 'Validate at schema.org ↗', 'schema-override-manager' ) }
					</Button>
				</p>
			) }

			{ filterEmpty && (
				<Notice status="info" isDismissible={ false }>
					{ __( 'No schema detected from Yoast or Rank Math via filters. This is common while editing — the live page may still emit schema. Use "Fetch live page" below to see what is actually rendered.', 'schema-override-manager' ) }
				</Notice>
			) }

			{ yoastBlocks.length > 0 && (
				<PanelBody title={ __( 'Yoast SEO (filter)', 'schema-override-manager' ) } initialOpen>
					{ yoastBlocks.map( ( { type, data }, i ) => (
						<SchemaBlock key={ i } source="Yoast" type={ type } data={ data } />
					) ) }
				</PanelBody>
			) }

			{ rmBlocks.length > 0 && (
				<PanelBody title={ __( 'Rank Math (filter)', 'schema-override-manager' ) } initialOpen>
					{ rmBlocks.map( ( { type, data }, i ) => (
						<SchemaBlock key={ i } source="Rank Math" type={ type } data={ data } />
					) ) }
				</PanelBody>
			) }

			<div className="som-fetch-live">
				<hr />
				<h4>{ __( 'Live page snapshot', 'schema-override-manager' ) }</h4>
				<p className="description">
					{ __( 'Fetches the published page and parses every JSON-LD block actually rendered. Catches theme and third-party output the filter-based view misses.', 'schema-override-manager' ) }
				</p>

				<Button variant="secondary" onClick={ fetchLive } isBusy={ liveLoading } disabled={ liveLoading }>
					{ liveLoading ? <Spinner /> : __( 'Fetch live page', 'schema-override-manager' ) }
				</Button>

				{ liveError && (
					<Notice status="error" isDismissible onRemove={ () => setLiveError( null ) }>
						{ liveError }
					</Notice>
				) }

				{ live && (
					<div className="som-live-results">
						<p className="description">
							{ __( 'Fetched URL: ', 'schema-override-manager' ) }
							<code>{ live.url }</code>
						</p>

						{ live.ours?.length > 0 && (
							<PanelBody title={ __( 'From this plugin', 'schema-override-manager' ) } initialOpen>
								{ live.ours.map( ( { type, data }, i ) => (
									<SchemaBlock key={ i } source="SOM" type={ type } data={ data } />
								) ) }
							</PanelBody>
						) }

						{ live.other?.length > 0 && (
							<PanelBody title={ __( 'From other sources (theme, plugins)', 'schema-override-manager' ) } initialOpen>
								{ live.other.map( ( { type, data }, i ) => (
									<SchemaBlock key={ i } source="other" type={ type } data={ data } />
								) ) }
							</PanelBody>
						) }

						{ live.ours?.length === 0 && live.other?.length === 0 && (
							<p>{ __( 'No JSON-LD blocks found in the rendered page.', 'schema-override-manager' ) }</p>
						) }
					</div>
				) }
			</div>
		</div>
	);
}
