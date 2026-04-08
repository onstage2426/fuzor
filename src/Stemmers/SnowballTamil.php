<?php

namespace Fuzor\Stemmers;

// Generated from tamil.sbl by Snowball 3.0.0 - https://snowballstem.org/

class SnowballTamil extends SnowballStemmer
{
    private const array A_0 = [
        ["\u{0BB5}\u{0BC1}", -1, 3],
        ["\u{0BB5}\u{0BC2}", -1, 4],
        ["\u{0BB5}\u{0BCA}", -1, 2],
        ["\u{0BB5}\u{0BCB}", -1, 1]
    ];

    private const array A_1 = [
        ["\u{0B95}", -1, -1],
        ["\u{0B99}", -1, -1],
        ["\u{0B9A}", -1, -1],
        ["\u{0B9E}", -1, -1],
        ["\u{0BA4}", -1, -1],
        ["\u{0BA8}", -1, -1],
        ["\u{0BAA}", -1, -1],
        ["\u{0BAE}", -1, -1],
        ["\u{0BAF}", -1, -1],
        ["\u{0BB5}", -1, -1]
    ];

    private const array A_2 = [
        ["\u{0BC0}", -1, -1],
        ["\u{0BC8}", -1, -1],
        ["\u{0BBF}", -1, -1]
    ];

    private const array A_3 = [
        ["\u{0BC0}", -1, -1],
        ["\u{0BC1}", -1, -1],
        ["\u{0BC2}", -1, -1],
        ["\u{0BC6}", -1, -1],
        ["\u{0BC7}", -1, -1],
        ["\u{0BC8}", -1, -1],
        ["\u{0BBE}", -1, -1],
        ["\u{0BBF}", -1, -1]
    ];

    private const array A_4 = [
        ["", -1, 2],
        ["\u{0BC8}", 0, 1],
        ["\u{0BCD}", 0, 1]
    ];

    private const array A_5 = [
        ["\u{0BA9}\u{0BC1}", -1, 8],
        ["\u{0BC1}\u{0B95}\u{0BCD}", -1, 7],
        ["\u{0BC1}\u{0B95}\u{0BCD}\u{0B95}\u{0BCD}", -1, 7],
        ["\u{0B9F}\u{0BCD}\u{0B95}\u{0BCD}", -1, 3],
        ["\u{0BB1}\u{0BCD}\u{0B95}\u{0BCD}", -1, 4],
        ["\u{0B99}\u{0BCD}", -1, 9],
        ["\u{0B9F}\u{0BCD}\u{0B9F}\u{0BCD}", -1, 5],
        ["\u{0BA4}\u{0BCD}\u{0BA4}\u{0BCD}", -1, 6],
        ["\u{0BA8}\u{0BCD}\u{0BA4}\u{0BCD}", -1, 1],
        ["\u{0BA8}\u{0BCD}", -1, 1],
        ["\u{0B9F}\u{0BCD}\u{0BAA}\u{0BCD}", -1, 3],
        ["\u{0BAF}\u{0BCD}", -1, 2],
        ["\u{0BA9}\u{0BCD}\u{0BB1}\u{0BCD}", -1, 4],
        ["\u{0BB5}\u{0BCD}", -1, 1],
        ["\u{0BA8}\u{0BCD}\u{0BA4}", -1, 1],
        ["\u{0BAF}", -1, 1],
        ["\u{0BB5}", -1, 1]
    ];

    private const array A_6 = [
        ["\u{0B95}", -1, -1],
        ["\u{0B9A}", -1, -1],
        ["\u{0B9F}", -1, -1],
        ["\u{0BA4}", -1, -1],
        ["\u{0BAA}", -1, -1],
        ["\u{0BB1}", -1, -1]
    ];

    private const array A_7 = [
        ["\u{0B95}", -1, -1],
        ["\u{0B9A}", -1, -1],
        ["\u{0B9F}", -1, -1],
        ["\u{0BA4}", -1, -1],
        ["\u{0BAA}", -1, -1],
        ["\u{0BB1}", -1, -1]
    ];

    private const array A_8 = [
        ["\u{0B9E}", -1, -1],
        ["\u{0BA3}", -1, -1],
        ["\u{0BA8}", -1, -1],
        ["\u{0BA9}", -1, -1],
        ["\u{0BAE}", -1, -1],
        ["\u{0BAF}", -1, -1],
        ["\u{0BB0}", -1, -1],
        ["\u{0BB2}", -1, -1],
        ["\u{0BB3}", -1, -1],
        ["\u{0BB4}", -1, -1],
        ["\u{0BB5}", -1, -1]
    ];

