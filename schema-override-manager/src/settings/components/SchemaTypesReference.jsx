import { __ } from '@wordpress/i18n';

const TYPES = [
	{ type: 'WebSite',        note: 'SearchAction support for Sitelinks Search Box' },
	{ type: 'Organization',   note: 'With sameAs social profiles' },
	{ type: 'LocalBusiness',  note: 'Subtypes: Restaurant, MedicalBusiness, etc.' },
	{ type: 'WebPage',        note: 'Subtypes: AboutPage, ContactPage, FAQPage' },
	{ type: 'Article',        note: 'Subtypes: BlogPosting, NewsArticle' },
	{ type: 'FAQPage',        note: 'With Question/Answer pairs' },
	{ type: 'BreadcrumbList', note: 'Auto-generated from permalink structure, overridable' },
	{ type: 'Person',         note: 'For author pages' },
	{ type: 'Product',        note: 'Basic: name, description, image, offers' },
	{ type: 'Service',        note: 'name, description, provider, areaServed' },
];

export default function SchemaTypesReference() {
	return (
		<div className="som-reference">
			<h2>{ __( 'Supported Schema Types — Phase 1', 'schema-override-manager' ) }</h2>
			<p className="description">
				{ __( 'Phase 2 candidates: Review, Event, HowTo, Course, JobPosting.', 'schema-override-manager' ) }
			</p>
			<table className="widefat striped">
				<thead>
					<tr>
						<th>{ __( 'Type', 'schema-override-manager' ) }</th>
						<th>{ __( 'Notes', 'schema-override-manager' ) }</th>
						<th>{ __( 'schema.org', 'schema-override-manager' ) }</th>
					</tr>
				</thead>
				<tbody>
					{ TYPES.map( ( { type, note } ) => (
						<tr key={ type }>
							<td><code>{ type }</code></td>
							<td>{ note }</td>
							<td>
								<a
									href={ `https://schema.org/${ type }` }
									target="_blank"
									rel="noopener noreferrer"
								>
									schema.org/{ type }
								</a>
							</td>
						</tr>
					) ) }
				</tbody>
			</table>
		</div>
	);
}
