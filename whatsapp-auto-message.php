<?php
/*
Plugin Name: WhatsApp Auto Message
Description: Envía mensajes automáticos de WhatsApp al finalizar una compra en WooCommerce usando la API oficial de whatsapp.
Version: 0.3
Author: Orlando Montesinos Quintana
Author URI: https://orlandomontesinos.com/
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

/**
 * Registrar ajustes en el admin
 */
function wam_register_settings() {
    add_option( 'wam_wa_api_token', '' );
    add_option( 'wam_wa_api_graph', '' );
    add_option( 'wam_wa_api_phone_id', '' );
    add_option( 'wam_wa_api_recipient', '' );
    add_option( 'wam_wa_api_delivery', '' );

    register_setting( 'wam_options_group', 'wam_wa_api_token' );
    register_setting( 'wam_options_group', 'wam_wa_api_graph' );
    register_setting( 'wam_options_group', 'wam_wa_api_phone_id' );
    register_setting( 'wam_options_group', 'wam_wa_api_recipient' );
    register_setting( 'wam_options_group', 'wam_wa_api_delivery' );
}
add_action( 'admin_init', 'wam_register_settings' );

/**
 * Página de configuración
 */
function wam_register_options_page() {
    add_menu_page(
        'WhatsApp Auto Message',
        'WhatsApp Auto Message',
        'manage_options',
        'whatsapp-auto-message',
        'wam_options_page_html',
        'dashicons-whatsapp',
        56
    );
}
add_action( 'admin_menu', 'wam_register_options_page' );

