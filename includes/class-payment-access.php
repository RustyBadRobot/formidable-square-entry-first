<?php

declare(strict_types=1);

namespace FrmSquareHostedCheckout;

if (! defined('ABSPATH')) {
	exit;
}

class PaymentAccess {
	public function __construct(private EntryRepository $repository) {
	}

	public function issue_token(int $entry_id): string {
		$state    = $this->repository->get_state($entry_id) ?: $this->repository->initialize_entry_payment_state($entry_id);
		$settings = get_settings();
		$token    = bin2hex(random_bytes(32));

		$this->repository->update_state(
			$entry_id,
			[
				'payment_access_token'      => hash('sha256', $token),
				'payment_access_expires_at' => gmdate('Y-m-d H:i:s', time() + ((int) $settings['access_token_ttl_minutes'] * MINUTE_IN_SECONDS)),
				'payment_updated_at'        => current_time_mysql(),
				'payment_session_uuid'      => $state['payment_session_uuid'] ?: wp_generate_uuid4(),
			]
		);

		return $token;
	}

	public function validate(string $entry_key, string $token): array {
		$entry = $this->repository->get_entry_by_key($entry_key);
		if (! $entry) {
			throw new \RuntimeException('Entry not found.');
		}

		$state = $this->repository->get_state((int) $entry['id']);
		if (! $state) {
			throw new \RuntimeException('Payment state not initialised.');
		}

		$stored_hash = (string) ($state['payment_access_token'] ?? '');
		$expires_at  = (string) ($state['payment_access_expires_at'] ?? '');

		if ($stored_hash === '' || $expires_at === '') {
			throw new \RuntimeException('Payment access has not been issued for this entry.');
		}

		if (strtotime($expires_at) < time()) {
			throw new \RuntimeException('Payment access token has expired.');
		}

		if (! hash_equals($stored_hash, hash('sha256', $token))) {
			throw new \RuntimeException('Invalid payment access token.');
		}

		return [$entry, $state];
	}

	public function set_public_token_cookie(string $entry_key, string $token): void {
		$config = get_config();
		setcookie(
			COOKIE_PREFIX . $entry_key,
			$token,
			[
				'expires'  => time() + (int) $config['entry_cookie_ttl'],
				'path'     => COOKIEPATH ?: '/',
				'domain'   => COOKIE_DOMAIN ?: '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			]
		);
	}

	public function get_public_token_cookie(string $entry_key): string {
		return sanitize_text_field($_COOKIE[COOKIE_PREFIX . $entry_key] ?? '');
	}

	public function clear_public_token_cookie(string $entry_key): void {
		setcookie(
			COOKIE_PREFIX . $entry_key,
			'',
			[
				'expires'  => time() - HOUR_IN_SECONDS,
				'path'     => COOKIEPATH ?: '/',
				'domain'   => COOKIE_DOMAIN ?: '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			]
		);
	}
}
