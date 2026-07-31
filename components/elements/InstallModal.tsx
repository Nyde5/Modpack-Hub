import React, { useEffect, useState } from 'react';
import styled from 'styled-components/macro';
import tw from 'twin.macro';
import Modal from '@/components/elements/Modal';
import Button from '@/components/elements/Button';

import Input from '@/components/elements/Input';
import Spinner from '@/components/elements/Spinner';
import Tooltip from '@/components/elements/tooltip/Tooltip';
import { PackSummary, PackVersion, Preflight, getVersions, getPreflight, install, InstallBody } from '../api';

const LOADER_HELP =
    'Runs the mod loader installer (Forge/NeoForge/Fabric) on the node so the server is ready ' +
    'to start. Needed for Modrinth packs (.mrpack), which usually do NOT bundle the loader. ' +
    'CurseForge server packs often already include it: leave it off in that case.';

const Title = styled.h2`
    ${tw`text-lg font-semibold text-neutral-100 mb-3`};
`;
const Label = styled.span`
    ${tw`block text-sm text-neutral-300 mb-1`};
`;
const Select = styled.select`
    ${tw`w-full p-2 rounded bg-neutral-800 text-neutral-100 border border-neutral-600`};
`;
const Warning = styled.div`
    ${tw`bg-yellow-900 border border-yellow-700 rounded p-2.5 text-sm text-yellow-200 mb-3`};
`;
const CheckRow = styled.label`
    ${tw`flex items-center gap-2 mb-2 cursor-pointer text-sm text-neutral-300`};
`;
const InfoBadge = styled.span`
    ${tw`inline-flex items-center justify-center w-4 h-4 rounded-full border border-neutral-400 text-neutral-400 flex-shrink-0`};
    font-size: 11px;
    font-style: italic;
    font-weight: 700;
    cursor: help;
`;

interface Props {
    serverUuid: string;

    pack?: PackSummary;
    url?: string;

    backupsAllowed: boolean;
    onClose: () => void;
    onStarted: () => void;
    onError: (e: unknown) => void;
}

