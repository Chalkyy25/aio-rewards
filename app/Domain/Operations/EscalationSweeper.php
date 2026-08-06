<?php

namespace App\Domain\Operations;

use App\Domain\Settings\SettingsRepository;
use App\Enums\OperationsPriority;
use App\Models\OperationsItem;

/**
 * Bumps priority for items that have been sitting open past configurable
 * thresholds. Idempotent — an item is only escalated once per level.
 */
class EscalationSweeper
{
    public function __construct(
        private readonly OperationsWriter $writer,
        private readonly SettingsRepository $settings,
    ) {}

    /**
     * Sweep open items and escalate any that have crossed a level threshold.
     *
     * @return array{escalated:int}
     */
    public function sweep(): array
    {
        $level1After = (int) $this->threshold('ops.escalation.high_after_minutes', 30);
        $level2After = (int) $this->threshold('ops.escalation.critical_after_minutes', 60);

        $escalated = 0;

        $items = OperationsItem::query()
            ->whereIn('status', \App\Enums\OperationsStatus::openValues())
            ->get();

        foreach ($items as $item) {
            $ageMinutes = (int) $item->created_at->diffInMinutes(now());
            $currentLevel = (int) ($item->escalation_level ?? 0);

            // Level 1: age >= level1After AND current level = 0
            if ($currentLevel === 0 && $ageMinutes >= $level1After) {
                if ($item->priorityEnum() !== OperationsPriority::Critical) {
                    $this->writer->escalate($item, 'age exceeded '.$level1After.'m');
                    $escalated++;

                    continue;
                }
            }

            // Level 2: age >= level2After AND current level = 1
            if ($currentLevel === 1 && $ageMinutes >= $level2After) {
                if ($item->priorityEnum() !== OperationsPriority::Critical) {
                    $this->writer->escalate($item, 'age exceeded '.$level2After.'m');
                    $escalated++;
                }
            }
        }

        return ['escalated' => $escalated];
    }

    private function threshold(string $key, int $default): int
    {
        $v = $this->settings->value($key);

        return $v === null || $v === '' ? $default : (int) $v;
    }
}
