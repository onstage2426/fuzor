<?php

namespace Fuzor\Stemmers;

// Generated from yiddish.sbl by Snowball 3.0.0 - https://snowballstem.org/

class SnowballYiddish extends SnowballStemmer
{
    private const array A_0 = [
        ["\u{05D5}\u{05D5}", -1, 1],
        ["\u{05D5}\u{05D9}", -1, 2],
        ["\u{05D9}\u{05D9}", -1, 3],
        ["\u{05DA}", -1, 4],
        ["\u{05DD}", -1, 5],
        ["\u{05DF}", -1, 6],
        ["\u{05E3}", -1, 7],
        ["\u{05E5}", -1, 8]
    ];

    private const array A_1 = [
        ["\u{05D0}\u{05D3}\u{05D5}\u{05E8}\u{05DB}", -1, 1],
        ["\u{05D0}\u{05D4}\u{05D9}\u{05E0}", -1, 1],
        ["\u{05D0}\u{05D4}\u{05E2}\u{05E8}", -1, 1],
        ["\u{05D0}\u{05D4}\u{05F2}\u{05DE}", -1, 1],
        ["\u{05D0}\u{05D5}\u{05DE}", -1, 1],
        ["\u{05D0}\u{05D5}\u{05E0}\u{05D8}\u{05E2}\u{05E8}", -1, 1],
        ["\u{05D0}\u{05D9}\u{05D1}\u{05E2}\u{05E8}", -1, 1],
        ["\u{05D0}\u{05E0}", -1, 1],
        ["\u{05D0}\u{05E0}\u{05D8}", 7, 1],
        ["\u{05D0}\u{05E0}\u{05D8}\u{05E7}\u{05E2}\u{05D2}\u{05E0}", 8, 1],
        ["\u{05D0}\u{05E0}\u{05D9}\u{05D3}\u{05E2}\u{05E8}", 7, 1],
        ["\u{05D0}\u{05E4}", -1, 1],
        ["\u{05D0}\u{05E4}\u{05D9}\u{05E8}", 11, 1],
        ["\u{05D0}\u{05E7}\u{05E2}\u{05D2}\u{05E0}", -1, 1],
        ["\u{05D0}\u{05E8}\u{05D0}\u{05E4}", -1, 1],
        ["\u{05D0}\u{05E8}\u{05D5}\u{05DE}", -1, 1],
        ["\u{05D0}\u{05E8}\u{05D5}\u{05E0}\u{05D8}\u{05E2}\u{05E8}", -1, 1],
        ["\u{05D0}\u{05E8}\u{05D9}\u{05D1}\u{05E2}\u{05E8}", -1, 1],
        ["\u{05D0}\u{05E8}\u{05F1}\u{05E1}", -1, 1],
        ["\u{05D0}\u{05E8}\u{05F1}\u{05E4}", -1, 1],
        ["\u{05D0}\u{05E8}\u{05F2}\u{05E0}", -1, 1],
        ["\u{05D0}\u{05F0}\u{05E2}\u{05E7}", -1, 1],
        ["\u{05D0}\u{05F1}\u{05E1}", -1, 1],
        ["\u{05D0}\u{05F1}\u{05E4}", -1, 1],
        ["\u{05D0}\u{05F2}\u{05E0}", -1, 1],
        ["\u{05D1}\u{05D0}", -1, 1],
        ["\u{05D1}\u{05F2}", -1, 1],
        ["\u{05D3}\u{05D5}\u{05E8}\u{05DB}", -1, 1],
        ["\u{05D3}\u{05E2}\u{05E8}", -1, 1],
        ["\u{05DE}\u{05D9}\u{05D8}", -1, 1],
        ["\u{05E0}\u{05D0}\u{05DB}", -1, 1],
        ["\u{05E4}\u{05D0}\u{05E8}", -1, 1],
        ["\u{05E4}\u{05D0}\u{05E8}\u{05D1}\u{05F2}", 31, 1],
        ["\u{05E4}\u{05D0}\u{05E8}\u{05F1}\u{05E1}", 31, 1],
        ["\u{05E4}\u{05D5}\u{05E0}\u{05D0}\u{05E0}\u{05D3}\u{05E2}\u{05E8}", -1, 1],
        ["\u{05E6}\u{05D5}", -1, 1],
        ["\u{05E6}\u{05D5}\u{05D6}\u{05D0}\u{05DE}\u{05E2}\u{05E0}", 35, 1],
        ["\u{05E6}\u{05D5}\u{05E0}\u{05F1}\u{05E4}", 35, 1],
        ["\u{05E6}\u{05D5}\u{05E8}\u{05D9}\u{05E7}", 35, 1],
        ["\u{05E6}\u{05E2}", -1, 1]
    ];

