<?php

namespace Fuzor\Stemmers;

// Generated from irish.sbl by Snowball 3.0.0 - https://snowballstem.org/

class SnowballIrish extends SnowballStemmer
{
    private const array A_0 = [
        ["b'", -1, 1],
        ["bh", -1, 4],
        ["bhf", 1, 2],
        ["bp", -1, 8],
        ["ch", -1, 5],
        ["d'", -1, 1],
        ["d'fh", 5, 2],
        ["dh", -1, 6],
        ["dt", -1, 9],
        ["fh", -1, 2],
        ["gc", -1, 5],
        ["gh", -1, 7],
        ["h-", -1, 1],
        ["m'", -1, 1],
        ["mb", -1, 4],
        ["mh", -1, 10],
        ["n-", -1, 1],
        ["nd", -1, 6],
        ["ng", -1, 7],
        ["ph", -1, 8],
        ["sh", -1, 3],
        ["t-", -1, 1],
        ["th", -1, 9],
        ["ts", -1, 3]
    ];

    private const array A_1 = [
        ["\u{00ED}ochta", -1, 1],
        ["a\u{00ED}ochta", 0, 1],
        ["ire", -1, 2],
        ["aire", 2, 2],
        ["abh", -1, 1],
        ["eabh", 4, 1],
        ["ibh", -1, 1],
        ["aibh", 6, 1],
        ["amh", -1, 1],
        ["eamh", 8, 1],
        ["imh", -1, 1],
        ["aimh", 10, 1],
        ["\u{00ED}ocht", -1, 1],
        ["a\u{00ED}ocht", 12, 1],
        ["ir\u{00ED}", -1, 2],
        ["air\u{00ED}", 14, 2]
    ];

    private const array A_2 = [
        ["\u{00F3}ideacha", -1, 6],
        ["patacha", -1, 5],
        ["achta", -1, 1],
        ["arcachta", 2, 2],
        ["eachta", 2, 1],
        ["grafa\u{00ED}ochta", -1, 4],
        ["paite", -1, 5],
        ["ach", -1, 1],
        ["each", 7, 1],
        ["\u{00F3}ideach", 8, 6],
        ["gineach", 8, 3],
        ["patach", 7, 5],
        ["grafa\u{00ED}och", -1, 4],
        ["pataigh", -1, 5],
        ["\u{00F3}idigh", -1, 6],
        ["acht\u{00FA}il", -1, 1],
        ["eacht\u{00FA}il", 15, 1],
        ["gineas", -1, 3],
        ["ginis", -1, 3],
        ["acht", -1, 1],
        ["arcacht", 19, 2],
        ["eacht", 19, 1],
        ["grafa\u{00ED}ocht", -1, 4],
        ["arcachta\u{00ED}", -1, 2],
        ["grafa\u{00ED}ochta\u{00ED}", -1, 4]
    ];

    private const array A_3 = [
        ["imid", -1, 1],
        ["aimid", 0, 1],
        ["\u{00ED}mid", -1, 1],
        ["a\u{00ED}mid", 2, 1],
        ["adh", -1, 2],
        ["eadh", 4, 2],
        ["faidh", -1, 1],
        ["fidh", -1, 1],
        ["\u{00E1}il", -1, 2],
        ["ain", -1, 2],
        ["tear", -1, 2],
        ["tar", -1, 2]
    ];

    private const array G_v = ["a"=>true, "e"=>true, "i"=>true, "o"=>true, "u"=>true, "\u{00E1}"=>true, "\u{00E9}"=>true, "\u{00ED}"=>true, "\u{00F3}"=>true, "\u{00FA}"=>true];

    private int $I_p2 = 0;
    private int $I_p1 = 0;
    private int $I_pV = 0;



