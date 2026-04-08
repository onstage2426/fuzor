<?php

namespace Fuzor\Stemmers;

// Generated from turkish.sbl by Snowball 3.0.0 - https://snowballstem.org/

class SnowballTurkish extends SnowballStemmer
{
    private const array A_0 = [
        ["m", -1, -1],
        ["n", -1, -1],
        ["miz", -1, -1],
        ["niz", -1, -1],
        ["muz", -1, -1],
        ["nuz", -1, -1],
        ["m\u{0131}z", -1, -1],
        ["n\u{0131}z", -1, -1],
        ["m\u{00FC}z", -1, -1],
        ["n\u{00FC}z", -1, -1]
    ];

    private const array A_1 = [
        ["leri", -1, -1],
        ["lar\u{0131}", -1, -1]
    ];

    private const array A_2 = [
        ["ni", -1, -1],
        ["nu", -1, -1],
        ["n\u{0131}", -1, -1],
        ["n\u{00FC}", -1, -1]
    ];

    private const array A_3 = [
        ["in", -1, -1],
        ["un", -1, -1],
        ["\u{0131}n", -1, -1],
        ["\u{00FC}n", -1, -1]
    ];

    private const array A_4 = [
        ["a", -1, -1],
        ["e", -1, -1]
    ];

    private const array A_5 = [
        ["na", -1, -1],
        ["ne", -1, -1]
    ];

    private const array A_6 = [
        ["da", -1, -1],
        ["ta", -1, -1],
        ["de", -1, -1],
        ["te", -1, -1]
    ];

    private const array A_7 = [
        ["nda", -1, -1],
        ["nde", -1, -1]
    ];

    private const array A_8 = [
        ["dan", -1, -1],
        ["tan", -1, -1],
        ["den", -1, -1],
        ["ten", -1, -1]
    ];

    private const array A_9 = [
        ["ndan", -1, -1],
        ["nden", -1, -1]
    ];

    private const array A_10 = [
        ["la", -1, -1],
        ["le", -1, -1]
    ];

    private const array A_11 = [
        ["ca", -1, -1],
        ["ce", -1, -1]
    ];

    private const array A_12 = [
        ["im", -1, -1],
        ["um", -1, -1],
        ["\u{0131}m", -1, -1],
        ["\u{00FC}m", -1, -1]
    ];

    private const array A_13 = [
        ["sin", -1, -1],
        ["sun", -1, -1],
        ["s\u{0131}n", -1, -1],
        ["s\u{00FC}n", -1, -1]
    ];

    private const array A_14 = [
        ["iz", -1, -1],
        ["uz", -1, -1],
        ["\u{0131}z", -1, -1],
        ["\u{00FC}z", -1, -1]
    ];

    private const array A_15 = [
        ["siniz", -1, -1],
        ["sunuz", -1, -1],
        ["s\u{0131}n\u{0131}z", -1, -1],
        ["s\u{00FC}n\u{00FC}z", -1, -1]
    ];

    private const array A_16 = [
        ["lar", -1, -1],
        ["ler", -1, -1]
    ];

    private const array A_17 = [
        ["niz", -1, -1],
        ["nuz", -1, -1],
        ["n\u{0131}z", -1, -1],
        ["n\u{00FC}z", -1, -1]
    ];

    private const array A_18 = [
        ["dir", -1, -1],
        ["tir", -1, -1],
        ["dur", -1, -1],
        ["tur", -1, -1],
        ["d\u{0131}r", -1, -1],
        ["t\u{0131}r", -1, -1],
        ["d\u{00FC}r", -1, -1],
        ["t\u{00FC}r", -1, -1]
    ];

    private const array A_19 = [
        ["cas\u{0131}na", -1, -1],
        ["cesine", -1, -1]
    ];

    private const array A_20 = [
        ["di", -1, -1],
        ["ti", -1, -1],
        ["dik", -1, -1],
        ["tik", -1, -1],
        ["duk", -1, -1],
        ["tuk", -1, -1],
        ["d\u{0131}k", -1, -1],
        ["t\u{0131}k", -1, -1],
        ["d\u{00FC}k", -1, -1],
        ["t\u{00FC}k", -1, -1],
        ["dim", -1, -1],
        ["tim", -1, -1],
        ["dum", -1, -1],
        ["tum", -1, -1],
        ["d\u{0131}m", -1, -1],
        ["t\u{0131}m", -1, -1],
        ["d\u{00FC}m", -1, -1],
        ["t\u{00FC}m", -1, -1],
        ["din", -1, -1],
        ["tin", -1, -1],
        ["dun", -1, -1],
        ["tun", -1, -1],
        ["d\u{0131}n", -1, -1],
        ["t\u{0131}n", -1, -1],
        ["d\u{00FC}n", -1, -1],
        ["t\u{00FC}n", -1, -1],
        ["du", -1, -1],
        ["tu", -1, -1],
        ["d\u{0131}", -1, -1],
        ["t\u{0131}", -1, -1],
        ["d\u{00FC}", -1, -1],
        ["t\u{00FC}", -1, -1]
    ];

    private const array A_21 = [
        ["sa", -1, -1],
        ["se", -1, -1],
        ["sak", -1, -1],
        ["sek", -1, -1],
        ["sam", -1, -1],
        ["sem", -1, -1],
        ["san", -1, -1],
        ["sen", -1, -1]
    ];

    private const array A_22 = [
        ["mi\u{015F}", -1, -1],
        ["mu\u{015F}", -1, -1],
        ["m\u{0131}\u{015F}", -1, -1],
        ["m\u{00FC}\u{015F}", -1, -1]
    ];

    private const array A_23 = [
        ["b", -1, 1],
        ["c", -1, 2],
        ["d", -1, 3],
        ["\u{011F}", -1, 4]
    ];

    private const array G_vowel = ["a"=>true, "e"=>true, "i"=>true, "o"=>true, "u"=>true, "\u{00F6}"=>true, "\u{00FC}"=>true, "\u{0131}"=>true];

