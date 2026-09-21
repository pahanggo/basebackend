<?php

namespace Backpack\CRUD\app\Exceptions;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AccessDeniedException extends Exception
{
    /**
     * Render the exception into an HTTP response.
     *
     * @param  Request
     * @return Response
     */
    public function render($request)
    {
        return response(view('errors.403', ['exception' => $this]), 403);
    }
}
