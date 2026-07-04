<?php
declare(strict_types=1);

/**
 * OnboardingWizard — step state machine for the platform-admin "new tenant
 * onboarding" wizard (admin/tenant-onboard.php).
 *
 * Pure / DB-free: it knows the step order, per-step required fields, and how
 * to compute completion from a $progress array the caller loads/saves
 * (persisted as JSON by admin/tenant-onboard.php). No DB or I/O here so it is
 * trivially unit-testable.
 *
 * Steps:
 *   1. shop  — shop profile (name, contact)
 *   2. line  — first LINE OA channel credentials
 *   3. ai    — AI settings (Gemini key + toggles) — optional, can be skipped
 *   4. done  — summary / completion marker
 */
class OnboardingWizard
{
    /** Canonical step order. */
    public const STEPS = ['shop', 'line', 'ai', 'done'];

    /** Steps a tenant may explicitly skip (still marks progress, no validation). */
    private const SKIPPABLE_STEPS = ['ai'];

    /** Required $data keys per step for validate() to pass. */
    private const REQUIRED_FIELDS = [
        'shop' => ['shop_name'],
        'line' => ['channel_id', 'channel_secret', 'channel_access_token'],
        'ai'   => ['gemini_api_key'],
        'done' => [],
    ];

    /**
     * @param array<string,bool> $progress step key => completed. Missing keys
     *                                     are treated as not completed.
     */
    public function __construct(private array $progress = [])
    {
    }

    /** Build from a JSON string (as persisted); tolerant of null/invalid JSON. */
    public static function fromJson(?string $json): self
    {
        $decoded = $json !== null ? json_decode($json, true) : null;
        return new self(is_array($decoded) ? $decoded : []);
    }

    public function toJson(): string
    {
        return json_encode($this->progress, JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    /** @return array<int,string> */
    public function steps(): array
    {
        return self::STEPS;
    }

    public function isValidStep(string $step): bool
    {
        return in_array($step, self::STEPS, true);
    }

    public function isSkippable(string $step): bool
    {
        return in_array($step, self::SKIPPABLE_STEPS, true);
    }

    /** @return array<int,string> required field names for $step. */
    public function requiredFields(string $step): array
    {
        return self::REQUIRED_FIELDS[$step] ?? [];
    }

    /**
     * Validate submitted data for a step against its required fields.
     * Returns an array of missing field names (empty = valid).
     *
     * @param array<string,mixed> $data
     * @return array<int,string>
     */
    public function validate(string $step, array $data): array
    {
        $missing = [];
        foreach ($this->requiredFields($step) as $field) {
            $value = $data[$field] ?? null;
            if ($value === null || trim((string) $value) === '') {
                $missing[] = $field;
            }
        }
        return $missing;
    }

    public function isStepCompleted(string $step): bool
    {
        return (bool) ($this->progress[$step] ?? false);
    }

    /** Mark a step completed (validated) or skipped (skippable steps only). */
    public function markCompleted(string $step): self
    {
        if (!$this->isValidStep($step)) {
            throw new \InvalidArgumentException("Unknown onboarding step: {$step}");
        }
        $next = $this->progress;
        $next[$step] = true;
        return new self($next);
    }

    public function markSkipped(string $step): self
    {
        if (!$this->isSkippable($step)) {
            throw new \InvalidArgumentException("Step '{$step}' cannot be skipped");
        }
        $next = $this->progress;
        $next[$step] = true;
        $next[$step . '_skipped'] = true;
        return new self($next);
    }

    public function wasSkipped(string $step): bool
    {
        return (bool) ($this->progress[$step . '_skipped'] ?? false);
    }

    /**
     * First step that is not yet completed, in canonical order.
     * Returns null when every step is completed (wizard finished).
     */
    public function currentStep(): ?string
    {
        foreach (self::STEPS as $step) {
            if (!$this->isStepCompleted($step)) {
                return $step;
            }
        }
        return null;
    }

    /** True once every step (including 'done') is marked completed. */
    public function isFinished(): bool
    {
        return $this->currentStep() === null;
    }

    /** 0-100 completion percentage across all steps. */
    public function completionPercent(): int
    {
        $total = count(self::STEPS);
        if ($total === 0) {
            return 0;
        }
        $done = 0;
        foreach (self::STEPS as $step) {
            if ($this->isStepCompleted($step)) {
                $done++;
            }
        }
        return (int) round(($done / $total) * 100);
    }

    /**
     * Can $step be entered right now? A step is reachable once every step
     * before it (in canonical order) is completed. The first step is always
     * reachable.
     */
    public function canEnterStep(string $step): bool
    {
        if (!$this->isValidStep($step)) {
            return false;
        }
        foreach (self::STEPS as $s) {
            if ($s === $step) {
                return true;
            }
            if (!$this->isStepCompleted($s)) {
                return false;
            }
        }
        return false;
    }

    /** @return array<string,bool> raw progress map (for persistence). */
    public function toArray(): array
    {
        return $this->progress;
    }
}
