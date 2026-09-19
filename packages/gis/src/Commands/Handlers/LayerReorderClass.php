<?php

namespace Gis\Commands\Handlers;

use Gis\Commands\Command;
use Gis\Commands\CommandContext;
use Gis\Commands\CommandFailed;
use Gis\Commands\Effect;

/**
 * `layer.reorderClass` — a PLACEMENT command: move one sublayer among its
 * siblings.
 *
 * Sublayer order is both the order the rows read in and the order they paint
 * in, first on top (specification section 10). It is the one thing about a
 * classification a user arranges by hand, which is why it is worth a command
 * of its own rather than a rewrite through `layer.setClassification`: a drag
 * must not clobber a colour somebody else picked while it was happening.
 *
 * **Positions are named, never numbered.** `value` is the class being moved
 * and `before` is the class it must end up in front of, or null for the end of
 * the list. An index would be read against a list that may have been reordered
 * since the drag began, and would then move the wrong row — silently, because
 * every index in range is a plausible one.
 *
 * The `other` bucket is not in the list and cannot be moved. It is the absence
 * of a class rather than one of them, and it always paints last.
 */
class LayerReorderClass extends Command
{
    public static function op(): string
    {
        return 'layer.reorderClass';
    }

    public function authorize(CommandContext $context): bool
    {
        return $context->access->mayEditPlacement($this->resolveId($context));
    }

    public function apply(CommandContext $context): void
    {
        $id = $this->resolveId($context);
        $placement = $context->access->placementById($id);

        if ($placement === null) {
            throw CommandFailed::notFound('placement', $id);
        }

        $classification = $placement->classification;

        if (! is_array($classification) || ! isset($classification['classes'])) {
            throw CommandFailed::invalidProperty('This layer is not classified.');
        }

        $value = (string) $this->required('value');
        $before = $this->get('before');
        $before = $before === null ? null : (string) $before;

        $reordered = self::moved($classification['classes'], $value, $before);

        if ($reordered === null) {
            throw CommandFailed::invalidProperty('No such class: '.$value);
        }

        $classification['classes'] = $reordered;

        if (! $context->guardVersion('placement', $placement, $this->requiredInt('version'), ['classification'], $merged)) {
            return;
        }

        $placement->forceFill([
            'classification' => $classification,
            'version' => $placement->version + 1,
        ])->save();

        $effect = new Effect('placement', $placement->id, $placement->version, ['classification']);

        $context->record($merged ? $effect->asMerged() : $effect);
    }

    /**
     * The list with one class lifted out and put back before another.
     *
     * Returns null when the class being moved is not in the list. A `before`
     * that is not in the list means the end, which is what a drop past the
     * last row is — and what a concurrent removal of the target leaves behind.
     *
     * @param  array<int, array<string, mixed>>  $classes
     * @return array<int, array<string, mixed>>|null
     */
    protected static function moved(array $classes, string $value, ?string $before): ?array
    {
        $moving = null;
        $rest = [];

        foreach ($classes as $class) {
            if ((string) ($class['value'] ?? '') === $value) {
                $moving = $class;

                continue;
            }

            $rest[] = $class;
        }

        if ($moving === null) {
            return null;
        }

        if ($before === null || $before === $value) {
            $rest[] = $moving;

            return $rest;
        }

        $out = [];
        $placed = false;

        foreach ($rest as $class) {
            if (! $placed && (string) ($class['value'] ?? '') === $before) {
                $out[] = $moving;
                $placed = true;
            }

            $out[] = $class;
        }

        if (! $placed) {
            $out[] = $moving;
        }

        return $out;
    }
}
