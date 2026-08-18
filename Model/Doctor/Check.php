<?php
declare(strict_types=1);

namespace ParkkTech\FastMagento\Model\Doctor;

/**
 * One diagnostic result.
 *
 * Every FastMagento failure mode found in the field shares a shape: the storefront keeps
 * returning HTTP 200 while a feature is quietly off. A check therefore always carries a
 * remediation string — reporting a problem without saying what to do about it is what left
 * stores stuck in the first place.
 */
class Check
{
    public const OK = 'ok';
    public const WARN = 'warn';
    public const FAIL = 'fail';
    public const SKIP = 'skip';

    /**
     * @param string $status One of the class constants.
     * @param string $group Section heading, e.g. "Cluster".
     * @param string $title What was checked.
     * @param string $detail What was found.
     * @param string $fix What to do about it (empty when status is OK).
     */
    private function __construct(
        public readonly string $status,
        public readonly string $group,
        public readonly string $title,
        public readonly string $detail,
        public readonly string $fix = ''
    ) {
    }

    public static function ok(string $group, string $title, string $detail = ''): self
    {
        return new self(self::OK, $group, $title, $detail);
    }

    public static function warn(string $group, string $title, string $detail, string $fix = ''): self
    {
        return new self(self::WARN, $group, $title, $detail, $fix);
    }

    public static function fail(string $group, string $title, string $detail, string $fix = ''): self
    {
        return new self(self::FAIL, $group, $title, $detail, $fix);
    }

    public static function skip(string $group, string $title, string $detail = ''): self
    {
        return new self(self::SKIP, $group, $title, $detail);
    }

    public function isProblem(): bool
    {
        return $this->status === self::FAIL || $this->status === self::WARN;
    }
}
