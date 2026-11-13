/**
 * Upload a file to S3.
 *
 * @param {File|Blob} file
 * @param {UploadOptions} [options]
 * @returns {Promise<SignedUpload>}
 */
export function upload(file: File | Blob, options?: UploadOptions): Promise<SignedUpload>;
/**
 * @typedef {Object} SignedUpload
 * @property {string} uuid The unique ID of the upload.
 * @property {string} key The S3 key of the uploaded file, to send to the backend.
 * @property {string|null} bucket The S3 bucket, or null when the disk has no `bucket` configured.
 * @property {string} url The presigned URL the file was uploaded to.
 * @property {Object<string, string>} headers The headers used for the upload.
 * @property {string|null} extension The file extension derived from the content type, or null if unknown.
 */
/**
 * @typedef {Object} UploadOptions
 * @property {string} [url] URL of the backend route returning signed upload URLs. Defaults to `/signed-upload-url`.
 * @property {string} [contentType] MIME type of the file. Defaults to `file.type`, or `application/octet-stream`.
 * @property {(ratio: number) => void} [progress] Called with the upload progress, from 0 to 1.
 * @property {Object<string, string>} [headers] Extra headers to send to the backend route (e.g. `Authorization`).
 * @property {string} [csrfToken] CSRF token, sent as `X-CSRF-TOKEN`. By default, for same-origin requests, the
 *     `XSRF-TOKEN` cookie set by Laravel is sent as `X-XSRF-TOKEN` (like axios and Inertia do), or else the content
 *     of the `<meta name="csrf-token">` tag is sent as `X-CSRF-TOKEN`.
 * @property {AbortSignal} [signal] Signal to cancel the upload.
 * @property {HttpClient} [httpClient] An axios-compatible HTTP client to use instead of `fetch` and `XMLHttpRequest`.
 *     The client is then responsible for CSRF and authentication.
 */
/**
 * An axios-compatible HTTP client (`axios` itself, or an axios instance).
 *
 * @typedef {Object} HttpClient
 * @property {(url: string, data: any, config: Object<string, any>) => Promise<{ data: any }>} post
 * @property {(url: string, data: any, config: Object<string, any>) => Promise<any>} put
 */
/**
 * Error thrown when the upload fails. `status` is the HTTP status code of the failed request (0 on network errors).
 */
export class UploadError extends Error {
    /**
     * @param {string} message
     * @param {number} status
     * @param {unknown} [cause] The underlying error, if any.
     */
    constructor(message: string, status: number, cause?: unknown);
    /** @type {number} */
    status: number;
    /** @type {unknown} */
    cause: unknown;
}
/**
 * Upload a file to S3.
 *
 * @param {File|Blob} file
 * @param {UploadOptions} [options]
 * @returns {Promise<SignedUpload>}
 */
export function store(file: File | Blob, options?: UploadOptions): Promise<SignedUpload>;
declare namespace _default {
    export { upload };
    export { store };
    export { UploadError };
}
export default _default;
export type SignedUpload = {
    /**
     * The unique ID of the upload.
     */
    uuid: string;
    /**
     * The S3 key of the uploaded file, to send to the backend.
     */
    key: string;
    /**
     * The S3 bucket, or null when the disk has no `bucket` configured.
     */
    bucket: string | null;
    /**
     * The presigned URL the file was uploaded to.
     */
    url: string;
    /**
     * The headers used for the upload.
     */
    headers: {
        [x: string]: string;
    };
    /**
     * The file extension derived from the content type, or null if unknown.
     */
    extension: string | null;
};
export type UploadOptions = {
    /**
     * URL of the backend route returning signed upload URLs. Defaults to `/signed-upload-url`.
     */
    url?: string | undefined;
    /**
     * MIME type of the file. Defaults to `file.type`, or `application/octet-stream`.
     */
    contentType?: string | undefined;
    /**
     * Called with the upload progress, from 0 to 1.
     */
    progress?: ((ratio: number) => void) | undefined;
    /**
     * Extra headers to send to the backend route (e.g. `Authorization`).
     */
    headers?: {
        [x: string]: string;
    } | undefined;
    /**
     * CSRF token, sent as `X-CSRF-TOKEN`. By default, for same-origin requests, the
     * `XSRF-TOKEN` cookie set by Laravel is sent as `X-XSRF-TOKEN` (like axios and Inertia do), or else the content
     * of the `<meta name="csrf-token">` tag is sent as `X-CSRF-TOKEN`.
     */
    csrfToken?: string | undefined;
    /**
     * Signal to cancel the upload.
     */
    signal?: AbortSignal | undefined;
    /**
     * An axios-compatible HTTP client to use instead of `fetch` and `XMLHttpRequest`.
     * The client is then responsible for CSRF and authentication.
     */
    httpClient?: HttpClient | undefined;
};
/**
 * An axios-compatible HTTP client (`axios` itself, or an axios instance).
 */
export type HttpClient = {
    post: (url: string, data: any, config: {
        [x: string]: any;
    }) => Promise<{
        data: any;
    }>;
    put: (url: string, data: any, config: {
        [x: string]: any;
    }) => Promise<any>;
};
