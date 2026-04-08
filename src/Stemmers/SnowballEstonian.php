<?php

namespace Fuzor\Stemmers;

// Generated from estonian.sbl by Snowball 3.0.0 - https://snowballstem.org/

class SnowballEstonian extends SnowballStemmer
{
    private const array A_0 = [
        ["gi", -1, 1],
        ["ki", -1, 2]
    ];

    private const array A_1 = [
        ["da", -1, 3],
        ["mata", -1, 1],
        ["b", -1, 3],
        ["ksid", -1, 1],
        ["nuksid", 3, 1],
        ["me", -1, 3],
        ["sime", 5, 1],
        ["ksime", 6, 1],
        ["nuksime", 7, 1],
        ["akse", -1, 2],
        ["dakse", 9, 1],
        ["takse", 9, 1],
        ["site", -1, 1],
        ["ksite", 12, 1],
        ["nuksite", 13, 1],
        ["n", -1, 3],
        ["sin", 15, 1],
        ["ksin", 16, 1],
        ["nuksin", 17, 1],
        ["daks", -1, 1],
        ["taks", -1, 1]
    ];

    private const array A_2 = [
        ["aa", -1, -1],
        ["ee", -1, -1],
        ["ii", -1, -1],
        ["oo", -1, -1],
        ["uu", -1, -1],
        ["\u{00E4}\u{00E4}", -1, -1],
        ["\u{00F5}\u{00F5}", -1, -1],
        ["\u{00F6}\u{00F6}", -1, -1],
        ["\u{00FC}\u{00FC}", -1, -1]
    ];

    private const array A_3 = [
        ["lane", -1, 1],
        ["line", -1, 3],
        ["mine", -1, 2],
        ["lasse", -1, 1],
        ["lisse", -1, 3],
        ["misse", -1, 2],
        ["lasi", -1, 1],
        ["lisi", -1, 3],
        ["misi", -1, 2],
        ["last", -1, 1],
        ["list", -1, 3],
        ["mist", -1, 2]
    ];

    private const array A_4 = [
        ["ga", -1, 1],
        ["ta", -1, 1],
        ["le", -1, 1],
        ["sse", -1, 1],
        ["l", -1, 1],
        ["s", -1, 1],
        ["ks", 5, 1],
        ["t", -1, 2],
        ["lt", 7, 1],
        ["st", 7, 1]
    ];

    private const array A_5 = [
        ["", -1, 2],
        ["las", 0, 1],
        ["lis", 0, 1],
        ["mis", 0, 1],
        ["t", 0, -1]
    ];

    private const array A_6 = [
        ["d", -1, 4],
        ["sid", 0, 2],
        ["de", -1, 4],
        ["ikkude", 2, 1],
        ["ike", -1, 1],
        ["ikke", -1, 1],
        ["te", -1, 3]
    ];

    private const array A_7 = [
        ["va", -1, -1],
        ["du", -1, -1],
        ["nu", -1, -1],
        ["tu", -1, -1]
    ];

    private const array A_8 = [
        ["kk", -1, 1],
        ["pp", -1, 2],
        ["tt", -1, 3]
    ];

    private const array A_9 = [
        ["ma", -1, 2],
        ["mai", -1, 1],
        ["m", -1, 1]
    ];

