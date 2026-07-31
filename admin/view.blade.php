<form action="{{ $root }}" method="POST">
  {{ csrf_field() }}
  <input type="hidden" name="_method" value="PATCH" />
  <div class="row">
    <div class="col-xs-12 col-md-6">
      <div class="box box-primary">
        <div class="box-header with-border"><h3 class="box-title">CurseForge</h3></div>
        <div class="box-body">
          <label class="control-label">API key</label>
          <input type="password" name="curseforge_api_key" value="" class="form-control" autocomplete="off"
                 placeholder="{{ $hasCfKey ? 'key saved — leave empty to keep it' : 'no key: source disabled' }}" />
          <p class="text-muted small">
            Get it at console.curseforge.com. It is verified with a test call before being saved.
          </p>
          @if($hasCfKey)
            <div>
              <input type="checkbox" name="clear_curseforge_api_key" id="clear_cf_key" value="1" />
              <label for="clear_cf_key" class="control-label" style="font-weight:400">remove the saved key</label>
            </div>
          @endif
        </div>
      </div>
    </div>
    <div class="col-xs-12 col-md-6">
      <div class="box box-warning">
        <div class="box-header with-border"><h3 class="box-title">Sources &amp; limits</h3></div>
        <div class="box-body">
          <label class="control-label">Enabled sources</label>

          @foreach($allSources as $source)
            <div style="margin-bottom:4px">
              <input type="checkbox" name="sources[]" id="source_{{ $source }}" value="{{ $source }}" {{ in_array($source, $sources) ? 'checked' : '' }} />
              <label for="source_{{ $source }}" class="control-label" style="font-weight:400">{{ $source }}</label>
            </div>
          @endforeach
          <label class="control-label">Max pack size (MB)</label>
          <input type="number" name="max_pack_mb" value="{{ $maxMb }}" class="form-control" min="64" max="16384" />
          <p class="text-muted small">Limit on the Content-Length declared by packs installed via direct URL.</p>
        </div>
      </div>
    </div>
  </div>
  <button type="submit" class="btn btn-primary">Save</button>
</form>

<form action="{{ $root }}" method="POST" style="margin-top:10px">
  {{ csrf_field() }}
  <div class="box box-default">
    <div class="box-header with-border"><h3 class="box-title">Installer egg</h3></div>
    <div class="box-body">
      <p>
        Status:
        @if($eggImported)
          <span class="label label-success">imported</span>
        @else
          <span class="label label-warning">not imported</span> — it must be imported before the first installation.
        @endif
      </p>
      <button type="submit" class="btn btn-default">
        {{ $eggImported ? 'Update installer egg' : 'Import installer egg' }}
      </button>
      <p class="text-muted small">Creates/updates the service egg in the "ModpackHub" nest. Do not assign it to servers manually.</p>
    </div>
  </div>
</form>
