<?php

namespace Fuzor\Stemmers;

// Generated from arabic.sbl by Snowball 3.0.0 - https://snowballstem.org/

class SnowballArabic extends SnowballStemmer
{
    private const array A_0 = [
        ["\u{0640}", -1, 1],
        ["\u{064B}", -1, 1],
        ["\u{064C}", -1, 1],
        ["\u{064D}", -1, 1],
        ["\u{064E}", -1, 1],
        ["\u{064F}", -1, 1],
        ["\u{0650}", -1, 1],
        ["\u{0651}", -1, 1],
        ["\u{0652}", -1, 1],
        ["\u{0660}", -1, 2],
        ["\u{0661}", -1, 3],
        ["\u{0662}", -1, 4],
        ["\u{0663}", -1, 5],
        ["\u{0664}", -1, 6],
        ["\u{0665}", -1, 7],
        ["\u{0666}", -1, 8],
        ["\u{0667}", -1, 9],
        ["\u{0668}", -1, 10],
        ["\u{0669}", -1, 11],
        ["\u{FE80}", -1, 12],
        ["\u{FE81}", -1, 16],
        ["\u{FE82}", -1, 16],
        ["\u{FE83}", -1, 13],
        ["\u{FE84}", -1, 13],
        ["\u{FE85}", -1, 17],
        ["\u{FE86}", -1, 17],
        ["\u{FE87}", -1, 14],
        ["\u{FE88}", -1, 14],
        ["\u{FE89}", -1, 15],
        ["\u{FE8A}", -1, 15],
        ["\u{FE8B}", -1, 15],
        ["\u{FE8C}", -1, 15],
        ["\u{FE8D}", -1, 18],
        ["\u{FE8E}", -1, 18],
        ["\u{FE8F}", -1, 19],
        ["\u{FE90}", -1, 19],
        ["\u{FE91}", -1, 19],
        ["\u{FE92}", -1, 19],
        ["\u{FE93}", -1, 20],
        ["\u{FE94}", -1, 20],
        ["\u{FE95}", -1, 21],
        ["\u{FE96}", -1, 21],
        ["\u{FE97}", -1, 21],
        ["\u{FE98}", -1, 21],
        ["\u{FE99}", -1, 22],
        ["\u{FE9A}", -1, 22],
        ["\u{FE9B}", -1, 22],
        ["\u{FE9C}", -1, 22],
        ["\u{FE9D}", -1, 23],
        ["\u{FE9E}", -1, 23],
        ["\u{FE9F}", -1, 23],
        ["\u{FEA0}", -1, 23],
        ["\u{FEA1}", -1, 24],
        ["\u{FEA2}", -1, 24],
        ["\u{FEA3}", -1, 24],
        ["\u{FEA4}", -1, 24],
        ["\u{FEA5}", -1, 25],
        ["\u{FEA6}", -1, 25],
        ["\u{FEA7}", -1, 25],
        ["\u{FEA8}", -1, 25],
        ["\u{FEA9}", -1, 26],
        ["\u{FEAA}", -1, 26],
        ["\u{FEAB}", -1, 27],
        ["\u{FEAC}", -1, 27],
        ["\u{FEAD}", -1, 28],
        ["\u{FEAE}", -1, 28],
        ["\u{FEAF}", -1, 29],
        ["\u{FEB0}", -1, 29],
        ["\u{FEB1}", -1, 30],
        ["\u{FEB2}", -1, 30],
        ["\u{FEB3}", -1, 30],
        ["\u{FEB4}", -1, 30],
        ["\u{FEB5}", -1, 31],
        ["\u{FEB6}", -1, 31],
        ["\u{FEB7}", -1, 31],
        ["\u{FEB8}", -1, 31],
        ["\u{FEB9}", -1, 32],
        ["\u{FEBA}", -1, 32],
        ["\u{FEBB}", -1, 32],
        ["\u{FEBC}", -1, 32],
        ["\u{FEBD}", -1, 33],
        ["\u{FEBE}", -1, 33],
        ["\u{FEBF}", -1, 33],
        ["\u{FEC0}", -1, 33],
        ["\u{FEC1}", -1, 34],
        ["\u{FEC2}", -1, 34],
        ["\u{FEC3}", -1, 34],
        ["\u{FEC4}", -1, 34],
        ["\u{FEC5}", -1, 35],
        ["\u{FEC6}", -1, 35],
        ["\u{FEC7}", -1, 35],
        ["\u{FEC8}", -1, 35],
        ["\u{FEC9}", -1, 36],
        ["\u{FECA}", -1, 36],
        ["\u{FECB}", -1, 36],
        ["\u{FECC}", -1, 36],
        ["\u{FECD}", -1, 37],
        ["\u{FECE}", -1, 37],
        ["\u{FECF}", -1, 37],
        ["\u{FED0}", -1, 37],
        ["\u{FED1}", -1, 38],
        ["\u{FED2}", -1, 38],
        ["\u{FED3}", -1, 38],
        ["\u{FED4}", -1, 38],
        ["\u{FED5}", -1, 39],
        ["\u{FED6}", -1, 39],
        ["\u{FED7}", -1, 39],
        ["\u{FED8}", -1, 39],
        ["\u{FED9}", -1, 40],
        ["\u{FEDA}", -1, 40],
        ["\u{FEDB}", -1, 40],
        ["\u{FEDC}", -1, 40],
        ["\u{FEDD}", -1, 41],
        ["\u{FEDE}", -1, 41],
        ["\u{FEDF}", -1, 41],
        ["\u{FEE0}", -1, 41],
        ["\u{FEE1}", -1, 42],
        ["\u{FEE2}", -1, 42],
        ["\u{FEE3}", -1, 42],
        ["\u{FEE4}", -1, 42],
        ["\u{FEE5}", -1, 43],
        ["\u{FEE6}", -1, 43],
        ["\u{FEE7}", -1, 43],
        ["\u{FEE8}", -1, 43],
        ["\u{FEE9}", -1, 44],
        ["\u{FEEA}", -1, 44],
        ["\u{FEEB}", -1, 44],
        ["\u{FEEC}", -1, 44],
        ["\u{FEED}", -1, 45],
        ["\u{FEEE}", -1, 45],
        ["\u{FEEF}", -1, 46],
        ["\u{FEF0}", -1, 46],
        ["\u{FEF1}", -1, 47],
        ["\u{FEF2}", -1, 47],
        ["\u{FEF3}", -1, 47],
        ["\u{FEF4}", -1, 47],
        ["\u{FEF5}", -1, 51],
        ["\u{FEF6}", -1, 51],
        ["\u{FEF7}", -1, 49],
        ["\u{FEF8}", -1, 49],
        ["\u{FEF9}", -1, 50],
        ["\u{FEFA}", -1, 50],
        ["\u{FEFB}", -1, 48],
        ["\u{FEFC}", -1, 48]
    ];