    private const array A_9 = [
        ["\u{0BC0}", -1, -1],
        ["\u{0BC1}", -1, -1],
        ["\u{0BC2}", -1, -1],
        ["\u{0BC6}", -1, -1],
        ["\u{0BC7}", -1, -1],
        ["\u{0BC8}", -1, -1],
        ["\u{0BCD}", -1, -1],
        ["\u{0BBE}", -1, -1],
        ["\u{0BBF}", -1, -1]
    ];

    private const array A_10 = [
        ["\u{0B85}", -1, -1],
        ["\u{0B87}", -1, -1],
        ["\u{0B89}", -1, -1]
    ];

    private const array A_11 = [
        ["\u{0B95}", -1, -1],
        ["\u{0B99}", -1, -1],
        ["\u{0B9A}", -1, -1],
        ["\u{0B9E}", -1, -1],
        ["\u{0BA4}", -1, -1],
        ["\u{0BA8}", -1, -1],
        ["\u{0BAA}", -1, -1],
        ["\u{0BAE}", -1, -1],
        ["\u{0BAF}", -1, -1],
        ["\u{0BB5}", -1, -1]
    ];

    private const array A_12 = [
        ["\u{0B95}", -1, -1],
        ["\u{0B9A}", -1, -1],
        ["\u{0B9F}", -1, -1],
        ["\u{0BA4}", -1, -1],
        ["\u{0BAA}", -1, -1],
        ["\u{0BB1}", -1, -1]
    ];

    private const array A_13 = [
        ["\u{0B95}\u{0BB3}\u{0BCD}", -1, 4],
        ["\u{0BC1}\u{0B99}\u{0BCD}\u{0B95}\u{0BB3}\u{0BCD}", 0, 1],
        ["\u{0B9F}\u{0BCD}\u{0B95}\u{0BB3}\u{0BCD}", 0, 3],
        ["\u{0BB1}\u{0BCD}\u{0B95}\u{0BB3}\u{0BCD}", 0, 2]
    ];

    private const array A_14 = [
        ["\u{0BC7}", -1, -1],
        ["\u{0BCB}", -1, -1],
        ["\u{0BBE}", -1, -1]
    ];

    private const array A_15 = [
        ["\u{0BAA}\u{0BBF}", -1, -1],
        ["\u{0BB5}\u{0BBF}", -1, -1]
    ];

    private const array A_16 = [
        ["\u{0BC0}", -1, -1],
        ["\u{0BC1}", -1, -1],
        ["\u{0BC2}", -1, -1],
        ["\u{0BC6}", -1, -1],
        ["\u{0BC7}", -1, -1],
        ["\u{0BC8}", -1, -1],
        ["\u{0BBE}", -1, -1],
        ["\u{0BBF}", -1, -1]
    ];

    private const array A_17 = [
        ["\u{0BAA}\u{0B9F}\u{0BCD}\u{0B9F}\u{0BC1}", -1, 3],
        ["\u{0BB5}\u{0BBF}\u{0B9F}\u{0BCD}\u{0B9F}\u{0BC1}", -1, 3],
        ["\u{0BAA}\u{0B9F}\u{0BC1}", -1, 3],
        ["\u{0BB5}\u{0BBF}\u{0B9F}\u{0BC1}", -1, 3],
        ["\u{0BAA}\u{0B9F}\u{0BCD}\u{0B9F}\u{0BA4}\u{0BC1}", -1, 3],
        ["\u{0BC6}\u{0BA9}\u{0BCD}\u{0BB1}\u{0BC1}", -1, 1],
        ["\u{0BC1}\u{0B9F}\u{0BC8}", -1, 1],
        ["\u{0BBF}\u{0BB2}\u{0BCD}\u{0BB2}\u{0BC8}", -1, 1],
        ["\u{0BC1}\u{0B9F}\u{0BA9}\u{0BCD}", -1, 1],
        ["\u{0BC6}\u{0BA9}\u{0BC1}\u{0BAE}\u{0BCD}", -1, 1],
        ["\u{0BBF}\u{0B9F}\u{0BAE}\u{0BCD}", -1, 1],
        ["\u{0BC6}\u{0BB2}\u{0BCD}\u{0BB2}\u{0BBE}\u{0BAE}\u{0BCD}", -1, 3],
        ["\u{0BAA}\u{0B9F}\u{0BCD}\u{0B9F}", -1, 3],
        ["\u{0BAA}\u{0B9F}\u{0BCD}\u{0B9F}\u{0BA3}", -1, 3],
        ["\u{0BC6}\u{0BA9}", -1, 1],
        ["\u{0BA4}\u{0BBE}\u{0BA9}", -1, 3],
        ["\u{0BAA}\u{0B9F}\u{0BBF}\u{0BA4}\u{0BBE}\u{0BA9}", 15, 3],
        ["\u{0BC1}\u{0B9F}\u{0BC8}\u{0BAF}", -1, 1],
        ["\u{0BBE}\u{0B95}\u{0BBF}\u{0BAF}", -1, 1],
        ["\u{0B95}\u{0BC1}\u{0BB0}\u{0BBF}\u{0BAF}", -1, 3],
        ["\u{0BB2}\u{0BCD}\u{0BB2}", -1, 2],
        ["\u{0BC1}\u{0BB3}\u{0BCD}\u{0BB3}", -1, 1],
        ["\u{0BBE}\u{0B95}\u{0BBF}", -1, 1],
        ["\u{0BAA}\u{0B9F}\u{0BBF}", -1, 3],
        ["\u{0BBF}\u{0BA9}\u{0BCD}\u{0BB1}\u{0BBF}", -1, 1],
        ["\u{0BAA}\u{0BB1}\u{0BCD}\u{0BB1}\u{0BBF}", -1, 3]
    ];

