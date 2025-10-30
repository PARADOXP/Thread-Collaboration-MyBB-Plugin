<?php
/**
 * Collaboration Draft Page
 * Allows collaborators to write and edit posts together
 */

define("IN_MYBB", 1);
require_once "./global.php";

// Advanced diff analysis function for precise contribution tracking
function analyzeContentChanges($old_content, $new_content) {
    $changes = array(
        'chars_added' => 0,
        'chars_removed' => 0,
        'words_added' => 0,
        'words_removed' => 0,
        'lines_added' => 0,
        'lines_removed' => 0,
        'actual_additions' => '',
        'actual_removals' => '',
        'diff_details' => array()
    );
    
    if ($old_content === $new_content) {
        return $changes;
    }
    
    // Use character-level diff analysis for more precision
    $old_chars = str_split($old_content);
    $new_chars = str_split($new_content);
    
    $i = 0;
    $j = 0;
    $additions = '';
    $removals = '';
    
    while ($i < count($old_chars) || $j < count($new_chars)) {
        if ($i >= count($old_chars)) {
            // Only new characters left
            $additions .= $new_chars[$j];
            $changes['chars_added']++;
            $j++;
        } elseif ($j >= count($new_chars)) {
            // Only old characters left
            $removals .= $old_chars[$i];
            $changes['chars_removed']++;
            $i++;
        } elseif ($old_chars[$i] === $new_chars[$j]) {
            // Characters are the same
            $i++;
            $j++;
        } else {
            // Characters are different - find best match
            $found = false;
            for ($k = $j + 1; $k < count($new_chars) && $k < $j + 10; $k++) {
                if ($old_chars[$i] === $new_chars[$k]) {
                    // Found match - add all new characters up to this point
                    for ($l = $j; $l < $k; $l++) {
                        $additions .= $new_chars[$l];
                        $changes['chars_added']++;
                    }
                    $j = $k;
                    $found = true;
                    break;
                }
            }
            
            if (!$found) {
                // No match found - remove old character
                $removals .= $old_chars[$i];
                $changes['chars_removed']++;
                $i++;
            }
        }
    }
    
    $changes['actual_additions'] = $additions;
    $changes['actual_removals'] = $removals;
    
    // Calculate word changes
    $old_words = str_word_count($old_content);
    $new_words = str_word_count($new_content);
    $changes['words_added'] = max(0, $new_words - $old_words);
    $changes['words_removed'] = max(0, $old_words - $new_words);
    
    // Calculate line changes
    $old_lines = substr_count($old_content, "\n");
    $new_lines = substr_count($new_content, "\n");
    $changes['lines_added'] = max(0, $new_lines - $old_lines);
    $changes['lines_removed'] = max(0, $old_lines - $new_lines);
    
    return $changes;
}

// Load MyBB parsing functions
require_once MYBB_ROOT . "inc/functions_post.php";
require_once MYBB_ROOT . "inc/functions.php";

// Load required functions
require_once MYBB_ROOT . "inc/plugins/Thread_Collaboration/Functions/post_functions.php";

// API ENDPOINTS - Handle these BEFORE permission checks
if ($mybb->get_input('action') == 'analyze_changes') {
    // Real-time change analysis endpoint
    
    if (!$mybb->user['uid']) {
        echo json_encode(array('success' => false, 'error' => 'Not logged in'));
        exit;
    }
    
    $content_before = $mybb->get_input('content_before');
    $content_after = $mybb->get_input('content_after');
    
    $changes = analyzeContentChanges($content_before, $content_after);
    
    $response = array(
        'success' => true,
        'changes' => $changes,
        'summary' => array(
            'chars_added' => $changes['chars_added'],
            'chars_removed' => $changes['chars_removed'],
            'words_added' => $changes['words_added'],
            'words_removed' => $changes['words_removed'],
            'actual_additions' => $changes['actual_additions'],
            'actual_removals' => $changes['actual_removals']
        )
    );
    
    echo json_encode($response);
    exit;
}

if ($mybb->get_input('action') == 'log_draft_edit') {
    
    // Check if user is logged in for API calls
    if (!$mybb->user['uid']) {
        echo json_encode(array('success' => false, 'error' => 'Not logged in'));
        exit;
    }
    
    $draft_id = (int)$mybb->get_input('draft_id');
    $content_before = $mybb->get_input('content_before');
    $content_after = $mybb->get_input('content_after');
    $action = $mybb->get_input('action_type', 'edit');
    
    if ($draft_id > 0 && $content_after !== null) {
        // Use advanced diff analysis for precise tracking
        $changes = analyzeContentChanges($content_before, $content_after);
        
        $chars_added = $changes['chars_added'];
        $chars_removed = $changes['chars_removed'];
        $words_added = $changes['words_added'];
        $words_removed = $changes['words_removed'];
        
        $edit_summary = '';
        if ($chars_added > 0) $edit_summary .= "Added {$chars_added} characters";
        if ($chars_removed > 0) {
            if ($edit_summary) $edit_summary .= ", ";
            $edit_summary .= "Removed {$chars_removed} characters";
        }
        if ($words_added > 0) $edit_summary .= " ({$words_added} words)";
        
        // Use prepared statement approach for better security
        $log_data = array(
            'draft_id' => (int)$draft_id,
            'uid' => (int)$mybb->user['uid'],
            'action' => $action,
            'content_before' => $content_before,
            'content_after' => $content_after,
            'characters_added' => (int)$chars_added,
            'characters_removed' => (int)$chars_removed,
            'words_added' => (int)$words_added,
            'words_removed' => (int)$words_removed,
            'edit_summary' => $edit_summary,
            'dateline' => (int)TIME_NOW,
            'ip_address' => inet_pton(get_ip()),
            'actual_additions' => $changes['actual_additions'],
            'actual_removals' => $changes['actual_removals']
        );
        
        // Check if new columns exist, otherwise use fallback
        $columns_check = $db->query("SHOW COLUMNS FROM " . TABLE_PREFIX . "collab_draft_edit_logs LIKE 'actual_additions'");
        $has_new_columns = $db->num_rows($columns_check) > 0;
        
        if ($has_new_columns) {
            // Use new columns for advanced tracking
            $sql = "INSERT INTO " . TABLE_PREFIX . "collab_draft_edit_logs 
                    (draft_id, uid, action, content_before, content_after, characters_added, characters_removed, words_added, words_removed, edit_summary, dateline, ip_address, actual_additions, actual_removals) 
                    VALUES (
                        '" . (int)$draft_id . "',
                        '" . (int)$mybb->user['uid'] . "',
                        '" . $db->escape_string($action) . "',
                        '" . $db->escape_string($content_before) . "',
                        '" . $db->escape_string($content_after) . "',
                        '" . (int)$chars_added . "',
                        '" . (int)$chars_removed . "',
                        '" . (int)$words_added . "',
                        '" . (int)$words_removed . "',
                        '" . $db->escape_string($edit_summary) . "',
                        '" . (int)TIME_NOW . "',
                        '',
                        '" . $db->escape_string($changes['actual_additions']) . "',
                        '" . $db->escape_string($changes['actual_removals']) . "'
                    )";
        } else {
            // Fallback to original structure
        $sql = "INSERT INTO " . TABLE_PREFIX . "collab_draft_edit_logs 
                (draft_id, uid, action, content_before, content_after, characters_added, characters_removed, words_added, words_removed, edit_summary, dateline, ip_address) 
                VALUES (
                    '" . (int)$draft_id . "',
                    '" . (int)$mybb->user['uid'] . "',
                    '" . $db->escape_string($action) . "',
                    '" . $db->escape_string($content_before) . "',
                    '" . $db->escape_string($content_after) . "',
                    '" . (int)$chars_added . "',
                    '" . (int)$chars_removed . "',
                    '" . (int)$words_added . "',
                    '" . (int)$words_removed . "',
                    '" . $db->escape_string($edit_summary) . "',
                    '" . (int)TIME_NOW . "',
                    ''
                )";
        }
        
        $result = $db->query($sql);
        
        $response = array(
            'success' => true,
            'chars_added' => $chars_added,
            'chars_removed' => $chars_removed,
            'words_added' => $words_added,
            'words_removed' => $words_removed,
            'edit_summary' => $edit_summary
        );
        
        echo json_encode($response);
    } else {
        echo json_encode(array('success' => false, 'error' => 'Invalid parameters'));
    }
    exit;
}

