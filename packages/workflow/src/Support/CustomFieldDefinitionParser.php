<?php

namespace Workflow\Support;

use Throwable;

/**
 * Safely evaluates the field-policy editor's "Custom field definition"
 * escape hatch — a designer-authored PHP array literal, e.g.
 * "['tab' => 'Details', 'options' => ['a' => 'A']]" — into a real PHP
 * array, without ever giving eval() a code path to anything but that
 * literal. Shared by FieldPolicyResolver (applies it at render time) and
 * WorkflowFieldDefinitionValidateController (checks it before the designer
 * saves).
 */
class CustomFieldDefinitionParser
{
    /**
     * Returns the decoded array, or null if $code is empty, not a pure
     * array-literal expression, or fails to evaluate / doesn't evaluate to
     * an array.
     */
    public function parse(string $code): ?array
    {
        $code = trim($code);

        if ($code === '' || ! $this->isLiteralArrayExpression($code)) {
            return null;
        }

        try {
            // @phpstan-ignore-next-line — eval() here is deliberate and
            // safe: isLiteralArrayExpression() above has already rejected
            // anything but array/string/number/true/false/null tokens.
            $value = eval("return {$code};");
        } catch (Throwable $e) {
            return null;
        }

        return is_array($value) ? $value : null;
    }

    /**
     * Same evaluation as parse(), but returns a human-readable reason for
     * rejection instead of silently discarding it — used to validate a
     * definition before the designer saves it. Returns null when $code is
     * empty (nothing to validate) or valid.
     */
    public function validate(string $code): ?string
    {
        $code = trim($code);

        if ($code === '') {
            return null;
        }

        if (! $this->isLiteralArrayExpression($code)) {
            return 'Only a literal PHP array is allowed — no function calls, variables, or string interpolation.';
        }

        try {
            $value = eval("return {$code};");
        } catch (Throwable $e) {
            return 'Not valid PHP: '.$e->getMessage();
        }

        if (! is_array($value)) {
            return "Must evaluate to an array, e.g. ['tab' => 'Details'].";
        }

        return null;
    }

    /**
     * Tokenizes $code and rejects it unless every token is one that can
     * only ever construct a literal array of scalars/nested arrays —
     * T_VARIABLE, function-name T_STRING, T_OBJECT_OPERATOR, string
     * interpolation, and everything else executable is disallowed. This is
     * what makes eval()-ing the result in parse()/validate() safe despite
     * the value originating from an admin-editable UI field.
     */
    protected function isLiteralArrayExpression(string $code): bool
    {
        $tokens = @token_get_all('<?php '.$code.';');

        if ($tokens === false) {
            return false;
        }

        $allowedIds = [
            T_ARRAY, T_DOUBLE_ARROW, T_CONSTANT_ENCAPSED_STRING, T_LNUMBER, T_DNUMBER,
            T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG,
        ];
        $allowedPunctuation = ['[', ']', '(', ')', ',', ';', '-', '+'];

        foreach ($tokens as $token) {
            if (is_string($token)) {
                if (! in_array($token, $allowedPunctuation, true)) {
                    return false;
                }

                continue;
            }

            [$id, $text] = $token;

            if ($id === T_STRING && in_array(strtolower($text), ['true', 'false', 'null'], true)) {
                continue;
            }

            if (! in_array($id, $allowedIds, true)) {
                return false;
            }
        }

        return true;
    }
}