    private const array A_1 = [
        ["\u{0622}", -1, 1],
        ["\u{0623}", -1, 1],
        ["\u{0624}", -1, 1],
        ["\u{0625}", -1, 1],
        ["\u{0626}", -1, 1]
    ];

    private const array A_2 = [
        ["\u{0622}", -1, 1],
        ["\u{0623}", -1, 1],
        ["\u{0624}", -1, 2],
        ["\u{0625}", -1, 1],
        ["\u{0626}", -1, 3]
    ];

    private const array A_3 = [
        ["\u{0627}\u{0644}", -1, 2],
        ["\u{0628}\u{0627}\u{0644}", -1, 1],
        ["\u{0643}\u{0627}\u{0644}", -1, 1],
        ["\u{0644}\u{0644}", -1, 2]
    ];

    private const array A_4 = [
        ["\u{0623}\u{0622}", -1, 2],
        ["\u{0623}\u{0623}", -1, 1],
        ["\u{0623}\u{0624}", -1, 1],
        ["\u{0623}\u{0625}", -1, 4],
        ["\u{0623}\u{0627}", -1, 3]
    ];

    private const array A_5 = [
        ["\u{0641}", -1, 1],
        ["\u{0648}", -1, 1]
    ];

    private const array A_6 = [
        ["\u{0627}\u{0644}", -1, 2],
        ["\u{0628}\u{0627}\u{0644}", -1, 1],
        ["\u{0643}\u{0627}\u{0644}", -1, 1],
        ["\u{0644}\u{0644}", -1, 2]
    ];

    private const array A_7 = [
        ["\u{0628}", -1, 1],
        ["\u{0628}\u{0627}", 0, -1],
        ["\u{0628}\u{0628}", 0, 2],
        ["\u{0643}\u{0643}", -1, 3]
    ];

