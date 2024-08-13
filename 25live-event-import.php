<?php
/*
Plugin Name:  25Live Event Import
Plugin URI:   https://jpederson.com
Description:  A small plugin that lets you set a 25Live feed URL, and it creates a wpcron job to automatically import events on a schedule.
Version:      1.0
Author:       James Pederson
Author URI:   https://jpederson.com
License:      GPL2
License URI:  https://www.gnu.org/licenses/gpl-2.0.html
Text Domain:  25live
*/

// set up a global to enable/disable debug that just shows a preview of the first record in the file so you can map values.
global $events_import_debug;
$events_import_debug = false;


// start cron on plugin activation
register_activation_hook( __FILE__, 'start_25live_cron' );


// activation hook for 25live
function start_25live_cron() {
    if ( !wp_next_scheduled( 'import_25live' ) ) {
        wp_schedule_event(time(), 'hourly', 'import_25live');
    }
}


// register an action that does the actual import
add_action( 'import_25live', 'do_25live_import' );


// process the custom fields into a simpler array
function process_25live_custom_fields( $custom_fields ) {

    // empty fields output
    $fields_output = array();

    // if we have custom fields passed in
    if ( !empty( $custom_fields ) ) {

        // loop through the custom fields for this event
        foreach ( $custom_fields as $cf ) {

            // generate an array key from the field label
            $key = sanitize_title( $cf->label );

            // load it into the array
            $fields_output[ $key ] = $cf->value;

        }

    }

    // return the custom fields array
    return $fields_output;

}


// the import function
function do_25live_import() {
    
    // let's get $wpdb ready to use
    global $wpdb, $events_import_debug;

    // get the plugin options
    // check and see if the functionality is enabled
    $events_import_enable = get_field( '25live_enable', 'option' );
    if ( empty( $events_import_enable ) ) $events_import_enable = true;

    // get the feed url setting
    $events_json_url = get_field( '25live_feed_url', 'option' );
    if ( empty( $events_json_url ) ) $events_json_url = 'https://25livepub.collegenet.com/calendars/events-for-albion-college-website.json';

    // if the import feature is enabled
    if ( $events_import_enable ) {
        
        // pull events feed
        $events_raw = file_get_contents( $events_json_url );

        // parse the json
        $events = json_decode( $events_raw );

        // if debug global is set to 'dump', we'll dump the full feed from 25Live
        // so we can see the data structure. this helps with mapping new fields
        // to their wp_postmeta values
        if ( $events_import_debug == 'dump' ) {
            print "<pre>";
            print_r( $events );
            print "</pre>";
            die;
        }

        // if we have events
        if ( !empty( $events ) ) {

            // start looping through them
            foreach ( $events as $event ) {

                // if debug is set to log
                if ( $events_import_debug == 'log' ) print "<pre>";

                // process the custom fields
                $event->fields = process_25live_custom_fields( $event->customFields );
                if ( $events_import_debug == 'log' ) print_r( $event->fields );

                // get a previous post if it exists.
                $previous_post = $wpdb->get_results( "SELECT * FROM `wp_postmeta` WHERE `meta_key`='_p_event_external_id' AND `meta_value`='" . $event->eventID . "' LIMIT 1;" );

                // set up an array of the post data
                $post_data = array(
                    'post_author' => 24,
                    'post_title' => $event->title,
                    'post_content' => $event->description,
                    'post_type' => 'tribe_events',
                    'post_status' => 'publish',
                    'comment_status' => 'closed',
                    'ping_status' => 'closed'
                );

                // if we're creating this event
                if ( empty( $previous_post ) ) {

                    // insert it first
                    $post_id = wp_insert_post( $post_data );

                    // and then add the external event id for our new post id
                    add_post_meta( $post_id, '_p_event_external_id', $event->eventID );

                    // log output
                    if ( $events_import_debug == 'log' ) print "Add event: <strong>\"" . $event->title . "\"</strong>\n";

                } else {

                    // since the post exists already, set the id
                    $post_id = $previous_post[0]->post_id;
                    
                    // also add that post ID to the $post_data array so we can use it to update the post
                    $post_data['ID'] = $post_id;

                    // update the post data from the original
                    wp_update_post( $post_data );

                    // log output
                    if ( $events_import_debug == 'log' ) print "Update event: <strong>\"" . $event->title . "\"</strong>\n";

                }

                // format some times
                $start_time = strtotime( $event->startDateTime );
                $start_offset = ( intval( $event->startTimeZoneOffset ) / 100 ) * 3600;
                $end_time = strtotime( $event->endDateTime );
                $end_offset = ( intval( $event->endTimeZoneOffset ) / 100 ) * 3600;
                $start_time_utc = $start_time - $start_offset;
                $end_time_utc = $end_time - $end_offset;

                // set update some event details to postmeta (they'll be added if they don't exist)
                update_post_meta( $post_id, '_p_event_location_text', $event->location );
                update_post_meta( $post_id, '_EventStartDate', date( 'Y-m-d H:i:s', $start_time ) );
                update_post_meta( $post_id, '_EventEndDate',date( 'Y-m-d H:i:s', $start_time ) );
                update_post_meta( $post_id, '_EventStartDateUTC', date( 'Y-m-d H:i:s', $start_time_utc ) );
                update_post_meta( $post_id, '_EventEndDateUTC', date( 'Y-m-d H:i:s', $end_time_utc ) );
                update_post_meta( $post_id, 'Permalink', $event->permaLinkUrl );
                update_post_meta( $post_id, 'Event Action URL', $event->eventActionUrl );

                // if we have a 'categories' custom field.
                if ( !empty( $event->fields['categories'] ) ) {

                    // check if our category exists.
                    $cat_info = term_exists( $event->fields['categories'], 'tribe_events_cat' );

                    // if the category doesn't exist
                    if ( !$cat_info ) {

                        // create the category (returns new category info)
                        $cat_info = wp_insert_term( $event->fields['categories'], 'tribe_events_cat' );
                        if ( $cat_info ) {
                            if ( $events_import_debug == 'log' ) print " - Create event category: " . $event->fields['categories'] . "\n";
                        }

                    }

                    // add our new post to that category
                    if ( wp_set_post_terms( $post_id, $cat_info['term_id'], 'tribe_events_cat', 1 ) ) {
                        if ( $events_import_debug == 'log' ) print " - Add event to category: " . $event->fields['categories'] . "\n";
                    }
                    
                } // end if have category

                // custom field loop
                foreach ( $event->fields as $event_cf_key => $event_cf_value ) {

                    // if it's not the category, just add it as a custom field so we have the info in the event.
                    update_post_meta( $post_id, $event_cf_key, $event_cf_value );

                }

                // log output
                if ( $events_import_debug == 'log' ) print "<pre>";

            } // end event loop

        } // end if we have events
    
    } // end if enabled

}


