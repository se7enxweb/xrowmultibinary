{default attribute_base=ContentObjectAttribute}
<div id="uploader">You browser doesn't have no tools installed.</div>
{/default}

{* fetch the content of the class wich includes this datatype *}
{def $max_filesize = $attribute.contentclass_attribute.data_int1
     $max_number_of_files = $attribute.contentclass_attribute.data_int2
     $file_button_text = 'add_files'
     $language = ezini( 'RegionalSettings', 'Locale', 'site.ini')
     $dependency_js_list = array( 'xrowm::i18n::'|concat( $language ) )
}

{if $max_number_of_files|eq( 1 )}
    {set $file_button_text = 'add_file'}
{/if}


<link rel="stylesheet" href="/extension/xrowmultibinary/design/standard/stylesheets/plupload.queue.css" type="text/css" media="screen" />

<script type="text/javascript" src="/extension/xrowmultibinary/design/standard/javascript/google_jsapi.js"></script>
<script type="text/javascript" src="/extension/xrowmultibinary/design/standard/javascript/gears_init.js"></script>
<script type="text/javascript" src="/extension/xrowmultibinary/design/standard/javascript/browserplus-min-2.4.21.js"></script>
<!-- Load source versions of the plupload script files -->
<script type="text/javascript" src="/extension/xrowmultibinary/design/standard/javascript/plupload.js"></script>
<script type="text/javascript" src="/extension/xrowmultibinary/design/standard/javascript/plupload.gears.js"></script>
<script type="text/javascript" src="/extension/xrowmultibinary/design/standard/javascript/plupload.silverlight.js"></script>
<script type="text/javascript" src="/extension/xrowmultibinary/design/standard/javascript/plupload.flash.js"></script>
<script type="text/javascript" src="/extension/xrowmultibinary/design/standard/javascript/plupload.browserplus.js"></script>
<script type="text/javascript" src="/extension/xrowmultibinary/design/standard/javascript/plupload.html5.js"></script>
<script type="text/javascript" src="/extension/xrowmultibinary/design/standard/javascript/jquery.plupload.queue.js"></script>
<script type="text/javascript" src="/extension/xrowmultibinary/design/standard/javascript/jquery-ui-1.8.1.custom.min.js"></script>

<!-- Load TinyMCE code -->
{ezscript( $dependency_js_list )}

<script>
    var files=new Array(); // regular array (add an optional integer
    {foreach $attribute.content as $key => $file}
        files[{$key}]=new plupload.File('{$file.filename}','{$file.original_filename}',{$file.filesize});
        files[{$key}].status = plupload.DONE;
        files[{$key}].percent = 100;
    {/foreach}
</script>
{literal}
<script>

var html='<div class="plupload_wrapper plupload_scroll">' +
    '<div id="uploader_container" class="plupload_container">' +
        '<div class="plupload">' +
            '<div class="plupload_content">' +
                '<div class="plupload_filelist_header">' +
                    '<div class="plupload_file_name">' + plupload.translate( 'filename' ) + '</div>' +
                    '<div class="plupload_file_action_header2">&nbsp;</div>' +
                    '<div class="plupload_file_action">&nbsp;</div>' +
                    '<div class="plupload_file_size">' + plupload.translate( 'size' ) + '</div>' +
                    '<div class="plupload_file_size">&nbsp;</div>' +
                    '<div class="plupload_clearer">&nbsp;</div>' +
                '</div>' +
                '<ul id="uploader_filelist" class="plupload_filelist"></ul>' +
                '<div class="plupload_filelist_footer">' +
                    '<div class="plupload_file_name">' +
                        '<div class="plupload_buttons">' +
                            '<a href="#" id="plupload_add" class="plupload_button plupload_add">' + plupload.translate( '{/literal}{$file_button_text}{literal}' ) + '</a>' +
                            '<a href="#" id="plupload_start" class="plupload_button plupload_start">' + plupload.translate( 'start_upload' ) + '</a>' +
                        '</div>' +
                        '<span class="plupload_upload_status"></span>' +
                    '</div>' +
                    '<div class="plupload_file_action"></div>' +
                    '<div class="plupload_file_size"><span class="plupload_total_file_size">0 b</span></div>' +
                    '<div class="plupload_file_status"><span class="plupload_total_status">0%</span></div>' +
                    '<div class="plupload_progress">' +
                        '<div class="plupload_progress_container">' +
                            '<div class="plupload_progress_bar"></div>' +
                        '</div>' +
                    '</div>' +
                    '<div class="plupload_clearer">&nbsp;</div>' +
                '</div>' +
            '</div>' +
        '</div>' +
    '</div>' +
    '<input type="hidden" id="uploader_count" name="uploader_count" value="0" />' +
'</div>';

// Custom example logic
$(function() {
    function randomString() {
        var chars = "0123456789ABCDEFGHIJKLMNOPQRSTUVWXTZabcdefghiklmnopqrstuvwxyz";
        var string_length = 10;
        var randomstring = '';
        for (var i=0; i<string_length; i++) {
            var rnum = Math.floor(Math.random() * chars.length);
            randomstring += chars.substring(rnum,rnum+1);
        }
        return randomstring;
    }

    $("#uploader").pluploadQueue({
        runtimes : 'gears,flash,silverlight,browserplus', // not proven stable html5
        url : '{/literal}{concat( "xrowmultibinary/upload/",$attribute.id,"/",$attribute.version,"/",$attribute.language_code)|ezurl(no)}{literal}/' + randomString(),
        max_file_size : '{/literal}{$max_filesize}mb{literal}',
        chunk_size : '1mb',
        unique_names : false,
        no_files_with_same_name : true,
        upload_on_publish : true,
        rename: false,
        language: '{/literal}{$language}{literal}',
        max_number_of_files: '{/literal}{$max_number_of_files}{literal}',
        //filters : [
        //	{title : "Image files", extensions : "jpg,gif,png"},
        //	{title : "Zip files", extensions : "zip"}
        //],
        // Silverlight settings
        silverlight_xap_url : '/extension/xrowmultibinary/design/standard/javascript/plupload.silverlight.xap',
        // Flash settings
        flash_swf_url : '/extension/xrowmultibinary/design/standard/javascript/plupload.flash.swf'
        },
        files,
        html
    );


});
</script>

{/literal}