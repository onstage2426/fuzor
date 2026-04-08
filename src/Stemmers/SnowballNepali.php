<?php

namespace Fuzor\Stemmers;

// Generated from nepali.sbl by Snowball 3.0.0 - https://snowballstem.org/

class SnowballNepali extends SnowballStemmer
{
    private const array A_0 = [
        ["\u{0915}\u{0940}", -1, 2],
        ["\u{0932}\u{093E}\u{0907}", -1, 1],
        ["\u{0932}\u{0947}", -1, 1],
        ["\u{0932}\u{093E}\u{0908}", -1, 1],
        ["\u{0915}\u{0948}", -1, 2],
        ["\u{0938}\u{0901}\u{0917}\u{0948}", -1, 1],
        ["\u{092E}\u{0948}", -1, 1],
        ["\u{0915}\u{094B}", -1, 2],
        ["\u{0938}\u{0901}\u{0917}", -1, 1],
        ["\u{0938}\u{0902}\u{0917}", -1, 1],
        ["\u{092E}\u{093E}\u{0930}\u{094D}\u{092B}\u{0924}", -1, 1],
        ["\u{0930}\u{0924}", -1, 1],
        ["\u{0915}\u{093E}", -1, 2],
        ["\u{092E}\u{093E}", -1, 1],
        ["\u{0926}\u{094D}\u{0935}\u{093E}\u{0930}\u{093E}", -1, 1],
        ["\u{0915}\u{093F}", -1, 2],
        ["\u{092A}\u{091B}\u{093F}", -1, 1]
    ];

    private const array A_1 = [
        ["\u{0901}", -1, 1],
        ["\u{0902}", -1, 1],
        ["\u{0948}", -1, 2]
    ];

