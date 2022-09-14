<?php

global $dt_campaign_signup_mailchimp_list_id, $dt_campaign_signup_mailchimp_tag;
$dt_campaign_signup_mailchimp_list_id = "4df6e5ea4e";
$dt_campaign_signup_mailchimp_tag = "campaign_manager";

/**
 * Prints scripts or data in the head tag on the front end.
 *
 */
add_action( 'wp_head', function() : void {
    ?>

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <?php
} );

/* Register styles */

add_action( 'wp_enqueue_scripts', function() {


    wp_enqueue_style( 'reset', trailingslashit( get_template_directory_uri() ) . 'assets/css/reset.css', [ 'normalize' ], filemtime( trailingslashit( get_template_directory() ) . 'assets/css/reset.css' ) );
    wp_enqueue_style( 'normalize', trailingslashit( get_template_directory_uri() ) . 'assets/css/normalize.css', [], filemtime( trailingslashit( get_template_directory() ) . 'assets/css/normalize.css' ) );
    wp_enqueue_style( 'main', trailingslashit( get_template_directory_uri() ) . 'assets/css/main.css', [ 'normalize', 'reset' ], filemtime( trailingslashit( get_template_directory() ) . 'assets/css/main.css' ) );

} );


function dt_pcsu_signup_blog_notification_email_rename( $message, $domain, $path, $title, $user, $user_email, $key, $meta ) {
    return str_replace( "blog", "site", $message );
}

add_filter( 'wpmu_signup_blog_notification_email', 'dt_pcsu_signup_blog_notification_email_rename', 10, 8 );

add_action( 'signup_extra_fields', function ( $errors ){
    ?>
  <p>
    <span style="text-decoration: underline;">Already have an account?</span> Sign in instead to create a new prayer campaign site:
    <input type="button"
           onclick="location.href='wp-login.php?redirect_to=<?php echo esc_html( urlencode( site_url( 'wp-signup.php' ) ) ); ?>';"
           value="Sign in"/>
  </p>
    <?php
} );

add_action( 'signup_blogform', function ( $errors ){

    wp_nonce_field( 'dt_extra_meta_info', 'dt_signup_blogform' );
    ?>
    <style>
        #privacy { display: none}
        .private-notice { color: #949494 }
    </style>
    <label for="dt_champion_name">
        What is your name? <span class="private-notice">Answer is kept private.</span>
    </label>
    <input type="text" id="dt_champion_name" name="dt_champion_name">
    <label for="dt_prayer_site">
        Do you have an existing prayer network? If so, what is the link? <span class="private-notice">Answer is kept private.</span>
    </label>
    <input type="text" id="dt_prayer_site" name="dt_prayer_site">
    <label for="dt_reason_for_subsite">
        What is your target location or people group? <span class="private-notice">Answer is kept private.</span>
    </label>
    <input type="text" id="dt_reason_for_subsite" name="dt_reason_for_subsite">
    <p>
        <label for="dt_newsletter">
            <input id="dt_newsletter" type="checkbox" name="dt_newsletter" checked>
            <strong>Sign me up for Pray4Movement news.</strong>
        </label>
    </p>
    <?php
} );


// store extra fields in wp_signups table while activating user
add_filter( 'add_signup_meta', 'dt_add_signup_meta' );
function dt_add_signup_meta( $meta ){

    if ( !isset( $_POST["dt_signup_blogform"] ) || !wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST["dt_signup_blogform"] ) ), "dt_extra_meta_info" ) ) {
        return;
    }

    if ( isset( $_POST["dt_newsletter"] ) ){
        $meta["dt_newsletter"] = 1;
    }
    if ( isset( $_POST["dt_champion_name"] ) ) {
        $meta["dt_champion_name"] = sanitize_text_field( wp_unslash( $_POST["dt_champion_name"] ) );
    }
    if ( isset( $_POST["dt_prayer_site"] ) ) {
        $meta["dt_prayer_site"] = sanitize_text_field( wp_unslash( $_POST["dt_prayer_site"] ) );
    }
    if ( isset( $_POST["dt_reason_for_subsite"] ) ) {
        $meta["dt_reason_for_subsite"] = sanitize_text_field( wp_unslash( $_POST["dt_reason_for_subsite"] ) );
    }

    return $meta;
}

/**
 * Fires when a site's initialization routine should be executed.
 *
 * @param \WP_Site $new_site New site object.
 * @param array    $args     Arguments for the initialization.
 */
add_action( 'wp_initialize_site', function( \WP_Site $new_site, array $args ) : void {
    global $dt_campaign_signup_mailchimp_tag;
    $domain = $new_site->domain;
    $blog_id = $new_site->blog_id;
    $user_id = $args["user_id"];
    $meta = $args["options"];

    if ( isset( $meta["dt_newsletter"] ) ){
        $tags = [ $dt_campaign_signup_mailchimp_tag ];
        add_user_to_mailchimp( $user_id, $tags );
    }

    $token = get_option( "crm_link_token" );
    $domain = get_option( "crm_link_domain" );

    if ( !$token || !$domain ) {
        error_log( "token or domain missing in the DB at crm_link_token or crm_link_domain" );
        return;
    }

    $site_key = md5( $token . $domain . get_site()->domain );
    $transfer_token = md5( $site_key . current_time( 'Y-m-dH', 1 ) );

    if ( !$user_id ) {
        $user_id = get_current_user_id();
    }

    $user = get_user_by( "ID", $user_id );

    if ( !$user ) {
        return;
    }

    if ( !$blog_id ) {
        $blog_id = get_current_blog_id();
    }

    $blog = get_blog_details( $blog_id );

    $email = $user->user_email;
    $fields = [
        "user_info" => [
            "name" => $meta["dt_champion_name"],
        ],
        "instance_links" => $blog->domain,
        "dt_prayer_site" => $meta["dt_prayer_site"],
        "dt_reason_for_subsite" => $meta["dt_reason_for_subsite"],
    ];
    $args = [
        'method' => 'POST',
        'body' => $fields,
        'headers' => [
            'Authorization' => 'Bearer ' . $transfer_token,
        ],
    ];
    $response = wp_remote_post( 'http://' . $domain . '/wp-json/dt-campaign/v1/contact/import?email=' . urlencode( $email ), $args );

    return;

}, 10, 2 );

