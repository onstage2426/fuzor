<?php

namespace Fuzor\Stemmers;

// Generated from lithuanian.sbl by Snowball 3.0.0 - https://snowballstem.org/

class SnowballLithuanian extends SnowballStemmer
{
    private const array A_0 = [
        ["a", -1, -1],
        ["ia", 0, -1],
        ["osna", 0, -1],
        ["iosna", 2, -1],
        ["uosna", 2, -1],
        ["iuosna", 4, -1],
        ["ysna", 0, -1],
        ["\u{0117}sna", 0, -1],
        ["e", -1, -1],
        ["ie", 8, -1],
        ["enie", 9, -1],
        ["oje", 8, -1],
        ["ioje", 11, -1],
        ["uje", 8, -1],
        ["iuje", 13, -1],
        ["yje", 8, -1],
        ["enyje", 15, -1],
        ["\u{0117}je", 8, -1],
        ["ame", 8, -1],
        ["iame", 18, -1],
        ["sime", 8, -1],
        ["ome", 8, -1],
        ["\u{0117}me", 8, -1],
        ["tum\u{0117}me", 22, -1],
        ["ose", 8, -1],
        ["iose", 24, -1],
        ["uose", 24, -1],
        ["iuose", 26, -1],
        ["yse", 8, -1],
        ["enyse", 28, -1],
        ["\u{0117}se", 8, -1],
        ["ate", 8, -1],
        ["iate", 31, -1],
        ["ite", 8, -1],
        ["kite", 33, -1],
        ["site", 33, -1],
        ["ote", 8, -1],
        ["tute", 8, -1],
        ["\u{0117}te", 8, -1],
        ["tum\u{0117}te", 38, -1],
        ["i", -1, -1],
        ["ai", 40, -1],
        ["iai", 41, -1],
        ["ei", 40, -1],
        ["tumei", 43, -1],
        ["ki", 40, -1],
        ["imi", 40, -1],
        ["umi", 40, -1],
        ["iumi", 47, -1],
        ["si", 40, -1],
        ["asi", 49, -1],
        ["iasi", 50, -1],
        ["esi", 49, -1],
        ["iesi", 52, -1],
        ["siesi", 53, -1],
        ["isi", 49, -1],
        ["aisi", 55, -1],
        ["eisi", 55, -1],
        ["tumeisi", 57, -1],
        ["uisi", 55, -1],
        ["osi", 49, -1],
        ["\u{0117}josi", 60, -1],
        ["uosi", 60, -1],
        ["iuosi", 62, -1],
        ["siuosi", 63, -1],
        ["usi", 49, -1],
        ["ausi", 65, -1],
        ["\u{010D}iausi", 66, -1],
        ["\u{0105}si", 49, -1],
        ["\u{0117}si", 49, -1],
        ["\u{0173}si", 49, -1],
        ["t\u{0173}si", 70, -1],
        ["ti", 40, -1],
        ["enti", 72, -1],
        ["inti", 72, -1],
        ["oti", 72, -1],
        ["ioti", 75, -1],
        ["uoti", 75, -1],
        ["iuoti", 77, -1],
        ["auti", 72, -1],
        ["iauti", 79, -1],
        ["yti", 72, -1],
        ["\u{0117}ti", 72, -1],
        ["tel\u{0117}ti", 82, -1],
        ["in\u{0117}ti", 82, -1],
        ["ter\u{0117}ti", 82, -1],
        ["ui", 40, -1],
        ["iui", 86, -1],
        ["eniui", 87, -1],
        ["oj", -1, -1],
        ["\u{0117}j", -1, -1],
        ["k", -1, -1],
        ["am", -1, -1],
        ["iam", 92, -1],
        ["iem", -1, -1],
        ["im", -1, -1],
        ["sim", 95, -1],
        ["om", -1, -1],
        ["tum", -1, -1],
        ["\u{0117}m", -1, -1],
        ["tum\u{0117}m", 99, -1],
        ["an", -1, -1],
        ["on", -1, -1],
        ["ion", 102, -1],
        ["un", -1, -1],
        ["iun", 104, -1],
        ["\u{0117}n", -1, -1],
        ["o", -1, -1],
        ["io", 107, -1],
        ["enio", 108, -1],
        ["\u{0117}jo", 107, -1],
        ["uo", 107, -1],
        ["s", -1, -1],
        ["as", 112, -1],
        ["ias", 113, -1],
        ["es", 112, -1],
        ["ies", 115, -1],
        ["is", 112, -1],
        ["ais", 117, -1],
        ["iais", 118, -1],
        ["tumeis", 117, -1],
        ["imis", 117, -1],
        ["enimis", 121, -1],
        ["omis", 117, -1],
        ["iomis", 123, -1],
        ["umis", 117, -1],
        ["\u{0117}mis", 117, -1],
        ["enis", 117, -1],
        ["asis", 117, -1],
        ["ysis", 117, -1],
        ["ams", 112, -1],
        ["iams", 130, -1],
        ["iems", 112, -1],
        ["ims", 112, -1],
        ["enims", 133, -1],
        ["oms", 112, -1],
        ["ioms", 135, -1],
        ["ums", 112, -1],
        ["\u{0117}ms", 112, -1],
        ["ens", 112, -1],
        ["os", 112, -1],
        ["ios", 140, -1],
        ["uos", 140, -1],
        ["iuos", 142, -1],
        ["us", 112, -1],
        ["aus", 144, -1],
        ["iaus", 145, -1],
        ["ius", 144, -1],
        ["ys", 112, -1],
        ["enys", 148, -1],
        ["\u{0105}s", 112, -1],
        ["i\u{0105}s", 150, -1],
        ["\u{0117}s", 112, -1],
        ["am\u{0117}s", 152, -1],
        ["iam\u{0117}s", 153, -1],
        ["im\u{0117}s", 152, -1],
        ["kim\u{0117}s", 155, -1],
        ["sim\u{0117}s", 155, -1],
        ["om\u{0117}s", 152, -1],
        ["\u{0117}m\u{0117}s", 152, -1],
        ["tum\u{0117}m\u{0117}s", 159, -1],
        ["at\u{0117}s", 152, -1],
        ["iat\u{0117}s", 161, -1],
        ["sit\u{0117}s", 152, -1],
        ["ot\u{0117}s", 152, -1],
        ["\u{0117}t\u{0117}s", 152, -1],
        ["tum\u{0117}t\u{0117}s", 165, -1],
        ["\u{016B}s", 112, -1],
        ["\u{012F}s", 112, -1],
        ["t\u{0173}s", 112, -1],
        ["at", -1, -1],
        ["iat", 170, -1],
        ["it", -1, -1],
        ["sit", 172, -1],
        ["ot", -1, -1],
        ["\u{0117}t", -1, -1],
        ["tum\u{0117}t", 175, -1],
        ["u", -1, -1],
        ["au", 177, -1],
        ["iau", 178, -1],
        ["\u{010D}iau", 179, -1],
        ["iu", 177, -1],
        ["eniu", 181, -1],
        ["siu", 181, -1],
        ["y", -1, -1],
        ["\u{0105}", -1, -1],
        ["i\u{0105}", 185, -1],
        ["\u{0117}", -1, -1],
        ["\u{0119}", -1, -1],
        ["\u{012F}", -1, -1],
        ["en\u{012F}", 189, -1],
        ["\u{0173}", -1, -1],
        ["i\u{0173}", 191, -1]
    ];