    private const array G_U = ["i"=>true, "u"=>true, "\u{00FC}"=>true, "\u{0131}"=>true];

    private const array G_vowel1 = ["a"=>true, "o"=>true, "u"=>true, "\u{0131}"=>true];

    private const array G_vowel2 = ["e"=>true, "i"=>true, "\u{00F6}"=>true, "\u{00FC}"=>true];

    private const array G_vowel3 = ["a"=>true, "\u{0131}"=>true];

    private const array G_vowel4 = ["e"=>true, "i"=>true];

    private const array G_vowel5 = ["o"=>true, "u"=>true];

    private const array G_vowel6 = ["\u{00F6}"=>true, "\u{00FC}"=>true];

    private bool $B_continue_stemming_noun_suffixes = false;



    protected function r_check_vowel_harmony(): bool
    {
        $v_1 = $this->limit - $this->cursor;
        if (!$this->go_out_grouping_b(self::G_vowel)) {
            return false;
        }
        $v_2 = $this->limit - $this->cursor;
        if (!($this->eq_s_b("a"))) {
            goto lab0;
        }
        if (!$this->go_out_grouping_b(self::G_vowel1)) {
            goto lab0;
        }
        goto lab1;
    lab0:
        $this->cursor = $this->limit - $v_2;
        if (!($this->eq_s_b("e"))) {
            goto lab2;
        }
        if (!$this->go_out_grouping_b(self::G_vowel2)) {
            goto lab2;
        }
        goto lab1;
    lab2:
        $this->cursor = $this->limit - $v_2;
        if (!($this->eq_s_b("\u{0131}"))) {
            goto lab3;
        }
        if (!$this->go_out_grouping_b(self::G_vowel3)) {
            goto lab3;
        }
        goto lab1;
    lab3:
        $this->cursor = $this->limit - $v_2;
        if (!($this->eq_s_b("i"))) {
            goto lab4;
        }
        if (!$this->go_out_grouping_b(self::G_vowel4)) {
            goto lab4;
        }
        goto lab1;
    lab4:
        $this->cursor = $this->limit - $v_2;
        if (!($this->eq_s_b("o"))) {
            goto lab5;
        }
        if (!$this->go_out_grouping_b(self::G_vowel5)) {
            goto lab5;
        }
        goto lab1;
    lab5:
        $this->cursor = $this->limit - $v_2;
        if (!($this->eq_s_b("\u{00F6}"))) {
            goto lab6;
        }
        if (!$this->go_out_grouping_b(self::G_vowel6)) {
            goto lab6;
        }
        goto lab1;
    lab6:
        $this->cursor = $this->limit - $v_2;
        if (!($this->eq_s_b("u"))) {
            goto lab7;
        }
        if (!$this->go_out_grouping_b(self::G_vowel5)) {
            goto lab7;
        }
        goto lab1;
    lab7:
        $this->cursor = $this->limit - $v_2;
        if (!($this->eq_s_b("\u{00FC}"))) {
            return false;
        }
        if (!$this->go_out_grouping_b(self::G_vowel6)) {
            return false;
        }
    lab1:
        $this->cursor = $this->limit - $v_1;
        return true;
    }


    protected function r_mark_suffix_with_optional_n_consonant(): bool
    {
        $v_1 = $this->limit - $this->cursor;
        if (!($this->eq_s_b("n"))) {
            goto lab0;
        }
        $v_2 = $this->limit - $this->cursor;
        if (!($this->in_grouping_b(self::G_vowel))) {
            goto lab0;
        }
        $this->cursor = $this->limit - $v_2;
        goto lab1;
    lab0:
        $this->cursor = $this->limit - $v_1;
        $v_3 = $this->limit - $this->cursor;
        if (!($this->eq_s_b("n"))) {
            goto lab2;
        }
        return false;
    lab2:
        $this->cursor = $this->limit - $v_3;
        $v_4 = $this->limit - $this->cursor;
        if ($this->cursor <= $this->limit_backward) {
            return false;
        }
        $this->dec_cursor();
        if (!($this->in_grouping_b(self::G_vowel))) {
            return false;
        }
        $this->cursor = $this->limit - $v_4;
    lab1:
        return true;
    }


    protected function r_mark_suffix_with_optional_s_consonant(): bool
    {
        $v_1 = $this->limit - $this->cursor;
        if (!($this->eq_s_b("s"))) {
            goto lab0;
        }
        $v_2 = $this->limit - $this->cursor;
        if (!($this->in_grouping_b(self::G_vowel))) {
            goto lab0;
        }
        $this->cursor = $this->limit - $v_2;
        goto lab1;
    lab0:
        $this->cursor = $this->limit - $v_1;
        $v_3 = $this->limit - $this->cursor;
        if (!($this->eq_s_b("s"))) {
            goto lab2;
        }
        return false;
    lab2:
        $this->cursor = $this->limit - $v_3;
        $v_4 = $this->limit - $this->cursor;
        if ($this->cursor <= $this->limit_backward) {
            return false;
        }
        $this->dec_cursor();
        if (!($this->in_grouping_b(self::G_vowel))) {
            return false;
        }
        $this->cursor = $this->limit - $v_4;
    lab1:
        return true;
    }


    protected function r_mark_suffix_with_optional_y_consonant(): bool
    {
        $v_1 = $this->limit - $this->cursor;
        if (!($this->eq_s_b("y"))) {
            goto lab0;
        }
        $v_2 = $this->limit - $this->cursor;
        if (!($this->in_grouping_b(self::G_vowel))) {
            goto lab0;
        }
        $this->cursor = $this->limit - $v_2;
        goto lab1;
    lab0:
        $this->cursor = $this->limit - $v_1;
        $v_3 = $this->limit - $this->cursor;
        if (!($this->eq_s_b("y"))) {
            goto lab2;
        }
        return false;
    lab2:
        $this->cursor = $this->limit - $v_3;
        $v_4 = $this->limit - $this->cursor;
        if ($this->cursor <= $this->limit_backward) {
            return false;
        }
        $this->dec_cursor();
        if (!($this->in_grouping_b(self::G_vowel))) {
            return false;
        }
        $this->cursor = $this->limit - $v_4;
    lab1:
        return true;
    }


