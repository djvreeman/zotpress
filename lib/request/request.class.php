<?php if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly 


/**
 *
 *  ZOTPRESS REQUEST CLASS
 *
 *  Based on Sean Huber's CURL library with additions by Mike Purvis.
 *  Checks for updates every 10 minutes.
 *
 *  Requires: request url (e.g. https://api.zotero.org/...), api user id (can be accessed from request url)
 *  Returns: array with json and headers (json-formatted)
 *
*/

if ( ! class_exists('ZotpressRequest') )
{
    class ZotpressRequest
    {
        public  $update = false,
                $request_error = false,
                $check_every_n_mins = 10, // 10 minutes
                $api_user_id,
                $api_key = false,
                $cleaned_url = false,
                $request_type = 'item',
                $account_type = false; // 'users' or 'groups'

        // REVIEW: This was causing problems for some people ...
        // Could it be how the database is set up?
        // e.g., https://stackoverflow.com/questions/36028844/warning-gzdecode-data-error-in-php
        function zotpress_gzdecode( $data )
        {
            if ( ! is_null( $data ) )
                // Thanks to Waynn Lue (StackOverflow)
                if ( function_exists("gzdecode") )
                    return gzdecode($data);
                else
                    return gzinflate(substr($data,10,-8));
        }


        function set_request_meta( $url, $update, $request_type = 'item' )
        {
            $this->update = $update;

            if ( $request_type != 'item' )
                $this->request_type = $request_type;

            // Extract API key from URL if present (for backward compatibility)
            // Then remove it from URL as we'll use it as a header
            $parsed_url = parse_url($url);
            if ( isset($parsed_url['query']) ) {
                parse_str($parsed_url['query'], $query_params);
                if ( isset($query_params['key']) ) {
                    $this->api_key = $query_params['key'];
                    // Remove key from query string
                    unset($query_params['key']);
                    // Rebuild URL without key
                    $new_query = http_build_query($query_params);
                    $url = $parsed_url['scheme'] . '://' . $parsed_url['host'] . $parsed_url['path'];
                    if ( !empty($new_query) ) {
                        $url .= '?' . $new_query;
                    } elseif ( isset($parsed_url['fragment']) ) {
                        $url .= '#' . $parsed_url['fragment'];
                    }
                }
            }

            // Get and set api user id and account type
            // Check for groups first, then users
            $divider = "users/";
            if ( strpos( $url, "groups/" ) !== false ) {
                $divider = "groups/";
                $this->account_type = "groups";
                error_log("Zotpress: Detected group account from URL: " . $url);
            } elseif ( strpos( $url, "users/" ) !== false ) {
                $this->account_type = "users";
            } else {
                // If neither found, log error and default to users
                error_log("Zotpress: Could not determine account type from URL: " . $url);
                $this->account_type = "users";
            }
            error_log("Zotpress: set_request_meta - account_type set to: " . $this->account_type . " for URL: " . $url);
            $temp1 = explode( $divider, $url );
            if ( isset($temp1[1]) ) {
                $temp2 = explode( "/", $temp1[1] );
                $this->api_user_id = $temp2[0];
            } else {
                error_log("Zotpress: Could not extract API user ID from URL: " . $url);
                $this->api_user_id = false;
            }

            // If we don't have an API key from URL, try to get it from database
            if ( ! $this->api_key && $this->api_user_id ) {
                global $wpdb;
                $zp_account = zotpress_get_account( $wpdb, $this->api_user_id );
                if ( count($zp_account) > 0 && ! empty($zp_account[0]->public_key) ) {
                    $this->api_key = $zp_account[0]->public_key;
                }
            }

            // Store cleaned URL (without key) for use in requests
            $this->cleaned_url = $url;
        }


        // NOTE: used by shortcode.request.php
        function get_request_cache( $url, $update, $request_type = 'item' )
        {
            $this->set_request_meta( $url, $update, $request_type );

            // Use cleaned URL for cache lookup
            $cache_url = $this->cleaned_url ? $this->cleaned_url : $url;
            $data = $this->check_and_get_cache( $cache_url );

            // Check for request errors
            if ( $this->request_error !== false )
                return 'Error: ' . $this->request_error; // exit();
            else // Otherwise, return the data
                return $data;
        }

        
        // NEW in 7.3.6: Request an update if cached version out of date
        function get_request_update( $url, $update, $request_type = 'item' )
        {
            global $wpdb;

            $this->set_request_meta( $url, $update, $request_type );

            // Use cleaned URL for request
            $request_url = $this->cleaned_url ? $this->cleaned_url : $url;
            $data = $this->getRegular( $wpdb, $request_url );
            $data["json"] = $data["data"];

            // Check for request errors
            if ( $this->request_error !== false )
                return 'Error: ' . $this->request_error; // exit();
            else // Otherwise, return the data
                return $data;
        }


        function get_request_contents( $url, $update, $request_type = 'item' )
        {
            $this->set_request_meta( $url, $update, $request_type );

            // NEW in 7.3.6: First, check the cache:
            // Use cleaned URL for cache lookup
            // Initialize variables to prevent undefined variable warnings
            $json = false;
            $tags = false;
            $headers = false;
            
            $cache_url = $this->cleaned_url ? $this->cleaned_url : $url;
            $data = $this->check_and_get_cache( $cache_url );
            $data_json = json_decode($data["json"]);

            // Only try to update if time has passed:
            $updateneeded = $data["updateneeded"];

            // Then, proceed without cache if none exists;
            // if no cache, then array returned:
            if ( ! is_array($data_json) )
            // if ( property_exists($data_json, "status")
            //         && $data_json->status == "No Cache" )
            {
                // Use cleaned URL for request
                $request_url = $this->cleaned_url ? $this->cleaned_url : $url;
                $data = $this->get_xml_data( $request_url, $data["updateneeded"] );
            }

            // Check for request errors
            if ( $this->request_error !== false )
                return 'Error: ' . $this->request_error; // exit();
            else // Otherwise, return the data
                return $data;
        }


        // Limit Zotero request calls based on elapsed time
        function check_time( $last_time )
        {
            // Set time zone based on WP installation
            // 7.4: Removing to rely on WP
            // date_default_timezone_set( wp_timezone_string() );

            // Set up the dates to compare
            $last_time = date_create($last_time);
            $now = date_create();

            $timeElapsed = date_diff($last_time, $now);

            // Convert to total minutes difference
            $timeElapsedMin = ( $timeElapsed->y * 525600 )
                + ( $timeElapsed->m * 43800 )
                + ( $timeElapsed->d * 1440 )
                + ( $timeElapsed->i )
                + ( $timeElapsed->s * 0.0166667 );

            if ( $timeElapsedMin > $this->check_every_n_mins )
                return true;
            else // Not time yet
                return false;
        }


        function check_and_get_cache( $url )
        {
            global $wpdb;

            // Use cleaned URL for cache lookups (consistent key regardless of key location)
            $cache_url = $this->cleaned_url ? $this->cleaned_url : $url;

            // First, check db to see if cached version exists
            // $zp_query =
            //         "
            //         SELECT DISTINCT ".$wpdb->prefix."zotpress_cache.*
            //         FROM ".$wpdb->prefix."zotpress_cache
            //         WHERE ".$wpdb->prefix."zotpress_cache.request_id = '".md5( $url )."'
            //         AND ".$wpdb->prefix."zotpress_cache.api_user_id = '".$this->api_user_id."'
            //         ";
            // $zp_results = $wpdb->get_results( $zp_query, OBJECT );
            $zp_results = $wpdb->get_results(
                $wpdb->prepare(
                    "
                    SELECT DISTINCT ".$wpdb->prefix."zotpress_cache.*
                    FROM ".$wpdb->prefix."zotpress_cache
                    WHERE ".$wpdb->prefix."zotpress_cache.request_id = %s
                    AND ".$wpdb->prefix."zotpress_cache.api_user_id = %s
                    ",
                    array( md5($cache_url), $this->api_user_id )
                ), OBJECT
            );
            // unset($zp_query);

            $updateneeded = false;

            if ( count($zp_results) != 0 )
            {
                // Cache exists, but is it out of date? Check:
                if ( isset($zp_results[0]->retrieved)
                        && $this->check_time($zp_results[0]->retrieved) )
                    $updateneeded = true;

                // Use the cache:
                $json = $this->zotpress_gzdecode( $zp_results[0]->json );
                $tags = $this->zotpress_gzdecode( $zp_results[0]->tags );
                $headers = $zp_results[0]->headers;
            }

            else // No cache
            {
                // $json = json_encode( array('status' => 'No Cache') );
                $json = wp_json_encode( array('status' => 'No Cache') );
                $tags = false;
                $headers = false;
            }

            $wpdb->flush();

            return array(
                "json" => $json, 
                "tags" => $tags, 
                "headers" => $headers, 
                "updateneeded" => $updateneeded );
        }


        function get_xml_data( $url, $updateneeded=false )
        {
            global $wpdb;
            
            // Initialize variables to prevent undefined variable warnings
            $json = false;
            $tags = false;
            $headers = false;

            // Use cleaned URL for cache lookups
            $cache_url = $this->cleaned_url ? $this->cleaned_url : $url;

            // Just want to check for cached version
            if ( $this->update === false )
            {
                // First, check db to see if cached version exists
                // $zp_query =
                //         "
                //         SELECT DISTINCT ".$wpdb->prefix."zotpress_cache.*
                //         FROM ".$wpdb->prefix."zotpress_cache
                //         WHERE ".$wpdb->prefix."zotpress_cache.request_id = '".md5( $cache_url )."'
                //         AND ".$wpdb->prefix."zotpress_cache.api_user_id = '".$this->api_user_id."'
                //         ";
                // $zp_results = $wpdb->get_results( $zp_query, OBJECT ); unset($zp_query);

                $zp_results = $wpdb->get_results(
                    $wpdb->prepare(
                        "
                        SELECT DISTINCT ".$wpdb->prefix."zotpress_cache.*
                        FROM ".$wpdb->prefix."zotpress_cache
                        WHERE ".$wpdb->prefix."zotpress_cache.request_id = %s
                        AND ".$wpdb->prefix."zotpress_cache.api_user_id = %s
                        ",
                        array( md5($cache_url), $this->api_user_id )
                    ), OBJECT
                );
                
                // Cache exists
                if ( count($zp_results) > 0 )
                {
                    $json = $this->zotpress_gzdecode($zp_results[0]->json);
                    $tags = $this->zotpress_gzdecode($zp_results[0]->tags);
                    $headers = $zp_results[0]->headers;
                }

                else // No cached
                {
                    // Use cleaned URL for request
                    $request_url = $this->cleaned_url ? $this->cleaned_url : $url;
                    $regular = $this->getRegular( $wpdb, $request_url );

                    $json = $regular['data'];
                    $tags = $regular['tags'];
                    $headers = $regular['headers'];
                }

                $wpdb->flush();
            }

            else // Normal or RIS
            {
                // Use cleaned URL for request
                $request_url = $this->cleaned_url ? $this->cleaned_url : $url;
                $regular = $this->getRegular( $wpdb, $request_url );

                $json = $regular['data'];
                $tags = $regular['tags'];
                $headers = $regular['headers'];
            }

            return array( "json" => $json, "tags" => $tags, "headers" => $headers, "updateneeded" => $updateneeded );
        }


        function getRegular( $wpdb, $url )
        {
            global $wpdb;

            // Use cleaned URL for cache lookups (consistent key regardless of key location)
            $cache_url = $this->cleaned_url ? $this->cleaned_url : $url;

            // First, check db to see if cached version exists
            // $zp_query =
            //         "
            //         SELECT DISTINCT ".$wpdb->prefix."zotpress_cache.*
            //         FROM ".$wpdb->prefix."zotpress_cache
            //         WHERE ".$wpdb->prefix."zotpress_cache.request_id = '".md5( $url )."'
            //         AND ".$wpdb->prefix."zotpress_cache.api_user_id = '".$this->api_user_id."'
            //         ";
            // $zp_results = $wpdb->get_results($zp_query, OBJECT); unset($zp_query);

            $zp_results = $wpdb->get_results(
                $wpdb->prepare(
                    "
                    SELECT DISTINCT ".$wpdb->prefix."zotpress_cache.*
                    FROM ".$wpdb->prefix."zotpress_cache
                    WHERE ".$wpdb->prefix."zotpress_cache.request_id = %s
                    AND ".$wpdb->prefix."zotpress_cache.api_user_id = %s
                    ",
                    array( md5($cache_url), $this->api_user_id )
                ), OBJECT
            );

            // Then, if no cached version, proceed and save one.
            // Or, if cached version exists, check to see if it's out of date,
            // and return whichever is newer (and cache the newest).
            // if ( count($zp_results) == 0
            //         || ( property_exists($zp_results[0], 'retrieved') && $zp_results[0]->retrieved !== null
            //                 && $this->check_time($zp_results[0]->retrieved) ) )
            // {
            if ( count($zp_results) == 0
                    || ( isset($zp_results[0]->retrieved)
                            && $this->check_time($zp_results[0]->retrieved) ) )
            {
                // Check if this is a group account - use stored account_type for reliable detection
                $is_group = ( $this->account_type === "groups" );
                
                $headers_arr = array ( "Zotero-API-Version" => "3" );

                // Add API key as header (recommended method per Zotero API docs)
                // For groups, only add key if we have one - public groups work without keys
                if ( $this->api_key ) {
                    $headers_arr["Zotero-API-Key"] = $this->api_key;
                } else if ( $is_group ) {
                    // For groups without API key, try without key (public groups don't need auth)
                    error_log("Zotpress: Group request without API key - attempting public group access");
                }

                if ( count($zp_results) > 0 )
                    $headers_arr["If-Modified-Since-Version"] = $zp_results[0]->libver;

                // Use cleaned URL (without key in query string)
                $request_url = $this->cleaned_url ? $this->cleaned_url : $url;

                // Get response
                $response = wp_remote_get( $request_url, array ( 'headers' => $headers_arr ) );

                if ( is_wp_error($response) )
                    $this->request_error = $response->get_error_message();
                // Check for HTTP error codes that might indicate permission issues
                else if ( isset($response["response"]["code"]) ) {
                    $http_code = $response["response"]["code"];
                    if ( $http_code == 403 ) {
                        // Check if this is a group account - use stored account_type for reliable detection
                        // Also check URL as fallback in case account_type wasn't set
                        $is_group = ( $this->account_type === "groups" ) || ( strpos( $request_url, "groups/" ) !== false ) || ( strpos( $url, "groups/" ) !== false );
                        error_log("Zotpress: 403 error - account_type: " . ($this->account_type ? $this->account_type : "NOT SET") . ", request_url: " . $request_url . ", original url: " . $url . ", Is Group: " . ($is_group ? "Yes" : "No") . ", Has API Key: " . ($this->api_key ? "Yes" : "No"));
                        if ( $is_group && $this->api_key ) {
                            // For groups, if we get 403 with an API key, try without the key
                            // Public groups don't require authentication per Zotero API docs
                            error_log("Zotpress: Got 403 for group with API key, retrying without key (public group may not require auth)");
                            $headers_arr_no_key = array ( "Zotero-API-Version" => "3" );
                            if ( count($zp_results) > 0 )
                                $headers_arr_no_key["If-Modified-Since-Version"] = $zp_results[0]->libver;
                            
                            $retry_response = wp_remote_get( $request_url, array ( 'headers' => $headers_arr_no_key ) );
                            
                            // Check if retry was successful
                            if ( ! is_wp_error($retry_response) && isset($retry_response["response"]["code"]) && $retry_response["response"]["code"] == 200 ) {
                                // Success! Public group doesn't need API key - use the retry response
                                $this->api_key = false; // Clear key for this request
                                $this->request_error = false;
                                $response = $retry_response; // Use the successful retry response
                                $headers = wp_json_encode( wp_remote_retrieve_headers( $response )->getAll() );
                                error_log("Zotpress: Retry successful for public group - proceeding with response");
                                // Continue processing below - don't set error, let it fall through to normal processing
                            } else {
                                // Still failed, provide error message
                                if ( ! is_wp_error($retry_response) && isset($retry_response["response"]["code"]) ) {
                                    $retry_code = $retry_response["response"]["code"];
                                    if ( $retry_code == 403 ) {
                                        $this->request_error = "Access forbidden. This group is private and requires an API key with 'Read' or 'Read/Write' permissions. Check your Zotero Settings > Keys and the group's permissions.";
                                    } else {
                                        $this->request_error = "HTTP Error {$retry_code}: " . ( isset($retry_response["body"]) ? $retry_response["body"] : "Unknown error" );
                                    }
                                } else {
                                    $this->request_error = "Access forbidden. For group libraries, ensure your API key has 'Read' or 'Read/Write' permissions for this group, or the group is public. Check your Zotero Settings > Keys and the group's permissions.";
                                }
                            }
                        } else if ( $is_group ) {
                            // Group but no API key (or invalid key) - try without key first (public groups don't need auth)
                            error_log("Zotpress: 403 for group without valid API key, retrying without key (public group may not require auth)");
                            $headers_arr_no_key = array ( "Zotero-API-Version" => "3" );
                            if ( count($zp_results) > 0 )
                                $headers_arr_no_key["If-Modified-Since-Version"] = $zp_results[0]->libver;
                            
                            $retry_response = wp_remote_get( $request_url, array ( 'headers' => $headers_arr_no_key ) );
                            
                            // Check if retry was successful
                            if ( ! is_wp_error($retry_response) && isset($retry_response["response"]["code"]) && $retry_response["response"]["code"] == 200 ) {
                                // Success! Public group doesn't need API key - use the retry response
                                $this->request_error = false;
                                $response = $retry_response; // Use the successful retry response
                                $headers = wp_json_encode( wp_remote_retrieve_headers( $response )->getAll() );
                                error_log("Zotpress: Retry successful for public group (no API key) - proceeding with response");
                                // Continue processing below - don't set error, let it fall through to normal processing
                            } else {
                                // Still failed, group is private
                                $this->request_error = "Access forbidden. This group is private and requires an API key with 'Read' or 'Read/Write' permissions. Check your Zotero Settings > Keys and the group's permissions.";
                            }
                        } else {
                            $this->request_error = "Access forbidden. Check that your API key has proper permissions.";
                        }
                    } else if ( $http_code == 404 ) {
                        $this->request_error = "Resource not found. Verify the group/user ID and that the resource exists.";
                    } else if ( $http_code >= 400 ) {
                        $this->request_error = "HTTP Error {$http_code}: " . ( isset($response["body"]) ? $response["body"] : "Unknown error" );
                    } else {
                        // 7.3.13: No collection and tag error reporting:
                        if ( isset($response["body"]) && ( $response["body"] == "Collection not found" || $response["body"] == "Tag not found" ) ) {
                            $this->request_error = $response["body"];
                        } else {
                            // $headers = json_encode( wp_remote_retrieve_headers( $response )->getAll() );
                            $headers = wp_json_encode( wp_remote_retrieve_headers( $response )->getAll() );
                        }
                    }
                } else {
                    // 7.3.13: No collection and tag error reporting:
                    if ( isset($response["body"]) && ( $response["body"] == "Collection not found" || $response["body"] == "Tag not found" ) ) {
                        $this->request_error = $response["body"];
                    } else {
                        // $headers = json_encode( wp_remote_retrieve_headers( $response )->getAll() );
                        $headers = wp_json_encode( wp_remote_retrieve_headers( $response )->getAll() );
                    }
                }
            }

            if ( ! $this->request_error )
            {
                // Proceed if no cached version or to check server for newer
                if ( count($zp_results) == 0
                        || ( isset($response["response"]["code"])
                                && $response["response"]["code"] != "304" ) )
                {
                    // Deal with errors
                    if ( is_wp_error($response)
                            || ! isset($response['body']) )
                    {
                        $this->request_error = $response->get_error_message();
                        
                        if ( $response->get_error_code() == "http_request_failed" )
                        {
                            // Try again with less restrictions
                            add_filter('https_ssl_verify', '__return_false');
                            $fallback_headers = array("Zotero-API-Version" => "2");
                            if ( $this->api_key ) {
                                $fallback_headers["Zotero-API-Key"] = $this->api_key;
                            }
                            $response = wp_remote_get( $request_url, array( 'headers' => $fallback_headers ) );

                            if (is_wp_error($response) || ! isset($response['body'])) {
                                $this->request_error = $response->get_error_message();
                            } elseif ($response == "An error occurred" || ( isset($response['body']) && $response['body'] == "An error occurred")) {
                                $this->request_error = "WordPress was unable to import from Zotero. This is likely caused by an incorrect citation style name. For example, 'mla' is now 'modern-language-association'. Use the name found in the style's URL at the Zotero Style Repository.";
                            } else // no errors this time
                            {
                                $this->request_error = false;
                            }
                        }
                    }

                    elseif ( $response == "An error occurred"
                            || ( isset($response['body'])
                                    && $response['body'] == "An error occurred") )
                    {
                        $this->request_error = "WordPress was unable to import from Zotero. This is likely caused by an incorrect citation style name. For example, 'mla' is now 'modern-language-association'. Use the name found in the style's URL at the Zotero Style Repository.";
                    }

                    // Then, get actual data
                    $data = wp_remote_retrieve_body( $response ); // Thanks to Trainsmart.com developer!

                    // Make sure tags didn't return an error -- redo if so
                    if ( $data == "Tag not found" )
                    {
                        $url_break = explode("/", $url);
                        $url = $url_break[0]."//".$url_break[2]."/".$url_break[3]."/".$url_break[4]."/".$url_break[7];
                        $url = str_replace("=50", "=5", $url);

                        $data = $this->get_xml_data( $url );
                    }

                    // Add or update cache, if not attachment, etc.
                    if ( isset($response["headers"]["last-modified-version"]) )
                    {
                        if ( $this->request_type != 'ris' )
                        {
                            $data = json_decode($data); // will become 'json'
                            $tags = array(); // empty for now; by item key later

                            // If not array, turn into one for simplicity
                            if ( ! is_array($data) ) $data = array($data);

                            // Remove unncessary details
                            // REVIEW: Does this account for all unused metadata? Depends on item type ...
                            foreach( $data as $id => $item )
                            {
                                if ( property_exists($data[$id], 'version') ) unset($data[$id]->version);
                                if ( property_exists($data[$id], 'links') ) unset($data[$id]->links);

                                if ( property_exists($data[$id], 'library') )
                                {
                                    if ( property_exists($data[$id]->library, 'type') ) unset($data[$id]->library->type);
                                    if ( property_exists($data[$id]->library, 'name') ) unset($data[$id]->library->name);
                                    if ( property_exists($data[$id]->library, 'links') ) unset($data[$id]->library->links);
                                }
                                if ( property_exists($data[$id], 'data') )
                                {
                                    if ( property_exists($data[$id]->data, 'key') ) unset($data[$id]->data->key);
                                    if ( property_exists($data[$id]->data, 'version') ) unset($data[$id]->data->version);
                                    if ( property_exists($data[$id]->data, 'series') ) unset($data[$id]->data->series);
                                    if ( property_exists($data[$id]->data, 'seriesNumber') ) unset($data[$id]->data->seriesNumber);
                                    if ( property_exists($data[$id]->data, 'seriesTitle') ) unset($data[$id]->data->seriesTitle);
                                    if ( property_exists($data[$id]->data, 'seriesText') ) unset($data[$id]->data->seriesText);
                                    if ( property_exists($data[$id]->data, 'publicationTitle') ) unset($data[$id]->data->publicationTitle);
                                    if ( property_exists($data[$id]->data, 'journalAbbreviation') ) unset($data[$id]->data->journalAbbreviation);
                                    if ( property_exists($data[$id]->data, 'issue') ) unset($data[$id]->data->issue);
                                    if ( property_exists($data[$id]->data, 'volume') ) unset($data[$id]->data->volume);
                                    if ( property_exists($data[$id]->data, 'numberOfVolumes') ) unset($data[$id]->data->numberOfVolumes);
                                    if ( property_exists($data[$id]->data, 'edition') ) unset($data[$id]->data->edition);
                                    if ( property_exists($data[$id]->data, 'place') ) unset($data[$id]->data->place);
                                    if ( property_exists($data[$id]->data, 'publisher') ) unset($data[$id]->data->publisher);
                                    if ( property_exists($data[$id]->data, 'pages') ) unset($data[$id]->data->pages);
                                    if ( property_exists($data[$id]->data, 'numPages') ) unset($data[$id]->data->numPages);
                                    if ( property_exists($data[$id]->data, 'shortTitle') ) unset($data[$id]->data->shortTitle);
                                    if ( property_exists($data[$id]->data, 'accessDate') ) unset($data[$id]->data->accessDate);
                                    if ( property_exists($data[$id]->data, 'archive') ) unset($data[$id]->data->archive);
                                    if ( property_exists($data[$id]->data, 'archiveLocation') ) unset($data[$id]->data->archiveLocation);
                                    if ( property_exists($data[$id]->data, 'libraryCatalog') ) unset($data[$id]->data->libraryCatalog);
                                    if ( property_exists($data[$id]->data, 'callNumber') ) unset($data[$id]->data->callNumber);
                                    if ( property_exists($data[$id]->data, 'rights') ) unset($data[$id]->data->rights);
                                    if ( property_exists($data[$id]->data, 'extra') ) unset($data[$id]->data->extra);
                                    if ( property_exists($data[$id]->data, 'relations') ) unset($data[$id]->data->relations);
                                    if ( property_exists($data[$id]->data, 'dateAdded') ) unset($data[$id]->data->dateAdded);
                                    if ( property_exists($data[$id]->data, 'websiteTitle') ) unset($data[$id]->data->websiteTitle);
                                    if ( property_exists($data[$id]->data, 'websiteType') ) unset($data[$id]->data->websiteType);
                                    if ( property_exists($data[$id]->data, 'inPublications') ) unset($data[$id]->data->inPublications);
                                    if ( property_exists($data[$id]->data, 'presentationType') ) unset($data[$id]->data->presentationType);
                                    if ( property_exists($data[$id]->data, 'meetingName') ) unset($data[$id]->data->meetingName);
                                }

                                // As of 7.1.4, tags are saved separately
                                // due to possibily large quantities and the
                                // limits of blob; so we always save now
                                // REVIEW: Do we need the account, too?
                                
                                // 7.4 Update: Might not exist
                                if ( isset($item->key) )
                                    $tags[$item->key] = "";

                                if ( property_exists($data[$id], 'data')
                                        && property_exists($data[$id]->data, 'tags') )
                                {
                                    $tags[$item->key] = $data[$id]->data->tags;
                                    unset($data[$id]->data->tags);
                                }
                            }

                            $json = wp_json_encode($data);
                            $tags = wp_json_encode($tags);
                            // $json = json_encode($data);
                            // $tags = json_encode($tags);

                            $wpdb->query(
                                $wpdb->prepare(
                                "
                                INSERT INTO ".$wpdb->prefix."zotpress_cache
                                ( request_id, api_user_id, json, tags, headers, libver, retrieved )
                                VALUES ( %s, %s, %s, %s, %s, %d, %s )
                                ON DUPLICATE KEY UPDATE
                                json = VALUES(json),
                                tags = VALUES(tags),
                                headers = VALUES(headers),
                                libver = VALUES(libver),
                                retrieved = VALUES(retrieved)
                                ",
                                array
                                (
                                    md5( $cache_url ),
                                    $this->api_user_id,
                                    gzencode($json),
                                    gzencode($tags), // 7.1.4: separated from $data
                                    $headers,
                                    $response["headers"]["last-modified-version"],
                                    gmdate('m/d/Y h:i:s a')
                                ))
                            );
                        }

                        else // assume 'ris'
                        {
                            // REVIEW: Eventually cache?
                            // NOTE: $data is everything / the RIS
                            $json = $data;
                            $tags = false;
                            // $headers = $response["headers"];
                        }
                    }

                    else 
                    {
                        // If not an item, e.g., if attachment, PDF, etc.
                        $json = $data;
                        $tags = false;
                        $headers = $response["headers"];
                    }
                }

                // Retrieve cached version
                else
                {
                    // Reset retrieved datetime:
                    $wpdb->query(
                        $wpdb->prepare(
                        "
                        INSERT INTO ".$wpdb->prefix."zotpress_cache
                        ( request_id, api_user_id, retrieved )
                        VALUES ( %s, %s, %s )
                        ON DUPLICATE KEY UPDATE
                        retrieved = VALUES(retrieved)
                        ",
                        array
                        (
                            md5( $cache_url ),
                            $this->api_user_id,
                            gmdate('m/d/Y h:i:s a')
                        ))
                    );

                    $json = $this->zotpress_gzdecode($zp_results[0]->json);
                    $tags = $this->zotpress_gzdecode($zp_results[0]->tags);
                    $headers = $zp_results[0]->headers;
                }
            }

            $wpdb->flush();

            return array( "data" => $json, "tags" => $tags, "headers" => $headers );
        }
    }
}

?>