    private const array A_2 = [
        ["\u{05D3}\u{05D6}\u{05E9}", -1, -1],
        ["\u{05E9}\u{05D8}\u{05E8}", -1, -1],
        ["\u{05E9}\u{05D8}\u{05E9}", -1, -1],
        ["\u{05E9}\u{05E4}\u{05E8}", -1, -1]
    ];

    private const array A_3 = [
        ["\u{05E7}\u{05DC}\u{05D9}\u{05D1}", -1, 9],
        ["\u{05E8}\u{05D9}\u{05D1}", -1, 10],
        ["\u{05D8}\u{05E8}\u{05D9}\u{05D1}", 1, 7],
        ["\u{05E9}\u{05E8}\u{05D9}\u{05D1}", 1, 15],
        ["\u{05D4}\u{05F1}\u{05D1}", -1, 23],
        ["\u{05E9}\u{05F0}\u{05D9}\u{05D2}", -1, 12],
        ["\u{05D2}\u{05D0}\u{05E0}\u{05D2}", -1, 1],
        ["\u{05D6}\u{05D5}\u{05E0}\u{05D2}", -1, 18],
        ["\u{05E9}\u{05DC}\u{05D5}\u{05E0}\u{05D2}", -1, 21],
        ["\u{05E6}\u{05F0}\u{05D5}\u{05E0}\u{05D2}", -1, 20],
        ["\u{05D1}\u{05F1}\u{05D2}", -1, 22],
        ["\u{05D1}\u{05D5}\u{05E0}\u{05D3}", -1, 16],
        ["\u{05F0}\u{05D9}\u{05D6}", -1, 6],
        ["\u{05D1}\u{05D9}\u{05D8}", -1, 4],
        ["\u{05DC}\u{05D9}\u{05D8}", -1, 8],
        ["\u{05DE}\u{05D9}\u{05D8}", -1, 3],
        ["\u{05E9}\u{05E0}\u{05D9}\u{05D8}", -1, 14],
        ["\u{05E0}\u{05D5}\u{05DE}", -1, 2],
        ["\u{05E9}\u{05D8}\u{05D0}\u{05E0}", -1, 25],
        ["\u{05D1}\u{05D9}\u{05E1}", -1, 5],
        ["\u{05E9}\u{05DE}\u{05D9}\u{05E1}", -1, 13],
        ["\u{05E8}\u{05D9}\u{05E1}", -1, 11],
        ["\u{05D8}\u{05E8}\u{05D5}\u{05E0}\u{05E7}", -1, 19],
        ["\u{05E4}\u{05D0}\u{05E8}\u{05DC}\u{05F1}\u{05E8}", -1, 24],
        ["\u{05E9}\u{05F0}\u{05F1}\u{05E8}", -1, 26],
        ["\u{05F0}\u{05D5}\u{05D8}\u{05E9}", -1, 17]
    ];