if ($mybb->get_input('action') == 'get_edit_logs') {
    
    // Check if user is logged in for API calls
    if (!$mybb->user['uid']) {
        echo json_encode(array('success' => false, 'error' => 'Not logged in'));
        exit;
    }
    
    $draft_id = (int)$mybb->get_input('draft_id');
    $page = (int)$mybb->get_input('page', 1);
    $limit = (int)$mybb->get_input('limit', 10); // Default 10 logs per page
    
    if ($draft_id > 0) {
        // Calculate offset for pagination
        $offset = ($page - 1) * $limit;
        
        // Get total count
        $count_query = $db->query("
            SELECT COUNT(*) as total
            FROM " . TABLE_PREFIX . "collab_draft_edit_logs
            WHERE draft_id = '{$draft_id}'
        ");
        $total_count = $db->fetch_array($count_query)['total'];
        
        // Get paginated logs
        $logs_query = $db->query("
            SELECT el.*, u.username, u.avatar, u.avatardimensions
            FROM " . TABLE_PREFIX . "collab_draft_edit_logs el
            LEFT JOIN " . TABLE_PREFIX . "users u ON el.uid = u.uid
            WHERE el.draft_id = '{$draft_id}'
            ORDER BY el.dateline DESC
            LIMIT {$limit} OFFSET {$offset}
        ");
        
        $logs = array();
        while ($log = $db->fetch_array($logs_query)) {
            // Handle avatar path
            $avatar_path = '';
            if (!empty($log['avatar'])) {
                if (strpos($log['avatar'], 'http') === 0) {
                    // External avatar URL
                    $avatar_path = $log['avatar'];
                } else {
                    // Local avatar file
                    $avatar_path = $mybb->settings['bburl'] . '/uploads/avatars/' . $log['avatar'];
                }
            } else {
                // Default avatar
                $avatar_path = $mybb->settings['bburl'] . '/images/default_avatar.png';
            }
            
            $logs[] = array(
                'log_id' => $log['log_id'],
                'username' => $log['username'],
                'avatar' => $avatar_path,
                'avatardimensions' => $log['avatardimensions'],
                'action' => $log['action'],
                'characters_added' => $log['characters_added'],
                'characters_removed' => $log['characters_removed'],
                'words_added' => $log['words_added'],
                'words_removed' => $log['words_removed'],
                'edit_summary' => $log['edit_summary'],
                'dateline' => $log['dateline'],
                'formatted_time' => date('Y-m-d H:i:s', $log['dateline'])
            );
        }
        
        $total_pages = ceil($total_count / $limit);
        
        echo json_encode(array(
            'success' => true, 
            'logs' => $logs,
            'pagination' => array(
                'current_page' => $page,
                'total_pages' => $total_pages,
                'total_logs' => $total_count,
                'limit' => $limit,
                'has_more' => $page < $total_pages
            )
        ));
    } else {
        echo json_encode(array('success' => false, 'error' => 'Invalid draft ID'));
    }
    exit;
}

if ($mybb->get_input('action') == 'get_edit_contributions') {
    
    // Check if user is logged in for API calls
    if (!$mybb->user['uid']) {
        echo json_encode(array('success' => false, 'error' => 'Not logged in'));
        exit;
    }
    
    $draft_id = (int)$mybb->get_input('draft_id');
    
    if ($draft_id > 0) {
        // Check if new columns exist for advanced tracking
        $columns_check = $db->query("SHOW COLUMNS FROM " . TABLE_PREFIX . "collab_draft_edit_logs LIKE 'actual_additions'");
        $has_advanced_tracking = $db->num_rows($columns_check) > 0;
        
        if ($has_advanced_tracking) {
            // Use advanced tracking with actual additions/removals
            $contributions_query = $db->query("
                SELECT el.uid, u.username, u.avatar, u.avatardimensions,
                       SUM(COALESCE(LENGTH(el.actual_additions), 0)) as total_chars_added,
                       SUM(COALESCE(LENGTH(el.actual_removals), 0)) as total_chars_removed,
                       SUM(el.words_added) as total_words_added,
                       SUM(el.words_removed) as total_words_removed,
                       COUNT(el.log_id) as edit_count
                FROM " . TABLE_PREFIX . "collab_draft_edit_logs el
                LEFT JOIN " . TABLE_PREFIX . "users u ON el.uid = u.uid
                WHERE el.draft_id = '{$draft_id}'
                GROUP BY el.uid
                ORDER BY total_chars_added DESC
            ");
        } else {
            // Fallback to original method
        $contributions_query = $db->query("
            SELECT el.uid, u.username, u.avatar, u.avatardimensions,
                   SUM(el.characters_added) as total_chars_added,
                   SUM(el.characters_removed) as total_chars_removed,
                   SUM(el.words_added) as total_words_added,
                   SUM(el.words_removed) as total_words_removed,
                   COUNT(el.log_id) as edit_count
            FROM " . TABLE_PREFIX . "collab_draft_edit_logs el
            LEFT JOIN " . TABLE_PREFIX . "users u ON el.uid = u.uid
            WHERE el.draft_id = '{$draft_id}'
            GROUP BY el.uid
            ORDER BY total_chars_added DESC
        ");
        }
        
        $contributions = array();
        $total_chars = 0;
        
        while ($contrib = $db->fetch_array($contributions_query)) {
            $contributions[] = $contrib;
            $total_chars += $contrib['total_chars_added'];
        }
        
        foreach ($contributions as &$contrib) {
            $contrib['percentage'] = $total_chars > 0 ? round(($contrib['total_chars_added'] / $total_chars) * 100, 1) : 0;
        }
        
        $response = array(
            'success' => true, 
            'contributions' => $contributions,
            'total_chars' => $total_chars
        );
        
        echo json_encode($response);
    } else {
        echo json_encode(array('success' => false, 'error' => 'Invalid draft ID'));
    }
    exit;
}

// Get all drafts for Edit Logs (including published)
if ($mybb->get_input('action') == 'get_all_drafts') {
    // Check if user is logged in for API calls
    if (!$mybb->user['uid']) {
        echo json_encode(array('success' => false, 'error' => 'Not logged in'));
        exit;
    }
    
    $tid = (int)$mybb->get_input('tid');
    if (!$tid) {
        echo json_encode(array('success' => false, 'error' => 'Invalid thread ID'));
        exit;
    }
    
    $drafts_query = $db->query("
        SELECT d.*, u.username, u.avatar
        FROM " . TABLE_PREFIX . "collab_drafts d
        LEFT JOIN " . TABLE_PREFIX . "users u ON d.uid = u.uid
        WHERE d.tid = '{$tid}'
        ORDER BY d.last_edit DESC, d.dateline DESC
    ");
    
    $drafts = array();
    while ($draft = $db->fetch_array($drafts_query)) {
        $drafts[] = array(
            'draft_id' => $draft['draft_id'],
            'subject' => $draft['subject'],
            'content' => $draft['content'],
            'status' => $draft['status'],
            'created_by' => $draft['username'],
            'created_date' => $draft['dateline'],
            'last_edit' => $draft['last_edit']
        );
    }
    
    echo json_encode(array('success' => true, 'drafts' => $drafts));
    exit;
}

// Handle template requests early (before authentication checks)
if ($mybb->get_input('action') == 'get_template') {
    $template_name = $mybb->get_input('template_name');
            $allowed_templates = array(
                'collaboration_draft_item',
                'collaboration_draft_modal_publish',
                'collaboration_draft_modal_collaborators',
                'collaboration_draft_live_display',
                'collaboration_draft_contribution_item',
                'collaboration_draft_notification',
                'collaboration_draft_archive_item',
                'collaboration_draft_archive_published_item',
                'collaboration_draft_collaborator_item',
                'collaboration_user_item',
                'collaboration_collaborator_item',
                'collaboration_role_item',
                'collaboration_role_span',
                'collaboration_notification',
                'collaboration_custom_modal'
            );
    
    if (in_array($template_name, $allowed_templates)) {
        $template_file = MYBB_ROOT . 'inc/plugins/Thread_Collaboration/Templates/' . $template_name . '.html';
        if (file_exists($template_file)) {
            $template_content = file_get_contents($template_file);
            echo json_encode(array('success' => true, 'template' => $template_content));
        } else {
            echo json_encode(array('success' => false, 'error' => 'Template file not found: ' . $template_file));
        }
    } else {
        echo json_encode(array('success' => false, 'error' => 'Invalid template name: ' . $template_name));
    }
    exit;
}

// Check if user is logged in
if (!$mybb->user['uid']) {
    error_no_permission();
}

// Check if draft page is enabled
if ($mybb->settings['thread_collaboration_enable_draft_page'] != '1') {
    error("Draft page feature is disabled.");
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

// Check if draft page is allowed in this forum
if (!thread_collaboration_is_draft_enabled($thread['fid'])) {
    error("Draft page is not available in this forum.");
}

// Check if user can access draft
if (!thread_collaboration_can_access_chat($tid)) {
    error("You do not have permission to access this draft room.");
}

// Load language
$lang->load("thread_collaboration");

// Set page title
$page_title = $lang->collaboration_draft_title . " - " . htmlspecialchars_uni($thread['subject']);

// Add breadcrumbs
add_breadcrumb($lang->collaboration_draft_title, "collaboration_draft.php?tid={$tid}");
add_breadcrumb(htmlspecialchars_uni($thread['subject']), "showthread.php?tid={$tid}");

// Handle AJAX requests
if ($mybb->get_input('action') == 'save_draft') {
    
    $subject = trim($mybb->get_input('subject'));
    $content = trim($mybb->get_input('content'));
    $draft_id = (int)$mybb->get_input('draft_id');
    
    if (empty($subject) || empty($content)) {
        echo json_encode(array('success' => false, 'error' => 'Subject and content are required.'));
        exit;
    }
    
    if (strlen($subject) > 200) {
        echo json_encode(array('success' => false, 'error' => 'Subject is too long.'));
        exit;
    }
    
    if (strlen($content) > 10000) {
        echo json_encode(array('success' => false, 'error' => 'Content is too long.'));
        exit;
    }
    
    $draft_data = array(
        'tid' => $tid,
        'uid' => $mybb->user['uid'],
        'subject' => $db->escape_string($subject),
        'content' => $db->escape_string($content),
        'dateline' => TIME_NOW,
        'last_edit' => TIME_NOW,
        'status' => 'draft'
    );
    
    if ($draft_id > 0) {
        // Update existing draft
        $db->update_query("collab_drafts", $draft_data, "draft_id='{$draft_id}'");
        $new_draft_id = $draft_id;
    } else {
        // Create new draft
        $new_draft_id = $db->insert_query("collab_drafts", $draft_data);
    }
    
    if ($new_draft_id) {
        
        // Track contribution
        $contribution_data = array(
            'draft_id' => $new_draft_id,
            'uid' => $mybb->user['uid'],
            'action' => 'edit',
            'characters_added' => strlen($content),
            'dateline' => TIME_NOW
        );
        
        $contribution_result = $db->insert_query("collab_draft_contributions", $contribution_data);
        
        echo json_encode(array('success' => true, 'draft_id' => $new_draft_id));
        } else {
        echo json_encode(array('success' => false, 'error' => 'Failed to save draft.'));
    }
    exit;
}

if ($mybb->get_input('action') == 'get_drafts') {
    $drafts_query = $db->query("
        SELECT d.*, u.username, u.avatar
        FROM " . TABLE_PREFIX . "collab_drafts d
        LEFT JOIN " . TABLE_PREFIX . "users u ON d.uid = u.uid
        WHERE d.tid = '{$tid}' AND d.status NOT IN ('published', 'archived')
        ORDER BY d.last_edit DESC
    ");
    
    $drafts = array();
    while ($draft = $db->fetch_array($drafts_query)) {
        $drafts[] = array(
            'draft_id' => $draft['draft_id'],
            'subject' => $draft['subject'],
            'content' => $draft['content'],
            'status' => $draft['status'],
            'created_by' => $draft['username'],
            'created_date' => $draft['dateline'],
            'last_edit' => $draft['last_edit']
        );
    }
    
    echo json_encode(array('success' => true, 'drafts' => $drafts));
    exit;
}

// Get all drafts for Edit Logs (including published)
if ($mybb->get_input('action') == 'get_all_drafts') {
    error_log("=== DEBUG: get_all_drafts called for tid: $tid ===");
    
    $drafts_query = $db->query("
        SELECT d.*, u.username, u.avatar
        FROM " . TABLE_PREFIX . "collab_drafts d
        LEFT JOIN " . TABLE_PREFIX . "users u ON d.uid = u.uid
        WHERE d.tid = '{$tid}'
        ORDER BY d.last_edit DESC
    ");
    
    $drafts = array();
    $draft_count = 0;
    while ($draft = $db->fetch_array($drafts_query)) {
        $drafts[] = array(
            'draft_id' => $draft['draft_id'],
            'subject' => $draft['subject'],
            'content' => $draft['content'],
            'status' => $draft['status'],
            'created_by' => $draft['username'],
            'created_date' => $draft['dateline'],
            'last_edit' => $draft['last_edit']
        );
        $draft_count++;
        error_log("DEBUG: Found draft - ID: {$draft['draft_id']}, Subject: {$draft['subject']}, Status: {$draft['status']}");
    }
    
    error_log("DEBUG: Total drafts found: $draft_count");
    
    echo json_encode(array('success' => true, 'drafts' => $drafts));
    exit;
}

if ($mybb->get_input('action') == 'get_archive') {
    
    $archive_query = $db->query("
        SELECT d.*, u.username, u.avatar, 
               archived_user.username as archived_by_username
        FROM " . TABLE_PREFIX . "collab_drafts d
        LEFT JOIN " . TABLE_PREFIX . "users u ON d.uid = u.uid
        LEFT JOIN " . TABLE_PREFIX . "users archived_user ON d.archived_by = archived_user.uid
        WHERE d.tid = '{$tid}' AND (d.status = 'published' OR d.status = 'archived')
        ORDER BY 
            CASE 
                WHEN d.status = 'published' THEN d.published_date 
                WHEN d.status = 'archived' THEN d.archived_date 
                ELSE d.dateline 
            END DESC
    ");
    
    $drafts = array();
    while ($draft = $db->fetch_array($archive_query)) {
        $drafts[] = array(
            'draft_id' => $draft['draft_id'],
            'subject' => $draft['subject'],
            'content' => $draft['content'],
            'status' => $draft['status'],
            'created_by' => $draft['username'],
            'created_date' => $draft['dateline'],
            'published_date' => $draft['published_date'],
            'archived_date' => $draft['archived_date'],
            'archived_by' => $draft['archived_by_username'],
            'last_edit' => $draft['last_edit']
        );
    }
    
    echo json_encode(array('success' => true, 'drafts' => $drafts));
    exit;
}

if ($mybb->get_input('action') == 'get_draft') {
    $draft_id = (int)$mybb->get_input('draft_id');
    
    $draft_query = $db->query("
        SELECT d.*, u.username, u.avatar
        FROM " . TABLE_PREFIX . "collab_drafts d
        LEFT JOIN " . TABLE_PREFIX . "users u ON d.uid = u.uid
        WHERE d.draft_id = '{$draft_id}' AND d.tid = '{$tid}'
    ");
    $draft = $db->fetch_array($draft_query);
    
    if ($draft) {
        echo json_encode(array('success' => true, 'draft' => $draft));
    } else {
        echo json_encode(array('success' => false, 'error' => 'Draft not found.'));
    }
    exit;
}

if ($mybb->get_input('action') == 'delete_draft') {
    $draft_id = (int)$mybb->get_input('draft_id');
    
    // Check if user can delete this draft
    $draft_query = $db->simple_select("collab_drafts", "*", "draft_id='{$draft_id}' AND tid='{$tid}'", array('limit' => 1));
    $draft = $db->fetch_array($draft_query);
    
    if (!$draft) {
        echo json_encode(array('success' => false, 'error' => 'Draft not found.'));
        exit;
    }
    
    $can_delete = ($draft['uid'] == $mybb->user['uid'] || thread_collaboration_can_moderate_chat($tid));
    if (!$can_delete) {
        echo json_encode(array('success' => false, 'error' => 'You do not have permission to delete this draft.'));
        exit;
    }
    
    // Check if draft is already archived
    if ($draft['status'] == 'archived') {
        // Permanently delete the draft and its related data
        $db->delete_query("collab_draft_edit_logs", "draft_id='{$draft_id}'");
    $db->delete_query("collab_draft_contributions", "draft_id='{$draft_id}'");
        $result = $db->delete_query("collab_drafts", "draft_id='{$draft_id}'");
        
        if ($result) {
            echo json_encode(array('success' => true, 'message' => 'Draft permanently deleted'));
        } else {
            echo json_encode(array('success' => false, 'error' => 'Failed to delete draft'));
        }
    } else {
        // Archive draft instead of deleting it
        $archive_data = array(
            'status' => 'archived',
            'archived_date' => TIME_NOW,
            'archived_by' => $mybb->user['uid']
        );
        
        $result = $db->update_query("collab_drafts", $archive_data, "draft_id='{$draft_id}'");
        
        if ($result) {
            echo json_encode(array('success' => true, 'message' => 'Draft moved to archive'));
        } else {
            echo json_encode(array('success' => false, 'error' => 'Failed to archive draft'));
        }
    }
    exit;
}

if ($mybb->get_input('action') == 'publish_draft') {
    // Start output buffering to prevent any output before JSON
    ob_start();
    
    // Disable error display and enable logging
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
    
    $draft_id = (int)$mybb->get_input('draft_id');
    
    try {
    // Get draft
        $draft_query = $db->simple_select("collab_drafts", "*", "draft_id='{$draft_id}' AND tid='{$tid}'", array('limit' => 1));
        $draft = $db->fetch_array($draft_query);

        if (!$draft) {
            ob_clean();
        echo json_encode(array('success' => false, 'error' => 'Draft not found.'));
        exit;
    }
    
        // Create post
    $post_data = array(
        'tid' => $tid,
        'uid' => $mybb->user['uid'],
        'subject' => $draft['subject'],
        'message' => $draft['content'],
        'dateline' => TIME_NOW,
            'ipaddress' => get_ip(),
            'visible' => 1,
            'posthash' => md5($mybb->user['uid'] . TIME_NOW . rand())
        );
        
        // Use MyBB's standard PostDataHandler approach
        require_once MYBB_ROOT."inc/datahandlers/post.php";
        $posthandler = new PostDataHandler("insert");
        
        // Get thread info with thread owner's username
        $thread_query = $db->query("
            SELECT t.*, u.username as thread_owner_username
            FROM " . TABLE_PREFIX . "threads t
            LEFT JOIN " . TABLE_PREFIX . "users u ON t.uid = u.uid
            WHERE t.tid = '{$tid}'
        ");
        $thread = $db->fetch_array($thread_query);
        
        if (!$thread) {
            ob_clean();
            echo json_encode(array('success' => false, 'error' => 'Thread not found.'));
            exit;
        }
        
        // Check draft management settings
        $publish_as_author = false;
        $allow_collaborators_publish = false;
        $publishing_permissions = 'all';
        $allowed_roles = array();
        $allowed_collaborators = array();
        
        // Get draft management settings
        $settings_query = $db->simple_select("collab_draft_settings", "*", "tid='{$tid}'", array('limit' => 1));
        $settings = $db->fetch_array($settings_query);
        
        if ($settings) {
            $allow_collaborators_publish = $settings['allow_collaborators_publish'];
            $publish_as_author = $settings['publish_as_author'];
            $publishing_permissions = $settings['publishing_permissions'];
            $allowed_roles = $settings['allowed_roles'] ? json_decode($settings['allowed_roles'], true) : array();
            $allowed_collaborators = $settings['allowed_collaborators'] ? json_decode($settings['allowed_collaborators'], true) : array();
        }
        
        // Check if user can publish (thread owner, moderator, management usergroup, or collaborator with permission)
        $can_publish = false;
        
        // Thread owner can always publish
        if ($mybb->user['uid'] == $thread['uid']) {
            $can_publish = true;
        }
        
        // Check if user is a forum moderator
        if (!$can_publish && is_moderator($thread['fid'])) {
            $can_publish = true;
        }
        
        // Check if user is in management usergroups
        if (!$can_publish) {
            $management_groups = array();
            if (!empty($mybb->settings['thread_collaboration_moderator_usergroups'])) {
                $management_groups = explode(',', $mybb->settings['thread_collaboration_moderator_usergroups']);
            }
            if (in_array($mybb->user['usergroup'], $management_groups)) {
                $can_publish = true;
            }
        }
        
        // If user is not thread owner, moderator, or management user, check collaborator publishing permissions
        if (!$can_publish) {
            // If collaborators can't publish, only thread owner/moderator/management can
            if (!$allow_collaborators_publish) {
                ob_clean();
                echo json_encode(array('success' => false, 'error' => 'Only thread owner, moderators, and management users can publish drafts.'));
                exit;
            }
            
            // Check specific publishing permissions
            if ($publishing_permissions == 'all') {
                $can_publish = true;
            } elseif ($publishing_permissions == 'primary') {
                // Check if user is a primary contributor
                $primary_query = $db->simple_select("collab_contributor_posts", "is_primary_author", "pid='{$draft['published_post_id']}' AND uid='{$mybb->user['uid']}'", array('limit' => 1));
                $primary = $db->fetch_array($primary_query);
                $can_publish = $primary && $primary['is_primary_author'];
            } elseif ($publishing_permissions == 'collaborators') {
                // Check if user is in allowed collaborators list
                $can_publish = in_array($mybb->user['uid'], $allowed_collaborators);
            }
            }
            
            if (!$can_publish) {
                ob_clean();
                echo json_encode(array('success' => false, 'error' => 'You do not have permission to publish drafts.'));
                exit;
        }
        
        // Set up post data using MyBB's standard format
        if ($publish_as_author && $mybb->user['uid'] != $thread['uid']) {
            // Publish as the collaborator (publisher)
        $post = array(
            'tid' => $tid,
            'fid' => $thread['fid'],
                'subject' => $thread['subject'],
                'uid' => $mybb->user['uid'], // Publisher as post author
                'username' => $mybb->user['username'], // Publisher's username
            'message' => $draft['content'], // Only publish the message content
            'ipaddress' => get_ip(),
            'posthash' => md5($mybb->user['uid'] . TIME_NOW . rand()),
            'options' => array(
                'signature' => 0,
                'subscriptionmethod' => '',
                'disablesmilies' => 0
            )
        );
        } else {
            // Publish as thread owner (default behavior)
        $post = array(
            'tid' => $tid,
            'fid' => $thread['fid'],
                'subject' => $thread['subject'],
                'uid' => $thread['uid'], // Thread owner as post author
                'username' => $thread['thread_owner_username'], // Thread owner's username
            'message' => $draft['content'], // Only publish the message content
            'ipaddress' => get_ip(),
            'posthash' => md5($mybb->user['uid'] . TIME_NOW . rand()),
            'options' => array(
                'signature' => 0,
                'subscriptionmethod' => '',
                'disablesmilies' => 0
            )
        );
        }
        
        // Use MyBB's standard PostDataHandler
        $posthandler->set_data($post);
        
        if ($posthandler->validate_post()) {
            $postinfo = $posthandler->insert_post();
            $post_id = $postinfo['pid'];
        } else {
            $post_id = false;
        }
        
        if ($post_id) {
            // Mark draft as published and set published date and post ID
        $db->update_query("collab_drafts", array(
            'status' => 'published',
                'published_date' => TIME_NOW,
                'published_post_id' => $post_id
        ), "draft_id='{$draft_id}'");
        
            // Register all contributors for multi-author reputation system
            thread_collaboration_register_contributors($post_id, $tid, $draft_id);
        
            ob_clean();
            echo json_encode(array('success' => true, 'post_id' => $post_id));
    } else {
            ob_clean();
        echo json_encode(array('success' => false, 'error' => 'Failed to publish draft.'));
        }
    } catch (Exception $e) {
        ob_clean();
        echo json_encode(array('success' => false, 'error' => 'Server error: ' . $e->getMessage()));
    }
    
    exit;
}

if ($mybb->get_input('action') == 'save_draft_settings') {
    try {
        // Check if user is thread owner or moderator
        $thread_query = $db->simple_select("threads", "uid", "tid='{$tid}'", array('limit' => 1));
        $thread = $db->fetch_array($thread_query);
        
        $can_manage = false;
        if ($thread && $mybb->user['uid'] == $thread['uid']) {
            $can_manage = true; // Thread owner
        } else {
            // Check if user is moderator/management
            $management_groups = array();
            if (!empty($mybb->settings['thread_collaboration_moderator_usergroups'])) {
                $management_groups = explode(',', $mybb->settings['thread_collaboration_moderator_usergroups']);
            }
            if (in_array($mybb->user['usergroup'], $management_groups)) {
                $can_manage = true;
            }
        }
        
        if (!$can_manage) {
            echo json_encode(array('success' => false, 'error' => 'You do not have permission to manage draft settings.'));
            exit;
        }
        
        $allow_collaborators_publish = (int)$mybb->get_input('allow_collaborators_publish');
        $publish_as_author = (int)$mybb->get_input('publish_as_author');
        $publishing_permissions = $db->escape_string($mybb->get_input('publishing_permissions'));
        $allowed_collaborators = $db->escape_string($mybb->get_input('allowed_collaborators'));
        
        // Debug: Log the received data
        error_log("Draft settings save attempt - TID: {$tid}, Publishing: {$publishing_permissions}, Collaborators: {$allowed_collaborators}");
        
        // Validate JSON for allowed_collaborators
        if (!empty($allowed_collaborators)) {
            $decoded_collaborators = json_decode($allowed_collaborators, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception("Invalid JSON for allowed_collaborators: " . json_last_error_msg());
            }
        }
        
        // Check if settings already exist
        $existing_query = $db->simple_select("collab_draft_settings", "id", "tid='{$tid}'", array('limit' => 1));
        $existing = $db->fetch_array($existing_query);
        
        $settings_data = array(
            'tid' => $tid,
            'allow_collaborators_publish' => $allow_collaborators_publish,
            'publish_as_author' => $publish_as_author,
            'publishing_permissions' => $publishing_permissions,
            'allowed_collaborators' => $allowed_collaborators,
            'created_by' => $mybb->user['uid'],
            'created_date' => TIME_NOW,
            'updated_date' => TIME_NOW
        );
        
        if ($existing) {
            // Update existing settings
            $result = $db->update_query("collab_draft_settings", $settings_data, "tid='{$tid}'");
            if (!$result) {
                throw new Exception("Failed to update settings: " . $db->error_string());
            }
        } else {
            // Insert new settings
            $result = $db->insert_query("collab_draft_settings", $settings_data);
            if (!$result) {
                throw new Exception("Failed to insert settings: " . $db->error_string());
            }
        }
        
        echo json_encode(array('success' => true, 'message' => 'Draft settings saved successfully.'));
        exit;
        
    } catch (Exception $e) {
        error_log("Draft settings save error: " . $e->getMessage());
        echo json_encode(array('success' => false, 'error' => 'Database error: ' . $e->getMessage()));
        exit;
    }
}

if ($mybb->get_input('action') == 'get_draft_settings') {
    $settings_query = $db->simple_select("collab_draft_settings", "*", "tid='{$tid}'", array('limit' => 1));
    $settings = $db->fetch_array($settings_query);
    
    if ($settings) {
        echo json_encode(array('success' => true, 'settings' => $settings));
    } else {
        // Return default settings
        $default_settings = array(
            'allow_collaborators_publish' => 0,
            'publish_as_author' => 0,
            'publishing_permissions' => 'all',
            'allowed_collaborators' => json_encode(array())
        );
        echo json_encode(array('success' => true, 'settings' => $default_settings));
    }
    exit;
}

if ($mybb->get_input('action') == 'get_thread_collaborators') {
    $collaborators_query = $db->query("
        SELECT tc.uid, tc.role, tc.role_icon, u.username, u.avatar, u.avatardimensions
        FROM " . TABLE_PREFIX . "thread_collaborators tc
        LEFT JOIN " . TABLE_PREFIX . "users u ON tc.uid = u.uid
        WHERE tc.tid = '{$tid}'
        ORDER BY u.username ASC
    ");
    
    $collaborators = array();
    while ($collaborator = $db->fetch_array($collaborators_query)) {
        $collaborators[] = array(
            'uid' => $collaborator['uid'],
            'username' => $collaborator['username'],
            'role' => $collaborator['role'],
            'role_icon' => $collaborator['role_icon'],
            'avatar' => $collaborator['avatar'],
            'avatardimensions' => $collaborator['avatardimensions']
        );
    }
    
    echo json_encode(array('success' => true, 'collaborators' => $collaborators));
    exit;
}

if ($mybb->get_input('action') == 'get_archive') {
    $draft_id = (int)$mybb->get_input('draft_id');
    $content_before = $mybb->get_input('content_before');
    $content_after = $mybb->get_input('content_after');
    $action = $mybb->get_input('action_type', 'edit');
    
    if ($draft_id > 0 && $content_after !== null) {
        // Calculate changes
        $chars_before = strlen($content_before);
        $chars_after = strlen($content_after);
        $chars_added = max(0, $chars_after - $chars_before);
        $chars_removed = max(0, $chars_before - $chars_after);
        
        // Calculate word changes
        $words_before = str_word_count($content_before);
        $words_after = str_word_count($content_after);
        $words_added = max(0, $words_after - $words_before);
        $words_removed = max(0, $words_before - $words_after);
        
        // Create edit summary
        $edit_summary = '';
        if ($chars_added > 0) $edit_summary .= "Added {$chars_added} characters";
        if ($chars_removed > 0) {
            if ($edit_summary) $edit_summary .= ", ";
            $edit_summary .= "Removed {$chars_removed} characters";
        }
        if ($words_added > 0) $edit_summary .= " ({$words_added} words)";
        
        // Log the edit
        $log_data = array(
            'draft_id' => $draft_id,
            'uid' => $mybb->user['uid'],
            'action' => $action,
            'content_before' => $content_before,
            'content_after' => $content_after,
            'characters_added' => $chars_added,
            'characters_removed' => $chars_removed,
            'words_added' => $words_added,
            'words_removed' => $words_removed,
            'edit_summary' => $edit_summary,
            'dateline' => TIME_NOW,
            'ip_address' => inet_pton(get_ip())
        );
        
        // Use direct SQL with proper escaping to avoid issues with special characters
        $sql = "INSERT INTO " . TABLE_PREFIX . "collab_draft_edit_logs 
                (draft_id, uid, action, content_before, content_after, characters_added, characters_removed, words_added, words_removed, edit_summary, dateline, ip_address) 
                VALUES (
                    '" . (int)$draft_id . "',
                    '" . (int)$mybb->user['uid'] . "',
                    '" . $db->escape_string($action) . "',
                    '" . $db->escape_string($content_before) . "',
                    '" . $db->escape_string($content_after) . "',
                    '" . (int)$chars_added . "',
                    '" . (int)$chars_removed . "',
                    '" . (int)$words_added . "',
                    '" . (int)$words_removed . "',
                    '" . $db->escape_string($edit_summary) . "',
                    '" . (int)TIME_NOW . "',
                    ''
                )";
        
        $result = $db->query($sql);
        
        echo json_encode(array(
            'success' => true,
            'chars_added' => $chars_added,
            'chars_removed' => $chars_removed,
            'words_added' => $words_added,
            'words_removed' => $words_removed,
            'edit_summary' => $edit_summary
        ));
    } else {
        echo json_encode(array('success' => false, 'error' => 'Invalid parameters'));
    }
    exit;
}

if ($mybb->get_input('action') == 'get_edit_logs') {
    $draft_id = (int)$mybb->get_input('draft_id');
    
    if ($draft_id > 0) {
        $logs_query = $db->query("
            SELECT el.*, u.username
            FROM " . TABLE_PREFIX . "collab_draft_edit_logs el
            LEFT JOIN " . TABLE_PREFIX . "users u ON el.uid = u.uid
            WHERE el.draft_id = '{$draft_id}'
            ORDER BY el.dateline DESC
        ");
        
        $logs = array();
        while ($log = $db->fetch_array($logs_query)) {
            $logs[] = array(
                'log_id' => $log['log_id'],
                'username' => $log['username'],
                'avatar' => '',
                'avatardimensions' => '',
                'action' => $log['action'],
                'characters_added' => $log['characters_added'],
                'characters_removed' => $log['characters_removed'],
                'words_added' => $log['words_added'],
                'words_removed' => $log['words_removed'],
                'edit_summary' => $log['edit_summary'],
                'dateline' => $log['dateline'],
                'formatted_time' => date('Y-m-d H:i:s', $log['dateline'])
            );
        }
        
        echo json_encode(array('success' => true, 'logs' => $logs));
    } else {
        echo json_encode(array('success' => false, 'error' => 'Invalid draft ID'));
    }
    exit;
}

if ($mybb->get_input('action') == 'get_edit_contributions') {
    $draft_id = (int)$mybb->get_input('draft_id');
    
    if ($draft_id > 0) {
        // Check if new columns exist for advanced tracking
        $columns_check = $db->query("SHOW COLUMNS FROM " . TABLE_PREFIX . "collab_draft_edit_logs LIKE 'actual_additions'");
        $has_advanced_tracking = $db->num_rows($columns_check) > 0;
        
        if ($has_advanced_tracking) {
            // Use advanced tracking with actual additions/removals
            $contributions_query = $db->query("
                SELECT el.uid, u.username, u.avatar, u.avatardimensions,
                       SUM(COALESCE(LENGTH(el.actual_additions), 0)) as total_chars_added,
                       SUM(COALESCE(LENGTH(el.actual_removals), 0)) as total_chars_removed,
                       SUM(el.words_added) as total_words_added,
                       SUM(el.words_removed) as total_words_removed,
                       COUNT(el.log_id) as edit_count
                FROM " . TABLE_PREFIX . "collab_draft_edit_logs el
                LEFT JOIN " . TABLE_PREFIX . "users u ON el.uid = u.uid
                WHERE el.draft_id = '{$draft_id}'
                GROUP BY el.uid
                ORDER BY total_chars_added DESC
            ");
        } else {
            // Fallback to original method
        $contributions_query = $db->query("
            SELECT el.uid, u.username, u.avatar, u.avatardimensions,
                   SUM(el.characters_added) as total_chars_added,
                   SUM(el.characters_removed) as total_chars_removed,
                   SUM(el.words_added) as total_words_added,
                   SUM(el.words_removed) as total_words_removed,
                   COUNT(el.log_id) as edit_count
            FROM " . TABLE_PREFIX . "collab_draft_edit_logs el
            LEFT JOIN " . TABLE_PREFIX . "users u ON el.uid = u.uid
            WHERE el.draft_id = '{$draft_id}'
            GROUP BY el.uid
            ORDER BY total_chars_added DESC
        ");
        }
        
        $contributions = array();
        $total_chars = 0;
        
        while ($contrib = $db->fetch_array($contributions_query)) {
            $contributions[] = $contrib;
            $total_chars += $contrib['total_chars_added'];
        }
        
        // Calculate percentages
        foreach ($contributions as &$contrib) {
            $contrib['percentage'] = $total_chars > 0 ? round(($contrib['total_chars_added'] / $total_chars) * 100, 1) : 0;
        }
        
        echo json_encode(array(
            'success' => true, 
            'contributions' => $contributions,
            'total_chars' => $total_chars
        ));
    } else {
        echo json_encode(array('success' => false, 'error' => 'Invalid draft ID'));
    }
    exit;
}

if ($mybb->get_input('action') == 'get_draft_collaborators') {
    $draft_id = (int)$mybb->get_input('draft_id');
    
    // Get only collaborators who have contributed to this specific draft using the new edit logs system
    $contributors_query = $db->query("
        SELECT DISTINCT el.uid, u.username, u.avatar, u.avatardimensions,
               SUM(el.characters_added) as total_contributions
        FROM " . TABLE_PREFIX . "collab_draft_edit_logs el
        LEFT JOIN " . TABLE_PREFIX . "users u ON el.uid = u.uid
        WHERE el.draft_id = '{$draft_id}'
        GROUP BY el.uid
        ORDER BY total_contributions DESC
    ");
    
    $contributors = array();
    while ($contributor = $db->fetch_array($contributors_query)) {
        // Handle avatar path
        $avatar_path = '';
        if (!empty($contributor['avatar'])) {
            if (strpos($contributor['avatar'], 'http') === 0) {
                // External avatar URL
                $avatar_path = $contributor['avatar'];
            } else {
                // Local avatar file
                $avatar_path = $mybb->settings['bburl'] . '/uploads/avatars/' . $contributor['avatar'];
            }
        } else {
            // Default avatar
            $avatar_path = $mybb->settings['bburl'] . '/images/default_avatar.png';
        }
        
        // Get user's roles in this thread
        $roles_query = $db->simple_select("thread_collaborators", "role, role_icon", "tid='{$tid}' AND uid='{$contributor['uid']}'");
        $roles = array();
        while ($role = $db->fetch_array($roles_query)) {
            $roles[] = $role;
        }
        
        // If no roles found, check if user is thread owner
        if (empty($roles) && $contributor['uid'] == $thread['uid']) {
            $roles[] = array(
                'role' => 'Owner',
                'role_icon' => 'fas fa-crown'
            );
        }
        
        // If still no roles, add a default contributor role
        if (empty($roles)) {
            $roles[] = array(
                'role' => 'Contributor',
                'role_icon' => 'fas fa-user-edit'
            );
        }
        
        $contributors[] = array(
            'uid' => $contributor['uid'],
            'username' => $contributor['username'],
            'avatar' => $avatar_path,
            'avatardimensions' => $contributor['avatardimensions'],
            'roles' => $roles,
            'total_contributions' => (int)$contributor['total_contributions']
        );
    }
    
    echo json_encode(array('success' => true, 'collaborators' => $contributors));
    exit;
}

if ($mybb->get_input('action') == 'get_online_users') {
    // Use the existing function that properly handles online users
    $online_users = thread_collaboration_get_online_collaborators($tid);
    
    // Convert to the format expected by JavaScript
    $formatted_users = array();
    foreach ($online_users as $user) {
        $formatted_users[] = array(
            'uid' => $user['uid'],
            'username' => $user['username'],
            'avatar' => $user['avatar'], // Use actual avatar from database
            'role' => $user['role'],
            'role_icon' => $user['role_icon']
        );
    }
    
    echo json_encode(array('success' => true, 'users' => $formatted_users));
    exit;
}

if ($mybb->get_input('action') == 'get_collaborators') {
    $collaborators = thread_collaboration_get_thread_collaborators($tid);
    
    $collaborators_data = array();
    foreach ($collaborators as $collaborator) {
        // Pass the full roles array like the chat page
        $collaborators_data[] = array(
            'uid' => $collaborator['uid'],
            'username' => $collaborator['username'],
            'avatar' => $collaborator['avatar'], // Use actual avatar from database
            'roles' => $collaborator['roles'] // Pass all roles like chat page
        );
    }
    
    echo json_encode(array('success' => true, 'collaborators' => $collaborators_data));
    exit;
}

if ($mybb->get_input('action') == 'get_user_role') {
    // Get user's role in this thread
    $user_role = 'Collaborator';
    $user_role_icon = 'fas fa-user';
    
    // Check if user is thread owner
    $thread_query = $db->query("
        SELECT t.uid as owner_uid
        FROM " . TABLE_PREFIX . "threads t
        WHERE t.tid = '{$tid}'
    ");
    $thread_data = $db->fetch_array($thread_query);
    
    if ($thread_data && $thread_data['owner_uid'] == $mybb->user['uid']) {
        $user_role = 'Owner';
        $user_role_icon = 'fas fa-crown';
    } else {
        // Check if user has specific roles in thread_collaborators
        $role_query = $db->query("
            SELECT role, role_icon
            FROM " . TABLE_PREFIX . "thread_collaborators
            WHERE tid = '{$tid}' AND uid = '{$mybb->user['uid']}'
            ORDER BY role ASC
            LIMIT 1
        ");
        
        if ($db->num_rows($role_query) > 0) {
            $role_data = $db->fetch_array($role_query);
            $user_role = $role_data['role'];
            $user_role_icon = $role_data['role_icon'] ?: 'fas fa-user';
        }
    }
    
    echo json_encode(array(
        'success' => true,
        'role' => $user_role,
        'role_icon' => $user_role_icon
    ));
    exit;
}

if ($mybb->get_input('action') == 'get_message_count') {
    // Get total message count for this thread
    $count_query = $db->query("
        SELECT COUNT(*) as message_count
        FROM " . TABLE_PREFIX . "posts
        WHERE tid = '{$tid}'
    ");
    $count_data = $db->fetch_array($count_query);
    
    echo json_encode(array(
        'success' => true,
        'count' => (int)$count_data['message_count']
    ));
    exit;
}

if ($mybb->get_input('action') == 'save_draft_view_preference') {
    $expanded = (bool)$mybb->get_input('expanded');
    
    // Check if setting already exists
    $existing_query = $db->simple_select("collab_chat_settings", "setting_id", "tid='{$tid}' AND uid='{$mybb->user['uid']}' AND setting_name='draft_view_expanded'", array('limit' => 1));
    
    if ($db->num_rows($existing_query) > 0) {
        // Update existing setting
        $db->update_query("collab_chat_settings", array(
            'setting_value' => $expanded ? 1 : 0,
            'dateline' => time()
        ), "tid='{$tid}' AND uid='{$mybb->user['uid']}' AND setting_name='draft_view_expanded'");
    } else {
        // Insert new setting
        $db->insert_query("collab_chat_settings", array(
            'tid' => $tid,
            'uid' => $mybb->user['uid'],
            'setting_name' => 'draft_view_expanded',
            'setting_value' => $expanded ? 1 : 0,
            'dateline' => time()
        ));
    }
    
    echo json_encode(array('success' => true));
    exit;
}

// Get collaborators
$collaborators = thread_collaboration_get_thread_collaborators($tid);

// Get draft view expanded preference
$draft_view_expanded = false;
$preference_query = $db->simple_select("collab_chat_settings", "setting_value", "tid='{$tid}' AND uid='{$mybb->user['uid']}' AND setting_name='draft_view_expanded'", array('limit' => 1));
if ($db->num_rows($preference_query) > 0) {
    $preference = $db->fetch_array($preference_query);
    $draft_view_expanded = (bool)$preference['setting_value'];
}

// Generate chat button HTML based on settings
$chat_button_html = '';
if (thread_collaboration_is_chat_enabled($thread['fid'])) {
    $chat_button_html = '<a href="collaboration_chats.php?tid=' . $tid . '" class="draft-btn" title="Return to Chat">
        <i class="fas fa-comments"></i> ' . $lang->collaboration_draft_back_to_chat . '
    </a>';
}

// Load template directly from file
$template_file = MYBB_ROOT . 'inc/plugins/Thread_Collaboration/Templates/collaboration_draft.html';
if (!file_exists($template_file)) {
    die("Template file not found");
}

$template_content = file_get_contents($template_file);

// Check if user can manage draft settings (thread owner or moderator)
$can_manage_draft_settings = false;
if ($mybb->user['uid'] == $thread['uid']) {
    $can_manage_draft_settings = true; // Thread owner
} else {
    // Check if user is moderator/management
    $management_groups = array();
    if (!empty($mybb->settings['thread_collaboration_moderator_usergroups'])) {
        $management_groups = explode(',', $mybb->settings['thread_collaboration_moderator_usergroups']);
    }
    if (in_array($mybb->user['usergroup'], $management_groups)) {
        $can_manage_draft_settings = true;
    }
}

// Replace template variables manually
$page = $template_content;
$page = str_replace('{$thread_subject}', htmlspecialchars_uni($thread['subject']), $page);
$page = str_replace('{$tid}', $tid, $page);
$page = str_replace('{$mybb->user[\'username\']}', htmlspecialchars_uni($mybb->user['username']), $page);
$page = str_replace('{$mybb->user[\'username\']}', htmlspecialchars_uni($mybb->user['username']), $page);
$page = str_replace('{$mybb->user[\'username\'][0]}', strtoupper(substr($mybb->user['username'], 0, 1)), $page);
$page = str_replace('{$mybb->user[\'avatar\']}', htmlspecialchars_uni($mybb->user['avatar']), $page);
$page = str_replace('{$mybb->user[\'uid\']}', (int)$mybb->user['uid'], $page);
$page = str_replace('{$draft_view_expanded}', $draft_view_expanded ? 'expanded' : '', $page);
$page = str_replace('{$chat_button_html}', $chat_button_html, $page);
$page = str_replace('{$can_manage_draft_settings}', $can_manage_draft_settings ? 'true' : 'false', $page);

// Generate floating settings icon based on permissions
$floating_settings_icon = '';
if ($can_manage_draft_settings) {
    $floating_settings_icon = '<div class="floating-settings-icon" id="floating-settings-icon">
        <i class="fas fa-cog"></i>
    </div>';
}
$page = str_replace('{$floating_settings_icon}', $floating_settings_icon, $page);

// Handle JavaScript template variables safely
$page = str_replace('{$current_user_id}', (int)$mybb->user['uid'], $page);
$page = str_replace('{$current_user_js}', addslashes(htmlspecialchars_uni($mybb->user['username'])), $page);

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
