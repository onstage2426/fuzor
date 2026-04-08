<?php

namespace Fuzor\Stemmers;

// Generated from polish.sbl by Snowball 3.0.0 - https://snowballstem.org/

class SnowballPolish extends SnowballStemmer
{
    private const array A_0 = [
        ["by\u{015B}cie", -1, 1],
        ["bym", -1, 1],
        ["by", -1, 1],
        ["by\u{015B}my", -1, 1],
        ["by\u{015B}", -1, 1]
    ];

    private const array A_1 = [
        ["\u{0105}c", -1, 1],
        ["aj\u{0105}c", 0, 1],
        ["sz\u{0105}c", 0, 2],
        ["sz", -1, 1],
        ["iejsz", 3, 1]
    ];

    private const array A_2 = [
        ["a", -1, 1, 'r_R1'],
        ["\u{0105}ca", 0, 1],
        ["aj\u{0105}ca", 1, 1],
        ["sz\u{0105}ca", 1, 2],
        ["ia", 0, 1, 'r_R1'],
        ["sza", 0, 1],
        ["iejsza", 5, 1],
        ["a\u{0142}a", 0, 1],
        ["ia\u{0142}a", 7, 1],
        ["i\u{0142}a", 0, 1],
        ["\u{0105}c", -1, 1],
        ["aj\u{0105}c", 10, 1],
        ["e", -1, 1, 'r_R1'],
        ["\u{0105}ce", 12, 1],
        ["aj\u{0105}ce", 13, 1],
        ["sz\u{0105}ce", 13, 2],
        ["ie", 12, 1, 'r_R1'],
        ["cie", 16, 1],
        ["acie", 17, 1],
        ["ecie", 17, 1],
        ["icie", 17, 1],
        ["ajcie", 17, 1],
        ["li\u{015B}cie", 17, 4],
        ["ali\u{015B}cie", 22, 1],
        ["ieli\u{015B}cie", 22, 1],
        ["ili\u{015B}cie", 22, 1],
        ["\u{0142}y\u{015B}cie", 17, 4],
        ["a\u{0142}y\u{015B}cie", 26, 1],
        ["ia\u{0142}y\u{015B}cie", 27, 1],
        ["i\u{0142}y\u{015B}cie", 26, 1],
        ["sze", 12, 1],
        ["iejsze", 30, 1],
        ["ach", -1, 1, 'r_R1'],
        ["iach", 32, 1, 'r_R1'],
        ["ich", -1, 5],
        ["ych", -1, 5],
        ["i", -1, 1, 'r_R1'],
        ["ali", 36, 1],
        ["ieli", 36, 1],
        ["ili", 36, 1],
        ["ami", 36, 1, 'r_R1'],
        ["iami", 40, 1, 'r_R1'],
        ["imi", 36, 5],
        ["ymi", 36, 5],
        ["owi", 36, 1, 'r_R1'],
        ["iowi", 44, 1, 'r_R1'],
        ["aj", -1, 1],
        ["ej", -1, 5],
        ["iej", 47, 5],
        ["am", -1, 1],
        ["a\u{0142}am", 49, 1],
        ["ia\u{0142}am", 50, 1],
        ["i\u{0142}am", 49, 1],
        ["em", -1, 1, 'r_R1'],
        ["iem", 53, 1, 'r_R1'],
        ["a\u{0142}em", 53, 1],
        ["ia\u{0142}em", 55, 1],
        ["i\u{0142}em", 53, 1],
        ["im", -1, 5],
        ["om", -1, 1, 'r_R1'],
        ["iom", 59, 1, 'r_R1'],
        ["ym", -1, 5],
        ["o", -1, 1, 'r_R1'],
        ["ego", 62, 5],
        ["iego", 63, 5],
        ["a\u{0142}o", 62, 1],
        ["ia\u{0142}o", 65, 1],
        ["i\u{0142}o", 62, 1],
        ["u", -1, 1, 'r_R1'],
        ["iu", 68, 1, 'r_R1'],
        ["emu", 68, 5],
        ["iemu", 70, 5],
        ["\u{00F3}w", -1, 1, 'r_R1'],
        ["y", -1, 5],
        ["amy", 73, 1],
        ["emy", 73, 1],
        ["imy", 73, 1],
        ["li\u{015B}my", 73, 4],
        ["ali\u{015B}my", 77, 1],
        ["ieli\u{015B}my", 77, 1],
        ["ili\u{015B}my", 77, 1],
        ["\u{0142}y\u{015B}my", 73, 4],
        ["a\u{0142}y\u{015B}my", 81, 1],
        ["ia\u{0142}y\u{015B}my", 82, 1],
        ["i\u{0142}y\u{015B}my", 81, 1],
        ["a\u{0142}y", 73, 1],
        ["ia\u{0142}y", 85, 1],
        ["i\u{0142}y", 73, 1],
        ["asz", -1, 1],
        ["esz", -1, 1],
        ["isz", -1, 1],
        ["a\u{0142}", -1, 1],
        ["ia\u{0142}", 91, 1],
        ["i\u{0142}", -1, 1],
        ["\u{0105}", -1, 1, 'r_R1'],
        ["\u{0105}c\u{0105}", 94, 1],
        ["aj\u{0105}c\u{0105}", 95, 1],
        ["sz\u{0105}c\u{0105}", 95, 2],
        ["i\u{0105}", 94, 1, 'r_R1'],
        ["aj\u{0105}", 94, 1],
        ["sz\u{0105}", 94, 3],
        ["iejsz\u{0105}", 100, 1],
        ["a\u{0107}", -1, 1],
        ["ie\u{0107}", -1, 1],
        ["i\u{0107}", -1, 1],
        ["\u{0105}\u{0107}", -1, 1],
        ["a\u{015B}\u{0107}", -1, 1],
        ["e\u{015B}\u{0107}", -1, 1],
        ["\u{0119}", -1, 1],
        ["sz\u{0119}", 108, 2],
        ["\u{0142}a\u{015B}", -1, 4],
        ["a\u{0142}a\u{015B}", 110, 1],
        ["ia\u{0142}a\u{015B}", 111, 1],
        ["i\u{0142}a\u{015B}", 110, 1],
        ["\u{0142}e\u{015B}", -1, 4],
        ["a\u{0142}e\u{015B}", 114, 1],
        ["ia\u{0142}e\u{015B}", 115, 1],
        ["i\u{0142}e\u{015B}", 114, 1]
    ];

