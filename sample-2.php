<?php 

// Prevent direct access to the file
if( ! defined( 'ABSPATH' ) ) exit;

class MintPayment
{
    // Initialize actions for AJAX requests
    public function init()
    {
        add_action( 'wp_ajax_payment', array( $this, '__payment' ) );
        add_action( 'wp_ajax_update_summary', array( $this, '__update_summary' ) );
        add_action( 'wp_ajax_reload_payment_summary', array( $this, '__reload_payment_summary' ) );
        add_action( 'wp_ajax_reload_card_form', array( $this, '__reload_credit_card_form' ) );
    }

    // Handle payment processing
    public function __payment()
    {
        // Verify nonce for security
        if ( !wp_verify_nonce( $_REQUEST['payment_frm_nonce'], "payment_action")) {
            exit("No naughty business please!!");
        }

        // Retrieve billing data from the POST request
        $cusData = array();
        $billing_data = isset($_POST["billing"]) ? $_POST["billing"] : array();
        $post_id = isset($_POST['pid']) && !empty($_POST['pid']) ? filter_var($_POST['pid'], FILTER_SANITIZE_STRING) : '';
        extract($billing_data);  // Extract variables from billing data

        // Calculate price based on the project ID and country
        $priceData = $this->calculate_price($post_id, $country);

        // Validation for required fields
        if (empty($full_name)) {
            $error = new WP_Error( 'FNAME_EMPTY', '<span class="error">Please enter Full name!</span>' );
            wp_send_json_error( $error );
        } elseif (empty($email)) {
            $error = new WP_Error( 'EMAIL_EMPTY', '<span class="error">Please enter Email address!</span>' );
            wp_send_json_error( $error );
        } elseif(!is_email($email)) {
            $error = new WP_Error( 'EMAIL_INVALID', '<span class="error">Email address is invalid!</span>' );
            wp_send_json_error( $error );
        } elseif(empty($phone)) {
            $error = new WP_Error( 'PHONE_EMPTY', '<span class="error">Please enter Phone number!</span>' );
            wp_send_json_error( $error );
        } else {
            // Handle payment processing if payment method is provided
            $PaymentMethod = isset($_POST["paymentMethod"]) ? $_POST["paymentMethod"] : '';
            if(!empty($PaymentMethod)) {
                $apiObject = new Mint_Api();
                $cusData['paymentMethodId'] = $PaymentMethod;
                $cusData['amount'] = $priceData['amount'];
                $cusData['currency'] = $priceData['currency'];

                // Charge the customer using the API
                $chargeResponse = $apiObject->charge_customer($cusData);

                // Check if payment succeeded
                if(isset($chargeResponse['status']) && $chargeResponse['status'] == 'succeeded') {
                    // Payment successful, return success response
                    wp_send_json_success(array( 'message' => '<span class="success"><strong>Well done! </strong>Your payment was successful.</span>'));
                } else {
                    // Payment failed, return error
                    $error = new WP_Error( 'PAYMENT_ISSUE_CHARGE', '<span class="error">'.$chargeResponse['message'].'</span>' );
                    wp_send_json_error( $error );
                }

            } else {
                // Error when payment method is not attached
                $error = new WP_Error( 'PAYMENT_ISSUE_ATTACH', '<span class="error">Something went wrong due to attaching the payment method!</span>' );
                wp_send_json_error( $error );
            }
        }

        die;
    }

    // Calculate price based on project ID and country
    public function calculate_price($pid = null, $country = 'SG')
    {
        $data = array();
        if(empty($pid)) return $data;

        // Get project details and tax rate
        $adminObj = new Mintology_Admin();
        $apiObject = new Mint_Api();
        $pData = $adminObj->mintology_get_project_details($pid);

        $contract = isset($pData['contract_type']) ? strtolower($pData['contract_type']) : '';
        $wallet_type = isset($pData['wallet_type']) ? strtolower($pData['wallet_type']) : '';
        
        $currency = $this->get_country_currency($country);
        $address = array("country" => $country);
        $gst = $apiObject->get_tax_rate($address);

        // Calculate price based on contract and wallet type
        $tot_amount = $apiObject->calculate_price($contract, $wallet_type);
        $tot_amount = isset($tot_amount['price']) ? $tot_amount['price'] : 0;

        // Apply GST if applicable
        if(isset($gst['percentage']) && $gst['percentage'] > 0) {
            $percentage = $gst['percentage'];
            $tot_amount = $tot_amount + ($tot_amount * ($percentage / 100));
        }

        // Return calculated price and currency
        $data['amount'] = $tot_amount;
        $data['currency'] = $currency;

        return $data;
    }

    // Get the currency of the selected country
    public function get_country_currency($selectedCountryCode = 'SG')
    {
        $apiUrl = esc_url(MINTOLOGY_PLUGIN_URL.'admin/json/all.json');
        $jsonData = file_get_contents($apiUrl);
        $countries = json_decode($jsonData, true);
        $countryData = [];
        
        foreach ($countries as $country) {
            $countryCode = $country['alpha2Code'];
            if(isset($country['currencies'])) {
                $currency = $country['currencies'][0]['code'];
                $countryData[$countryCode] = $currency;
            }
        }

        // Return currency for selected country, default to SGD if not found
        return isset($countryData[$selectedCountryCode]) ? $countryData[$selectedCountryCode] : 'SGD';
    }