    protected function r_mark_suffix_with_optional_U_vowel(): bool
    {
        $v_1 = $this->limit - $this->cursor;
        if (!($this->in_grouping_b(self::G_U))) {
            goto lab0;
        }
        $v_2 = $this->limit - $this->cursor;
        if (!($this->out_grouping_b(self::G_vowel))) {
            goto lab0;
        }
        $this->cursor = $this->limit - $v_2;
        goto lab1;
    lab0:
        $this->cursor = $this->limit - $v_1;
        $v_3 = $this->limit - $this->cursor;
        if (!($this->in_grouping_b(self::G_U))) {
            goto lab2;
        }
        return false;
    lab2:
        $this->cursor = $this->limit - $v_3;
        $v_4 = $this->limit - $this->cursor;
        if ($this->cursor <= $this->limit_backward) {
            return false;
        }
        $this->dec_cursor();
        if (!($this->out_grouping_b(self::G_vowel))) {
            return false;
        }
        $this->cursor = $this->limit - $v_4;
    lab1:
        return true;
    }


    protected function r_mark_possessives(): bool
    {
        if ($this->find_among_b(self::A_0) === 0) {
            return false;
        }
        return $this->r_mark_suffix_with_optional_U_vowel();
    }


    protected function r_mark_sU(): bool
    {
        if (!$this->r_check_vowel_harmony()) {
            return false;
        }
        if (!($this->in_grouping_b(self::G_U))) {
            return false;
        }
        return $this->r_mark_suffix_with_optional_s_consonant();
    }


    protected function r_mark_lArI(): bool
    {
        return $this->find_among_b(self::A_1) !== 0;
    }


    protected function r_mark_yU(): bool
    {
        if (!$this->r_check_vowel_harmony()) {
            return false;
        }
        if (!($this->in_grouping_b(self::G_U))) {
            return false;
        }
        return $this->r_mark_suffix_with_optional_y_consonant();
    }


    protected function r_mark_nU(): bool
    {
        if (!$this->r_check_vowel_harmony()) {
            return false;
        }
        return $this->find_among_b(self::A_2) !== 0;
    }


    protected function r_mark_nUn(): bool
    {
        if (!$this->r_check_vowel_harmony()) {
            return false;
        }
        if ($this->find_among_b(self::A_3) === 0) {
            return false;
        }
        return $this->r_mark_suffix_with_optional_n_consonant();
    }


    protected function r_mark_yA(): bool
    {
        if (!$this->r_check_vowel_harmony()) {
            return false;
        }
        if ($this->find_among_b(self::A_4) === 0) {
            return false;
        }
        return $this->r_mark_suffix_with_optional_y_consonant();
    }


    protected function r_mark_nA(): bool
    {
        if (!$this->r_check_vowel_harmony()) {
            return false;
        }
        return $this->find_among_b(self::A_5) !== 0;
    }


    protected function r_mark_DA(): bool
    {
        if (!$this->r_check_vowel_harmony()) {
            return false;
        }
        return $this->find_among_b(self::A_6) !== 0;
    }


    protected function r_mark_ndA(): bool
    {
        if (!$this->r_check_vowel_harmony()) {
            return false;
        }
        return $this->find_among_b(self::A_7) !== 0;
    }


    protected function r_mark_DAn(): bool
    {
        if (!$this->r_check_vowel_harmony()) {
            return false;
        }
        return $this->find_among_b(self::A_8) !== 0;
    }


    protected function r_mark_ndAn(): bool
    {
        if (!$this->r_check_vowel_harmony()) {
            return false;
        }
        return $this->find_among_b(self::A_9) !== 0;
    }


    protected function r_mark_ylA(): bool
    {
        if (!$this->r_check_vowel_harmony()) {
            return false;
        }
        if ($this->find_among_b(self::A_10) === 0) {
            return false;
        }
        return $this->r_mark_suffix_with_optional_y_consonant();
    }


    protected function r_mark_ki(): bool
    {
        return $this->eq_s_b("ki");
    }


    protected function r_mark_ncA(): bool
    {
        if (!$this->r_check_vowel_harmony()) {
            return false;
        }
        if ($this->find_among_b(self::A_11) === 0) {
            return false;
        }
        return $this->r_mark_suffix_with_optional_n_consonant();
    }


    protected function r_mark_yUm(): bool
    {
        if (!$this->r_check_vowel_harmony()) {
            return false;
        }
        if ($this->find_among_b(self::A_12) === 0) {
            return false;
        }
        return $this->r_mark_suffix_with_optional_y_consonant();
    }


    protected function r_mark_sUn(): bool
    {
        if (!$this->r_check_vowel_harmony()) {
            return false;
        }
        return $this->find_among_b(self::A_13) !== 0;
    }


    protected function r_mark_yUz(): bool
    {
        if (!$this->r_check_vowel_harmony()) {
            return false;
        }
        if ($this->find_among_b(self::A_14) === 0) {
            return false;
        }
        return $this->r_mark_suffix_with_optional_y_consonant();
    }


    protected function r_mark_sUnUz(): bool
    {
        return $this->find_among_b(self::A_15) !== 0;
    }


    protected function r_mark_lAr(): bool
    {
        if (!$this->r_check_vowel_harmony()) {
            return false;
        }
        return $this->find_among_b(self::A_16) !== 0;
    }


    protected function r_mark_nUz(): bool
    {
        if (!$this->r_check_vowel_harmony()) {
            return false;
        }
        return $this->find_among_b(self::A_17) !== 0;
    }


    protected function r_mark_DUr(): bool
    {
        if (!$this->r_check_vowel_harmony()) {
            return false;
        }
        return $this->find_among_b(self::A_18) !== 0;
    }


