<?php

use NotificationChannels\WebPush\PushSubscription;

return [

    /**
     * These are the keys for authentication (VAPID).
     * These keys must be safely stored and should not change.
     */
    'vapid' => [
        'subject' => env('VAPID_SUBJECT'),
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        'pem_file' => env('VAPID_PEM_FILE'),
    ],

    /**
     * This is model that will be used to for push subscriptions.
     */
    'model' => PushSubscription::class,

    /**
     * This is the name of the table that will be created by the migration and
     * used by the PushSubscription model shipped with this package.
     */
    'table_name' => env('WEBPUSH_DB_TABLE', 'push_subscriptions'),

    /**
     * This is the database connection that will be used by the migration and
     * the PushSubscription model shipped with this package.
     */
    'database_connection' => env('WEBPUSH_DB_CONNECTION', env('DB_CONNECTION', 'mysql')),

    /**
     * The Guzzle client options used by Minishlink\WebPush.
     */
    'client_options' => [],

    // Security-critical transport options are constructed directly, not client_options.
    'delivery' => [
        'connection' => 'redis',
        'queue' => 'pushnotify',
        'dns_timeout' => 2,
        'connect_timeout' => 3,
        'request_timeout' => 10,
        'ttl' => 300,
        // Also deny locally routed public space and deployment-specific NAT64 prefixes.
        'blocked_cidrs' => [],
    ],

    /**
     * Google Cloud Messaging.
     *
     * @deprecated
     */
    'gcm' => [
        'key' => env('GCM_KEY'),
        'sender_id' => env('GCM_SENDER_ID'),
    ],

];
