import { useState } from '@wordpress/element';
import { Button, ToggleControl, CheckboxControl, Notice, Spinner, PanelBody } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

const YOAST_TYPES  = [ 'WebSite', 'WebPage', 'Article', 'BreadcrumbList', 'Person', 'Organization' ];
const RM_TYPES     = [ 'WebSite', 'WebPage', 'Article', 'BreadcrumbList', 'Person', 'Organization', 'Product', 'FAQPage' ];

export default function GlobalSuppressionRules() {
	const [ rules, setRules ]   = useState( window.somSettings?.suppress ?? {} );
	const [ saving, setSaving ] = useState( false );
	const [ notice, setNotice ] = useState( null );

	const toggle = ( key ) => setRules( prev => ( { ...prev, [ key ]: ! prev[ key ] } ) );

	const toggleType = ( group, type ) => {
		const current = rules[ group ] ?? [];
		const next    = current.includes( type )
			? current.filter( t => t !== type )
			: [ ...current, type ];
		setRules( prev => ( { ...prev, [ group ]: next } ) );
	};

	const save = async () => {
		setSaving( true );
		setNotice( null );
		try {
			await apiFetch( {
				url: window.somSettings.restUrl + '/global-suppression',
				method: 'POST',
				data: rules,
			} );
			setNotice( { type: 'success', message: __( 'Suppression rules saved.', 'schema-override-manager' ) } );
		} catch {
			setNotice( { type: 'error', message: __( 'Save failed.', 'schema-override-manager' ) } );
		} finally {
			setSaving( false );
		}
	};

	return (
		<div className="som-global-suppression">
			{ notice && (
				<Notice status={ notice.type } isDismissible onRemove={ () => setNotice( null ) }>
					{ notice.message }
				</Notice>
			) }

			<PanelBody title={ __( 'Yoast SEO', 'schema-override-manager' ) } initialOpen>
				<ToggleControl
					label={ __( 'Suppress ALL Yoast schema', 'schema-override-manager' ) }
					checked={ !! rules.yoast_all }
					onChange={ () => toggle( 'yoast_all' ) }
				/>
				{ ! rules.yoast_all && (
					<>
						<p><strong>{ __( 'Suppress specific types:', 'schema-override-manager' ) }</strong></p>
						{ YOAST_TYPES.map( type => (
							<CheckboxControl
								key={ type }
								label={ type }
								checked={ ( rules.yoast_types ?? [] ).includes( type ) }
								onChange={ () => toggleType( 'yoast_types', type ) }
							/>
						) ) }
					</>
				) }
			</PanelBody>

			<PanelBody title={ __( 'Rank Math', 'schema-override-manager' ) } initialOpen>
				<ToggleControl
					label={ __( 'Suppress ALL Rank Math schema', 'schema-override-manager' ) }
					checked={ !! rules.rank_math_all }
					onChange={ () => toggle( 'rank_math_all' ) }
				/>
				{ ! rules.rank_math_all && (
					<>
						<p><strong>{ __( 'Suppress specific types:', 'schema-override-manager' ) }</strong></p>
						{ RM_TYPES.map( type => (
							<CheckboxControl
								key={ type }
								label={ type }
								checked={ ( rules.rank_math_types ?? [] ).includes( type ) }
								onChange={ () => toggleType( 'rank_math_types', type ) }
							/>
						) ) }
					</>
				) }
			</PanelBody>

			<PanelBody title={ __( 'Theme / Other Plugins', 'schema-override-manager' ) } initialOpen>
				<ToggleControl
					label={ __( 'Suppress all theme/plugin JSON-LD (output buffering)', 'schema-override-manager' ) }
					checked={ !! rules.theme_all }
					onChange={ () => toggle( 'theme_all' ) }
					help={ __( 'Requires "Enable theme suppression output buffering" to be on in plugin settings. Use with caution.', 'schema-override-manager' ) }
				/>
			</PanelBody>

			<div className="som-save-row">
				<Button variant="primary" onClick={ save } isBusy={ saving } disabled={ saving }>
					{ saving ? <Spinner /> : __( 'Save Suppression Rules', 'schema-override-manager' ) }
				</Button>
			</div>
		</div>
	);
}
