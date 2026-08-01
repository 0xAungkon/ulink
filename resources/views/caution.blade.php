<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Check this link before continuing — ULink</title>
    <style>
        :root{color-scheme:light;--text:#172033;--muted:#64748b;--line:#dfe5ed;--primary:#166b59;--danger:#b93b50}*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;background:#f4f7fb;color:var(--text);font-family:Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif}.panel{width:min(650px,100%);background:#fff;border:1px solid var(--line);border-radius:20px;box-shadow:0 22px 65px rgba(31,45,68,.13);overflow:hidden}.top{padding:34px 38px}.brand{display:flex;align-items:center;gap:9px;font-weight:800;margin-bottom:28px}.mark{display:grid;place-items:center;width:32px;height:32px;background:var(--primary);color:#fff;border-radius:9px}.warning{display:flex;gap:13px;align-items:flex-start}.icon{flex:none;display:grid;place-items:center;width:42px;height:42px;border-radius:11px;background:#fff0f2;color:var(--danger);font-size:21px}.warning h1{font-size:24px;line-height:1.2;letter-spacing:-.025em;margin:2px 0 8px}.warning p,.explain{color:var(--muted);line-height:1.65;font-size:14px}.destination{margin:23px 0;padding:15px 17px;background:#f7f9fc;border:1px solid var(--line);border-radius:11px}.destination span{display:block;color:var(--muted);font-size:10px;text-transform:uppercase;letter-spacing:.09em;font-weight:750;margin-bottom:7px}.destination strong,.destination code{display:block;overflow-wrap:anywhere}.destination strong{font-size:15px}.destination code{color:#526177;font-size:12px;margin-top:6px}.buttons{display:grid;grid-template-columns:1fr 1fr;gap:11px;margin-top:25px}.button{height:48px;border:0;border-radius:9px;font-weight:750;font-size:14px;cursor:pointer;text-decoration:none;display:grid;place-items:center}.enter{background:var(--primary);color:#fff}.back{background:#fff;color:#405067;border:1px solid #cbd5e1}.privacy{text-align:center;color:#94a3b8;font-size:10px;margin:14px 0 0}.bottom{padding:23px 38px;background:#f8fafc;border-top:1px solid var(--line)}.bottom h2{font-size:14px;margin:0 0 9px}.bottom p{color:var(--muted);font-size:12px;line-height:1.6;margin:0}.mode{display:inline-block;margin-top:13px;padding:5px 9px;border-radius:20px;background:#e8f5f1;color:var(--primary);font-size:10px;font-weight:750;text-transform:uppercase;letter-spacing:.07em}@media(max-width:560px){body{padding:12px}.top{padding:27px 22px}.bottom{padding:20px 22px}.buttons{grid-template-columns:1fr}.warning h1{font-size:21px}}
    </style>
</head>
<body>
<main class="panel">
    <section class="top">
        <div class="brand"><span class="mark">U</span> ULink safety check</div>
        <div class="warning"><span class="icon">!</span><div><h1>Make sure you trust this website</h1><p>You are leaving ULink for content controlled by someone else. Only continue if you trust whoever sent you this link.</p></div></div>
        <div class="destination"><span>Destination website</span><strong>{{ $host }}</strong>@if($ip)<code>Resolved IP: {{ $ip }}</code>@endif</div>
        <p class="explain">Be careful when entering passwords, phone numbers, payment details, recovery codes, or other personal information. ULink does not verify or endorse this destination.</p>
        <div class="buttons"><a class="button enter" href="{{ $continueUrl }}">I understand — enter site</a><button class="button back" onclick="history.length > 1 ? history.back() : location.href='/'">Go back</button></div>
        <p class="privacy">Your acknowledgement is remembered for this link only. Other ULinks will show their own safety check.</p>
    </section>
    <section class="bottom"><h2>Why am I seeing this?</h2><p>This one-time notice helps reduce phishing risk for both redirect and proxy links. Automated clients can bypass the browser notice with the <code>X-ULink-No-Screen</code> header.</p><span class="mode">{{ ucfirst($link->delivery_mode) }} mode</span></section>
</main>
</body>
</html>
