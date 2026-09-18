<?php

namespace Gis\Support;

/**
 * A box the client already holds every feature for.
 *
 * A pan moves the viewport a little, and the padded area the client reads with
 * overlaps the one it read last time heavily — a quarter-viewport pan leaves 83%
 * of the new area already in memory. Refetching that is most of the response
 * for none of the information.
 *
 * So the client sends the box it already has, and the read leaves out anything
 * whose bounding box meets it. **Meets, not is contained by:** a feature
 * straddling the old boundary was returned by the old read, because that read
 * asked for everything intersecting its box too. Excluding on intersection is
 * what makes the two sets disjoint, and disjoint is what lets the client append
 * without deduplicating.
 *
 * The exclusion is only sound while the previous response was complete. A
 * capped one dropped features inside the box it claims to hold, and excluding
 * them here would lose them until the next zoom. The client does not send
 * `held` after a capped read.
 */
final readonly class Held
{
    public function __construct(
        public float $minx,
        public float $miny,
        public float $maxx,
        public float $maxy,
    ) {}
}
