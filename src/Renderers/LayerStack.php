<?php

namespace Vistik\LaravelCodeAnalytics\Renderers;

use Countable;
use Vistik\LaravelCodeAnalytics\Enums\FileGroup;

readonly class LayerStack implements Countable
{
    /** @var CakeLayer[] */
    public array $layers;

    public function __construct(CakeLayer ...$layers)
    {
        $seenGroups = [];
        $seenColors = [];

        foreach ($layers as $layer) {
            $lowerColor = strtolower($layer->color);

            if (isset($seenColors[$lowerColor])) {
                throw new \InvalidArgumentException("Duplicate layer color: {$layer->color} (used by \"{$seenColors[$lowerColor]}\" and \"{$layer->label}\").");
            }

            $seenColors[$lowerColor] = $layer->label;

            foreach ($layer->groups as $group) {
                if (isset($seenGroups[$group->value])) {
                    throw new \InvalidArgumentException("FileGroup {$group->value} appears in both \"{$seenGroups[$group->value]}\" and \"{$layer->label}\".");
                }

                $seenGroups[$group->value] = $layer->label;
            }
        }

        $this->layers = $layers;
    }

    public static function default(): self
    {
        return new self(
            new CakeLayer('Entry', '#ffa657', [FileGroup::ROUTE, FileGroup::CONFIG]),
            new CakeLayer('Controllers', '#d29922', [FileGroup::CONTROLLER, FileGroup::HTTP, FileGroup::CONSOLE]),
            new CakeLayer('Requests / Resources', '#e3b341', [FileGroup::REQUEST]),
            new CakeLayer('Application', '#79c0ff', [FileGroup::SERVICE, FileGroup::ACTION, FileGroup::JOB, FileGroup::EVENT]),
            new CakeLayer('Domain', '#3fb950', [FileGroup::MODEL, FileGroup::CORE, FileGroup::NOVA]),
            new CakeLayer('Infrastructure', '#8957e5', [FileGroup::DB, FileGroup::PROVIDER]),
            new CakeLayer('Presentation', '#7ee787', [FileGroup::VIEW, FileGroup::FRONTEND]),
            new CakeLayer('Testing', '#58a6ff', [FileGroup::TEST, FileGroup::OTHER]),
        );
    }

    public static function fromConfig(): self
    {
        return config('analysis.layer_stack') ?? self::default();
    }

    public static function forMethodGraph(): self
    {
        return new self(
            new CakeLayer('Public', '#79c0ff', [FileGroup::VIS_PUBLIC]),
            new CakeLayer('Protected', '#e3b341', [FileGroup::VIS_PROTECTED]),
            new CakeLayer('Private', '#8957e5', [FileGroup::VIS_PRIVATE]),
            new CakeLayer('External', '#484f58', [FileGroup::VIS_EXTERNAL]),
        );
    }

    /**
     * @param  array{layers: array<array{label: string, color: string, groups: string[]}>}  $data
     */
    public static function fromArray(array $data): self
    {
        $layers = array_map(fn (array $layer) => new CakeLayer(
            label: $layer['label'],
            color: $layer['color'],
            groups: array_map(fn (string $g) => FileGroup::from($g), $layer['groups']),
        ), $data['layers']);

        return new self(...$layers);
    }

    /**
     * @return array{layers: array<array{label: string, color: string, groups: string[]}>}
     */
    public function toArray(): array
    {
        return [
            'layers' => array_map(fn (CakeLayer $layer) => [
                'label' => $layer->label,
                'color' => $layer->color,
                'groups' => array_map(fn (FileGroup $g) => $g->value, $layer->groups),
            ], $this->layers),
        ];
    }

    public function fallbackIndex(): int
    {
        return count($this->layers) - 1;
    }

    public function count(): int
    {
        return count($this->layers);
    }

    public function buildLayerMapJs(): string
    {
        $entries = [];
        foreach ($this->layers as $index => $layer) {
            foreach ($layer->groups as $group) {
                $value = $group->value;
                $label = json_encode($layer->label);
                $entries[] = "  {$value}: { layer: {$index}, label: {$label} }";
            }
        }

        return "{\n".implode(",\n", $entries)."\n}";
    }

    public function buildColorsJs(): string
    {
        $colors = array_map(
            fn (CakeLayer $layer) => '  '.json_encode($layer->color),
            $this->layers,
        );

        return "[\n".implode(",\n", $colors)."\n]";
    }

    public function buildLabelsJs(): string
    {
        $labels = array_map(
            fn (CakeLayer $layer) => '  '.json_encode($layer->label),
            $this->layers,
        );

        return "[\n".implode(",\n", $labels)."\n]";
    }
}
