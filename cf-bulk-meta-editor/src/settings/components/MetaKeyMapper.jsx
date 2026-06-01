import { TextControl, SelectControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

const PRESET_OPTIONS_BASE = [
	{ label: __( '— Select a preset —', 'cf-bulk-meta-editor' ), value: '' },
	{ label: 'Yoast SEO', value: 'yoast' },
	{ label: 'Rank Math', value: 'rankmath' },
	{ label: 'AIOSEO', value: 'aioseo' },
	{ label: 'The SEO Framework', value: 'seoframework' },
];

export default function MetaKeyMapper( { titleKey, descKey, presets, onTitleKey, onDescKey } ) {
	const applyPreset = ( slug, field ) => {
		if ( ! slug || ! presets[ slug ] ) return;
		if ( field === 'title' ) onTitleKey( presets[ slug ].title );
		if ( field === 'desc' ) onDescKey( presets[ slug ].desc );
	};

	const applyBoth = ( slug ) => {
		if ( ! slug || ! presets[ slug ] ) return;
		onTitleKey( presets[ slug ].title );
		onDescKey( presets[ slug ].desc );
	};

	return (
		<div className="bme-card">
			<h2>{ __( 'Meta Key Mapping', 'cf-bulk-meta-editor' ) }</h2>
			<p>{ __( 'Choose which postmeta keys store your SEO titles and descriptions. Use a preset or enter custom keys.', 'cf-bulk-meta-editor' ) }</p>

			<SelectControl
				label={ __( 'Apply preset to both fields', 'cf-bulk-meta-editor' ) }
				options={ PRESET_OPTIONS_BASE }
				value=""
				onChange={ applyBoth }
			/>

			<TextControl
				label={ __( 'Meta Title key', 'cf-bulk-meta-editor' ) }
				value={ titleKey }
				onChange={ onTitleKey }
				help={ __( 'e.g. _yoast_wpseo_title', 'cf-bulk-meta-editor' ) }
			/>

			<TextControl
				label={ __( 'Meta Description key', 'cf-bulk-meta-editor' ) }
				value={ descKey }
				onChange={ onDescKey }
				help={ __( 'e.g. _yoast_wpseo_metadesc', 'cf-bulk-meta-editor' ) }
			/>
		</div>
	);
}