    protected function r_mark_cAsInA(): bool
    {
        return $this->find_among_b(self::A_19) !== 0;
    }


    protected function r_mark_yDU(): bool
    {
        if (!$this->r_check_vowel_harmony()) {
            return false;
        }
        if ($this->find_among_b(self::A_20) === 0) {
            return false;
        }
        return $this->r_mark_suffix_with_optional_y_consonant();
    }


    protected function r_mark_ysA(): bool
    {
        if ($this->find_among_b(self::A_21) === 0) {
            return false;
        }
        return $this->r_mark_suffix_with_optional_y_consonant();
    }


    protected function r_mark_ymUs_(): bool
    {
        if (!$this->r_check_vowel_harmony()) {
            return false;
        }
        if ($this->find_among_b(self::A_22) === 0) {
            return false;
        }
        return $this->r_mark_suffix_with_optional_y_consonant();
    }


    protected function r_mark_yken(): bool
    {
        if (!($this->eq_s_b("ken"))) {
            return false;
        }
        return $this->r_mark_suffix_with_optional_y_consonant();
    }


    protected function r_stem_nominal_verb_suffixes(): bool
    {
        $this->ket = $this->cursor;
        $this->B_continue_stemming_noun_suffixes = true;
        $v_1 = $this->limit - $this->cursor;
        $v_2 = $this->limit - $this->cursor;
        if (!$this->r_mark_ymUs_()) {
            goto lab1;
        }
        goto lab2;
    lab1:
        $this->cursor = $this->limit - $v_2;
        if (!$this->r_mark_yDU()) {
            goto lab3;
        }
        goto lab2;
    lab3:
        $this->cursor = $this->limit - $v_2;
        if (!$this->r_mark_ysA()) {
            goto lab4;
        }
        goto lab2;
    lab4:
        $this->cursor = $this->limit - $v_2;
        if (!$this->r_mark_yken()) {
            goto lab0;
        }
    lab2:
        goto lab5;
    lab0:
        $this->cursor = $this->limit - $v_1;
        if (!$this->r_mark_cAsInA()) {
            goto lab6;
        }
        $v_3 = $this->limit - $this->cursor;
        if (!$this->r_mark_sUnUz()) {
            goto lab7;
        }
        goto lab8;
    lab7:
        $this->cursor = $this->limit - $v_3;
        if (!$this->r_mark_lAr()) {
            goto lab9;
        }
        goto lab8;
    lab9:
        $this->cursor = $this->limit - $v_3;
        if (!$this->r_mark_yUm()) {
            goto lab10;
        }
        goto lab8;
    lab10:
        $this->cursor = $this->limit - $v_3;
        if (!$this->r_mark_sUn()) {
            goto lab11;
        }
        goto lab8;
    lab11:
        $this->cursor = $this->limit - $v_3;
        if (!$this->r_mark_yUz()) {
            goto lab12;
        }
        goto lab8;
    lab12:
        $this->cursor = $this->limit - $v_3;
    lab8:
        if (!$this->r_mark_ymUs_()) {
            goto lab6;
        }
        goto lab5;
    lab6:
        $this->cursor = $this->limit - $v_1;
        if (!$this->r_mark_lAr()) {
            goto lab13;
        }
        $this->bra = $this->cursor;
        $this->slice_del();
        $v_4 = $this->limit - $this->cursor;
        $this->ket = $this->cursor;
        $v_5 = $this->limit - $this->cursor;
        if (!$this->r_mark_DUr()) {
            goto lab15;
        }
        goto lab16;
    lab15:
        $this->cursor = $this->limit - $v_5;
        if (!$this->r_mark_yDU()) {
            goto lab17;
        }
        goto lab16;
    lab17:
        $this->cursor = $this->limit - $v_5;
        if (!$this->r_mark_ysA()) {
            goto lab18;
        }
        goto lab16;
    lab18:
        $this->cursor = $this->limit - $v_5;
        if (!$this->r_mark_ymUs_()) {
            $this->cursor = $this->limit - $v_4;
            goto lab14;
        }
    lab16:
    lab14:
        $this->B_continue_stemming_noun_suffixes = false;
        goto lab5;
    lab13:
        $this->cursor = $this->limit - $v_1;
        if (!$this->r_mark_nUz()) {
            goto lab19;
        }
        $v_6 = $this->limit - $this->cursor;
        if (!$this->r_mark_yDU()) {
            goto lab20;
        }
        goto lab21;
    lab20:
        $this->cursor = $this->limit - $v_6;
        if (!$this->r_mark_ysA()) {
            goto lab19;
        }
    lab21:
        goto lab5;
    lab19:
        $this->cursor = $this->limit - $v_1;
        $v_7 = $this->limit - $this->cursor;
        if (!$this->r_mark_sUnUz()) {
            goto lab23;
        }
        goto lab24;
    lab23:
        $this->cursor = $this->limit - $v_7;
        if (!$this->r_mark_yUz()) {
            goto lab25;
        }
        goto lab24;
    lab25:
        $this->cursor = $this->limit - $v_7;
        if (!$this->r_mark_sUn()) {
            goto lab26;
        }
        goto lab24;
    lab26:
        $this->cursor = $this->limit - $v_7;
        if (!$this->r_mark_yUm()) {
            goto lab22;
        }
    lab24:
        $this->bra = $this->cursor;
        $this->slice_del();
        $v_8 = $this->limit - $this->cursor;
        $this->ket = $this->cursor;
        if (!$this->r_mark_ymUs_()) {
            $this->cursor = $this->limit - $v_8;
            goto lab27;
        }
    lab27:
        goto lab5;
    lab22:
        $this->cursor = $this->limit - $v_1;
        if (!$this->r_mark_DUr()) {
            return false;
        }
        $this->bra = $this->cursor;
        $this->slice_del();
        $v_9 = $this->limit - $this->cursor;
        $this->ket = $this->cursor;
        $v_10 = $this->limit - $this->cursor;
        if (!$this->r_mark_sUnUz()) {
            goto lab29;
        }
        goto lab30;
    lab29:
        $this->cursor = $this->limit - $v_10;
        if (!$this->r_mark_lAr()) {
            goto lab31;
        }
        goto lab30;
    lab31:
        $this->cursor = $this->limit - $v_10;
        if (!$this->r_mark_yUm()) {
            goto lab32;
        }
        goto lab30;
    lab32:
        $this->cursor = $this->limit - $v_10;
        if (!$this->r_mark_sUn()) {
            goto lab33;
        }
        goto lab30;
    lab33:
        $this->cursor = $this->limit - $v_10;
        if (!$this->r_mark_yUz()) {
            goto lab34;
        }
        goto lab30;
    lab34:
        $this->cursor = $this->limit - $v_10;
    lab30:
        if (!$this->r_mark_ymUs_()) {
            $this->cursor = $this->limit - $v_9;
            goto lab28;
        }
    lab28:
    lab5:
        $this->bra = $this->cursor;
        $this->slice_del();
        return true;
    }