    private const array A_8 = [
        ["\u{0633}\u{0623}", -1, 4],
        ["\u{0633}\u{062A}", -1, 2],
        ["\u{0633}\u{0646}", -1, 3],
        ["\u{0633}\u{064A}", -1, 1]
    ];

    private const array A_9 = [
        ["\u{062A}\u{0633}\u{062A}", -1, 1],
        ["\u{0646}\u{0633}\u{062A}", -1, 1],
        ["\u{064A}\u{0633}\u{062A}", -1, 1]
    ];

    private const array A_10 = [
        ["\u{0643}", -1, 1],
        ["\u{0643}\u{0645}", -1, 2],
        ["\u{0647}\u{0645}", -1, 2],
        ["\u{0647}\u{0646}", -1, 2],
        ["\u{0647}", -1, 1],
        ["\u{064A}", -1, 1],
        ["\u{0643}\u{0645}\u{0627}", -1, 3],
        ["\u{0647}\u{0645}\u{0627}", -1, 3],
        ["\u{0646}\u{0627}", -1, 2],
        ["\u{0647}\u{0627}", -1, 2]
    ];

    private const array A_11 = [
        ["\u{0648}", -1, 1],
        ["\u{064A}", -1, 1],
        ["\u{0627}", -1, 1]
    ];

    private const array A_12 = [
        ["\u{0643}", -1, 1],
        ["\u{0643}\u{0645}", -1, 2],
        ["\u{0647}\u{0645}", -1, 2],
        ["\u{0643}\u{0646}", -1, 2],
        ["\u{0647}\u{0646}", -1, 2],
        ["\u{0647}", -1, 1],
        ["\u{0643}\u{0645}\u{0648}", -1, 3],
        ["\u{0646}\u{064A}", -1, 2],
        ["\u{0643}\u{0645}\u{0627}", -1, 3],
        ["\u{0647}\u{0645}\u{0627}", -1, 3],
        ["\u{0646}\u{0627}", -1, 2],
        ["\u{0647}\u{0627}", -1, 2]
    ];

    private const array A_13 = [
        ["\u{0646}", -1, 1],
        ["\u{0648}\u{0646}", 0, 3],
        ["\u{064A}\u{0646}", 0, 3],
        ["\u{0627}\u{0646}", 0, 3],
        ["\u{062A}\u{0646}", 0, 2],
        ["\u{064A}", -1, 1],
        ["\u{0627}", -1, 1],
        ["\u{062A}\u{0645}\u{0627}", 6, 4],
        ["\u{0646}\u{0627}", 6, 2],
        ["\u{062A}\u{0627}", 6, 2],
        ["\u{062A}", -1, 1]
    ];

    private const array A_14 = [
        ["\u{062A}\u{0645}", -1, 1],
        ["\u{0648}\u{0627}", -1, 1]
    ];

    private const array A_15 = [
        ["\u{0648}", -1, 1],
        ["\u{062A}\u{0645}\u{0648}", 0, 2]
    ];

    private bool $B_is_defined = false;
    private bool $B_is_verb = false;
    private bool $B_is_noun = false;



