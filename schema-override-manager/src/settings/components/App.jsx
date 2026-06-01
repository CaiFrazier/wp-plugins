import { useState } from '@wordpress/element';
import { TabPanel, Card, CardBody } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import SiteIdentityBuilder from './SiteIdentityBuilder';
import PostTypeTemplates from './PostTypeTemplates';
import GlobalSuppressionRules from './GlobalSuppressionRules';
import SchemaTypesReference from './SchemaTypesReference';
import PluginSettings from './PluginSettings';

const TABS = [
	{ name: 'site-identity',  title: __( 'Site Identity', 'schema-override-manager' ) },
	{ name: 'post-types',     title: __( 'Post Type Templates', 'schema-override-manager' ) },
	{ name: 'suppression',    title: __( 'Global Suppression', 'schema-override-manager' ) },
	{ name: 'plugin',         title: __( 'Plugin Settings', 'schema-override-manager' ) },
	{ name: 'reference',      title: __( 'Schema Types Reference', 'schema-override-manager' ) },
];

export default function App() {
	const [ activeTab, setActiveTab ] = useState( 'site-identity' );

	return (
		<div className="som-settings-wrap">
			<h1>{ __( 'Schema Override Manager', 'schema-override-manager' ) }</h1>
			<TabPanel
				tabs={ TABS }
				onSelect={ setActiveTab }
				initialTabName={ activeTab }
			>
				{ ( tab ) => (
					<Card>
						<CardBody>
							{ tab.name === 'site-identity' && <SiteIdentityBuilder /> }
							{ tab.name === 'post-types'    && <PostTypeTemplates /> }
							{ tab.name === 'suppression'   && <GlobalSuppressionRules /> }
							{ tab.name === 'plugin'        && <PluginSettings /> }
							{ tab.name === 'reference'     && <SchemaTypesReference /> }
						</CardBody>
					</Card>
				) }
			</TabPanel>
		</div>
	);
}
