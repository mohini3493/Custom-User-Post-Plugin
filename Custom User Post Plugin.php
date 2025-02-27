<?php
/*
Plugin Name: Custom User & Post Management
Description: A plugin that implements custom user registration, admin approval, and post management.
Version: 1.0
Author: Mohini
*/

// Register a custom user registration form
function custom_user_registration_form() {
    ob_start();
    ?>
    <form action="" method="post">
        <input type="text" name="username" placeholder="Username" required>
        <input type="email" name="email" placeholder="Email" required>
        <input type="password" name="password" placeholder="Password" required>
        <input type="submit" name="custom_register" value="Register">
    </form>
    <?php
    if (isset($_GET['registration_status'])) {
        if ($_GET['registration_status'] == 'success') {
            echo '<p style="color: green;">Registration successful! Awaiting admin approval.</p>';
        } elseif ($_GET['registration_status'] == 'error') {
            echo '<p style="color: red;">Registration failed. Please try again.</p>';
        }
    }
    return ob_get_clean();
}
add_shortcode('custom_registration', 'custom_user_registration_form');

// Handle user registration
function custom_handle_user_registration() {
    if (isset($_POST['custom_register'])) {
        $username = sanitize_text_field($_POST['username']);
        $email = sanitize_email($_POST['email']);
        $password = $_POST['password'];

        $userdata = array(
            'user_login' => $username,
            'user_email' => $email,
            'user_pass' => $password,
            'role' => 'contributor' // Set role to Contributor
        );

        $user_id = wp_insert_user($userdata);

        if (!is_wp_error($user_id)) {
            update_user_meta($user_id, 'custom_approval_status', 'pending');
            wp_update_user(['ID' => $user_id, 'user_status' => 1]); // Prevent login until approval
            wp_mail(get_option('admin_email'), 'New User Registration', "A new user ($username) has registered. Approve them in the admin panel.");
            wp_redirect(add_query_arg('registration_status', 'success', wp_get_referer()));
            exit;
        } else {
            wp_redirect(add_query_arg('registration_status', 'error', wp_get_referer()));
            exit;
        }
    }
}
add_action('init', 'custom_handle_user_registration');

// Add "Approval Status" column in the user list in admin panel
function custom_add_user_approval_column($columns) {
    $columns['approval_status'] = 'Approval Status'; // Add the column for approval status
    return $columns;
}
add_filter('manage_users_columns', 'custom_add_user_approval_column');

// Display the Approval Status (Pending or Approved)
function custom_show_approval_status($value, $column_name, $user_id) {
    if ($column_name == 'approval_status') {
        $status = get_user_meta($user_id, 'custom_approval_status', true);
        return $status == 'approved' ? 'Approved' : 'Pending'; // Show Approved or Pending
    }
    return $value;
}
add_filter('manage_users_custom_column', 'custom_show_approval_status', 10, 3);


// Add Approval Status field in the user edit page
function custom_user_profile_fields($user) {
    // Check if the current user has permission to edit others' profiles
    if (current_user_can('edit_users')) {
        // Get current approval status
        $status = get_user_meta($user->ID, 'custom_approval_status', true);
        ?>
        <h3><?php _e("Approval Status", "your_plugin_domain"); ?></h3>

        <table class="form-table">
            <tr>
                <th><label for="approval_status"><?php _e("Approval Status"); ?></label></th>
                <td>
                    <select name="approval_status" id="approval_status">
                        <option value="pending" <?php selected($status, 'pending'); ?>>Pending</option>
                        <option value="approved" <?php selected($status, 'approved'); ?>>Approved</option>
                    </select>
                </td>
            </tr>
        </table>
        <?php
    }
}
add_action('show_user_profile', 'custom_user_profile_fields');
add_action('edit_user_profile', 'custom_user_profile_fields');



// Save Approval Status when admin updates the user
function custom_save_user_approval_status($user_id) {
    // Check if the current user has permission to edit users
    if (current_user_can('edit_user', $user_id)) {
        // If approval status is set, save it
        if (isset($_POST['approval_status'])) {
            $approval_status = sanitize_text_field($_POST['approval_status']);
            update_user_meta($user_id, 'custom_approval_status', $approval_status);
        }
    }
}
add_action('personal_options_update', 'custom_save_user_approval_status');
add_action('edit_user_profile_update', 'custom_save_user_approval_status');

// Restrict login for users with pending approval status
function custom_restrict_login($user, $username, $password) {
    // Check if there's no error and the user is a WP_User object
    if (!is_wp_error($user) && is_a($user, 'WP_User')) {
        // Get the user's approval status
        $status = get_user_meta($user->ID, 'custom_approval_status', true);
        
        // Log the status for debugging (check the log to see the approval status)
        error_log('User Status: ' . $status); // Log the approval status
        
        // If the user's approval status is not 'approved', deny login and show message
        if ($status !== 'approved') {
            return new WP_Error('approval_error', __('Your account is pending admin approval. You cannot log in yet.'));
        }
    }
    return $user;
}
add_filter('authenticate', 'custom_restrict_login', 10, 3);

// Restrict access to wp-admin for users with pending approval status
// Restrict access to wp-admin for users with pending approval status and show a message
function restrict_admin_access_for_pending_users() {
    if (is_admin() && !current_user_can('administrator')) {  // Check if the user is not an admin
        $user = wp_get_current_user();
        $status = get_user_meta($user->ID, 'custom_approval_status', true);
        
        // Check if the user's approval status is pending
        if ($status !== 'approved') {
            // Show a message and stop further execution
            wp_die(__('Your account is pending admin approval. You cannot access the admin panel until approval.'));
        }
    }
}
add_action('admin_init', 'restrict_admin_access_for_pending_users');

// Display a message after changing the approval status
function custom_user_update_message($user_id) {
    if (isset($_GET['approval_status_updated']) && $_GET['approval_status_updated'] == 'true') {
        echo '<div class="updated"><p>' . __('Approval status updated successfully.', 'your_plugin_domain') . '</p></div>';
    }
}
add_action('admin_notices', 'custom_user_update_message');


// Redirect back to the user list page with a success message after approval status is updated
function custom_redirect_after_user_update($user_id) {
    if (isset($_POST['approval_status'])) {
        wp_redirect(admin_url('user-edit.php?user_id=' . $user_id . '&approval_status_updated=true'));
        exit;
    }
}
add_action('edit_user_profile_update', 'custom_redirect_after_user_update');
add_action('personal_options_update', 'custom_redirect_after_user_update');


// Notify admin when a post status changes (approve, publish, etc.)
function custom_notify_admin_on_post_changes($new_status, $old_status, $post) {
    if ($old_status != $new_status) {
        $subject = "Post Status Change";
        $message = "Post '{$post->post_title}' has been changed to $new_status.";
        wp_mail(get_option('admin_email'), $subject, $message);
    }
}
add_action('transition_post_status', 'custom_notify_admin_on_post_changes', 10, 3);