    protected function r_mark_regions(): bool
    {
        $this->I_pV = $this->limit;
        $this->I_p1 = $this->limit;
        $this->I_p2 = $this->limit;
        $v_1 = $this->cursor;
        if (!$this->go_out_grouping(self::G_v)) {
            goto lab0;
        }
        $this->inc_cursor();
        $this->I_pV = $this->cursor;
        if (!$this->go_in_grouping(self::G_v)) {
            goto lab0;
        }
        $this->inc_cursor();
        $this->I_p1 = $this->cursor;
        if (!$this->go_out_grouping(self::G_v)) {
            goto lab0;
        }
        $this->inc_cursor();
        if (!$this->go_in_grouping(self::G_v)) {
            goto lab0;
        }
        $this->inc_cursor();
        $this->I_p2 = $this->cursor;
    lab0:
        $this->cursor = $v_1;
        return true;
    }


    protected function r_initial_morph(): bool
    {
        $this->bra = $this->cursor;
        $among_var = $this->find_among(self::A_0);
        if (0 === $among_var) {
            return false;
        }
        $this->ket = $this->cursor;
        switch ($among_var) {
            case 1:
                $this->slice_del();
                break;
            case 2:
                $this->slice_from("f");
                break;
            case 3:
                $this->slice_from("s");
                break;
            case 4:
                $this->slice_from("b");
                break;
            case 5:
                $this->slice_from("c");
                break;
            case 6:
                $this->slice_from("d");
                break;
            case 7:
                $this->slice_from("g");
                break;
            case 8:
                $this->slice_from("p");
                break;
            case 9:
                $this->slice_from("t");
                break;
            case 10:
                $this->slice_from("m");
                break;
        }
        return true;
    }


    protected function r_RV(): bool
    {
        return $this->I_pV <= $this->cursor;
    }


    protected function r_R1(): bool
    {
        return $this->I_p1 <= $this->cursor;
    }


    protected function r_R2(): bool
    {
        return $this->I_p2 <= $this->cursor;
    }


    protected function r_noun_sfx(): bool
    {
        $this->ket = $this->cursor;
        $among_var = $this->find_among_b(self::A_1);
        if (0 === $among_var) {
            return false;
        }
        $this->bra = $this->cursor;
        switch ($among_var) {
            case 1:
                if (!$this->r_R1()) {
                    return false;
                }
                $this->slice_del();
                break;
            case 2:
                if (!$this->r_R2()) {
                    return false;
                }
                $this->slice_del();
                break;
        }
        return true;
    }


    protected function r_deriv(): bool
    {
        $this->ket = $this->cursor;
        $among_var = $this->find_among_b(self::A_2);
        if (0 === $among_var) {
            return false;
        }
        $this->bra = $this->cursor;
        switch ($among_var) {
            case 1:
                if (!$this->r_R2()) {
                    return false;
                }
                $this->slice_del();
                break;
            case 2:
                $this->slice_from("arc");
                break;
            case 3:
                $this->slice_from("gin");
                break;
            case 4:
                $this->slice_from("graf");
                break;
            case 5:
                $this->slice_from("paite");
                break;
            case 6:
                $this->slice_from("\u{00F3}id");
                break;
        }
        return true;
    }


    protected function r_verb_sfx(): bool
    {
        $this->ket = $this->cursor;
        $among_var = $this->find_among_b(self::A_3);
        if (0 === $among_var) {
            return false;
        }
        $this->bra = $this->cursor;
        switch ($among_var) {
            case 1:
                if (!$this->r_RV()) {
                    return false;
                }
                $this->slice_del();
                break;
            case 2:
                if (!$this->r_R1()) {
                    return false;
                }
                $this->slice_del();
                break;
        }
        return true;
    }


    public function stem(): bool
    {
        $v_1 = $this->cursor;
        $this->r_initial_morph();
        $this->cursor = $v_1;
        $this->r_mark_regions();
        $this->limit_backward = $this->cursor;
        $this->cursor = $this->limit;
        $v_2 = $this->limit - $this->cursor;
        $this->r_noun_sfx();
        $this->cursor = $this->limit - $v_2;
        $v_3 = $this->limit - $this->cursor;
        $this->r_deriv();
        $this->cursor = $this->limit - $v_3;
        $v_4 = $this->limit - $this->cursor;
        $this->r_verb_sfx();
        $this->cursor = $this->limit - $v_4;
        $this->cursor = $this->limit_backward;
        return true;
    }
}
