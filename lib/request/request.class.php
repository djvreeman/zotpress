<?php if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly 


/**
 *
 *  ZOTPRESS REQUEST CLASS
 *
 *  Based on Sean Huber's CURL library with additions by Mike Purvis.
 *  Checks for updates every 10 minutes or based on cache timer setting.
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
                $check_every_n_mins = 10, // 10 minutes by default
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


        /**
         * Move API keys out of the query string (Zotero-API-Key header is
         * preferred) and keep a stable cache URL without the key.
         */
        function prepare_request_url( $url )
        {
            $parsed_url = parse_url( $url );

            if ( isset( $parsed_url['query'] ) ) {
                parse_str( $parsed_url['query'], $query_params );
                if ( isset( $query_params['key'] ) ) {
                    $this->api_key = $query_params['key'];
                    unset( $query_params['key'] );
                    $new_query = http_build_query( $query_params );
                    $url = $parsed_url['scheme'] . '://' . $parsed_url['host'] . $parsed_url['path'];
                    if ( ! empty( $new_query ) ) {
                        $url .= '?' . $new_query;
                    }
                }
            }

            return $url;
        }


        function get_cache_url( $url )
        {
            return $this->cleaned_url ? $this->cleaned_url : $url;
        }


        function get_zotero_headers( $extra = array() )
        {
            $headers_arr = array_merge( array( "Zotero-API-Version" => "3" ), $extra );

            if ( $this->api_key ) {
                $headers_arr["Zotero-API-Key"] = $this->api_key;
            }

            return $headers_arr;
        }


        function set_request_meta( $url, $update, $request_type = 'item' )
        {
            $this->update = $update;

            if ( $request_type != 'item' )
                $this->request_type = $request_type;

            $url = $this->prepare_request_url( $url );

            // Check for groups first, then users
            $divider = "users/";
            if ( strpos( $url, "groups/" ) !== false ) {
                $divider = "groups/";
                $this->account_type = "groups";
            } elseif ( strpos( $url, "users/" ) !== false ) {
                $this->account_type = "users";
            } else {
                $this->account_type = "users";
            }

            $temp1 = explode( $divider, $url );
            if ( isset( $temp1[1] ) ) {
                $temp2 = explode( "/", $temp1[1] );
                $this->api_user_id = $temp2[0];
            } else {
                $this->api_user_id = false;
            }

            // If the URL had no key, look it up from the saved account
            if ( ! $this->api_key && $this->api_user_id ) {
                global $wpdb;
                $zp_account = zotpress_get_account( $wpdb, $this->api_user_id );
                if ( count( $zp_account ) > 0 && ! empty( $zp_account[0]->public_key ) ) {
                    $this->api_key = $zp_account[0]->public_key;
                }
            }

            $this->cleaned_url = $url;
        }


        // NOTE: used by shortcode.request.php
        function get_request_cache( $url, $update, $request_type = 'item' )
        {
            $this->set_request_meta( $url, $update, $request_type );

            $data = $this->check_and_get_cache( $this->get_cache_url( $url ) );

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

            $data = $this->getRegular( $wpdb, $this->get_cache_url( $url ) );
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

            $json = false;
            $tags = false;
            $headers = false;

            // NEW in 7.3.6: First, check the cache:
            $data = $this->check_and_get_cache( $this->get_cache_url( $url ) );
            $data_json = ( isset( $data["json"] ) ) ? json_decode( $data["json"] ) : null;

            // Only try to update if time has passed:
            $updateneeded = isset( $data["updateneeded"] ) ? $data["updateneeded"] : false;

            // Then, proceed without cache if none exists;
            // if no cache, then array returned:
            if ( ! is_array($data_json) )
            // if ( property_exists($data_json, "status")
            //         && $data_json->status == "No Cache" )
            {
                $data = $this->get_xml_data( $this->get_cache_url( $url ), $updateneeded );
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

            // 7.4.4: Added cache timer
            if ( get_option("Zotpress_DefaultCacheTimer")
                    && is_int( get_option("Zotpress_DefaultCacheTimer") )
                    && get_option("Zotpress_DefaultCacheTimer") >= 10 )
                $this->check_every_n_mins = get_option("Zotpress_DefaultCacheTimer");

            if ( $timeElapsedMin > $this->check_every_n_mins )
                return true;
            else // Not time yet
                return false;
        }


        function check_and_get_cache( $url )
        {
            global $wpdb;

            $cache_url = $this->get_cache_url( $url );

            // First, check db to see if cached version exists
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

            $json = false;
            $tags = false;
            $headers = false;
            $cache_url = $this->get_cache_url( $url );

            // Just want to check for cached version
            if ( $this->update === false )
            {
                // First, check db to see if cached version exists
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
                    $regular = $this->getRegular( $wpdb, $cache_url );

                    $json = $regular['data'];
                    $tags = $regular['tags'];
                    $headers = $regular['headers'];
                }

                $wpdb->flush();
            }

            else // Normal or RIS
            {
                $regular = $this->getRegular( $wpdb, $cache_url );

                $json = $regular['data'];
                $tags = $regular['tags'];
                $headers = $regular['headers'];
            }

            return array( "json" => $json, "tags" => $tags, "headers" => $headers, "updateneeded" => $updateneeded );
        }


        function getRegular( $wpdb, $url )
        {
            global $wpdb;

            $json = false;
            $tags = false;
            $headers = false;
            $cache_url = $this->get_cache_url( $url );

            // First, check db to see if cached version exists
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
            if ( count($zp_results) == 0
                    || ( isset($zp_results[0]->retrieved)
                            && $this->check_time($zp_results[0]->retrieved) ) )
            {
                $headers_arr = $this->get_zotero_headers();

                if ( count($zp_results) > 0 )
                    $headers_arr["If-Modified-Since-Version"] = $zp_results[0]->libver;

                // Get response
                $response = wp_remote_get( $cache_url, array ( 'headers' => $headers_arr ) );

                if ( is_wp_error($response) )
                    $this->request_error = $response->get_error_message();
                // 7.3.13: No collection and tag error reporting:
                else if ( $response["body"] == "Collection not found"
                        || $response["body"] == "Tag not found" )
                    $this->request_error = $response["body"];
                else
                    // $headers = json_encode( wp_remote_retrieve_headers( $response )->getAll() );
                    $headers = wp_json_encode( wp_remote_retrieve_headers( $response )->getAll() );
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
                            $response = wp_remote_get( $cache_url, array( 'headers' => $this->get_zotero_headers( array( "Zotero-API-Version" => "2" ) ) ) );

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
                        $url_break = explode("/", $cache_url);
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
                                if ( isset( $data[$id]->data ) ) {
                                    if ( isset( $data[$id]->data->title ) && is_string( $data[$id]->data->title ) ) {
                                        $data[$id]->data->title = zotpress_sanitize_special_chars( $data[$id]->data->title, 'title' );
                                    }
                                    foreach ( array( 'abstractNote', 'shortTitle', 'publicationTitle', 'seriesTitle', 'websiteTitle' ) as $field ) {
                                        if ( isset( $data[$id]->data->$field ) && is_string( $data[$id]->data->$field ) ) {
                                            $data[$id]->data->$field = zotpress_sanitize_special_chars( $data[$id]->data->$field, 'title' );
                                        }
                                    }
                                }

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
                                {
                                    $tags[$item->key] = "";

                                    if ( property_exists($data[$id], 'data')
                                            && property_exists($data[$id]->data, 'tags') )
                                    {
                                        $tags[$item->key] = zotpress_sanitize_special_chars( $data[$id]->data->tags, 'tag' );
                                        unset($data[$id]->data->tags);
                                    }
                                }
                            }

                            $json = zotpress_json_encode($data);
                            $tags_json = zotpress_json_encode($tags);

                            $json_compressed = @gzencode($json, 9);
                            $tags_compressed = @gzencode($tags_json, 9);
                            if ( $json_compressed === false ) $json_compressed = $json;
                            if ( $tags_compressed === false ) $tags_compressed = $tags_json;

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
                                    $json_compressed,
                                    $tags_compressed, // 7.1.4: separated from $data
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
