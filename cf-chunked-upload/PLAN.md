# CF Chunked Upload — Plugin Plan

**Slug:** `cf-chunked-upload`  
**Status:** Internal MVP  
**Release path:** Internal → standalone distribution → WordPress.org (eventual)

---

## Problem

PHP's `upload_max_filesize` and `post_max_size` are hard server limits that apply to every HTTP request. Most shared hosts set these at 8–64 MB and won't raise them without a plan upgrade. Chunked uploading bypasses this entirely: the file is split client-side before any request is made, so the server never sees a payload larger than one chunk.

Target use cases:
- WordPress backup files (`.zip`, `.tar.gz`) in the 1–10+ GB range
- Large media (video, high-res image archives)
- Plugin/theme ZIPs that exceed host limits (v2)

---

## Prior Art

The closest existing plugin is **Big File Uploads** (formerly Tuxedo Big File Uploads), maintained by Infinite Uploads. It hooks `plupload_default_settings` / `plupload_init` to enable Plupload's built-in chunking, redirects the chunk action to its own `admin-ajax.php` handler (`bfu_chunker`), and appends chunks sequentially to a single growing `.part` file in `wp-content/bfu-temp/` before handing the assembled file to `media_handle_upload()` with `wp_handle_sideload`. WordPress core does not natively reassemble Plupload chunks; the plugin must provide that logic.

What this plan does differently and why:

- **Importer destination.** Big File Uploads only writes to the media library. CF Chunked Upload adds a separate import surface for backup files and arbitrary data that should not become attachments.
- **Per-chunk files instead of append-in-place.** Storing each chunk as `{chunkIndex}.part` permits out-of-order arrivals, parallel chunk uploads, and a clean `GET /status/{uploadId}` for resume. Append-only is simpler but sequential-only.
- **Integrity verification.** Per-chunk SHA-256 plus a whole-file SHA-256 are verified server-side. Big File Uploads has no integrity check; a silently corrupted chunk produces a broken backup the user only discovers on restore.
- **REST routes instead of `admin-ajax.php`.** Cleaner namespacing, easier permission callbacks, less coupled to the legacy media-form nonce flow.

The Big File Uploads source is worth reading before implementation: `chunk_size` defaults clamped to 80% of `upload_max_filesize`, the temp directory pattern outside `uploads/`, and the swap of `$_FILES['async-upload']` before calling `media_handle_upload()` are all proven patterns to copy.

---

## MVP Scope

Two upload surfaces in v1:

1. **Media Library** — hook into the existing WP media modal; large files get chunked transparently
2. **Standalone Importer** — a dedicated admin page for uploading arbitrary files (backups, data exports) to a configurable server directory, outside the media library pipeline

Plugin/theme ZIP upload (via WP_Upgrader) is deferred to v2 — it requires hooking WP's internal upgrader pipeline and is a separate problem.

---

## Architecture

### Client Side

**Chunking logic (`src/Uploader.js`)**

- Intercepts file selection via a custom file input (standalone) or by hooking into the WP media modal's file picker
- Files below the threshold (default: skip chunking below 10 MB) go through normal WP upload unchanged
- Files at or above threshold are chunked using `File.prototype.slice()` — pure browser API, no dependencies
- Generates a UUID `uploadId` per upload session (crypto.randomUUID())
- Uploads chunks with a small concurrency window (default: 3 in flight) and per-chunk retry up to 3 attempts before surfacing an error
  - Chunks are written to disk as `{chunkIndex}.part` files, so out-of-order arrivals are fine and order is preserved at finalize time by iterating indices in order. Parallelism is bounded because most shared hosts won't tolerate >5 concurrent requests from one origin. Sequential (concurrency = 1) is the safe fallback for unstable connections and is exposed as a setting.
- Computes a SHA-256 hash of each chunk client-side via Web Crypto (`crypto.subtle.digest`) and includes it in the request. Also computes a streaming SHA-256 of the whole file across all chunks for verification at finalize.
- Each chunk request is a `FormData` POST carrying:
  - `uploadId` — session identifier
  - `chunkIndex` — 0-based position
  - `totalChunks` — total count
  - `fileName` — original filename (sanitized server-side)
  - `mimeType` — original file MIME type (declared, re-verified server-side)
  - `chunkSha256` — hex SHA-256 of the chunk bytes
  - The chunk blob