    protected function r_Normalize_pre(): bool
    {
        $v_1 = $this->cursor;
        while (true) {
            $v_2 = $this->cursor;
            $v_3 = $this->cursor;
            $this->bra = $this->cursor;
            $among_var = $this->find_among(self::A_0);
            if (0 === $among_var) {
                goto lab2;
            }
            $this->ket = $this->cursor;
            switch ($among_var) {
                case 1:
                    $this->slice_del();
                    break;
                case 2:
                    $this->slice_from("0");
                    break;
                case 3:
                    $this->slice_from("1");
                    break;
                case 4:
                    $this->slice_from("2");
                    break;
                case 5:
                    $this->slice_from("3");
                    break;
                case 6:
                    $this->slice_from("4");
                    break;
                case 7:
                    $this->slice_from("5");
                    break;
                case 8:
                    $this->slice_from("6");
                    break;
                case 9:
                    $this->slice_from("7");
                    break;
                case 10:
                    $this->slice_from("8");
                    break;
                case 11:
                    $this->slice_from("9");
                    break;
                case 12:
                    $this->slice_from("\u{0621}");
                    break;
                case 13:
                    $this->slice_from("\u{0623}");
                    break;
                case 14:
                    $this->slice_from("\u{0625}");
                    break;
                case 15:
                    $this->slice_from("\u{0626}");
                    break;
                case 16:
                    $this->slice_from("\u{0622}");
                    break;
                case 17:
                    $this->slice_from("\u{0624}");
                    break;
                case 18:
                    $this->slice_from("\u{0627}");
                    break;
                case 19:
                    $this->slice_from("\u{0628}");
                    break;
                case 20:
                    $this->slice_from("\u{0629}");
                    break;
                case 21:
                    $this->slice_from("\u{062A}");
                    break;
                case 22:
                    $this->slice_from("\u{062B}");
                    break;
                case 23:
                    $this->slice_from("\u{062C}");
                    break;
                case 24:
                    $this->slice_from("\u{062D}");
                    break;
                case 25:
                    $this->slice_from("\u{062E}");
                    break;
                case 26:
                    $this->slice_from("\u{062F}");
                    break;
                case 27:
                    $this->slice_from("\u{0630}");
                    break;
                case 28:
                    $this->slice_from("\u{0631}");
                    break;
                case 29:
                    $this->slice_from("\u{0632}");
                    break;
                case 30:
                    $this->slice_from("\u{0633}");
                    break;
                case 31:
                    $this->slice_from("\u{0634}");
                    break;
                case 32:
                    $this->slice_from("\u{0635}");
                    break;
                case 33:
                    $this->slice_from("\u{0636}");
                    break;
                case 34:
                    $this->slice_from("\u{0637}");
                    break;
                case 35:
                    $this->slice_from("\u{0638}");
                    break;
                case 36:
                    $this->slice_from("\u{0639}");
                    break;
                case 37:
                    $this->slice_from("\u{063A}");
                    break;
                case 38:
                    $this->slice_from("\u{0641}");
                    break;
                case 39:
                    $this->slice_from("\u{0642}");
                    break;
                case 40:
                    $this->slice_from("\u{0643}");
                    break;
                case 41:
                    $this->slice_from("\u{0644}");
                    break;
                case 42:
                    $this->slice_from("\u{0645}");
                    break;
                case 43:
                    $this->slice_from("\u{0646}");
                    break;
                case 44:
                    $this->slice_from("\u{0647}");
                    break;
                case 45:
                    $this->slice_from("\u{0648}");
                    break;
                case 46:
                    $this->slice_from("\u{0649}");
                    break;
                case 47:
                    $this->slice_from("\u{064A}");
                    break;
                case 48:
                    $this->slice_from("\u{0644}\u{0627}");
                    break;
                case 49:
                    $this->slice_from("\u{0644}\u{0623}");
                    break;
                case 50:
                    $this->slice_from("\u{0644}\u{0625}");
                    break;
                case 51:
                    $this->slice_from("\u{0644}\u{0622}");
                    break;
            }
            goto lab3;
        lab2:
            $this->cursor = $v_3;
            if ($this->cursor >= $this->limit) {
                goto lab1;
            }
            $this->inc_cursor();
        lab3:
            continue;
        lab1:
            $this->cursor = $v_2;
            break;
        }
    lab0:
        $this->cursor = $v_1;
        return true;
    }


    protected function r_Normalize_post(): bool
    {
        $v_1 = $this->cursor;
        $this->limit_backward = $this->cursor;
        $this->cursor = $this->limit;
        $this->ket = $this->cursor;
        if ($this->find_among_b(self::A_1) === 0) {
            goto lab0;
        }
        $this->bra = $this->cursor;
        $this->slice_from("\u{0621}");
        $this->cursor = $this->limit_backward;
    lab0:
        $this->cursor = $v_1;
        $v_2 = $this->cursor;
        while (true) {
            $v_3 = $this->cursor;
            $v_4 = $this->cursor;
            $this->bra = $this->cursor;
            $among_var = $this->find_among(self::A_2);
            if (0 === $among_var) {
                goto lab3;
            }
            $this->ket = $this->cursor;
            switch ($among_var) {
                case 1:
                    $this->slice_from("\u{0627}");
                    break;
                case 2:
                    $this->slice_from("\u{0648}");
                    break;
                case 3:
                    $this->slice_from("\u{064A}");
                    break;
            }
            goto lab4;
        lab3:
            $this->cursor = $v_4;
            if ($this->cursor >= $this->limit) {
                goto lab2;
            }
            $this->inc_cursor();
        lab4:
            continue;
        lab2:
            $this->cursor = $v_3;
            break;
        }
    lab1:
        $this->cursor = $v_2;
        return true;
    }


