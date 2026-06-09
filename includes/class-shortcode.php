<?php

declare(strict_types=1);

namespace FrmSquareHostedCheckout;

if (! defined('ABSPATH')) {
	exit;
}

class Shortcode {
	public function __construct(
		private EntryRepository $repository,
		private PaymentAccess $payment_access,
		private Logger $logger
	) {
	}

	public function register(): void {
		add_shortcode('frm_square_hosted_checkout', [$this, 'render_payment_page']);
		add_shortcode('frm_square_payment_status', [$this, 'render_status_page']);
		add_action('wp_enqueue_scripts', [$this, 'register_assets']);
	}

	public function register_assets(): void {
		wp_register_style('frm-square-hc', FRM_SQUARE_HC_URL . 'assets/css/payment-page.css', [], FRM_SQUARE_HC_VERSION);
		wp_register_script('frm-square-hc', FRM_SQUARE_HC_URL . 'assets/js/payment-page.js', [], FRM_SQUARE_HC_VERSION, true);
		wp_localize_script(
			'frm-square-hc',
			'frmSquareHostedCheckout',
			[
				'restUrl'       => esc_url_raw(rest_url(REST_NAMESPACE . '/payment-link')),
				'statusRestUrl' => esc_url_raw(rest_url(REST_NAMESPACE . '/payment-status')),
				'labels'        => [
					'processing'    => __('Redirecting to secure Square checkout...', 'frm-square-hosted-checkout'),
					'failed'        => __('Unable to start checkout. Please try again.', 'frm-square-hosted-checkout'),
					'statusWaiting' => __('Still waiting for payment confirmation. You can refresh this page.', 'frm-square-hosted-checkout'),
				],
			]
		);
	}

