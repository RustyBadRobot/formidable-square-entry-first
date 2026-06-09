<?php

declare(strict_types=1);

namespace FrmSquareHostedCheckout;

use WP_REST_Request;
use WP_REST_Response;

if (! defined('ABSPATH')) {
	exit;
}

class WebhookService {
	public function __construct(
		private EntryRepository $repository,
		private SquareClient $square_client,
		private Logger $logger
	) {
	}

	public function handle(WP_REST_Request $request): WP_REST_Response {
		$raw_body  = (string) $request->get_body();
		$signature = (string) $request->get_header('x-square-hmacsha256-signature');
		$event     = json_decode($raw_body, true);

		if (! $this->verify_signature($raw_body, $signature)) {
			$this->logger->log(0, 'webhook_signature_failed', 'error', ['body' => truncate_for_log($raw_body)]);
			return new WP_REST_Response(['success' => false, 'message' => 'Invalid signature'], 401);
		}

		if (! is_array($event)) {
			return new WP_REST_Response(['success' => false, 'message' => 'Invalid payload'], 400);
		}

		$event_id   = sanitize_text_field((string) ($event['event_id'] ?? $event['id'] ?? ''));
		$event_type = sanitize_text_field((string) ($event['type'] ?? 'unknown'));

		if ($this->logger->has_request_been_processed($event_id)) {
			return new WP_REST_Response(['success' => true, 'message' => 'Duplicate ignored'], 200);
		}

		$this->logger->log(0, 'webhook_received', 'received', ['type' => $event_type], '', $event_id);

		try {
			$this->process_event($event, $event_id, $event_type);
		} catch (\Throwable $throwable) {
			$this->logger->log(0, 'webhook_processing_failed', 'error', ['message' => $throwable->getMessage(), 'type' => $event_type], '', $event_id);
			return new WP_REST_Response(['success' => false, 'message' => 'Processing error'], 500);
		}

		return new WP_REST_Response(['success' => true], 200);
	}

	public function verify_signature(string $raw_body, string $signature): bool {
		$settings = get_settings();
		$key      = (string) $settings['webhook_signature_key'];
		if ($key === '' || $signature === '') {
			return false;
		}

		$computed = base64_encode(hash_hmac('sha256', rest_webhook_url() . $raw_body, $key, true));
		return hash_equals($computed, $signature);
	}

	private function process_event(array $event, string $event_id, string $event_type): void {
		$object   = is_array($event['data']['object'] ?? null) ? $event['data']['object'] : [];
		$entry_id = $this->correlate_entry_id($object);

		if ($entry_id <= 0) {
			$this->logger->log(0, 'webhook_correlation_failed', 'warning', ['type' => $event_type, 'object' => $object], '', $event_id);
			$this->logger->log(0, 'webhook_processed', 'ignored', ['type' => $event_type], '', $event_id);
			return;
		}

		$state = $this->repository->get_state($entry_id);
		if (! $state) {
			return;
		}

		if (str_starts_with($event_type, 'payment.')) {
			$this->process_payment_event($entry_id, $state, $object, $event_type, $event_id);
		} elseif (str_starts_with($event_type, 'refund.')) {
			$this->process_refund_event($entry_id, $state, $object, $event_type, $event_id);
		} else {
			$this->logger->log($entry_id, 'webhook_processed', 'ignored', ['type' => $event_type], '', $event_id);
		}
	}

