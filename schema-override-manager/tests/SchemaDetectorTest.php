<?php

namespace SchemaOverrideManager\Tests;

use PHPUnit\Framework\TestCase;
use SchemaOverrideManager\SchemaDetector;

/**
 * Covers the known-type lists that drive suppression checkboxes when live
 * filter detection returns nothing (SOM-P1-003). The bootstrap defines
 * WPSEO_Options + RankMath, so both integrations report active here.
 */
final class SchemaDetectorTest extends TestCase {

	private SchemaDetector $detector;

	protected function setUp(): void {
		som_test_reset_state();
		$this->detector = new SchemaDetector();
	}

	public function test_yoast_types_returns_the_known_default_set(): void {
		$types = $this->detector->get_yoast_types();

		self::assertContains( 'Article', $types );
		self::assertContains( 'BreadcrumbList', $types );
		self::assertContains( 'Person', $types );
	}

	public function test_rank_math_types_includes_ecommerce_defaults(): void {
		$types = $this->detector->get_rank_math_types();

		self::assertContains( 'Product', $types );
		self::assertContains( 'FAQPage', $types );
	}

	public function test_yoast_types_are_filterable(): void {
		add_filter(
			'som_yoast_emitted_types',
			static function ( $types ) {
				$types[] = 'Service';
				$types[] = 'LocalBusiness';
				return $types;
			}
		);

		$types = $this->detector->get_yoast_types();

		self::assertContains( 'Service', $types );
		self::assertContains( 'LocalBusiness', $types );
	}

	public function test_rankmath_types_are_filterable(): void {
		add_filter(
			'som_rankmath_emitted_types',
			static function () {
				// A filter may replace the list wholesale.
				return [ 'Recipe' ];
			}
		);

		self::assertSame( [ 'Recipe' ], $this->detector->get_rank_math_types() );
	}

	public function test_filtered_types_are_normalized_and_deduped(): void {
		add_filter(
			'som_yoast_emitted_types',
			static function () {
				return [
					'https://schema.org/Article',
					'Article',
					'schema:Person',
					'',
					42,
				];
			}
		);

		$types = $this->detector->get_yoast_types();

		self::assertSame( [ 'Article', 'Person' ], $types );
	}
}