    private const array A_10 = [
        ["joob", -1, 1],
        ["jood", -1, 1],
        ["joodakse", 1, 1],
        ["jooma", -1, 1],
        ["joomata", 3, 1],
        ["joome", -1, 1],
        ["joon", -1, 1],
        ["joote", -1, 1],
        ["joovad", -1, 1],
        ["juua", -1, 1],
        ["juuakse", 9, 1],
        ["j\u{00E4}i", -1, 12],
        ["j\u{00E4}id", 11, 12],
        ["j\u{00E4}ime", 11, 12],
        ["j\u{00E4}in", 11, 12],
        ["j\u{00E4}ite", 11, 12],
        ["j\u{00E4}\u{00E4}b", -1, 12],
        ["j\u{00E4}\u{00E4}d", -1, 12],
        ["j\u{00E4}\u{00E4}da", 17, 12],
        ["j\u{00E4}\u{00E4}dakse", 18, 12],
        ["j\u{00E4}\u{00E4}di", 17, 12],
        ["j\u{00E4}\u{00E4}ks", -1, 12],
        ["j\u{00E4}\u{00E4}ksid", 21, 12],
        ["j\u{00E4}\u{00E4}ksime", 21, 12],
        ["j\u{00E4}\u{00E4}ksin", 21, 12],
        ["j\u{00E4}\u{00E4}ksite", 21, 12],
        ["j\u{00E4}\u{00E4}ma", -1, 12],
        ["j\u{00E4}\u{00E4}mata", 26, 12],
        ["j\u{00E4}\u{00E4}me", -1, 12],
        ["j\u{00E4}\u{00E4}n", -1, 12],
        ["j\u{00E4}\u{00E4}te", -1, 12],
        ["j\u{00E4}\u{00E4}vad", -1, 12],
        ["j\u{00F5}i", -1, 1],
        ["j\u{00F5}id", 32, 1],
        ["j\u{00F5}ime", 32, 1],
        ["j\u{00F5}in", 32, 1],
        ["j\u{00F5}ite", 32, 1],
        ["keeb", -1, 4],
        ["keed", -1, 4],
        ["keedakse", 38, 4],
        ["keeks", -1, 4],
        ["keeksid", 40, 4],
        ["keeksime", 40, 4],
        ["keeksin", 40, 4],
        ["keeksite", 40, 4],
        ["keema", -1, 4],
        ["keemata", 45, 4],
        ["keeme", -1, 4],
        ["keen", -1, 4],
        ["kees", -1, 4],
        ["keeta", -1, 4],
        ["keete", -1, 4],
        ["keevad", -1, 4],
        ["k\u{00E4}ia", -1, 8],
        ["k\u{00E4}iakse", 53, 8],
        ["k\u{00E4}ib", -1, 8],
        ["k\u{00E4}id", -1, 8],
        ["k\u{00E4}idi", 56, 8],
        ["k\u{00E4}iks", -1, 8],
        ["k\u{00E4}iksid", 58, 8],
        ["k\u{00E4}iksime", 58, 8],
        ["k\u{00E4}iksin", 58, 8],
        ["k\u{00E4}iksite", 58, 8],
        ["k\u{00E4}ima", -1, 8],
        ["k\u{00E4}imata", 63, 8],
        ["k\u{00E4}ime", -1, 8],
        ["k\u{00E4}in", -1, 8],
        ["k\u{00E4}is", -1, 8],
        ["k\u{00E4}ite", -1, 8],
        ["k\u{00E4}ivad", -1, 8],
        ["laob", -1, 16],
        ["laod", -1, 16],
        ["laoks", -1, 16],
        ["laoksid", 72, 16],
        ["laoksime", 72, 16],
        ["laoksin", 72, 16],
        ["laoksite", 72, 16],
        ["laome", -1, 16],
        ["laon", -1, 16],
        ["laote", -1, 16],
        ["laovad", -1, 16],
        ["loeb", -1, 14],
        ["loed", -1, 14],
        ["loeks", -1, 14],
        ["loeksid", 83, 14],
        ["loeksime", 83, 14],
        ["loeksin", 83, 14],
        ["loeksite", 83, 14],
        ["loeme", -1, 14],
        ["loen", -1, 14],
        ["loete", -1, 14],
        ["loevad", -1, 14],
        ["loob", -1, 7],
        ["lood", -1, 7],
        ["loodi", 93, 7],
        ["looks", -1, 7],
        ["looksid", 95, 7],
        ["looksime", 95, 7],
        ["looksin", 95, 7],
        ["looksite", 95, 7],
        ["looma", -1, 7],
        ["loomata", 100, 7],
        ["loome", -1, 7],
        ["loon", -1, 7],
        ["loote", -1, 7],
        ["loovad", -1, 7],
        ["luua", -1, 7],
        ["luuakse", 106, 7],
        ["l\u{00F5}i", -1, 6],
        ["l\u{00F5}id", 108, 6],
        ["l\u{00F5}ime", 108, 6],
        ["l\u{00F5}in", 108, 6],
        ["l\u{00F5}ite", 108, 6],
        ["l\u{00F6}\u{00F6}b", -1, 5],
        ["l\u{00F6}\u{00F6}d", -1, 5],
        ["l\u{00F6}\u{00F6}dakse", 114, 5],
        ["l\u{00F6}\u{00F6}di", 114, 5],
        ["l\u{00F6}\u{00F6}ks", -1, 5],
        ["l\u{00F6}\u{00F6}ksid", 117, 5],
        ["l\u{00F6}\u{00F6}ksime", 117, 5],
        ["l\u{00F6}\u{00F6}ksin", 117, 5],
        ["l\u{00F6}\u{00F6}ksite", 117, 5],
        ["l\u{00F6}\u{00F6}ma", -1, 5],
        ["l\u{00F6}\u{00F6}mata", 122, 5],
        ["l\u{00F6}\u{00F6}me", -1, 5],
        ["l\u{00F6}\u{00F6}n", -1, 5],
        ["l\u{00F6}\u{00F6}te", -1, 5],
        ["l\u{00F6}\u{00F6}vad", -1, 5],
        ["l\u{00FC}\u{00FC}a", -1, 5],
        ["l\u{00FC}\u{00FC}akse", 128, 5],
        ["m\u{00FC}\u{00FC}a", -1, 13],
        ["m\u{00FC}\u{00FC}akse", 130, 13],
        ["m\u{00FC}\u{00FC}b", -1, 13],
        ["m\u{00FC}\u{00FC}d", -1, 13],
        ["m\u{00FC}\u{00FC}di", 133, 13],
        ["m\u{00FC}\u{00FC}ks", -1, 13],
        ["m\u{00FC}\u{00FC}ksid", 135, 13],
        ["m\u{00FC}\u{00FC}ksime", 135, 13],
        ["m\u{00FC}\u{00FC}ksin", 135, 13],
        ["m\u{00FC}\u{00FC}ksite", 135, 13],
        ["m\u{00FC}\u{00FC}ma", -1, 13],
        ["m\u{00FC}\u{00FC}mata", 140, 13],
        ["m\u{00FC}\u{00FC}me", -1, 13],
        ["m\u{00FC}\u{00FC}n", -1, 13],
        ["m\u{00FC}\u{00FC}s", -1, 13],
        ["m\u{00FC}\u{00FC}te", -1, 13],
        ["m\u{00FC}\u{00FC}vad", -1, 13],
        ["n\u{00E4}eb", -1, 18],
        ["n\u{00E4}ed", -1, 18],
        ["n\u{00E4}eks", -1, 18],
        ["n\u{00E4}eksid", 149, 18],
        ["n\u{00E4}eksime", 149, 18],
        ["n\u{00E4}eksin", 149, 18],
        ["n\u{00E4}eksite", 149, 18],
        ["n\u{00E4}eme", -1, 18],
        ["n\u{00E4}en", -1, 18],
        ["n\u{00E4}ete", -1, 18],
        ["n\u{00E4}evad", -1, 18],
        ["n\u{00E4}gema", -1, 18],
        ["n\u{00E4}gemata", 158, 18],
        ["n\u{00E4}ha", -1, 18],
        ["n\u{00E4}hakse", 160, 18],
        ["n\u{00E4}hti", -1, 18],
        ["p\u{00F5}eb", -1, 15],
        ["p\u{00F5}ed", -1, 15],
        ["p\u{00F5}eks", -1, 15],
        ["p\u{00F5}eksid", 165, 15],
        ["p\u{00F5}eksime", 165, 15],
        ["p\u{00F5}eksin", 165, 15],
        ["p\u{00F5}eksite", 165, 15],
        ["p\u{00F5}eme", -1, 15],
        ["p\u{00F5}en", -1, 15],
        ["p\u{00F5}ete", -1, 15],
        ["p\u{00F5}evad", -1, 15],
        ["saab", -1, 2],
        ["saad", -1, 2],
        ["saada", 175, 2],
        ["saadakse", 176, 2],
        ["saadi", 175, 2],
        ["saaks", -1, 2],
        ["saaksid", 179, 2],
        ["saaksime", 179, 2],
        ["saaksin", 179, 2],
        ["saaksite", 179, 2],
        ["saama", -1, 2],
        ["saamata", 184, 2],
        ["saame", -1, 2],
        ["saan", -1, 2],
        ["saate", -1, 2],
        ["saavad", -1, 2],
        ["sai", -1, 2],
        ["said", 190, 2],
        ["saime", 190, 2],
        ["sain", 190, 2],
        ["saite", 190, 2],
        ["s\u{00F5}i", -1, 9],
        ["s\u{00F5}id", 195, 9],
        ["s\u{00F5}ime", 195, 9],
        ["s\u{00F5}in", 195, 9],
        ["s\u{00F5}ite", 195, 9],
        ["s\u{00F6}\u{00F6}b", -1, 9],
        ["s\u{00F6}\u{00F6}d", -1, 9],
        ["s\u{00F6}\u{00F6}dakse", 201, 9],
        ["s\u{00F6}\u{00F6}di", 201, 9],
        ["s\u{00F6}\u{00F6}ks", -1, 9],
        ["s\u{00F6}\u{00F6}ksid", 204, 9],
        ["s\u{00F6}\u{00F6}ksime", 204, 9],
        ["s\u{00F6}\u{00F6}ksin", 204, 9],
        ["s\u{00F6}\u{00F6}ksite", 204, 9],
        ["s\u{00F6}\u{00F6}ma", -1, 9],
        ["s\u{00F6}\u{00F6}mata", 209, 9],
        ["s\u{00F6}\u{00F6}me", -1, 9],
        ["s\u{00F6}\u{00F6}n", -1, 9],
        ["s\u{00F6}\u{00F6}te", -1, 9],
        ["s\u{00F6}\u{00F6}vad", -1, 9],
        ["s\u{00FC}\u{00FC}a", -1, 9],
        ["s\u{00FC}\u{00FC}akse", 215, 9],
        ["teeb", -1, 17],
        ["teed", -1, 17],
        ["teeks", -1, 17],
        ["teeksid", 219, 17],
        ["teeksime", 219, 17],
        ["teeksin", 219, 17],
        ["teeksite", 219, 17],
        ["teeme", -1, 17],
        ["teen", -1, 17],
        ["teete", -1, 17],
        ["teevad", -1, 17],
        ["tegema", -1, 17],
        ["tegemata", 228, 17],
        ["teha", -1, 17],
        ["tehakse", 230, 17],
        ["tehti", -1, 17],
        ["toob", -1, 10],
        ["tood", -1, 10],
        ["toodi", 234, 10],
        ["tooks", -1, 10],
        ["tooksid", 236, 10],
        ["tooksime", 236, 10],
        ["tooksin", 236, 10],
        ["tooksite", 236, 10],
        ["tooma", -1, 10],
        ["toomata", 241, 10],
        ["toome", -1, 10],
        ["toon", -1, 10],
        ["toote", -1, 10],
        ["toovad", -1, 10],
        ["tuua", -1, 10],
        ["tuuakse", 247, 10],
        ["t\u{00F5}i", -1, 10],
        ["t\u{00F5}id", 249, 10],
        ["t\u{00F5}ime", 249, 10],
        ["t\u{00F5}in", 249, 10],
        ["t\u{00F5}ite", 249, 10],
        ["viia", -1, 3],
        ["viiakse", 254, 3],
        ["viib", -1, 3],
        ["viid", -1, 3],
        ["viidi", 257, 3],
        ["viiks", -1, 3],
        ["viiksid", 259, 3],
        ["viiksime", 259, 3],
        ["viiksin", 259, 3],
        ["viiksite", 259, 3],
        ["viima", -1, 3],
        ["viimata", 264, 3],
        ["viime", -1, 3],
        ["viin", -1, 3],
        ["viisime", -1, 3],
        ["viisin", -1, 3],
        ["viisite", -1, 3],
        ["viite", -1, 3],
        ["viivad", -1, 3],
        ["v\u{00F5}ib", -1, 11],
        ["v\u{00F5}id", -1, 11],
        ["v\u{00F5}ida", 274, 11],
        ["v\u{00F5}idakse", 275, 11],
        ["v\u{00F5}idi", 274, 11],
        ["v\u{00F5}iks", -1, 11],
        ["v\u{00F5}iksid", 278, 11],
        ["v\u{00F5}iksime", 278, 11],
        ["v\u{00F5}iksin", 278, 11],
        ["v\u{00F5}iksite", 278, 11],
        ["v\u{00F5}ima", -1, 11],
        ["v\u{00F5}imata", 283, 11],
        ["v\u{00F5}ime", -1, 11],
        ["v\u{00F5}in", -1, 11],
        ["v\u{00F5}is", -1, 11],
        ["v\u{00F5}ite", -1, 11],
        ["v\u{00F5}ivad", -1, 11]
    ];

