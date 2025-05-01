# Boodil PHP API Client

A lightweight PHP client for integrating with the [Boodil API](https://boodil.com) (API and Hosted Flows).

- **Author**: Jason Parker  
- **Email**: jason@weareprismic.com  
- **Version**: 1.4  
- **Date**: 01-05-2025  

## Features

- Connects to Boodil's test or production environment  
- Supports Payment Initiation (Widget Flow)  
- Supports Hosted Payment Pages  
- Includes methods for creating transactions, payments, and retrieving status  
- Merchant credential verification  

## Requirements

- PHP 8.0+  
- cURL extension enabled  

## Installation

Clone or download this repository and include the class in your project:

```php
require_once 'BoodilApiClient.php';
```

## Usage

```php
$client = new BoodilApiClient($merchantUuid, $apiKey, $apiSecret, BoodilApiClient::SERVER_PROD);

// Create a transaction
$response = $client->createTransaction([
    'merchantUuid' => $merchantUuid,
    'reference' => 'ORDER-123',
    'amount' => 100.00,
    'currency' => 'GBP',
    'redirectUrl' => 'https://yourdomain.com/return'
]);

// Create a payment using transaction UUID and consent token
$payment = $client->createPaymentWithUuid('transaction-uuid', 'consent-token');

// Get transaction status
$status = $client->getTransactionStatus('transaction-uuid');

// Verify merchant credentials
$verified = $client->verifyMerchantCredentials();
```

Refer to the class methods for full details and required fields.

## Licence

This project is released under the **Polyform Noncommercial License 1.0.0**.

You may use, modify, and share this code for **non-commercial purposes only**.  
**Commercial use is strictly prohibited** without written permission from the author.

To request commercial use, contact: [jason@weareprismic.com](mailto:jason@weareprismic.com)  
Full licence text: [https://polyformproject.org/licenses/noncommercial/1.0.0/](https://polyformproject.org/licenses/noncommercial/1.0.0/)