**Progress UI (`src/Progress.js`)**

- Per-upload progress bar: `X of N chunks uploaded (Y%)`
- Estimated time remaining, calculated from rolling average of the last 5 chunk upload times
- Current transfer speed (MB/s)
- Clear error state with chunk-level retry button
- Warning if user attempts to navigate away mid-upload (`beforeunload` event)

For the standalone importer, this renders in a dedicated React app mounted in the admin page. For the media modal integration, it replaces the default WP progress bar for chunked files only.

---

### Server Side (PHP REST API)

All endpoints registered under `cf-chunked-upload/v1`. All require a valid WP REST nonce (`X-WP-Nonce` header). Capability checks vary by route:
- `/chunk`, `/finalize`, `/status/{uploadId}`, `/upload/{uploadId}` (DELETE), `/finalize-status/{jobId}`: `current_user_can('upload_files')` for the media destination; configurable capability (default `manage_options`) for the importer destination
- `/host-info`: `current_user_can('manage_options')` (read-only PHP ini values; not sensitive but also not public)

---

#### `POST /chunk`

Receives one chunk, writes it to temp storage.

**Request:** multipart/form-data with `uploadId`, `chunkIndex`, `totalChunks`, `fileName`, `mimeType`, `chunkSha256`, chunk blob

**Server behavior:**
1. Validate nonce (`wp_rest`) and capability
2. Validate `uploadId` matches a strict UUID v4 regex (`/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i`) before using it as a directory component
3. Sanitize `fileName` via `sanitize_file_name()` (used only for the eventual destination, never for temp paths)
4. Validate `chunkIndex` and `totalChunks` are integers, `chunkIndex` is in `[0, totalChunks)`, `totalChunks` is in a sane range (e.g. 1–10,000)
5. For media uploads: validate the declared MIME against `wp_get_mime_types()`. For the standalone importer: validate against the configured extension allowlist
6. Compute SHA-256 of the received chunk bytes and compare to `chunkSha256`. Reject with 422 on mismatch. This catches network corruption and is also a guard against truncated chunks
7. Write chunk to: `wp-content/cf-chunks/{uploadId}/{chunkIndex}.part` (outside `uploads/` to avoid any chance of accidental web exposure if `.htaccess` is ignored)
8. Touch `wp-content/cf-chunks/{uploadId}/.heartbeat` so cleanup can detect active uploads
9. Return `{ received: chunkIndex, remaining: totalChunks - (received count) }`

**Temp directory** lives at `wp-content/cf-chunks/` (outside `wp-content/uploads/`) and is created with both an `index.php` silence file and an `.htaccess` denying direct HTTP access. The `wp-content/` location is the same pattern Big File Uploads uses and is the right choice: even if `.htaccess` is ignored on Nginx, this path is not under `uploads/` and typically has no permissive static-file rule. The settings page surfaces a copyable Nginx `location` block for users to add to their server config.

---

#### `POST /finalize`

Called after all chunks are confirmed received. Reassembles and moves the file.

**Request:** JSON body with `uploadId`, `fileName`, `mimeType`, `destination` (`media` | `import`), `totalSha256` (hex SHA-256 of the whole file as computed client-side).

**Server behavior (streaming — never loads full file into memory):**
1. Reject the request if `destination` is not exactly `media` or `import` (explicit whitelist; do not branch on truthiness)
2. Verify all `{0..totalChunks-1}.part` files exist in the chunk directory
3. Check available disk space via `disk_free_space()` against `2 × declared_file_size`; abort with a clear error if insufficient
4. Enqueue the assembly as an Action Scheduler job (or schedule a one-off WP-Cron event) and return `202 Accepted` with a `jobId`. The client polls `GET /finalize-status/{jobId}` for progress and result. This avoids `set_time_limit(0)`, which the WP.org Coding Standards / WPCS rules flag as a runtime configuration change (see Open Items)
5. Inside the job: open the output file handle in append mode, iterate chunk files in index order, stream `fread`/`fwrite` in 1 MB blocks. Update a running SHA-256 context (`hash_init('sha256')` / `hash_update`) across all chunks. `fclose` and `unlink` each part after appending so peak disk usage stays at roughly file-size, not 2× file-size
6. After assembly, finalize the hash. Compare to the client-supplied `totalSha256`. On mismatch, delete the assembled file and return an error; assembly is wasted but data integrity is preserved
7. Run `finfo_file($assembledPath)` to detect actual MIME. Reject if it conflicts with the declared `mimeType` (for media route, also reject if not in `wp_get_mime_types()`)
8. **If `destination === 'media'`:** swap `$_FILES['async-upload']` to point at the assembled file (the Big File Uploads pattern), then call `media_handle_upload()` with `wp_handle_sideload` action. This runs `wp_check_filetype_and_ext()` natively. Return the new attachment ID and URL
9. **If `destination === 'import'`:** apply the configured filename collision policy (see Settings), move the file to the configured imports directory. Return the final path and file size. No media library entry created
10. Delete the `{uploadId}/` chunk directory
11. The job updates a transient (`cf_chunked_upload_job_{jobId}`) with status / result so the polling endpoint can return it