    private const array G_V1 = ["a"=>true, "e"=>true, "i"=>true, "o"=>true, "u"=>true, "\u{00E4}"=>true, "\u{00F5}"=>true, "\u{00F6}"=>true, "\u{00FC}"=>true];

    private const array G_RV = ["a"=>true, "e"=>true, "i"=>true, "o"=>true, "u"=>true];

    private const array G_KI = ["b"=>true, "d"=>true, "f"=>true, "g"=>true, "h"=>true, "k"=>true, "p"=>true, "s"=>true, "t"=>true, "z"=>true, "\u{0161}"=>true, "\u{017E}"=>true];

    private const array G_GI = ["a"=>true, "c"=>true, "e"=>true, "i"=>true, "j"=>true, "l"=>true, "m"=>true, "n"=>true, "o"=>true, "q"=>true, "r"=>true, "u"=>true, "v"=>true, "w"=>true, "x"=>true, "\u{00E4}"=>true, "\u{00F5}"=>true, "\u{00F6}"=>true, "\u{00FC}"=>true];

    private int $I_p1 = 0;



    protected function r_mark_regions(): bool
    {
        $this->I_p1 = $this->limit;
        if (!$this->go_out_grouping(self::G_V1)) {
            return false;
        }
        $this->inc_cursor();
        if (!$this->go_in_grouping(self::G_V1)) {
            return false;
        }
        $this->inc_cursor();
        $this->I_p1 = $this->cursor;
        return true;
    }


