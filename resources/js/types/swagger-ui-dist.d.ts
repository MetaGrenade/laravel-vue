/** `swagger-ui-dist` ships no types. Only what resources/js/api-docs.ts uses is described. */
declare module 'swagger-ui-dist/swagger-ui-es-bundle.js' {
    /** The request as Swagger UI hands it to `fetch`. */
    interface SwaggerRequest {
        credentials?: RequestCredentials;
        headers: Record<string, string>;
    }

    interface SwaggerOptions {
        url: string;
        domNode: HTMLElement;
        validatorUrl: string | null;
        requestInterceptor: (request: SwaggerRequest) => SwaggerRequest;
    }

    export default function SwaggerUIBundle(options: SwaggerOptions): unknown;
}
