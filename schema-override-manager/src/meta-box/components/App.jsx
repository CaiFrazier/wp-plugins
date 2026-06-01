import { useState, useEffect } from '@wordpress/element';
import { TabPanel, Spinner } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import ExistingSchemaViewer from './ExistingSchemaViewer';
import SuppressionPanel from './SuppressionPanel';
import SchemaBuilder, { denormalizeBlockForLoad } from './SchemaBuilder';
import SchemaPreview from './SchemaPreview';

const TABS = [
	{ name: 'existing',    title: __( 'Existing', 'schema-override-manager' ) },
	{ name: 'suppression', title: __( 'Suppression', 'schema-override-manager' ) },
	{ name: 'custom',      title: __( 'Custom Schema', 'schema-override-manager' ) },
	{ name: 'preview',     title: __( 'Preview', 'schema-override-manager' ) },
];

export default function App( { isBlockEditor = false } ) {
	const { postId, restUrl } = window.somMetaBox ?? {};

	const [ schema, setSchema ] = useState(
		( window.somMetaBox?.schema ?? [] ).map( denormalizeBlockForLoad )
	);
	const [ suppression, setSuppression ] = useState( window.somMetaBox?.suppression ?? {} );
	const [ detected,    setDetected    ] = useState( null );
	const [ preview,     setPreview     ] = useState( null );
	const [ activeTab,   setActiveTab   ] = useState( 'existing' );
	const [ loading,     setLoading     ] = useState( false );

	const fetchDetected = async () => {
		if ( detected !== null ) return;
		setLoading( true );
		try {
			const data = await apiFetch( { url: `${ restUrl }/page/${ postId }/detected` } );
			setDetected( data );
		} finally {
			setLoading( false );
		}
	};

	const fetchPreview = async () => {
		setLoading( true );
		try {
			const data = await apiFetch( { url: `${ restUrl }/page/${ postId }/preview` } );
			setPreview( data );
		} finally {
			setLoading( false );
		}
	};

	const handleTabSelect = ( tab ) => {
		setActiveTab( tab );
		if ( tab === 'existing' ) fetchDetected();
		if ( tab === 'preview'  ) fetchPreview();
	};

	useEffect( () => { fetchDetected(); }, [] );

	return (
		<div className={ `som-meta-box${ isBlockEditor ? ' som-meta-box--sidebar' : '' }` }>
			{ loading && <div className="som-loading"><Spinner /></div> }
			<TabPanel tabs={ TABS } onSelect={ handleTabSelect } initialTabName={ activeTab }>
				{ ( tab ) => (
					<>
						{ tab.name === 'existing'    && <ExistingSchemaViewer detected={ detected } /> }
						{ tab.name === 'suppression' && (
							<SuppressionPanel
								postId={ postId }
								suppression={ suppression }
								detected={ detected }
								onChange={ setSuppression }
							/>
						) }
						{ tab.name === 'custom' && (
							<SchemaBuilder
								postId={ postId }
								schema={ schema }
								onChange={ setSchema }
							/>
						) }
						{ tab.name === 'preview' && <SchemaPreview preview={ preview } onRefresh={ fetchPreview } /> }
					</>
				) }
			</TabPanel>
		</div>
	);
}
