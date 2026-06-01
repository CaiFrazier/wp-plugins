import { useState } from '@wordpress/element';
import { Button, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import apiFetch from '../../shared/api';
import bmeLog from '../../shared/logger';
import MetaKeyMapper from './MetaKeyMapper';
import PostTypeList from './PostTypeList';
import CustomColumnRepeater from './CustomColumnRepeater';
import DebugSettings from './DebugSettings';

// Default to {} so the bundle doesn't crash at import time if loaded outside
// the settings page. The screen will render with empty state until real
// localized data arrives.
const {
	apiNamespace,
	settings: initialSettings = {},
	postTypes = {},
	presets = {},
} = window.bmeSettingsData ?? {};

export default function App() {
	const [ settings, setSettings ] = useState( initialSettings );
	const [ saving, setSaving ]     = useState( false );
	const [ notice, setNotice ]     = useState( null );

	const update = ( key, value ) => setSettings( ( s ) => ( { ...s, [ key ]: value } ) );

	const save = async () => {
		setSaving( true );
		bmeLog.info( 'settings', 'save begin' );
		try {
			const updated = await apiFetch( {
				path: `/${ apiNamespace }/settings`,
				method: 'POST',
				data: settings,
			} );
			setSettings( updated );
			setNotice( { type: 'success', message: __( 'Settings saved.', 'cf-bulk-meta-editor' ) } );
			bmeLog.info( 'settings', 'save success' );
		} catch ( e ) {
			setNotice( { type: 'error', message: e.message || __( 'Save failed.', 'cf-bulk-meta-editor' ) } );
			bmeLog.error( 'settings', 'save failed', { message: e.message } );
		} finally {
			setSaving( false );
		}
	};

	return (
		<div className="bme-settings">
			<h1>{ __( 'Bulk Meta Editor Settings', 'cf-bulk-meta-editor' ) }</h1>

			{ notice && (
				<Notice status={ notice.type } onRemove={ () => setNotice( null ) }>
					{ notice.message }
				</Notice>
			) }

			<MetaKeyMapper
				titleKey={ settings.meta_title_key }
				descKey={ settings.meta_desc_key }
				presets={ presets }
				onTitleKey={ ( v ) => update( 'meta_title_key', v ) }
				onDescKey={ ( v ) => update( 'meta_desc_key', v ) }
			/>

			<PostTypeList
				postTypes={ postTypes }
				enabled={ settings.enabled_types }
				onChange={ ( v ) => update( 'enabled_types', v ) }
			/>

			<CustomColumnRepeater
				columns={ settings.custom_columns }
				onChange={ ( v ) => update( 'custom_columns', v ) }
			/>

			<DebugSettings
				debugMode={ settings.debug_mode }
				logLevel={ settings.log_level }
				onDebugMode={ ( v ) => update( 'debug_mode', v ) }
				onLogLevel={ ( v ) => update( 'log_level', v ) }
			/>

			<div className="bme-settings-footer">
				<Button variant="primary" onClick={ save } isBusy={ saving } disabled={ saving }>
					{ __( 'Save Settings', 'cf-bulk-meta-editor' ) }
				</Button>
			</div>
		</div>
	);
}