    protected function r_stem_suffix_chain_before_ki(): bool
    {
        $this->ket = $this->cursor;
        if (!$this->r_mark_ki()) {
            return false;
        }
        $v_1 = $this->limit - $this->cursor;
        if (!$this->r_mark_DA()) {
            goto lab0;
        }
        $this->bra = $this->cursor;
        $this->slice_del();
        $v_2 = $this->limit - $this->cursor;
        $this->ket = $this->cursor;
        $v_3 = $this->limit - $this->cursor;
        if (!$this->r_mark_lAr()) {
            goto lab2;
        }
        $this->bra = $this->cursor;
        $this->slice_del();
        $v_4 = $this->limit - $this->cursor;
        if (!$this->r_stem_suffix_chain_before_ki()) {
            $this->cursor = $this->limit - $v_4;
            goto lab3;
        }
    lab3:
        goto lab4;
    lab2:
        $this->cursor = $this->limit - $v_3;
        if (!$this->r_mark_possessives()) {
            $this->cursor = $this->limit - $v_2;
            goto lab1;
        }
        $this->bra = $this->cursor;
        $this->slice_del();
        $v_5 = $this->limit - $this->cursor;
        $this->ket = $this->cursor;
        if (!$this->r_mark_lAr()) {
            $this->cursor = $this->limit - $v_5;
            goto lab5;
        }
        $this->bra = $this->cursor;
        $this->slice_del();
        if (!$this->r_stem_suffix_chain_before_ki()) {
            $this->cursor = $this->limit - $v_5;
            goto lab5;
        }
    lab5:
    lab4:
    lab1:
        goto lab6;
    lab0:
        $this->cursor = $this->limit - $v_1;
        if (!$this->r_mark_nUn()) {
            goto lab7;
        }
        $this->bra = $this->cursor;
        $this->slice_del();
        $v_6 = $this->limit - $this->cursor;
        $this->ket = $this->cursor;
        $v_7 = $this->limit - $this->cursor;
        if (!$this->r_mark_lArI()) {
            goto lab9;
        }
        $this->bra = $this->cursor;
        $this->slice_del();
        goto lab10;
    lab9:
        $this->cursor = $this->limit - $v_7;
        $this->ket = $this->cursor;
        $v_8 = $this->limit - $this->cursor;
        if (!$this->r_mark_possessives()) {
            goto lab12;
        }
        goto lab13;
    lab12:
        $this->cursor = $this->limit - $v_8;
        if (!$this->r_mark_sU()) {
            goto lab11;
        }
    lab13:
        $this->bra = $this->cursor;
        $this->slice_del();
        $v_9 = $this->limit - $this->cursor;
        $this->ket = $this->cursor;
        if (!$this->r_mark_lAr()) {
            $this->cursor = $this->limit - $v_9;
            goto lab14;
        }
        $this->bra = $this->cursor;
        $this->slice_del();
        if (!$this->r_stem_suffix_chain_before_ki()) {
            $this->cursor = $this->limit - $v_9;
            goto lab14;
        }
    lab14:
        goto lab10;
    lab11:
        $this->cursor = $this->limit - $v_7;
        if (!$this->r_stem_suffix_chain_before_ki()) {
            $this->cursor = $this->limit - $v_6;
            goto lab8;
        }
    lab10:
    lab8:
        goto lab6;
    lab7:
        $this->cursor = $this->limit - $v_1;
        if (!$this->r_mark_ndA()) {
            return false;
        }
        $v_10 = $this->limit - $this->cursor;
        if (!$this->r_mark_lArI()) {
            goto lab15;
        }
        $this->bra = $this->cursor;
        $this->slice_del();
        goto lab16;
    lab15:
        $this->cursor = $this->limit - $v_10;
        if (!$this->r_mark_sU()) {
            goto lab17;
        }
        $this->bra = $this->cursor;
        $this->slice_del();
        $v_11 = $this->limit - $this->cursor;
        $this->ket = $this->cursor;
        if (!$this->r_mark_lAr()) {
            $this->cursor = $this->limit - $v_11;
            goto lab18;
        }
        $this->bra = $this->cursor;
        $this->slice_del();
        if (!$this->r_stem_suffix_chain_before_ki()) {
            $this->cursor = $this->limit - $v_11;
            goto lab18;
        }
    lab18:
        goto lab16;
    lab17:
        $this->cursor = $this->limit - $v_10;
        if (!$this->r_stem_suffix_chain_before_ki()) {
            return false;
        }
    lab16:
    lab6:
        return true;
    }


