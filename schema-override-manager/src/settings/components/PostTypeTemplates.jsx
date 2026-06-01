import { useState } from '@wordpress/element';
import { Button, SelectControl, Notice, Spinner, PanelBody } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

const SCHEMA_TYPE_OPTIONS = [
	{ label: __( '— None —', 'schema-override-manager' ), value: '' },
	{ label: 'Article',        value: 'Article' },
	{ label: 'BlogPosting',    value: 'BlogPosting' },
	{ label: 'NewsArticle',    value: 'NewsArticle' },
	{ label: 'WebPage',        value: 'WebPage' },
	{ label: 'AboutPage',      value: 'AboutPage' },
	{ label: 'ContactPage',    value: 'ContactPage' },
	{ label: 'FAQPage',        value: 'FAQPage' },
	{ label: 'Product',        value: 'Product' },
	{ label: 'Service',        value: 'Service' },
	{ label: 'Person',         value: 'Person' },
];

export default function PostTypeTemplates() {
	const postTypes = window.somSettings?.postTypes ?? [];
	const [ templates, setTemplates ] = useState(
		Object.fromEntries( postTypes.map( pt => [ pt.slug, pt.template ] ) )
	);
	const [ saving, setSaving ] = useState( null );
	const [ notice, setNotice ] = useState( null );

	const save = async ( slug ) => {
		setSaving( slug );
		setNotice( null );
		try {
			await apiFetch( {
				url: `${ window.somSettings.restUrl }/template/${ slug }`,
				method: 'POST',
				data: templates[ slug ] ?? {},
			} );
			setNotice( { type: 'success', message: __( 'Template saved.', 'schema-override-manager' ) } );
		} catch {
			setNotice( { type: 'error', message: __( 'Save failed.', 'schema-override-manager' ) } );
		} finally {
			setSaving( null );
		}
	};

	return (
		<div className="som-post-type-templates">
			<p className="description">
				{ __( 'Set the default schema type for each post type. These apply to all posts unless overridden per-page.', 'schema-override-manager' ) }
			</p>

			{ notice && (
				<Notice status={ notice.type } isDismissible onRemove={ () => setNotice( null ) }>
					{ notice.message }
				</Notice>
			) }

			{ postTypes.map( pt => (
				<PanelBody key={ pt.slug } title={ pt.label } initialOpen={ false }>
					<SelectControl
						label={ __( 'Default schema type', 'schema-override-manager' ) }
						value={ templates[ pt.slug ]?.defaultType ?? '' }
						options={ SCHEMA_TYPE_OPTIONS }
						onChange={ v => setTemplates( prev => ( {
							...prev,
							[ pt.slug ]: { ...( prev[ pt.slug ] ?? {} ), defaultType: v },
						} ) ) }
					/>
					<Button
						variant="primary"
						isSmall
						onClick={ () => save( pt.slug ) }
						isBusy={ saving === pt.slug }
						disabled={ !! saving }
					>
						{ saving === pt.slug ? <Spinner /> : __( 'Save', 'schema-override-manager' ) }
					</Button>
				</PanelBody>
			) ) }
		</div>
	);
}
