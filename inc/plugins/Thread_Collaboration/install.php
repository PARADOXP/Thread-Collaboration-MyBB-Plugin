<?php
if(!defined('IN_MYBB')) { die('Direct initialization of this file is not allowed.'); }

if (!defined('PLUGINLIBRARY')) {
    define('PLUGINLIBRARY', MYBB_ROOT . 'inc/plugins/pluginlibrary.php');
}

function tc_install_run()
{
    global $db;

    // Load and execute schema from database.sql
    $schemaFile = MYBB_ROOT.'inc/plugins/Thread_Collaboration/database.sql';
    if(file_exists($schemaFile))
    {
        $sql = @file_get_contents($schemaFile);
        if($sql !== false)
        {
            // Replace {TABLE_PREFIX} placeholder
            $sql = str_replace('{TABLE_PREFIX}', $db->table_prefix, $sql);

            // Split by semicolon taking into account simple cases
            $statements = array_filter(array_map('trim', explode(';', $sql)));
            foreach($statements as $statement)
            {
                if($statement !== '') {
                    $db->write_query($statement);
                }
            }
        }
    }

    // Apply core edits via PluginLibrary
    if(function_exists('thread_collaboration_apply_core_edits'))
    {
        thread_collaboration_apply_core_edits(true);
    }

    // Install settings via PluginLibrary from settings.json if available
    if(file_exists(PLUGINLIBRARY))
    {
        require_once PLUGINLIBRARY;
        $PL = new PluginLibrary();
        $settingsFile = MYBB_ROOT.'inc/plugins/Thread_Collaboration/settings.json';
        if(file_exists($settingsFile))
        {
            $json = @file_get_contents($settingsFile);
            $data = @json_decode($json, true);
            if(is_array($data) && isset($data['group']) && isset($data['settings']) && is_array($data['settings']))
            {
                $group = $data['group'];
                $name = !empty($group['name']) ? $group['name'] : 'thread_collaboration';
                $title = !empty($group['title']) ? $group['title'] : 'Thread Collaboration Settings';
                $description = !empty($group['description']) ? $group['description'] : '';
                $PL->settings($name, $title, $description, $data['settings']);
            }
        }
    }
}

