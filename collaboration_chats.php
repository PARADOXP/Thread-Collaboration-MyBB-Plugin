<?php
/**
 * Collaboration Chat Page
 * Allows collaborators to discuss their work in real-time
 */

define("IN_MYBB", 1);
require_once "./global.php";

// Debug: Check if script is being executed
file_put_contents('debug.txt', "=== SCRIPT STARTED ===\n", FILE_APPEND | LOCK_EX);

// Load MyBB parsing functions
require_once MYBB_ROOT . "inc/functions_post.php";
require_once MYBB_ROOT . "inc/functions.php";

// Load required functions
require_once MYBB_ROOT . "inc/plugins/Thread_Collaboration/Functions/post_functions.php";

// Check if user is logged in
if (!$mybb->user['uid']) {
    error_no_permission();
}

// Check if chat page is enabled
if ($mybb->settings['thread_collaboration_enable_chat_page'] != '1') {
    error("Chat page feature is disabled.");
}

// Get thread ID
$tid = (int)$mybb->get_input('tid');
if (!$tid) {
    error("Invalid thread specified.");
}

// Get thread information
$thread_query = $db->simple_select("threads", "*", "tid='{$tid}'", array('limit' => 1));
$thread = $db->fetch_array($thread_query);
if (!$thread) {
    error("Thread not found.");
}

// Check if chat page is allowed in this forum
if (!thread_collaboration_is_chat_enabled($thread['fid'])) {
    error("Chat page is not available in this forum.");
}

// Check if user can access chat
if (!thread_collaboration_can_access_chat($tid)) {
    error("You do not have permission to access this chat room.");
}

// Load language
$lang->load("thread_collaboration");

// Set page title
$page_title = $lang->collaboration_chat_title . " - " . htmlspecialchars_uni($thread['subject']);

