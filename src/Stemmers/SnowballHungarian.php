<?php

namespace Fuzor\Stemmers;

// Generated from hungarian.sbl by Snowball 3.0.0 - https://snowballstem.org/

class SnowballHungarian extends SnowballStemmer
{
    private const array A_0 = [
        ["\u{00E1}", -1, 1],
        ["\u{00E9}", -1, 2]
    ];

    private const array A_1 = [
        ["bb", -1, -1],
        ["cc", -1, -1],
        ["dd", -1, -1],
        ["ff", -1, -1],
        ["gg", -1, -1],
        ["jj", -1, -1],
        ["kk", -1, -1],
        ["ll", -1, -1],
        ["mm", -1, -1],
        ["nn", -1, -1],
        ["pp", -1, -1],
        ["rr", -1, -1],
        ["ccs", -1, -1],
        ["ss", -1, -1],
        ["zzs", -1, -1],
        ["tt", -1, -1],
        ["vv", -1, -1],
        ["ggy", -1, -1],
        ["lly", -1, -1],
        ["nny", -1, -1],
        ["tty", -1, -1],
        ["ssz", -1, -1],
        ["zz", -1, -1]
    ];

    private const array A_2 = [
        ["al", -1, 1],
        ["el", -1, 1]
    ];

    private const array A_3 = [
        ["ba", -1, -1],
        ["ra", -1, -1],
        ["be", -1, -1],
        ["re", -1, -1],
        ["ig", -1, -1],
        ["nak", -1, -1],
        ["nek", -1, -1],
        ["val", -1, -1],
        ["vel", -1, -1],
        ["ul", -1, -1],
        ["b\u{0151}l", -1, -1],
        ["r\u{0151}l", -1, -1],
        ["t\u{0151}l", -1, -1],
        ["n\u{00E1}l", -1, -1],
        ["n\u{00E9}l", -1, -1],
        ["b\u{00F3}l", -1, -1],
        ["r\u{00F3}l", -1, -1],
        ["t\u{00F3}l", -1, -1],
        ["\u{00FC}l", -1, -1],
        ["n", -1, -1],
        ["an", 19, -1],
        ["ban", 20, -1],
        ["en", 19, -1],
        ["ben", 22, -1],
        ["k\u{00E9}ppen", 22, -1],
        ["on", 19, -1],
        ["\u{00F6}n", 19, -1],
        ["k\u{00E9}pp", -1, -1],
        ["kor", -1, -1],
        ["t", -1, -1],
        ["at", 29, -1],
        ["et", 29, -1],
        ["k\u{00E9}nt", 29, -1],
        ["ank\u{00E9}nt", 32, -1],
        ["enk\u{00E9}nt", 32, -1],
        ["onk\u{00E9}nt", 32, -1],
        ["ot", 29, -1],
        ["\u{00E9}rt", 29, -1],
        ["\u{00F6}t", 29, -1],
        ["hez", -1, -1],
        ["hoz", -1, -1],
        ["h\u{00F6}z", -1, -1],
        ["v\u{00E1}", -1, -1],
        ["v\u{00E9}", -1, -1]
    ];

    private const array A_4 = [
        ["\u{00E1}n", -1, 2],
        ["\u{00E9}n", -1, 1],
        ["\u{00E1}nk\u{00E9}nt", -1, 2]
    ];

    private const array A_5 = [
        ["stul", -1, 1],
        ["astul", 0, 1],
        ["\u{00E1}stul", 0, 2],
        ["st\u{00FC}l", -1, 1],
        ["est\u{00FC}l", 3, 1],
        ["\u{00E9}st\u{00FC}l", 3, 3]
    ];

    private const array A_6 = [
        ["\u{00E1}", -1, 1],
        ["\u{00E9}", -1, 1]
    ];