	public function render_payment_page(array $atts = []): string {
		wp_enqueue_style('frm-square-hc');
		wp_enqueue_script('frm-square-hc');

		$entry_key = sanitize_text_field($_GET['entry'] ?? '');
		$token     = sanitize_text_field($_GET['token'] ?? '');

		if ($entry_key !== '' && $token === '') {
			$cookie_token = $this->payment_access->get_public_token_cookie($entry_key);
			if ($cookie_token !== '') {
				$this->payment_access->clear_public_token_cookie($entry_key);
				wp_safe_redirect(add_query_arg(['entry' => $entry_key, 'token' => $cookie_token]));
				exit;
			}
		}

		try {
			[$entry, $state] = $this->payment_access->validate($entry_key, $token);
		} catch (\Throwable $throwable) {
			return $this->wrap_message(__('This payment link is invalid or has expired.', 'frm-square-hosted-checkout'), 'error');
		}

		$this->logger->log((int) $entry['id'], 'payment_page_viewed', 'viewed', ['status' => $state['payment_status'] ?? '']);

		$can_retry = in_array((string) $state['payment_status'], retryable_statuses(), true) && (string) $state['payment_status'] !== 'succeeded';

		ob_start();
		?>
		<div class="frm-square-hc-card">
			<h2><?php esc_html_e('Redirecting to Secure Payment', 'frm-square-hosted-checkout'); ?></h2>
			<p>
				<?php
				printf(
					esc_html__('Amount due: %1$s %2$s', 'frm-square-hosted-checkout'),
					esc_html((string) $state['payment_amount']),
					esc_html((string) $state['payment_currency'])
				);
				?>
			</p>
			<p><?php esc_html_e('Please wait while we connect you to Square checkout.', 'frm-square-hosted-checkout'); ?></p>
			<p><?php echo esc_html($this->status_label((string) $state['payment_status'])); ?></p>
			<?php if ((string) $state['payment_status'] === 'succeeded') : ?>
				<?php echo $this->wrap_message(__('This entry has already been paid.', 'frm-square-hosted-checkout'), 'success'); ?>
			<?php elseif ($can_retry) : ?>
				<button type="button" class="frm-square-hc-button" data-entry="<?php echo esc_attr($entry_key); ?>" data-token="<?php echo esc_attr($token); ?>" data-retry="<?php echo in_array((string) $state['payment_status'], ['failed', 'abandoned'], true) ? '1' : '0'; ?>">
					<?php esc_html_e('Continue to Secure Payment', 'frm-square-hosted-checkout'); ?>
				</button>
				<p class="frm-square-hc-feedback" aria-live="polite"></p>
			<?php else : ?>
				<?php echo $this->wrap_message(__('This entry is not currently eligible for payment.', 'frm-square-hosted-checkout'), 'error'); ?>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	public function render_status_page(array $atts = []): string {
		wp_enqueue_style('frm-square-hc');
		wp_enqueue_script('frm-square-hc');

		$atts = shortcode_atts(
			[
				'name_field_id'      => 0,
				'reference_field_id' => 0,
				'donation_field_id'  => 0,
				'for_someone_else_field_id' => 0,
			],
			$atts,
			'frm_square_payment_status'
		);

		$entry_key = sanitize_text_field($_GET['entry'] ?? '');
		$token     = sanitize_text_field($_GET['token'] ?? '');

		try {
			[$entry, $state] = $this->payment_access->validate($entry_key, $token);
		} catch (\Throwable $throwable) {
			return $this->wrap_message(__('Unable to load payment status for this entry.', 'frm-square-hosted-checkout'), 'error');
		}

		if ((string) $state['payment_status'] === 'succeeded') {
			return $this->render_thank_you_page($entry, $state, $atts);
		}

		$message = match ((string) $state['payment_status']) {
			'processing', 'checkout_created', 'awaiting_payment', 'pending' => __('Payment pending confirmation. Webhooks remain authoritative.', 'frm-square-hosted-checkout'),
			'failed' => __('Payment failed. You can try again.', 'frm-square-hosted-checkout'),
			'abandoned' => __('Payment was not completed. You can start a fresh checkout.', 'frm-square-hosted-checkout'),
			'refunded' => __('Payment refunded.', 'frm-square-hosted-checkout'),
			'partially_refunded' => __('Payment partially refunded.', 'frm-square-hosted-checkout'),
			default => __('Payment status is currently unavailable.', 'frm-square-hosted-checkout'),
		};

		$status        = (string) $state['payment_status'];
		$retry_allowed = in_array($status, ['failed', 'abandoned', 'pending'], true);
		$polling_attrs = sprintf(
			' data-payment-status-poll="1" data-entry="%1$s" data-token="%2$s" data-status="%3$s"',
			esc_attr($entry_key),
			esc_attr($token),
			esc_attr($status)
		);

		ob_start();
		?>
		<div class="frm-square-hc-card"<?php echo $polling_attrs; ?>>
			<h2><?php esc_html_e('Payment Status', 'frm-square-hosted-checkout'); ?></h2>
			<?php echo $this->wrap_message($message, $status === 'succeeded' ? 'success' : 'info'); ?>
			<p class="frm-square-hc-status-label"><?php echo esc_html($this->status_label($status)); ?></p>
			<p>
				<?php
				printf(
					esc_html__('Saved amount: %1$s %2$s', 'frm-square-hosted-checkout'),
					esc_html((string) $state['payment_amount']),
					esc_html((string) $state['payment_currency'])
				);
				?>
			</p>
			<?php if ($retry_allowed) : ?>
				<button type="button" class="frm-square-hc-button" data-entry="<?php echo esc_attr($entry_key); ?>" data-token="<?php echo esc_attr($token); ?>" data-retry="1">
					<?php esc_html_e('Try Payment Again', 'frm-square-hosted-checkout'); ?>
				</button>
			<?php endif; ?>
			<p class="frm-square-hc-feedback" aria-live="polite"></p>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	private function render_thank_you_page(array $entry, array $state, array $atts): string {
		$config             = get_config();
		$entry_id           = (int) $entry['id'];
		$name_field_id      = (int) $atts['name_field_id'] ?: (int) ($config['applicant_name_fid'] ?? 0);
		$reference_field_id = (int) $atts['reference_field_id'] ?: (int) ($config['membership_number_fid'] ?? 0);
		$donation_field_id  = (int) $atts['donation_field_id'] ?: (int) ($config['donation_fid'] ?? 0);
		$for_someone_else_field_id = (int) $atts['for_someone_else_field_id'] ?: (int) ($config['membership_for_someone_else_fid'] ?? 0);
		$applicant_name     = $this->entry_field_display_value($entry_id, $name_field_id);
		$reference_number   = $this->entry_field_display_value($entry_id, $reference_field_id);
		$donation_amount    = normalize_amount_to_decimal($this->repository->get_entry_meta_value($entry_id, $donation_field_id));
		$has_donation       = (float) $donation_amount > 0;
		$is_for_someone_else = $this->is_yes_answer($this->repository->get_entry_meta_value($entry_id, $for_someone_else_field_id));

		if ($applicant_name === '') {
			$applicant_name = __('Member', 'frm-square-hosted-checkout');
		}

		if ($reference_number === '') {
			$reference_number = (string) ($state['square_reference_id'] ?? $entry_id);
		}

		ob_start();
		?>
		<section class="frm-square-hc-thanks" aria-labelledby="frm-square-hc-thanks-title">
			<div class="frm-square-hc-thanks-hero">
				<p class="frm-square-hc-kicker"><?php esc_html_e('Membership confirmed', 'frm-square-hosted-checkout'); ?></p>
				<h2 id="frm-square-hc-thanks-title">
					<?php
					echo esc_html(
						$is_for_someone_else
							? __('Thank you for purchasing BRPS membership', 'frm-square-hosted-checkout')
							: __('Thank you for joining BRPS', 'frm-square-hosted-checkout')
					);
					?>
				</h2>
				<p>
					<?php
					printf(
						esc_html__('Dear %s,', 'frm-square-hosted-checkout'),
						esc_html($applicant_name)
					);
					?>
				</p>
			</div>

			<div class="frm-square-hc-thanks-body">
				<?php if ($is_for_someone_else) : ?>
					<p><?php esc_html_e("Thank you for purchasing a membership to the Bluebell Railway Preservation Society (BRPS)! We are thrilled to welcome them as a valued member of our community. Your support helps us preserve and celebrate the heritage of the UK's first preserved standard gauge railway.", 'frm-square-hosted-checkout'); ?></p>
				<?php else : ?>
					<p><?php esc_html_e("Thank you for joining the Bluebell Railway Preservation Society (BRPS)! We are thrilled to welcome you as a valued member of our community. Your support helps us preserve and celebrate the heritage of the UK's first preserved standard gauge railway.", 'frm-square-hosted-checkout'); ?></p>
				<?php endif; ?>

				<div class="frm-square-hc-reference">
					<span><?php esc_html_e('Your Membership Application Reference Number', 'frm-square-hosted-checkout'); ?></span>
					<strong><?php echo esc_html($reference_number); ?></strong>
				</div>

				<p>
					<?php
					echo esc_html(
						$is_for_someone_else
							? __('Please allow 14 days for the application to be processed.', 'frm-square-hosted-checkout')
							: __('Please allow 14 days for your application to be processed.', 'frm-square-hosted-checkout')
					);
					?>
				</p>

				<div class="frm-square-hc-contact">
					<h3><?php esc_html_e('Need help?', 'frm-square-hosted-checkout'); ?></h3>
					<p>
						<?php
						printf(
							wp_kses(
								__('If you have any questions or need further assistance, please contact our Membership Office at <a href="mailto:%1$s">%1$s</a> or call <a href="tel:%2$s">%3$s</a>. Calls are answered on Thursdays between 10am and midday, and 1.30pm and 3pm. At other times, please leave a message, and we will get back to you as soon as possible.', 'frm-square-hosted-checkout'),
								[
									'a' => [
										'href' => [],
									],
								]
							),
							'membership@bluebell-railway.com',
							'01825724883',
							'01825 724883'
						);
						?>
					</p>
				</div>

				<p>
					<?php
					echo esc_html(
						$is_for_someone_else
							? __('Thank you once again for your support. We look forward to seeing them at the railway soon!', 'frm-square-hosted-checkout')
							: __('Thank you once again for your support and for becoming a part of the Bluebell Railway family. We look forward to seeing you at the railway soon!', 'frm-square-hosted-checkout')
					);
					?>
				</p>

				<div class="frm-square-hc-payment-summary">
					<h3><?php esc_html_e('Payment Details', 'frm-square-hosted-checkout'); ?></h3>
					<p>
						<?php
						printf(
							$has_donation
								? esc_html__('We have successfully received your payment of %1$s%2$s', 'frm-square-hosted-checkout')
								: esc_html__('We have successfully received your payment of %1$s%2$s.', 'frm-square-hosted-checkout'),
							'&pound;',
							esc_html(normalize_amount_to_decimal($state['payment_amount'] ?? '0'))
						);
						?>
						<?php if ($has_donation) : ?>
							<?php
							printf(
								esc_html__(' and an optional donation of %1$s%2$s. We greatly appreciate your support!', 'frm-square-hosted-checkout'),
								'&pound;',
								esc_html($donation_amount)
							);
							?>
						<?php endif; ?>
					</p>
				</div>
			</div>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	private function wrap_message(string $message, string $type): string {
		return sprintf('<div class="frm-square-hc-message frm-square-hc-%1$s">%2$s</div>', esc_attr($type), esc_html($message));
	}

	private function entry_field_display_value(int $entry_id, int $field_id): string {
		$raw = $this->repository->get_entry_meta_value($entry_id, $field_id);
		if ($raw === '') {
			return '';
		}

		$value = maybe_unserialize($raw);
		if (is_array($value)) {
			$parts = array_filter(array_map(
				static fn ($part): string => trim(is_scalar($part) ? (string) $part : ''),
				$value
			));

			return trim(implode(' ', $parts));
		}

		return trim(wp_strip_all_tags((string) $value));
	}

	private function is_yes_answer(string $raw): bool {
		$value = strtolower(trim(wp_strip_all_tags($raw)));

		return in_array($value, ['1', 'y', 'yes', 'true', 'someone else', 'for someone else'], true);
	}

	private function status_label(string $status): string {
		return match ($status) {
			'pending' => __('Payment has not been started yet.', 'frm-square-hosted-checkout'),
			'checkout_created' => __('Checkout has been created and is ready.', 'frm-square-hosted-checkout'),
			'awaiting_payment' => __('Waiting for Square checkout completion.', 'frm-square-hosted-checkout'),
			'processing' => __('Square is processing the payment.', 'frm-square-hosted-checkout'),
			'succeeded' => __('Payment confirmed by Square.', 'frm-square-hosted-checkout'),
			'failed' => __('Square reported a failed payment.', 'frm-square-hosted-checkout'),
			'abandoned' => __('The checkout session was abandoned or timed out.', 'frm-square-hosted-checkout'),
			'refunded' => __('The charge has been refunded.', 'frm-square-hosted-checkout'),
			'partially_refunded' => __('The charge has been partially refunded.', 'frm-square-hosted-checkout'),
			default => __('Unknown payment status.', 'frm-square-hosted-checkout'),
		};
	}
}