    private const array A_4 = [
        ["\u{05D5}\u{05E0}\u{05D2}", -1, 1],
        ["\u{05E1}\u{05D8}\u{05D5}", -1, 1],
        ["\u{05D8}", -1, 1],
        ["\u{05D1}\u{05E8}\u{05D0}\u{05DB}\u{05D8}", 2, 31],
        ["\u{05E1}\u{05D8}", 2, 1],
        ["\u{05D9}\u{05E1}\u{05D8}", 4, 33],
        ["\u{05E2}\u{05D8}", 2, 1],
        ["\u{05E9}\u{05D0}\u{05E4}\u{05D8}", 2, 1],
        ["\u{05D4}\u{05F2}\u{05D8}", 2, 1],
        ["\u{05E7}\u{05F2}\u{05D8}", 2, 1],
        ["\u{05D9}\u{05E7}\u{05F2}\u{05D8}", 9, 1],
        ["\u{05DC}\u{05E2}\u{05DB}", -1, 1],
        ["\u{05E2}\u{05DC}\u{05E2}\u{05DB}", 11, 1],
        ["\u{05D9}\u{05D6}\u{05DE}", -1, 1],
        ["\u{05D9}\u{05DE}", -1, 1],
        ["\u{05E2}\u{05DE}", -1, 1],
        ["\u{05E2}\u{05E0}\u{05E2}\u{05DE}", 15, 3],
        ["\u{05D8}\u{05E2}\u{05E0}\u{05E2}\u{05DE}", 16, 4],
        ["\u{05E0}", -1, 1],
        ["\u{05E7}\u{05DC}\u{05D9}\u{05D1}\u{05E0}", 18, 14],
        ["\u{05E8}\u{05D9}\u{05D1}\u{05E0}", 18, 15],
        ["\u{05D8}\u{05E8}\u{05D9}\u{05D1}\u{05E0}", 20, 12],
        ["\u{05E9}\u{05E8}\u{05D9}\u{05D1}\u{05E0}", 20, 7],
        ["\u{05D4}\u{05F1}\u{05D1}\u{05E0}", 18, 27],
        ["\u{05E9}\u{05F0}\u{05D9}\u{05D2}\u{05E0}", 18, 17],
        ["\u{05D6}\u{05D5}\u{05E0}\u{05D2}\u{05E0}", 18, 22],
        ["\u{05E9}\u{05DC}\u{05D5}\u{05E0}\u{05D2}\u{05E0}", 18, 25],
        ["\u{05E6}\u{05F0}\u{05D5}\u{05E0}\u{05D2}\u{05E0}", 18, 24],
        ["\u{05D1}\u{05F1}\u{05D2}\u{05E0}", 18, 26],
        ["\u{05D1}\u{05D5}\u{05E0}\u{05D3}\u{05E0}", 18, 20],
        ["\u{05F0}\u{05D9}\u{05D6}\u{05E0}", 18, 11],
        ["\u{05D8}\u{05E0}", 18, 4],
        ["GE\u{05D1}\u{05D9}\u{05D8}\u{05E0}", 31, 9],
        ["GE\u{05DC}\u{05D9}\u{05D8}\u{05E0}", 31, 13],
        ["GE\u{05DE}\u{05D9}\u{05D8}\u{05E0}", 31, 8],
        ["\u{05E9}\u{05E0}\u{05D9}\u{05D8}\u{05E0}", 31, 19],
        ["\u{05E1}\u{05D8}\u{05E0}", 31, 1],
        ["\u{05D9}\u{05E1}\u{05D8}\u{05E0}", 36, 1],
        ["\u{05E2}\u{05D8}\u{05E0}", 31, 1],
        ["GE\u{05D1}\u{05D9}\u{05E1}\u{05E0}", 18, 10],
        ["\u{05E9}\u{05DE}\u{05D9}\u{05E1}\u{05E0}", 18, 18],
        ["GE\u{05E8}\u{05D9}\u{05E1}\u{05E0}", 18, 16],
        ["\u{05E2}\u{05E0}", 18, 1],
        ["\u{05D2}\u{05D0}\u{05E0}\u{05D2}\u{05E2}\u{05E0}", 42, 5],
        ["\u{05E2}\u{05DC}\u{05E2}\u{05E0}", 42, 1],
        ["\u{05E0}\u{05D5}\u{05DE}\u{05E2}\u{05E0}", 42, 6],
        ["\u{05D9}\u{05D6}\u{05DE}\u{05E2}\u{05E0}", 42, 1],
        ["\u{05E9}\u{05D8}\u{05D0}\u{05E0}\u{05E2}\u{05E0}", 42, 29],
        ["\u{05D8}\u{05E8}\u{05D5}\u{05E0}\u{05E7}\u{05E0}", 18, 23],
        ["\u{05E4}\u{05D0}\u{05E8}\u{05DC}\u{05F1}\u{05E8}\u{05E0}", 18, 28],
        ["\u{05E9}\u{05F0}\u{05F1}\u{05E8}\u{05E0}", 18, 30],
        ["\u{05F0}\u{05D5}\u{05D8}\u{05E9}\u{05E0}", 18, 21],
        ["\u{05D2}\u{05F2}\u{05E0}", 18, 5],
        ["\u{05E1}", -1, 1],
        ["\u{05D8}\u{05E1}", 53, 4],
        ["\u{05E2}\u{05D8}\u{05E1}", 54, 1],
        ["\u{05E0}\u{05E1}", 53, 1],
        ["\u{05D8}\u{05E0}\u{05E1}", 56, 4],
        ["\u{05E2}\u{05E0}\u{05E1}", 56, 3],
        ["\u{05E2}\u{05E1}", 53, 1],
        ["\u{05D9}\u{05E2}\u{05E1}", 59, 2],
        ["\u{05E2}\u{05DC}\u{05E2}\u{05E1}", 59, 1],
        ["\u{05E2}\u{05E8}\u{05E1}", 53, 1],
        ["\u{05E2}\u{05E0}\u{05E2}\u{05E8}\u{05E1}", 62, 1],
        ["\u{05E2}", -1, 1],
        ["\u{05D8}\u{05E2}", 64, 4],
        ["\u{05E1}\u{05D8}\u{05E2}", 65, 1],
        ["\u{05E2}\u{05D8}\u{05E2}", 65, 1],
        ["\u{05D9}\u{05E2}", 64, -1],
        ["\u{05E2}\u{05DC}\u{05E2}", 64, 1],
        ["\u{05E2}\u{05E0}\u{05E2}", 64, 3],
        ["\u{05D8}\u{05E2}\u{05E0}\u{05E2}", 70, 4],
        ["\u{05E2}\u{05E8}", -1, 1],
        ["\u{05D8}\u{05E2}\u{05E8}", 72, 4],
        ["\u{05E1}\u{05D8}\u{05E2}\u{05E8}", 73, 1],
        ["\u{05E2}\u{05D8}\u{05E2}\u{05E8}", 73, 1],
        ["\u{05E2}\u{05E0}\u{05E2}\u{05E8}", 72, 3],
        ["\u{05D8}\u{05E2}\u{05E0}\u{05E2}\u{05E8}", 76, 4],
        ["\u{05D5}\u{05EA}", -1, 32]
    ];

