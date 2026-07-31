import http from '@/api/http';

const BASE = '/api/client/extensions/modpackhub';

export type Source = 'modrinth' | 'curseforge' | 'url';

export interface PackSummary {
    source: Source;
    id: string;
    name: string;
    summary: string;
    icon_url: string | null;
    downloads: number;
    updated_at: string | null;
    distributable?: boolean;
}

export interface PackVersion {
    id: string;
    name: string;
    mc_versions: string[];
    loader: string;
    server_file: boolean;
    layout?: 'zip' | 'cfpack' | 'multimc';
    release_type?: 'release' | 'beta' | 'alpha' | null;
}

export interface Installation {
    id: number;
    source: Source;
    pack_name: string;
    mc_version: string | null;
    loader: string | null;
    status: string;
    error_message: string | null;
    notes: string | null;
    created_at: string;
    updated_at: string;
}

export interface Config {
    sources: Source[];
    max_pack_mb: number;
}

export const getConfig = async (): Promise<Config> =>
    (await http.get(`${BASE}/config`)).data;

export const search = async (
    source: Source,
    q: string,
    page: number,
    mcVersion?: string,
    loader?: string
): Promise<{ data: PackSummary[]; page: number; total: number; ignored_filters?: string[] }> =>
    (await http.get(`${BASE}/search`, { params: { source, q, page, mc_version: mcVersion, loader } })).data;

export const getVersions = async (
    source: Source,
    packId: string,
    mcVersion?: string,
    loader?: string
): Promise<{ data: PackVersion[]; blocked: string | null }> => {
    const res = (
        await http.get(`${BASE}/packs/${source}/${encodeURIComponent(packId)}/versions`, {
            params: { mc_version: mcVersion, loader },
        })
    ).data;

    return { data: res.data, blocked: res.blocked ?? null };
};

export interface InstallBody {
    source: Source;
    pack_id?: string;
    version_id?: string;
    url?: string;
    accept_eula: boolean;
    install_loader?: boolean;
    backup?: boolean;
    replace_mods?: boolean;
    allow_missing?: boolean;
}

export interface Preflight {
    applicable: boolean;
    blocked?: string;
    mods?: number;
    client_only_skipped?: number;
    from_modrinth?: number;
    unavailable?: string[];
}

export const getPreflight = async (source: Source, packId: string, versionId: string): Promise<Preflight> =>
    (
        await http.get(
            `${BASE}/packs/${source}/${encodeURIComponent(packId)}/versions/${encodeURIComponent(versionId)}/preflight`,
        )
    ).data;

export const install = async (uuid: string, body: InstallBody): Promise<number> =>
    (await http.post(`${BASE}/servers/${uuid}/install`, body)).data.installation_id;

export const getInstalls = async (
    uuid: string,
): Promise<{ data: Installation[]; current: Installation | null; backups_allowed: boolean }> =>
    (await http.get(`${BASE}/servers/${uuid}/installs`)).data;

export const ACTIVE_STATUSES = ['pending', 'backing_up', 'switching_egg', 'installing', 'restoring_egg'];