    private const array A_1 = [
        ["ing", -1, -1],
        ["aj", -1, -1],
        ["iaj", 1, -1],
        ["iej", -1, -1],
        ["oj", -1, -1],
        ["ioj", 4, -1],
        ["uoj", 4, -1],
        ["iuoj", 6, -1],
        ["auj", -1, -1],
        ["\u{0105}j", -1, -1],
        ["i\u{0105}j", 9, -1],
        ["\u{0117}j", -1, -1],
        ["\u{0173}j", -1, -1],
        ["i\u{0173}j", 12, -1],
        ["ok", -1, -1],
        ["iok", 14, -1],
        ["iuk", -1, -1],
        ["uliuk", 16, -1],
        ["u\u{010D}iuk", 16, -1],
        ["i\u{0161}k", -1, -1],
        ["iul", -1, -1],
        ["yl", -1, -1],
        ["\u{0117}l", -1, -1],
        ["am", -1, -1],
        ["dam", 23, -1],
        ["jam", 23, -1],
        ["zgan", -1, -1],
        ["ain", -1, -1],
        ["esn", -1, -1],
        ["op", -1, -1],
        ["iop", 29, -1],
        ["ias", -1, -1],
        ["ies", -1, -1],
        ["ais", -1, -1],
        ["iais", 33, -1],
        ["os", -1, -1],
        ["ios", 35, -1],
        ["uos", 35, -1],
        ["iuos", 37, -1],
        ["aus", -1, -1],
        ["iaus", 39, -1],
        ["\u{0105}s", -1, -1],
        ["i\u{0105}s", 41, -1],
        ["\u{0119}s", -1, -1],
        ["ut\u{0117}ait", -1, -1],
        ["ant", -1, -1],
        ["iant", 45, -1],
        ["siant", 46, -1],
        ["int", -1, -1],
        ["ot", -1, -1],
        ["uot", 49, -1],
        ["iuot", 50, -1],
        ["yt", -1, -1],
        ["\u{0117}t", -1, -1],
        ["yk\u{0161}t", -1, -1],
        ["iau", -1, -1],
        ["dav", -1, -1],
        ["sv", -1, -1],
        ["\u{0161}v", -1, -1],
        ["yk\u{0161}\u{010D}", -1, -1],
        ["\u{0119}", -1, -1],
        ["\u{0117}j\u{0119}", 60, -1]
    ];

