import SwaggerUIBundle from 'swagger-ui-dist/swagger-ui-es-bundle.js';
import 'swagger-ui-dist/swagger-ui.css';

// The API reference at /api/docs (resources/views/api/docs.blade.php): Swagger UI, built with the site and served from its own
// address, so the content security policy names no other host for it (docs/security-headers.md). A Vite entry of its own: no other
// page loads it.
const root = document.getElementById('swagger-ui');

if (root) {
    SwaggerUIBundle({
        url: root.dataset.specUrl ?? '',
        domNode: root,
        // Left alone, Swagger UI asks validator.swagger.io for a badge and tells it the address of this site.
        validatorUrl: null,
        requestInterceptor: (request) => {
            // "Try it out" is answered for the key typed under Authorize and nothing else. With the browser's cookies sent along, the
            // session of whoever is signed in would answer instead, with everything that person may do and no scopes, a DELETE too.
            request.credentials = 'omit';
            // The API asks for this on every request (docs/api.md). Swagger UI leaves it at "anything", and then a call with no key,
            // or a wrong one, is answered with a redirect to the sign-in page, which is shown as that page with a 200.
            delete request.headers.accept;
            request.headers.Accept = 'application/json';

            return request;
        },
    });
}