---

#### `GET /status/{uploadId}`

Returns which chunks have been received for a given upload session. Validates `uploadId` format before reading the directory. Used by the client to verify all chunks landed before calling finalize, and as the foundation for v2 resume-on-reload.

---

#### `DELETE /upload/{uploadId}`

Cancels an in-progress upload and deletes the temp directory immediately. Required because the cleanup cron can otherwise leave cancelled chunks on disk for up to its retention window. Validates `uploadId` format and capability before deleting.

---

#### `GET /finalize-status/{jobId}`

Returns the status of a finalize job enqueued by `POST /finalize`. Response shape: `{ status: 'pending' | 'running' | 'complete' | 'error', progress?: 0-1, result?: { attachmentId | path, fileSize }, error?: { code, message } }`. The client polls this every 2 seconds while finalize is running.

---

### Cleanup (Cron)

A WP-Cron job (`cf_chunked_upload_cleanup`) runs hourly and deletes any `cf-chunks/{uploadId}/` directory whose **newest** file (including the `.heartbeat` touched per chunk) is more than the configured retention age old (default: 2 hours). Using the newest file is critical: with oldest-file logic, a long-running upload at the boundary of the retention age would be wiped mid-upload. Also fires if the heartbeat file is missing, which only happens if the directory was created by a non-chunk action (cleanup safety net).

---

## File Structure

```
wp-plugins/cf-chunked-upload/
  cf-chunked-upload.php          # Plugin header, constants, bootstrap
  includes/
    RestApi.php                  # Registers WP REST routes
    ChunkReceiver.php            # POST /chunk handler (validates, hashes, writes part)
    FinalizeEnqueue.php          # POST /finalize handler (queues background job)
    FinalizeJob.php              # The Action Scheduler / WP-Cron job that does streaming reassembly + sideload
    FinalizeStatus.php           # GET /finalize-status/{jobId}
    StatusCheck.php              # GET /status/{uploadId}
    Cancel.php                   # DELETE /upload/{uploadId}
    HostInfo.php                 # GET /host-info — reads PHP ini values for the settings readout
    Cleanup.php                  # Cron job registration and handler (newest-file age policy)
    Capabilities.php             # Permission callbacks for REST routes
    Integrity.php                # Chunk + whole-file SHA-256 verification helpers
    ImporterPage.php             # Registers the standalone importer admin page
    Settings.php                 # Options registration and settings page
  src/
    index.js                     # Entry: mounts standalone importer app + hooks media modal
    Uploader.js                  # Core chunking logic, parallel fetch loop, retry, SHA-256
    Progress.js                  # Progress bar component
    MediaModalHook.js            # Intercepts wp.media file selection for large files
    ImporterApp.js               # Full standalone importer React app
    useUpload.js                 # React hook wrapping Uploader.js
  build/                         # Webpack output (@wordpress/scripts)
  composer.json
  package.json
```

---

## Settings Page

Located at **Media > Chunked Upload** in the WP admin.

---

### Host Limits (read-only, top of page)

A card showing live values read from `phpinfo` / `ini_get` at page load via a `GET /cf-chunked-upload/v1/host-info` REST call:

| Limit | Value | Source |
|---|---|---|
| Max upload filesize | e.g. 64 MB | `upload_max_filesize` |
| Max POST size | e.g. 64 MB | `post_max_size` |
| Memory limit | e.g. 256 MB | `memory_limit` |
| Max execution time | e.g. 30s | `max_execution_time` |
| Free disk space (uploads dir) | e.g. 12.4 GB | `disk_free_space()` |

