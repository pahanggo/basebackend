<?php

namespace Workflow\Support;

/**
 * Guards every `return_to` redirect target the workflow package accepts as
 * request input (WorkflowShowController seeds it from workflow_show.blade.php's
 * own $crud->route, but WorkflowTransitionController is a separate endpoint
 * anyone could POST to directly with an attacker-supplied value — both must
 * independently reject anything that isn't this same app, or a POST straight
 * to the transition endpoint would be a plain open-redirect vector).
 */
class SafeLocalUrl
{
    public static function resolve(?string $url): ?string
    {
        if (! $url) {
            return null;
        }

        return str_starts_with($url, url('/')) ? $url : null;
    }
}