    protected function r_stem_noun_suffixes(): bool
    {
        $v_1 = $this->limit - $this->cursor;
        $this->ket = $this->cursor;
        if (!$this->r_mark_lAr()) {
            goto lab0;
        }
        $this->bra = $this->cursor;
        $this->slice_del();
        $v_2 = $this->limit - $this->cursor;
        if (!$this->r_stem_suffix_chain_before_ki()) {
            $this->cursor = $this->limit - $v_2;
            goto lab1;
        }
    lab1:
        goto lab2;
    lab0:
        $this->cursor = $this->limit - $v_1;
        $this->ket = $this->cursor;
        if (!$this->r_mark_ncA()) {
            goto lab3;
        }
        $this->bra = $this->cursor;
        $this->slice_del();
        $v_3 = $this->limit - $this->cursor;
        $v_4 = $this->limit - $this->cursor;
        $this->ket = $this->cursor;
        if (!$this->r_mark_lArI()) {
            goto lab5;
        }
        $this->bra = $this->cursor;
        $this->slice_del();
        goto lab6;
    lab5:
        $this->cursor = $this->limit - $v_4;
        $this->ket = $this->cursor;
        $v_5 = $this->limit - $this->cursor;
        if (!$this->r_mark_possessives()) {
            goto lab8;
        }
        goto lab9;
    lab8:
        $this->cursor = $this->limit - $v_5;
        if (!$this->r_mark_sU()) {
            goto lab7;
        }
    lab9:
        $this->bra = $this->cursor;
        $this->slice_del();
        $v_6 = $this->limit - $this->cursor;
        $this->ket = $this->cursor;
        if (!$this->r_mark_lAr()) {
            $this->cursor = $this->limit - $v_6;
            goto lab10;
        }
        $this->bra = $this->cursor;
        $this->slice_del();
        if (!$this->r_stem_suffix_chain_before_ki()) {
            $this->cursor = $this->limit - $v_6;
            goto lab10;
        }
    lab10:
        goto lab6;
    lab7:
        $this->cursor = $this->limit - $v_4;
        $this->ket = $this->cursor;
        if (!$this->r_mark_lAr()) {
            $this->cursor = $this->limit - $v_3;
            goto lab4;
        }
        $this->bra = $this->cursor;
        $this->slice_del();
        if (!$this->r_stem_suffix_chain_before_ki()) {
            $this->cursor = $this->limit - $v_3;
            goto lab4;
        }
    lab6:
    lab4:
        goto lab2;
    lab3:
        $this->cursor = $this->limit - $v_1;
        $this->ket = $this->cursor;
        $v_7 = $this->limit - $this->cursor;
        if (!$this->r_mark_ndA()) {
            goto lab12;
        }
        goto lab13;
    lab12:
        $this->cursor = $this->limit - $v_7;
        if (!$this->r_mark_nA()) {
            goto lab11;
        }
    lab13:
        $v_8 = $this->limit - $this->cursor;
        if (!$this->r_mark_lArI()) {
            goto lab14;
        }
        $this->bra = $this->cursor;
        $this->slice_del();
        goto lab15;
    lab14:
        $this->cursor = $this->limit - $v_8;
        if (!$this->r_mark_sU()) {
            goto lab16;
        }
        $this->bra = $this->cursor;
        $this->slice_del();
        $v_9 = $this->limit - $this->cursor;
        $this->ket = $this->cursor;
        if (!$this->r_mark_lAr()) {
            $this->cursor = $this->limit - $v_9;
            goto lab17;
        }
        $this->bra = $this->cursor;
        $this->slice_del();
        if (!$this->r_stem_suffix_chain_before_ki()) {
            $this->cursor = $this->limit - $v_9;
            goto lab17;
        }
    lab17:
        goto lab15;
    lab16:
        $this->cursor = $this->limit - $v_8;
        if (!$this->r_stem_suffix_chain_before_ki()) {
            goto lab11;
        }
    lab15:
        goto lab2;
    lab11:
        $this->cursor = $this->limit - $v_1;
        $this->ket = $this->cursor;
        $v_10 = $this->limit - $this->cursor;
        if (!$this->r_mark_ndAn()) {
            goto lab19;
        }
        goto lab20;
    lab19:
        $this->cursor = $this->limit - $v_10;
        if (!$this->r_mark_nU()) {
            goto lab18;
        }
    lab20:
        $v_11 = $this->limit - $this->cursor;
        if (!$this->r_mark_sU()) {
            goto lab21;
        }
        $this->bra = $this->cursor;
        $this->slice_del();
        $v_12 = $this->limit - $this->cursor;
        $this->ket = $this->cursor;
        if (!$this->r_mark_lAr()) {
            $this->cursor = $this->limit - $v_12;
            goto lab22;
        }
        $this->bra = $this->cursor;
        $this->slice_del();
        if (!$this->r_stem_suffix_chain_before_ki()) {
            $this->cursor = $this->limit - $v_12;
            goto lab22;
        }
    lab22:
        goto lab23;
    lab21:
        $this->cursor = $this->limit - $v_11;
        if (!$this->r_mark_lArI()) {
            goto lab18;
        }
    lab23:
        goto lab2;
    lab18:
        $this->cursor = $this->limit - $v_1;
        $this->ket = $this->cursor;
        if (!$this->r_mark_DAn()) {
            goto lab24;
        }
        $this->bra = $this->cursor;
        $this->slice_del();
        $v_13 = $this->limit - $this->cursor;
        $this->ket = $this->cursor;
        $v_14 = $this->limit - $this->cursor;
        if (!$this->r_mark_possessives()) {
            goto lab26;
        }
        $this->bra = $this->cursor;
        $this->slice_del();
        $v_15 = $this->limit - $this->cursor;
        $this->ket = $this->cursor;
        if (!$this->r_mark_lAr()) {
            $this->cursor = $this->limit - $v_15;
            goto lab27;
        }
        $this->bra = $this->cursor;
        $this->slice_del();
        if (!$this->r_stem_suffix_chain_before_ki()) {
            $this->cursor = $this->limit - $v_15;
            goto lab27;
        }
    lab27:
        goto lab28;
    lab26:
        $this->cursor = $this->limit - $v_14;
        if (!$this->r_mark_lAr()) {
            goto lab29;
        }
        $this->bra = $this->cursor;
        $this->slice_del();
        $v_16 = $this->limit - $this->cursor;
        if (!$this->r_stem_suffix_chain_before_ki()) {
            $this->cursor = $this->limit - $v_16;
            goto lab30;
        }
    lab30:
        goto lab28;
    lab29:
        $this->cursor = $this->limit - $v_14;
        if (!$this->r_stem_suffix_chain_before_ki()) {
            $this->cursor = $this->limit - $v_13;
            goto lab25;
        }
    lab28:
    lab25:
        goto lab2;
    lab24:
        $this->cursor = $this->limit - $v_1;
        $this->ket = $this->cursor;
        $v_17 = $this->limit - $this->cursor;
        if (!$this->r_mark_nUn()) {
            goto lab32;
        }
        goto lab33;
    lab32:
        $this->cursor = $this->limit - $v_17;
        if (!$this->r_mark_ylA()) {
            goto lab31;
        }
    lab33:
        $this->bra = $this->cursor;
        $this->slice_del();
        $v_18 = $this->limit - $this->cursor;
        $v_19 = $this->limit - $this->cursor;
        $this->ket = $this->cursor;
        if (!$this->r_mark_lAr()) {
            goto lab35;
        }
        $this->bra = $this->cursor;
        $this->slice_del();
        if (!$this->r_stem_suffix_chain_before_ki()) {
            goto lab35;
        }
        goto lab36;
    lab35:
        $this->cursor = $this->limit - $v_19;
        $this->ket = $this->cursor;
        $v_20 = $this->limit - $this->cursor;
        if (!$this->r_mark_possessives()) {
            goto lab38;
        }
        goto lab39;
    lab38:
        $this->cursor = $this->limit - $v_20;
        if (!$this->r_mark_sU()) {
            goto lab37;
        }
    lab39:
        $this->bra = $this->cursor;
        $this->slice_del();
        $v_21 = $this->limit - $this->cursor;
        $this->ket = $this->cursor;
        if (!$this->r_mark_lAr()) {
            $this->cursor = $this->limit - $v_21;
            goto lab40;
        }
        $this->bra = $this->cursor;
        $this->slice_del();
        if (!$this->r_stem_suffix_chain_before_ki()) {
            $this->cursor = $this->limit - $v_21;
            goto lab40;
        }
    lab40:
        goto lab36;
    lab37:
        $this->cursor = $this->limit - $v_19;
        if (!$this->r_stem_suffix_chain_before_ki()) {
            $this->cursor = $this->limit - $v_18;
            goto lab34;
        }
    lab36:
    lab34:
        goto lab2;
    lab31:
        $this->cursor = $this->limit - $v_1;
        $this->ket = $this->cursor;
        if (!$this->r_mark_lArI()) {
            goto lab41;
        }
        $this->bra = $this->cursor;
        $this->slice_del();
        goto lab2;
    lab41:
        $this->cursor = $this->limit - $v_1;
        if (!$this->r_stem_suffix_chain_before_ki()) {
            goto lab42;
        }
        goto lab2;
    lab42:
        $this->cursor = $this->limit - $v_1;
        $this->ket = $this->cursor;
        $v_22 = $this->limit - $this->cursor;
        if (!$this->r_mark_DA()) {
            goto lab44;
        }
        goto lab45;
    lab44:
        $this->cursor = $this->limit - $v_22;
        if (!$this->r_mark_yU()) {
            goto lab46;
        }
        goto lab45;
    lab46:
        $this->cursor = $this->limit - $v_22;
        if (!$this->r_mark_yA()) {
            goto lab43;
        }
    lab45:
        $this->bra = $this->cursor;
        $this->slice_del();
        $v_23 = $this->limit - $this->cursor;
        $this->ket = $this->cursor;
        $v_24 = $this->limit - $this->cursor;
        if (!$this->r_mark_possessives()) {
            goto lab48;
        }
        $this->bra = $this->cursor;
        $this->slice_del();
        $v_25 = $this->limit - $this->cursor;
        $this->ket = $this->cursor;
        if (!$this->r_mark_lAr()) {
            $this->cursor = $this->limit - $v_25;
            goto lab49;
        }
    lab49:
        goto lab50;
    lab48:
        $this->cursor = $this->limit - $v_24;
        if (!$this->r_mark_lAr()) {
            $this->cursor = $this->limit - $v_23;
            goto lab47;
        }
    lab50:
        $this->bra = $this->cursor;
        $this->slice_del();
        $this->ket = $this->cursor;
        if (!$this->r_stem_suffix_chain_before_ki()) {
            $this->cursor = $this->limit - $v_23;
            goto lab47;
        }
    lab47:
        goto lab2;
    lab43:
        $this->cursor = $this->limit - $v_1;
        $this->ket = $this->cursor;
        $v_26 = $this->limit - $this->cursor;
        if (!$this->r_mark_possessives()) {
            goto lab51;
        }
        goto lab52;
    lab51:
        $this->cursor = $this->limit - $v_26;
        if (!$this->r_mark_sU()) {
            return false;
        }
    lab52:
        $this->bra = $this->cursor;
        $this->slice_del();
        $v_27 = $this->limit - $this->cursor;
        $this->ket = $this->cursor;
        if (!$this->r_mark_lAr()) {
            $this->cursor = $this->limit - $v_27;
            goto lab53;
        }
        $this->bra = $this->cursor;
        $this->slice_del();
        if (!$this->r_stem_suffix_chain_before_ki()) {
            $this->cursor = $this->limit - $v_27;
            goto lab53;
        }
    lab53:
    lab2:
        return true;
    }


