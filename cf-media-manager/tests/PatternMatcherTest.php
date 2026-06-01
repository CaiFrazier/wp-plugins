<?php

namespace CFMediaManager\Tests;

use CFMediaManager\PatternMatcher;
use PHPUnit\Framework\TestCase;

final class PatternMatcherTest extends TestCase {

	public function test_exact_path_match(): void {
		self::assertTrue( PatternMatcher::matches( '/contact/', "/contact/" ) );
	}

	public function test_no_match_returns_false(): void {
		self::assertFalse( PatternMatcher::matches( '/about/', "/contact/" ) );
	}

	public function test_wildcard_suffix_match(): void {
		self::assertTrue( PatternMatcher::matches( '/shop/widgets/red', "/shop/*" ) );
	}

	public function test_wildcard_middle_match(): void {
		self::assertTrue( PatternMatcher::matches( '/2024/old-gallery/photo', "*/old-gallery/*" ) );
	}

	public function test_comments_and_blank_lines_ignored(): void {
		$patterns = "# this is a comment\n\n/contact/\n   \n# another comment";
		self::assertTrue( PatternMatcher::matches( '/contact/', $patterns ) );
		self::assertFalse( PatternMatcher::matches( '/about/', $patterns ) );
	}

	public function test_case_insensitive(): void {
		self::assertTrue( PatternMatcher::matches( '/Contact/', "/contact/" ) );
		self::assertTrue( PatternMatcher::matches( '/Shop/Widgets', "/SHOP/*" ) );
	}

	public function test_anchored_match_does_not_partial_match(): void {
		// "/shop" should not match "/shopping/" — patterns are anchored.
		self::assertFalse( PatternMatcher::matches( '/shopping/', "/shop" ) );
	}

	public function test_multiple_patterns_any_match(): void {
		$patterns = "/contact/\n/about/\n/shop/*";
		self::assertTrue( PatternMatcher::matches( '/about/', $patterns ) );
		self::assertTrue( PatternMatcher::matches( '/shop/widgets', $patterns ) );
		self::assertFalse( PatternMatcher::matches( '/blog/', $patterns ) );
	}

	public function test_empty_pattern_list_never_matches(): void {
		self::assertFalse( PatternMatcher::matches( '/contact/', '' ) );
		self::assertFalse( PatternMatcher::matches( '/contact/', "\n# comment only\n" ) );
	}

	public function test_empty_path_normalizes_to_root(): void {
		self::assertTrue( PatternMatcher::matches( '', '/' ) );
	}

	public function test_special_regex_characters_in_pattern_are_quoted(): void {
		// The pattern contains characters that would be regex metas if not quoted.
		// "(test)" should match the literal substring, not a capturing group.
		self::assertTrue( PatternMatcher::matches( '/foo(test)/', '/foo(test)/' ) );
		self::assertFalse( PatternMatcher::matches( '/footestplus/', '/foo(test)/' ) );
	}
}
