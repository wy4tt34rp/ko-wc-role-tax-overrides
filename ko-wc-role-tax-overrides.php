<?php
/**
 * Plugin Name: KO - Woo Role Tax Overrides
 * Description: Override WooCommerce tax rates for specific user roles, with admin tools and order audit visibility.
 * Version: 1.1.2
 * Author: KO
 * License: GPL2+
 * Text Domain: ko-wc-role-tax-overrides
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'KO_WC_Role_Tax_Overrides' ) ) {

	class KO_WC_Role_Tax_Overrides {

		const OPTION_KEY        = 'ko_wc_role_tax_overrides_settings';
		const ORDER_META_PREFIX = '_ko_role_tax_override_';
		const SESSION_NOTICE_FLAG = 'ko_role_tax_override_notice_shown';
		const LOG_SOURCE        = 'ko-role-tax-overrides';
		const ADMIN_SLUG        = 'ko-role-tax-overrides';

		protected $current_override = null;

		public function __construct() {
			add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
			add_action( 'admin_init', array( $this, 'register_settings' ) );
			add_action( 'admin_notices', array( $this, 'maybe_render_admin_active_badge_notice' ) );

			add_filter( 'woocommerce_customer_is_vat_exempt', array( $this, 'maybe_make_customer_tax_exempt' ), 9999, 1 );
			add_filter( 'woocommerce_matched_tax_rates', array( $this, 'override_matched_tax_rates' ), 9999, 3 );
			add_filter( 'woocommerce_shipping_tax_class', array( $this, 'maybe_override_shipping_tax_class' ), 9999, 1 );

			add_action( 'woocommerce_before_checkout_form', array( $this, 'maybe_output_checkout_notice' ), 5 );
			add_action( 'woocommerce_before_cart', array( $this, 'maybe_output_cart_notice' ), 5 );

			add_action( 'woocommerce_checkout_create_order', array( $this, 'capture_override_meta_on_order' ), 20, 2 );
			add_action( 'woocommerce_new_order', array( $this, 'capture_override_meta_on_admin_order' ), 20, 1 );

			add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_order_list_column_legacy' ), 20 );
			add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_order_list_column_legacy' ), 20, 2 );

			add_filter( 'manage_woocommerce_page_wc-orders_columns', array( $this, 'add_order_list_column_hpos' ), 20 );
			add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $this, 'render_order_list_column_hpos' ), 20, 2 );

			add_action( 'add_meta_boxes', array( $this, 'add_order_meta_box_legacy' ) );
			add_action( 'add_meta_boxes_woocommerce_page_wc-orders', array( $this, 'add_order_meta_box_hpos' ) );
		}

		public function add_admin_menu() {
			add_menu_page(
				__( 'Role Based Taxes', 'ko-wc-role-tax-overrides' ),
				__( 'Role Based Taxes', 'ko-wc-role-tax-overrides' ),
				'manage_woocommerce',
				self::ADMIN_SLUG,
				array( $this, 'render_settings_page' ),
				'dashicons-calculator',
				57
			);

			add_submenu_page(
				self::ADMIN_SLUG,
				__( 'Settings', 'ko-wc-role-tax-overrides' ),
				__( 'Settings', 'ko-wc-role-tax-overrides' ),
				'manage_woocommerce',
				self::ADMIN_SLUG,
				array( $this, 'render_settings_page' )
			);

			add_submenu_page(
				self::ADMIN_SLUG,
				__( 'Logs', 'ko-wc-role-tax-overrides' ),
				__( 'Logs', 'ko-wc-role-tax-overrides' ),
				'manage_woocommerce',
				self::ADMIN_SLUG . '-logs',
				array( $this, 'render_logs_page' )
			);

			add_submenu_page(
				self::ADMIN_SLUG,
				__( 'Debug', 'ko-wc-role-tax-overrides' ),
				__( 'Debug', 'ko-wc-role-tax-overrides' ),
				'manage_woocommerce',
				self::ADMIN_SLUG . '-debug',
				array( $this, 'render_debug_page' )
			);
		}

		public function register_settings() {
			register_setting(
				'ko_wc_role_tax_overrides_group',
				self::OPTION_KEY,
				array(
					'type'              => 'array',
					'sanitize_callback' => array( $this, 'sanitize_settings' ),
					'default'           => $this->get_default_settings(),
				)
			);
		}

		protected function get_default_settings() {
			return array(
				'apply_to_shipping'   => 1,
				'show_checkout_notice'=> 1,
				'show_cart_notice'    => 1,
				'notice_message'      => 'A role-based tax rule has been applied to your order.',
				'enable_logging'      => 0,
				'overrides'           => array(),
			);
		}

		public function get_settings() {
			$settings = get_option( self::OPTION_KEY, array() );
			if ( ! is_array( $settings ) ) {
				$settings = array();
			}
			return wp_parse_args( $settings, $this->get_default_settings() );
		}

		public function sanitize_settings( $input ) {
			$sanitized = $this->get_default_settings();

			$sanitized['apply_to_shipping']    = ! empty( $input['apply_to_shipping'] ) ? 1 : 0;
			$sanitized['show_checkout_notice'] = ! empty( $input['show_checkout_notice'] ) ? 1 : 0;
			$sanitized['show_cart_notice']     = ! empty( $input['show_cart_notice'] ) ? 1 : 0;
			$sanitized['notice_message']       = isset( $input['notice_message'] ) ? sanitize_text_field( $input['notice_message'] ) : 'A role-based tax rule has been applied to your order.';
			if ( '' === $sanitized['notice_message'] ) {
				$sanitized['notice_message'] = 'A role-based tax rule has been applied to your order.';
			}
			$sanitized['enable_logging']       = ! empty( $input['enable_logging'] ) ? 1 : 0;
			$sanitized['overrides']            = array();

			if ( ! empty( $input['overrides'] ) && is_array( $input['overrides'] ) ) {
				foreach ( $input['overrides'] as $row ) {
					if ( empty( $row['role'] ) ) {
						continue;
					}

					$role     = sanitize_key( $row['role'] );
					$rate     = isset( $row['rate'] ) ? wc_format_decimal( $row['rate'] ) : '';
					$priority = isset( $row['priority'] ) ? absint( $row['priority'] ) : 10;

					if ( '' === $rate ) {
						continue;
					}

					$sanitized['overrides'][] = array(
						'role'     => $role,
						'rate'     => max( 0, (float) $rate ),
						'priority' => max( 1, $priority ),
					);
				}
			}

			usort(
				$sanitized['overrides'],
				function( $a, $b ) {
					if ( (int) $a['priority'] === (int) $b['priority'] ) {
						return strcmp( (string) $a['role'], (string) $b['role'] );
					}
					return ( (int) $a['priority'] < (int) $b['priority'] ) ? -1 : 1;
				}
			);

			return $sanitized;
		}

		protected function log( $message, $context = array() ) {
			$settings = $this->get_settings();

			if ( empty( $settings['enable_logging'] ) || ! function_exists( 'wc_get_logger' ) ) {
				return;
			}

			$logger = wc_get_logger();
			$line = $message;

			if ( ! empty( $context ) ) {
				$line .= ' | ' . wp_json_encode( $context );
			}

			$logger->info( $line, array( 'source' => self::LOG_SOURCE ) );
		}

		protected function get_editable_role_labels() {
			$roles = get_editable_roles();
			$labels = array();

			foreach ( $roles as $key => $data ) {
				$labels[ $key ] = isset( $data['name'] ) ? $data['name'] : $key;
			}

			return $labels;
		}

		public function get_current_user_override() {
			if ( null !== $this->current_override ) {
				return $this->current_override;
			}

			$this->current_override = null;

			if ( ! is_user_logged_in() ) {
				return null;
			}

			$user = wp_get_current_user();

			if ( empty( $user->roles ) || ! is_array( $user->roles ) ) {
				return null;
			}

			$settings  = $this->get_settings();
			$overrides = isset( $settings['overrides'] ) && is_array( $settings['overrides'] ) ? $settings['overrides'] : array();

			foreach ( $overrides as $override ) {
				if ( empty( $override['role'] ) || ! isset( $override['rate'] ) ) {
					continue;
				}

				if ( in_array( $override['role'], $user->roles, true ) ) {
					$this->current_override = array(
						'role'     => $override['role'],
						'rate'     => (float) $override['rate'],
						'priority' => isset( $override['priority'] ) ? (int) $override['priority'] : 10,
						'user_id'  => (int) $user->ID,
					);
					return $this->current_override;
				}
			}

			return null;
		}

		public function maybe_render_admin_active_badge_notice() {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				return;
			}

			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			if ( empty( $screen->id ) ) {
				return;
			}

			$allowed = array(
				'toplevel_page_' . self::ADMIN_SLUG,
				'role-based-taxes_page_' . self::ADMIN_SLUG . '-logs',
				'role-based-taxes_page_' . self::ADMIN_SLUG . '-debug',
				'woocommerce_page_wc-orders',
				'edit-shop_order',
				'shop_order',
			);

			if ( ! in_array( $screen->id, $allowed, true ) ) {
				return;
			}

			$count = count( $this->get_settings()['overrides'] );

			if ( $count < 1 ) {
				return;
			}

			echo '<div class="notice notice-info is-dismissible"><p><strong>' .
				esc_html__( 'Role Based Taxes active:', 'ko-wc-role-tax-overrides' ) .
				'</strong> ' .
				sprintf(
					esc_html__( '%d override rule(s) currently configured.', 'ko-wc-role-tax-overrides' ),
					(int) $count
				) .
				'</p></div>';
		}

		public function maybe_make_customer_tax_exempt( $is_vat_exempt ) {
			$override = $this->get_current_user_override();

			if ( empty( $override ) ) {
				return $is_vat_exempt;
			}

			return ( (float) $override['rate'] <= 0 ) ? true : false;
		}

		public function override_matched_tax_rates( $matched_tax_rates, $tax_class, $customer ) {
			$override = $this->get_current_user_override();

			if ( empty( $override ) ) {
				return $matched_tax_rates;
			}

			if ( (float) $override['rate'] <= 0 ) {
				return array();
			}

			$this->log(
				'Applied tax override to matched tax rates.',
				array(
					'role'      => $override['role'],
					'rate'      => $override['rate'],
					'priority'  => $override['priority'],
					'user_id'   => $override['user_id'],
					'tax_class' => $tax_class,
				)
			);

			return array(
				999999 => array(
					'tax_rate_id'       => 999999,
					'tax_rate_country'  => '',
					'tax_rate_state'    => '',
					'tax_rate'          => (string) $override['rate'],
					'tax_rate_name'     => 'Role Tax Override',
					'tax_rate_priority' => 1,
					'tax_rate_compound' => 0,
					'tax_rate_shipping' => 1,
					'tax_rate_order'    => 1,
					'tax_rate_class'    => $tax_class,
					'tax_rate_postcode' => '',
					'tax_rate_city'     => '',
				),
			);
		}

		public function maybe_override_shipping_tax_class( $shipping_tax_class ) {
			$settings = $this->get_settings();
			$override = $this->get_current_user_override();

			if ( empty( $override ) ) {
				return $shipping_tax_class;
			}

			if ( empty( $settings['apply_to_shipping'] ) ) {
				return '';
			}

			return $shipping_tax_class;
		}

		protected function print_frontend_notice_once( $context = 'checkout' ) {
			static $printed = array(
				'checkout' => false,
				'cart'     => false,
			);

			$override = $this->get_current_user_override();

			if ( empty( $override ) || ! function_exists( 'wc_print_notice' ) ) {
				return;
			}

			if ( ! isset( $printed[ $context ] ) ) {
				$printed[ $context ] = false;
			}

			if ( true === $printed[ $context ] ) {
				return;
			}

			$printed[ $context ] = true;

			$settings = $this->get_settings();

			wc_print_notice(
				esc_html( $settings['notice_message'] ),
				'notice'
			);
		}

		public function maybe_output_checkout_notice() {
			$settings = $this->get_settings();

			if ( empty( $settings['show_checkout_notice'] ) ) {
				return;
			}

			$this->print_frontend_notice_once( 'checkout' );
		}

		public function maybe_output_cart_notice() {
			$settings = $this->get_settings();

			if ( empty( $settings['show_cart_notice'] ) ) {
				return;
			}

			$this->print_frontend_notice_once( 'cart' );
		}

		public function capture_override_meta_on_order( $order, $data ) {
			$override = $this->get_current_user_override();

			if ( empty( $override ) || ! is_a( $order, 'WC_Order' ) ) {
				return;
			}

			$this->store_override_meta_on_order( $order, $override, 'checkout' );
		}

		public function capture_override_meta_on_admin_order( $order_id ) {
			if ( ! is_admin() ) {
				return;
			}

			$order = wc_get_order( $order_id );

			if ( ! $order || $order->get_meta( self::ORDER_META_PREFIX . 'role', true ) ) {
				return;
			}

			$override = $this->get_current_user_override();

			if ( empty( $override ) ) {
				return;
			}

			$this->store_override_meta_on_order( $order, $override, 'admin' );
		}

		protected function store_override_meta_on_order( $order, $override, $source = 'checkout' ) {
			$order->update_meta_data( self::ORDER_META_PREFIX . 'role', $override['role'] );
			$order->update_meta_data( self::ORDER_META_PREFIX . 'rate', (string) $override['rate'] );
			$order->update_meta_data( self::ORDER_META_PREFIX . 'priority', (string) $override['priority'] );
			$order->update_meta_data( self::ORDER_META_PREFIX . 'user_id', (string) $override['user_id'] );
			$order->update_meta_data( self::ORDER_META_PREFIX . 'source', sanitize_key( $source ) );
			$order->save();

			$this->log(
				'Stored order tax override meta.',
				array(
					'order_id'  => $order->get_id(),
					'role'      => $override['role'],
					'rate'      => $override['rate'],
					'priority'  => $override['priority'],
					'user_id'   => $override['user_id'],
					'source'    => $source,
				)
			);
		}

		public function add_order_list_column_legacy( $columns ) {
			$new = array();

			foreach ( $columns as $key => $label ) {
				$new[ $key ] = $label;

				if ( 'order_total' === $key ) {
					$new['ko_role_tax_override'] = __( 'Tax Override', 'ko-wc-role-tax-overrides' );
				}
			}

			if ( ! isset( $new['ko_role_tax_override'] ) ) {
				$new['ko_role_tax_override'] = __( 'Tax Override', 'ko-wc-role-tax-overrides' );
			}

			return $new;
		}

		public function render_order_list_column_legacy( $column, $post_id ) {
			if ( 'ko_role_tax_override' !== $column ) {
				return;
			}

			$this->render_order_list_column_output( wc_get_order( $post_id ) );
		}

		public function add_order_list_column_hpos( $columns ) {
			$new = array();

			foreach ( $columns as $key => $label ) {
				$new[ $key ] = $label;

				if ( 'order_total' === $key ) {
					$new['ko_role_tax_override'] = __( 'Tax Override', 'ko-wc-role-tax-overrides' );
				}
			}

			if ( ! isset( $new['ko_role_tax_override'] ) ) {
				$new['ko_role_tax_override'] = __( 'Tax Override', 'ko-wc-role-tax-overrides' );
			}

			return $new;
		}

		public function render_order_list_column_hpos( $column, $order ) {
			if ( 'ko_role_tax_override' !== $column ) {
				return;
			}

			if ( is_numeric( $order ) ) {
				$order = wc_get_order( $order );
			}

			$this->render_order_list_column_output( $order );
		}

		protected function render_order_list_column_output( $order ) {
			if ( ! $order ) {
				echo '&mdash;';
				return;
			}

			$role = $order->get_meta( self::ORDER_META_PREFIX . 'role', true );
			$rate = $order->get_meta( self::ORDER_META_PREFIX . 'rate', true );

			if ( '' === $role || '' === $rate ) {
				echo '&mdash;';
				return;
			}

			echo esc_html( $role . ': ' . $rate . '%' );
		}

		public function add_order_meta_box_legacy() {
			add_meta_box(
				'ko-role-tax-audit',
				__( 'Role Tax Audit', 'ko-wc-role-tax-overrides' ),
				array( $this, 'render_order_meta_box' ),
				'shop_order',
				'side',
				'default'
			);
		}

		public function add_order_meta_box_hpos( $screen_id ) {
			add_meta_box(
				'ko-role-tax-audit',
				__( 'Role Tax Audit', 'ko-wc-role-tax-overrides' ),
				array( $this, 'render_order_meta_box' ),
				$screen_id,
				'side',
				'default'
			);
		}

		public function render_order_meta_box( $post_or_order ) {
			$order = ( $post_or_order instanceof WC_Order ) ? $post_or_order : wc_get_order( is_object( $post_or_order ) && isset( $post_or_order->ID ) ? $post_or_order->ID : 0 );

			if ( ! $order ) {
				echo '<p>' . esc_html__( 'No order found.', 'ko-wc-role-tax-overrides' ) . '</p>';
				return;
			}

			$role     = $order->get_meta( self::ORDER_META_PREFIX . 'role', true );
			$rate     = $order->get_meta( self::ORDER_META_PREFIX . 'rate', true );
			$priority = $order->get_meta( self::ORDER_META_PREFIX . 'priority', true );
			$user_id  = $order->get_meta( self::ORDER_META_PREFIX . 'user_id', true );
			$source   = $order->get_meta( self::ORDER_META_PREFIX . 'source', true );
			$labels   = $this->get_editable_role_labels();

			if ( '' === $role && '' === $rate ) {
				echo '<p>' . esc_html__( 'No role-based tax override was stored for this order.', 'ko-wc-role-tax-overrides' ) . '</p>';
				return;
			}

			$role_label = isset( $labels[ $role ] ) ? $labels[ $role ] : $role;

			echo '<p><strong>' . esc_html__( 'Applied role:', 'ko-wc-role-tax-overrides' ) . '</strong> ' . esc_html( $role_label ) . ' <code>(' . esc_html( $role ) . ')</code></p>';
			echo '<p><strong>' . esc_html__( 'Applied rate:', 'ko-wc-role-tax-overrides' ) . '</strong> ' . esc_html( $rate ) . '%</p>';
			echo '<p><strong>' . esc_html__( 'Priority:', 'ko-wc-role-tax-overrides' ) . '</strong> ' . esc_html( $priority ) . '</p>';
			echo '<p><strong>' . esc_html__( 'User ID:', 'ko-wc-role-tax-overrides' ) . '</strong> ' . esc_html( $user_id ) . '</p>';
			echo '<p><strong>' . esc_html__( 'Captured from:', 'ko-wc-role-tax-overrides' ) . '</strong> ' . esc_html( $source ? $source : 'checkout' ) . '</p>';
		}

		public function render_settings_page() {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				return;
			}

			$editable_roles = get_editable_roles();
			$settings       = $this->get_settings();
			$overrides      = isset( $settings['overrides'] ) ? $settings['overrides'] : array();

			if ( empty( $overrides ) ) {
				$overrides = array(
					array(
						'role'     => '',
						'rate'     => '',
						'priority' => 10,
					),
				);
			}

			$active_count = count( array_filter( $settings['overrides'] ) );
			?>
			<div class="wrap">
				<h1>
					<?php esc_html_e( 'Role Based Taxes', 'ko-wc-role-tax-overrides' ); ?>
					<?php if ( $active_count > 0 ) : ?>
						<span style="display:inline-block;margin-left:10px;padding:2px 8px;border-radius:999px;background:#2271b1;color:#fff;font-size:12px;vertical-align:middle;">
							<?php echo esc_html( $active_count . ' active' ); ?>
						</span>
					<?php endif; ?>
				</h1>

				<p><?php esc_html_e( 'Assign a custom WooCommerce tax rate to specific user roles. Use 0 for tax-exempt roles. Only the roles listed here are overridden.', 'ko-wc-role-tax-overrides' ); ?></p>

				<form method="post" action="options.php">
					<?php settings_fields( 'ko_wc_role_tax_overrides_group' ); ?>

					<table class="form-table" role="presentation" style="max-width:900px;">
						<tr>
							<th scope="row"><?php esc_html_e( 'Apply to shipping', 'ko-wc-role-tax-overrides' ); ?></th>
							<td><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[apply_to_shipping]" value="1" <?php checked( ! empty( $settings['apply_to_shipping'] ) ); ?>> <?php esc_html_e( 'Use the override rate for shipping tax too.', 'ko-wc-role-tax-overrides' ); ?></label></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Checkout notice', 'ko-wc-role-tax-overrides' ); ?></th>
							<td><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[show_checkout_notice]" value="1" <?php checked( ! empty( $settings['show_checkout_notice'] ) ); ?>> <?php esc_html_e( 'Show one notice at the top of checkout.', 'ko-wc-role-tax-overrides' ); ?></label></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Cart notice', 'ko-wc-role-tax-overrides' ); ?></th>
							<td><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[show_cart_notice]" value="1" <?php checked( ! empty( $settings['show_cart_notice'] ) ); ?>> <?php esc_html_e( 'Show one notice at the top of cart.', 'ko-wc-role-tax-overrides' ); ?></label></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Notice message', 'ko-wc-role-tax-overrides' ); ?></th>
							<td>
								<input type="text" class="regular-text" style="width:100%;max-width:700px;" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[notice_message]" value="<?php echo esc_attr( $settings['notice_message'] ); ?>" />
								<p class="description"><?php esc_html_e( 'Message shown on cart/checkout when a role-based tax override is active.', 'ko-wc-role-tax-overrides' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Logging', 'ko-wc-role-tax-overrides' ); ?></th>
							<td><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[enable_logging]" value="1" <?php checked( ! empty( $settings['enable_logging'] ) ); ?>> <?php esc_html_e( 'Write tax override events to WooCommerce logs.', 'ko-wc-role-tax-overrides' ); ?></label></td>
						</tr>
					</table>

					<h2><?php esc_html_e( 'Role override rules', 'ko-wc-role-tax-overrides' ); ?></h2>

					<table class="widefat striped" id="ko-role-tax-overrides-table" style="max-width:1000px;">
						<thead>
							<tr>
								<th style="width:40%;"><?php esc_html_e( 'User Role', 'ko-wc-role-tax-overrides' ); ?></th>
								<th style="width:20%;"><?php esc_html_e( 'Tax Rate (%)', 'ko-wc-role-tax-overrides' ); ?></th>
								<th style="width:20%;"><?php esc_html_e( 'Priority', 'ko-wc-role-tax-overrides' ); ?></th>
								<th style="width:20%;"><?php esc_html_e( 'Remove', 'ko-wc-role-tax-overrides' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $overrides as $index => $row ) : ?>
								<tr>
									<td>
										<select name="<?php echo esc_attr( self::OPTION_KEY ); ?>[overrides][<?php echo esc_attr( $index ); ?>][role]" style="width:100%;">
											<option value=""><?php esc_html_e( 'Select a role', 'ko-wc-role-tax-overrides' ); ?></option>
											<?php foreach ( $editable_roles as $role_key => $role_data ) : ?>
												<option value="<?php echo esc_attr( $role_key ); ?>" <?php selected( $row['role'], $role_key ); ?>>
													<?php echo esc_html( $role_data['name'] ); ?>
												</option>
											<?php endforeach; ?>
										</select>
									</td>
									<td>
										<input type="number" step="0.0001" min="0" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[overrides][<?php echo esc_attr( $index ); ?>][rate]" value="<?php echo esc_attr( $row['rate'] ); ?>" style="width:100%;" placeholder="0 or 7.25" />
									</td>
									<td>
										<input type="number" step="1" min="1" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[overrides][<?php echo esc_attr( $index ); ?>][priority]" value="<?php echo esc_attr( isset( $row['priority'] ) ? $row['priority'] : 10 ); ?>" style="width:100%;" />
									</td>
									<td>
										<button type="button" class="button ko-remove-row"><?php esc_html_e( 'Remove', 'ko-wc-role-tax-overrides' ); ?></button>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>

					<p style="margin-top:15px;">
						<button type="button" class="button button-secondary" id="ko-add-row"><?php esc_html_e( 'Add Role Override', 'ko-wc-role-tax-overrides' ); ?></button>
					</p>

					<?php submit_button(); ?>
				</form>
			</div>

			<script>
				(function() {
					const tableBody = document.querySelector('#ko-role-tax-overrides-table tbody');
					const addBtn = document.getElementById('ko-add-row');

					if (!tableBody || !addBtn) {
						return;
					}

					addBtn.addEventListener('click', function() {
						const rowCount = tableBody.querySelectorAll('tr').length;
						const tr = document.createElement('tr');

						tr.innerHTML = `
							<td>
								<select name="<?php echo esc_js( self::OPTION_KEY ); ?>[overrides][${rowCount}][role]" style="width:100%;">
									<option value=""><?php echo esc_js( __( 'Select a role', 'ko-wc-role-tax-overrides' ) ); ?></option>
									<?php foreach ( $editable_roles as $role_key => $role_data ) : ?>
										<option value="<?php echo esc_attr( $role_key ); ?>"><?php echo esc_html( $role_data['name'] ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
							<td>
								<input type="number" step="0.0001" min="0" name="<?php echo esc_js( self::OPTION_KEY ); ?>[overrides][${rowCount}][rate]" value="" style="width:100%;" placeholder="0 or 7.25" />
							</td>
							<td>
								<input type="number" step="1" min="1" name="<?php echo esc_js( self::OPTION_KEY ); ?>[overrides][${rowCount}][priority]" value="10" style="width:100%;" />
							</td>
							<td>
								<button type="button" class="button ko-remove-row"><?php echo esc_js( __( 'Remove', 'ko-wc-role-tax-overrides' ) ); ?></button>
							</td>
						`;

						tableBody.appendChild(tr);
					});

					document.addEventListener('click', function(e) {
						if (e.target.classList.contains('ko-remove-row')) {
							const row = e.target.closest('tr');
							if (row) {
								row.remove();
							}
						}
					});
				})();
			</script>
			<?php
		}

		public function render_logs_page() {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				return;
			}
			?>
			<div class="wrap">
				<h1><?php esc_html_e( 'Role Based Taxes: Logs', 'ko-wc-role-tax-overrides' ); ?></h1>
				<p><?php esc_html_e( 'When logging is enabled, entries are written to the WooCommerce log source:', 'ko-wc-role-tax-overrides' ); ?> <code><?php echo esc_html( self::LOG_SOURCE ); ?></code></p>
				<p><?php esc_html_e( 'View them under WooCommerce > Status > Logs.', 'ko-wc-role-tax-overrides' ); ?></p>
			</div>
			<?php
		}

		public function render_debug_page() {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				return;
			}

			$override = $this->get_current_user_override();
			$user     = wp_get_current_user();
			?>
			<div class="wrap">
				<h1><?php esc_html_e( 'Role Based Taxes: Debug', 'ko-wc-role-tax-overrides' ); ?></h1>
				<table class="widefat striped" style="max-width:900px;">
					<tbody>
						<tr>
							<th style="width:30%;"><?php esc_html_e( 'Current user ID', 'ko-wc-role-tax-overrides' ); ?></th>
							<td><?php echo esc_html( get_current_user_id() ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Current user roles', 'ko-wc-role-tax-overrides' ); ?></th>
							<td><code><?php echo esc_html( wp_json_encode( isset( $user->roles ) ? $user->roles : array() ) ); ?></code></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Resolved override', 'ko-wc-role-tax-overrides' ); ?></th>
							<td><code><?php echo esc_html( wp_json_encode( $override ) ); ?></code></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Configured settings', 'ko-wc-role-tax-overrides' ); ?></th>
							<td><code><?php echo esc_html( wp_json_encode( $this->get_settings() ) ); ?></code></td>
						</tr>
					</tbody>
				</table>
			</div>
			<?php
		}
	}

	new KO_WC_Role_Tax_Overrides();
}
