<?php

namespace Gis\Commands\Handlers;

use Gis\Commands\Command;
use Gis\Commands\CommandContext;
use Gis\Commands\CommandFailed;
use Gis\Commands\Effect;
use Gis\Support\Classification;
use InvalidArgumentException;

/**
 * `layer.setClassState` — a PLACEMENT command: one sublayer's visibility,
 * opacity or colours.
 *
 * This is what every checkbox, slider and colour swatch on a sublayer row
 * sends, so it is a patch of one class rather than a rewrite of the whole
 * document. Two people dimming two different categories should not collide,
 * and `layer.setClassification` rewriting all fourteen would guarantee they do.
 *
 * `value` identifies the class, not an index: a reconfiguration reorders the
 * list, and a command in flight across that would otherwise land on whichever
 * category moved into its slot. The literal string `other` addresses the
 * fallback bucket, which is why a class whose own value is `other` is stored in
 * the list and matched there first.
 *
 * **A class's opacity is not a second alpha.** Opacity already multiplies down
 * the tree — a group at 0.5 holding a layer at 0.5 paints at 0.25 — and a class
 * is one more level of that same chain, each level the only control on its own
 * object. What section 10 forbids is `style.fillOpacity`: a *second* control on
 * one object. Do not remove this one for looking like that one.
 */
class LayerSetClassState extends Command
{
    public static function op(): string
    {
        return 'layer.setClassState';
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

        $value = $this->required('value');

        if (! is_string($value) && ! is_numeric($value)) {
            throw CommandFailed::invalidProperty('value must be a string.');
        }

        $value = (string) $value;
        $patch = array_intersect_key($this->payload, array_flip(['label', 'visible', 'opacity', 'style']));

        try {
            $classification = self::patched($classification, $value, $patch);
        } catch (InvalidArgumentException $e) {
            throw CommandFailed::invalidProperty($e->getMessage());
        }

        if ($classification === null) {
            throw CommandFailed::invalidProperty('No such class: '.$value);
        }

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
     * The document with one class's state replaced, or null when there is no
     * such class.
     *
     * @param  array<string, mixed>  $classification
     * @param  array<string, mixed>  $patch
     * @return array<string, mixed>|null
     */
    protected static function patched(array $classification, string $value, array $patch): ?array
    {
        foreach ($classification['classes'] as $index => $class) {
            if ((string) ($class['value'] ?? '') !== $value) {
                continue;
            }

            $classification['classes'][$index] = ['value' => $class['value']]
                + Classification::state($patch, is_array($class) ? $class : []);

            return $classification;
        }

        if ($value === 'other') {
            $current = is_array($classification['other'] ?? null) ? $classification['other'] : [];
            $classification['other'] = Classification::state($patch, $current);

            return $classification;
        }

        return null;
    }
}
