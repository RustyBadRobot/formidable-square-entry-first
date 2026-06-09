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
		add_action(WEEKLY_REPORT_CRON_HOOK, [$this, 'send_weekly_report']);
		$this->ensure_events_scheduled();
	}

	public function add_schedule(array $schedules): array {
		$schedules['frm_square_hc_every_fifteen'] = [
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => __('Every 15 Minutes (Formidable Square Hosted Checkout)', 'frm-square-hosted-checkout'),
		];

		$schedules['frm_square_hc_weekly'] = [
			'interval' => WEEK_IN_SECONDS,
			'display'  => __('Weekly (Formidable Square Hosted Checkout)', 'frm-square-hosted-checkout'),
		];

		return $schedules;
	}

	public function run(): void {
		$this->mark_abandoned_entries();
		$this->reconcile_entries();
	}

	public function ensure_events_scheduled(): void {
		if (! wp_next_scheduled(CRON_HOOK)) {
			wp_schedule_event(time() + 300, 'frm_square_hc_every_fifteen', CRON_HOOK);
		}

		if (! wp_next_scheduled(WEEKLY_REPORT_CRON_HOOK)) {
			wp_schedule_event(time() + HOUR_IN_SECONDS, 'frm_square_hc_weekly', WEEKLY_REPORT_CRON_HOOK);
		}
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

	public function send_weekly_report(): void {
		$settings   = get_settings();
		$recipients = parse_email_recipients((string) ($settings['weekly_report_recipients'] ?? ''));

		if ($recipients === []) {
			return;
		}

		$this->reconcile_entries();

		$rows    = $this->repository->get_weekly_report_entries(24);
		$subject = sprintf(
			/* translators: %s: Site name. */
			__('[%s] Weekly failed and incomplete payment report', 'frm-square-hosted-checkout'),
			wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES)
		);
		$headers = ['Content-Type: text/html; charset=UTF-8'];

		$sent = wp_mail($recipients, $subject, $this->build_weekly_report_email($rows), $headers);
		$this->logger->log(0, 'weekly_report_sent', $sent ? 'success' : 'error', [
			'recipients' => $recipients,
			'count'      => count($rows),
		]);
	}

	private function build_weekly_report_email(array $rows): string {
		$site_name = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
		$count     = count($rows);

		ob_start();
		?>
		<p><?php echo esc_html(sprintf(__('Weekly Square payment report for %s.', 'frm-square-hosted-checkout'), $site_name)); ?></p>
		<p>
			<?php
			echo esc_html(
				sprintf(
					_n(
						'%d failed, abandoned, or stale incomplete payment currently needs review.',
						'%d failed, abandoned, or stale incomplete payments currently need review.',
						$count,
						'frm-square-hosted-checkout'
					),
					$count
				)
			);
			?>
		</p>

		<?php if ($rows === []) : ?>
			<p><?php esc_html_e('No failed, abandoned, or stale incomplete payments were found.', 'frm-square-hosted-checkout'); ?></p>
		<?php else : ?>
			<table border="1" cellpadding="6" cellspacing="0" style="border-collapse: collapse; width: 100%;">
				<thead>
					<tr>
						<th align="left"><?php esc_html_e('Entry', 'frm-square-hosted-checkout'); ?></th>
						<th align="left"><?php esc_html_e('Applicant', 'frm-square-hosted-checkout'); ?></th>
						<th align="left"><?php esc_html_e('Status', 'frm-square-hosted-checkout'); ?></th>
						<th align="left"><?php esc_html_e('Amount', 'frm-square-hosted-checkout'); ?></th>
						<th align="left"><?php esc_html_e('Last Updated', 'frm-square-hosted-checkout'); ?></th>
						<th align="left"><?php esc_html_e('Square IDs', 'frm-square-hosted-checkout'); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($rows as $row) : ?>
						<?php
						$entry_id    = (int) $row['entry_id'];
						$entry_url   = admin_url('admin.php?page=formidable-entries&frm_action=show&id=' . $entry_id);
						$square_ids  = array_filter([
							(string) ($row['square_payment_id'] ?? ''),
							(string) ($row['square_order_id'] ?? ''),
							(string) ($row['square_payment_link_id'] ?? ''),
						]);
						?>
						<tr>
							<td><a href="<?php echo esc_url($entry_url); ?>"><?php echo esc_html((string) $entry_id); ?></a></td>
							<td><?php echo esc_html((string) ($row['applicant_name'] ?? '')); ?></td>
							<td><?php echo esc_html((string) ($row['payment_status'] ?? '')); ?></td>
							<td><?php echo esc_html(trim((string) ($row['payment_amount'] ?? '') . ' ' . (string) ($row['payment_currency'] ?? ''))); ?></td>
							<td><?php echo esc_html((string) ($row['payment_updated_at'] ?? '')); ?></td>
							<td><?php echo esc_html(implode(' / ', $square_ids)); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p><?php esc_html_e('Incomplete payments are only included once they have been unchanged for at least 24 hours.', 'frm-square-hosted-checkout'); ?></p>
		<?php endif; ?>
		<?php

		return (string) ob_get_clean();
	}
}