    protected function r_Checks1(): bool
    {
        $this->bra = $this->cursor;
        $among_var = $this->find_among(self::A_3);
        if (0 === $among_var) {
            return false;
        }
        $this->ket = $this->cursor;
        switch ($among_var) {
            case 1:
                if (mb_strlen($this->current, 'UTF-8') <= 4) {
                    return false;
                }
                $this->B_is_noun = true;
                $this->B_is_verb = false;
                $this->B_is_defined = true;
                break;
            case 2:
                if (mb_strlen($this->current, 'UTF-8') <= 3) {
                    return false;
                }
                $this->B_is_noun = true;
                $this->B_is_verb = false;
                $this->B_is_defined = true;
                break;
        }
        return true;
    }


    protected function r_Prefix_Step1(): bool
    {
        $this->bra = $this->cursor;
        $among_var = $this->find_among(self::A_4);
        if (0 === $among_var) {
            return false;
        }
        $this->ket = $this->cursor;
        switch ($among_var) {
            case 1:
                if (mb_strlen($this->current, 'UTF-8') <= 3) {
                    return false;
                }
                $this->slice_from("\u{0623}");
                break;
            case 2:
                if (mb_strlen($this->current, 'UTF-8') <= 3) {
                    return false;
                }
                $this->slice_from("\u{0622}");
                break;
            case 3:
                if (mb_strlen($this->current, 'UTF-8') <= 3) {
                    return false;
                }
                $this->slice_from("\u{0627}");
                break;
            case 4:
                if (mb_strlen($this->current, 'UTF-8') <= 3) {
                    return false;
                }
                $this->slice_from("\u{0625}");
                break;
        }
        return true;
    }


    protected function r_Prefix_Step2(): bool
    {
        $this->bra = $this->cursor;
        if ($this->find_among(self::A_5) === 0) {
            return false;
        }
        $this->ket = $this->cursor;
        if (mb_strlen($this->current, 'UTF-8') <= 3) {
            return false;
        }
        $v_1 = $this->cursor;
        if (!($this->eq_s("\u{0627}"))) {
            goto lab0;
        }
        return false;
    lab0:
        $this->cursor = $v_1;
        $this->slice_del();
        return true;
    }


    protected function r_Prefix_Step3a_Noun(): bool
    {
        $this->bra = $this->cursor;
        $among_var = $this->find_among(self::A_6);
        if (0 === $among_var) {
            return false;
        }
        $this->ket = $this->cursor;
        switch ($among_var) {
            case 1:
                if (mb_strlen($this->current, 'UTF-8') <= 5) {
                    return false;
                }
                $this->slice_del();
                break;
            case 2:
                if (mb_strlen($this->current, 'UTF-8') <= 4) {
                    return false;
                }
                $this->slice_del();
                break;
        }
        return true;
    }


    protected function r_Prefix_Step3b_Noun(): bool
    {
        $this->bra = $this->cursor;
        $among_var = $this->find_among(self::A_7);
        if (0 === $among_var) {
            return false;
        }
        $this->ket = $this->cursor;
        switch ($among_var) {
            case 1:
                if (mb_strlen($this->current, 'UTF-8') <= 3) {
                    return false;
                }
                $this->slice_del();
                break;
            case 2:
                if (mb_strlen($this->current, 'UTF-8') <= 3) {
                    return false;
                }
                $this->slice_from("\u{0628}");
                break;
            case 3:
                if (mb_strlen($this->current, 'UTF-8') <= 3) {
                    return false;
                }
                $this->slice_from("\u{0643}");
                break;
        }
        return true;
    }


