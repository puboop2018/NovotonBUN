{*
 * Travel Core - Facility Scan Progress Page
 *
 * Shows progress bar and auto-submits the next batch.
 * Redirects back to scan_facilities POST action to continue processing.
 *}

{capture name="mainbox"}

<div style="max-width: 600px; margin: 40px auto; text-align: center;">

    <h3>Scanning {$scan_provider|upper} Hotel Facilities</h3>

    {* Progress bar *}
    <div style="background: #f5f5f5; border: 1px solid #ddd; border-radius: 4px; height: 30px; margin: 20px 0; position: relative; overflow: hidden;">
        <div style="background: #5cb85c; height: 100%; width: {$scan_percent}%; transition: width 0.3s ease; min-width: 2px;"></div>
        <span style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); font-weight: bold; font-size: 13px; color: #333;">
            {$scan_percent}%
        </span>
    </div>

    <p style="font-size: 14px; color: #666;">
        Processed <strong>{$scan_offset|number_format:0:".":","|escape:'html'}</strong> of <strong>{$scan_total|number_format:0:".":","|escape:'html'}</strong> hotels
    </p>

    {* Auto-continue form — submits automatically after 1 second *}
    <form id="scanForm" action="{"travel_feature_mappings.scan_facilities"|fn_url}" method="post">
        <input type="hidden" name="security_hash" value="{$security_hash}">
        <input type="hidden" name="scan_provider" value="{$scan_provider|escape:'html'}">
        <input type="hidden" name="scan_offset" value="{$scan_offset}">
        <input type="hidden" name="batch_size" value="{$batch_size}">

        <button type="submit" id="continueBtn" class="btn btn-primary">
            <i class="icon-play"></i> Continue Scanning...
        </button>

        <a href="{"travel_feature_mappings.manage"|fn_url}" class="btn" style="margin-left: 10px;">
            <i class="icon-stop"></i> Stop
        </a>
    </form>

    <p style="font-size: 12px; color: #999; margin-top: 15px;">
        Processing {$batch_size} hotels per batch. Click "Stop" to pause — you can resume later.
    </p>
</div>

<script>
// Auto-submit after 1 second to continue the next batch
setTimeout(function() {
    document.getElementById('continueBtn').textContent = 'Processing next batch...';
    document.getElementById('continueBtn').disabled = true;
    document.getElementById('scanForm').submit();
}, 1000);
</script>

{/capture}

{capture name="buttons"}{/capture}

{include file="common/mainbox.tpl"
    title=__("travel_core.fm_scan_facilities")
    content=$smarty.capture.mainbox
    buttons=$smarty.capture.buttons
}
