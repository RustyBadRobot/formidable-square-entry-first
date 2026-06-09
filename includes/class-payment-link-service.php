<?php

declare(strict_types=1);

namespace FrmSquareHostedCheckout;

if (! defined('ABSPATH')) {
	exit;
}

class PaymentLinkService {
	public function __construct(
		private EntryRepository $repository,
		private PaymentAccess $payment_access,
		private SquareClient $square_client,
		private Logger $logger
	) {
	}

	public function create_from_public_request(string $entry_key, string $token, bool $force_retry = false): array {
		[$entry, $state] = $this->payment_access->validate($entry_key, $token);
		$entry_id        = (int) $entry['id'];

		if (($state['payment_status'] ?? '') === 'succeeded') {
			throw new \RuntimeException('This entry has already been paid.');
		}

		if (! in_array((string) $state['payment_status'], retryable_statuses(), true) && ! $force_retry) {
			throw new \RuntimeException('This entry is not eligible for another checkout attempt.');
		}

		if (! empty($state['square_payment_link_url']) && in_array((string) $state['payment_status'], ['checkout_created', 'awaiting_payment'], true) && ! $force_retry) {
			return [
				'success'       => true,
				'entryId'       => $entry_id,
				'paymentLinkId' => (string) $state['square_payment_link_id'],
				'checkoutUrl'   => (string) $state['square_payment_link_url'],
				'status'        => (string) $state['payment_status'],
				'message'       => 'Existing checkout session reused.',
			];
		}

		$amount = normalize_amount_to_decimal((string) $state['payment_amount']);
		if ((float) $amount <= 0) {
			throw new \RuntimeException('The saved entry total is not payable.');
		}

		$currency      = strtoupper((string) $state['payment_currency']);
		$settings      = get_settings();
		$attempt_count = ((int) $state['payment_attempt_count']) + 1;
		$session_uuid  = wp_generate_uuid4();
		$reference_id  = (string) $state['square_reference_id'];
		$idempotency   = sprintf('frm-square-%d-%s', $entry_id, $session_uuid);
		$return_url    = $this->build_status_url((string) $entry['item_key'], $token);

		$this->repository->update_state(
			$entry_id,
			[
				'payment_status'            => 'pending',
				'payment_attempt_count'     => $attempt_count,
				'payment_session_uuid'      => $session_uuid,
				'payment_updated_at'        => current_time_mysql(),
				'payment_last_error_code'   => '',
				'payment_last_error_detail' => '',
				'payment_last_error_raw'    => '',
			]
		);

		$this->logger->log($entry_id, 'payment_link_create_started', 'started', [
			'attempt'      => $attempt_count,
			'amount'       => $amount,
			'currency'     => $currency,
			'reference_id' => $reference_id,
		]);

		$line_items = $this->build_line_items($entry_id, $amount, $currency);

		$payload = [
			'idempotency_key' => $idempotency,
			'order'           => [
				'location_id'  => (string) $settings['location_id'],
				'reference_id' => $reference_id,
				'line_items'   => $line_items,
			],
			'checkout_options' => [
				'redirect_url' => $return_url,
				'enable_coupon' => false,
			],
			'description' => sprintf('Entry %d payment attempt %d', $entry_id, $attempt_count),
		];

		try {
			$response = $this->square_client->create_payment_link($payload);
		} catch (\Throwable $throwable) {
			$this->repository->update_state(
				$entry_id,
				[
					'payment_status'            => 'failed',
					'payment_last_error_code'   => 'payment_link_error',
					'payment_last_error_detail' => $throwable->getMessage(),
					'payment_last_error_raw'    => ['message' => $throwable->getMessage()],
					'payment_updated_at'        => current_time_mysql(),
				]
			);
			$this->logger->log($entry_id, 'payment_link_create_failed', 'error', ['message' => $throwable->getMessage()]);
			throw $throwable;
		}

		$link      = is_array($response['payment_link'] ?? null) ? $response['payment_link'] : [];
		$order     = is_array($response['related_resources']['orders'][0] ?? null) ? $response['related_resources']['orders'][0] : [];
		$checkout  = (string) ($link['url'] ?? '');
		$link_id   = (string) ($link['id'] ?? '');
		$order_id  = (string) ($link['order_id'] ?? ($order['id'] ?? ''));
		$new_state = $this->repository->update_state(
			$entry_id,
			[
				'square_payment_link_id'  => $link_id,
				'square_payment_link_url' => $checkout,
				'square_order_id'         => $order_id,
				'payment_status'          => $checkout !== '' ? 'checkout_created' : 'failed',
				'payment_updated_at'      => current_time_mysql(),
			]
		);

		$this->logger->log($entry_id, 'payment_link_created', 'success', [
			'payment_link_id' => $link_id,
			'checkout_url'    => $checkout,
			'order_id'        => $order_id,
		], $link_id);

		return [
			'success'       => true,
			'entryId'       => $entry_id,
			'paymentLinkId' => $link_id,
			'checkoutUrl'   => $checkout,
			'status'        => (string) $new_state['payment_status'],
			'message'       => 'Checkout link created.',
		];
	}

