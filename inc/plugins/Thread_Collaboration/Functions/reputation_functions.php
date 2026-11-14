<?php
if(!defined('IN_MYBB')) { die('Direct initialization of this file is not allowed.'); }

// Hook into reputation system to handle multi-author posts
if (!function_exists('thread_collaboration_reputation_hook'))
{
function thread_collaboration_reputation_hook()
{
    global $mybb, $db, $lang;
    
    // Debug logging
    file_put_contents('debug.txt', "Reputation start hook triggered\n", FILE_APPEND);
    file_put_contents('debug.txt', "Input action: " . (isset($mybb->input['action']) ? $mybb->input['action'] : 'none') . "\n", FILE_APPEND);
    file_put_contents('debug.txt', "Input PID: " . (isset($mybb->input['pid']) ? $mybb->input['pid'] : 'none') . "\n", FILE_APPEND);
    
    // This hook runs when reputation is being given
    // We need to check if the post has multiple contributors and distribute reputation
    if (isset($mybb->input['pid']) && ($mybb->input['action'] == 'reputation' || $mybb->input['action'] == 'add' || $mybb->input['action'] == 'do_add')) {
        $pid = (int)$mybb->input['pid'];
        
        file_put_contents('debug.txt', "Checking contributors for PID {$pid}\n", FILE_APPEND);
        file_put_contents('debug.txt', "Action: " . $mybb->input['action'] . "\n", FILE_APPEND);
        
        // Check if this post has multiple contributors
        $contributors_query = $db->query("
            SELECT ccp.uid, ccp.contribution_percentage, ccp.is_primary_author, u.username
            FROM " . TABLE_PREFIX . "collab_contributor_posts ccp
            LEFT JOIN " . TABLE_PREFIX . "users u ON ccp.uid = u.uid
            WHERE ccp.pid = '{$pid}'
            ORDER BY ccp.contribution_percentage DESC
        ");
        
        $contributors = array();
        while ($contributor = $db->fetch_array($contributors_query)) {
            $contributors[] = $contributor;
        }
        
        file_put_contents('debug.txt', "Found " . count($contributors) . " contributors\n", FILE_APPEND);
        
        // If there are multiple contributors, store them for distribution
        if (count($contributors) >= 2) {
            // Use global variable to persist between hooks
            global $collaboration_contributors;
            
            // Only store if not already stored
            if (!isset($collaboration_contributors) || empty($collaboration_contributors)) {
                $collaboration_contributors = $contributors;
                $mybb->input['_collaboration_contributors'] = $contributors;
                file_put_contents('debug.txt', "Stored contributors for distribution: " . count($contributors) . "\n", FILE_APPEND);
            } else {
                file_put_contents('debug.txt', "Contributors already stored: " . count($collaboration_contributors) . "\n", FILE_APPEND);
            }
        } else {
            file_put_contents('debug.txt', "Not enough contributors for distribution\n", FILE_APPEND);
        }
    }
}
}

// Hook to distribute reputation after it's given
if (!function_exists('thread_collaboration_reputation_end_hook'))
{
function thread_collaboration_reputation_end_hook()
{
    global $mybb, $db;
    
    // Debug logging
    file_put_contents('debug.txt', "Reputation end hook triggered\n", FILE_APPEND);
    
    // Check if we have contributors to distribute reputation to
    global $collaboration_contributors;
    
    file_put_contents('debug.txt', "Global contributors exists: " . (isset($collaboration_contributors) ? 'yes' : 'no') . "\n", FILE_APPEND);
    if (isset($collaboration_contributors)) {
        file_put_contents('debug.txt', "Global contributors count: " . count($collaboration_contributors) . "\n", FILE_APPEND);
    }
    
    if (isset($collaboration_contributors) && is_array($collaboration_contributors) && count($collaboration_contributors) >= 2) {
        $pid = (int)$mybb->input['pid'];
        $contributors = $collaboration_contributors;
        
        file_put_contents('debug.txt', "Found contributors for distribution: " . count($contributors) . "\n", FILE_APPEND);
        
        // Distribute reputation to all contributors
        thread_collaboration_distribute_reputation($pid, $contributors);
        
        // Clear the global variable after use
        $collaboration_contributors = null;
    } else {
        file_put_contents('debug.txt', "No contributors found for distribution\n", FILE_APPEND);
    }
}
}

// Distribute reputation to all contributors
if (!function_exists('thread_collaboration_distribute_reputation'))
{
function thread_collaboration_distribute_reputation($pid, $contributors)
{
    global $mybb, $db, $lang;
    
    // Get the most recent reputation data for this post
    $reputation_query = $db->query("
        SELECT * FROM " . TABLE_PREFIX . "reputation 
        WHERE pid='{$pid}' 
        ORDER BY dateline DESC 
        LIMIT 1
    ");
    $reputation = $db->fetch_array($reputation_query);
    
    if (!$reputation) {
        return; // No reputation to distribute
    }
    
    $original_reputation = $reputation['reputation'];
    $giver_uid = $reputation['adduid'];
    $original_uid = $reputation['uid']; // The original post author
    
    // Distribute reputation based on contribution percentage
    foreach ($contributors as $contributor) {
        // Skip if this is the original post author (they already got the reputation)
        if ($contributor['uid'] == $original_uid) {
            continue;
        }
        
        $distributed_reputation = round($original_reputation * ($contributor['contribution_percentage'] / 100));
        
        // Ensure contributors get at least 1 reputation point if the original was positive
        if ($original_reputation > 0 && $distributed_reputation == 0) {
            $distributed_reputation = 1;
        }
        
        // Only give reputation if it's more than 0
        if ($distributed_reputation != 0) {
            // Check if reputation already exists for this contributor
            $existing_query = $db->simple_select("reputation", "rid", "pid='{$pid}' AND adduid='{$giver_uid}' AND uid='{$contributor['uid']}'");
            $existing = $db->fetch_array($existing_query);
            
            if (!$existing) {
                // Create new reputation entry for this contributor
                $reputation_data = array(
                    'pid' => $pid,
                    'uid' => $contributor['uid'],
                    'adduid' => $giver_uid,
                    'reputation' => $distributed_reputation,
                    'dateline' => TIME_NOW,
                    'comments' => $reputation['comments'] . ' (Distributed to contributor: ' . $contributor['username'] . ')'
                );
                
                $db->insert_query("reputation", $reputation_data);
                
                // Update user's total reputation
                $user_query = $db->simple_select("users", "reputation", "uid='{$contributor['uid']}'");
                $user = $db->fetch_array($user_query);
                $new_reputation = $user['reputation'] + $distributed_reputation;
                
                $db->update_query("users", array('reputation' => $new_reputation), "uid='{$contributor['uid']}'");
            }
        }
    }
}
}

// Hook to update post counts for contributors
if (!function_exists('thread_collaboration_update_post_counts'))
{
function thread_collaboration_update_post_counts($pid)
{
    global $db;
    
    // Get all contributors for this post
    $contributors_query = $db->query("
        SELECT ccp.uid
        FROM " . TABLE_PREFIX . "collab_contributor_posts ccp
        WHERE ccp.pid = '{$pid}'
    ");
    
    while ($contributor = $db->fetch_array($contributors_query)) {
        // Update post count for each contributor
        $user_query = $db->simple_select("users", "postnum", "uid='{$contributor['uid']}'");
        $user = $db->fetch_array($user_query);
        $new_post_count = $user['postnum'] + 1;
        
        $db->update_query("users", array('postnum' => $new_post_count), "uid='{$contributor['uid']}'");
    }
}
}

// Hook to show multi-author information in postbit
if (!function_exists('thread_collaboration_postbit_multi_author'))
{
function thread_collaboration_postbit_multi_author(&$post)
{
    global $db, $templates;
    
    // Safety check: ensure $post is an array
    if (!is_array($post) || !isset($post['pid'])) {
        return;
    }
    
    $pid = $post['pid'];
    
    // Check if this post has multiple contributors
    $contributors_query = $db->query("
        SELECT ccp.uid, ccp.contribution_percentage, ccp.is_primary_author, u.username
        FROM " . TABLE_PREFIX . "collab_contributor_posts ccp
        LEFT JOIN " . TABLE_PREFIX . "users u ON ccp.uid = u.uid
        WHERE ccp.pid = '{$pid}'
        ORDER BY ccp.contribution_percentage DESC
    ");
    
    $contributors = array();
    while ($contributor = $db->fetch_array($contributors_query)) {
        $contributors[] = $contributor;
    }
    
    if (count($contributors) >= 2) {
        $multi_author_info = '<div class="multi-author-info" style="background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 5px; padding: 10px; margin: 10px 0; font-size: 12px;">';
        $multi_author_info .= '<strong><i class="fas fa-users"></i> Multi-Author Post</strong><br>';
        $multi_author_info .= 'This post was collaboratively written by: ';
        
        $author_names = array();
        foreach ($contributors as $contributor) {
            $author_names[] = $contributor['username'] . ' (' . $contributor['contribution_percentage'] . '%)';
        }
        
        $multi_author_info .= implode(', ', $author_names);
        $multi_author_info .= '</div>';
        
        // Add to post array instead of returning
        $post['multi_author_info'] = $multi_author_info;
    } else {
        $post['multi_author_info'] = '';
    }
}
}

// Hook to modify search results for multi-author posts
if (!function_exists('thread_collaboration_search_hook'))
{
function thread_collaboration_search_hook()
{
    global $mybb, $db;
    
    // Only modify search if it's a finduser action
    if (isset($mybb->input['action']) && $mybb->input['action'] == 'finduser' && isset($mybb->input['uid'])) {
        $uid = (int)$mybb->input['uid'];
        
        // Get all posts where this user is a contributor
        $contributor_posts_query = $db->query("
            SELECT DISTINCT ccp.pid
            FROM " . TABLE_PREFIX . "collab_contributor_posts ccp
            WHERE ccp.uid = '{$uid}'
        ");
        
        $contributor_pids = array();
        while ($row = $db->fetch_array($contributor_posts_query)) {
            $contributor_pids[] = $row['pid'];
        }
        
        if (!empty($contributor_pids)) {
            // Store the contributor post IDs in a global variable for the search process
            global $collaboration_search_pids;
            $collaboration_search_pids = $contributor_pids;
        }
    }
}
}

// Hook to modify search results after they're generated
if (!function_exists('thread_collaboration_search_results_hook'))
{
function thread_collaboration_search_results_hook()
{
    global $mybb, $db, $searcharray;
    
    // Only modify search if it's a finduser action and we have contributor posts
    if (isset($mybb->input['action']) && $mybb->input['action'] == 'finduser' && isset($mybb->input['uid'])) {
        $uid = (int)$mybb->input['uid'];
        
        // Get all posts where this user is a contributor
        $contributor_posts_query = $db->query("
            SELECT DISTINCT ccp.pid
            FROM " . TABLE_PREFIX . "collab_contributor_posts ccp
            WHERE ccp.uid = '{$uid}'
        ");
        
        $contributor_pids = array();
        while ($row = $db->fetch_array($contributor_posts_query)) {
            $contributor_pids[] = $row['pid'];
        }
        
        if (!empty($contributor_pids)) {
            // Add contributor posts to the search results
            $existing_posts = explode(',', $searcharray['posts']);
            $all_posts = array_merge($existing_posts, $contributor_pids);
            $all_posts = array_unique($all_posts);
            $all_posts = array_filter($all_posts); // Remove empty values
            
            $searcharray['posts'] = implode(',', $all_posts);
        }
    }
}
}
