<?php

declare(strict_types=1);

namespace FrmSquareHostedCheckout;

if (! defined('ABSPATH')) {
	exit;
}

class SquareClient {
	public function request(string $method, string $path, array $body = []): array {
		$settings = get_settings();
		$token    = (string) $settings['access_token'];

		if ($token === '') {
			throw new \RuntimeException('Square access token is not configured.');
		}

		$url  = trailingslashit(square_base_url((string) $settings['environment'])) . ltrim($path, '/');
		$args = [
			'method'  => strtoupper($method),
			'timeout' => 25,
			'headers' => [
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
				'Square-Version' => '2025-10-16',
			],
		];

		if ($body !== []) {
			$args['body'] = wp_json_encode($body);
		}

		$response = wp_remote_request($url, $args);
		if (is_wp_error($response)) {
			throw new \RuntimeException($response->get_error_message());
		}

		$status = (int) wp_remote_retrieve_response_code($response);
		$raw    = (string) wp_remote_retrieve_body($response);
		$data   = json_decode($raw, true);

		if ($status < 200 || $status >= 300) {
			$error_message = 'Square API request failed.';
			if (is_array($data) && ! empty($data['errors'][0]['detail'])) {
				$error_message = (string) $data['errors'][0]['detail'];
			}

			throw new \RuntimeException($error_message . ' HTTP ' . $status);
		}

		return is_array($data) ? $data : [];
	}

	public function create_payment_link(array $payload): array {
		return $this->request('POST', '/v2/online-checkout/payment-links', $payload);
	}

	public function get_payment(string $payment_id): array {
		return $this->request('GET', '/v2/payments/' . rawurlencode($payment_id));
	}

	public function get_order(string $order_id): array {
		return $this->request('GET', '/v2/orders/' . rawurlencode($order_id));
	}
}
