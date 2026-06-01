<?php
namespace CFShared\Csv;

defined( 'ABSPATH' ) || exit;

/**
 * CSV cell-injection neutraliser (CWE-1236).
 *
 * When a CSV cell's first character is `=`, `+`, `-`, `@`, tab, or carriage
 * return, spreadsheet applications (Excel, Google Sheets, LibreOffice Calc,
 * Numbers) interpret the cell as a formula at open time. A malicious cell
 * value like `=cmd|'/c calc'!A1` can therefore execute arbitrary commands on
 * the machine of whoever opens the export — even though the WordPress site
 * itself was never compromised.
 *
 * The standard defence is to prefix any cell value whose first character is
 * one of those triggers with a single quote (`'`). Spreadsheets strip the
 * leading apostrophe and render the value as text, never as a formula. The
 * apostrophe survives reading the CSV back in via standard parsers — so the
 * trade-off is purely a presentation prefix in spreadsheet UIs.
 *
 * This helper centralises the rule so every CF plugin that emits CSVs
 * (Bulk Meta Editor today, others as they ship) applies identical escaping.
 */
final class Escaper {

	/**
	 * Characters that trigger formula evaluation when they're the FIRST
	 * character of a cell. Source: OWASP "CSV Injection" guidance.
	 */
	private const DANGEROUS = [ '=', '+', '-', '@', "\t", "\r" ];

	/**
	 * Escape a single cell value. Non-string scalars are cast to string;
	 * everything else (arrays, objects, nulls) becomes the empty string so the
	 * caller cannot accidentally serialise a non-scalar into a CSV cell.
	 */
	public static function escape_cell( $value ): string {
		if ( is_array( $value ) || is_object( $value ) ) {
			return '';
		}
		if ( null === $value || false === $value ) {
			return '';
		}
		$s = (string) $value;
		if ( '' === $s ) {
			return '';
		}
		if ( in_array( $s[0], self::DANGEROUS, true ) ) {
			return "'" . $s;
		}
		return $s;
	}

	/**
	 * Convenience: escape every value in a row (numeric-keyed array, as
	 * fputcsv() expects). Returns a new array; does not mutate the input.
	 */
	public static function escape_row( array $row ): array {
		$out = [];
		foreach ( $row as $k => $v ) {
			$out[ $k ] = self::escape_cell( $v );
		}
		return $out;
	}
}
