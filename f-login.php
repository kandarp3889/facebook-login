<?php
/*
Plugin Name: Facebook Login
Description: Facebook OAuth2 login integration.
Version: 1.0
Author: Gal Adany
*/

// Define the site URL and domain only once
$site_url = get_site_url(); // https://new.galadany.com
$parsed_url = parse_url($site_url);
$domain = $parsed_url['host']; // new.galadany.com

// Facebook Login
$facebookAppId = '7466502043372316';
$facebookAppSecret = 'a0090e41b504b08391897015980912f2';
$facebookRedirectUri = $site_url;
$facebookLoginUrl = 'https://www.facebook.com/v12.0/dialog/oauth?client_id=' . $facebookAppId . '&redirect_uri=' . urlencode($facebookRedirectUri) . '&response_type=code&scope=email';


// Shortcode for displaying login links
function social_login_shortcode() {
    global $googleLoginUrl, $facebookLoginUrl;

    $output = '<li class="facebook-btn"><a href="'.$facebookLoginUrl.'">
                    <div class="sicon">
                    <img src="/wp-content/uploads/2024/03/facebook.svg"> 
                    </div>
                    <div class="sname">
                    Facebook
                    </div>
                    </a></li>';

    return $output;
}
add_shortcode('facebook_social_login', 'social_login_shortcode');

// Handle callback from Facebook
function handle_facebook_callback() {

    global $site_url;
    
    if (isset($_GET['code'])) {
        $code = $_GET['code'];

        $facebookAppId = '7466502043372316';
        $facebookAppSecret = 'a0090e41b504b08391897015980912f2';
        $facebookRedirectUri = get_site_url().'/';
        
        $token_request_data = array(
            'client_id' => $facebookAppId,
            'client_secret' => $facebookAppSecret,
            'redirect_uri' => $facebookRedirectUri,
            'code' => $code
        );

        // Make a GET request to exchange code for access token
        $token_request_url = 'https://graph.facebook.com/v12.0/oauth/access_token?' . http_build_query($token_request_data);
        $token_response = wp_remote_get($token_request_url);

        if (is_wp_error($token_response)) {
            return; // Handle error
        }

        $token_body = wp_remote_retrieve_body($token_response);
        $token_info = json_decode($token_body, true);

        if (isset($token_info['error'])) {
            return; // Handle error
        }

        // Get user info using access token
        $access_token = $token_info['access_token'];
        $user_info_url = 'https://graph.facebook.com/v12.0/me?fields=id,email,first_name,last_name&access_token=' . $access_token;
        $user_info_response = wp_remote_get($user_info_url);

        if (is_wp_error($user_info_response)) {
            return; // Handle error
        }

        $user_info_body = wp_remote_retrieve_body($user_info_response);
        $user_info = json_decode($user_info_body, true);

        if (!$user_info || isset($user_info['error'])) {
            return; // Handle error
        }

        // Process user info or do other necessary actions
        $username = $user_info['first_name'] . '_' . $user_info['last_name']; // Generate username from Facebook name
        $user_email = isset($user_info['email']) ? $user_info['email'] : ''; // Get email if available
        $user = get_user_by('login', $username);

        // If user doesn't exist, register them
        if (!$user) {
            $user_id = wp_create_user($username, wp_generate_password(), $user_email);

            if (!is_wp_error($user_id)) {
                update_user_meta($user_id, 'first_name', $user_info['first_name']);
                update_user_meta($user_id, 'last_name', $user_info['last_name']);
                update_user_meta($user_id, 'email_verified', 1);

                // Update the email if it's available
                if (!empty($user_email)) {
                    wp_update_user(array(
                        'ID' => $user_id,
                        'user_email' => $user_email,
                        'display_name' => $user_info['first_name'] . ' ' . $user_info['last_name']
                    ));
                }

                $user = get_user_by('id', $user_id);
            }
        } else {
            // Update existing user's email if not already set
            if (empty($user->user_email) && !empty($user_email)) {
                wp_update_user(array(
                    'ID' => $user->ID,
                    'user_email' => $user_email
                ));
            }
        }

        // If user found, log them in
        if ($user) {

            if (!empty($_COOKIE['mailchimp_added']) && $_COOKIE['mailchimp_added'] === 'true') {

                $log_file = ABSPATH . 'user_login_fb.log';
                $added = add_subscriber_to_mailchimp($user_email);
                error_log($added, 3, $log_file);

            }

            check_and_remove_purchased_items_after_login($user->ID);
            wp_set_auth_cookie($user->ID, true);
            wp_redirect(get_site_url());
            exit;
        }
    }
}


add_action('init', 'handle_facebook_callback');