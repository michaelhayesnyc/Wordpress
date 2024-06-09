<?php
/**
 * Plugin Name: NicheSiteDirectory
 * Description: Manage "Heading" and "Company" Custom Post Types and their relationships.  Requires Advanced Custom Fields.
 * Version: 1.0
 * Author: Michael Hayes
 */

#Custom REST Endpoint

add_action('rest_api_init', function () {
    register_rest_route('nichesitedirectory/v1', '/update-relationship/', array(
        'methods' => 'POST',
        'callback' => 'nichesitedirectory_update_relationship',
        'permission_callback' => function () {
            return current_user_can('edit_posts');
        }
    ));
});

function nichesitedirectory_update_relationship($request) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'company_heading_relationships';

	$company_post_id = sanitize_text_field($request['company_post_id']);
    $heading_post_id = sanitize_text_field($request['heading_post_id']);
    $company_id = sanitize_text_field($request['company_id']);
    $heading_id = sanitize_text_field($request['heading_id']);
    $ranking = intval($request['ranking']);

    // Check if the relationship already exists
    $existing = $wpdb->get_row($wpdb->prepare(
        "SELECT id FROM $table_name WHERE company_post_id = %d AND heading_post_id = %d",
        $company_post_id, $heading_post_id
    ));

    // Prepare the data for insertion or update
    $data = array(
        'company_post_id' => $company_post_id,
        'heading_post_id' => $heading_post_id,
        'company_id' => $company_id,
        'heading_id' => $heading_id,
        'ranking' => $ranking
    );
    $format = array('%d', '%d', '%s', '%s', '%d'); // Define formats for each field

    if ($existing) {
        // If the relationship exists, update it
        $where = array('id' => $existing->id);
        $updated = $wpdb->update($table_name, $data, $where, $format, array('%d'));
        if (false === $updated) {
            return new WP_Error('db_update_error', 'Failed to update relationship', array('status' => 500));
        }
    } else {
        // If the relationship does not exist, insert it
        $inserted = $wpdb->insert($table_name, $data, $format);
        if (false === $inserted) {
            return new WP_Error('db_insert_error', 'Failed to insert new relationship', array('status' => 500));
        }
    }

    return new WP_REST_Response(array('message' => 'Relationship updated successfully'), 200);
}

#Plugin Activation

function nichesitedirectory_activate() {
    // Code to create the table if it doesn't exist
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    $table_name = $wpdb->prefix . 'company_heading_relationships';

    $sql = "CREATE TABLE $table_name (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        company_post_id bigint(20) NOT NULL,
        heading_post_id bigint(20) NOT NULL,
        company_id varchar(255) NOT NULL,
        heading_id varchar(255) NOT NULL,
        ranking int(11) NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY unique_relationship (company_post_id, heading_post_id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

register_activation_hook(__FILE__, 'nichesitedirectory_activate');


function nichesitedirectory_import_acf_relationships() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'company_heading_relationships';

    $company_posts = get_posts(['post_type' => 'company', 'numberposts' => -1]);
    
    foreach ($company_posts as $company_post) {
        $headings = get_field('headings', $company_post->ID);
        if ($headings) {
            foreach ($headings as $heading) {
                
                nichesitedirectory_process_relationship($company_post->ID, $heading->ID);
            }
        }
    }
}


function nichesitedirectory_process_relationship($company_post_id, $heading_post_id) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'company_heading_relationships';
	
	// Retrieve the ACF field values for company_id and heading_id
    $company_id = get_field('company_id', $company_post_id);
    $heading_id = get_field('heading_id', $heading_post_id);

    // Ensure you have valid values; otherwise, set them to a default or handle accordingly
    $company_id = !empty($company_id) ? $company_id : '';
    $heading_id = !empty($heading_id) ? $heading_id : '';
	
    // Check if the relationship already exists
    $existing = $wpdb->get_row($wpdb->prepare(
        "SELECT id FROM $table_name WHERE company_post_id = %d AND heading_post_id = %d",
        $company_post_id, $heading_post_id
    ));

    if ($existing) {
        // Relationship exists. Update it if necessary.
        // This example doesn't have a specific update logic, but if you have additional data to update, you can do it here.
        // For instance, if you were tracking updates, you might increment a counter or update a timestamp.
    } else {
        // No existing relationship, insert a new record.
        $wpdb->insert(
            $table_name,
            array(
                'company_post_id' => $company_post_id,
                'heading_post_id' => $heading_post_id,
                // Assuming these are also part of your data model:
                'company_id' => $company_id,
                'heading_id' => $heading_id,
                'ranking' => 0 // Default ranking, adjust as necessary
            ),
            array('%d', '%d', '%s', '%s', '%d') // Data types: %d for integer, %s for string
        );
    }

    // Handle errors (optional)
    if ($wpdb->last_error) {
        // Log error or take other actions
        error_log('Error in nichesitedirectory_process_relationship: ' . $wpdb->last_error);
    }
}


