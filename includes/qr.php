<?php

function qr_matrix(string $text): array
{
    $bytes = array_values(unpack('C*', $text));
    $count = count($bytes);
    $version = 1;
    $dataCodewords = 0;
    for ($try = 1; $try <= 10; $try++) {
        $dataCodewords = qr_data_codewords($try);
        if ($count <= $dataCodewords - 2) {
            $version = $try;
            break;
        }
    }
    $bits = '0100' . str_pad(decbin($count), 8, '0', STR_PAD_LEFT);
    foreach ($bytes as $byte) {
        $bits .= str_pad(decbin($byte), 8, '0', STR_PAD_LEFT);
    }
    $capacity = $dataCodewords * 8;
    $bits .= str_repeat('0', min(4, $capacity - strlen($bits)));
    while (strlen($bits) % 8 !== 0) {
        $bits .= '0';
    }
    $pad = ['11101100', '00010001'];
    $padAt = 0;
    while (strlen($bits) < $capacity) {
        $bits .= $pad[$padAt % 2];
        $padAt++;
    }
    $data = [];
    for ($i = 0; $i < $dataCodewords; $i++) {
        $data[] = bindec(substr($bits, $i * 8, 8));
    }
    $codewords = qr_interleave($data, $version);
    return qr_draw($codewords, $version);
}

function qr_data_codewords(int $version): int
{
    $blocks = qr_blocks($version);
    return $blocks[0] * $blocks[1] + $blocks[2] * $blocks[3];
}

function qr_blocks(int $version): array
{
    $table = [
        1 => [1, 16, 0, 0, 10],
        2 => [1, 28, 0, 0, 16],
        3 => [1, 44, 0, 0, 26],
        4 => [2, 32, 0, 0, 18],
        5 => [2, 43, 0, 0, 24],
        6 => [4, 27, 0, 0, 16],
        7 => [4, 31, 0, 0, 18],
        8 => [2, 38, 2, 39, 22],
        9 => [3, 36, 2, 37, 22],
        10 => [4, 43, 1, 44, 26],
    ];
    return $table[$version];
}

function qr_interleave(array $data, int $version): array
{
    [$g1, $d1, $g2, $d2, $ec] = qr_blocks($version);
    $blocks = [];
    $offset = 0;
    for ($i = 0; $i < $g1; $i++) {
        $chunk = array_slice($data, $offset, $d1);
        $offset += $d1;
        $blocks[] = [$chunk, qr_remainder($chunk, $ec)];
    }
    for ($i = 0; $i < $g2; $i++) {
        $chunk = array_slice($data, $offset, $d2);
        $offset += $d2;
        $blocks[] = [$chunk, qr_remainder($chunk, $ec)];
    }
    $out = [];
    $longest = max($d1, $d2);
    for ($i = 0; $i < $longest; $i++) {
        foreach ($blocks as [$chunk]) {
            if ($i < count($chunk)) {
                $out[] = $chunk[$i];
            }
        }
    }
    for ($i = 0; $i < $ec; $i++) {
        foreach ($blocks as [, $check]) {
            $out[] = $check[$i];
        }
    }
    return $out;
}

function qr_remainder(array $data, int $ec): array
{
    $gen = [1];
    for ($i = 0; $i < $ec; $i++) {
        $next = array_fill(0, count($gen) + 1, 0);
        foreach ($gen as $index => $coeff) {
            $next[$index] ^= qr_mul($coeff, qr_exp($i));
            $next[$index + 1] ^= $coeff;
        }
        $gen = $next;
    }
    $gen = array_reverse($gen);
    $result = array_merge($data, array_fill(0, $ec, 0));
    $dataLength = count($data);
    for ($i = 0; $i < $dataLength; $i++) {
        $factor = $result[$i];
        if ($factor === 0) {
            continue;
        }
        foreach ($gen as $index => $coeff) {
            $result[$i + $index] ^= qr_mul($coeff, $factor);
        }
    }
    return array_slice($result, $dataLength);
}

