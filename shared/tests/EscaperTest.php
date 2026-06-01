<?php

use CFShared\Csv\Escaper;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/Csv/Escaper.php';

final class EscaperTest extends TestCase {

	/**
	 * The classic CSV-injection payload — survives in postmeta, opens as a
	 * formula in Excel, and runs arbitrary commands. After escape_cell() it
	 * must be prefixed with a single quote so the spreadsheet renders it as
	 * literal text.
	 */
	public function test_prefixes_equals_payload(): void {
		$payload = "=cmd|'/c calc'!A1";
		$this->assertSame( "'" . $payload, Escaper::escape_cell( $payload ) );
	}

	/**
	 * All six OWASP-listed first-character triggers must be neutralised.
	 *
	 * @dataProvider dangerous_prefix_provider
	 */
	public function test_prefixes_every_dangerous_first_character( string $input ): void {
		$result = Escaper::escape_cell( $input );
		$this->assertSame( "'", $result[0], 'first char must be a single quote' );
		$this->assertSame( $input, substr( $result, 1 ), 'rest of value must survive intact' );
	}

	public function dangerous_prefix_provider(): array {
		return [
			'equals'       => [ '=SUM(A1:A10)' ],
			'plus'         => [ '+1+1' ],
			'minus'        => [ '-2+3' ],
			'at-sign'      => [ '@SUM(A1:A10)' ],
			'tab'          => [ "\tnot really a tab" ],
			'carriage-ret' => [ "\rmaybe a CR" ],
		];
	}

	public function test_does_not_prefix_safe_strings(): void {
		foreach ( [ 'hello', 'Hello world', '0', '123', 'a=b=c', '  =leading-spaces' ] as $safe ) {
			$this->assertSame( $safe, Escaper::escape_cell( $safe ), "must not modify: $safe" );
		}
	}

	public function test_empty_string_stays_empty(): void {
		$this->assertSame( '', Escaper::escape_cell( '' ) );
	}

	public function test_null_and_false_become_empty_string(): void {
		$this->assertSame( '', Escaper::escape_cell( null ) );
		$this->assertSame( '', Escaper::escape_cell( false ) );
	}

	public function test_arrays_and_objects_become_empty_string(): void {
		$this->assertSame( '', Escaper::escape_cell( [ 'a', 'b' ] ) );
		$this->assertSame( '', Escaper::escape_cell( new \stdClass() ) );
	}

	public function test_numeric_scalars_are_stringified_safely(): void {
		$this->assertSame( '42', Escaper::escape_cell( 42 ) );
		$this->assertSame( '3.14', Escaper::escape_cell( 3.14 ) );
		$this->assertSame( '1', Escaper::escape_cell( true ) );
	}

	public function test_escape_row_neutralises_every_cell_independently(): void {
		$row = [
			'safe',
			'=danger',
			'@danger',
			'',
			null,
			'innocent text',
		];
		$out = Escaper::escape_row( $row );

		$this->assertSame( 'safe',          $out[0] );
		$this->assertSame( "'=danger",      $out[1] );
		$this->assertSame( "'@danger",      $out[2] );
		$this->assertSame( '',              $out[3] );
		$this->assertSame( '',              $out[4] );
		$this->assertSame( 'innocent text', $out[5] );
	}

	public function test_escape_row_does_not_mutate_input(): void {
		$row    = [ '=danger', 'safe' ];
		$before = $row;
		Escaper::escape_row( $row );
		$this->assertSame( $before, $row );
	}

	public function test_dangerous_char_not_at_start_is_left_alone(): void {
		// e.g. SEO meta description containing "=" in the middle is fine —
		// only the leading character matters for spreadsheet auto-formula.
		$value = 'A title with = in it';
		$this->assertSame( $value, Escaper::escape_cell( $value ) );
	}
}
