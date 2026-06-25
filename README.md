# Extendable Order and Payment Management API

## Project Overview

This project is a Laravel API for managing orders and payments.

It supports:

- User registration and login with JWT authentication
- Creating, viewing, updating, and deleting orders
- Processing payments for confirmed orders
- Listing all payments or payments for one order
- Adding new payment gateways with minimal code changes

The project is built to keep the payment logic easy to extend. Gateway classes are separated from the main payment service, and each gateway is configured through environment variables and `config/payments.php`.

## Requirements

Before you run the project, make sure you have:

- PHP 8.3 or newer
- Composer
- MySQL or another database supported by Laravel
- Node.js and npm

## Installation Steps

1. Clone the repository.
2. Open the project folder.
3. Install PHP packages:

```bash
composer install
```

4. Install frontend packages:

```bash
npm install
```

5. Copy the environment file:

```bash
cp .env.example .env
```

If you are using Windows PowerShell, use:

```powershell
Copy-Item .env.example .env
```

6. Generate the Laravel app key:

```bash
php artisan key:generate
```

## Environment Configuration

Update your `.env` file with your local values.

Important application values:

- `APP_URL`
- `DB_CONNECTION`
- `DB_HOST`
- `DB_PORT`
- `DB_DATABASE`
- `DB_USERNAME`
- `DB_PASSWORD`

Payment gateway values:

```env
CREDIT_CARD_API_KEY=test-credit-card-key
CREDIT_CARD_SECRET=test-credit-card-secret
CREDIT_CARD_SANDBOX=true

PAYPAL_CLIENT_ID=test-paypal-client-id
PAYPAL_CLIENT_SECRET=test-paypal-client-secret
PAYPAL_SANDBOX=true
```

JWT values:

- `JWT_SECRET`
- `JWT_ALGO`
- `JWT_TTL`
- `JWT_REFRESH_TTL`

## Database Migration

Run the migrations with:

```bash
php artisan migrate
```

If you want a fresh database:

```bash
php artisan migrate:fresh
```

## JWT Setup

Generate the JWT secret once after creating `.env`:

```bash
php artisan jwt:secret
```

This command writes the secret into your `.env` file.

## How to Run the API

Start the local server:

```bash
php artisan serve
```

The API will usually be available at:

```text
http://127.0.0.1:8000
```

If you want to build frontend assets too:

```bash
npm run build
```

## How to Run Tests

Run the full test suite:

```bash
php artisan test
```

The tests use an in-memory SQLite database through `phpunit.xml`, so they do not need your local MySQL database.

## API Endpoint Summary

All protected endpoints require this header:

```text
Authorization: Bearer {token}
Accept: application/json
```

### Authentication

| Method | Endpoint | Auth | Purpose |
| --- | --- | --- | --- |
| POST | `/api/auth/register` | No | Register a new user |
| POST | `/api/auth/login` | No | Login and get a JWT token |
| POST | `/api/auth/refresh` | No | Refresh the JWT token |
| GET | `/api/auth/me` | Yes | Get the authenticated user |
| POST | `/api/auth/logout` | Yes | Logout and invalidate the token |

### Orders

| Method | Endpoint | Auth | Purpose |
| --- | --- | --- | --- |
| GET | `/api/orders` | Yes | List user orders with pagination and optional status filter |
| POST | `/api/orders` | Yes | Create a new order |
| GET | `/api/orders/{id}` | Yes | Get one order |
| PATCH | `/api/orders/{id}` | Yes | Update an order |
| DELETE | `/api/orders/{id}` | Yes | Delete an order if it has no payments |

Order statuses:

- `pending`
- `confirmed`
- `cancelled`

### Payments

| Method | Endpoint | Auth | Purpose |
| --- | --- | --- | --- |
| GET | `/api/payment-methods` | Yes | List available payment methods |
| GET | `/api/payments` | Yes | List user payments with pagination and filters |
| GET | `/api/orders/{id}/payments` | Yes | List payments for one order |
| POST | `/api/orders/{id}/payments` | Yes | Process a payment for one order |

Payment statuses:

- `pending`
- `successful`
- `failed`

