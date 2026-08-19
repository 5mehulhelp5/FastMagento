<?php
declare(strict_types=1);

namespace ParkkTech\FastMagento\Model\Plp;

use Magento\Framework\FlagManager;

/**
 * Persistent record of PLP hydration falling back to the native EAV path.
 *
 * WHY THIS EXISTS. The fallback is deliberately silent to the shopper — the page still renders,
 * just via hundreds of MySQL queries instead of one OpenSearch fetch. Measured on a 12-product
 * category page: ~27 SELECTs hydrated vs ~286 fallen back (45 per-product stock-status reads,
 * 135 catalog_product_entity touches). Before this record existed, that degradation was invisible:
 * a store whose index had drifted behind the catalogue (typically a broken Magento cron) served
 * EVERY listing through the storm while `fastmagento:doctor` reported the LISTING group all green —
 * a real support case, found via a MagePsycho profiler trace. Doctor now reads this flag and fails
 * loudly with the reason.
 *
 * Write discipline: a fallback request is already expensive, so one small flag write adds nothing —
 * but writes are still throttled to once per THROTTLE_SECONDS so a traffic burst against a broken
 * index does not hammer the flag row. The count is therefore approximate (a floor, not a total);
 * recency and reason are what doctor diagnoses from. Recording must never break the page: callers
 * get a no-throw guarantee.
 */
class FallbackRecorder
{
    public const FLAG_CODE = 'fastmagento_plp_fallbacks';
    private const THROTTLE_SECONDS = 60;

    public function __construct(private readonly FlagManager $flagManager)
    {
    }

    /**
     * Record one fallback occurrence. No-throw.
     */
    public function record(string $reason): void
    {
        try {
            $now = time();
            $data = $this->flagManager->getFlagData(self::FLAG_CODE);
            $data = is_array($data) ? $data : [];

            $lastAt = (int) ($data['last_at'] ?? 0);
            if ($lastAt && ($now - $lastAt) < self::THROTTLE_SECONDS) {
                return; // throttled — the record already says "happening right now"
            }

            $this->flagManager->saveFlag(self::FLAG_CODE, [
                'count' => (int) ($data['count'] ?? 0) + 1,
                'first_at' => (int) ($data['first_at'] ?? $now),
                'last_at' => $now,
                'last_reason' => mb_substr($reason, 0, 500),
            ]);
        } catch (\Throwable $e) {
            // Never let bookkeeping break the storefront. The fallback itself is already logged.
        }
    }

    /**
     * @return array{count:int, first_at:int, last_at:int, last_reason:string}|null
     */
    public function read(): ?array
    {
        try {
            $data = $this->flagManager->getFlagData(self::FLAG_CODE);
        } catch (\Throwable $e) {
            return null;
        }
        if (!is_array($data) || empty($data['last_at'])) {
            return null;
        }
        return [
            'count' => (int) ($data['count'] ?? 0),
            'first_at' => (int) ($data['first_at'] ?? 0),
            'last_at' => (int) $data['last_at'],
            'last_reason' => (string) ($data['last_reason'] ?? ''),
        ];
    }

    /**
     * Clear the record (doctor --fix, after the operator has fixed the underlying cause).
     */
    public function clear(): void
    {
        try {
            $this->flagManager->deleteFlag(self::FLAG_CODE);
        } catch (\Throwable $e) {
            // nothing to do
        }
    }
}
