<?php
/**
 * Plugin Name: Ghateino
 * Description: در شرایط قطعی اینترنت یا نیاز به قطع کردن درخواست ها به وبسایت های خاص بهترین گزینه شما افزونه قطعینو هست
 * Version: 1.1.1
 * Plugin URI: https://shokrino.com
 * Author: Shokrino Team
 * Author URI: https://shokrino.com
 * Text Domain: ghatino
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.9
 * Requires PHP: 7.4
 */

defined( 'ABSPATH' ) || exit;

/*
این افزونه توسط تیم شکرینو و متین شکری بصورت رایگان باتوجه به شرایط اینترنت طراحی شده
به هیچ عنوان کپی از این محصول چه بصورت رایگان و چه بصورت شامل هزینه نباید انجام بشه و منتشر بشه

رایگان بودن این افزونه به معنای کپی آزاد نیست!

فقط با اسم قطعینو بصورت رایگان از سایت شکرینو و گیت هاب شکرینو قابل دریافت هست
*/


if ( ! class_exists( 'Ghateino_HTTP_Control' ) ) {

	final class Ghateino_HTTP_Control {

		const OPTION_KEY = 'ghateino_http_control_settings';
		const LOG_KEY    = 'ghateino_http_logs';

		public function __construct() {
			$settings = $this->get_settings();
			$this->prepare_local_assets();

			add_filter( 'pre_http_request', [ $this, 'filter_http_requests' ], 10, 3 );
			add_filter( 'http_request_args', [ $this, 'enforce_request_timeout' ], 20, 2 );

			add_action( 'admin_menu', [ $this, 'add_settings_page' ] );
			add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), [ $this, 'plugin_action_links' ] );
			add_action( 'admin_init', [ $this, 'register_settings' ] );
			add_action( 'admin_init', [ $this, 'handle_clear_logs' ] );
			add_action( 'wp_dashboard_setup', [ $this, 'register_dashboard_widget' ] );
			add_filter( 'script_loader_src', [ $this, 'maybe_replace_script_src' ], 20, 2 );
			add_filter( 'style_loader_src', [ $this, 'maybe_replace_style_src' ], 20, 2 );

			if ( $settings['disable_gravatar'] === 'yes' ) {
				add_filter( 'get_avatar', [ $this, 'disable_gravatar' ], 10, 5 );
			}

			if ( $settings['disable_telemetry'] === 'yes' ) {
				$this->disable_wordpress_telemetry();
			}

			if ( $settings['disable_updates'] === 'yes' ) {
				$this->disable_updates();
			}
		}
		
		public function filter_http_requests( $preempt, $parsed_args, $url ) {
			$settings = $this->get_settings();
			$mode     = $settings['mode'];

			if ( $mode === 'disabled' ) {
				return $preempt;
			}

			$host = (string) wp_parse_url( $url, PHP_URL_HOST );

			if ( ! $host ) {
				return $preempt;
			}

			$host = strtolower( $host );

			if ( 'yes' === $settings['block_mixpanel'] && $this->is_mixpanel_host( $host ) ) {
				$this->log_request( $url, $host, 'blocked_mixpanel' );
				return new WP_Error( 'ghateino_blocked', 'Blocked by Ghateino Mixpanel Rule' );
			}

			if ( $mode === 'whitelist' ) {
				$whitelist = array_map( 'trim', explode( "\n", $settings['whitelist'] ) );
				if ( ! $this->host_in_list( $host, $whitelist ) ) {
					$this->log_request( $url, $host, 'blocked_by_whitelist_mode' );
					return new WP_Error( 'ghateino_blocked', 'Blocked by Ghateino Whitelist Mode' );
				}
			} elseif ( $mode === 'blacklist' ) {
				$blacklist = array_map( 'trim', explode( "\n", $settings['blacklist'] ) );
				if ( $this->host_in_list( $host, $blacklist ) ) {
					$this->log_request( $url, $host, 'blocked_by_blacklist_mode' );
					return new WP_Error( 'ghateino_blocked', 'Blocked by Ghateino Blacklist Mode' );
				}
			}

			return $preempt;
		}

		public function enforce_request_timeout( $args, $url ) {
			$settings = $this->get_settings();

			if ( 'yes' !== $settings['enable_timeout_guard'] ) {
				return $args;
			}

			$timeout_limit = $this->normalize_timeout_limit( $settings['max_request_timeout'] ?? '3' );
			if ( $timeout_limit <= 0 ) {
				return $args;
			}

			$host = (string) wp_parse_url( $url, PHP_URL_HOST );
			if ( '' !== $host && $this->is_local_host( $host ) ) {
				return $args;
			}

			$current_timeout = isset( $args['timeout'] ) ? (float) $args['timeout'] : 0;
			if ( $current_timeout > 0 && $current_timeout <= $timeout_limit ) {
				return $args;
			}

			$args['timeout'] = $timeout_limit;

			if ( '' !== $host ) {
				$this->log_request( $url, strtolower( $host ), 'timeout_limited_' . (string) $timeout_limit . 's' );
			}

			return $args;
		}

		private function normalize_timeout_limit( $raw_timeout ) {
			$timeout = (int) $raw_timeout;

			if ( $timeout < 1 ) {
				return 1;
			}

			if ( $timeout > 30 ) {
				return 30;
			}

			return $timeout;
		}

		private function is_local_host( $host ) {
			$host = strtolower( trim( (string) $host ) );
			if ( '' === $host ) {
				return true;
			}

			if ( in_array( $host, [ 'localhost', '127.0.0.1', '::1' ], true ) ) {
				return true;
			}

			$site_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
			$site_host = strtolower( trim( $site_host ) );

			return '' !== $site_host && $host === $site_host;
		}

		private function log_request( $url, $host, $mode ) {
			$settings = $this->get_settings();
			
			if ( $settings['enable_logging'] !== 'yes' ) {
				return;
			}

			$logs = get_option( self::LOG_KEY, [] );
			$current_time = current_time( 'timestamp' );
			$retention_days = intval( $settings['log_retention_days'] ) ?: 7;
			$retention_seconds = $retention_days * DAY_IN_SECONDS;

			if ( ! empty( $logs ) ) {
				$logs = array_filter( $logs, function( $log ) use ( $current_time, $retention_seconds ) {
					return ( $current_time - strtotime( $log['time'] ) ) <= $retention_seconds;
				});
			}

			$logs[] = [
				'time' => current_time( 'mysql' ),
				'host' => $host,
				'url'  => $url,
				'mode' => $mode
			];

			if ( count( $logs ) > 500 ) {
				$logs = array_slice( $logs, -500 );
			}

			$logs = array_values( $logs );

			update_option( self::LOG_KEY, $logs );
		}

		public function handle_clear_logs() {
			if ( isset( $_GET['page'] ) && $_GET['page'] === 'ghateino' && isset( $_GET['clear_logs'] ) && current_user_can( 'manage_options' ) ) {
				check_admin_referer( 'ghateino_clear_logs' );
				delete_option( self::LOG_KEY );
				wp_safe_redirect( admin_url( 'options-general.php?page=ghateino&logs_cleared=true' ) );
				exit;
			}
		}

		private function disable_wordpress_telemetry() {
			add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
				if ( strpos( $url, 'wordpress.org' ) !== false ) {
					return new WP_Error( 'ghateino_blocked', 'Telemetry blocked' );
				}
				return $pre;
			}, 20, 3 );
		}

		private function disable_updates() {
			add_filter( 'pre_site_transient_update_core', '__return_null' );
			add_filter( 'pre_site_transient_update_plugins', '__return_null' );
			add_filter( 'pre_site_transient_update_themes', '__return_null' );

			remove_action( 'admin_init', 'wp_version_check' );
			remove_action( 'admin_init', 'wp_update_plugins' );
			remove_action( 'admin_init', 'wp_update_themes' );
		}

		public function disable_gravatar( $avatar, $id_or_email, $size, $default, $alt ) {
			$settings = $this->get_settings();
			$custom_default = ! empty( $settings['gravatar_url'] ) ? $settings['gravatar_url'] : site_url( '/wp-content/themes/bonyadco/assets/img/logo-bonyad.png' );

			return sprintf(
				'<img alt="%s" src="%s" class="avatar avatar-%d photo" height="%d" width="%d" />',
				esc_attr( $alt ),
				esc_url( $custom_default ),
				(int) $size,
				(int) $size,
				(int) $size
			);
		}
		
		public function register_dashboard_widget() {
			add_meta_box(
				'ghateino_dashboard_widget',
				'قطعینو',
				[ $this, 'render_dashboard_widget' ],
				'dashboard',
				'normal',
				'high'
			);
		}

		public function render_dashboard_widget() {
			$settings_url = admin_url( 'options-general.php?page=ghateino' );

			echo '<div class="ghateino-widget-content">';
			echo '<p><strong>همه درخواست ها رو مدیریت کنید تا در شرایط قطع بودن دسترسی سرور شما به اینترنت جهانی بتوانید بدون کاهش سرعت از سایت وردپرس خودتون استفاده کنید</strong></p>';
			echo '<p><strong>برای دریافت تغییرات و اطلاعات مهم حتما سایت و کانال پیامرسان داخلی را داشته باشید</strong></p>';
			echo '<ul>';
			echo '<li><a href="https://shokrino.com" target="_blank" rel="noopener noreferrer">سایت شکرینو</a></li>';
			echo '<li><a href="https://ble.ir/shokrino" target="_blank" rel="noopener noreferrer">کانال پیامرسان بله</a></li>';
			echo '</ul>';
			echo '<p><a class="button button-primary" href="' . esc_url( $settings_url ) . '">تنظیمات افزونه</a></p>';
			echo '</div>';
		}

		public function add_settings_page() {
			add_options_page(
				'تنظیمات قطعینو',
				'قطعینو',
				'manage_options',
				'ghateino',
				[ $this, 'render_settings_page' ]
			);
		}

		public function plugin_action_links( $links ) {
			$settings_url = admin_url( 'options-general.php?page=ghateino' );
			$custom_links = [
				'<a href="' . esc_url( $settings_url ) . '">تنظیمات</a>',
				'<a href="https://shokrino.com" target="_blank" rel="noopener noreferrer">سایت توسعه دهنده</a>',
			];

			return array_merge( $custom_links, $links );
		}


		public function register_settings() {
			register_setting(
				'ghateino_group',
				self::OPTION_KEY,
				[ 'sanitize_callback' => [ $this, 'sanitize_settings' ] ]
			);
		}

		public function sanitize_settings( $input ) {
			$input = is_array( $input ) ? $input : [];

			$mode = isset( $input['mode'] ) ? sanitize_text_field( $input['mode'] ) : 'disabled';
			if ( ! in_array( $mode, [ 'disabled', 'whitelist', 'blacklist' ], true ) ) {
				$mode = 'disabled';
			}

			$gravatar_url = isset( $input['gravatar_url'] ) ? esc_url_raw( trim( (string) $input['gravatar_url'] ) ) : '';
			if ( '' === $gravatar_url ) {
				$gravatar_url = plugin_dir_url( __FILE__ ) . 'user.jpg';
			}

			$retention_days = isset( $input['log_retention_days'] ) ? (string) intval( $input['log_retention_days'] ) : '7';
			if ( ! in_array( $retention_days, [ '1', '3', '7', '15', '30' ], true ) ) {
				$retention_days = '7';
			}

			$max_request_timeout = isset( $input['max_request_timeout'] ) ? (string) $this->normalize_timeout_limit( $input['max_request_timeout'] ) : '3';

			return [
				'mode'                => $mode,
				'whitelist'           => $this->sanitize_host_list( $input['whitelist'] ?? '' ),
				'blacklist'           => $this->sanitize_host_list( $input['blacklist'] ?? '' ),
				'disable_telemetry'   => isset( $input['disable_telemetry'] ) ? 'yes' : 'no',
				'disable_updates'     => isset( $input['disable_updates'] ) ? 'yes' : 'no',
				'disable_gravatar'    => isset( $input['disable_gravatar'] ) ? 'yes' : 'no',
				'gravatar_url'        => $gravatar_url,
				'enable_logging'      => isset( $input['enable_logging'] ) ? 'yes' : 'no',
				'log_retention_days'  => $retention_days,
				'local_asset_rewrite' => isset( $input['local_asset_rewrite'] ) ? 'yes' : 'no',
				'block_mixpanel'      => isset( $input['block_mixpanel'] ) ? 'yes' : 'no',
				'strict_asset_block'  => isset( $input['strict_asset_block'] ) ? 'yes' : 'no',
				'enable_timeout_guard'=> isset( $input['enable_timeout_guard'] ) ? 'yes' : 'no',
				'max_request_timeout' => $max_request_timeout,
			];
		}

		private function sanitize_host_list( $raw_hosts ) {
			$raw_hosts = is_string( $raw_hosts ) ? $raw_hosts : '';
			$lines     = preg_split( '/\r\n|\r|\n/', $raw_hosts );
			$hosts     = [];

			foreach ( $lines as $line ) {
				$line = trim( $line );
				if ( '' === $line ) {
					continue;
				}

				$host = wp_parse_url( $line, PHP_URL_HOST );
				if ( ! $host ) {
					$host = $line;
				}

				$host = strtolower( trim( (string) $host ) );
				$host = preg_replace( '/:\\d+$/', '', $host );

				if ( '' !== $host ) {
					$hosts[] = $host;
				}
			}

			$hosts = array_values( array_unique( $hosts ) );

			return implode( "\n", $hosts );
		}

		private function host_in_list( $host, $domains ) {
			$host = strtolower( trim( (string) $host ) );
			if ( '' === $host || ! is_array( $domains ) ) {
				return false;
			}

			foreach ( $domains as $domain ) {
				$domain = strtolower( trim( (string) $domain ) );
				if ( '' === $domain ) {
					continue;
				}

				if ( $host === $domain ) {
					return true;
				}

				$suffix = '.' . $domain;
				if ( strlen( $host ) > strlen( $domain ) && substr( $host, -strlen( $suffix ) ) === $suffix ) {
					return true;
				}
			}

			return false;
		}

		private function get_quick_whitelist_presets() {
			$category_presets = [
				[
					'label'   => 'زیرساخت و CDN ایرانی',
					'domains' => $this->get_iranian_cdn_infra_domains(),
				],
				[
					'label'   => 'نقشه و مسیریابی',
					'domains' => $this->get_iranian_map_domains(),
				],
				[
					'label'   => 'تبلیغات و آنالیتیکس',
					'domains' => $this->get_iranian_ads_domains(),
				],
				[
					'label'   => 'هاستینگ ایرانی',
					'domains' => $this->get_iranian_hosting_domains(),
				],
				[
					'label'   => 'وبسایت های ایرانی پرکاربرد',
					'domains' => $this->get_iranian_popular_sites_domains(),
				],
				[
					'label'   => 'سرویس های دولتی و اعتماد',
					'domains' => $this->get_iranian_gov_trust_domains(),
				],
				[
					'label'   => 'فونت، ایمیل و مارکت های ایرانی',
					'domains' => $this->get_iranian_misc_domains(),
				],
				[
					'label'   => 'لایسنس افزونه و قالب ایرانی',
					'domains' => $this->get_license_vendor_domains(),
				],
				[
					'label'   => 'شرکت های PSP شاپرک',
					'domains' => $this->get_psp_domains(),
				],
				[
					'label'   => 'پرداخت یارها (درگاه ها)',
					'domains' => $this->get_paymentyar_domains(),
				],
				[
					'label'   => 'پرداخت اقساطی',
					'domains' => $this->get_installment_payment_api_domains(),
				],
				[
					'label'   => 'پیامرسان های داخلی',
					'domains' => $this->get_iranian_messenger_api_domains(),
				],
				[
					'label'   => 'پنل های پیامکی',
					'domains' => $this->get_sms_panel_api_domains(),
				],
				[
					'label'   => 'سرویس های ارسال پستی و پیک',
					'domains' => $this->get_shipping_api_domains(),
				],
			];

			$all_domains = [];
			foreach ( $category_presets as $preset ) {
				if ( ! empty( $preset['domains'] ) && is_array( $preset['domains'] ) ) {
					$all_domains = array_merge( $all_domains, $preset['domains'] );
				}
			}

			$aggregate_preset = [
				'label'   => 'همه دامنه ها اضافه شود',
				'domains' => array_values( array_unique( $all_domains ) ),
			];

			return array_merge( [ $aggregate_preset ], $category_presets );
		}

		private function get_license_vendor_domains() {
			return [
				'shokrino.com',
				'api.shokrino.com',
				'abzarwp.com',
				'api.abzarwp.com',
				'cdn.abzarwp.com',
				'dl.abzarwp.com',
				'zhaket.com',
				'api.zhaket.com',
				'cdn.zhaket.com',
				'files.zhaket.com',
				'rtl-theme.com',
				'api.rtl-theme.com',
				'cdn.rtl-theme.com',
				'dl.rtl-theme.com',
			];
		}

		private function get_psp_domains() {
			return [
				'pep.co.ir',
				'sep.ir',
				'pna.co.ir',
				'pec.ir',
				'sadadpsp.ir',
				'omidpayment.ir',
				'fanavacard.ir',
				'sepehrpay.com',
				'irankish.com',
				'behpardakht.com',
				'ecd-co.ir',
				'asanpardakht.ir',
			];
		}

		private function get_paymentyar_domains() {
			return [
				'zarinpal.com',
				'www.zarinpal.com',
				'api.zarinpal.com',
				'nextpay.org',
				'api.nextpay.org',
				'idpay.ir',
				'api.idpay.ir',
				'pay.ir',
				'api.pay.ir',
				'vandar.io',
				'api.vandar.io',
				'jibit.ir',
				'api.jibit.ir',
				'zibal.ir',
				'api.zibal.ir',
				'payping.ir',
				'api.payping.ir',
			];
		}

		private function get_installment_payment_api_domains() {
			return [
				'digipay.ir',
				'api.digipay.ir',
				'mydigipay.com',
				'api.mydigipay.com',
				'azkivam.com',
				'api.azkivam.com',
				'torob.com',
				'api.torob.com',
				'torobpay.com',
				'api.torobpay.com',
				'snappay.ir',
				'api.snappay.ir',
				'lendo.ir',
				'api.lendo.ir',
				'tara360.com',
				'api.tara360.com',
			];
		}

		private function get_iranian_messenger_api_domains() {
			return [
				'bale.ai',
				'safir.bale.ai',
				'tapi.bale.ai',
				'api.bale.ai',
				'botapi.bale.ai',
				'eitaa.com',
				'api.eitaa.com',
				'rubika.ir',
				'api.rubika.ir',
				'splus.ir',
				'api.splus.ir',
				'gap.im',
				'api.gap.im',
				'igap.net',
				'api.igap.net',
				'soroush-app.ir',
				'api.soroush-app.ir',
			];
		}

		private function get_sms_panel_api_domains() {
			return [
				'kavenegar.com',
				'api.kavenegar.com',
				'avanak.ir',
				'portal.avanak.ir',
				'sms.ir',
				'api.sms.ir',
				'ghasedak.me',
				'api.ghasedak.me',
				'gateway.ghasedak.me',
				'melipayamak.com',
				'api.melipayamak.com',
				'api.payamak-panel.com',
				'rest.payamak-panel.com',
				'ippanel.com',
				'api2.ippanel.com',
				'edge.ippanel.com',
				'limosms.com',
				'api.limosms.com',
				'farazsms.com',
				'api.farazsms.com',
				'api.iranpayamak.com',
				'api.sabanovin.com',
				'api.sms-webservice.com',
				'smspanel.trez.ir',
				'magfa.com',
				'sms.magfa.com',
				'payamresan.com',
				'api.payamresan.com',
			];
		}

		private function get_shipping_api_domains() {
			return [
				'post.ir',
				'api.post.ir',
				'ecommerce.post.ir',
				'tipaxco.com',
				'api.tipaxco.com',
				'chaparnet.com',
				'api.chaparnet.com',
				'postex.ir',
				'api.postex.ir',
				'mahex.com',
				'api.mahex.com',
				'alopeyk.com',
				'api.alopeyk.com',
				'miare.co',
				'api.miare.co',
			];
		}

		private function get_iranian_cdn_infra_domains() {
			return [
				'cdn.arvancloud.ir',
				'static.arvancloud.ir',
				'arvancloud.ir',
				'arvancloud.com',
				'cdn.arvancloud.com',
				'parspack.com',
				'cdn.parspack.com',
				'abr.ir',
			];
		}

		private function get_iranian_map_domains() {
			return [
				'neshan.org',
				'api.neshan.org',
				'cedarmaps.com',
				'api.cedarmaps.com',
				'map.ir',
				'balad.ir',
				'api.balad.ir',
			];
		}

		private function get_iranian_ads_domains() {
			return [
				'yektanet.com',
				'cdn.yektanet.com',
				'tapsell.ir',
				'adivery.ir',
				'mediaad.org',
			];
		}

		private function get_iranian_hosting_domains() {
			return [
				'mizbanfa.com',
				'hostiran.net',
				'hostiran.com',
				'netafraz.com',
				'limoo.host',
				'iran.liara.run',
				'liara.run',
				'darkube.app',
				'hamravesh.com',
				'fandogh.cloud',
				'sotoon.ir',
			];
		}

		private function get_iranian_popular_sites_domains() {
			return [
				'digikala.com',
				'api.digikala.com',
				'divar.ir',
				'api.divar.ir',
				'torob.com',
				'api.torob.com',
				'snapp.ir',
				'tapsi.ir',
				'aparat.com',
				'filimo.com',
				'namava.ir',
				'telewebion.com',
				'rubika.ir',
				'bale.ai',
				'eitaa.com',
				'gap.im',
				'igap.net',
				'soroush-app.ir',
			];
		}

		private function get_iranian_gov_trust_domains() {
			return [
				'nic.ir',
				'irnic.ir',
				'enamad.ir',
				'samandehi.ir',
			];
		}

		private function get_iranian_misc_domains() {
			return [
				'chmail.ir',
				'parsmail.com',
				'v1.fontapi.ir',
				'fonts.irfonts.ir',
				'cdn.fontiran.com',
				'fontiran.com',
				'cafebazaar.ir',
				'myket.ir',
				'charkhoneh.com',
				'sibche.com',
				'virgool.io',
				'zoomit.ir',
			];
		}

		private function get_settings() {
			$saved = get_option( self::OPTION_KEY, [] );

			$defaults = [
				'mode'               => 'disabled',
				'whitelist'          => 'api.shokrino.com',
				'blacklist'          => '',
				'disable_telemetry'  => 'yes',
				'disable_updates'    => 'yes',
				'disable_gravatar'   => 'yes',
				'gravatar_url'       => plugin_dir_url(__FILE__) . 'user.jpg',
				'enable_logging'     => 'no',
				'log_retention_days' => '7',
				'local_asset_rewrite'=> 'yes',
				'block_mixpanel'     => 'yes',
				'strict_asset_block' => 'yes',
				'enable_timeout_guard'=> 'no',
				'max_request_timeout'=> '3',
			];

			return wp_parse_args( $saved, $defaults );
		}

		public function maybe_replace_script_src( $src, $handle ) {
			$settings = $this->get_settings();
			if ( 'yes' !== $settings['local_asset_rewrite'] ) {
				return $src;
			}

			$host = (string) wp_parse_url( $src, PHP_URL_HOST );
			$path = (string) wp_parse_url( $src, PHP_URL_PATH );

			if ( '' === $host || '' === $path ) {
				return $src;
			}

			$host = strtolower( $host );

			if ( 'yes' === $settings['block_mixpanel'] && $this->is_mixpanel_host( $host ) ) {
				$this->log_request( $src, $host, 'mixpanel_rewritten_to_local_stub' );
				return plugin_dir_url( __FILE__ ) . 'assets/js/mixpanel-stub.js';
			}

			$replacement = $this->map_script_path_to_local( $path, $src );
			if ( $replacement ) {
				$this->log_request( $src, $host, 'cdn_rewritten_to_local' );
				return $replacement;
			}

			if ( $this->should_block_external_assets( $host, $settings ) ) {
				$this->log_request( $src, $host, 'blocked_external_script_no_local' );
				return plugin_dir_url( __FILE__ ) . 'assets/js/blocked-asset.js';
			}

			return $src;
		}

		public function maybe_replace_style_src( $src, $handle ) {
			$settings = $this->get_settings();
			if ( 'yes' !== $settings['local_asset_rewrite'] ) {
				return $src;
			}

			$host = (string) wp_parse_url( $src, PHP_URL_HOST );
			$path = (string) wp_parse_url( $src, PHP_URL_PATH );

			if ( '' === $host || '' === $path ) {
				return $src;
			}

			$host = strtolower( $host );
			$path = strtolower( $path );

			$replacement = $this->map_style_path_to_local( $path, $src, $host );
			if ( $replacement ) {
				return $replacement;
			}

			if ( $this->should_block_external_assets( $host, $settings ) ) {
				$this->log_request( $src, $host, 'blocked_external_style_no_local' );
				return plugin_dir_url( __FILE__ ) . 'assets/css/blocked-asset.css';
			}

			return $src;
		}

		private function map_style_path_to_local( $path, $original_src, $host ) {
			$fontawesome_base_url = plugin_dir_url( __FILE__ ) . 'assets/vendor/fontawesome/css/';
			$fontawesome_base_dir = plugin_dir_path( __FILE__ ) . 'assets/vendor/fontawesome/css/';

			if ( strpos( $path, 'fontawesome-free' ) !== false && strpos( $path, '/all.min.css' ) !== false && file_exists( $fontawesome_base_dir . 'all.min.css' ) ) {
				$this->log_request( $original_src, $host, 'fontawesome_all_rewritten_to_local' );
				return $fontawesome_base_url . 'all.min.css';
			}

			if ( strpos( $path, 'fontawesome-free' ) !== false && strpos( $path, '/v4-shims.min.css' ) !== false && file_exists( $fontawesome_base_dir . 'v4-shims.min.css' ) ) {
				$this->log_request( $original_src, $host, 'fontawesome_shims_rewritten_to_local' );
				return $fontawesome_base_url . 'v4-shims.min.css';
			}

			if ( 'fonts.googleapis.com' === $host && strpos( $path, '/css2' ) !== false ) {
				$roboto_css = plugin_dir_path( __FILE__ ) . 'assets/vendor/google-fonts/roboto.css';
				if ( file_exists( $roboto_css ) ) {
					$this->log_request( $original_src, $host, 'google_fonts_rewritten_to_local' );
					return plugin_dir_url( __FILE__ ) . 'assets/vendor/google-fonts/roboto.css';
				}
			}

			$swiper_css_path = plugin_dir_path( __FILE__ ) . 'assets/vendor/swiper/swiper-bundle.min.css';
			if ( preg_match( '#/swiper(-bundle)?(\\.min)?\\.css$#', $path ) && file_exists( $swiper_css_path ) ) {
				$this->log_request( $original_src, $host, 'swiper_rewritten_to_local' );
				return plugin_dir_url( __FILE__ ) . 'assets/vendor/swiper/swiper-bundle.min.css';
			}

			if ( strpos( $path, 'dashicons' ) !== false ) {
				$this->log_request( $original_src, $host, 'dashicons_rewritten_to_local' );
				return plugin_dir_url( __FILE__ ) . 'assets/vendor/dashicons/css/dashicons.min.css';
			}

			if ( strpos( $path, 'eicons' ) !== false || strpos( $path, 'elementor-icons' ) !== false ) {
				$this->log_request( $original_src, $host, 'eicons_rewritten_to_local' );
				return plugin_dir_url( __FILE__ ) . 'assets/vendor/eicons/css/elementor-icons.min.css';
			}

			return '';
		}

		private function should_block_external_assets( $host, $settings ) {
			if ( 'yes' !== $settings['strict_asset_block'] ) {
				return false;
			}

			if ( ! $this->is_known_cdn_host( $host ) && ! $this->is_mixpanel_host( $host ) ) {
				return false;
			}

			if ( isset( $settings['mode'] ) && 'whitelist' === $settings['mode'] ) {
				$whitelist = array_map( 'trim', explode( "\n", (string) ( $settings['whitelist'] ?? '' ) ) );
				if ( $this->host_in_list( $host, $whitelist ) ) {
					return false;
				}
			}

			$site_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
			$site_host = strtolower( $site_host );

			if ( '' === $site_host ) {
				return $this->is_known_cdn_host( $host );
			}

			return $host !== $site_host;
		}

		private function is_known_cdn_host( $host ) {
			$cdn_hosts = [
				'ajax.googleapis.com',
				'cdnjs.cloudflare.com',
				'cdn.jsdelivr.net',
				'unpkg.com',
				'code.jquery.com',
				'fonts.googleapis.com',
				'fonts.gstatic.com',
				'use.fontawesome.com',
				'kit.fontawesome.com',
				'maxcdn.bootstrapcdn.com',
			];

			return in_array( $host, $cdn_hosts, true );
		}

		private function map_script_path_to_local( $path, $original_src ) {
			$path = strtolower( $path );
			$plugin_url = plugin_dir_url( __FILE__ ) . 'assets/vendor/wp-core-js/';
			$swiper_path = plugin_dir_path( __FILE__ ) . 'assets/vendor/swiper/swiper-bundle.min.js';
			$ace_base_dir = plugin_dir_path( __FILE__ ) . 'assets/vendor/ace-builds/src-min-noconflict/';
			$ace_base_url = plugin_dir_url( __FILE__ ) . 'assets/vendor/ace-builds/src-min-noconflict/';

			if ( preg_match( '#/ace(-min)?\\.js$#', $path ) && file_exists( $ace_base_dir . 'ace.min.js' ) ) {
				return $ace_base_url . 'ace.min.js';
			}

			if ( preg_match( '#/ext-language_tools\\.js$#', $path ) && file_exists( $ace_base_dir . 'ext-language_tools.js' ) ) {
				return $ace_base_url . 'ext-language_tools.js';
			}

			if ( preg_match( '#/swiper(-bundle)?(\\.min)?\\.js$#', $path ) && file_exists( $swiper_path ) ) {
				return plugin_dir_url( __FILE__ ) . 'assets/vendor/swiper/swiper-bundle.min.js';
			}

			if ( preg_match( '#/jquery(\\.min)?\\.js$#', $path ) ) {
				return $plugin_url . 'jquery.min.js';
			}

			if ( preg_match( '#/jquery-migrate(\\.min)?\\.js$#', $path ) ) {
				return $plugin_url . 'jquery-migrate.min.js';
			}

			if ( preg_match( '#/underscore(-min)?\\.js$#', $path ) ) {
				return $plugin_url . 'underscore.min.js';
			}

			if ( preg_match( '#/backbone(-min)?\\.js$#', $path ) ) {
				return $plugin_url . 'backbone.min.js';
			}

			if ( preg_match( '#/react(\\.production\\.min|\\.min)?\\.js$#', $path ) ) {
				return $plugin_url . 'react.min.js';
			}

			if ( preg_match( '#/react-dom(\\.production\\.min|\\.min)?\\.js$#', $path ) ) {
				return $plugin_url . 'react-dom.min.js';
			}

			return apply_filters( 'ghateino_local_script_rewrite', '', $path, $original_src );
		}

		private function is_mixpanel_host( $host ) {
			$mixpanel_hosts = [
				'api-eu.mixpanel.com',
				'api.mixpanel.com',
				'cdn.mxpnl.com',
				'api-js.mixpanel.com',
			];

			return in_array( $host, $mixpanel_hosts, true );
		}

		private function prepare_local_assets() {
			$this->ensure_core_js_assets();
			$this->ensure_ace_assets();
			$this->ensure_dashicons_assets();
			$this->ensure_eicons_assets();
			$this->ensure_swiper_assets();
			$this->ensure_fontawesome_assets();
			$this->ensure_google_fonts_assets();
			$this->ensure_block_fallback_assets();
		}

		private function ensure_ace_assets() {
			$plugin_base = plugin_dir_path( __FILE__ );
			$this->ensure_dir( $plugin_base . 'assets/vendor/ace-builds/src-min-noconflict/' );
		}

		private function ensure_block_fallback_assets() {
			$plugin_base = plugin_dir_path( __FILE__ );
			$this->ensure_dir( $plugin_base . 'assets/js/' );
			$this->ensure_dir( $plugin_base . 'assets/css/' );

			$blocked_js = $plugin_base . 'assets/js/blocked-asset.js';
			if ( ! file_exists( $blocked_js ) ) {
				file_put_contents( $blocked_js, "/* blocked external script by Ghateino */\n" );
			}

			$blocked_css = $plugin_base . 'assets/css/blocked-asset.css';
			if ( ! file_exists( $blocked_css ) ) {
				file_put_contents( $blocked_css, "/* blocked external style by Ghateino */\n" );
			}
		}

		private function ensure_fontawesome_assets() {
			$plugin_base = plugin_dir_path( __FILE__ );
			$target_css_dir = $plugin_base . 'assets/vendor/fontawesome/css/';
			$target_webfonts_dir = $plugin_base . 'assets/vendor/fontawesome/webfonts/';

			$this->ensure_dir( $target_css_dir );
			$this->ensure_dir( $target_webfonts_dir );
		}

		private function ensure_google_fonts_assets() {
			$plugin_base = plugin_dir_path( __FILE__ );
			$this->ensure_dir( $plugin_base . 'assets/vendor/google-fonts/' );
		}

		private function ensure_swiper_assets() {
			$plugin_base = plugin_dir_path( __FILE__ );
			$target_dir  = $plugin_base . 'assets/vendor/swiper/';
			$target_js   = $target_dir . 'swiper-bundle.min.js';
			$target_css  = $target_dir . 'swiper-bundle.min.css';

			if ( file_exists( $target_js ) && file_exists( $target_css ) ) {
				return;
			}

			$this->ensure_dir( $target_dir );

			$source_js = $this->find_first_existing_file(
				[
					WP_CONTENT_DIR . '/plugins/elementor/assets/lib/swiper/v8/swiper.min.js',
					WP_CONTENT_DIR . '/plugins/elementor/assets/lib/swiper/swiper.min.js',
					ABSPATH . 'wp-includes/js/dist/vendor/swiper/swiper-bundle.min.js',
				],
				'swiper*.min.js'
			);

			$source_css = $this->find_first_existing_file(
				[
					WP_CONTENT_DIR . '/plugins/elementor/assets/lib/swiper/v8/css/swiper.min.css',
					WP_CONTENT_DIR . '/plugins/elementor/assets/lib/swiper/swiper.min.css',
					ABSPATH . 'wp-includes/css/dist/vendor/swiper/swiper-bundle.min.css',
				],
				'swiper*.min.css'
			);

			if ( $source_js && ! file_exists( $target_js ) ) {
				@copy( $source_js, $target_js );
			}

			if ( $source_css && ! file_exists( $target_css ) ) {
				@copy( $source_css, $target_css );
			}
		}

		private function find_first_existing_file( $candidates, $file_pattern ) {
			foreach ( $candidates as $candidate ) {
				if ( is_file( $candidate ) ) {
					return $candidate;
				}
			}

			if ( ! is_dir( WP_CONTENT_DIR ) ) {
				return '';
			}

			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( WP_CONTENT_DIR, FilesystemIterator::SKIP_DOTS )
			);

			foreach ( $iterator as $file_info ) {
				if ( ! $file_info->isFile() ) {
					continue;
				}

				if ( fnmatch( $file_pattern, $file_info->getFilename() ) ) {
					return $file_info->getPathname();
				}
			}

			return '';
		}

		private function ensure_core_js_assets() {
			$plugin_base = plugin_dir_path( __FILE__ );
			$target_base = $plugin_base . 'assets/vendor/wp-core-js/';

			$asset_map = [
				'jquery.min.js'         => ABSPATH . 'wp-includes/js/jquery/jquery.min.js',
				'jquery-migrate.min.js' => ABSPATH . 'wp-includes/js/jquery/jquery-migrate.min.js',
				'underscore.min.js'     => ABSPATH . 'wp-includes/js/underscore.min.js',
				'backbone.min.js'       => ABSPATH . 'wp-includes/js/backbone.min.js',
				'react.min.js'          => ABSPATH . 'wp-includes/js/dist/vendor/react.min.js',
				'react-dom.min.js'      => ABSPATH . 'wp-includes/js/dist/vendor/react-dom.min.js',
			];

			$this->ensure_dir( $target_base );

			foreach ( $asset_map as $target_name => $source_path ) {
				$target_path = $target_base . $target_name;
				if ( file_exists( $target_path ) ) {
					continue;
				}

				if ( file_exists( $source_path ) ) {
					@copy( $source_path, $target_path );
				}
			}
		}

		private function ensure_dashicons_assets() {
			$plugin_base = plugin_dir_path( __FILE__ );
			$target_css  = $plugin_base . 'assets/vendor/dashicons/css/dashicons.min.css';
			$target_font = $plugin_base . 'assets/vendor/dashicons/fonts/';

			if ( file_exists( $target_css ) && file_exists( $target_font . 'dashicons.woff2' ) ) {
				return;
			}

			$source_css = ABSPATH . 'wp-includes/css/dashicons.min.css';
			$source_dir = ABSPATH . 'wp-includes/fonts/';

			if ( ! file_exists( $source_css ) || ! is_dir( $source_dir ) ) {
				return;
			}

			$this->ensure_dir( dirname( $target_css ) );
			$this->ensure_dir( $target_font );

			@copy( $source_css, $target_css );

			$font_files = [ 'dashicons.eot', 'dashicons.svg', 'dashicons.ttf', 'dashicons.woff', 'dashicons.woff2' ];
			foreach ( $font_files as $font_file ) {
				$source_file = $source_dir . $font_file;
				if ( file_exists( $source_file ) ) {
					@copy( $source_file, $target_font . $font_file );
				}
			}
		}

		private function ensure_eicons_assets() {
			$plugin_base = plugin_dir_path( __FILE__ );
			$target_css  = $plugin_base . 'assets/vendor/eicons/css/elementor-icons.min.css';
			$target_font = $plugin_base . 'assets/vendor/eicons/fonts/';

			if ( file_exists( $target_css ) ) {
				return;
			}

			$source_base = WP_CONTENT_DIR . '/plugins/elementor/assets/lib/eicons/';
			$source_css  = $source_base . 'css/elementor-icons.min.css';
			$source_font = $source_base . 'fonts/';

			$this->ensure_dir( dirname( $target_css ) );
			$this->ensure_dir( $target_font );

			if ( file_exists( $source_css ) ) {
				@copy( $source_css, $target_css );
			}

			if ( is_dir( $source_font ) ) {
				$font_files = glob( $source_font . '*' );
				if ( is_array( $font_files ) ) {
					foreach ( $font_files as $font_file ) {
						if ( is_file( $font_file ) ) {
							@copy( $font_file, $target_font . basename( $font_file ) );
						}
					}
				}
			}

			if ( ! file_exists( $target_css ) ) {
				$fallback_css = "/* Elementor eicons fallback placeholder */\n";
				$fallback_css .= "@font-face{font-family:eicons;src:local('Arial');}\n";
				$fallback_css .= "[class^=eicon-],[class*=\" eicon-\"]{font-family:eicons!important;}\n";
				file_put_contents( $target_css, $fallback_css );
			}
		}

		private function ensure_dir( $dir ) {
			if ( ! is_dir( $dir ) ) {
				wp_mkdir_p( $dir );
			}
		}

		public function render_settings_page() {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			$settings = $this->get_settings();
			$logs     = get_option( self::LOG_KEY, [] );
			$clear_url = wp_nonce_url( admin_url( 'options-general.php?page=ghateino&clear_logs=1' ), 'ghateino_clear_logs' );
			$quick_presets = $this->get_quick_whitelist_presets();

			?>
			<div class="wrap">
				<h1>تنظیمات افزونه قطعینو</h1>
				<style>
					.ghateino-card {
						background: #ffffff;
						border-radius: 14px;
						padding: 18px 20px;
						box-shadow: 0 6px 20px rgba(16, 24, 40, 0.06);
						margin-bottom: 18px;
						max-width: 980px;
					}

					.ghateino-guide {
						background: #0096ff;
						color: #fff;
					}

					.ghateino-form-table th {
						width: 260px;
					}

					.ghateino-settings-form input[type="text"],
					.ghateino-settings-form input[type="number"],
					.ghateino-settings-form textarea,
					.ghateino-settings-form select {
						border-radius: 10px;
						border-color: #cfd6de;
					}

					.ghateino-section-title {
						font-size: 16px;
						padding-bottom: 10px;
						margin: 0 0 10px;
						border-bottom: 1px solid #e7ecf2;
					}
				</style>

				<div class="ghateino-card ghateino-guide" style="margin-top:14px;">
					<p style="margin:0 0 8px;"><strong>راهنمای سریع کار با افزونه:</strong></p>
					<p style="margin:0 0 6px;">1) اگر می خواهید با خیال راحت شروع کنید، حالت «غیرفعال» مناسب است و سایت بدون محدودیت روی هیچ دامنه ای کار می کند.</p>
					<p style="margin:0 0 6px;">2) اگر اینترنت بین الملل ناپایدار است، حالت «لیست سفید» کمک می کند فقط سرویس های ضروری سایت شما فعال بمانند. آنهارا بسته به نیاز به لیست اضافه کنید.</p>
					<p style="margin:0 0 6px;">3) برای بهتر شدن سرعت در زمان اختلال، «محدودسازی Timeout» را روشن کنید و مقدار 3 ثانیه را امتحان کنید.</p>
					<p style="margin:0;">4) اگر سرویس خاصی مثل پرداخت یا پیامک دیر پاسخ می دهد، مقدار Timeout را کمی بیشتر کنید (مثلا 5 ثانیه).</p>
				</div>

				<div class="ghateino-card" style="display:flex; flex-wrap:wrap; gap:10px; align-items:center;">
					<a class="button button-secondary" href="https://ble.ir/shokrino" target="_blank" rel="noopener noreferrer">کانال پیامرسان بله توسعه دهنده</a>
					<span class="description">برای راهنماها و اطلاعیه های جدید در زمانی که صرفا پیامرسان های داخلی دردسترس هست کانال ما را دنبال کنید.</span>
				</div>

				<?php if ( isset( $_GET['logs_cleared'] ) ) : ?>
					<div class="notice notice-success is-dismissible"><p>لاگ‌ها با موفقیت پاک شدند.</p></div>
				<?php endif; ?>

				<form method="post" action="options.php" class="ghateino-card ghateino-settings-form">
					<?php settings_fields( 'ghateino_group' ); ?>
					
					<h2 class="ghateino-section-title">تنظیمات اصلی فایروال</h2>
					<table class="form-table ghateino-form-table">
						<tr valign="top">
							<th scope="row">حالت کاری فایروال:</th>
							<td>
								<select name="<?php echo esc_attr( self::OPTION_KEY ); ?>[mode]" style="width: 300px;">
									<option value="disabled" <?php selected( $settings['mode'], 'disabled' ); ?>>غیرفعال (بدون محدودیت)</option>
									<option value="whitelist" <?php selected( $settings['mode'], 'whitelist' ); ?>>مسدودسازی همه (فقط مجازها از لیست سفید)</option>
									<option value="blacklist" <?php selected( $settings['mode'], 'blacklist' ); ?>>آزادسازی همه (فقط مسدودها از لیست سیاه)</option>
								</select>
							</td>
						</tr>
						<tr valign="top">
							<th scope="row">لیست سفید (Whitelist):<br><small>هر دامنه در یک خط (مثال: api.shokrino.com)</small></th>
							<td>
								<textarea id="ghateino-whitelist-textarea" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[whitelist]" rows="5" style="width: 400px;"><?php echo esc_textarea( $settings['whitelist'] ); ?></textarea>
								<div style="margin-top: 10px;">
									<p class="description" style="margin-bottom: 8px;"><strong>افزودن سریع سایت های رایج به لیست سفید:</strong></p>
									<div style="display: flex; flex-wrap: wrap; gap: 8px;">
										<?php foreach ( $quick_presets as $preset ) : ?>
											<button
												type="button"
												class="button button-secondary ghateino-quick-whitelist"
												data-domains="<?php echo esc_attr( implode( ',', $preset['domains'] ) ); ?>"
											>
												<?php echo esc_html( $preset['label'] ); ?>
											</button>
										<?php endforeach; ?>
									</div>
									<p class="description" style="margin-top: 8px;">با کلیک روی هر دکمه، دامنه های همان دسته به لیست سفید اضافه می شود (بدون ثبت تکراری).</p>
								</div>
							</td>
						</tr>
						<tr valign="top">
							<th scope="row">لیست سیاه (Blacklist):<br><small>هر دامنه در یک خط</small></th>
							<td>
								<textarea name="<?php echo esc_attr( self::OPTION_KEY ); ?>[blacklist]" rows="5" style="width: 400px;"><?php echo esc_textarea( $settings['blacklist'] ); ?></textarea>
							</td>
						</tr>
					</table>

					<h2 class="ghateino-section-title" style="margin-top: 20px;">تنظیمات امکانات جانبی</h2>
					<table class="form-table ghateino-form-table">
						<tr valign="top">
							<th scope="row">تلمتری وردپرس:</th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[disable_telemetry]" value="yes" <?php checked( $settings['disable_telemetry'], 'yes' ); ?> />
									جلوگیری از ارسال اطلاعات به سرورهای وردپرس (wordpress.org)
								</label>
							</td>
						</tr>
						<tr valign="top">
							<th scope="row">بروزرسانی‌های خودکار:</th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[disable_updates]" value="yes" <?php checked( $settings['disable_updates'], 'yes' ); ?> />
									غیرفعال کردن جستجو برای آپدیت هسته، قالب‌ها و افزونه‌ها
								</label>
							</td>
						</tr>
						<tr valign="top">
							<th scope="row">سرویس گراواتار:</th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[disable_gravatar]" value="yes" <?php checked( $settings['disable_gravatar'], 'yes' ); ?> />
									مسدودسازی گراواتار و جایگزینی با عکس محلی
								</label>
							</td>
						</tr>
						<tr valign="top">
							<th scope="row">جایگزینی CDN با فایل محلی:</th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[local_asset_rewrite]" value="yes" <?php checked( $settings['local_asset_rewrite'], 'yes' ); ?> />
									تلاش برای جایگزینی CDNهای رایج JS با نسخه محلی وردپرس (jQuery, React, Backbone, Underscore)
								</label>
							</td>
						</tr>
						<tr valign="top">
							<th scope="row">مسدودسازی Mixpanel:</th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[block_mixpanel]" value="yes" <?php checked( $settings['block_mixpanel'], 'yes' ); ?> />
									مسدودسازی درخواست‌های Mixpanel (از جمله `api-eu.mixpanel.com`) و جایگزینی اسکریپت با نسخه خنثی محلی
								</label>
							</td>
						</tr>
						<tr valign="top">
							<th scope="row">بلاک سختگیرانه Asset خارجی:</th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[strict_asset_block]" value="yes" <?php checked( $settings['strict_asset_block'], 'yes' ); ?> />
									اولویت مطلق با لوکال: اگر CSS/JS خارجی قابل جایگزینی نبود، سریع بلاک شود تا کندی ایجاد نشود
								</label>
							</td>
						</tr>
						<tr valign="top">
							<th scope="row">محدودسازی Timeout درخواست‌های خارجی:</th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[enable_timeout_guard]" value="yes" <?php checked( $settings['enable_timeout_guard'], 'yes' ); ?> />
									فعال‌سازی سقف زمان برای درخواست‌های HTTP خارجی
								</label>
								<p class="description" style="margin-top:6px;">اگر timeout درخواست بیشتر از مقدار زیر باشد، کاهش داده می‌شود تا درخواست‌های کند سریع‌تر fail شوند.</p>
							</td>
						</tr>
						<tr valign="top">
							<th scope="row">حداکثر Timeout (ثانیه):</th>
							<td>
								<input type="number" min="1" max="30" step="1" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[max_request_timeout]" value="<?php echo esc_attr( $settings['max_request_timeout'] ); ?>" style="width:100px;" />
								<p class="description">پیشنهاد: ۳ ثانیه. پیش‌فرض افزونه روی ۳ است ولی این قابلیت برای حفظ سازگاری نسخه‌های قبلی به‌صورت پیش‌فرض غیرفعال است.</p>
							</td>
						</tr>
						<tr valign="top">
							<th scope="row">لینک جایگزین گراواتار:</th>
							<td>
								<input type="text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[gravatar_url]" value="<?php echo esc_url( $settings['gravatar_url'] ); ?>" style="width: 400px;" />
								<p class="description">اگر گراواتار مسدود شود، این تصویر جایگزین پروفایل کاربران خواهد شد.</p>
							</td>
						</tr>
					</table>

					<h2 class="ghateino-section-title" style="margin-top: 20px;">تنظیمات لاگ (گزارش‌گیری)</h2>
					<table class="form-table ghateino-form-table">
						<tr valign="top">
							<th scope="row">ثبت لاگ درخواست‌ها:</th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[enable_logging]" value="yes" <?php checked( $settings['enable_logging'], 'yes' ); ?> />
									فعال‌سازی ثبت درخواست‌های مسدود شده (فقط در زمان نیاز به عیب‌یابی فعال کنید)
								</label>
							</td>
						</tr>
						<tr valign="top">
							<th scope="row">نگهداری لاگ‌ها:</th>
							<td>
								<select name="<?php echo esc_attr( self::OPTION_KEY ); ?>[log_retention_days]">
									<option value="1" <?php selected( $settings['log_retention_days'], '1' ); ?>>۱ روز</option>
									<option value="3" <?php selected( $settings['log_retention_days'], '3' ); ?>>۳ روز</option>
									<option value="7" <?php selected( $settings['log_retention_days'], '7' ); ?>>۷ روز</option>
									<option value="15" <?php selected( $settings['log_retention_days'], '15' ); ?>>۱۵ روز</option>
									<option value="30" <?php selected( $settings['log_retention_days'], '30' ); ?>>۳۰ روز</option>
								</select>
								<p class="description">لاگ‌های قدیمی‌تر از این زمان به‌صورت خودکار حذف می‌شوند.</p>
							</td>
						</tr>
					</table>
					
					<?php submit_button( 'ذخیره تمامی تنظیمات' ); ?>
				</form>

				<script>
					document.addEventListener('DOMContentLoaded', function () {
						var textarea = document.getElementById('ghateino-whitelist-textarea');
						if (!textarea) {
							return;
						}

						var quickButtons = document.querySelectorAll('.ghateino-quick-whitelist');
						quickButtons.forEach(function (button) {
							button.addEventListener('click', function () {
								var currentLines = textarea.value
									.split(/\r?\n/)
									.map(function (line) { return line.trim().toLowerCase(); })
									.filter(Boolean);

								var map = {};
								currentLines.forEach(function (domain) {
									map[domain] = true;
								});

								var domains = (button.getAttribute('data-domains') || '')
									.split(',')
									.map(function (domain) { return domain.trim().toLowerCase(); })
									.filter(Boolean);

								domains.forEach(function (domain) {
									if (!map[domain]) {
										currentLines.push(domain);
										map[domain] = true;
									}
								});

								textarea.value = currentLines.join('\n');
								textarea.dispatchEvent(new Event('change'));
							});
						});
					});
				</script>

				<details class="ghateino-card">
					<summary style="font-size: 1.2em; font-weight: bold; cursor: pointer; display: flex; align-items: center; justify-content: space-between;">
						<span>مشاهده لاگ درخواست‌های مسدود شده (کلیک کنید)</span>
					</summary>
					
					<div style="margin-top: 20px;">
						<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
							<p class="description">لاگ‌ها به‌صورت خودکار محدود شده‌اند. برای جلوگیری از پر شدن دیتابیس می‌توانید آنها را پاک کنید.</p>
							<a href="<?php echo esc_url( $clear_url ); ?>" class="button button-secondary" onclick="return confirm('آیا از پاک کردن لاگ‌ها مطمئن هستید؟');">پاک کردن لاگ‌ها</a>
						</div>
						
						<table class="widefat striped">
							<thead>
								<tr>
									<th style="width: 15%;">زمان</th>
									<th style="width: 20%;">دلیل مسدودسازی</th>
									<th style="width: 20%;">میزبان (Host)</th>
									<th style="width: 45%;">آدرس (URL) کامل</th>
								</tr>
							</thead>
							<tbody>
								<?php if ( empty( $logs ) ) : ?>
									<tr>
										<td colspan="4" style="text-align: center;">هیچ درخواستی تا کنون مسدود نشده است.</td>
									</tr>
								<?php else : ?>
									<?php foreach ( array_reverse( $logs ) as $log ) : ?>
										<tr>
											<td><?php echo esc_html( $log['time'] ); ?></td>
											<td><code><?php echo esc_html( $log['mode'] ); ?></code></td>
											<td><strong><?php echo esc_html( $log['host'] ); ?></strong></td>
											<td style="word-break: break-all;"><code><?php echo esc_url( $log['url'] ); ?></code></td>
										</tr>
									<?php endforeach; ?>
								<?php endif; ?>
							</tbody>
						</table>
					</div>
				</details>
			</div>
			<?php
		}
	}

	new Ghateino_HTTP_Control();
}

// A plugin from Shokrino Team in shokrino.com