<?php
namespace SchemaOverrideManager;

defined( 'ABSPATH' ) || exit;

use SchemaOverrideManager\SchemaOutput;
use SchemaOverrideManager\Integrations\YoastIntegration;
use SchemaOverrideManager\Integrations\RankMathIntegration;

class Suppressor {

	private Settings $settings;
	private bool $ob_active = false;

	/**
	 * Output-buffer nesting level recorded immediately after our ob_start().
	 * ob_get_clean() pops the TOP of the OB stack, so before we call it we
	 * verify the stack is still where we left it. If another plugin or theme
	 * helper pushed its own buffer between our start and end hooks, popping
	 * now would consume their content — that's the classic LIFO foot-gun
	 * that causes "my page is missing the footer" bug reports.
	 *
	 * -1 means "no buffer recorded yet".
	 *
	 * @var int
	 */
	private int $ob_level = -1;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	public function init(): void {
		// Global rules are always applied; per-page rules are merged in on singular pages.
		add_action( 'wp', [ $this, 'register_suppression_hooks' ] );
	}

	public function register_suppression_hooks(): void {
		$global = $this->settings->get_global_suppression();
		$page   = [];

		if ( is_singular() ) {
			$post_id = (int) get_queried_object_id();
			$page    = $this->settings->get_page_suppression( $post_id );
		}

		$rules = $this->merge_rules( $global, $page );

		// Replace-mode blocks implicitly suppress the same @type from Yoast/RM.
		if ( is_singular() ) {
			$rules = $this->add_replace_mode_suppression( $rules, (int) get_queried_object_id() );
		}

		$this->apply_yoast_suppression( $rules );
		$this->apply_rank_math_suppression( $rules );
		$this->apply_theme_suppression( $rules );

		if ( $this->has_any_rules( $rules ) ) {
			Logger::instance()->debug(
				'suppression',
				'Registered suppression rules',
				[
					'rules'       => $rules,
					'is_singular' => is_singular(),
					'post_id'     => is_singular() ? get_queried_object_id() : null,
				]
			);
		}
	}

	private function has_any_rules( array $rules ): bool {
		return ! empty( $rules['yoast_all'] )
			|| ! empty( $rules['rank_math_all'] )
			|| ! empty( $rules['theme_all'] )
			|| ! empty( $rules['yoast_types'] )
			|| ! empty( $rules['rank_math_types'] );
	}

	/**
	 * Per-page schema blocks with mode = "replace" cause the same @type
	 * to be suppressed from Yoast and Rank Math output.
	 *
	 * @param array $rules   Merged suppression rules to extend.
	 * @param int   $post_id Post whose page schema is inspected.
	 * @return array Rules with implied type suppressions added.
	 */
	private function add_replace_mode_suppression( array $rules, int $post_id ): array {
		$page_schema = $this->settings->get_page_schema( $post_id );
		if ( empty( $page_schema ) ) {
			return $rules;
		}

		foreach ( $page_schema as $block ) {
			$mode = $block['_som_mode'] ?? 'extend';
			if ( 'replace' !== $mode ) {
				continue;
			}
			// @type may be a string or an array (multi-typed nodes). Normalize
			// to the bare name at push time so a URL-form type stored in the
			// block ("https://schema.org/Article") suppresses the same as a
			// bare one. The apply_* methods still normalize at comparison time
			// as the compat layer for rows stored before this existed.
			$raw   = $block['@type'] ?? '';
			$types = is_array( $raw ) ? $raw : [ $raw ];
			foreach ( $types as $t ) {
				$type = Util::normalize_schema_type( $t );
				if ( '' === $type ) {
					continue;
				}
				$rules['yoast_types'][]     = $type;
				$rules['rank_math_types'][] = $type;
			}
		}

		$rules['yoast_types']     = array_values( array_unique( $rules['yoast_types'] ?? [] ) );
		$rules['rank_math_types'] = array_values( array_unique( $rules['rank_math_types'] ?? [] ) );

		return $rules;
	}

	private function apply_yoast_suppression( array $rules ): void {
		if ( ! YoastIntegration::is_active() ) {
			return;
		}

		if ( ! empty( $rules['yoast_all'] ) ) {
			YoastIntegration::suppress_all();
			return;
		}

		YoastIntegration::suppress_types( $rules['yoast_types'] ?? [] );
	}

