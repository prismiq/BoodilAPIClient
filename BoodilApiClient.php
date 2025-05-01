<?php

/**
 * Boodl API Client
 * Created By: Jason Parker <jason@weareprismic.com>
 * Date: 01-05-2025
 * Description: A simple PHP client for the Boodil API (API and Hosted Flows).
 * Version 1.3 - Updated Auth Scheme
 * Version 1.2 - Added Hosted Payment Pages API
 * Version 1.1 - Added Payment Initiation API (Widget Flow)
 * Version 1.0 - Initial version
 * Version 1.4 - Updated documentation and fixed typos
 * Licence: Non-commercial use only
 */

class BoodilApiClient
{

    private string $baseUrl;
    // Store all credentials provided
    private string $merchantUuid; // Still needed for request bodies/params
    private string $apiKey;       // Used for Basic Auth Username
    private string $apiSecret;    // Used for Basic Auth Password
    private int $timeout = 30; // Default timeout in seconds

    const SERVER_TEST = 'https://api-test.boodil.com/api/v1';
    const SERVER_PROD = 'https://api.boodil.com/api/v1';

    /**
     * Constructor for the Boodil API Client.
     *
     * @param string $merchantUuid Your Merchant UUID (required for request bodies/params).
     * @param string $apiKey Your API Key (used for Basic Auth username).
     * @param string $apiSecret Your API Secret (used for Basic Auth password).
     * @param string $serverUrl The base URL for the API (use SERVER_TEST or SERVER_PROD constants). Defaults to Test.
     */
    public function __construct(string $merchantUuid, string $apiKey, string $apiSecret, string $serverUrl = self::SERVER_TEST)
    {
        if (!extension_loaded('curl')) {
            throw new \Exception('The cURL PHP extension is required.');
        }
        // Validate inputs are not empty
        if (empty(trim($merchantUuid))) {
            throw new \InvalidArgumentException('Merchant UUID cannot be empty.');
        }
        if (empty(trim($apiKey))) {
            throw new \InvalidArgumentException('API Key cannot be empty.');
        }
        if (empty(trim($apiSecret))) {
            throw new \InvalidArgumentException('API Secret cannot be empty.');
        }

        $this->merchantUuid = $merchantUuid; // Store for potential use, though not directly in auth
        $this->apiKey = $apiKey;
        $this->apiSecret = $apiSecret;
        $this->baseUrl = rtrim($serverUrl, '/');
    }

    /**
     * Sets the timeout for cURL requests.
     *
     * @param int $seconds Timeout duration in seconds.
     */
    public function setTimeout(int $seconds): void
    {
        $this->timeout = $seconds;
    }

