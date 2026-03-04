<div class="panel" id="openmesconn-panel">
    <div class="panel-heading">
        <i class="icon-cogs"></i>
        {l s='OpenMES — Manufacturing' mod='openmesconnector'}
    </div>
    <div class="panel-body">
        <div class="form-group">
            <label class="control-label col-lg-3">
                {l s='Manufacture this product' mod='openmesconnector'}
            </label>
            <div class="col-lg-9">
                <span class="switch prestashop-switch fixed-width-lg">
                    <input type="radio" name="openmesconn_manufacture" id="openmesconn_on"
                           value="1" {if $openmesconn_manufacture}checked{/if}>
                    <label for="openmesconn_on">{l s='Yes' mod='openmesconnector'}</label>
                    <input type="radio" name="openmesconn_manufacture" id="openmesconn_off"
                           value="0" {if !$openmesconn_manufacture}checked{/if}>
                    <label for="openmesconn_off">{l s='No' mod='openmesconnector'}</label>
                    <a class="slide-button btn"></a>
                </span>
                <p class="help-block">
                    {l s='When enabled, a work order will be created in OpenMES every time this product is ordered.' mod='openmesconnector'}
                </p>
            </div>
        </div>

        <div class="form-group">
            <label class="control-label col-lg-3">
                {l s='Production line' mod='openmesconnector'}
            </label>
            <div class="col-lg-9">
                <select name="openmesconn_line_id" class="form-control fixed-width-xl">
                    {foreach from=$openmesconn_lines item=line}
                        <option value="{$line.id|intval}"
                            {if $openmesconn_line_id == $line.id}selected{/if}>
                            {$line.name|escape:'html':'UTF-8'}
                        </option>
                    {/foreach}
                </select>
                <p class="help-block">
                    {l s='Assign this product to a specific line. Leave empty to use the module default.' mod='openmesconnector'}
                </p>
            </div>
        </div>
    </div>
</div>
