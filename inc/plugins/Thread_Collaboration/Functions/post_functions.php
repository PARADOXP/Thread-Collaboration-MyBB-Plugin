<?php
// Disallow direct access
if(!defined("IN_MYBB"))
{
    die("Direct initialization of this file is not allowed.<br /><br />Please make sure IN_MYBB is defined.");
}

// Check if user can access collaboration chat
if (!function_exists('thread_collaboration_can_access_chat'))
{
function thread_collaboration_can_access_chat($tid)
{
    global $mybb, $db;
    
    // Check if user is logged in
    if (!$mybb->user['uid']) {
        return false;
    }
    
    // Check if user is thread owner
    $thread_query = $db->simple_select("threads", "uid", "tid='{$tid}'", array('limit' => 1));
    $thread = $db->fetch_array($thread_query);
    if ($thread && $mybb->user['uid'] == $thread['uid']) {
        return true;
    }
    
    // Check if user is a collaborator
    $collaborator_query = $db->simple_select("thread_collaborators", "cid", "tid='{$tid}' AND uid='{$mybb->user['uid']}'", array('limit' => 1));
    if ($collaborator_query && $db->num_rows($collaborator_query) > 0) {
        return true;
    }
    
    // Check if user is in moderator/management usergroups
    $management_groups = array();
    if (!empty($mybb->settings['thread_collaboration_moderator_usergroups'])) {
        $management_groups = explode(',', $mybb->settings['thread_collaboration_moderator_usergroups']);
    }
    if (in_array($mybb->user['usergroup'], $management_groups)) {
        return true;
    }
    
    return false;
}
}

