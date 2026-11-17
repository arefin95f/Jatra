<?php

function ticket_edge_bin(): ?string
{
    foreach ([
        'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
        'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
    ] as $path) {
        if (is_file($path)) {
            return $path;
        }
    }
    return null;
}

function ticket_website_css(int $width = 618): string
{
    return <<<CSS
* { box-sizing: border-box; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
html, body {
  margin: 0; padding: 0; width: {$width}px;
  background: #fff; color: #17283b;
  font-family: "Segoe UI", "DejaVu Sans", sans-serif;
  line-height: 1.25;
}
.ticket { width: {$width}px; margin: 0; background: #fff; }
.ticket-main { padding: 32px 32px 32px; }
.kicker { margin: 0; color: #8b5b12; font-size: 11px; font-weight: 800; letter-spacing: 0.16em; text-transform: uppercase; line-height: 1.3; }
.ticket-route { margin: 8px 0 8px; }
.ticket-route > div { display: inline-block; vertical-align: top; text-align: left; }
.ticket-route > div + div { float: right; }
.ticket-route small { display: block; color: #607287; font-size: 11px; line-height: 1.3; margin: 0; }
.ticket-route strong { display: block; font-size: 26px; font-weight: 800; letter-spacing: -0.03em; line-height: 1; margin: -2px 0 0; }
.ticket-meta { margin: 0; font-size: 0; }
.ticket-meta > div { display: inline-block; width: 48.7%; margin: 0 2.6% 8px 0; vertical-align: top; font-size: 16px; }
.ticket-meta > div:nth-child(2n) { margin-right: 0; }
.ticket-meta dt { display: block; color: #607287; font-size: 11px; font-weight: 400; line-height: 1.3; margin: 0; }
.ticket-meta dd { display: block; margin: -2px 0 0; font-weight: 800; font-size: 16px; line-height: 1.15; }
.ticket-bought { display: block; margin: 8px 0 0; }
.ticket-bought dt { display: block; color: #607287; font-size: 11px; font-weight: 400; line-height: 1.3; margin: 0; }
.ticket-bought dd { display: block; margin: -2px 0 0; font-weight: 800; font-size: 16px; line-height: 1.15; }
.enjoy { margin: 10px 0 0; color: #263d57; font-weight: 700; font-size: 14px; line-height: 1.25; }
.ticket-side {
  background: #14263d; color: #fff; padding: 18px 20px 18px;
  border-top: 2px dashed #8eabda;
}
.ticket.train .ticket-side { background: #0e7c66; border-top-color: #7dcebb; }
.ticket.plane .ticket-side { background: #1f4e89; border-top-color: #8ba6c7; }
.ticket-side { font-size: 0; }
.ticket-facts { display: inline-block; width: 466px; vertical-align: top; font-size: 14px; line-height: 1.25; }
.ticket-qr { display: inline-block; width: 112px; vertical-align: top; text-align: center; margin: 48px 0 0 0; font-size: 12px; }
.ticket-who .ticket-kicker, .ticket-who b, .ticket-who .ticket-nid { display: block; margin: 0; line-height: 1.25; }
.ticket-who b { font-size: 18px; font-weight: 800; letter-spacing: -0.02em; }
.ticket-kicker { color: #9eb0c8; font-size: 10px; font-weight: 800; letter-spacing: 0.14em; text-transform: uppercase; }
.ticket.train .ticket-kicker { color: #b7e5d8; }
.ticket-nid { font-size: 16px; font-weight: 800; letter-spacing: 0.08em; }
.ticket-rule { height: 1px; margin: 6px 0; background: #3a516c; }
.ticket-code { display: block; margin: 14px 0 6px; font-weight: 700; letter-spacing: 0.04em; font-size: 14px; line-height: 1.25; }
.ticket-facts > span, .ticket-facts > strong { display: block; margin: 4px 0 0; }
.ticket-svc { color: #d5deea; font-size: 13px; margin-top: 4px; }
.ticket-pay { display: table; margin-top: 6px; font-size: 14px; border-spacing: 0; }
.ticket-pay .badge, .ticket-pay small { display: table-cell; vertical-align: middle; }
.ticket-pay small { color: #d5deea; font-size: 12px; padding-left: 8px; }
.ticket-side strong { display: block; font-size: 22px; color: #e9a83b; line-height: 1; font-weight: 800; margin: 4px 0 0; }
.ticket-qr .qr, .ticket-qr svg, .ticket-qr .qr-grid { background: #fff; border-radius: 8px; width: 112px; }
.ticket-qr small { display: block; color: #d5deea; text-align: center; margin-top: 6px; font-size: 12px; }
.badge {
  display: inline-block; border-radius: 999px; padding: 3px 8px;
  font-size: 12px; font-weight: 700; background: #e5f7ef; color: #12805c;
}
.badge.cancelled, .badge.blocked { background: #fdecec; color: #bf3d46; }
.badge.pending { background: #fff4df; color: #a9600b; }
.badge.completed, .badge.refunded { background: #eef2f6; color: #526277; }
CSS;
}

function ticket_card_html(array $booking): string
{
    ob_start();
    ticket_document($booking);
    return (string) ob_get_clean();
}

function ticket_print_html(array $booking, ?int $heightPx = null): string
{
    $width = 618;
    $card = ticket_card_html($booking);
    $css = ticket_website_css($width);
    if ($heightPx !== null && $heightPx > 0) {
        $wMm = round($width * 25.4 / 96, 2);
        $hMm = round($heightPx * 25.4 / 96, 2);
        $css = "@page { size: {$wMm}mm {$hMm}mm; margin: 0; }\n" . $css
            . "html, body, .ticket { width: {$width}px; height: {$heightPx}px; max-height: {$heightPx}px; overflow: hidden; }\n";
    }
    $title = htmlspecialchars((string) $booking['booking_code'], ENT_QUOTES, 'UTF-8');
    $measure = $heightPx === null
        ? '<script>document.documentElement.setAttribute("data-h", String(Math.ceil(document.querySelector(".ticket").getBoundingClientRect().height)));</script>'
        : '';
    return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=' . $width . '">'
        . '<title>' . $title . '</title><style>' . $css . '</style></head><body>'
        . $card . $measure
        . '</body></html>';
}

function ticket_run_edge(string $edge, string $html, string $dir, ?string $pdfFile = null): string
{
    $htmlFile = $dir . DIRECTORY_SEPARATOR . 'ticket.html';
    $profile = $dir . DIRECTORY_SEPARATOR . 'profile';
    $stdoutFile = $dir . DIRECTORY_SEPARATOR . 'stdout.log';
    $stderrFile = $dir . DIRECTORY_SEPARATOR . 'stderr.log';
    file_put_contents($htmlFile, $html);
    $cmd = escapeshellarg($edge)
        . ' --headless=new --disable-gpu --no-first-run --no-default-browser-check --disable-extensions'
        . ' --no-pdf-header-footer --user-data-dir=' . escapeshellarg($profile);
    if ($pdfFile !== null) {
        $cmd .= ' --print-to-pdf=' . escapeshellarg($pdfFile);
    } else {
        $cmd .= ' --dump-dom';
    }
    $cmd .= ' ' . escapeshellarg($htmlFile);
    $pipes = [];
    $process = proc_open(
        $cmd,
        [0 => ['pipe', 'r'], 1 => ['file', $stdoutFile, 'w'], 2 => ['file', $stderrFile, 'w']],
        $pipes,
        null,
        null,
        ['bypass_shell' => true]
    );
    if (!is_resource($process)) {
        return '';
    }
    fclose($pipes[0]);
    $deadline = microtime(true) + 12;
    do {
        $status = proc_get_status($process);
        if (!$status['running']) {
            break;
        }
        if (microtime(true) >= $deadline) {
            proc_terminate($process, 9);
            break;
        }
        usleep(100000);
    } while (true);
    proc_close($process);
    return is_file($stdoutFile) ? (string) file_get_contents($stdoutFile) : '';
}

function ticket_measure_height(array $booking, string $edge): int
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'jatra-measure-' . bin2hex(random_bytes(4));
    if (!mkdir($dir) && !is_dir($dir)) {
        return 460;
    }
    $dom = ticket_run_edge($edge, ticket_print_html($booking, null), $dir, null);
    ticket_pdf_cleanup($dir);
    if (preg_match('/data-h="(\d+)"/', $dom, $m)) {
        return max(360, min(900, (int) $m[1]));
    }
    return 460;
}

function ticket_pdf_page_count(string $pdf): int
{
    if (preg_match('/\/Type\s*\/Pages[^>]*\/Count\s+(\d+)/s', $pdf, $m)) {
        return (int) $m[1];
    }
    if (preg_match('/\/Count\s+(\d+)/', $pdf, $m)) {
        return (int) $m[1];
    }
    return 0;
}

function ticket_pdf(array $booking, array $matrix): string
{
    $rendered = ticket_pdf_browser($booking);
    if ($rendered !== null) {
        return $rendered;
    }
    $rendered = ticket_pdf_dom($booking);
    if ($rendered !== null) {
        return $rendered;
    }
    throw new RuntimeException('The ticket PDF could not be created.');
}

function ticket_qr_table(array $matrix): string
{
    $n = count($matrix);
    if ($n === 0) {
        return '';
    }
    $quiet = 2;
    $html = '<table class="qr-grid" cellspacing="0" cellpadding="0">';
    for ($y = -$quiet; $y < $n + $quiet; $y++) {
        $html .= '<tr>';
        for ($x = -$quiet; $x < $n + $quiet; $x++) {
            $dark = $x >= 0 && $y >= 0 && $x < $n && $y < $n && !empty($matrix[$y][$x]);
            $html .= '<td style="width:3px;height:3px;padding:0;background:' . ($dark ? '#000' : '#fff') . ';font-size:1px;line-height:0"></td>';
        }
        $html .= '</tr>';
    }
    return $html . '</table>';
}

function ticket_pdf_dom(array $booking): ?string
{
    $autoload = dirname(__DIR__) . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        return null;
    }
    require_once $autoload;
    if (!class_exists(Dompdf\Dompdf::class)) {
        return null;
    }
    require_once __DIR__ . '/qr.php';
    $html = preg_replace('/<script\b[^>]*>.*?<\/script>/s', '', ticket_print_html($booking, null)) ?? '';
    $html = str_replace(
        'font-family: "Segoe UI", "DejaVu Sans", sans-serif;',
        'font-family: "DejaVu Sans", sans-serif;',
        $html
    );
    $fontFile = 'assets/fonts/NotoSansBengali-Bold.ttf';
    $fontCss = '@font-face { font-family: "Jatra Taka"; font-weight: normal; src: url("' . $fontFile . '") format("truetype"); }'
        . '@font-face { font-family: "Jatra Taka"; font-weight: bold; src: url("' . $fontFile . '") format("truetype"); }'
        . '.taka { font-family: "Jatra Taka", sans-serif; font-weight: 700; }'
        . '.qr-grid { border-collapse: collapse; background: #fff; margin: 0 auto; }';
    $html = str_replace('</style>', $fontCss . '</style>', $html);
    $html = str_replace('৳', '<span class="taka">৳</span>', $html);
    $grid = ticket_qr_table(qr_matrix(verify_url(ensure_verify_token($booking))));
    if ($grid !== '') {
        $html = preg_replace('/<svg class="qr\b.*?<\/svg>/s', $grid, $html) ?? $html;
    }
    $widthPt = 618 * 72 / 96;
    $options = new Dompdf\Options();
    $options->set('isRemoteEnabled', true);
    $options->set('isHtml5ParserEnabled', true);
    $options->set('defaultFont', 'DejaVu Sans');
    $options->setChroot(dirname(__DIR__));
    $measured = 0.0;
    $dompdf = new Dompdf\Dompdf($options);
    $dompdf->setCallbacks([[
        'event' => 'end_frame',
        'f' => static function ($frame) use (&$measured): void {
            $node = $frame->get_node();
            if ($node->nodeType !== XML_ELEMENT_NODE) {
                return;
            }
            $class = ' ' . $node->getAttribute('class') . ' ';
            if (!str_contains($class, ' ticket ')) {
                return;
            }
            $box = $frame->get_padding_box();
            $measured = max($measured, (float) $box['y'] + (float) $box['h']);
        },
    ]]);
    try {
        $dompdf->loadHtml($html);
        $dompdf->setPaper([0, 0, $widthPt, 800]);
        $dompdf->render();
        $heightPt = $measured > 100 ? $measured + 2 : 460;
        $dompdf = new Dompdf\Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper([0, 0, $widthPt, $heightPt]);
        $dompdf->render();
    } catch (Throwable $exception) {
        return null;
    }
    $pdf = $dompdf->output();
    return is_string($pdf) && str_starts_with($pdf, '%PDF') ? $pdf : null;
}

function ticket_pdf_browser(array $booking): ?string
{
    $edge = ticket_edge_bin();
    if ($edge === null) {
        return null;
    }
    $height = ticket_measure_height($booking, $edge);
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'jatra-pdf-' . bin2hex(random_bytes(6));
    if (!mkdir($dir) && !is_dir($dir)) {
        return null;
    }
    $pdfFile = $dir . DIRECTORY_SEPARATOR . 'ticket.pdf';
    // Exact website ticket height as the PDF page — lock box so Edge cannot spill a second page.
    ticket_run_edge($edge, ticket_print_html($booking, $height), $dir, $pdfFile);
    $pdf = null;
    if (is_file($pdfFile) && filesize($pdfFile) > 1000) {
        $loaded = file_get_contents($pdfFile);
        if (is_string($loaded) && str_starts_with($loaded, '%PDF')) {
            $pdf = $loaded;
            // If Edge still spilled, grow the page by a few px to the measured size + padding.
            $pages = ticket_pdf_page_count($pdf);
            $grow = $height;
            for ($i = 0; $i < 5 && $pages !== 1; $i++) {
                $grow += 12;
                @unlink($pdfFile);
                ticket_run_edge($edge, ticket_print_html($booking, $grow), $dir, $pdfFile);
                if (!is_file($pdfFile)) {
                    break;
                }
                $loaded = file_get_contents($pdfFile);
                if (!is_string($loaded) || !str_starts_with($loaded, '%PDF')) {
                    break;
                }
                $pdf = $loaded;
                $pages = ticket_pdf_page_count($pdf);
            }
        }
    }
    ticket_pdf_cleanup($dir);
    return $pdf;
}

function ticket_system_print(array $booking): bool
{
    require_once __DIR__ . '/qr.php';
    $token = ensure_verify_token($booking);
    $pdf = ticket_pdf($booking, qr_matrix(verify_url($token)));
    $safe = preg_replace('/[^A-Za-z0-9_-]/', '', $booking['booking_code']);
    $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'jatra-' . $safe . '.pdf';
    if (file_put_contents($file, $pdf) === false) {
        return false;
    }
    $acrobat = 'C:\\Program Files\\Adobe\\Acrobat DC\\Acrobat\\Acrobat.exe';
    if (is_file($acrobat)) {
        return ticket_launch([$acrobat, '/p', $file]);
    }
    $edge = ticket_edge_bin();
    if ($edge === null) {
        return false;
    }
    return ticket_launch([$edge, $file]);
}

function ticket_launch(array $command): bool
{
    $pipes = [];
    $proc = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($proc)) {
        return false;
    }
    foreach ($pipes as $pipe) {
        fclose($pipe);
    }
    return true;
}

function ticket_pdf_cleanup(string $path): void
{
    if (is_dir($path)) {
        foreach (scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            ticket_pdf_cleanup($path . DIRECTORY_SEPARATOR . $item);
        }
        @rmdir($path);
        return;
    }
    if (is_file($path)) {
        @unlink($path);
    }
}

function ticket_pdf_vector(array $booking, array $matrix): string
{
    $widthPx = 618;
    $heightPx = 460;
    $pageW = round($widthPx * 72 / 96, 2);
    $pageH = round($heightPx * 72 / 96, 2);
    $navy = [0.106, 0.141, 0.200];
    $gold = [0.886, 0.635, 0.102];
    $ink = [0.110, 0.098, 0.082];
    $muted = [0.420, 0.384, 0.345];
    $ops = "1 1 1 rg\n0 0 $pageW $pageH re f\n";
    $yTop = static function (float $fromTop) use ($pageH): float {
        return $pageH - $fromTop;
    };

    $ops .= pdf_text(24, $yTop(28), 11, strtoupper(mode_label($booking['mode'])) . ' TICKET', $gold, true);
    $ops .= pdf_text(24, $yTop(58), 22, strtoupper($booking['origin']), $ink, true);
    $ops .= pdf_text(250, $yTop(48), 10, 'TO', $muted, false);
    $ops .= pdf_text(250, $yTop(68), 18, strtoupper($booking['destination']), $ink, true);

    $seatParts = split_seat_label((string) $booking['seat_label']);
    $seatShown = $seatParts['coach'] !== ''
        ? $seatParts['coach'] . ' / ' . $seatParts['seat']
        : (string) $booking['seat_label'];
    $meta = [
        ['DATE', date_label($booking['travel_date']), 24],
        ['TIME', clock_label($booking['depart_time']), 130],
        ['SEAT', $seatShown, 240],
        ['CLASS', ticket_class_label($booking), 340],
    ];
    foreach ($meta as [$label, $value, $x]) {
        $ops .= pdf_text($x, $yTop(96), 8, $label, $muted, false);
        $ops .= pdf_text($x, $yTop(112), 12, $value, $ink, true);
    }
    $ops .= pdf_text(24, $yTop(136), 8, 'PURCHASED', $muted, false);
    $ops .= pdf_text(24, $yTop(150), 11, purchased_label($booking['created_at'] ?? ''), $ink, true);
    $ops .= pdf_text(24, $yTop(172), 11, 'Enjoy your journey', $navy, false);

    $stubH = 118;
    $stubY = 18;
    $nid = nid_label($booking['nid_no'] ?? '');
    $ops .= pdf_fill(0, $stubY, $pageW, $stubH, $navy);
    $ops .= pdf_text(20, $stubY + $stubH - 22, 8, 'PASSENGER', [0.62, 0.70, 0.80], false);
    $ops .= pdf_text(20, $stubY + $stubH - 38, 12, $booking['passenger_name'], [1, 1, 1], true);
    $ops .= pdf_text(20, $stubY + $stubH - 54, 10, 'NID  ' . ($nid !== '' ? $nid : '—'), [0.85, 0.89, 0.94], false);
    $ops .= pdf_text(20, $stubY + $stubH - 74, 11, $booking['booking_code'], [1, 1, 1], true);
    $ops .= pdf_text(20, $stubY + $stubH - 94, 16, 'Tk ' . number_format((float) $booking['amount'], 0), $gold, true);
    $ops .= pdf_qr($matrix, $pageW - 118, $stubY + 12, 90);
    $ops .= pdf_text($pageW - 108, $stubY + 6, 7, 'Scan to verify', [0.85, 0.89, 0.94], false);

    return pdf_wrap($ops, $pageW, $pageH);
}

function pdf_qr(array $matrix, float $x, float $y, float $size): string
{
    $n = count($matrix);
    $quiet = 4;
    $cell = $size / ($n + $quiet * 2);
    $ops = sprintf("1 1 1 rg\n%F %F %F %F re f\n0 0 0 rg\n", $x, $y, $size, $size);
    for ($row = 0; $row < $n; $row++) {
        for ($col = 0; $col < $n; $col++) {
            if (!$matrix[$row][$col]) {
                continue;
            }
            $px = $x + ($col + $quiet) * $cell;
            $py = $y + ($n - 1 - $row + $quiet) * $cell;
            $ops .= sprintf("%F %F %F %F re f\n", $px, $py, $cell, $cell);
        }
    }
    return $ops;
}

function pdf_fill(float $x, float $y, float $w, float $h, array $color): string
{
    return pdf_color($color) . sprintf("%F %F %F %F re f\n", $x, $y, $w, $h);
}

function pdf_color(array $color): string
{
    return sprintf("%F %F %F rg\n", $color[0], $color[1], $color[2]);
}

function pdf_text(float $x, float $y, float $size, string $text, array $color, bool $bold): string
{
    $font = $bold ? 'F2' : 'F1';
    $safe = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);
    if ($safe === false) {
        $safe = preg_replace('/[^\x20-\x7E]/', '?', $text) ?? $text;
    }
    $safe = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $safe);
    return pdf_color($color) . sprintf("BT /%s %F Tf 1 0 0 1 %F %F Tm (%s) Tj ET\n", $font, $size, $x, $y, $safe);
}

function pdf_wrap(string $ops, float $pageW = 595.0, float $pageH = 842.0): string
{
    $objects = [];
    $objects[] = "<< /Type /Catalog /Pages 2 0 R >>";
    $objects[] = "<< /Type /Pages /Kids [3 0 R] /Count 1 >>";
    $objects[] = sprintf(
        "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %F %F] /Contents 4 0 R /Resources << /Font << /F1 5 0 R /F2 6 0 R >> >> >>",
        $pageW,
        $pageH
    );
    $objects[] = "<< /Length " . strlen($ops) . " >>\nstream\n" . $ops . "endstream";
    $objects[] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";
    $objects[] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>";
    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objects as $index => $object) {
        $offsets[] = strlen($pdf);
        $pdf .= ($index + 1) . " 0 obj\n" . $object . "\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";
    for ($i = 1; $i <= count($objects); $i++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
    }
    $pdf .= "trailer << /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";
    return $pdf;
}