    private const array A_2 = [
        ["\u{0947}\u{0915}\u{0940}", -1, 1],
        ["\u{090F}\u{0915}\u{0940}", -1, 1],
        ["\u{0907}\u{090F}\u{0915}\u{0940}", 1, 1],
        ["\u{093F}\u{090F}\u{0915}\u{0940}", 1, 1],
        ["\u{0926}\u{0947}\u{0916}\u{0940}", -1, 1],
        ["\u{0925}\u{0940}", -1, 1],
        ["\u{0926}\u{0940}", -1, 1],
        ["\u{091B}\u{0941}", -1, 1],
        ["\u{0947}\u{091B}\u{0941}", 7, 1],
        ["\u{0928}\u{0947}\u{091B}\u{0941}", 8, 1],
        ["\u{090F}\u{091B}\u{0941}", 7, 1],
        ["\u{0928}\u{0941}", -1, 1],
        ["\u{0939}\u{0930}\u{0941}", -1, 1],
        ["\u{0939}\u{0930}\u{0942}", -1, 1],
        ["\u{091B}\u{0947}", -1, 1],
        ["\u{0925}\u{0947}", -1, 1],
        ["\u{0928}\u{0947}", -1, 1],
        ["\u{0947}\u{0915}\u{0948}", -1, 1],
        ["\u{0928}\u{0947}\u{0915}\u{0948}", 17, 1],
        ["\u{090F}\u{0915}\u{0948}", -1, 1],
        ["\u{0926}\u{0948}", -1, 1],
        ["\u{0907}\u{0926}\u{0948}", 20, 1],
        ["\u{093F}\u{0926}\u{0948}", 20, 1],
        ["\u{0947}\u{0915}\u{094B}", -1, 1],
        ["\u{0928}\u{0947}\u{0915}\u{094B}", 23, 1],
        ["\u{090F}\u{0915}\u{094B}", -1, 1],
        ["\u{0907}\u{090F}\u{0915}\u{094B}", 25, 1],
        ["\u{093F}\u{090F}\u{0915}\u{094B}", 25, 1],
        ["\u{0926}\u{094B}", -1, 1],
        ["\u{0907}\u{0926}\u{094B}", 28, 1],
        ["\u{093F}\u{0926}\u{094B}", 28, 1],
        ["\u{092F}\u{094B}", -1, 1],
        ["\u{0907}\u{092F}\u{094B}", 31, 1],
        ["\u{0925}\u{094D}\u{092F}\u{094B}", 31, 1],
        ["\u{092D}\u{092F}\u{094B}", 31, 1],
        ["\u{093F}\u{092F}\u{094B}", 31, 1],
        ["\u{0925}\u{093F}\u{092F}\u{094B}", 35, 1],
        ["\u{0926}\u{093F}\u{092F}\u{094B}", 35, 1],
        ["\u{091B}\u{094C}", -1, 1],
        ["\u{0907}\u{091B}\u{094C}", 38, 1],
        ["\u{0947}\u{091B}\u{094C}", 38, 1],
        ["\u{0928}\u{0947}\u{091B}\u{094C}", 40, 1],
        ["\u{090F}\u{091B}\u{094C}", 38, 1],
        ["\u{093F}\u{091B}\u{094C}", 38, 1],
        ["\u{092F}\u{094C}", -1, 1],
        ["\u{091B}\u{094D}\u{092F}\u{094C}", 44, 1],
        ["\u{0925}\u{094D}\u{092F}\u{094C}", 44, 1],
        ["\u{0925}\u{093F}\u{092F}\u{094C}", 44, 1],
        ["\u{091B}\u{0928}\u{094D}", -1, 1],
        ["\u{0907}\u{091B}\u{0928}\u{094D}", 48, 1],
        ["\u{0947}\u{091B}\u{0928}\u{094D}", 48, 1],
        ["\u{0928}\u{0947}\u{091B}\u{0928}\u{094D}", 50, 1],
        ["\u{090F}\u{091B}\u{0928}\u{094D}", 48, 1],
        ["\u{093F}\u{091B}\u{0928}\u{094D}", 48, 1],
        ["\u{0932}\u{093E}\u{0928}\u{094D}", -1, 1],
        ["\u{091B}\u{093F}\u{0928}\u{094D}", -1, 1],
        ["\u{0925}\u{093F}\u{0928}\u{094D}", -1, 1],
        ["\u{092A}\u{0930}\u{094D}", -1, 1],
        ["\u{0907}\u{0938}\u{094D}", -1, 1],
        ["\u{0925}\u{093F}\u{0907}\u{0938}\u{094D}", 58, 1],
        ["\u{091B}\u{0947}\u{0938}\u{094D}", -1, 1],
        ["\u{0939}\u{094B}\u{0938}\u{094D}", -1, 1],
        ["\u{091B}\u{0938}\u{094D}", -1, 1],
        ["\u{0907}\u{091B}\u{0938}\u{094D}", 62, 1],
        ["\u{0947}\u{091B}\u{0938}\u{094D}", 62, 1],
        ["\u{0928}\u{0947}\u{091B}\u{0938}\u{094D}", 64, 1],
        ["\u{090F}\u{091B}\u{0938}\u{094D}", 62, 1],
        ["\u{093F}\u{091B}\u{0938}\u{094D}", 62, 1],
        ["\u{093F}\u{0938}\u{094D}", -1, 1],
        ["\u{0925}\u{093F}\u{0938}\u{094D}", 68, 1],
        ["\u{0925}\u{093F}\u{090F}", -1, 1],
        ["\u{091B}", -1, 1],
        ["\u{0907}\u{091B}", 71, 1],
        ["\u{0947}\u{091B}", 71, 1],
        ["\u{0928}\u{0947}\u{091B}", 73, 1],
        ["\u{0939}\u{0941}\u{0928}\u{0947}\u{091B}", 74, 1],
        ["\u{0939}\u{0941}\u{0928}\u{094D}\u{091B}", 71, 1],
        ["\u{0907}\u{0928}\u{094D}\u{091B}", 71, 1],
        ["\u{093F}\u{0928}\u{094D}\u{091B}", 71, 1],
        ["\u{090F}\u{091B}", 71, 1],
        ["\u{093F}\u{091B}", 71, 1],
        ["\u{0947}\u{0915}\u{093E}", -1, 1],
        ["\u{0928}\u{0947}\u{0915}\u{093E}", 81, 1],
        ["\u{090F}\u{0915}\u{093E}", -1, 1],
        ["\u{0907}\u{090F}\u{0915}\u{093E}", 83, 1],
        ["\u{093F}\u{090F}\u{0915}\u{093E}", 83, 1],
        ["\u{0926}\u{093E}", -1, 1],
        ["\u{0907}\u{0926}\u{093E}", 86, 1],
        ["\u{093F}\u{0926}\u{093E}", 86, 1],
        ["\u{0926}\u{0947}\u{0916}\u{093F}", -1, 1],
        ["\u{092E}\u{093E}\u{0925}\u{093F}", -1, 1]
    ];