    protected function r_emphasis(): bool
    {
        if ($this->cursor < $this->I_p1) {
            return false;
        }
        $v_1 = $this->limit_backward;
        $this->limit_backward = $this->I_p1;
        $this->ket = $this->cursor;
        $among_var = $this->find_among_b(self::A_0);
        if (0 === $among_var) {
            $this->limit_backward = $v_1;
            return false;
        }
        $this->bra = $this->cursor;
        $this->limit_backward = $v_1;
        $v_2 = $this->limit - $this->cursor;
        if (!$this->hop_back(4)) {
            return false;
        }
        $this->cursor = $this->limit - $v_2;
        switch ($among_var) {
            case 1:
                $v_3 = $this->limit - $this->cursor;
                if (!($this->in_grouping_b(self::G_GI))) {
                    return false;
                }
                $this->cursor = $this->limit - $v_3;
                $v_4 = $this->limit - $this->cursor;
                if (!$this->r_LONGV()) {
                    goto lab0;
                }
                return false;
            lab0:
                $this->cursor = $this->limit - $v_4;
                $this->slice_del();
                break;
            case 2:
                if (!($this->in_grouping_b(self::G_KI))) {
                    return false;
                }
                $this->slice_del();
                break;
        }
        return true;
    }


    protected function r_verb(): bool
    {
        if ($this->cursor < $this->I_p1) {
            return false;
        }
        $v_1 = $this->limit_backward;
        $this->limit_backward = $this->I_p1;
        $this->ket = $this->cursor;
        $among_var = $this->find_among_b(self::A_1);
        if (0 === $among_var) {
            $this->limit_backward = $v_1;
            return false;
        }
        $this->bra = $this->cursor;
        $this->limit_backward = $v_1;
        switch ($among_var) {
            case 1:
                $this->slice_del();
                break;
            case 2:
                $this->slice_from("a");
                break;
            case 3:
                if (!($this->in_grouping_b(self::G_V1))) {
                    return false;
                }
                $this->slice_del();
                break;
        }
        return true;
    }


