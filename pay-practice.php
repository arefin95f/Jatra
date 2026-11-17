<?php
require __DIR__ . '/includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'complete') {
    csrf_check();
    header('Content-Type: application/json; charset=utf-8');
    $payer = current_user();
    $sim = $_SESSION['sim_pay'] ?? null;
    $tranId = is_array($sim) ? (string) ($sim['tran_id'] ?? '') : '';
    if (!$payer || !preg_match('/^JTRA[A-F0-9]{16}$/', $tranId)) {
        echo json_encode(['url' => 'bookings.php']);
        exit;
    }
    $channel = (string) ($_POST['channel'] ?? '');
    $paidMethod = in_array($channel, ['bkash', 'nagad', 'rocket'], true) ? 'mobile_money' : 'card';
    require_once __DIR__ . '/includes/notifications.php';
    global $db;
    $rows = [];
    $db->begin_transaction();
    try {
        $rows = qall(
            "SELECT id, schedule_id, seat_label, booking_code
             FROM bookings
             WHERE payment_ref = ? AND user_id = ? AND payment_status = 'pending' AND booking_status = 'pending'
             ORDER BY id FOR UPDATE",
            'si',
            [$tranId, (int) $payer['id']]
        );
        if ($rows) {
            $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            qexec(
                "UPDATE bookings
                 SET payment_status = 'paid', booking_status = 'confirmed', payment_method = ?
                 WHERE id IN ($placeholders) AND payment_status = 'pending' AND booking_status = 'pending'",
                's' . str_repeat('i', count($ids)),
                array_merge([$paidMethod], $ids)
            );
            foreach ($rows as $row) {
                qexec(
                    'DELETE FROM seat_holds WHERE schedule_id = ? AND seat_label = ?',
                    'is',
                    [(int) $row['schedule_id'], (string) $row['seat_label']]
                );
            }
        }
        $db->commit();
    } catch (Throwable $exception) {
        $db->rollback();
        echo json_encode(['url' => 'bookings.php']);
        exit;
    }
    unset($_SESSION['sim_pay']);
    if (!$rows) {
        echo json_encode(['url' => 'bookings.php']);
        exit;
    }
    $emailSent = false;
    try {
        $emailJob = enqueue_ticket_email((int) $rows[0]['id']);
        $emailSent = process_ticket_email_job($emailJob);
    } catch (Throwable $exception) {
        error_log('Ticket email could not be sent after payment: ' . $exception->getMessage());
    }
    flash(
        'ok',
        $emailSent
            ? 'Payment confirmed. Your ticket is booked. Your ticket email has been sent.'
            : 'Payment confirmed. Your ticket is booked. Your booking is confirmed, but the ticket email could not be sent now. It is saved for retry.'
    );
    echo json_encode(['url' => 'ticket.php?code=' . rawurlencode((string) $rows[0]['booking_code'])]);
    exit;
}

$payAmount = 150.0;
$payMode = 'mobile';
$payLive = false;
if (is_array($_SESSION['sim_pay'] ?? null) && isset($_SESSION['sim_pay']['amount'])) {
    $payAmount = round((float) $_SESSION['sim_pay']['amount'], 2);
    $payMode = (($_SESSION['sim_pay']['method'] ?? '') === 'card') ? 'card' : 'mobile';
    $payLive = true;
}
$payAmountText = number_format($payAmount, 2, '.', '');