    /**
     * Internal method to execute API requests using cURL.
     *
     * @param string $method HTTP method (GET, POST).
     * @param string $endpoint API endpoint path (e.g., '/transactions').
     * @param array $queryParams Associative array of query parameters for GET requests.
     * @param array|null $body Associative array representing the JSON request body for POST requests.
     * @return array Decoded JSON response as an associative array.
     * @throws \Exception On cURL errors or non-successful API responses.
     * @throws \InvalidArgumentException On invalid input.
     */
    private function _request(string $method, string $endpoint, array $queryParams = [], ?array $body = null): array
    {
        $url = $this->baseUrl . $endpoint;
        if (!empty($queryParams)) {
            $url .= '?' . http_build_query($queryParams);
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC); // Handles Basic Auth
        // Use API Key as username and API Secret as password
        curl_setopt($ch, CURLOPT_USERPWD, "{$this->apiKey}:{$this->apiSecret}");

        // Build headers array
        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'User-Agent: Boodil-PHP-Client/1.2' // Updated User-Agent
        ];

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        switch (strtoupper($method)) {
            case 'POST':
                curl_setopt($ch, CURLOPT_POST, true);
                if ($body !== null) {
                    $jsonBody = json_encode($body);
                    if ($jsonBody === false) {
                        curl_close($ch);
                        throw new \InvalidArgumentException("Failed to encode request body as JSON: " . json_last_error_msg());
                    }
                    // Debug the actual JSON being sent
                    error_log("JSON body being sent: " . $jsonBody);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
                }
                break;
            case 'GET':
                // Default cURL method is GET
                break;
            default:
                curl_close($ch);
                throw new \InvalidArgumentException("Unsupported HTTP method: " . $method);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $errno = curl_errno($ch);
        curl_close($ch);

        if ($errno) {
            throw new \Exception("cURL Error ({$errno}): " . $error);
        }

        $decodedResponse = json_decode((string)$response, true);
        $jsonError = json_last_error();

        if ($jsonError !== JSON_ERROR_NONE) {
            // Handle cases where response is not valid JSON, but might still be an error
            if ($httpCode >= 400) {
                throw new \Exception("API Error: Received non-JSON response with HTTP status " . $httpCode . ". Response body: " . $response);
            } else {
                // Allow empty successful responses
                if ($httpCode >= 200 && $httpCode < 300 && trim((string)$response) === '') {
                    return [];
                }
                throw new \Exception("API Error: Failed to decode JSON response (" . json_last_error_msg() . "). Response body: " . $response);
            }
        }

        if ($httpCode >= 400) {
            // Try to get a meaningful error message from the response
            $errorMessage = 'API Error';
            if (isset($decodedResponse['message'])) {
                $errorMessage = $decodedResponse['message'];
            } elseif (isset($decodedResponse['error']['message'])) { // Check nested structure
                $errorMessage = $decodedResponse['error']['message'];
            } elseif (isset($decodedResponse['error'])) { // Sometimes error is just a string under 'error'
                $errorMessage = is_string($decodedResponse['error']) ? $decodedResponse['error'] : json_encode($decodedResponse['error']);
            } elseif (is_string($decodedResponse)) { // Sometimes error is just a string
                $errorMessage = $decodedResponse;
            }
            // Include response body in error for more context if possible
            $errorDetails = json_encode($decodedResponse);
            throw new \Exception("API Error: " . $errorMessage . " (HTTP " . $httpCode . ")" . ($errorDetails ? " Details: " . $errorDetails : ""));
        }

        // Ensure we always return an array, even if the response was empty or null
        return is_array($decodedResponse) ? $decodedResponse : [];
    }


    // --- Payment Initiation API (Widget Flow) Methods ---

    /**
     * [Widget Flow] Create Transaction: Initiates the Boodil widget process.
     * See API documentation for detailed field descriptions and requirements.
     *
     * @param array $transactionData Associative array matching the Transaction schema.
     *                                Required keys: merchantUuid, reference, amount, currency, redirectUrl.
     * @return array Response containing the transaction 'uuid'.
     * @throws \Exception On API or cURL error.
     * @throws \InvalidArgumentException If required keys are missing or invalid.
     */
    public function createTransaction(array $transactionData): array
    {
        $required = ['merchantUuid', 'reference', 'amount', 'currency', 'redirectUrl'];
        foreach ($required as $key) {
            if (!isset($transactionData[$key])) {
                throw new \InvalidArgumentException("[Widget Flow] Missing required key in transaction data: " . $key);
            }
        }
        if (!is_numeric($transactionData['amount'])) {
            throw new \InvalidArgumentException("[Widget Flow] Transaction 'amount' must be numeric.");
        }
        $transactionData['amount'] = (float)$transactionData['amount'];

        // Ensure the merchantUuid in the data matches the one configured for the client instance
        if ($transactionData['merchantUuid'] !== $this->merchantUuid) {
            // Optionally add a warning or error if they don't match, depending on expected usage
            // trigger_error("Warning: merchantUuid in transactionData does not match client configuration.", E_USER_WARNING);
        }


        return $this->_request('POST', '/transactions', [], $transactionData);
    }

