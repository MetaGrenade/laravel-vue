<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted proxies
    |--------------------------------------------------------------------------
    |
    | Laravel's TrustProxies middleware reads this. A proxy that ends HTTPS (a load balancer, Cloudflare, Traefik, Caddy, a Docker
    | host's nginx) and passes plain HTTP on tells the site it did so in the X-Forwarded-* headers, and those headers are believed only
    | from the addresses listed here. Until they are, the site sees every visitor as http://: no Strict-Transport-Security header is
    | sent, and a signed link (the email verification link, a purchased download, a data export) made as https:// is refused when it is
    | opened, because the address it is checked against is the http:// one. See docs/deployment.md.
    |
    | TRUSTED_PROXIES is
    |
    |   empty        no proxy is trusted (the default), except where Laravel knows the host: Forge's *.on-forge.com, Vapor and Cloud;
    |   *            whatever address the request comes from. Right only when the site can be reached through the proxy and in no other
    |                way (a firewall, a private network), because anybody who can reach PHP directly could then claim to be anybody;
    |   REMOTE_ADDR  the same, written the way that says what it does: the address that connected;
    |   a list       the addresses or ranges of the proxies, separated by commas: 10.0.0.0/8,172.16.0.0/12
    |
    | A site whose web server itself ends HTTPS (nginx or Apache with a certificate, PHP-FPM behind it) does not need it.
    |
    */

    'proxies' => env('TRUSTED_PROXIES') ?: null,

];
