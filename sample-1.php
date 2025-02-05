<?php

/**
 * The API functionality of the TestPackage plugin.
 *
 * @link       
 * @since      1.0.0
 * @package    TestPackage
 * @subpackage TestPackage/admin/api/
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit();
}
use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;

class Mint_Api
{
    // Store API keys as class properties
    private $key_authToken;
    private $key_xApi;
    private $key_x_mintKey;

    // Constructor to initialize the keys
    public function __construct()
    {
        $this->key_authToken = '__mint_auth_token';
        $this->key_xApi = '__mint_x_api_key';
        $this->key_x_mintKey = '__mint_x_mint_key';
    }

    // Get the authorization key for API requests
    private function get_authorization_key()
    {
        return base64_encode(MINTOLOGY_CLIENT_ID . ':' . MINTOLOGY_CLIENT_SECRET);
    }

    // Returns a Guzzle HTTP client configured with the provided base URI
    private function get_api_client($base_uri)
    {
        return new Client(['base_uri' => $base_uri]);
    }

    // Fetches the access token for API authentication
    public function get_access_token()
    {
        $key = $this->get_authorization_key();
        if (empty($key)) return; // Return if the key is empty

        $client = $this->get_api_client(MINTOLOGY_ACCESS_TOKEN_URL);

        try {
            // Make a POST request to get the access token
            $response = $client->post('oauth2/token', [
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Authorization' => "Basic {$key}",
                ],
                'form_params' => [
                    'grant_type' => 'client_credentials',
                    'scope' => 'mintology/wp/write',
                ],
            ]);
            return json_decode($response->getBody(), true); // Return the response as an array
        } catch (BadResponseException $e) {
            return json_decode($e->getResponse()->getBody(), true); // Return error if the request fails
        }
    }

    // Registers a plugin with Mintology API
    public function register_plugin($email = '', $plugin_type = 'Wordpress')
    {
        $token_data = $this->get_access_token();
        if (isset($token_data['error'])) return $token_data; // Return error if access token is invalid

        $client = $this->get_api_client(MINTOLOGY_API_BASE_URI);
        $access_token = $token_data['access_token'];
        $token_type = $token_data['token_type'] ?? 'Bearer'; // Default token type is 'Bearer'

        try {
            // Make a POST request to register the plugin
            $response = $client->post('plugins/register', [
                'headers' => [
                    'Authorization' => "$token_type $access_token",
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'email' => $email,
                    'plugin_type' => $plugin_type,
                ],
            ]);
            return json_decode($response->getBody(), true); // Return the response
        } catch (BadResponseException $e) {
            return json_decode($e->getResponse()->getBody(), true); // Return error if request fails
        }
    }

    // Fetches the Mintology key from WordPress options
    public function get_mintology_key()
    {
        $mint_key = get_option($this->key_x_mintKey);
        return $mint_key ? (new MintLoginRegister())->decrypt_mintology_key($mint_key) : null;
    }

    // Uploads a storage file to Mintology API
    public function upload_storage_file($name = 'Mint-demo', $type = 'image/png', $nft = 'image', $folder = 'folder', $pid = '')
    {
        $client = $this->get_api_client(MINTOLOGY_API_BASE_URI);
        $x_mint_key = $this->get_mintology_key();

        if (empty($x_mint_key)) return; // Return if Mintology key is not found

        $name = $this->clean_title($name); // Clean up the file name
        $prefix = "generative-layers/{$pid}";
        
        $bodyParam = [
            'name' => $name,
            'type' => $type
        ];

        // If the file is not an image, add additional parameters
        if ($nft !== 'image') {
            $bodyParam += [
                'prefix' => $prefix,
                'skip_file_id_generation' => true,
                'root_directory' => 'generative-source'
            ];
        }

        try {
            // Make a POST request to upload the file
            $response = $client->post('storage/upload-url', [
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                    'API-Key' => $x_mint_key,
                ],
                'json' => $bodyParam,
            ]);

            return json_decode($response->getBody(), true)['data']; // Return file data on success
        } catch (BadResponseException $e) {
            return json_decode($e->getResponse()->getBody(), true); // Return error if the request fails
        }
    }

    // Cleans up the file name by removing unwanted characters
    private function clean_title($string = '')
    {
        return preg_replace('/[^A-Za-z0-9\-.]/', '', str_replace(' ', '-', $string));
    }

    // Fetches the client information based on the user's token
    public function get_client($user_id = '')
    {
        $token = $this->get_auth_token($user_id);
        if (empty($token)) {
            wp_send_json_error(new WP_Error('401', 'Token not found!')); // Return error if token is not found
        }

        $client = $this->get_api_client(MINTOLOGY_API_BASE_URI);

        try {
            // Make a GET request to fetch the client data
            $response = $client->get('me', [
                'headers' => [
                    'Authorization' => "Bearer $token",
                    'Accept' => 'application/json',
                ],
            ]);
            return json_decode($response->getBody(), true); // Return the client data
        } catch (BadResponseException $e) {
            return json_decode($e->getResponse()->getBody(), true); // Return error if the request fails
        }
    }

    // Fetches the organization ID for a given user
    public function get_organization_id($user_id = '')
    {
        return $this->get_client($user_id ?: get_current_user_id())['organization_id'] ?? null; // Return organization ID or null
    }

    /**
     * Add/Update User Meta with checking
     * 
     * @param string $user_id   User ID.
     * @param string $meta_key  Meta key.
     * @param string $meta_value Meta value.
     * @return string|void Status message or void.
     */
    private function __add_user_meta($user_id = '', $meta_key = '', $meta_value = '')
    {
        if (empty($user_id) || empty($meta_key)) {
            return;
        }
        
        $previous_data = get_user_meta($user_id, '_user_api_data', true) ?: [];
        $previous_data[$meta_key] = $meta_value;
        
        $response = update_user_meta($user_id, '_user_api_data', $previous_data);
        return $response === false ? 'Data Added' : 'Data Updated';
    }

    /**
     * Remove Storage File By Key
     * 
     * @param string $key The file key.
     * @return string|array Error message or API response.
     */
    public function remove_storage_file($key = '')
    {
        if (empty($key) || !$this->is_valid_key($key)) {
            return 'Invalid or missing key';
        }
        
        $client = $this->Mint_Api_Client();
        $x_mint_key = $this->get_mintology_key();

        if (empty($x_mint_key)) {
            return 'Mintology key is not specified!';
        }

        $keyArr = explode('/', $key);
        $fileName = $keyArr[2];
        $fileID = $keyArr[1];
        $endPoint = "storage/{$fileID}/{$fileName}";

        return $this->make_request('DELETE', $endPoint, $x_mint_key);
    }

    /**
     * Generate NFT by layer
     * 
     * @param array $layers Array of layers.
     * @return array|string API response or error message.
     */
    public function generate_nft_by_layers($layers = [])
    {
        if (empty($layers)) {
            return 'No layers provided';
        }

        $client = $this->Mint_Api_Client();
        $x_mint_key = $this->get_mintology_key();

        if (empty($x_mint_key)) {
            return 'Mintology key is not specified!';
        }

        return $this->make_request('POST', 'preview', $x_mint_key, $layers);
    }

    /**
     * Generate a unique ID
     * 
     * @return string Unique identifier.
     */
    private function generate_v4_uuid()
    {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000, mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));
    }

    /**
     * Create Project
     * 
     * @param array $project Project data.
     * @return array|string API response or error message.
     */
    public function create_project($project = [])
    {
        return $this->handle_project_request('POST', 'projects', $project);
    }

    /**
     * Update Project
     * 
     * @param string|null $project_id Project ID.
     * @param array $project Project data.
     * @return array|string API response or error message.
     */
    public function update_project($project_id = null, $project = [])
    {
        return $this->handle_project_request('PUT', "projects/{$project_id}", $project);
    }

    /**
     * Retrieve Project
     * 
     * @param string|null $project_id Project ID.
     * @return array|string API response or error message.
     */
    public function retrieve_project($project_id = null)
    {
        return $this->handle_project_request('GET', "projects/{$project_id}");
    }

    /**
     * List all Projects
     * 
     * @return array List of projects or empty array on failure.
     */
    public function list_projects()
    {
        $x_mint_key = $this->get_mintology_key();
        
        if (empty($x_mint_key)) {
            return [];
        }

        return $this->make_request('GET', 'projects', $x_mint_key);
    }

    /**
     * Deploy Project
     * 
     * @param string|null $projectId Project ID.
     * @return array|string API response or error message.
     */
    public function deploy_project($projectId = null)
    {
        return $this->handle_project_request('POST', "projects/{$projectId}/deploy");
    }

    /**
     * Delete Project
     * 
     * @param string|null $projectId Project ID.
     * @return array|string API response or error message.
     */
    public function delete_project($projectId = null)
    {
        return $this->handle_project_request('DELETE', "projects/{$projectId}");
    }

    /**
     * Get Project Status
     * 
     * @param string|null $projectId Project ID.
     * @return string Project status.
     */
    public function get_project_status($projectId = null)
    {
        $status = 'draft';

        if (empty($projectId)) {
            return $status;
        }

        $client_data = $this->handle_project_request('GET', "projects/{$projectId}");
        return $client_data['status'] ?? $status;
    }

    /**
     * Handle API Project Request
     * 
     * @param string $method HTTP method (POST, GET, PUT, DELETE).
     * @param string $endpoint API endpoint.
     * @param array $data Optional request data.
     * @return array|string API response or error message.
     */
    private function handle_project_request($method, $endpoint, $data = [])
    {
        $x_mint_key = $this->get_mintology_key();
        
        if (empty($x_mint_key)) {
            return 'Mintology key is not specified!';
        }

        return $this->make_request($method, $endpoint, $x_mint_key, $data);
    }

    /**
     * Make API Request
     * 
     * @param string $method HTTP method.
     * @param string $endpoint API endpoint.
     * @param string $x_mint_key Mintology API key.
     * @param array $data Optional request data.
     * @return array|string API response or error message.
     */
    private function make_request($method, $endpoint, $x_mint_key, $data = [])
    {
        $client = $this->Mint_Api_Client();
        $options = [
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'API-Key' => $x_mint_key,
            ],
        ];

        if (!empty($data)) {
            $options['body'] = json_encode($data, JSON_UNESCAPED_SLASHES);
        }

        try {
            $response = $client->request($method, $endpoint, $options);
            return json_decode($response->getBody()->getContents(), true);
        } catch (BadResponseException $e) {
            $response = $e->getResponse();
            return json_decode($response->getBody()->getContents(), true);
        }
    }

    /**
     * Validate Storage Key Format
     * 
     * @param string $key The file key.
     * @return bool True if valid, false otherwise.
     */
    private function is_valid_key($key)
    {
        $keyArr = explode('/', $key);
        return count($keyArr) >= 3;
    }

    ///////////////////////////////////////////////////////////////////////////////////////
    //////////////////         Mintology Production API Endpoints       //////////////////
    ///////////////////////////////////////////////////////////////////////////////////////

    /**
     * Search for contracts based on search terms.
     * 
     * @param array $searchTermObj The search parameters for filtering contracts.
     * @return array The response data containing contract search results.
     */
    public function search_contract($searchTermObj = [])
    {
        $client = $this->Mint_Prod_Api_Client();
        try {
            // Send a POST request to search for contracts
            $response = $client->request('POST', 'contracts/search', [
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode($searchTermObj),
            ]);

            // Decode response into associative array
            $client_data = json_decode($response->getBody()->getContents(), true);
        } catch (BadResponseException $e) {
            // Handle error response
            $response = $e->getResponse();
            $client_data = json_decode($response->getBody()->getContents(), true);
        }
        return $client_data;
    }

    /**
     * Search for contracts by specific attributes.
     * 
     * @param array $searchObj Search parameters for filtering contracts by attributes.
     * @return array The response data containing search results by attributes.
     */
    public function search_contract_by_attributes($searchObj = [])
    {
        $client = $this->Mint_Prod_Api_Client();
        try {
            // Send a POST request to search by contract attributes
            $response = $client->request('POST', 'tokens/search', [
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode($searchObj),
            ]);

            // Decode response into associative array
            $client_data = json_decode($response->getBody()->getContents(), true);
        } catch (BadResponseException $e) {
            // Handle error response
            $response = $e->getResponse();
            $client_data = json_decode($response->getBody()->getContents(), true);
        }
        return $client_data;
    }

    /**
     * Authorize MetaMask wallet for a given project.
     * 
     * @param string|null $project_id The project ID.
     * @param array $values Wallet data and other required information.
     * @param bool $disableCookie Whether to disable setting cookies (optional).
     * @return array The response data including authorization status.
     */
    public function getAuthorizeMetaMask($project_id = null, $values = [], $disableCookie = false)
    {
        $client = $this->Mint_Api_Client();
        $x_mint_key = $this->get_mintology_key();
        $client_data = [];
        $wallet_address = $values['wallet_address'];

        // Set cookies for wallet address and project ID if not disabled
        if ($wallet_address && !$disableCookie) {
            setcookie('wallet_address', base64_encode($wallet_address), time() + 60 * 60 * 24, '/');
            setcookie('project_id', base64_encode($project_id), time() + 60 * 60 * 24, '/');
        }

        try {
            // Send a POST request to authorize MetaMask wallet
            $response = $client->request('POST', "$project_id/authorize", [
                'headers' => [
                    'Accept'        => 'application/json',
                    'Content-Type'  => 'application/json',
                    'API-Key'       => $x_mint_key,
                ],
                'body' => json_encode($values),
            ]);

            // Decode response
            $client_data['response'] = json_decode($response->getBody()->getContents());
            $client_data['statusCode'] = $response->getStatusCode();
        } catch (BadResponseException $e) {
            // Handle error response
            $response = $e->getResponse();
            $client_data['statusCode'] = $response->getStatusCode();
        }

        return $client_data;
    }

    /**
     * Authorize mintable wallet based on the project and token.
     * 
     * @param string|null $project_id The project ID.
     * @param string $token Authorization token for mintable wallet.
     * @return array The response data including authorization details.
     */
    public function getAuthorizeMintable($project_id = null, $token = "")
    {
        $client = $this->Mint_Api_Client();
        $client_data = [];

        try {
            // Send a GET request to fetch mintable wallet address
            $response = $client->request('GET', '/mintable/wallet', [
                'headers' => [
                    'Accept'        => 'application/json',
                    'Content-Type'  => 'application/json',
                    'Authorization' => "Bearer $token",
                ]
            ]);

            // Decode response and get address if available
            $content = json_decode($response->getBody()->getContents());
            $address = isset($content->data->address) ? $content->data->address : '';

            if ($address) {
                // If wallet address exists, authorize the MetaMask wallet
                $client_data = $this->getAuthorizeMetaMask($project_id, [
                    'wallet_address' => $address,
                ]);
            }
        } catch (BadResponseException $e) {
            // Handle error response
            $response = $e->getResponse();
            $client_data['statusCode'] = $response->getStatusCode();
        }
        return $client_data;
    }

    /**
     * List projects with token and premint data, caching the result for performance.
     * 
     * @param bool $update Whether to force an update of the cached data (optional).
     * @return array The combined project data including tokens and premints.
     */
    public function list_projects_with_token_premint_data($update = false)
    {
        $x_mint_key = $this->get_mintology_key();
        $short_key = $this->generate_5_digit_key($x_mint_key);
        $transient_name = '__projects_cache_data_' . $short_key;

        if ($update == true) {
            // If update is true, fetch and update the project data
            $api_data = $this->get_and_update_project_to_cache();
        } else {
            // Try to get data from cache, if expired, fetch and update it
            $api_data = get_transient($transient_name);

            if ($api_data == false) {
                $api_data = $this->get_and_update_project_to_cache();
            }
        }

        return $api_data;
    }

    /**
     * Base62 encode a hexadecimal string.
     * 
     * @param string $hex The hexadecimal string to encode.
     * @return string The base62 encoded string.
     */
    public function base62_encode($hex)
    {
        $base62_chars = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
        $base62 = '';
        $num = hexdec($hex);

        // Convert hex to base62
        while ($num > 0) {
            $base62 = $base62_chars[$num % 62] . $base62;
            $num = floor($num / 62);
        }

        return $base62;
    }

    /**
     * Generate a 5-character key based on an input string.
     * 
     * @param string $input_key The input string to generate the key from.
     * @return string The generated 5-character key.
     */
    public function generate_5_digit_key($input_key)
    {
        // Step 1: Hash the input key using SHA-256
        $hash = hash('sha256', $input_key);
        $short_hash = substr($hash, 0, 6);

        // Step 2: Convert the short hash to a base62 string
        $base62_encoded = $this->base62_encode($short_hash);

        // Ensure the output is exactly 5 characters long
        return substr(str_pad($base62_encoded, 5, '0', STR_PAD_LEFT), 0, 5);
    }

    /**
     * Get and update project data, either from the cache or by making API requests.
     * 
     * @return array The combined data for all projects.
     */
    public function get_and_update_project_to_cache()
    {
        $x_mint_key = $this->get_mintology_key();
        if (empty($x_mint_key)) return;

        $short_key = $this->generate_5_digit_key($x_mint_key);
        $transient_name = '__projects_cache_data_' . $short_key;

        $client = new \GuzzleHttp\Client([
            'base_uri' => MINTOLOGY_API_BASE_URI,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'API-Key' => $x_mint_key,
            ],
        ]);

        // Fetch project data
        $projectsEndpoint = 'projects';
        $response = $client->get($projectsEndpoint);
        $projectsData = json_decode($response->getBody()->getContents(), true)['data'] ?? [];

        // Prepare concurrent requests for premints and token data
        $promises = [];
        foreach ($projectsData as $project) {
            $projectId = $project['project_id'];

            // Prepare async requests for premints and token data
            $premintsEndpoint = $projectId . '/premints';
            $promises['premints_' . $projectId] = $client->getAsync($premintsEndpoint);

            $tokenEndpoint = 'analytics/tokens/totals';
            $promises['token_' . $projectId] = $client->getAsync($tokenEndpoint, [
                'query' => ['projectId' => $projectId],
            ]);
        }

        // Wait for all promises to complete
        $responses = \GuzzleHttp\Promise\settle($promises)->wait();

        // Combine the data
        $combinedData = [];
        foreach ($projectsData as $project) {
            $projectId = $project['project_id'];

            // Add premints and token data to the project
            $project['premints'] = json_decode($responses['premints_' . $projectId]['value']->getBody()->getContents(), true);
            $project['token'] = json_decode($responses['token_' . $projectId]['value']->getBody()->getContents(), true);

            // Add the combined data to the result
            $combinedData[] = $project;
        }

        // Cache the combined data for 1 hour
        set_transient($transient_name, $combinedData, HOUR_IN_SECONDS);

        return $combinedData;
    }


}