function wam_options_page_html() {
    ?>
    <div class="wrap">
        <h1>Configuración - WhatsApp Auto Message</h1>
        <form method="post" action="options.php">
            <?php settings_fields( 'wam_options_group' ); ?>
            <?php do_settings_sections( 'wam_options_group' ); ?>

            <table class="form-table">
                <tr valign="top">
                    <th scope="row">API (Token)</th>
                    <td><input type="text" name="wam_wa_api_token" value="<?php echo esc_attr( get_option('wam_wa_api_token') ); ?>" style="width:400px;"></td>
                </tr>
                <tr valign="top">
                    <th scope="row">Graph Version</th>
                    <td><input type="text" name="wam_wa_api_graph" value="<?php echo esc_attr( get_option('wam_wa_api_graph') ); ?>" placeholder="ej: v19.0" style="width:400px;"></td>
                </tr>
                <tr valign="top">
                    <th scope="row">Phone Number ID</th>
                    <td><input type="text" name="wam_wa_api_phone_id" value="<?php echo esc_attr( get_option('wam_wa_api_phone_id') ); ?>" style="width:400px;"></td>
                </tr>
                <tr valign="top">
                    <th scope="row">
                        <h2>Números :</h2>
                    </th>
                    <td>
                        <p>Estos números son los que usará WhatsApp para enviar el mensaje automático.</p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Destinatario (Ventas)</th>
                    <td><input type="text" name="wam_wa_api_recipient" value="<?php echo esc_attr( get_option('wam_wa_api_recipient') ); ?>" style="width:400px;"></td>
                </tr>
                <tr valign="top">
                    <th scope="row">Destinatario (Paquetería)</th>
                    <td><input type="text" name="wam_wa_api_delivery" value="<?php echo esc_attr( get_option('wam_wa_api_delivery') ); ?>" style="width:400px;"></td>
                </tr>
            </table>

            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

/**
 * Helper: obtiene dirección de envío con fallback a facturación
 */
function wam_get_shipping_address( $order ) {
    $first_name = $order->get_shipping_first_name() ?: $order->get_billing_first_name();
    $last_name  = $order->get_shipping_last_name() ?: $order->get_billing_last_name();
    $address_1  = $order->get_shipping_address_1() ?: $order->get_billing_address_1();
    $address_2  = $order->get_shipping_address_2() ?: $order->get_billing_address_2();
    $city       = $order->get_shipping_city() ?: $order->get_billing_city();
    $state      = $order->get_shipping_state() ?: $order->get_billing_state();
    $postcode   = $order->get_shipping_postcode() ?: $order->get_billing_postcode();
    $country    = $order->get_shipping_country() ?: $order->get_billing_country();
    $phone      = $order->get_shipping_phone() ?: $order->get_billing_phone();

    $out  = $first_name . " " . $last_name . "\n";
    $out .= $address_1 . "\n";
    if ( $address_2 ) {
        $out .= $address_2 . "\n";
    }
    $out .= $city . ", " . $state . "\n";
    $out .= $postcode . "\n";
    $out .= $country . "\n\n";
    if ( $phone ) {
        $out .= "*Teléfono* : " . $phone . "\n";
    }
    $out .= "\n";

    return $out;
}

/**
 * Hook WooCommerce: al completar pedido
 */
function wam_send_whatsapp_message( $order_id ) {
    if ( ! $order_id ) return;

    $order = wc_get_order( $order_id );
    if ( ! $order ) return;

    $token       = get_option( 'wam_wa_api_token' );
    $graph       = get_option( 'wam_wa_api_graph' );
    $phone_id    = get_option( 'wam_wa_api_phone_id' );
    $recipient   = get_option( 'wam_wa_api_recipient' ); // Ventas

    // Construcción de mensaje completo (Ventas)
    $message  = "*Mensaje enviado Automáticamente*\n";
    $message .= "*a través de IA.*\n\n";
    $message .= "Hola Soy " . $order->get_billing_first_name() . "!\n";
    $message .= "He realizado la compra de los siguientes productos:\n\n";
    $message .= "*Pedido:*\n#" . $order->get_order_number() . "\n\n";

    foreach ( $order->get_items() as $item ) {
        $product  = $item->get_product();
        $price    = $product ? $product->get_price() : 0;
        $quantity = $item->get_quantity();
        $message .= $quantity . "x - *Valor : $" . number_format($price, 0, '.', ',') . "* - ";
        $message .= "*" . $item->get_name() . "*\n\n";
    }

    $message .= "*Subtotal:*\n";
    $message .= number_format($order->get_subtotal(), 2, '.', ',') . " " . $order->get_currency() . "\n\n";
    $message .= "*Metodo de Pago:*\n" . $order->get_payment_method_title() . "\n\n";
    $message .= "*Datos de Envío:*\n" . wam_get_shipping_address( $order );

    $shipping_total  = $order->get_shipping_total();
    $shipping_method = $order->get_shipping_method();
    $message .= "*Envío:*\n";
    if ( $shipping_total > 0 ) {
        $message .= number_format($shipping_total, 2, '.', ',') . " " . $order->get_currency();
        if ( $shipping_method ) {
            $message .= " vía " . $shipping_method;
        }
        $message .= "\n\n";
    } else {
        $message .= "Sin costo de envío\n\n";
    }

    $message .= "*Total:*\n" . number_format($order->get_total(), 2, '.', ',') . " " . $order->get_currency() . "\n\n";

    $cambio = get_post_meta( $order_id, '_monto_cambio', true );
    if ( ! empty( $cambio ) ) {
        $currency_symbol = get_woocommerce_currency_symbol( $order->get_currency() );
        $message .= "*Monto del billete para cambio:*\n" . number_format( $cambio, 2, '.', ',' ) . $currency_symbol . "\n\n";
    }

    $customer_note = $order->get_customer_note();
    if ( ! empty( $customer_note ) ) {
        $message .= "*Nota de Pedido:*\n" . $customer_note . "\n\n";
    }

    $message .= "-";

    // Enviar mensaje a Ventas
    wam_send_to_whatsapp_api( $token, $graph, $phone_id, $recipient, $message );

    // Enviar mensaje simplificado a Paquetería
    $delivery_number = get_option( 'wam_wa_api_delivery' );
    if ( $delivery_number ) {
        $short_msg  = "*Mensaje enviado Automáticamente*\n";
        $short_msg .= "*a través de IA.*\n\n";
        $short_msg .= "*Pedido #" . $order->get_order_number() . "*\n\n";
        $short_msg .= "*Metodo de Pago:*\n" . $order->get_payment_method_title() . "\n\n";
        $short_msg .= "*Datos de Envío:*\n" . wam_get_shipping_address( $order );
        $short_msg .= "*Total:*\n" . number_format($order->get_total(), 2, '.', ',') . " " . $order->get_currency() . "\n\n";

        if ( ! empty( $cambio ) ) {
            $currency_symbol = get_woocommerce_currency_symbol( $order->get_currency() );
            $short_msg .= "*Monto del billete para cambio:*\n" . number_format( $cambio, 2, '.', ',' ) . $currency_symbol . "\n\n";
        }

        $customer_note = $order->get_customer_note();
        if ( ! empty( $customer_note ) ) {
            $short_msg .= "*Nota de Pedido:*\n" . $customer_note . "\n\n";
        }

        $short_msg .= "-";

        wam_send_to_whatsapp_api( $token, $graph, $phone_id, $delivery_number, $short_msg );
    }
}
add_action( 'woocommerce_thankyou', 'wam_send_whatsapp_message', 10, 1 );

/**
 * Función genérica para enviar mensajes vía WhatsApp API
 */
function wam_send_to_whatsapp_api( $token, $graph, $phone_id, $to, $message ) {
    $url  = "https://graph.facebook.com/{$graph}/{$phone_id}/messages";
    $body = [
        'messaging_product' => 'whatsapp',
        'to'   => $to,
        'type' => 'text',
        'text' => ['body' => $message]
    ];

    $args = [
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'Content-Type'  => 'application/json'
        ],
        'body'   => wp_json_encode( $body ),
        'method' => 'POST'
    ];

    $response = wp_remote_post( $url, $args );

    if ( is_wp_error( $response ) ) {
        error_log( 'WhatsApp Auto Message error: ' . $response->get_error_message() );
    }
}