    // Update payment summary
    public function __update_summary()
    {
        $country = isset($_POST["country"]) ? $_POST["country"] : 'SG';
        $post_id = isset($_POST['pid']) && !empty($_POST['pid']) ? $_POST['pid'] : '';
        $adminObj = new Mintology_Admin();
        $currency = $this->get_country_currency($country);

        // Generate and send the updated payment summary
        $summary = $this->print_payment_summary($post_id, $country, $currency);
        wp_send_json_success($summary);
        die;
    }

    // Reload payment summary
    public function __reload_payment_summary()
    {
        $project_id = isset($_POST['pid']) && !empty($_POST['pid']) ? $_POST['pid'] : '';
        if(empty($project_id)) return;

        $output = array();
        $summary = $this->print_payment_summary($project_id);

        // Output HTML for the payment summary
        ob_start();
        ?>
        <div class="mint-loader-img-container" style="display:none;">
            <img src="<?php echo esc_url(MINTOLOGY_PLUGIN_URL. 'admin/images/loader-small.gif'); ?>">
        </div>
        <h4><?php echo esc_html__('Products', 'mintology'); ?></h4>
        <ul class="prod">
            <li><span><?php echo esc_attr($summary['contractTxt']); ?></span><span id="contract_price"><?php echo esc_attr($summary['contractPrice']); ?></span></li>
            <li><span><?php echo esc_attr($summary['walletTxt']); ?></span><span id="wallet_price"><?php echo esc_attr($summary['walletPrice']); ?></span></li>
        </ul>
        <ul class="sub">
            <li><span><?php echo esc_html__('Subtotal', 'mintology'); ?></span><span id="subtotal_price"><?php echo esc_attr($summary['subtotal']); ?></span></li>
            <?php if(isset($summary["gst_amount"]) && !empty($summary["gst_amount"])): ?>
                <li><span><?php echo esc_html( sprintf("%s %d%%", $summary["gst_display_name"], $summary["gst_percentage"]) ); ?></span><span id="gst_amount_price"><?php echo esc_attr($summary['gst_amount']); ?></span></li>
            <?php endif; ?>
        </ul>
        <div class="total-section">
            <p class="total-price"><?php echo esc_html__('Total Price', 'mintology'); ?></p>
            <h4 class="price total_price" id="tot_amount_price"><?php echo esc_attr($summary['tot_amount']); ?></h4>
        </div>
        <div class="checkbox-row">
            <!-- Terms and conditions checkbox can be added here -->
        </div>
        <input type="submit" value="Pay" id="payment_btn" name="payment_btn" class="btn btn-primary btn-disabled">
        <?php
        $output['data'] = ob_get_contents();
        ob_end_clean();

        wp_send_json_success($output);
        die;
    }

    // Generate and print payment summary for the given project
    public function print_payment_summary($project_id = null, $country = "SG")
    {
        $data = array();
        if(empty($project_id)) return $data;

        // Get project details
        $adminObj = new Mintology_Admin();
        $contractWallet = $adminObj->get_contract_wallet_data();
        $apiObject = new Mint_Api();
        $formatter = new NumberFormatter('en_US', NumberFormatter::CURRENCY);

        // Retrieve price and calculate totals
        $priceData = $this->calculate_price($project_id, $country);
        $tot_amount = $priceData['amount'];
        $currency = $priceData['currency'];
        $summary = $this->get_summary($project_id, $currency);

        // Return summary data
        $data = array_merge($summary, array("tot_amount" => $formatter->formatCurrency($tot_amount, $currency)));
        return $data;
    }

    // Fetch summary for the given project ID
    public function get_summary($project_id = null, $currency = 'SGD')
    {
        $summary = array();
        if(empty($project_id)) return $summary;

        $contractData = $this->get_contract_data($project_id);
        $walletData = $this->get_wallet_data($project_id);

        $summary['contractTxt'] = esc_html__('Contract', 'mintology');
        $summary['contractPrice'] = $contractData['price'];
        $summary['walletTxt'] = esc_html__('Wallet', 'mintology');
        $summary['walletPrice'] = $walletData['price'];

        // Apply GST if applicable
        $gstData = $this->calculate_gst($project_id);
        $summary['gst_display_name'] = esc_html__('GST');
        $summary['gst_percentage'] = $gstData['percentage'];
        $summary['gst_amount'] = $gstData['amount'];

        // Calculate subtotal
        $summary['subtotal'] = $this->calculate_subtotal($contractData['price'], $walletData['price'], $summary['gst_amount']);

        return $summary;
    }

    private function calculate_subtotal($contract_price, $wallet_price, $gst_amount) {
        return $contract_price + $wallet_price + $gst_amount;
    }
}

$payment = new MintPayment();
$payment->init();
