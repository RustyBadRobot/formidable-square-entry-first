<?php

declare(strict_types=1);

namespace FrmSquareHostedCheckout;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if (! defined('ABSPATH')) {
	exit;
}

class RestController {
	public function __construct(
		private PaymentLinkService $payment_links,
		private PaymentAccess $payment_access,
		private WebhookService $webhooks,
		private Logger $logger
	) {
	}

	public function register_routes(): void {
		register_rest_route(
			REST_NAMESPACE,
			'/payment-link',
			[
				'methods'             => 'POST',
				'callback'            => [$this, 'create_payment_link'],
				'permission_callback' => '__return_true',
			]
		);

		register_rest_route(
			REST_NAMESPACE,
			'/webhook',
			[
				'methods'             => 'POST',
				'callback'            => [$this, 'webhook'],
				'permission_callback' => '__return_true',
			]
		);

		register_rest_route(
			REST_NAMESPACE,
			'/payment-status',
			[
				'methods'             => 'GET',
				'callback'            => [$this, 'payment_status'],
				'permission_callback' => '__return_true',
			]
		);
	}

	public function create_payment_link(WP_REST_Request $request): WP_REST_Response|WP_Error {
		$entry_key   = sanitize_text_field((string) $request->get_param('entry'));
		$token       = sanitize_text_field((string) $request->get_param('token'));
		$force_retry = filter_var($request->get_param('retry'), FILTER_VALIDATE_BOOL);

		if ($entry_key === '' || $token === '') {
			return new WP_Error('frm_square_missing_params', 'Missing entry or token.', ['status' => 400]);
		}

		try {
			$result = $this->payment_links->create_from_public_request($entry_key, $token, (bool) $force_retry);
			$this->logger->log((int) $result['entryId'], 'redirect_to_square', 'success', ['checkout_url' => $result['checkoutUrl']]);
			return new WP_REST_Response($result, 200);
		} catch (\Throwable $throwable) {
			return new WP_REST_Response(
				[
					'success' => false,
					'message' => $throwable->getMessage(),
				],
				400
			);
		}
	}

	public function webhook(WP_REST_Request $request): WP_REST_Response {
		return $this->webhooks->handle($request);
	}

	public function payment_status(WP_REST_Request $request): WP_REST_Response {
		$entry_key = sanitize_text_field((string) $request->get_param('entry'));
		$token     = sanitize_text_field((string) $request->get_param('token'));

		if ($entry_key === '' || $token === '') {
			return new WP_REST_Response(
				[
					'success' => false,
					'message' => __('Missing entry or token.', 'frm-square-hosted-checkout'),
				],
				400
			);
		}

		try {
			[, $state] = $this->payment_access->validate($entry_key, $token);
		} catch (\Throwable $throwable) {
			return new WP_REST_Response(
				[
					'success' => false,
					'message' => __('Unable to load payment status for this entry.', 'frm-square-hosted-checkout'),
				],
				403
			);
		}

		$status = (string) ($state['payment_status'] ?? 'pending');

		return new WP_REST_Response(
			[
				'success'     => true,
				'status'      => $status,
				'isFinal'     => in_array($status, final_statuses(), true),
				'isSucceeded' => $status === 'succeeded',
				'message'     => $this->status_label($status),
				'reloadUrl'   => $this->build_status_url($entry_key, $token),
			],
			200
		);
	}

	private function build_status_url(string $entry_key, string $token): string {
		$config = get_config();

		return add_query_arg(
			[
				'entry' => rawurlencode($entry_key),
				'token' => rawurlencode($token),
			],
			home_url((string) $config['status_page_path'])
		);
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