function add_user_to_mailchimp( $user_id, $tags = [] ){
    global $dt_campaign_signup_mailchimp_list_id;

    if ( !$user_id ){
        $user_id = get_current_user_id();
    }

    $api_key = get_site_option( "dt_mailchimp_api_key", null );

    $user = get_user_by( "ID", $user_id );

    if ( $user && $api_key ){
        $url = "https://us14.api.mailchimp.com/3.0/lists/$dt_campaign_signup_mailchimp_list_id/members/";
        $response = wp_remote_post( $url, [
            "body" => json_encode([
                "email_address" => $user->user_email,
                "status" => "subscribed",
                "merge_fields" => [
                    "FNAME" => $user->first_name ?? "",
                    "LNAME" => $user->last_name ?? ""
                ],
                "tags" => $tags
            ]),
            "headers" => [
                "Authorization" => "Bearer $api_key",
                'Content-Type' => 'application/json; charset=utf-8'
            ],
            'data_format' => 'body',
        ]);

        if ( is_wp_error( $response ) ){
            return;
        }
    }
}

/**
 * Cleanup mailchimp when deleting site.
 *
 * @param $blog_id
 * @param $drop
 */
add_action( 'wp_uninitialize_site', 'dt_removed_mailchimp_tag', 1, 1 );
function dt_removed_mailchimp_tag( $old_site ) {
    global $dt_campaign_signup_mailchimp_list_id, $dt_campaign_signup_mailchimp_tag;

    $api_key = get_site_option( "dt_mailchimp_api_key", null );

    $admin_email = get_blog_option( $old_site->id, "admin_email" );


    if ( $admin_email && $api_key ){
        $email_hash = md5( strtolower( $admin_email ) );
        $url = "https://us14.api.mailchimp.com/3.0/lists/$dt_campaign_signup_mailchimp_list_id/members/$email_hash/tags";
        $response = wp_remote_post( $url, [
            "body" => json_encode( [
                "tags" => [
                    [
                        'name' => $dt_campaign_signup_mailchimp_tag,
                        'status' => 'inactive',
                    ],
                ]
            ] ),
            "headers" => [
                "Authorization" => "Bearer $api_key",
                'Content-Type' => 'application/json; charset=utf-8'
            ],
            'data_format' => 'body',
        ] );
    }

}

/**
 * Filters site details and error messages following registration.
 *
 * @param array $result { Array of domain, path, blog name, blog title, user and error messages. @type string         $domain     Domain for the site. @type string         $path       Path for the site. Used in subdirectory installations. @type string         $blogname   The unique site name (slug). @type string         $blog_title Blog title. @type string|WP_User $user       By default, an empty string. A user object if provided. @type WP_Error       $errors     WP_Error containing any errors found.
}
 * @return array { Array of domain, path, blog name, blog title, user and error messages. @type string         $domain     Domain for the site. @type string         $path       Path for the site. Used in subdirectory installations. @type string         $blogname   The unique site name (slug). @type string         $blog_title Blog title. @type string|WP_User $user       By default, an empty string. A user object if provided. @type WP_Error       $errors     WP_Error containing any errors found.
}
 */
add_filter( 'wpmu_validate_blog_signup', function( array $result ) : array {

    require_once( 'bad-words.php' );

    $bad_words = dt_get_bad_words();

    /* check domain, blogname and blog title for key words */
    foreach ( $bad_words as $key_word ) {
        if ( strpos( $result["domain"], $key_word ) !== false ||
        strpos( $result["blog_title"], $key_word ) !== false ||
        strpos( $result["blogname"], $key_word ) !== false ) {
            $result["errors"] = new WP_Error( "unexpected_key_word", "There is a banned keyword in the domain or blog title" );
        }
    }

    return $result;
}  );

/**
 * Fires before the site Sign-up form.
 *
 */
add_action( 'before_signup_form', function() : void {
    global $domain, $dt_old_domain;

    $dt_old_domain = $domain;

    $needle = "campaigns.";

    if ( stripos( $domain, $needle ) === 0 ) {
        //phpcs:ignore
        $domain = substr( $domain, strlen( $needle ) );
    }

    ?>
    <p>Please choose a <strong>Site Domain</strong> and <strong>Site Title</strong> that describes your prayer focus. We recommend domains like pray4france, france-ramadan, france-lent, france247, etc. The Site Domain and Site Title will be publicly visible.</p>
    <?php
} );

/**
 * Fires after a network is retrieved.
 *
 * @param \WP_Network $_network Network data.
 * @return \WP_Network Network data.
 */
add_filter( 'get_network', function( \WP_Network $_network ) : \WP_Network {
    global $domain;
    if ( isset( $_SERVER["REQUEST_URI"] ) && $_SERVER["REQUEST_URI"] === "/wp-signup.php" ) {
        $_network->domain = $domain;
    }
    return $_network;
} );

/**
 * Fires when the site or user sign-up process is complete.
 *
 */
add_action( 'after_signup_form', function() : void {
    global $domain, $dt_old_domain;

    //phpcs:ignore
    $domain = $dt_old_domain;
} );