    protected function r_remove_category_1(): bool
    {
        $this->ket = $this->cursor;
        $among_var = $this->find_among_b(self::A_0);
        if (0 === $among_var) {
            return false;
        }
        $this->bra = $this->cursor;
        switch ($among_var) {
            case 1:
                $this->slice_del();
                break;
            case 2:
                $v_1 = $this->limit - $this->cursor;
                if (!($this->eq_s_b("\u{090F}"))) {
                    goto lab0;
                }
                goto lab1;
            lab0:
                $this->cursor = $this->limit - $v_1;
                if (!($this->eq_s_b("\u{0947}"))) {
                    goto lab2;
                }
                goto lab1;
            lab2:
                $this->cursor = $this->limit - $v_1;
                $this->slice_del();
            lab1:
                break;
        }
        return true;
    }


    protected function r_remove_category_2(): bool
    {
        $this->ket = $this->cursor;
        $among_var = $this->find_among_b(self::A_1);
        if (0 === $among_var) {
            return false;
        }
        $this->bra = $this->cursor;
        switch ($among_var) {
            case 1:
                $v_1 = $this->limit - $this->cursor;
                if (!($this->eq_s_b("\u{092F}\u{094C}"))) {
                    goto lab0;
                }
                goto lab1;
            lab0:
                $this->cursor = $this->limit - $v_1;
                if (!($this->eq_s_b("\u{091B}\u{094C}"))) {
                    goto lab2;
                }
                goto lab1;
            lab2:
                $this->cursor = $this->limit - $v_1;
                if (!($this->eq_s_b("\u{0928}\u{094C}"))) {
                    goto lab3;
                }
                goto lab1;
            lab3:
                $this->cursor = $this->limit - $v_1;
                if (!($this->eq_s_b("\u{0925}\u{0947}"))) {
                    return false;
                }
            lab1:
                $this->slice_del();
                break;
            case 2:
                if (!($this->eq_s_b("\u{0924}\u{094D}\u{0930}"))) {
                    return false;
                }
                $this->slice_del();
                break;
        }
        return true;
    }


    protected function r_remove_category_3(): bool
    {
        $this->ket = $this->cursor;
        if ($this->find_among_b(self::A_2) === 0) {
            return false;
        }
        $this->bra = $this->cursor;
        $this->slice_del();
        return true;
    }


    public function stem(): bool
    {
        $this->limit_backward = $this->cursor;
        $this->cursor = $this->limit;
        $v_1 = $this->limit - $this->cursor;
        $this->r_remove_category_1();
        $this->cursor = $this->limit - $v_1;
        while (true) {
            $v_2 = $this->limit - $this->cursor;
            $v_3 = $this->limit - $this->cursor;
            $this->r_remove_category_2();
            $this->cursor = $this->limit - $v_3;
            if (!$this->r_remove_category_3()) {
                goto lab0;
            }
            continue;
        lab0:
            $this->cursor = $this->limit - $v_2;
            break;
        }
        $this->cursor = $this->limit_backward;
        return true;
    }
}
