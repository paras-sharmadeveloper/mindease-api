{{--
  MindEase email layout. Table-based with inline styles, so it renders the same in Gmail, Outlook and Apple Mail.
  No images (often blocked by mail apps): the logo is text. Sections: title, preheader, content, and optional button.
--}}
<!doctype html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="x-apple-disable-message-reformatting">
<meta name="color-scheme" content="light only">
<meta name="supported-color-schemes" content="light only">
<title>@yield('title')</title>
<style>
  @media (max-width: 520px) {
    .card { padding: 28px 20px !important; }
    .outer { padding: 16px 10px !important; }
    h1 { font-size: 22px !important; }
  }
  a { color: #D64938; }
</style>
</head>
<body style="margin:0; padding:0; background:#F7F4F2; -webkit-text-size-adjust:100%;">
  {{-- Preview text shown next to the subject in the inbox. --}}
  <div style="display:none; max-height:0; overflow:hidden; opacity:0; color:transparent;">@yield('preheader')&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;</div>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#F7F4F2;">
    <tr>
      <td class="outer" align="center" style="padding:32px 16px;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:520px;">
          {{-- Header --}}
          <tr>
            <td align="center" style="background:#D64938; border-radius:24px 24px 0 0; padding:26px 24px;">
              <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                <tr>
                  <td width="36" height="36" align="center" valign="middle" style="background:#FFFFFF; border-radius:12px; color:#D64938; font:900 18px/36px Arial, Helvetica, sans-serif;">M</td>
                  <td style="padding-left:10px; color:#FFFFFF; font:800 19px/36px Arial, Helvetica, sans-serif; letter-spacing:-.2px;">MindEase</td>
                </tr>
              </table>
            </td>
          </tr>

          {{-- Card --}}
          <tr>
            <td class="card" style="background:#FFFFFF; border-radius:0 0 24px 24px; padding:36px 32px; color:#4F3422; font:500 16px/1.6 Arial, Helvetica, sans-serif;">
              <h1 style="margin:0 0 14px; font:800 24px/1.25 Arial, Helvetica, sans-serif; color:#4F3422; letter-spacing:-.3px;">@yield('heading')</h1>
              @yield('content')

              @hasSection('button_url')
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:26px 0 8px;">
                  <tr>
                    <td align="center" bgcolor="#D64938" style="border-radius:999px;">
                      <a href="@yield('button_url')" target="_blank" style="display:inline-block; padding:16px 34px; font:800 16px/1 Arial, Helvetica, sans-serif; color:#FFFFFF; text-decoration:none; border-radius:999px;">@yield('button_label')</a>
                    </td>
                  </tr>
                </table>
                <p style="margin:18px 0 0; font-size:13px; line-height:1.5; color:#736B66;">
                  Button not working? Copy this link into your browser:<br>
                  <a href="@yield('button_url')" style="color:#D64938; word-break:break-all;">@yield('button_url')</a>
                </p>
              @endif

              @hasSection('note')
                <p style="margin:24px 0 0; padding:14px 16px; background:#F7F4F2; border-radius:16px; font-size:14px; line-height:1.5; color:#736B66;">@yield('note')</p>
              @endif
            </td>
          </tr>

          {{-- Footer --}}
          <tr>
            <td align="center" style="padding:22px 16px 0; font:500 12.5px/1.6 Arial, Helvetica, sans-serif; color:#736B66;">
              You're receiving this because of activity on your MindEase account.<br>
              MindEase &middot; Australia
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
