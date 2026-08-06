<?php

namespace App\Domain\Provider\Drivers;

use App\Domain\Provider\Contracts\CustomerVerificationContract;
use App\Domain\Provider\DTOs\VerifyCustomerRequest;
use App\Domain\Provider\DTOs\VerifyCustomerResult;
use App\Domain\Provider\Enums\VerificationFailureReason;
use App\Domain\Provider\Exceptions\ProviderUnavailableException;
use App\Domain\Settings\SettingsRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;
use SensitiveParameter;
use Throwable;

/**
 * Standard Xtream Codes verification driver.
 *
 * Contract (Xtream Codes API):
 *   GET {dns}/player_api.php?username=X&password=Y
 *   200 { "user_info": { "auth": 0|1, "status": "Active" | ..., ... } }
 *
 * Rules:
 *   - `auth = 1` AND `status ∈ activeStatusValues` → eligible
 *   - `auth = 1` AND status not in whitelist → inactive
 *   - `auth = 0` (or missing user_info) → wrong_credentials
 *   - HTTP transport failure → ProviderUnavailableException
 *
 * The upstream response is NEVER returned to callers or persisted. Only
 * a typed VerifyCustomerResult (eligibility / reason / opaque customer ref)
 * escapes this class. Diagnostic data (HTTP status + timestamps) is written
 * to Settings via `recordProbe()` — never the credentials.
 */
class XtreamVerificationDriver implements CustomerVerificationContract
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly SettingsRepository $settings,
        private readonly string $dnsUrl,
        private readonly int $timeout = 8,
        /** @var list<string> */
        private readonly array $activeStatusValues = ['Active'],
    ) {}

    public function driverKey(): string
    {
        return 'xtream';
    }

    public function verifyActiveCustomer(VerifyCustomerRequest $request): VerifyCustomerResult
    {
        if ($this->dnsUrl === '') {
            throw new ProviderUnavailableException('Xtream DNS URL is not configured.');
        }

        $status = null;
        try {
            $response = $this->http
                ->timeout($this->timeout)
                ->connectTimeout(min(5, $this->timeout))
                ->retry(2, 250, throw: false)
                ->acceptJson()
                ->withoutRedirecting()
                ->get($this->endpoint(), [
                    'username' => $request->providerUsername,
                    'password' => $request->providerPassword,
                ]);
            $status = $response->status();
        } catch (ConnectionException $e) {
            $this->recordProbe(success: false, httpStatus: null, note: 'connection_error');
            throw new ProviderUnavailableException('Xtream DNS unreachable', 0, $e);
        } catch (Throwable $e) {
            // Never include the request body — the password would leak.
            Log::warning('Xtream verification unexpected failure', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            $this->recordProbe(success: false, httpStatus: null, note: 'transport_error');
            throw new ProviderUnavailableException('Xtream verification failed', 0, $e);
        }

        // The Xtream API is quirky: some panels return 200 with an empty
        // response for bad credentials, others return 401/403. Cover both.
        if (in_array($status, [401, 403], true)) {
            $this->recordProbe(success: true, httpStatus: $status, note: 'reject_wrong_credentials');

            return VerifyCustomerResult::reject(VerificationFailureReason::WrongCredentials);
        }

        if ($status === 404) {
            $this->recordProbe(success: true, httpStatus: $status, note: 'reject_not_found');

            return VerifyCustomerResult::reject(VerificationFailureReason::NotFound);
        }

        if (! $response->successful()) {
            $this->recordProbe(success: false, httpStatus: $status, note: 'upstream_error');
            throw new ProviderUnavailableException('Xtream verification returned '.$status);
        }

        $body = $response->json();
        if (! is_array($body)) {
            $body = [];
        }

        $userInfo = is_array($body['user_info'] ?? null) ? $body['user_info'] : null;
        $auth = (int) ($userInfo['auth'] ?? 0);
        $upstreamStatus = (string) ($userInfo['status'] ?? '');
        $customerRef = isset($userInfo['username']) ? (string) $userInfo['username'] : null;

        if ($userInfo === null || $auth !== 1) {
            $this->recordProbe(success: true, httpStatus: $status, note: 'reject_wrong_credentials');

            return VerifyCustomerResult::reject(VerificationFailureReason::WrongCredentials);
        }

        $whitelist = array_map('strtolower', $this->activeStatusValues);
        if (! in_array(strtolower($upstreamStatus), $whitelist, true)) {
            $this->recordProbe(success: true, httpStatus: $status, note: 'reject_inactive');

            return VerifyCustomerResult::reject(VerificationFailureReason::Inactive);
        }

        $this->recordProbe(success: true, httpStatus: $status, note: 'eligible');

        return VerifyCustomerResult::eligible($customerRef);
    }

    /**
     * Public probe for the "Test connection" admin action. Uses an
     * intentionally-invalid credential pair so the upstream cannot side-effect
     * on our account, but still returns a reachable HTTP status.
     *
     * @return array{ok: bool, http_status: ?int, note: string}
     */
    public function probeConnection(#[SensitiveParameter] string $probeUsername = 'aio_probe', #[SensitiveParameter] string $probePassword = 'aio_probe_pw'): array
    {
        if ($this->dnsUrl === '') {
            return ['ok' => false, 'http_status' => null, 'note' => 'no_dns_url_configured'];
        }
        try {
            $response = $this->http
                ->timeout($this->timeout)
                ->connectTimeout(min(5, $this->timeout))
                ->acceptJson()
                ->get($this->endpoint(), ['username' => $probeUsername, 'password' => $probePassword]);
        } catch (ConnectionException) {
            $this->recordProbe(success: false, httpStatus: null, note: 'connection_error');

            return ['ok' => false, 'http_status' => null, 'note' => 'unreachable'];
        } catch (Throwable) {
            $this->recordProbe(success: false, httpStatus: null, note: 'transport_error');

            return ['ok' => false, 'http_status' => null, 'note' => 'transport_error'];
        }

        // Reaching player_api.php at all means the DNS is up. A 2xx / 4xx
        // both count as "reachable"; only 5xx or connection error do not.
        $ok = $response->status() < 500;
        $this->recordProbe(success: $ok, httpStatus: $response->status(), note: $ok ? 'reachable' : 'upstream_5xx');

        return ['ok' => $ok, 'http_status' => $response->status(), 'note' => $ok ? 'reachable' : 'upstream_5xx'];
    }

    private function endpoint(): string
    {
        return rtrim($this->dnsUrl, '/').'/player_api.php';
    }

    private function recordProbe(bool $success, ?int $httpStatus, string $note): void
    {
        $this->settings->putMany([
            'provider.last_response_code' => $httpStatus !== null ? (string) $httpStatus : null,
            'provider.last_note' => $note,
            ($success ? 'provider.last_success_at' : 'provider.last_failure_at') => now()->toIso8601String(),
        ]);
    }
}
