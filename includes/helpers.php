<?php

declare(strict_types=1);

namespace FrmSquareHostedCheckout;

if (! defined('ABSPATH')) {
	exit;
}

const OPTION_KEY = 'frm_square_hc_settings';
const STATE_TABLE = 'frm_square_entry_state';
const AUDIT_TABLE = 'frm_square_audit_log';
const COOKIE_PREFIX = 'frm_square_hc_token_';
const CRON_HOOK = 'frm_square_hc_reconcile';
const WEEKLY_REPORT_CRON_HOOK = 'frm_square_hc_weekly_report';
const REST_NAMESPACE = 'frm-square/v1';

function default_settings(): array {
	return [
		'application_id'            => '',
		'location_id'               => '',
		'access_token'              => '',
		'environment'               => 'sandbox',
		'webhook_signature_key'     => '',
		'default_currency'          => 'GBP',
		'abandonment_timeout'       => 60,
		'enable_logging'            => 1,
		'success_url'               => '',
		'cancel_url'                => '',
		'access_token_ttl_minutes'  => 1440,
		'weekly_report_recipients'  => '',
	];
}

function get_settings(): array {
	return wp_parse_args((array) get_option(OPTION_KEY, []), default_settings());
}

function get_config(): array {
	$config = [
		'form_id'               => 8,
		'amount_field_id'       => 532,
		'currency_field_id'     => 0,
		'applicant_name_fid'    => 70,
		'membership_number_fid' => 530,
		'membership_for_someone_else_fid' => 80,
		'membership_type_fid'   => 226,
		'membership_length_fid' => 228,
		'donation_fid'          => 244,
		'mirror_field_ids'      => [
			'payment_status' => 541,
		],
		'payment_page_path'     => '/pay/',
		'status_page_path'      => '/payment-status/',
		'entry_cookie_ttl'      => 600,
		'entry_reference_prefix' => '',
	];

	return apply_filters('frm_square_hc_config', $config);
}

function state_keys(): array {
	return [
		'payment_status',
		'payment_gateway',
		'payment_amount',
		'payment_currency',
		'payment_attempt_count',
		'payment_last_error_code',
		'payment_last_error_detail',
		'payment_last_error_raw',
		'payment_access_token',
		'payment_access_expires_at',
		'payment_session_uuid',
		'payment_created_at',
		'payment_updated_at',
		'payment_completed_at',
		'payment_abandoned_at',
		'square_payment_link_id',
		'square_payment_link_url',
		'square_order_id',
		'square_payment_id',
		'square_reference_id',
		'square_receipt_url',
		'square_last_webhook_event_id',
		'refunded_amount',
		'refund_ids_json',
	];
}

function non_final_statuses(): array {
	return ['pending', 'checkout_created', 'awaiting_payment', 'processing'];
}

function retryable_statuses(): array {
	return ['pending', 'failed', 'abandoned', 'checkout_created', 'awaiting_payment'];
}

function final_statuses(): array {
	return ['succeeded', 'refunded', 'partially_refunded', 'failed', 'abandoned'];
}

function weekly_report_final_problem_statuses(): array {
	return ['failed', 'abandoned'];
}

function weekly_report_incomplete_statuses(): array {
	return ['pending', 'checkout_created', 'awaiting_payment', 'processing'];
}

function parse_email_recipients(string $recipients): array {
	$emails = array_map('trim', explode(',', $recipients));
	$emails = array_filter($emails, static fn (string $email): bool => $email !== '' && is_email($email));

	return array_values(array_unique($emails));
}

function current_time_mysql(): string {
	return current_time('mysql', true);
}

function normalize_amount_to_decimal(mixed $value): string {
	$sanitized = preg_replace('/[^0-9.\-]/', '', (string) $value);
	if ($sanitized === '' || ! is_numeric($sanitized)) {
		return '0.00';
	}

	return number_format((float) $sanitized, 2, '.', '');
}

function decimal_to_cents(string $amount): int {
	return (int) round(((float) $amount) * 100, 0, PHP_ROUND_HALF_UP);
}

function cents_to_decimal(int $amount): string {
	return number_format($amount / 100, 2, '.', '');
}

function state_table_name(): string {
	global $wpdb;
	return $wpdb->prefix . STATE_TABLE;
}

function audit_table_name(): string {
	global $wpdb;
	return $wpdb->prefix . AUDIT_TABLE;
}

function rest_webhook_url(): string {
	return rest_url(REST_NAMESPACE . '/webhook');
}

function square_base_url(string $environment): string {
	return $environment === 'production'
		? 'https://connect.squareup.com'
		: 'https://connect.squareupsandbox.com';
}

function square_checkout_hosts(): array {
	$hosts = [
		'square.link',
		'sandbox.square.link',
	];

	return array_values(array_unique(array_filter(array_map('strtolower', (array) apply_filters('frm_square_hc_checkout_hosts', $hosts)))));
}

function truncate_for_log(mixed $value, int $length = 1000): string {
	$json = wp_json_encode($value);
	if (! is_string($json)) {
		return '';
	}

	if (strlen($json) <= $length) {
		return $json;
	}

	return substr($json, 0, $length) . '...';
}
