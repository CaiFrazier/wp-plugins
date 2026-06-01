/**
 * Schema type registry — single source of truth for the per-page builder.
 * Type list is derived from Cartographer's RELEVANT_SCHEMA_TYPES so the two
 * systems agree on what's "worth showing" to editors.
 *
 * Each entry can declare a `fields` array of field descriptors. Types without
 * a fields array fall back to the generic property editor (key/value rows).
 */

import { __ } from '@wordpress/i18n';

const COMMON = [
	{ key: 'name',        label: 'Name',        type: 'text' },
	{ key: 'url',         label: 'URL',         type: 'url' },
	{ key: 'description', label: 'Description', type: 'textarea' },
];

const ARTICLE_FIELDS = [
	{ key: 'headline',      label: 'Headline',       type: 'text' },
	{ key: 'description',   label: 'Description',    type: 'textarea' },
	{ key: 'image',         label: 'Image URL',      type: 'url' },
	{ key: 'author',        label: 'Author',         type: 'text' },
	{ key: 'datePublished', label: 'Date Published', type: 'date' },
	{ key: 'dateModified',  label: 'Date Modified',  type: 'date' },
];

const ORG_FIELDS = [
	...COMMON,
	{ key: 'logo',      label: 'Logo URL', type: 'url' },
	{ key: 'telephone', label: 'Phone',    type: 'text' },
	{ key: 'email',     label: 'Email',    type: 'text' },
];

const PERSON_FIELDS = [
	...COMMON,
	{ key: 'image',    label: 'Image URL',  type: 'url' },
	{ key: 'jobTitle', label: 'Job Title',  type: 'text' },
	{ key: 'email',    label: 'Email',      type: 'text' },
];

const PRODUCT_FIELDS = [
	...COMMON,
	{ key: 'image',         label: 'Image URL', type: 'url' },
	{ key: 'sku',           label: 'SKU',       type: 'text' },
	{ key: 'brand',         label: 'Brand',     type: 'text' },
	{ key: 'price',         label: 'Price',     type: 'text' },
	{ key: 'priceCurrency', label: 'Currency',  type: 'text', placeholder: 'USD' },
];

const SERVICE_FIELDS = [
	...COMMON,
	{ key: 'provider',   label: 'Provider',   type: 'text' },
	{ key: 'areaServed', label: 'Area Served', type: 'text' },
	{ key: 'serviceType', label: 'Service Type', type: 'text' },
];

const EVENT_FIELDS = [
	...COMMON,
	{ key: 'startDate',   label: 'Start Date', type: 'date' },
	{ key: 'endDate',     label: 'End Date',   type: 'date' },
	{ key: 'location',    label: 'Location',   type: 'text' },
	{ key: 'image',       label: 'Image URL',  type: 'url' },
];

const VIDEO_FIELDS = [
	...COMMON,
	{ key: 'thumbnailUrl',  label: 'Thumbnail URL', type: 'url' },
	{ key: 'uploadDate',    label: 'Upload Date',   type: 'date' },
	{ key: 'duration',      label: 'Duration (ISO 8601)', type: 'text', placeholder: 'PT1M30S' },
	{ key: 'contentUrl',    label: 'Content URL',   type: 'url' },
	{ key: 'embedUrl',      label: 'Embed URL',     type: 'url' },
];

const RECIPE_FIELDS = [
	...COMMON,
	{ key: 'image',           label: 'Image URL',     type: 'url' },
	{ key: 'author',          label: 'Author',        type: 'text' },
	{ key: 'prepTime',        label: 'Prep Time',     type: 'text', placeholder: 'PT15M' },
	{ key: 'cookTime',        label: 'Cook Time',     type: 'text', placeholder: 'PT30M' },
	{ key: 'recipeYield',     label: 'Yield',         type: 'text' },
	{ key: 'recipeCategory',  label: 'Category',      type: 'text' },
];