    /**
     * [Widget Flow] Create Payment: Converts an authorized transaction into a payment.
     * This is called after the user returns from their bank via the redirectUrl.
     *
     * @param array $paymentData Associative array matching the Payment schema.
     *                           Required keys: merchantUuid, uuid (from transaction), consentToken (from redirect URL).
     * @return array Response containing payment details.
     * @throws \Exception On API or cURL error.
     * @throws \InvalidArgumentException If required keys are missing.
     */
    public function createPayment(array $paymentData): array
    {
        $required = ['merchantUuid', 'uuid', 'consentToken'];
        foreach ($required as $key) {
            if (!isset($paymentData[$key])) {
                throw new \InvalidArgumentException("[Widget Flow] Missing required key in payment data: " . $key);
            }
        }
        if ($paymentData['merchantUuid'] !== $this->merchantUuid) {
            // trigger_error("Warning: merchantUuid in paymentData does not match client configuration.", E_USER_WARNING);
        }

        // Debug the payment data being sent
        error_log("Payment data being sent to API: " . json_encode($paymentData));

        // IMPORTANT: Keep consentToken in the request body
        // According to the API documentation, we need to use the /payments endpoint
        // and include the consentToken in the request body

        return $this->_request('POST', '/payments', [], $paymentData);
    }

    /**
     * [Widget Flow] Complete Payment with Transaction UUID: Simplified version that takes transaction UUID and consent token.
     * This is a convenience method that builds the necessary request.
     * Use this after the user has completed the payment flow with the widget and is redirected back.
     * Note: consentToken must be included in the request body per API requirements.
     *
     * @param string $transactionUuid The UUID of the transaction from the createTransaction response.
     * @param string $consentToken The consent token returned from the bank authorization.
     * @return array Response containing payment details.
     * @throws \Exception On API or cURL error.
     */
    public function createPaymentWithUuid(string $transactionUuid, string $consentToken): array
    {
        if (empty(trim($transactionUuid))) {
            throw new \InvalidArgumentException("[Widget Flow] Transaction UUID cannot be empty.");
        }

        if (empty(trim($consentToken))) {
            throw new \InvalidArgumentException("[Widget Flow] Consent token cannot be empty.");
        }

        $paymentData = [
            'merchantUuid' => $this->merchantUuid,
            'uuid' => $transactionUuid,
            'consentToken' => $consentToken // This needs to be in the request body
        ];

        return $this->createPayment($paymentData);
    }

    /**
     * [Widget Flow] Get Transaction Status: Retrieves the status of a previously created transaction.
     *
     * @param string $uuid The UUID of the transaction.
     * @return array Response containing transaction status details.
     * @throws \Exception On API or cURL error.
     * @throws \InvalidArgumentException If UUID is empty.
     */
    public function getTransactionStatus(string $uuid): array
    {
        if (empty(trim($uuid))) {
            throw new \InvalidArgumentException("[Widget Flow] Transaction UUID cannot be empty.");
        }
        // Note: GET requests might not need merchantUuid in query params, depends on API design
        return $this->_request('GET', '/transactions/status', ['uuid' => $uuid]);
    }

    /**
     * [Widget Flow] Get Payment Status: Retrieves the status of a previously created payment.
     * Use this to check for final settlement status codes (e.g., ACCC, ACSC).
     *
     * @param string $uuid The UUID of the payment (same as the transaction UUID).
     * @return array Response containing payment status details.
     * @throws \Exception On API or cURL error.
     * @throws \InvalidArgumentException If UUID is empty.
     */
    public function getPaymentStatus(string $uuid): array
    {
        if (empty(trim($uuid))) {
            throw new \InvalidArgumentException("[Widget Flow] Payment UUID cannot be empty.");
        }
        return $this->_request('GET', '/payments/status', ['uuid' => $uuid]);
    }


    // --- Hosted Payment Pages API Methods ---

