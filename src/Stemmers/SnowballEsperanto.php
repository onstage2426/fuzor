<?php

namespace Fuzor\Stemmers;

// Generated from esperanto.sbl by Snowball 3.0.0 - https://snowballstem.org/

class SnowballEsperanto extends SnowballStemmer
{
    private const array A_0 = [
        ["", -1, 14],
        ["-", 0, 13],
        ["cx", 0, 1],
        ["gx", 0, 2],
        ["hx", 0, 3],
        ["jx", 0, 4],
        ["q", 0, 12],
        ["sx", 0, 5],
        ["ux", 0, 6],
        ["w", 0, 12],
        ["x", 0, 12],
        ["y", 0, 12],
        ["\u{00E1}", 0, 7],
        ["\u{00E9}", 0, 8],
        ["\u{00ED}", 0, 9],
        ["\u{00F3}", 0, 10],
        ["\u{00FA}", 0, 11]
    ];

    private const array A_1 = [
        ["as", -1, -1],
        ["i", -1, -1],
        ["is", 1, -1],
        ["os", -1, -1],
        ["u", -1, -1],
        ["us", 4, -1]
    ];

    private const array A_2 = [
        ["ci", -1, -1],
        ["gi", -1, -1],
        ["hi", -1, -1],
        ["li", -1, -1],
        ["ili", 3, -1],
        ["\u{015D}li", 3, -1],
        ["mi", -1, -1],
        ["ni", -1, -1],
        ["oni", 7, -1],
        ["ri", -1, -1],
        ["si", -1, -1],
        ["vi", -1, -1],
        ["ivi", 11, -1],
        ["\u{011D}i", -1, -1],
        ["\u{015D}i", -1, -1],
        ["i\u{015D}i", 14, -1],
        ["mal\u{015D}i", 14, -1]
    ];

    private const array A_3 = [
        ["amb", -1, -1],
        ["bald", -1, -1],
        ["malbald", 1, -1],
        ["morg", -1, -1],
        ["postmorg", 3, -1],
        ["adi", -1, -1],
        ["hodi", -1, -1],
        ["ank", -1, -1],
        ["\u{0109}irk", -1, -1],
        ["tut\u{0109}irk", 8, -1],
        ["presk", -1, -1],
        ["almen", -1, -1],
        ["apen", -1, -1],
        ["hier", -1, -1],
        ["anta\u{016D}hier", 13, -1],
        ["malgr", -1, -1],
        ["ankor", -1, -1],
        ["kontr", -1, -1],
        ["anstat", -1, -1],
        ["kvaz", -1, -1]
    ];

    private const array A_4 = [
        ["aliu", -1, -1],
        ["unu", -1, -1]
    ];

    private const array A_5 = [
        ["aha", -1, -1],
        ["haha", 0, -1],
        ["haleluja", -1, -1],
        ["hola", -1, -1],
        ["hosana", -1, -1],
        ["maltra", -1, -1],
        ["hura", -1, -1],
        ["\u{0125}a\u{0125}a", -1, -1],
        ["ekde", -1, -1],
        ["elde", -1, -1],
        ["disde", -1, -1],
        ["ehe", -1, -1],
        ["maltre", -1, -1],
        ["dirlididi", -1, -1],
        ["malpli", -1, -1],
        ["mal\u{0109}i", -1, -1],
        ["malkaj", -1, -1],
        ["amen", -1, -1],
        ["tamen", 17, -1],
        ["oho", -1, -1],
        ["maltro", -1, -1],
        ["minus", -1, -1],
        ["uhu", -1, -1],
        ["muu", -1, -1]
    ];

    private const array A_6 = [
        ["tri", -1, -1],
        ["du", -1, -1],
        ["unu", -1, -1]
    ];

    private const array A_7 = [
        ["dek", -1, -1],
        ["cent", -1, -1]
    ];

    private const array A_8 = [
        ["k", -1, -1],
        ["kelk", 0, -1],
        ["nen", -1, -1],
        ["t", -1, -1],
        ["mult", 3, -1],
        ["samt", 3, -1],
        ["\u{0109}", -1, -1]
    ];

