<?php

declare(strict_types=1);

namespace FrmSquareHostedCheckout;

if (! defined('ABSPATH')) {
	exit;
}

class Plugin {
	private static ?self $instance = null;
	private EntryRepository $repository;
	private PaymentAccess $payment_access;
	private PaymentLinkService $payment_links;
	private Logger $logger;
	private string $last_created_entry_key = '';
	private string $last_created_entry_token = '';

	public static function instance(): self {
		if (! self::$instance) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		$this->repository     = new EntryRepository();
		$this->payment_access = new PaymentAccess($this->repository);
		$this->logger         = new Logger();

		$square_client   = new SquareClient();
		$this->payment_links = new PaymentLinkService($this->repository, $this->payment_access, $square_client, $this->logger);
		$webhooks        = new WebhookService($this->repository, $square_client, $this->logger);
		$rest_controller = new RestController($this->payment_links, $this->payment_access, $webhooks, $this->logger);
		$shortcode       = new Shortcode($this->repository, $this->payment_access, $this->logger);
		$cron            = new Cron($this->repository, $square_client, $this->logger);

		add_action('plugins_loaded', [Settings::class, 'init']);
		add_action('rest_api_init', [$rest_controller, 'register_routes']);
		add_action('init', [$shortcode, 'register']);
		add_action('init', [$cron, 'register']);
		add_action('frm_after_create_entry', [$this, 'handle_formidable_entry_created'], 20, 2);
		add_action('template_redirect', [$this, 'maybe_bootstrap_tokenized_redirect']);
		add_action('template_redirect', [$this, 'maybe_auto_redirect_to_square'], 20);
		add_action('admin_notices', [$this, 'render_admin_entry_notice']);
		add_filter('allowed_redirect_hosts', [$this, 'allow_square_checkout_redirect_hosts']);
		add_filter('frm_redirect_url', [$this, 'normalize_formidable_payment_redirect'], 20, 3);
	}

	public static function activate(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$state   = state_table_name();
		$audit   = audit_table_name();

		$state_sql = "CREATE TABLE {$state} (
			entry_id BIGINT UNSIGNED NOT NULL,
			entry_key VARCHAR(190) NOT NULL,
			form_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			payment_status VARCHAR(50) NOT NULL DEFAULT 'pending',
			payment_gateway VARCHAR(50) NOT NULL DEFAULT 'square',
			payment_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
			payment_currency CHAR(3) NOT NULL DEFAULT 'GBP',
			payment_attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
			payment_last_error_code VARCHAR(100) NOT NULL DEFAULT '',
			payment_last_error_detail TEXT NULL,
			payment_last_error_raw LONGTEXT NULL,
			payment_access_token VARCHAR(128) NOT NULL DEFAULT '',
			payment_access_expires_at DATETIME NULL,
			payment_session_uuid VARCHAR(64) NOT NULL DEFAULT '',
			payment_created_at DATETIME NULL,
			payment_updated_at DATETIME NULL,
			payment_completed_at DATETIME NULL,
			payment_abandoned_at DATETIME NULL,
			square_payment_link_id VARCHAR(100) NOT NULL DEFAULT '',
			square_payment_link_url TEXT NULL,
			square_order_id VARCHAR(100) NOT NULL DEFAULT '',
			square_payment_id VARCHAR(100) NOT NULL DEFAULT '',
			square_reference_id VARCHAR(100) NOT NULL DEFAULT '',
			square_receipt_url TEXT NULL,
			square_last_webhook_event_id VARCHAR(100) NOT NULL DEFAULT '',
			refunded_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
			refund_ids_json LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (entry_id),
			KEY payment_status (payment_status),
			KEY square_order_id (square_order_id),
			KEY square_payment_id (square_payment_id),
			KEY square_payment_link_id (square_payment_link_id),
			KEY square_reference_id (square_reference_id)
		) {$charset};";

