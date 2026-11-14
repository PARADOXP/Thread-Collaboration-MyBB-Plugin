<?php
/**
 * Thread Collaboration Edit History Page
 * Displays edit history with diff viewer and restore functionality
 */

define("IN_MYBB", 1);
require_once "./global.php";


// Check if user is logged in
if (!$mybb->user['uid']) {
    error_no_permission();
}

// Get parameters
$pid = (int)$mybb->get_input('pid');
$tid = (int)$mybb->get_input('tid');

if (!$pid || !$tid) {
    error("Invalid parameters.");
}

// Get post and thread information
$post_query = $db->simple_select("posts", "*", "pid='{$pid}'", array('limit' => 1));
$post = $db->fetch_array($post_query);

if (!$post) {
    error("Post not found.");
}

$thread_query = $db->simple_select("threads", "*", "tid='{$tid}'", array('limit' => 1));
$thread = $db->fetch_array($thread_query);

if (!$thread) {
    error("Thread not found.");
}

// Check permissions - allow thread owner, management users, and collaborators
$can_access_edit_history = false;

// Check if current user is thread owner
$is_thread_owner = ($mybb->user['uid'] == $thread['uid']);

// Check if current user is a collaborator in this thread
$is_current_user_collaborator = false;
$collaborator_query = $db->simple_select("thread_collaborators", "cid", "tid='{$tid}' AND uid='{$mybb->user['uid']}'", array('limit' => 1));
if ($collaborator_query && $db->num_rows($collaborator_query) > 0) {
    $is_current_user_collaborator = true;
}

// Check if current user is in moderator/management usergroups
$is_management_user = false;
$management_groups = array();
if (!empty($mybb->settings['thread_collaboration_moderator_usergroups'])) {
    $management_groups = explode(',', $mybb->settings['thread_collaboration_moderator_usergroups']);
}
if (in_array($mybb->user['usergroup'], $management_groups)) {
    $is_management_user = true;
}

// Check if there are any collaborators in this thread (for thread owner to see their own post history)
$has_collaborators = false;
$collaborators_count_query = $db->simple_select("thread_collaborators", "COUNT(*) as count", "tid='{$tid}'");
$collaborators_count = $db->fetch_array($collaborators_count_query);
if ($collaborators_count['count'] > 0) {
    $has_collaborators = true;
}

// Determine if user can access edit history
if ($is_thread_owner && $has_collaborators) {
    // Thread owner can see edit history on all posts if there are collaborators (they are default collaborator)
    $can_access_edit_history = true;
} elseif ($is_management_user) {
    // Management users can see edit history on all posts
    $can_access_edit_history = true;
} elseif ($is_current_user_collaborator) {
    // Collaborators can see edit history on all posts (including their own)
    $can_access_edit_history = true;
}

// Check permissions
if (!$can_access_edit_history) {
    error_no_permission();
}

// Handle restore action
if ($mybb->get_input('action') == 'restore' && $mybb->request_method == 'post') {
    verify_post_check($mybb->get_input('my_post_key'));
    
    $history_id = (int)$mybb->get_input('history_id');
    
    // Get the history record
    $history_query = $db->simple_select("collab_edit_history", "*", "history_id='{$history_id}' AND pid='{$pid}'", array('limit' => 1));
    $history = $db->fetch_array($history_query);
    
    if (!$history) {
        error("History record not found.");
    }
    
    // Restore the post content (preserve original dateline to maintain post order)
    $update_data = array(
        'message' => $db->escape_string($history['original_content'])
    );
    
    if (!empty($history['original_subject'])) {
        $update_data['subject'] = $db->escape_string($history['original_subject']);
    }
    
    $db->update_query("posts", $update_data, "pid='{$pid}'");
    
    // Log the restore action
    $restore_data = array(
        'pid' => $pid,
        'tid' => $tid,
        'editor_uid' => $mybb->user['uid'],
        'edit_type' => 'restore',
        'original_content' => $post['message'],
        'new_content' => $history['original_content'],
        'original_subject' => $post['subject'],
        'new_subject' => $history['original_subject'],
        'edit_reason' => 'Restored from history #' . $history_id,
        'restore_from_id' => $history_id,
        'dateline' => TIME_NOW,
        'ip_address' => $db->escape_string(get_ip())
    );
    
    $db->insert_query("collab_edit_history", $restore_data);
    
    // Redirect back to the thread
    redirect("showthread.php?tid={$tid}&pid={$pid}#pid{$pid}", "Post restored successfully.");
}