    private const array A_9 = [
        ["a", -1, -1],
        ["e", -1, -1],
        ["i", -1, -1],
        ["j", -1, 1],
        ["aj", 3, -1],
        ["oj", 3, -1],
        ["n", -1, 1],
        ["an", 6, -1],
        ["en", 6, -1],
        ["jn", 6, 1],
        ["ajn", 9, -1],
        ["ojn", 9, -1],
        ["on", 6, -1],
        ["o", -1, -1],
        ["as", -1, -1],
        ["is", -1, -1],
        ["os", -1, -1],
        ["us", -1, -1],
        ["u", -1, -1]
    ];

    private const array G_vowel = ["a"=>true, "e"=>true, "i"=>true, "o"=>true, "u"=>true];

    private const array G_aou = ["a"=>true, "o"=>true, "u"=>true];

    private const array G_digit = ["0"=>true, "1"=>true, "2"=>true, "3"=>true, "4"=>true, "5"=>true, "6"=>true, "7"=>true, "8"=>true, "9"=>true];



    protected function r_canonical_form(): bool
    {
        $B_foreign = false;
        while (true) {
            $v_1 = $this->cursor;
            $this->bra = $this->cursor;
            $among_var = $this->find_among(self::A_0);
            $this->ket = $this->cursor;
            switch ($among_var) {
                case 1:
                    $this->slice_from("\u{0109}");
                    break;
                case 2:
                    $this->slice_from("\u{011D}");
                    break;
                case 3:
                    $this->slice_from("\u{0125}");
                    break;
                case 4:
                    $this->slice_from("\u{0135}");
                    break;
                case 5:
                    $this->slice_from("\u{015D}");
                    break;
                case 6:
                    $this->slice_from("\u{016D}");
                    break;
                case 7:
                    $this->slice_from("a");
                    $B_foreign = true;
                    break;
                case 8:
                    $this->slice_from("e");
                    $B_foreign = true;
                    break;
                case 9:
                    $this->slice_from("i");
                    $B_foreign = true;
                    break;
                case 10:
                    $this->slice_from("o");
                    $B_foreign = true;
                    break;
                case 11:
                    $this->slice_from("u");
                    $B_foreign = true;
                    break;
                case 12:
                    $B_foreign = true;
                    break;
                case 13:
                    $B_foreign = false;
                    break;
                case 14:
                    if ($this->cursor >= $this->limit) {
                        goto lab0;
                    }
                    $this->inc_cursor();
                    break;
            }
            continue;
        lab0:
            $this->cursor = $v_1;
            break;
        }
        return !$B_foreign;
    }


    protected function r_initial_apostrophe(): bool
    {
        $this->bra = $this->cursor;
        if (!($this->eq_s("'"))) {
            return false;
        }
        $this->ket = $this->cursor;
        if (!($this->eq_s("st"))) {
            return false;
        }
        if ($this->find_among(self::A_1) === 0) {
            return false;
        }
        if ($this->cursor < $this->limit) {
            return false;
        }
        $this->slice_from("e");
        return true;
    }


    protected function r_pronoun(): bool
    {
        $this->ket = $this->cursor;
        $v_1 = $this->limit - $this->cursor;
        if (!($this->eq_s_b("n"))) {
            $this->cursor = $this->limit - $v_1;
            goto lab0;
        }
    lab0:
        $this->bra = $this->cursor;
        if ($this->find_among_b(self::A_2) === 0) {
            return false;
        }
        $v_2 = $this->limit - $this->cursor;
        if ($this->cursor > $this->limit_backward) {
            goto lab1;
        }
        goto lab2;
    lab1:
        $this->cursor = $this->limit - $v_2;
        if (!($this->eq_s_b("-"))) {
            return false;
        }
    lab2:
        $this->slice_del();
        return true;
    }


