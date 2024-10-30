<?php

/**
 * WHMCS Addon to help manage calls to MagniFinance
 * MagniFinance API: https://app.magnifinance.com
 *
 * PHP version 5
 *
 * @package    WHMCS-MagniFinance
 * @author     WebDS <info@webds.pt>
 * @copyright  WebDS
 * @license     CC Attribution-NonCommercial-NoDerivs
 * @version    $1.0$
 * @link       http://www.webds.pt/
 */
class MAGNIFINANCE_WEB {

    function __construct() {
        require_once('nusoap/nusoap.php');

        $this->loginEmail = get_option('wc_mf_loginemail');
        $this->loginToken = get_option('wc_mf_api_logintoken');
        $this->vat = get_option('wc_mf_vat');

        $this->api_url = 'https://app.magnifinance.com/Magni_API/Magni_API.asmx?WSDL';

        $this->service = new nusoap_client($this->api_url, array('trace' => TRUE));
    }

    function Invoice_Create($params) {
        //pre($params);
        $return = (object) $this->service->call("Invoice_Create", $params);


        //echo "<pre>".print_r($this->service,true)."</pre>";
        //exit();

        return $return;
    }

    function prepInvoice($order, $order_id, $close = false) {

        $invoiceID = get_post_meta($order_id, 'wc_mf_inv_num', true);

        if (empty($invoiceID))
            $invoiceID = 0;

        $client_name = $order->billing_first_name . " " . $order->billing_last_name;

        $billing_address = $order->billing_address_1;

        $client_vat = $order->billing_nif;
        $customer_id = '';
        if (!empty($order->customer_user)) {
            $customer_id = $order->customer_user;
        }


        if (isset($order->billing_address_2))
            $billing_address = $billing_address . "\n" . $order->billing_address_2 . "\n";

        if ($order->billing_company == '') {
            $name_go = $client_name . " (" . $order->billing_email . ")";
        } else {
            $name_go = $order->billing_company;
        }

        // Lets get the user's MagniFinance data

        if (empty($client_vat)) {
            $client_vat = '999999990';
        }

        $client = array(
            'ClientNIF' => $client_vat,
            'ClientName' => $name_go,
            'ClientAddress' => $billing_address,
            'ClientCity' => $order->billing_city,
            'ClientEmail' => $order->billing_email,
            'ClientCountryCode' => $order->billing_country,
            'ClientZipCode' => $order->billing_postcode,
            'ClientPhoneNumber' => $order->billing_phone,
            'External_Id' => $customer_id
        );


        $InvoiceType = 'I';
        if (get_option('wc_mf_create_simplified_invoice') == 1)
            $InvoiceType = 'S';


        $tax = new WC_Tax(); //looking for appropriate vat for specific product
        $apply_tax = $tax->find_rates(array('country' => $order->billing_country));

        $invoice = array(
            'InvoiceId' => $invoiceID,
            'InvoiceDate' => date('Y-m-d', strtotime($order->order_date)),
            'InvoiceDueDate' => date('Y-m-d', strtotime("+1 month", strtotime($order->order_date))),
            'InvoiceType' => $InvoiceType,
            'Products' => array(
                'InvoiceProduct' => $this->prepItems($order, $apply_tax)
            )
        );

        if (get_option('wc_mf_vatexemption_invoice') == 1)
            $invoice['TaxExemptionReasonCode'] = 'M10';

        $params = array(
            'Authentication' => array(
                'LoginEmail' => $this->loginEmail,
                'LoginToken' => $this->loginToken
            ),
            'Client' => $client,
            'Invoice' => $invoice,
            'IsToClose' => $close
        );


        if (get_option('wc_mf_send_invoice') == 1 && $invoiceID > 0)
            $params['SendByEmailToAddress'] = $order->billing_email;

        return $params;
    }