function qr_exp(int $n): int
{
    static $exp = null;
    static $log = null;
    if ($exp === null) {
        $exp = [];
        $log = array_fill(0, 256, 0);
        $value = 1;
        for ($i = 0; $i < 255; $i++) {
            $exp[$i] = $value;
            $log[$value] = $i;
            $value <<= 1;
            if ($value & 0x100) {
                $value ^= 0x11d;
            }
        }
        $GLOBALS['qr_log'] = $log;
    }
    return $exp[$n % 255];
}

function qr_mul(int $a, int $b): int
{
    if ($a === 0 || $b === 0) {
        return 0;
    }
    qr_exp(0);
    return qr_exp($GLOBALS['qr_log'][$a] + $GLOBALS['qr_log'][$b]);
}

function qr_draw(array $codewords, int $version): array
{
    $size = 21 + ($version - 1) * 4;
    $dark = array_fill(0, $size, array_fill(0, $size, false));
    $func = array_fill(0, $size, array_fill(0, $size, false));
    $mark = function (int $x, int $y, bool $on) use (&$dark, &$func, $size): void {
        if ($x < 0 || $y < 0 || $x >= $size || $y >= $size) {
            return;
        }
        $dark[$y][$x] = $on;
        $func[$y][$x] = true;
    };
    $finder = function (int $ox, int $oy) use ($mark): void {
        for ($y = -1; $y <= 7; $y++) {
            for ($x = -1; $x <= 7; $x++) {
                $on = $x >= 0 && $x <= 6 && $y >= 0 && $y <= 6
                    && ($x === 0 || $x === 6 || $y === 0 || $y === 6 || ($x >= 2 && $x <= 4 && $y >= 2 && $y <= 4));
                $mark($ox + $x, $oy + $y, $on);
            }
        }
    };
    $finder(0, 0);
    $finder($size - 7, 0);
    $finder(0, $size - 7);
    for ($i = 0; $i < $size; $i++) {
        if (!$func[6][$i]) {
            $mark($i, 6, $i % 2 === 0);
        }
        if (!$func[$i][6]) {
            $mark(6, $i, $i % 2 === 0);
        }
    }
    $centers = [
        2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30], 6 => [6, 34],
        7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46], 10 => [6, 28, 50],
    ][$version] ?? [];
    foreach ($centers as $cy) {
        foreach ($centers as $cx) {
            if ($func[$cy][$cx]) {
                continue;
            }
            for ($y = -2; $y <= 2; $y++) {
                for ($x = -2; $x <= 2; $x++) {
                    $mark($cx + $x, $cy + $y, max(abs($x), abs($y)) !== 1);
                }
            }
        }
    }
    $mark(8, 4 * $version + 9, true);
    $reserve = function (int $x, int $y) use (&$func, $size): void {
        if ($x >= 0 && $y >= 0 && $x < $size && $y < $size) {
            $func[$y][$x] = true;
        }
    };
    for ($i = 0; $i < 9; $i++) {
        $reserve(8, $i);
        $reserve($i, 8);
    }
    for ($i = 0; $i < 8; $i++) {
        $reserve($size - 1 - $i, 8);
        $reserve(8, $size - 1 - $i);
    }

    $bits = '';
    foreach ($codewords as $word) {
        $bits .= str_pad(decbin($word), 8, '0', STR_PAD_LEFT);
    }
    $bitAt = 0;
    $up = true;
    for ($col = $size - 1; $col > 0; $col -= 2) {
        if ($col === 6) {
            $col--;
        }
        for ($step = 0; $step < $size; $step++) {
            $y = $up ? $size - 1 - $step : $step;
            foreach ([0, -1] as $shift) {
                $x = $col + $shift;
                if ($func[$y][$x]) {
                    continue;
                }
                $dark[$y][$x] = $bitAt < strlen($bits) && $bits[$bitAt] === '1';
                $bitAt++;
            }
        }
        $up = !$up;
    }

    $best = null;
    $bestScore = PHP_INT_MAX;
    $bestMask = 0;
    for ($mask = 0; $mask < 8; $mask++) {
        $grid = $dark;
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                if (!$func[$y][$x] && qr_masked($x, $y, $mask)) {
                    $grid[$y][$x] = !$grid[$y][$x];
                }
            }
        }
        qr_format($grid, $size, $mask);
        $score = qr_penalty($grid, $size);
        if ($score < $bestScore) {
            $bestScore = $score;
            $best = $grid;
            $bestMask = $mask;
        }
    }
    return $best;
}