// Get collaboration chat messages
if (!function_exists('thread_collaboration_get_chat_messages'))
{
function thread_collaboration_get_chat_messages($tid, $limit = 50, $offset = 0, $last_message_id = 0)
{
    global $db;
    
    $where = "tid='{$tid}'";
    if ($last_message_id > 0) {
        $where .= " AND message_id > '{$last_message_id}'";
    }
    
    // Ensure offset is never negative
    $offset = max(0, (int)$offset);
    
    $query = $db->query("
        SELECT cm.*, u.username, u.usergroup, u.displaygroup, g.usertitle, g.namestyle, g.image
        FROM " . TABLE_PREFIX . "collab_chat_messages cm
        LEFT JOIN " . TABLE_PREFIX . "users u ON cm.uid = u.uid
        LEFT JOIN " . TABLE_PREFIX . "usergroups g ON u.usergroup = g.gid
        WHERE {$where}
        ORDER BY cm.dateline ASC
        LIMIT {$limit} OFFSET {$offset}
    ");
    
    
    $count_query = $db->query("SELECT COUNT(*) as total FROM " . TABLE_PREFIX . "collab_chat_messages WHERE tid='{$tid}'");
    $count_result = $db->fetch_array($count_query);
    
    
    $messages = array();
    while ($message = $db->fetch_array($query)) {
        $messages[] = $message;
    }
    
    return $messages;
}
}

// Post a chat message
if (!function_exists('thread_collaboration_post_chat_message'))
{
function thread_collaboration_post_chat_message($tid, $message, $reply_to = 0, $is_system = false, $system_type = null)
{
    global $mybb, $db;
    
    if (!thread_collaboration_can_access_chat($tid)) {
        return false;
    }
    
    $message_data = array(
        'tid' => (int)$tid,
        'uid' => (int)$mybb->user['uid'],
        'message' => $db->escape_string($message),
        'reply_to' => (int)$reply_to,
        'dateline' => TIME_NOW,
        'ip_address' => $db->escape_string(get_ip()),
        'is_system' => $is_system ? 1 : 0,
        'system_type' => $db->escape_string($system_type)
    );
    
    $message_id = $db->insert_query("collab_chat_messages", $message_data);
    
    if ($message_id) {
        // Get the full message data with user info
        $query = $db->query("
            SELECT cm.*, u.username, u.usergroup, u.displaygroup, g.usertitle, g.namestyle, g.image
            FROM " . TABLE_PREFIX . "collab_chat_messages cm
            LEFT JOIN " . TABLE_PREFIX . "users u ON cm.uid = u.uid
            LEFT JOIN " . TABLE_PREFIX . "usergroups g ON u.usergroup = g.gid
            WHERE cm.message_id = '{$message_id}'
        ");
        
        return $db->fetch_array($query);
    }
    
    return false;
}
}

// Get user's collaboration role for a thread
if (!function_exists('thread_collaboration_get_user_role'))
{
function thread_collaboration_get_user_role($tid, $uid)
{
    global $db;
    
    // Check if user is thread owner
    $thread_query = $db->simple_select("threads", "uid", "tid='{$tid}'", array('limit' => 1));
    $thread = $db->fetch_array($thread_query);
    if ($thread && $uid == $thread['uid']) {
        return array('role' => 'Owner', 'role_icon' => 'fas fa-crown');
    }
    
    // Check if user is a collaborator
    $collaborator_query = $db->simple_select("thread_collaborators", "role, role_icon", "tid='{$tid}' AND uid='{$uid}'", array('limit' => 1));
    $collaborator = $db->fetch_array($collaborator_query);
    if ($collaborator) {
        return array('role' => $collaborator['role'], 'role_icon' => $collaborator['role_icon']);
    }
    
    return array('role' => 'Guest', 'role_icon' => 'fas fa-user');
}
}

// Format chat message for display
if (!function_exists('thread_collaboration_format_chat_message'))
{
function thread_collaboration_format_chat_message($message, $user_info, $role_info)
{
    global $mybb;
    
    // Check if user_info is valid
    if (empty($user_info) || !isset($user_info['username']) || empty($user_info['username'])) {
        
        $user_info = array('username' => 'Unknown User', 'uid' => 0, 'usertitle' => '', 'namestyle' => '');
    }
    
    $username = htmlspecialchars_uni($user_info['username']);
    
    
    if (strpos($user_info['username'], '{username}') !== false) {
    }
    
    
    
    // Debug to debug.txt file
    $debug_info .= "Message ID: " . $message['message_id'] . "\n";
    $debug_info .= "User UID: " . $message['uid'] . "\n";
    $debug_info .= "User Info: " . print_r($user_info, true) . "\n";
    $debug_info .= "Username (raw): '" . $user_info['username'] . "'\n";
    $debug_info .= "Username (escaped): '" . $username . "'\n";
    $debug_info .= "Username Link: " . $username_link . "\n";
    $debug_info .= "Role Info: " . print_r($role_info, true) . "\n";
    $role = htmlspecialchars_uni($role_info['role']);
    $role_icon = htmlspecialchars_uni($role_info['role_icon']);
    $usertitle = htmlspecialchars_uni($user_info['usertitle']);
    $formatted_time = my_date('relative', $message['dateline']);
    
    // Create role class name from role (convert to lowercase and replace spaces with hyphens)
    $role_class = strtolower(str_replace(' ', '-', $role));
    
    // Create clickable username manually
    $username_link = '<a href="member.php?action=profile&uid=' . $user_info['uid'] . '" style="color: inherit; text-decoration: none;">' . $username . '</a>';
    
    if ($user_info['uid'] == 1) {
    }
    
    // Format the message content
    $message_content = $message['message'];
    
    // Try to load MyBB parsing functions
    if (!function_exists('mycode_parse')) {
        require_once MYBB_ROOT . "inc/functions_post.php";
        require_once MYBB_ROOT . "inc/functions.php";
        
        // Ensure MyBB globals are available
        global $mybb, $db, $cache, $templates, $lang, $parser;
        
        // Initialize parser if needed
        if (!isset($parser)) {
            require_once MYBB_ROOT . "inc/class_parser.php";
            $parser = new postParser;
        }
    }
    
    // Apply MyBB post formatting if functions exist
    if (function_exists('mycode_parse')) {
        // Parse MyCode (including [img] tags)
        $message_content = mycode_parse($message_content);
        
        // Parse other MyBB formatting
        if (function_exists('smilies_parse')) {
            $message_content = smilies_parse($message_content);
        }
        
        // Parse URLs
        if (function_exists('auto_url')) {
            $message_content = auto_url($message_content);
        }
    } else {
        // Manual MyCode parsing as fallback
        $message_content = thread_collaboration_manual_mycode_parse($message_content);
    }
    
    
    // Process namestyle to replace {username} with actual username
    $processed_namestyle = $user_info['namestyle'];
    if (strpos($processed_namestyle, '{username}') !== false) {
        $processed_namestyle = str_replace('{username}', $username, $processed_namestyle);
    }
    
    // Use the processed namestyle as the complete styling instead of just color
    $username_styled = str_replace('{username}', $username, $user_info['namestyle']);
    
    // Check if current user can edit/delete this message
    $can_edit = ($message['uid'] == $mybb->user['uid'] || thread_collaboration_can_moderate_chat($message['tid']));
    $can_delete = ($message['uid'] == $mybb->user['uid'] || thread_collaboration_can_moderate_chat($message['tid']));
    
    $action_buttons = '';
    if ($can_edit || $can_delete) {
        $action_buttons = '<div class="chat-message-actions">';
        if ($can_edit) {
            $action_buttons .= '<button class="chat-action-btn edit" onclick="editMessage(' . $message['message_id'] . ')" title="Edit message"><i class="fas fa-edit"></i></button>';
        }
        if ($can_delete) {
            $action_buttons .= '<button class="chat-action-btn delete" onclick="deleteMessage(' . $message['message_id'] . ')" title="Delete message"><i class="fas fa-trash"></i></button>';
        }
        $action_buttons .= '</div>';
    }
    
    // Add reply button for all users (everyone can reply to any message) - include in main actions container
    if ($action_buttons) {
        // If we already have action buttons, add reply to the same container
        $action_buttons = str_replace('</div>', '<button class="chat-action-btn reply" onclick="replyToMessage(' . $message['message_id'] . ')" title="Reply to message"><i class="fas fa-reply"></i></button></div>', $action_buttons);
    } else {
        // If no other action buttons, create container just for reply
        $action_buttons = '<div class="chat-message-actions">';
        $action_buttons .= '<button class="chat-action-btn reply" onclick="replyToMessage(' . $message['message_id'] . ')" title="Reply to message"><i class="fas fa-reply"></i></button>';
        $action_buttons .= '</div>';
    }
    
    $html = '
    <div class="chat-message" data-message-id="' . $message['message_id'] . '">
        <div class="chat-message-id">#' . $message['message_id'] . '</div>
        ' . $action_buttons . '
        <div class="chat-message-header">
            <span class="chat-username">' . $username_styled . '</span>
            <span class="chat-role ' . $role_class . '">
                <i class="' . $role_icon . '"></i> ' . $role . '
            </span>
            <span class="chat-time">' . $formatted_time . '</span>
        </div>';
        
        // Add reply display if this message is a reply
        if (!empty($message['reply_to']) && $message['reply_to'] > 0) {
            $reply_message = thread_collaboration_get_reply_message($message['reply_to']);
            if ($reply_message) {
                $html .= '
                <div class="chat-message-reply-display">
                    <div class="reply-display-header">
                        <i class="fas fa-reply"></i>
                        <span>Replying to ' . htmlspecialchars($reply_message['username']) . '</span>
                    </div>
                    <div class="reply-display-message-id">#' . $reply_message['message_id'] . '</div>
                    <div class="reply-display-message-content">' . htmlspecialchars($reply_message['message']) . '</div>
                </div>';
            }
        }
        
        $html .= '
        <div class="chat-message-content">' . $message_content . '</div>
    </div>';
    
    if ($message['uid'] == 1 || $user_info['username'] == 'admin') { // Assuming owner/admin UID
        
        // Debug to debug.txt file
        $debug_info .= "Message UID: " . $message['uid'] . "\n";
        $debug_info .= "Username: '" . $user_info['username'] . "'\n";
        $debug_info .= "Username Link: " . $username_link . "\n";
        $debug_info .= "Final HTML: " . $html . "\n";
        
        if (strpos($html, '{username}') !== false) {
        }
    }
    
    return $html;
}

// Manual MyCode parsing function as fallback
if (!function_exists('thread_collaboration_manual_mycode_parse'))
{
function thread_collaboration_manual_mycode_parse($message)
{
    // Basic MyCode parsing
    $message = htmlspecialchars_uni($message);
    
    // Bold
    $message = preg_replace('/\[b\](.*?)\[\/b\]/is', '<strong>$1</strong>', $message);
    
    // Italic
    $message = preg_replace('/\[i\](.*?)\[\/i\]/is', '<em>$1</em>', $message);
    
    // Underline
    $message = preg_replace('/\[u\](.*?)\[\/u\]/is', '<u>$1</u>', $message);
    
    // Strikethrough
    $message = preg_replace('/\[s\](.*?)\[\/s\]/is', '<s>$1</s>', $message);
    
    // Color
    $message = preg_replace('/\[color=([^]]+)\](.*?)\[\/color\]/is', '<span style="color: $1;">$2</span>', $message);
    
    // Size
    $message = preg_replace('/\[size=([^]]+)\](.*?)\[\/size\]/is', '<span style="font-size: $1px;">$2</span>', $message);
    
    // URL
    $message = preg_replace('/\[url\](.*?)\[\/url\]/is', '<a href="$1" target="_blank">$1</a>', $message);
    $message = preg_replace('/\[url=(.*?)\](.*?)\[\/url\]/is', '<a href="$1" target="_blank">$2</a>', $message);
    
    // Image
    $message = preg_replace('/\[img\](.*?)\[\/img\]/is', '<img src="$1" alt="Image" class="chat-image" style="max-width: 300px; max-height: 200px; width: auto; height: auto; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); margin: 8px 0; display: block; cursor: pointer;" onclick="this.style.maxWidth=this.style.maxWidth==\'100%\'?\'300px\':\'100%\'; this.style.maxHeight=this.style.maxHeight==\'100%\'?\'200px\':\'100%\';" title="Click to toggle size">', $message);
    
    // Code
    $message = preg_replace('/\[code\](.*?)\[\/code\]/is', '<code style="background: #f4f4f4; padding: 2px 4px; border-radius: 3px; font-family: monospace;">$1</code>', $message);
    
    // Quote
    $message = preg_replace('/\[quote\](.*?)\[\/quote\]/is', '<blockquote style="border-left: 3px solid #ccc; margin: 10px 0; padding: 10px; background: #f9f9f9;">$1</blockquote>', $message);
    
    // Spoiler
    $message = preg_replace('/\[spoiler\](.*?)\[\/spoiler\]/is', '<details><summary>Spoiler</summary>$1</details>', $message);
    
    // List
    $message = preg_replace('/\[list\](.*?)\[\/list\]/is', '<ul>$1</ul>', $message);
    $message = preg_replace('/\[\*\](.*?)(?=\[\*\]|\[\/list\]|$)/is', '<li>$1</li>', $message);
    
    // Convert line breaks
    $message = nl2br($message);
    
    return $message;
}
}

// Check if user can moderate chat (edit/delete any message)
function thread_collaboration_can_moderate_chat($tid)
{
    global $mybb, $db;
    
    // Check if user is a moderator or admin
    if (is_moderator($tid, "canmoderate") || $mybb->usergroup['cancp'] == 1) {
        return true;
    }
    
    // Check if user is thread owner
    $thread_query = $db->simple_select("threads", "uid", "tid='{$tid}'", array('limit' => 1));
    $thread = $db->fetch_array($thread_query);
    if ($thread && $thread['uid'] == $mybb->user['uid']) {
        return true;
    }
    
    return false;
}
}

// Check if chat page is enabled and allowed in forum
if (!function_exists('thread_collaboration_is_chat_enabled'))
{
function thread_collaboration_is_chat_enabled($fid)
{
    global $mybb;
    
    // Check if chat page is enabled
    if ($mybb->settings['thread_collaboration_enable_chat_page'] != '1') {
        return false;
    }
    
    // Check if chat is allowed in this forum
    $chat_allowed_forums = $mybb->settings['thread_collaboration_chat_allowed_forums'];
    if ($chat_allowed_forums != -1) {
        $allowed_forums = explode(',', $chat_allowed_forums);
        if (!in_array($fid, $allowed_forums)) {
            return false;
        }
    }
    
    return true;
}
}

// Check if draft page is enabled and allowed in forum
if (!function_exists('thread_collaboration_is_draft_enabled'))
{
function thread_collaboration_is_draft_enabled($fid)
{
    global $mybb;
    
    // Check if draft page is enabled
    if ($mybb->settings['thread_collaboration_enable_draft_page'] != '1') {
        return false;
    }
    
    // Check if draft is allowed in this forum
    $draft_allowed_forums = $mybb->settings['thread_collaboration_draft_allowed_forums'];
    if ($draft_allowed_forums != -1) {
        $allowed_forums = explode(',', $draft_allowed_forums);
        if (!in_array($fid, $allowed_forums)) {
            return false;
        }
    }
    
    return true;
}
}

// Check if collaboration is allowed in forum
if (!function_exists('thread_collaboration_is_forum_allowed'))
{
function thread_collaboration_is_forum_allowed($fid = 0)
{
    global $mybb;
    
    // If no forum ID provided, assume it's allowed
    if (!$fid) {
        return true;
    }
    
    // Check if collaboration is enabled globally
    if ($mybb->settings['thread_collaboration_enabled'] != '1') {
        return false;
    }
    
    // Check if collaboration is allowed in this specific forum
    $allowed_forums = $mybb->settings['thread_collaboration_allowed_forums'];
    if ($allowed_forums != -1) {
        $forums = explode(',', $allowed_forums);
        if (!in_array($fid, $forums)) {
            return false;
        }
    }
    
    return true;
}
}

// Get collaboration contributions for a post
if (!function_exists('thread_collaboration_get_post_contributions'))
{
function thread_collaboration_get_post_contributions($pid, $tid)
{
    global $db, $mybb, $templates;
    
    // Safety checks
    if (empty($pid) || empty($tid)) {
        return '';
    }
    
    // Get thread info to check forum
    $thread_query = $db->simple_select("threads", "fid", "tid='{$tid}'", array('limit' => 1));
    $thread = $db->fetch_array($thread_query);
    if (!$thread) {
        return '';
    }
    
    // Check if collaboration is enabled for this forum
    if (!thread_collaboration_is_forum_allowed($thread['fid'])) {
        return '';
    }
    
    // Get post information
    $post_query = $db->simple_select("posts", "uid, dateline", "pid='{$pid}'", array('limit' => 1));
    $post = $db->fetch_array($post_query);
    if (!$post) {
        return '';
    }
    
    // Check if this post was published from a draft using the published_post_id column
    $draft_check_query = $db->query("
        SELECT cd.draft_id, cd.status 
        FROM " . TABLE_PREFIX . "collab_drafts cd
        WHERE cd.tid = '{$tid}' 
        AND cd.status = 'published'
        AND cd.published_post_id = '{$pid}'
        LIMIT 1
    ");
    
    $draft_info = $db->fetch_array($draft_check_query);
    if (!$draft_info) {
        return '';
    }
    
    // Get thread information
    $thread_query = $db->simple_select("threads", "fid, uid as thread_owner", "tid='{$tid}'", array('limit' => 1));
    $thread = $db->fetch_array($thread_query);
    if (!$thread) {
        return '';
    }
    
    // Get all collaborators for this thread
    $collaborators_query = $db->query("
        SELECT tc.uid, tc.role, tc.role_icon, u.username
        FROM " . TABLE_PREFIX . "thread_collaborators tc
        LEFT JOIN " . TABLE_PREFIX . "users u ON tc.uid = u.uid
        WHERE tc.tid = '{$tid}'
        UNION
        SELECT '{$thread['thread_owner']}' as uid, 'Owner' as role, 'fas fa-crown' as role_icon, u.username
        FROM " . TABLE_PREFIX . "users u
        WHERE u.uid = '{$thread['thread_owner']}'
    ");
    
    $collaborators = array();
    while ($collaborator = $db->fetch_array($collaborators_query)) {
        $collaborators[$collaborator['uid']] = $collaborator;
    }
    
    if (empty($collaborators)) {
        return '';
    }
    
    // Get contribution data from draft contributions table for this specific published draft
    $contributions_query = $db->query("
        SELECT dc.uid, dc.characters_added, u.username, 
               COALESCE(tc.role, 'Contributor') as role, 
               COALESCE(tc.role_icon, 'fas fa-user') as role_icon
        FROM " . TABLE_PREFIX . "collab_draft_contributions dc
        LEFT JOIN " . TABLE_PREFIX . "users u ON dc.uid = u.uid
        LEFT JOIN " . TABLE_PREFIX . "thread_collaborators tc ON dc.uid = tc.uid AND tc.tid = '{$tid}'
        WHERE dc.draft_id = '{$draft_info['draft_id']}'
        GROUP BY dc.uid
        ORDER BY dc.characters_added DESC
    ");
    
    $contributions = array();
    $total_contributions = 0;
    
    while ($contribution = $db->fetch_array($contributions_query)) {
        $contributions[$contribution['uid']] = $contribution;
        $total_contributions += $contribution['characters_added'];
        
        // Debug: Log contribution data
        file_put_contents('debug.txt', "Contribution Data: " . print_r($contribution, true) . "\n", FILE_APPEND);
    }
    
    // If no contributions found, don't show the box
    if ($total_contributions == 0) {
        return '';
    }
    
    // Generate contribution items using MyBB templates
    $contribution_items = '';
    foreach ($contributions as $uid => $contribution) {
        $percentage = $total_contributions > 0 ? round(($contribution['characters_added'] / $total_contributions) * 100, 1) : 0;
        $role = $contribution['role'] ?: 'Owner';
        $role_icon = $contribution['role_icon'] ?: 'fas fa-crown';
        $username = htmlspecialchars_uni($contribution['username']);
        
        // Use MyBB template system with safe variable replacement
        $template_content = $templates->get("threadcollaboration_contribution_item");
        if ($template_content) {
            // Unescape the template content first
            $template_content = html_entity_decode($template_content, ENT_QUOTES, 'UTF-8');
            $template_content = str_replace('\\"', '"', $template_content);
            
            // Safe template variable replacement
            $contribution_item = $template_content;
            $contribution_item = str_replace('{$username}', $username, $contribution_item);
            $contribution_item = str_replace('{$role}', htmlspecialchars_uni($role), $contribution_item);
            $contribution_item = str_replace('{$role_icon}', htmlspecialchars_uni($role_icon), $contribution_item);
            $contribution_item = str_replace('{$percentage}', $percentage, $contribution_item);
            
            $contribution_items .= $contribution_item;
        } else {
            // Template not found - skip this contribution item
            continue;
        }
    }
    
    // Generate main contribution display using MyBB templates
    $contributor_count = count($contributions);
    $contributor_plural = $contributor_count > 1 ? 's' : '';
    
    // Check if this contribution box should be collapsed by default
    $collapsed_style = '';
    if (!empty($mybb->cookies['collaboration_contributions_collapsed'])) {
        $collapsed_items = explode('|', $mybb->cookies['collaboration_contributions_collapsed']);
        if (in_array('collaboration_contributions_' . $pid, $collapsed_items)) {
            $collapsed_style = 'display: none;';
        }
    } else {
        // Default to collapsed state
        $collapsed_style = 'display: none;';
    }
    
    // Use MyBB template system with safe variable replacement
    $template_content = $templates->get("threadcollaboration_contribution_display");
    if ($template_content) {
        // Unescape the template content first
        $template_content = html_entity_decode($template_content, ENT_QUOTES, 'UTF-8');
        $template_content = str_replace('\\"', '"', $template_content);
        
        // Safe template variable replacement
        $contribution_display = $template_content;
        $contribution_display = str_replace('{$contributor_count}', $contributor_count, $contribution_display);
        $contribution_display = str_replace('{$contributor_plural}', $contributor_plural, $contribution_display);
        $contribution_display = str_replace('{$contribution_items}', $contribution_items, $contribution_display);
        $contribution_display = str_replace('{$post_id}', $pid, $contribution_display);
        $contribution_display = str_replace('{$collapsed_style}', $collapsed_style, $contribution_display);
        
        return $contribution_display;
    } else {
        // Template not found - return empty string
        return '';
    }
}
}

// Register contributors for multi-author reputation system
if (!function_exists('thread_collaboration_register_contributors'))
{
function thread_collaboration_register_contributors($post_id, $tid, $draft_id)
{
    global $db;
    
    // Get all contributors for this draft
    $contributors_query = $db->query("
        SELECT dc.uid, SUM(dc.characters_added) as total_contributions
        FROM " . TABLE_PREFIX . "collab_draft_contributions dc
        WHERE dc.draft_id = '{$draft_id}'
        GROUP BY dc.uid
        ORDER BY total_contributions DESC
    ");
    
    $contributors = array();
    $total_contributions = 0;
    
    while ($contributor = $db->fetch_array($contributors_query)) {
        $contributors[] = $contributor;
        $total_contributions += $contributor['total_contributions'];
    }
    
    // Only register if there are 2 or more contributors
    if (count($contributors) >= 2) {
        foreach ($contributors as $index => $contributor) {
            $percentage = $total_contributions > 0 ? round(($contributor['total_contributions'] / $total_contributions) * 100, 2) : 0;
            $is_primary = ($index === 0) ? 1 : 0; // First contributor is primary
            
            $contributor_data = array(
                'pid' => $post_id,
                'tid' => $tid,
                'uid' => $contributor['uid'],
                'draft_id' => $draft_id,
                'contribution_percentage' => $percentage,
                'is_primary_author' => $is_primary,
                'added_date' => TIME_NOW
            );
            
            $db->insert_query("collab_contributor_posts", $contributor_data);
        }
        
        // Update post counts for all contributors
        thread_collaboration_update_post_counts($post_id);
    }
}
}

// Get chat room button HTML
if (!function_exists('thread_collaboration_get_chat_button'))
{
function thread_collaboration_get_chat_button($tid)
{
    global $mybb, $lang, $db;
    
    // Get thread info to check forum
    $thread_query = $db->simple_select("threads", "fid", "tid='{$tid}'", array('limit' => 1));
    $thread = $db->fetch_array($thread_query);
    if (!$thread) {
        return '';
    }
    
    // Check if chat page is enabled and allowed in this forum
    if (!thread_collaboration_is_chat_enabled($thread['fid'])) {
        return '';
    }
    
    if (!thread_collaboration_can_access_chat($tid)) {
        return '';
    }
    
    $lang->load("thread_collaboration");
    
    return '<a href="collaboration_chats.php?tid=' . $tid . '" class="button collaboration-chat-btn" title="' . $lang->collaboration_chat_room . '">
        <i class="fas fa-comments"></i> ' . $lang->collaboration_chat_room . '
    </a>';
}
}

// Get draft room button HTML
if (!function_exists('thread_collaboration_get_draft_button'))
{
function thread_collaboration_get_draft_button($tid)
{
    global $mybb, $lang, $db;
    
    // Get thread info to check forum
    $thread_query = $db->simple_select("threads", "fid", "tid='{$tid}'", array('limit' => 1));
    $thread = $db->fetch_array($thread_query);
    if (!$thread) {
        return '';
    }
    
    // Check if draft page is enabled and allowed in this forum
    if (!thread_collaboration_is_draft_enabled($thread['fid'])) {
        return '';
    }
    
    if (!thread_collaboration_can_access_draft($tid)) {
        return '';
    }
    
    $lang->load("thread_collaboration");
    
    // Set fallback for draft room language string
    if (!isset($lang->collaboration_draft_room)) {
        $lang->collaboration_draft_room = 'Draft Room';
    }
    
    
    return '<a href="collaboration_draft.php?tid=' . $tid . '" class="button collaboration-draft-btn" title="' . $lang->collaboration_draft_room . '">
        <i class="fas fa-edit"></i> ' . $lang->collaboration_draft_room . '
    </a>';
}
}

// Check if draft functionality is enabled for a forum
if (!function_exists('thread_collaboration_is_draft_enabled'))
{
function thread_collaboration_is_draft_enabled($fid)
{
    global $mybb;
    
    // Check if draft functionality is enabled globally
    if ($mybb->settings['thread_collaboration_enable_draft_page'] != '1') {
        return false;
    }
    
    // Check if collaboration is allowed in this forum
    return thread_collaboration_is_forum_allowed($fid);
}
}

// Check if user can access draft functionality
if (!function_exists('thread_collaboration_can_access_draft'))
{
function thread_collaboration_can_access_draft($tid)
{
    global $mybb, $db;
    
    // User must be logged in
    if (!$mybb->user['uid']) {
        return false;
    }
    
    // Check if user is thread owner or collaborator
    $thread_query = $db->simple_select("threads", "uid, fid", "tid='{$tid}'", array('limit' => 1));
    $thread = $db->fetch_array($thread_query);
    if (!$thread) {
        return false;
    }
    
    // Thread owner can always access
    if ($thread['uid'] == $mybb->user['uid']) {
        return true;
    }
    
    // Check if user is a collaborator
    $collaborator_query = $db->simple_select("thread_collaborators", "uid", "tid='{$tid}' AND uid='{$mybb->user['uid']}'", array('limit' => 1));
    if ($db->num_rows($collaborator_query) > 0) {
        return true;
    }
    
    // Check if user is a forum moderator
    if (is_moderator($thread['fid'])) {
        return true;
    }
    
    // Check if user is in management groups
    if (!empty($mybb->settings['thread_collaboration_moderator_usergroups'])) {
        $management_groups = explode(',', $mybb->settings['thread_collaboration_moderator_usergroups']);
        if (in_array($mybb->user['usergroup'], $management_groups)) {
            return true;
        }
    }
    
    return false;
}
}

// Add system message to chat
if (!function_exists('thread_collaboration_add_system_message'))
{
function thread_collaboration_add_system_message($tid, $message, $system_type = 'info')
{
    return thread_collaboration_post_chat_message($tid, $message, true, $system_type);
}
}

// Check if user has unread chat messages
if (!function_exists('thread_collaboration_has_unread_chat'))
{
function thread_collaboration_has_unread_chat($tid, $last_visit = 0)
{
    global $db;
    
    if (!$last_visit) {
        $last_visit = TIME_NOW - (24 * 60 * 60); // Default to 24 hours ago
    }
    
    $query = $db->simple_select("collab_chat_messages", "COUNT(*) as count", "tid='{$tid}' AND dateline > '{$last_visit}' AND is_system = 0");
    $result = $db->fetch_array($query);
    
    return $result['count'] > 0;
}
}

// Get online collaborators for a thread
if (!function_exists('thread_collaboration_get_online_collaborators'))
{
function thread_collaboration_get_online_collaborators($tid)
{
    global $db, $mybb;
    
    // Get online users from MyBB's session system
    $online_time = TIME_NOW - 300; // 5 minutes ago
    
    // Debug: Log the online time threshold
    error_log("Online time threshold: " . $online_time . " (Current time: " . TIME_NOW . ")");
    
    // Get thread owner first and check if they're online
    $thread_query = $db->query("
        SELECT t.uid as owner_uid, u.username as owner_username, u.usertitle as owner_usertitle,
               u.lastactive, u.invisible, u.avatar, u.avatardimensions
        FROM " . TABLE_PREFIX . "threads t
        LEFT JOIN " . TABLE_PREFIX . "users u ON t.uid = u.uid
        WHERE t.tid = '{$tid}'
    ");
    $thread_data = $db->fetch_array($thread_query);
    
    $online_users = array();
    
    // Add thread owner if they're online
    if ($thread_data && $thread_data['lastactive'] > $online_time) {
        $online_users[] = array(
            'uid' => $thread_data['owner_uid'],
            'username' => $thread_data['owner_username'],
            'avatar' => $thread_data['avatar'],
            'avatardimensions' => $thread_data['avatardimensions'],
            'role' => 'Owner',
            'role_icon' => 'fas fa-crown',
            'is_online' => true
        );
    }
    
    // Get all other collaborators for this thread and check if they're online
    $query = $db->query("
        SELECT DISTINCT tc.uid, u.username, tc.role, tc.role_icon, u.lastactive, u.invisible, u.avatar, u.avatardimensions
        FROM " . TABLE_PREFIX . "thread_collaborators tc
        LEFT JOIN " . TABLE_PREFIX . "users u ON tc.uid = u.uid
        WHERE tc.tid = '{$tid}' AND u.lastactive > '{$online_time}'
        ORDER BY u.username
    ");
    
    while ($user = $db->fetch_array($query)) {
        // Skip if this user is already added as owner
        if ($user['uid'] != $thread_data['owner_uid']) {
            $online_users[] = array(
                'uid' => $user['uid'],
                'username' => $user['username'],
                'avatar' => $user['avatar'],
                'avatardimensions' => $user['avatardimensions'],
                'role' => $user['role'],
                'role_icon' => $user['role_icon'],
                'is_online' => true
            );
        }
    }
    
    // Debug: Log the results
    error_log("Online users found: " . count($online_users));
    foreach ($online_users as $user) {
        error_log("Online user: " . $user['username'] . " (UID: " . $user['uid'] . ")");
    }
    
    return $online_users;
}
}

// Get thread collaborators
if (!function_exists('thread_collaboration_get_thread_collaborators'))
{
function thread_collaboration_get_thread_collaborators($tid)
{
    global $db;
    
    // Get thread owner first
    $thread_query = $db->query("
        SELECT t.uid as owner_uid, u.username as owner_username, u.usertitle as owner_usertitle, u.avatar, u.avatardimensions
        FROM " . TABLE_PREFIX . "threads t
        LEFT JOIN " . TABLE_PREFIX . "users u ON t.uid = u.uid
        WHERE t.tid = '{$tid}'
    ");
    $thread_data = $db->fetch_array($thread_query);
    
    $collaborators = array();
    $user_roles = array(); // Track roles per user
    
    // Add thread owner as "Owner" role
    if ($thread_data) {
        $user_roles[$thread_data['owner_uid']] = array(
            'uid' => $thread_data['owner_uid'],
            'username' => $thread_data['owner_username'],
            'avatar' => $thread_data['avatar'],
            'avatardimensions' => $thread_data['avatardimensions'],
            'roles' => array(array(
                'role' => 'Owner',
                'role_icon' => 'fas fa-crown'
            ))
        );
    }
    
    // Get all other collaborators for this thread
    $query = $db->query("
        SELECT tc.uid, u.username, tc.role, tc.role_icon, u.avatar, u.avatardimensions
        FROM " . TABLE_PREFIX . "thread_collaborators tc
        LEFT JOIN " . TABLE_PREFIX . "users u ON tc.uid = u.uid
        WHERE tc.tid = '{$tid}'
        ORDER BY u.username
    ");
    
    while ($collaborator = $db->fetch_array($query)) {
        $uid = $collaborator['uid'];
        
        // Initialize user if not exists
        if (!isset($user_roles[$uid])) {
            $user_roles[$uid] = array(
                'uid' => $uid,
                'username' => $collaborator['username'],
                'avatar' => $collaborator['avatar'],
                'avatardimensions' => $collaborator['avatardimensions'],
                'roles' => array()
            );
        }
        
        // Add role to user's roles array
        $user_roles[$uid]['roles'][] = array(
            'role' => $collaborator['role'],
            'role_icon' => $collaborator['role_icon']
        );
    }
    
    // Convert to final format
    foreach ($user_roles as $user_data) {
        $collaborators[] = $user_data;
    }
    
    return $collaborators;
}
}

// Get reply message data
if (!function_exists('thread_collaboration_get_reply_message'))
{
function thread_collaboration_get_reply_message($message_id)
{
    global $db;
    
    $query = $db->query("
        SELECT cm.message_id, cm.message, u.username
        FROM " . TABLE_PREFIX . "collab_chat_messages cm
        LEFT JOIN " . TABLE_PREFIX . "users u ON cm.uid = u.uid
        WHERE cm.message_id = '{$message_id}'
    ");
    
    if ($db->num_rows($query) > 0) {
        return $db->fetch_array($query);
    }
    
    return false;
}
}