    protected function r_post_process_last_consonants(): bool
    {
        $this->ket = $this->cursor;
        $among_var = $this->find_among_b(self::A_23);
        if (0 === $among_var) {
            return false;
        }
        $this->bra = $this->cursor;
        switch ($among_var) {
            case 1:
                $this->slice_from("p");
                break;
            case 2:
                $this->slice_from("\u{00E7}");
                break;
            case 3:
                $this->slice_from("t");
                break;
            case 4:
                $this->slice_from("k");
                break;
        }
        return true;
    }


    protected function r_append_U_to_stems_ending_with_d_or_g(): bool
    {
        $this->ket = $this->cursor;
        $this->bra = $this->cursor;
        $v_1 = $this->limit - $this->cursor;
        if (!($this->eq_s_b("d"))) {
            goto lab0;
        }
        goto lab1;
    lab0:
        $this->cursor = $this->limit - $v_1;
        if (!($this->eq_s_b("g"))) {
            return false;
        }
    lab1:
        if (!$this->go_out_grouping_b(self::G_vowel)) {
            return false;
        }
        $v_2 = $this->limit - $this->cursor;
        $v_3 = $this->limit - $this->cursor;
        if (!($this->eq_s_b("a"))) {
            goto lab3;
        }
        goto lab4;
    lab3:
        $this->cursor = $this->limit - $v_3;
        if (!($this->eq_s_b("\u{0131}"))) {
            goto lab2;
        }
    lab4:
        $this->slice_from("\u{0131}");
        goto lab5;
    lab2:
        $this->cursor = $this->limit - $v_2;
        $v_4 = $this->limit - $this->cursor;
        if (!($this->eq_s_b("e"))) {
            goto lab7;
        }
        goto lab8;
    lab7:
        $this->cursor = $this->limit - $v_4;
        if (!($this->eq_s_b("i"))) {
            goto lab6;
        }
    lab8:
        $this->slice_from("i");
        goto lab5;
    lab6:
        $this->cursor = $this->limit - $v_2;
        $v_5 = $this->limit - $this->cursor;
        if (!($this->eq_s_b("o"))) {
            goto lab10;
        }
        goto lab11;
    lab10:
        $this->cursor = $this->limit - $v_5;
        if (!($this->eq_s_b("u"))) {
            goto lab9;
        }
    lab11:
        $this->slice_from("u");
        goto lab5;
    lab9:
        $this->cursor = $this->limit - $v_2;
        $v_6 = $this->limit - $this->cursor;
        if (!($this->eq_s_b("\u{00F6}"))) {
            goto lab12;
        }
        goto lab13;
    lab12:
        $this->cursor = $this->limit - $v_6;
        if (!($this->eq_s_b("\u{00FC}"))) {
            return false;
        }
    lab13:
        $this->slice_from("\u{00FC}");
    lab5:
        return true;
    }