    protected function r_LONGV(): bool
    {
        return $this->find_among_b(self::A_2) !== 0;
    }


    protected function r_i_plural(): bool
    {
        if ($this->cursor < $this->I_p1) {
            return false;
        }
        $v_1 = $this->limit_backward;
        $this->limit_backward = $this->I_p1;
        $this->ket = $this->cursor;
        if (!($this->eq_s_b("i"))) {
            $this->limit_backward = $v_1;
            return false;
        }
        $this->bra = $this->cursor;
        $this->limit_backward = $v_1;
        if (!($this->in_grouping_b(self::G_RV))) {
            return false;
        }
        $this->slice_del();
        return true;
    }


    protected function r_special_noun_endings(): bool
    {
        if ($this->cursor < $this->I_p1) {
            return false;
        }
        $v_1 = $this->limit_backward;
        $this->limit_backward = $this->I_p1;
        $this->ket = $this->cursor;
        $among_var = $this->find_among_b(self::A_3);
        if (0 === $among_var) {
            $this->limit_backward = $v_1;
            return false;
        }
        $this->bra = $this->cursor;
        $this->limit_backward = $v_1;
        switch ($among_var) {
            case 1:
                $this->slice_from("lase");
                break;
            case 2:
                $this->slice_from("mise");
                break;
            case 3:
                $this->slice_from("lise");
                break;
        }
        return true;
    }