clear_expired_holds();
$hold = qone(
    'SELECT schedule_id, TIMESTAMPDIFF(SECOND, NOW(), MIN(expires_at)) AS seconds
     FROM seat_holds
     WHERE hold_token = ? AND expires_at > NOW()
     GROUP BY schedule_id
     ORDER BY MIN(expires_at)
     LIMIT 1',
    's',
    [hold_token()]
);
$holdSeconds = $hold ? max(0, (int) $hold['seconds']) : 0;
$holdSchedule = $hold ? (int) $hold['schedule_id'] : 0;
$holdLabel = sprintf('%d:%02d', intdiv($holdSeconds, 60), $holdSeconds % 60);
$ticketUrl = 'bookings.php';
$practiceUser = current_user();
if ($practiceUser) {
    $latestTicket = qone(
        "SELECT booking_code FROM bookings
         WHERE user_id = ? AND booking_status IN ('confirmed', 'pending')
         ORDER BY id DESC
         LIMIT 1",
        'i',
        [(int) $practiceUser['id']]
    );
    if ($latestTicket) {
        $ticketUrl = 'ticket.php?code=' . rawurlencode((string) $latestTicket['booking_code']);
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $payLive ? 'Payment' : 'Practice payment' ?> · Jatra</title>
  <style>
    :root {
      --blue: #1f8fe0;
      --blue-2: #1877c9;
      --ink: #243044;
      --muted: #7b8798;
      --line: #eceff3;
      --sheet: #f7f8fa;
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      min-height: 100vh;
      min-height: 100dvh;
      background: #fff;
      font-family: "Segoe UI", "Nirmala UI", system-ui, sans-serif;
      color: var(--ink);
    }
    .phone {
      width: 100%;
      height: 100vh;
      height: 100dvh;
      background: #fff;
      display: flex;
      flex-direction: column;
    }
    .top {
      display: grid;
      grid-template-columns: 44px 1fr 44px;
      align-items: center;
      padding: 14px 8px 0;
      background: #fff;
    }
    .icon-btn {
      width: 36px;
      height: 36px;
      border: 0;
      background: transparent;
      color: #5c6778;
      cursor: pointer;
    }
    .brand {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 8px;
      padding-bottom: 8px;
    }
    .logo {
      width: 74px;
      height: 74px;
      border-radius: 18px;
      display: grid;
      place-items: center;
      background: #e9a83b;
      color: #14263d;
      font-size: 40px;
      font-weight: 800;
    }
    .brand b { font-size: 18px; font-weight: 650; }
    .tools {
      display: flex;
      justify-content: center;
      gap: 28px;
      padding: 6px 16px 14px;
      background: #fff;
      color: var(--blue);
      font-size: 13px;
    }
    .tools a {
      color: inherit;
      font: inherit;
      text-decoration: none;
      cursor: pointer;
      display: grid;
      justify-items: center;
      gap: 2px;
    }
    .tools svg { display: block; }
    .session-row {
      display: flex;
      justify-content: flex-end;
      background: #fff;
      padding: 0 12px;
    }
    .session {
      background: #4b5563;
      color: #fff;
      font-size: 12px;
      border-radius: 4px 4px 0 0;
      padding: 4px 8px;
    }
    .tabs {
      display: grid;
      grid-template-columns: 1fr 1fr;
      background: #fff;
    }
    .tabs button {
      border: 0;
      background: var(--blue);
      color: #fff;
      font: inherit;
      font-size: 13px;
      font-weight: 700;
      letter-spacing: 0.04em;
      padding: 12px 8px;
      cursor: pointer;
      opacity: 0.72;
    }
    .tabs button[aria-selected="true"] { opacity: 1; }
    .pane { flex: 1; background: #fff; }
    .pane[hidden] { display: none; }
    .wallets {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 14px 12px;
      padding: 22px 16px 16px;
    }
    .wallet {
      position: relative;
      height: 78px;
      border: 0;
      background: #fff;
      box-shadow: 0 0 0 1px #e6e9ee;
      cursor: pointer;
      display: grid;
      place-items: center;
      padding: 10px 8px;
    }
    .wallet img { max-width: 100%; max-height: 52px; object-fit: contain; }
    .wallet[aria-pressed="true"] { background: #eceff3; }
    .check {
      display: none;
      position: absolute;
      top: 6px;
      right: 6px;
      width: 16px;
      height: 16px;
      border-radius: 50%;
      background: #2fa84f;
      color: #fff;
      font-size: 11px;
      line-height: 16px;
    }
    .wallet[aria-pressed="true"] .check { display: block; }
    .card-head {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 18px 18px 8px;
    }
    .schemes { display: flex; align-items: center; gap: 8px; font-weight: 800; }
    .visa { color: #1a1f71; letter-spacing: 0.08em; font-style: italic; }
    .mc { width: 34px; height: 22px; position: relative; }
    .mc i, .mc b {
      position: absolute;
      top: 0;
      width: 22px;
      height: 22px;
      border-radius: 50%;
    }
    .mc i { left: 0; background: #eb001b; }
    .mc b { right: 0; background: #f79e1b; mix-blend-mode: multiply; }
    .other { border: 0; background: transparent; color: var(--blue); font: inherit; font-size: 14px; cursor: pointer; padding: 0; }
    #pane-card:not([hidden]) { display: flex; flex-direction: column; }
    .phone.other-open .spacer { display: none; }
    .phone.other-open #pane-card { background: #f6f3ee; }
    .card-other { flex: 1; background: #f6f3ee; padding: 14px 18px 18px; }
    .card-other[hidden], #card-main[hidden] { display: none; }
    .other-back {
      border: 0;
      background: transparent;
      color: #243044;
      padding: 4px 2px 18px;
      cursor: pointer;
    }
    .other-row { display: flex; gap: 36px; align-items: flex-start; padding-left: 18px; }
    .other-opt {
      border: 0;
      background: transparent;
      cursor: pointer;
      display: grid;
      justify-items: center;
      gap: 8px;
      color: #111;
      font: inherit;
      font-size: 14px;
      font-weight: 700;
      padding: 4px;
    }
    .other-opt .mc { width: 74px; height: 46px; }
    .other-opt .mc i, .other-opt .mc b { width: 46px; height: 46px; }
    .other-opt img, .nexus-mark { height: 46px; width: auto; }
    .schemes .nexus-mark { height: 28px; }
    .brand-name { color: #111; font-size: 14px; font-weight: 700; font-style: normal; letter-spacing: 0; }
    .other-opt[aria-pressed="true"] { outline: 2px solid var(--blue); outline-offset: 6px; }
    form { padding: 8px 18px 18px; }
    .field, .split input {
      width: 100%;
      border: 1px solid #d5dbe3;
      background: #fff;
      border-radius: 2px;
      padding: 14px 12px;
      font: inherit;
      color: var(--ink);
    }
    .number { display: flex; align-items: center; gap: 8px; }
    .number input { flex: 1; }
    .split { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-top: 12px; }
    .cvc-wrap { position: relative; }
    .cvc-wrap svg { position: absolute; right: 10px; top: 12px; }
    form .field { margin-top: 12px; }
    .remember {
      display: flex;
      gap: 10px;
      align-items: flex-start;
      margin-top: 16px;
      padding: 14px;
      background: #f4f6f8;
      color: #5d6b7c;
      font-size: 14px;
    }
    .remember input { margin-top: 3px; }
    .remember a { color: #e07a12; }
    .spacer { flex: 1; background: #fff; }
    .pay {
      border: 0;
      width: 100%;
      padding: 16px;
      background: #c5ced6;
      color: #fff;
      font: inherit;
      font-weight: 800;
      letter-spacing: 0.04em;
      cursor: pointer;
    }
    .pay:not(:disabled) { background: var(--blue-2); }
    .pay:disabled { cursor: not-allowed; }
    .result {
      margin: 0;
      padding: 10px 16px;
      background: #e8f6ef;
      color: #12805c;
      font-size: 14px;
      font-weight: 650;
    }
    .result[hidden] { display: none; }
    .wallet-step { display: none; flex: 1; flex-direction: column; background: #e7e9ed; min-height: 0; }
    .wallet-step.on { display: flex; }
    .wallet-bar {
      display: grid;
      grid-template-columns: 44px 1fr 44px;
      align-items: center;
      background: #fff;
      border-bottom: 3px solid #0d7a62;
      min-height: 52px;
    }
    .wallet-bar b { text-align: center; font-size: 18px; font-weight: 650; }
    .bkash-sheet {
      margin: 12px 12px 0;
      flex: 1;
      min-height: 0;
      display: flex;
      flex-direction: column;
      background: #fff;
      border-radius: 4px 4px 0 0;
      overflow: hidden;
    }
    .bkash-head { padding: 18px 14px 12px; }
    .bkash-logo {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 2px;
    }
    .bkash-logo span {
      color: #e2136e;
      font-family: "Nirmala UI", "Segoe UI", sans-serif;
      font-size: 34px;
      font-weight: 650;
      line-height: 1;
    }
    .bkash-bird {
      width: 46px;
      height: 36px;
      margin-left: 2px;
      background: url("assets/pay/bkash.png") right center / 120px auto no-repeat;
    }
    .bkash-merchant {
      display: grid;
      grid-template-columns: 36px 1fr auto;
      gap: 8px;
      align-items: center;
      margin-top: 16px;
      text-align: left;
    }
    .bkash-avatar {
      width: 34px;
      height: 34px;
      border-radius: 50%;
      display: grid;
      place-items: center;
      background: #e9a83b;
      color: #14263d;
      font-weight: 800;
    }
    .bkash-merchant b { display: block; font-size: 14px; font-weight: 650; }
    .bkash-merchant small { display: block; color: #8b95a5; font-size: 12px; margin-top: 2px; }
    .bkash-merchant strong { font-size: 18px; font-weight: 750; }
    .wallet-body {
      background: #e2136e;
      color: #fff;
      padding: 28px 18px 22px;
      text-align: center;
      flex: 1;
      display: flex;
      flex-direction: column;
      justify-content: center;
    }
    .wallet-body[hidden] { display: none; }
    .wallet-body p {
      margin: 0 auto 16px;
      max-width: 100%;
      font-size: 16px;
      line-height: 1.45;
      font-weight: 550;
    }
    .wallet-body input {
      width: 100%;
      border: 0;
      border-radius: 4px;
      padding: 14px 12px;
      text-align: center;
      font: inherit;
      font-size: 18px;
      color: #243044;
    }
    .wallet-body input::placeholder { color: #b7bec7; }
    .wallet-body input:focus { outline: none; }
    .wallet-note { margin: 14px 0 0; max-width: none; font-size: 14px; white-space: nowrap; }
    .wallet-note a { color: #fff; }
    .resend {
      border: 0;
      background: transparent;
      color: #fff;
      font: inherit;
      font-size: 15px;
      cursor: pointer;
    }
    .wallet-actions {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 14px;
      padding: 16px 16px 6px;
    }
    .wallet-actions button {
      border: 0;
      border-radius: 4px;
      padding: 14px 8px;
      font: inherit;
      font-size: 16px;
      font-weight: 650;
      cursor: pointer;
    }
    .wallet-actions .cancel { background: #f4f5f7; color: #222; }
    .wallet-actions .confirm { background: #e2136e; color: #fff; }
    .wallet-actions .confirm:disabled { background: #e4e6ea; color: #c5c8ce; cursor: not-allowed; }
    .wallet-step.done .wallet-actions,
    .wallet-step.done .bkash-merchant { display: none; }
    .bkash-check { margin: 0 auto 10px; }
    .done-title { margin: 0 0 8px; max-width: none; font-size: 18px; font-weight: 500; }
    .done-note { font-size: 15px; }
    .done-note a { color: #fff; }
    .bkash-foot { text-align: center; padding: 10px 12px 16px; color: #8b95a5; font-size: 12px; }
    .bkash-foot .hotline {
      display: flex;
      justify-content: center;
      align-items: center;
      gap: 6px;
      color: #e2136e;
      font-weight: 750;
      font-size: 14px;
      margin-bottom: 4px;
    }
    .phone.paying > :not(.wallet-step),
    .phone.paying #pane-card { display: none; }
    .card-step { background: #fff; }
    .card-step .wallet-bar { border-bottom: 0; }
    .card-success {
      flex: 1;
      min-height: 0;
      background: var(--blue-2);
      color: #fff;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      text-align: center;
      padding: 24px 18px 48px;
    }
    .card-success .done-title { margin: 8px 0; font-size: 18px; font-weight: 500; }
    .card-success .done-note { margin: 0; font-size: 15px; }
    .card-success .done-note a { color: #fff; text-decoration: underline; }
    .rocket-done { display: none; }
    .rocket-step.done .rocket-screen { display: none; }
    .rocket-step.done .rocket-done { display: flex; background: #8b3392; }
    .rocket-step.done .wallet-bar { border-bottom: 0; }
    .nagad-screen {
      --nagad: #c2191e;
      flex: 1;
      min-height: 0;
      overflow: auto;
      background: var(--nagad);
      color: #fff;
      display: flex;
      flex-direction: column;
      padding: 10px 18px 12px;
      text-align: center;
    }
    .nagad-lang {
      align-self: flex-end;
      display: flex;
      border: 1.5px solid #fff;
      border-radius: 7px;
      overflow: hidden;
      font-size: 11px;
      line-height: 1.5;
      font-weight: 650;
    }
    .nagad-lang button {
      border: 0;
      background: transparent;
      color: #ffffff;
      font: inherit;
      font-size: 12px;
      font-weight: 700;
      line-height: 1.5;
      padding: 1px 8px;
      cursor: pointer;
    }
    .nagad-lang button.on { background: #ffffff; color: var(--nagad); }
    .nagad-cart { width: 136px; height: auto; margin: 8px auto 0; display: block; }
    .nagad-screen h2 { margin: 2px 0 14px; font-size: 28px; font-weight: 650; letter-spacing: 0.2px; }
    .nagad-meta { margin: 0 4px 8px; text-align: left; font-size: 15px; font-weight: 700; line-height: 1.7; color: #ffffff; }
    .nagad-pane { margin-top: 26px; }
    .nagad-pane p { margin: 0 0 10px; font-size: 16px; font-weight: 700; color: #ffffff; }
    .nagad-pane[hidden] { display: none; }
    .nagad-boxes { display: flex; justify-content: center; align-items: center; gap: 3px; }
    .nagad-boxes input {
      width: 26px;
      height: 30px;
      border: 0;
      border-radius: 2px;
      padding: 0;
      text-align: center;
      font: inherit;
      font-size: 16px;
      color: #243044;
      background: #fff;
    }
    .nagad-boxes input:focus { outline: 2px solid rgba(255,255,255,0.85); outline-offset: 1px; }
    .nagad-boxes .dash { width: 8px; text-align: center; font-weight: 700; line-height: 1; }
    .nagad-pin { gap: 12px; }
    .nagad-pin input { width: 42px; height: 46px; border-radius: 3px; }
    .nagad-terms { margin: 12px 0 0; font-weight: 700; line-height: 1.45; color: #ffffff; }
    .nagad-lead { display: block; white-space: nowrap; font-size: 13px; color: #ffffff; }
    .nagad-terms a {
      color: #ffffff;
      text-decoration: none;
      border-bottom: 1px solid #ffffff;
      padding-bottom: 1px;
      line-height: 1.1;
      font-size: 13px;
    }
    .nagad-otp {
      width: 100%;
      border: 0;
      border-radius: 8px;
      padding: 13px 12px;
      text-align: center;
      font: inherit;
      font-size: 18px;
      letter-spacing: 3px;
      color: #243044;
      background: #fff;
    }
    .nagad-otp::placeholder { color: #b7b7b7; letter-spacing: 6px; }
    .nagad-otp:focus { outline: none; }
    .nagad-actions { display: flex; justify-content: center; gap: 22px; margin: 22px 0 4px; }
    .nagad-screen.is-code .nagad-actions { gap: 8px; }
    .nagad-actions button {
      background: #fff;
      color: var(--nagad);
      border: 0;
      border-radius: 6px;
      min-width: 104px;
      padding: 8px 16px;
      font: inherit;
      font-size: 15px;
      font-weight: 700;
      cursor: pointer;
    }
    .nagad-screen.is-code .nagad-actions button { min-width: 0; padding: 8px 12px; }
    .nagad-actions button[hidden] { display: none; }
    .nagad-step.done .nagad-info,
    .nagad-step.done .nagad-actions { display: none; }
    .nagad-brand { margin-top: auto; padding: 18px 0 4px; }
    .nagad-brand img { height: 72px; width: auto; }
    .rocket-step { background: #fff; }
    .rocket-step .wallet-bar b { font-size: 16px; }
    .rocket-screen {
      flex: 1;
      min-height: 0;
      overflow: auto;
      background: #fff;
      color: #111;
      font-family: Arial, "Segoe UI", sans-serif;
      padding: 8px 10px 16px;
    }
    .dbbl-top { display: flex; align-items: center; justify-content: space-between; padding: 8px 4px 4px; }
    .dbbl-logo { height: 42px; width: auto; }
    .dbbl-side { width: 52px; height: 36px; border-radius: 2px; background: linear-gradient(160deg, #d7e4f5, #8aa4c4); }
    .dbbl-rule {
      height: 7px;
      margin: 2px 0;
      background: linear-gradient(#0b4d85, #1795ff 42%, #0e7ddf 68%, #5d89a9);
    }
    .nexus {
      margin: 3px 0 2px;
      text-align: center;
      color: #1616c2;
      font-weight: 800;
      font-size: 17px;
      letter-spacing: 0.06em;
      font-family: Arial, Helvetica, sans-serif;
    }
    .dbbl-h { margin: 10px 0 12px; text-align: center; font-size: 15px; font-weight: 700; }
    .dbbl-form { display: grid; grid-template-columns: 108px 1fr; gap: 7px 8px; align-items: center; max-width: 340px; margin: 0 auto; font-size: 13px; }
    .dbbl-form label { text-align: right; }
    .dbbl-form input { border: 1px solid #222; height: 24px; padding: 0 4px; font: inherit; font-size: 13px; background: #fff; }
    .dbbl-form input:focus { outline: 1px solid #1a33e6; }
    #rocket-account { width: 100%; }
    .pin-row { display: flex; align-items: center; gap: 6px; }
    #rocket-pin { width: 72px; }
    .pin-keys { border: 0; background: transparent; padding: 0; cursor: pointer; color: #222; }
    .dbbl-actions { display: flex; justify-content: center; align-items: center; gap: 3px; margin: 16px 0 10px; }
    .dbbl-stripes { width: 10px; height: 22px; background: repeating-linear-gradient(90deg, #2e7d32 0 2px, #f4d03f 2px 4px, #c62828 4px 6px, #1565c0 6px 10px); }
    .dbbl-actions button {
      min-width: 72px;
      height: 24px;
      border: 1px solid #8d8d8d;
      border-radius: 2px;
      background: linear-gradient(#f3f3f3, #bdbdbd);
      font: inherit;
      font-size: 11px;
      font-weight: 700;
      letter-spacing: 0.06em;
      cursor: pointer;
    }
    .dbbl-seals { display: flex; justify-content: center; align-items: flex-end; gap: 18px; margin: 8px 0; }
    .visa-seal { color: #1a33e6; font-weight: 800; font-style: italic; line-height: 0.95; text-align: center; }
    .visa-seal b { display: block; font-size: 18px; }
    .visa-seal span { font-size: 11px; }
    .mc-seal { text-align: left; line-height: 1; }
    .mc-seal b { display: block; color: #e10600; font-size: 15px; font-style: italic; }
    .mc-seal span { font-size: 12px; font-weight: 700; font-style: italic; }
    .dbbl-foot { text-align: center; font-weight: 800; font-size: 15px; line-height: 1.25; margin: 8px 0 0; color: #111; }
    .dbbl-foot small { display: block; margin-top: 2px; font-size: 14px; font-weight: 700; }
    @media (min-width: 520px) {
      body {
        display: grid;
        place-items: center;
        background: #e6e9ee;
      }
      .phone {
        width: 430px;
        height: min(780px, 100dvh);
        box-shadow: 0 16px 50px rgba(20, 30, 45, 0.16);
      }
    }
    @media (max-width: 519px) {
      .pay { padding-bottom: calc(16px + env(safe-area-inset-bottom)); }
    }
  </style>
</head>
<body>
  <section class="phone" aria-label="Practice payment">
    <div class="top">
      <button class="icon-btn" type="button" aria-label="Back">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 5 8 12l7 7"/></svg>
      </button>
      <div class="brand">
        <div class="logo" aria-hidden="true">J</div>
        <b>Jatra</b>
      </div>
      <button class="icon-btn" type="button" aria-label="Language">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18M8 5v14"/></svg>
      </button>
    </div>
    <div class="tools">
      <a href="https://sslcommerz.com/contact/" target="_blank" rel="noopener"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M4 13a8 8 0 0 1 16 0"/><path d="M12 13v3"/><path d="M8 19h8"/></svg>Support</a>
      <a href="https://sslcommerz.com/faq/" target="_blank" rel="noopener"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><circle cx="12" cy="12" r="8"/><path d="M9.5 9a2.5 2.5 0 1 1 3.5 2.3c-.8.4-1 .9-1 1.7"/><path d="M12 17h.01"/></svg>FAQ</a>
      <a href="https://campaign.sslcommerz.com/" target="_blank" rel="noopener"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M4 10h16v9H4z"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>Offers</a>
      <a href="https://my.sslcommerz.com/" target="_blank" rel="noopener"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><circle cx="12" cy="8" r="3"/><path d="M5 19c1.5-3 3.8-4.5 7-4.5S17.5 16 19 19"/></svg>Login</a>
    </div>
    <div class="session-row"><span class="session">Session Time: <span id="clock"><?= $holdLabel ?></span></span></div>
    <div class="tabs" role="tablist">
      <button type="button" id="tab-card" role="tab" aria-selected="false" aria-controls="pane-card">CARDS</button>
      <button type="button" id="tab-mobile" role="tab" aria-selected="true" aria-controls="pane-mobile">MOBILE BANKING</button>
    </div>

    <div class="pane" id="pane-mobile" role="tabpanel">
      <div class="wallets">
        <button class="wallet" type="button" data-wallet="bKash" aria-label="bKash" aria-pressed="false"><span class="check">✓</span><img src="assets/pay/bkash.png" alt=""></button>
        <button class="wallet" type="button" data-wallet="Nagad" aria-label="Nagad" aria-pressed="false"><span class="check">✓</span><img src="assets/pay/nagad.png" alt=""></button>
        <button class="wallet" type="button" data-wallet="Rocket" aria-label="Rocket" aria-pressed="false"><span class="check">✓</span><img src="assets/pay/rocket.png" alt=""></button>
      </div>
    </div>

    <div class="pane" id="pane-card" role="tabpanel" hidden>
      <div id="card-main">
      <div class="card-head">
        <div class="schemes" id="card-schemes" aria-hidden="true"><span class="visa">VISA</span><span class="mc"><i></i><b></b></span></div>
        <button class="other" id="other-cards" type="button">Other Cards</button>
      </div>
      <form id="card-form">
        <div class="number">
          <input class="field" name="number" inputmode="numeric" autocomplete="off" maxlength="19" placeholder="Enter Card Number" required>
          <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#66788a" stroke-width="1.6" aria-hidden="true"><path d="M4 8h4l1-2h6l1 2h4v11H4z"/><circle cx="12" cy="13" r="3"/></svg>
        </div>
        <div class="split">
          <input name="expiry" inputmode="numeric" autocomplete="off" maxlength="5" placeholder="MM/YY" required>
          <div class="cvc-wrap">
            <input name="cvc" inputmode="numeric" autocomplete="off" maxlength="4" placeholder="CVC/CVV" required>
            <svg width="22" height="16" viewBox="0 0 24 16" fill="none" stroke="#66788a" stroke-width="1.4" aria-hidden="true"><rect x="1" y="1" width="22" height="14" rx="2"/><path d="M1 5h22"/></svg>
          </div>
        </div>
        <input class="field" name="name" autocomplete="off" maxlength="80" placeholder="Card Holder Name" required>
        <label class="remember">
          <input type="checkbox" name="remember">
          <span>Remember Me<br>By clicking the remember me, you agree to our <a href="https://sslcommerz.com/terms-and-conditions/" target="_blank" rel="noopener">Terms &amp; Conditions</a></span>
        </label>
      </form>
      </div>
      <div class="card-other" id="card-other" hidden>
        <button class="other-back" id="other-back" type="button" aria-label="Back">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 5 8 12l7 7"/></svg>
        </button>
        <div class="other-row">
          <button class="other-opt" type="button" data-other="mastercard" aria-pressed="false">
            <span class="mc" aria-hidden="true"><i></i><b></b></span>
            <span>mastercard.</span>
          </button>
          <button class="other-opt" type="button" data-other="nexus" aria-pressed="false">
            <img src="assets/pay/dbbl-mark.png" alt="">
            <span>DBBL NEXUS</span>
          </button>
        </div>
      </div>
    </div>
    <div class="spacer"></div>
    <p class="result" id="result" hidden></p>
    <button class="pay" id="pay" type="button" disabled>PAY <?= e($payAmountText) ?> BDT</button>
    <section class="wallet-step card-step" id="card-step" aria-label="Card">
      <div class="wallet-bar">
        <button class="icon-btn" id="card-back" type="button" aria-label="Back">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 5 8 12l7 7"/></svg>
        </button>
        <b>Card</b>
        <span></span>
      </div>
      <div class="card-success">
        <div class="bkash-check" aria-hidden="true">
          <svg width="68" height="68" viewBox="0 0 64 64" fill="none" stroke="#fff" stroke-width="2">
            <circle cx="32" cy="32" r="20"/>
            <path d="M23 33.2 29.2 39.4 42 25.5" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </div>
        <p class="done-title">Verification Successful</p>
        <p class="done-note">Redirecting to <a id="card-merchant-link" href="<?= e($ticketUrl) ?>">Merchant Website</a> in <span id="card-redirect">4</span>s</p>
      </div>
    </section>
    <section class="wallet-step" id="bkash-step" aria-label="bKash">
      <div class="wallet-bar">
        <button class="icon-btn" id="bkash-back" type="button" aria-label="Back">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 5 8 12l7 7"/></svg>
        </button>
        <b>bKash</b>
        <span></span>
      </div>
      <div class="bkash-sheet">
        <div class="bkash-head">
          <div class="bkash-logo" aria-hidden="true"><span>বিকাশ</span><i class="bkash-bird"></i></div>
          <div class="bkash-merchant">
            <div class="bkash-avatar">J</div>
            <div>
              <b>Jatra</b>
              <small>Inv No: JTRA96966261113...</small>
            </div>
            <strong>৳<?= e($payAmountText) ?></strong>
          </div>
        </div>
        <div class="wallet-body" id="bkash-number-pane">
          <p>Your bKash Account Number</p>
          <input id="bkash-number" inputmode="numeric" maxlength="11" autocomplete="off" placeholder="01XXXXXXXXX">
          <p class="wallet-note">Confirm and proceed, <a href="https://www.bkash.com/en/page/terms-of-use-checkout" target="_blank" rel="noopener">terms &amp; conditions</a></p>
        </div>
        <div class="wallet-body" id="bkash-code-pane" hidden>
          <p>Enter Verification Code Sent To<br><span id="bkash-mask"></span></p>
          <input id="bkash-code" inputmode="numeric" maxlength="6" autocomplete="off" placeholder="Enter 6 digit code">
          <p class="wallet-note"><button class="resend" id="bkash-resend" type="button">Resend Code in 27s</button></p>
        </div>
        <div class="wallet-body" id="bkash-pin-pane" hidden>
          <p>Enter PIN Of Your bKash Account Number<br><span id="bkash-pin-mask"></span></p>
          <input id="bkash-pin" type="password" inputmode="numeric" maxlength="5" autocomplete="off" placeholder="Enter PIN">
        </div>
        <div class="wallet-body" id="bkash-done-pane" hidden>
          <div class="bkash-check" aria-hidden="true">
            <svg width="68" height="68" viewBox="0 0 64 64" fill="none" stroke="#fff" stroke-width="2">
              <circle cx="32" cy="32" r="20"/>
              <path d="M23 33.2 29.2 39.4 42 25.5" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
          </div>
          <p class="done-title">Verification Successful</p>
          <p class="wallet-note done-note">Redirecting to <a id="bkash-merchant-link" href="<?= e($ticketUrl) ?>">Merchant Website</a> in <span id="bkash-redirect">4</span>s</p>
        </div>
      </div>
      <div class="wallet-actions">
        <button class="cancel" id="bkash-cancel" type="button">Cancel</button>
        <button class="confirm" id="bkash-confirm" type="button" disabled>Confirm</button>
      </div>
      <div class="bkash-foot">
        <div class="hotline">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M6.6 10.8a15 15 0 0 0 6.6 6.6l2.2-2.2a1 1 0 0 1 1-.25 11 11 0 0 0 3.6.6 1 1 0 0 1 1 1V20a1 1 0 0 1-1 1A17 17 0 0 1 3 4a1 1 0 0 1 1-1h3.5a1 1 0 0 1 1 1 11 11 0 0 0 .6 3.6 1 1 0 0 1-.25 1z"/></svg>
          16247
        </div>
        <div>© 2026 bKash, All Rights Reserved</div>
      </div>
    </section>
    <section class="wallet-step nagad-step" id="nagad-step" aria-label="Nagad">
      <div class="wallet-bar">
        <button class="icon-btn" id="nagad-back" type="button" aria-label="Back">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 5 8 12l7 7"/></svg>
        </button>
        <b>Nagad</b>
        <span></span>
      </div>
      <div class="nagad-screen">
        <div class="nagad-lang" role="group" aria-label="Language">
          <button type="button" id="nagad-bn">বাং</button>
          <button type="button" id="nagad-en" class="on">Eng</button>
        </div>
        <svg class="nagad-cart" viewBox="0 0 180 112" aria-hidden="true">
          <g fill="none" stroke="#ffffff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <path d="M70 46V28h18v18"/>
            <path d="M74 28c2-8 14-8 16 0"/>
            <path d="M92 48V22h26v26"/>
            <path d="M97 22c3.4-9 18-9 21 0"/>
            <path d="M8 42h16M3 51h18M9 60h13"/>
            <path d="M42 26h18M60 26v16"/>
            <path d="M48 42h92M42 55h104M36 68h116"/>
            <path d="M56 42 46 68M132 42l10 26"/>
            <circle cx="68" cy="90" r="8"/>
            <circle cx="116" cy="90" r="8"/>
          </g>
          <g fill="#ffffff">
            <rect x="156" y="44" width="5" height="5"/>
            <rect x="166" y="52" width="5" height="5"/>
            <rect x="158" y="62" width="4" height="4"/>
            <rect x="172" y="40" width="3.5" height="3.5"/>
          </g>
        </svg>
        <h2>Jatra</h2>
        <div class="nagad-info">
          <div class="nagad-meta">
            <div id="nagad-invoice">Invoice No: NG76146261113Y37295</div>
            <div id="nagad-amount">Total Amount: BDT <?= e($payAmountText) ?></div>
            <div id="nagad-charge">Charge: BDT 0</div>
          </div>
          <div class="nagad-pane" id="nagad-number-pane">
            <p id="nagad-account-label">Your Nagad Account Number</p>
            <div class="nagad-boxes" id="nagad-account">
              <input inputmode="numeric" maxlength="1" aria-label="Account digit 1"><input inputmode="numeric" maxlength="1" aria-label="Account digit 2"><input inputmode="numeric" maxlength="1" aria-label="Account digit 3">
              <span class="dash">-</span>
              <input inputmode="numeric" maxlength="1" aria-label="Account digit 4"><input inputmode="numeric" maxlength="1" aria-label="Account digit 5"><input inputmode="numeric" maxlength="1" aria-label="Account digit 6"><input inputmode="numeric" maxlength="1" aria-label="Account digit 7">
              <span class="dash">-</span>
              <input inputmode="numeric" maxlength="1" aria-label="Account digit 8"><input inputmode="numeric" maxlength="1" aria-label="Account digit 9"><input inputmode="numeric" maxlength="1" aria-label="Account digit 10"><input inputmode="numeric" maxlength="1" aria-label="Account digit 11">
            </div>
            <p class="nagad-terms"><span class="nagad-lead" id="nagad-lead">By clicking/tapping "Proceed" you are agreeing to our</span><a id="nagad-terms-link" href="https://nagad.com.bd/en/terms-of-use/" target="_blank" rel="noopener">Terms and Conditions</a></p>
          </div>
          <div class="nagad-pane" id="nagad-code-pane" hidden>
            <p id="nagad-otp-label">Enter Verification Code [OTP]</p>
            <input class="nagad-otp" id="nagad-code" inputmode="numeric" maxlength="6" autocomplete="off" placeholder="XXXXXX">
          </div>
          <div class="nagad-pane" id="nagad-pin-pane" hidden>
            <p id="nagad-pin-label">Enter PIN</p>
            <div class="nagad-boxes nagad-pin" id="nagad-pin">
              <input inputmode="numeric" maxlength="1" aria-label="PIN digit 1"><input inputmode="numeric" maxlength="1" aria-label="PIN digit 2"><input inputmode="numeric" maxlength="1" aria-label="PIN digit 3"><input inputmode="numeric" maxlength="1" aria-label="PIN digit 4">
            </div>
          </div>
        </div>
        <div class="nagad-pane" id="nagad-done-pane" hidden>
          <div class="bkash-check" aria-hidden="true">
            <svg width="68" height="68" viewBox="0 0 64 64" fill="none" stroke="#fff" stroke-width="2">
              <circle cx="32" cy="32" r="20"/>
              <path d="M23 33.2 29.2 39.4 42 25.5" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
          </div>
          <p class="done-title" id="nagad-done-title">Verification Successful</p>
          <p class="done-note" id="nagad-done-note">Redirecting to <a id="nagad-site-link" href="<?= e($ticketUrl) ?>">Merchant Website</a> in <span id="nagad-redirect">4</span><span id="nagad-sec">s</span></p>
        </div>
        <div class="nagad-actions">
          <button id="nagad-proceed" type="button">Proceed</button>
          <button id="nagad-resend" type="button" hidden>Resend Code</button>
          <button id="nagad-close" type="button">Close</button>
        </div>
        <div class="nagad-brand"><img src="assets/pay/nagad-white.png" alt="নগদ"></div>
      </div>
    </section>
    <section class="wallet-step rocket-step" id="rocket-step" aria-label="Rocket">
      <div class="wallet-bar">
        <button class="icon-btn" id="rocket-head-back" type="button" aria-label="Back">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 5 8 12l7 7"/></svg>
        </button>
        <b>DBBL Mobile Banking</b>
        <span></span>
      </div>
      <div class="rocket-screen">
        <div class="dbbl-top">
          <img class="dbbl-logo" src="assets/pay/dbbl.png" alt="Dutch-Bangla Bank">
          <svg class="dbbl-side" viewBox="0 0 52 36" aria-hidden="true">
            <rect width="52" height="36" fill="#c5d4e8"/>
            <rect x="8" y="8" width="22" height="16" rx="1" fill="#4a6fa5"/>
            <rect x="28" y="14" width="14" height="10" fill="#2e4d73"/>
            <circle cx="18" cy="16" r="3" fill="#d7e6f7"/>
          </svg>
        </div>
        <div class="dbbl-rule"></div>
        <div class="nexus">DBBL NEXUS GATEWAY</div>
        <div class="dbbl-rule"></div>
        <div class="dbbl-h">Mobile Account Information</div>
        <div class="dbbl-form">
          <label for="rocket-account">Mobile Account</label>
          <input id="rocket-account" inputmode="numeric" maxlength="11" autocomplete="off">
          <label for="rocket-pin">PIN</label>
          <div class="pin-row">
            <input id="rocket-pin" type="password" inputmode="numeric" maxlength="4" autocomplete="off">
            <button class="pin-keys" id="rocket-keys" type="button" aria-label="Keyboard">
              <svg width="28" height="18" viewBox="0 0 28 18" fill="none" stroke="currentColor" stroke-width="1.2"><rect x="1" y="1" width="26" height="16" rx="1"/><path d="M5 6h2M9 6h2M13 6h2M17 6h2M21 6h2M5 10h2M9 10h2M13 10h2M17 10h2M7 14h14"/></svg>
            </button>
          </div>
          <label>Amount</label>
          <div><?= e($payAmountText) ?></div>
          <label>Currency</label>
          <div>BDT</div>
          <label>Description</label>
          <div>62026111306552860150</div>
        </div>
        <div class="dbbl-actions">
          <i class="dbbl-stripes"></i>
          <button id="rocket-back" type="button">BACK</button>
          <i class="dbbl-stripes"></i>
          <button id="rocket-submit" type="button">SUBMIT</button>
          <i class="dbbl-stripes"></i>
          <button id="rocket-reset" type="button">RESET</button>
          <i class="dbbl-stripes"></i>
        </div>
        <div class="dbbl-seals" aria-hidden="true">
          <div class="visa-seal"><b>VERIFIED</b><span>by VISA</span></div>
          <div class="mc-seal"><b>MasterCard</b><span>SecureCode</span></div>
        </div>
        <div class="dbbl-rule"></div>
        <div class="dbbl-foot">DBBL E-COMM With Jatra<small>ecom1.dutchbanglabank.com</small></div>
      </div>
      <div class="card-success rocket-done" id="rocket-done">
        <div class="bkash-check" aria-hidden="true">
          <svg width="68" height="68" viewBox="0 0 64 64" fill="none" stroke="#fff" stroke-width="2">
            <circle cx="32" cy="32" r="20"/>
            <path d="M23 33.2 29.2 39.4 42 25.5" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </div>
        <p class="done-title">Verification Successful</p>
        <p class="done-note">Redirecting to <a id="rocket-merchant-link" href="<?= e($ticketUrl) ?>">Merchant Website</a> in <span id="rocket-redirect">4</span>s</p>
      </div>
    </section>
  </section>
  <script>
    var tabCard = document.getElementById("tab-card");
    var tabMobile = document.getElementById("tab-mobile");
    var paneCard = document.getElementById("pane-card");
    var paneMobile = document.getElementById("pane-mobile");
    var pay = document.getElementById("pay");
    var result = document.getElementById("result");
    var cardForm = document.getElementById("card-form");
    var mode = "mobile";
    var wallet = "";
    var cardView = "form";
    var cardOther = "";
    var scheduleId = <?= (int) $holdSchedule ?>;
    var deadline = Date.now() + <?= (int) $holdSeconds ?> * 1000;

    function renderClock() {
      var remain = Math.max(0, Math.round((deadline - Date.now()) / 1000));
      var minutes = Math.floor(remain / 60);
      var seconds = remain % 60;
      document.getElementById("clock").textContent = minutes + ":" + (seconds < 10 ? "0" : "") + seconds;
    }
    function syncHold(seconds) {
      deadline = Date.now() + Math.max(0, parseInt(seconds, 10) || 0) * 1000;
      renderClock();
    }

    function paint() {
      if (mode === "card" && cardView === "other") {
        pay.disabled = true;
        return;
      }
      var cardReady = cardForm.checkValidity();
      pay.disabled = mode === "card" ? !cardReady : wallet === "";
    }
    function applyCardBrand(brand) {
      var schemes = document.getElementById("card-schemes");
      if (brand === "mastercard") schemes.innerHTML = '<span class="mc"><i></i><b></b></span><span class="brand-name">mastercard.</span>';
      else if (brand === "nexus") schemes.innerHTML = '<img class="nexus-mark" src="assets/pay/dbbl-mark.png" alt=""><span class="brand-name">DBBL NEXUS</span>';
      else schemes.innerHTML = '<span class="visa">VISA</span><span class="mc"><i></i><b></b></span>';
    }
    function showOther(open) {
      cardView = open ? "other" : "form";
      document.getElementById("card-main").hidden = open;
      document.getElementById("card-other").hidden = !open;
      phone.classList.toggle("other-open", open);
      if (open) {
        cardOther = "";
        document.querySelectorAll(".other-opt").forEach(function (button) {
          button.setAttribute("aria-pressed", "false");
        });
      }
      result.hidden = true;
      paint();
    }
    function show(next) {
      mode = next;
      var card = next === "card";
      tabCard.setAttribute("aria-selected", card ? "true" : "false");
      tabMobile.setAttribute("aria-selected", card ? "false" : "true");
      paneCard.hidden = !card;
      paneMobile.hidden = card;
      result.hidden = true;
      paint();
    }
    tabCard.addEventListener("click", function () { show("card"); });
    tabMobile.addEventListener("click", function () { show("mobile"); });
    cardForm.addEventListener("input", function () {
      result.hidden = true;
      paint();
    });
    var phone = document.querySelector(".phone");
    var bkashStep = document.getElementById("bkash-step");
    var bkashNumberPane = document.getElementById("bkash-number-pane");
    var bkashCodePane = document.getElementById("bkash-code-pane");
    var bkashPinPane = document.getElementById("bkash-pin-pane");
    var bkashDonePane = document.getElementById("bkash-done-pane");
    var ticketUrl = <?= json_encode($ticketUrl, JSON_UNESCAPED_SLASHES) ?>;
    var payLive = <?= $payLive ? 'true' : 'false' ?>;
    var payCsrf = <?= json_encode(csrf_token(), JSON_UNESCAPED_SLASHES) ?>;
    function finishPay(channel) {
      if (!payLive) {
        window.location.href = ticketUrl;
        return;
      }
      var body = new URLSearchParams();
      body.set("csrf", payCsrf);
      body.set("action", "complete");
      body.set("channel", channel);
      fetch("pay-practice.php", { method: "POST", body: body, credentials: "same-origin" })
        .then(function (response) { return response.json(); })
        .then(function (data) { window.location.href = (data && data.url) || "bookings.php"; })
        .catch(function () { window.location.href = "bookings.php"; });
    }
    var bkashNumber = document.getElementById("bkash-number");
    var bkashCode = document.getElementById("bkash-code");
    var bkashPin = document.getElementById("bkash-pin");
    var bkashConfirm = document.getElementById("bkash-confirm");
    var bkashResend = document.getElementById("bkash-resend");
    var bkashPhase = "number";
    var bkashLeft = 27;
    var bkashTimer = null;
    var redirectLeft = 4;
    var redirectTimer = null;

    function digitsOnly(input) {
      input.value = input.value.replace(/\D/g, "").slice(0, input.maxLength);
    }
    function maskAccount(value) {
      return value.slice(0, 3) + " ** *** " + value.slice(-3);
    }
    function paintResend() {
      bkashResend.textContent = bkashLeft > 0 ? "Resend Code in " + bkashLeft + "s" : "Resend Code";
    }
    function startResend() {
      clearInterval(bkashTimer);
      bkashLeft = 27;
      paintResend();
      bkashTimer = setInterval(function () {
        if (bkashLeft > 0) bkashLeft -= 1;
        paintResend();
        if (bkashLeft <= 0) clearInterval(bkashTimer);
      }, 1000);
    }
    function bkashReady() {
      if (bkashPhase === "number") bkashConfirm.disabled = !/^01\d{9}$/.test(bkashNumber.value);
      else if (bkashPhase === "code") bkashConfirm.disabled = !/^\d{6}$/.test(bkashCode.value);
      else bkashConfirm.disabled = !/^\d{5}$/.test(bkashPin.value);
    }
    function showPhase(phase) {
      bkashPhase = phase;
      bkashNumberPane.hidden = phase !== "number";
      bkashCodePane.hidden = phase !== "code";
      bkashPinPane.hidden = phase !== "pin";
      bkashDonePane.hidden = phase !== "done";
      bkashStep.classList.toggle("done", phase === "done");
      if (phase !== "done") bkashReady();
    }
    function startRedirect() {
      clearInterval(bkashTimer);
      clearInterval(redirectTimer);
      redirectLeft = 4;
      document.getElementById("bkash-redirect").textContent = "4";
      showPhase("done");
      redirectTimer = setInterval(function () {
        redirectLeft -= 1;
        if (redirectLeft <= 0) {
          clearInterval(redirectTimer);
          finishPay("bkash");
          return;
        }
        document.getElementById("bkash-redirect").textContent = String(redirectLeft);
      }, 1000);
    }
    function openBkash() {
      bkashNumber.value = "";
      bkashCode.value = "";
      bkashPin.value = "";
      showPhase("number");
      phone.classList.add("paying");
      bkashStep.classList.add("on");
      bkashNumber.focus();
    }
    function closeBkash() {
      clearInterval(bkashTimer);
      clearInterval(redirectTimer);
      phone.classList.remove("paying");
      bkashStep.classList.remove("on");
      bkashStep.classList.remove("done");
    }
    document.getElementById("bkash-back").addEventListener("click", function () {
      if (bkashPhase === "done") {
        closeBkash();
        return;
      }
      if (bkashPhase === "pin") {
        showPhase("code");
        bkashCode.focus();
        return;
      }
      if (bkashPhase === "code") {
        showPhase("number");
        bkashNumber.focus();
        return;
      }
      closeBkash();
    });
    document.getElementById("bkash-cancel").addEventListener("click", closeBkash);
    bkashNumber.addEventListener("input", function () { digitsOnly(bkashNumber); bkashReady(); });
    bkashCode.addEventListener("input", function () { digitsOnly(bkashCode); bkashReady(); });
    bkashPin.addEventListener("input", function () { digitsOnly(bkashPin); bkashReady(); });
    bkashResend.addEventListener("click", function () {
      if (bkashLeft <= 0) startResend();
    });
    bkashConfirm.addEventListener("click", function () {
      if (bkashPhase === "number") {
        var masked = maskAccount(bkashNumber.value);
        document.getElementById("bkash-mask").textContent = masked;
        document.getElementById("bkash-pin-mask").textContent = "(" + masked + ")";
        bkashCode.value = "";
        showPhase("code");
        startResend();
        bkashCode.focus();
        return;
      }
      if (bkashPhase === "code") {
        bkashPin.value = "";
        showPhase("pin");
        bkashPin.focus();
        return;
      }
      startRedirect();
    });

    var nagadStep = document.getElementById("nagad-step");
    var nagadAccount = document.getElementById("nagad-account");
    var nagadPinBoxes = document.getElementById("nagad-pin");
    var nagadCode = document.getElementById("nagad-code");
    var nagadPhase = "number";
    var nagadTimer = null;

    function boxDigits(container) {
      return Array.prototype.map.call(container.querySelectorAll("input"), function (input) {
        return input.value;
      }).join("");
    }
    function clearBoxes(container) {
      container.querySelectorAll("input").forEach(function (input) { input.value = ""; });
    }
    function wireBoxes(container) {
      var inputs = container.querySelectorAll("input");
      inputs.forEach(function (input, index) {
        input.addEventListener("input", function () {
          input.value = input.value.replace(/\D/g, "").slice(-1);
          if (input.value && inputs[index + 1]) inputs[index + 1].focus();
        });
        input.addEventListener("keydown", function (event) {
          if (event.key === "Backspace" && !input.value && inputs[index - 1]) inputs[index - 1].focus();
        });
      });
    }
    function showNagad(phase) {
      nagadPhase = phase;
      document.getElementById("nagad-number-pane").hidden = phase !== "number";
      document.getElementById("nagad-code-pane").hidden = phase !== "code";
      document.getElementById("nagad-pin-pane").hidden = phase !== "pin";
      document.getElementById("nagad-done-pane").hidden = phase !== "done";
      document.getElementById("nagad-resend").hidden = phase !== "code";
      nagadStep.classList.toggle("done", phase === "done");
      nagadStep.querySelector(".nagad-screen").classList.toggle("is-code", phase === "code");
    }
    function fitNagadLead() {
      var lead = document.querySelector(".nagad-lead");
      if (!lead) return;
      var parent = lead.parentElement.clientWidth;
      if (!parent) return;
      var size = 15;
      lead.style.fontSize = size + "px";
      while (size > 10 && lead.scrollWidth > parent) {
        size -= 0.5;
        lead.style.fontSize = size + "px";
      }
      var line = lead.nextElementSibling;
      if (line) line.style.fontSize = lead.style.fontSize;
    }
    var nagadLocale = "en";
    var nagadCopy = {
      en: {
        invoice: "Invoice No: NG76146261113Y37295",
        amount: "Total Amount: BDT <?= e($payAmountText) ?>",
        charge: "Charge: BDT 0",
        account: "Your Nagad Account Number",
        lead: "By clicking/tapping \"Proceed\" you are agreeing to our",
        terms: "Terms and Conditions",
        termsUrl: "https://nagad.com.bd/en/terms-of-use/",
        otp: "Enter Verification Code [OTP]",
        pin: "Enter PIN",
        proceed: "Proceed",
        resend: "Resend Code",
        close: "Close",
        done: "Verification Successful",
        site: "Merchant Website",
        before: "Redirecting to ",
        after: " in ",
        sec: "s"
      },
      bn: {
        invoice: "ইনভয়েস নম্বর: NG76146261113Y37295",
        amount: "মোট পরিমাণ: BDT <?= e($payAmountText) ?>",
        charge: "চার্জ: BDT 0",
        account: "আপনার নগদ অ্যাকাউন্ট নম্বর",
        lead: "\"প্রসিড\" বাটনে ক্লিক/ট্যাপ করে আপনি আমাদের",
        terms: "শর্তাবলীতে সম্মত হচ্ছেন",
        termsUrl: "https://nagad.com.bd/bn/pg/?n=terms-of-use",
        otp: "ভেরিফিকেশন কোড [OTP] দিন",
        pin: "পিন দিন",
        proceed: "প্রসিড",
        resend: "পুনরায় পাঠান",
        close: "বন্ধ",
        done: "ভেরিফিকেশন সফল",
        site: "মার্চেন্ট ওয়েবসাইট",
        before: "",
        after: "-এ যাচ্ছে ",
        sec: "সে"
      }
    };
    function applyNagadLang(code) {
      nagadLocale = code;
      var copy = nagadCopy[code];
      document.getElementById("nagad-bn").classList.toggle("on", code === "bn");
      document.getElementById("nagad-en").classList.toggle("on", code === "en");
      document.getElementById("nagad-invoice").textContent = copy.invoice;
      document.getElementById("nagad-amount").textContent = copy.amount;
      document.getElementById("nagad-charge").textContent = copy.charge;
      document.getElementById("nagad-account-label").textContent = copy.account;
      document.getElementById("nagad-lead").textContent = copy.lead;
      document.getElementById("nagad-terms-link").textContent = copy.terms;
      document.getElementById("nagad-terms-link").href = copy.termsUrl;
      document.getElementById("nagad-otp-label").textContent = copy.otp;
      document.getElementById("nagad-pin-label").textContent = copy.pin;
      document.getElementById("nagad-proceed").textContent = copy.proceed;
      document.getElementById("nagad-resend").textContent = copy.resend;
      document.getElementById("nagad-close").textContent = copy.close;
      document.getElementById("nagad-done-title").textContent = copy.done;
      document.getElementById("nagad-site-link").textContent = copy.site;
      document.getElementById("nagad-done-note").firstChild.textContent = copy.before;
      document.getElementById("nagad-sec").textContent = copy.sec;
      var after = document.getElementById("nagad-site-link").nextSibling;
      if (after) after.textContent = copy.after;
      fitNagadLead();
    }
    function openNagad() {
      clearInterval(nagadTimer);
      clearBoxes(nagadAccount);
      clearBoxes(nagadPinBoxes);
      nagadCode.value = "";
      showNagad("number");
      phone.classList.add("paying");
      nagadStep.classList.add("on");
      fitNagadLead();
      nagadAccount.querySelector("input").focus();
    }
    function closeNagad() {
      clearInterval(nagadTimer);
      phone.classList.remove("paying");
      nagadStep.classList.remove("on");
      nagadStep.classList.remove("done");
    }
    function nagadRedirect() {
      var left = 4;
      document.getElementById("nagad-redirect").textContent = "4";
      showNagad("done");
      nagadTimer = setInterval(function () {
        left -= 1;
        if (left <= 0) {
          clearInterval(nagadTimer);
          finishPay("nagad");
          return;
        }
        document.getElementById("nagad-redirect").textContent = String(left);
      }, 1000);
    }
    wireBoxes(nagadAccount);
    wireBoxes(nagadPinBoxes);
    document.getElementById("nagad-back").addEventListener("click", function () {
      if (nagadPhase === "pin") { showNagad("code"); nagadCode.focus(); return; }
      if (nagadPhase === "code") { showNagad("number"); nagadAccount.querySelector("input").focus(); return; }
      closeNagad();
    });
    document.getElementById("nagad-close").addEventListener("click", closeNagad);
    document.getElementById("nagad-bn").addEventListener("click", function () { applyNagadLang("bn"); });
    document.getElementById("nagad-en").addEventListener("click", function () { applyNagadLang("en"); });
    document.getElementById("nagad-resend").addEventListener("click", function () {
      nagadCode.value = "";
      nagadCode.focus();
    });
    nagadCode.addEventListener("input", function () { digitsOnly(nagadCode); });
    document.getElementById("nagad-proceed").addEventListener("click", function () {
      if (nagadPhase === "number") {
        if (!/^01\d{9}$/.test(boxDigits(nagadAccount))) return;
        nagadCode.value = "";
        showNagad("code");
        nagadCode.focus();
        return;
      }
      if (nagadPhase === "code") {
        if (!/^\d{6}$/.test(nagadCode.value)) return;
        clearBoxes(nagadPinBoxes);
        showNagad("pin");
        nagadPinBoxes.querySelector("input").focus();
        return;
      }
      if (nagadPhase === "pin") {
        if (!/^\d{4}$/.test(boxDigits(nagadPinBoxes))) return;
        nagadRedirect();
      }
    });

    var rocketAccount = document.getElementById("rocket-account");
    var rocketPin = document.getElementById("rocket-pin");
    var rocketStep = document.getElementById("rocket-step");
    var rocketLeft = 4;
    var rocketTimer = null;
    function showRocketForm() {
      clearInterval(rocketTimer);
      rocketStep.classList.remove("done");
    }
    function startRocketRedirect() {
      clearInterval(rocketTimer);
      rocketLeft = 4;
      document.getElementById("rocket-redirect").textContent = "4";
      rocketStep.classList.add("done");
      rocketTimer = setInterval(function () {
        rocketLeft -= 1;
        if (rocketLeft <= 0) {
          clearInterval(rocketTimer);
          finishPay("rocket");
          return;
        }
        document.getElementById("rocket-redirect").textContent = String(rocketLeft);
      }, 1000);
    }
    function openRocket() {
      rocketAccount.value = "";
      rocketPin.value = "";
      showRocketForm();
      phone.classList.add("paying");
      rocketStep.classList.add("on");
      rocketAccount.focus();
    }
    function closeRocket() {
      showRocketForm();
      phone.classList.remove("paying");
      rocketStep.classList.remove("on");
    }
    document.getElementById("rocket-head-back").addEventListener("click", function () {
      if (rocketStep.classList.contains("done")) {
        showRocketForm();
        rocketAccount.focus();
        return;
      }
      closeRocket();
    });
    document.getElementById("rocket-back").addEventListener("click", closeRocket);
    document.getElementById("rocket-keys").addEventListener("click", function () { rocketPin.focus(); });
    document.getElementById("rocket-reset").addEventListener("click", function () {
      rocketAccount.value = "";
      rocketPin.value = "";
      rocketAccount.focus();
    });
    rocketAccount.addEventListener("input", function () { digitsOnly(rocketAccount); });
    rocketPin.addEventListener("input", function () { digitsOnly(rocketPin); });
    document.getElementById("rocket-merchant-link").addEventListener("click", function (event) {
      event.preventDefault();
      clearInterval(rocketTimer);
      finishPay("rocket");
    });
    document.getElementById("rocket-submit").addEventListener("click", function () {
      if (!/^01\d{9}$/.test(rocketAccount.value) || !/^\d{4}$/.test(rocketPin.value)) return;
      startRocketRedirect();
    });

    document.querySelectorAll(".wallet").forEach(function (button) {
      button.addEventListener("click", function () {
        wallet = button.getAttribute("data-wallet");
        document.querySelectorAll(".wallet").forEach(function (other) {
          other.setAttribute("aria-pressed", other === button ? "true" : "false");
        });
        result.hidden = true;
        paint();
      });
    });
    var cardStep = document.getElementById("card-step");
    var cardRedirectLeft = 4;
    var cardRedirectTimer = null;
    function closeCard() {
      clearInterval(cardRedirectTimer);
      phone.classList.remove("paying");
      cardStep.classList.remove("on");
    }
    function startCardRedirect() {
      clearInterval(cardRedirectTimer);
      cardRedirectLeft = 4;
      document.getElementById("card-redirect").textContent = "4";
      phone.classList.add("paying");
      cardStep.classList.add("on");
      cardRedirectTimer = setInterval(function () {
        cardRedirectLeft -= 1;
        if (cardRedirectLeft <= 0) {
          clearInterval(cardRedirectTimer);
          finishPay("card");
          return;
        }
        document.getElementById("card-redirect").textContent = String(cardRedirectLeft);
      }, 1000);
    }
    document.getElementById("card-back").addEventListener("click", closeCard);
    document.getElementById("card-merchant-link").addEventListener("click", function (event) {
      event.preventDefault();
      clearInterval(cardRedirectTimer);
      finishPay("card");
    });
    document.getElementById("bkash-merchant-link").addEventListener("click", function (event) {
      event.preventDefault();
      clearInterval(redirectTimer);
      finishPay("bkash");
    });
    document.getElementById("nagad-site-link").addEventListener("click", function (event) {
      event.preventDefault();
      clearInterval(nagadTimer);
      finishPay("nagad");
    });
    document.getElementById("other-cards").addEventListener("click", function () { showOther(true); });
    document.getElementById("other-back").addEventListener("click", function () { showOther(false); });
    document.querySelectorAll(".other-opt").forEach(function (button) {
      button.addEventListener("click", function () {
        cardOther = button.getAttribute("data-other");
        applyCardBrand(cardOther);
        cardForm.reset();
        showOther(false);
        cardForm.number.focus();
      });
    });
    pay.addEventListener("click", function () {
      if (mode === "card") {
        if (cardView === "other" || !cardForm.checkValidity()) return;
        result.hidden = true;
        startCardRedirect();
        return;
      }
      if (wallet === "bKash") openBkash();
      else if (wallet === "Nagad") openNagad();
      else if (wallet === "Rocket") openRocket();
    });
    setInterval(renderClock, 250);
    if (scheduleId) {
      setInterval(function () {
        fetch("hold.php?schedule=" + encodeURIComponent(scheduleId), { credentials: "same-origin" })
          .then(function (response) { return response.json(); })
          .then(function (data) { syncHold(data.seconds || 0); })
          .catch(function () {});
      }, 5000);
    }
    show(<?= json_encode($payMode) ?>);
  </script>
</body>
</html>
