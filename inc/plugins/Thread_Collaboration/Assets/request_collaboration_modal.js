/* global jQuery */
(function($){
	$(document).ready(function(){
		// Check if Select2 is available and load it if needed
		function ensureSelect2Loaded() {
			if (typeof $.fn.select2 !== "undefined") {
				return true;
			}
			
			// Check if Select2 CSS and JS are already loaded
			var select2CSS = $("link[href*='select2.css']");
			var select2JS = $("script[src*='select2']");
			
			if (select2CSS.length && select2JS.length) {
				// Select2 resources are loaded but not initialized, wait a bit
				return false;
			}
			
			// Try to load Select2 if not available
			if (!select2CSS.length) {
				$("<link>").attr({
					rel: "stylesheet",
					type: "text/css",
					href: window.location.origin + "/jscripts/select2/select2.css"
				}).appendTo("head");
			}
			
			if (!select2JS.length) {
				$.getScript(window.location.origin + "/jscripts/select2/select2.min.js", function() {
					// Select2 loaded successfully
				});
			}
			
			return false;
		}
		
		// Initialize Select2 for the role field when modal opens
		function initializeModalRoleSelect2() {
			// Ensure Select2 is loaded
			if (!ensureSelect2Loaded()) {
				// If Select2 is not ready, try again later
				setTimeout(initializeModalRoleSelect2, 200);
				return;
			}
			
			// Check if Select2 is available (from existing resources)
			if (typeof $.fn.select2 === "undefined") {
				// Select2 not available, using regular input
				return;
			}
			
			// Get default roles from global variable
			var defaultRoles = Array.isArray(window.threadCollabDefaultRoles) ? window.threadCollabDefaultRoles : [];
			
			$("#request_role").select2({
				placeholder: "Select a role or type custom...",
				minimumInputLength: 0,
				multiple: false,
				allowClear: true,
				data: defaultRoles.map(function(role) {
					return {
						id: role.name,
						text: role.name,
						icon: role.icon
					};
				}),
				initSelection: function(element, callback) {
					var value = $(element).val();
					if (value !== "") {
						callback({
							id: value,
							text: value
						});
					}
				},
				createSearchChoice: function(term, data) {
					if ($(data).filter(function() {
						return this.text.localeCompare(term) === 0;
					}).length === 0) {
						return {id: term, text: term};
					}
				}
			});
			
			// Handle role selection to auto-fill icon and show/hide icon field
			$("#request_role").on("select2-selecting", function(e) {
				var selectedRole = e.choice;
				if (selectedRole && selectedRole.icon) {
					$("#request_role_icon").val(selectedRole.icon);
					$("#icon_display").html("<i class='" + selectedRole.icon + "'></i> " + selectedRole.icon).show();
				} else {
					$("#request_role_icon").val("");
					$("#icon_display").text("Icon will be auto-filled based on role selection").hide();
				}
			});
			
			// Handle custom role input
			$("#request_role").on("change", function() {
				var selectedRole = $(this).val();
				var defaultRole = defaultRoles.find(function(role) {
					return role.name === selectedRole;
				});
				
				if (defaultRole && defaultRole.icon) {
					$("#request_role_icon").val(defaultRole.icon);
					$("#icon_display").html("<i class='" + defaultRole.icon + "'></i> " + defaultRole.icon).show();
				} else {
					$("#request_role_icon").val("");
					$("#icon_display").text("Icon will be auto-filled based on role selection").hide();
				}
			});
		}
		
		// Initialize Select2 when modal opens
		window.initializeModalRoleSelect2 = initializeModalRoleSelect2;
	});
})(jQuery);

// Global functions for modal management
function openCollaborationModal() {
	var modal = document.getElementById("collaboration-modal");
	if (modal) {
		// Get thread ID from the button that was clicked
		var threadId = event.target.getAttribute("data-thread-id");
		if (!threadId) {
			console.error("Thread ID not found");
			return;
		}
		
		modal.style.display = "block";
		// Update form action with correct thread ID
		var form = document.getElementById("collaboration-request-form");
		if (form) {
			form.action = "showthread.php?tid=" + threadId + "&action=request_collaboration";
		}
		
		// Initialize Select2 for role field with retry mechanism
		if (typeof window.initializeModalRoleSelect2 === "function") {
			// Try to initialize immediately
			window.initializeModalRoleSelect2();
			
			// If Select2 is not available, try again after a short delay
			if (typeof jQuery !== "undefined" && !jQuery("#request_role").data("select2")) {
				setTimeout(function() {
					window.initializeModalRoleSelect2();
				}, 500);
			}
		}
		
		// Focus on first input
		var firstInput = document.getElementById("request_role");
		if (firstInput) {
			firstInput.focus();
		}
	}
}

function closeCollaborationModal() {
	var modal = document.getElementById("collaboration-modal");
	if (modal) {
		modal.style.display = "none";
		// Clear form
		var form = document.getElementById("collaboration-request-form");
		if (form) {
			form.reset();
		}
		// Destroy Select2 to prevent conflicts
		if (typeof jQuery !== "undefined" && jQuery("#request_role").data("select2")) {
			jQuery("#request_role").select2("destroy");
		}
	}
}

// Close modal when clicking outside of it
window.onclick = function(event) {
	var modal = document.getElementById("collaboration-modal");
	if (event.target == modal) {
		closeCollaborationModal();
	}
}

// Close modal with Escape key
document.addEventListener("keydown", function(event) {
	if (event.key === "Escape") {
		closeCollaborationModal();
	}
});