function qr_masked(int $x, int $y, int $mask): bool
{
    return match ($mask) {
        0 => ($x + $y) % 2 === 0,
        1 => $y % 2 === 0,
        2 => $x % 3 === 0,
        3 => ($x + $y) % 3 === 0,
        4 => (intdiv($y, 2) + intdiv($x, 3)) % 2 === 0,
        5 => ($x * $y) % 2 + ($x * $y) % 3 === 0,
        6 => (($x * $y) % 2 + ($x * $y) % 3) % 2 === 0,
        default => (($x + $y) % 2 + ($x * $y) % 3) % 2 === 0,
    };
}

function qr_format(array &$grid, int $size, int $mask): void
{
    $data = $mask;
    $rem = $data;
    for ($i = 0; $i < 10; $i++) {
        $rem = ($rem << 1) ^ ((($rem >> 9) & 1) * 0x537);
    }
    $format = (($data << 10) | ($rem & 0x3ff)) ^ 0x5412;
    $bit = function (int $i) use ($format): bool {
        return (($format >> $i) & 1) === 1;
    };
    for ($i = 0; $i <= 5; $i++) {
        $grid[$i][8] = $bit($i);
    }
    $grid[7][8] = $bit(6);
    $grid[8][8] = $bit(7);
    $grid[8][7] = $bit(8);
    for ($i = 9; $i < 15; $i++) {
        $grid[8][14 - $i] = $bit($i);
    }
    for ($i = 0; $i < 8; $i++) {
        $grid[8][$size - 1 - $i] = $bit($i);
    }
    for ($i = 8; $i < 15; $i++) {
        $grid[$size - 15 + $i][8] = $bit($i);
    }
}

function qr_penalty(array $grid, int $size): int
{
    $score = 0;
    for ($y = 0; $y < $size; $y++) {
        $run = 1;
        for ($x = 1; $x < $size; $x++) {
            if ($grid[$y][$x] === $grid[$y][$x - 1]) {
                $run++;
                if ($run === 5) {
                    $score += 3;
                } elseif ($run > 5) {
                    $score++;
                }
            } else {
                $run = 1;
            }
        }
    }
    for ($x = 0; $x < $size; $x++) {
        $run = 1;
        for ($y = 1; $y < $size; $y++) {
            if ($grid[$y][$x] === $grid[$y - 1][$x]) {
                $run++;
                if ($run === 5) {
                    $score += 3;
                } elseif ($run > 5) {
                    $score++;
                }
            } else {
                $run = 1;
            }
        }
    }
    for ($y = 0; $y < $size - 1; $y++) {
        for ($x = 0; $x < $size - 1; $x++) {
            $on = $grid[$y][$x];
            if ($on === $grid[$y][$x + 1] && $on === $grid[$y + 1][$x] && $on === $grid[$y + 1][$x + 1]) {
                $score += 3;
            }
        }
    }
    $darkCount = 0;
    foreach ($grid as $row) {
        foreach ($row as $cell) {
            if ($cell) {
                $darkCount++;
            }
        }
    }
    $percent = (int) (abs($darkCount * 100 / ($size * $size) - 50) / 5);
    return $score + $percent * 10;
}

function qr_svg(array $matrix, int $pixels = 148): string
{
    $n = count($matrix);
    $quiet = 4;
    $scale = $pixels / ($n + $quiet * 2);
    $rects = '';
    for ($y = 0; $y < $n; $y++) {
        for ($x = 0; $x < $n; $x++) {
            if (!$matrix[$y][$x]) {
                continue;
            }
            $rects .= '<rect fill="#000" x="' . round(($x + $quiet) * $scale, 2) . '" y="' . round(($y + $quiet) * $scale, 2)
                . '" width="' . round($scale, 2) . '" height="' . round($scale, 2) . '"/>';
        }
    }
    return '<svg class="qr" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $pixels . ' ' . $pixels
        . '" width="' . $pixels . '" height="' . $pixels . '" role="img" aria-label="Ticket QR code">'
        . '<rect width="100%" height="100%" fill="#fff"/>' . $rects . '</svg>';
}
