<?php

namespace App\Domain\Provider\Drivers;

use App\Domain\Provider\Contracts\CustomerVerificationContract;
use App\Domain\Provider\DTOs\VerifyCustomerRequest;
use App\Domain\Provider\DTOs\VerifyCustomerResult;
use App\Domain\Provider\Enums\VerificationFailureReason;
use App\Domain\Provider\Exceptions\ProviderUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Real upstream IPTV / VPN provider verification driver.
 *
 * This is a scaffold. The exact endpoint/payload shape depends on the
 * provider API contract — expected to be:
 *
 *   POST {url}/verify
 *   Authorization: Bearer {api_key}
 *   { "username": "...", "password": "..." }
 *
 *   200 { "active": true|false, "customer_ref": "abc123" }
 *   401 { "error": "wrong_credentials" }
 *   404 { "error": "not_found" }
 *
 * Adjust the JSON parsing when the provider contract is finalised in
 * production integration.
 */
class AioIptvVerificationDriver implements CustomerVerificationContract
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly string $url,
        private readonly string $apiKey,
        private readonly int $timeout = 8,
    ) {}

    public function verifyActiveCustomer(VerifyCustomerRequest $request): VerifyCustomerResult
    {
        try {
            $response = $this->http
                ->timeout($this->timeout)
                ->connectTimeout(min(5, $this->timeout))
                ->retry(3, 250, throw: false)
                ->acceptJson()
                ->withHeaders(['Authorization' => 'Bearer '.$this->apiKey])
                ->post(rtrim($this->url, '/').'/verify', [
                    'username' => $request->providerUsername,
                    'password' => $request->providerPassword,
                ]);
        } catch (ConnectionException $e) {
            throw new ProviderUnavailableException('Provider verification API unreachable', 0, $e);
        } catch (Throwable $e) {
            // Deliberately no request body in the log.
            Log::warning('Provider verification failed with unexpected exception', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            throw new ProviderUnavailableException('Provider verification failed', 0, $e);
        }

        if ($response->status() === 401 || $response->status() === 403) {
            return VerifyCustomerResult::reject(VerificationFailureReason::WrongCredentials);
        }

        if ($response->status() === 404) {
            return VerifyCustomerResult::reject(VerificationFailureReason::NotFound);
        }

        if (! $response->successful()) {
            throw new ProviderUnavailableException('Provider verification returned '.$response->status());
        }

        /** @var array<string, mixed> $body */
        $body = $response->json() ?? [];

        $active = (bool) ($body['active'] ?? false);
        if (! $active) {
            return VerifyCustomerResult::reject(VerificationFailureReason::Inactive);
        }

        return VerifyCustomerResult::eligible((string) ($body['customer_ref'] ?? ''));
    }

    public function driverKey(): string
    {
        return 'aio_iptv_v1';
    }
}