    protected function r_case_ending(): bool
    {
        if ($this->cursor < $this->I_p1) {
            return false;
        }
        $v_1 = $this->limit_backward;
        $this->limit_backward = $this->I_p1;
        $this->ket = $this->cursor;
        $among_var = $this->find_among_b(self::A_4);
        if (0 === $among_var) {
            $this->limit_backward = $v_1;
            return false;
        }
        $this->bra = $this->cursor;
        $this->limit_backward = $v_1;
        switch ($among_var) {
            case 1:
                $v_2 = $this->limit - $this->cursor;
                if (!($this->in_grouping_b(self::G_RV))) {
                    goto lab0;
                }
                goto lab1;
            lab0:
                $this->cursor = $this->limit - $v_2;
                if (!$this->r_LONGV()) {
                    return false;
                }
            lab1:
                break;
            case 2:
                $v_3 = $this->limit - $this->cursor;
                if (!$this->hop_back(4)) {
                    return false;
                }
                $this->cursor = $this->limit - $v_3;
                break;
        }
        $this->slice_del();
        return true;
    }


    protected function r_plural_three_first_cases(): bool
    {
        if ($this->cursor < $this->I_p1) {
            return false;
        }
        $v_1 = $this->limit_backward;
        $this->limit_backward = $this->I_p1;
        $this->ket = $this->cursor;
        $among_var = $this->find_among_b(self::A_6);
        if (0 === $among_var) {
            $this->limit_backward = $v_1;
            return false;
        }
        $this->bra = $this->cursor;
        $this->limit_backward = $v_1;
        switch ($among_var) {
            case 1:
                $this->slice_from("iku");
                break;
            case 2:
                $v_2 = $this->limit - $this->cursor;
                if (!$this->r_LONGV()) {
                    goto lab0;
                }
                return false;
            lab0:
                $this->cursor = $this->limit - $v_2;
                $this->slice_del();
                break;
            case 3:
                $v_3 = $this->limit - $this->cursor;
                $v_4 = $this->limit - $this->cursor;
                if (!$this->hop_back(4)) {
                    goto lab1;
                }
                $this->cursor = $this->limit - $v_4;
                $among_var = $this->find_among_b(self::A_5);
                switch ($among_var) {
                    case 1:
                        $this->slice_from("e");
                        break;
                    case 2:
                        $this->slice_del();
                        break;
                }
                goto lab2;
            lab1:
                $this->cursor = $this->limit - $v_3;
                $this->slice_from("t");
            lab2:
                break;
            case 4:
                $v_5 = $this->limit - $this->cursor;
                if (!($this->in_grouping_b(self::G_RV))) {
                    goto lab3;
                }
                goto lab4;
            lab3:
                $this->cursor = $this->limit - $v_5;
                if (!$this->r_LONGV()) {
                    return false;
                }
            lab4:
                $this->slice_del();
                break;
        }
        return true;
    }


    protected function r_nu(): bool
    {
        if ($this->cursor < $this->I_p1) {
            return false;
        }
        $v_1 = $this->limit_backward;
        $this->limit_backward = $this->I_p1;
        $this->ket = $this->cursor;
        if ($this->find_among_b(self::A_7) === 0) {
            $this->limit_backward = $v_1;
            return false;
        }
        $this->bra = $this->cursor;
        $this->limit_backward = $v_1;
        $this->slice_del();
        return true;
    }


    protected function r_undouble_kpt(): bool
    {
        if (!($this->in_grouping_b(self::G_V1))) {
            return false;
        }
        if ($this->I_p1 > $this->cursor) {
            return false;
        }
        $this->ket = $this->cursor;
        $among_var = $this->find_among_b(self::A_8);
        if (0 === $among_var) {
            return false;
        }
        $this->bra = $this->cursor;
        switch ($among_var) {
            case 1:
                $this->slice_from("k");
                break;
            case 2:
                $this->slice_from("p");
                break;
            case 3:
                $this->slice_from("t");
                break;
        }
        return true;
    }


