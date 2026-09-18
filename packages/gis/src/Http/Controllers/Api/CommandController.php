<?php

namespace Gis\Http\Controllers\Api;

use Gis\Commands\CommandBatch;
use Gis\Commands\CommandFailed;
use Gis\Http\ProblemResponse;
use Gis\Models\Map;
use Gis\Support\MapAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;

/**
 * One endpoint for every mutation.
 *
 * There is deliberately no `PATCH /features/{id}`. A per-resource REST surface
 * would give undo nothing to record, sync nothing to queue and a bug report
 * nothing to replay; the command envelope is what makes all three the same
 * mechanism (specification section 7).
 *
 * The controller itself is thin on purpose — envelope in, problem document or
 * applied list out. Everything that decides anything is in `CommandBatch`,
 * which is where the transaction, the authorisation pass, the version guard and
 * the log live.
 */
class CommandController extends Controller
{
    public function __invoke(Request $request, Map $map): JsonResponse
    {
        $access = MapAccess::resolve($map, (int) $request->user()->getKey());

        if ($access === null) {
            return ProblemResponse::make(
                'command_unauthorized',
                403,
                'No access to this map',
                'You are not a member of this map.',
            );
        }

        try {
            // Validated, but the commands are then taken from the raw input:
            // `validate()` returns only the keys it was given rules for, and a
            // rule of `commands.*.op` would strip every command down to its op.
            // The payload shapes belong to the handlers, not to a rule list
            // that would be a second copy of the catalogue.
            $validated = $request->validate([
                'clientId' => ['required', 'string', 'max:64'],
                'seq' => ['required', 'integer', 'min:0'],
                'mapVersion' => ['sometimes', 'integer', 'min:0'],
                'commands' => ['required', 'array', 'min:1'],
                'commands.*.op' => ['required', 'string', 'max:64'],
            ]);
        } catch (ValidationException $e) {
            return ProblemResponse::make(
                'command_invalid',
                422,
                'Request envelope invalid',
                'The batch envelope is missing a required field.',
                ['errors' => $e->errors()],
            );
        }

        try {
            $response = (new CommandBatch)->apply(
                $access,
                (int) $request->user()->getKey(),
                $validated['clientId'],
                (int) $validated['seq'],
                (array) $request->input('commands'),
            );
        } catch (CommandFailed $failure) {
            return ProblemResponse::fromCommandFailure($failure);
        }

        return new JsonResponse($response);
    }
}
