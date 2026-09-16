<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Drushfs_Admin_Menu {

	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'add_menu_page' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_scripts' ] );
	}

	public static function add_menu_page(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Speedy Orders', 'drusoft-shipping-for-sameday' ),
			__( 'Speedy Orders', 'drusoft-shipping-for-sameday' ),
			'manage_woocommerce',
			'drushfs-orders',
			[ __CLASS__, 'render_page' ]
		);
	}

	public static function enqueue_scripts( $hook ): void {
		if ( 'woocommerce_page_drushfs-orders' !== $hook ) {
			return;
		}

		wp_enqueue_script(
			'drushfs-admin-orders',
			DRUSHFS_URL . 'assets/js/admin-orders.js',
			[ 'jquery' ],
			DRUSHFS_VER,
			true
		);

		wp_localize_script( 'drushfs-admin-orders', 'drushfs_admin_params', [
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'drushfs_actions' ),
			'i18n'     => [
				'confirm_cancel'  => __( 'Are you sure you want to cancel this shipment?', 'drusoft-shipping-for-sameday' ),
				'requesting'      => __( 'Requesting...', 'drusoft-shipping-for-sameday' ),
				'requested'       => __( 'Requested', 'drusoft-shipping-for-sameday' ),
				'request_courier' => __( 'Request Courier', 'drusoft-shipping-for-sameday' ),
				'generating'      => __( 'Generating...', 'drusoft-shipping-for-sameday' ),
				'generate'        => __( 'Generate', 'drusoft-shipping-for-sameday' ),
			],
		] );
	}

	public static function render_page(): void {
		require_once __DIR__ . '/class-drushfs-orders-list-table.php';

		$table = new Drushfs_Orders_List_Table();
		$table->prepare_items();

		echo '<div class="wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Speedy Orders', 'drusoft-shipping-for-sameday' ) . '</h1>';
		echo '<form method="post">';
		$table->display();
		echo '</form>';
		echo '</div>';
	}
}

Drushfs_Admin_Menu::init();
