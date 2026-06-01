import { createRoot } from '@wordpress/element';
import { registerPlugin } from '@wordpress/plugins';
import { PluginSidebar, PluginSidebarMoreMenuItem } from '@wordpress/block-editor';
import { __ } from '@wordpress/i18n';
import App from './components/App';
import './index.css';

// Block Editor: sidebar panel.
registerPlugin( 'schema-override-manager', {
	render: () => (
		<>
			<PluginSidebarMoreMenuItem target="schema-override-manager-sidebar">
				{ __( 'Schema Override', 'schema-override-manager' ) }
			</PluginSidebarMoreMenuItem>
			<PluginSidebar name="schema-override-manager-sidebar" title={ __( 'Schema Override', 'schema-override-manager' ) }>
				<App isBlockEditor />
			</PluginSidebar>
		</>
	),
} );

// Classic Editor: meta box.
document.addEventListener( 'DOMContentLoaded', () => {
	const root = document.getElementById( 'som-meta-box-root' );
	if ( root ) {
		createRoot( root ).render( <App /> );
	}
} );