		$audit_sql = "CREATE TABLE {$audit} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			entry_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			event_type VARCHAR(100) NOT NULL,
			event_status VARCHAR(50) NOT NULL,
			square_object_id VARCHAR(100) NOT NULL DEFAULT '',
			request_id VARCHAR(100) NOT NULL DEFAULT '',
			payload_summary TEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY entry_id (entry_id),
			KEY event_type (event_type),
			KEY request_id (request_id)
		) {$charset};";

		dbDelta($state_sql);
		dbDelta($audit_sql);
		add_option(OPTION_KEY, default_settings());

		add_filter(
			'cron_schedules',
			static function (array $schedules): array {
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
		);

		if (! wp_next_scheduled(CRON_HOOK)) {
			wp_schedule_event(time() + 300, 'frm_square_hc_every_fifteen', CRON_HOOK);
		}

		if (! wp_next_scheduled(WEEKLY_REPORT_CRON_HOOK)) {
			wp_schedule_event(time() + HOUR_IN_SECONDS, 'frm_square_hc_weekly', WEEKLY_REPORT_CRON_HOOK);
		}
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook(CRON_HOOK);
		wp_clear_scheduled_hook(WEEKLY_REPORT_CRON_HOOK);
	}

	public function handle_formidable_entry_created(int $entry_id, int $form_id): void {
		$config = get_config();
		if ((int) $form_id !== (int) $config['form_id']) {
			return;
		}

		$state = $this->repository->initialize_entry_payment_state($entry_id);
		if ($state === []) {
			return;
		}

		$entry = $this->repository->get_entry($entry_id);
		if (! $entry) {
			return;
		}

		$token = $this->payment_access->issue_token($entry_id);
		$this->payment_access->set_public_token_cookie((string) $entry['item_key'], $token);
		$this->last_created_entry_key   = (string) $entry['item_key'];
		$this->last_created_entry_token = $token;

		$this->logger->log($entry_id, 'entry_saved_for_payment', 'success', [
			'entry_key' => $entry['item_key'],
			'amount'    => $state['payment_amount'],
			'currency'  => $state['payment_currency'],
		]);
	}

	public function normalize_formidable_payment_redirect(mixed $url, object $form, array $params): mixed {
		$config = get_config();
		if ((int) ($form->id ?? 0) !== (int) $config['form_id']) {
			return $url;
		}

		$action = (string) ($params['action'] ?? ($_POST['frm_action'] ?? 'create'));
		if ($action !== '' && $action !== 'create') {
			return $url;
		}

		$url_string = is_scalar($url) ? (string) $url : '';
		$entry_key  = $this->get_query_arg_from_url($url_string, 'entry') ?: $this->last_created_entry_key;
		if ($entry_key === '') {
			return $url;
		}

		$token = $this->last_created_entry_key === $entry_key ? $this->last_created_entry_token : '';
		if ($token === '') {
			$entry = $this->repository->get_entry_by_key($entry_key);
			if ($entry) {
				$token = $this->payment_access->issue_token((int) $entry['id']);
				$this->payment_access->set_public_token_cookie($entry_key, $token);
				$this->last_created_entry_key   = $entry_key;
				$this->last_created_entry_token = $token;
			}
		}

		$args = ['entry' => $entry_key];
		if ($token !== '') {
			$args['token'] = $token;
		}

		return add_query_arg($args, home_url((string) $config['payment_page_path']));
	}

	public function maybe_bootstrap_tokenized_redirect(): void {
		if (is_admin()) {
			return;
		}

		$entry_key = sanitize_text_field($_GET['entry'] ?? '');
		$token     = sanitize_text_field($_GET['token'] ?? '');

		if ($entry_key === '' || $token !== '') {
			return;
		}

		$cookie_token = $this->payment_access->get_public_token_cookie($entry_key);
		if ($cookie_token === '') {
			return;
		}

		$this->payment_access->clear_public_token_cookie($entry_key);
		wp_safe_redirect(add_query_arg(['entry' => $entry_key, 'token' => $cookie_token]));
		exit;
	}

	public function maybe_auto_redirect_to_square(): void {
		if (is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) {
			return;
		}

		$entry_key = sanitize_text_field($_GET['entry'] ?? '');
		$token     = sanitize_text_field($_GET['token'] ?? '');

		if ($entry_key === '' || $token === '' || ! $this->is_payment_page_request()) {
			return;
		}

		try {
			$result = $this->payment_links->create_from_public_request($entry_key, $token, false);
		} catch (\Throwable $throwable) {
			$this->logger->log(0, 'payment_page_auto_redirect_failed', 'error', [
				'entry_key' => $entry_key,
				'message'   => $throwable->getMessage(),
			]);
			return;
		}

		$checkout_url = esc_url_raw((string) ($result['checkoutUrl'] ?? ''));
		if ($checkout_url === '') {
			return;
		}

		$this->logger->log((int) ($result['entryId'] ?? 0), 'payment_page_auto_redirect', 'success', [
			'checkout_url' => $checkout_url,
		]);

		wp_safe_redirect($checkout_url);
		exit;
	}

	public function allow_square_checkout_redirect_hosts(array $hosts): array {
		return array_values(array_unique(array_merge($hosts, square_checkout_hosts())));
	}

	private function is_payment_page_request(): bool {
		$config        = get_config();
		$payment_path  = wp_parse_url(home_url((string) $config['payment_page_path']), PHP_URL_PATH);
		$request_path  = wp_parse_url((string) wp_unslash($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
		$payment_path  = untrailingslashit((string) $payment_path);
		$request_path  = untrailingslashit((string) $request_path);

		return $payment_path !== '' && $payment_path === $request_path;
	}

	private function get_query_arg_from_url(string $url, string $key): string {
		$query = (string) wp_parse_url($url, PHP_URL_QUERY);
		if ($query === '') {
			return '';
		}

		parse_str($query, $args);
		if (! is_scalar($args[$key] ?? null)) {
			return '';
		}

		return sanitize_text_field((string) $args[$key]);
	}

	public function render_admin_entry_notice(): void {
		if (! current_user_can('manage_options')) {
			return;
		}

		$entry_id = absint($_GET['id'] ?? 0);
		if ($entry_id <= 0) {
			return;
		}

		$state = $this->repository->get_state($entry_id);
		if (! $state) {
			return;
		}

		$audit_rows = $this->logger->get_recent_audit_rows($entry_id, 5);
		?>
		<div class="notice notice-info">
			<p><strong><?php esc_html_e('Square Payment Summary', 'frm-square-hosted-checkout'); ?></strong></p>
			<p>
				<?php
				printf(
					esc_html__('Status: %1$s | Amount: %2$s %3$s | Attempts: %4$d | Payment Link ID: %5$s | Payment ID: %6$s | Order ID: %7$s', 'frm-square-hosted-checkout'),
					esc_html((string) $state['payment_status']),
					esc_html((string) $state['payment_amount']),
					esc_html((string) $state['payment_currency']),
					(int) $state['payment_attempt_count'],
					esc_html((string) $state['square_payment_link_id']),
					esc_html((string) $state['square_payment_id']),
					esc_html((string) $state['square_order_id'])
				);
				?>
			</p>
			<?php if (! empty($state['square_receipt_url'])) : ?>
				<p><a href="<?php echo esc_url((string) $state['square_receipt_url']); ?>" target="_blank" rel="noreferrer"><?php esc_html_e('Open Square receipt', 'frm-square-hosted-checkout'); ?></a></p>
			<?php endif; ?>
			<?php if (! empty($state['payment_last_error_detail'])) : ?>
				<p><?php echo esc_html((string) $state['payment_last_error_detail']); ?></p>
			<?php endif; ?>
			<?php if ($audit_rows) : ?>
				<p><?php esc_html_e('Recent audit trail:', 'frm-square-hosted-checkout'); ?></p>
				<ul>
					<?php foreach ($audit_rows as $row) : ?>
						<li><?php echo esc_html(sprintf('%s - %s (%s)', $row['created_at'], $row['event_type'], $row['event_status'])); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
		<?php
	}
}
