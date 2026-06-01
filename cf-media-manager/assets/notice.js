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
	var notice = document.querySelector( '.cf-media-manager-purge-notice' );
	if ( ! notice || typeof cfMediaManagerNotice === 'undefined' ) {
		return;
	}
	var btn    = notice.querySelector( '.cf-media-manager-notice-purge' );
	var status = notice.querySelector( '.cf-media-manager-notice-purge-status' );
	if ( ! btn ) { return; }

	var __      = wp.i18n.__;
	var _n      = wp.i18n._n;
	var sprintf = wp.i18n.sprintf;

	btn.addEventListener( 'click', function () {
		btn.disabled = true;
		if ( status ) { status.textContent = ' ' + __( 'Purging…', 'cf-media-manager' ); }

		var data = new FormData();
		data.append( 'action', 'cf_media_manager_purge_caches' );
		data.append( 'nonce',  cfMediaManagerNotice.nonce );

		fetch( cfMediaManagerNotice.ajaxUrl, {
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
							_n( '✓ Purged %d cache layer.', '✓ Purged %d cache layers.', n, 'cf-media-manager' ),
							n
						);
					}
					setTimeout( function () { notice.style.display = 'none'; }, 1800 );
				} else {
					btn.disabled = false;
					if ( status ) { status.textContent = ' ' + __( 'Purge failed.', 'cf-media-manager' ); }
				}
			} )
			.catch( function () {
				btn.disabled = false;
				if ( status ) { status.textContent = ' ' + __( 'Network error.', 'cf-media-manager' ); }
			} );
	} );
} )();
