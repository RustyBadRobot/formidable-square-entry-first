<?php

declare(strict_types=1);

namespace FrmSquareHostedCheckout;

if (! defined('ABSPATH')) {
	exit;
}

class Cron {
	public function __construct(
		private EntryRepository $repository,
		private SquareClient $square_client,
		private Logger $logger
	) {
	}

	public function register(): void {
		add_filter('cron_schedules', [$this, 'add_schedule']);
		add_action(CRON_HOOK, [$this, 'run']);
	}

	public function add_schedule(array $schedules): array {
		$schedules['frm_square_hc_every_fifteen'] = [
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => __('Every 15 Minutes (Formidable Square Hosted Checkout)', 'frm-square-hosted-checkout'),
		];

		return $schedules;
	}

	public function run(): void {
		$this->mark_abandoned_entries();
		$this->reconcile_entries();
	}

	public function mark_abandoned_entries(): void {
		$settings = get_settings();
		$rows     = $this->repository->get_stale_entries((int) $settings['abandonment_timeout']);

		foreach ($rows as $row) {
			if ((string) $row['payment_status'] === 'succeeded') {
				continue;
			}

			$this->repository->update_state(
				(int) $row['entry_id'],
				[
					'payment_status'       => 'abandoned',
					'payment_abandoned_at' => current_time_mysql(),
					'payment_updated_at'   => current_time_mysql(),
				]
			);
			$this->logger->log((int) $row['entry_id'], 'abandoned_marked', 'success', ['previous_status' => $row['payment_status']]);
		}
	}

	public function reconcile_entries(): void {
		$candidates = $this->repository->get_reconciliation_candidates();

		foreach ($candidates as $state) {
			$entry_id = (int) $state['entry_id'];

			try {
				if (! empty($state['square_payment_id'])) {
					$payment = $this->square_client->get_payment((string) $state['square_payment_id']);
					$status  = (string) ($payment['payment']['status'] ?? '');
					$mapped  = SquareStatusMapper::map_payment_status($status);

					$this->repository->update_state(
						$entry_id,
						[
							'payment_status'       => $mapped,
							'square_receipt_url'   => esc_url_raw((string) ($payment['payment']['receipt_url'] ?? '')),
							'payment_updated_at'   => current_time_mysql(),
							'payment_completed_at' => $mapped === 'succeeded' ? current_time_mysql() : (string) $state['payment_completed_at'],
						]
					);
					$this->logger->log($entry_id, 'reconciliation_update', 'success', ['source' => 'payment', 'status' => $mapped]);
					continue;
				}

				if (! empty($state['square_order_id'])) {
					$order  = $this->square_client->get_order((string) $state['square_order_id']);
					$square = (string) ($order['order']['state'] ?? '');
					$mapped = $square === 'COMPLETED' ? 'processing' : (string) $state['payment_status'];

					$this->repository->update_state(
						$entry_id,
						[
							'payment_status'     => $mapped,
							'payment_updated_at' => current_time_mysql(),
						]
					);
					$this->logger->log($entry_id, 'reconciliation_update', 'success', ['source' => 'order', 'status' => $mapped]);
				}
			} catch (\Throwable $throwable) {
				$this->logger->log($entry_id, 'reconciliation_update', 'error', ['message' => $throwable->getMessage()]);
			}
		}
	}
}