    private const array A_5 = [
        ["\u{05D5}\u{05E0}\u{05D2}", -1, 1],
        ["\u{05E9}\u{05D0}\u{05E4}\u{05D8}", -1, 1],
        ["\u{05D4}\u{05F2}\u{05D8}", -1, 1],
        ["\u{05E7}\u{05F2}\u{05D8}", -1, 1],
        ["\u{05D9}\u{05E7}\u{05F2}\u{05D8}", 3, 1],
        ["\u{05DC}", -1, 2]
    ];

    private const array A_6 = [
        ["\u{05D9}\u{05D2}", -1, 1],
        ["\u{05D9}\u{05E7}", -1, 1],
        ["\u{05D3}\u{05D9}\u{05E7}", 1, 1],
        ["\u{05E0}\u{05D3}\u{05D9}\u{05E7}", 2, 1],
        ["\u{05E2}\u{05E0}\u{05D3}\u{05D9}\u{05E7}", 3, 1],
        ["\u{05D1}\u{05DC}\u{05D9}\u{05E7}", 1, -1],
        ["\u{05D2}\u{05DC}\u{05D9}\u{05E7}", 1, -1],
        ["\u{05E0}\u{05D9}\u{05E7}", 1, 1],
        ["\u{05D9}\u{05E9}", -1, 1]
    ];

    private const array G_niked = ["\u{05B0}"=>true, "\u{05B1}"=>true, "\u{05B2}"=>true, "\u{05B3}"=>true, "\u{05B4}"=>true, "\u{05B5}"=>true, "\u{05B6}"=>true, "\u{05B7}"=>true, "\u{05B8}"=>true, "\u{05B9}"=>true, "\u{05BB}"=>true, "\u{05BC}"=>true, "\u{05BF}"=>true, "\u{05C1}"=>true, "\u{05C2}"=>true];

    private const array G_vowel = ["\u{05D0}"=>true, "\u{05D5}"=>true, "\u{05D9}"=>true, "\u{05E2}"=>true, "\u{05F1}"=>true, "\u{05F2}"=>true];

    private const array G_consonant = ["\u{05D1}"=>true, "\u{05D2}"=>true, "\u{05D3}"=>true, "\u{05D4}"=>true, "\u{05D6}"=>true, "\u{05D7}"=>true, "\u{05D8}"=>true, "\u{05DA}"=>true, "\u{05DB}"=>true, "\u{05DC}"=>true, "\u{05DD}"=>true, "\u{05DE}"=>true, "\u{05DF}"=>true, "\u{05E0}"=>true, "\u{05E1}"=>true, "\u{05E3}"=>true, "\u{05E4}"=>true, "\u{05E5}"=>true, "\u{05E6}"=>true, "\u{05E7}"=>true, "\u{05E8}"=>true, "\u{05E9}"=>true, "\u{05EA}"=>true, "\u{05F0}"=>true];

    private int $I_p1 = 0;