    private const array A_18 = [
        ["\u{0BC0}", -1, -1],
        ["\u{0BC1}", -1, -1],
        ["\u{0BC2}", -1, -1],
        ["\u{0BC6}", -1, -1],
        ["\u{0BC7}", -1, -1],
        ["\u{0BC8}", -1, -1],
        ["\u{0BBE}", -1, -1],
        ["\u{0BBF}", -1, -1]
    ];

    private const array A_19 = [
        ["\u{0BC0}", -1, -1],
        ["\u{0BC1}", -1, -1],
        ["\u{0BC2}", -1, -1],
        ["\u{0BC6}", -1, -1],
        ["\u{0BC7}", -1, -1],
        ["\u{0BC8}", -1, -1],
        ["\u{0BBE}", -1, -1],
        ["\u{0BBF}", -1, -1]
    ];

    private const array A_20 = [
        ["\u{0BC0}", -1, 7],
        ["\u{0BCA}\u{0B9F}\u{0BC1}", -1, 2],
        ["\u{0BCB}\u{0B9F}\u{0BC1}", -1, 2],
        ["\u{0BA4}\u{0BC1}", -1, 6],
        ["\u{0BBF}\u{0BB0}\u{0BC1}\u{0BA8}\u{0BCD}\u{0BA4}\u{0BC1}", 3, 2],
        ["\u{0BBF}\u{0BA9}\u{0BCD}\u{0BB1}\u{0BC1}", -1, 2],
        ["\u{0BC1}\u{0B9F}\u{0BC8}", -1, 2],
        ["\u{0BA9}\u{0BC8}", -1, 1],
        ["\u{0B95}\u{0BA3}\u{0BCD}", -1, 1],
        ["\u{0BAE}\u{0BC1}\u{0BA9}\u{0BCD}", -1, 1],
        ["\u{0BBF}\u{0BA9}\u{0BCD}", -1, 3],
        ["\u{0BBF}\u{0B9F}\u{0BAE}\u{0BCD}", -1, 4],
        ["\u{0BAE}\u{0BC7}\u{0BB1}\u{0BCD}", -1, 1],
        ["\u{0BBF}\u{0BB1}\u{0BCD}", -1, 2],
        ["\u{0BB2}\u{0BCD}", -1, 5],
        ["\u{0BAE}\u{0BC7}\u{0BB2}\u{0BCD}", 14, 1],
        ["\u{0BBE}\u{0BAE}\u{0BB2}\u{0BCD}", 14, 2],
        ["\u{0BBE}\u{0BB2}\u{0BCD}", 14, 2],
        ["\u{0BBF}\u{0BB2}\u{0BCD}", 14, 2],
        ["\u{0BC1}\u{0BB3}\u{0BCD}", -1, 2],
        ["\u{0B95}\u{0BC0}\u{0BB4}\u{0BCD}", -1, 1],
        ["\u{0BB5}\u{0BBF}\u{0B9F}", -1, 2]
    ];

    private const array A_21 = [
        ["\u{0B95}", -1, -1],
        ["\u{0B9A}", -1, -1],
        ["\u{0B9F}", -1, -1],
        ["\u{0BA4}", -1, -1],
        ["\u{0BAA}", -1, -1],
        ["\u{0BB1}", -1, -1]
    ];

    private const array A_22 = [
        ["\u{0B95}", -1, -1],
        ["\u{0B9A}", -1, -1],
        ["\u{0B9F}", -1, -1],
        ["\u{0BA4}", -1, -1],
        ["\u{0BAA}", -1, -1],
        ["\u{0BB1}", -1, -1]
    ];