	private function build_line_items(int $entry_id, string $amount, string $currency): array {
		$config           = get_config();
		$total_minor      = decimal_to_cents($amount);
		$donation_minor   = $this->get_donation_minor($entry_id, (int) ($config['donation_fid'] ?? 0));
		$donation_minor   = min($donation_minor, $total_minor);
		$membership_minor = $total_minor - $donation_minor;

		if ($donation_minor <= 0 || $membership_minor <= 0) {
			return [
				$this->make_line_item($this->get_membership_line_item_name($entry_id, $config), $total_minor, $currency),
			];
		}

		return [
			$this->make_line_item($this->get_membership_line_item_name($entry_id, $config), $membership_minor, $currency),
			$this->make_line_item('Donation', $donation_minor, $currency),
		];
	}

	private function get_donation_minor(int $entry_id, int $field_id): int {
		if ($field_id <= 0) {
			return 0;
		}

		$raw        = trim($this->repository->get_entry_meta_value($entry_id, $field_id));
		$normalized = preg_replace('/[^\d\.,]/', '', $raw);
		$normalized = str_replace(',', '.', (string) $normalized);

		if ($normalized === '' || ! is_numeric($normalized)) {
			return 0;
		}

		return max(0, (int) round(((float) $normalized) * 100, 0, PHP_ROUND_HALF_UP));
	}

	private function get_membership_line_item_name(int $entry_id, array $config): string {
		$type_field_id   = (int) ($config['membership_type_fid'] ?? 0);
		$length_field_id = (int) ($config['membership_length_fid'] ?? 0);
		$type            = $type_field_id > 0 ? trim($this->repository->get_entry_meta_value($entry_id, $type_field_id)) : '';
		$length          = $length_field_id > 0 ? trim($this->repository->get_entry_meta_value($entry_id, $length_field_id)) : '';

		if ($type !== '' && $length !== '') {
			return sprintf('BRPS Membership - %s (%s)', $type, $length);
		}

		if ($type !== '') {
			return sprintf('BRPS Membership - %s', $type);
		}

		return 'BRPS Membership';
	}

	private function make_line_item(string $name, int $amount_minor, string $currency): array {
		return [
			'name' => $name,
			'quantity' => '1',
			'base_price_money' => [
				'amount'   => $amount_minor,
				'currency' => $currency,
			],
		];
	}

	public function build_status_url(string $entry_key, string $token): string {
		$config = get_config();
		$url    = home_url((string) $config['status_page_path']);

		return add_query_arg(
			[
				'entry' => rawurlencode($entry_key),
				'token' => rawurlencode($token),
			],
			$url
		);
	}
}