    protected function r_prelude(): bool
    {
        $v_1 = $this->cursor;
        while (true) {
            $v_2 = $this->cursor;
            while (true) {
                $v_3 = $this->cursor;
                $this->bra = $this->cursor;
                $among_var = $this->find_among(self::A_0);
                if (0 === $among_var) {
                    goto lab2;
                }
                $this->ket = $this->cursor;
                switch ($among_var) {
                    case 1:
                        $v_4 = $this->cursor;
                        if (!($this->eq_s("\u{05BC}"))) {
                            goto lab3;
                        }
                        goto lab2;
                    lab3:
                        $this->cursor = $v_4;
                        $this->slice_from("\u{05F0}");
                        break;
                    case 2:
                        $v_5 = $this->cursor;
                        if (!($this->eq_s("\u{05B4}"))) {
                            goto lab4;
                        }
                        goto lab2;
                    lab4:
                        $this->cursor = $v_5;
                        $this->slice_from("\u{05F1}");
                        break;
                    case 3:
                        $v_6 = $this->cursor;
                        if (!($this->eq_s("\u{05B4}"))) {
                            goto lab5;
                        }
                        goto lab2;
                    lab5:
                        $this->cursor = $v_6;
                        $this->slice_from("\u{05F2}");
                        break;
                    case 4:
                        $this->slice_from("\u{05DB}");
                        break;
                    case 5:
                        $this->slice_from("\u{05DE}");
                        break;
                    case 6:
                        $this->slice_from("\u{05E0}");
                        break;
                    case 7:
                        $this->slice_from("\u{05E4}");
                        break;
                    case 8:
                        $this->slice_from("\u{05E6}");
                        break;
                }
                $this->cursor = $v_3;
                break;
            lab2:
                $this->cursor = $v_3;
                if ($this->cursor >= $this->limit) {
                    goto lab1;
                }
                $this->inc_cursor();
            }
            continue;
        lab1:
            $this->cursor = $v_2;
            break;
        }
    lab0:
        $this->cursor = $v_1;
        $v_7 = $this->cursor;
        while (true) {
            $v_8 = $this->cursor;
            while (true) {
                $v_9 = $this->cursor;
                $this->bra = $this->cursor;
                if (!($this->in_grouping(self::G_niked))) {
                    goto lab8;
                }
                $this->ket = $this->cursor;
                $this->slice_del();
                $this->cursor = $v_9;
                break;
            lab8:
                $this->cursor = $v_9;
                if ($this->cursor >= $this->limit) {
                    goto lab7;
                }
                $this->inc_cursor();
            }
            continue;
        lab7:
            $this->cursor = $v_8;
            break;
        }
    lab6:
        $this->cursor = $v_7;
        return true;
    }


    protected function r_mark_regions(): bool
    {
        $this->I_p1 = $this->limit;
        $v_1 = $this->cursor;
        $this->bra = $this->cursor;
        if (!($this->eq_s("\u{05D2}\u{05E2}"))) {
            $this->cursor = $v_1;
            goto lab0;
        }
        $this->ket = $this->cursor;
        $v_2 = $this->cursor;
        $v_3 = $this->cursor;
        if (!($this->eq_s("\u{05DC}\u{05D8}"))) {
            goto lab2;
        }
        goto lab3;
    lab2:
        $this->cursor = $v_3;
        if (!($this->eq_s("\u{05D1}\u{05E0}"))) {
            goto lab4;
        }
        goto lab3;
    lab4:
        $this->cursor = $v_3;
        if ($this->cursor < $this->limit) {
            goto lab1;
        }
    lab3:
        $this->cursor = $v_1;
        goto lab0;
    lab1:
        $this->cursor = $v_2;
        $this->slice_from("GE");
    lab0:
        $v_4 = $this->cursor;
        if ($this->find_among(self::A_1) === 0) {
            $this->cursor = $v_4;
            goto lab5;
        }
        $v_5 = $this->cursor;
        $v_6 = $this->cursor;
        $v_7 = $this->cursor;
        if (!($this->eq_s("\u{05E6}\u{05D5}\u{05D2}\u{05E0}"))) {
            goto lab7;
        }
        goto lab8;
    lab7:
        $this->cursor = $v_7;
        if (!($this->eq_s("\u{05E6}\u{05D5}\u{05E7}\u{05D8}"))) {
            goto lab9;
        }
        goto lab8;
    lab9:
        $this->cursor = $v_7;
        if (!($this->eq_s("\u{05E6}\u{05D5}\u{05E7}\u{05E0}"))) {
            goto lab6;
        }
    lab8:
        if ($this->cursor < $this->limit) {
            goto lab6;
        }
        $this->cursor = $v_6;
        goto lab10;
    lab6:
        $this->cursor = $v_5;
        $v_8 = $this->cursor;
        if (!($this->eq_s("\u{05D2}\u{05E2}\u{05D1}\u{05E0}"))) {
            goto lab11;
        }
        $this->cursor = $v_8;
        goto lab10;
    lab11:
        $this->cursor = $v_5;
        $this->bra = $this->cursor;
        if (!($this->eq_s("\u{05D2}\u{05E2}"))) {
            goto lab12;
        }
        $this->ket = $this->cursor;
        $this->slice_from("GE");
        goto lab10;
    lab12:
        $this->cursor = $v_5;
        $this->bra = $this->cursor;
        if (!($this->eq_s("\u{05E6}\u{05D5}"))) {
            $this->cursor = $v_4;
            goto lab5;
        }
        $this->ket = $this->cursor;
        $this->slice_from("TSU");
    lab10:
    lab5:
        $v_9 = $this->cursor;
        if (!$this->hop(3)) {
            return false;
        }
        $I_x = $this->cursor;
        $this->cursor = $v_9;
        $v_10 = $this->cursor;
        if ($this->find_among(self::A_2) === 0) {
            $this->cursor = $v_10;
            goto lab13;
        }
    lab13:
        $v_11 = $this->cursor;
        if (!($this->in_grouping(self::G_consonant))) {
            goto lab14;
        }
        if (!($this->in_grouping(self::G_consonant))) {
            goto lab14;
        }
        if (!($this->in_grouping(self::G_consonant))) {
            goto lab14;
        }
        $this->I_p1 = $this->cursor;
        return false;
    lab14:
        $this->cursor = $v_11;
        if (!$this->go_out_grouping(self::G_vowel)) {
            return false;
        }
        $this->inc_cursor();
        if (!$this->go_in_grouping(self::G_vowel)) {
            return false;
        }
        $this->I_p1 = $this->cursor;
        if ($this->I_p1 >= $I_x) {
            goto lab15;
        }
        $this->I_p1 = $I_x;
    lab15:
        return true;
    }


