<?php

namespace Fuzor\Stemmers;

abstract class SnowballStemmer
{
    protected string $current = '';
    protected int $cursor = 0;
    protected int $limit = 0;
    protected int $limit_backward = 0;
    protected int $bra = 0;
    protected int $ket = 0;

    /** @var array<string, string> */
    private array $stemCache = [];

    abstract public function stem(): bool;

    protected function copyFrom(self $other): void
    {
        $this->current          = $other->current;
        $this->cursor           = $other->cursor;
        $this->limit            = $other->limit;
        $this->limit_backward   = $other->limit_backward;
        $this->bra              = $other->bra;
        $this->ket              = $other->ket;
    }


    /**
     * @param array<string, bool> $s
     */
    protected function in_grouping(array $s): bool
    {
        if ($this->cursor >= $this->limit) {
            return false;
        }
        $b = $this->current[$this->cursor];
        $o = ord($b[0]);
        if ($o < 0x80) {
            if (!isset($s[$b])) {
                return false;
            }
            ++$this->cursor;
            return true;
        }
        $chLen = $o < 0xe0 ? 2 : ($o < 0xf0 ? 3 : 4);
        $ch = substr($this->current, $this->cursor, $chLen);
        if (!isset($s[$ch])) {
            return false;
        }
        $this->cursor += $chLen;
        return true;
    }


    /**
     * @param array<string, bool> $s
     */
    protected function go_in_grouping(array $s): bool
    {
        while ($this->cursor < $this->limit) {
            $b = $this->current[$this->cursor];
            $o = ord($b[0]);
            if ($o < 0x80) {
                if (!isset($s[$b])) {
                    return true;
                }
                ++$this->cursor;
            } else {
                $chLen = $o < 0xe0 ? 2 : ($o < 0xf0 ? 3 : 4);
                $ch = substr($this->current, $this->cursor, $chLen);
                if (!isset($s[$ch])) {
                    return true;
                }
                $this->cursor += $chLen;
            }
        }
        return false;
    }


    /**
     * @param array<string, bool> $s
     */
    protected function in_grouping_b(array $s): bool
    {
        if ($this->cursor <= $this->limit_backward) {
            return false;
        }
        $b = $this->current[$this->cursor - 1];
        if (ord($b[0]) < 0x80) {
            if (!isset($s[$b])) {
                return false;
            }
            --$this->cursor;
            return true;
        }
        $o = $this->cursor - 1;
        while ($o > 0 && ord($this->current[$o]) < 0xc0) {
            $o--;
        }
        $ch = substr($this->current, $o, $this->cursor - $o);
        if (!isset($s[$ch])) {
            return false;
        }
        $this->cursor = $o;
        return true;
    }


    /**
     * @param array<string, bool> $s
     */
    protected function go_in_grouping_b(array $s): bool
    {
        while ($this->cursor > $this->limit_backward) {
            $b = $this->current[$this->cursor - 1];
            if (ord($b[0]) < 0x80) {
                if (!isset($s[$b])) {
                    return true;
                }
                --$this->cursor;
            } else {
                $o = $this->cursor - 1;
                while ($o > 0 && ord($this->current[$o]) < 0xc0) {
                    $o--;
                }
                $ch = substr($this->current, $o, $this->cursor - $o);
                if (!isset($s[$ch])) {
                    return true;
                }
                $this->cursor = $o;
            }
        }
        return false;
    }


    /**
     * @param array<string, bool> $s
     */
    protected function out_grouping(array $s): bool
    {
        if ($this->cursor >= $this->limit) {
            return false;
        }
        $b = $this->current[$this->cursor];
        $o = ord($b[0]);
        if ($o < 0x80) {
            if (isset($s[$b])) {
                return false;
            }
            ++$this->cursor;
            return true;
        }
        $chLen = $o < 0xe0 ? 2 : ($o < 0xf0 ? 3 : 4);
        $ch = substr($this->current, $this->cursor, $chLen);
        if (isset($s[$ch])) {
            return false;
        }
        $this->cursor += $chLen;
        return true;
    }


    /**
     * @param array<string, bool> $s
     */
    protected function go_out_grouping(array $s): bool
    {
        while ($this->cursor < $this->limit) {
            $b = $this->current[$this->cursor];
            $o = ord($b[0]);
            if ($o < 0x80) {
                if (isset($s[$b])) {
                    return true;
                }
                ++$this->cursor;
            } else {
                $chLen = $o < 0xe0 ? 2 : ($o < 0xf0 ? 3 : 4);
                $ch = substr($this->current, $this->cursor, $chLen);
                if (isset($s[$ch])) {
                    return true;
                }
                $this->cursor += $chLen;
            }
        }
        return false;
    }


