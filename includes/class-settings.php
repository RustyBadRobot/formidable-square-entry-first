<?php

declare(strict_types=1);

namespace FrmSquareHostedCheckout;

if (! defined('ABSPATH')) {
	exit;
}

class Settings {
	public static function init(): void {
		add_action('admin_menu', [self::class, 'register_menu']);
		add_action('admin_init', [self::class, 'register_settings']);
	}

	public static function register_menu(): void {
		add_options_page(
			__('Formidable Square Checkout', 'frm-square-hosted-checkout'),
			__('Formidable Square Checkout', 'frm-square-hosted-checkout'),
			'manage_options',
			'frm-square-hosted-checkout',
			[self::class, 'render_page']
		);
	}

	public static function register_settings(): void {
		register_setting(
			'frm_square_hc_settings_group',
			OPTION_KEY,
			[
				'sanitize_callback' => [self::class, 'sanitize'],
				'default'           => default_settings(),
			]
		);

		add_settings_section(
			'frm_square_hc_main',
			__('Square Settings', 'frm-square-hosted-checkout'),
			static function (): void {
				echo '<p>' . esc_html__('Configure Square credentials, public return URLs, and plugin behaviour. Use HTTPS for all payment-related URLs.', 'frm-square-hosted-checkout') . '</p>';
			},
			'frm-square-hosted-checkout'
		);

		$fields = [
			'application_id'        => __('Square Application ID', 'frm-square-hosted-checkout'),
			'location_id'           => __('Square Location ID', 'frm-square-hosted-checkout'),
			'access_token'          => __('Square Access Token', 'frm-square-hosted-checkout'),
			'environment'           => __('Square Environment', 'frm-square-hosted-checkout'),
			'webhook_signature_key' => __('Webhook Signature Key', 'frm-square-hosted-checkout'),
			'default_currency'      => __('Default Currency', 'frm-square-hosted-checkout'),
			'abandonment_timeout'   => __('Abandonment Timeout (minutes)', 'frm-square-hosted-checkout'),
			'access_token_ttl_minutes' => __('Public Token TTL (minutes)', 'frm-square-hosted-checkout'),
			'success_url'           => __('Success URL', 'frm-square-hosted-checkout'),
			'cancel_url'            => __('Cancel / Return URL', 'frm-square-hosted-checkout'),
			'weekly_report_recipients' => __('Weekly Report Recipients', 'frm-square-hosted-checkout'),
			'enable_logging'        => __('Enable Logging', 'frm-square-hosted-checkout'),
		];

		foreach ($fields as $key => $label) {
			add_settings_field(
				$key,
				$label,
				[self::class, 'render_field'],
				'frm-square-hosted-checkout',
				'frm_square_hc_main',
				['key' => $key]
			);
		}
	}

	public static function sanitize(array $input): array {
		$current = get_settings();
		$output  = $current;

		$output['application_id']           = sanitize_text_field($input['application_id'] ?? '');
		$output['location_id']              = sanitize_text_field($input['location_id'] ?? '');
		$output['environment']              = in_array(($input['environment'] ?? 'sandbox'), ['sandbox', 'production'], true) ? $input['environment'] : 'sandbox';
		$output['default_currency']         = strtoupper(substr(sanitize_text_field($input['default_currency'] ?? 'GBP'), 0, 3));
		$output['abandonment_timeout']      = max(5, absint($input['abandonment_timeout'] ?? 60));
		$output['access_token_ttl_minutes'] = max(15, absint($input['access_token_ttl_minutes'] ?? 1440));
		$output['success_url']              = esc_url_raw($input['success_url'] ?? '');
		$output['cancel_url']               = esc_url_raw($input['cancel_url'] ?? '');
		$output['weekly_report_recipients'] = implode(', ', parse_email_recipients((string) ($input['weekly_report_recipients'] ?? '')));
		$output['enable_logging']           = empty($input['enable_logging']) ? 0 : 1;

		$secrets = [
			'access_token',
			'webhook_signature_key',
		];

		foreach ($secrets as $secret_key) {
			$submitted = (string) ($input[$secret_key] ?? '');
			if ($submitted === '' || $submitted === '********') {
				$output[$secret_key] = $current[$secret_key] ?? '';
			} else {
				$output[$secret_key] = sanitize_text_field($submitted);
			}
		}

		return $output;
	}

	public static function render_field(array $args): void {
		$key      = $args['key'];
		$settings = get_settings();
		$value    = $settings[$key] ?? '';
		$name     = OPTION_KEY . '[' . $key . ']';

		switch ($key) {
			case 'environment':
				?>
				<select name="<?php echo esc_attr($name); ?>">
					<option value="sandbox" <?php selected($value, 'sandbox'); ?>><?php esc_html_e('Sandbox', 'frm-square-hosted-checkout'); ?></option>
					<option value="production" <?php selected($value, 'production'); ?>><?php esc_html_e('Production', 'frm-square-hosted-checkout'); ?></option>
				</select>
				<?php
				break;
			case 'enable_logging':
				?>
				<label>
					<input type="checkbox" name="<?php echo esc_attr($name); ?>" value="1" <?php checked((int) $value, 1); ?> />
					<?php esc_html_e('Write plugin audit events', 'frm-square-hosted-checkout'); ?>
				</label>
				<?php
				break;
			case 'access_token':
			case 'webhook_signature_key':
				$display_value = $value === '' ? '' : '********';
				printf(
					'<input type="password" class="regular-text" autocomplete="off" name="%1$s" value="%2$s" placeholder="%3$s" />',
					esc_attr($name),
					esc_attr($display_value),
					esc_attr__('Leave as ******** to keep the stored value', 'frm-square-hosted-checkout')
				);
				break;
			default:
				printf(
					'<input type="%1$s" class="regular-text" name="%2$s" value="%3$s" />',
					in_array($key, ['abandonment_timeout', 'access_token_ttl_minutes'], true) ? 'number' : 'text',
					esc_attr($name),
					esc_attr((string) $value)
				);
				break;
		}

		if ($key === 'location_id') {
			echo '<p class="description">' . esc_html__('Used when building the Square hosted checkout order.', 'frm-square-hosted-checkout') . '</p>';
		}

		if ($key === 'cancel_url') {
			echo '<p class="description">' . esc_html__('Webhook URL:', 'frm-square-hosted-checkout') . ' <code>' . esc_html(rest_webhook_url()) . '</code></p>';
		}

		if ($key === 'weekly_report_recipients') {
			echo '<p class="description">' . esc_html__('Comma-separated email addresses that should receive the weekly failed and incomplete payment report.', 'frm-square-hosted-checkout') . '</p>';
		}
	}

	public static function render_page(): void {
		if (! current_user_can('manage_options')) {
			return;
		}

		$config = get_config();
		?>
		<div class="wrap">
			<h1><?php esc_html_e('Formidable Square Hosted Checkout', 'frm-square-hosted-checkout'); ?></h1>
			<p>
				<?php
				printf(
					esc_html__('Current Formidable form ID: %1$d. Amount field ID: %2$d. Override these via the %3$s filter if needed.', 'frm-square-hosted-checkout'),
					(int) $config['form_id'],
					(int) $config['amount_field_id'],
					'<code>frm_square_hc_config</code>'
				);
				?>
			</p>
			<form method="post" action="options.php">
				<?php
				settings_fields('frm_square_hc_settings_group');
				do_settings_sections('frm-square-hosted-checkout');
				submit_button();
				?>
			</form>
		</div>
		<?php
	}
}
