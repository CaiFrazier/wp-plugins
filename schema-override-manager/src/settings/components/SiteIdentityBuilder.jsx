import { useState } from '@wordpress/element';
import {
	Button, TextControl, TextareaControl, SelectControl,
	ToggleControl, Notice, Spinner, PanelBody, PanelRow,
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import LocalBusinessFields from './LocalBusinessFields';

const ROOT_TYPE_OPTIONS = [
	{ label: __( 'Organization', 'schema-override-manager' ), value: 'Organization' },
	{ label: __( 'LocalBusiness', 'schema-override-manager' ), value: 'LocalBusiness' },
];

/**
 * Storage shape (an array of blocks, matching SchemaOutput::collect_schema_blocks):
 *   [
 *     { '@type': 'Organization' | 'LocalBusiness', '_som_root': true, ...orgFields },
 *     { '@type': 'WebSite', ...websiteFields }
 *   ]
 *
 * Form shape (internal to this component):
 *   { rootType, org, website, localBusiness }
 */

function blocksToForm( blocks ) {
	const form = {
		rootType:      'Organization',
		org:           {},
		website:       {},
		localBusiness: {},
	};

	if ( ! Array.isArray( blocks ) ) return form;

	for ( const block of blocks ) {
		if ( ! block || typeof block !== 'object' ) continue;
		const type = block['@type'];

		if ( type === 'Organization' ) {
			form.rootType = 'Organization';
			form.org = stripMeta( block );
		} else if ( type === 'LocalBusiness' || isLocalBusinessSubtype( type ) ) {
			form.rootType = 'LocalBusiness';
			// Pull org fields out, then the rest into localBusiness.
			const { name, url, logo, description, email, telephone, sameAs, ...rest } = stripMeta( block );
			form.org = { name, url, logo, description, email, telephone, sameAs };
			form.localBusiness = { subtype: type, ...rest };
		} else if ( type === 'WebSite' ) {
			form.website = stripMeta( block );
		}
	}

	return form;
}

function formToBlocks( form ) {
	const blocks = [];

	const orgFields = pruneEmpty( form.org ?? {} );
	if ( Object.keys( orgFields ).length > 0 ) {
		if ( form.rootType === 'LocalBusiness' ) {
			const lb = pruneEmpty( form.localBusiness ?? {} );
			const { subtype, ...lbRest } = lb;
			blocks.push( {
				'@type': subtype || 'LocalBusiness',
				...orgFields,
				...lbRest,
			} );
		} else {
			blocks.push( { '@type': 'Organization', ...orgFields } );
		}
	}

	const websiteFields = pruneEmpty( form.website ?? {} );
	if ( Object.keys( websiteFields ).length > 0 || websiteFields.searchAction ) {
		const block = { '@type': 'WebSite', ...websiteFields };
		if ( websiteFields.searchAction ) {
			delete block.searchAction;
			block.potentialAction = {
				'@type':       'SearchAction',
				target:        {
					'@type':       'EntryPoint',
					urlTemplate:   `${ window.location.origin }/?s={search_term_string}`,
				},
				'query-input': 'required name=search_term_string',
			};
		}
		blocks.push( block );
	}

	return blocks;
}

function stripMeta( obj ) {
	const { '@type': _t, '@context': _c, _som_root: _r, ...rest } = obj;
	return rest;
}

function pruneEmpty( obj ) {
	const out = {};
	for ( const [ k, v ] of Object.entries( obj ) ) {
		if ( v === undefined || v === null || v === '' ) continue;
		if ( Array.isArray( v ) && v.length === 0 ) continue;
		if ( typeof v === 'object' && ! Array.isArray( v ) && Object.keys( pruneEmpty( v ) ).length === 0 ) continue;
		out[ k ] = v;
	}
	return out;
}

function isLocalBusinessSubtype( type ) {
	return [
		'Restaurant', 'BarOrPub', 'Bakery', 'CafeOrCoffeeShop', 'MedicalBusiness',
		'Dentist', 'Physician', 'LegalService', 'Attorney', 'FinancialService',
		'RealEstateAgent', 'AutoDealer', 'HairSalon', 'Store', 'HotelOrMotel',
		'SportsActivityLocation', 'ShoppingCenter',
	].includes( type );
}

export default function SiteIdentityBuilder() {
	const [ form, setForm ]     = useState( () => blocksToForm( window.somSettings?.global ) );
	const [ saving, setSaving ] = useState( false );
	const [ notice, setNotice ] = useState( null );

	const update         = ( key, value ) => setForm( prev => ( { ...prev, [ key ]: value } ) );
	const updateOrg      = ( key, value ) => update( 'org', { ...form.org, [ key ]: value } );
	const updateWebsite  = ( key, value ) => update( 'website', { ...form.website, [ key ]: value } );

	const save = async () => {
		setSaving( true );
		setNotice( null );
		try {
			const blocks = formToBlocks( form );
			await apiFetch( {
				url: window.somSettings.restUrl + '/global-schema',
				method: 'POST',
				data: blocks,
			} );
			setNotice( { type: 'success', message: __( 'Saved.', 'schema-override-manager' ) } );
		} catch ( e ) {
			setNotice( { type: 'error', message: e?.message ?? __( 'Save failed.', 'schema-override-manager' ) } );
		} finally {
			setSaving( false );
		}
	};

	return (
		<div className="som-site-identity">
			{ notice && (
				<Notice status={ notice.type } isDismissible onRemove={ () => setNotice( null ) }>
					{ notice.message }
				</Notice>
			) }

			<PanelBody title={ __( 'Root Entity Type', 'schema-override-manager' ) } initialOpen>
				<PanelRow>
					<SelectControl
						label={ __( 'Root schema type', 'schema-override-manager' ) }
						value={ form.rootType }
						options={ ROOT_TYPE_OPTIONS }
						onChange={ v => update( 'rootType', v ) }
						help={ __( 'LocalBusiness extends Organization. Choose one — they are mutually exclusive.', 'schema-override-manager' ) }
					/>
				</PanelRow>
			</PanelBody>

			<PanelBody title={ __( 'Organization', 'schema-override-manager' ) } initialOpen>
				<TextControl label={ __( 'Name', 'schema-override-manager' ) }
					value={ form.org.name ?? '' } onChange={ v => updateOrg( 'name', v ) } />
				<TextControl label={ __( 'URL', 'schema-override-manager' ) } type="url"
					value={ form.org.url ?? '' } onChange={ v => updateOrg( 'url', v ) } />
				<TextControl label={ __( 'Logo URL', 'schema-override-manager' ) } type="url"
					value={ form.org.logo ?? '' } onChange={ v => updateOrg( 'logo', v ) } />
				<TextareaControl label={ __( 'Description', 'schema-override-manager' ) }
					value={ form.org.description ?? '' } onChange={ v => updateOrg( 'description', v ) } />
				<TextControl label={ __( 'Email', 'schema-override-manager' ) }
					value={ form.org.email ?? '' } onChange={ v => updateOrg( 'email', v ) } />
				<TextControl label={ __( 'Phone', 'schema-override-manager' ) }
					value={ form.org.telephone ?? '' } onChange={ v => updateOrg( 'telephone', v ) } />

				<p><strong>{ __( 'Social Profiles (sameAs)', 'schema-override-manager' ) }</strong></p>
				{ [ 'twitter', 'facebook', 'linkedin', 'instagram', 'youtube' ].map( platform => (
					<TextControl key={ platform } type="url"
						label={ platform.charAt( 0 ).toUpperCase() + platform.slice( 1 ) }
						value={ form.org.sameAs?.[ platform ] ?? '' }
						onChange={ v => updateOrg( 'sameAs', { ...( form.org.sameAs ?? {} ), [ platform ]: v } ) } />
				) ) }
			</PanelBody>

			{ form.rootType === 'LocalBusiness' && (
				<LocalBusinessFields data={ form.localBusiness } onChange={ v => update( 'localBusiness', v ) } />
			) }

			<PanelBody title={ __( 'WebSite', 'schema-override-manager' ) } initialOpen={ false }>
				<TextControl label={ __( 'Site Name', 'schema-override-manager' ) }
					value={ form.website.name ?? '' } onChange={ v => updateWebsite( 'name', v ) } />
				<TextControl label={ __( 'URL', 'schema-override-manager' ) } type="url"
					value={ form.website.url ?? '' } onChange={ v => updateWebsite( 'url', v ) } />
				<ToggleControl
					label={ __( 'Enable SearchAction (Sitelinks Search Box)', 'schema-override-manager' ) }
					checked={ !! form.website.searchAction }
					onChange={ v => updateWebsite( 'searchAction', v ) }
					help={ __( 'Adds potentialAction for Google Sitelinks Search Box.', 'schema-override-manager' ) }
				/>
			</PanelBody>

			<div className="som-save-row">
				<Button variant="primary" onClick={ save } isBusy={ saving } disabled={ saving }>
					{ saving ? <Spinner /> : __( 'Save Site Identity', 'schema-override-manager' ) }
				</Button>
			</div>
		</div>
	);
}
