<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Zammad URL
    |--------------------------------------------------------------------------
    |
    | This URL is used to properly communicate with the Zammad REST-API for
    | every request that is made. Please make sure to include the scheme
    | and the hostname in it. Otherwise we can't find the destination.
    |
    */

    'url' => env('ZAMMAD_URL'),

    /*
    |--------------------------------------------------------------------------
    | Zammad Access Token
    |--------------------------------------------------------------------------
    |
    | The access token is used to authenticate with the Zammad REST-API. You
    | should make sure to activate the "HTTP Token Authentication" in the
    | configuration. Afterwards generate a token in your settings page.
    |
    */

    'token' => env('ZAMMAD_TOKEN'),

    /*
     |--------------------------------------------------------------------------
     | HTTP Retry Values
     |--------------------------------------------------------------------------
     |
     | GET, HEAD, PUT and DELETE retry connection failures, HTTP 429 and server errors.
     | Other methods retry only when the host could not be resolved or connected to.
     | The maximum value is the total number of attempts. The delay is in milliseconds.
     |
     */

    'http_retry_maximum' => env('ZAMMAD_HTTP_RETRY_MAXIMUM', 3),
    'http_retry_delay' => env('ZAMMAD_HTTP_RETRY_DELAY', 1500),

    /*
    |--------------------------------------------------------------------------
    | HTTP Timeouts
    |--------------------------------------------------------------------------
    |
    | The connection timeout limits the time spent establishing a connection.
    | The request timeout limits the total request duration. Both are in seconds.
    |
    */

    'connect_timeout' => env('ZAMMAD_CONNECT_TIMEOUT', 10),
    'request_timeout' => env('ZAMMAD_REQUEST_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | Object reference error on delete request
    |--------------------------------------------------------------------------
    |
    | Please note that removing data cannot be undone. Zammad will also remove references - thus potentially tickets!
    | Removing data with references in e.g. activity streams is not possible via API - this will be indicated by "error":
    | "Can't delete, object has references." (Status 422). This is not a bug.
    | Enable object_reference_error_ignore to return this 422 response without throwing.
    | The object_reference_error message is matched after decoding HTML entities.
    | Consider using Data Privacy via UI for more control instead. https://docs.zammad.org/en/latest/api/user.html#delete
    |
    */

    'object_reference_error_ignore' => env('ZAMMAD_OBJECT_REFERENCE_ERROR_IGNORE', false),
    'object_reference_error' => env('ZAMMAD_OBJECT_REFERENCE_ERROR', "Can't delete, object has references."),

    /*
    |--------------------------------------------------------------------------
    | Comment Object HTML Parsing
    |--------------------------------------------------------------------------
    |
    | These filters control Comment::body_filtered for HTML comments. The original
    | body is preserved. Images can be removed, table markup can be unwrapped,
    | and signature markers can truncate the filtered body before the signature.
    | Safe HTML sanitization still applies when these optional filters are disabled.
    |
    */

    /**
     * Remove img elements from the filtered HTML body. This does not truncate
     * surrounding text or change the original comment body.
     */
    'filter_images' => env('ZAMMAD_FILTER_IMAGES', true),

    /**
     * Remove table markup while preserving its text in the filtered HTML body.
     * This does not truncate at a table or change the original comment body.
     */
    'filter_tables' => env('ZAMMAD_FILTER_TABLES', true),

    /**
     * Truncate the filtered HTML body before the first signature marker below,
     * removing the marker and everything after it. The original body is preserved.
     */
    'filter_signature_marker' => env('ZAMMAD_FILTER_SIGNATURE_MARKER', true),

    /**
     * Literal, case-sensitive marker used when filter_signature_marker is enabled.
     * The filtered HTML body is truncated before its first exact occurrence.
     */
    'filter_signature_marker_value' => env('ZAMMAD_FILTER_SIGNATURE_MARKER_VALUE', '<span class="js-signatureMarker"></span>'),

    /**
     * Truncate the filtered HTML body before the first data-signature marker below,
     * removing the marker and everything after it. The original body is preserved.
     */
    'filter_data_signature' => env('ZAMMAD_FILTER_DATA_SIGNATURE', true),

    /**
     * Literal, case-sensitive marker used when filter_data_signature is enabled.
     * The filtered HTML body is truncated before its first exact occurrence.
     */
    'filter_data_signature_value' => env('ZAMMAD_FILTER_DATA_SIGNATURE_VALUE', '<div data-signature="true" data-signature-id="1">'),

    /*
    |--------------------------------------------------------------------------
    | Dynamic Ticket Attributes with Casts
    |--------------------------------------------------------------------------
    |
    | You should define a list of all your dynamic ticket attributes here to
    | ensure that they are correctly converted into the native types. The
    | only limitation you have is to include following supported types.
    |
    | Supported: "string", "integer", "float", "boolean", "datetime"
    |
    */

    'ticket' => [
        // 'note' => 'string',
    ],

    /*
    |--------------------------------------------------------------------------
    | Ticket States
    |--------------------------------------------------------------------------
    |
    | The ticket states are used to determine if a ticket is open, closed,
    | active or inactive. You can use this information to filter tickets
    | by their state. The following states are supported by default.
    | https://docs.zammad.org/en/latest/api/ticket/states.html
    |
    */

    'ticket_states' => [
        'open' => [1, 2, 3, 7],
        'closed' => [4],
        'active' => [1, 2, 3, 4, 7],
        'inactive' => [5, 6],
    ],

];