    private const array A_23 = [
        ["\u{0B85}", -1, -1],
        ["\u{0B86}", -1, -1],
        ["\u{0B87}", -1, -1],
        ["\u{0B88}", -1, -1],
        ["\u{0B89}", -1, -1],
        ["\u{0B8A}", -1, -1],
        ["\u{0B8E}", -1, -1],
        ["\u{0B8F}", -1, -1],
        ["\u{0B90}", -1, -1],
        ["\u{0B92}", -1, -1],
        ["\u{0B93}", -1, -1],
        ["\u{0B94}", -1, -1]
    ];

    private const array A_24 = [
        ["\u{0BC0}", -1, -1],
        ["\u{0BC1}", -1, -1],
        ["\u{0BC2}", -1, -1],
        ["\u{0BC6}", -1, -1],
        ["\u{0BC7}", -1, -1],
        ["\u{0BC8}", -1, -1],
        ["\u{0BBE}", -1, -1],
        ["\u{0BBF}", -1, -1]
    ];

    private const array A_25 = [
        ["\u{0B95}\u{0BC1}", -1, 6],
        ["\u{0BAA}\u{0B9F}\u{0BC1}", -1, 1],
        ["\u{0BA4}\u{0BC1}", -1, 3],
        ["\u{0BBF}\u{0BB1}\u{0BCD}\u{0BB1}\u{0BC1}", -1, 1],
        ["\u{0BA9}\u{0BC8}", -1, 1],
        ["\u{0BB5}\u{0BC8}", -1, 1],
        ["\u{0BA9}\u{0BC6}\u{0BA9}\u{0BCD}", -1, 1],
        ["\u{0BC7}\u{0BA9}\u{0BCD}", -1, 5],
        ["\u{0BA9}\u{0BA9}\u{0BCD}", -1, 1],
        ["\u{0BAA}\u{0BA9}\u{0BCD}", -1, 1],
        ["\u{0BB5}\u{0BA9}\u{0BCD}", -1, 2],
        ["\u{0BBE}\u{0BA9}\u{0BCD}", -1, 4],
        ["\u{0BA9}\u{0BBE}\u{0BA9}\u{0BCD}", 11, 1],
        ["\u{0BAE}\u{0BBF}\u{0BA9}\u{0BCD}", -1, 1],
        ["\u{0B95}\u{0BC1}\u{0BAE}\u{0BCD}", -1, 1],
        ["\u{0B9F}\u{0BC1}\u{0BAE}\u{0BCD}", -1, 5],
        ["\u{0BA4}\u{0BC1}\u{0BAE}\u{0BCD}", -1, 1],
        ["\u{0BB1}\u{0BC1}\u{0BAE}\u{0BCD}", -1, 1],
        ["\u{0BC6}\u{0BAE}\u{0BCD}", -1, 5],
        ["\u{0BC7}\u{0BAE}\u{0BCD}", -1, 5],
        ["\u{0BCB}\u{0BAE}\u{0BCD}", -1, 5],
        ["\u{0BA9}\u{0BAE}\u{0BCD}", -1, 1],
        ["\u{0BAA}\u{0BAE}\u{0BCD}", -1, 1],
        ["\u{0BBE}\u{0BAE}\u{0BCD}", -1, 5],
        ["\u{0BBE}\u{0BAF}\u{0BCD}", -1, 5],
        ["\u{0BC0}\u{0BB0}\u{0BCD}", -1, 5],
        ["\u{0BA9}\u{0BB0}\u{0BCD}", -1, 1],
        ["\u{0BAA}\u{0BB0}\u{0BCD}", -1, 1],
        ["\u{0BC0}\u{0BAF}\u{0BB0}\u{0BCD}", -1, 5],
        ["\u{0BB5}\u{0BB0}\u{0BCD}", -1, 1],
        ["\u{0BBE}\u{0BB0}\u{0BCD}", -1, 5],
        ["\u{0BA9}\u{0BBE}\u{0BB0}\u{0BCD}", 30, 1],
        ["\u{0BAE}\u{0BBE}\u{0BB0}\u{0BCD}", 30, 1],
        ["\u{0B95}\u{0BCA}\u{0BA3}\u{0BCD}\u{0B9F}\u{0BBF}\u{0BB0}\u{0BCD}", -1, 1],
        ["\u{0BA9}\u{0BBF}\u{0BB0}\u{0BCD}", -1, 5],
        ["\u{0BA9}\u{0BB3}\u{0BCD}", -1, 1],
        ["\u{0BAA}\u{0BB3}\u{0BCD}", -1, 1],
        ["\u{0BB5}\u{0BB3}\u{0BCD}", -1, 1],
        ["\u{0BBE}\u{0BB3}\u{0BCD}", -1, 5],
        ["\u{0BA9}\u{0BBE}\u{0BB3}\u{0BCD}", 38, 1],
        ["\u{0B95}", -1, 1],
        ["\u{0BA4}", -1, 1],
        ["\u{0BA9}", -1, 1],
        ["\u{0BAA}", -1, 1],
        ["\u{0BAF}", -1, 1],
        ["\u{0BBE}", -1, 5]
    ];

