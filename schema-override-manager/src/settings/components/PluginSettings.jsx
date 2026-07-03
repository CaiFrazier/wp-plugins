import { useState } from '@wordpress/element';
import {
	Button, CheckboxControl, ToggleControl, Notice, Spinner,
	PanelBody, RangeControl, Modal, Flex, FlexItem,
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

const DEFAULT_SETTINGS = {
	enabled_post_types:   [ 'post', 'page' ],
	output_priority:      5,
	theme_suppression_ob: false,
};

export default function PluginSettings() {
	const initial = {
		...DEFAULT_SETTINGS,
		...( window.somSettings?.settings ?? {} ),
	};

	const [ settings, setSettings ] = useState( initial );
	const [ saving, setSaving ]     = useState( false );
	const [ notice, setNotice ]     = useState( null );
	const [ confirmingOb, setConfirmingOb ] = useState( false );

	const allPostTypes = window.somSettings?.allPostTypes ?? [];

	const update = ( key, value ) => setSettings( prev => ( { ...prev, [ key ]: value } ) );

	// Turning OB on is the risky direction (it can break a page's <head>), so
	// it goes through a confirmation modal. Turning it off is immediate.
	const onToggleOb = ( next ) => {
		if ( next ) {
			setConfirmingOb( true );
		} else {
			update( 'theme_suppression_ob', false );
		}
	};

	const confirmOb = () => {
		update( 'theme_suppression_ob', true );
		setConfirmingOb( false );
	};

	const togglePostType = ( slug ) => {
		const current = settings.enabled_post_types ?? [];
		const next    = current.includes( slug )
			? current.filter( s => s !== slug )
			: [ ...current, slug ];
		update( 'enabled_post_types', next );
	};

	const save = async () => {
		setSaving( true );
		setNotice( null );
		try {
			await apiFetch( {
				url: window.somSettings.restUrl + '/settings',
				method: 'POST',
				data: settings,
			} );
			setNotice( {
				type: 'success',
				message: __( 'Settings saved. Reload any open editor tabs for changes to take effect.', 'schema-override-manager' ),
			} );
		} catch {
			setNotice( { type: 'error', message: __( 'Save failed.', 'schema-override-manager' ) } );
		} finally {
			setSaving( false );
		}
	};

	return (
		<div className="som-plugin-settings">
			{ notice && (
				<Notice status={ notice.type } isDismissible onRemove={ () => setNotice( null ) }>
					{ notice.message }
				</Notice>
			) }

			<PanelBody title={ __( 'Enabled Post Types', 'schema-override-manager' ) } initialOpen>
				<p className="description">
					{ __( 'The Schema Override meta box and Block Editor sidebar panel will appear on these post types.', 'schema-override-manager' ) }
				</p>
				{ allPostTypes.map( ( pt ) => (
					<CheckboxControl
						key={ pt.slug }
						label={ `${ pt.label } (${ pt.slug })` }
						checked={ ( settings.enabled_post_types ?? [] ).includes( pt.slug ) }
						onChange={ () => togglePostType( pt.slug ) }
					/>
				) ) }
			</PanelBody>

			<PanelBody title={ __( 'Output Priority', 'schema-override-manager' ) } initialOpen={ false }>
				<RangeControl
					label={ __( 'wp_head priority for our JSON-LD', 'schema-override-manager' ) }
					min={ 1 }
					max={ 100 }
					value={ settings.output_priority }
					onChange={ v => update( 'output_priority', v ?? 5 ) }
					help={ __( 'Lower runs earlier. Default 5 ensures our output appears before most themes (which use 10).', 'schema-override-manager' ) }
				/>
			</PanelBody>

			<PanelBody title={ __( 'Advanced', 'schema-override-manager' ) } initialOpen={ false }>
				<h4>{ __( 'Theme Suppression (Output Buffering)', 'schema-override-manager' ) }</h4>
				<Notice status="warning" isDismissible={ false }>
					{ __( 'This is the master switch for output-buffering-based suppression of theme/plugin JSON-LD. It is required for any "Suppress all theme JSON-LD" rule to take effect. Output buffering has a small performance cost — leave off unless you need it. If something on your site breaks, return here and turn it off.', 'schema-override-manager' ) }
				</Notice>
				<ToggleControl
					label={ __( 'Enable theme-suppression output buffering', 'schema-override-manager' ) }
					checked={ !! settings.theme_suppression_ob }
					onChange={ onToggleOb }
				/>
			</PanelBody>

			{ confirmingOb && (
				<Modal
					title={ __( 'Enable output buffering?', 'schema-override-manager' ) }
					onRequestClose={ () => setConfirmingOb( false ) }
				>
					<p>
						{ __( 'Output buffering captures your entire page <head> so foreign JSON-LD can be stripped from it. On most sites this is safe, but a theme or plugin that manipulates the same buffer can, in rare cases, break page output. The plugin detects a corrupted buffer and bails to protect the page, but you should verify your site after enabling — especially the front page and a typical post.', 'schema-override-manager' ) }
					</p>
					<p>
						{ __( 'You can turn this off again here at any time. The change takes effect after you click "Save Plugin Settings".', 'schema-override-manager' ) }
					</p>
					<Flex justify="flex-end">
						<FlexItem>
							<Button variant="tertiary" onClick={ () => setConfirmingOb( false ) }>
								{ __( 'Cancel', 'schema-override-manager' ) }
							</Button>
						</FlexItem>
						<FlexItem>
							<Button variant="primary" onClick={ confirmOb }>
								{ __( 'Enable output buffering', 'schema-override-manager' ) }
							</Button>
						</FlexItem>
					</Flex>
				</Modal>
			) }

			<div className="som-save-row">
				<Button variant="primary" onClick={ save } isBusy={ saving } disabled={ saving }>
					{ saving ? <Spinner /> : __( 'Save Plugin Settings', 'schema-override-manager' ) }
				</Button>
			</div>
		</div>
	);
}
