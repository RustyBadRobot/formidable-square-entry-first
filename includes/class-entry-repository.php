<?php

declare(strict_types=1);

namespace FrmSquareHostedCheckout;

use wpdb;

if (! defined('ABSPATH')) {
	exit;
}

class EntryRepository {
	public function get_entry_by_key(string $entry_key): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $wpdb->prefix . 'frm_items WHERE item_key = %s LIMIT 1',
				sanitize_text_field($entry_key)
			),
			ARRAY_A
		);

		return is_array($row) ? $row : null;
	}

	public function get_entry(int $entry_id): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $wpdb->prefix . 'frm_items WHERE id = %d LIMIT 1',
				$entry_id
			),
			ARRAY_A
		);

		return is_array($row) ? $row : null;
	}

	public function get_entry_meta_value(int $entry_id, int $field_id): string {
		if ($field_id <= 0) {
			return '';
		}

		global $wpdb;

		$value = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT meta_value FROM ' . $wpdb->prefix . 'frm_item_metas WHERE item_id = %d AND field_id = %d ORDER BY id DESC LIMIT 1',
				$entry_id,
				$field_id
			)
		);

		return is_scalar($value) ? (string) $value : '';
	}

	public function initialize_entry_payment_state(int $entry_id): array {
		$entry = $this->get_entry($entry_id);
		if (! $entry) {
			throw new \RuntimeException('Entry not found.');
		}

		$config = get_config();
		if ((int) $entry['form_id'] !== (int) $config['form_id']) {
			return [];
		}

		$existing = $this->get_state($entry_id);
		if (! empty($existing['entry_id'])) {
			return $existing;
		}

		$amount   = $this->get_locked_amount($entry_id);
		$currency = $this->get_locked_currency($entry_id);

		$state = [
			'entry_id'                   => $entry_id,
			'entry_key'                  => (string) $entry['item_key'],
			'form_id'                    => (int) $entry['form_id'],
			'payment_status'             => 'pending',
			'payment_gateway'            => 'square',
			'payment_amount'             => $amount,
			'payment_currency'           => $currency,
			'payment_attempt_count'      => 0,
			'payment_last_error_code'    => '',
			'payment_last_error_detail'  => '',
			'payment_last_error_raw'     => '',
			'payment_access_token'       => '',
			'payment_access_expires_at'  => '',
			'payment_session_uuid'       => wp_generate_uuid4(),
			'payment_created_at'         => current_time_mysql(),
			'payment_updated_at'         => current_time_mysql(),
			'payment_completed_at'       => '',
			'payment_abandoned_at'       => '',
			'square_payment_link_id'     => '',
			'square_payment_link_url'    => '',
			'square_order_id'            => '',
			'square_payment_id'          => '',
			'square_reference_id'        => $config['entry_reference_prefix'] . $entry_id,
			'square_receipt_url'         => '',
			'square_last_webhook_event_id' => '',
			'refunded_amount'            => '0.00',
			'refund_ids_json'            => '[]',
		];

		$this->upsert_state($state);
		$this->mirror_state_to_formidable($entry_id, $state);

		return $this->get_state($entry_id) ?: $state;
	}

	public function get_locked_amount(int $entry_id): string {
		$config = get_config();
		return normalize_amount_to_decimal($this->get_entry_meta_value($entry_id, (int) $config['amount_field_id']));
	}

	public function get_locked_currency(int $entry_id): string {
		$config   = get_config();
		$settings = get_settings();

		if (! empty($config['currency_field_id'])) {
			$value = strtoupper($this->get_entry_meta_value($entry_id, (int) $config['currency_field_id']));
			if ($value !== '') {
				return substr($value, 0, 3);
			}
		}

		return strtoupper((string) $settings['default_currency']);
	}

	public function get_state(int $entry_id): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . state_table_name() . ' WHERE entry_id = %d LIMIT 1',
				$entry_id
			),
			ARRAY_A
		);

		return is_array($row) ? $row : null;
	}

	public function get_state_by_entry_key(string $entry_key): ?array {
		$entry = $this->get_entry_by_key($entry_key);
		if (! $entry) {
			return null;
		}

		return $this->get_state((int) $entry['id']);
	}

	public function upsert_state(array $state): void {
		global $wpdb;

		$existing = $this->get_state((int) $state['entry_id']);
		$now      = current_time_mysql();
		$error_raw = $state['payment_last_error_raw'] ?? '';
		$refund_ids = $state['refund_ids_json'] ?? '[]';

		if (! is_string($error_raw)) {
			$error_raw = wp_json_encode($error_raw);
		}

		if (! is_string($refund_ids)) {
			$refund_ids = wp_json_encode($refund_ids);
		}

		$data = [
			'entry_id'                     => (int) $state['entry_id'],
			'entry_key'                    => sanitize_text_field((string) ($state['entry_key'] ?? '')),
			'form_id'                      => (int) ($state['form_id'] ?? 0),
			'payment_status'               => sanitize_key((string) ($state['payment_status'] ?? 'pending')),
			'payment_gateway'              => sanitize_key((string) ($state['payment_gateway'] ?? 'square')),
			'payment_amount'               => normalize_amount_to_decimal($state['payment_amount'] ?? '0'),
			'payment_currency'             => strtoupper(substr((string) ($state['payment_currency'] ?? ''), 0, 3)),
			'payment_attempt_count'        => (int) ($state['payment_attempt_count'] ?? 0),
			'payment_last_error_code'      => sanitize_text_field((string) ($state['payment_last_error_code'] ?? '')),
			'payment_last_error_detail'    => sanitize_textarea_field((string) ($state['payment_last_error_detail'] ?? '')),
			'payment_last_error_raw'       => $error_raw,
			'payment_access_token'         => sanitize_text_field((string) ($state['payment_access_token'] ?? '')),
			'payment_access_expires_at'    => sanitize_text_field((string) ($state['payment_access_expires_at'] ?? '')),
			'payment_session_uuid'         => sanitize_text_field((string) ($state['payment_session_uuid'] ?? '')),
			'payment_created_at'           => sanitize_text_field((string) ($state['payment_created_at'] ?? $now)),
			'payment_updated_at'           => sanitize_text_field((string) ($state['payment_updated_at'] ?? $now)),
			'payment_completed_at'         => sanitize_text_field((string) ($state['payment_completed_at'] ?? '')),
			'payment_abandoned_at'         => sanitize_text_field((string) ($state['payment_abandoned_at'] ?? '')),
			'square_payment_link_id'       => sanitize_text_field((string) ($state['square_payment_link_id'] ?? '')),
			'square_payment_link_url'      => esc_url_raw((string) ($state['square_payment_link_url'] ?? '')),
			'square_order_id'              => sanitize_text_field((string) ($state['square_order_id'] ?? '')),
			'square_payment_id'            => sanitize_text_field((string) ($state['square_payment_id'] ?? '')),
			'square_reference_id'          => sanitize_text_field((string) ($state['square_reference_id'] ?? '')),
			'square_receipt_url'           => esc_url_raw((string) ($state['square_receipt_url'] ?? '')),
			'square_last_webhook_event_id' => sanitize_text_field((string) ($state['square_last_webhook_event_id'] ?? '')),
			'refunded_amount'              => normalize_amount_to_decimal($state['refunded_amount'] ?? '0'),
			'refund_ids_json'              => $refund_ids,
			'updated_at'                   => $now,
		];

		if ($existing) {
			$wpdb->update(
				state_table_name(),
				$data,
				['entry_id' => (int) $state['entry_id']],
				null,
				['%d']
			);
		} else {
			$data['created_at'] = $now;
			$wpdb->insert(state_table_name(), $data);
		}
	}

	public function update_state(int $entry_id, array $changes): array {
		$current = $this->get_state($entry_id);
		if (! $current) {
			$current = $this->initialize_entry_payment_state($entry_id);
		}

		$previous_status = (string) ($current['payment_status'] ?? '');
		$state = array_merge($current, $changes);
		$state['entry_id'] = $entry_id;
		$new_status = (string) ($state['payment_status'] ?? '');

		$this->upsert_state($state);
		$this->mirror_state_to_formidable($entry_id, $state);

		$updated = $this->get_state($entry_id) ?: $state;
		if ($previous_status !== 'succeeded' && $new_status === 'succeeded') {
			$this->trigger_formidable_success_email($entry_id);
		}

		return $updated;
	}

	private function trigger_formidable_success_email(int $entry_id): void {
		if (
			! class_exists('FrmEntry')
			|| ! class_exists('FrmForm')
			|| ! class_exists('FrmFormActionsController')
		) {
			return;
		}

		$entry = \FrmEntry::getOne($entry_id, true);
		if (! $entry || empty($entry->form_id)) {
			return;
		}

		$form = \FrmForm::getOne($entry->form_id);
		if (! $form) {
			return;
		}

		\FrmFormActionsController::trigger_actions('update', $form, $entry, 'email');
	}

	public function mirror_state_to_formidable(int $entry_id, array $state): void {
		$config       = get_config();
		$mirror_map   = is_array($config['mirror_field_ids']) ? $config['mirror_field_ids'] : [];
		global $wpdb;

		foreach ($mirror_map as $logical_key => $field_id) {
			$field_id = (int) $field_id;
			if ($field_id <= 0 || ! array_key_exists($logical_key, $state)) {
				continue;
			}

			$value       = is_scalar($state[$logical_key]) ? (string) $state[$logical_key] : wp_json_encode($state[$logical_key]);
			$existing_id = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT id FROM ' . $wpdb->prefix . 'frm_item_metas WHERE item_id = %d AND field_id = %d LIMIT 1',
					$entry_id,
					$field_id
				)
			);

			if ($existing_id) {
				$wpdb->update(
					$wpdb->prefix . 'frm_item_metas',
					['meta_value' => $value],
					['id' => (int) $existing_id],
					['%s'],
					['%d']
				);
			} else {
				$wpdb->insert(
					$wpdb->prefix . 'frm_item_metas',
					[
						'item_id'     => $entry_id,
						'field_id'    => $field_id,
						'meta_value'  => $value,
						'created_at'  => current_time_mysql(),
					],
					['%d', '%d', '%s', '%s']
				);
			}
		}
	}

	public function find_entry_id_by_reference(string $reference_id): int {
		global $wpdb;

		$entry_id = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT entry_id FROM ' . state_table_name() . ' WHERE square_reference_id = %s LIMIT 1',
				sanitize_text_field($reference_id)
			)
		);

		return (int) $entry_id;
	}

	public function find_entry_id_by_square_object(string $column, string $value): int {
		$allowed = [
			'square_order_id',
			'square_payment_id',
			'square_payment_link_id',
		];

		if (! in_array($column, $allowed, true)) {
			return 0;
		}

		global $wpdb;

		$entry_id = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT entry_id FROM ' . state_table_name() . ' WHERE ' . $column . ' = %s LIMIT 1',
				sanitize_text_field($value)
			)
		);

		return (int) $entry_id;
	}

	public function get_stale_entries(int $minutes): array {
		global $wpdb;

		$statuses     = non_final_statuses();
		$placeholders = implode(',', array_fill(0, count($statuses), '%s'));
		$threshold    = gmdate('Y-m-d H:i:s', time() - ($minutes * MINUTE_IN_SECONDS));

		$sql = $wpdb->prepare(
			'SELECT * FROM ' . state_table_name() . " WHERE payment_status IN ($placeholders) AND payment_updated_at <= %s",
			[...$statuses, $threshold]
		);

		return $wpdb->get_results($sql, ARRAY_A) ?: [];
	}

	public function get_reconciliation_candidates(): array {
		global $wpdb;

		$statuses     = ['checkout_created', 'awaiting_payment', 'processing'];
		$placeholders = implode(',', array_fill(0, count($statuses), '%s'));

		$sql = $wpdb->prepare(
			'SELECT * FROM ' . state_table_name() . " WHERE payment_status IN ($placeholders) AND (square_order_id <> '' OR square_payment_id <> '')",
			$statuses
		);

		return $wpdb->get_results($sql, ARRAY_A) ?: [];
	}

	public function get_weekly_report_entries(int $incomplete_min_age_hours = 24): array {
		global $wpdb;

		$problem_statuses      = weekly_report_final_problem_statuses();
		$incomplete_statuses   = weekly_report_incomplete_statuses();
		$problem_placeholders  = implode(',', array_fill(0, count($problem_statuses), '%s'));
		$incomplete_placeholders = implode(',', array_fill(0, count($incomplete_statuses), '%s'));
		$threshold             = gmdate('Y-m-d H:i:s', time() - ($incomplete_min_age_hours * HOUR_IN_SECONDS));

		$sql = $wpdb->prepare(
			'SELECT state.*, items.created_at AS entry_created_at
			FROM ' . state_table_name() . ' state
			LEFT JOIN ' . $wpdb->prefix . 'frm_items items ON items.id = state.entry_id
			WHERE state.payment_status IN (' . $problem_placeholders . ')
				OR (state.payment_status IN (' . $incomplete_placeholders . ') AND state.payment_updated_at <= %s)
			ORDER BY state.payment_updated_at ASC',
			[...$problem_statuses, ...$incomplete_statuses, $threshold]
		);

		$rows = $wpdb->get_results($sql, ARRAY_A) ?: [];
		$config = get_config();
		$applicant_name_field_id = (int) ($config['applicant_name_fid'] ?? 0);

		foreach ($rows as &$row) {
			$row['applicant_name'] = $applicant_name_field_id > 0
				? $this->get_entry_meta_value((int) $row['entry_id'], $applicant_name_field_id)
				: '';
		}
		unset($row);

		return $rows;
	}
}
