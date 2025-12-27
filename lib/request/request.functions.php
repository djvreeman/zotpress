<?php if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Comprehensive sanitization function for titles and tags with special characters
 * Handles quotes, brackets, question marks, and other problematic characters
 * 
 * This is a fallback if the function isn't already defined in shortcode.functions.php
 * 
 * @param mixed $data The data to sanitize (string, array, or object)
 * @param string $type The type of data: 'title', 'tag', or 'general'
 * @return mixed Sanitized data in the same format as input
 */
if ( ! function_exists( 'zotpress_sanitize_special_chars' ) ) {
	function zotpress_sanitize_special_chars( $data, $type = 'general' ) {
		try {
			// Handle arrays
			if ( is_array( $data ) ) {
				$sanitized = array();
				foreach ( $data as $key => $value ) {
					$sanitized[$key] = zotpress_sanitize_special_chars( $value, $type );
				}
				return $sanitized;
			}
			
			// Handle objects
			if ( is_object( $data ) ) {
				$sanitized = new stdClass();
				foreach ( get_object_vars( $data ) as $key => $value ) {
					$sanitized->$key = zotpress_sanitize_special_chars( $value, $type );
				}
				return $sanitized;
			}
			
			// Handle strings
			if ( ! is_string( $data ) ) {
				return $data;
			}
			
			// Ensure UTF-8 encoding
			$data = mb_convert_encoding( $data, 'UTF-8', 'UTF-8' );
			
			// Remove null bytes and other control characters that can break JSON
			$data = str_replace( "\0", '', $data );
			$data = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $data );
			
			// Normalize line breaks
			$data = str_replace( array( "\r\n", "\r" ), "\n", $data );
			
			// For titles and tags, handle special characters more carefully
			if ( $type === 'title' || $type === 'tag' ) {
				// Preserve valid UTF-8 characters but ensure proper encoding
				$data = mb_convert_encoding( $data, 'UTF-8', 'UTF-8' );
				
				// Ensure the string is valid UTF-8
				if ( ! mb_check_encoding( $data, 'UTF-8' ) ) {
					$data = mb_convert_encoding( $data, 'UTF-8', 'UTF-8' );
				}
				
				// Remove any remaining invalid UTF-8 sequences
				$data = mb_convert_encoding( $data, 'UTF-8', 'UTF-8' );
				
				// Remove any characters that could break JSON encoding
				// This includes control characters but preserves printable characters including brackets, quotes, etc.
				$data = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $data );
			}
			
			return $data;
			
		} catch ( Exception $e ) {
			error_log( "Zotpress: Error in zotpress_sanitize_special_chars: " . $e->getMessage() );
			// Return safe fallback
			if ( is_string( $data ) ) {
				return mb_convert_encoding( $data, 'UTF-8', 'UTF-8' );
			}
			return $data;
		}
	}
} 


    // +---------------------------------+
    // | ZOTPRESS BASIC IMPORT FUNCTIONS |
    // +---------------------------------+

    if ( ! function_exists('zotpress_db_prep') )
    {
        function zotpress_db_prep ($input)
        {
            return (str_replace("%", "%%", $input));
        }
    }


    if ( ! function_exists('zotpress_extract_year') )
    {
        function zotpress_extract_year ($date)
        {
    		if ( strlen($date) > 0 ):
    			preg_match_all( '/(\d{4})/', $date, $matches );
    			if ( isset($matches[0][0]) ):
    				return $matches[0][0];
    			else:
    				return "";
    			endif;
    		else:
    			return "";
    		endif;
        }
    }


    if ( ! function_exists('zotpress_get_api_user_id') )
    {
        function zotpress_get_api_user_id ($api_user_id_incoming=false)
        {
            if (isset($_GET['api_user_id']) 
                    && preg_match("/^\\d+\$/", sanitize_text_field(wp_unslash($_GET['api_user_id']))) == 1) {
                $api_user_id = htmlentities(sanitize_text_field(wp_unslash($_GET['api_user_id'])));
            } elseif ($api_user_id_incoming !== false) {
                $api_user_id = $api_user_id_incoming;
            } else
                $api_user_id = false;

            return $api_user_id;
        }
    }


    if ( ! function_exists('zotpress_get_account') )
    {
        function zotpress_get_account ($wpdb, $api_user_id_incoming=false)
        {
            if ( $api_user_id_incoming !== false )
    		{
                $zp_account = $wpdb->get_results(
                    $wpdb->prepare(
                        "
                        SELECT * FROM `".$wpdb->prefix."zotpress` 
                        WHERE `api_user_id`=%s
                        ",
                        $api_user_id_incoming
                    )
                );
    		}
            else
    		{
                $zp_account = $wpdb->get_results(
                    "
                    SELECT * FROM `".$wpdb->prefix."zotpress` 
                    ORDER BY `id` DESC LIMIT 1
                    "
                );
    		}

            return $zp_account;
        }
    }



    if ( ! function_exists('zotpress_clear_cache_for_user') )
    {
        function zotpress_clear_cache_for_user ($wpdb, $api_user_id)
        {
            // $wpdb->query("DELETE FROM ".$wpdb->prefix."zotpress_cache WHERE api_user_id='".$api_user_id."'");
            $wpdb->query(
                $wpdb->prepare(
                    "
                    DELETE FROM `".$wpdb->prefix."zotpress_cache` 
                    WHERE `api_user_id`=%s
                    ",
                    array( $api_user_id )
                )
            );
        }
    }


    if ( ! function_exists('zotpress_check_author_continue') )
    {
    	// Takes single author
    	function zotpress_check_author_continue( $item, $author )
    	{
    		$author_continue = false;
    		$author = strtolower($author);

    		// Accounts for last names with: de, van, el, seif
    		if ( stripos( $author, "van " ) !== false ) {
                  $author = explode( "van ", $author );
                  $author[1] = "van ".$author[1];
              } elseif ( stripos( $author, "de " ) !== false ) {
                  $author = explode( "de ", $author );
                  $author[1] = "de ".$author[1];
              } elseif ( stripos( $author, "el " ) !== false ) {
                  $author = explode( "el ", $author );
                  $author[1] = "el ".$author[1];
              } elseif ( stripos( $author, "seif " ) !== false ) {
                  $author = explode( "seif ", $author );
                  $author[1] = "seif ".$author[1];
              } elseif ( stripos( $author, " " ) !== false ) {
                  $author = explode( " ", $author );
                  // Deal with multiple blanks
                  // NOTE: Previously assumed multiple first/middle names
                  // CHANGED: Check this possibility as well as multiple (7.3)
                  // last names; so keep array of 1-3+ items
                  // if ( count($author) > 2 )
                  // {
                  // 	$new_name = array();
                  // 	foreach ( $author as $num => $author_name )
                  // 	{
                  // 		if ( $num == 0 ) $new_name[0] .= $author_name;
                  // 		else if ( $num != count($author)-1 ) $new_name[0] .= " ". $author_name;
                  // 		else if ( $num == count($author)-1 ) $new_name[1] .= $author_name;
                  // 	}
                  // 	$author = $new_name;
                  // }
              } elseif ( stripos( $author, "+" ) !== false ) {
                  // $author = explode( "+", $author );
                  $author = array( str_replace( "+", " ", $author ) );
              } else // Just last name
              {
                  $author = array( $author );
              }

    		// Deal with blank firstname
    		if ( $author[0] == "" )
    		{
    			$author[0] = $author[1];
    			unset( $author[1] );
    		}

    		// Trim firstname
            // QUESTION: Is this needed?
    		$author[0] = trim($author[0]);

    		// Check
    		foreach ( $item->data->creators as $creator )
    		{
                // NOTE: Assumes last name only
    			if ( count($author) == 1 )
    			{
    				if ( ( property_exists($creator, 'lastName') && $creator->lastName !== null
                            && strtolower($creator->lastName) === $author[0] )
    						|| ( property_exists($creator, 'name') && $creator->name !== null
                                    && strtolower($creator->name) === $author[0] ) )
    					$author_continue = true;
    			}

                // NOTE: Assumes first and last names OR two last names
    			elseif ( count($author) == 2 )
    			{
                    if ( ( property_exists($creator, 'firstName') && $creator->firstName !== null
						&& ( strtolower($creator->firstName) === $author[0]
						&& strtolower($creator->lastName) === $author[1] )
                       )
                       || ( strtolower($creator->lastName) === $author[0]." ".$author[1] )
                       || ( property_exists($creator, 'name') && $creator->name !== null
                            && ( strtolower($creator->name) === implode(" ", $author) ) ) )
                            $author_continue = true;
    			}

                elseif (( property_exists($creator, 'firstName') && $creator->firstName !== null
                        && ( strtolower($creator->firstName) === $author[0]." ".$author[1]
                                && strtolower($creator->lastName) === $author[2] ) )
                        // One first name and two last names
                        || ( property_exists($creator, 'firstName') && $creator->firstName !== null
                                && ( strtolower($creator->firstName) === $author[0]
                                        && strtolower($creator->lastName) === $author[1]." ".$author[2] ) )
                        // All combined
                        || ( property_exists($creator, 'name') && $creator->name !== null
                                && strtolower($creator->name) === implode(" ", $author) ))
                {
                    $author_continue = true;
                }
            }

    		return $author_continue;

    	} // function zotpress_check_author_continue
    }

?>