    protected function r_final_apostrophe(): bool
    {
        $this->ket = $this->cursor;
        if (!($this->eq_s_b("'"))) {
            return false;
        }
        $this->bra = $this->cursor;
        $v_1 = $this->limit - $this->cursor;
        if (!($this->eq_s_b("l"))) {
            goto lab0;
        }
        if ($this->cursor > $this->limit_backward) {
            goto lab0;
        }
        $this->slice_from("a");
        goto lab1;
    lab0:
        $this->cursor = $this->limit - $v_1;
        if (!($this->eq_s_b("un"))) {
            goto lab2;
        }
        if ($this->cursor > $this->limit_backward) {
            goto lab2;
        }
        $this->slice_from("u");
        goto lab1;
    lab2:
        $this->cursor = $this->limit - $v_1;
        if ($this->find_among_b(self::A_3) === 0) {
            goto lab3;
        }
        $v_2 = $this->limit - $this->cursor;
        if ($this->cursor > $this->limit_backward) {
            goto lab4;
        }
        goto lab5;
    lab4:
        $this->cursor = $this->limit - $v_2;
        if (!($this->eq_s_b("-"))) {
            goto lab3;
        }
    lab5:
        $this->slice_from("a\u{016D}");
        goto lab1;
    lab3:
        $this->cursor = $this->limit - $v_1;
        $this->slice_from("o");
    lab1:
        return true;
    }


    protected function r_ujn_suffix(): bool
    {
        $this->ket = $this->cursor;
        $v_1 = $this->limit - $this->cursor;
        if (!($this->eq_s_b("n"))) {
            $this->cursor = $this->limit - $v_1;
            goto lab0;
        }
    lab0:
        $v_2 = $this->limit - $this->cursor;
        if (!($this->eq_s_b("j"))) {
            $this->cursor = $this->limit - $v_2;
            goto lab1;
        }
    lab1:
        $this->bra = $this->cursor;
        if ($this->find_among_b(self::A_4) === 0) {
            return false;
        }
        $v_3 = $this->limit - $this->cursor;
        if ($this->cursor > $this->limit_backward) {
            goto lab2;
        }
        goto lab3;
    lab2:
        $this->cursor = $this->limit - $v_3;
        if (!($this->eq_s_b("-"))) {
            return false;
        }
    lab3:
        $this->slice_del();
        return true;
    }


    protected function r_uninflected(): bool
    {
        if ($this->find_among_b(self::A_5) === 0) {
            return false;
        }
        $v_1 = $this->limit - $this->cursor;
        if ($this->cursor > $this->limit_backward) {
            goto lab0;
        }
        goto lab1;
    lab0:
        $this->cursor = $this->limit - $v_1;
        if (!($this->eq_s_b("-"))) {
            return false;
        }
    lab1:
        return true;
    }


    protected function r_merged_numeral(): bool
    {
        if ($this->find_among_b(self::A_6) === 0) {
            return false;
        }
        return $this->find_among_b(self::A_7) !== 0;
    }


    protected function r_correlative(): bool
    {
        $this->ket = $this->cursor;
        $this->bra = $this->cursor;
        $v_1 = $this->limit - $this->cursor;
        $v_2 = $this->limit - $this->cursor;
        $v_3 = $this->limit - $this->cursor;
        if (!($this->eq_s_b("n"))) {
            $this->cursor = $this->limit - $v_3;
            goto lab1;
        }
    lab1:
        $this->bra = $this->cursor;
        if (!($this->eq_s_b("e"))) {
            goto lab0;
        }
        goto lab2;
    lab0:
        $this->cursor = $this->limit - $v_2;
        $v_4 = $this->limit - $this->cursor;
        if (!($this->eq_s_b("n"))) {
            $this->cursor = $this->limit - $v_4;
            goto lab3;
        }
    lab3:
        $v_5 = $this->limit - $this->cursor;
        if (!($this->eq_s_b("j"))) {
            $this->cursor = $this->limit - $v_5;
            goto lab4;
        }
    lab4:
        $this->bra = $this->cursor;
        if (!($this->in_grouping_b(self::G_aou))) {
            return false;
        }
    lab2:
        if (!($this->eq_s_b("i"))) {
            return false;
        }
        $v_6 = $this->limit - $this->cursor;
        if ($this->find_among_b(self::A_8) === 0) {
            $this->cursor = $this->limit - $v_6;
            goto lab5;
        }
    lab5:
        $v_7 = $this->limit - $this->cursor;
        if ($this->cursor > $this->limit_backward) {
            goto lab6;
        }
        goto lab7;
    lab6:
        $this->cursor = $this->limit - $v_7;
        if (!($this->eq_s_b("-"))) {
            return false;
        }
    lab7:
        $this->cursor = $this->limit - $v_1;
        $this->slice_del();
        return true;
    }


