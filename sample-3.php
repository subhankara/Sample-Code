<?php
    ///////////////////////////////////////////////////////////////////////////////////////
    //////////////////         ACF Code Example                          //////////////////
    ///////////////////////////////////////////////////////////////////////////////////////

    /**
     * Retrieves the review data from a repeater field and returns it as an associative array.
     *
     * @return array An associative array containing the rating source as the key, and 
     *               an array with rating and review count as values.
     */
    function __get_reviews_manual_count() {
        // Initialize the response array to hold the review data
        $review_data = array();

        // Check if there are rows in the 'total_review_rating' repeater field
        if (have_rows('total_review_rating')) {
            // Loop through each row in the 'total_review_rating' repeater field
            while (have_rows('total_review_rating')) {
                the_row(); // Move to the next row

                // Get the values of sub-fields for each review
                $rating_source = get_sub_field('rating_source');
                $total_rating = get_sub_field('total_rating');
                $total_reviews = get_sub_field('total_reviews');

                // Ensure the rating source is not empty before adding to the array
                if ($rating_source) {
                    // Populate the review data array with the source, rating, and review count
                    $review_data[$rating_source] = array(
                        'rating' => floatval($total_rating), // Convert the total rating to float for consistency
                        'review' => intval($total_reviews) // Convert the total reviews count to integer for consistency
                    );
                }
            }
        }

        // Return the populated array with review data
        return $review_data;
    }

    /**
     * Custom search query function to modify the search query based on URL parameters.
     *
     * @param WP_Query $query The WP_Query object being modified.
     */
    function custom_search_query($query) {
        // Ensure that this only runs for the main query on the frontend search page
        if (!is_admin() && $query->is_main_query() && $query->is_search()) {
            // Initialize meta_query and tax_query to store filters
            $meta_query = array('relation' => 'AND');
            $tax_query = array('relation' => 'AND');

            // Filter by 'tour_code' ACF field if provided in URL
            if (!empty($_GET['tour_code'])) {
                $meta_query[] = array(
                    'key'     => 'internal_tour_code', // ACF relationship field key
                    'value'   => sanitize_text_field($_GET['tour_code']),
                    'compare' => 'LIKE'
                );
            }

            // Filter by 'destination_id' if provided (multiple destinations are handled)
            if (!empty($_GET['destination_id'])) {
                $destination_ids = explode('-and-', sanitize_text_field($_GET['destination_id']));
                $destination_meta_query = array('relation' => 'AND');

                // Loop through each destination ID and add to the meta_query
                foreach ($destination_ids as $id) {
                    $destination_meta_query[] = array(
                        'key'     => 'destination_copy', // ACF relationship field key
                        'value'   => '"' . intval($id) . '"', // Ensure correct formatting for relationship fields
                        'compare' => 'LIKE'
                    );
                }

                $meta_query[] = $destination_meta_query;
            }
            // Alternatively, filter by 'destination_name' if provided
            elseif (!empty($_GET['destination_name'])) {
                global $wpdb;
                $destination_name = sanitize_text_field($_GET['destination_name']);

                // Query posts with title matching destination_name
                $destination_ids = $wpdb->get_col($wpdb->prepare("
                    SELECT p.ID
                    FROM {$wpdb->posts} p
                    WHERE p.post_title LIKE %s
                    AND p.post_type = 'destination'
                    AND p.post_status = 'publish'
                ", '%' . $wpdb->esc_like($destination_name) . '%'));

                // If matching destinations found, add them to the meta query
                if (!empty($destination_ids)) {
                    $destination_meta_query = array('relation' => 'OR');
                    foreach ($destination_ids as $id) {
                        $destination_meta_query[] = array(
                            'key'     => 'destination_copy', // ACF relationship field key
                            'value'   => '"' . intval($id) . '"',
                            'compare' => 'LIKE'
                        );
                    }
                    $meta_query[] = $destination_meta_query;
                }
            }

            // Filter by 'destination_start' (starting locations) if provided
            if (!empty($_GET['destination_start'])) {
                $destination_start = sanitize_text_field($_GET['destination_start']);
                $start_locations = explode('-or-', $destination_start);

                $start_meta_query = array('relation' => 'OR');

                // Loop through each start location and add to the meta_query
                foreach ($start_locations as $location) {
                    $start_meta_query[] = array(
                        'key'     => 'destination_start', // ACF field for starting location
                        'value'   => $location,
                        'compare' => 'LIKE'
                    );
                }

                $meta_query[] = $start_meta_query;
            }

            // Filter by 'destination_end' (ending locations) if provided
            if (!empty($_GET['destination_end'])) {
                $destination_end = sanitize_text_field($_GET['destination_end']);
                $end_locations = explode('-or-', $destination_end);

                $end_meta_query = array('relation' => 'OR');

                // Loop through each end location and add to the meta_query
                foreach ($end_locations as $location) {
                    $end_meta_query[] = array(
                        'key'     => 'destination_end', // ACF field for ending location
                        'value'   => $location,
                        'compare' => 'LIKE'
                    );
                }

                $meta_query[] = $end_meta_query;
            }

            // Filter by days range ('min_days' and 'max_days') if provided
            if (isset($_GET['min_days']) && isset($_GET['max_days'])) {
                $min_days = intval($_GET['min_days']);
                $max_days = intval($_GET['max_days']);

                // Ensure the days range is valid before adding to the meta_query
                if ($min_days >= 0 && $max_days >= 0 && $min_days <= $max_days) {
                    $meta_query[] = array(
                        'key'     => 'days',
                        'value'   => array($min_days, $max_days),
                        'type'    => 'NUMERIC',
                        'compare' => 'BETWEEN'
                    );
                } else {
                    error_log('Invalid days range: ' . print_r($_GET, true));
                }
            }

            // Filter by 'tour_style' (e.g., Camping, Accommodated) if provided
            if (!empty($_GET['tour_style'])) {
                $tour_style = sanitize_text_field($_GET['tour_style']);
                $tour_style_tax_query = array('relation' => 'OR');

                // Add taxonomy condition for 'Camping Tours' or 'Accommodated Tours'
                if ($tour_style == 'Camping' || $tour_style == 'Accommodated') {
                    $tour_style_tax_query[] = array(
                        'taxonomy' => 'tour-type', // Custom taxonomy for tour type
                        'field'    => 'slug',
                        'terms'    => $tour_style,
                    );
                }

                // Add ACF field condition for 'Small Group Tours' or 'Private Tours'
                if ($tour_style == 'Small group' || $tour_style == 'Private tour') {
                    $meta_query[] = array(
                        'key'     => 'group', // ACF field for group size/type
                        'value'   => $tour_style,
                        'compare' => '=', // Exact match
                    );
                }

                $tax_query[] = $tour_style_tax_query;
            }

            // Filter by selected 'experiences' if provided (e.g., malaria_free, family_friendly)
            if (!empty($_GET['experiences'])) {
                $experiences = array_map('sanitize_text_field', $_GET['experiences']);
                foreach ($experiences as $experience) {
                    $meta_query[] = array(
                        'key'     => $experience, // ACF field name (experience criteria)
                        'value'   => '1',         // Value you're checking for (1 means checked)
                        'compare' => '=',         // Match the value exactly
                    );
                }
            }

            // Apply the meta query to the search query if it has more than one condition
            if (count($meta_query) > 1) {
                $query->set('meta_query', $meta_query);
            }

            // Apply the tax query to the search query if it has more than one condition
            if (count($tax_query) > 1) {
                $query->set('tax_query', $tax_query);
            }

            // Handle sorting based on URL parameters 'sort_by' and 'order'
            $sort_by = !empty($_GET['sort_by']) ? sanitize_text_field($_GET['sort_by']) : 'days';
            $order = !empty($_GET['order']) ? sanitize_text_field($_GET['order']) : 'ASC';

            // Set the custom sorting and order for the query
            $query->set('meta_key', $sort_by);
            $query->set('orderby', 'meta_value_num'); // Ensure numeric sorting
            $query->set('order', strtoupper($order) == 'DESC' ? 'DESC' : 'ASC');
        }
    }

?>