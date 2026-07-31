# ModpackHub

A Blueprint extension for Pterodactyl that adds a **Modpacks** tab to every server, so your users
can install a Minecraft modpack themselves — search it, pick a version, click install — instead of
uploading files over SFTP or opening a ticket.

Packs come from **Modrinth**, **CurseForge** or a **direct URL**.

## Install

Download `modpackhub.blueprint` from the [latest release](https://github.com/Nyde5/Modpack-Hub/releases/latest),
put it on the panel and install it:

```bash
cd /var/www/pterodactyl
blueprint -install modpackhub.blueprint
```

Then open **Admin → Extensions → ModpackHub** and click **Import installer egg** — once, before the
first installation.

## For the person installing a modpack

- **Search and install from the panel.** Type the name of a pack, pick a version, accept the EULA,
  start. Progress is shown live on the page.
- **A backup is taken first**, unless you turn it off. It is a normal panel backup: if you don't
  like the result, restore it from the server's own **Backups** tab.
- **You are told what will happen before it happens.** For CurseForge packs the dialog says how many
  mods will be installed and which ones — if any — the authors don't allow to be downloaded, so you
  can decide before anything on the server is touched.
- **Your world stays.** Installing a new pack replaces the contents of the `mods` folder (and only
  that, and only if you leave the option on). Worlds, `eula.txt`, configs and player data are not
  touched.
- **Nothing restarts by itself.** The new pack is in place; you start the server when you want.

## For the admin

**Requirements:** a Pterodactyl panel with Blueprint and a running Wings node. For the CurseForge
source, a free API key from [console.curseforge.com](https://console.curseforge.com) — without it
that source is simply hidden, everything else works.

In **Admin → Extensions → ModpackHub** you set the API key, choose which sources are enabled, and
set a maximum size for direct-URL packs. The **installer egg** has to be imported from that page
before the first installation; it lives in its own "ModpackHub" nest and should never be assigned
to a server by hand.

Installations use the panel's queue, and the panel's scheduler (`* * * * * php artisan
schedule:run`, the cron Pterodactyl already needs) also closes installations left hanging by a
crashed worker, so a failed install can never block a server for good.

## Good to know

- **A modpack made for the client is still a client modpack.** ModpackHub installs the server side
  of a pack and skips mods that only make sense on a client, but a pack designed to make your game
  look nicer has little to give a server.
- **Some mods cannot be downloaded automatically.** On CurseForge an author can forbid it. Those
  mods are looked up on Modrinth, and taken from there only when it is the exact same file; what is
  left is listed by name, and you choose whether to install without it or fetch the pack by hand.
- **One installation at a time per server**, and never while a backup is being restored.

## Under the hood

The pack is not downloaded by the panel. The server is temporarily switched to a service egg that
downloads and extracts on the Wings node — loader installers for Forge, NeoForge and Fabric run
there too — and the original egg is restored afterwards, including when something fails halfway.

URLs are validated before anything is fetched, archives are inspected before being extracted, and
the CurseForge API key never leaves the panel.

## Build from source

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
