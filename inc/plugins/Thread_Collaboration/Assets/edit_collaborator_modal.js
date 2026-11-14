/* global jQuery, MyBB */
(function($){
	$(document).ready(function(){
		// Get default roles from global variable
		var defaultRoles = Array.isArray(window.threadCollabEditRoles) ? window.threadCollabEditRoles : [];
		
		// Initialize role Select2
		function initializeEditRoleSelect2() {
			if ($.fn.select2) {
				if (typeof MyBB !== "undefined" && MyBB.select2) { 
					MyBB.select2(); 
				}
				
				$("#edit_role").select2({
					placeholder: "Select a role or type custom...",
					minimumInputLength: 0,
					multiple: false,
					allowClear: true,
					data: defaultRoles.map(function(role){ return { id: role.name, text: role.name, icon: role.icon }; }),
					initSelection: function(element, callback){
						var value = $(element).val();
						if (value !== "") { callback({ id: value, text: value }); }
					},
					createSearchChoice: function(term, data){
						if ($(data).filter(function(){ return this.text.localeCompare(term) === 0; }).length === 0) {
							return { id: term, text: term };
						}
					}
				});
				
				// Handle role selection for icon auto-fill
				$("#edit_role").on("select2-selecting", function(e) {
					var selectedRole = e.choice;
					var $iconField = $("#edit_icon");
					var $iconLabel = $(".icon-field-label");
					
					var matchedRole = defaultRoles.find(function(r) { return r.name === selectedRole.text; });
					if (matchedRole && matchedRole.icon) {
						$iconField.val(matchedRole.icon);
						$iconLabel.hide();
					} else {
						$iconLabel.show();
						$iconField.val("");
					}
				});
				
				$("#edit_role").on("change", function() {
					var value = $(this).val();
					var $iconField = $("#edit_icon");
					var $iconLabel = $(".icon-field-label");
					
					var matchedRole = defaultRoles.find(function(r) { return r.name === value; });
					if (matchedRole && matchedRole.icon) {
						$iconField.val(matchedRole.icon);
						$iconLabel.hide();
					} else if ($.trim(value) !== "") {
						$iconLabel.show();
						$iconField.val("");
					} else {
						$iconLabel.hide();
						$iconField.val("");
					}
				});
			}
		}
		
		// Initialize additional role Select2
		function initializeAdditionalRoleSelect2($element) {
			if ($.fn.select2) {
				if (typeof MyBB !== "undefined" && MyBB.select2) { 
					MyBB.select2(); 
				}
				
				$element.select2({
					placeholder: "Select additional role or type custom...",
					minimumInputLength: 0,
					multiple: false,
					allowClear: true,
					data: defaultRoles.map(function(role){ return { id: role.name, text: role.name, icon: role.icon }; }),
					initSelection: function(element, callback){
						var value = $(element).val();
						if (value !== "") { callback({ id: value, text: value }); }
					},
					createSearchChoice: function(term, data){
						if ($(data).filter(function(){ return this.text.localeCompare(term) === 0; }).length === 0) {
							return { id: term, text: term };
						}
					}
				});
				
				// Handle additional role selection for icon auto-fill
				$element.on("select2-selecting", function(e) {
					var selectedRole = e.choice;
					var $field = $(this).closest('.additional-role-row');
					var $iconLabel = $field.find('.additional-icon-field-label');
					var $iconInput = $field.find('.collaborator_additional_role_icon');
					
					var matchedRole = defaultRoles.find(function(r) { return r.name === selectedRole.text; });
					if (matchedRole && matchedRole.icon) {
						$iconInput.val(matchedRole.icon);
						$iconLabel.hide();
					} else {
						$iconLabel.show();
						$iconInput.val("");
					}
				});
				
				$element.on("change", function() {
					var selectedRole = $(this).val();
					var $field = $(this).closest('.additional-role-row');
					var $iconLabel = $field.find('.additional-icon-field-label');
					var $iconInput = $field.find('.collaborator_additional_role_icon');
					
					var matchedRole = defaultRoles.find(function(r) { return r.name === selectedRole; });
					if (matchedRole && matchedRole.icon) {
						$iconInput.val(matchedRole.icon);
						$iconLabel.hide();
					} else if ($.trim(selectedRole) !== "") {
						$iconLabel.show();
						$iconInput.val("");
					} else {
						$iconLabel.hide();
						$iconInput.val("");
					}
				});
			}
		}
		
		// Toggle multiple roles
		$(document).on("change", "#enable_multiple_roles", function() {
			var $additionalRoles = $(".additional-roles");
			if ($(this).is(":checked")) {
				$additionalRoles.show();
				$additionalRoles.find(".collaborator_additional_role").each(function(index) {
					$(this).attr("id", "edit_additional_role_" + index);
					$(this).prev("label").attr("for", "edit_additional_role_" + index);
					initializeAdditionalRoleSelect2($(this));
				});
			} else {
				$additionalRoles.hide();
				$additionalRoles.find(".collaborator_additional_role").each(function() {
					try { $(this).select2("destroy"); } catch(e) {}
				});
			}
		});
		
		// Add additional role row
		$(document).on("click", ".add_additional_role", function(e) {
			e.preventDefault();
			e.stopPropagation();
			var $additionalRoles = $(".additional-roles");
			var $template = $('<div class="additional-role-row">\
				<label>Additional Role: <input type="text" name="edit_additional_role[]" class="textbox collaborator_additional_role" style="width: 200px;" placeholder="Select additional role or type custom..." /></label>\
				<label class="additional-icon-field-label" style="display: none;">Icon: <input type="text" name="edit_additional_role_icon[]" class="textbox collaborator_additional_role_icon" style="width: 120px;" placeholder="fas fa-icon" /></label>\
				<button type="button" class="add_additional_role">Add More</button>\
				<button type="button" class="remove_additional_role" style="display: none;">Remove</button>\
			</div>');
			var newIndex = $additionalRoles.find(".collaborator_additional_role").length;
			$template.find(".collaborator_additional_role").attr("id", "edit_additional_role_" + newIndex).prev("label").attr("for", "edit_additional_role_" + newIndex);
			$additionalRoles.append($template);
			$template.find(".remove_additional_role").show();
			initializeAdditionalRoleSelect2($template.find(".collaborator_additional_role"));
			return false;
		});
		
		// Remove additional role row
		$(document).on("click", ".remove_additional_role", function(e) {
			e.preventDefault();
			e.stopPropagation();
			var $parent = $(this).closest(".additional-role-row");
			var $additionalRoles = $(".additional-roles");
			if ($additionalRoles.find(".additional-role-row").length > 1) {
				try { $parent.find(".collaborator_additional_role").select2("destroy"); } catch(e) {}
				$parent.remove();
				if ($additionalRoles.find(".additional-role-row").length === 1) {
					$additionalRoles.find(".additional-role-row .remove_additional_role").hide();
				}
			}
			return false;
		});
		
		// Edit collaborator button click handler
		$(".edit-collaborator-btn").on("click", function() {
			var uid = $(this).data("uid");
			var role = $(this).data("role");
			var icon = $(this).data("icon");
			var type = $(this).data("type");
			var requestId = $(this).data("request-id");
			
			// Get username from the row
			var username = $(this).closest("tr").find("strong a").text();
			
 			// Check if modal exists
			if ($("#editCollaboratorModal").length === 0) {
				alert("Edit modal not found. Please refresh the page.");
				return;
			}
			
			// Populate modal
			$("#edit_uid").val(uid);
			$("#edit_type").val(type);
			if (requestId) {
				$("#edit_request_id").val(requestId);
			}
			
			// Clean role name - remove HTML tags
			var cleanRole = role.replace(/<[^>]*>/g, "").trim();
			$("#edit_role").val(cleanRole);
			$("#edit_icon").val(icon);
			$("#edit_username").text(username);
			
			// Reset additional roles section
			$("#enable_multiple_roles").prop("checked", false);
			$(".additional-roles").hide();
			$(".additional-roles .collaborator_additional_role").each(function() {
				try { $(this).select2("destroy"); } catch(e) {}
			});
			$(".additional-roles .additional-role-row").not(":first").remove();
			$(".additional-roles .additional-role-row:first .collaborator_additional_role").val("");
			$(".additional-roles .additional-role-row:first .collaborator_additional_role_icon").val("");
			$(".additional-roles .additional-role-row .remove_additional_role").hide();
			
			// Initialize Select2 for role field
			initializeEditRoleSelect2();
			
			// Show modal
			$("#editCollaboratorModal").show();
		});
		
		// Close modal handlers
		$(".collaboration-modal-close, #cancelEdit").on("click", function() {
			$("#editCollaboratorModal").hide();
		});
		
		// Close modal when clicking outside
		$(window).on("click", function(event) {
			if (event.target.id === "editCollaboratorModal") {
				$("#editCollaboratorModal").hide();
			}
		});
		
		// Revoke invitation button click handler
		$(".revoke-invitation-btn").on("click", function() {
			var uid = $(this).data("uid");
			var username = $(this).data("username");
			
			// Confirm revoke action
			if (confirm("Are you sure you want to revoke the collaboration invitation for " + username + "? This action cannot be undone.")) {
				// Create form and submit
				var form = $('<form method="post" action="showthread.php">');
				form.append('<input type="hidden" name="my_post_key" value="' + $('input[name="my_post_key"]').val() + '">');
				form.append('<input type="hidden" name="action" value="revoke_invitation">');
				form.append('<input type="hidden" name="tid" value="' + $('input[name="tid"]').val() + '">');
				form.append('<input type="hidden" name="revoke_uid" value="' + uid + '">');
				$('body').append(form);
				form.submit();
			}
		});
	});
})(jQuery);