    private const array A_26 = [
        ["\u{0B95}\u{0BBF}\u{0BA9}\u{0BCD}\u{0BB1}\u{0BCD}", -1, -1],
        ["\u{0BBE}\u{0BA8}\u{0BBF}\u{0BA9}\u{0BCD}\u{0BB1}\u{0BCD}", -1, -1],
        ["\u{0B95}\u{0BBF}\u{0BB1}\u{0BCD}", -1, -1],
        ["\u{0B95}\u{0BBF}\u{0BA9}\u{0BCD}\u{0BB1}", -1, -1],
        ["\u{0BBE}\u{0BA8}\u{0BBF}\u{0BA9}\u{0BCD}\u{0BB1}", -1, -1],
        ["\u{0B95}\u{0BBF}\u{0BB1}", -1, -1]
    ];

    private bool $B_found_vetrumai_urupu = false;



    protected function r_has_min_length(): bool
    {
        return mb_strlen($this->current, 'UTF-8') > 4;
    }


    protected function r_fix_va_start(): bool
    {
        $this->bra = $this->cursor;
        $among_var = $this->find_among(self::A_0);
        if (0 === $among_var) {
            return false;
        }
        $this->ket = $this->cursor;
        switch ($among_var) {
            case 1:
                $this->slice_from("\u{0B93}");
                break;
            case 2:
                $this->slice_from("\u{0B92}");
                break;
            case 3:
                $this->slice_from("\u{0B89}");
                break;
            case 4:
                $this->slice_from("\u{0B8A}");
                break;
        }
        return true;
    }


    protected function r_fix_endings(): bool
    {
        $v_1 = $this->cursor;
        while (true) {
            $v_2 = $this->cursor;
            if (!$this->r_fix_ending()) {
                goto lab1;
            }
            continue;
        lab1:
            $this->cursor = $v_2;
            break;
        }
    lab0:
        $this->cursor = $v_1;
        return true;
    }


    protected function r_remove_question_prefixes(): bool
    {
        $this->bra = $this->cursor;
        if (!($this->eq_s("\u{0B8E}"))) {
            return false;
        }
        if ($this->find_among(self::A_1) === 0) {
            return false;
        }
        if (!($this->eq_s("\u{0BCD}"))) {
            return false;
        }
        $this->ket = $this->cursor;
        $this->slice_del();
        $v_1 = $this->cursor;
        $this->r_fix_va_start();
        $this->cursor = $v_1;
        return true;
    }