add_action('save_post', 'nichesitedirectory_process_relationships_on_post_save', 10, 2);

function nichesitedirectory_process_relationships_on_post_save($post_id, $post) {
    // Check if this is a save action for a "Heading" or "Company" post type
    if ('heading' === $post->post_type || 'company' === $post->post_type) {
        // Avoid recursion or saving during autosave
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }

        // For "Company" posts, find and process all related "Heading" posts
        if ('company' === $post->post_type) {
            $headings = get_field('headings', $post_id);
            if ($headings) {
                foreach ($headings as $heading_post_id) {
                    // Assuming you have the ACF field 'company_id' directly on the "Company" post
                    $company_id = get_field('company_id', $post_id);
                    // And 'heading_id' on each "Heading" post
                    $heading_id = get_field('heading_id', $heading_post_id);

                    nichesitedirectory_process_relationship($post_id, $heading_post_id, $company_id, $heading_id);
                }
            }
        }

        // Similarly, for "Heading" posts, you'd need to inverse the logic
        // However, this assumes a direct relationship field on "Company" posts pointing to "Headings", which may not be your structure
        // Adjust based on your actual ACF relationship setup
    }
}


function nichesitedirectory_register_admin_page() {
    add_menu_page(
        __('View Relationships', 'text-domain'), // Page title
        __('Relationships', 'text-domain'), // Menu title
        'manage_options', // Capability required to see this option
        'view-relationships', // Menu slug
        'nichesitedirectory_display_relationships_screen', // Function to display the admin page
        'dashicons-networking', // Icon
        6 // Position
    );
}
add_action('admin_menu', 'nichesitedirectory_register_admin_page');

function nichesitedirectory_display_relationships_screen() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'company_heading_relationships';
    
    $relationships = $wpdb->get_results("SELECT * FROM {$table_name}");

    echo '<div class="wrap"><h1>' . esc_html__('Company-Heading Relationships', 'nichesitedirectory') . '</h1>';
    
    if ($relationships) {
        echo '<table class="widefat fixed" cellspacing="0">
        <thead>
            <tr>
                <th>' . __('Company Name', 'nichesitedirectory') . '</th>
                <th>' . __('Heading Name', 'nichesitedirectory') . '</th>
                <th>' . __('Company Thomas ID', 'nichesitedirectory') . '</th>
                <th>' . __('Heading Thomas ID', 'nichesitedirectory') . '</th>
                <th>' . __('Ranking', 'nichesitedirectory') . '</th>
            </tr>
        </thead>
        <tbody>';

        foreach ($relationships as $relationship) {
            $edit_company_url = get_edit_post_link($relationship->company_post_id);
            $edit_heading_url = get_edit_post_link($relationship->heading_post_id);

            $company_name = get_field('company_name', $relationship->company_post_id) ?: 'N/A';
            $heading_name = get_field('heading_name', $relationship->heading_post_id) ?: 'N/A';

            echo '<tr>';
            echo '<td><a href="' . esc_url($edit_company_url) . '">' . esc_html($company_name) . '</a></td>';
            echo '<td><a href="' . esc_url($edit_heading_url) . '">' . esc_html($heading_name) . '</a></td>';
            echo '<td>' . esc_html($relationship->company_id) . '</td>';
            echo '<td>' . esc_html($relationship->heading_id) . '</td>';
            echo '<td>' . esc_html($relationship->ranking) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    } else {
        echo '<p>' . __('No relationships found.', 'nichesitedirectory') . '</p>';
    }

    echo '</div>'; // Close the wrap
}

add_filter('acf/settings/save_json', 'nichesitedirectory_acf_json_save_point');

function nichesitedirectory_acf_json_save_point( $path ) {
    // Update the path to your desired location within your plugin.
    $path = plugin_dir_path(__FILE__) . 'acf-json';

    // Ensure the directory exists. If not, attempt to create it.
    if (!is_dir($path)) {
        mkdir($path, 0755, true);
    }

    return $path;
}

register_activation_hook(__FILE__, 'nichesitedirectory_check_acf_dependency');

function nichesitedirectory_check_acf_dependency() {
    if (!class_exists('ACF')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(__('Nichesite Directory requires Advanced Custom Fields to be installed and active.', 'nichesitedirectory'));
    }
}



