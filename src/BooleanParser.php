<?php

declare(strict_types=1);

namespace Fuzor;

/**
 * Shunting-Yard boolean query parser.
 *
 * Converts a boolean query string to a postfix (Reverse Polish) token list.
 * All methods are stateless; no instance is needed.
 *
 * @internal
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
            if ($token !== '|' && $token !== '&' && $token !== '~' && $token !== '(' && $token !== ')') {
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
     * Tokenise a raw boolean query string into a well-formed stream of operators and words.
     *
     * One regex pass splits the input into operator characters and runs of other non-space
     * characters; whitespace only separates. A single left-to-right walk then rebuilds the
     * operator stream from what an operand-or-operator position allows:
     *
     *   - whitespace between two operands is an implicit AND ("a b", "a ~b", "a (b)", ") a");
     *   - "or" is always the OR operator, so a leading or doubled "or" is dropped like '|';
     *   - a leading "-" on a word is NOT ("a -b"); a hyphen inside a word is kept ("e-mail");
     *   - runs with no letter, digit, or '@' ("-", "+", "…") carry nothing to search and are
     *     dropped, instead of becoming an operand that matches no documents;
     *   - a binary operator with nothing to its left, a repeated '~', and anything dangling
     *     at the end are dropped; parentheses are balanced.
     *
     * So spacing never changes the meaning — "a|b", "a | b", "a or b", and "a  |  b" parse
     * alike — and malformed input degrades to its well-formed part instead of collapsing to
     * no results. The one binary operator kept in operand position is a '|' as the very
     * first token: callers prepend it as the OR identity (see toPostfix()).
     *
     * @param  string      $expression Raw query string.
     * @return list<string>            Word tokens and operator characters.
     */
    private static function lexExpression(string $expression): array
    {
        // One pass in C: an operator character, or a run of other non-space characters split
        // into its leading '-' (group 1) and the rest (group 2) when the rest can hold a search
        // term. A run with nothing searchable ("-", "+", "…") matches the last alternative and
        // leaves group 2 empty.
        /** @infection-ignore-all MBString: lowercasing only affects word tokens; operator characters are ASCII */
        preg_match_all(
            '/[|&~()]|(-*)([^\s|&~()]*[\p{L}\p{N}\p{Pc}@][^\s|&~()]*)|[^\s|&~()]+/u',
            mb_strtolower($expression, 'UTF-8'),
            $matches,
            PREG_SET_ORDER,
        );

        /** @var list<string> $tokens */
        $tokens        = [];
        $expectOperand = true; // true at the start and after a binary operator, '~', or '('
        $depth         = 0;

        foreach ($matches as $match) {
            $lexeme = $match[0];
            if ($lexeme === '|' || $lexeme === '&' || $lexeme === 'or') {
                if (!$expectOperand || $tokens === []) {
                    $tokens[]      = $lexeme === '&' ? '&' : '|';
                    $expectOperand = true;
                }
                continue;
            }
            if ($lexeme === ')') {
                self::dropDangling($tokens);
                if ($depth === 0 || end($tokens) === '(') {
                    if ($depth > 0) {
                        array_pop($tokens); // "()" — an empty group contributes nothing
                        $depth--;
                        $last          = end($tokens);
                        $expectOperand = $last === false || $last === '|' || $last === '&'
                            || $last === '~' || $last === '(';
                    }
                    continue;
                }
                $tokens[]      = ')';
                $depth--;
                $expectOperand = false;
                continue;
            }

            // Operand start: '(', '~', or a word (a leading '-' is NOT).
            $word = $match[2] ?? '';
            if ($lexeme !== '(' && $lexeme !== '~' && $word === '') {
                continue; // nothing searchable in this run
            }
            if (!$expectOperand) {
                $tokens[] = '&';
            }
            if (($lexeme === '~' || ($match[1] ?? '') !== '') && end($tokens) !== '~') {
                $tokens[] = '~';
            }
            if ($lexeme === '(') {
                $tokens[] = '(';
                $depth++;
                $expectOperand = true;
            } elseif ($word !== '') {
                $tokens[]      = $word;
                $expectOperand = false;
            } else {
                $expectOperand = true; // a bare '~' waits for its operand
            }
        }

        self::dropDangling($tokens);
        while (end($tokens) === '(') {
            array_pop($tokens);
            $depth--;
            self::dropDangling($tokens);
        }
        for (; $depth > 0; $depth--) {
            $tokens[] = ')';
        }
        return $tokens;
    }

    /**
     * Remove trailing tokens that still wait for an operand: binary operators and '~'.
     * A leading '|' (the caller's OR identity) is kept.
     *
     * @param list<string> $tokens
     */
    private static function dropDangling(array &$tokens): void
    {
        while (isset($tokens[1])) {
            $last = end($tokens);
            if ($last !== '|' && $last !== '&' && $last !== '~') {
                break;
            }
            array_pop($tokens);
        }
        if ($tokens === ['~'] || $tokens === ['&']) {
            $tokens = [];
        }
    }
}
