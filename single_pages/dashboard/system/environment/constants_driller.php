<?php defined('C5_EXECUTE') or die('Access Denied.');

$dh = Loader::helper('concrete/dashboard');
$th = Loader::helper('text');
$jh = Loader::helper('json');
$fh = Loader::helper('form');
?>
<script>
var ConstantDriller = {
	initialize: function() {
		$(".ccm-pane-options-permanent-search input[type=text]").on("keypress", function(e) {
			switch(event.which) {
				case 13:
					ConstantDriller.search();
					event.preventDefault();
					break;
			}
		});
	},
	parseData: function(constants, isAll) {
		var $foo;
		$foo = $('<div></div>');
		var parsed = [];
		$.each(constants, function() {
			if("value" in this) {
				if(this.value === null) {
					this.htmlValue = '<span class="null">null</span>';
				}
				else {
					switch(typeof this.value) {
						case "number":
							this.htmlValue = '<span class="num">' + this.value + '</span>';
							break;
						case "boolean":
							this.htmlValue = '<span class="bool">' + (this.value ? 'true' : 'false') + '</span>';
							break;
						case "string":
							this.title = this.value;
							this.htmlValue = '<span class="str">&quot;' + $foo.empty().text(this.value).html() + '&quot;</span>';
							break;
						case "object":
							this.htmlValue = '<span class="other">' + $foo.empty().text(this.value.type).html() + '</span>';
							break;
						default:
							throw "?";
					}
				}
			}
			else {
				this.htmlValue = '<span class="undef"><?php echo t('Not defined.'); ?></span>';
			}
			parsed.push(this);
		});
		if(isAll) {
			ConstantDriller.allData = parsed;
		}
		return parsed;
	},
	showAll: function() {
		if(ConstantDriller.allData) {
			ConstantDriller.showData(ConstantDriller.allData);
		}
		else {
			jQuery.fn.dialog.showLoader();
			$.ajax({
				async: true,
				cache: false,
				url: <?php echo $jh->encode(str_replace('&amp;', '&', $this->action('ajax_getall'))); ?>,
				dataType: "json",
				success: function(d, status, xhr) {
					jQuery.fn.dialog.hideLoader();
					ConstantDriller.showData(ConstantDriller.parseData(d, true));
				},
				error: function(xhr, status, error) {
					jQuery.fn.dialog.hideLoader();
					alert(xhr.responseText);
				}
			});
		}
	},
	search: function() {
		var f = {}, s, b = false;
		if((s = $.trim($("#cdConstantName").val())).length) {
			f.name = s;
			b = true;
		}
		if((s = $.trim($("#cdFilePath").val())).length) {
			f.file = s;
			b = true;
		}
		if((s = $("#cdUsage").val()).length) {
			f.usage = s;
			b = true;
		}
		if(!b) {
			ConstantDriller.showAll();
		}
		else {
			jQuery.fn.dialog.showLoader();
			$.ajax({
				async: true,
				cache: false,
				url: <?php echo $jh->encode(str_replace('&amp;', '&', $this->action('ajax_search'))); ?>,
				type: "POST",
				data: f,
				dataType: "json",
				success: function(d, status, xhr) {
					jQuery.fn.dialog.hideLoader();
					ConstantDriller.showData(ConstantDriller.parseData(d, false));
				},
				error: function(xhr, status, error) {
					jQuery.fn.dialog.hideLoader();
					alert(xhr.responseText);
				}
			});
		}
	},
	rescan: function() {
		if(!confirm(<?php echo $jh->encode(t('This operation may take a few minutes.') . "\n" . t('Do you want to proceed anyway?')) ?>)) {
			return;
		}
		jQuery.fn.dialog.showLoader();
		$.ajax({
			async: true,
			cache: false,
			url: <?php echo $jh->encode(str_replace('&amp;', '&', $this->action('ajax_scan'))); ?>,
			dataType: "json",
			success: function(d, status, xhr) {
				jQuery.fn.dialog.hideLoader();
				$(".ccm-pane-footer").text(d.lastUpdate);
			},
			error: function(xhr, status, error) {
				jQuery.fn.dialog.hideLoader();
				alert(xhr.responseText);
			}
		});
	},
	showData: function(constants) {
		var $tb, $tdv, no3rdlibs;
		no3rdlibs = $("#cdNo3rdPartyLibs").prop("checked");
		$tb = $("#ConstantDriller-list tbody");
		$tb.empty();
		$.each(constants, function() {
			var list = {defined: [], used: []}, skip;
			skip = false;
			if(no3rdlibs) {
				skip = true;
				$.each(this.places, function() {
					if(this.file.indexOf("concrete/libraries/3rdparty") !== 0) {
						skip = false;
						return false;
					}
				});
			}
			if(skip) {
				return;
			}
			$.each(this.places, function() {
				var i = $.extend({}, this);
				delete i.usage;
				list[this.usage].push(i);
			});
			$tb.append($tr = $('<tr></tr>')
				.append($('<td class="n"></td>').text(this.name).attr("title", this.name))
				.append($tdv = $('<td class="v"></td>').html(this.htmlValue))
				.append($('<td class="p"></td>')
					.append($('<a href="#" onclick="ConstantDriller.showPlaces(\'<?php echo t('Constant %s defined in…', '[C]'); ?>\'.replace(/\\[C\\]/g, \'' + this.name + '\'), $(this).data(\'list\'));return false"' + (list.defined.length ? '' : ' class="disabled" disabled="disabled"') + '><?php echo t('Defined in…'); ?></a>')
						.data("list", list.defined)
					)
					.append('<br />')
					.append($('<a href="#" onclick="ConstantDriller.showPlaces(\'<?php echo t('Constant %s used in…', '[C]'); ?>\'.replace(/\\[C\\]/g, \'' + this.name + '\'), $(this).data(\'list\'));return false"' + (list.used.length ? '' : ' class="disabled" disabled="disabled"') + '><?php echo t('Used in…'); ?></a>')
						.data("list", list.used)
					)
				)
			);
			if("title" in this) {
				$tdv.attr("title", this.title);
			}
		});
	},
	showPlaces: function(title, places) {
		if(!places.length) {
			alert(<?php echo $jh->encode(t('Nowhere')); ?>);
		}
		else {
			var $dialog, $tb;
			$dialog = $('<div class="ccm-ui"></div>')
				.append($('<div style="max-height:400px;overflow:auto"></div>')
					.append($('<table class="ccm-results-list ConstantDriller-places"></table>')
						.append('<thead><tr><th><?php echo t('File'); ?></th><th style="text-align:right"><?php echo t('Line'); ?></th></tr></thead>')
						.append($tb = $('<tbody></tbody>'))
					)
				)
				.append($('<div class="dialog-buttons"></div>')
					.append($('<button class="btn primary ccm-button-right"><?php echo t('Close'); ?></button>')
						.on("click", function() {
							$dialog.dialog("close");
							return false;
						})
					)
				)
			;
			$.each(places, function() {
				$tb.append($('<tr></tr>')
					.append($('<td></td>').text(this.file))
					.append($('<td style="text-align:right"></td>').text(this.line.toString()))
				);
			});
			$dialog.dialog({
				title: title,
				modal: false,
				width: 600,
				resizable: true,
				open: function() {
					$(this).parent().find('.ui-dialog-buttonpane').addClass("ccm-ui").html('');
					$(this).find('.dialog-buttons').appendTo($(this).parent().find('.ui-dialog-buttonpane'));
					$(this).find('.dialog-buttons').remove();
				},
				close: function() {
					$dialog.remove();
				},
				buttons: {foo: function(){}}
			});
		}
	}
};
$(document).ready(function() {
	ConstantDriller.initialize();
});
</script>
<?php echo $dh->getDashboardPaneHeaderWrapper(t('Constants Driller'), t('The Constants Driller allow you to inspect all the constants used or defined in your concrete5 installation.'), false, false); ?>
<div class="ccm-pane-options">
	<a href="javascript:void(0)" onclick="ccm_paneToggleOptions(this)" class="ccm-icon-option-closed"><?=t('Options')?></a>
	<div class="ccm-pane-options-content">
		<form class="form-horizontal" onsubmit="return false">
			<div class="span1">
				<?php echo $fh->button('cdRescan', t('Rescan'), array('onclick' => 'ConstantDriller.rescan(); return false')); ?>
			</div>
			<div class="span6">
				<?php echo $fh->label('cdNo3rdPartyLibs', t('Exclude 3rd party libraries'), array('style' => 'white-space:nowrap')); ?>
				<div class="controls">
					<?php echo $fh->checkbox('cdNo3rdPartyLibs', '', true); ?>
				</div>
			</div>
			<div class="clearfix"></div>
		</form>
	</div>