const HOWTO_FIELDS = [
	...COMMON,
	{ key: 'image',     label: 'Image URL',  type: 'url' },
	{ key: 'totalTime', label: 'Total Time', type: 'text', placeholder: 'PT30M' },
];

const JOB_FIELDS = [
	{ key: 'title',          label: 'Job Title',         type: 'text' },
	{ key: 'description',    label: 'Description',       type: 'textarea' },
	{ key: 'datePosted',     label: 'Date Posted',       type: 'date' },
	{ key: 'validThrough',   label: 'Valid Through',     type: 'date' },
	{ key: 'employmentType', label: 'Employment Type',   type: 'text', placeholder: 'FULL_TIME' },
	{ key: 'hiringOrganization', label: 'Hiring Org Name', type: 'text' },
];

const REVIEW_FIELDS = [
	{ key: 'name',          label: 'Review Name',  type: 'text' },
	{ key: 'reviewBody',    label: 'Review Body',  type: 'textarea' },
	{ key: 'author',        label: 'Author',       type: 'text' },
	{ key: 'reviewRating',  label: 'Rating (1-5)', type: 'text' },
	{ key: 'itemReviewed',  label: 'Item Name',    type: 'text' },
];

const COURSE_FIELDS = [
	...COMMON,
	{ key: 'provider', label: 'Provider', type: 'text' },
];

/**
 * The full registry. Keys = canonical schema.org @type names.
 * Special types (FAQPage, BreadcrumbList) opt into custom UI via `editor: 'faq' | 'breadcrumbs'`.
 */
