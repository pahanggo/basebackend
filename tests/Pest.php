<?php

use Tests\TestCase;

uses(TestCase::class)->in(
    'Feature',
    'Unit',
    '../packages/workflow/tests/Feature',
    '../packages/workflow/tests/Unit',
    '../packages/workflow-demo-purchase-request/tests/Feature',
);
