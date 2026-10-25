@use('App\Support\Mail\MailBrand')
{{--
    The theme of every email (config/mail.php, `markdown.theme`). A view and not a plain .css file so that the colour of buttons and
    links can come from the `mail.brand` settings. It is inlined into the HTML by the mail renderer, which leaves out media queries:
    the ones for dark mode are in vendor/mail/html/layout.blade.php.
--}}
@php
    $brand = MailBrand::color();
    $onBrand = MailBrand::onColor();
    $link = MailBrand::linkColor();
@endphp
/* Base */

body,
body *:not(html):not(style):not(br):not(tr):not(code) {
    box-sizing: border-box;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif,
        'Apple Color Emoji', 'Segoe UI Emoji', 'Segoe UI Symbol';
    position: relative;
}

body {
    -webkit-text-size-adjust: none;
    background-color: #f4f4f5;
    color: #3f3f46;
    height: 100%;
    line-height: 1.4;
    margin: 0;
    padding: 0;
    width: 100% !important;
}

p,
ul,
ol,
blockquote {
    line-height: 1.4;
    text-align: start;
}

a {
    color: {{ $link }};
}

a img {
    border: none;
}

/* Typography */

h1 {
    color: #18181b;
    font-size: 20px;
    font-weight: bold;
    margin-top: 0;
    text-align: start;
}

h2 {
    color: #18181b;
    font-size: 17px;
    font-weight: bold;
    margin-top: 0;
    text-align: start;
}

h3 {
    color: #18181b;
    font-size: 15px;
    font-weight: bold;
    margin-top: 0;
    text-align: left;
}

p {
    font-size: 16px;
    line-height: 1.55em;
    margin-top: 0;
    text-align: left;
}

p.sub {
    font-size: 12px;
}

img {
    max-width: 100%;
}

/* Layout */

.wrapper {
    -premailer-cellpadding: 0;
    -premailer-cellspacing: 0;
    -premailer-width: 100%;
    background-color: #f4f4f5;
    margin: 0;
    padding: 0;
    width: 100%;
}

.content {
    -premailer-cellpadding: 0;
    -premailer-cellspacing: 0;
    -premailer-width: 100%;
    margin: 0;
    padding: 0;
    width: 100%;
}

/* Header: the logo, or a badge with the first letter of the name and the name beside it */

.header {
    padding: 28px 0 20px;
    text-align: center;
}

.header a {
    color: #18181b;
    font-size: 20px;
    font-weight: bold;
    text-decoration: none;
}

.mark {
    background-color: {{ $brand }};
    border-radius: 8px;
    color: {{ $onBrand }};
    font-size: 18px;
    font-weight: bold;
    height: 36px;
    line-height: 36px;
    text-align: center;
    width: 36px;
}

.header .mark a {
    color: {{ $onBrand }};
    text-decoration: none;
}

.wordmark {
    color: #18181b;
    font-size: 20px;
    font-weight: bold;
    padding-left: 10px;
    text-align: left;
}

/* Logo */

.logo {
    max-height: 48px;
    max-width: 220px;
}

/* Body */

.body {
    -premailer-cellpadding: 0;
    -premailer-cellspacing: 0;
    -premailer-width: 100%;
    background-color: #f4f4f5;
    border-bottom: 1px solid #f4f4f5;
    border-top: 1px solid #f4f4f5;
    margin: 0;
    padding: 0;
    width: 100%;
}

.inner-body {
    -premailer-cellpadding: 0;
    -premailer-cellspacing: 0;
    -premailer-width: 570px;
    background-color: #ffffff;
    border-color: #e4e4e7;
    border-radius: 8px;
    border-width: 1px;
    box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.08), 0 1px 2px -1px rgba(0, 0, 0, 0.08);
    margin: 0 auto;
    padding: 0;
    width: 570px;
}

.inner-body a {
    word-break: break-all;
}

/* Subcopy */

.subcopy {
    border-top: 1px solid #e4e4e7;
    margin-top: 25px;
    padding-top: 25px;
}

.subcopy p {
    font-size: 14px;
}

/* Footer */

.footer {
    -premailer-cellpadding: 0;
    -premailer-cellspacing: 0;
    -premailer-width: 570px;
    margin: 0 auto;
    padding: 0;
    text-align: center;
    width: 570px;
}

.footer p {
    color: #63636d;
    font-size: 12px;
    text-align: center;
}

.footer a {
    color: #63636d;
    text-decoration: underline;
}

/* Tables */

.table table {
    -premailer-cellpadding: 0;
    -premailer-cellspacing: 0;
    -premailer-width: 100%;
    margin: 30px auto;
    width: 100%;
}

.table th {
    border-bottom: 1px solid #e4e4e7;
    margin: 0;
    padding-bottom: 8px;
}

.table td {
    color: #3f3f46;
    font-size: 15px;
    line-height: 18px;
    margin: 0;
    padding: 10px 0;
}

.content-cell {
    max-width: 100vw;
    padding: 32px;
}

/* Buttons */

.action {
    -premailer-cellpadding: 0;
    -premailer-cellspacing: 0;
    -premailer-width: 100%;
    margin: 30px auto;
    padding: 0;
    text-align: center;
    width: 100%;
    float: unset;
}

.button {
    -webkit-text-size-adjust: none;
    border-radius: 6px;
    color: #fff;
    display: inline-block;
    font-weight: bold;
    overflow: hidden;
    text-decoration: none;
}

.button-blue,
.button-primary {
    background-color: {{ $brand }};
    border-bottom: 10px solid {{ $brand }};
    border-left: 22px solid {{ $brand }};
    border-right: 22px solid {{ $brand }};
    border-top: 10px solid {{ $brand }};
    color: {{ $onBrand }};
}

.button-green,
.button-success {
    background-color: #16a34a;
    border-bottom: 10px solid #16a34a;
    border-left: 22px solid #16a34a;
    border-right: 22px solid #16a34a;
    border-top: 10px solid #16a34a;
}

.button-red,
.button-error {
    background-color: #dc2626;
    border-bottom: 10px solid #dc2626;
    border-left: 22px solid #dc2626;
    border-right: 22px solid #dc2626;
    border-top: 10px solid #dc2626;
}

/* Panels */

.panel {
    border-left: {{ $brand }} solid 4px;
    margin: 21px 0;
}

.panel-content {
    background-color: #f4f4f5;
    color: #3f3f46;
    padding: 16px;
}

.panel-content p {
    color: #3f3f46;
}

.panel-item {
    padding: 0;
}

.panel-item p:last-of-type {
    margin-bottom: 0;
    padding-bottom: 0;
}

/* Utilities */

.break-all {
    word-break: break-all;
}
