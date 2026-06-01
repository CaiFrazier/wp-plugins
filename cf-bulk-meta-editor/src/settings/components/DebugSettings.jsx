import { ToggleControl, SelectControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

const LEVELS = [
	{ label: 'Debug (verbose)', value: 'debug' },
	{ label: 'Info',            value: 'info' },
	{ label: 'Warn',            value: 'warn' },
	{ label: 'Error',           value: 'error' },
];

export default function DebugSettings( { debugMode, logLevel, onDebugMode, onLogLevel } ) {
	return (
		<div className="bme-card">
			<h2>{ __( 'Diagnostic Logging', 'cf-bulk-meta-editor' ) }</h2>
			<p className="bme-card-help">
				{ __(
					'Captures every REST request, capability check, save attempt, and meta-key write to a JSONL file in uploads/bulk-meta-editor/. Visible at Tools → BME Diagnostics. Leave on while testing on staging; turn off in production once you trust the integration.',
					'cf-bulk-meta-editor'
				) }
			</p>

			<ToggleControl
				label={ __( 'Enable diagnostic logging', 'cf-bulk-meta-editor' ) }
				help={ __(
					'Force-enable via wp-config: define( "BME_DEBUG", true );',
					'cf-bulk-meta-editor'
				) }
				checked={ !! debugMode }
				onChange={ onDebugMode }
			/>

			<SelectControl
				label={ __( 'Log level', 'cf-bulk-meta-editor' ) }
				value={ logLevel || 'debug' }
				options={ LEVELS }
				onChange={ onLogLevel }
				disabled={ ! debugMode }
				help={ __(
					'When debug mode is off, only warnings and errors are recorded regardless of this setting.',
					'cf-bulk-meta-editor'
				) }
			/>
		</div>
	);
}