	private function correlate_entry_id(array $object): int {
		$reference_candidates = [
			(string) ($object['order']['reference_id'] ?? ''),
			(string) ($object['payment']['reference_id'] ?? ''),
		];

		foreach ($reference_candidates as $reference_id) {
			$reference_id = sanitize_text_field($reference_id);
			if ($reference_id === '') {
				continue;
			}
			$entry_id = $this->repository->find_entry_id_by_reference($reference_id);
			if ($entry_id > 0) {
				return $entry_id;
			}
		}

		$order_id = sanitize_text_field((string) ($object['payment']['order_id'] ?? $object['order']['id'] ?? ''));
		if ($order_id !== '') {
			$entry_id = $this->repository->find_entry_id_by_square_object('square_order_id', $order_id);
			if ($entry_id > 0) {
				return $entry_id;
			}
		}

		$payment_id = sanitize_text_field((string) ($object['payment']['id'] ?? $object['refund']['payment_id'] ?? ''));
		if ($payment_id !== '') {
			$entry_id = $this->repository->find_entry_id_by_square_object('square_payment_id', $payment_id);
			if ($entry_id > 0) {
				return $entry_id;
			}
		}

		$link_id = sanitize_text_field((string) ($object['payment_link']['id'] ?? ''));
		if ($link_id !== '') {
			return $this->repository->find_entry_id_by_square_object('square_payment_link_id', $link_id);
		}

		return 0;
	}

	private function process_payment_event(int $entry_id, array $state, array $object, string $event_type, string $event_id): void {
		$payment      = is_array($object['payment'] ?? null) ? $object['payment'] : [];
		$payment_id   = sanitize_text_field((string) ($payment['id'] ?? ''));
		$order_id     = sanitize_text_field((string) ($payment['order_id'] ?? ''));
		$receipt_url  = esc_url_raw((string) ($payment['receipt_url'] ?? ''));
		$square_state = sanitize_text_field((string) ($payment['status'] ?? ''));
		$mapped       = SquareStatusMapper::map_payment_status($square_state);

		$changes = [
			'square_payment_id'            => $payment_id ?: (string) $state['square_payment_id'],
			'square_order_id'              => $order_id ?: (string) $state['square_order_id'],
			'square_receipt_url'           => $receipt_url ?: (string) $state['square_receipt_url'],
			'square_last_webhook_event_id' => $event_id,
			'payment_status'               => $mapped,
			'payment_updated_at'           => current_time_mysql(),
		];

		if ($mapped === 'succeeded') {
			$changes['payment_completed_at'] = current_time_mysql();
		}

		if ($mapped === 'abandoned') {
			$changes['payment_abandoned_at'] = current_time_mysql();
		}

		$this->repository->update_state($entry_id, $changes);
		$this->logger->log($entry_id, 'webhook_processed', 'success', ['type' => $event_type, 'status' => $mapped], $payment_id, $event_id);
	}

	private function process_refund_event(int $entry_id, array $state, array $object, string $event_type, string $event_id): void {
		$refund         = is_array($object['refund'] ?? null) ? $object['refund'] : [];
		$refund_id      = sanitize_text_field((string) ($refund['id'] ?? ''));
		$refund_status  = sanitize_text_field((string) ($refund['status'] ?? ''));
		$refunded_cents = (int) ($refund['amount_money']['amount'] ?? 0);
		$charged_cents  = decimal_to_cents((string) ($state['payment_amount'] ?? '0'));
		$existing_ids   = json_decode((string) ($state['refund_ids_json'] ?? '[]'), true);
		$existing_ids   = is_array($existing_ids) ? $existing_ids : [];

		if ($refund_id !== '' && ! in_array($refund_id, $existing_ids, true)) {
			$existing_ids[] = $refund_id;
		}

		$this->repository->update_state(
			$entry_id,
			[
				'payment_status'               => SquareStatusMapper::map_refund_status($refund_status, $refunded_cents, $charged_cents),
				'refunded_amount'              => cents_to_decimal($refunded_cents),
				'refund_ids_json'              => wp_json_encode($existing_ids),
				'square_last_webhook_event_id' => $event_id,
				'payment_updated_at'           => current_time_mysql(),
			]
		);

		$this->logger->log($entry_id, 'webhook_processed', 'success', ['type' => $event_type, 'refund_status' => $refund_status], $refund_id, $event_id);
	}
}
