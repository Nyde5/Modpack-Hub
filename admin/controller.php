<?php

namespace Pterodactyl\Http\Controllers\Admin\Extensions\modpackhub;

use Illuminate\View\View;
use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Nest;
use Illuminate\Http\UploadedFile;
use Illuminate\View\Factory as ViewFactory;
use Illuminate\Http\RedirectResponse;
use Prologue\Alerts\AlertsMessageBag;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Http\Requests\Admin\AdminFormRequest;
use Pterodactyl\Services\Nests\NestCreationService;
use Pterodactyl\Services\Eggs\Sharing\EggImporterService;
use Pterodactyl\Services\Eggs\Sharing\EggUpdateImporterService;
use Pterodactyl\BlueprintFramework\Extensions\modpackhub\Services\CurseForgeService;
use Pterodactyl\BlueprintFramework\Libraries\ExtensionLibrary\Admin\BlueprintAdminLibrary as BlueprintExtensionLibrary;

class modpackhubExtensionController extends Controller
{
    public const SOURCES = ['modrinth', 'curseforge', 'url'];

    public const SETTING_EGG_ID = 'installer_egg_id';
    private const EGG_FILE = 'egg-modpackhub-installer.json';
    private const NEST_NAME = 'ModpackHub';

    public function __construct(
        private ViewFactory $view,
        private BlueprintExtensionLibrary $blueprint,
        private AlertsMessageBag $alert,
        private CurseForgeService $curseforge,
        private NestCreationService $nestCreation,
        private EggImporterService $eggImporter,
        private EggUpdateImporterService $eggUpdater,
    ) {}

    public function index(): View
    {
        $cfKey   = (string) $this->blueprint->dbGet('modpackhub', 'curseforge_api_key', '');
        $sources = (string) $this->blueprint->dbGet('modpackhub', 'sources_enabled', implode(',', self::SOURCES));
        $maxMb   = (string) $this->blueprint->dbGet('modpackhub', 'max_pack_mb', '2048');
        $eggId   = (int) $this->blueprint->dbGet('modpackhub', self::SETTING_EGG_ID, 0);
        $eggOk   = $eggId > 0 && Egg::query()->whereKey($eggId)->exists();

        return $this->view->make('admin.extensions.modpackhub.index', [
            'hasCfKey' => $cfKey !== '',
            'sources'  => array_filter(array_map('trim', explode(',', $sources))),
            'maxMb'    => $maxMb,
            'allSources' => self::SOURCES,
            'eggImported' => $eggOk,
            'root'     => '/admin/extensions/modpackhub',
            'blueprint' => $this->blueprint,
        ]);
    }

    public function post(): RedirectResponse
    {
        $path = base_path('.blueprint/extensions/modpackhub/private/' . self::EGG_FILE);
        if (!is_file($path)) {
            $this->alert->danger('Egg file not found in the extension: ' . self::EGG_FILE)->flash();

            return redirect()->route('admin.extensions.modpackhub.index');
        }

        $file = new UploadedFile($path, self::EGG_FILE, 'application/json', null, true);

        try {
            $existingId = (int) $this->blueprint->dbGet('modpackhub', self::SETTING_EGG_ID, 0);
            $existing = $existingId > 0 ? Egg::query()->find($existingId) : null;

            if ($existing) {
                $egg = $this->eggUpdater->handle($existing, $file);
                $this->alert->success('Installer egg updated.')->flash();
            } else {
                $egg = $this->eggImporter->handle($file, $this->nestId());
                $this->blueprint->dbSet('modpackhub', self::SETTING_EGG_ID, (string) $egg->id);
                $this->alert->success('Installer egg imported (nest "' . self::NEST_NAME . '").')->flash();
            }
        } catch (\Throwable $e) {
            $this->alert->danger('Egg import failed: ' . $e->getMessage())->flash();
        }

        return redirect()->route('admin.extensions.modpackhub.index');
    }

    private function nestId(): int
    {
        $nest = Nest::query()->where('name', self::NEST_NAME)->first();
        if ($nest) {
            return $nest->id;
        }

        return $this->nestCreation->handle([
            'name' => self::NEST_NAME,
            'description' => 'Egg di servizio usati da ModpackHub. Non creare server qui manualmente.',
        ])->id;
    }

    public function update(modpackhubSettingsFormRequest $request): RedirectResponse
    {
        $this->blueprint->dbSet('modpackhub', 'sources_enabled', implode(',', $request->input('sources', [])));
        $this->blueprint->dbSet('modpackhub', 'max_pack_mb', (string) $request->input('max_pack_mb'));

        $key = trim((string) $request->input('curseforge_api_key', ''));

        if ($request->boolean('clear_curseforge_api_key')) {
            $this->blueprint->dbSet('modpackhub', 'curseforge_api_key', '');
            $this->alert->warning('CurseForge key removed.')->flash();
        } elseif ($key !== '') {
            if ($this->curseforge->keyIsValid($key)) {
                $this->blueprint->dbSet('modpackhub', 'curseforge_api_key', $key);
                $this->alert->success('CurseForge key verified against the API and saved.')->flash();
            } else {
                $this->alert->danger('CurseForge rejected the key: it was NOT saved. The other settings were.')->flash();
            }
        } else {
            $this->alert->success('Settings saved.')->flash();
        }

        return redirect()->route('admin.extensions.modpackhub.index');
    }
}

class modpackhubSettingsFormRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'curseforge_api_key' => 'nullable|string|max:255',
            'clear_curseforge_api_key' => 'nullable|boolean',
            'sources' => 'nullable|array',
            'sources.*' => 'in:' . implode(',', modpackhubExtensionController::SOURCES),
            'max_pack_mb' => 'required|integer|min:64|max:16384',
        ];
    }
}