    protected function r_fix_ending(): bool
    {
        if (mb_strlen($this->current, 'UTF-8') <= 3) {
            return false;
        }
        $this->limit_backward = $this->cursor;
        $this->cursor = $this->limit;
        $v_1 = $this->limit - $this->cursor;
        $this->ket = $this->cursor;
        $among_var = $this->find_among_b(self::A_5);
        if (0 === $among_var) {
            goto lab0;
        }
        $this->bra = $this->cursor;
        switch ($among_var) {
            case 1:
                $this->slice_del();
                break;
            case 2:
                $v_2 = $this->limit - $this->cursor;
                if ($this->find_among_b(self::A_2) === 0) {
                    goto lab0;
                }
                $this->cursor = $this->limit - $v_2;
                $this->slice_del();
                break;
            case 3:
                $this->slice_from("\u{0BB3}\u{0BCD}");
                break;
            case 4:
                $this->slice_from("\u{0BB2}\u{0BCD}");
                break;
            case 5:
                $this->slice_from("\u{0B9F}\u{0BC1}");
                break;
            case 6:
                if (!$this->B_found_vetrumai_urupu) {
                    goto lab0;
                }
                $v_3 = $this->limit - $this->cursor;
                if (!($this->eq_s_b("\u{0BC8}"))) {
                    goto lab1;
                }
                goto lab0;
            lab1:
                $this->cursor = $this->limit - $v_3;
                $this->slice_from("\u{0BAE}\u{0BCD}");
                break;
            case 7:
                $this->slice_from("\u{0BCD}");
                break;
            case 8:
                $v_4 = $this->limit - $this->cursor;
                if ($this->find_among_b(self::A_3) === 0) {
                    goto lab2;
                }
                goto lab0;
            lab2:
                $this->cursor = $this->limit - $v_4;
                $this->slice_del();
                break;
            case 9:
                $among_var = $this->find_among_b(self::A_4);
                switch ($among_var) {
                    case 1:
                        $this->slice_del();
                        break;
                    case 2:
                        $this->slice_from("\u{0BAE}\u{0BCD}");
                        break;
                }
                break;
        }
        goto lab3;
    lab0:
        $this->cursor = $this->limit - $v_1;
        $this->ket = $this->cursor;
        if (!($this->eq_s_b("\u{0BCD}"))) {
            return false;
        }
        $v_5 = $this->limit - $this->cursor;
        if ($this->find_among_b(self::A_6) === 0) {
            goto lab4;
        }
        $v_6 = $this->limit - $this->cursor;
        if (!($this->eq_s_b("\u{0BCD}"))) {
            $this->cursor = $this->limit - $v_6;
            goto lab5;
        }
        if ($this->find_among_b(self::A_7) === 0) {
            $this->cursor = $this->limit - $v_6;
            goto lab5;
        }
    lab5:
        $this->bra = $this->cursor;
        $this->slice_del();
        goto lab6;
    lab4:
        $this->cursor = $this->limit - $v_5;
        if ($this->find_among_b(self::A_8) === 0) {
            goto lab7;
        }
        $this->bra = $this->cursor;
        if (!($this->eq_s_b("\u{0BCD}"))) {
            goto lab7;
        }
        $this->slice_del();
        goto lab6;
    lab7:
        $this->cursor = $this->limit - $v_5;
        $v_7 = $this->limit - $this->cursor;
        if ($this->find_among_b(self::A_9) === 0) {
            return false;
        }
        $this->cursor = $this->limit - $v_7;
        $this->bra = $this->cursor;
        $this->slice_del();
    lab6:
    lab3:
        $this->cursor = $this->limit_backward;
        return true;
    }


    protected function r_remove_pronoun_prefixes(): bool
    {
        $this->bra = $this->cursor;
        if ($this->find_among(self::A_10) === 0) {
            return false;
        }
        if ($this->find_among(self::A_11) === 0) {
            return false;
        }
        if (!($this->eq_s("\u{0BCD}"))) {
            return false;
        }
        $this->ket = $this->cursor;
        $this->slice_del();
        $v_1 = $this->cursor;
        $this->r_fix_va_start();
        $this->cursor = $v_1;
        return true;
    }


    protected function r_remove_plural_suffix(): bool
    {
        $this->limit_backward = $this->cursor;
        $this->cursor = $this->limit;
        $this->ket = $this->cursor;
        $among_var = $this->find_among_b(self::A_13);
        if (0 === $among_var) {
            return false;
        }
        $this->bra = $this->cursor;
        switch ($among_var) {
            case 1:
                $v_1 = $this->limit - $this->cursor;
                if ($this->find_among_b(self::A_12) === 0) {
                    goto lab0;
                }
                $this->slice_from("\u{0BC1}\u{0B99}\u{0BCD}");
                goto lab1;
            lab0:
                $this->cursor = $this->limit - $v_1;
                $this->slice_from("\u{0BCD}");
            lab1:
                break;
            case 2:
                $this->slice_from("\u{0BB2}\u{0BCD}");
                break;
            case 3:
                $this->slice_from("\u{0BB3}\u{0BCD}");
                break;
            case 4:
                $this->slice_del();
                break;
        }
        $this->cursor = $this->limit_backward;
        return true;
    }


    protected function r_remove_question_suffixes(): bool
    {
        if (!$this->r_has_min_length()) {
            return false;
        }
        $this->limit_backward = $this->cursor;
        $this->cursor = $this->limit;
        $v_1 = $this->limit - $this->cursor;
        $this->ket = $this->cursor;
        if ($this->find_among_b(self::A_14) === 0) {
            goto lab0;
        }
        $this->bra = $this->cursor;
        $this->slice_from("\u{0BCD}");
    lab0:
        $this->cursor = $this->limit - $v_1;
        $this->cursor = $this->limit_backward;
        $this->r_fix_endings();
        return true;
    }


    protected function r_remove_command_suffixes(): bool
    {
        if (!$this->r_has_min_length()) {
            return false;
        }
        $this->limit_backward = $this->cursor;
        $this->cursor = $this->limit;
        $this->ket = $this->cursor;
        if ($this->find_among_b(self::A_15) === 0) {
            return false;
        }
        $this->bra = $this->cursor;
        $this->slice_del();
        $this->cursor = $this->limit_backward;
        return true;
    }


