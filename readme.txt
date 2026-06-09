=== Formidable Square Hosted Checkout ===
Contributors: codex
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.3
Version: 1.0.0
License: GPLv2 or later

Entry-first Square hosted checkout flow for Formidable Forms.

== Description ==

This plugin saves the Formidable entry first, locks the amount from the saved server-side entry, and then redirects the visitor to Square hosted checkout by using Square CreatePaymentLink.

Core flow:

1. Formidable saves entry for form ID 8.
2. Plugin initialises payment state, stores a hashed public access token, and sets a short-lived bootstrap cookie for the first redirect.
3. Payment page shortcode validates entry key + token and creates a Square hosted checkout link on demand.
4. Square webhooks update payment and refund state asynchronously.
5. WP-Cron marks stale entries as abandoned and reconciles uncertain states.

== Installation ==

1. Put this folder in `wp-content/plugins/`.
2. Activate the plugin in WordPress admin.
3. Go to `Settings > Formidable Square Checkout` and enter:
   - Square location ID
   - Square access token
   - Square environment
   - Square webhook signature key
   - default currency
   - success URL
   - cancel URL
4. Create a WordPress page for the payment step and place:
   `[frm_square_hosted_checkout]`
5. Create a WordPress page for payment status and place:
   `[frm_square_payment_status]`
6. Point your Square webhook subscription to:
   `WP REST webhook URL shown on the settings page`

== Formidable Configuration ==

Use your existing form ID `8` and amount field ID `532`.

Recommended confirmation redirect:

`/pay/?entry=[key]`

The plugin will issue the secure payment token server-side after entry creation and bootstrap the first redirect by cookie if the token is not already in the URL. If you later wire a custom redirect filter that appends the token directly, that also works.

Optional integrator filter for alternate IDs and hidden field mirrors:

```php
add_filter('frm_square_hc_config', static function(array $config): array {
	$config['form_id'] = 8;
	$config['amount_field_id'] = 532;
	$config['currency_field_id'] = 0;
	$config['payment_page_path'] = '/pay/';
	$config['status_page_path'] = '/payment-status/';
	$config['mirror_field_ids'] = [
		// Map these if you want payment values mirrored into hidden Formidable fields.
		// 'payment_status' => 123,
		// 'payment_amount' => 124,
		// 'payment_currency' => 125,
	];
	return $config;
});
```

== Database ==

Activation creates:

1. `wp_frm_square_entry_state`
   Stores the current payment lifecycle state keyed by Formidable entry ID.
2. `wp_frm_square_audit_log`
   Stores structured audit events for support and debugging.

The SQL lives in the plugin activation hook in `includes/class-plugin.php`.

== Test Plan ==

Sandbox setup:

1. Use Square sandbox credentials and sandbox webhook subscription.
2. Submit the configured Formidable form with a non-zero total.
3. Confirm the payment page shows the locked amount from the saved entry.

Successful checkout:

1. Click the hosted checkout button.
2. Complete payment with a Square sandbox test card.
3. Confirm webhook updates the entry state to `succeeded`.
4. Confirm the status page shows success and retry is blocked.

Abandoned checkout:

1. Submit the form and open the hosted checkout.
2. Close the tab without paying.
3. Wait for the abandonment timeout plus cron run.
4. Confirm state moves to `abandoned` and retry becomes available.

Declined or failed checkout:

1. Use a Square sandbox failure scenario.
2. Confirm the entry remains saved.
3. Confirm webhook or reconciliation updates the state to `failed`.
4. Confirm retry generates a fresh checkout link.

Webhook processing:

1. Send the webhook to the REST endpoint with valid signature.
2. Re-send the same event ID.
3. Confirm the duplicate is ignored idempotently.

Duplicate protection:

1. Create a checkout link.
2. Click the payment button again before final status.
3. Confirm the existing hosted checkout URL is reused unless retry is explicitly requested.

== Known Limitations / Follow-ups ==

1. Square event payloads can vary by object shape; if your account sends different webhook object nesting, extend the correlation logic in `class-webhook-service.php`.
2. The plugin keeps the authoritative state in its own entry-state table and can optionally mirror selected values into hidden Formidable fields.
3. If you want richer admin UI inside Formidable’s entry screens, replace the lightweight admin notice with a dedicated integration panel once your exact admin screen hooks are confirmed.