// admin interface fields in ACF
add_action( 'acf/include_fields', function() {
	if ( ! function_exists( 'acf_add_local_field_group' ) ) {
		return;
	}

	acf_add_local_field_group( array(
        'key' => '25_live_events_import_options',
        'title' => '25Live Event Import Settings',
        'fields' => array(
            array(
                'key' => 'field_6571ea9243c93',
                'label' => 'About this Plugin',
                'name' => '',
                'aria-label' => '',
                'type' => 'message',
                'instructions' => '',
                'required' => 0,
                'conditional_logic' => 0,
                'wrapper' => array(
                    'width' => '60',
                    'class' => '',
                    'id' => '',
                ),
                'message' => 'This plugin imports events from 25Live into The Events Calendar plugin. It allows you to set a 25Live JSON feed URL, and the plugin handles the rest by scheduling a cron job (which runs every hour) and imports the events.

    To adjust which events are brought into the site, change the feed URL below. The events are brought in every hour using WordPress\' built-in cron functionality.',
                'new_lines' => 'wpautop',
                'esc_html' => 0,
            ),
            array(
                'key' => 'field_6571ff97106eb',
                'label' => 'Activation',
                'name' => '25live_enable',
                'aria-label' => '',
                'type' => 'true_false',
                'instructions' => 'Enable or disable this functionality using the toggle below.',
                'required' => 0,
                'conditional_logic' => 0,
                'wrapper' => array(
                    'width' => '40',
                    'class' => '',
                    'id' => '',
                ),
                'message' => '',
                'default_value' => 0,
                'ui_on_text' => 'Enabled',
                'ui_off_text' => 'Disabled',
                'ui' => 1,
            ),
            array(
                'key' => 'field_6571ea10cb1f1',
                'label' => '25Live Feed URL',
                'name' => '25live_feed_url',
                'aria-label' => '',
                'type' => 'text',
                'instructions' => '',
                'required' => 0,
                'conditional_logic' => 0,
                'wrapper' => array(
                    'width' => '60',
                    'class' => '',
                    'id' => '',
                ),
                'default_value' => '',
                'maxlength' => '',
                'placeholder' => '',
                'prepend' => '',
                'append' => '',
            ),
            array(
                'key' => 'field_657200b23a79f',
                'label' => 'Need Help?',
                'name' => '',
                'aria-label' => '',
                'type' => 'message',
                'instructions' => '',
                'required' => 0,
                'conditional_logic' => 0,
                'wrapper' => array(
                    'width' => '',
                    'class' => '',
                    'id' => '',
                ),
                'message' => 'If you need any help or are having issues with this functionality, feel free to get in touch with James Pederson (<a href="mailto:james@jpederson.com">james@jpederson.com</a>) and he can help troubleshoot.',
                'new_lines' => 'wpautop',
                'esc_html' => 0,
            ),
        ),
        'location' => array(
            array(
                array(
                    'param' => 'options_page',
                    'operator' => '==',
                    'value' => '25live-event-import',
                ),
            ),
        ),
        'menu_order' => 0,
        'position' => 'normal',
        'style' => 'seamless',
        'label_placement' => 'top',
        'instruction_placement' => 'label',
        'hide_on_screen' => '',
        'active' => true,
        'description' => '',
        'show_in_rest' => 0,
    ) );
} );



add_action( 'acf/init', function() {
	acf_add_options_page( array(
        'page_title' => '25Live Event Import',
        'menu_slug' => '25live-event-import',
        'parent_slug' => 'plugins.php',
        'position' => '',
        'redirect' => false,
        'capability' => 'edit_tribe_events',
        'autoload' => true,
    ) );
} );