    private const array A_7 = [
        ["k", -1, 3],
        ["ak", 0, 3],
        ["ek", 0, 3],
        ["ok", 0, 3],
        ["\u{00E1}k", 0, 1],
        ["\u{00E9}k", 0, 2],
        ["\u{00F6}k", 0, 3]
    ];

    private const array A_8 = [
        ["\u{00E9}i", -1, 1],
        ["\u{00E1}\u{00E9}i", 0, 3],
        ["\u{00E9}\u{00E9}i", 0, 2],
        ["\u{00E9}", -1, 1],
        ["k\u{00E9}", 3, 1],
        ["ak\u{00E9}", 4, 1],
        ["ek\u{00E9}", 4, 1],
        ["ok\u{00E9}", 4, 1],
        ["\u{00E1}k\u{00E9}", 4, 3],
        ["\u{00E9}k\u{00E9}", 4, 2],
        ["\u{00F6}k\u{00E9}", 4, 1],
        ["\u{00E9}\u{00E9}", 3, 2]
    ];

    private const array A_9 = [
        ["a", -1, 1],
        ["ja", 0, 1],
        ["d", -1, 1],
        ["ad", 2, 1],
        ["ed", 2, 1],
        ["od", 2, 1],
        ["\u{00E1}d", 2, 2],
        ["\u{00E9}d", 2, 3],
        ["\u{00F6}d", 2, 1],
        ["e", -1, 1],
        ["je", 9, 1],
        ["nk", -1, 1],
        ["unk", 11, 1],
        ["\u{00E1}nk", 11, 2],
        ["\u{00E9}nk", 11, 3],
        ["\u{00FC}nk", 11, 1],
        ["uk", -1, 1],
        ["juk", 16, 1],
        ["\u{00E1}juk", 17, 2],
        ["\u{00FC}k", -1, 1],
        ["j\u{00FC}k", 19, 1],
        ["\u{00E9}j\u{00FC}k", 20, 3],
        ["m", -1, 1],
        ["am", 22, 1],
        ["em", 22, 1],
        ["om", 22, 1],
        ["\u{00E1}m", 22, 2],
        ["\u{00E9}m", 22, 3],
        ["o", -1, 1],
        ["\u{00E1}", -1, 2],
        ["\u{00E9}", -1, 3]
    ];

    private const array A_10 = [
        ["id", -1, 1],
        ["aid", 0, 1],
        ["jaid", 1, 1],
        ["eid", 0, 1],
        ["jeid", 3, 1],
        ["\u{00E1}id", 0, 2],
        ["\u{00E9}id", 0, 3],
        ["i", -1, 1],
        ["ai", 7, 1],
        ["jai", 8, 1],
        ["ei", 7, 1],
        ["jei", 10, 1],
        ["\u{00E1}i", 7, 2],
        ["\u{00E9}i", 7, 3],
        ["itek", -1, 1],
        ["eitek", 14, 1],
        ["jeitek", 15, 1],
        ["\u{00E9}itek", 14, 3],
        ["ik", -1, 1],
        ["aik", 18, 1],
        ["jaik", 19, 1],
        ["eik", 18, 1],
        ["jeik", 21, 1],
        ["\u{00E1}ik", 18, 2],
        ["\u{00E9}ik", 18, 3],
        ["ink", -1, 1],
        ["aink", 25, 1],
        ["jaink", 26, 1],
        ["eink", 25, 1],
        ["jeink", 28, 1],
        ["\u{00E1}ink", 25, 2],
        ["\u{00E9}ink", 25, 3],
        ["aitok", -1, 1],
        ["jaitok", 32, 1],
        ["\u{00E1}itok", -1, 2],
        ["im", -1, 1],
        ["aim", 35, 1],
        ["jaim", 36, 1],
        ["eim", 35, 1],
        ["jeim", 38, 1],
        ["\u{00E1}im", 35, 2],
        ["\u{00E9}im", 35, 3]
    ];