    protected function r_is_reserved_word(): bool
    {
        if (!($this->eq_s_b("ad"))) {
            return false;
        }
        $v_1 = $this->limit - $this->cursor;
        if (!($this->eq_s_b("soy"))) {
            $this->cursor = $this->limit - $v_1;
            goto lab0;
        }
    lab0:
        if ($this->cursor > $this->limit_backward) {
            return false;
        }
        return true;
    }


    protected function r_remove_proper_noun_suffix(): bool
    {
        $v_1 = $this->cursor;
        $this->bra = $this->cursor;
        while (true) {
            $v_2 = $this->cursor;
            $v_3 = $this->cursor;
            if (!($this->eq_s("'"))) {
                goto lab2;
            }
            goto lab1;
        lab2:
            $this->cursor = $v_3;
            $this->cursor = $v_2;
            break;
        lab1:
            $this->cursor = $v_2;
            if ($this->cursor >= $this->limit) {
                goto lab0;
            }
            $this->inc_cursor();
        }
        $this->ket = $this->cursor;
        $this->slice_del();
    lab0:
        $this->cursor = $v_1;
        $v_4 = $this->cursor;
        if (!$this->hop(2)) {
            goto lab3;
        }
        while (true) {
            $v_5 = $this->cursor;
            if (!($this->eq_s("'"))) {
                goto lab4;
            }
            $this->cursor = $v_5;
            break;
        lab4:
            $this->cursor = $v_5;
            if ($this->cursor >= $this->limit) {
                goto lab3;
            }
            $this->inc_cursor();
        }
        $this->bra = $this->cursor;
        $this->cursor = $this->limit;
        $this->ket = $this->cursor;
        $this->slice_del();
    lab3:
        $this->cursor = $v_4;
        return true;
    }


    protected function r_more_than_one_syllable_word(): bool
    {
        $v_1 = $this->cursor;
        for ($v_2 = 2; $v_2 > 0; $v_2--) {
            if (!$this->go_out_grouping(self::G_vowel)) {
                return false;
            }
            $this->inc_cursor();
        }
        $this->cursor = $v_1;
        return true;
    }


    protected function r_postlude(): bool
    {
        $this->limit_backward = $this->cursor;
        $this->cursor = $this->limit;
        $v_1 = $this->limit - $this->cursor;
        if (!$this->r_is_reserved_word()) {
            goto lab0;
        }
        return false;
    lab0:
        $this->cursor = $this->limit - $v_1;
        $v_2 = $this->limit - $this->cursor;
        $this->r_append_U_to_stems_ending_with_d_or_g();
        $this->cursor = $this->limit - $v_2;
        $v_3 = $this->limit - $this->cursor;
        $this->r_post_process_last_consonants();
        $this->cursor = $this->limit - $v_3;
        $this->cursor = $this->limit_backward;
        return true;
    }


    public function stem(): bool
    {
        $this->r_remove_proper_noun_suffix();
        if (!$this->r_more_than_one_syllable_word()) {
            return false;
        }
        $this->limit_backward = $this->cursor;
        $this->cursor = $this->limit;
        $v_1 = $this->limit - $this->cursor;
        $this->r_stem_nominal_verb_suffixes();
        $this->cursor = $this->limit - $v_1;
        if (!$this->B_continue_stemming_noun_suffixes) {
            return false;
        }
        $v_2 = $this->limit - $this->cursor;
        $this->r_stem_noun_suffixes();
        $this->cursor = $this->limit - $v_2;
        $this->cursor = $this->limit_backward;
        return $this->r_postlude();
    }
}
