<h1 align="center">ModpackHub</h1>

<p align="center">
  <b>Install Minecraft modpacks from Modrinth, CurseForge and direct URLs — from the panel.</b>
</p>

<p align="center">
  <i>
    <a href="https://github.com/BlueprintFramework/framework">Blueprint</a> ·
    <a href="https://github.com/pterodactyl/panel">Pterodactyl</a> ·
    <a href="https://github.com/Nyde5/Modpack-Hub/releases/latest">Latest release</a>
  </i>
</p>

<p align="center">
  <a href="https://github.com/Nyde5/Modpack-Hub/releases/latest"><img alt="Version" src="https://img.shields.io/badge/version-0.1.0-3b82f6?style=flat-square"></a>
  <img alt="License" src="https://img.shields.io/badge/license-GPLv3-3b82f6?style=flat-square">
  <img alt="Pterodactyl" src="https://img.shields.io/badge/Pterodactyl-1.11.x-3b82f6?style=flat-square">
  <img alt="Blueprint" src="https://img.shields.io/badge/Blueprint-beta--2026--06-3b82f6?style=flat-square">
</p>

ModpackHub is a Blueprint extension that adds a **Modpacks** tab to every server. Your users search
for a pack, pick a version and click install — no SFTP upload, no ticket. The pack is downloaded and
extracted **on the Wings node**, not by the panel, and the server goes back to its own egg when the
installation is over.

## Features

- **Three sources:** Modrinth, CurseForge and any direct HTTPS link to a pack.
- **A backup is taken first**, unless the user turns it off. It is a normal panel backup, restored
  from the server's own **Backups** tab.
- **The dialog says what will happen before it happens:** for CurseForge packs, how many mods will
  be installed, which ones are client-only, and which ones the authors don't allow to be downloaded.
- **Loader installers run on the node.** Forge, NeoForge and Fabric are installed where they are
  needed, so the panel never has to touch a jar.
- **Only `mods/` is replaced** — and only if the user leaves that option on. Worlds, `eula.txt`,
  configs and player data stay where they are.
- **Live progress**, one status card, and **no automatic restart**: the server is started when the
  user wants.
- **A crashed worker can't lock a server.** A scheduled command closes installations left hanging
  and restores the original egg on its own.

## Requirements

| | |
|---|---|
| Panel | Pterodactyl 1.11.x with [Blueprint](https://blueprint.zip) — built against `beta-2026-06` |
| PHP | 8.2+ |
| Node | a running Wings node |
| Panel services | the queue worker and the cron (`* * * * * php artisan schedule:run`) Pterodactyl already needs |
| CurseForge | optional: a free API key from [console.curseforge.com](https://console.curseforge.com). Without it that source is simply hidden and everything else works |

## Installation

1. Download `modpackhub.blueprint` from the [latest release](https://github.com/Nyde5/Modpack-Hub/releases/latest).
2. Put it in your Pterodactyl folder (usually `/var/www/pterodactyl`).
3. Install it:

```bash
cd /var/www/pterodactyl
blueprint -install modpackhub.blueprint
```

4. Open **Admin → Extensions → ModpackHub** and click **Import installer egg**. This is done once,
   before the first installation.

## Configuration

Everything lives in **Admin → Extensions → ModpackHub**:

- **API key** — the CurseForge key. It is checked against the API before being saved, and it never
  reaches the browser: the field is always empty, empty means "keep the saved key", and there is a
  dedicated checkbox to remove it.
- **Enabled sources** — turn Modrinth, CurseForge or direct URLs on and off.
- **Max pack size (MB)** — the size limit for direct-URL packs.
- **Installer egg** — the import button and its status.

> [!NOTE]
> The installer egg lives in its own **ModpackHub** nest and is switched onto a server only for the
> duration of an installation. Don't assign it to a server by hand.

## Updating

Download the new release and install it the same way — Blueprint updates the extension in place,
and your settings and installation history are kept:

```bash
blueprint -install modpackhub.blueprint
```

## Uninstalling

```bash
blueprint -remove modpackhub
```

Three things stay behind **on purpose**, and can be removed by hand if you want them gone:

- the `modpackhub_installations` table and its rows — Blueprint never reverts migrations, and those
  rows hold the original egg of any installation still in flight;
- the installer egg and its nest — removing an egg while a server is temporarily switched to it
  would strand that server;
- the extension settings, including the CurseForge key, so a reinstall doesn't start from scratch.

## How it works

The panel doesn't download the pack. The server is temporarily switched to a service egg that
fetches and extracts the modpack on the Wings node — this is also where the loader installers run —
and the original egg, startup command and variables are restored afterwards, including when
something fails halfway.

Installations run in the panel's queue as a state machine on the database, so progress survives a
panel restart, and the same scheduler that Pterodactyl already runs closes the ones whose worker
died.

On the security side: direct URLs are validated before anything is fetched (HTTPS only, no private
address ranges, redirects re-checked at every hop), archives are inspected before being extracted,
every URL a pack carries inside itself has to belong to a known CDN, and the CurseForge API key
never leaves the panel.

## Good to know

- **A modpack made for the client is still a client modpack.** ModpackHub installs the server side
  of a pack and skips mods that only make sense on a client, but a pack designed to make your game
  look nicer has little to give a server.
- **Some mods cannot be downloaded automatically.** On CurseForge an author can forbid it. Those
  mods are looked up on Modrinth and taken from there only when it is byte-for-byte the same file;
  what is left is listed by name, and the user chooses whether to install without it.
- **CurseForge packs in manifest format don't ship a loader or a server jar** — leave "install the
  mod loader" on, as the dialog suggests, or the server will have mods and nothing to run them with.
- **One installation at a time per server**, and never while a backup is being restored.

## Roadmap

**v0.2.0 — mod manager:** listing, adding, removing and toggling single mods, plus uploading a jar
from your own computer, for the mods an API can't hand over.

## Building from source

```bash
cd /var/www/pterodactyl
blueprint -build modpackhub      # iterative dev build
blueprint -export modpackhub     # produces modpackhub.blueprint
blueprint -install modpackhub.blueprint
```

The client UI (`components/`) is compiled with the panel's asset build during install.

## License

Copyright (C) 2026 Giuseppe Maugeri

ModpackHub is free software: you can redistribute it and/or modify it under the terms of the
**GNU General Public License version 3** as published by the Free Software Foundation. It is
distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied
warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the [LICENSE](LICENSE) file
for the full text.