Below the table: a plain-language note — e.g. *"Your host limits individual file uploads to 64 MB. Chunked upload is active and will split files larger than [threshold] automatically — your effective upload limit is now your available disk space."*

---

### Chunk Settings

**Default chunk size**
- Radio group: 2 MB / 4 MB / **8 MB** (default) / 16 MB / Custom
- Custom: text input accepting MB value
- The selector is **clamped at save time** to `min(upload_max_filesize, post_max_size)` minus a 10% safety margin. The clamp value is calculated from the live host-info readout. If the user picks 16 MB on a host that caps requests at 8 MB, the save reduces it to ~7 MB and shows an inline warning explaining why. Big File Uploads uses the same 80%-of-host-limit pattern as its default.
- Description blurb: *"Each chunk is one HTTP request to your server. Larger chunks mean fewer requests and faster uploads on good connections, but increase the chance of a single chunk failing on unstable connections. 8 MB is a safe default that works on virtually all hosts. Chunks must fit inside your host's `upload_max_filesize` and `post_max_size`; we clamp automatically."*

**Concurrent chunk uploads**
- Number input, default: 3, range: 1–6
- Description blurb: *"How many chunks to upload at the same time. Higher values are faster on good connections; lower values are more reliable on flaky ones. Set to 1 to disable parallelism entirely."*

**Chunking threshold**
- Number input (MB), default: 10
- Description blurb: *"Files smaller than this are uploaded normally without chunking. Only raise this if you're seeing failures on smaller files."*

**Max retries per chunk**
- Number input, default: 3, range: 1–10
- Description blurb: *"If a chunk upload fails (network hiccup, timeout), the plugin will retry this many times before giving up and surfacing an error."*

---

### Standalone Importer Settings

**Imports directory**
- Text input, default: `wp-content/cf-imports/`
- Must be an absolute path or path relative to ABSPATH; validated server-side on save
- Description blurb: *"Where uploaded import files are saved on the server. This directory is created automatically if it doesn't exist. It is NOT web-accessible by default — a .htaccess rule is added on creation."*

**Allowed file types**
- Multiselect or tags input, default: all types allowed
- Presets: "Backup files (.zip, .tar, .tar.gz, .sql)", "Media archives (.zip)", "Data exports (.csv, .json, .xml)"
- Server-side: both the extension allowlist AND a post-assembly `finfo_file()` MIME detection are enforced. A `.zip` allowlist with a file whose detected MIME is `application/x-php` is rejected even though its extension matches.
- Description blurb: *"Restricts what file types the importer will accept. Leave empty to allow all types. Files are also re-inspected after upload to confirm the contents match the extension."*

**Filename collision policy**
- Dropdown: "Rename with timestamp" (default) / "Reject" / "Overwrite"
- Default: timestamp rename produces `backup-2026-05-18-145902.zip` when `backup.zip` already exists in the imports directory
- Description blurb: *"What happens when a file with the same name already exists. Overwrite is destructive and not recommended for backup workflows."*

**Required capability**
- Dropdown: `upload_files` / `manage_options` / `administrator` (role)
- Default: `manage_options`
- Description blurb: *"Who can use the standalone importer. The media library chunking respects the standard 'Upload files' capability and is not affected by this setting."*

**Stale chunk cleanup age**
- Number input (hours), default: 2
- Description blurb: *"Incomplete uploads (abandoned or failed) leave temporary chunk files on the server. This controls how long before they're automatically deleted. Lower values free disk space faster; higher values give more time to resume a stalled upload."*

---

### Standalone Importer Admin Page

Located at **Tools > Import Large File**.

Layout:
- Drop zone (full-width) with "or click to browse" fallback
- Active uploads list — each upload shows filename, size, progress bar with speed + ETA, and a cancel button
- Completed uploads list — filename, size, final destination path, timestamp, and a "Copy path" button (useful for pointing backup restore tools at the file)
- A note: *"Files uploaded here are saved directly to the server filesystem and do not appear in the Media Library."*

---

## 8 GB Backup File — Specific Considerations

At 8 MB chunks, an 8 GB file is ~1,024 chunks. Key design decisions driven by this:

- **Assembly must stream.** Never hold the full file in memory. The `Assembler.php` implementation writes and immediately discards each chunk as it appends, keeping peak memory at ~1 MB regardless of file size.
- **Peak disk usage is roughly 1x file size during assembly,** because each `.part` is `unlink`ed immediately after its bytes are appended to the output file. Worst case is 2x at the very start before any unlinks; the disk-space pre-check uses 2x to be safe.
- **Assembly runs in a background job, not the request thread.** Action Scheduler (or a one-off WP-Cron event) drives reassembly. The `/finalize` REST call returns `202` immediately and the client polls `/finalize-status/{jobId}`. This avoids `set_time_limit(0)`, which WPCS / WP.org review flags as a runtime configuration change and is the cleanest way to handle multi-minute assembly without depending on a hosting-specific execution-time setting.
- **Integrity is verified end-to-end.** Per-chunk SHA-256 catches network corruption at upload time; whole-file SHA-256 catches assembly bugs and silent disk issues. Both are mandatory; a backup file is exactly the kind of payload where silent corruption is unacceptable.
- **ETA matters.** At a typical shared host upload speed of 5–10 MB/s, 8 GB takes 13–27 minutes. The progress UI must show estimated time remaining or the user will assume it's hung.
- **Tab/window closure warning.** A `beforeunload` handler fires (`event.preventDefault()` and assigning a string to `event.returnValue`) if the user tries to close the tab during an active upload. All modern browsers (Chrome 51+, Firefox 44+, Safari 9.1+) ignore custom messages and show their own generic prompt — that's the expected behavior and the only available behavior. No resume-on-reload in v1, so losing progress means starting over.
- **Nonce lifetime.** WordPress REST nonces (`wp_rest`) have a 12–24 hour lifetime depending on when they were minted relative to the 12-hour epoch tick. For uploads under an hour this is a non-issue. For uploads that span a working day or a long pause, the client refreshes the nonce by re-fetching it via `apiFetch` (or a small dedicated endpoint) before each chunk POST that detects a 403 response, then retries the chunk once.

---

## Release Stages

**v1 — Internal**
- Full feature set above
- No readme.txt, no WordPress.org assets
- Distributed as a zip or installed directly from the monorepo

**v2 — Standalone Distribution**
- Resume-on-reload (persist chunk state to WP options or a custom DB table)
- Plugin/theme ZIP upload hook (WP_Upgrader integration)
- Proper readme.txt and changelog
- Distributed via a private download or GitHub releases

**v3 — WordPress.org**
- Full review pass for WP.org guidelines. The v1 design already avoids `set_time_limit` and `ini_set` by running assembly in a background job, which removes the most common review snag for plugins handling large files
- Internationalization (text domain, .pot file)
- Formal compatibility testing matrix
- Support forum readiness

---

## Open Items

- **Action Scheduler vs WP-Cron for finalize.** Action Scheduler is more reliable for jobs > 30 seconds and ships with WooCommerce, so it's already on millions of installs. WP-Cron is built in but unreliable on low-traffic sites (it piggybacks on page loads). Decision needed for v1: bundle Action Scheduler as a dependency (it handles being loaded multiple times gracefully) vs WP-Cron with a self-rescheduling continuation pattern. Lean toward Action Scheduler.
- **Nginx config snippet.** Settings page must detect Nginx (`$_SERVER['SERVER_SOFTWARE']` heuristic) and surface a copyable `location ~ /wp-content/cf-chunks/ { deny all; return 403; }` block. `.htaccess` covers Apache and LiteSpeed; Nginx requires manual server config.
- **v2 resume.** With per-chunk files plus the status endpoint already in place, resume is mostly client-side work: persist `{ uploadId, fileName, totalChunks, fileSha256? }` to `localStorage` keyed by a fingerprint of the selected file (name + size + lastModified). On page load, present any incomplete uploads with a "Resume" button that calls `GET /status/{uploadId}` and continues from the lowest missing index. Bonus: a server-side transient with the same key for cross-device resume, but probably not worth the complexity.
- **Plugin/theme ZIP upload (v2).** Hooking `WP_Upgrader` is harder than the media path because the upgrader expects a synchronous local file. The realistic approach is: chunked upload to the importer destination, then on completion shell out to the upgrader with that path. Worth a separate small spike before committing to v2 scope.
- **MU support.** Big File Uploads is multisite-aware. CF Chunked Upload should at minimum not break on multisite. Decision needed on whether limits and the importer destination are per-site or network-wide.
