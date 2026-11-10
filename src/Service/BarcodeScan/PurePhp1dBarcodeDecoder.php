<?php

declare(strict_types=1);

namespace App\Service\BarcodeScan;

use GdImage;

/**
 * Lightweight 1D barcode reader (CODE-128 + Interleaved 2 of 5) using GD only.
 * Used when zbarimg is not available on the host.
 */
final class PurePhp1dBarcodeDecoder
{
    /** @var list<string> CODE-128 patterns 0..106 (stop is 7 widths / 13 modules) */
    private const CODE128 = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
        '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
        '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
        '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
        '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
        '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
        '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
        '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
        '114131', '311141', '411131', '211412', '211214', '211232', '2331112',
    ];

    /** @var list<string> */
    private const I25 = [
        'NNWWN', 'WNNNW', 'NWNNW', 'WWNNN', 'NNWNW',
        'WNWNN', 'NWWNN', 'NNNWW', 'WNNWN', 'NWNWN',
    ];

    /**
     * @return list<array{type: string, data: string}>
     */
    public function decodeFile(string $imagePath): array
    {
        if (!\function_exists('imagecreatefromstring')) {
            return [];
        }

        $binary = @file_get_contents($imagePath);
        if ($binary === false || $binary === '') {
            return [];
        }

        $img = @imagecreatefromstring($binary);
        if ($img === false) {
            return [];
        }

        try {
            return $this->decodeImage($img);
        } finally {
            imagedestroy($img);
        }
    }

    /**
     * @param GdImage $img
     *
     * @return list<array{type: string, data: string}>
     */
    public function decodeImage(GdImage $img): array
    {
        $width = imagesx($img);
        $height = imagesy($img);
        if ($width < 40 || $height < 10) {
            return [];
        }

        $fromCode128 = [];
        $fromI25 = [];
        $seen128 = [];
        $seenI25 = [];
        $rows = $this->candidateRows($img, $width, $height);

        foreach ([100, 115, 128, 140] as $threshold) {
            foreach ($rows as $y) {
                foreach ([false, true] as $invert) {
                    $runs = $this->rowRuns($img, $width, $y, $threshold, $invert);
                    if ($runs === []) {
                        continue;
                    }

                    foreach ([$runs, array_reverse($runs)] as $oriented) {
                        $c128 = $this->decodeCode128($oriented);
                        if ($c128 !== null) {
                            $key = $c128['type'].'|'.$c128['data'];
                            if (!isset($seen128[$key])) {
                                $seen128[$key] = true;
                                $fromCode128[] = $c128;
                            }
                            continue;
                        }

                        $i25 = $this->decodeInterleaved2of5($oriented);
                        if ($i25 !== null) {
                            $key = $i25['type'].'|'.$i25['data'];
                            if (!isset($seenI25[$key])) {
                                $seenI25[$key] = true;
                                $fromI25[] = $i25;
                            }
                        }
                    }
                }
            }

            // Strong CODE-128 checksum hits beat noisy I2/5 guesses.
            if ($fromCode128 !== []) {
                return $fromCode128;
            }
        }

        return $fromI25;
    }

    /**
     * Pick high-transition rows from each vertical band so page footer noise
     * cannot crowd out the real barcode band.
     *
     * @return list<int>
     */
    private function candidateRows(GdImage $img, int $width, int $height): array
    {
        $step = max(1, intdiv($height, 400));
        $bands = 6;
        $bandH = max(1, intdiv($height, $bands));
        /** @var array<int, list<array{0: int, 1: int}>> $byBand */
        $byBand = [];

        for ($y = 0; $y < $height; $y += $step) {
            $transitions = 0;
            $prev = null;
            for ($x = 0; $x < $width; ++$x) {
                $black = $this->isBlack($img, $x, $y, 128);
                if ($prev !== null && $black !== $prev) {
                    ++$transitions;
                }
                $prev = $black;
            }
            if ($transitions < 50) {
                continue;
            }
            $band = min($bands - 1, intdiv($y, $bandH));
            $byBand[$band][] = [$transitions, $y];
        }

        $picked = [];
        foreach ($byBand as $scored) {
            usort($scored, static fn (array $a, array $b): int => $b[0] <=> $a[0]);
            foreach (array_slice($scored, 0, 12) as [, $y]) {
                foreach ([-2, -1, 0, 1, 2] as $dy) {
                    $yy = $y + $dy;
                    if ($yy >= 0 && $yy < $height) {
                        $picked[$yy] = true;
                    }
                }
            }
        }

        $list = array_map('interval', array_keys($picked));
        sort($list);

        return $list;
    }

    /**
     * @return list<int>
     */
    private function rowRuns(GdImage $img, int $width, int $y, int $threshold, bool $invert): array
    {
        $bits = [];
        for ($x = 0; $x < $width; ++$x) {
            $black = $this->isBlack($img, $x, $y, $threshold);
            $bits[] = $invert ? !$black : $black;
        }

        $first = 0;
        while ($first < $width && $bits[$first] === false) {
            ++$first;
        }
        $last = $width - 1;
        while ($last > $first && $bits[$last] === false) {
            --$last;
        }
        if ($last - $first < 40) {
            return [];
        }

        $runs = [];
        $current = $bits[$first];
        $len = 1;
        for ($x = $first + 1; $x <= $last; ++$x) {
            if ($bits[$x] === $current) {
                ++$len;
                continue;
            }
            $runs[] = $len;
            $current = $bits[$x];
            $len = 1;
        }
        $runs[] = $len;

        if ($bits[$first] !== true) {
            array_shift($runs);
        }

        return count($runs) >= 20 ? array_values($runs) : [];
    }

    private function isBlack(GdImage $img, int $x, int $y, int $threshold): bool
    {
        $rgb = imagecolorat($img, $x, $y);
        $r = ($rgb >> 16) & 0xFF;
        $g = ($rgb >> 8) & 0xFF;
        $b = $rgb & 0xFF;

        return (($r + $g + $b) / 3) < $threshold;
    }

    /**
     * @param list<int> $runs
     *
     * @return array{type: string, data: string}|null
     */
    private function decodeCode128(array $runs): ?array
    {
        $n = count($runs);
        if ($n < 25) {
            return null;
        }

        $map = $this->code128Map();
        $maxOffset = $n - 25;

        for ($offset = 0; $offset <= $maxOffset; ++$offset) {
            $start = $this->matchCode128Pattern($runs, $offset, 6, $map, 11);
            if ($start === null || $start < 103 || $start > 105) {
                continue;
            }

            $codes = [$start];
            $pos = $offset + 6;
            $ok = true;

            while ($pos + 7 <= $n) {
                if (count($codes) >= 2) {
                    $stop = $this->matchCode128Pattern($runs, $pos, 7, $map, 13);
                    if ($stop === 106) {
                        break;
                    }
                }

                $value = $this->matchCode128Pattern($runs, $pos, 6, $map, 11);
                if ($value === null || $value === 106) {
                    $ok = false;
                    break;
                }
                $codes[] = $value;
                $pos += 6;

                if (count($codes) > 80) {
                    $ok = false;
                    break;
                }
            }

            if (!$ok || count($codes) < 3) {
                continue;
            }

            if ($this->matchCode128Pattern($runs, $pos, 7, $map, 13) !== 106) {
                continue;
            }

            $startCode = $codes[0];
            $check = $codes[count($codes) - 1];
            $payloadCodes = array_slice($codes, 1, -1);

            $sum = $startCode;
            foreach ($payloadCodes as $i => $value) {
                $sum += ($i + 1) * $value;
            }
            if ($sum % 103 !== $check) {
                continue;
            }

            $data = $this->code128Payload($startCode, $payloadCodes);
            if ($data === null || $data === '') {
                continue;
            }

            // Numeric CODE-128 ticket payloads are exposed as I2/5 for API stability.
            $type = (ctype_digit($data) && strlen($data) >= 6) ? 'I2/5' : 'CODE-128';

            return ['type' => $type, 'data' => $data];
        }

        return null;
    }

    /**
     * @param list<int> $payloadCodes
     */
    private function code128Payload(int $startCode, array $payloadCodes): ?string
    {
        $mode = match ($startCode) {
            103 => 'A',
            104 => 'B',
            105 => 'C',
            default => null,
        };
        if ($mode === null) {
            return null;
        }

        $out = '';
        foreach ($payloadCodes as $code) {
            if ($code === 99) {
                $mode = 'C';
                continue;
            }
            if ($code === 100) {
                $mode = 'B';
                continue;
            }
            if ($code === 101) {
                $mode = 'A';
                continue;
            }
            if ($code === 102) {
                continue;
            }
            if ($code >= 103) {
                return null;
            }

            if ($mode === 'C') {
                $out .= str_pad((string) $code, 2, '0', \STR_PAD_LEFT);
                continue;
            }

            if ($mode === 'B') {
                $out .= \chr(32 + $code);
                continue;
            }

            if ($code < 64) {
                $out .= \chr(32 + $code);
            } else {
                $out .= \chr($code - 64);
            }
        }

        return $out;
    }

    /**
     * @param list<int> $runs
     * @param array<string, int> $map
     */
    private function matchCode128Pattern(array $runs, int $offset, int $count, array $map, int $modules): ?int
    {
        if ($offset + $count > count($runs)) {
            return null;
        }

        $slice = array_slice($runs, $offset, $count);
        $sum = array_sum($slice);
        if ($sum <= 0) {
            return null;
        }

        $vals = [];
        $acc = 0;
        foreach ($slice as $r) {
            $w = (int) round(($r * $modules) / $sum);
            $w = max(1, min(4, $w));
            $vals[] = $w;
            $acc += $w;
        }

        if ($acc !== $modules) {
            $idx = 0;
            $max = -1;
            foreach ($vals as $i => $v) {
                if ($v > $max) {
                    $max = $v;
                    $idx = $i;
                }
            }
            $vals[$idx] = max(1, min(4, $vals[$idx] + ($modules - $acc)));
            if (array_sum($vals) !== $modules) {
                return null;
            }
        }

        return $map[implode('', $vals)] ?? null;
    }

    /**
     * @return array<string, int>
     */
    private function code128Map(): array
    {
        static $map = null;
        if (\is_array($map)) {
            return $map;
        }

        $map = [];
        foreach (self::CODE128 as $value => $pattern) {
            $map[$pattern] = $value;
        }

        return $map;
    }

    /**
     * @param list<int> $runs
     *
     * @return array{type: string, data: string}|null
     */
    private function decodeInterleaved2of5(array $runs): ?array
    {
        $n = count($runs);
        // start(4) + at least 5 digit-pairs(50) + stop(3) roughly; keep modest lower bound.
        if ($n < 4 + 30 + 3) {
            return null;
        }

        for ($offset = 0; $offset <= min(40, $n - 37); ++$offset) {
            $start = array_slice($runs, $offset, 4);
            if (!$this->isNarrowQuartet($start)) {
                continue;
            }

            $pos = $offset + 4;
            $digits = '';

            while ($pos + 10 + 3 <= $n) {
                $decoded = $this->decodeI25Block(array_slice($runs, $pos, 10));
                if ($decoded === null) {
                    break;
                }
                $digits .= $decoded;
                $pos += 10;
            }

            // Require a reasonably long even payload to avoid decorative false positives.
            if (strlen($digits) < 12 || (strlen($digits) % 2) !== 0) {
                continue;
            }

            $stop = array_slice($runs, $pos, 3);
            if (!$this->isI25Stop($stop)) {
                continue;
            }

            return ['type' => 'I2/5', 'data' => $digits];
        }

        return null;
    }

    /**
     * @param list<int> $runs
     */
    private function isNarrowQuartet(array $runs): bool
    {
        if (count($runs) < 4) {
            return false;
        }
        $avg = array_sum(array_slice($runs, 0, 4)) / 4.0;
        if ($avg <= 0) {
            return false;
        }
        foreach (array_slice($runs, 0, 4) as $r) {
            if ($r > $avg * 1.6) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<int> $runs
     */
    private function isI25Stop(array $runs): bool
    {
        if (count($runs) < 3) {
            return false;
        }

        return $runs[0] > $runs[1] * 1.5 && $runs[0] > $runs[2] * 1.5;
    }

    /**
     * @param list<int> $block
     */
    private function decodeI25Block(array $block): ?string
    {
        if (count($block) < 10) {
            return null;
        }

        $bars = [$block[0], $block[2], $block[4], $block[6], $block[8]];
        $spaces = [$block[1], $block[3], $block[5], $block[7], $block[9]];

        $d1 = $this->matchI25Digit($bars);
        $d2 = $this->matchI25Digit($spaces);
        if ($d1 === null || $d2 === null) {
            return null;
        }

        return $d1.$d2;
    }

    /**
     * @param list<int> $widths
     */
    private function matchI25Digit(array $widths): ?string
    {
        if (count($widths) !== 5) {
            return null;
        }

        $sorted = $widths;
        rsort($sorted);
        $threshold = ($sorted[1] + $sorted[2]) / 2.0;
        $pattern = '';
        foreach ($widths as $w) {
            $pattern .= $w >= $threshold ? 'W' : 'N';
        }

        if (substr_count($pattern, 'W') !== 2) {
            return null;
        }

        $idx = array_search($pattern, self::I25, true);

        return $idx === false ? null : (string) $idx;
    }
}