    /**
     * [Hosted Flow] Create Hosted Payment Request: Initiates a hosted payment page session.
     * Corresponds to POST /hosted/payment-request
     *
     * @param array $requestData Associative array matching the Hosted-Payment-Request schema.
     *                           Required keys: merchantUuid, reference, amount, currency, redirectUrl, country.
     *                           See example for optional fields like email, firstName, cart etc.
     * @return array Response containing 'uuid', 'hostedUrl', 'authToken', 'authorisationExpiresAt'.
     * @throws \Exception On API or cURL error.
     * @throws \InvalidArgumentException If required keys are missing or invalid.
     */
    public function createHostedPaymentRequest(array $requestData): array
    {
        $required = ['merchantUuid', 'reference', 'amount', 'currency', 'redirectUrl', 'country'];
        foreach ($required as $key) {
            if (!isset($requestData[$key])) {
                throw new \InvalidArgumentException("[Hosted Flow] Missing required key in hosted payment request data: " . $key);
            }
        }
        if (!is_numeric($requestData['amount'])) {
            throw new \InvalidArgumentException("[Hosted Flow] Hosted payment request 'amount' must be numeric.");
        }
        $requestData['amount'] = (float)$requestData['amount']; // Ensure float/decimal format if needed by API

        if (empty(trim($requestData['country'])) || strlen($requestData['country']) !== 2) {
            throw new \InvalidArgumentException("[Hosted Flow] Hosted payment request 'country' must be a 2-character string (e.g., 'GB', 'DE').");
        }
        if (isset($requestData['currency']) && strlen($requestData['currency']) !== 3) {
            throw new \InvalidArgumentException("[Hosted Flow] Hosted payment request 'currency' must be a 3-character string (e.g., 'GBP', 'EUR').");
        }

        // Optional fields validation (example for cart)
        if (isset($requestData['cart']) && !is_array($requestData['cart'])) {
            throw new \InvalidArgumentException("[Hosted Flow] Hosted payment request 'cart' must be an array if provided.");
        }

        // Ensure the merchantUuid in the data matches the one configured for the client instance
        if ($requestData['merchantUuid'] !== $this->merchantUuid) {
            // trigger_error("Warning: merchantUuid in requestData does not match client configuration.", E_USER_WARNING);
        }


        return $this->_request('POST', '/hosted/payment-request', [], $requestData);
    }

    /**
     * [Hosted Flow] Get Hosted Payment Status: Retrieves the status of a payment initiated via a hosted page.
     * Corresponds to GET /hosted/payments/status
     * This is typically called after the user returns to your redirectUrl.
     *
     * @param string $uuid The UUID returned from the createHostedPaymentRequest call.
     * @param string $paymentRequestId The paymentRequestId returned as a query parameter to your redirectUrl.
     * @return array Response containing payment status details (structure might vary, check API docs/response).
     * @throws \Exception On API or cURL error.
     * @throws \InvalidArgumentException If any parameter is empty.
     */
    // Updated signature: merchantUuid is now implicitly handled by auth, but needed in query params per API spec
    public function getHostedPaymentStatus(string $uuid, string $paymentRequestId): array
    {
        if (empty(trim($uuid))) {
            throw new \InvalidArgumentException("[Hosted Flow] Hosted payment UUID cannot be empty.");
        }
        // merchantUuid is now taken from the class property for the query param
        if (empty(trim($this->merchantUuid))) {
            // This should not happen if constructor validation works
            throw new \InvalidArgumentException("[Hosted Flow] Merchant UUID is not configured in the client.");
        }
        if (empty(trim($paymentRequestId))) {
            throw new \InvalidArgumentException("[Hosted Flow] Payment Request ID cannot be empty.");
        }

        $queryParams = [
            'uuid' => $uuid,
            'merchantUuid' => $this->merchantUuid, // Use the stored merchantUuid
            'paymentRequestId' => $paymentRequestId
        ];
        // Note: The endpoint in api-2.yaml is /hosted/payments/status
        // Ensure this matches the actual required endpoint.
        return $this->_request('GET', '/hosted/payments/status', $queryParams);
    }


    // --- Common Methods ---

    /**
     * Verify Merchant Credentials: Checks if the provided API credentials (used in constructor) are valid.
     * Works for both API types as it uses the correct authentication (API Key + Secret).
     * The merchantUuid query parameter is still required by the endpoint itself.
     *
     * @return array Response containing verification message (e.g., ['message' => 'Merchant is verified']).
     * @throws \Exception On API or cURL error (e.g., 401/403 if invalid, 404 if endpoint wrong).
     */
    // Updated signature: merchantUuid is now implicitly handled by auth, but needed in query params per API spec
    public function verifyMerchantCredentials(): array
    {
        // Note: Basic Auth (API Key + Secret) handles the actual authentication.
        // The query parameter confirms *which* merchant is being verified.
        if (empty(trim($this->merchantUuid))) {
            // This should not happen if constructor validation works
            throw new \InvalidArgumentException("Merchant UUID is not configured in the client for verification.");
        }
        return $this->_request('GET', '/merchants/verify', ['merchantUuid' => $this->merchantUuid]);
    }
} // End of class BoodilApiClient