    private const array G_v = ["a"=>true, "e"=>true, "i"=>true, "o"=>true, "u"=>true, "\u{00E1}"=>true, "\u{00E9}"=>true, "\u{00ED}"=>true, "\u{00F3}"=>true, "\u{00F6}"=>true, "\u{00FA}"=>true, "\u{00FC}"=>true, "\u{0151}"=>true, "\u{0171}"=>true];

    private int $I_p1 = 0;



    protected function r_mark_regions(): bool
    {
        $this->I_p1 = $this->limit;
        $v_1 = $this->cursor;
        if (!($this->in_grouping(self::G_v))) {
            goto lab0;
        }
        $v_2 = $this->cursor;
        if (!$this->go_in_grouping(self::G_v)) {
            goto lab1;
        }
        $this->inc_cursor();
        $this->I_p1 = $this->cursor;
    lab1:
        $this->cursor = $v_2;
        goto lab2;
    lab0:
        $this->cursor = $v_1;
        if (!$this->go_out_grouping(self::G_v)) {
            return false;
        }
        $this->inc_cursor();
        $this->I_p1 = $this->cursor;
    lab2:
        return true;
    }


    protected function r_R1(): bool
    {
        return $this->I_p1 <= $this->cursor;
    }


    protected function r_v_ending(): bool
    {
        $this->ket = $this->cursor;
        $among_var = $this->find_among_b(self::A_0);
        if (0 === $among_var) {
            return false;
        }
        $this->bra = $this->cursor;
        if (!$this->r_R1()) {
            return false;
        }
        switch ($among_var) {
            case 1:
                $this->slice_from("a");
                break;
            case 2:
                $this->slice_from("e");
                break;
        }
        return true;
    }


    protected function r_double(): bool
    {
        $v_1 = $this->limit - $this->cursor;
        if ($this->find_among_b(self::A_1) === 0) {
            return false;
        }
        $this->cursor = $this->limit - $v_1;
        return true;
    }


    protected function r_undouble(): bool
    {
        if ($this->cursor <= $this->limit_backward) {
            return false;
        }
        $this->dec_cursor();
        $this->ket = $this->cursor;
        if ($this->cursor <= $this->limit_backward) {
            return false;
        }
        $this->dec_cursor();
        $this->bra = $this->cursor;
        $this->slice_del();
        return true;
    }


    protected function r_instrum(): bool
    {
        $this->ket = $this->cursor;
        if ($this->find_among_b(self::A_2) === 0) {
            return false;
        }
        $this->bra = $this->cursor;
        if (!$this->r_R1()) {
            return false;
        }
        if (!$this->r_double()) {
            return false;
        }
        $this->slice_del();
        return $this->r_undouble();
    }


    protected function r_case(): bool
    {
        $this->ket = $this->cursor;
        if ($this->find_among_b(self::A_3) === 0) {
            return false;
        }
        $this->bra = $this->cursor;
        if (!$this->r_R1()) {
            return false;
        }
        $this->slice_del();
        return $this->r_v_ending();
    }


    protected function r_case_special(): bool
    {
        $this->ket = $this->cursor;
        $among_var = $this->find_among_b(self::A_4);
        if (0 === $among_var) {
            return false;
        }
        $this->bra = $this->cursor;
        if (!$this->r_R1()) {
            return false;
        }
        switch ($among_var) {
            case 1:
                $this->slice_from("e");
                break;
            case 2:
                $this->slice_from("a");
                break;
        }
        return true;
    }


    protected function r_case_other(): bool
    {
        $this->ket = $this->cursor;
        $among_var = $this->find_among_b(self::A_5);
        if (0 === $among_var) {
            return false;
        }
        $this->bra = $this->cursor;
        if (!$this->r_R1()) {
            return false;
        }
        switch ($among_var) {
            case 1:
                $this->slice_del();
                break;
            case 2:
                $this->slice_from("a");
                break;
            case 3:
                $this->slice_from("e");
                break;
        }
        return true;
    }


