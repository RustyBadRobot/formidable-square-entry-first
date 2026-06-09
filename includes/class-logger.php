<?php

declare(strict_types=1);

namespace FrmSquareHostedCheckout;

use wpdb;

if (! defined('ABSPATH')) {
	exit;
}

class Logger {
	public function is_enabled(): bool {
		$settings = get_settings();
		return ! empty($settings['enable_logging']);
	}

	public function log(
		int $entry_id,
		string $event_type,
		string $event_status,
		array $context = [],
		string $square_object_id = '',
		string $request_id = ''
	): void {
		if (! $this->is_enabled()) {
			return;
		}

		global $wpdb;

		$wpdb->insert(
			audit_table_name(),
			[
				'entry_id'         => $entry_id,
				'event_type'       => sanitize_key($event_type),
				'event_status'     => sanitize_key($event_status),
				'square_object_id' => sanitize_text_field($square_object_id),
				'request_id'       => sanitize_text_field($request_id),
				'payload_summary'  => truncate_for_log($context),
				'created_at'       => current_time_mysql(),
			],
			['%d', '%s', '%s', '%s', '%s', '%s', '%s']
		);

		if (defined('WP_DEBUG') && WP_DEBUG) {
			error_log('[frm-square-hc] ' . $event_type . ' ' . truncate_for_log($context, 300));
		}
	}

	public function has_request_been_processed(string $request_id): bool {
		if ($request_id === '') {
			return false;
		}

		global $wpdb;

		$count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . audit_table_name() . ' WHERE request_id = %s AND event_type = %s',
				$request_id,
				'webhook_processed'
			)
		);

		return (int) $count > 0;
	}

	public function get_recent_audit_rows(int $entry_id, int $limit = 10): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . audit_table_name() . ' WHERE entry_id = %d ORDER BY id DESC LIMIT %d',
				$entry_id,
				$limit
			),
			ARRAY_A
		) ?: [];
	}
}