Payment methods currently available:

- `credit_card`
- `paypal`

## Architecture Explanation

The project is organized into clear layers:

- `routes/api.php`: defines the API endpoints
- `app/Http/Controllers/Api`: receives the request and returns the response
- `app/Http/Requests`: validates input data
- `app/Services`: contains business logic for orders and payments
- `app/Payments`: contains the gateway factory and payment result object
- `app/Payments/Gateways`: contains one class for each payment gateway
- `app/Models`: contains Eloquent models and relationships
- `app/Http/Resources`: formats API responses

Payment flow:

1. The client sends a payment request with a payment method.
2. `PaymentService` checks the business rules.
3. `PaymentGatewayFactory` reads the gateway definition from `config/payments.php`.
4. The factory resolves the correct gateway class and passes its config.
5. The gateway simulates the charge and returns a `PaymentResult`.
6. The payment record is updated and returned through `PaymentResource`.

This keeps the payment logic open for extension without changing the main service logic every time a new gateway is added.

## Database Design

The project uses these main tables:

### `users`

- Stores application users
- A user can have many orders

### `orders`

- Stores customer details for each order
- Fields include customer name, email, phone, status, and total
- An order belongs to one user
- An order has many items
- An order has many payments

### `order_items`

- Stores products inside an order
- Fields include product name, quantity, and price
- Each item belongs to one order

### `payments`

- Stores payment attempts for orders
- Fields include method, status, amount, transaction reference, and gateway response
- Each payment belongs to one order

Relationship summary:

- One user has many orders
- One order has many order items
- One order has many payments

## Business Rules

The API follows these rules:

- A new order starts with status `pending`
- Order total is calculated from the items, not from client input
- Payments can only be processed for orders with status `confirmed`
- Orders cannot be deleted if they have any associated payments
- An order with a successful payment cannot be paid again
- A successful payment prevents order updates
- Order items can only be changed while the order is still `pending`
- Users can only access their own orders and payments

## How to Add a Payment Gateway

The project is built so a new gateway can be added with small changes.

### Step 1: Create the gateway class

Create a new class inside `app/Payments/Gateways`.

Example:

```php
namespace App\Payments\Gateways;

use App\Contracts\PaymentGateway;
use App\Models\Order;
use App\Payments\PaymentResult;

class StripeGateway implements PaymentGateway
{
    public function __construct(
        private readonly array $config,
    ) {
    }

    public function charge(Order $order): PaymentResult
    {
        return new PaymentResult(
            successful: true,
            transactionReference: 'STRIPE-123',
            gatewayResponse: [
                'gateway' => 'stripe',
                'status' => 'approved',
                'amount' => (float) $order->total,
            ],
        );
    }
}
```

### Step 2: Register it in `config/payments.php`

```php
'stripe' => [
    'driver' => StripeGateway::class,
    'secret' => env('STRIPE_SECRET'),
    'sandbox' => env('STRIPE_SANDBOX', true),
],
```

### Step 3: Add the environment values

```env
STRIPE_SECRET=
STRIPE_SANDBOX=true
```

### Step 4: Clear cached config

```bash
php artisan config:clear
```

That is enough for the factory to discover the new gateway. You do not need to edit `PaymentGatewayFactory` again.

## Assumptions

- Payments are simulated. No real external payment API is called.
- `credit_card` is currently simulated as a successful payment.
- `paypal` is currently simulated as a failed payment.
- Gateway credentials are loaded from `.env` through `config/payments.php`.
- API responses are JSON only.
- Authentication uses JWT with the `api` guard.
- Pagination is supported for order and payment listing endpoints.

## Postman Import Instructions

If you have a Postman collection JSON file for this project, import it like this:

1. Open Postman.
2. Click `Import`.
3. Choose the collection JSON file.
4. Import the file.
5. Create or select an environment.
6. Set a variable like `base_url` to `http://127.0.0.1:8000`.
7. Run the register or login request first.
8. Copy the returned token.
9. Add the token to the collection or environment as a Bearer token.

If you do not have a collection file yet, you can still test the API manually by creating requests in Postman using the endpoint summary above.
