/* CF QR Redirect — admin meta box interactions.
 * Renders a live QR code preview, handles PNG download, copy-to-clipboard,
 * and the GA4 event preview block.
 */
(function ($) {
	'use strict';

	if (typeof QRCode === 'undefined') {
		return;
	}

	var $container = $('#cfqr-qrcode');
	var qr = null;
	var currentUrl = $container.length ? ($container.data('url') || '') : '';

	function render(url) {
		if (!$container.length || !url) {
			return;
		}
		$container.empty();
		qr = new QRCode($container[0], {
			text: url,
			width: 240,
			height: 240,
			correctLevel: QRCode.CorrectLevel.H
		});
	}

	render(currentUrl);

	// Download PNG: re-render at print quality (1000x1000) into a hidden div,
	// grab the resulting <canvas> or <img> data URL, trigger a download.
	$('#cfqr-download-png').on('click', function (e) {
		e.preventDefault();
		if (!currentUrl) {
			return;
		}
		var $hidden = $('<div>').css({ position: 'absolute', left: '-9999px', top: '-9999px' }).appendTo('body');
		// eslint-disable-next-line no-new
		new QRCode($hidden[0], {
			text: currentUrl,
			width: 1000,
			height: 1000,
			correctLevel: QRCode.CorrectLevel.H
		});
		// davidshimjs renders both a <canvas> and an <img>. Prefer the canvas.
		setTimeout(function () {
			var canvas = $hidden.find('canvas')[0];
			var img = $hidden.find('img')[0];
			var dataUrl = '';
			if (canvas) {
				dataUrl = canvas.toDataURL('image/png');
			} else if (img) {
				dataUrl = img.src;
			}
			if (dataUrl) {
				var slug = currentUrl.split('/').pop() || 'qr-code';
				var $a = $('<a>').attr({
					href: dataUrl,
					download: 'qr-' + slug + '.png'
				}).appendTo('body');
				$a[0].click();
				$a.remove();
			}
			$hidden.remove();
		}, 50);
	});

	// Copy-to-clipboard for any .cfqr-copy button.
	$(document).on('click', '.cfqr-copy', function (e) {
		e.preventDefault();
		var text = $(this).data('clipboard') || '';
		if (!text) return;
		var $btn = $(this);
		var original = $btn.text();
		var done = function () {
			$btn.text('Copied!');
			setTimeout(function () { $btn.text(original); }, 1200);
		};
		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(text).then(done, function () {
				fallbackCopy(text);
				done();
			});
		} else {
			fallbackCopy(text);
			done();
		}
	});

	function fallbackCopy(text) {
		var $temp = $('<textarea>').val(text).css({ position: 'fixed', opacity: 0 }).appendTo('body');
		$temp[0].select();
		try { document.execCommand('copy'); } catch (e) { /* swallow */ }
		$temp.remove();
	}

	// Toggle UTM-only / intermediate-only sections based on the analytics mode radio,
	// and reflect the choice on the picker frames so the active option visibly stands out.
	function syncModeVisibility() {
		var mode = $('input[name="cfqr_analytics_mode"]:checked').val() || 'utm';
		$('.cfqr-utm-only').toggle(mode === 'utm');
		$('.cfqr-intermediate-only').toggle(mode === 'intermediate');
		$('.cfqr-mode-option').each(function () {
			var $opt = $(this);
			var optMode = $opt.find('input[type="radio"]').val();
			$opt.toggleClass('is-active', optMode === mode);
		});
		updateEventPreview();
	}

	function updateEventPreview() {
		var $preview = $('#cfqr-event-preview');
		if (!$preview.length) return;
		var dest = $('#cfqr_destination').val() || '';
		var camp = $('#cfqr_campaign').val() || '';
		var slug = (currentUrl.split('/').pop()) || '';
		var snippet = "gtag('event', 'qr_redirect', {\n"
			+ "  short_code: " + JSON.stringify(slug) + ",\n"
			+ "  destination: " + JSON.stringify(dest) + ",\n"
			+ "  campaign: " + JSON.stringify(camp) + ",\n"
			+ "  traffic_type: 'qr'\n"
			+ "});";
		$preview.text(snippet);
	}

	$(document).on('change', 'input[name="cfqr_analytics_mode"]', syncModeVisibility);
	$(document).on('input', '#cfqr_destination, #cfqr_campaign', updateEventPreview);
	syncModeVisibility();

	// If the post slug is changed via the permalink editor, re-render the QR.
	$(document).on('click', '#edit-slug-buttons .save', function () {
		setTimeout(function () {
			var $newSlug = $('#editable-post-name-full');
			if (!$newSlug.length) return;
			var newSlug = $newSlug.text().trim();
			if (!newSlug) return;
			var parts = currentUrl.split('/');
			parts[parts.length - 1] = newSlug;
			currentUrl = parts.join('/');
			$('.cfqr-shorturl').text(currentUrl);
			$('.cfqr-copy').data('clipboard', currentUrl);
			render(currentUrl);
			updateEventPreview();
		}, 200);
	});

})(jQuery);