    protected function r_factive(): bool
    {
        $this->ket = $this->cursor;
        if ($this->find_among_b(self::A_6) === 0) {
            return false;
        }
        $this->bra = $this->cursor;
        if (!$this->r_R1()) {
            return false;
        }
        if (!$this->r_double()) {
            return false;
        }
        $this->slice_del();
        return $this->r_undouble();
    }


    protected function r_plural(): bool
    {
        $this->ket = $this->cursor;
        $among_var = $this->find_among_b(self::A_7);
        if (0 === $among_var) {
            return false;
        }
        $this->bra = $this->cursor;
        if (!$this->r_R1()) {
            return false;
        }
        switch ($among_var) {
            case 1:
                $this->slice_from("a");
                break;
            case 2:
                $this->slice_from("e");
                break;
            case 3:
                $this->slice_del();
                break;
        }
        return true;
    }


    protected function r_owned(): bool
    {
        $this->ket = $this->cursor;
        $among_var = $this->find_among_b(self::A_8);
        if (0 === $among_var) {
            return false;
        }
        $this->bra = $this->cursor;
        if (!$this->r_R1()) {
            return false;
        }
        switch ($among_var) {
            case 1:
                $this->slice_del();
                break;
            case 2:
                $this->slice_from("e");
                break;
            case 3:
                $this->slice_from("a");
                break;
        }
        return true;
    }


    protected function r_sing_owner(): bool
    {
        $this->ket = $this->cursor;
        $among_var = $this->find_among_b(self::A_9);
        if (0 === $among_var) {
            return false;
        }
        $this->bra = $this->cursor;
        if (!$this->r_R1()) {
            return false;
        }
        switch ($among_var) {
            case 1:
                $this->slice_del();
                break;
            case 2:
                $this->slice_from("a");
                break;
            case 3:
                $this->slice_from("e");
                break;
        }
        return true;
    }


    protected function r_plur_owner(): bool
    {
        $this->ket = $this->cursor;
        $among_var = $this->find_among_b(self::A_10);
        if (0 === $among_var) {
            return false;
        }
        $this->bra = $this->cursor;
        if (!$this->r_R1()) {
            return false;
        }
        switch ($among_var) {
            case 1:
                $this->slice_del();
                break;
            case 2:
                $this->slice_from("a");
                break;
            case 3:
                $this->slice_from("e");
                break;
        }
        return true;
    }


    public function stem(): bool
    {
        $v_1 = $this->cursor;
        $this->r_mark_regions();
        $this->cursor = $v_1;
        $this->limit_backward = $this->cursor;
        $this->cursor = $this->limit;
        $v_2 = $this->limit - $this->cursor;
        $this->r_instrum();
        $this->cursor = $this->limit - $v_2;
        $v_3 = $this->limit - $this->cursor;
        $this->r_case();
        $this->cursor = $this->limit - $v_3;
        $v_4 = $this->limit - $this->cursor;
        $this->r_case_special();
        $this->cursor = $this->limit - $v_4;
        $v_5 = $this->limit - $this->cursor;
        $this->r_case_other();
        $this->cursor = $this->limit - $v_5;
        $v_6 = $this->limit - $this->cursor;
        $this->r_factive();
        $this->cursor = $this->limit - $v_6;
        $v_7 = $this->limit - $this->cursor;
        $this->r_owned();
        $this->cursor = $this->limit - $v_7;
        $v_8 = $this->limit - $this->cursor;
        $this->r_sing_owner();
        $this->cursor = $this->limit - $v_8;
        $v_9 = $this->limit - $this->cursor;
        $this->r_plur_owner();
        $this->cursor = $this->limit - $v_9;
        $v_10 = $this->limit - $this->cursor;
        $this->r_plural();
        $this->cursor = $this->limit - $v_10;
        $this->cursor = $this->limit_backward;
        return true;
    }
}