export const SCHEMA_TYPE_REGISTRY = {
	// Articles.
	Article:           { fields: ARTICLE_FIELDS },
	BlogPosting:       { fields: ARTICLE_FIELDS },
	NewsArticle:       { fields: ARTICLE_FIELDS },
	TechArticle:       { fields: ARTICLE_FIELDS },
	ScholarlyArticle:  { fields: ARTICLE_FIELDS },
	ClaimReview:       { fields: ARTICLE_FIELDS },

	// Pages.
	WebPage:           { fields: COMMON },
	AboutPage:         { fields: COMMON },
	ContactPage:       { fields: COMMON },
	CheckoutPage:      { fields: COMMON },
	CollectionPage:    { fields: COMMON },
	ItemPage:          { fields: COMMON },
	SearchResultsPage: { fields: COMMON },
	ProfilePage:       { fields: COMMON },
	MedicalWebPage:    { fields: COMMON },

	// Site.
	WebSite: { fields: [
		{ key: 'name',         label: 'Site Name',  type: 'text' },
		{ key: 'url',          label: 'URL',        type: 'url' },
		{ key: 'inLanguage',   label: 'Language',   type: 'text' },
	] },

	// Special editors.
	FAQPage:        { editor: 'faq' },
	BreadcrumbList: { editor: 'breadcrumbs' },
	HowTo:          { fields: HOWTO_FIELDS },

	// People.
	Person: { fields: PERSON_FIELDS },

	// Organizations.
	Organization:            { fields: ORG_FIELDS },
	Corporation:             { fields: ORG_FIELDS },
	LocalBusiness:           { fields: ORG_FIELDS },
	Store:                   { fields: ORG_FIELDS },
	GovernmentOrganization:  { fields: ORG_FIELDS },
	EducationalOrganization: { fields: ORG_FIELDS },
	MedicalOrganization:     { fields: ORG_FIELDS },
	NGO:                     { fields: ORG_FIELDS },
	PerformingGroup:         { fields: ORG_FIELDS },
	SportsOrganization:      { fields: ORG_FIELDS },

	// LocalBusiness subtypes.
	Restaurant:        { fields: ORG_FIELDS },
	Hotel:             { fields: ORG_FIELDS },
	Hospital:          { fields: ORG_FIELDS },
	School:            { fields: ORG_FIELDS },
	Library:           { fields: ORG_FIELDS },
	Museum:            { fields: ORG_FIELDS },
	ShoppingCenter:    { fields: ORG_FIELDS },
	TouristAttraction: { fields: ORG_FIELDS },
	FoodEstablishment: { fields: ORG_FIELDS },
	LodgingBusiness:   { fields: ORG_FIELDS },

	// Commerce.
	Product:        { fields: PRODUCT_FIELDS },
	Offer:          { fields: [
		{ key: 'price',         label: 'Price',         type: 'text' },
		{ key: 'priceCurrency', label: 'Currency',      type: 'text', placeholder: 'USD' },
		{ key: 'url',           label: 'URL',           type: 'url' },
		{ key: 'availability',  label: 'Availability',  type: 'select', options: [
			{ label: '— select —',     value: '' },
			{ label: 'In Stock',        value: 'https://schema.org/InStock' },
			{ label: 'Out of Stock',    value: 'https://schema.org/OutOfStock' },
			{ label: 'Pre-Order',       value: 'https://schema.org/PreOrder' },
			{ label: 'Discontinued',    value: 'https://schema.org/Discontinued' },
		] },
	] },
	AggregateOffer: { fields: PRODUCT_FIELDS },
	Review:         { fields: REVIEW_FIELDS },
	AggregateRating: { fields: [
		{ key: 'ratingValue', label: 'Rating Value', type: 'text' },
		{ key: 'reviewCount', label: 'Review Count', type: 'text' },
		{ key: 'bestRating',  label: 'Best Rating',  type: 'text' },
		{ key: 'worstRating', label: 'Worst Rating', type: 'text' },
	] },
	ProductCollection: { fields: COMMON },

	// Service.
	Service: { fields: SERVICE_FIELDS },

	// Events.
	Event:        { fields: EVENT_FIELDS },
	EventSeries:  { fields: EVENT_FIELDS },
	SportsEvent:  { fields: EVENT_FIELDS },
	MusicEvent:   { fields: EVENT_FIELDS },
	BusinessEvent: { fields: EVENT_FIELDS },

	// Creative.
	Book:               { fields: COMMON },
	Movie:              { fields: COMMON },
	VideoObject:        { fields: VIDEO_FIELDS },
	ImageObject:        { fields: COMMON },
	Recipe:             { fields: RECIPE_FIELDS },
	MusicRecording:     { fields: COMMON },
	Podcast:            { fields: COMMON },
	TVSeries:           { fields: COMMON },
	TVEpisode:          { fields: COMMON },
	SoftwareApplication: { fields: COMMON },
	MobileApplication:  { fields: COMMON },
	WebApplication:     { fields: COMMON },

	// Educational.
	Course:                          { fields: COURSE_FIELDS },
	LearningResource:                { fields: COMMON },
	Quiz:                            { fields: COMMON },
	EducationalOccupationalProgram:  { fields: COMMON },
	Dataset:                         { fields: COMMON },

	// Medical.
	MedicalCondition:    { fields: COMMON },
	Drug:                { fields: COMMON },
	HealthTopicContent:  { fields: COMMON },

	// Real estate / vehicles / misc.
	JobPosting:        { fields: JOB_FIELDS },
	RealEstateListing: { fields: COMMON },
	Residence:         { fields: COMMON },
	Apartment:         { fields: COMMON },
	Vehicle:           { fields: COMMON },
	Car:               { fields: COMMON },
	Motorcycle:        { fields: COMMON },
};

export function getSchemaTypeNames() {
	return Object.keys( SCHEMA_TYPE_REGISTRY ).sort();
}

export function getSchemaTypeOptions() {
	return [
		{ label: __( '— Select type —', 'schema-override-manager' ), value: '' },
		...getSchemaTypeNames().map( name => ( { label: name, value: name } ) ),
	];
}

export function getTypeDef( type ) {
	return SCHEMA_TYPE_REGISTRY[ type ] ?? null;
}