    function prepItems($order, $apply_tax = '') {

        $default_vat = $this->vat;

        $usedCoupons = $order->get_used_coupons();

        if (!empty($usedCoupons))
            $coupon = $this->getCoupons($usedCoupons, $order);

        foreach ($order->get_fees() as $fee_item_id => $fee_item) {
            $order_data['fee_lines'][] = array(
                'id' => $fee_item_id,
                'title' => $fee_item->get_name(),
                'tax_class' => $fee_item->get_tax_class(),
                'total' => wc_format_decimal($order->get_line_total($fee_item), 2),
                'total_tax' => wc_format_decimal($order->get_line_tax($fee_item), 2),
            );
        }

        foreach ($order->get_items() as $pid => $item) {
            $dp = 2;
            $product = $item->get_product();

            $price = $item->get_subtotal() / $item->get_quantity();
            $discount = 100 - (($item->get_total() * 100) / $item->get_subtotal());
            if ($discount < 0 OR $discount > 100) {
                $discount = 0;
            }


            ##### START TAX CALCULATION #######
            $item_meta = get_metadata('order_item', $item->get_id());

            $line_total = $item_meta['_line_total'][0];
            $line_tax = $item_meta['_line_tax'][0];

            $vat = round(((float) $line_tax * 100) / (float) $line_total);

            ##### END TAX CALCULATION #######

            if ($product->get_sku() && $product_code != 'id') {
                $ProductCode = $product->get_sku();
            } else {
                $ProductCode = "#" . $pid;
            }

            $items[] = array(
                'ProductCode' => $ProductCode,
                'ProductDescription' => $item->get_name(),
                'ProductUnitPrice' => wc_format_decimal($order->get_item_subtotal($item, false, false), $dp),
                'ProductQuantity' => $item->get_quantity(),
                'ProductDiscount' => wc_format_decimal($discount, 2),
                'ProductType' => 'P',
                'TaxValue' => $vat,
            );

            if (!empty($usedCoupons) && $coupon->discount_type == 'fixed_product') {
                $ProductDiscount = $this->handleDiscount($coupon, $order, $item);

                if (is_array($ProductDiscount['item'])) {

                    $items[] = $ProductDiscount['item'];
                }
            }
        }

        $shipping = reset($order->get_items('shipping'));

        if (isset($shipping['method_id'])) {
            $ProductUnitPrice = $shipping['cost'];

            if ($apply_tax['shipping'] == 'no') {
                $default_vat = 0;
            }

            $items[] = array(
                'ProductCode' => 'Envio',
                'ProductDescription' => 'Custos de Envio',
                'ProductUnitPrice' => $ProductUnitPrice,
                'ProductQuantity' => 1,
                'ProductType' => 'S',
                'TaxValue' => $default_vat,
            );
        }

        
        return $items;
    }

    function getCoupons($usedCoupons, $order) {

        foreach ($usedCoupons as $code) {
            $coupon = new WC_Coupon($code);
        }

        return $coupon;
    }

    function handleDiscount($coupon, $order, $item = null) {

        $return = $this->getDiscountLine($coupon, $item, $order);


        return $return;
    }

    function getDiscountLine(WC_Coupon $coupon, $item, $order) {

        $return['type'] = $coupon->discount_type;
        switch ($coupon->discount_type) {
            case 'fixed_cart':
                $return['item'] = array(
                    'ProductCode' => __('Desconto'),
                    'ProductDescription' => $coupon->code,
                    'ProductUnitPrice' => -abs($coupon->coupon_amount),
                    'ProductQuantity' => $item['qty'],
                    'ProductDiscount' => 0,
                    'ProductType' => 'P',
                    'TaxValue' => $this->vat,
                );
                break;
            case 'percent':
                $return['item'] = (float) number_format($coupon->coupon_amount, 2, '.', '');
                break;
            case 'fixed_product':
                if (in_array($item['item_meta']['_product_id'][0], $coupon->product_ids)) {
                    $return['item'] = array(
                        'ProductCode' => __('Desconto') . ' - ' . $coupon->code,
                        'ProductDescription' => $item['qty'] . "x " . $item['name'],
                        'ProductUnitPrice' => -abs($coupon->coupon_amount),
                        'ProductQuantity' => $item['qty'],
                        'ProductDiscount' => 0,
                        'ProductType' => 'P',
                        'TaxValue' => $this->vat,
                    );
                } else {
                    $return['item'] = 0;
                }
                break;
            case 'percent_product':
                if (in_array($item['item_meta']['_product_id'][0], $coupon->product_ids))
                    $return['item'] = (float) number_format($coupon->coupon_amount, 2, '.', '');
                else
                    $return['item'] = 0;
                break;
        }

        return $return;
    }

}
