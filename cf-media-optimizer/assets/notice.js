/**
 * CF Media Manager — admin notice "Purge All Caches Now" handler.
 *
 * Loaded by AdminPage::admin_scripts() on the dashboard, media library, and
 * the plugin's own page when a post-conversion purge is pending. Lives in
 * its own file (rather than inline in PHP) so it can be linted, cached, and
 * audited without grepping through PHP heredocs.
 *
 * i18n: wp-i18n is enqueued as a dep in AdminPage::admin_scripts() and
 * translations are wired via wp_set_script_translations().
 */
( function () {
	var notice = document.querySelector( '.cf-media-optimizer-purge-notice' );
	if ( ! notice || typeof cfMediaOptimizerNotice === 'undefined' ) {
		return;
	}
	var btn    = notice.querySelector( '.cf-media-optimizer-notice-purge' );
	var status = notice.querySelector( '.cf-media-optimizer-notice-purge-status' );
	if ( ! btn ) { return; }

	var __      = wp.i18n.__;
	var _n      = wp.i18n._n;
	var sprintf = wp.i18n.sprintf;

	btn.addEventListener( 'click', function () {
		btn.disabled = true;
		if ( status ) { status.textContent = ' ' + __( 'Purging…', 'cf-media-optimizer' ); }

		var data = new FormData();
		data.append( 'action', 'cf_media_optimizer_purge_caches' );
		data.append( 'nonce',  cfMediaOptimizerNotice.nonce );

		fetch( cfMediaOptimizerNotice.ajaxUrl, {
			method      : 'POST',
			body        : data,
			credentials : 'same-origin'
		} )
			.then( function ( r ) { return r.json(); } )
			.then( function ( res ) {
				if ( res && res.success ) {
					var n = ( res.data && res.data.results ) ? res.data.results.length : 0;
					if ( status ) {
						status.textContent = ' ' + sprintf(
							/* translators: %d: number of cache layers purged. */
							_n( '✓ Purged %d cache layer.', '✓ Purged %d cache layers.', n, 'cf-media-optimizer' ),
							n
						);
					}
					setTimeout( function () { notice.style.display = 'none'; }, 1800 );
				} else {
					btn.disabled = false;
					if ( status ) { status.textContent = ' ' + __( 'Purge failed.', 'cf-media-optimizer' ); }
				}
			} )
			.catch( function () {
				btn.disabled = false;
				if ( status ) { status.textContent = ' ' + __( 'Network error.', 'cf-media-optimizer' ); }
			} );
	} );
} )();