    protected function r_Prefix_Step3_Verb(): bool
    {
        $this->bra = $this->cursor;
        $among_var = $this->find_among(self::A_8);
        if (0 === $among_var) {
            return false;
        }
        $this->ket = $this->cursor;
        switch ($among_var) {
            case 1:
                if (mb_strlen($this->current, 'UTF-8') <= 4) {
                    return false;
                }
                $this->slice_from("\u{064A}");
                break;
            case 2:
                if (mb_strlen($this->current, 'UTF-8') <= 4) {
                    return false;
                }
                $this->slice_from("\u{062A}");
                break;
            case 3:
                if (mb_strlen($this->current, 'UTF-8') <= 4) {
                    return false;
                }
                $this->slice_from("\u{0646}");
                break;
            case 4:
                if (mb_strlen($this->current, 'UTF-8') <= 4) {
                    return false;
                }
                $this->slice_from("\u{0623}");
                break;
        }
        return true;
    }


    protected function r_Prefix_Step4_Verb(): bool
    {
        $this->bra = $this->cursor;
        if ($this->find_among(self::A_9) === 0) {
            return false;
        }
        $this->ket = $this->cursor;
        if (mb_strlen($this->current, 'UTF-8') <= 4) {
            return false;
        }
        $this->B_is_verb = true;
        $this->B_is_noun = false;
        $this->slice_from("\u{0627}\u{0633}\u{062A}");
        return true;
    }


    protected function r_Suffix_Noun_Step1a(): bool
    {
        $this->ket = $this->cursor;
        $among_var = $this->find_among_b(self::A_10);
        if (0 === $among_var) {
            return false;
        }
        $this->bra = $this->cursor;
        switch ($among_var) {
            case 1:
                if (mb_strlen($this->current, 'UTF-8') < 4) {
                    return false;
                }
                $this->slice_del();
                break;
            case 2:
                if (mb_strlen($this->current, 'UTF-8') < 5) {
                    return false;
                }
                $this->slice_del();
                break;
            case 3:
                if (mb_strlen($this->current, 'UTF-8') < 6) {
                    return false;
                }
                $this->slice_del();
                break;
        }
        return true;
    }


    protected function r_Suffix_Noun_Step1b(): bool
    {
        $this->ket = $this->cursor;
        if (!($this->eq_s_b("\u{0646}"))) {
            return false;
        }
        $this->bra = $this->cursor;
        if (mb_strlen($this->current, 'UTF-8') <= 5) {
            return false;
        }
        $this->slice_del();
        return true;
    }


    protected function r_Suffix_Noun_Step2a(): bool
    {
        $this->ket = $this->cursor;
        if ($this->find_among_b(self::A_11) === 0) {
            return false;
        }
        $this->bra = $this->cursor;
        if (mb_strlen($this->current, 'UTF-8') <= 4) {
            return false;
        }
        $this->slice_del();
        return true;
    }


    protected function r_Suffix_Noun_Step2b(): bool
    {
        $this->ket = $this->cursor;
        if (!($this->eq_s_b("\u{0627}\u{062A}"))) {
            return false;
        }
        $this->bra = $this->cursor;
        if (mb_strlen($this->current, 'UTF-8') < 5) {
            return false;
        }
        $this->slice_del();
        return true;
    }


    protected function r_Suffix_Noun_Step2c1(): bool
    {
        $this->ket = $this->cursor;
        if (!($this->eq_s_b("\u{062A}"))) {
            return false;
        }
        $this->bra = $this->cursor;
        if (mb_strlen($this->current, 'UTF-8') < 4) {
            return false;
        }
        $this->slice_del();
        return true;
    }


    protected function r_Suffix_Noun_Step2c2(): bool
    {
        $this->ket = $this->cursor;
        if (!($this->eq_s_b("\u{0629}"))) {
            return false;
        }
        $this->bra = $this->cursor;
        if (mb_strlen($this->current, 'UTF-8') < 4) {
            return false;
        }
        $this->slice_del();
        return true;
    }


    protected function r_Suffix_Noun_Step3(): bool
    {
        $this->ket = $this->cursor;
        if (!($this->eq_s_b("\u{064A}"))) {
            return false;
        }
        $this->bra = $this->cursor;
        if (mb_strlen($this->current, 'UTF-8') < 3) {
            return false;
        }
        $this->slice_del();
        return true;
    }