// Get edit history
$history_query = $db->query("
    SELECT h.*, u.username, u.usergroup, u.displaygroup
    FROM " . TABLE_PREFIX . "collab_edit_history h
    LEFT JOIN " . TABLE_PREFIX . "users u ON h.editor_uid = u.uid
    WHERE h.pid = '{$pid}'
    ORDER BY h.dateline DESC
");

$history_items = array();
while ($item = $db->fetch_array($history_query)) {
    $item['username'] = htmlspecialchars_uni($item['username']);
    $item['formatted_date'] = my_date('relative', $item['dateline']);
    $item['formatted_datetime'] = my_date($mybb->settings['dateformat'], $item['dateline']) . ' ' . my_date($mybb->settings['timeformat'], $item['dateline']);
    $history_items[] = $item;
}


// Handle AJAX requests
if ($mybb->get_input('action') == 'get_diff') {
    $history_id = (int)$mybb->get_input('history_id');
    
    // Get the history record
    $history_query = $db->simple_select("collab_edit_history", "*", "history_id='{$history_id}' AND pid='{$pid}'", array('limit' => 1));
    $history = $db->fetch_array($history_query);
    
    if (!$history) {
        echo json_encode(array('success' => false, 'error' => 'History record not found.'));
        exit;
    }
    
    // Get editor info
    $editor_query = $db->simple_select("users", "username", "uid='{$history['editor_uid']}'", array('limit' => 1));
    $editor = $db->fetch_array($editor_query);
    $editor_name = $editor ? $editor['username'] : 'Unknown User';
    
    // Create diff
    $old_content = $history['original_content'] ?: '';
    $new_content = $history['new_content'] ?: '';
    
    $diff = createTextDiff($old_content, $new_content);
    
    $response = array(
        'success' => true,
        'diff' => $diff,
        'old_meta' => $editor_name . ' - ' . my_date('relative', $history['dateline']),
        'new_meta' => 'Current version',
        'old_content' => $old_content,
        'new_content' => $new_content
    );
    
    echo json_encode($response);
    exit;
}

if ($mybb->get_input('action') == 'get_restore_preview') {
    $history_id = (int)$mybb->get_input('history_id');
    
    // Get the history record
    $history_query = $db->simple_select("collab_edit_history", "*", "history_id='{$history_id}' AND pid='{$pid}'", array('limit' => 1));
    $history = $db->fetch_array($history_query);
    
    if (!$history) {
        echo json_encode(array('success' => false, 'error' => 'History record not found.'));
        exit;
    }
    
    $response = array(
        'success' => true,
        'content' => htmlspecialchars_uni($history['original_content']),
        'subject' => $history['original_subject'] ? htmlspecialchars_uni($history['original_subject']) : null
    );
    
    echo json_encode($response);
    exit;
}

// Load language
$lang->load("thread_collaboration");

// Page title
$page_title = "Edit History - " . htmlspecialchars_uni($thread['subject']);

// Start output
add_breadcrumb("Edit History", "collaboration_edit_history.php?pid={$pid}&tid={$tid}");
add_breadcrumb(htmlspecialchars_uni($thread['subject']), "showthread.php?tid={$tid}");

$plugins->run_hooks("collaboration_edit_history_start");

// Create a simple HTML page for now

// Build edit history content using templates
$edit_history_content = '';


if (!empty($history_items)) {
    $edit_history_items = '';
    
    foreach ($history_items as $item) {
        $edit_type_text = '';
        $edit_type_icon = '';
        if ($item['edit_type'] == 'create') {
            $edit_type_text = 'Created';
            $edit_type_icon = 'fas fa-plus';
        } elseif ($item['edit_type'] == 'edit') {
            $edit_type_text = 'Edited';
            $edit_type_icon = 'fas fa-edit';
        } elseif ($item['edit_type'] == 'restore') {
            $edit_type_text = 'Restored';
            $edit_type_icon = 'fas fa-undo';
        }
        
        $restore_button = '';
        if ($item['edit_type'] != 'create' && $item['original_content']) {
            $restore_button = '<button class="button restore-btn" data-history-id="' . $item['history_id'] . '" title="Restore this version">
                                <i class="fas fa-undo"></i> Restore
                            </button>';
        }
        
        $edit_reason = '';
        if ($item['edit_reason']) {
            $edit_reason = '<div class="edit-reason">
                <strong>Reason:</strong> ' . htmlspecialchars_uni($item['edit_reason']) . '
            </div>';
        }
        
        // Prepare item template variables
        $item_vars = array(
            'history_id' => $item['history_id'],
            'edit_type' => $item['edit_type'],
            'edit_type_icon' => $edit_type_icon,
            'edit_type_text' => $edit_type_text,
            'username' => htmlspecialchars_uni($item['username']),
            'formatted_date' => $item['formatted_date'],
            'formatted_datetime' => $item['formatted_datetime'],
            'restore_button' => $restore_button,
            'edit_reason' => $edit_reason,
            'content_preview' => my_substr(strip_tags($item['new_content']), 0, 200) . '...'
        );
        
        // Load item template with fallback
        $item_template = $templates->get('edit_history_item', $item_vars);
        if (strpos($item_template, '<!-- start: edit_history_item -->') !== false) {
            // Fallback: Load directly from file
            $item_file = MYBB_ROOT . 'inc/plugins/Thread_Collaboration/Templates/collaboration_edit_history_item.html';
            if (file_exists($item_file)) {
                $item_content = file_get_contents($item_file);
                foreach ($item_vars as $key => $value) {
                    $item_content = str_replace('{$' . $key . '}', $value, $item_content);
                }
                $edit_history_items .= $item_content;
            }
        } else {
            $edit_history_items .= $item_template;
        }
    }
    
    // Load list template with fallback
    $list_template = $templates->get('edit_history_list', array('edit_history_items' => $edit_history_items));
    if (strpos($list_template, '<!-- start: edit_history_list -->') !== false) {
        // Fallback: Load directly from file
        $list_file = MYBB_ROOT . 'inc/plugins/Thread_Collaboration/Templates/collaboration_edit_history_list.html';
        if (file_exists($list_file)) {
            $list_content = file_get_contents($list_file);
            $list_content = str_replace('{$edit_history_items}', $edit_history_items, $list_content);
            $edit_history_content = $list_content;
        }
    } else {
        $edit_history_content = $list_template;
    }
} else {
    // Load empty template with fallback
    $empty_template = $templates->get('edit_history_empty');
    if (strpos($empty_template, '<!-- start: edit_history_empty -->') !== false) {
        // Fallback: Load directly from file
        $empty_file = MYBB_ROOT . 'inc/plugins/Thread_Collaboration/Templates/collaboration_edit_history_empty.html';
        if (file_exists($empty_file)) {
            $edit_history_content = file_get_contents($empty_file);
        }
    } else {
        $edit_history_content = $empty_template;
    }
}

// Prepare template variables
$template_vars = array(
    'thread_subject' => htmlspecialchars_uni($thread['subject']),
    'tid' => $tid,
    'pid' => $pid,
    'edit_history_content' => $edit_history_content,
    'headerinclude' => $headerinclude,
    'header' => $header,
    'footer' => $footer,
    'lang' => $lang
);


if (!isset($templates) || !is_object($templates)) {
    die("Templates object not found");
}


// Check if template exists
$template_exists = $templates->get('edit_history');
if (empty($template_exists)) {
    die("Template not found");
}

// Check if template is just wrapper comments (indicates template not loaded properly)
if (strpos($template_exists, '<!-- start: edit_history -->') !== false) {
    // Fallback: Load template directly from file
    $template_file = MYBB_ROOT . 'inc/plugins/Thread_Collaboration/Templates/collaboration_edit_history.html';
    if (file_exists($template_file)) {
        $template_content = file_get_contents($template_file);
        
        // Replace template variables manually
        $page = $template_content;
        foreach ($template_vars as $key => $value) {
            if ($key === 'lang') {
                // Handle language object specially - replace all {$lang->...} patterns
                $page = preg_replace_callback('/\{\$lang->([^}]+)\}/', function($matches) use ($lang) {
                    $lang_key = $matches[1];
                    return isset($lang->$lang_key) ? $lang->$lang_key : $matches[0];
                }, $page);
            } else {
                $page = str_replace('{$' . $key . '}', $value, $page);
            }
        }
        
        // Handle MyBB object properties
        $page = str_replace('{$mybb->asset_url}', $mybb->asset_url, $page);
        $page = str_replace('{$mybb->post_code}', $mybb->post_code, $page);
    } else {
        die("Template file not found");
    }
} else {
    // Load and render the template normally
    $page = $templates->get('edit_history', $template_vars);
}


if (empty($page)) {
    die("Page is empty");
}

output_page($page);

// Helper function to create text diff
function createTextDiff($old_text, $new_text) {
    $old_lines = explode("\n", $old_text);
    $new_lines = explode("\n", $new_text);
    
    $diff = array();
    $old_index = 0;
    $new_index = 0;
    
    while ($old_index < count($old_lines) || $new_index < count($new_lines)) {
        if ($old_index >= count($old_lines)) {
            // Only new lines left
            $diff[] = array(
                'type' => 'added',
                'content' => $new_lines[$new_index],
                'oldLine' => null,
                'newLine' => $new_index + 1
            );
            $new_index++;
        } elseif ($new_index >= count($new_lines)) {
            // Only old lines left
            $diff[] = array(
                'type' => 'removed',
                'content' => $old_lines[$old_index],
                'oldLine' => $old_index + 1,
                'newLine' => null
            );
            $old_index++;
        } elseif ($old_lines[$old_index] === $new_lines[$new_index]) {
            // Lines are the same
            $diff[] = array(
                'type' => 'context',
                'content' => $old_lines[$old_index],
                'oldLine' => $old_index + 1,
                'newLine' => $new_index + 1
            );
            $old_index++;
            $new_index++;
        } else {
            // Lines are different - find best match
            $found = false;
            for ($i = $new_index + 1; $i < count($new_lines); $i++) {
                if ($old_lines[$old_index] === $new_lines[$i]) {
                    // Found match - add all new lines up to this point
                    for ($j = $new_index; $j < $i; $j++) {
                        $diff[] = array(
                            'type' => 'added',
                            'content' => $new_lines[$j],
                            'oldLine' => null,
                            'newLine' => $j + 1
                        );
                    }
                    $new_index = $i;
                    $found = true;
                    break;
                }
            }
            
            if (!$found) {
                // No match found - add as removed
                $diff[] = array(
                    'type' => 'removed',
                    'content' => $old_lines[$old_index],
                    'oldLine' => $old_index + 1,
                    'newLine' => null
                );
                $old_index++;
            }
        }
    }
    
    return $diff;
}
