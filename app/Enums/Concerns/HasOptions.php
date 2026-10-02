<?php

namespace App\Enums\Concerns;

trait HasOptions
{
    /**
     * Get the human readable label for the case.
     */
    abstract public function label(): string;

    /**
     * Get the case as a {value, label} pair for the frontend.
     *
     * @return array{value: string, label: string}
     */
    public function toOption(): array
    {
        return ['value' => $this->value, 'label' => $this->label()];
    }

    /**
     * Get every case as a {value, label} pair, e.g. for select inputs.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $case): array => $case->toOption(), self::cases());
    }
}