function tc_activate_run()
{
    global $db;

    // Ensure settings exist and rebuild (safe to run multiple times)
    if(function_exists('thread_collaboration_add_settings')) {
        thread_collaboration_add_settings();
    }
    // Normalize Owner Role Visibility to yes/no and map legacy values
    $row = $db->fetch_array($db->simple_select('settings','sid,optionscode,value',"name='thread_collaboration_show_owner_role_mode'"));
    if($row){
        if($row['value']==='always'){ $db->update_query('settings', array('value'=>'1'), "sid='".$db->escape_string($row['sid'])."'"); }
        elseif($row['value']==='when_collaborators'){ $db->update_query('settings', array('value'=>'0'), "sid='".$db->escape_string($row['sid'])."'"); }
        if($row['optionscode']!=='yesno'){
            $db->update_query('settings', array('optionscode'=>'yesno'), "sid='".$db->escape_string($row['sid'])."'");
        }
    }
    if(function_exists('rebuild_settings') === false) {
        require_once MYBB_ROOT.'inc/functions.php';
    }
    rebuild_settings();

    // Install global stylesheet via PluginLibrary from Assets/collaboration.css
    if(file_exists(PLUGINLIBRARY))
    {
        require_once PLUGINLIBRARY;
        $PL = new PluginLibrary();
        $css_path = MYBB_ROOT.'inc/plugins/Thread_Collaboration/Assets/collaboration.css';
        if(file_exists($css_path))
        {
            $css = @file_get_contents($css_path);
            if($css !== false)
            {
                $PL->stylesheet('collaboration', $css);
            }
        }
    }

    require_once MYBB_ROOT."inc/adminfunctions_templates.php";
    // Inject collaboration fields after the thread subject field
    find_replace_templatesets("newthread", "#" . preg_quote('{$posticons}') . "#i", '{$collaboration_fields} {$posticons}');
    find_replace_templatesets("editpost", "#" . preg_quote('{$posticons}') . "#i", '{$collaboration_fields} {$posticons}');
    // Inject collaboration box just after the ratethread
    find_replace_templatesets("showthread", "#" . preg_quote('{$ratethread}') . "#i", '{$ratethread} {$collaboration_box}');

    // Add collaborator role display to postbit templates
    find_replace_templatesets("postbit", "#" . preg_quote('{$post[\'usertitle\']}') . "#i", '{$post[\'collaborator_role\']}{$post[\'usertitle\']}');
    find_replace_templatesets("postbit_classic", "#" . preg_quote('{$post[\'usertitle\']}') . "#i", '{$post[\'collaborator_role\']}{$post[\'usertitle\']}');
    
    // Add edit history button to postbit templates
    find_replace_templatesets("postbit", "#" . preg_quote('{$post[\'button_rep\']}') . "#i", '{$post[\'button_rep\']}{$post[\'collaborator_edit_history\']}');
    find_replace_templatesets("postbit_classic", "#" . preg_quote('{$post[\'button_rep\']}') . "#i", '{$post[\'button_rep\']}{$post[\'collaborator_edit_history\']}');
    
    // Add contribution display box to postbit templates - after the last </div> (end of post structure)
    find_replace_templatesets("postbit", "#" . preg_quote('</div>') . "(?!.*</div>.*)$#i", '{$post[\'collaboration_contributions\']}</div>');
    find_replace_templatesets("postbit_classic", "#" . preg_quote('</div>') . "(?!.*</div>.*)$#i", '{$post[\'collaboration_contributions\']}</div>');

    // Add collaboration chat button next to top newreply button in showthread template
    find_replace_templatesets("showthread", "#" . preg_quote('<div class="float_right">' . "\n\t\t" . '{$newreply}') . "#i", '<div class="float_right">' . "\n\t\t" . '{$newreply} {$collaboration_chat_button}');
    
    // Add collaboration draft button next to bottom newreply button in showthread template
    find_replace_templatesets("showthread", "#" . preg_quote('<div style="padding-top: 4px;" class="float_right">' . "\n\t\t" . '{$newreply}') . "#i", '<div style="padding-top: 4px;" class="float_right">' . "\n\t\t" . '{$newreply} {$collaboration_draft_button}');

    // Add collaboration invitations link to usercp_nav_misc template (no literal tab escapes)
    find_replace_templatesets(
        "usercp_nav_misc",
        "#" . preg_quote('{$lang->ucp_nav_view_profile}</a></td></tr>') . "#i",
        '{$lang->ucp_nav_view_profile}</a></td></tr><tr><td class="trow1 smalltext"><a href="usercp.php?action=collaboration_invitations" class="usercp_nav_item usercp_nav_collaboration_invitations">Collaboration Invitations</a></td></tr>'
    );

    // Add Font Awesome CDN to headerinclude template
    find_replace_templatesets("headerinclude", "#" . preg_quote('{$stylesheets}') . "#i", '{$stylesheets} <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.0/css/all.min.css" integrity="sha512-DxV+EoADOkOygM4IR9yXP8Sb2qwgidEmeqAEmDKIOfPRQZOWbXCzLC6vjbZyy0vPisbH2SyW27+ddLVCN+OMzQ==" crossorigin="anonymous" referrerpolicy="no-referrer" />');

    // Add collaboration info to member_profile template
    find_replace_templatesets("member_profile", "#" . preg_quote('{$profilefields}') . "#i", '{$profilefields} {$collaboration_profile_info}');

    if(function_exists('thread_collaboration_add_template_modifications')) {
        thread_collaboration_add_template_modifications();
    }
}


