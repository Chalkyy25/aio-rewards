<?php

namespace App\Domain\Settings;

use App\Models\Setting;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\Cache;

/**
 * Read/write layer over the `settings` table with a single-key cache so
 * public pages can call `settings('brand.name')` without touching the DB
 * on every request. All writes bust the cache and are audited.
 */
class SettingsRepository
{
    private const CACHE_KEY = 'aio.settings.all';

    /** @return array<string, string> */
    public function all(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function () {
            try {
                return Setting::query()->pluck('value', 'key')->all();
            } catch (\Throwable) {
                // Table missing (e.g. fresh test DB before migrations) —
                // fall back to defaults so the app still renders.
                return [];
            }
        });
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $val = $this->all()[$key] ?? null;

        return ($val === null || $val === '') ? $default : $val;
    }

    public function put(string $key, ?string $value, ?User $actor = null): void
    {
        $row = Setting::updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'updated_by_user_id' => $actor?->getKey()],
        );
        Cache::forget(self::CACHE_KEY);

        AuditLogger::record(
            action: 'settings.updated',
            subject: $row,
            after: ['key' => $key],
            actor: $actor,
        );
    }

    /** @param array<string, ?string> $pairs */
    public function putMany(array $pairs, ?User $actor = null): void
    {
        foreach ($pairs as $k => $v) {
            Setting::updateOrCreate(
                ['key' => $k],
                ['value' => $v, 'updated_by_user_id' => $actor?->getKey()],
            );
        }
        Cache::forget(self::CACHE_KEY);
        AuditLogger::record(
            action: 'settings.bulk_updated',
            subject: null,
            after: ['keys' => array_keys($pairs)],
            actor: $actor,
        );
    }

    /**
     * Registry of every editable key + its default. Used to render the
     * settings admin page and to answer `default($key)`.
     *
     * @return array<string, array{group:string, label:string, default:string, textarea?:bool}>
     */
    public function schema(): array
    {
        return [
            // Branding
            'brand.name' => ['group' => 'branding', 'label' => 'Brand name', 'default' => 'AIO Rewards'],
            'brand.tagline' => ['group' => 'branding', 'label' => 'Tagline', 'default' => 'Rewarding the people who share what they love.'],
            'brand.support_email' => ['group' => 'branding', 'label' => 'Support email', 'default' => 'support@aio.example'],
            'brand.footer_note' => ['group' => 'branding', 'label' => 'Footer note', 'default' => '© AIO Media — all rights reserved.'],

            // Public content
            'public.landing_heading' => ['group' => 'public', 'label' => 'Landing heading', 'default' => 'Refer friends. Earn rewards.'],
            'public.landing_subheading' => ['group' => 'public', 'label' => 'Landing subheading', 'default' => 'Share your link, celebrate every sale, and cash out at every milestone.', 'textarea' => true],
            'public.packages_heading' => ['group' => 'public', 'label' => 'Packages page heading', 'default' => 'Choose your plan'],
            'public.packages_subheading' => ['group' => 'public', 'label' => 'Packages page subheading', 'default' => 'Simple, honest packages. Cancel anytime.', 'textarea' => true],

            // Customer order messages
            'orders.payment_received_lead' => ['group' => 'orders', 'label' => 'Payment received — lead line', 'default' => "We've received your payment. Your AIO Media order is being prepared.", 'textarea' => true],
            'orders.completed_lead' => ['group' => 'orders', 'label' => 'Order completed — lead line', 'default' => 'Your AIO Media access is ready.', 'textarea' => true],
            'orders.default_setup_instructions' => ['group' => 'orders', 'label' => 'Default setup instructions', 'default' => "1) Install the AIO Player from the download links below.\n2) Sign in with the credentials shown at the top.\n3) Tap Live TV and enjoy.", 'textarea' => true],
            'orders.security_reminder' => ['group' => 'orders', 'label' => 'Security reminder', 'default' => 'For your security your password is never sent by email. Save it somewhere safe from the page above.', 'textarea' => true],

            // Provider verification (Xtream) — Super Admin only. NEVER holds secrets.
            'provider.enabled' => ['group' => 'provider', 'label' => 'Enable verification', 'default' => '1'],
            'provider.display_name' => ['group' => 'provider', 'label' => 'Provider display name', 'default' => 'AIO Media'],
            'provider.xtream_dns_url' => ['group' => 'provider', 'label' => 'Xtream DNS URL', 'default' => ''],
            'provider.timeout_seconds' => ['group' => 'provider', 'label' => 'Connection timeout (seconds)', 'default' => '8'],
            'provider.active_status_values' => ['group' => 'provider', 'label' => 'Active status values (comma-separated)', 'default' => 'Active'],
        ];
    }

    public function default(string $key): ?string
    {
        return $this->schema()[$key]['default'] ?? null;
    }

    /**
     * Convenience: resolved value with default fallback.
     */
    public function value(string $key): ?string
    {
        return $this->get($key, $this->default($key));
    }
}
