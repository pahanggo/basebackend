<?php

namespace Gis\Support;

use RuntimeException;

/**
 * The service answered, and said no.
 *
 * Distinct from a transport failure on purpose: a refusal is deterministic and
 * usually caused by one unservable record, so it is worth bisecting the window
 * to find it. A timeout is not, and retrying is the whole answer there.
 */
class ServiceRefusedWindow extends RuntimeException {}