    protected function r_long_word(): bool
    {
        $v_1 = $this->limit - $this->cursor;
        for ($v_2 = 2; $v_2 > 0; $v_2--) {
            if (!$this->go_out_grouping_b(self::G_vowel)) {
                goto lab0;
            }
            $this->dec_cursor();
        }
        goto lab1;
    lab0:
        $this->cursor = $this->limit - $v_1;
        while (true) {
            if (!($this->eq_s_b("-"))) {
                goto lab3;
            }
            break;
        lab3:
            if ($this->cursor <= $this->limit_backward) {
                goto lab2;
            }
            $this->dec_cursor();
        }
        if ($this->cursor <= $this->limit_backward) {
            goto lab2;
        }
        $this->dec_cursor();
        goto lab1;
    lab2:
        $this->cursor = $this->limit - $v_1;
        if (!$this->go_out_grouping_b(self::G_digit)) {
            return false;
        }
        $this->dec_cursor();
    lab1:
        return true;
    }


    protected function r_standard_suffix(): bool
    {
        $this->ket = $this->cursor;
        $among_var = $this->find_among_b(self::A_9);
        if (0 === $among_var) {
            return false;
        }
        switch ($among_var) {
            case 1:
                $v_1 = $this->limit - $this->cursor;
                $v_2 = $this->limit - $this->cursor;
                if (!($this->eq_s_b("-"))) {
                    goto lab0;
                }
                goto lab1;
            lab0:
                $this->cursor = $this->limit - $v_2;
                if (!($this->in_grouping_b(self::G_digit))) {
                    return false;
                }
            lab1:
                $this->cursor = $this->limit - $v_1;
                break;
        }
        $v_3 = $this->limit - $this->cursor;
        if (!($this->eq_s_b("-"))) {
            $this->cursor = $this->limit - $v_3;
            goto lab2;
        }
    lab2:
        $this->bra = $this->cursor;
        $this->slice_del();
        return true;
    }


    public function stem(): bool
    {
        $v_1 = $this->cursor;
        if (!$this->r_canonical_form()) {
            return false;
        }
        $this->cursor = $v_1;
        $v_2 = $this->cursor;
        $this->r_initial_apostrophe();
        $this->cursor = $v_2;
        $this->limit_backward = $this->cursor;
        $this->cursor = $this->limit;
        $v_3 = $this->limit - $this->cursor;
        if (!$this->r_pronoun()) {
            goto lab0;
        }
        return false;
    lab0:
        $this->cursor = $this->limit - $v_3;
        $v_4 = $this->limit - $this->cursor;
        $this->r_final_apostrophe();
        $this->cursor = $this->limit - $v_4;
        $v_5 = $this->limit - $this->cursor;
        if (!$this->r_correlative()) {
            goto lab1;
        }
        return false;
    lab1:
        $this->cursor = $this->limit - $v_5;
        $v_6 = $this->limit - $this->cursor;
        if (!$this->r_uninflected()) {
            goto lab2;
        }
        return false;
    lab2:
        $this->cursor = $this->limit - $v_6;
        $v_7 = $this->limit - $this->cursor;
        if (!$this->r_merged_numeral()) {
            goto lab3;
        }
        return false;
    lab3:
        $this->cursor = $this->limit - $v_7;
        $v_8 = $this->limit - $this->cursor;
        if (!$this->r_ujn_suffix()) {
            goto lab4;
        }
        return false;
    lab4:
        $this->cursor = $this->limit - $v_8;
        $v_9 = $this->limit - $this->cursor;
        if (!$this->r_long_word()) {
            return false;
        }
        $this->cursor = $this->limit - $v_9;
        if (!$this->r_standard_suffix()) {
            return false;
        }
        $this->cursor = $this->limit_backward;
        return true;
    }
}
