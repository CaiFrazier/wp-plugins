=== CF Forms ===
Contributors: caifrazier
Tags: forms, rest-api, contact form, spam
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 0.2.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Backend infrastructure for site forms: a REST submission endpoint, spam-resistant validation, entry storage, and admin notification email.

== Description ==

CF Forms is intentionally backend-only: it does not render any form markup. Build your own HTML form (Divi module, block, custom template, anything) and POST its data to the REST endpoint below. CF Forms handles storage, spam resistance, and notification.

**Endpoint:**

`POST /wp-json/cf-forms/v1/submit`

    {
      "form_id": "contact",
      "fields": { "name": "Jane Doe", "email": "jane@example.com", "message": "..." },
      "hp_field": "",
      "rendered_at": 1720450000
    }

* `form_id` (required) becomes a `sanitize_key()`'d slug used to tag the stored entry.
* `fields` (required) is an object of scalar values. Any key containing "email" is run through `sanitize_email()`; everything else through `sanitize_text_field()`.
* `hp_field` is an optional honeypot. Render an input with this name, hide it with CSS (not `display:none` alone; use an off-screen technique), and leave it empty. Bots that fill every field trip it.
* `rendered_at` is an optional unix timestamp of when the form was rendered client-side. Submissions faster than `min_elapsed_seconds` (default 3), and submissions with a future timestamp, are treated as bots.

Every accepted request returns an identical `{"success":true}` on a stored submission, a honeypot trip, and a time-trap trip. Callers cannot distinguish "detected as spam" from "delivered," so scripted submitters get no signal to adapt against. Only malformed requests (missing `form_id`, no valid fields) or a full rate-limit bucket return an error.

**Storage:** entries are stored as a non-public `cff_entry` post type, visible under wp-admin, CF Forms. Nothing is exposed on the front end or in the public REST API. Each entry records the submitted fields, IP, user agent, and whether its notification email was accepted by `wp_mail()`.

**Notification:** each stored (non-spam) submission fires `wp_mail()` to the configured notification address (the site admin email by default). When a submitted field looks like an email address, it is set as the message `Reply-To`. CF Forms does not manage SMTP transport; pair it with an SMTP plugin for reliable delivery.

**Rate limiting:** coarse, transient-backed, per-IP (default 10 requests/hour). Every well-formed request counts against the bucket, including spam trips, so a bot cannot hammer the endpoint with rejected payloads for free. Not a substitute for a WAF, but it blunts scripted floods.

== Hooks ==

Filters:

* `cff_validate_submission` ( `true|WP_Error $valid`, `string $form_id`, `array $fields` ) - return a `WP_Error` to reject a submission before storage (e.g. enforce per-form required fields).
* `cff_client_ip` ( `string $ip`, `array $server` ) - override client-IP resolution for a known proxy setup.
* `cff_notification_recipient` ( `string $to`, `string $form_id`, `array $fields` ) - change or clear (return '') the notification recipient.
* `cff_notification_subject` / `cff_notification_body` / `cff_notification_headers` - customise the notification email.

Actions:

* `cff_entry_created` ( `int $entry_id`, `string $form_id`, `array $fields` ) - fires after a submission is stored and the notification attempted.
* `cff_spam_detected` ( `string $form_id`, `string $ip`, `string $reason` ) - fires on a honeypot or time-trap trip (`$reason` is `honeypot` or `time_trap`).

== Changelog ==

= 0.2.0 =
* Add a rate-limited Continuum support endpoint with ZIP signature validation, a 20 MB cap, private file permissions, entry storage, and SMTP notification attachment.

= 0.1.0 =
* Initial release: REST submission endpoint, entry storage, honeypot + time-trap + rate-limit validation, notification email with Reply-To, extensibility hooks. No front-end form markup yet.