export default ({ serverUuid, pack, url, backupsAllowed, onClose, onStarted, onError }: Props) => {
    const [versions, setVersions] = useState<PackVersion[] | null>(null);

    const [versionsBlocked, setVersionsBlocked] = useState<string | null>(null);
    const [versionId, setVersionId] = useState<string>('');
    const [eula, setEula] = useState(false);

    const [installLoader, setInstallLoader] = useState(pack?.source === 'modrinth');

    const [backup, setBackup] = useState(backupsAllowed);

    const [replaceMods, setReplaceMods] = useState(true);

    const [preflight, setPreflight] = useState<Preflight | null>(null);
    const [allowMissing, setAllowMissing] = useState(false);
    const [submitting, setSubmitting] = useState(false);

    const needsVersion = !!pack;

    useEffect(() => {
        if (!pack) return;
        let alive = true;
        getVersions(pack.source, pack.id)
            .then((v) => {
                if (!alive) return;
                setVersions(v.data);
                setVersionsBlocked(v.blocked);

                const release = v.data.find((x) => x.release_type === 'release');
                setVersionId((release ?? v.data[0])?.id ?? '');
            })
            .catch((e) => alive && onError(e));
        return () => {
            alive = false;
        };
    }, [pack]);

    useEffect(() => {
        if (!pack) return;
        const v = versions?.find((x) => x.id === versionId);
        if (!v) return;
        setInstallLoader(pack.source === 'modrinth' || (v.layout === 'cfpack' && !v.server_file));
    }, [pack, versions, versionId]);

    useEffect(() => {
        if (!pack || !versionId) return;
        let alive = true;
        setPreflight(null);
        setAllowMissing(false);
        getPreflight(pack.source, pack.id, versionId)
            .then((p) => alive && setPreflight(p))
            .catch((e) => {
                if (!alive) return;

                const detail = e?.response?.data?.errors?.[0]?.detail;
                setPreflight(detail ? { applicable: true, blocked: detail } : { applicable: false });
            });
        return () => {
            alive = false;
        };
    }, [pack, versionId]);

    const missing = preflight?.unavailable ?? [];

    const blocked = versionsBlocked ?? preflight?.blocked;

    const missingBlocks = missing.length > 0 && !allowMissing;

    const submit = async () => {
        if (submitting || !eula || (needsVersion && !versionId) || missingBlocks || blocked) return;
        setSubmitting(true);
        try {
            const body: InstallBody = pack
                ? {
                      source: pack.source,
                      pack_id: pack.id,
                      version_id: versionId,
                      accept_eula: eula,
                      install_loader: installLoader,
                      backup,
                      replace_mods: replaceMods,
                      allow_missing: allowMissing,
                  }
                : { source: 'url', url, accept_eula: eula, backup, replace_mods: replaceMods };
            await install(serverUuid, body);
            onStarted();
        } catch (e) {
            onError(e);
            setSubmitting(false);
        }
    };

    const title = pack ? pack.name : 'Install from URL';

    return (
        <Modal visible onDismissed={onClose} showSpinnerOverlay={submitting}>
            <Title>{title}</Title>

            {needsVersion && versions === null && (
                <div css={tw`p-5 text-center`}>
                    <Spinner size={'large'} centered />
                </div>
            )}

            {needsVersion && versions !== null && versions.length === 0 && !versionsBlocked && (
                <p css={tw`text-red-300`}>No installable version for this pack.</p>
            )}

            {needsVersion && versions !== null && versions.length > 0 && (
                <label css={tw`block mb-3`}>
                    <Label>Version</Label>
                    <Select value={versionId} onChange={(e) => setVersionId(e.target.value)}>
                        {versions.map((v) => (
                            <option key={v.id} value={v.id}>
                                {v.name} {v.mc_versions[0] ? `— MC ${v.mc_versions[0]}` : ''} {v.loader !== 'none' ? `(${v.loader})` : ''}
                                {v.server_file ? ' • server pack' : ''}

                                {v.release_type && v.release_type !== 'release' ? ` • ${v.release_type}` : ''}
                            </option>
                        ))}
                    </Select>
                </label>
            )}

            {url && <p css={tw`text-sm text-neutral-400 mb-3 break-all`}>{url}</p>}

            <Warning>
                ⚠️ Installing replaces the server files. The server will not be restarted automatically.
            </Warning>

            {blocked && <Warning css={tw`bg-red-900 border-red-700 text-red-200`}>{blocked}</Warning>}

            {preflight?.applicable && !blocked && (
                <p css={tw`text-xs text-neutral-400 mb-3`}>
                    {preflight.mods} server mods will be installed
                    {!!preflight.client_only_skipped &&
                        `, ${preflight.client_only_skipped} client-only mods skipped (they do nothing on a server)`}
                    {!!preflight.from_modrinth &&
                        `, ${preflight.from_modrinth} downloaded from Modrinth (same file, CurseForge does not allow downloading them)`}
                    .
                </p>
            )}

            {missing.length > 0 && (
                <Warning>
                    <div css={tw`mb-2`}>
                        {missing.length} mod{missing.length > 1 ? 's' : ''} of this pack cannot be downloaded
                        automatically: their authors did not allow redistribution on CurseForge.
                    </div>
                    <ul css={tw`mb-2 ml-4 list-disc break-all`}>
                        {missing.slice(0, 8).map((m) => (
                            <li key={m}>{m}</li>
                        ))}
                        {missing.length > 8 && <li>… and {missing.length - 8} more</li>}
                    </ul>
                    <CheckRow css={tw`mb-0 text-yellow-200`}>
                        <Input
                            type={'checkbox'}
                            checked={allowMissing}
                            onChange={(e) => setAllowMissing(e.target.checked)}
                        />
                        <span>Install without them (you can add them by hand later)</span>
                    </CheckRow>
                </Warning>
            )}

            <CheckRow>
                <Input
                    type={'checkbox'}
                    checked={backup}
                    disabled={!backupsAllowed}
                    onChange={(e) => setBackup(e.target.checked)}
                />
                <span>Back up the server before installing (restorable from the server’s Backups tab)</span>
            </CheckRow>

            {!backup && (
                <p css={tw`text-xs text-red-300 mb-2`}>
                    {backupsAllowed
                        ? 'Without a pre-install backup there is no way back to the current state of the server.'
                        : 'This server has a backup limit of 0: no backup can be created, so there will be no way back to ' +
                          'the current state. Ask an administrator to raise the limit.'}
                </p>
            )}

            <CheckRow>
                <Input type={'checkbox'} checked={replaceMods} onChange={(e) => setReplaceMods(e.target.checked)} />
                <span>Replace the mods already installed (empties the mods folder, nothing else)</span>
            </CheckRow>

            {!replaceMods && (
                <p css={tw`text-xs text-yellow-300 mb-2`}>
                    The mods of the previous pack will stay next to the new ones. The loader will load both, which
                    usually stops the server from starting.
                </p>
            )}

            <CheckRow>
                <Input type={'checkbox'} checked={eula} onChange={(e) => setEula(e.target.checked)} />
                <span>
                    I accept the Minecraft EULA (
                    <a href={'https://aka.ms/MinecraftEULA'} target={'_blank'} rel={'noreferrer'} css={tw`text-primary-300`}>
                        aka.ms/MinecraftEULA
                    </a>
                    )
                </span>
            </CheckRow>

            {needsVersion && (
                <div css={tw`flex items-center gap-2 mb-4`}>
                    <label css={tw`flex items-center gap-2 cursor-pointer text-sm text-neutral-300`}>
                        <Input type={'checkbox'} checked={installLoader} onChange={(e) => setInstallLoader(e.target.checked)} />
                        <span>Install the mod loader on the node</span>
                    </label>
                    <Tooltip content={LOADER_HELP} placement={'top'}>
                        <InfoBadge>i</InfoBadge>
                    </Tooltip>
                </div>
            )}

            <div css={tw`flex justify-end gap-2`}>
                <Button isSecondary onClick={onClose}>
                    Cancel
                </Button>
                <Button
                    color={'green'}
                    disabled={!eula || submitting || (needsVersion && !versionId) || missingBlocks || !!blocked}
                    onClick={submit}
                >
                    Start installation
                </Button>
            </div>
        </Modal>
    );
};
