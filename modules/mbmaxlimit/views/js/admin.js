(function(){
	function initAutocomplete() {
		var $scope = $('[name="mb_scope"]');
		var $targetId = $('[name="mb_id_target"]');
		var $search = $('<input type="text" class="form-control" placeholder="Search..." id="mb_target_search" />');
		$targetId.after($search);
		var lastXhr;
		$search.on('input', function(){
			var term = $(this).val();
			var type = $scope.val();
			if (!term || !type) { return; }
			if (lastXhr) { lastXhr.abort(); }
			lastXhr = $.ajax({
				url: mbmaxlimit_ajax_url,
				method: 'GET',
				data: { ajax: 1, action: 'search', type: type, term: term },
				success: function(list){
					var $d = $('#mb_target_dropdown');
					if (!$d.length) { $d = $('<div id="mb_target_dropdown" class="panel" style="position:absolute; z-index:1000;"></div>').insertAfter($search); }
					$d.empty();
					try { list = JSON.parse(list); } catch(e) { list = []; }
					list.forEach(function(it){
						var $item = $('<div style="padding:6px; cursor:pointer;"></div>').text(it.name + ' (#' + it.id + ')');
						$item.on('click', function(){ $targetId.val(it.id); $d.empty(); $search.val(it.name + ' (#' + it.id + ')'); });
						$d.append($item);
					});
				}
			});
		});
	}

	$(document).ready(function(){
		if (typeof mbmaxlimit_ajax_url !== 'undefined') {
			initAutocomplete();
		}
	});
})();





