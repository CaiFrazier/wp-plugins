import { createRoot } from '@wordpress/element';
import { registerPlugin } from '@wordpress/plugins';
import { PluginSidebar, PluginSidebarMoreMenuItem } from '@wordpress/edit-post';
import { __ } from '@wordpress/i18n';
import App from './components/App';
import './index.css';

// Block Editor: sidebar panel. Only register for post types enabled in
// settings. The script is already enqueued solely on enabled types (see
// MetaBox::enqueue_assets), so this membership check is belt-and-suspenders —
// it also keeps the sidebar from registering if the localized data is absent.
const { postType, enabledPostTypes } = window.somMetaBox ?? {};
if ( Array.isArray( enabledPostTypes ) && enabledPostTypes.includes( postType ) ) {
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
}

// Classic Editor: meta box.
document.addEventListener( 'DOMContentLoaded', () => {
	const root = document.getElementById( 'som-meta-box-root' );
	if ( root ) {
		createRoot( root ).render( <App /> );
	}
} );
