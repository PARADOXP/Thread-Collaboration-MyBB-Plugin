/* global jQuery, MyBB, window */
(function($){
    $(document).ready(function(){
        var defaultRoles = Array.isArray(window.threadCollabDefaultRoles) ? window.threadCollabDefaultRoles : [];

        function initializeUsernameSelect2($element) {
            if ($.fn.select2) {
                if (typeof MyBB !== 'undefined' && MyBB.select2) { MyBB.select2(); }
                $element.select2({
                    placeholder: 'Search for a user',
                    minimumInputLength: 2,
                    multiple: false,
                    allowClear: true,
                    ajax: {
                        url: 'xmlhttp.php?action=get_users',
                        dataType: 'json',
                        data: function(term){ return { query: term }; },
                        results: function(data){ return { results: data }; }
                    },
                    initSelection: function(element, callback){
                        var value = $(element).val();
                        if (value !== '') { callback({ id: value, text: value }); }
                    },
                    createSearchChoice: function(term, data){
                        if ($(data).filter(function(){ return this.text.localeCompare(term) === 0; }).length === 0) {
                            return { id: term, text: term };
                        }
                    }
                });
                $element.on('change', function(){
                    var value = $element.select2('val');
                    $element.val(value);
                });
            }
        }

        function initializeRoleSelect2($element) {
            if ($.fn.select2) {
                if (typeof MyBB !== 'undefined' && MyBB.select2) { MyBB.select2(); }
                $element.select2({
                    placeholder: 'Select a role or type custom...',
                    minimumInputLength: 0,
                    multiple: false,
                    allowClear: true,
                    data: defaultRoles.map(function(role){ return { id: role.name, text: role.name, icon: role.icon }; }),
                    initSelection: function(element, callback){
                        var value = $(element).val();
                        if (value !== '') { callback({ id: value, text: value }); }
                    },
                    createSearchChoice: function(term, data){
                        if ($(data).filter(function(){ return this.text.localeCompare(term) === 0; }).length === 0) {
                            return { id: term, text: term };
                        }
                    }
                });

                $element.on('select2-selecting', function(e){
                    var selectedRole = e.choice;
                    var $field = $(this).closest('.thread_collaboration_field');
                    var $iconLabel = $field.find('.icon-field-label');
                    var $iconInput = $field.find('.collaborator_role_icon');
                    if (selectedRole && selectedRole.icon) {
                        $iconInput.val(selectedRole.icon);
                        $iconLabel.hide();
                    } else {
                        $iconLabel.show();
                        $iconInput.val('');
                    }
                });

                $element.on('change', function(){
                    var selectedRole = $(this).val();
                    var $field = $(this).closest('.thread_collaboration_field');
                    var $iconLabel = $field.find('.icon-field-label');
                    var $iconInput = $field.find('.collaborator_role_icon');
                    var defaultRole = defaultRoles.find(function(role){ return role.name === selectedRole; });
                    if (defaultRole && defaultRole.icon) {
                        $iconInput.val(defaultRole.icon);
                        $iconLabel.hide();
                    } else if ($.trim(selectedRole) !== '') {
                        $iconLabel.show();
                        $iconInput.val('');
                    } else {
                        $iconLabel.hide();
                        $iconInput.val('');
                    }
                });
            }
        }

        function initializeAdditionalRoleSelect2($element) {
            if ($.fn.select2) {
                if (typeof MyBB !== 'undefined' && MyBB.select2) { MyBB.select2(); }
                $element.select2({
                    placeholder: 'Select additional role or type custom...',
                    minimumInputLength: 0,
                    multiple: false,
                    allowClear: true,
                    data: defaultRoles.map(function(role){ return { id: role.name, text: role.name, icon: role.icon }; }),
                    initSelection: function(element, callback){
                        var value = $(element).val();
                        if (value !== '') { callback({ id: value, text: value }); }
                    },
                    createSearchChoice: function(term, data){
                        if ($(data).filter(function(){ return this.text.localeCompare(term) === 0; }).length === 0) {
                            return { id: term, text: term };
                        }
                    }
                });

                $element.on('select2-selecting', function(e){
                    var selectedRole = e.choice;
                    var $field = $(this).closest('.additional-role-row');
                    var $iconLabel = $field.find('.additional-icon-field-label');
                    var $iconInput = $field.find('.collaborator_additional_role_icon');
                    if (selectedRole && selectedRole.icon) {
                        $iconInput.val(selectedRole.icon);
                        $iconLabel.hide();
                    } else {
                        $iconLabel.show();
                        $iconInput.val('');
                    }
                });

                $element.on('change', function(){
                    var selectedRole = $(this).val();
                    var $field = $(this).closest('.additional-role-row');
                    var $iconLabel = $field.find('.additional-icon-field-label');
                    var $iconInput = $field.find('.collaborator_additional_role_icon');
                    var defaultRole = defaultRoles.find(function(role){ return role.name === selectedRole; });
                    if (defaultRole && defaultRole.icon) {
                        $iconInput.val(defaultRole.icon);
                        $iconLabel.hide();
                    } else if ($.trim(selectedRole) !== '') {
                        $iconLabel.show();
                        $iconInput.val('');
                    } else {
                        $iconLabel.hide();
                        $iconInput.val('');
                    }
                });
            }
        }

        // Initialize current fields
        $('.thread_collaboration_fields .collaborator_username').each(function(index){
            $(this).attr('id', 'collaborator_username_' + index);
            $(this).prev('label').attr('for', 'collaborator_username_' + index);
            $(this).closest('.thread_collaboration_field').attr('data-collab-index', index);
            initializeUsernameSelect2($(this));
        });

        $('.thread_collaboration_fields .collaborator_role').each(function(index){
            $(this).attr('id', 'collaborator_role_' + index);
            $(this).prev('label').attr('for', 'collaborator_role_' + index);
            $(this).closest('.thread_collaboration_field').attr('data-collab-index', index);
            initializeRoleSelect2($(this));
        });

        // Add new collaborator field
        $(document).on('click', '.add_collaborator_field', function(e){
            e.preventDefault();
            e.stopPropagation();
            var $template = $('#collaborator-template .thread_collaboration_field');
            if ($template.length === 0) { return false; }
            var $newField = $template.clone();
            $newField.find('.select2-container').remove();
            $newField.find('input').val('');
            var newIndex = $('.thread_collaboration_fields .collaborator_username').length;
            $newField.find('.collaborator_username').attr('id', 'collaborator_username_' + newIndex).prev('label').attr('for', 'collaborator_username_' + newIndex);
            $newField.find('.collaborator_role').attr('id', 'collaborator_role_' + newIndex).prev('label').attr('for', 'collaborator_role_' + newIndex);
            $newField.attr('data-collab-index', newIndex);
            $newField.find('.additional-roles .collaborator_additional_role').attr('name', 'collaborator_additional_role[' + newIndex + '][]');
            $newField.find('.additional-roles .collaborator_additional_role_icon').attr('name', 'collaborator_additional_role_icon[' + newIndex + '][]');
            $('.thread_collaboration_fields').append($newField);
            $newField.find('.remove_collaborator_field').show();
            initializeUsernameSelect2($newField.find('.collaborator_username'));
            initializeRoleSelect2($newField.find('.collaborator_role'));
            return false;
        });

        // Remove collaborator field
        $(document).on('click', '.remove_collaborator_field', function(e){
            e.preventDefault();
            e.stopPropagation();
            var $parent = $(this).closest('.thread_collaboration_field');
            if ($('.thread_collaboration_field').length > 1) {
                try { $parent.find('.collaborator_username').select2('destroy'); } catch(e) {}
                try { $parent.find('.collaborator_role').select2('destroy'); } catch(e) {}
                $parent.remove();
                if ($('.thread_collaboration_field').length === 1) {
                    $('.thread_collaboration_field .remove_collaborator_field').hide();
                }
            }
            return false;
        });

        // Toggle multiple roles
        $(document).on('change', '.enable_multiple_roles', function(){
            var $field = $(this).closest('.thread_collaboration_field');
            var $additionalRoles = $field.find('.additional-roles');
            if ($(this).is(':checked')) {
                $additionalRoles.show();
                $additionalRoles.find('.collaborator_additional_role').each(function(index){
                    $(this).attr('id', 'collaborator_additional_role_' + index);
                    $(this).prev('label').attr('for', 'collaborator_additional_role_' + index);
                    var collabIndex = $field.data('collab-index');
                    $(this).attr('name', 'collaborator_additional_role[' + collabIndex + '][' + index + ']');
                    $field.find('.collaborator_additional_role_icon').eq(index).attr('name', 'collaborator_additional_role_icon[' + collabIndex + '][' + index + ']');
                    initializeAdditionalRoleSelect2($(this));
                });
            } else {
                $additionalRoles.hide();
                $additionalRoles.find('.collaborator_additional_role').each(function(){
                    try { $(this).select2('destroy'); } catch(e) {}
                });
            }
        });

        // Add additional role row
        $(document).on('click', '.add_additional_role', function(e){
            e.preventDefault();
            e.stopPropagation();
            var $field = $(this).closest('.thread_collaboration_field');
            var $additionalRoles = $field.find('.additional-roles');
            var $template = $('<div class="additional-role-row">\
                <label>Additional Role: <input type="text" name="collaborator_additional_role[]" class="textbox collaborator_additional_role" style="width: 200px;" /></label>\
                <label class="additional-icon-field-label" style="display: none;">Icon: <input type="text" name="collaborator_additional_role_icon[]" class="textbox collaborator_additional_role_icon" style="width: 120px;" placeholder="fas fa-icon" /></label>\
                <button type="button" class="add_additional_role">Add More</button>\
                <button type="button" class="remove_additional_role" style="display: none;">Remove</button>\
            </div>');
            var newIndex = $additionalRoles.find('.collaborator_additional_role').length;
            $template.find('.collaborator_additional_role').attr('id', 'collaborator_additional_role_' + newIndex).prev('label').attr('for', 'collaborator_additional_role_' + newIndex);
            var collabIndex = $field.data('collab-index');
            $template.find('.collaborator_additional_role').attr('name', 'collaborator_additional_role[' + collabIndex + '][' + newIndex + ']');
            $template.find('.collaborator_additional_role_icon').attr('name', 'collaborator_additional_role_icon[' + collabIndex + '][' + newIndex + ']');
            $additionalRoles.append($template);
            $template.find('.remove_additional_role').show();
            initializeAdditionalRoleSelect2($template.find('.collaborator_additional_role'));
            return false;
        });

        // Remove additional role row
        $(document).on('click', '.remove_additional_role', function(e){
            e.preventDefault();
            e.stopPropagation();
            var $parent = $(this).closest('.additional-role-row');
            var $field = $parent.closest('.thread_collaboration_field');
            var $additionalRoles = $field.find('.additional-roles');
            if ($additionalRoles.find('.additional-role-row').length > 1) {
                try { $parent.find('.collaborator_additional_role').select2('destroy'); } catch(e) {}
                $parent.remove();
                if ($additionalRoles.find('.additional-role-row').length === 1) {
                    $additionalRoles.find('.additional-role-row .remove_additional_role').hide();
                }
            }
            return false;
        });

        // Normalize names and indices on submit
        $(document).on('submit', 'form', function(){
            var collabFields = $(this).find('.thread_collaboration_fields .thread_collaboration_field');
            if (collabFields.length === 0) { return true; }
            collabFields.each(function(i){
                var $field = $(this);
                $field.attr('data-collab-index', i);
                $field.find('.collaborator_username').attr('name', 'collaborator_username['+i+']');
                $field.find('.collaborator_role').attr('name', 'collaborator_role['+i+']');
                $field.find('.collaborator_role_icon').attr('name', 'collaborator_role_icon['+i+']');
                var $rows = $field.find('.additional-roles .additional-role-row');
                $rows.each(function(r){
                    $(this).find('.collaborator_additional_role').attr('name', 'collaborator_additional_role['+i+']['+r+']');
                    $(this).find('.collaborator_additional_role_icon').attr('name', 'collaborator_additional_role_icon['+i+']['+r+']');
                });
            });
            return true;
        });
    });
})(jQuery);

