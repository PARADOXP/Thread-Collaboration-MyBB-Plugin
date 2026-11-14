<?php
if(!defined('IN_MYBB')) { die('Direct initialization of this file is not allowed.'); }

if (!defined('PLUGINLIBRARY')) {
    define('PLUGINLIBRARY', MYBB_ROOT . 'inc/plugins/pluginlibrary.php');
}

function tc_uninstall_run()
{
    global $db;

    // Revert core edits first
    if(function_exists('thread_collaboration_revert_core_edits'))
    {
        thread_collaboration_revert_core_edits(true);
    }

    // Drop plugin tables
    $db->write_query("DROP TABLE IF EXISTS `".$db->table_prefix."thread_collaborators`");
    $db->write_query("DROP TABLE IF EXISTS `".$db->table_prefix."collaboration_invitations`");
    $db->write_query("DROP TABLE IF EXISTS `".$db->table_prefix."collaboration_requests`");
    $db->write_query("DROP TABLE IF EXISTS `".$db->table_prefix."collaboration_user_settings`");
    $db->write_query("DROP TABLE IF EXISTS `".$db->table_prefix."collab_post_edits`");
    $db->write_query("DROP TABLE IF EXISTS `".$db->table_prefix."collab_edit_history`");
    $db->write_query("DROP TABLE IF EXISTS `".$db->table_prefix."collab_chat_messages`");
    $db->write_query("DROP TABLE IF EXISTS `".$db->table_prefix."collab_drafts`");
    $db->write_query("DROP TABLE IF EXISTS `".$db->table_prefix."collab_draft_contributions`");
    $db->write_query("DROP TABLE IF EXISTS `".$db->table_prefix."collab_draft_edit_logs`");
    $db->write_query("DROP TABLE IF EXISTS `".$db->table_prefix."collab_chat_settings`");
    $db->write_query("DROP TABLE IF EXISTS `".$db->table_prefix."collab_contributor_posts`");
    $db->write_query("DROP TABLE IF EXISTS `".$db->table_prefix."collab_draft_settings`");

    // Remove settings
    $db->delete_query('settings', "name LIKE 'thread_collaboration_%'");
    $db->delete_query('settinggroups', "name = 'thread_collaboration'");
    rebuild_settings();

    // Remove stylesheet via PluginLibrary if present
    if(file_exists(PLUGINLIBRARY))
    {
        require_once PLUGINLIBRARY;
        $PL = new PluginLibrary();
        $PL->stylesheet_delete('collaboration');
    }

    // Remove templates forcefully (in case deactivate didn't)
    $db->delete_query('templates', "title LIKE 'thread_collaboration_%'");
    $db->delete_query('templates', "title LIKE 'usercp_collaboration_%'");

    // Refresh theme cache lists
    require_once MYBB_ADMIN_DIR . '/inc/functions_themes.php';
    $query = $db->simple_select('themes', 'tid');
    while ($theme = $db->fetch_array($query)) {
        update_theme_stylesheet_list($theme['tid']);
    }
}

function tc_deactivate_run()
{
    require_once MYBB_ROOT."inc/adminfunctions_templates.php";
    find_replace_templatesets("newthread", "#" . preg_quote('{$collaboration_fields} {$posticons}') . "#i", '{$posticons}');
    find_replace_templatesets("editpost", "#" . preg_quote('{$collaboration_fields} {$posticons}') . "#i", '{$posticons}');
    find_replace_templatesets("showthread", "#" . preg_quote('{$ratethread} {$collaboration_box}') . "#i", '{$ratethread}');

    // Remove collaborator role display from postbit templates
    find_replace_templatesets("postbit", "#" . preg_quote('{$post[\'collaborator_role\']}{$post[\'usertitle\']}') . "#i", '{$post[\'usertitle\']}');
    find_replace_templatesets("postbit_classic", "#" . preg_quote('{$post[\'collaborator_role\']}{$post[\'usertitle\']}') . "#i", '{$post[\'usertitle\']}');
    
    // Remove edit history button from postbit templates
    find_replace_templatesets("postbit", "#" . preg_quote('{$post[\'button_rep\']}{$post[\'collaborator_edit_history\']}') . "#i", '{$post[\'button_rep\']}');
    find_replace_templatesets("postbit_classic", "#" . preg_quote('{$post[\'button_rep\']}{$post[\'collaborator_edit_history\']}') . "#i", '{$post[\'button_rep\']}');
    
    // Remove contribution display box from postbit templates 
    find_replace_templatesets("postbit", "#" . preg_quote('{$post[\'collaboration_contributions\']}</div>') . "#i", '</div>');
    find_replace_templatesets("postbit_classic", "#" . preg_quote('{$post[\'collaboration_contributions\']}</div>') . "#i", '</div>');

    // Remove collaboration chat button from top newreply in showthread template
    find_replace_templatesets("showthread", "#" . preg_quote('<div class="float_right">' . "\n\t\t" . '{$newreply} {$collaboration_chat_button}') . "#i", '<div class="float_right">' . "\n\t\t" . '{$newreply}');
    
    // Remove collaboration draft button from bottom newreply in showthread template
    find_replace_templatesets("showthread", "#" . preg_quote('<div style="padding-top: 4px;" class="float_right">' . "\n\t\t" . '{$newreply} {$collaboration_draft_button}') . "#i", '<div style="padding-top: 4px;" class="float_right">' . "\n\t\t" . '{$newreply}');

    // Remove collaboration invitations link
    find_replace_templatesets(
        "usercp_nav_misc",
        "#" . preg_quote('<tr><td class="trow1 smalltext"><a href="usercp.php?action=collaboration_invitations" class="usercp_nav_item usercp_nav_collaboration_invitations">Collaboration Invitations</a></td></tr>') . "#i",
        ''
    );

    // Remove Font Awesome CDN from headerinclude template
    find_replace_templatesets("headerinclude", "#" . preg_quote('{$stylesheets} <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.0/css/all.min.css" integrity="sha512-DxV+EoADOkOygM4IR9yXP8Sb2qwgidEmeqAEmDKIOfPRQZOWbXCzLC6vjbZyy0vPisbH2SyW27+ddLVCN+OMzQ==" crossorigin="anonymous" referrerpolicy="no-referrer" />') . "#i", '{$stylesheets}');

    // Remove collaboration info from member_profile template
    find_replace_templatesets("member_profile", "#" . preg_quote('{$profilefields} {$collaboration_profile_info}') . "#i", '{$profilefields}');

    if(file_exists(PLUGINLIBRARY))
    {
        require_once PLUGINLIBRARY;
        $PL = new PluginLibrary();
        $PL->stylesheet_deactivate('collaboration');
    }

    if(function_exists('thread_collaboration_revert_template_modifications')) {
        thread_collaboration_revert_template_modifications();
    }
}


