<?php
namespace SchemaOverrideManager;

defined( 'ABSPATH' ) || exit;

/**
 * Schema utilities ported from Cartographer's structured-data extractor.
 *
 * Source: packages/cartographer/src/core/extractors/structuredData.ts
 *   - normalizeSchemaType()
 *   - flattenJsonLd()
 *   - RELEVANT_SCHEMA_TYPES
 */
final class Util {

	/**
	 * Normalize variants like `https://schema.org/Article` and `schema:Article`
	 * to the bare type name `Article`. This matters because some plugins emit
	 * fully qualified schema URLs in the `@type` field; if we suppress
	 * `Article` without normalizing, those blocks slip through.
	 */
	public static function normalize_schema_type( $value ): string {
		if ( ! is_string( $value ) || '' === $value ) {
			return '';
		}
		$trimmed = trim( $value );

		if ( 0 === strpos( $trimmed, 'http://schema.org/' ) ) {
			return substr( $trimmed, strlen( 'http://schema.org/' ) );
		}
		if ( 0 === strpos( $trimmed, 'https://schema.org/' ) ) {
			return substr( $trimmed, strlen( 'https://schema.org/' ) );
		}
		if ( 0 === strpos( $trimmed, 'schema:' ) ) {
			return substr( $trimmed, strlen( 'schema:' ) );
		}
		return $trimmed;
	}

	/**
	 * Walk a parsed JSON-LD payload and yield every typed node, descending
	 * through `@graph` arrays and array-valued root entries. Used by the live
	 * detector so Yoast's single `@graph` wrapper surfaces as individual
	 * Article/BreadcrumbList/Person entries in the viewer.
	 *
	 * @param mixed $node
	 * @param int   $depth Recursion guard.
	 * @return array[] List of associative arrays, each one a JSON-LD node.
	 */
	public static function flatten_json_ld( $node, int $depth = 0 ): array {
		if ( $depth > 10 ) {
			return [];
		}

		$results = [];

		if ( is_array( $node ) ) {
			// Numeric-keyed (list).
			if ( array_keys( $node ) === range( 0, count( $node ) - 1 ) ) {
				foreach ( $node as $entry ) {
					$results = array_merge( $results, self::flatten_json_ld( $entry, $depth + 1 ) );
				}
				return $results;
			}

			// Associative (object). Descend into @graph if present.
			if ( isset( $node['@graph'] ) && is_array( $node['@graph'] ) ) {
				foreach ( $node['@graph'] as $entry ) {
					$results = array_merge( $results, self::flatten_json_ld( $entry, $depth + 1 ) );
				}
				// Some payloads put both @type and @graph on the same node.
				// We yield the wrapper itself only if it has its own @type beyond the graph children.
				if ( isset( $node['@type'] ) ) {
					$copy = $node;
					unset( $copy['@graph'] );
					$results[] = $copy;
				}
				return $results;
			}

			if ( isset( $node['@type'] ) ) {
				$results[] = $node;
			}
		}

		return $results;
	}

	/**
	 * Curated set of schema.org types with rich-result or SEO relevance.
	 * Source of truth: Cartographer's RELEVANT_SCHEMA_TYPES.
	 *
	 * Used by the schema type selector in the per-page builder.
	 *
	 * @return string[]
	 */
	public static function relevant_schema_types(): array {
		static $types = null;
		if ( null !== $types ) {
			return $types;
		}
		$types = [
			// Content types.
			'Article', 'NewsArticle', 'BlogPosting', 'WebPage', 'WebSite',
			'TechArticle', 'ScholarlyArticle', 'ClaimReview',
			// E-commerce.
			'Product', 'Offer', 'AggregateOffer', 'Review', 'AggregateRating',
			'ProductCollection',
			// Organization (general).
			'Organization', 'Corporation', 'LocalBusiness', 'Store',
			// Organization (domain-specific).
			'GovernmentOrganization', 'EducationalOrganization', 'MedicalOrganization',
			'NGO', 'PerformingGroup', 'SportsOrganization',
			// Local business types.
			'Restaurant', 'Hotel', 'Hospital', 'School', 'Library', 'Museum',
			'ShoppingCenter', 'TouristAttraction', 'FoodEstablishment', 'LodgingBusiness',
			// People.
			'Person', 'ProfilePage',
			// Events.
			'Event', 'EventSeries', 'SportsEvent', 'MusicEvent', 'BusinessEvent',
			// Creative work.
			'Book', 'Movie', 'VideoObject', 'ImageObject', 'Recipe',
			'MusicRecording', 'Podcast', 'TVSeries', 'TVEpisode',
			'SoftwareApplication', 'MobileApplication', 'WebApplication',
			// Medical/Health.
			'MedicalCondition', 'Drug', 'MedicalWebPage', 'HealthTopicContent',
			// Educational.
			'Course', 'LearningResource', 'Quiz', 'EducationalOccupationalProgram',
			'Dataset',
			// Other useful.
			'FAQPage', 'HowTo', 'JobPosting', 'BreadcrumbList', 'SearchAction',
			'ContactPage', 'AboutPage', 'CheckoutPage', 'CollectionPage',
			'RealEstateListing', 'Residence', 'Apartment',
			'Vehicle', 'Car', 'Motorcycle',
			'Speakable', 'SpeakableSpecification',
			// Page subtypes.
			'ItemPage', 'SearchResultsPage',
			// Service.
			'Service',
		];
		return $types;
	}
}