function register_company_profile_post_type() {
    $labels = array(
        'name'                  => _x('Company Profiles', 'Post type general name', 'textdomain'),
        'singular_name'         => _x('Company Profile', 'Post type singular name', 'textdomain'),
        'menu_name'             => _x('Company Profiles', 'Admin Menu text', 'textdomain'),
        'name_admin_bar'        => _x('Company Profile', 'Add New on Toolbar', 'textdomain'),
        'add_new'               => __('Add New', 'textdomain'),
        'add_new_item'          => __('Add New Company Profile', 'textdomain'),
        'new_item'              => __('New Company Profile', 'textdomain'),
        'edit_item'             => __('Edit Company Profile', 'textdomain'),
        'view_item'             => __('View Company Profile', 'textdomain'),
        'all_items'             => __('All Company Profiles', 'textdomain'),
        'search_items'          => __('Search Company Profiles', 'textdomain'),
        'parent_item_colon'     => __('Parent Company Profiles:', 'textdomain'),
        'not_found'             => __('No company profiles found.', 'textdomain'),
        'not_found_in_trash'    => __('No company profiles found in Trash.', 'textdomain'),
        'featured_image'        => _x('Company Profile Cover Image', 'Overrides the “Featured Image” phrase for this post type. Added in 4.3', 'textdomain'),
        'set_featured_image'    => _x('Set cover image', 'Overrides the “Set featured image” phrase for this post type. Added in 4.3', 'textdomain'),
        'remove_featured_image' => _x('Remove cover image', 'Overrides the “Remove featured image” phrase for this post type. Added in 4.3', 'textdomain'),
        'use_featured_image'    => _x('Use as cover image', 'Overrides the “Use as featured image” phrase for this post type. Added in 4.3', 'textdomain'),
        'archives'              => _x('Company Profile archives', 'The post type archive label used in nav menus. Default “Post Archives”. Added in 4.4', 'textdomain'),
        'insert_into_item'      => _x('Insert into company profile', 'Overrides the “Insert into post”/”Insert into page” phrase (used when inserting media into a post). Added in 4.4', 'textdomain'),
        'uploaded_to_this_item' => _x('Uploaded to this company profile', 'Overrides the “Uploaded to this post”/”Uploaded to this page” phrase (used when viewing media attached to a post). Added in 4.4', 'textdomain'),
        'filter_items_list'     => _x('Filter company profiles list', 'Screen reader text for the filter links heading on the post type listing screen. Added in 4.4', 'textdomain'),
        'items_list_navigation' => _x('Company Profiles list navigation', 'Screen reader text for the pagination heading on the post type listing screen. Added in 4.4', 'textdomain'),
        'items_list'            => _x('Company Profiles list', 'Screen reader text for the items list heading on the post type listing screen. Added in 4.4', 'textdomain'),
    );

    $args = array(
        'labels'             => $labels,
        'public'             => true,
        'publicly_queryable' => true,
        'show_ui'            => true,
        'show_in_menu'       => true,
        'query_var'          => true,
        'rewrite'            => array('slug' => 'company'),
        'capability_type'    => 'post',
        'has_archive'        => true,
        'hierarchical'       => false,
        'menu_position'      => null,
        'show_in_rest'       => true, // Make it available in the REST API
        'rest_base'          => 'company', // Optional: Set a custom base for REST API routes
        'supports'           => array('title', 'editor', 'author', 'thumbnail', 'excerpt', 'comments'),
    );

    register_post_type('company', $args);
}

add_action('init', 'register_company_profile_post_type');

