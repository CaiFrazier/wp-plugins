<?php

namespace CFMediaManager\Tests;

use CFMediaManager\LibraryColumnRegistry;
use PHPUnit\Framework\TestCase;

/**
 * The registry is the single source of truth the REST controller, CSV
 * exporter, and JS modal all validate against. These tests guard the
 * internal consistency the rest of the library list view assumes.
 */
final class LibraryColumnRegistryTest extends TestCase {

	public function test_all_returns_groups_each_with_label_and_columns(): void {
		$all = LibraryColumnRegistry::all();

		self::assertNotEmpty( $all );
		foreach ( $all as $group_key => $group ) {
			self::assertArrayHasKey( 'label', $group, "Group '{$group_key}' missing label." );
			self::assertArrayHasKey( 'columns', $group, "Group '{$group_key}' missing columns." );
			self::assertNotEmpty( $group['columns'] );
		}
	}

	public function test_every_column_entry_has_the_required_shape(): void {
		foreach ( LibraryColumnRegistry::all() as $group ) {
			foreach ( $group['columns'] as $key => $col ) {
				self::assertIsString( $col['label'] ?? null, "Column '{$key}' label." );
				self::assertIsString( $col['desc'] ?? null, "Column '{$key}' desc." );
				self::assertIsBool( $col['default'] ?? null, "Column '{$key}' default." );
				self::assertIsString( $col['width'] ?? null, "Column '{$key}' width." );
				self::assertIsBool( $col['sortable'] ?? null, "Column '{$key}' sortable." );
			}
		}
	}

	public function test_flat_keys_match_the_union_of_all_group_columns(): void {
		$expected = array();
		foreach ( LibraryColumnRegistry::all() as $group ) {
			$expected = array_merge( $expected, array_keys( $group['columns'] ) );
		}

		self::assertSame( $expected, array_keys( LibraryColumnRegistry::flat() ) );
	}

	public function test_no_duplicate_column_keys_across_groups(): void {
		$keys = array();
		foreach ( LibraryColumnRegistry::all() as $group ) {
			$keys = array_merge( $keys, array_keys( $group['columns'] ) );
		}

		self::assertSame( array_unique( $keys ), $keys, 'Column keys must be globally unique.' );
	}

	public function test_defaults_are_non_empty_and_all_valid_keys(): void {
		$defaults = LibraryColumnRegistry::defaults();

		self::assertNotEmpty( $defaults );
		self::assertEmpty(
			array_diff( $defaults, LibraryColumnRegistry::valid_keys() ),
			'Every default column must be a valid registered key.'
		);
	}

	public function test_defaults_exactly_match_columns_flagged_default_true(): void {
		$flagged = array();
		foreach ( LibraryColumnRegistry::flat() as $key => $col ) {
			if ( $col['default'] ) {
				$flagged[] = $key;
			}
		}

		self::assertSame( $flagged, LibraryColumnRegistry::defaults() );
	}

	public function test_valid_keys_equals_flat_keys(): void {
		self::assertSame( array_keys( LibraryColumnRegistry::flat() ), LibraryColumnRegistry::valid_keys() );
	}
}