    protected function r_Suffix_Verb_Step1(): bool
    {
        $this->ket = $this->cursor;
        $among_var = $this->find_among_b(self::A_12);
        if (0 === $among_var) {
            return false;
        }
        $this->bra = $this->cursor;
        switch ($among_var) {
            case 1:
                if (mb_strlen($this->current, 'UTF-8') < 4) {
                    return false;
                }
                $this->slice_del();
                break;
            case 2:
                if (mb_strlen($this->current, 'UTF-8') < 5) {
                    return false;
                }
                $this->slice_del();
                break;
            case 3:
                if (mb_strlen($this->current, 'UTF-8') < 6) {
                    return false;
                }
                $this->slice_del();
                break;
        }
        return true;
    }


    protected function r_Suffix_Verb_Step2a(): bool
    {
        $this->ket = $this->cursor;
        $among_var = $this->find_among_b(self::A_13);
        if (0 === $among_var) {
            return false;
        }
        $this->bra = $this->cursor;
        switch ($among_var) {
            case 1:
                if (mb_strlen($this->current, 'UTF-8') < 4) {
                    return false;
                }
                $this->slice_del();
                break;
            case 2:
                if (mb_strlen($this->current, 'UTF-8') < 5) {
                    return false;
                }
                $this->slice_del();
                break;
            case 3:
                if (mb_strlen($this->current, 'UTF-8') <= 5) {
                    return false;
                }
                $this->slice_del();
                break;
            case 4:
                if (mb_strlen($this->current, 'UTF-8') < 6) {
                    return false;
                }
                $this->slice_del();
                break;
        }
        return true;
    }


    protected function r_Suffix_Verb_Step2b(): bool
    {
        $this->ket = $this->cursor;
        if ($this->find_among_b(self::A_14) === 0) {
            return false;
        }
        $this->bra = $this->cursor;
        if (mb_strlen($this->current, 'UTF-8') < 5) {
            return false;
        }
        $this->slice_del();
        return true;
    }


    protected function r_Suffix_Verb_Step2c(): bool
    {
        $this->ket = $this->cursor;
        $among_var = $this->find_among_b(self::A_15);
        if (0 === $among_var) {
            return false;
        }
        $this->bra = $this->cursor;
        switch ($among_var) {
            case 1:
                if (mb_strlen($this->current, 'UTF-8') < 4) {
                    return false;
                }
                $this->slice_del();
                break;
            case 2:
                if (mb_strlen($this->current, 'UTF-8') < 6) {
                    return false;
                }
                $this->slice_del();
                break;
        }
        return true;
    }


    protected function r_Suffix_All_alef_maqsura(): bool
    {
        $this->ket = $this->cursor;
        if (!($this->eq_s_b("\u{0649}"))) {
            return false;
        }
        $this->bra = $this->cursor;
        $this->slice_from("\u{064A}");
        return true;
    }