    protected function r_degrees(): bool
    {
        if ($this->cursor < $this->I_p1) {
            return false;
        }
        $v_1 = $this->limit_backward;
        $this->limit_backward = $this->I_p1;
        $this->ket = $this->cursor;
        $among_var = $this->find_among_b(self::A_9);
        if (0 === $among_var) {
            $this->limit_backward = $v_1;
            return false;
        }
        $this->bra = $this->cursor;
        $this->limit_backward = $v_1;
        switch ($among_var) {
            case 1:
                if (!($this->in_grouping_b(self::G_RV))) {
                    return false;
                }
                $this->slice_del();
                break;
            case 2:
                $this->slice_del();
                break;
        }
        return true;
    }


    protected function r_substantive(): bool
    {
        $v_1 = $this->limit - $this->cursor;
        $this->r_special_noun_endings();
        $this->cursor = $this->limit - $v_1;
        $v_2 = $this->limit - $this->cursor;
        $this->r_case_ending();
        $this->cursor = $this->limit - $v_2;
        $v_3 = $this->limit - $this->cursor;
        $this->r_plural_three_first_cases();
        $this->cursor = $this->limit - $v_3;
        $v_4 = $this->limit - $this->cursor;
        $this->r_degrees();
        $this->cursor = $this->limit - $v_4;
        $v_5 = $this->limit - $this->cursor;
        $this->r_i_plural();
        $this->cursor = $this->limit - $v_5;
        $v_6 = $this->limit - $this->cursor;
        $this->r_nu();
        $this->cursor = $this->limit - $v_6;
        return true;
    }


    protected function r_verb_exceptions(): bool
    {
        $this->bra = $this->cursor;
        $among_var = $this->find_among(self::A_10);
        if (0 === $among_var) {
            return false;
        }
        $this->ket = $this->cursor;
        if ($this->cursor < $this->limit) {
            return false;
        }
        switch ($among_var) {
            case 1:
                $this->slice_from("joo");
                break;
            case 2:
                $this->slice_from("saa");
                break;
            case 3:
                $this->slice_from("viima");
                break;
            case 4:
                $this->slice_from("keesi");
                break;
            case 5:
                $this->slice_from("l\u{00F6}\u{00F6}");
                break;
            case 6:
                $this->slice_from("l\u{00F5}i");
                break;
            case 7:
                $this->slice_from("loo");
                break;
            case 8:
                $this->slice_from("k\u{00E4}isi");
                break;
            case 9:
                $this->slice_from("s\u{00F6}\u{00F6}");
                break;
            case 10:
                $this->slice_from("too");
                break;
            case 11:
                $this->slice_from("v\u{00F5}isi");
                break;
            case 12:
                $this->slice_from("j\u{00E4}\u{00E4}ma");
                break;
            case 13:
                $this->slice_from("m\u{00FC}\u{00FC}si");
                break;
            case 14:
                $this->slice_from("luge");
                break;
            case 15:
                $this->slice_from("p\u{00F5}de");
                break;
            case 16:
                $this->slice_from("ladu");
                break;
            case 17:
                $this->slice_from("tegi");
                break;
            case 18:
                $this->slice_from("n\u{00E4}gi");
                break;
        }
        return true;
    }


    public function stem(): bool
    {
        $v_1 = $this->cursor;
        if (!$this->r_verb_exceptions()) {
            goto lab0;
        }
        return false;
    lab0:
        $this->cursor = $v_1;
        $v_2 = $this->cursor;
        $this->r_mark_regions();
        $this->cursor = $v_2;
        $this->limit_backward = $this->cursor;
        $this->cursor = $this->limit;
        $v_3 = $this->limit - $this->cursor;
        $this->r_emphasis();
        $this->cursor = $this->limit - $v_3;
        $v_4 = $this->limit - $this->cursor;
        $v_5 = $this->limit - $this->cursor;
        if (!$this->r_verb()) {
            goto lab2;
        }
        goto lab3;
    lab2:
        $this->cursor = $this->limit - $v_5;
        $this->r_substantive();
    lab3:
    lab1:
        $this->cursor = $this->limit - $v_4;
        $v_6 = $this->limit - $this->cursor;
        $this->r_undouble_kpt();
        $this->cursor = $this->limit - $v_6;
        $this->cursor = $this->limit_backward;
        return true;
    }
}