    /**
     * @param array<string, bool> $s
     */
    protected function out_grouping_b(array $s): bool
    {
        if ($this->cursor <= $this->limit_backward) {
            return false;
        }
        $b = $this->current[$this->cursor - 1];
        if (ord($b[0]) < 0x80) {
            if (isset($s[$b])) {
                return false;
            }
            --$this->cursor;
            return true;
        }
        $o = $this->cursor - 1;
        while ($o > 0 && ord($this->current[$o]) < 0xc0) {
            $o--;
        }
        $ch = substr($this->current, $o, $this->cursor - $o);
        if (isset($s[$ch])) {
            return false;
        }
        $this->cursor = $o;
        return true;
    }


    /**
     * @param array<string, bool> $s
     */
    protected function go_out_grouping_b(array $s): bool
    {
        while ($this->cursor > $this->limit_backward) {
            $b = $this->current[$this->cursor - 1];
            if (ord($b[0]) < 0x80) {
                if (isset($s[$b])) {
                    return true;
                }
                --$this->cursor;
            } else {
                $o = $this->cursor - 1;
                while ($o > 0 && ord($this->current[$o]) < 0xc0) {
                    $o--;
                }
                $ch = substr($this->current, $o, $this->cursor - $o);
                if (isset($s[$ch])) {
                    return true;
                }
                $this->cursor = $o;
            }
        }
        return false;
    }


    protected function eq_s(string $s): bool
    {
        $slength = strlen($s);
        if ($this->limit - $this->cursor < $slength) {
            return false;
        }
        if (substr_compare($this->current, $s, $this->cursor, $slength) !== 0) {
            return false;
        }
        $this->cursor += $slength;
        return true;
    }


    protected function eq_s_b(string $s): bool
    {
        $slength = strlen($s);
        if ($this->cursor - $this->limit_backward < $slength) {
            return false;
        }
        if (substr_compare($this->current, $s, $this->cursor - $slength, $slength) !== 0) {
            return false;
        }
        $this->cursor -= $slength;
        return true;
    }


    /**
     * @param array[] $v
     */
    protected function find_among(array $v): int
    {
        $i = 0;
        $j = count($v);

        $c = $this->cursor;
        $l = $this->limit;
        $cur = $this->current;

        $common_i = 0;
        $common_j = 0;

        $first_key_inspected = false;

        while (true) {
            $k = $i + (($j - $i) >> 1);
            $diff = 0;
            $common = min($common_i, $common_j);
            // w[0]: string, w[1]: substring_i, w[2]: result, w[3]: function (optional)
            $w = $v[$k];
            $w0 = $w[0];
            $w0length = strlen((string) $w0);
            // $i2 always equals $common, so use $common directly
            while ($common < $w0length) {
                if ($c + $common === $l) {
                    $diff = -1;
                    break;
                }
                if (($diff = $cur[$c + $common] <=> $w0[$common]) !== 0) {
                    break;
                }
                $common++;
            }
            if ($diff < 0) {
                $j = $k;
                $common_j = $common;
            } else {
                $i = $k;
                $common_i = $common;
            }
            if ($j - $i <= 1) {
                if ($i > 0) {
                    break;
                }
                if ($j === $i) {
                    break;
                }
                if ($first_key_inspected) {
                    break;
                }
                $first_key_inspected = true;
            }
        }
        do {
            $w = $v[$i];
            $w0length = strlen((string) $w[0]);
            if ($common_i >= $w0length) {
                $this->cursor = $c + $w0length;
                if (!isset($w[3])) {
                    return $w[2];
                }
                $res = $this->{$w[3]}();
                $this->cursor = $c + $w0length;
                if ($res) {
                    return $w[2];
                }
            }
            $i = $w[1];
        } while ($i >= 0);
        return 0;
    }


