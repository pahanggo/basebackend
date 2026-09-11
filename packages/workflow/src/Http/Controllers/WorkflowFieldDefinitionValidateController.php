<?php

namespace Workflow\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Workflow\Support\CustomFieldDefinitionParser;

/**
 * Validates the field-policy editor's "Custom field definition" PHP array
 * literals before the designer saves — so a disallowed construct or plain
 * typo is caught immediately instead of the definition silently being
 * ignored later at render time (see CustomFieldDefinitionParser).
 */
class WorkflowFieldDefinitionValidateController
{
    public function __invoke(Request $request, CustomFieldDefinitionParser $parser): JsonResponse
    {
        $definitions = (array) $request->input('definitions', []);
        $errors = [];

        foreach ($definitions as $index => $code) {
            $message = $parser->validate((string) $code);

            if ($message !== null) {
                $errors[$index] = $message;
            }
        }

        return response()->json(['errors' => $errors]);
    }
}
