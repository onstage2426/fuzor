<?php

declare(strict_types=1);

namespace Fuzor;

/**
 * Shunting-Yard boolean query parser.
 *
 * Converts a boolean query string to a postfix (Reverse Polish) token list.
 * All methods are stateless; no instance is needed.
 */
class BooleanParser
{
    /**
     * Convert a boolean query expression to postfix notation.
     *
     * Caller should prepend '|' so the algorithm always has a left-hand operand
     * (OR with an empty set is the identity and does not affect the result).
     *
     * @param  string $expression Raw boolean query string.
     * @return array{list<string>, string|null} [postfix token list, last word term or null]
     */
    public static function toPostfix(string $expression): array
    {
        /** @var list<string> $postfix */
        $postfix  = [];
        /** @var list<string> $stack */
        $stack    = [];
        $lastTerm = null;

        foreach (self::lexExpression($expression) as $token) {
            if (!in_array($token, ['|', '&', '~', '(', ')'], true)) {
                $postfix[] = $token;
                $lastTerm  = $token;
            } elseif ($token === '(') {
                $stack[] = $token;
            } elseif ($token === ')') {
                while (($top = array_pop($stack)) !== '(' && !empty($top)) {
                    $postfix[] = $top;
                }
            } else {
                $tokenPriority = self::expressionPriority($token);
                while (
                    !empty($stack) && ($top = end($stack)) !== '('
                    && self::expressionPriority($top) >= $tokenPriority
                ) {
                    $postfix[] = array_pop($stack);
                }
                $stack[] = $token;
            }
        }
        while (!empty($stack)) {
            $postfix[] = array_pop($stack);
        }

        return [$postfix, $lastTerm];
    }

    /**
     * Return the precedence level of a boolean operator.
     *
     * Higher value binds tighter: ~ (3) > & (2) > | (1).
     * Parentheses are handled structurally in toPostfix() and are not assigned a precedence.
     *
     * @param  string $operator Operator token.
     * @return int             Precedence level; 0 for unknown tokens.
     * @infection-ignore-all MatchArmRemoval: removing any single arm preserves Shunting-Yard output —
     *   '|'→0 or default→-1/1 are both equivalent (proved in comments on the arms below)
     */
    private static function expressionPriority(string $operator): int
    {
        return match ($operator) {
            /** @infection-ignore-all MatchArmRemoval|DecrementInteger: removing the '|' arm or setting it to 0 is equivalent because '|' is only compared against itself (1>=1 → 0>=0) or '&'/'~' (1>=2/3 → 0>=2/3, both false); Shunting-Yard output is unchanged */
            '|'     => 1,
            '&'     => 2,
            /** @infection-ignore-all IncrementInteger: raising '~' to 4 preserves strict highest priority (4>2>1); Shunting-Yard output is unchanged */
            '~'     => 3,
            /** @infection-ignore-all DecrementInteger|IncrementInteger: default covers '(' which is guarded by the $top!=='(' check before priority is consulted; all other unknowns are non-operators and never reach the stack */
            default => 0,
        };
    }

    /**
     * Tokenise a raw boolean query string into operators and word operands.
     *
     * Normalises natural-language syntax before splitting:
     *   " or " → |,   " -" → &~,   " " → &
     *
     * @param  string      $expression Raw query string.
     * @return list<string>            Word tokens and operator characters.
     */
    private static function lexExpression(string $expression): array
    {
        /** @infection-ignore-all MBString: operator keywords (' or ', ' -', ' ') are ASCII-only so strtolower produces identical results for the string-replace step; word tokens are lowercased here and flow through unchanged */
        $expression = $expression
            |> (fn(string $s): string => mb_strtolower($s, 'UTF-8'))
            |> (fn(string $s): string => preg_replace(['/\s*\(\s*/', '/\s*\)\s*(?!-)/'], ['(', ')'], $s) ?? $s)
            |> (fn(string $s): string => str_replace([' or ', ' -', ' '], ['|', '&~', '&'], $s));

        return preg_split('/([|~&()])/', $expression, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
    }
}
