import { useState } from '@wordpress/element';
import { Button, CheckboxControl, ToggleControl, Notice, Spinner } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';

export default function SuppressionPanel( { postId, suppression, detected, onChange } ) {
	const [ saving, setSaving ] = useState( false );
	const [ notice, setNotice ] = useState( null );

	const { restUrl, knownTypes } = window.somMetaBox ?? {};

	// Offer a checkbox for every type live detection surfaced AND every known
	// type for the active SEO plugin, so per-type suppression is available even
	// when the filter-based detector returns nothing (common in the editor).
	// A type still ticked in stored rules but absent from both lists is kept so
	// the user can see and clear it.
	const unionTypes = ( group ) => {
		const live   = ( detected?.[ group ] ?? [] ).map( b => b.type );
		const known  = knownTypes?.[ group ] ?? [];
		const stored = suppression[ `${ group }_types` ] ?? [];
		return [ ...new Set( [ ...live, ...known, ...stored ].filter( Boolean ) ) ];
	};

	const yoastTypes = unionTypes( 'yoast' );
	const rmTypes    = unionTypes( 'rank_math' );

	const toggle = ( key ) => onChange( { ...suppression, [ key ]: ! suppression[ key ] } );

	const toggleType = ( group, type ) => {
		const current = suppression[ group ] ?? [];
		const next    = current.includes( type )
			? current.filter( t => t !== type )
			: [ ...current, type ];
		onChange( { ...suppression, [ group ]: next } );
	};

	const save = async () => {
		setSaving( true );
		setNotice( null );
		try {
			await apiFetch( {
				url: `${ restUrl }/page/${ postId }/suppression`,
				method: 'POST',
				data: suppression,
			} );
			setNotice( { type: 'success', message: __( 'Suppression rules saved.', 'schema-override-manager' ) } );
		} catch {
			setNotice( { type: 'error', message: __( 'Save failed.', 'schema-override-manager' ) } );
		} finally {
			setSaving( false );
		}
	};

	return (
		<div className="som-suppression-panel">
			{ notice && (
				<Notice status={ notice.type } isDismissible onRemove={ () => setNotice( null ) }>
					{ notice.message }
				</Notice>
			) }

			<p className="description">
				{ __( 'Per-page rules are added on top of global suppression rules.', 'schema-override-manager' ) }
			</p>

			<div className="som-suppression-section">
				<h4>{ __( 'Yoast SEO', 'schema-override-manager' ) }</h4>
				<ToggleControl
					label={ __( 'Suppress all Yoast schema', 'schema-override-manager' ) }
					checked={ !! suppression.yoast_all }
					onChange={ () => toggle( 'yoast_all' ) }
				/>
				{ ! suppression.yoast_all && yoastTypes.map( type => (
					<CheckboxControl
						key={ type }
						/* translators: %s is a schema.org type name, e.g. Article. */
						label={ sprintf( __( 'Suppress %s', 'schema-override-manager' ), type ) }
						checked={ ( suppression.yoast_types ?? [] ).includes( type ) }
						onChange={ () => toggleType( 'yoast_types', type ) }
					/>
				) ) }
			</div>

			<div className="som-suppression-section">
				<h4>{ __( 'Rank Math', 'schema-override-manager' ) }</h4>
				<ToggleControl
					label={ __( 'Suppress all Rank Math schema', 'schema-override-manager' ) }
					checked={ !! suppression.rank_math_all }
					onChange={ () => toggle( 'rank_math_all' ) }
				/>
				{ ! suppression.rank_math_all && rmTypes.map( type => (
					<CheckboxControl
						key={ type }
						/* translators: %s is a schema.org type name, e.g. Article. */
						label={ sprintf( __( 'Suppress %s', 'schema-override-manager' ), type ) }
						checked={ ( suppression.rank_math_types ?? [] ).includes( type ) }
						onChange={ () => toggleType( 'rank_math_types', type ) }
					/>
				) ) }
			</div>

			<div className="som-suppression-section">
				<h4>{ __( 'Theme / Other Plugins', 'schema-override-manager' ) }</h4>
				<ToggleControl
					label={ __( 'Suppress all theme/plugin JSON-LD', 'schema-override-manager' ) }
					checked={ !! suppression.theme_all }
					onChange={ () => toggle( 'theme_all' ) }
					help={ __( 'Requires output buffering to be enabled in plugin settings.', 'schema-override-manager' ) }
				/>
			</div>

			<Button variant="primary" onClick={ save } isBusy={ saving } disabled={ saving }>
				{ saving ? <Spinner /> : __( 'Save Suppression Rules', 'schema-override-manager' ) }
			</Button>
		</div>
	);
}