    /**
     * find_among_b is for backwards processing. Same comments apply
     */
    protected function find_among_b(array $v): int
    {
        $i = 0;
        $j = count($v);

        $c = $this->cursor;
        $lb = $this->limit_backward;
        $cur = $this->current;

        $common_i = 0;
        $common_j = 0;

        $first_key_inspected = false;

        while (true) {
            $k = $i + (($j - $i) >> 1);
            $diff = 0;
            $common = min($common_i, $common_j);
            $w = $v[$k];
            $w0 = $w[0];
            $w0length = strlen((string) $w0);
            $w0lenMinus1 = $w0length - 1;
            while ($common < $w0length) {
                if ($c - $common === $lb) {
                    $diff = -1;
                    break;
                }
                if (($diff = $cur[$c - 1 - $common] <=> $w0[$w0lenMinus1 - $common]) !== 0) {
                    break;
                }
                $common++;
            }
            if ($diff < 0) {
                $j = $k;
                $common_j = $common;
            } else {
                $i = $k;
                $common_i = $common;
            }
            if ($j - $i <= 1) {
                if ($i > 0 || $j === $i || $first_key_inspected) {
                    break;
                }
                $first_key_inspected = true;
            }
        }
        do {
            $w = $v[$i];
            $w0length = strlen((string) $w[0]);
            if ($common_i >= $w0length) {
                $this->cursor = $c - $w0length;
                if (!isset($w[3])) {
                    return $w[2];
                }
                $res = $this->{$w[3]}();
                $this->cursor = $c - $w0length;
                if ($res) {
                    return $w[2];
                }
            }
            $i = $w[1];
        } while ($i >= 0);
        return 0;
    }


    /**
     * to replace chars between $c_bra and $c_ket in $this->current by the chars in $s.
     */
    private function replace_s(int $c_bra, int $c_ket, string $s): int
    {
        $slength = strlen($s);
        $adjustment = $slength - ($c_ket - $c_bra);
        $this->current = substr_replace($this->current, $s, $c_bra, $c_ket - $c_bra);
        $this->limit += $adjustment;
        if ($this->cursor >= $c_ket) {
            $this->cursor += $adjustment;
        } elseif ($this->cursor > $c_bra) {
            $this->cursor = $c_bra;
        }
        return $adjustment;
    }


    private function slice_check(): void
    {
        if (
            $this->bra < 0 ||
            $this->bra > $this->ket ||
            $this->ket > $this->limit ||
            $this->limit > strlen($this->current)
        ) {
            throw new \LogicException('Faulty slice operation');
        }
    }


    protected function slice_from(string $s): void
    {
        $this->slice_check();
        $this->replace_s($this->bra, $this->ket, $s);
        $this->ket = $this->bra + strlen($s);
    }


    protected function slice_del(): void
    {
        $this->slice_from('');
    }


    protected function insert(int $c_bra, int $c_ket, string $s): void
    {
        $adjustment = $this->replace_s($c_bra, $c_ket, $s);
        if ($c_bra <= $this->bra) {
            $this->bra += $adjustment;
        }
        if ($c_bra <= $this->ket) {
            $this->ket += $adjustment;
        }
    }


    protected function slice_to(): string
    {
        $this->slice_check();
        return substr($this->current, $this->bra, $this->ket - $this->bra);
    }

    protected function inc_cursor(): void
    {
        do {
            ++$this->cursor;
        } while ($this->cursor < $this->limit && (ord($this->current[$this->cursor]) & 0xc0) === 0x80);
    }

    protected function dec_cursor(): void
    {
        do {
            --$this->cursor;
        } while ($this->cursor > $this->limit_backward && (ord($this->current[$this->cursor]) & 0xc0) === 0x80);
    }

    protected function hop(int $delta): bool
    {
        $res = $this->cursor;
        while ($delta > 0) {
            $delta--;
            if ($res >= $this->limit) {
                return false;
            }
            do {
                $res++;
            } while ($res < $this->limit && (ord($this->current[$res]) & 0xc0) === 0x80);
        }
        $this->cursor = $res;
        return true;
    }

    protected function hop_checked(int $delta): bool
    {
        return $delta >= 0 && $this->hop($delta);
    }

    protected function hop_back(int $delta): bool
    {
        $res = $this->cursor;
        while ($delta > 0) {
            $delta--;
            if ($res <= $this->limit_backward) {
                return false;
            }
            do {
                $res--;
            } while ($res > $this->limit_backward && (ord($this->current[$res]) & 0xc0) === 0x80);
        }
        $this->cursor = $res;
        return true;
    }

    protected function hop_back_checked(int $delta): bool
    {
        return $delta >= 0 && $this->hop_back($delta);
    }

    /**
     * Public entry point for stemming a word
     */
    public function stemWord(string $word): string
    {
        return $this->stemCache[$word] ??= $this->runStem($word);
    }

    private function runStem(string $word): string
    {
        $this->current = $word;
        $this->cursor = 0;
        $this->limit = strlen($word);
        $this->limit_backward = 0;
        $this->bra = $this->cursor;
        $this->ket = $this->limit;
        $this->stem();
        return $this->current;
    }
}