    private const array A_2 = [
        ["ojime", -1, 7],
        ["\u{0117}jime", -1, 3],
        ["avime", -1, 6],
        ["okate", -1, 8],
        ["aite", -1, 1],
        ["uote", -1, 2],
        ["asius", -1, 5],
        ["okat\u{0117}s", -1, 8],
        ["ait\u{0117}s", -1, 1],
        ["uot\u{0117}s", -1, 2],
        ["esiu", -1, 4]
    ];

    private const array A_3 = [
        ["\u{010D}", -1, 1],
        ["d\u{017E}", -1, 2]
    ];

    private const array G_v = ["a"=>true, "e"=>true, "i"=>true, "o"=>true, "u"=>true, "y"=>true, "\u{0105}"=>true, "\u{0117}"=>true, "\u{0119}"=>true, "\u{012F}"=>true, "\u{016B}"=>true, "\u{0173}"=>true];

    private int $I_p1 = 0;



    protected function r_step1(): bool
    {
        if ($this->cursor < $this->I_p1) {
            return false;
        }
        $v_1 = $this->limit_backward;
        $this->limit_backward = $this->I_p1;
        $this->ket = $this->cursor;
        if ($this->find_among_b(self::A_0) === 0) {
            $this->limit_backward = $v_1;
            return false;
        }
        $this->bra = $this->cursor;
        $this->limit_backward = $v_1;
        $this->slice_del();
        return true;
    }


    protected function r_step2(): bool
    {
        while (true) {
            $v_1 = $this->limit - $this->cursor;
            if ($this->cursor < $this->I_p1) {
                goto lab0;
            }
            $v_2 = $this->limit_backward;
            $this->limit_backward = $this->I_p1;
            $this->ket = $this->cursor;
            if ($this->find_among_b(self::A_1) === 0) {
                $this->limit_backward = $v_2;
                goto lab0;
            }
            $this->bra = $this->cursor;
            $this->limit_backward = $v_2;
            $this->slice_del();
            continue;
        lab0:
            $this->cursor = $this->limit - $v_1;
            break;
        }
        return true;
    }


    protected function r_fix_conflicts(): bool
    {
        $this->ket = $this->cursor;
        $among_var = $this->find_among_b(self::A_2);
        if (0 === $among_var) {
            return false;
        }
        $this->bra = $this->cursor;
        switch ($among_var) {
            case 1:
                $this->slice_from("ait\u{0117}");
                break;
            case 2:
                $this->slice_from("uot\u{0117}");
                break;
            case 3:
                $this->slice_from("\u{0117}jimas");
                break;
            case 4:
                $this->slice_from("esys");
                break;
            case 5:
                $this->slice_from("asys");
                break;
            case 6:
                $this->slice_from("avimas");
                break;
            case 7:
                $this->slice_from("ojimas");
                break;
            case 8:
                $this->slice_from("okat\u{0117}");
                break;
        }
        return true;
    }


    protected function r_fix_chdz(): bool
    {
        $this->ket = $this->cursor;
        $among_var = $this->find_among_b(self::A_3);
        if (0 === $among_var) {
            return false;
        }
        $this->bra = $this->cursor;
        switch ($among_var) {
            case 1:
                $this->slice_from("t");
                break;
            case 2:
                $this->slice_from("d");
                break;
        }
        return true;
    }


    protected function r_fix_gd(): bool
    {
        $this->ket = $this->cursor;
        if (!($this->eq_s_b("gd"))) {
            return false;
        }
        $this->bra = $this->cursor;
        $this->slice_from("g");
        return true;
    }


    public function stem(): bool
    {
        $this->I_p1 = $this->limit;
        $v_1 = $this->cursor;
        $v_2 = $this->cursor;
        if (!($this->eq_s("a"))) {
            $this->cursor = $v_2;
            goto lab1;
        }
        if (mb_strlen($this->current, 'UTF-8') <= 6) {
            $this->cursor = $v_2;
            goto lab1;
        }
    lab1:
        if (!$this->go_out_grouping(self::G_v)) {
            goto lab0;
        }
        $this->inc_cursor();
        if (!$this->go_in_grouping(self::G_v)) {
            goto lab0;
        }
        $this->inc_cursor();
        $this->I_p1 = $this->cursor;
    lab0:
        $this->cursor = $v_1;
        $this->limit_backward = $this->cursor;
        $this->cursor = $this->limit;
        $v_3 = $this->limit - $this->cursor;
        $this->r_fix_conflicts();
        $this->cursor = $this->limit - $v_3;
        $v_4 = $this->limit - $this->cursor;
        $this->r_step1();
        $this->cursor = $this->limit - $v_4;
        $v_5 = $this->limit - $this->cursor;
        $this->r_fix_chdz();
        $this->cursor = $this->limit - $v_5;
        $v_6 = $this->limit - $this->cursor;
        $this->r_step2();
        $this->cursor = $this->limit - $v_6;
        $v_7 = $this->limit - $this->cursor;
        $this->r_fix_chdz();
        $this->cursor = $this->limit - $v_7;
        $v_8 = $this->limit - $this->cursor;
        $this->r_fix_gd();
        $this->cursor = $this->limit - $v_8;
        $this->cursor = $this->limit_backward;
        return true;
    }
}