    protected function r_R1(): bool
    {
        return $this->I_p1 <= $this->cursor;
    }


    protected function r_R1plus3(): bool
    {
        return $this->I_p1 <= ($this->cursor + 6);
    }


    protected function r_standard_suffix(): bool
    {
        $v_1 = $this->limit - $this->cursor;
        $this->ket = $this->cursor;
        $among_var = $this->find_among_b(self::A_4);
        if (0 === $among_var) {
            goto lab0;
        }
        $this->bra = $this->cursor;
        switch ($among_var) {
            case 1:
                if (!$this->r_R1()) {
                    goto lab0;
                }
                $this->slice_del();
                break;
            case 2:
                if (!$this->r_R1()) {
                    goto lab0;
                }
                $this->slice_from("\u{05D9}\u{05E2}");
                break;
            case 3:
                if (!$this->r_R1()) {
                    goto lab0;
                }
                $this->slice_del();
                $this->ket = $this->cursor;
                $among_var = $this->find_among_b(self::A_3);
                if (0 === $among_var) {
                    goto lab0;
                }
                $this->bra = $this->cursor;
                switch ($among_var) {
                    case 1:
                        $this->slice_from("\u{05D2}\u{05F2}");
                        break;
                    case 2:
                        $this->slice_from("\u{05E0}\u{05E2}\u{05DE}");
                        break;
                    case 3:
                        $this->slice_from("\u{05DE}\u{05F2}\u{05D3}");
                        break;
                    case 4:
                        $this->slice_from("\u{05D1}\u{05F2}\u{05D8}");
                        break;
                    case 5:
                        $this->slice_from("\u{05D1}\u{05F2}\u{05E1}");
                        break;
                    case 6:
                        $this->slice_from("\u{05F0}\u{05F2}\u{05D6}");
                        break;
                    case 7:
                        $this->slice_from("\u{05D8}\u{05E8}\u{05F2}\u{05D1}");
                        break;
                    case 8:
                        $this->slice_from("\u{05DC}\u{05F2}\u{05D8}");
                        break;
                    case 9:
                        $this->slice_from("\u{05E7}\u{05DC}\u{05F2}\u{05D1}");
                        break;
                    case 10:
                        $this->slice_from("\u{05E8}\u{05F2}\u{05D1}");
                        break;
                    case 11:
                        $this->slice_from("\u{05E8}\u{05F2}\u{05E1}");
                        break;
                    case 12:
                        $this->slice_from("\u{05E9}\u{05F0}\u{05F2}\u{05D2}");
                        break;
                    case 13:
                        $this->slice_from("\u{05E9}\u{05DE}\u{05F2}\u{05E1}");
                        break;
                    case 14:
                        $this->slice_from("\u{05E9}\u{05E0}\u{05F2}\u{05D3}");
                        break;
                    case 15:
                        $this->slice_from("\u{05E9}\u{05E8}\u{05F2}\u{05D1}");
                        break;
                    case 16:
                        $this->slice_from("\u{05D1}\u{05D9}\u{05E0}\u{05D3}");
                        break;
                    case 17:
                        $this->slice_from("\u{05F0}\u{05D9}\u{05D8}\u{05E9}");
                        break;
                    case 18:
                        $this->slice_from("\u{05D6}\u{05D9}\u{05E0}\u{05D2}");
                        break;
                    case 19:
                        $this->slice_from("\u{05D8}\u{05E8}\u{05D9}\u{05E0}\u{05E7}");
                        break;
                    case 20:
                        $this->slice_from("\u{05E6}\u{05F0}\u{05D9}\u{05E0}\u{05D2}");
                        break;
                    case 21:
                        $this->slice_from("\u{05E9}\u{05DC}\u{05D9}\u{05E0}\u{05D2}");
                        break;
                    case 22:
                        $this->slice_from("\u{05D1}\u{05F2}\u{05D2}");
                        break;
                    case 23:
                        $this->slice_from("\u{05D4}\u{05F2}\u{05D1}");
                        break;
                    case 24:
                        $this->slice_from("\u{05E4}\u{05D0}\u{05E8}\u{05DC}\u{05D9}\u{05E8}");
                        break;
                    case 25:
                        $this->slice_from("\u{05E9}\u{05D8}\u{05F2}");
                        break;
                    case 26:
                        $this->slice_from("\u{05E9}\u{05F0}\u{05E2}\u{05E8}");
                        break;
                }
                break;
            case 4:
                $v_2 = $this->limit - $this->cursor;
                if (!$this->r_R1()) {
                    goto lab1;
                }
                $this->slice_del();
                goto lab2;
            lab1:
                $this->cursor = $this->limit - $v_2;
                $this->slice_from("\u{05D8}");
            lab2:
                $this->ket = $this->cursor;
                if (!($this->eq_s_b("\u{05D1}\u{05E8}\u{05D0}\u{05DB}"))) {
                    goto lab0;
                }
                $v_3 = $this->limit - $this->cursor;
                if (!($this->eq_s_b("\u{05D2}\u{05E2}"))) {
                    $this->cursor = $this->limit - $v_3;
                    goto lab3;
                }
            lab3:
                $this->bra = $this->cursor;
                $this->slice_from("\u{05D1}\u{05E8}\u{05E2}\u{05E0}\u{05D2}");
                break;
            case 5:
                $this->slice_from("\u{05D2}\u{05F2}");
                break;
            case 6:
                $this->slice_from("\u{05E0}\u{05E2}\u{05DE}");
                break;
            case 7:
                $this->slice_from("\u{05E9}\u{05E8}\u{05F2}\u{05D1}");
                break;
            case 8:
                $this->slice_from("\u{05DE}\u{05F2}\u{05D3}");
                break;
            case 9:
                $this->slice_from("\u{05D1}\u{05F2}\u{05D8}");
                break;
            case 10:
                $this->slice_from("\u{05D1}\u{05F2}\u{05E1}");
                break;
            case 11:
                $this->slice_from("\u{05F0}\u{05F2}\u{05D6}");
                break;
            case 12:
                $this->slice_from("\u{05D8}\u{05E8}\u{05F2}\u{05D1}");
                break;
            case 13:
                $this->slice_from("\u{05DC}\u{05F2}\u{05D8}");
                break;
            case 14:
                $this->slice_from("\u{05E7}\u{05DC}\u{05F2}\u{05D1}");
                break;
            case 15:
                $this->slice_from("\u{05E8}\u{05F2}\u{05D1}");
                break;
            case 16:
                $this->slice_from("\u{05E8}\u{05F2}\u{05E1}");
                break;
            case 17:
                $this->slice_from("\u{05E9}\u{05F0}\u{05F2}\u{05D2}");
                break;
            case 18:
                $this->slice_from("\u{05E9}\u{05DE}\u{05F2}\u{05E1}");
                break;
            case 19:
                $this->slice_from("\u{05E9}\u{05E0}\u{05F2}\u{05D3}");
                break;
            case 20:
                $this->slice_from("\u{05D1}\u{05D9}\u{05E0}\u{05D3}");
                break;
            case 21:
                $this->slice_from("\u{05F0}\u{05D9}\u{05D8}\u{05E9}");
                break;
            case 22:
                $this->slice_from("\u{05D6}\u{05D9}\u{05E0}\u{05D2}");
                break;
            case 23:
                $this->slice_from("\u{05D8}\u{05E8}\u{05D9}\u{05E0}\u{05E7}");
                break;
            case 24:
                $this->slice_from("\u{05E6}\u{05F0}\u{05D9}\u{05E0}\u{05D2}");
                break;
            case 25:
                $this->slice_from("\u{05E9}\u{05DC}\u{05D9}\u{05E0}\u{05D2}");
                break;
            case 26:
                $this->slice_from("\u{05D1}\u{05F2}\u{05D2}");
                break;
            case 27:
                $this->slice_from("\u{05D4}\u{05F2}\u{05D1}");
                break;
            case 28:
                $this->slice_from("\u{05E4}\u{05D0}\u{05E8}\u{05DC}\u{05D9}\u{05E8}");
                break;
            case 29:
                $this->slice_from("\u{05E9}\u{05D8}\u{05F2}");
                break;
            case 30:
                $this->slice_from("\u{05E9}\u{05F0}\u{05E2}\u{05E8}");
                break;
            case 31:
                $this->slice_from("\u{05D1}\u{05E8}\u{05E2}\u{05E0}\u{05D2}");
                break;
            case 32:
                if (!$this->r_R1()) {
                    goto lab0;
                }
                $this->slice_from("\u{05D4}");
                break;
            case 33:
                $v_4 = $this->limit - $this->cursor;
                $v_5 = $this->limit - $this->cursor;
                if (!($this->eq_s_b("\u{05D2}"))) {
                    goto lab5;
                }
                goto lab6;
            lab5:
                $this->cursor = $this->limit - $v_5;
                if (!($this->eq_s_b("\u{05E9}"))) {
                    goto lab4;
                }
            lab6:
                $v_6 = $this->limit - $this->cursor;
                if (!$this->r_R1plus3()) {
                    $this->cursor = $this->limit - $v_6;
                    goto lab7;
                }
                $this->slice_from("\u{05D9}\u{05E1}");
            lab7:
                goto lab8;
            lab4:
                $this->cursor = $this->limit - $v_4;
                if (!$this->r_R1()) {
                    goto lab0;
                }
                $this->slice_del();
            lab8:
                break;
        }
    lab0:
        $this->cursor = $this->limit - $v_1;
        $v_7 = $this->limit - $this->cursor;
        $this->ket = $this->cursor;
        $among_var = $this->find_among_b(self::A_5);
        if (0 === $among_var) {
            goto lab9;
        }
        $this->bra = $this->cursor;
        switch ($among_var) {
            case 1:
                if (!$this->r_R1()) {
                    goto lab9;
                }
                $this->slice_del();
                break;
            case 2:
                if (!$this->r_R1()) {
                    goto lab9;
                }
                if (!($this->in_grouping_b(self::G_consonant))) {
                    goto lab9;
                }
                $this->slice_del();
                break;
        }
    lab9:
        $this->cursor = $this->limit - $v_7;
        $v_8 = $this->limit - $this->cursor;
        $this->ket = $this->cursor;
        $among_var = $this->find_among_b(self::A_6);
        if (0 === $among_var) {
            goto lab10;
        }
        $this->bra = $this->cursor;
        switch ($among_var) {
            case 1:
                if (!$this->r_R1()) {
                    goto lab10;
                }
                $this->slice_del();
                break;
        }
    lab10:
        $this->cursor = $this->limit - $v_8;
        $v_9 = $this->limit - $this->cursor;
        while (true) {
            $v_10 = $this->limit - $this->cursor;
            while (true) {
                $v_11 = $this->limit - $this->cursor;
                $this->ket = $this->cursor;
                $v_12 = $this->limit - $this->cursor;
                if (!($this->eq_s_b("GE"))) {
                    goto lab14;
                }
                goto lab15;
            lab14:
                $this->cursor = $this->limit - $v_12;
                if (!($this->eq_s_b("TSU"))) {
                    goto lab13;
                }
            lab15:
                $this->bra = $this->cursor;
                $this->slice_del();
                $this->cursor = $this->limit - $v_11;
                break;
            lab13:
                $this->cursor = $this->limit - $v_11;
                if ($this->cursor <= $this->limit_backward) {
                    goto lab12;
                }
                $this->dec_cursor();
            }
            continue;
        lab12:
            $this->cursor = $this->limit - $v_10;
            break;
        }
    lab11:
        $this->cursor = $this->limit - $v_9;
        return true;
    }


    public function stem(): bool
    {
        $this->r_prelude();
        $v_1 = $this->cursor;
        $this->r_mark_regions();
        $this->cursor = $v_1;
        $this->limit_backward = $this->cursor;
        $this->cursor = $this->limit;
        $this->r_standard_suffix();
        $this->cursor = $this->limit_backward;
        return true;
    }
}