	private function apply_rank_math_suppression( array $rules ): void {
		if ( ! RankMathIntegration::is_active() ) {
			return;
		}

		if ( ! empty( $rules['rank_math_all'] ) ) {
			RankMathIntegration::suppress_all();
			return;
		}

		RankMathIntegration::suppress_types( $rules['rank_math_types'] ?? [] );
	}

	private function apply_theme_suppression( array $rules ): void {
		$config = $this->settings->get_settings();

		// Output buffering is opt-in at the settings level and rule level.
		if ( empty( $config['theme_suppression_ob'] ) || empty( $rules['theme_all'] ) ) {
			return;
		}

		add_action( 'wp_head', [ $this, 'start_ob' ], 1 );
		add_action( 'wp_head', [ $this, 'end_ob_and_strip' ], 999 );
	}

	/**
	 * Open the wp_head output buffer for the theme-suppression strip.
	 *
	 * @internal
	 */
	public function start_ob(): void {
		ob_start();
		$this->ob_active = true;
		// Snapshot AFTER ob_start so $ob_level is the level our buffer occupies.
		$this->ob_level = ob_get_level();
	}

	/**
	 * Close the wp_head buffer and strip foreign JSON-LD from it.
	 *
	 * @internal
	 */
	public function end_ob_and_strip(): void {
		if ( ! $this->ob_active ) {
			return;
		}
		$this->ob_active = false;

		// If another plugin or theme helper pushed its own buffer on top of
		// ours (current level > our recorded level), or one was popped out
		// from under us (current < our recorded level), ob_get_clean() would
		// pop the wrong buffer and silently corrupt page output. Detect that
		// and bail without touching the stack — Yoast/RankMath schema may
		// still appear, but the page itself stays intact.
		$current_level = ob_get_level();
		if ( $current_level !== $this->ob_level ) {
			Logger::instance()->warn(
				'suppression',
				'OB stack mismatch — skipping theme suppression strip to avoid corrupting another plugin\'s buffer',
				[
					'expected_level' => $this->ob_level,
					'actual_level'   => $current_level,
				]
			);
			return;
		}

		$output = ob_get_clean();
		if ( false === $output ) {
			return;
		}

		try {
			$marker = preg_quote( SchemaOutput::OUTPUT_MARKER, '#' );

			// Strip JSON-LD blocks that do NOT contain our marker comment.
			// This preserves our own output regardless of priority ordering.
			// Note: this also strips Yoast/Rank Math output if those plugins emit
			// before this hook fires. To preserve specific Yoast/RM types alongside
			// theme suppression, use type-specific suppression at the filter level
			// (configured in Global Suppression / per-page Suppression panels).
			$cleaned = preg_replace_callback(
				'#<script\s+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is',
				function ( $m ) use ( $marker ) {
					return preg_match( '#' . $marker . '#', $m[1] ) ? $m[0] : '';
				},
				$output
			);

			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Buffered HTML; already escaped by upstream renderers.
			echo null !== $cleaned ? $cleaned : $output;
		} catch ( \Throwable $e ) {
			// preg_replace_callback can hit a backtrack limit on pathological
			// inputs and return null with the callback never invoked; we'd echo
			// $output in that case via the ternary above. A genuine exception
			// (e.g. a callback that throws) gets us here — we still need to
			// emit the buffered content so the page isn't blank.
			Logger::instance()->error(
				'suppression',
				'OB strip threw — re-emitting raw buffer to preserve the page',
				[
					'message' => $e->getMessage(),
					'file'    => $e->getFile(),
					'line'    => $e->getLine(),
				]
			);
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Buffered HTML; already escaped by upstream renderers.
			echo $output;
		}
	}

	/**
	 * Merge global and per-page suppression rules.
	 *
	 * @param array $global_rules Site-wide suppression rules.
	 * @param array $page_rules   Per-page suppression rules.
	 * @return array Booleans OR'd, type lists unioned.
	 */
	private function merge_rules( array $global_rules, array $page_rules ): array {
		$merged = $global_rules;

		// Boolean OR: if either layer suppresses all, suppress all.
		foreach ( [ 'yoast_all', 'rank_math_all', 'theme_all' ] as $key ) {
			$merged[ $key ] = ! empty( $global_rules[ $key ] ) || ! empty( $page_rules[ $key ] );
		}

		// Array union for type-level suppression.
		foreach ( [ 'yoast_types', 'rank_math_types' ] as $key ) {
			$global_types   = $global_rules[ $key ] ?? [];
			$page_types     = $page_rules[ $key ] ?? [];
			$merged[ $key ] = array_unique( array_merge( $global_types, $page_types ) );
		}

		return $merged;
	}
}
