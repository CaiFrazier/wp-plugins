<?php

namespace CFShared\Media;

defined( 'ABSPATH' ) || exit;

/**
 * Shared alt-text metadata contract for the CF media plugins.
 *
 * The alt-text "decorative" flag is a dataset shared between CF Media Manager
 * (whose Accessibility editor SETS it) and CF Media Optimizer (whose render-time
 * alt fallback READS it to skip images the author marked decorative). Keeping
 * the key names here — in the CFShared kernel both plugins bundle — means the two
 * cohabit over one postmeta contract instead of each defining its own, with no
 * runtime dependency between them.
 *
 * META_KEY_ALT is WordPress core's own attachment-alt key; it lives here only so
 * both plugins reference a single named constant rather than repeating the string.
 * META_KEY_DECORATIVE keeps the pre-split `cf_media_manager` prefix so existing
 * decorative flags set by CF Media Manager 2.x keep resolving.
 */
final class AltMeta {

	const META_KEY_ALT        = '_wp_attachment_image_alt';
	const META_KEY_DECORATIVE = '_cf_media_manager_decorative';

	private function __construct() {}
}