    private const array A_3 = [
        ["\u{0144}", -1, 2],
        ["\u{0107}", -1, 1],
        ["\u{015B}", -1, 3],
        ["\u{017A}", -1, 4]
    ];

    private const array G_v = ["a"=>true, "e"=>true, "i"=>true, "o"=>true, "u"=>true, "y"=>true, "\u{00F3}"=>true, "\u{0105}"=>true, "\u{0119}"=>true];

    private int $I_p1 = 0;



    protected function r_mark_regions(): bool
    {
        $this->I_p1 = $this->limit;
        if (!$this->go_out_grouping(self::G_v)) {
            return false;
        }
        $this->inc_cursor();
        if (!$this->go_in_grouping(self::G_v)) {
            return false;
        }
        $this->inc_cursor();
        $this->I_p1 = $this->cursor;
        return true;
    }


    protected function r_R1(): bool
    {
        return $this->I_p1 <= $this->cursor;
    }


    protected function r_remove_endings(): bool
    {
        $v_1 = $this->limit - $this->cursor;
        if ($this->cursor < $this->I_p1) {
            goto lab0;
        }
        $v_2 = $this->limit_backward;
        $this->limit_backward = $this->I_p1;
        $this->ket = $this->cursor;
        if ($this->find_among_b(self::A_0) === 0) {
            $this->limit_backward = $v_2;
            goto lab0;
        }
        $this->bra = $this->cursor;
        $this->limit_backward = $v_2;
        $this->slice_del();
    lab0:
        $this->cursor = $this->limit - $v_1;
        $this->ket = $this->cursor;
        $among_var = $this->find_among_b(self::A_2);
        if (0 === $among_var) {
            return false;
        }
        $this->bra = $this->cursor;
        switch ($among_var) {
            case 1:
                $this->slice_del();
                break;
            case 2:
                $this->slice_from("s");
                break;
            case 3:
                $v_3 = $this->limit - $this->cursor;
                $v_4 = $this->limit - $this->cursor;
                if (!$this->r_R1()) {
                    goto lab1;
                }
                $this->cursor = $this->limit - $v_4;
                $this->slice_del();
                goto lab2;
            lab1:
                $this->cursor = $this->limit - $v_3;
                $this->slice_from("s");
            lab2:
                break;
            case 4:
                $this->slice_from("\u{0142}");
                break;
            case 5:
                $this->slice_del();
                $v_5 = $this->limit - $this->cursor;
                $this->ket = $this->cursor;
                $among_var = $this->find_among_b(self::A_1);
                if (0 === $among_var) {
                    $this->cursor = $this->limit - $v_5;
                    goto lab3;
                }
                $this->bra = $this->cursor;
                switch ($among_var) {
                    case 1:
                        $this->slice_del();
                        break;
                    case 2:
                        $this->slice_from("s");
                        break;
                }
            lab3:
                break;
        }
        return true;
    }


    protected function r_normalize_consonant(): bool
    {
        $this->ket = $this->cursor;
        $among_var = $this->find_among_b(self::A_3);
        if (0 === $among_var) {
            return false;
        }
        $this->bra = $this->cursor;
        if ($this->cursor > $this->limit_backward) {
            goto lab0;
        }
        return false;
    lab0:
        switch ($among_var) {
            case 1:
                $this->slice_from("c");
                break;
            case 2:
                $this->slice_from("n");
                break;
            case 3:
                $this->slice_from("s");
                break;
            case 4:
                $this->slice_from("z");
                break;
        }
        return true;
    }


    public function stem(): bool
    {
        $v_1 = $this->cursor;
        $this->r_mark_regions();
        $this->cursor = $v_1;
        $v_2 = $this->cursor;
        if (!$this->hop(2)) {
            goto lab0;
        }
        $this->limit_backward = $this->cursor;
        $this->cursor = $this->limit;
        if (!$this->r_remove_endings()) {
            goto lab0;
        }
        $this->cursor = $this->limit_backward;
        goto lab1;
    lab0:
        $this->cursor = $v_2;
        $this->limit_backward = $this->cursor;
        $this->cursor = $this->limit;
        if (!$this->r_normalize_consonant()) {
            return false;
        }
        $this->cursor = $this->limit_backward;
    lab1:
        return true;
    }
}