</div>
<div class="ccm-pane-options">
	<form class="form-horizontal" onsubmit="return false">
		<div class="ccm-pane-options-permanent-search">
			<div class="span3">
				<?php echo $fh->label('cdConstantName', t('Constant Name')); ?>
				<div class="controls">
					<?php echo $fh->text('cdConstantName', '', array('style'=> 'width: 100px')); ?>
				</div>
			</div>
			<div class="span3">
				<?php echo $fh->label('cdFilePath', t('File path'))?>
				<div class="controls">
					<?php echo $fh->text('cdFilePath', '', array('style'=> 'width: 100px')); ?>
				</div>
			</div>
			<div class="span5">
				<?php echo $fh->label('cdUsage', t('Usage')); ?>
				<div class="controls">
					<?php print $fh->select('cdUsage', array('' => t('Any'), 'defined' => t('Defined'), 'used' => t('Used')), '', array('style' => 'width:100px')); ?>
					<?php echo $fh->button('cdSearch', t('Search'), array('style' => 'margin-left: 10px', 'onclick' => 'ConstantDriller.search();return false')); ?>
				</div>
			</div>
		</div>
	</form>
</div>
<div class="ccm-pane-body">
	<table class="ccm-results-list" id="ConstantDriller-list">
		<thead><tr>
			<th style="width:37%"><?php echo t('Constant'); ?></th>
			<th style="width:47%"><?php echo t('Current value'); ?></th>
			<th><?php echo t('Found in'); ?></th>
		</tr></thead>
		<tbody></tbody>
	</table>
</div>
<div class="ccm-pane-footer"><?php echo $th->entities($this->controller->GetLastDrillDatetime()); ?></div>
<?php
echo $dh->getDashboardPaneFooterWrapper();