function register_headings_post_type() {
    $labels = array(
        'name'                  => _x('Headings', 'Post type general name', 'textdomain'),
        'singular_name'         => _x('Heading', 'Post type singular name', 'textdomain'),
        'menu_name'             => _x('Headings', 'Admin Menu text', 'textdomain'),
        'name_admin_bar'        => _x('Heading', 'Add New on Toolbar', 'textdomain'),
        'add_new'               => __('Add New', 'textdomain'),
        'add_new_item'          => __('Add New Heading', 'textdomain'),
        'new_item'              => __('New Heading', 'textdomain'),
        'edit_item'             => __('Edit Heading', 'textdomain'),
        'view_item'             => __('View Heading', 'textdomain'),
        'all_items'             => __('All Headings', 'textdomain'),
        'search_items'          => __('Search Headings', 'textdomain'),
        'parent_item_colon'     => __('Parent Headings:', 'textdomain'),
        'not_found'             => __('No headings found.', 'textdomain'),
        'not_found_in_trash'    => __('No headings found in Trash.', 'textdomain'),
        'featured_image'        => _x('Heading Cover Image', 'Overrides the “Featured Image” phrase for this post type. Added in 4.3', 'textdomain'),
        'set_featured_image'    => _x('Set cover image', 'Overrides the “Set featured image” phrase for this post type. Added in 4.3', 'textdomain'),
        'remove_featured_image' => _x('Remove cover image', 'Overrides the “Remove featured image” phrase for this post type. Added in 4.3', 'textdomain'),
        'use_featured_image'    => _x('Use as cover image', 'Overrides the “Use as featured image” phrase for this post type. Added in 4.3', 'textdomain'),
        'archives'              => _x('Heading archives', 'The post type archive label used in nav menus. Default “Post Archives”. Added in 4.4', 'textdomain'),
        'insert_into_item'      => _x('Insert into heading', 'Overrides the “Insert into post”/”Insert into page” phrase (used when inserting media into a post). Added in 4.4', 'textdomain'),
        'uploaded_to_this_item' => _x('Uploaded to this heading', 'Overrides the “Uploaded to this post”/”Uploaded to this page” phrase (used when viewing media attached to a post). Added in 4.4', 'textdomain'),
        'filter_items_list'     => _x('Filter headings list', 'Screen reader text for the filter links heading on the post type listing screen. Added in 4.4', 'textdomain'),
        'items_list_navigation' => _x('Headings list navigation', 'Screen reader text for the pagination heading on the post type listing screen. Added in 4.4', 'textdomain'),
        'items_list'            => _x('Headings list', 'Screen reader text for the items list heading on the post type listing screen. Added in 4.4', 'textdomain'),
    );

    $args = array(
        'labels'             => $labels,
        'public'             => true,
        'publicly_queryable' => true,
        'show_ui'            => true,
        'show_in_menu'       => true,
        'query_var'          => true,
        'rewrite'            => array('slug' => 'services'),
        'capability_type'    => 'post',
        'has_archive'        => true,
        'hierarchical'       => false,
        'menu_position'      => null,
        'show_in_rest'       => true, // This line makes it available in the REST API
        'rest_base'          => 'services', // Optionally set a custom base for REST API routes
        'supports'           => array('title', 'editor', 'author', 'thumbnail', 'excerpt', 'comments'),
    );

    register_post_type('headings', $args);
}

add_action('init', 'register_headings_post_type');

// Google Maps Embed
// Enqueue Scripts

function nichesitedirectory_enqueue_google_maps() {
    wp_enqueue_script('google-maps', 'https://maps.googleapis.com/maps/api/js?key=API-KEY-HERE', array(), null, true);
}
add_action('wp_enqueue_scripts', 'nichesitedirectory_enqueue_google_maps');


// Define Shortcode

function company_google_maps_shortcode($atts, $content = null) {
    global $post;

    // Try to get ACF fields if lat and long attributes are not provided
    $default_lat = get_field('lat', $post->ID) ?: '0';
    $default_long = get_field('long', $post->ID) ?: '0';

    // Shortcode attributes with dynamic defaults based on ACF fields
    $atts = shortcode_atts(array(
        'lat' => $default_lat, // Default latitude from ACF field or '0'
        'lng' => $default_long, // Default longitude from ACF field or '0'
        'zoom' => '15', // Default zoom level
        'width' => '600', // Default width in pixels
        'height' => '400' // Default height in pixels
    ), $atts);

    $map_id = uniqid('nichesite_map_'); // Unique ID for each map instance

    // Start capturing output
    ob_start();
    ?>
    <div id="<?php echo esc_attr($map_id); ?>" style="width: <?php echo esc_attr($atts['width']); ?>px; height: <?php echo esc_attr($atts['height']); ?>px;"></div>
    <script>
        function initMap<?php echo esc_js($map_id); ?>() {
            var location = {lat: <?php echo esc_js($atts['lat']); ?>, lng: <?php echo esc_js($atts['lng']); ?>};
            var map = new google.maps.Map(document.getElementById('<?php echo esc_js($map_id); ?>'), {
                zoom: parseInt(<?php echo esc_js($atts['zoom']); ?>, 10),
                center: location
            });
            var marker = new google.maps.Marker({
                position: location,
                map: map
            });
        }
        initMap<?php echo esc_js($map_id); ?>();
    </script>
    <?php
    // Return the output buffer content
    return ob_get_clean();
}
add_shortcode('company_map', 'company_google_maps_shortcode');


?>