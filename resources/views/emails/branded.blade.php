{{-- One template for every email: verification, password reset, account created.
     Table layout and inline styles only - most mail clients ignore <style>. --}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light">
<title>{{ $subject }}</title>
</head>
<body style="margin:0; padding:0; background:#F3F7F2; font-family:'Segoe UI', Helvetica, Arial, sans-serif; color:#0F1F17;">
<span style="display:none; max-height:0; overflow:hidden; opacity:0;">{{ $lines[0] ?? $heading }}</span>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F3F7F2; padding:32px 12px;">
<tr><td align="center">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px; background:#FFFFFF; border:1px solid #E1ECE4; border-radius:16px; overflow:hidden;">

<tr><td style="background:#0F3D2E; padding:22px 32px;">
<table role="presentation" cellpadding="0" cellspacing="0"><tr>
<td style="width:40px; height:40px; background:#22C55E; border-radius:10px; text-align:center; vertical-align:middle; font-size:20px; font-weight:800; color:#0F3D2E;">D</td>
<td style="padding-left:12px;">
<div style="font-size:16px; font-weight:700; color:#FFFFFF;">Dietary Dashboard</div>
<div style="font-size:12px; color:#A7D3B9;">DOST-FNRI</div>
</td>
</tr></table>
</td></tr>

<tr><td style="padding:32px 32px 8px;">
<h1 style="margin:0 0 18px; font-size:22px; line-height:1.3; color:#0F1F17;">{{ $heading }}</h1>
<p style="margin:0 0 14px; font-size:15px; line-height:1.6;">{{ $greeting }}</p>
@foreach ($lines as $line)
<p style="margin:0 0 14px; font-size:15px; line-height:1.6; color:#2D4237;">{{ $line }}</p>
@endforeach
</td></tr>

<tr><td align="center" style="padding:12px 32px 24px;">
<a href="{{ $actionUrl }}" target="_blank" rel="noopener" style="display:inline-block; background:#15803D; color:#FFFFFF; text-decoration:none; font-size:15px; font-weight:700; padding:14px 28px; border-radius:10px;">{{ $actionText }}</a>
</td></tr>

<tr><td style="padding:0 32px 24px;">
@foreach ($outro as $line)
<p style="margin:0 0 10px; font-size:13.5px; line-height:1.6; color:#4B5E54;">{{ $line }}</p>
@endforeach
</td></tr>

<tr><td style="padding:18px 32px; background:#F6FAF7; border-top:1px solid #E1ECE4;">
<p style="margin:0 0 6px; font-size:12.5px; line-height:1.5; color:#4B5E54;">If the button doesn't work, copy this link into your browser:</p>
<p style="margin:0; font-size:12px; line-height:1.5; word-break:break-all;"><a href="{{ $actionUrl }}" style="color:#15803D;">{{ $actionUrl }}</a></p>
</td></tr>

</table>
<p style="margin:16px 0 0; font-size:12px; color:#4B5E54;">Food and Nutrition Research Institute &middot; Department of Science and Technology</p>
</td></tr>
</table>
</body>
</html>