// Add breadcrumbs
add_breadcrumb($lang->collaboration_chat_title, "collaboration_chats.php?tid={$tid}");
add_breadcrumb(htmlspecialchars_uni($thread['subject']), "showthread.php?tid={$tid}");

    // Handle AJAX requests
    if ($mybb->get_input('action') == 'get_online_users') {
        $online_users = thread_collaboration_get_online_collaborators($tid);
        
        echo json_encode(array('success' => true, 'users' => $online_users));
        exit;
    }
    
    if ($mybb->get_input('action') == 'get_collaborators') {
        $collaborators = thread_collaboration_get_thread_collaborators($tid);
        
        echo json_encode(array('success' => true, 'collaborators' => $collaborators));
        exit;
    }
    
    if ($mybb->get_input('action') == 'get_user_role') {
        $role_info = thread_collaboration_get_user_role($tid, $mybb->user['uid']);
        echo json_encode(array('success' => true, 'role' => $role_info['role'], 'role_icon' => $role_info['role_icon']));
        exit;
    }
    
    
    if ($mybb->get_input('action') == 'edit_message') {
        $message_id = (int)$mybb->get_input('message_id');
        $new_content = trim($mybb->get_input('content'));
        
        if (empty($new_content)) {
            echo json_encode(array('success' => false, 'error' => 'Message cannot be empty.'));
            exit;
        }
        
        if (strlen($new_content) > 1000) {
            echo json_encode(array('success' => false, 'error' => 'Message is too long.'));
            exit;
        }
        
        // Check if user can edit this message
        $message_query = $db->simple_select("collab_chat_messages", "*", "message_id='{$message_id}'", array('limit' => 1));
        $message = $db->fetch_array($message_query);
        
        if (!$message) {
            echo json_encode(array('success' => false, 'error' => 'Message not found.'));
            exit;
        }
        
        $can_edit = ($message['uid'] == $mybb->user['uid'] || thread_collaboration_can_moderate_chat($tid));
        if (!$can_edit) {
            echo json_encode(array('success' => false, 'error' => 'You do not have permission to edit this message.'));
            exit;
        }
        
        // Update the message
        $db->update_query("collab_chat_messages", array(
            'message' => $db->escape_string($new_content),
            'edited' => 1,
            'edit_time' => TIME_NOW
        ), "message_id='{$message_id}'");
        
        // Get user info for formatting
        $user_query = $db->query("
            SELECT u.*, g.usertitle, g.namestyle, g.image
            FROM " . TABLE_PREFIX . "users u
            LEFT JOIN " . TABLE_PREFIX . "usergroups g ON u.usergroup = g.gid
            WHERE u.uid = '{$message['uid']}'
        ");
        $user_info = $db->fetch_array($user_query);
        
        // If the complex query fails, try a simple user query
        if (empty($user_info) || empty($user_info['username'])) {
            $simple_query = $db->query("
                SELECT * FROM " . TABLE_PREFIX . "users 
                WHERE uid = '{$message['uid']}'
            ");
            $user_info = $db->fetch_array($simple_query);
            
            // Get usergroup info separately
            if ($user_info && $user_info['usergroup']) {
                $group_query = $db->query("
                    SELECT usertitle, namestyle, image 
                    FROM " . TABLE_PREFIX . "usergroups 
                    WHERE gid = '{$user_info['usergroup']}'
                ");
                $group_info = $db->fetch_array($group_query);
                if ($group_info) {
                    $user_info['usertitle'] = $group_info['usertitle'];
                    $user_info['namestyle'] = $group_info['namestyle'];
                    $user_info['image'] = $group_info['image'];
                }
            }
        }
        
        $role_info = thread_collaboration_get_user_role($tid, $message['uid']);
        
        // Create updated message data for formatting
        $updated_message = array(
            'message_id' => $message_id,
            'uid' => $message['uid'],
            'message' => $new_content,
            'timestamp' => $message['timestamp'],
            'edited' => 1,
            'edit_time' => TIME_NOW
        );
        
        // Format the updated message
        $formatted_content = thread_collaboration_format_chat_message($updated_message, $user_info, $role_info);
        
        echo json_encode(array(
            'success' => true,
            'formatted_content' => $formatted_content
        ));
        exit;
    }
    
    if ($mybb->get_input('action') == 'delete_message') {
        $message_id = (int)$mybb->get_input('message_id');
        
        // Check if user can delete this message
        $message_query = $db->simple_select("collab_chat_messages", "*", "message_id='{$message_id}'", array('limit' => 1));
        $message = $db->fetch_array($message_query);
        
        if (!$message) {
            echo json_encode(array('success' => false, 'error' => 'Message not found.'));
            exit;
        }
        
        $can_delete = ($message['uid'] == $mybb->user['uid'] || thread_collaboration_can_moderate_chat($tid));
        if (!$can_delete) {
            echo json_encode(array('success' => false, 'error' => 'You do not have permission to delete this message.'));
            exit;
        }
        
        // Delete the message
        $db->delete_query("collab_chat_messages", "message_id='{$message_id}'");
        
        echo json_encode(array('success' => true));
        exit;
    }
    
    if ($mybb->get_input('action') == 'send_message') {
    
    try {
        // Start output buffering to catch any unexpected output
        ob_start();
        
        // Disable error display to prevent HTML output
        error_reporting(E_ALL);
        ini_set('display_errors', 0);
        ini_set('log_errors', 1);
    
    $message = trim($mybb->get_input('message'));
    $reply_to = intval($mybb->get_input('reply_to'));
    
    if (empty($message)) {
        echo json_encode(array('success' => false, 'error' => $lang->collaboration_chat_error_message_empty));
        exit;
    }
    
    if (strlen($message) > 1000) {
        echo json_encode(array('success' => false, 'error' => $lang->collaboration_chat_error_message_too_long));
        exit;
    }
    
    // Check if function exists
    if (!function_exists('thread_collaboration_post_chat_message')) {
        echo json_encode(array('success' => false, 'error' => 'Function not found'));
        exit;
    }
    
    
    $message_data = thread_collaboration_post_chat_message($tid, $message, $reply_to);
    
    if ($message_data) {
        // Get user info directly from database with usergroup data
        $user_query = $db->query("
            SELECT u.*, g.usertitle, g.namestyle, g.image
            FROM " . TABLE_PREFIX . "users u
            LEFT JOIN " . TABLE_PREFIX . "usergroups g ON u.usergroup = g.gid
            WHERE u.uid = '{$message_data['uid']}'
        ");
        $user_info = $db->fetch_array($user_query);
        
        // If the complex query fails, try a simple user query
        if (empty($user_info) || empty($user_info['username'])) {
            $simple_query = $db->query("
                SELECT * FROM " . TABLE_PREFIX . "users 
                WHERE uid = '{$message_data['uid']}'
            ");
            $user_info = $db->fetch_array($simple_query);
            
            // Get usergroup info separately
            if ($user_info && $user_info['usergroup']) {
                $group_query = $db->query("
                    SELECT usertitle, namestyle, image 
                    FROM " . TABLE_PREFIX . "usergroups 
                    WHERE gid = '{$user_info['usergroup']}'
                ");
                $group_info = $db->fetch_array($group_query);
                if ($group_info) {
                    $user_info['usertitle'] = $group_info['usertitle'];
                    $user_info['namestyle'] = $group_info['namestyle'];
                    $user_info['image'] = $group_info['image'];
                }
            }
        }
        
        if (empty($user_info) || empty($user_info['username'])) {
            // Handle case where user info is not found
        }
        $role_info = thread_collaboration_get_user_role($tid, $message_data['uid']);
        $formatted_message = thread_collaboration_format_chat_message($message_data, $user_info, $role_info);
        
        // Clear any output buffer content
        ob_clean();
        
        echo json_encode(array(
            'success' => true,
            'message_id' => $message_data['message_id'],
            'html' => $formatted_message,
            'original_content' => $message // Store original MyCode content
        ));
    } else {
        // Clear any output buffer content
        ob_clean();
        
        echo json_encode(array('success' => false, 'error' => 'Failed to send message.'));
    }
    
    } catch (Exception $e) {
        // Clear any output buffer content
        ob_clean();
        
        echo json_encode(array('success' => false, 'error' => 'PHP Error: ' . $e->getMessage()));
    }
    exit;
}

if ($mybb->get_input('action') == 'get_messages') {
    $last_message_id = (int)$mybb->get_input('last_message_id');
    $live = (int)$mybb->get_input('live', 1);
    $page = (int)$mybb->get_input('page', 1);
    $per_page = (int)$mybb->get_input('per_page', 15);
    
    if ($live) {
        // Live mode: Get recent messages (last 50)
        $messages = thread_collaboration_get_chat_messages($tid, 50, 0, $last_message_id);
    } else {
        // Pagination mode: Get messages for current page
        $offset = max(0, ($page - 1) * $per_page);
        $messages = thread_collaboration_get_chat_messages($tid, $per_page, $offset);
    }
    
    $formatted_messages = array();
    foreach ($messages as $message) {
        // Get user info directly from database with usergroup data
        $user_query = $db->query("
            SELECT u.*, g.usertitle, g.namestyle, g.image
            FROM " . TABLE_PREFIX . "users u
            LEFT JOIN " . TABLE_PREFIX . "usergroups g ON u.usergroup = g.gid
            WHERE u.uid = '{$message['uid']}'
        ");
        $user_info = $db->fetch_array($user_query);
        
        // If the complex query fails, try a simple user query
        if (empty($user_info) || empty($user_info['username'])) {
            $simple_query = $db->query("
                SELECT * FROM " . TABLE_PREFIX . "users 
                WHERE uid = '{$message['uid']}'
            ");
            $user_info = $db->fetch_array($simple_query);
            
            // Get usergroup info separately
            if ($user_info && $user_info['usergroup']) {
                $group_query = $db->query("
                    SELECT usertitle, namestyle, image 
                    FROM " . TABLE_PREFIX . "usergroups 
                    WHERE gid = '{$user_info['usergroup']}'
                ");
                $group_info = $db->fetch_array($group_query);
                if ($group_info) {
                    $user_info['usertitle'] = $group_info['usertitle'];
                    $user_info['namestyle'] = $group_info['namestyle'];
                    $user_info['image'] = $group_info['image'];
                }
            }
        }
        
        $role_info = thread_collaboration_get_user_role($tid, $message['uid']);
        
        
        $formatted_messages[] = array(
            'message_id' => $message['message_id'],
            'html' => thread_collaboration_format_chat_message($message, $user_info, $role_info),
            'original_content' => $message['message'] // Store original MyCode content
        );
    }
    
    echo json_encode(array(
        'success' => true,
        'messages' => $formatted_messages,
        'last_message_id' => !empty($messages) ? end($messages)['message_id'] : $last_message_id
    ));
    exit;
}

// Handle chat view preference saving
if ($mybb->get_input('action') == 'save_chat_view_preference') {
    $expanded = (int)$mybb->get_input('expanded');
    
    // Check if preference already exists
    $existing_query = $db->simple_select("collab_chat_settings", "setting_id", "tid='{$tid}' AND uid='{$mybb->user['uid']}' AND setting_name='chat_view_expanded'", array('limit' => 1));
    
    if ($db->num_rows($existing_query) > 0) {
        // Update existing preference
        $db->update_query("collab_chat_settings", array(
            'setting_value' => $expanded,
            'dateline' => TIME_NOW
        ), "tid='{$tid}' AND uid='{$mybb->user['uid']}' AND setting_name='chat_view_expanded'");
    } else {
        // Insert new preference
        $db->insert_query("collab_chat_settings", array(
            'tid' => $tid,
            'uid' => $mybb->user['uid'],
            'setting_name' => 'chat_view_expanded',
            'setting_value' => $expanded,
            'dateline' => TIME_NOW
        ));
    }
    
    // Debug: Log the preference save
    error_log("Chat view preference saved: tid={$tid}, uid={$mybb->user['uid']}, expanded={$expanded}");
    
    echo json_encode(array('success' => true));
    exit;
}

// Handle chat view preference loading
if ($mybb->get_input('action') == 'get_chat_view_preference') {
    $preference_query = $db->simple_select("collab_chat_settings", "setting_value", "tid='{$tid}' AND uid='{$mybb->user['uid']}' AND setting_name='chat_view_expanded'", array('limit' => 1));
    
    $expanded = false;
    if ($db->num_rows($preference_query) > 0) {
        $preference = $db->fetch_array($preference_query);
        $expanded = (bool)$preference['setting_value'];
    }
    
    // Debug: Log the preference load
    error_log("Chat view preference loaded: tid={$tid}, uid={$mybb->user['uid']}, expanded={$expanded}");
    
    echo json_encode(array('success' => true, 'expanded' => $expanded));
    exit;
}

// Load chat view preference for server-side rendering
$chat_view_expanded = false;
$preference_query = $db->simple_select("collab_chat_settings", "setting_value", "tid='{$tid}' AND uid='{$mybb->user['uid']}' AND setting_name='chat_view_expanded'", array('limit' => 1));
if ($db->num_rows($preference_query) > 0) {
    $preference = $db->fetch_array($preference_query);
    $chat_view_expanded = (bool)$preference['setting_value'];
}

// Pagination settings
    $per_page = (int)$mybb->get_input('per_page', 15);
    $current_page = (int)$mybb->get_input('page', 1);
    $live_mode = (int)$mybb->get_input('live', 0); // Default to pagination mode
    

// Ensure per_page is at least 1 to prevent division by zero
if ($per_page < 1) {
    $per_page = 15;
}

// Get total message count for pagination
$count_query = $db->simple_select("collab_chat_messages", "COUNT(*) as total", "tid='{$tid}'");
$total_messages = $db->fetch_array($count_query)['total'];
$total_pages = $total_messages > 0 ? ceil($total_messages / $per_page) : 1;

// Validate current page
if ($current_page < 1) {
    $current_page = 1;
} elseif ($current_page > $total_pages && $total_pages > 0) {
    $current_page = $total_pages;
}

// Get messages based on mode
if ($live_mode) {
    // Live mode: Get recent messages (last 50)
    $messages = thread_collaboration_get_chat_messages($tid, 50);
    $last_message_id = !empty($messages) ? end($messages)['message_id'] : 0;
} else {
    // Paginated mode: Get messages for current page
    $offset = max(0, ($current_page - 1) * $per_page); // Ensure offset is never negative
    $messages = thread_collaboration_get_chat_messages($tid, $per_page, $offset);
    $last_message_id = 0; // No auto-refresh in paginated mode
}


if (!empty($messages)) {
    
    foreach ($messages as $i => $msg) {
        if (strpos($msg['username'], '{username}') !== false) {
        }
    }
} else {
}

// Build messages HTML
$messages_html = '';
if (!empty($messages)) {
    foreach ($messages as $message) {
        // Get user info directly from database with usergroup data
        $user_query = $db->query("
            SELECT u.*, g.usertitle, g.namestyle, g.image
            FROM " . TABLE_PREFIX . "users u
            LEFT JOIN " . TABLE_PREFIX . "usergroups g ON u.usergroup = g.gid
            WHERE u.uid = '{$message['uid']}'
        ");
        $user_info = $db->fetch_array($user_query);
        
        // If the complex query fails, try a simple user query
        if (empty($user_info) || empty($user_info['username'])) {
            $simple_query = $db->query("
                SELECT * FROM " . TABLE_PREFIX . "users 
                WHERE uid = '{$message['uid']}'
            ");
            $user_info = $db->fetch_array($simple_query);
            
            // Get usergroup info separately
            if ($user_info && $user_info['usergroup']) {
                $group_query = $db->query("
                    SELECT usertitle, namestyle, image 
                    FROM " . TABLE_PREFIX . "usergroups 
                    WHERE gid = '{$user_info['usergroup']}'
                ");
                $group_info = $db->fetch_array($group_query);
                if ($group_info) {
                    $user_info['usertitle'] = $group_info['usertitle'];
                    $user_info['namestyle'] = $group_info['namestyle'];
                    $user_info['image'] = $group_info['image'];
                }
            }
        }
        
        $role_info = thread_collaboration_get_user_role($tid, $message['uid']);
        
        
        $messages_html .= thread_collaboration_format_chat_message($message, $user_info, $role_info);
    }
} else {
    $messages_html = '<div class="chat-no-messages"><i class="fas fa-comments"></i><p>' . $lang->collaboration_chat_no_messages . '</p></div>';
}

// Generate draft button HTML based on settings
$draft_button_html = '';
if (thread_collaboration_is_draft_enabled($thread['fid'])) {
    $draft_button_html = '<a href="collaboration_draft.php?tid=' . $tid . '" class="draft-btn" title="Collaborative Draft Post">
        <i class="fas fa-edit"></i> ' . $lang->collaboration_chat_draft_post . '
    </a>';
}

// Load template directly from file (like edit history page)
$template_file = MYBB_ROOT . 'inc/plugins/Thread_Collaboration/Templates/collaboration_chat.html';
if (!file_exists($template_file)) {
    die("Template file not found");
}

$template_content = file_get_contents($template_file);

// Replace template variables manually
$page = $template_content;
$page = str_replace('{$thread_subject}', htmlspecialchars_uni($thread['subject']), $page);
$page = str_replace('{$tid}', $tid, $page);
$page = str_replace('{$mybb->user[\'username\']}', htmlspecialchars_uni($mybb->user['username']), $page);
$page = str_replace('{$mybb->user[\'username\'][0]}', strtoupper(substr($mybb->user['username'], 0, 1)), $page);
$page = str_replace('{$mybb->user[\'avatar\']}', htmlspecialchars_uni($mybb->user['avatar']), $page);
$page = str_replace('{$messages_html}', $messages_html, $page);
$page = str_replace('{$last_message_id}', $last_message_id, $page);
$page = str_replace('{$live_mode}', $live_mode, $page);
$page = str_replace('{$current_page}', $current_page, $page);
$page = str_replace('{$total_pages}', $total_pages, $page);
$page = str_replace('{$total_messages}', $total_messages, $page);
$page = str_replace('{$chat_view_expanded}', $chat_view_expanded ? 'expanded' : '', $page);
$page = str_replace('{$draft_button_html}', $draft_button_html, $page);

// Calculate pagination link values
$prev_page = max(1, $current_page - 1);
$next_page = min($total_pages, $current_page + 1);
$page = str_replace('{$current_page-1}', $prev_page, $page);
$page = str_replace('{$current_page+1}', $next_page, $page);



$page = str_replace('{$headerinclude}', $headerinclude, $page);
$page = str_replace('{$header}', $header, $page);
$page = str_replace('{$footer}', $footer, $page);

// Handle language variables
$page = preg_replace_callback('/\{\$lang->([^}]+)\}/', function($matches) use ($lang) {
    $lang_key = $matches[1];
    return isset($lang->$lang_key) ? $lang->$lang_key : $matches[0];
}, $page);

// Handle MyBB object properties
$page = str_replace('{$mybb->asset_url}', $mybb->asset_url, $page);
$page = str_replace('{$mybb->post_code}', $mybb->post_code, $page);

output_page($page);