    protected function r_remove_um(): bool
    {
        if (!$this->r_has_min_length()) {
            return false;
        }
        $this->limit_backward = $this->cursor;
        $this->cursor = $this->limit;
        $this->ket = $this->cursor;
        if (!($this->eq_s_b("\u{0BC1}\u{0BAE}\u{0BCD}"))) {
            return false;
        }
        $this->bra = $this->cursor;
        $this->slice_from("\u{0BCD}");
        $this->cursor = $this->limit_backward;
        $v_1 = $this->cursor;
        $this->r_fix_ending();
        $this->cursor = $v_1;
        return true;
    }


    protected function r_remove_common_word_endings(): bool
    {
        if (!$this->r_has_min_length()) {
            return false;
        }
        $this->limit_backward = $this->cursor;
        $this->cursor = $this->limit;
        $this->ket = $this->cursor;
        $among_var = $this->find_among_b(self::A_17);
        if (0 === $among_var) {
            return false;
        }
        $this->bra = $this->cursor;
        switch ($among_var) {
            case 1:
                $this->slice_from("\u{0BCD}");
                break;
            case 2:
                $v_1 = $this->limit - $this->cursor;
                if ($this->find_among_b(self::A_16) === 0) {
                    goto lab0;
                }
                return false;
            lab0:
                $this->cursor = $this->limit - $v_1;
                $this->slice_from("\u{0BCD}");
                break;
            case 3:
                $this->slice_del();
                break;
        }
        $this->cursor = $this->limit_backward;
        $this->r_fix_endings();
        return true;
    }


    protected function r_remove_vetrumai_urupukal(): bool
    {
        $this->B_found_vetrumai_urupu = false;
        if (!$this->r_has_min_length()) {
            return false;
        }
        $this->limit_backward = $this->cursor;
        $this->cursor = $this->limit;
        $v_1 = $this->limit - $this->cursor;
        $v_2 = $this->limit - $this->cursor;
        $this->ket = $this->cursor;
        $among_var = $this->find_among_b(self::A_20);
        if (0 === $among_var) {
            goto lab0;
        }
        $this->bra = $this->cursor;
        switch ($among_var) {
            case 1:
                $this->slice_del();
                break;
            case 2:
                $this->slice_from("\u{0BCD}");
                break;
            case 3:
                $v_3 = $this->limit - $this->cursor;
                if (!($this->eq_s_b("\u{0BAE}"))) {
                    goto lab1;
                }
                goto lab0;
            lab1:
                $this->cursor = $this->limit - $v_3;
                $this->slice_from("\u{0BCD}");
                break;
            case 4:
                if (mb_strlen($this->current, 'UTF-8') < 7) {
                    goto lab0;
                }
                $this->slice_from("\u{0BCD}");
                break;
            case 5:
                $v_4 = $this->limit - $this->cursor;
                if ($this->find_among_b(self::A_18) === 0) {
                    goto lab2;
                }
                goto lab0;
            lab2:
                $this->cursor = $this->limit - $v_4;
                $this->slice_from("\u{0BCD}");
                break;
            case 6:
                $v_5 = $this->limit - $this->cursor;
                if ($this->find_among_b(self::A_19) === 0) {
                    goto lab3;
                }
                goto lab0;
            lab3:
                $this->cursor = $this->limit - $v_5;
                $this->slice_del();
                break;
            case 7:
                $this->slice_from("\u{0BBF}");
                break;
        }
        $this->cursor = $this->limit - $v_2;
        goto lab4;
    lab0:
        $this->cursor = $this->limit - $v_1;
        $v_6 = $this->limit - $this->cursor;
        $this->ket = $this->cursor;
        if (!($this->eq_s_b("\u{0BC8}"))) {
            return false;
        }
        $v_7 = $this->limit - $this->cursor;
        $v_8 = $this->limit - $this->cursor;
        if ($this->find_among_b(self::A_21) === 0) {
            goto lab6;
        }
        goto lab5;
    lab6:
        $this->cursor = $this->limit - $v_8;
        goto lab7;
    lab5:
        $this->cursor = $this->limit - $v_7;
        $v_9 = $this->limit - $this->cursor;
        if ($this->find_among_b(self::A_22) === 0) {
            return false;
        }
        if (!($this->eq_s_b("\u{0BCD}"))) {
            return false;
        }
        $this->cursor = $this->limit - $v_9;
    lab7:
        $this->bra = $this->cursor;
        $this->slice_from("\u{0BCD}");
        $this->cursor = $this->limit - $v_6;
    lab4:
        $this->B_found_vetrumai_urupu = true;
        $v_10 = $this->limit - $this->cursor;
        $this->ket = $this->cursor;
        if (!($this->eq_s_b("\u{0BBF}\u{0BA9}\u{0BCD}"))) {
            goto lab8;
        }
        $this->bra = $this->cursor;
        $this->slice_from("\u{0BCD}");
    lab8:
        $this->cursor = $this->limit - $v_10;
        $this->cursor = $this->limit_backward;
        $this->r_fix_endings();
        return true;
    }


