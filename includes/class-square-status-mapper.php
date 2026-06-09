<?php

declare(strict_types=1);

namespace FrmSquareHostedCheckout;

if (! defined('ABSPATH')) {
	exit;
}

class SquareStatusMapper {
	public static function map_payment_status(string $square_status): string {
		return match (strtoupper($square_status)) {
			'COMPLETED' => 'succeeded',
			'APPROVED', 'PENDING' => 'processing',
			'CANCELED' => 'abandoned',
			'FAILED' => 'failed',
			default => 'awaiting_payment',
		};
	}

	public static function map_refund_status(string $square_status, int $refunded_cents, int $charged_cents): string {
		$status = strtoupper($square_status);

		if ($status === 'COMPLETED') {
			return $refunded_cents >= $charged_cents ? 'refunded' : 'partially_refunded';
		}

		if ($status === 'PENDING') {
			return 'processing';
		}

		return 'succeeded';
	}
}
