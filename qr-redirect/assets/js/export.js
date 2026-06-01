/* CF QR Redirect — bulk PNG ZIP export.
 * Reads a JSON payload of {slug, label, url} from the rendered admin page,
 * generates each QR as a 1000x1000 PNG via davidshimjs/qrcodejs into off-screen
 * canvases, bundles them with JSZip, and triggers a single ZIP download.
 *
 * Runs only on the cfqr-zip-export page; no jQuery dependency.
 */
(function () {
	'use strict';

	if (typeof QRCode === 'undefined' || typeof JSZip === 'undefined') {
		return;
	}

	var node = document.getElementById('cfqr-zip-payload');
	if (!node) return;

	var statusBox = document.getElementById('cfqr-zip-status');
	var statusText = statusBox ? statusBox.querySelector('.cfqr-status-text') : null;
	var statusSpinner = statusBox ? statusBox.querySelector('.spinner') : null;
	var bar = document.getElementById('cfqr-zip-bar');
	var totalInput = document.getElementById('cfqr-zip-total');
	var total = totalInput ? parseInt(totalInput.value, 10) || 0 : 0;

	function setStatus(msg, isError, isDone) {
		if (statusText) statusText.textContent = msg;
		if (statusSpinner) {
			if (isDone || isError) {
				statusSpinner.classList.remove('is-active');
				statusSpinner.style.display = 'none';
			}
		}
		if (statusBox && isError) statusBox.classList.add('cfqr-status-error');
		if (statusBox && isDone) statusBox.classList.add('cfqr-status-done');
	}

	function setProgress(done) {
		if (!bar || !total) return;
		var pct = Math.min(100, Math.round((done / total) * 100));
		bar.style.width = pct + '%';
	}

	var payload;
	try {
		payload = JSON.parse(node.textContent || '[]');
	} catch (e) {
		setStatus('Could not parse export data: ' + e.message, true);
		return;
	}
	if (!Array.isArray(payload) || payload.length === 0) {
		setStatus('No codes to export.', true);
		return;
	}

	var zip = new JSZip();
	var folder = zip.folder('qr-codes');
	var done = 0;

	function safeFilename(slug) {
		// Slugs come from our own alphabet (or sanitized vanity); strip anything
		// outside [A-Za-z0-9_-] just in case.
		return String(slug).replace(/[^A-Za-z0-9_\-]/g, '_') || 'code';
	}

	function renderOne(entry) {
		return new Promise(function (resolve) {
			var hidden = document.createElement('div');
			hidden.style.cssText = 'position:absolute;left:-99999px;top:-99999px;width:1000px;height:1000px;';
			document.body.appendChild(hidden);

			// eslint-disable-next-line no-new
			new QRCode(hidden, {
				text: entry.url,
				width: 1000,
				height: 1000,
				correctLevel: QRCode.CorrectLevel.H
			});

			// Give the library a tick to draw, then capture from the canvas it created.
			setTimeout(function () {
				var canvas = hidden.querySelector('canvas');
				var img = hidden.querySelector('img');
				var dataUrl = '';
				if (canvas) {
					dataUrl = canvas.toDataURL('image/png');
				} else if (img) {
					dataUrl = img.src;
				}
				if (dataUrl && dataUrl.indexOf('data:image/png;base64,') === 0) {
					var base64 = dataUrl.substring('data:image/png;base64,'.length);
					var name = safeFilename(entry.slug) + '.png';
					folder.file(name, base64, { base64: true });
				}
				hidden.parentNode.removeChild(hidden);
				done += 1;
				setProgress(done);
				if (statusText) {
					statusText.textContent = 'Generated ' + done + ' of ' + total + '…';
				}
				resolve();
			}, 30);
		});
	}

	function processSequentially(items) {
		// Sequential rather than parallel — generating 50+ 1000px canvases at once
		// can spike memory enough to make the tab unresponsive on lower-end machines.
		var p = Promise.resolve();
		items.forEach(function (entry) {
			p = p.then(function () { return renderOne(entry); });
		});
		return p;
	}

	processSequentially(payload).then(function () {
		if (statusText) statusText.textContent = 'Compressing ZIP…';
		return zip.generateAsync({ type: 'blob' });
	}).then(function (blob) {
		var url = URL.createObjectURL(blob);
		var a = document.createElement('a');
		var stamp = new Date().toISOString().slice(0, 10);
		a.href = url;
		a.download = 'qr-codes-' + stamp + '.zip';
		document.body.appendChild(a);
		a.click();
		document.body.removeChild(a);
		// Revoke after a short delay so Safari doesn't cancel the download mid-stream.
		setTimeout(function () { URL.revokeObjectURL(url); }, 5000);
		setStatus('✓ Done. The ZIP download started — you can close this tab.', false, true);
	}).catch(function (err) {
		setStatus('Export failed: ' + (err && err.message ? err.message : err), true);
	});

})();