    protected function r_remove_tense_suffixes(): bool
    {
        while (true) {
            $v_1 = $this->cursor;
            if (!$this->r_remove_tense_suffix()) {
                goto lab0;
            }
            continue;
        lab0:
            $this->cursor = $v_1;
            break;
        }
        return true;
    }


    protected function r_remove_tense_suffix(): bool
    {
        $B_found_a_match = false;
        if (!$this->r_has_min_length()) {
            return false;
        }
        $this->limit_backward = $this->cursor;
        $this->cursor = $this->limit;
        $v_1 = $this->limit - $this->cursor;
        $v_2 = $this->limit - $this->cursor;
        $this->ket = $this->cursor;
        $among_var = $this->find_among_b(self::A_25);
        if (0 === $among_var) {
            goto lab0;
        }
        $this->bra = $this->cursor;
        switch ($among_var) {
            case 1:
                $this->slice_del();
                break;
            case 2:
                $v_3 = $this->limit - $this->cursor;
                if ($this->find_among_b(self::A_23) === 0) {
                    goto lab1;
                }
                goto lab0;
            lab1:
                $this->cursor = $this->limit - $v_3;
                $this->slice_del();
                break;
            case 3:
                $v_4 = $this->limit - $this->cursor;
                if ($this->find_among_b(self::A_24) === 0) {
                    goto lab2;
                }
                goto lab0;
            lab2:
                $this->cursor = $this->limit - $v_4;
                $this->slice_del();
                break;
            case 4:
                $v_5 = $this->limit - $this->cursor;
                if (!($this->eq_s_b("\u{0B9A}"))) {
                    goto lab3;
                }
                goto lab0;
            lab3:
                $this->cursor = $this->limit - $v_5;
                $this->slice_from("\u{0BCD}");
                break;
            case 5:
                $this->slice_from("\u{0BCD}");
                break;
            case 6:
                $v_6 = $this->limit - $this->cursor;
                if (!($this->eq_s_b("\u{0BCD}"))) {
                    goto lab0;
                }
                $this->cursor = $this->limit - $v_6;
                $this->slice_del();
                break;
        }
        $B_found_a_match = true;
        $this->cursor = $this->limit - $v_2;
    lab0:
        $this->cursor = $this->limit - $v_1;
        $v_7 = $this->limit - $this->cursor;
        $this->ket = $this->cursor;
        if ($this->find_among_b(self::A_26) === 0) {
            goto lab4;
        }
        $this->bra = $this->cursor;
        $this->slice_del();
        $B_found_a_match = true;
    lab4:
        $this->cursor = $this->limit - $v_7;
        $this->cursor = $this->limit_backward;
        $this->r_fix_endings();
        return $B_found_a_match;
    }


    public function stem(): bool
    {
        $this->B_found_vetrumai_urupu = false;
        $v_1 = $this->cursor;
        $this->r_fix_ending();
        $this->cursor = $v_1;
        if (!$this->r_has_min_length()) {
            return false;
        }
        $v_2 = $this->cursor;
        $this->r_remove_question_prefixes();
        $this->cursor = $v_2;
        $v_3 = $this->cursor;
        $this->r_remove_pronoun_prefixes();
        $this->cursor = $v_3;
        $v_4 = $this->cursor;
        $this->r_remove_question_suffixes();
        $this->cursor = $v_4;
        $v_5 = $this->cursor;
        $this->r_remove_um();
        $this->cursor = $v_5;
        $v_6 = $this->cursor;
        $this->r_remove_common_word_endings();
        $this->cursor = $v_6;
        $v_7 = $this->cursor;
        $this->r_remove_vetrumai_urupukal();
        $this->cursor = $v_7;
        $v_8 = $this->cursor;
        $this->r_remove_plural_suffix();
        $this->cursor = $v_8;
        $v_9 = $this->cursor;
        $this->r_remove_command_suffixes();
        $this->cursor = $v_9;
        $v_10 = $this->cursor;
        $this->r_remove_tense_suffixes();
        $this->cursor = $v_10;
        return true;
    }
}