    public function stem(): bool
    {
        $this->B_is_noun = true;
        $this->B_is_verb = true;
        $this->B_is_defined = false;
        $v_1 = $this->cursor;
        $this->r_Checks1();
        $this->cursor = $v_1;
        $this->r_Normalize_pre();
        $this->limit_backward = $this->cursor;
        $this->cursor = $this->limit;
        $v_2 = $this->limit - $this->cursor;
        $v_3 = $this->limit - $this->cursor;
        if (!$this->B_is_verb) {
            goto lab1;
        }
        $v_4 = $this->limit - $this->cursor;
        $v_5 = 1;
        while (true) {
            $v_6 = $this->limit - $this->cursor;
            if (!$this->r_Suffix_Verb_Step1()) {
                goto lab3;
            }
            $v_5--;
            continue;
        lab3:
            $this->cursor = $this->limit - $v_6;
            break;
        }
        if ($v_5 > 0) {
            goto lab2;
        }
        $v_7 = $this->limit - $this->cursor;
        if (!$this->r_Suffix_Verb_Step2a()) {
            goto lab4;
        }
        goto lab5;
    lab4:
        $this->cursor = $this->limit - $v_7;
        if (!$this->r_Suffix_Verb_Step2c()) {
            goto lab6;
        }
        goto lab5;
    lab6:
        $this->cursor = $this->limit - $v_7;
        if ($this->cursor <= $this->limit_backward) {
            goto lab2;
        }
        $this->dec_cursor();
    lab5:
        goto lab7;
    lab2:
        $this->cursor = $this->limit - $v_4;
        if (!$this->r_Suffix_Verb_Step2b()) {
            goto lab8;
        }
        goto lab7;
    lab8:
        $this->cursor = $this->limit - $v_4;
        if (!$this->r_Suffix_Verb_Step2a()) {
            goto lab1;
        }
    lab7:
        goto lab9;
    lab1:
        $this->cursor = $this->limit - $v_3;
        if (!$this->B_is_noun) {
            goto lab10;
        }
        $v_8 = $this->limit - $this->cursor;
        $v_9 = $this->limit - $this->cursor;
        if (!$this->r_Suffix_Noun_Step2c2()) {
            goto lab12;
        }
        goto lab13;
    lab12:
        $this->cursor = $this->limit - $v_9;
        if ($this->B_is_defined) {
            goto lab14;
        }
        if (!$this->r_Suffix_Noun_Step1a()) {
            goto lab14;
        }
        $v_10 = $this->limit - $this->cursor;
        if (!$this->r_Suffix_Noun_Step2a()) {
            goto lab15;
        }
        goto lab16;
    lab15:
        $this->cursor = $this->limit - $v_10;
        if (!$this->r_Suffix_Noun_Step2b()) {
            goto lab17;
        }
        goto lab16;
    lab17:
        $this->cursor = $this->limit - $v_10;
        if (!$this->r_Suffix_Noun_Step2c1()) {
            goto lab18;
        }
        goto lab16;
    lab18:
        $this->cursor = $this->limit - $v_10;
        if ($this->cursor <= $this->limit_backward) {
            goto lab14;
        }
        $this->dec_cursor();
    lab16:
        goto lab13;
    lab14:
        $this->cursor = $this->limit - $v_9;
        if (!$this->r_Suffix_Noun_Step1b()) {
            goto lab19;
        }
        $v_11 = $this->limit - $this->cursor;
        if (!$this->r_Suffix_Noun_Step2a()) {
            goto lab20;
        }
        goto lab21;
    lab20:
        $this->cursor = $this->limit - $v_11;
        if (!$this->r_Suffix_Noun_Step2b()) {
            goto lab22;
        }
        goto lab21;
    lab22:
        $this->cursor = $this->limit - $v_11;
        if (!$this->r_Suffix_Noun_Step2c1()) {
            goto lab19;
        }
    lab21:
        goto lab13;
    lab19:
        $this->cursor = $this->limit - $v_9;
        if ($this->B_is_defined) {
            goto lab23;
        }
        if (!$this->r_Suffix_Noun_Step2a()) {
            goto lab23;
        }
        goto lab13;
    lab23:
        $this->cursor = $this->limit - $v_9;
        if (!$this->r_Suffix_Noun_Step2b()) {
            $this->cursor = $this->limit - $v_8;
            goto lab11;
        }
    lab13:
    lab11:
        if (!$this->r_Suffix_Noun_Step3()) {
            goto lab10;
        }
        goto lab9;
    lab10:
        $this->cursor = $this->limit - $v_3;
        if (!$this->r_Suffix_All_alef_maqsura()) {
            goto lab0;
        }
    lab9:
    lab0:
        $this->cursor = $this->limit - $v_2;
        $this->cursor = $this->limit_backward;
        $v_12 = $this->cursor;
        $v_13 = $this->cursor;
        if (!$this->r_Prefix_Step1()) {
            $this->cursor = $v_13;
            goto lab25;
        }
    lab25:
        $v_14 = $this->cursor;
        if (!$this->r_Prefix_Step2()) {
            $this->cursor = $v_14;
            goto lab26;
        }
    lab26:
        $v_15 = $this->cursor;
        if (!$this->r_Prefix_Step3a_Noun()) {
            goto lab27;
        }
        goto lab28;
    lab27:
        $this->cursor = $v_15;
        if (!$this->B_is_noun) {
            goto lab29;
        }
        if (!$this->r_Prefix_Step3b_Noun()) {
            goto lab29;
        }
        goto lab28;
    lab29:
        $this->cursor = $v_15;
        if (!$this->B_is_verb) {
            goto lab24;
        }
        $v_16 = $this->cursor;
        if (!$this->r_Prefix_Step3_Verb()) {
            $this->cursor = $v_16;
            goto lab30;
        }
    lab30:
        if (!$this->r_Prefix_Step4_Verb()) {
            goto lab24;
        }
    lab28:
    lab24:
        $this->cursor = $v_12;
        $this->r_Normalize_post();
        return true;
    }
}
